<?php

/**
 * ================================================================
 * Logistox - Authentication Middleware
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/middleware/AuthMiddleware.php
 * الوظيفة: حارس البوابة الأول - التحقق من المصادقة والجلسات
 *
 * المسؤوليات:
 * 1. استخراج Bearer Token من Authorization Header
 * 2. التحقق من صلاحية الجلسة في قاعدة البيانات
 * 3. التحقق من حالة المستخدم (active, locked, deleted)
 * 4. حقن بيانات المستخدم في Request للـ Controllers
 * 5. تحديث آخر نشاط للجلسة (بذكاء لتوفير الأداء)
 * 6. تسجيل محاولات الوصول غير المصرح بها
 * 7. إرسال Security Headers مناسبة
 *
 * ملاحظات هامة:
 * - يجب أن يسبق PermissionMiddleware في سلسلة الـ Middleware
 * - يعتمد على جداول: user_sessions, users, roles, login_history
 * - يستخدم Prepared Statements لمنع SQL Injection
 * - يدعم تحديث النشاط كل 5 دقائق فقط لتوفير الأداء
 * ================================================================
 */

declare(strict_types=1);

namespace App\Middleware;

use Core\Database;
use Core\Response;
use Throwable;
use Exception;

/**
 * Class AuthMiddleware
 *
 * Middleware للتحقق من المصادقة وإدارة الجلسات
 */
class AuthMiddleware
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var int فترة تحديث النشاط (بالثواني)
     *
     * بدلاً من تحديث last_activity_at في كل طلب،
     * نقوم بالتحديث فقط إذا مضى أكثر من هذه الفترة.
     * هذا يقلل من استعلامات UPDATE بشكل كبير.
     */
    private const ACTIVITY_UPDATE_INTERVAL = 300; // 5 دقائق

    /**
     * @var array قائمة الـ Headers المسموح بها
     */
    private const ALLOWED_HEADERS = [
        'Authorization',
        'authorization',
        'X-Requested-With',
        'Content-Type',
        'Accept',
        'X-CSRF-Token',
    ];

    /**
     * Constructor
     *
     * @throws Exception إذا فشل الاتصال بقاعدة البيانات
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
        } catch (Throwable $e) {
            error_log('[AUTH_MIDDLEWARE] Database connection failed: ' . $e->getMessage());
            Response::internalError('فشل الاتصال بخدمة المصادقة');
        }
    }

    /**
     * نقطة الدخول الرئيسية للـ Middleware
     *
     * @param array $request مصفوفة الطلب القادمة من Router
     *                       تحتوي على: method, path, params, query, body, headers, ip, user_agent
     * @return bool true للسماح بالمرور، أو إنهاء الطلب عبر Response
     */
    public function handle(array $request): bool
    {
        // 1. السماح بطلبات OPTIONS (Preflight) للمرور بدون مصادقة
        if (($request['method'] ?? '') === 'OPTIONS') {
            return true;
        }

        // 2. استخراج الـ Token من الهيدر
        $token = $this->extractBearerToken($request);

        if ($token === null) {
            $this->logUnauthorizedAccess($request, 'MISSING_TOKEN');
            Response::unauthorized('يرجى توفير رمز المصادقة (Bearer Token) في هيدر Authorization.');
        }

        // 3. التحقق من صحة الـ Token (الطول والشكل)
        if (!$this->validateTokenFormat($token)) {
            $this->logUnauthorizedAccess($request, 'INVALID_TOKEN_FORMAT');
            Response::unauthorized('رمز المصادقة غير صالح.');
        }

        // 4. البحث عن الجلسة في قاعدة البيانات
        $session = $this->findSession($token);

        if ($session === null) {
            $this->logUnauthorizedAccess($request, 'SESSION_NOT_FOUND');
            Response::unauthorized('الجلسة غير موجودة أو منتهية الصلاحية. يرجى تسجيل الدخول مرة أخرى.');
        }

        // 5. التحقق من حالة الجلسة
        if (!$this->validateSessionState($session, $request)) {
            return false; // تم إرسال الرد داخل الدالة
        }

        // 6. التحقق من حالة المستخدم
        if (!$this->validateUserState($session, $request)) {
            return false; // تم إرسال الرد داخل الدالة
        }

        // 7. تحديث آخر نشاط للجلسة (بذكاء)
        $this->updateActivityIfNeeded($session);

        // 8. حقن بيانات المستخدم في الـ Request
        $request['user'] = [
            'id'             => (int) $session['user_id'],
            'username'       => $session['username'],
            'full_name'      => $session['full_name'],
            'email'          => $session['email'] ?? null,
            'role_id'        => (int) $session['role_id'],
            'role_name'      => $session['role_name'],
            'role_display'   => $session['role_display_name'],
            'warehouse_id'   => isset($session['warehouse_id']) ? (int) $session['warehouse_id'] : null,
            'session_id'     => (int) $session['session_id'],
            'device_name'    => $session['device_name'] ?? 'Unknown',
            'ip_address'     => $session['ip_address'] ?? null,
            'must_change_password' => (bool) ($session['must_change_password'] ?? false),
        ];

        // 9. إضافة Security Headers
        $this->addSecurityHeaders();

        // 10. السماح بالمرور إلى الـ Middleware التالي أو الـ Controller
        return true;
    }

    // =========================================================================
    // استخراج الـ Token
    // =========================================================================

    /**
     * استخراج Bearer Token من هيدر Authorization
     *
     * @param array $request مصفوفة الطلب
     * @return string|null الـ Token أو null إذا لم يوجد
     */
    private function extractBearerToken(array $request): ?string
    {
        $headers = $request['headers'] ?? [];

        // البحث عن Authorization header (حساس لحالة الأحرف)
        $authHeader = null;
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                $authHeader = (string) $value;
                break;
            }
        }

        // إذا لم يوجد في headers، نجرب $_SERVER
        if ($authHeader === null) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '';
        }

        if (empty($authHeader)) {
            return null;
        }

        // التحقق من صيغة Bearer Token
        if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches)) {
            $token = trim($matches[1]);
            return $token !== '' ? $token : null;
        }

        return null;
    }

    /**
     * التحقق من صحة شكل الـ Token
     *
     * @param string $token الـ Token المراد فحصه
     * @return bool true إذا كان الشكل صحيحاً
     *
     * نتوقع أن يكون الـ Token عبارة عن 128 حرف hex (64 bytes)
     * كما تم توليده في AuthService::login() باستخدام bin2hex(random_bytes(64))
     */
    private function validateTokenFormat(string $token): bool
    {
        // الطول يجب أن يكون 128 حرف (64 bytes hex-encoded)
        // أو على الأقل 32 حرف (للـ Tokens القديمة أو المختصرة)
        $length = strlen($token);

        if ($length < 32 || $length > 256) {
            return false;
        }

        // يجب أن يحتوي فقط على أحرف hex أو alphanumeric
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $token)) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // التحقق من الجلسة
    // =========================================================================

    /**
     * البحث عن الجلسة في قاعدة البيانات
     *
     * @param string $token الـ Token المراد البحث عنه
     * @return array|null بيانات الجلسة أو null
     *
     * يستخدم استعلام JOIN واحد لجلب كل البيانات المطلوبة
     * مع استخدام Indexes محسّنة على user_sessions.token
     */
    private function findSession(string $token): ?array
    {
        try {
            $sql = "
                SELECT
                    s.id                  AS session_id,
                    s.user_id,
                    s.token,
                    s.device_id,
                    s.device_name,
                    s.ip_address          AS session_ip,
                    s.user_agent          AS session_user_agent,
                    s.is_active           AS session_is_active,
                    s.last_activity_at,
                    s.expires_at,
                    s.revoked_at,
                    s.revoked_reason,
                    u.id                  AS user_id_check,
                    u.username,
                    u.full_name,
                    u.email,
                    u.role_id,
                    u.warehouse_id,
                    u.is_active           AS user_is_active,
                    u.is_locked,
                    u.locked_until,
                    u.deleted_at          AS user_deleted_at,
                    u.must_change_password,
                    r.name                AS role_name,
                    r.display_name        AS role_display_name,
                    r.is_active           AS role_is_active
                FROM user_sessions s
                INNER JOIN users u ON s.user_id = u.id
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE s.token = ?
                LIMIT 1
            ";

            $session = $this->db->selectOne($sql, [$token]);

            return $session ?: null;

        } catch (Throwable $e) {
            error_log('[AUTH_MIDDLEWARE] Session lookup failed: ' . $e->getMessage());
            Response::internalError('حدث خطأ أثناء التحقق من الجلسة');
        }
    }

    /**
     * التحقق من حالة الجلسة
     *
     * @param array $session بيانات الجلسة
     * @param array $request بيانات الطلب
     * @return bool true إذا كانت الجلسة صالحة
     */
    private function validateSessionState(array $session, array $request): bool
    {
        // 1. التحقق من أن الجلسة نشطة
        if ((int) $session['session_is_active'] !== 1) {
            $reason = $session['revoked_reason'] ?? 'UNKNOWN';
            $this->logUnauthorizedAccess($request, "SESSION_INACTIVE:{$reason}");

            $message = 'الجلسة غير نشطة.';
            if ($reason === 'USER_LOGOUT') {
                $message = 'لقد قمت بتسجيل الخروج. يرجى تسجيل الدخول مرة أخرى.';
            } elseif ($reason === 'NEW_LOGIN_SINGLE_DEVICE') {
                $message = 'تم تسجيل الدخول من جهاز آخر. حسابك لا يسمح بتعدد الأجهزة.';
            } elseif ($reason === 'USER_DISABLED') {
                $message = 'تم تعطيل حسابك. يرجى التواصل مع مدير النظام.';
            } elseif ($reason === 'PASSWORD_CHANGED') {
                $message = 'تم تغيير كلمة المرور. يرجى تسجيل الدخول مرة أخرى.';
            } elseif ($reason === 'MANUAL_REVOKED') {
                $message = 'تم إلغاء هذه الجلسة. يرجى تسجيل الدخول مرة أخرى.';
            }

            Response::unauthorized($message);
        }

        // 2. التحقق من انتهاء الصلاحية
        $expiresAt = strtotime($session['expires_at'] ?? 'now');
        if ($expiresAt < time()) {
            // إلغاء الجلسة المنتهية تلقائياً
            $this->expireSession((int) $session['session_id'], 'EXPIRED');
            $this->logUnauthorizedAccess($request, 'SESSION_EXPIRED');
            Response::unauthorized('انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى.');
        }

        // 3. التحقق من عدم إلغاء الجلسة
        if ($session['revoked_at'] !== null) {
            $this->logUnauthorizedAccess($request, 'SESSION_REVOKED');
            Response::unauthorized('تم إلغاء هذه الجلسة. يرجى تسجيل الدخول مرة أخرى.');
        }

        return true;
    }

    /**
     * التحقق من حالة المستخدم
     *
     * @param array $session بيانات الجلسة
     * @param array $request بيانات الطلب
     * @return bool true إذا كان المستخدم صالحاً
     */
    private function validateUserState(array $session, array $request): bool
    {
        // 1. التحقق من أن المستخدم غير محذوف (Soft Delete)
        if ($session['user_deleted_at'] !== null) {
            $this->expireSession((int) $session['session_id'], 'USER_DELETED');
            $this->logUnauthorizedAccess($request, 'USER_DELETED');
            Response::forbidden('حسابك تم حذفه. يرجى التواصل مع مدير النظام.');
        }

        // 2. التحقق من أن المستخدم نشط
        if ((int) $session['user_is_active'] !== 1) {
            $this->expireSession((int) $session['session_id'], 'USER_DISABLED');
            $this->logUnauthorizedAccess($request, 'USER_DISABLED');
            Response::forbidden('حسابك معطّل حالياً. يرجى التواصل مع مدير النظام.');
        }

        // 3. التحقق من أن الدور نشط
        if ($session['role_is_active'] !== null && (int) $session['role_is_active'] !== 1) {
            $this->logUnauthorizedAccess($request, 'ROLE_DISABLED');
            Response::forbidden('الدور المرتبط بحسابك معطّل. يرجى التواصل مع مدير النظام.');
        }

        // 4. التحقق من قفل الحساب
        if ((int) $session['is_locked'] === 1) {
            $lockedUntil = $session['locked_until'] ? strtotime($session['locked_until']) : 0;
            if ($lockedUntil > time()) {
                $remainingMinutes = (int) ceil(($lockedUntil - time()) / 60);
                $this->logUnauthorizedAccess($request, 'ACCOUNT_LOCKED');
                Response::forbidden(
                    "حسابك مقفل مؤقتاً بسبب محاولات دخول فاشلة متكررة. " .
                    "حاول مرة أخرى بعد {$remainingMinutes} دقيقة."
                );
            } else {
                // فتح الحساب تلقائياً إذا انتهت مدة القفل
                $this->unlockUser((int) $session['user_id']);
            }
        }

        return true;
    }

    // =========================================================================
    // تحديث النشاط
    // =========================================================================

    /**
     * تحديث آخر نشاط للجلسة (بذكاء لتوفير الأداء)
     *
     * @param array $session بيانات الجلسة
     *
     * نقوم بالتحديث فقط إذا مضى أكثر من ACTIVITY_UPDATE_INTERVAL
     * على آخر نشاط. هذا يقلل من استعلامات UPDATE بشكل كبير
     * ويحسن أداء النظام تحت الحمل العالي.
     */
    private function updateActivityIfNeeded(array $session): void
    {
        $lastActivity = $session['last_activity_at']
            ? strtotime($session['last_activity_at'])
            : 0;

        $now = time();

        // التحديث فقط إذا مضى أكثر من 5 دقائق
        if (($now - $lastActivity) >= self::ACTIVITY_UPDATE_INTERVAL) {
            try {
                $this->db->update('user_sessions', [
                    'last_activity_at' => date('Y-m-d H:i:s', $now),
                    'updated_at'       => date('Y-m-d H:i:s', $now),
                ], [
                    'id' => (int) $session['session_id'],
                ]);
            } catch (Throwable $e) {
                // فشل التحديث لا يجب أن يكسر الطلب
                error_log('[AUTH_MIDDLEWARE] Activity update failed: ' . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // دوال مساعدة
    // =========================================================================

    /**
     * إلغاء جلسة (تعطيلها)
     *
     * @param int $sessionId معرف الجلسة
     * @param string $reason سبب الإلغاء
     */
    private function expireSession(int $sessionId, string $reason): void
    {
        try {
            $this->db->update('user_sessions', [
                'is_active'      => 0,
                'revoked_at'     => date('Y-m-d H:i:s'),
                'revoked_reason' => $reason,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], [
                'id' => $sessionId,
            ]);
        } catch (Throwable $e) {
            error_log('[AUTH_MIDDLEWARE] Session expiry failed: ' . $e->getMessage());
        }
    }

    /**
     * فتح حساب مقفل
     *
     * @param int $userId معرف المستخدم
     */
    private function unlockUser(int $userId): void
    {
        try {
            $this->db->update('users', [
                'is_locked'              => 0,
                'locked_until'           => null,
                'failed_login_attempts'  => 0,
                'updated_at'             => date('Y-m-d H:i:s'),
            ], [
                'id' => $userId,
            ]);
        } catch (Throwable $e) {
            error_log('[AUTH_MIDDLEWARE] User unlock failed: ' . $e->getMessage());
        }
    }

    /**
     * تسجيل محاولة وصول غير مصرح بها
     *
     * @param array $request بيانات الطلب
     * @param string $reason سبب الرفض
     *
     * هذه المعلومات مهمة لـ:
     * - مراقبة الهجمات الأمنية
     * - تحليل أنماط الوصول المشبوه
     * - توفير معلومات للمدققين
     */
    private function logUnauthorizedAccess(array $request, string $reason): void
    {
        try {
            $ip = $this->getClientIp($request);
            $userAgent = $request['user_agent'] ?? 'Unknown';
            $method = $request['method'] ?? 'UNKNOWN';
            $path = $request['path'] ?? 'UNKNOWN';

            $message = sprintf(
                "[UNAUTHORIZED ACCESS] Reason: %s | Method: %s | Path: %s | IP: %s | UA: %s",
                $reason,
                $method,
                $path,
                $ip,
                substr($userAgent, 0, 200)
            );

            error_log($message);

            // يمكن أيضاً حفظها في جدول audit_logs لاحقاً
            // لكن لتجنب الحمل الزائد، نكتفي بـ error_log حالياً

        } catch (Throwable $e) {
            // فشل التسجيل لا يجب أن يكسر الطلب
            // لا نفعل شيئاً هنا لتجنب حلقة لا نهائية
        }
    }

    /**
     * جلب IP العميل الحقيقي
     *
     * @param array $request بيانات الطلب
     * @return string IP العميل
     *
     * يأخذ في الاعتبار:
     * - X-Forwarded-For (إذا كان الـ Proxy موثوقاً)
     * - X-Real-IP
     * - REMOTE_ADDR
     */
    private function getClientIp(array $request): string
    {
        // إذا كان الـ IP موجوداً في request (من Router)
        if (!empty($request['ip'])) {
            return (string) $request['ip'];
        }

        // التحقق من الـ Proxy (فقط إذا تم تفعيل TRUST_PROXY)
        $trustProxy = filter_var(
            getenv('TRUST_PROXY') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );

        if ($trustProxy) {
            // X-Forwarded-For
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $firstIp = trim($ips[0]);
                if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                    return $firstIp;
                }
            }

            // X-Real-IP
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = trim($_SERVER['HTTP_X_REAL_IP']);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        // REMOTE_ADDR
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = trim($_SERVER['REMOTE_ADDR']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    /**
     * إضافة Security Headers للاستجابة
     *
     * هذه الـ Headers تحمي من:
     * - Clickjacking (X-Frame-Options)
     * - MIME Sniffing (X-Content-Type-Options)
     * - XSS (X-XSS-Protection)
     * - Information Leakage (X-Powered-By removal)
     */
    private function addSecurityHeaders(): void
    {
        // منعClickjacking
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');

            // إزالة X-Powered-By لإخفاء معلومات الخادم
            if (function_exists('header_remove')) {
                header_remove('X-Powered-By');
            }
        }
    }
}

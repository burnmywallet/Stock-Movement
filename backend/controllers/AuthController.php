<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Response;
use App\Services\AuthService;
use App\Services\AuditService;
use Core\Database;
use Throwable;

/**
 * ============================================================================
 * Auth Controller
 * نظام إدارة المخازن والمخزون - Logistox v5.0
 * ============================================================================
 * 
 * المسؤول عن:
 * - استقبال طلبات المصادقة (تسجيل الدخول، الخروج).
 * - التحقق من صحة البيانات المدخلة (Validation).
 * - استدعاء طبقة الأعمال (AuthService).
 * - إرجاع استجابات JSON موحدة.
 * - تسجيل أحداث الأمان (Audit Logging).
 * ============================================================================
 */
class AuthController
{
    private AuthService $authService;
    private AuditService $auditService;

    /**
     * حقن الاعتماديات (Dependency Injection)
     */
    public function __construct()
    {
        $db = Database::getInstance();
        $this->authService = new AuthService($db);
        $this->auditService = new AuditService($db);
    }

    // =========================================================================
    // 1. تسجيل الدخول (Login)
    // =========================================================================

    /**
     * معالجة طلب تسجيل الدخول
     * POST /api/auth/login
     */
    public function login(): void
    {
        $input = $this->getJsonInput();
        
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';

        // 1. التحقق من صحة المدخلات
        if (empty($username) || empty($password)) {
            Response::validationError(
                [
                    'username' => empty($username) ? 'اسم المستخدم مطلوب' : null,
                    'password' => empty($password) ? 'كلمة المرور مطلوبة' : null
                ],
                'بيانات الدخول غير مكتملة'
            );
        }

        // 2. جمع بيانات السياق (Context) للتسجيل والأمان
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        // توليد معرف جهاز بسيط بناءً على المتصفح و IP (يمكن للـ Frontend إرسال معرف أدق)
        $deviceId = hash('sha256', $userAgent . $ipAddress);

        try {
            // 3. استدعاء طبقة الأعمال
            $result = $this->authService->login($username, $password, $ipAddress, $userAgent, $deviceId);

            // 4. تسجيل نجاح العملية في سجل التدقيق
            $this->auditService->log(
                userId: $result['user']['id'],
                action: 'LOGIN_SUCCESS',
                entityType: 'user',
                entityId: $result['user']['id'],
                description: "تسجيل دخول ناجح من IP: {$ipAddress}",
                ipAddress: $ipAddress,
                userAgent: $userAgent
            );

            // 5. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تسجيل الدخول بنجاح',
                data: [
                    'token' => $result['token'],
                    'expires_at' => $result['expires_at'],
                    'must_change_password' => $result['must_change_password'],
                    'user' => $result['user']
                ],
                status: 200
            );

        } catch (Throwable $e) {
            // 6. تسجيل محاولة الدخول الفاشلة
            $this->auditService->log(
                userId: null,
                action: 'LOGIN_FAILED',
                entityType: 'user',
                entityId: null,
                description: "محاولة دخول فاشلة للمستخدم: {$username} - السبب: {$e->getMessage()}",
                ipAddress: $ipAddress,
                userAgent: $userAgent
            );

            // 7. إرجاع خطأ 401 (Unauthorized)
            Response::unauthorized($e->getMessage());
        }
    }

    // =========================================================================
    // 2. تسجيل الخروج (Logout)
    // =========================================================================

    /**
     * معالجة طلب تسجيل الخروج
     * POST /api/auth/logout
     */
    public function logout(): void
    {
        $token = $this->getBearerToken();
        
        if (!$token) {
            Response::unauthorized('رمز المصادقة (Token) مطلوب لتسجيل الخروج');
        }

        try {
            $this->authService->logout($token);
            Response::success('تم تسجيل الخروج بنجاح');
        } catch (Throwable $e) {
            Response::internalError('فشل في إتمام عملية تسجيل الخروج');
        }
    }

    // =========================================================================
    // 3. بيانات المستخدم الحالي (Me)
    // =========================================================================

    /**
     * جلب بيانات المستخدم الحالي بناءً على الـ Token
     * GET /api/auth/me
     */
    public function me(): void
    {
        $token = $this->getBearerToken();
        
        if (!$token) {
            Response::unauthorized();
        }

        $sessionData = $this->authService->validateSession($token);

        if (!$sessionData) {
            Response::unauthorized('الجلسة منتهية أو غير صالحة');
        }

        Response::success('بيانات المستخدم الحالية', [
            'user' => [
                'id' => $sessionData['user_id'],
                'username' => $sessionData['username'],
                'full_name' => $sessionData['full_name'],
                'email' => $sessionData['email'],
                'role_name' => $sessionData['role_name'],
                'role_display_name' => $sessionData['role_display_name'],
            ],
            'session' => [
                'device_name' => $sessionData['device_name'],
                'ip_address' => $sessionData['ip_address'],
                'last_activity_at' => $sessionData['last_activity_at'],
                'expires_at' => $sessionData['expires_at'],
            ]
        ]);
    }

    // =========================================================================
    // 4. إدارة الجلسات والأجهزة (Sessions)
    // =========================================================================

    /**
     * جلب قائمة الأجهزة/الجلسات النشطة للمستخدم
     * GET /api/auth/sessions
     */
    public function getSessions(): void
    {
        $token = $this->getBearerToken();
        $currentUser = $this->authService->validateSession($token);

        if (!$currentUser) {
            Response::unauthorized();
        }

        $sessions = $this->authService->getUserSessions((int)$currentUser['user_id']);

        Response::success('قائمة الأجهزة النشطة', [
            'sessions' => $sessions,
            'current_session_id' => $currentUser['id']
        ]);
    }

    /**
     * إلغاء جلسة معينة (تسجيل الخروج من جهاز آخر)
     * DELETE /api/auth/sessions/{id}
     * 
     * @param array $params المعاملات القادمة من الـ Router (مثل ['id' => 5])
     */
    public function revokeSession(array $params): void
    {
        $token = $this->getBearerToken();
        $currentUser = $this->authService->validateSession($token);

        if (!$currentUser) {
            Response::unauthorized();
        }

        $sessionId = $params['id'] ?? null;
        if (!$sessionId || !is_numeric($sessionId)) {
            Response::badRequest('معرف الجلسة (ID) غير صالح');
        }

        try {
            $this->authService->revokeSession((int)$sessionId, (int)$currentUser['user_id']);
            Response::success('تم إلغاء الجلسة المحددة بنجاح');
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 'REVOKE_ERROR', 400);
        }
    }

    // =========================================================================
    // 5. استعادة كلمة المرور (Stubs / هياكل جاهزة للتطوير)
    // =========================================================================

    public function forgotPassword(): void
    {
        // TODO: تنفيذ منطق إرسال رابط إعادة التعيين عبر البريد الإلكتروني
        Response::success('تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني (قيد التطوير)');
    }

    public function resetPassword(): void
    {
        // TODO: تنفيذ منطق التحقق من الرمز وتحديث كلمة المرور
        Response::success('تم تحديث كلمة المرور بنجاح (قيد التطوير)');
    }

    // =========================================================================
    // Helper Methods (دوال مساعدة داخلية)
    // =========================================================================

    /**
     * قراءة مدخلات JSON من جسم الطلب (Request Body)
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }

        $decoded = json_decode($input, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * استخراج Bearer Token من رأس الطلب
     */
    private function getBearerToken(): ?string
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
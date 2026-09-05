<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Exception;

/**
 * ============================================================================
 * Authentication Service
 * مسؤول عن: تسجيل الدخول، الجلسات، قفل الحسابات، تعدد الأجهزة
 * ============================================================================
 */
class AuthService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * تسجيل الدخول
     */
    public function login(string $username, string $password, string $ipAddress, string $userAgent, ?string $deviceId): array
    {
        // 1. جلب المستخدم
        $user = $this->db->fetch("
            SELECT u.*, r.name as role_name, r.display_name as role_display_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.username = ? AND u.deleted_at IS NULL
        ", [$username]);

        if (!$user) {
            $this->logLoginAttempt(null, $username, $ipAddress, $userAgent, $deviceId, false, 'USER_NOT_FOUND');
            throw new Exception('بيانات الدخول غير صحيحة');
        }

        // 2. التحقق من حالة الحساب
        if (!$user['is_active']) {
            $this->logLoginAttempt($user['id'], $username, $ipAddress, $userAgent, $deviceId, false, 'ACCOUNT_DISABLED');
            throw new Exception('هذا الحساب معطل. يرجى التواصل مع مدير النظام.');
        }

        // 3. التحقق من القفل
        if ($user['is_locked'] && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $this->logLoginAttempt($user['id'], $username, $ipAddress, $userAgent, $deviceId, false, 'ACCOUNT_LOCKED');
            $remainingMinutes = ceil((strtotime($user['locked_until']) - time()) / 60);
            throw new Exception("الحساب مقفل مؤقتاً. حاول مرة أخرى بعد {$remainingMinutes} دقيقة.");
        }

        // 4. التحقق من كلمة المرور
        if (!password_verify($password, $user['password_hash'])) {
            $this->incrementFailedAttempts($user['id']);
            $this->logLoginAttempt($user['id'], $username, $ipAddress, $userAgent, $deviceId, false, 'INVALID_PASSWORD');
            throw new Exception('بيانات الدخول غير صحيحة');
        }

        // 5. إدارة تعدد الأجهزة
        if (!$user['allow_multiple_devices']) {
            $this->db->execute("
                UPDATE user_sessions
                SET is_active = 0, revoked_at = NOW(), revoked_reason = 'NEW_LOGIN_SINGLE_DEVICE'
                WHERE user_id = ? AND is_active = 1
            ", [$user['id']]);
        }

        // 6. إنشاء جلسة جديدة
        $token = bin2hex(random_bytes(64)); // 128 حرف hex
        $expiresAt = date('Y-m-d H:i:s', time() + (int)($_ENV['SESSION_TIMEOUT'] ?? 1800));
        $deviceName = $this->parseDeviceName($userAgent);

        $this->db->execute("
            INSERT INTO user_sessions (
                user_id, token, device_id, device_name, ip_address, user_agent,
                expires_at, last_activity_at, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 1)
        ", [$user['id'], $token, $deviceId, $deviceName, $ipAddress, $userAgent, $expiresAt]);

        // 7. تحديث بيانات آخر دخول
        $this->db->execute("
            UPDATE users
            SET last_login_at = NOW(),
                last_login_ip = ?,
                failed_login_attempts = 0,
                is_locked = 0,
                locked_until = NULL
            WHERE id = ?
        ", [$ipAddress, $user['id']]);

        // 8. تسجيل نجاح الدخول
        $this->logLoginAttempt($user['id'], $username, $ipAddress, $userAgent, $deviceId, true, null);

        // 9. تنظيف البيانات الحساسة
        unset(
            $user['password_hash'],
            $user['security_answer_1_hash'],
            $user['security_answer_2_hash'],
            $user['security_answer_3_hash'],
            $user['remember_token']
        );

        return [
            'user' => $user,
            'token' => $token,
            'expires_at' => $expiresAt,
            'must_change_password' => (bool)$user['must_change_password']
        ];
    }

    /**
     * تسجيل الخروج
     */
    public function logout(string $token): void
    {
        $this->db->execute("
            UPDATE user_sessions
            SET is_active = 0, revoked_at = NOW(), revoked_reason = 'USER_LOGOUT'
            WHERE token = ? AND is_active = 1
        ", [$token]);
    }

    /**
     * التحقق من صلاحية الجلسة
     */
    public function validateSession(string $token): ?array
    {
        $session = $this->db->fetch("
            SELECT s.*, u.username, u.full_name, u.email, u.is_active as user_is_active,
                   r.name as role_name, r.display_name as role_display_name
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE s.token = ? AND s.is_active = 1 AND s.expires_at > NOW() AND u.deleted_at IS NULL
        ", [$token]);

        if (!$session) {
            return null;
        }

        if (!$session['user_is_active']) {
            // تعطيل الجلسة تلقائياً إذا تم تعطيل المستخدم
            $this->db->execute("
                UPDATE user_sessions
                SET is_active = 0, revoked_at = NOW(), revoked_reason = 'USER_DISABLED'
                WHERE id = ?
            ", [$session['id']]);
            return null;
        }

        // تحديث آخر نشاط
        $this->db->execute("
            UPDATE user_sessions SET last_activity_at = NOW() WHERE id = ?
        ", [$session['id']]);

        return $session;
    }

    /**
     * جلب جميع الجلسات النشطة للمستخدم
     */
    public function getUserSessions(int $userId): array
    {
        return $this->db->fetchAll("
            SELECT id, device_id, device_name, ip_address, user_agent,
                   last_activity_at, expires_at, is_active, created_at
            FROM user_sessions
            WHERE user_id = ? AND is_active = 1 AND expires_at > NOW()
            ORDER BY last_activity_at DESC
        ", [$userId]);
    }

    /**
     * إلغاء جلسة معينة
     */
    public function revokeSession(int $sessionId, int $currentUserId): void
    {
        // التحقق من أن الجلسة للمستخدم الحالي (لمنع IDOR)
        $session = $this->db->fetch("
            SELECT id, user_id FROM user_sessions WHERE id = ? AND is_active = 1
        ", [$sessionId]);

        if (!$session) {
            throw new Exception('الجلسة غير موجودة أو منتهية.');
        }

        // السماح للمستخدم بإلغاء جلساته فقط، أو للمدير إلغاء أي جلسة
        $currentUser = $this->db->fetch("
            SELECT role_id FROM users WHERE id = ?
        ", [$currentUserId]);

        $isAdmin = $this->db->fetch("
            SELECT COUNT(*) as count FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ? AND p.name = 'users.manage'
        ", [$currentUser['role_id']]);

        if ($session['user_id'] !== $currentUserId && !(int)$isAdmin['count'] === 0) {
            throw new Exception('لا يمكنك إلغاء جلسات مستخدمين آخرين.');
        }

        $this->db->execute("
            UPDATE user_sessions
            SET is_active = 0, revoked_at = NOW(), revoked_reason = 'MANUAL_REVOKED'
            WHERE id = ?
        ", [$sessionId]);
    }

    /**
     * تغيير كلمة المرور
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->db->fetch("
            SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL
        ", [$userId]);

        if (!$user) {
            throw new Exception('المستخدم غير موجود.');
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            throw new Exception('كلمة المرور الحالية غير صحيحة.');
        }

        // التحقق من قوة كلمة المرور
        if (strlen($newPassword) < 8) {
            throw new Exception('يجب أن تكون كلمة المرور 8 أحرف على الأقل.');
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        $this->db->execute("
            UPDATE users
            SET password_hash = ?, must_change_password = 0, updated_at = NOW()
            WHERE id = ?
        ", [$newHash, $userId]);

        // إلغاء جميع الجلسات الأخرى بعد تغيير كلمة المرور (أمان إضافي)
        $this->db->execute("
            UPDATE user_sessions
            SET is_active = 0, revoked_at = NOW(), revoked_reason = 'PASSWORD_CHANGED'
            WHERE user_id = ? AND is_active = 1
        ", [$userId]);
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function incrementFailedAttempts(int $userId): void
    {
        $maxAttempts = (int)($_ENV['AUTH_MAX_LOGIN_ATTEMPTS'] ?? 5);
        $lockoutMinutes = (int)($_ENV['AUTH_LOCKOUT_DURATION'] ?? 15);

        $this->db->execute("
            UPDATE users
            SET failed_login_attempts = failed_login_attempts + 1,
                is_locked = CASE WHEN failed_login_attempts + 1 >= ? THEN 1 ELSE 0 END,
                locked_until = CASE
                    WHEN failed_login_attempts + 1 >= ?
                    THEN DATE_ADD(NOW(), INTERVAL ? MINUTE)
                    ELSE NULL
                END
            WHERE id = ?
        ", [$maxAttempts, $maxAttempts, $lockoutMinutes, $userId]);
    }

    private function logLoginAttempt(
        ?int $userId,
        string $username,
        string $ip,
        string $agent,
        ?string $deviceId,
        bool $success,
        ?string $reason
    ): void {
        try {
            $this->db->execute("
                INSERT INTO login_history (user_id, username, ip_address, user_agent, device_id, is_success, failure_reason)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [$userId, $username, $ip, $agent, $deviceId, $success ? 1 : 0, $reason]);
        } catch (Exception $e) {
            // فشل تسجيل المحاولات لا يجب أن يكسر تسجيل الدخول
            error_log('[AUTH] Failed to log login attempt: ' . $e->getMessage());
        }
    }

    private function parseDeviceName(string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'Unknown Device';
        }

        $patterns = [
            '/Windows NT ([\d.]+)/' => 'Windows $1',
            '/Macintosh; .*Mac OS X ([\d_]+)/' => 'macOS $1',
            '/Linux; Android ([\d.]+)/' => 'Android $1',
            '/iPhone; CPU iPhone OS ([\d_]+)/' => 'iOS $1',
            '/iPad; CPU OS ([\d_]+)/' => 'iPadOS $1',
        ];

        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent, $matches)) {
                return $name;
            }
        }

        return 'Unknown Device';
    }
}
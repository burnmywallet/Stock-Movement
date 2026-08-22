<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/AuthController.php
// الوصف: متحكم المصادقة - تم إصلاح خطأ SQL بالكامل
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Session;
use Core\Audit;
use Exception;

class AuthController
{
    private $db;
    private $session;
    private $audit;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->session = new Session();
        $this->audit = new Audit();
    }

    /**
     * POST /api/auth/login - نسخة مُصلحة 100%
     */
    public function login(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $identifier = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $deviceName = $input['device_name'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'جهاز غير معروف';
            $ip = getClientIP();

            if (empty($identifier) || empty($password)) {
                errorResponse('يرجى إدخال اسم المستخدم وكلمة المرور', 400);
                return;
            }

            $pdo = $this->db->getConnection();

            // ✅ البحث عن المستخدم
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->audit->loginFailed($identifier, 'user_not_found');
                errorResponse('اسم المستخدم أو كلمة المرور غير صحيحة', 401);
                return;
            }

            if (!$user['is_active']) {
                $this->audit->loginFailed($identifier, 'account_inactive');
                errorResponse('الحساب غير نشط. الرجاء التواصل مع المسؤول', 403);
                return;
            }

            if ($user['deleted_at'] !== null) {
                $this->audit->loginFailed($identifier, 'account_deleted');
                errorResponse('الحساب غير موجود', 404);
                return;
            }

            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
                $this->audit->loginFailed($identifier, 'account_locked');
                errorResponse("الحساب مقفل مؤقتاً. حاول مرة أخرى بعد {$remaining} دقيقة", 403);
                return;
            }

            // ✅ التحقق من كلمة المرور
            $passwordValid = password_verify($password, $user['password_hash']);

            if (!$passwordValid) {
                $attempts = ($user['failed_login_attempts'] ?? 0) + 1;
                $maxAttempts = 5;
                
                if ($attempts >= $maxAttempts) {
                    $lockUntil = date('Y-m-d H:i:s', time() + 1800);
                    $updateStmt = $pdo->prepare("
                        UPDATE users 
                        SET failed_login_attempts = ?, 
                            is_locked = 1, 
                            locked_until = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$attempts, $lockUntil, $user['id']]);
                    $this->audit->loginFailed($identifier, 'account_locked_max_attempts');
                    errorResponse('تم قفل الحساب بسبب كثرة المحاولات الفاشلة. حاول مرة أخرى بعد 30 دقيقة', 403);
                    return;
                } else {
                    $updateStmt = $pdo->prepare("
                        UPDATE users 
                        SET failed_login_attempts = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$attempts, $user['id']]);
                }

                $this->audit->loginFailed($identifier, 'invalid_password');
                errorResponse('اسم المستخدم أو كلمة المرور غير صحيحة', 401);
                return;
            }

            // ✅ إنشاء التوكن
            $token = base64_encode($user['id'] . ':' . time() . ':' . bin2hex(random_bytes(16)));
            $refreshToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 86400);

            // ✅ إنشاء جلسة جديدة
            $deviceName = substr($deviceName, 0, 100);
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            $insertStmt = $pdo->prepare("
                INSERT INTO user_sessions (
                    user_id, session_token, refresh_token, device_name, 
                    ip_address, user_agent, expires_at, login_at, 
                    last_activity, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1, NOW())
            ");
            $insertStmt->execute([
                $user['id'],
                $token,
                $refreshToken,
                $deviceName,
                $ip,
                $userAgent,
                $expiresAt
            ]);
            $sessionId = $pdo->lastInsertId();

            // ✅ تحديث آخر تسجيل دخول
            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET last_login_at = NOW(),
                    last_login_ip = ?,
                    failed_login_attempts = 0,
                    is_locked = 0,
                    locked_until = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$ip, $user['id']]);

            // ✅ جلب اسم الدور
            $roleStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
            $roleStmt->execute([$user['role_id']]);
            $role = $roleStmt->fetch();

            // ✅ جلب الصلاحيات
            $permissionsStmt = $pdo->prepare("
                SELECT p.name FROM permissions p
                INNER JOIN role_permissions rp ON rp.permission_id = p.id
                WHERE rp.role_id = ? AND rp.is_allowed = 1
            ");
            $permissionsStmt->execute([$user['role_id']]);
            $permissions = $permissionsStmt->fetchAll(\PDO::FETCH_COLUMN);

            // ✅ تسجيل نجاح الدخول
            $this->audit->loginSuccess($user['id'], $user['username']);

            // ✅ تخزين في الجلسة
            $this->session->set('user', [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $role['name'] ?? 'user',
                'role_id' => $user['role_id'],
                'permissions' => $permissions
            ]);
            $this->session->set('auth_token', $token);
            $this->session->set('session_id', $sessionId);

            successResponse('تم تسجيل الدخول بنجاح', [
                'token' => $token,
                'refresh_token' => $refreshToken,
                'expires_in' => 86400,
                'session_id' => $sessionId,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $role['name'] ?? 'user',
                    'role_id' => $user['role_id'],
                    'permissions' => $permissions,
                    'avatar' => $user['avatar'] ?? '/assets/images/default-avatar.png'
                ]
            ]);

        } catch (Exception $e) {
            error_log('Login error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            errorResponse('حدث خطأ في تسجيل الدخول: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            $sessionId = $_REQUEST['session_id'] ?? null;
            $username = $_REQUEST['user']['username'] ?? 'غير معروف';

            if (!$userId) {
                errorResponse('المستخدم غير مسجل', 401);
                return;
            }

            if ($sessionId) {
                $pdo = $this->db->getConnection();
                $stmt = $pdo->prepare("
                    UPDATE user_sessions 
                    SET is_active = 0, 
                        logout_at = NOW(),
                        terminated_by = 'user',
                        terminated_reason = 'تسجيل خروج'
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$sessionId, $userId]);
            }

            $this->audit->logoutSuccess($userId, $username);
            $this->session->destroy();

            successResponse('تم تسجيل الخروج بنجاح');

        } catch (Exception $e) {
            error_log('Logout error: ' . $e->getMessage());
            errorResponse('حدث خطأ في تسجيل الخروج: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/auth/validate
     */
    public function validate(): void
    {
        try {
            $token = $this->getTokenFromRequest();
            
            if (!$token) {
                errorResponse('لم يتم توفير رمز المصادقة', 401);
                return;
            }

            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("
                SELECT 
                    s.*,
                    u.id as user_id,
                    u.username,
                    u.full_name,
                    u.email,
                    u.role_id,
                    u.is_active,
                    u.deleted_at,
                    r.name as role_name
                FROM user_sessions s
                INNER JOIN users u ON u.id = s.user_id
                INNER JOIN roles r ON r.id = u.role_id
                WHERE s.session_token = ? 
                  AND s.is_active = 1
                  AND s.expires_at > NOW()
                  AND u.is_active = 1
                  AND u.deleted_at IS NULL
            ");
            $stmt->execute([$token]);
            $session = $stmt->fetch();

            if (!$session) {
                errorResponse('الجلسة غير صالحة أو منتهية', 401);
                return;
            }

            // تحديث آخر نشاط
            $updateStmt = $pdo->prepare("
                UPDATE user_sessions 
                SET last_activity = NOW(), 
                    request_count = request_count + 1
                WHERE id = ?
            ");
            $updateStmt->execute([$session['id']]);

            // جلب الصلاحيات
            $permissionsStmt = $pdo->prepare("
                SELECT p.name FROM permissions p
                INNER JOIN role_permissions rp ON rp.permission_id = p.id
                WHERE rp.role_id = ? AND rp.is_allowed = 1
            ");
            $permissionsStmt->execute([$session['role_id']]);
            $permissions = $permissionsStmt->fetchAll(\PDO::FETCH_COLUMN);

            $this->session->set('user', [
                'id' => $session['user_id'],
                'username' => $session['username'],
                'full_name' => $session['full_name'],
                'email' => $session['email'],
                'role' => $session['role_name'],
                'role_id' => $session['role_id'],
                'permissions' => $permissions
            ]);
            $this->session->set('auth_token', $token);

            successResponse('الجلسة صالحة', [
                'user' => [
                    'id' => $session['user_id'],
                    'username' => $session['username'],
                    'full_name' => $session['full_name'],
                    'email' => $session['email'],
                    'role' => $session['role_name'],
                    'role_id' => $session['role_id'],
                    'permissions' => $permissions
                ],
                'session' => [
                    'id' => $session['id'],
                    'expires_at' => $session['expires_at'],
                    'last_activity' => $session['last_activity']
                ]
            ]);

        } catch (Exception $e) {
            error_log('Validate error: ' . $e->getMessage());
            errorResponse('حدث خطأ في التحقق من الجلسة: ' . $e->getMessage(), 500);
        }
    }

    private function getTokenFromRequest(): ?string
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (strpos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }
        return $_COOKIE['auth_token'] ?? null;
    }
}

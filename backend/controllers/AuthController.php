<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/controllers/AuthController.php
// الوصف: متحكم المصادقة - تسجيل الدخول والخروج وإدارة الجلسات
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

namespace Controllers;

use Core\Auth;
use Core\Database;
use Core\Session;
use Core\Audit;

class AuthController
{
    /**
     * @var Auth $auth - نظام المصادقة
     */
    private $auth;
    
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var Session $session - إدارة الجلسات
     */
    private $session;
    
    /**
     * @var Audit $audit - سجل التدقيق
     */
    private $audit;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->session = new Session();
        $this->audit = new Audit();
    }

    /**
     * POST /api/auth/login
     * تسجيل الدخول
     */
    public function login(): void
    {
        try {
            // جلب البيانات
            $input = json_decode(file_get_contents('php://input'), true);
            
            $identifier = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $deviceName = $input['device_name'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'غير معروف';
            $remember = $input['remember'] ?? false;

            // التحقق من البيانات
            if (empty($identifier) || empty($password)) {
                errorResponse('يرجى إدخال اسم المستخدم وكلمة المرور');
                return;
            }

            // محاولة تسجيل الدخول
            $result = $this->auth->login($identifier, $password, $deviceName);

            if ($result['success']) {
                // تسجيل في سجل التدقيق
                $this->audit->log(
                    $result['data']['user']['id'],
                    'LOGIN_SUCCESS',
                    'auth',
                    'تسجيل دخول ناجح',
                    [
                        'username' => $identifier,
                        'device' => $deviceName,
                        'ip' => getClientIP()
                    ]
                );

                // تخزين في الجلسة
                $this->session->set('user', $result['data']['user']);
                $this->session->set('auth_token', $result['data']['token']);

                // إذا كان remember، تمديد صلاحية الجلسة
                if ($remember) {
                    $this->extendSession($result['data']['user']['id']);
                }

                successResponse('تم تسجيل الدخول بنجاح', $result['data']);
                return;
            }

            // تسجيل محاولة فاشلة
            $this->audit->log(
                null,
                'LOGIN_FAILED',
                'auth',
                'محاولة تسجيل دخول فاشلة',
                [
                    'username' => $identifier,
                    'ip' => getClientIP()
                ]
            );

            errorResponse($result['message']);

        } catch (\Exception $e) {
            error_log('Login error: ' . $e->getMessage());
            errorResponse('حدث خطأ في تسجيل الدخول: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/auth/logout
     * تسجيل الخروج
     */
    public function logout(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            $sessionId = $_REQUEST['session_id'] ?? null;
            $username = $_REQUEST['user']['username'] ?? 'غير معروف';

            // إنهاء الجلسة
            $this->auth->logout($userId, $sessionId);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'LOGOUT',
                'auth',
                'تسجيل خروج',
                [
                    'username' => $username,
                    'ip' => getClientIP()
                ]
            );

            // إنهاء جلسة PHP
            $this->session->destroy();

            successResponse('تم تسجيل الخروج بنجاح');

        } catch (\Exception $e) {
            error_log('Logout error: ' . $e->getMessage());
            errorResponse('حدث خطأ في تسجيل الخروج: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/auth/validate
     * التحقق من صحة الجلسة
     */
    public function validate(): void
    {
        try {
            $token = $this->getTokenFromRequest();
            
            if (!$token) {
                errorResponse('لم يتم توفير رمز المصادقة', 401);
                return;
            }

            $session = $this->auth->validateSession($token);
            
            if ($session) {
                // تحديث الجلسة
                $this->session->set('user', [
                    'id' => $session['user_id'],
                    'username' => $session['username'],
                    'full_name' => $session['full_name'],
                    'email' => $session['email'],
                    'role' => $session['role_name']
                ]);

                successResponse('الجلسة صالحة', [
                    'user' => [
                        'id' => $session['user_id'],
                        'username' => $session['username'],
                        'full_name' => $session['full_name'],
                        'email' => $session['email'],
                        'role' => $session['role_name'],
                        'permissions' => $this->auth->getUserPermissions($session['user_id'])
                    ],
                    'session' => [
                        'id' => $session['id'],
                        'expires_at' => $session['expires_at'],
                        'last_activity' => $session['last_activity']
                    ]
                ]);
                return;
            }

            errorResponse('الجلسة غير صالحة أو منتهية', 401);

        } catch (\Exception $e) {
            error_log('Validate error: ' . $e->getMessage());
            errorResponse('حدث خطأ في التحقق من الجلسة: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/auth/refresh
     * تجديد رمز المصادقة
     */
    public function refresh(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $refreshToken = $input['refresh_token'] ?? '';

            if (empty($refreshToken)) {
                errorResponse('لم يتم توفير رمز التحديث');
                return;
            }

            $result = $this->auth->refreshToken($refreshToken);
            
            if ($result) {
                // تحديث التوكن في الجلسة
                $this->session->set('auth_token', $result['token']);
                
                successResponse('تم تجديد الرمز بنجاح', $result);
                return;
            }

            errorResponse('رمز التحديث غير صالح', 401);

        } catch (\Exception $e) {
            error_log('Refresh error: ' . $e->getMessage());
            errorResponse('حدث خطأ في تجديد الرمز: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/auth/change-password
     * تغيير كلمة المرور
     */
    public function changePassword(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('المستخدم غير مسجل', 401);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            $currentPassword = $input['current_password'] ?? '';
            $newPassword = $input['new_password'] ?? '';
            $confirmPassword = $input['confirm_password'] ?? '';

            // التحقق من البيانات
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                errorResponse('يرجى ملء جميع الحقول');
                return;
            }

            if ($newPassword !== $confirmPassword) {
                errorResponse('كلمة المرور الجديدة وتأكيدها غير متطابقين');
                return;
            }

            // تغيير كلمة المرور
            $result = $this->auth->changePassword($userId, $currentPassword, $newPassword);

            if ($result['success']) {
                $this->audit->log($userId, 'PASSWORD_CHANGED', 'auth', 'تغيير كلمة المرور');
                successResponse('تم تغيير كلمة المرور بنجاح. سيتم تسجيل الخروج من جميع الأجهزة');
                return;
            }

            errorResponse($result['message']);

        } catch (\Exception $e) {
            error_log('Change password error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/auth/forgot-password
     * طلب إعادة تعيين كلمة المرور
     */
    public function forgotPassword(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $email = $input['email'] ?? '';

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                errorResponse('يرجى إدخال بريد إلكتروني صحيح');
                return;
            }

            // التحقق من وجود المستخدم
            $user = $this->db->queryOne(
                "SELECT id, username, email, full_name FROM users 
                 WHERE email = :email AND deleted_at IS NULL",
                ['email' => $email]
            );
            
            if (!$user) {
                errorResponse('البريد الإلكتروني غير مسجل في النظام');
                return;
            }

            // توليد رمز إعادة التعيين
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // حفظ الرمز
            $this->db->update(
                'users',
                [
                    'password_reset_token' => $token,
                    'password_reset_expires' => $expiresAt,
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                ['id' => $user['id']]
            );

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $user['id'],
                'PASSWORD_RESET_REQUEST',
                'auth',
                'طلب إعادة تعيين كلمة المرور',
                ['email' => $email]
            );

            successResponse('تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني', [
                'email' => $email,
                'expires_in' => '1 hour'
            ]);

        } catch (\Exception $e) {
            error_log('Forgot password error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/auth/reset-password
     * إعادة تعيين كلمة المرور
     */
    public function resetPassword(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $token = $input['token'] ?? '';
            $password = $input['password'] ?? '';
            $confirmPassword = $input['confirm_password'] ?? '';

            if (empty($token) || empty($password) || empty($confirmPassword)) {
                errorResponse('يرجى ملء جميع الحقول');
                return;
            }

            if ($password !== $confirmPassword) {
                errorResponse('كلمة المرور وتأكيدها غير متطابقين');
                return;
            }

            // التحقق من صلاحية الرمز
            $user = $this->db->queryOne(
                "SELECT id, username, password_hash FROM users 
                 WHERE password_reset_token = :token 
                   AND password_reset_expires > NOW()
                   AND deleted_at IS NULL",
                ['token' => $token]
            );

            if (!$user) {
                errorResponse('رمز إعادة التعيين غير صحيح أو منتهي الصلاحية');
                return;
            }

            // تغيير كلمة المرور
            $result = $this->auth->resetPassword($user['id'], $password);

            if ($result['success']) {
                // مسح رمز إعادة التعيين
                $this->db->update(
                    'users',
                    [
                        'password_reset_token' => null,
                        'password_reset_expires' => null,
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    ['id' => $user['id']]
                );

                $this->audit->log(
                    $user['id'],
                    'PASSWORD_RESET',
                    'auth',
                    'إعادة تعيين كلمة المرور'
                );

                successResponse('تم إعادة تعيين كلمة المرور بنجاح. يرجى تسجيل الدخول بكلمة المرور الجديدة');
                return;
            }

            errorResponse($result['message']);

        } catch (\Exception $e) {
            error_log('Reset password error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/auth/sessions
     * جلب الجلسات النشطة للمستخدم
     */
    public function sessions(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('المستخدم غير مسجل', 401);
                return;
            }

            $sessions = $this->db->query(
                "SELECT id, device_name, device_type, ip_address, 
                        login_at, last_activity, expires_at,
                        is_active, trusted_device, security_score
                 FROM user_sessions 
                 WHERE user_id = :user_id 
                 ORDER BY last_activity DESC",
                ['user_id' => $userId]
            );

            successResponse('تم جلب الجلسات النشطة', $sessions);

        } catch (\Exception $e) {
            error_log('Sessions error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/auth/sessions/terminate
     * إنهاء جلسة محددة
     */
    public function terminateSession(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            $input = json_decode(file_get_contents('php://input'), true);
            $sessionId = $input['session_id'] ?? null;

            if (!$userId || !$sessionId) {
                errorResponse('بيانات غير مكتملة');
                return;
            }

            // التحقق من ملكية الجلسة
            $session = $this->db->queryOne(
                "SELECT id FROM user_sessions 
                 WHERE id = :id AND user_id = :user_id",
                ['id' => $sessionId, 'user_id' => $userId]
            );

            if (!$session) {
                errorResponse('الجلسة غير موجودة أو لا تنتمي لك');
                return;
            }

            // إنهاء الجلسة
            $this->db->update(
                'user_sessions',
                [
                    'is_active' => 0,
                    'logout_at' => date('Y-m-d H:i:s'),
                    'terminated_by' => 'user',
                    'terminated_reason' => 'تم إنهاء الجلسة يدوياً'
                ],
                ['id' => $sessionId]
            );

            $this->audit->log(
                $userId,
                'SESSION_TERMINATED',
                'auth',
                'تم إنهاء جلسة',
                ['session_id' => $sessionId]
            );

            successResponse('تم إنهاء الجلسة بنجاح');

        } catch (\Exception $e) {
            error_log('Terminate session error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/auth/sessions/terminate-all
     * إنهاء جميع الجلسات (باستثناء الحالية)
     */
    public function terminateAllSessions(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            $currentSessionId = $_REQUEST['session_id'] ?? null;

            if (!$userId) {
                errorResponse('المستخدم غير مسجل', 401);
                return;
            }

            // إنهاء جميع الجلسات باستثناء الحالية
            $params = ['user_id' => $userId];
            $sql = "UPDATE user_sessions 
                    SET is_active = 0, logout_at = NOW(),
                        terminated_by = 'user',
                        terminated_reason = 'تم إنهاء جميع الجلسات'
                    WHERE user_id = :user_id AND is_active = 1";
            
            if ($currentSessionId) {
                $sql .= " AND id != :session_id";
                $params['session_id'] = $currentSessionId;
            }
            
            $this->db->execute($sql, $params);

            $this->audit->log(
                $userId,
                'ALL_SESSIONS_TERMINATED',
                'auth',
                'تم إنهاء جميع الجلسات'
            );

            successResponse('تم إنهاء جميع الجلسات بنجاح');

        } catch (\Exception $e) {
            error_log('Terminate all sessions error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/auth/me
     * جلب معلومات المستخدم الحالي
     */
    public function me(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('المستخدم غير مسجل', 401);
                return;
            }

            $user = $this->db->queryOne(
                "SELECT u.*, r.name as role_name, r.display_name as role_display
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = :id AND u.deleted_at IS NULL",
                ['id' => $userId]
            );
            
            if (!$user) {
                errorResponse('المستخدم غير موجود');
                return;
            }

            // جلب الصلاحيات
            $permissions = $this->auth->getUserPermissions($userId);

            // جلب الجلسات النشطة
            $sessions = $this->db->query(
                "SELECT id, device_name, ip_address, login_at, last_activity 
                 FROM user_sessions 
                 WHERE user_id = :user_id AND is_active = 1
                 ORDER BY last_activity DESC",
                ['user_id' => $userId]
            );

            successResponse('تم جلب بيانات المستخدم', [
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role_name'],
                    'role_display' => $user['role_display'],
                    'is_active' => (bool)$user['is_active'],
                    'is_verified' => (bool)$user['is_verified'],
                    'last_login_at' => $user['last_login_at'],
                    'last_login_ip' => $user['last_login_ip'],
                    'created_at' => $user['created_at']
                ],
                'permissions' => $permissions,
                'active_sessions' => $sessions
            ]);

        } catch (\Exception $e) {
            error_log('Me error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    // ================================================================
    // دوال مساعدة خاصة
    // ================================================================

    /**
     * تمديد صلاحية الجلسة
     */
    private function extendSession(int $userId, int $sessionId = null): void
    {
        $timeout = (int)($_ENV['SESSION_TIMEOUT'] ?? 86400);
        $expiresAt = date('Y-m-d H:i:s', time() + $timeout);
        
        $params = [
            'user_id' => $userId,
            'expires_at' => $expiresAt
        ];
        
        $sql = "UPDATE user_sessions 
                SET expires_at = :expires_at, refreshed_at = NOW()
                WHERE user_id = :user_id AND is_active = 1";
        
        if ($sessionId) {
            $sql .= " AND id = :session_id";
            $params['session_id'] = $sessionId;
        }
        
        $this->db->execute($sql, $params);
    }

    /**
     * الحصول على التوكن من الطلب
     */
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

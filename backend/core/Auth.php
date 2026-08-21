<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/core/Auth.php
// الوصف: نظام المصادقة المتقدم مع صلاحيات RBAC
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

namespace Core;

use PDO;
use Exception;

class Auth
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var string $table - جدول المستخدمين
     */
    private $table = 'users';
    
    /**
     * @var int $maxLoginAttempts - الحد الأقصى لمحاولات الدخول
     */
    private $maxLoginAttempts = 5;
    
    /**
     * @var int $lockoutDuration - مدة القفل بالدقائق
     */
    private $lockoutDuration = 30;
    
    /**
     * @var int $sessionTimeout - مدة صلاحية الجلسة بالثواني
     */
    private $sessionTimeout = 86400;
    
    /**
     * @var string $jwtSecret - مفتاح JWT السري
     */
    private $jwtSecret;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->jwtSecret = $_ENV['JWT_SECRET'] ?? 'default_secret_key_change_me';
        $this->loadSettings();
    }

    /**
     * تحميل إعدادات المصادقة
     */
    private function loadSettings(): void
    {
        try {
            $settings = $this->db->query(
                "SELECT setting_key, setting_value FROM system_settings 
                 WHERE setting_key IN ('max_login_attempts', 'lockout_duration', 'session_timeout')"
            );
            
            foreach ($settings as $setting) {
                switch ($setting['setting_key']) {
                    case 'max_login_attempts':
                        $this->maxLoginAttempts = (int)$setting['setting_value'];
                        break;
                    case 'lockout_duration':
                        $this->lockoutDuration = (int)$setting['setting_value'];
                        break;
                    case 'session_timeout':
                        $this->sessionTimeout = (int)$setting['setting_value'];
                        break;
                }
            }
        } catch (Exception $e) {
            // استخدام القيم الافتراضية
        }
    }

    /**
     * تسجيل الدخول
     */
    public function login(string $identifier, string $password, string $deviceName = null): array
    {
        try {
            // البحث عن المستخدم
            $user = $this->findUser($identifier);
            
            if (!$user) {
                $this->logFailedAttempt($identifier);
                return [
                    'success' => false,
                    'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'
                ];
            }
            
            // التحقق من نشاط الحساب
            if (!$user['is_active']) {
                return [
                    'success' => false,
                    'message' => 'الحساب غير نشط. الرجاء التواصل مع المسؤول'
                ];
            }
            
            // التحقق من قفل الحساب
            if ($this->isAccountLocked($user['id'])) {
                $remaining = $this->getLockoutRemaining($user['id']);
                return [
                    'success' => false,
                    'message' => "الحساب مقفل مؤقتاً. حاول مرة أخرى بعد {$remaining} دقيقة"
                ];
            }
            
            // التحقق من كلمة المرور
            if (!$this->verifyPassword($password, $user['password_hash'], $user['username'])) {
                $this->handleFailedLogin($user['id']);
                return [
                    'success' => false,
                    'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'
                ];
            }
            
            // التحقق من صلاحية كلمة المرور
            if ($this->isPasswordExpired($user['id'])) {
                return [
                    'success' => false,
                    'message' => 'انتهت صلاحية كلمة المرور. يجب تغييرها قبل تسجيل الدخول',
                    'force_password_change' => true
                ];
            }
            
            // إنشاء الجلسة
            $sessionData = $this->createSession($user, $deviceName);
            
            // تحديث آخر تسجيل دخول
            $this->updateLastLogin($user['id']);
            
            // تسجيل نجاح الدخول
            $this->logAuth($user['id'], 'LOGIN_SUCCESS', [
                'device' => $deviceName,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]);
            
            // جلب الصلاحيات
            $permissions = $this->getUserPermissions($user['id']);
            $role = $this->getUserRole($user['role_id']);
            
            return [
                'success' => true,
                'message' => 'تم تسجيل الدخول بنجاح',
                'data' => [
                    'token' => $sessionData['token'],
                    'refresh_token' => $sessionData['refresh_token'],
                    'expires_in' => $this->sessionTimeout,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'full_name' => $user['full_name'],
                        'email' => $user['email'],
                        'role' => $role,
                        'permissions' => $permissions,
                        'avatar' => $this->getUserAvatar($user['id'])
                    ]
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ];
        }
    }

    /**
     * التحقق من صحة الجلسة
     */
    public function validateSession(string $token): ?array
    {
        try {
            $session = $this->db->queryOne("
                SELECT s.*, u.id as user_id, u.username, u.full_name, u.email, u.role_id,
                       r.name as role_name
                FROM user_sessions s
                INNER JOIN users u ON u.id = s.user_id
                INNER JOIN roles r ON r.id = u.role_id
                WHERE s.session_token = :token
                  AND s.is_active = 1
                  AND s.expires_at > NOW()
                  AND u.is_active = 1
                  AND u.deleted_at IS NULL
            ", ['token' => $token]);

            if (!$session) {
                return null;
            }

            // تحديث آخر نشاط
            $this->db->execute(
                "UPDATE user_sessions SET last_activity = NOW(), request_count = request_count + 1 WHERE id = :id",
                ['id' => $session['id']]
            );

            return $session;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * تجديد رمز المصادقة
     */
    public function refreshToken(string $refreshToken): ?array
    {
        try {
            $session = $this->db->queryOne("
                SELECT id, user_id FROM user_sessions 
                WHERE refresh_token = :token AND is_active = 1
            ", ['token' => $refreshToken]);

            if (!$session) {
                return null;
            }

            // توليد رمز جديد
            $newToken = $this->generateToken();
            $newRefreshToken = $this->generateToken();
            $expiresAt = date('Y-m-d H:i:s', time() + $this->sessionTimeout);

            $this->db->execute(
                "UPDATE user_sessions 
                 SET session_token = :new_token, 
                     refresh_token = :new_refresh,
                     expires_at = :expires_at,
                     refreshed_at = NOW()
                 WHERE id = :id",
                [
                    'new_token' => $newToken,
                    'new_refresh' => $newRefreshToken,
                    'expires_at' => $expiresAt,
                    'id' => $session['id']
                ]
            );

            return [
                'token' => $newToken,
                'refresh_token' => $newRefreshToken,
                'expires_in' => $this->sessionTimeout
            ];

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * تسجيل الخروج
     */
    public function logout(int $userId = null, int $sessionId = null): bool
    {
        try {
            if ($sessionId) {
                $this->db->execute(
                    "UPDATE user_sessions 
                     SET is_active = 0, logout_at = NOW() 
                     WHERE id = :id",
                    ['id' => $sessionId]
                );
            }
            
            if ($userId) {
                $this->db->execute(
                    "UPDATE user_sessions 
                     SET is_active = 0, logout_at = NOW() 
                     WHERE user_id = :user_id AND is_active = 1",
                    ['user_id' => $userId]
                );
            }
            
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * إنهاء جميع جلسات المستخدم
     */
    public function terminateAllSessions(int $userId, int $exceptSessionId = null): bool
    {
        try {
            $params = ['user_id' => $userId];
            $sql = "UPDATE user_sessions 
                    SET is_active = 0, logout_at = NOW(),
                        terminated_by = 'admin',
                        terminated_reason = 'تم إنهاء جميع الجلسات'
                    WHERE user_id = :user_id AND is_active = 1";
            
            if ($exceptSessionId) {
                $sql .= " AND id != :except_id";
                $params['except_id'] = $exceptSessionId;
            }
            
            $this->db->execute($sql, $params);
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * تغيير كلمة المرور
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return ['success' => false, 'message' => 'المستخدم غير موجود'];
            }

            // التحقق من كلمة المرور الحالية
            if ($currentPassword && !$this->verifyPassword($currentPassword, $user['password_hash'], $user['username'])) {
                return ['success' => false, 'message' => 'كلمة المرور الحالية غير صحيحة'];
            }

            // التحقق من قوة كلمة المرور
            if (!$this->isPasswordSecure($newPassword)) {
                return ['success' => false, 'message' => 'كلمة المرور ضعيفة. يجب أن تحتوي على 8 أحرف على الأقل، حرف كبير، حرف صغير، رقم، رمز خاص'];
            }

            // التحقق من عدم تكرار كلمة المرور
            if ($this->isPasswordReused($userId, $newPassword)) {
                return ['success' => false, 'message' => 'لا يمكن استخدام كلمة مرور مستخدمة سابقاً'];
            }

            // حفظ كلمة المرور القديمة في السجل
            $this->db->insert('password_history', [
                'user_id' => $userId,
                'password_hash' => $user['password_hash'],
                'changed_by' => $userId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'reason' => 'manual',
                'changed_at' => date('Y-m-d H:i:s')
            ]);

            // تحديث كلمة المرور
            $newHash = $this->hashPassword($newPassword, $user['username']);
            $this->db->update(
                $this->table,
                [
                    'password_hash' => $newHash,
                    'last_password_change' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                ['id' => $userId]
            );

            // إنهاء جميع الجلسات (إعادة تسجيل الدخول مطلوبة)
            $this->terminateAllSessions($userId);

            $this->logAuth($userId, 'PASSWORD_CHANGED', ['reason' => 'manual_change']);

            return ['success' => true, 'message' => 'تم تغيير كلمة المرور بنجاح'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }

    /**
     * إعادة تعيين كلمة المرور (بدون التحقق من القديمة)
     */
    public function resetPassword(int $userId, string $newPassword): array
    {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return ['success' => false, 'message' => 'المستخدم غير موجود'];
            }

            // التحقق من قوة كلمة المرور
            if (!$this->isPasswordSecure($newPassword)) {
                return ['success' => false, 'message' => 'كلمة المرور ضعيفة'];
            }

            // حفظ كلمة المرور القديمة في السجل
            $this->db->insert('password_history', [
                'user_id' => $userId,
                'password_hash' => $user['password_hash'],
                'changed_by' => $userId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'reason' => 'reset',
                'changed_at' => date('Y-m-d H:i:s')
            ]);

            // تحديث كلمة المرور
            $newHash = $this->hashPassword($newPassword, $user['username']);
            $this->db->update(
                $this->table,
                [
                    'password_hash' => $newHash,
                    'last_password_change' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                ['id' => $userId]
            );

            // إنهاء جميع الجلسات
            $this->terminateAllSessions($userId);

            $this->logAuth($userId, 'PASSWORD_RESET', ['reason' => 'admin_reset']);

            return ['success' => true, 'message' => 'تم إعادة تعيين كلمة المرور بنجاح'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }

    /**
     * التحقق من الصلاحية
     */
    public function hasPermission(int $userId, string $permission): bool
    {
        try {
            $permissions = $this->getUserPermissions($userId);
            return in_array($permission, $permissions) || in_array('admin', $permissions);
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * جلب صلاحيات المستخدم
     */
    public function getUserPermissions(int $userId): array
    {
        try {
            $permissions = $this->db->query("
                SELECT p.name FROM permissions p
                INNER JOIN role_permissions rp ON rp.permission_id = p.id
                INNER JOIN users u ON u.role_id = rp.role_id
                WHERE u.id = :user_id AND rp.is_allowed = 1
            ", ['user_id' => $userId]);
            
            return array_column($permissions, 'name');
            
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * جلب دور المستخدم
     */
    public function getUserRole(int $roleId): string
    {
        try {
            $role = $this->db->queryOne(
                "SELECT name FROM roles WHERE id = :id",
                ['id' => $roleId]
            );
            return $role ? $role['name'] : 'user';
            
        } catch (Exception $e) {
            return 'user';
        }
    }

    /**
     * جلب الصورة الشخصية للمستخدم
     */
    public function getUserAvatar(int $userId): string
    {
        try {
            $user = $this->db->queryOne(
                "SELECT avatar FROM users WHERE id = :id",
                ['id' => $userId]
            );
            return $user && $user['avatar'] ? $user['avatar'] : '/assets/images/default-avatar.png';
            
        } catch (Exception $e) {
            return '/assets/images/default-avatar.png';
        }
    }

    /**
     * إنشاء مستخدم جديد
     */
    public function createUser(array $data): array
    {
        try {
            // التحقق من وجود المستخدم
            $exists = $this->db->queryOne(
                "SELECT id FROM {$this->table} 
                 WHERE username = :username OR email = :email",
                ['username' => $data['username'], 'email' => $data['email']]
            );
            
            if ($exists) {
                return ['success' => false, 'message' => 'اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل'];
            }
            
            // التحقق من قوة كلمة المرور
            if (!$this->isPasswordSecure($data['password'])) {
                return ['success' => false, 'message' => 'كلمة المرور ضعيفة'];
            }
            
            // تجزئة كلمة المرور
            $data['password_hash'] = $this->hashPassword($data['password'], $data['username']);
            unset($data['password']);
            
            // إضافة المستخدم
            $data['created_at'] = date('Y-m-d H:i:s');
            $userId = $this->db->insert($this->table, $data);
            
            // تسجيل العملية
            $this->logAuth($userId, 'USER_CREATED', ['data' => $data]);
            
            return ['success' => true, 'message' => 'تم إنشاء المستخدم بنجاح', 'user_id' => $userId];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }

    /**
     * تحديث مستخدم
     */
    public function updateUser(int $userId, array $data): array
    {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return ['success' => false, 'message' => 'المستخدم غير موجود'];
            }

            // التحقق من عدم وجود اسم مستخدم مكرر
            if (isset($data['username']) && $data['username'] !== $user['username']) {
                $exists = $this->db->queryOne(
                    "SELECT id FROM {$this->table} WHERE username = :username AND id != :id",
                    ['username' => $data['username'], 'id' => $userId]
                );
                if ($exists) {
                    return ['success' => false, 'message' => 'اسم المستخدم مستخدم بالفعل'];
                }
            }

            // التحقق من عدم وجود بريد مكرر
            if (isset($data['email']) && $data['email'] !== $user['email']) {
                $exists = $this->db->queryOne(
                    "SELECT id FROM {$this->table} WHERE email = :email AND id != :id",
                    ['email' => $data['email'], 'id' => $userId]
                );
                if ($exists) {
                    return ['success' => false, 'message' => 'البريد الإلكتروني مستخدم بالفعل'];
                }
            }

            // تحديث كلمة المرور إذا تم توفيرها
            if (isset($data['password']) && !empty($data['password'])) {
                if (!$this->isPasswordSecure($data['password'])) {
                    return ['success' => false, 'message' => 'كلمة المرور ضعيفة'];
                }
                $data['password_hash'] = $this->hashPassword($data['password'], $user['username']);
                $data['last_password_change'] = date('Y-m-d H:i:s');
                unset($data['password']);
            }

            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update($this->table, $data, ['id' => $userId]);

            $this->logAuth($userId, 'USER_UPDATED', ['data' => $data]);

            return ['success' => true, 'message' => 'تم تحديث المستخدم بنجاح'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }

    /**
     * حذف مستخدم (حذف ناعم)
     */
    public function deleteUser(int $userId): array
    {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return ['success' => false, 'message' => 'المستخدم غير موجود'];
            }

            // منع حذف المستخدم الحالي
            if ($userId == $this->getCurrentUserId()) {
                return ['success' => false, 'message' => 'لا يمكن حذف المستخدم الحالي'];
            }

            $this->db->update($this->table, [
                'deleted_at' => date('Y-m-d H:i:s'),
                'is_active' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $userId]);

            // إنهاء جميع الجلسات
            $this->terminateAllSessions($userId);

            $this->logAuth($userId, 'USER_DELETED', ['user_id' => $userId]);

            return ['success' => true, 'message' => 'تم حذف المستخدم بنجاح'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }

    /**
     * قفل المستخدم
     */
    public function lockUser(int $userId): array
    {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return ['success' => false, 'message' => 'المستخدم غير موجود'];
            }

            $this->db->update($this->table, [
                'is_locked' => 1,
                'locked_until' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $userId]);

            // إنهاء جميع الجلسات
            $this->terminateAllSessions($userId);

            $this->logAuth($userId, 'USER_LOCKED', ['user_id' => $userId]);

            return ['success' => true, 'message' => 'تم قفل المستخدم بنجاح'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }

    /**
     * فتح قفل المستخدم
     */
    public function unlockUser(int $userId): array
    {
        try {
            $user = $this->getUserById($userId);
            if (!$user) {
                return ['success' => false, 'message' => 'المستخدم غير موجود'];
            }

            $this->db->update($this->table, [
                'is_locked' => 0,
                'locked_until' => null,
                'failed_login_attempts' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $userId]);

            $this->logAuth($userId, 'USER_UNLOCKED', ['user_id' => $userId]);

            return ['success' => true, 'message' => 'تم فتح قفل المستخدم بنجاح'];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }
    }

    /**
     * الحصول على المستخدم الحالي
     */
    public function getCurrentUser(): ?array
    {
        $token = $this->getTokenFromRequest();
        if (!$token) {
            return null;
        }

        $session = $this->validateSession($token);
        if (!$session) {
            return null;
        }

        return $this->getUserById($session['user_id']);
    }

    /**
     * الحصول على معرف المستخدم الحالي
     */
    public function getCurrentUserId(): ?int
    {
        $user = $this->getCurrentUser();
        return $user ? $user['id'] : null;
    }

    // ================================================================
    // دوال مساعدة خاصة
    // ================================================================

    /**
     * البحث عن مستخدم
     */
    private function findUser(string $identifier): ?array
    {
        return $this->db->queryOne("
            SELECT u.*, r.name as role_name
            FROM {$this->table} u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.username = :identifier
               OR u.email = :identifier
               OR u.employee_id = :identifier
            AND u.deleted_at IS NULL
        ", ['identifier' => $identifier]);
    }

    /**
     * الحصول على مستخدم بالمعرف
     */
    private function getUserById(int $id): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM {$this->table} WHERE id = :id AND deleted_at IS NULL",
            ['id' => $id]
        );
    }

    /**
     * التحقق من كلمة المرور
     */
    private function verifyPassword(string $password, string $hash, string $username): bool
    {
        // دعم bcrypt
        if (password_verify($password, $hash)) {
            return true;
        }
        
        // دعم التجزئة القديمة (للتراجع)
        $salt = substr($username, 0, 4);
        $hashCheck = hash('sha256', $password . $salt);
        if ($hashCheck === $hash) {
            return true;
        }
        
        return false;
    }

    /**
     * تجزئة كلمة المرور
     */
    private function hashPassword(string $password, string $username): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * التحقق من قفل الحساب
     */
    private function isAccountLocked(int $userId): bool
    {
        $result = $this->db->queryOne(
            "SELECT locked_until FROM {$this->table} WHERE id = :id",
            ['id' => $userId]
        );
        
        if (!$result || !$result['locked_until']) {
            return false;
        }
        
        return strtotime($result['locked_until']) > time();
    }

    /**
     * الحصول على الوقت المتبقي لقفل الحساب
     */
    private function getLockoutRemaining(int $userId): int
    {
        $result = $this->db->queryOne(
            "SELECT locked_until FROM {$this->table} WHERE id = :id",
            ['id' => $userId]
        );
        
        if (!$result || !$result['locked_until']) {
            return 0;
        }
        
        $remaining = ceil((strtotime($result['locked_until']) - time()) / 60);
        return max(0, $remaining);
    }

    /**
     * التحقق من انتهاء صلاحية كلمة المرور
     */
    private function isPasswordExpired(int $userId): bool
    {
        $result = $this->db->queryOne(
            "SELECT last_password_change, password_expiry_days 
             FROM {$this->table} WHERE id = :id",
            ['id' => $userId]
        );
        
        if (!$result || !$result['last_password_change']) {
            return true;
        }
        
        $expiryDays = $result['password_expiry_days'] ?? 90;
        $expiryDate = strtotime($result['last_password_change']) + ($expiryDays * 86400);
        
        return $expiryDate < time();
    }

    /**
     * التحقق من قوة كلمة المرور
     */
    private function isPasswordSecure(string $password): bool
    {
        // 8 أحرف على الأقل
        if (strlen($password) < 8) {
            return false;
        }
        
        // حرف كبير
        if (!preg_match('/[A-Z]/', $password)) {
            return false;
        }
        
        // حرف صغير
        if (!preg_match('/[a-z]/', $password)) {
            return false;
        }
        
        // رقم
        if (!preg_match('/[0-9]/', $password)) {
            return false;
        }
        
        // رمز خاص
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            return false;
        }
        
        return true;
    }

    /**
     * التحقق من عدم تكرار كلمة المرور
     */
    private function isPasswordReused(int $userId, string $password): bool
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            return false;
        }
        
        $history = $this->db->query(
            "SELECT password_hash FROM password_history 
             WHERE user_id = :user_id 
             ORDER BY changed_at DESC LIMIT 5",
            ['user_id' => $userId]
        );
        
        foreach ($history as $record) {
            if ($this->verifyPassword($password, $record['password_hash'], $user['username'])) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * معالجة محاولة دخول فاشلة
     */
    private function handleFailedLogin(int $userId): void
    {
        // زيادة عدد المحاولات
        $this->db->execute(
            "UPDATE {$this->table} 
             SET failed_login_attempts = failed_login_attempts + 1 
             WHERE id = :id",
            ['id' => $userId]
        );
        
        // التحقق من عدد المحاولات
        $result = $this->db->queryOne(
            "SELECT failed_login_attempts FROM {$this->table} WHERE id = :id",
            ['id' => $userId]
        );
        
        if ($result && $result['failed_login_attempts'] >= $this->maxLoginAttempts) {
            // قفل الحساب
            $lockUntil = date('Y-m-d H:i:s', time() + ($this->lockoutDuration * 60));
            $this->db->update(
                $this->table,
                [
                    'is_locked' => 1,
                    'locked_until' => $lockUntil
                ],
                ['id' => $userId]
            );
            
            $this->logAuth($userId, 'LOCKED', [
                'reason' => 'max_attempts_exceeded',
                'attempts' => $result['failed_login_attempts'] + 1
            ]);
        }
    }

    /**
     * تسجيل محاولة دخول فاشلة لمستخدم غير موجود
     */
    private function logFailedAttempt(string $identifier): void
    {
        $this->db->insert('auth_logs', [
            'username' => $identifier,
            'action' => 'LOGIN_FAILED',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'details' => json_encode(['reason' => 'user_not_found']),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * تسجيل حدث مصادقة
     */
    private function logAuth(int $userId, string $action, array $details = []): void
    {
        $user = $this->getUserById($userId);
        $this->db->insert('auth_logs', [
            'user_id' => $userId,
            'username' => $user['username'] ?? null,
            'action' => $action,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * إنشاء جلسة جديدة
     */
    private function createSession(array $user, ?string $deviceName): array
    {
        // توليد الرموز
        $token = $this->generateToken();
        $refreshToken = $this->generateToken();
        $expiresAt = date('Y-m-d H:i:s', time() + $this->sessionTimeout);
        
        // إنهاء الجلسات القديمة (Single Session)
        if ($this->isSingleSessionEnabled()) {
            $this->db->execute(
                "UPDATE user_sessions 
                 SET is_active = 0, logout_at = NOW() 
                 WHERE user_id = :user_id AND is_active = 1",
                ['user_id' => $user['id']]
            );
        }
        
        // إنشاء جلسة جديدة
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $sessionId = $this->db->insert('user_sessions', [
            'user_id' => $user['id'],
            'session_token' => $token,
            'refresh_token' => $refreshToken,
            'device_name' => $deviceName ?? $userAgent,
            'device_type' => $this->detectDeviceType($userAgent),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'expires_at' => $expiresAt,
            'login_at' => date('Y-m-d H:i:s'),
            'last_activity' => date('Y-m-d H:i:s'),
            'is_active' => 1,
            'security_score' => $this->calculateSecurityScore($ip, $userAgent)
        ]);
        
        return [
            'token' => $token,
            'refresh_token' => $refreshToken,
            'session_id' => $sessionId,
            'expires_at' => $expiresAt
        ];
    }

    /**
     * توليد رمز عشوائي
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * كشف نوع الجهاز
     */
    private function detectDeviceType(string $userAgent): string
    {
        if (strpos($userAgent, 'Mobile') !== false) {
            return 'mobile';
        }
        if (strpos($userAgent, 'Tablet') !== false) {
            return 'tablet';
        }
        if (strpos($userAgent, 'Windows') !== false || strpos($userAgent, 'Macintosh') !== false) {
            return 'desktop';
        }
        return 'unknown';
    }

    /**
     * حساب درجة أمان الجلسة
     */
    private function calculateSecurityScore(string $ip, string $userAgent): int
    {
        $score = 100;
        
        // خصم للأجهزة غير الموثوقة
        if (strpos($userAgent, 'Mobile') !== false) {
            $score -= 10;
        }
        
        // خصم للـ VPN
        if (strpos($ip, '10.') === 0 || strpos($ip, '172.') === 0 || strpos($ip, '192.168.') === 0) {
            $score += 5; // شبكة محلية أكثر أماناً
        } else {
            $score -= 15;
        }
        
        return max(0, $score);
    }

    /**
     * التحقق من تفعيل الجلسة الواحدة
     */
    private function isSingleSessionEnabled(): bool
    {
        $result = $this->db->queryOne(
            "SELECT setting_value FROM system_settings 
             WHERE setting_key = 'single_session_enabled'"
        );
        
        return $result && $result['setting_value'] === 'true';
    }

    /**
     * تحديث آخر تسجيل دخول
     */
    private function updateLastLogin(int $userId): void
    {
        $this->db->update(
            $this->table,
            [
                'last_login_at' => date('Y-m-d H:i:s'),
                'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'failed_login_attempts' => 0,
                'is_locked' => 0,
                'locked_until' => null
            ],
            ['id' => $userId]
        );
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

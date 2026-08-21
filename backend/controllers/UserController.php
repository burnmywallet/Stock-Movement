<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/controllers/UserController.php
// الوصف: متحكم إدارة المستخدمين - CRUD كامل مع صلاحيات متقدمة
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;

class UserController
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var Auth $auth - نظام المصادقة
     */
    private $auth;
    
    /**
     * @var Audit $audit - سجل التدقيق
     */
    private $audit;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        $this->audit = new Audit();
    }

    /**
     * GET /api/users
     * جلب قائمة المستخدمين مع فلترة وبحث متقدم
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية المشاهدة
            if (!$this->auth->hasPermission($userId, 'users.view')) {
                errorResponse('ليس لديك صلاحية لعرض المستخدمين', 403);
                return;
            }

            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $search = $_GET['search'] ?? '';
            $role = $_GET['role'] ?? '';
            $status = $_GET['status'] ?? '';
            $department = $_GET['department'] ?? '';
            $sort = $_GET['sort'] ?? 'created_at';
            $order = $_GET['order'] ?? 'DESC';

            $params = [];
            $where = ["u.deleted_at IS NULL"];
            
            if (!empty($search)) {
                $where[] = "(u.username LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search)";
                $params['search'] = "%{$search}%";
            }
            
            if (!empty($role)) {
                $where[] = "u.role_id = :role";
                $params['role'] = $role;
            }
            
            if ($status === 'active') {
                $where[] = "u.is_active = 1";
            } elseif ($status === 'inactive') {
                $where[] = "u.is_active = 0";
            } elseif ($status === 'locked') {
                $where[] = "u.is_locked = 1";
            }
            
            if (!empty($department)) {
                $where[] = "u.department LIKE :department";
                $params['department'] = "%{$department}%";
            }

            $allowedSorts = ['id', 'username', 'full_name', 'email', 'created_at', 'last_login_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'created_at';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب المستخدمين
            $users = $this->db->query("
                SELECT 
                    u.id,
                    u.username,
                    u.email,
                    u.full_name,
                    u.employee_id,
                    u.department,
                    u.phone,
                    u.mobile,
                    u.role_id,
                    r.name as role_name,
                    r.display_name as role_display,
                    u.is_active,
                    u.is_verified,
                    u.is_locked,
                    u.failed_login_attempts,
                    u.locked_until,
                    u.last_login_at,
                    u.last_login_ip,
                    u.last_password_change,
                    u.password_expiry_days,
                    u.created_at,
                    u.updated_at,
                    u.deleted_at,
                    (SELECT COUNT(*) FROM user_sessions WHERE user_id = u.id AND is_active = 1) as active_sessions,
                    (SELECT COUNT(*) FROM audit_logs WHERE user_id = u.id AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as activities_7d
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY u.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            // إجمالي المستخدمين
            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM users u
                WHERE " . implode(' AND ', $where) . "
            ", $params);

            // إحصائيات إضافية
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive,
                    COUNT(CASE WHEN is_locked = 1 THEN 1 END) as locked,
                    COUNT(CASE WHEN is_verified = 1 THEN 1 END) as verified,
                    COUNT(DISTINCT role_id) as roles_count,
                    COUNT(DISTINCT department) as departments_count
                FROM users
                WHERE deleted_at IS NULL
            ");

            successResponse('تم جلب قائمة المستخدمين', [
                'data' => $users,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'active' => (int)($stats['active'] ?? 0),
                    'inactive' => (int)($stats['inactive'] ?? 0),
                    'locked' => (int)($stats['locked'] ?? 0),
                    'verified' => (int)($stats['verified'] ?? 0),
                    'roles_count' => (int)($stats['roles_count'] ?? 0),
                    'departments_count' => (int)($stats['departments_count'] ?? 0)
                ],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil((int)$total / $limit)
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Users list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/users/{id}
     * جلب بيانات مستخدم مع تفاصيل كاملة
     */
    public function show(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية المشاهدة
            if (!$this->auth->hasPermission($userId, 'users.view')) {
                errorResponse('ليس لديك صلاحية لعرض المستخدمين', 403);
                return;
            }

            $user = $this->getUserById($id);
            
            if (!$user) {
                errorResponse('المستخدم غير موجود');
                return;
            }

            // جلب الصلاحيات
            $permissions = $this->auth->getUserPermissions($id);
            
            // جلب الجلسات النشطة
            $sessions = $this->db->query("
                SELECT 
                    id,
                    device_name,
                    device_type,
                    ip_address,
                    login_at,
                    last_activity,
                    expires_at,
                    is_active,
                    trusted_device,
                    security_score,
                    request_count
                FROM user_sessions 
                WHERE user_id = :user_id 
                ORDER BY last_activity DESC
            ", ['user_id' => $id]);

            // جلب سجل النشاط
            $activities = $this->db->query("
                SELECT 
                    id,
                    action,
                    module,
                    description,
                    details,
                    ip_address,
                    created_at
                FROM audit_logs 
                WHERE user_id = :user_id 
                ORDER BY created_at DESC 
                LIMIT 50
            ", ['user_id' => $id]);

            // جلب سجل تغييرات كلمة المرور
            $passwordHistory = $this->db->query("
                SELECT 
                    changed_at,
                    ip_address,
                    reason
                FROM password_history 
                WHERE user_id = :user_id 
                ORDER BY changed_at DESC 
                LIMIT 10
            ", ['user_id' => $id]);

            successResponse('تم جلب بيانات المستخدم', [
                'user' => $user,
                'permissions' => $permissions,
                'sessions' => $sessions,
                'recent_activities' => $activities,
                'password_history' => $passwordHistory
            ]);

        } catch (\Exception $e) {
            error_log('User show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/users/me
     * جلب بيانات المستخدم الحالي
     */
    public function me(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $user = $this->getUserById($userId);
            
            if (!$user) {
                errorResponse('المستخدم غير موجود');
                return;
            }

            // جلب الصلاحيات
            $permissions = $this->auth->getUserPermissions($userId);
            
            // جلب الجلسات النشطة
            $sessions = $this->db->query("
                SELECT 
                    id,
                    device_name,
                    device_type,
                    ip_address,
                    login_at,
                    last_activity,
                    expires_at,
                    is_active,
                    trusted_device
                FROM user_sessions 
                WHERE user_id = :user_id AND is_active = 1
                ORDER BY last_activity DESC
            ", ['user_id' => $userId]);

            successResponse('تم جلب بيانات المستخدم', [
                'user' => $user,
                'permissions' => $permissions,
                'active_sessions' => $sessions
            ]);

        } catch (\Exception $e) {
            error_log('Me error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/users
     * إنشاء مستخدم جديد
     */
    public function create(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية الإنشاء
            if (!$this->auth->hasPermission($userId, 'users.create')) {
                errorResponse('ليس لديك صلاحية لإنشاء مستخدمين', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateUserData($input);

            // التحقق من وجود المستخدم
            $exists = $this->db->queryOne(
                "SELECT id FROM users WHERE username = :username OR email = :email",
                ['username' => $input['username'], 'email' => $input['email']]
            );
            
            if ($exists) {
                errorResponse('اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل');
                return;
            }

            // إنشاء المستخدم
            $data = [
                'username' => $input['username'],
                'email' => $input['email'],
                'password_hash' => password_hash($input['password'], PASSWORD_DEFAULT),
                'full_name' => $input['full_name'],
                'role_id' => $input['role_id'],
                'employee_id' => $input['employee_id'] ?? null,
                'department' => $input['department'] ?? null,
                'phone' => $input['phone'] ?? null,
                'mobile' => $input['mobile'] ?? null,
                'is_active' => $input['is_active'] ?? 1,
                'is_verified' => $input['is_verified'] ?? 0,
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $newUserId = $this->db->insert('users', $data);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'USER_CREATED',
                'users',
                "إنشاء مستخدم جديد: {$input['username']}",
                ['username' => $input['username'], 'user_id' => $newUserId],
                'user',
                $newUserId
            );

            successResponse('تم إنشاء المستخدم بنجاح', ['user_id' => $newUserId]);

        } catch (\Exception $e) {
            error_log('User create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * PUT /api/users/{id}
     * تحديث بيانات مستخدم
     */
    public function update(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية التحديث
            if (!$this->auth->hasPermission($userId, 'users.edit')) {
                errorResponse('ليس لديك صلاحية لتحديث المستخدمين', 403);
                return;
            }

            $user = $this->getUserById($id);
            if (!$user) {
                errorResponse('المستخدم غير موجود');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateUserData($input, true);

            // التحقق من عدم وجود اسم مستخدم مكرر
            if (isset($input['username']) && $input['username'] !== $user['username']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM users WHERE username = :username AND id != :id",
                    ['username' => $input['username'], 'id' => $id]
                );
                if ($exists) {
                    errorResponse('اسم المستخدم مستخدم بالفعل');
                    return;
                }
            }

            // التحقق من عدم وجود بريد مكرر
            if (isset($input['email']) && $input['email'] !== $user['email']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM users WHERE email = :email AND id != :id",
                    ['email' => $input['email'], 'id' => $id]
                );
                if ($exists) {
                    errorResponse('البريد الإلكتروني مستخدم بالفعل');
                    return;
                }
            }

            // تحديث البيانات
            $data = [
                'username' => $input['username'] ?? $user['username'],
                'email' => $input['email'] ?? $user['email'],
                'full_name' => $input['full_name'] ?? $user['full_name'],
                'role_id' => $input['role_id'] ?? $user['role_id'],
                'employee_id' => $input['employee_id'] ?? $user['employee_id'],
                'department' => $input['department'] ?? $user['department'],
                'phone' => $input['phone'] ?? $user['phone'],
                'mobile' => $input['mobile'] ?? $user['mobile'],
                'is_active' => $input['is_active'] ?? $user['is_active'],
                'is_verified' => $input['is_verified'] ?? $user['is_verified'],
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // تحديث كلمة المرور إذا تم توفيرها
            if (!empty($input['password'])) {
                $data['password_hash'] = password_hash($input['password'], PASSWORD_DEFAULT);
                $data['last_password_change'] = date('Y-m-d H:i:s');
            }

            $this->db->update('users', $data, ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'USER_UPDATED',
                'users',
                "تحديث بيانات المستخدم: {$user['username']}",
                ['username' => $user['username'], 'user_id' => $id],
                'user',
                $id
            );

            successResponse('تم تحديث بيانات المستخدم بنجاح');

        } catch (\Exception $e) {
            error_log('User update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/users/{id}
     * حذف مستخدم (حذف ناعم)
     */
    public function delete(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية الحذف
            if (!$this->auth->hasPermission($userId, 'users.delete')) {
                errorResponse('ليس لديك صلاحية لحذف المستخدمين', 403);
                return;
            }

            $user = $this->getUserById($id);
            if (!$user) {
                errorResponse('المستخدم غير موجود');
                return;
            }

            // منع حذف المستخدم الحالي
            if ($id == $userId) {
                errorResponse('لا يمكن حذف المستخدم الحالي');
                return;
            }

            // منع حذف المستخدمين الإداريين
            if ($user['role_name'] === 'admin') {
                errorResponse('لا يمكن حذف مستخدم إداري');
                return;
            }

            // الحذف الناعم
            $this->db->softDelete('users', ['id' => $id]);

            // إنهاء جميع جلسات المستخدم
            $this->db->update(
                'user_sessions',
                [
                    'is_active' => 0,
                    'logout_at' => date('Y-m-d H:i:s'),
                    'terminated_by' => 'admin',
                    'terminated_reason' => 'تم حذف المستخدم'
                ],
                ['user_id' => $id]
            );

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'USER_DELETED',
                'users',
                "حذف المستخدم: {$user['username']}",
                ['username' => $user['username'], 'user_id' => $id],
                'user',
                $id
            );

            successResponse('تم حذف المستخدم بنجاح');

        } catch (\Exception $e) {
            error_log('User delete error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/users/{id}/restore
     * استعادة مستخدم محذوف
     */
    public function restore(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية التحديث
            if (!$this->auth->hasPermission($userId, 'users.edit')) {
                errorResponse('ليس لديك صلاحية لاستعادة المستخدمين', 403);
                return;
            }

            $user = $this->db->queryOne(
                "SELECT id, username FROM users WHERE id = :id AND deleted_at IS NOT NULL",
                ['id' => $id]
            );
            
            if (!$user) {
                errorResponse('المستخدم غير موجود أو غير محذوف');
                return;
            }

            // استعادة المستخدم
            $this->db->update(
                'users',
                ['deleted_at' => null, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id]
            );

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'USER_RESTORED',
                'users',
                "استعادة المستخدم: {$user['username']}",
                ['username' => $user['username'], 'user_id' => $id],
                'user',
                $id
            );

            successResponse('تم استعادة المستخدم بنجاح');

        } catch (\Exception $e) {
            error_log('User restore error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/users/{id}/lock
     * قفل المستخدم
     */
    public function lock(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية التحديث
            if (!$this->auth->hasPermission($userId, 'users.edit')) {
                errorResponse('ليس لديك صلاحية لقفل المستخدمين', 403);
                return;
            }

            $user = $this->getUserById($id);
            if (!$user) {
                errorResponse('المستخدم غير موجود');
                return;
            }

            // منع قفل المستخدم الحالي
            if ($id == $userId) {
                errorResponse('لا يمكن قفل المستخدم الحالي');
                return;
            }

            $this->db->update(
                'users',
                [
                    'is_locked' => 1,
                    'locked_until' => date('Y-m-d H:i:s', strtotime('+1 day')),
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                ['id' => $id]
            );

            // إنهاء جميع الجلسات
            $this->db->update(
                'user_sessions',
                [
                    'is_active' => 0,
                    'logout_at' => date('Y-m-d H:i:s'),
                    'terminated_by' => 'admin',
                    'terminated_reason' => 'تم قفل المستخدم'
                ],
                ['user_id' => $id]
            );

            $this->audit->log(
                $userId,
                'USER_LOCKED',
                'users',
                "قفل المستخدم: {$user['username']}",
                ['username' => $user['username'], 'user_id' => $id],
                'user',
                $id
            );

            successResponse('تم قفل المستخدم بنجاح');

        } catch (\Exception $e) {
            error_log('User lock error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/users/{id}/unlock
     * فتح قفل المستخدم
     */
    public function unlock(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية التحديث
            if (!$this->auth->hasPermission($userId, 'users.edit')) {
                errorResponse('ليس لديك صلاحية لفتح قفل المستخدمين', 403);
                return;
            }

            $user = $this->getUserById($id);
            if (!$user) {
                errorResponse('المستخدم غير موجود');
                return;
            }

            $this->db->update(
                'users',
                [
                    'is_locked' => 0,
                    'locked_until' => null,
                    'failed_login_attempts' => 0,
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                ['id' => $id]
            );

            $this->audit->log(
                $userId,
                'USER_UNLOCKED',
                'users',
                "فتح قفل المستخدم: {$user['username']}",
                ['username' => $user['username'], 'user_id' => $id],
                'user',
                $id
            );

            successResponse('تم فتح قفل المستخدم بنجاح');

        } catch (\Exception $e) {
            error_log('User unlock error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * PUT /api/users/{id}/permissions
     * تحديث صلاحيات المستخدم
     */
    public function permissions(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية إدارة الصلاحيات
            if (!$this->auth->hasPermission($userId, 'users.permissions')) {
                errorResponse('ليس لديك صلاحية لتعديل صلاحيات المستخدمين', 403);
                return;
            }

            $user = $this->getUserById($id);
            if (!$user) {
                errorResponse('المستخدم غير موجود');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $permissions = $input['permissions'] ?? [];
            $roleId = $input['role_id'] ?? null;

            // إذا تم تغيير الدور
            if ($roleId && $roleId != $user['role_id']) {
                // التحقق من وجود الدور
                $role = $this->db->queryValue(
                    "SELECT id FROM roles WHERE id = :id",
                    ['id' => $roleId]
                );
                
                if (!$role) {
                    errorResponse('الدور غير موجود');
                    return;
                }

                // تحديث دور المستخدم
                $this->db->update(
                    'users',
                    ['role_id' => $roleId, 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $id]
                );

                $this->audit->log(
                    $userId,
                    'USER_ROLE_CHANGED',
                    'users',
                    "تغيير دور المستخدم: {$user['username']}",
                    [
                        'username' => $user['username'],
                        'user_id' => $id,
                        'old_role' => $user['role_id'],
                        'new_role' => $roleId
                    ],
                    'user',
                    $id
                );
            }

            // تحديث الصلاحيات المباشرة (إذا كان النظام يدعمها)
            if (!empty($permissions)) {
                // حذف الصلاحيات القديمة
                $this->db->delete('user_permissions', ['user_id' => $id]);
                
                // إضافة الصلاحيات الجديدة
                foreach ($permissions as $permissionId) {
                    $this->db->insert('user_permissions', [
                        'user_id' => $id,
                        'permission_id' => $permissionId,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }

                $this->audit->log(
                    $userId,
                    'USER_PERMISSIONS_CHANGED',
                    'users',
                    "تغيير صلاحيات المستخدم: {$user['username']}",
                    [
                        'username' => $user['username'],
                        'user_id' => $id,
                        'permissions' => $permissions
                    ],
                    'user',
                    $id
                );
            }

            successResponse('تم تحديث صلاحيات المستخدم بنجاح');

        } catch (\Exception $e) {
            error_log('User permissions error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/users/{id}/permissions
     * جلب صلاحيات المستخدم
     */
    public function getPermissions(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية المشاهدة
            if (!$this->auth->hasPermission($userId, 'users.view')) {
                errorResponse('ليس لديك صلاحية لعرض الصلاحيات', 403);
                return;
            }

            $user = $this->getUserById($id);
            if (!$user) {
                errorResponse('المستخدم غير موجود');
                return;
            }

            $permissions = $this->auth->getUserPermissions($id);
            $allPermissions = $this->db->query("
                SELECT id, name, display_name, module, description
                FROM permissions
                ORDER BY module, name
            ");

            successResponse('تم جلب صلاحيات المستخدم', [
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role_name']
                ],
                'permissions' => $permissions,
                'all_permissions' => $allPermissions
            ]);

        } catch (\Exception $e) {
            error_log('Get permissions error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/users/{id}/change-password
     * تغيير كلمة المرور (للمستخدم العادي)
     */
    public function changePassword(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من أن المستخدم يغير كلمة مروره الخاصة أو لديه صلاحية
            if ($id != $userId && !$this->auth->hasPermission($userId, 'users.edit')) {
                errorResponse('ليس لديك صلاحية لتغيير كلمة مرور الآخرين', 403);
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

            if (strlen($newPassword) < 8) {
                errorResponse('كلمة المرور يجب أن تكون 8 أحرف على الأقل');
                return;
            }

            // تغيير كلمة المرور
            $result = $this->auth->changePassword($id, $currentPassword, $newPassword);

            if ($result['success']) {
                $this->audit->log(
                    $userId,
                    'PASSWORD_CHANGED',
                    'users',
                    "تغيير كلمة المرور",
                    ['user_id' => $id],
                    'user',
                    $id
                );
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
     * GET /api/users/{id}/activities
     * جلب سجل نشاط المستخدم
     */
    public function activities(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية المشاهدة
            if (!$this->auth->hasPermission($userId, 'users.view')) {
                errorResponse('ليس لديك صلاحية لعرض النشاطات', 403);
                return;
            }

            $limit = (int)($_GET['limit'] ?? 50);
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            $activities = $this->db->query("
                SELECT 
                    id,
                    action,
                    module,
                    description,
                    details,
                    ip_address,
                    user_agent,
                    created_at
                FROM audit_logs 
                WHERE user_id = :user_id
                  AND created_at BETWEEN :from_date AND :to_date
                ORDER BY created_at DESC
                LIMIT :limit
            ", [
                'user_id' => $id,
                'from_date' => $fromDate . ' 00:00:00',
                'to_date' => $toDate . ' 23:59:59',
                'limit' => $limit
            ]);

            // إحصائيات النشاط
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(DISTINCT action) as unique_actions,
                    COUNT(DISTINCT DATE(created_at)) as active_days
                FROM audit_logs 
                WHERE user_id = :user_id
                  AND created_at BETWEEN :from_date AND :to_date
            ", [
                'user_id' => $id,
                'from_date' => $fromDate . ' 00:00:00',
                'to_date' => $toDate . ' 23:59:59'
            ]);

            successResponse('تم جلب سجل نشاط المستخدم', [
                'data' => $activities,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'unique_actions' => (int)($stats['unique_actions'] ?? 0),
                    'active_days' => (int)($stats['active_days'] ?? 0)
                ]
            ]);

        } catch (\Exception $e) {
            error_log('User activities error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/users/{id}/sessions
     * جلب جلسات المستخدم النشطة
     */
    public function sessions(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية المشاهدة
            if (!$this->auth->hasPermission($userId, 'users.view')) {
                errorResponse('ليس لديك صلاحية لعرض الجلسات', 403);
                return;
            }

            $sessions = $this->db->query("
                SELECT 
                    id,
                    device_name,
                    device_type,
                    ip_address,
                    login_at,
                    last_activity,
                    expires_at,
                    is_active,
                    trusted_device,
                    security_score,
                    request_count,
                    CASE 
                        WHEN expires_at <= NOW() THEN 'منتهية'
                        WHEN is_active = 0 THEN 'غير نشطة'
                        WHEN TIMESTAMPDIFF(MINUTE, last_activity, NOW()) > 30 THEN 'خاملة'
                        ELSE 'نشطة'
                    END as session_status
                FROM user_sessions 
                WHERE user_id = :user_id
                ORDER BY last_activity DESC
            ", ['user_id' => $id]);

            successResponse('تم جلب جلسات المستخدم', $sessions);

        } catch (\Exception $e) {
            error_log('User sessions error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على مستخدم بالمعرف
     */
    private function getUserById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                u.*,
                r.name as role_name,
                r.display_name as role_display,
                r.description as role_description,
                (SELECT COUNT(*) FROM user_sessions WHERE user_id = u.id AND is_active = 1) as active_sessions
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
        ", ['id' => $id]);
    }

    /**
     * التحقق من صحة بيانات المستخدم
     */
    private function validateUserData(array $data, bool $isUpdate = false): void
    {
        if (!$isUpdate) {
            if (empty($data['username'])) {
                errorResponse('اسم المستخدم مطلوب');
                return;
            }
            
            if (empty($data['email'])) {
                errorResponse('البريد الإلكتروني مطلوب');
                return;
            }
            
            if (empty($data['password'])) {
                errorResponse('كلمة المرور مطلوبة');
                return;
            }
            
            if (strlen($data['password']) < 8) {
                errorResponse('كلمة المرور يجب أن تكون 8 أحرف على الأقل');
                return;
            }
        }
        
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            errorResponse('البريد الإلكتروني غير صحيح');
            return;
        }
        
        if (!empty($data['phone']) && !preg_match('/^[0-9+\-\s()]{7,20}$/', $data['phone'])) {
            errorResponse('رقم الهاتف غير صحيح');
            return;
        }
        
        if (empty($data['full_name'])) {
            errorResponse('الاسم الكامل مطلوب');
            return;
        }
        
        if (empty($data['role_id'])) {
            errorResponse('الدور مطلوب');
            return;
        }
        
        // التحقق من وجود الدور
        $role = $this->db->queryValue(
            "SELECT id FROM roles WHERE id = :id",
            ['id' => $data['role_id']]
        );
        
        if (!$role) {
            errorResponse('الدور غير موجود');
            return;
        }
    }
}

<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/middleware/PermissionMiddleware.php
// الوصف: ميدل وير التحقق من الصلاحيات المتقدم - RBAC مع رسائل احترافية
// التاريخ: 2026-08-22
// ================================================================

namespace Middleware;

use Core\Auth;
use Core\Audit;
use Core\Database;
use Exception;

class PermissionMiddleware
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
     * @var Audit $audit - سجل التدقيق
     */
    private $audit;
    
    /**
     * @var array $requiredPermissions - الصلاحيات المطلوبة
     */
    private $requiredPermissions = [];
    
    /**
     * @var string $mode - وضع التحقق (all, any)
     */
    private $mode = 'all';
    
    /**
     * @var bool $strict - وضع صارم (يمنع الوصول حتى لو كان المستخدم Admin)
     */
    private $strict = false;
    
    /**
     * @var array $excludedRoutes - المسارات المستثناة من التحقق
     */
    private $excludedRoutes = [];

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->audit = new Audit();
    }

    /**
     * معالجة الطلب - التحقق من الصلاحيات
     */
    public function handle(array $params = []): bool
    {
        $userId = $_REQUEST['user_id'] ?? null;
        
        if (!$userId) {
            $this->forbidden('يجب تسجيل الدخول أولاً');
            return false;
        }

        // التحقق من المسارات المستثناة
        $path = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($path, PHP_URL_PATH);
        if ($this->isExcludedRoute($path)) {
            return true;
        }

        // الحصول على الصلاحيات المطلوبة من المعلمات
        $permissions = $params['permissions'] ?? $params[0] ?? null;
        
        if (empty($permissions)) {
            // لا توجد صلاحيات مطلوبة - نسمح بالمرور
            return true;
        }

        // تحويل إلى مصفوفة
        if (is_string($permissions)) {
            $permissions = [$permissions];
        }

        // تخزين للاستخدام في الرسائل
        $this->requiredPermissions = $permissions;

        // التحقق من الصلاحيات
        $hasPermission = $this->checkPermissions($userId, $permissions);
        
        if (!$hasPermission) {
            $this->logPermissionDenied($userId, $permissions);
            $this->forbidden('ليس لديك صلاحية للقيام بهذه العملية', $permissions);
            return false;
        }

        return true;
    }

    /**
     * التحقق من الصلاحيات مع التوريث
     */
    private function checkPermissions(int $userId, array $permissions): bool
    {
        $mode = $this->mode;
        
        // إذا كان المستخدم Admin وفي وضع غير صارم، يسمح بكل شيء
        if (!$this->strict && $this->auth->hasPermission($userId, 'admin')) {
            return true;
        }
        
        foreach ($permissions as $permission) {
            $has = $this->auth->hasPermission($userId, $permission);
            
            if ($mode === 'all' && !$has) {
                return false;
            }
            
            if ($mode === 'any' && $has) {
                return true;
            }
        }
        
        return $mode === 'all';
    }

    /**
     * التحقق من الصلاحية مع تفاصيل إضافية
     */
    public function checkPermissionWithDetails(int $userId, string $permission): array
    {
        try {
            // جلب تفاصيل المستخدم
            $user = $this->db->queryOne("
                SELECT u.id, u.username, u.full_name, u.role_id, r.name as role_name
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.id = :id AND u.deleted_at IS NULL
            ", ['id' => $userId]);

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'المستخدم غير موجود',
                    'code' => 'USER_NOT_FOUND'
                ];
            }

            // جلب الصلاحية
            $perm = $this->db->queryOne("
                SELECT id, name, display_name, module, description
                FROM permissions
                WHERE name = :name
            ", ['name' => $permission]);

            if (!$perm) {
                return [
                    'success' => false,
                    'message' => 'الصلاحية غير موجودة',
                    'code' => 'PERMISSION_NOT_FOUND'
                ];
            }

            // التحقق من الصلاحية
            $hasPermission = $this->auth->hasPermission($userId, $permission);

            // جلب الصلاحيات التي يملكها المستخدم
            $userPermissions = $this->auth->getUserPermissionsHierarchical($userId);

            return [
                'success' => $hasPermission,
                'message' => $hasPermission ? 'لديك الصلاحية' : 'ليس لديك الصلاحية',
                'data' => [
                    'user' => $user,
                    'permission' => $perm,
                    'has_permission' => $hasPermission,
                    'user_permissions' => $userPermissions,
                    'required_permission' => $permission
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'حدث خطأ: ' . $e->getMessage(),
                'code' => 'PERMISSION_CHECK_ERROR'
            ];
        }
    }

    /**
     * جلب جميع صلاحيات المستخدم
     */
    public function getUserAllPermissions(int $userId): array
    {
        try {
            $permissions = $this->auth->getUserPermissionsHierarchical($userId);
            
            // جلب تفاصيل كل صلاحية
            $details = [];
            foreach ($permissions as $perm) {
                $detail = $this->db->queryOne("
                    SELECT id, name, display_name, module, description
                    FROM permissions
                    WHERE name = :name
                ", ['name' => $perm]);
                if ($detail) {
                    $details[] = $detail;
                }
            }
            
            return $details;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * تسجيل رفض الصلاحية
     */
    private function logPermissionDenied(int $userId, array $permissions): void
    {
        try {
            $path = $_SERVER['REQUEST_URI'] ?? '';
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            
            // جلب اسم المستخدم
            $user = $this->db->queryOne(
                "SELECT username, full_name FROM users WHERE id = :id",
                ['id' => $userId]
            );
            
            $this->audit->log(
                $userId,
                'PERMISSION_DENIED',
                'rbac',
                'محاولة وصول بدون صلاحية',
                [
                    'path' => $path,
                    'method' => $method,
                    'required_permissions' => $permissions,
                    'mode' => $this->mode,
                    'ip' => $ip,
                    'username' => $user['username'] ?? null,
                    'full_name' => $user['full_name'] ?? null
                ]
            );
        } catch (Exception $e) {
            // السكوت عن الخطأ
        }
    }

    /**
     * إرسال استجابة ممنوعة (403) مع رسالة احترافية
     */
    private function forbidden(string $message, array $permissions = []): void
    {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'message' => $message,
            'code' => 'FORBIDDEN',
            'timestamp' => date('Y-m-d H:i:s'),
            'details' => [
                'required_permissions' => $permissions,
                'mode' => $this->mode,
                'suggestion' => 'يرجى التواصل مع مدير النظام للحصول على الصلاحيات المطلوبة'
            ]
        ];
        
        // إذا كان هناك مستخدم مسجل، نضيف معلومات إضافية
        if (isset($_REQUEST['user_id'])) {
            $userId = $_REQUEST['user_id'];
            $userPermissions = $this->auth->getUserPermissionsHierarchical($userId);
            $response['details']['user_permissions'] = $userPermissions;
            $response['details']['user_id'] = $userId;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * تعيين وضع التحقق (all/any)
     */
    public function setMode(string $mode): self
    {
        $this->mode = $mode === 'any' ? 'any' : 'all';
        return $this;
    }

    /**
     * تعيين الوضع الصارم
     */
    public function setStrict(bool $strict): self
    {
        $this->strict = $strict;
        return $this;
    }

    /**
     * تعيين الصلاحيات المطلوبة
     */
    public function setPermissions(array $permissions): self
    {
        $this->requiredPermissions = $permissions;
        return $this;
    }

    /**
     * إضافة صلاحية مطلوبة
     */
    public function addPermission(string $permission): self
    {
        if (!in_array($permission, $this->requiredPermissions)) {
            $this->requiredPermissions[] = $permission;
        }
        return $this;
    }

    /**
     * إزالة صلاحية مطلوبة
     */
    public function removePermission(string $permission): self
    {
        $key = array_search($permission, $this->requiredPermissions);
        if ($key !== false) {
            unset($this->requiredPermissions[$key]);
        }
        return $this;
    }

    /**
     * إضافة مسار مستثنى
     */
    public function addExcludedRoute(string $route): self
    {
        if (!in_array($route, $this->excludedRoutes)) {
            $this->excludedRoutes[] = $route;
        }
        return $this;
    }

    /**
     * التحقق من المسار المستثنى
     */
    private function isExcludedRoute(string $path): bool
    {
        foreach ($this->excludedRoutes as $excluded) {
            if ($path === $excluded || strpos($path, $excluded) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * الحصول على الصلاحيات المطلوبة
     */
    public function getRequiredPermissions(): array
    {
        return $this->requiredPermissions;
    }

    /**
     * الحصول على وضع التحقق
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * التحقق من وجود صلاحية معينة
     */
    public function hasPermission(int $userId, string $permission): bool
    {
        return $this->auth->hasPermission($userId, $permission);
    }

    /**
     * جلب جميع الصلاحيات المتاحة في النظام
     */
    public function getAllAvailablePermissions(): array
    {
        try {
            return $this->db->query("
                SELECT id, name, display_name, module, sub_module, description
                FROM permissions
                ORDER BY module, name
            ");
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * جلب الصلاحيات حسب الوحدة
     */
    public function getPermissionsByModule(string $module): array
    {
        try {
            return $this->db->query("
                SELECT id, name, display_name, description
                FROM permissions
                WHERE module = :module
                ORDER BY name
            ", ['module' => $module]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * إنشاء رسالة مخصصة لرفض الصلاحية
     */
    public function generateDeniedMessage(string $permission): string
    {
        $messages = [
            'admin' => 'هذه العملية تتطلب صلاحيات المدير العام',
            'products.create' => 'ليس لديك صلاحية لإنشاء أصناف جديدة',
            'products.edit' => 'ليس لديك صلاحية لتعديل الأصناف',
            'products.delete' => 'ليس لديك صلاحية لحذف الأصناف',
            'warehouses.create' => 'ليس لديك صلاحية لإنشاء مخازن جديدة',
            'warehouses.edit' => 'ليس لديك صلاحية لتعديل المخازن',
            'warehouses.delete' => 'ليس لديك صلاحية لحذف المخازن',
            'users.create' => 'ليس لديك صلاحية لإنشاء مستخدمين جدد',
            'users.edit' => 'ليس لديك صلاحية لتعديل المستخدمين',
            'users.delete' => 'ليس لديك صلاحية لحذف المستخدمين',
            'users.permissions' => 'ليس لديك صلاحية لتعديل صلاحيات المستخدمين',
            'receipts.create' => 'ليس لديك صلاحية لإنشاء إذون استلام',
            'receipts.approve' => 'ليس لديك صلاحية لاعتماد إذون الاستلام',
            'issues.create' => 'ليس لديك صلاحية لإنشاء إذون صرف',
            'issues.approve' => 'ليس لديك صلاحية لاعتماد إذون الصرف',
            'transfers.create' => 'ليس لديك صلاحية لإنشاء تحويلات',
            'transfers.approve' => 'ليس لديك صلاحية لاعتماد التحويلات',
            'returns.create' => 'ليس لديك صلاحية لإنشاء مرتجعات',
            'returns.approve' => 'ليس لديك صلاحية لاعتماد المرتجعات',
            'inventory.create' => 'ليس لديك صلاحية لبدء جلسات الجرد',
            'inventory.approve' => 'ليس لديك صلاحية لاعتماد جلسات الجرد',
            'reports.view' => 'ليس لديك صلاحية لعرض التقارير',
            'reports.export' => 'ليس لديك صلاحية لتصدير التقارير',
            'settings.edit' => 'ليس لديك صلاحية لتعديل إعدادات النظام',
            'backup.create' => 'ليس لديك صلاحية لإنشاء نسخ احتياطية',
            'backup.restore' => 'ليس لديك صلاحية لاستعادة النسخ الاحتياطية',
        ];
        
        return $messages[$permission] ?? 'ليس لديك صلاحية للقيام بهذه العملية';
    }
}

// ================================================================
// انتهى الملف
// ================================================================

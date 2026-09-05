<?php

/**
 * ================================================================
 * Logistox - Permission Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/PermissionController.php
 * الوظيفة: إدارة الصلاحيات وتعيينها للأدوار
 *
 * المسؤوليات:
 * 1. عرض كل الصلاحيات مجمعة حسب الموديول (index)
 * 2. عرض صلاحيات دور معين (role)
 * 3. تحديث صلاحيات دور (updateRole)
 * 4. عرض صلاحيات مستخدم معين (userPermissions)
 * 5. التحقق من صلاحيات مستخدم (checkPermission)
 *
 * الصلاحيات المطلوبة:
 * - permissions.view: عرض الصلاحيات
 * - permissions.manage: إدارة صلاحيات الأدوار
 *
 * قيود الحماية:
 * - منع تعديل صلاحيات الدور الرئيسي (admin - role_id = 1)
 * - منع منح صلاحيات غير موجودة
 * - التحقق من وجود الدور قبل التعديل
 * - التحديث الذري (Transaction) لمنع البيانات الجزئية
 * - تسجيل كل تغيير في audit_logs مع old_values و new_values
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\PermissionService;
use App\Services\RoleService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class PermissionController
 *
 * Controller لإدارة الصلاحيات وتعيينها للأدوار
 */
class PermissionController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var PermissionService خدمة الصلاحيات
     */
    private PermissionService $permissionService;

    /**
     * @var RoleService خدمة الأدوار (للتحقق من الأدوار النظامية)
     */
    private RoleService $roleService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var int معرف الدور الرئيسي المحمي
     */
    private const PROTECTED_ADMIN_ROLE_ID = 1;

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->permissionService = new PermissionService($this->db);
            $this->roleService = new RoleService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[PERMISSION_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة الصلاحيات');
        }
    }

    // =========================================================================
    // 1. عرض كل الصلاحيات مجمعة حسب الموديول (Index)
    // =========================================================================

    /**
     * عرض كل الصلاحيات مجمعة حسب الموديول
     *
     * GET /api/permissions
     *
     * النتيجة:
     * {
     *   "products": [
     *     { "id": 1, "name": "products.view", "display_name": "عرض الأصناف", ... },
     *     { "id": 2, "name": "products.create", ... },
     *     ...
     *   ],
     *   "warehouses": [ ... ],
     *   ...
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            $permissions = $this->permissionService->getAllGroupedByModule();

            // حساب الإجماليات
            $totalPermissions = 0;
            $totalModules = count($permissions);
            foreach ($permissions as $modulePermissions) {
                $totalPermissions += count($modulePermissions);
            }

            Response::success(
                message: 'تم جلب الصلاحيات بنجاح',
                data: [
                    'permissions'       => $permissions,
                    'total_modules'     => $totalModules,
                    'total_permissions' => $totalPermissions,
                ]
            );

        } catch (Throwable $e) {
            error_log('[PERMISSION_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الصلاحيات');
        }
    }

    // =========================================================================
    // 2. عرض صلاحيات دور معين (Role)
    // =========================================================================

    /**
     * عرض صلاحيات دور معين
     *
     * GET /api/permissions/role/{roleId}
     *
     * @param array $params المعاملات من Router (مثل ['role_id' => 2])
     * @return void يرسل استجابة JSON
     */
    public function role(array $params): void
    {
        try {
            // 1. التحقق من معرف الدور
            $roleId = $this->validateRoleId($params);

            // 2. التحقق من وجود الدور
            $role = $this->roleService->getById($roleId);
            if (!$role) {
                Response::notFound('الدور غير موجود');
            }

            // 3. جلب صلاحيات الدور
            $permissions = $this->permissionService->getRolePermissions($roleId);

            // 4. جلب كل الصلاحيات (للمقارنة وعرض غير الممنوحة)
            $allPermissions = $this->permissionService->getAllGroupedByModule();

            // 5. إنشاء خريطة للصلاحيات الممنوحة
            $grantedPermissionIds = array_column($permissions, 'id');

            // 6. إضافة حقل "granted" لكل صلاحية
            foreach ($allPermissions as $module => &$modulePermissions) {
                foreach ($modulePermissions as &$permission) {
                    $permission['granted'] = in_array($permission['id'], $grantedPermissionIds, true);
                }
            }

            Response::success(
                message: 'تم جلب صلاحيات الدور بنجاح',
                data: [
                    'role'                => $role,
                    'granted_permissions' => $permissions,
                    'all_permissions'     => $allPermissions,
                    'granted_count'       => count($permissions),
                ]
            );

        } catch (Throwable $e) {
            error_log('[PERMISSION_CONTROLLER] Role failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب صلاحيات الدور');
        }
    }

    // =========================================================================
    // 3. تحديث صلاحيات دور (Update Role) - الأهم!
    // =========================================================================

    /**
     * تحديث صلاحيات دور معين
     *
     * PUT /api/permissions/role/{roleId}
     *
     * Request Body (JSON):
     * {
     *   "permission_ids": [1, 2, 3, 5, 8, 12, 15]
     * }
     *
     * ملاحظة:
     * - هذه العملية تستبدل كل الصلاحيات القديمة لنفس الدور
     * - يجب إرسال قائمة كاملة بالصلاحيات المطلوبة
     * - العملية تتم في Transaction لضمان الذرية
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function updateRole(array $params): void
    {
        try {
            // 1. التحقق من معرف الدور
            $roleId = $this->validateRoleId($params);

            // 2. التحقق من وجود الدور
            $role = $this->roleService->getById($roleId);
            if (!$role) {
                Response::notFound('الدور غير موجود');
            }

            // 3. حماية الدور الرئيسي (admin) من التعديل
            if ($roleId === self::PROTECTED_ADMIN_ROLE_ID) {
                Response::forbidden(
                    'لا يمكن تعديل صلاحيات الدور الرئيسي (admin). ' .
                    'هذا الدور يملك كل الصلاحيات تلقائياً ولا يمكن تجريده منها.'
                );
            }

            // 4. حماية الأدوار النظامية الأخرى
            if ($role['is_system']) {
                Response::forbidden(
                    "لا يمكن تعديل صلاحيات الدور النظامي '{$role['display_name']}'. " .
                    'الأدوار النظامية محمية من التعديل.'
                );
            }

            // 5. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 6. التحقق من صحة البيانات
            $validationErrors = $this->validatePermissionIds($input);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات الصلاحيات غير صالحة');
            }

            $permissionIds = $input['permission_ids'] ?? [];

            // 7. التحقق من وجود كل الصلاحيات المقدمة
            $invalidPermissionIds = $this->validatePermissionsExist($permissionIds);
            if (!empty($invalidPermissionIds)) {
                Response::badRequest(
                    'بعض معرفات الصلاحيات غير موجودة: ' . implode(', ', $invalidPermissionIds)
                );
            }

            // 8. جلب الصلاحيات القديمة (للتدقيق)
            $oldPermissions = $this->permissionService->getRolePermissions($roleId);
            $oldPermissionIds = array_column($oldPermissions, 'id');
            sort($oldPermissionIds);

            // 9. تحديث الصلاحيات (يتم في Transaction داخل الـ Service)
            $this->permissionService->updateRolePermissions($roleId, $permissionIds);

            // 10. جلب الصلاحيات الجديدة
            $newPermissions = $this->permissionService->getRolePermissions($roleId);
            $newPermissionIds = array_column($newPermissions, 'id');
            sort($newPermissionIds);

            // 11. حساب الفرق (الممنوحة الجديدة والمحذوفة)
            $addedPermissions = array_diff($newPermissionIds, $oldPermissionIds);
            $removedPermissions = array_diff($oldPermissionIds, $newPermissionIds);

            // 12. تسجيل العملية في audit_logs
            $currentUserId = $this->getCurrentUserId();

            $descriptionParts = ["تم تحديث صلاحيات الدور: {$role['display_name']}"];
            if (count($addedPermissions) > 0) {
                $descriptionParts[] = "أُضيفت " . count($addedPermissions) . " صلاحية";
            }
            if (count($removedPermissions) > 0) {
                $descriptionParts[] = "أُزيلت " . count($removedPermissions) . " صلاحية";
            }

            $this->auditService->log(
                userId: $currentUserId,
                action: 'ROLE_PERMISSIONS_UPDATE',
                entityType: 'role',
                entityId: $roleId,
                oldValues: ['permission_ids' => $oldPermissionIds],
                newValues: ['permission_ids' => $newPermissionIds],
                description: implode(' - ', $descriptionParts),
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 13. مسح Cache الصلاحيات (لضمان تحميل الصلاحيات الجديدة)
            \App\Middleware\PermissionMiddleware::clearCache($roleId);

            // 14. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تحديث صلاحيات الدور بنجاح',
                data: [
                    'role'                => $role,
                    'permissions'         => $newPermissions,
                    'granted_count'       => count($newPermissions),
                    'added_count'         => count($addedPermissions),
                    'removed_count'       => count($removedPermissions),
                    'added_permissions'   => array_values($addedPermissions),
                    'removed_permissions' => array_values($removedPermissions),
                ]
            );

        } catch (Throwable $e) {
            error_log('[PERMISSION_CONTROLLER] UpdateRole failed: ' . $e->getMessage());
            Response::internalError('فشل في تحديث صلاحيات الدور: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 4. عرض صلاحيات مستخدم معين (User Permissions)
    // =========================================================================

    /**
     * عرض صلاحيات مستخدم معين
     *
     * GET /api/permissions/user/{userId}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function userPermissions(array $params): void
    {
        try {
            $userId = $params['user_id'] ?? null;
            if ($userId === null || !is_numeric($userId) || (int) $userId <= 0) {
                Response::badRequest('معرف المستخدم غير صالح');
            }
            $userId = (int) $userId;

            // التحقق من وجود المستخدم
            $userService = new \App\Services\UserService($this->db);
            $user = $userService->getById($userId);
            if (!$user) {
                Response::notFound('المستخدم غير موجود');
            }

            // جلب صلاحيات المستخدم
            $permissions = $this->permissionService->getUserPermissions($userId);

            Response::success(
                message: 'تم جلب صلاحيات المستخدم بنجاح',
                data: [
                    'user'        => [
                        'id'       => $user['id'],
                        'username' => $user['username'],
                        'full_name'=> $user['full_name'],
                        'role_name'=> $user['role_display_name'] ?? $user['role_name'] ?? 'unknown',
                    ],
                    'permissions' => $permissions,
                    'count'       => count($permissions),
                ]
            );

        } catch (Throwable $e) {
            error_log('[PERMISSION_CONTROLLER] UserPermissions failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب صلاحيات المستخدم');
        }
    }

    // =========================================================================
    // 5. التحقق من صلاحية مستخدم (Check Permission)
    // =========================================================================

    /**
     * التحقق من أن مستخدم معين يملك صلاحية محددة
     *
     * GET /api/permissions/check?user_id=X&permission=Y
     *
     * @return void يرسل استجابة JSON
     */
    public function checkPermission(): void
    {
        try {
            $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
            $permissionName = $_GET['permission'] ?? null;

            if ($userId === null || $userId <= 0) {
                Response::badRequest('معرف المستخدم (user_id) مطلوب');
            }

            if (empty($permissionName)) {
                Response::badRequest('اسم الصلاحية (permission) مطلوب');
            }

            // التحقق من وجود المستخدم
            $userService = new \App\Services\UserService($this->db);
            $user = $userService->getById($userId);
            if (!$user) {
                Response::notFound('المستخدم غير موجود');
            }

            // التحقق من الصلاحية
            $hasPermission = $this->permissionService->userHasPermission($userId, $permissionName);

            Response::success(
                message: $hasPermission ? 'المستخدم يملك الصلاحية' : 'المستخدم لا يملك الصلاحية',
                data: [
                    'user_id'    => $userId,
                    'username'   => $user['username'],
                    'permission' => $permissionName,
                    'has_permission' => $hasPermission,
                ]
            );

        } catch (Throwable $e) {
            error_log('[PERMISSION_CONTROLLER] CheckPermission failed: ' . $e->getMessage());
            Response::internalError('فشل في التحقق من الصلاحية');
        }
    }

    // =========================================================================
    // Helper Methods (دوال مساعدة)
    // =========================================================================

    /**
     * قراءة مدخلات JSON من جسم الطلب
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
     * التحقق من صحة معرف الدور
     */
    private function validateRoleId(array $params): int
    {
        $id = $params['role_id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف الدور غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة مصفوفة معرفات الصلاحيات
     *
     * @param array $data البيانات المدخلة
     * @return array مصفوفة الأخطاء
     */
    private function validatePermissionIds(array $data): array
    {
        $errors = [];

        // 1. التحقق من وجود permission_ids
        if (!array_key_exists('permission_ids', $data)) {
            $errors['permission_ids'] = 'حقل permission_ids مطلوب';
            return $errors;
        }

        // 2. التحقق من أن permission_ids مصفوفة
        if (!is_array($data['permission_ids'])) {
            $errors['permission_ids'] = 'permission_ids يجب أن يكون مصفوفة';
            return $errors;
        }

        // 3. التحقق من أن كل عنصر رقم موجب
        foreach ($data['permission_ids'] as $index => $id) {
            if (!is_numeric($id) || (int) $id <= 0) {
                $errors["permission_ids.{$index}"] = "معرف الصلاحية في الموقع {$index} غير صالح";
            }
        }

        // 4. التحقق من عدم وجود تكرار
        $uniqueIds = array_unique($data['permission_ids']);
        if (count($uniqueIds) !== count($data['permission_ids'])) {
            $errors['permission_ids'] = 'يوجد معرفات صلاحيات مكررة';
        }

        // 5. التحقق من الحد الأقصى (لحماية النظام من الحمل الزائد)
        if (count($data['permission_ids']) > 200) {
            $errors['permission_ids'] = 'لا يمكن تعيين أكثر من 200 صلاحية لدور واحد';
        }

        return $errors;
    }

    /**
     * التحقق من وجود كل معرفات الصلاحيات في قاعدة البيانات
     *
     * @param array $permissionIds معرفات الصلاحيات
     * @return array معرفات الصلاحيات غير الموجودة
     */
    private function validatePermissionsExist(array $permissionIds): array
    {
        if (empty($permissionIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));

        $existingIds = $this->db->select(
            "SELECT id FROM permissions WHERE id IN ({$placeholders})",
            $permissionIds
        );

        $existingIdList = array_column($existingIds, 'id');
        $invalidIds = array_diff($permissionIds, $existingIdList);

        return array_values($invalidIds);
    }

    /**
     * جلب معرف المستخدم الحالي
     */
    private function getCurrentUserId(): int
    {
        if (isset($_REQUEST['user']['id'])) {
            return (int) $_REQUEST['user']['id'];
        }

        if (isset($GLOBALS['current_user_id'])) {
            return (int) $GLOBALS['current_user_id'];
        }

        error_log('[PERMISSION_CONTROLLER] Current user ID not found');
        Response::unauthorized('لم يتم العثور على بيانات المستخدم. يرجى تسجيل الدخول مرة أخرى.');
    }

    /**
     * جلب IP العميل
     */
    private function getClientIp(): string
    {
        $trustProxy = filter_var(
            getenv('TRUST_PROXY') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );

        if ($trustProxy) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $firstIp = trim($ips[0]);
                if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                    return $firstIp;
                }
            }

            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = trim($_SERVER['HTTP_X_REAL_IP']);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = trim($_SERVER['REMOTE_ADDR']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }
}
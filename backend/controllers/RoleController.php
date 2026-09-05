<?php

/**
 * ================================================================
 * Logistox - Role Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/RoleController.php
 * الوظيفة: إدارة الأدوار (CRUD)
 *
 * الصلاحيات المطلوبة:
 * - roles.view: عرض الأدوار
 * - roles.create: إنشاء دور
 * - roles.update: تعديل دور
 * - roles.delete: حذف دور
 *
 * ملاحظات هامة:
 * - يعتمد على RoleService لتنفيذ منطق الأعمال
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يحمي الأدوار النظامية (is_system = 1) من الحذف
 * - يمنع حذف دور مرتبط بمستخدمين
 * - إدارة الصلاحيات الفعلية في PermissionController
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\RoleService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class RoleController
 *
 * Controller لإدارة الأدوار
 */
class RoleController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var RoleService خدمة الأدوار
     */
    private RoleService $roleService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->roleService = new RoleService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[ROLE_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة الأدوار');
        }
    }

    // =========================================================================
    // 1. عرض قائمة الأدوار (Index)
    // =========================================================================

    /**
     * عرض قائمة الأدوار مع الفلاتر
     *
     * GET /api/roles
     *
     * Query Parameters:
     * - search: بحث في name, display_name, description
     * - is_system: تصفية حسب النوع (1 = نظامي، 0 = مخصص)
     * - is_active: تصفية حسب الحالة (1 = نشط، 0 = معطل)
     * - sort_by: ترتيب حسب (name, display_name, created_at, updated_at)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            $filters = [
                'search'     => trim($_GET['search'] ?? ''),
                'is_system'  => isset($_GET['is_system']) ? (int) $_GET['is_system'] : null,
                'is_active'  => isset($_GET['is_active']) ? (int) $_GET['is_active'] : null,
                'sort_by'    => $_GET['sort_by'] ?? 'name',
                'sort_order' => strtolower($_GET['sort_order'] ?? 'asc'),
            ];

            $roles = $this->roleService->list($filters);

            Response::success(
                message: 'تم جلب قائمة الأدوار بنجاح',
                data: [
                    'count' => count($roles),
                    'roles' => $roles,
                ]
            );

        } catch (Throwable $e) {
            error_log('[ROLE_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة الأدوار');
        }
    }

    // =========================================================================
    // 2. إضافة دور جديد (Store)
    // =========================================================================

    /**
     * إضافة دور جديد
     *
     * POST /api/roles
     *
     * Request Body (JSON):
     * {
     *   "name": "accountant",
     *   "display_name": "محاسب",
     *   "description": "دور المحاسبين في النظام",
     *   "is_system": false,
     *   "is_active": true
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function store(): void
    {
        try {
            $input = $this->getJsonInput();

            $validationErrors = $this->validateRoleData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات الدور غير صالحة');
            }

            $roleId = $this->roleService->create($input);
            $role = $this->roleService->getById($roleId);

            $currentUserId = $this->getCurrentUserId();

            $this->auditService->log(
                userId: $currentUserId,
                action: 'ROLE_CREATE',
                entityType: 'role',
                entityId: $roleId,
                newValues: [
                    'name'         => $role['name'],
                    'display_name' => $role['display_name'],
                    'is_system'    => $role['is_system'],
                ],
                description: "تم إنشاء دور جديد: {$role['display_name']} ({$role['name']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::created(
                message: 'تم إنشاء الدور بنجاح',
                data: ['role' => $role],
                location: "/api/roles/{$roleId}"
            );

        } catch (Throwable $e) {
            error_log('[ROLE_CONTROLLER] Store failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            Response::internalError('فشل في إنشاء الدور: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. عرض تفاصيل دور (Show)
    // =========================================================================

    /**
     * عرض تفاصيل دور معين
     *
     * GET /api/roles/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            $roleId = $this->validateRoleId($params);
            $role = $this->roleService->getById($roleId);

            if (!$role) {
                Response::notFound('الدور غير موجود');
            }

            Response::success(
                message: 'تم جلب تفاصيل الدور بنجاح',
                data: ['role' => $role]
            );

        } catch (Throwable $e) {
            error_log('[ROLE_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل الدور');
        }
    }

    // =========================================================================
    // 4. تعديل دور (Update)
    // =========================================================================

    /**
     * تعديل دور موجود
     *
     * PUT /api/roles/{id}
     * PATCH /api/roles/{id}
     *
     * ملاحظة: لا يمكن تعديل is_system بعد الإنشاء
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            $roleId = $this->validateRoleId($params);

            $oldRole = $this->roleService->getById($roleId);
            if (!$oldRole) {
                Response::notFound('الدور غير موجود');
            }

            $input = $this->getJsonInput();
            $validationErrors = $this->validateRoleData($input, isNew: false, excludeId: $roleId);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // منع تعديل is_system
            unset($input['is_system']);

            $this->roleService->update($roleId, $input);
            $newRole = $this->roleService->getById($roleId);

            $currentUserId = $this->getCurrentUserId();

            $this->auditService->log(
                userId: $currentUserId,
                action: 'ROLE_UPDATE',
                entityType: 'role',
                entityId: $roleId,
                oldValues: [
                    'name'         => $oldRole['name'],
                    'display_name' => $oldRole['display_name'],
                    'description'  => $oldRole['description'],
                    'is_active'    => $oldRole['is_active'],
                ],
                newValues: [
                    'name'         => $newRole['name'],
                    'display_name' => $newRole['display_name'],
                    'description'  => $newRole['description'],
                    'is_active'    => $newRole['is_active'],
                ],
                description: "تم تعديل الدور: {$newRole['display_name']} ({$newRole['name']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم تعديل الدور بنجاح',
                data: ['role' => $newRole]
            );

        } catch (Throwable $e) {
            error_log('[ROLE_CONTROLLER] Update failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            Response::internalError('فشل في تعديل الدور: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 5. حذف دور (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف دور (Soft Delete)
     *
     * DELETE /api/roles/{id}
     *
     * قيود:
     * - لا يمكن حذف دور نظامي (is_system = 1)
     * - لا يمكن حذف دور مرتبط بمستخدمين
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            $roleId = $this->validateRoleId($params);

            $role = $this->roleService->getById($roleId);
            if (!$role) {
                Response::notFound('الدور غير موجود');
            }

            $currentUserId = $this->getCurrentUserId();
            $this->roleService->delete($roleId);

            $this->auditService->log(
                userId: $currentUserId,
                action: 'ROLE_DELETE',
                entityType: 'role',
                entityId: $roleId,
                oldValues: [
                    'name'         => $role['name'],
                    'display_name' => $role['display_name'],
                    'users_count'  => $role['users_count'],
                ],
                description: "تم حذف الدور (Soft Delete): {$role['display_name']} ({$role['name']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم حذف الدور بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[ROLE_CONTROLLER] Destroy failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن حذف دور نظامي')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'مرتبط ب')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف الدور');
        }
    }

    // =========================================================================
    // 6. جلب الأدوار النشطة (Active)
    // =========================================================================

    /**
     * جلب الأدوار النشطة فقط (للقوائم المنسدلة)
     *
     * GET /api/roles/active
     *
     * @return void يرسل استجابة JSON
     */
    public function active(): void
    {
        try {
            $roles = $this->roleService->getActiveRoles();

            Response::success(
                message: 'تم جلب الأدوار النشطة بنجاح',
                data: [
                    'count' => count($roles),
                    'roles' => $roles,
                ]
            );

        } catch (Throwable $e) {
            error_log('[ROLE_CONTROLLER] Active failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الأدوار النشطة');
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
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف الدور غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات الدور
     */
    private function validateRoleData(array $data, bool $isNew = true, ?int $excludeId = null): array
    {
        $errors = [];

        // 1. name (مطلوب)
        if ($isNew || isset($data['name'])) {
            if (empty($data['name'])) {
                $errors['name'] = 'اسم الدور (name) مطلوب';
            } elseif (strlen($data['name']) < 3) {
                $errors['name'] = 'اسم الدور يجب أن يكون 3 أحرف على الأقل';
            } elseif (strlen($data['name']) > 50) {
                $errors['name'] = 'اسم الدور يجب ألا يتجاوز 50 حرفاً';
            } elseif (!preg_match('/^[a-z0-9_]+$/', $data['name'])) {
                $errors['name'] = 'اسم الدور يجب أن يحتوي على أحرف صغيرة وأرقام وشرطة سفلية فقط';
            } elseif ($this->roleService->isNameTaken($data['name'], $excludeId)) {
                $errors['name'] = 'اسم الدور مستخدم بالفعل';
            }
        }

        // 2. display_name (مطلوب)
        if ($isNew || isset($data['display_name'])) {
            if (empty($data['display_name'])) {
                $errors['display_name'] = 'الاسم المعروض (display_name) مطلوب';
            } elseif (strlen($data['display_name']) > 100) {
                $errors['display_name'] = 'الاسم المعروض يجب ألا يتجاوز 100 حرف';
            } elseif ($this->roleService->isDisplayNameTaken($data['display_name'], $excludeId)) {
                $errors['display_name'] = 'الاسم المعروض مستخدم بالفعل';
            }
        }

        // 3. description (اختياري)
        if (!empty($data['description']) && strlen($data['description']) > 500) {
            $errors['description'] = 'الوصف يجب ألا يتجاوز 500 حرف';
        }

        // 4. is_system (اختياري، للجديد فقط)
        if ($isNew && isset($data['is_system'])) {
            if (!in_array($data['is_system'], [0, 1, true, false, '0', '1'], true)) {
                $errors['is_system'] = 'is_system يجب أن يكون 0 أو 1';
            }
        }

        // 5. is_active (اختياري)
        if (isset($data['is_active']) && $data['is_active'] !== null) {
            if (!in_array($data['is_active'], [0, 1, true, false, '0', '1'], true)) {
                $errors['is_active'] = 'is_active يجب أن يكون 0 أو 1';
            }
        }

        return $errors;
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

        error_log('[ROLE_CONTROLLER] Current user ID not found');
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
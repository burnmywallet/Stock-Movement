<?php

/**
 * ================================================================
 * Logistox - Category Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/CategoryController.php
 * الوظيفة: إدارة التصنيفات (CRUD + هيكل هرمي)
 *
 * المسؤوليات:
 * 1. عرض قائمة التصنيفات مع الفلاتر (index)
 * 2. جلب الهيكل الهرمي (hierarchy)
 * 3. جلب التصنيفات النشطة (active)
 * 4. إضافة تصنيف جديد (store)
 * 5. عرض تفاصيل تصنيف (show)
 * 6. تعديل تصنيف (update)
 * 7. حذف تصنيف - Soft Delete (destroy)
 * 8. التحقق من صحة البيانات (Validation)
 * 9. تسجيل كل العمليات في audit_logs
 *
 * ملاحظات هامة:
 * - يعتمد على CategoryService لتنفيذ منطق الأعمال
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يمنع حذف تصنيف له ارتباطات (منتجات، تصنيفات فرعية)
 * - يتحقق من تفرد code و name
 * - يدعم الهيكل الهرمي (Parent-Child)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\CategoryService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class CategoryController
 *
 * Controller لإدارة التصنيفات
 */
class CategoryController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var CategoryService خدمة التصنيفات
     */
    private CategoryService $categoryService;

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
            $this->categoryService = new CategoryService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[CATEGORY_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة التصنيفات');
        }
    }

    // =========================================================================
    // 1. عرض قائمة التصنيفات (Index)
    // =========================================================================

    /**
     * عرض قائمة التصنيفات مع الفلاتر
     *
     * GET /api/categories
     *
     * Query Parameters:
     * - search: بحث في name, code, description
     * - is_active: تصفية حسب الحالة (1 = نشط، 0 = معطل)
     * - parent_id: تصفية حسب التصنيف الرئيسي
     * - sort_by: ترتيب حسب (name, code, created_at, updated_at)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            // 1. قراءة Query Parameters
            $search = trim($_GET['search'] ?? '');
            $isActive = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;
            $parentId = !empty($_GET['parent_id']) ? (int) $_GET['parent_id'] : null;
            $sortBy = $_GET['sort_by'] ?? 'name';
            $sortOrder = strtolower($_GET['sort_order'] ?? 'asc');

            // 2. بناء الفلاتر
            $filters = [
                'search'      => $search,
                'is_active'   => $isActive,
                'parent_id'   => $parentId,
                'sort_by'     => $sortBy,
                'sort_order'  => $sortOrder,
            ];

            // 3. جلب البيانات
            $categories = $this->categoryService->list($filters);

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب قائمة التصنيفات بنجاح',
                data: [
                    'count'      => count($categories),
                    'categories' => $categories,
                ]
            );

        } catch (Throwable $e) {
            error_log('[CATEGORY_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة التصنيفات');
        }
    }

    // =========================================================================
    // 2. الهيكل الهرمي (Hierarchy)
    // =========================================================================

    /**
     * جلب الهيكل الهرمي للتصنيفات (شجرة)
     *
     * GET /api/categories/hierarchy
     *
     * @return void يرسل استجابة JSON
     */
    public function hierarchy(): void
    {
        try {
            $tree = $this->categoryService->getHierarchy();

            Response::success(
                message: 'تم جلب الهيكل الهرمي للتصنيفات بنجاح',
                data: [
                    'tree' => $tree,
                ]
            );

        } catch (Throwable $e) {
            error_log('[CATEGORY_CONTROLLER] Hierarchy failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الهيكل الهرمي');
        }
    }

    // =========================================================================
    // 3. التصنيفات النشطة (Active)
    // =========================================================================

    /**
     * جلب التصنيفات النشطة فقط (للقوائم المنسدلة)
     *
     * GET /api/categories/active
     *
     * @return void يرسل استجابة JSON
     */
    public function active(): void
    {
        try {
            $categories = $this->categoryService->getActiveCategories();

            Response::success(
                message: 'تم جلب التصنيفات النشطة بنجاح',
                data: [
                    'count'      => count($categories),
                    'categories' => $categories,
                ]
            );

        } catch (Throwable $e) {
            error_log('[CATEGORY_CONTROLLER] Active failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب التصنيفات النشطة');
        }
    }

    // =========================================================================
    // 4. إضافة تصنيف جديد (Store)
    // =========================================================================

    /**
     * إضافة تصنيف جديد
     *
     * POST /api/categories
     *
     * Request Body (JSON):
     * {
     *   "code": "CAT-007",              // اختياري - فريد
     *   "name": "لحوم مصنعة",           // مطلوب - فريد
     *   "description": "وصف التصنيف",   // اختياري
     *   "parent_id": 1,                 // اختياري - معرف التصنيف الرئيسي
     *   "is_active": true               // اختياري - افتراضي: true
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function store(): void
    {
        try {
            // 1. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 2. التحقق من البيانات
            $validationErrors = $this->validateCategoryData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التصنيف غير صالحة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إضافة التصنيف
            $categoryId = $this->categoryService->create($input, $userId);

            // 5. جلب بيانات التصنيف المضاف
            $category = $this->categoryService->getById($categoryId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'CATEGORY_CREATE',
                entityType: 'category',
                entityId: $categoryId,
                newValues: $input,
                description: "تم إضافة تصنيف جديد: {$category['name']} ({$category['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::created(
                message: 'تم إضافة التصنيف بنجاح',
                data: ['category' => $category],
                location: "/api/categories/{$categoryId}"
            );

        } catch (Throwable $e) {
            error_log('[CATEGORY_CONTROLLER] Store failed: ' . $e->getMessage());

            // معالجة أخطاء التفرد
            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            Response::internalError('فشل في إضافة التصنيف: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 5. عرض تفاصيل تصنيف (Show)
    // =========================================================================

    /**
     * عرض تفاصيل تصنيف معين
     *
     * GET /api/categories/{id}
     *
     * @param array $params المعاملات من Router (مثل ['id' => 5])
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            // 1. التحقق من معرف التصنيف
            $categoryId = $this->validateCategoryId($params);

            // 2. جلب بيانات التصنيف
            $category = $this->categoryService->getById($categoryId);

            if (!$category) {
                Response::notFound('التصنيف غير موجود');
            }

            // 3. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب تفاصيل التصنيف بنجاح',
                data: [
                    'category' => $category,
                ]
            );

        } catch (Throwable $e) {
            error_log('[CATEGORY_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل التصنيف');
        }
    }

    // =========================================================================
    // 6. تعديل تصنيف (Update)
    // =========================================================================

    /**
     * تعديل تصنيف موجود
     *
     * PUT /api/categories/{id}
     * PATCH /api/categories/{id}
     *
     * Request Body (JSON):
     * {
     *   "name": "لحوم مصنعة - محدث",    // اختياري
     *   "code": "CAT-007-NEW",          // اختياري
     *   "description": "وصف جديد",      // اختياري
     *   "parent_id": 2,                 // اختياري
     *   "is_active": false              // اختياري
     * }
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            // 1. التحقق من معرف التصنيف
            $categoryId = $this->validateCategoryId($params);

            // 2. جلب البيانات القديمة (للتدقيق)
            $oldCategory = $this->categoryService->getById($categoryId);
            if (!$oldCategory) {
                Response::notFound('التصنيف غير موجود');
            }

            // 3. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 4. التحقق من البيانات
            $validationErrors = $this->validateCategoryData($input, isNew: false, excludeId: $categoryId);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // 5. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 6. تعديل التصنيف
            $this->categoryService->update($categoryId, $input, $userId);

            // 7. جلب البيانات الجديدة
            $newCategory = $this->categoryService->getById($categoryId);

            // 8. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'CATEGORY_UPDATE',
                entityType: 'category',
                entityId: $categoryId,
                oldValues: $oldCategory,
                newValues: $newCategory,
                description: "تم تعديل التصنيف: {$newCategory['name']} ({$newCategory['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 9. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تعديل التصنيف بنجاح',
                data: ['category' => $newCategory]
            );

        } catch (Throwable $e) {
            error_log('[CATEGORY_CONTROLLER] Update failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'لا يمكن تعيين التصنيف كأب لنفسه')) {
                Response::badRequest('لا يمكن تعيين التصنيف كأب لنفسه.');
            }

            if (str_contains($e->getMessage(), 'حلقة دائرية')) {
                Response::badRequest('لا يمكن تعيين تصنيف رئيسي يكون فرعاً لهذا التصنيف.');
            }

            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            Response::internalError('فشل في تعديل التصنيف: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 7. حذف تصنيف (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف تصنيف (Soft Delete)
     *
     * DELETE /api/categories/{id}
     *
     * ملاحظات:
     * - لا يتم الحذف الفعلي من قاعدة البيانات
     * - يتم تعيين deleted_at = NOW()
     * - يمنع حذف تصنيف له ارتباطات (منتجات، تصنيفات فرعية)
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            // 1. التحقق من معرف التصنيف
            $categoryId = $this->validateCategoryId($params);

            // 2. جلب بيانات التصنيف
            $category = $this->categoryService->getById($categoryId);
            if (!$category) {
                Response::notFound('التصنيف غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. حذف التصنيف (Soft Delete)
            $this->categoryService->delete($categoryId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'CATEGORY_DELETE',
                entityType: 'category',
                entityId: $categoryId,
                oldValues: $category,
                description: "تم حذف التصنيف (Soft Delete): {$category['name']} ({$category['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم حذف التصنيف بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[CATEGORY_CONTROLLER] Destroy failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'تصنيفات فرعية')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'منتجات')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف التصنيف');
        }
    }

    // =========================================================================
    // Helper Methods (دوال مساعدة)
    // =========================================================================

    /**
     * قراءة مدخلات JSON من جسم الطلب
     *
     * @return array البيانات المقروءة
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
     * التحقق من صحة معرف التصنيف
     *
     * @param array $params المعاملات من Router
     * @return int معرف التصنيف الصحيح
     */
    private function validateCategoryId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف التصنيف غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات التصنيف
     *
     * @param array $data البيانات المراد التحقق منها
     * @param bool $isNew هل هو تصنيف جديد أم تعديل
     * @param int|null $excludeId استثناء تصنيف معين (للتحديث)
     * @return array مصفوفة أخطاء التحقق
     */
    private function validateCategoryData(array $data, bool $isNew = true, ?int $excludeId = null): array
    {
        $errors = [];

        // 1. التحقق من الحقول المطلوبة (للتصنيفات الجديدة فقط)
        if ($isNew) {
            if (empty($data['name'])) {
                $errors['name'] = 'اسم التصنيف مطلوب';
            } elseif (strlen($data['name']) > 100) {
                $errors['name'] = 'اسم التصنيف يجب ألا يتجاوز 100 حرف';
            } elseif (!$this->categoryService->isNameUnique($data['name'])) {
                $errors['name'] = 'اسم التصنيف مستخدم بالفعل';
            }
        }

        // 2. التحقق من name (إذا تم تقديمه)
        if (!empty($data['name'])) {
            if (strlen($data['name']) > 100) {
                $errors['name'] = 'اسم التصنيف يجب ألا يتجاوز 100 حرف';
            } elseif (!$this->categoryService->isNameUnique($data['name'], $excludeId)) {
                $errors['name'] = 'اسم التصنيف مستخدم بالفعل';
            }
        }

        // 3. التحقق من code (إذا تم تقديمه)
        if (!empty($data['code'])) {
            if (strlen($data['code']) > 50) {
                $errors['code'] = 'كود التصنيف يجب ألا يتجاوز 50 حرفاً';
            } elseif (!$this->categoryService->isCodeUnique($data['code'], $excludeId)) {
                $errors['code'] = 'كود التصنيف مستخدم بالفعل';
            }
        }

        // 4. التحقق من parent_id (إذا تم تقديمه)
        if (isset($data['parent_id']) && $data['parent_id'] !== null && $data['parent_id'] !== '') {
            if (!is_numeric($data['parent_id']) || (int) $data['parent_id'] <= 0) {
                $errors['parent_id'] = 'معرف التصنيف الرئيسي غير صالح';
            } elseif (!$this->categoryService->exists((int) $data['parent_id'])) {
                $errors['parent_id'] = 'التصنيف الرئيسي غير موجود';
            }
        }

        // 5. التحقق من is_active (إذا تم تقديمه)
        if (isset($data['is_active']) && $data['is_active'] !== null) {
            if (!in_array($data['is_active'], [0, 1, true, false, '0', '1'], true)) {
                $errors['is_active'] = 'is_active يجب أن يكون 0 أو 1';
            }
        }

        // 6. التحقق من طول الحقول الاختيارية
        if (!empty($data['description']) && strlen($data['description']) > 1000) {
            $errors['description'] = 'الوصف يجب ألا يتجاوز 1000 حرف';
        }

        return $errors;
    }

    /**
     * جلب معرف المستخدم الحالي من AuthMiddleware
     *
     * @return int معرف المستخدم
     */
    private function getCurrentUserId(): int
    {
        if (isset($_REQUEST['user']['id'])) {
            return (int) $_REQUEST['user']['id'];
        }

        if (isset($GLOBALS['current_user_id'])) {
            return (int) $GLOBALS['current_user_id'];
        }

        error_log('[CATEGORY_CONTROLLER] Current user ID not found');
        Response::unauthorized('لم يتم العثور على بيانات المستخدم. يرجى تسجيل الدخول مرة أخرى.');
    }

    /**
     * جلب IP العميل
     *
     * @return string IP العميل
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
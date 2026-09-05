<?php

/**
 * ================================================================
 * Logistox - Warehouse Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/WarehouseController.php
 * الوظيفة: إدارة المخازن (CRUD + ملخص + عرض المخزون)
 *
 * المسؤوليات:
 * 1. عرض قائمة المخازن مع الفلاتر (index)
 * 2. ملخص المخازن (summary)
 * 3. إضافة مخزن جديد (store)
 * 4. عرض تفاصيل مخزن (show)
 * 5. عرض مخزون مخزن معين (stock)
 * 6. تعديل مخزن (update)
 * 7. حذف مخزن - Soft Delete (destroy)
 * 8. التحقق من صحة البيانات (Validation)
 * 9. تسجيل كل العمليات في audit_logs
 *
 * ملاحظات هامة:
 * - يعتمد على WarehouseService لتنفيذ منطق الأعمال
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يمنع حذف مخزن له ارتباطات (أرصدة، حركات، مستندات)
 * - يتحقق من تفرد code
 * - يدعم الهيكل الهرمي (Parent-Child) للمخازن
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\WarehouseService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class WarehouseController
 *
 * Controller لإدارة المخازن
 */
class WarehouseController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var WarehouseService خدمة المخازن
     */
    private WarehouseService $warehouseService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var array أنواع المخازن المسموحة
     */
    private const ALLOWED_TYPES = ['main', 'sub', 'cold', 'freezer'];

    /**
     * Constructor
     *
     * يقوم بتهيئة الخدمات المطلوبة
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->warehouseService = new WarehouseService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[WAREHOUSE_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة المخازن');
        }
    }

    // =========================================================================
    // 1. عرض قائمة المخازن (Index)
    // =========================================================================

    /**
     * عرض قائمة المخازن مع الفلاتر
     *
     * GET /api/warehouses
     *
     * Query Parameters:
     * - search: بحث في name, code, location
     * - type: تصفية حسب النوع (main, sub, cold, freezer)
     * - is_active: تصفية حسب الحالة (1 = نشط، 0 = معطل)
     * - parent_id: تصفية حسب المخزن الرئيسي
     * - sort_by: ترتيب حسب (name, code, type, created_at, updated_at)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            // 1. قراءة Query Parameters
            $search = trim($_GET['search'] ?? '');
            $type = $_GET['type'] ?? null;
            $isActive = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;
            $parentId = !empty($_GET['parent_id']) ? (int) $_GET['parent_id'] : null;
            $sortBy = $_GET['sort_by'] ?? 'name';
            $sortOrder = strtolower($_GET['sort_order'] ?? 'asc');

            // 2. بناء الفلاتر
            $filters = [
                'search'      => $search,
                'type'        => $type,
                'is_active'   => $isActive,
                'parent_id'   => $parentId,
                'sort_by'     => $sortBy,
                'sort_order'  => $sortOrder,
            ];

            // 3. جلب البيانات
            $warehouses = $this->warehouseService->list($filters);

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب قائمة المخازن بنجاح',
                data: [
                    'count'      => count($warehouses),
                    'warehouses' => $warehouses,
                ]
            );

        } catch (Throwable $e) {
            error_log('[WAREHOUSE_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة المخازن');
        }
    }

    // =========================================================================
    // 2. ملخص المخازن (Summary)
    // =========================================================================

    /**
     * جلب ملخص المخازن (إحصائيات)
     *
     * GET /api/warehouses/summary
     *
     * @return void يرسل استجابة JSON
     */
    public function summary(): void
    {
        try {
            // 1. إجمالي المخازن حسب النوع
            $byType = $this->db->select("
                SELECT
                    type,
                    COUNT(*) AS count,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count
                FROM warehouses
                WHERE deleted_at IS NULL
                GROUP BY type
            ");

            // 2. إجمالي المخزون والقيمة
            $totals = $this->db->selectOne("
                SELECT
                    COUNT(DISTINCT w.id) AS total_warehouses,
                    SUM(CASE WHEN w.is_active = 1 THEN 1 ELSE 0 END) AS active_warehouses,
                    COALESCE(SUM(sb.quantity), 0) AS total_quantity,
                    COALESCE(SUM(sb.quantity * p.cost_price), 0) AS total_value,
                    COUNT(DISTINCT sb.product_id) AS total_products
                FROM warehouses w
                LEFT JOIN stock_balances sb ON w.id = sb.warehouse_id
                LEFT JOIN products p ON sb.product_id = p.id AND p.deleted_at IS NULL
                WHERE w.deleted_at IS NULL
            ");

            // 3. تنظيم البيانات
            $typeSummary = [];
            foreach ($byType as $row) {
                $typeSummary[$row['type']] = [
                    'count'        => (int) $row['count'],
                    'active_count' => (int) $row['active_count'],
                ];
            }

            $data = [
                'total_warehouses'  => (int) ($totals['total_warehouses'] ?? 0),
                'active_warehouses' => (int) ($totals['active_warehouses'] ?? 0),
                'total_quantity'    => (float) ($totals['total_quantity'] ?? 0),
                'total_value'       => (float) ($totals['total_value'] ?? 0),
                'total_products'    => (int) ($totals['total_products'] ?? 0),
                'by_type'           => $typeSummary,
            ];

            Response::success('تم جلب ملخص المخازن بنجاح', $data);

        } catch (Throwable $e) {
            error_log('[WAREHOUSE_CONTROLLER] Summary failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب ملخص المخازن');
        }
    }

    // =========================================================================
    // 3. إضافة مخزن جديد (Store)
    // =========================================================================

    /**
     * إضافة مخزن جديد
     *
     * POST /api/warehouses
     *
     * Request Body (JSON):
     * {
     *   "code": "WH-004",              // مطلوب - فريد
     *   "name": "مخزن فرع الجيزة",     // مطلوب
     *   "type": "sub",                 // مطلوب - main, sub, cold, freezer
     *   "parent_id": 1,                // اختياري - معرف المخزن الرئيسي
     *   "location": "الجيزة - مصر",    // اختياري
     *   "address": "العنوان التفصيلي", // اختياري
     *   "manager_id": 5,               // اختياري - معرف مدير المخزن
     *   "capacity": 1000.50,           // اختياري - السعة القصوى
     *   "is_active": true              // اختياري - افتراضي: true
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
            $validationErrors = $this->validateWarehouseData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات المخزن غير صالحة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إضافة المخزن
            $warehouseId = $this->warehouseService->create($input, $userId);

            // 5. جلب بيانات المخزن المضاف
            $warehouse = $this->warehouseService->getById($warehouseId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'WAREHOUSE_CREATE',
                entityType: 'warehouse',
                entityId: $warehouseId,
                newValues: $input,
                description: "تم إضافة مخزن جديد: {$warehouse['name']} ({$warehouse['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::created(
                message: 'تم إضافة المخزن بنجاح',
                data: ['warehouse' => $warehouse],
                location: "/api/warehouses/{$warehouseId}"
            );

        } catch (Throwable $e) {
            error_log('[WAREHOUSE_CONTROLLER] Store failed: ' . $e->getMessage());

            // معالجة أخطاء التفرد (Unique Constraint)
            if (str_contains($e->getMessage(), 'Duplicate entry') || 
                str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict('كود المخزن مستخدم بالفعل. يرجى استخدام كود مختلف.');
            }

            Response::internalError('فشل في إضافة المخزن: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 4. عرض تفاصيل مخزن (Show)
    // =========================================================================

    /**
     * عرض تفاصيل مخزن معين
     *
     * GET /api/warehouses/{id}
     *
     * @param array $params المعاملات من Router (مثل ['id' => 5])
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            // 1. التحقق من معرف المخزن
            $warehouseId = $this->validateWarehouseId($params);

            // 2. جلب بيانات المخزن
            $warehouse = $this->warehouseService->getById($warehouseId);

            if (!$warehouse) {
                Response::notFound('المخزن غير موجود');
            }

            // 3. جلب إحصائيات المخزن
            $statistics = $this->warehouseService->getStatistics($warehouseId);

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب تفاصيل المخزن بنجاح',
                data: [
                    'warehouse'  => $warehouse,
                    'statistics' => $statistics,
                ]
            );

        } catch (Throwable $e) {
            error_log('[WAREHOUSE_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل المخزن');
        }
    }

    // =========================================================================
    // 5. عرض مخزون المخزن (Stock)
    // =========================================================================

    /**
     * عرض مخزون مخزن معين
     *
     * GET /api/warehouses/{id}/stock
     *
     * Query Parameters:
     * - search: بحث في product name, code, barcode
     * - category_id: تصفية حسب التصنيف
     * - low_stock_only: عرض المنتجات منخفضة المخزون فقط (1 = نعم)
     * - sort_by: ترتيب حسب (name, code, quantity)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function stock(array $params): void
    {
        try {
            // 1. التحقق من معرف المخزن
            $warehouseId = $this->validateWarehouseId($params);

            // 2. التحقق من وجود المخزن
            if (!$this->warehouseService->exists($warehouseId)) {
                Response::notFound('المخزن غير موجود');
            }

            // 3. قراءة Query Parameters
            $search = trim($_GET['search'] ?? '');
            $categoryId = !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null;
            $lowStockOnly = isset($_GET['low_stock_only']) && (int) $_GET['low_stock_only'] === 1;
            $sortBy = $_GET['sort_by'] ?? 'name';
            $sortOrder = strtolower($_GET['sort_order'] ?? 'asc');

            // 4. بناء الاستعلام
            $sql = "
                SELECT
                    sb.product_id,
                    p.code,
                    p.barcode,
                    p.name,
                    c.name AS category_name,
                    u.symbol AS unit_symbol,
                    sb.quantity,
                    sb.reserved_quantity,
                    sb.available_quantity,
                    p.min_stock,
                    p.reorder_point,
                    p.max_stock,
                    p.cost_price,
                    (sb.quantity * COALESCE(p.cost_price, 0)) AS total_value,
                    sb.last_movement_date,
                    CASE
                        WHEN sb.quantity = 0 THEN 'out_of_stock'
                        WHEN sb.quantity <= p.min_stock THEN 'critical'
                        WHEN sb.quantity <= p.reorder_point THEN 'low'
                        ELSE 'normal'
                    END AS stock_status
                FROM stock_balances sb
                INNER JOIN products p ON sb.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN units u ON p.unit_id = u.id
                WHERE sb.warehouse_id = ?
                  AND p.deleted_at IS NULL
            ";

            $params = [$warehouseId];

            // 5. تطبيق الفلاتر
            if (!empty($search)) {
                $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ?)";
                $searchParam = "%{$search}%";
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
            }

            if ($categoryId !== null) {
                $sql .= " AND p.category_id = ?";
                $params[] = $categoryId;
            }

            if ($lowStockOnly) {
                $sql .= " AND sb.quantity <= p.reorder_point";
            }

            // 6. التحقق من صحة sort_by
            $allowedSortBy = ['name', 'code', 'quantity', 'total_value', 'last_movement_date'];
            if (!in_array($sortBy, $allowedSortBy, true)) {
                $sortBy = 'name';
            }

            if (!in_array($sortOrder, ['asc', 'desc'], true)) {
                $sortOrder = 'asc';
            }

            $sql .= " ORDER BY {$sortBy} {$sortOrder}";

            // 7. جلب البيانات
            $stockItems = $this->db->select($sql, $params);

            // 8. تحويل القيم الرقمية
            foreach ($stockItems as &$item) {
                $item['quantity'] = (float) $item['quantity'];
                $item['reserved_quantity'] = (float) $item['reserved_quantity'];
                $item['available_quantity'] = (float) $item['available_quantity'];
                $item['cost_price'] = $item['cost_price'] !== null ? (float) $item['cost_price'] : null;
                $item['total_value'] = (float) $item['total_value'];
            }

            // 9. حساب الإجماليات
            $totalQuantity = array_sum(array_column($stockItems, 'quantity'));
            $totalValue = array_sum(array_column($stockItems, 'total_value'));

            // 10. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب مخزون المخزن بنجاح',
                data: [
                    'warehouse_id'   => $warehouseId,
                    'count'          => count($stockItems),
                    'total_quantity' => $totalQuantity,
                    'total_value'    => $totalValue,
                    'items'          => $stockItems,
                ]
            );

        } catch (Throwable $e) {
            error_log('[WAREHOUSE_CONTROLLER] Stock failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب مخزون المخزن');
        }
    }

    // =========================================================================
    // 6. تعديل مخزن (Update)
    // =========================================================================

    /**
     * تعديل مخزن موجود
     *
     * PUT /api/warehouses/{id}
     * PATCH /api/warehouses/{id}
     *
     * Request Body (JSON):
     * {
     *   "name": "مخزن فرع الجيزة - محدث",  // اختياري
     *   "type": "cold",                     // اختياري
     *   "parent_id": 1,                     // اختياري
     *   "location": "الجيزة - مصر",         // اختياري
     *   "address": "العنوان الجديد",        // اختياري
     *   "manager_id": 6,                    // اختياري
     *   "capacity": 1500.00,                // اختياري
     *   "is_active": false                  // اختياري
     * }
     *
     * ملاحظة: code لا يمكن تعديله بعد الإنشاء
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            // 1. التحقق من معرف المخزن
            $warehouseId = $this->validateWarehouseId($params);

            // 2. جلب البيانات القديمة (للتدقيق)
            $oldWarehouse = $this->warehouseService->getById($warehouseId);
            if (!$oldWarehouse) {
                Response::notFound('المخزن غير موجود');
            }

            // 3. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 4. التحقق من البيانات
            $validationErrors = $this->validateWarehouseData($input, isNew: false);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // 5. منع تعديل code
            unset($input['code']);

            // 6. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 7. تعديل المخزن
            $this->warehouseService->update($warehouseId, $input, $userId);

            // 8. جلب البيانات الجديدة
            $newWarehouse = $this->warehouseService->getById($warehouseId);

            // 9. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'WAREHOUSE_UPDATE',
                entityType: 'warehouse',
                entityId: $warehouseId,
                oldValues: $oldWarehouse,
                newValues: $newWarehouse,
                description: "تم تعديل المخزن: {$newWarehouse['name']} ({$newWarehouse['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 10. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تعديل المخزن بنجاح',
                data: ['warehouse' => $newWarehouse]
            );

        } catch (Throwable $e) {
            error_log('[WAREHOUSE_CONTROLLER] Update failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'لا يمكن تعيين المخزن كأب لنفسه')) {
                Response::badRequest('لا يمكن تعيين المخزن كأب لنفسه.');
            }

            if (str_contains($e->getMessage(), 'حلقة دائرية')) {
                Response::badRequest('لا يمكن تعيين مخزن رئيسي يكون فرعاً لهذا المخزن.');
            }

            Response::internalError('فشل في تعديل المخزن: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 7. حذف مخزن (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف مخزن (Soft Delete)
     *
     * DELETE /api/warehouses/{id}
     *
     * ملاحظات:
     * - لا يتم الحذف الفعلي من قاعدة البيانات
     * - يتم تعيين deleted_at = NOW()
     * - يمنع حذف مخزن له ارتباطات (أرصدة، حركات، مستندات)
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            // 1. التحقق من معرف المخزن
            $warehouseId = $this->validateWarehouseId($params);

            // 2. جلب بيانات المخزن
            $warehouse = $this->warehouseService->getById($warehouseId);
            if (!$warehouse) {
                Response::notFound('المخزن غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. حذف المخزن (Soft Delete)
            $this->warehouseService->delete($warehouseId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'WAREHOUSE_DELETE',
                entityType: 'warehouse',
                entityId: $warehouseId,
                oldValues: $warehouse,
                description: "تم حذف المخزن (Soft Delete): {$warehouse['name']} ({$warehouse['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم حذف المخزن بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[WAREHOUSE_CONTROLLER] Destroy failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'أرصدة مخزنية')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'حركات مخزنية')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'إذونات')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'تحويلات')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'مخازن فرعية')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'مستخدمين')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف المخزن');
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
     * التحقق من صحة معرف المخزن
     *
     * @param array $params المعاملات من Router
     * @return int معرف المخزن الصحيح
     * @throws Exception إذا كان المعرف غير صالح
     */
    private function validateWarehouseId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف المخزن غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات المخزن
     *
     * @param array $data البيانات المراد التحقق منها
     * @param bool $isNew هل هو مخزن جديد أم تعديل
     * @return array مصفوفة أخطاء التحقق (فارغة إذا كانت البيانات صحيحة)
     */
    private function validateWarehouseData(array $data, bool $isNew = true): array
    {
        $errors = [];

        // 1. التحقق من الحقول المطلوبة (للمخازن الجديدة فقط)
        if ($isNew) {
            if (empty($data['code'])) {
                $errors['code'] = 'كود المخزن مطلوب';
            } elseif (strlen($data['code']) > 20) {
                $errors['code'] = 'كود المخزن يجب ألا يتجاوز 20 حرفاً';
            } elseif (!$this->warehouseService->isCodeUnique($data['code'])) {
                $errors['code'] = 'كود المخزن مستخدم بالفعل';
            }

            if (empty($data['name'])) {
                $errors['name'] = 'اسم المخزن مطلوب';
            } elseif (strlen($data['name']) > 100) {
                $errors['name'] = 'اسم المخزن يجب ألا يتجاوز 100 حرف';
            }

            if (empty($data['type'])) {
                $errors['type'] = 'نوع المخزن مطلوب';
            } elseif (!in_array($data['type'], self::ALLOWED_TYPES, true)) {
                $errors['type'] = 'نوع المخزن غير صالح. القيم المسموحة: ' . implode(', ', self::ALLOWED_TYPES);
            }
        }

        // 2. التحقق من type (إذا تم تقديمه)
        if (!empty($data['type']) && !in_array($data['type'], self::ALLOWED_TYPES, true)) {
            $errors['type'] = 'نوع المخزن غير صالح. القيم المسموحة: ' . implode(', ', self::ALLOWED_TYPES);
        }

        // 3. التحقق من parent_id (إذا تم تقديمه)
        if (isset($data['parent_id']) && $data['parent_id'] !== null && $data['parent_id'] !== '') {
            if (!is_numeric($data['parent_id']) || (int) $data['parent_id'] <= 0) {
                $errors['parent_id'] = 'معرف المخزن الرئيسي غير صالح';
            } elseif (!$this->warehouseService->exists((int) $data['parent_id'])) {
                $errors['parent_id'] = 'المخزن الرئيسي غير موجود';
            }
        }

        // 4. التحقق من manager_id (إذا تم تقديمه)
        if (isset($data['manager_id']) && $data['manager_id'] !== null && $data['manager_id'] !== '') {
            if (!is_numeric($data['manager_id']) || (int) $data['manager_id'] <= 0) {
                $errors['manager_id'] = 'معرف المدير غير صالح';
            } else {
                $manager = $this->db->selectOne(
                    "SELECT id FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                    [(int) $data['manager_id']]
                );
                if (!$manager) {
                    $errors['manager_id'] = 'المدير المحدد غير موجود أو غير نشط';
                }
            }
        }

        // 5. التحقق من capacity (إذا تم تقديمه)
        if (isset($data['capacity']) && $data['capacity'] !== null && $data['capacity'] !== '') {
            if (!is_numeric($data['capacity']) || (float) $data['capacity'] < 0) {
                $errors['capacity'] = 'السعة يجب أن تكون رقماً غير سالب';
            }
        }

        // 6. التحقق من is_active (إذا تم تقديمه)
        if (isset($data['is_active']) && $data['is_active'] !== null) {
            if (!in_array($data['is_active'], [0, 1, true, false, '0', '1'], true)) {
                $errors['is_active'] = 'is_active يجب أن يكون 0 أو 1';
            }
        }

        // 7. التحقق من طول الحقول الاختيارية
        if (!empty($data['location']) && strlen($data['location']) > 255) {
            $errors['location'] = 'الموقع يجب ألا يتجاوز 255 حرفاً';
        }

        return $errors;
    }

    /**
     * جلب معرف المستخدم الحالي من AuthMiddleware
     *
     * @return int معرف المستخدم
     * @throws Exception إذا لم يتم العثور على المستخدم
     */
    private function getCurrentUserId(): int
    {
        // AuthMiddleware يحقن بيانات المستخدم في $_REQUEST['user']
        if (isset($_REQUEST['user']['id'])) {
            return (int) $_REQUEST['user']['id'];
        }

        // بديل: قراءة من global variable
        if (isset($GLOBALS['current_user_id'])) {
            return (int) $GLOBALS['current_user_id'];
        }

        // إذا لم يتم العثور على المستخدم، نعتبره خطأ
        error_log('[WAREHOUSE_CONTROLLER] Current user ID not found');
        Response::unauthorized('لم يتم العثور على بيانات المستخدم. يرجى تسجيل الدخول مرة أخرى.');
    }

    /**
     * جلب IP العميل
     *
     * @return string IP العميل
     */
    private function getClientIp(): string
    {
        // التحقق من الـ Proxy (فقط إذا تم تفعيل TRUST_PROXY)
        $trustProxy = filter_var(
            getenv('TRUST_PROXY') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );

        if ($trustProxy) {
            // X-Forwarded-For
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $firstIp = trim($ips[0]);
                if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                    return $firstIp;
                }
            }

            // X-Real-IP
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = trim($_SERVER['HTTP_X_REAL_IP']);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        // REMOTE_ADDR
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = trim($_SERVER['REMOTE_ADDR']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }
}
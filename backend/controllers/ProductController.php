<?php

/**
 * ================================================================
 * Logistox - Product Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/ProductController.php
 * الوظيفة: إدارة المنتجات (CRUD + بحث + ترقيم)
 *
 * المسؤوليات:
 * 1. عرض قائمة المنتجات مع الفلاتر والترقيم (index)
 * 2. إضافة منتج جديد (store)
 * 3. عرض تفاصيل منتج (show)
 * 4. تعديل منتج (update)
 * 5. حذف منتج - Soft Delete (destroy)
 * 6. البحث المتقدم (search)
 * 7. المنتجات منخفضة المخزون (lowStock)
 * 8. التحقق من صحة البيانات (Validation)
 * 9. تسجيل كل العمليات في audit_logs
 *
 * ملاحظات هامة:
 * - يعتمد على ProductService لتنفيذ منطق الأعمال
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يمنع حذف منتج له حركات مخزنية
 * - يتحقق من تفرد code و barcode
 * - يدعم الباركود (EAN13, QR_CODE, CODE128)
 * - يدعم SKU (Stock Keeping Unit)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\ProductService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class ProductController
 *
 * Controller لإدارة المنتجات
 */
class ProductController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var ProductService خدمة المنتجات
     */
    private ProductService $productService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var int|null معرف المستخدم الحالي (من AuthMiddleware)
     */
    private ?int $currentUserId = null;

    /**
     * Constructor
     *
     * يقوم بتهيئة الخدمات المطلوبة
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->productService = new ProductService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[PRODUCT_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة المنتجات');
        }
    }

    // =========================================================================
    // 1. عرض قائمة المنتجات (Index)
    // =========================================================================

    /**
     * عرض قائمة المنتجات مع الفلاتر والترقيم
     *
     * GET /api/products
     *
     * Query Parameters:
     * - page: رقم الصفحة (افتراضي: 1)
     * - per_page: عدد العناصر في الصفحة (افتراضي: 25، حد أقصى: 100)
     * - search: بحث في name, code, barcode
     * - category_id: تصفية حسب التصنيف
     * - is_active: تصفية حسب الحالة (1 = نشط، 0 = معطل)
     * - sort_by: ترتيب حسب (name, code, created_at)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            // 1. قراءة Query Parameters
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
            $search = trim($_GET['search'] ?? '');
            $categoryId = !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null;
            $isActive = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;
            $sortBy = $_GET['sort_by'] ?? 'name';
            $sortOrder = strtolower($_GET['sort_order'] ?? 'asc');

            // 2. التحقق من صحة sort_by
            $allowedSortBy = ['name', 'code', 'created_at', 'updated_at'];
            if (!in_array($sortBy, $allowedSortBy, true)) {
                $sortBy = 'name';
            }

            // 3. التحقق من صحة sort_order
            if (!in_array($sortOrder, ['asc', 'desc'], true)) {
                $sortOrder = 'asc';
            }

            // 4. بناء الفلاتر
            $filters = [
                'search'      => $search,
                'category_id' => $categoryId,
                'is_active'   => $isActive,
                'sort_by'     => $sortBy,
                'sort_order'  => $sortOrder,
            ];

            // 5. جلب البيانات
            $products = $this->productService->list($filters);

            // 6. تطبيق الترقيم (Pagination)
            $total = count($products);
            $offset = ($page - 1) * $perPage;
            $paginatedProducts = array_slice($products, $offset, $perPage);

            // 7. إرجاع الاستجابة مع بيانات الترقيم
            Response::paginated(
                data: $paginatedProducts,
                total: $total,
                page: $page,
                perPage: $perPage,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[PRODUCT_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة المنتجات');
        }
    }

    // =========================================================================
    // 2. إضافة منتج جديد (Store)
    // =========================================================================

    /**
     * إضافة منتج جديد
     *
     * POST /api/products
     *
     * Request Body (JSON):
     * {
     *   "code": "PROD-001",              // مطلوب - فريد
     *   "name": "لحم بقري",              // مطلوب
     *   "barcode": "6281001234567",      // اختياري - فريد
     *   "sku": "SKU-001",                // اختياري
     *   "description": "وصف المنتج",     // اختياري
     *   "category_id": 1,                // اختياري
     *   "unit_id": 2,                    // اختياري
     *   "min_stock": 10,                 // اختياري - افتراضي: 0
     *   "reorder_point": 20,             // اختياري - افتراضي: 0
     *   "max_stock": 100,                // اختياري
     *   "cost_price": 150.50,            // اختياري
     *   "barcode_type": "EAN13",         // اختياري - افتراضي: EAN13
     *   "is_barcode_enabled": true,      // اختياري - افتراضي: true
     *   "is_sku_enabled": true,          // اختياري - افتراضي: true
     *   "is_active": true                // اختياري - افتراضي: true
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function store(): void
    {
        try {
            // 1. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 2. التحقق من البيانات المطلوبة
            $validationErrors = $this->validateProductData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات المنتج غير صالحة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إضافة المنتج
            $productId = $this->productService->create($input, $userId);

            // 5. جلب بيانات المنتج المضاف
            $product = $this->productService->getById($productId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'PRODUCT_CREATE',
                entityType: 'product',
                entityId: $productId,
                newValues: $input,
                description: "تم إضافة منتج جديد: {$product['name']} ({$product['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::created(
                message: 'تم إضافة المنتج بنجاح',
                data: ['product' => $product],
                location: "/api/products/{$productId}"
            );

        } catch (Throwable $e) {
            error_log('[PRODUCT_CONTROLLER] Store failed: ' . $e->getMessage());

            // معالجة أخطاء التفرد (Unique Constraint)
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                if (str_contains($e->getMessage(), 'code')) {
                    Response::conflict('كود المنتج مستخدم بالفعل. يرجى استخدام كود مختلف.');
                } elseif (str_contains($e->getMessage(), 'barcode')) {
                    Response::conflict('الباركود مستخدم بالفعل. يرجى استخدام باركود مختلف.');
                }
            }

            Response::internalError('فشل في إضافة المنتج: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. عرض تفاصيل منتج (Show)
    // =========================================================================

    /**
     * عرض تفاصيل منتج معين
     *
     * GET /api/products/{id}
     *
     * @param array $params المعاملات من Router (مثل ['id' => 5])
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            // 1. التحقق من معرف المنتج
            $productId = $this->validateProductId($params);

            // 2. جلب بيانات المنتج
            $product = $this->productService->getById($productId);

            if (!$product) {
                Response::notFound('المنتج غير موجود');
            }

            // 3. جلب أرصدة المنتج في كل المخازن
            $balances = $this->productService->getStockBalances($productId);

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب تفاصيل المنتج بنجاح',
                data: [
                    'product'  => $product,
                    'balances' => $balances,
                ]
            );

        } catch (Throwable $e) {
            error_log('[PRODUCT_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل المنتج');
        }
    }

    // =========================================================================
    // 4. تعديل منتج (Update)
    // =========================================================================

    /**
     * تعديل منتج موجود
     *
     * PUT /api/products/{id}
     * PATCH /api/products/{id}
     *
     * Request Body (JSON):
     * {
     *   "name": "لحم بقري ممتاز",        // اختياري
     *   "description": "وصف جديد",       // اختياري
     *   "category_id": 2,                // اختياري
     *   "unit_id": 3,                    // اختياري
     *   "min_stock": 15,                 // اختياري
     *   "reorder_point": 25,             // اختياري
     *   "max_stock": 150,                // اختياري
     *   "cost_price": 160.00,            // اختياري
     *   "is_active": true                // اختياري
     * }
     *
     * ملاحظة: code و barcode لا يمكن تعديلهما بعد الإنشاء
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            // 1. التحقق من معرف المنتج
            $productId = $this->validateProductId($params);

            // 2. جلب البيانات القديمة (للتدقيق)
            $oldProduct = $this->productService->getById($productId);
            if (!$oldProduct) {
                Response::notFound('المنتج غير موجود');
            }

            // 3. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 4. التحقق من البيانات
            $validationErrors = $this->validateProductData($input, isNew: false);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // 5. منع تعديل code و barcode
            unset($input['code'], $input['barcode']);

            // 6. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 7. تعديل المنتج
            $this->productService->update($productId, $input, $userId);

            // 8. جلب البيانات الجديدة
            $newProduct = $this->productService->getById($productId);

            // 9. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'PRODUCT_UPDATE',
                entityType: 'product',
                entityId: $productId,
                oldValues: $oldProduct,
                newValues: $newProduct,
                description: "تم تعديل المنتج: {$newProduct['name']} ({$newProduct['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 10. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تعديل المنتج بنجاح',
                data: ['product' => $newProduct]
            );

        } catch (Throwable $e) {
            error_log('[PRODUCT_CONTROLLER] Update failed: ' . $e->getMessage());
            Response::internalError('فشل في تعديل المنتج: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 5. حذف منتج (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف منتج (Soft Delete)
     *
     * DELETE /api/products/{id}
     *
     * ملاحظات:
     * - لا يتم الحذف الفعلي من قاعدة البيانات
     * - يتم تعيين deleted_at = NOW()
     * - يمنع حذف منتج له حركات مخزنية
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            // 1. التحقق من معرف المنتج
            $productId = $this->validateProductId($params);

            // 2. جلب بيانات المنتج
            $product = $this->productService->getById($productId);
            if (!$product) {
                Response::notFound('المنتج غير موجود');
            }

            // 3. التحقق من وجود حركات مخزنية
            $hasMovements = $this->productService->hasStockMovements($productId);
            if ($hasMovements) {
                Response::forbidden(
                    'لا يمكن حذف هذا المنتج لأنه يحتوي على حركات مخزنية. ' .
                    'يمكنك تعطيله بدلاً من ذلك بتعيين is_active = 0'
                );
            }

            // 4. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 5. حذف المنتج (Soft Delete)
            $this->productService->delete($productId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'PRODUCT_DELETE',
                entityType: 'product',
                entityId: $productId,
                oldValues: $product,
                description: "تم حذف المنتج (Soft Delete): {$product['name']} ({$product['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم حذف المنتج بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[PRODUCT_CONTROLLER] Destroy failed: ' . $e->getMessage());
            Response::internalError('فشل في حذف المنتج');
        }
    }

    // =========================================================================
    // 6. البحث المتقدم (Search)
    // =========================================================================

    /**
     * البحث المتقدم عن المنتجات
     *
     * GET /api/products/search
     *
     * Query Parameters:
     * - q: نص البحث (مطلوب)
     * - search_in: مجال البحث (name, code, barcode, all) - افتراضي: all
     * - limit: حد النتائج (افتراضي: 20، حد أقصى: 100)
     *
     * @return void يرسل استجابة JSON
     */
    public function search(): void
    {
        try {
            // 1. قراءة Query Parameters
            $query = trim($_GET['q'] ?? '');
            $searchIn = $_GET['search_in'] ?? 'all';
            $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));

            // 2. التحقق من وجود نص البحث
            if (empty($query)) {
                Response::badRequest('نص البحث (q) مطلوب');
            }

            // 3. التحقق من صحة search_in
            $allowedSearchIn = ['name', 'code', 'barcode', 'all'];
            if (!in_array($searchIn, $allowedSearchIn, true)) {
                $searchIn = 'all';
            }

            // 4. بناء الفلاتر
            $filters = [
                'search' => $query,
                'limit'  => $limit,
            ];

            // 5. البحث
            $products = $this->productService->search($query, $searchIn, $limit);

            // 6. إرجاع الاستجابة
            Response::success(
                message: 'تم البحث بنجاح',
                data: [
                    'query'    => $query,
                    'count'    => count($products),
                    'products' => $products,
                ]
            );

        } catch (Throwable $e) {
            error_log('[PRODUCT_CONTROLLER] Search failed: ' . $e->getMessage());
            Response::internalError('فشل في عملية البحث');
        }
    }

    // =========================================================================
    // 7. المنتجات منخفضة المخزون (Low Stock)
    // =========================================================================

    /**
     * جلب المنتجات منخفضة المخزون
     *
     * GET /api/products/low-stock
     *
     * Query Parameters:
     * - limit: حد النتائج (افتراضي: 20، حد أقصى: 100)
     *
     * @return void يرسل استجابة JSON
     */
    public function lowStock(): void
    {
        try {
            // 1. قراءة Query Parameters
            $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));

            // 2. جلب المنتجات منخفضة المخزون
            $products = $this->productService->getLowStockProducts($limit);

            // 3. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب المنتجات منخفضة المخزون بنجاح',
                data: [
                    'count'    => count($products),
                    'products' => $products,
                ]
            );

        } catch (Throwable $e) {
            error_log('[PRODUCT_CONTROLLER] LowStock failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب المنتجات منخفضة المخزون');
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
     * التحقق من صحة معرف المنتج
     *
     * @param array $params المعاملات من Router
     * @return int معرف المنتج الصحيح
     * @throws Exception إذا كان المعرف غير صالح
     */
    private function validateProductId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف المنتج غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات المنتج
     *
     * @param array $data البيانات المراد التحقق منها
     * @param bool $isNew هل هو منتج جديد أم تعديل
     * @return array مصفوفة أخطاء التحقق (فارغة إذا كانت البيانات صحيحة)
     */
    private function validateProductData(array $data, bool $isNew = true): array
    {
        $errors = [];

        // 1. التحقق من الحقول المطلوبة (للمنتجات الجديدة فقط)
        if ($isNew) {
            if (empty($data['code'])) {
                $errors['code'] = 'كود المنتج مطلوب';
            } elseif (strlen($data['code']) > 50) {
                $errors['code'] = 'كود المنتج يجب ألا يتجاوز 50 حرفاً';
            }

            if (empty($data['name'])) {
                $errors['name'] = 'اسم المنتج مطلوب';
            } elseif (strlen($data['name']) > 200) {
                $errors['name'] = 'اسم المنتج يجب ألا يتجاوز 200 حرف';
            }
        }

        // 2. التحقق من الباركود (إذا تم تقديمه)
        if (!empty($data['barcode'])) {
            if (strlen($data['barcode']) > 100) {
                $errors['barcode'] = 'الباركود يجب ألا يتجاوز 100 حرف';
            }
        }

        // 3. التحقق من SKU (إذا تم تقديمه)
        if (!empty($data['sku'])) {
            if (strlen($data['sku']) > 50) {
                $errors['sku'] = 'SKU يجب ألا يتجاوز 50 حرفاً';
            }
        }

        // 4. التحقق من category_id (إذا تم تقديمه)
        if (isset($data['category_id']) && $data['category_id'] !== null) {
            if (!is_numeric($data['category_id']) || (int) $data['category_id'] <= 0) {
                $errors['category_id'] = 'معرف التصنيف غير صالح';
            } else {
                // التحقق من وجود التصنيف
                $category = $this->db->selectOne(
                    "SELECT id FROM categories WHERE id = ? AND deleted_at IS NULL",
                    [(int) $data['category_id']]
                );
                if (!$category) {
                    $errors['category_id'] = 'التصنيف غير موجود';
                }
            }
        }

        // 5. التحقق من unit_id (إذا تم تقديمه)
        if (isset($data['unit_id']) && $data['unit_id'] !== null) {
            if (!is_numeric($data['unit_id']) || (int) $data['unit_id'] <= 0) {
                $errors['unit_id'] = 'معرف الوحدة غير صالح';
            } else {
                // التحقق من وجود الوحدة
                $unit = $this->db->selectOne(
                    "SELECT id FROM units WHERE id = ? AND deleted_at IS NULL",
                    [(int) $data['unit_id']]
                );
                if (!$unit) {
                    $errors['unit_id'] = 'الوحدة غير موجودة';
                }
            }
        }

        // 6. التحقق من القيم الرقمية
        $numericFields = ['min_stock', 'reorder_point', 'max_stock', 'cost_price'];
        foreach ($numericFields as $field) {
            if (isset($data[$field]) && $data[$field] !== null) {
                if (!is_numeric($data[$field]) || (float) $data[$field] < 0) {
                    $errors[$field] = "{$field} يجب أن يكون رقماً غير سالب";
                }
            }
        }

        // 7. التحقق من barcode_type (إذا تم تقديمه)
        if (!empty($data['barcode_type'])) {
            $allowedTypes = ['EAN13', 'QR_CODE', 'CODE128'];
            if (!in_array($data['barcode_type'], $allowedTypes, true)) {
                $errors['barcode_type'] = 'نوع الباركود غير صالح. القيم المسموحة: ' . implode(', ', $allowedTypes);
            }
        }

        // 8. التحقق من القيم المنطقية
        $booleanFields = ['is_barcode_enabled', 'is_sku_enabled', 'is_active'];
        foreach ($booleanFields as $field) {
            if (isset($data[$field]) && $data[$field] !== null) {
                if (!in_array($data[$field], [0, 1, true, false, '0', '1'], true)) {
                    $errors[$field] = "{$field} يجب أن يكون 0 أو 1";
                }
            }
        }

        // 9. التحقق من التوافق المنطقي
        if (isset($data['min_stock'], $data['reorder_point'], $data['max_stock'])) {
            $minStock = (float) ($data['min_stock'] ?? 0);
            $reorderPoint = (float) ($data['reorder_point'] ?? 0);
            $maxStock = (float) ($data['max_stock'] ?? 0);

            if ($minStock > $reorderPoint) {
                $errors['min_stock'] = 'الحد الأدنى للمخزون يجب أن يكون أقل من أو يساوي نقطة إعادة الطلب';
            }

            if ($maxStock > 0 && $reorderPoint > $maxStock) {
                $errors['reorder_point'] = 'نقطة إعادة الطلب يجب أن تكون أقل من أو تساوي الحد الأقصى للمخزون';
            }
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
        // أو يمكننا قراءتها من الـ Request object
        if (isset($_REQUEST['user']['id'])) {
            return (int) $_REQUEST['user']['id'];
        }

        // بديل: قراءة من global variable (إذا تم تمريرها)
        if (isset($GLOBALS['current_user_id'])) {
            return (int) $GLOBALS['current_user_id'];
        }

        // إذا لم يتم العثور على المستخدم، نعتبره خطأ
        error_log('[PRODUCT_CONTROLLER] Current user ID not found');
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
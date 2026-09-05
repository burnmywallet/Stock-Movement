<?php

/**
 * ================================================================
 * Logistox - Stock Balance Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/StockBalanceController.php
 * الوظيفة: عرض أرصدة المخزون (قراءة فقط - Read Only)
 *
 * المسؤوليات:
 * 1. عرض كل الأرصدة مع الفلاتر والترقيم (index)
 * 2. أرصدة منتج معين في كل المخازن (product)
 * 3. أرصدة مخزن معين - كل المنتجات (warehouse)
 * 4. رصيد منتج في مخزن محدد (productWarehouse)
 * 5. المنتجات منخفضة المخزون (lowStock)
 * 6. المنتجات التي نفدت (outOfStock)
 * 7. إحصائيات الأرصدة العامة (statistics)
 * 8. ملخص حسب المخزن (warehouseSummary)
 * 9. ملخص حسب التصنيف (categorySummary)
 * 10. تصدير الأرصدة (export)
 *
 * الصلاحيات المطلوبة:
 * - stock.view: عرض الأرصدة
 * - stock.export: تصدير الأرصدة
 *
 * ملاحظات هامة:
 * - هذا Controller للقراءة فقط (Read Only)
 * - تحديث الأرصدة يتم عبر InventoryService فقط
 * - يعتمد على StockService لتجميع البيانات
 * - يدعم الفلاتر المتقدمة والترقيم
 * - لا يحتاج Audit Logging (عمليات قراءة فقط)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\StockService;
use Throwable;
use Exception;

/**
 * Class StockBalanceController
 *
 * Controller لعرض أرصدة المخزون
 */
class StockBalanceController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var StockService خدمة الأرصدة
     */
    private StockService $stockService;

    /**
     * @var array حالات المخزون المسموحة
     */
    private const STOCK_STATUSES = ['normal', 'low', 'critical', 'out_of_stock'];

    /**
     * @var array أسماء الحالات بالعربية
     */
    private const STATUS_LABELS = [
        'normal'       => 'طبيعي',
        'low'          => 'منخفض',
        'critical'     => 'حرج',
        'out_of_stock' => 'نفد',
    ];

    /**
     * @var array ألوان الحالات (للواجهة الأمامية)
     */
    private const STATUS_COLORS = [
        'normal'       => '#27ae60',
        'low'          => '#f39c12',
        'critical'     => '#e67e22',
        'out_of_stock' => '#e74c3c',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->stockService = new StockService($this->db);
        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة الأرصدة');
        }
    }

    // =========================================================================
    // 1. عرض كل الأرصدة (Index)
    // =========================================================================

    /**
     * عرض كل الأرصدة مع الفلاتر والترقيم
     *
     * GET /api/stock/balances
     *
     * Query Parameters:
     * - warehouse_id: تصفية حسب المخزن
     * - product_id: تصفية حسب المنتج
     * - category_id: تصفية حسب التصنيف
     * - stock_status: تصفية حسب الحالة (normal, low, critical, out_of_stock)
     * - low_stock_only: عرض المنتجات منخفضة المخزون فقط (1 = نعم)
     * - search: بحث في اسم المنتج أو كوده أو باركوده
     * - has_stock: تصفية حسب وجود مخزون (1 = له مخزون، 0 = بدون)
     * - page: رقم الصفحة (افتراضي: 1)
     * - per_page: عدد العناصر (افتراضي: 25، حد أقصى: 100)
     * - sort_by: ترتيب حسب (product_name, quantity, total_value, last_movement_date)
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
            $offset = ($page - 1) *perPage;

            $filters = [
                'warehouse_id'   => !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null,
                'product_id'     => !empty($_GET['product_id']) ? (int) $_GET['product_id'] : null,
                'category_id'    => !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null,
                'stock_status'   => $_GET['stock_status'] ?? null,
                'low_stock_only' => isset($_GET['low_stock_only']) && (int) $_GET['low_stock_only'] === 1,
                'search'         => trim($_GET['search'] ?? ''),
                'has_stock'      => isset($_GET['has_stock']) ? (int) $_GET['has_stock'] : null,
                'sort_by'        => $_GET['sort_by'] ?? 'product_name',
                'sort_order'     => strtolower($_GET['sort_order'] ?? 'asc'),
            ];

            // 2. التحقق من صحة الفلاتر
            $this->validateFilters($filters);

            // 3. بناء الاستعلام
            [$sql, $params] = $this->buildBalancesQuery($filters);

            // 4. جلب العدد الإجمالي
            $countSql = "SELECT COUNT(*) AS total FROM ({$sql}) AS subquery";
            $totalResult = $this->db->selectOne($countSql, $params);
            $total = (int) ($totalResult['total'] ?? 0);

            // 5. إضافة LIMIT و OFFSET
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $perPage;
            $params[] = $offset;

            // 6. جلب البيانات
            $balances = $this->db->select($sql, $params);

            // 7. معالجة البيانات
            foreach ($balances as &$balance) {
                $this->enrichBalanceData($balance);
            }

            // 8. إرجاع الاستجابة مع الترقيم
            Response::paginated(
                data: $balances,
                total: $total,
                page: $page,
                perPage: $perPage,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الأرصدة');
        }
    }

    // =========================================================================
    // 2. أرصدة منتج معين في كل المخازن (Product)
    // =========================================================================

    /**
     * عرض أرصدة منتج معين في كل المخازن
     *
     * GET /api/stock/balances/product/{productId}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function product(array $params): void
    {
        try {
            $productId = $this->validateProductId($params);

            // 1. جلب معلومات المنتج
            $product = $this->db->selectOne("
                SELECT
                    p.id, p.code, p.barcode, p.name, p.description,
                    p.min_stock, p.reorder_point, p.max_stock, p.cost_price,
                    c.name AS category_name,
                    u.symbol AS unit_symbol
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN units u ON p.unit_id = u.id
                WHERE p.id = ? AND p.deleted_at IS NULL
            ", [$productId]);

            if (!$product) {
                Response::notFound('المنتج غير موجود');
            }

            // 2. جلب الأرصدة في كل المخازن
            $balances = $this->db->select("
                SELECT
                    sb.warehouse_id,
                    w.code AS warehouse_code,
                    w.name AS warehouse_name,
                    w.type AS warehouse_type,
                    sb.quantity,
                    sb.reserved_quantity,
                    sb.available_quantity,
                    sb.last_movement_date,
                    (sb.quantity * COALESCE(p.cost_price, 0)) AS total_value
                FROM stock_balances sb
                INNER JOIN warehouses w ON sb.warehouse_id = w.id
                INNER JOIN products p ON sb.product_id = p.id
                WHERE sb.product_id = ?
                  AND w.deleted_at IS NULL
                ORDER BY w.name ASC
            ", [$productId]);

            // 3. معالجة البيانات
            $totalQuantity = 0.0;
            $totalReserved = 0.0;
            $totalAvailable = 0.0;
            $totalValue = 0.0;
            $warehousesCount = 0;

            foreach ($balances as &$balance) {
                $balance['quantity'] = (float) $balance['quantity'];
                $balance['reserved_quantity'] = (float) $balance['reserved_quantity'];
                $balance['available_quantity'] = (float) $balance['available_quantity'];
                $balance['total_value'] = (float) $balance['total_value'];
                $balance['stock_status'] = $this->calculateStockStatus(
                    $balance['quantity'],
                    (float) $product['min_stock'],
                    (float) $product['reorder_point']
                );
                $balance['stock_status_label'] = self::STATUS_LABELS[$balance['stock_status']];
                $balance['stock_status_color'] = self::STATUS_COLORS[$balance['stock_status']];

                $totalQuantity += $balance['quantity'];
                $totalReserved += $balance['reserved_quantity'];
                $totalAvailable += $balance['available_quantity'];
                $totalValue += $balance['total_value'];
                $warehousesCount++;
            }

            // 4. تحديد الحالة الإجمالية للمنتج
            $overallStatus = $this->calculateStockStatus(
                $totalQuantity,
                (float) $product['min_stock'],
                (float) $product['reorder_point']
            );

            // 5. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب أرصدة المنتج بنجاح',
                data: [
                    'product' => [
                        'id'              => (int) $product['id'],
                        'code'            => $product['code'],
                        'barcode'         => $product['barcode'],
                        'name'            => $product['name'],
                        'description'     => $product['description'],
                        'category_name'   => $product['category_name'],
                        'unit_symbol'     => $product['unit_symbol'],
                        'min_stock'       => (float) $product['min_stock'],
                        'reorder_point'   => (float) $product['reorder_point'],
                        'max_stock'       => (float) $product['max_stock'],
                        'cost_price'      => $product['cost_price'] !== null ? (float) $product['cost_price'] : null,
                    ],
                    'summary' => [
                        'warehouses_count' => $warehousesCount,
                        'total_quantity'   => $totalQuantity,
                        'total_reserved'   => $totalReserved,
                        'total_available'  => $totalAvailable,
                        'total_value'      => $totalValue,
                        'overall_status'   => $overallStatus,
                        'overall_status_label' => self::STATUS_LABELS[$overallStatus],
                        'overall_status_color' => self::STATUS_COLORS[$overallStatus],
                    ],
                    'balances' => $balances,
                ]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] Product failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب أرصدة المنتج');
        }
    }

    // =========================================================================
    // 3. أرصدة مخزن معين (Warehouse)
    // =========================================================================

    /**
     * عرض أرصدة مخزن معين - كل المنتجات
     *
     * GET /api/stock/balances/warehouse/{warehouseId}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function warehouse(array $params): void
    {
        try {
            $warehouseId = $this->validateWarehouseId($params);

            // 1. جلب معلومات المخزن
            $warehouse = $this->db->selectOne("
                SELECT id, code, name, type, location, capacity
                FROM warehouses
                WHERE id = ? AND deleted_at IS NULL
            ", [$warehouseId]);

            if (!$warehouse) {
                Response::notFound('المخزن غير موجود');
            }

            // 2. قراءة الفلاتر
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
            $offset = ($page - 1) * $perPage;
            $search = trim($_GET['search'] ?? '');
            $categoryId = !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null;
            $lowStockOnly = isset($_GET['low_stock_only']) && (int) $_GET['low_stock_only'] === 1;

            // 3. بناء الاستعلام
            $sql = "
                SELECT
                    sb.product_id,
                    p.code AS product_code,
                    p.barcode,
                    p.name AS product_name,
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
                    sb.last_movement_date
                FROM stock_balances sb
                INNER JOIN products p ON sb.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN units u ON p.unit_id = u.id
                WHERE sb.warehouse_id = ?
                  AND p.deleted_at IS NULL
            ";

            $params = [$warehouseId];

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
                $sql .= " AND sb.quantity <= p.reorder_point AND sb.quantity > 0";
            }

            // 4. عد الإجمالي
            $countSql = "SELECT COUNT(*) AS total FROM ({$sql}) AS subquery";
            $totalResult = $this->db->selectOne($countSql, $params);
            $total = (int) ($totalResult['total'] ?? 0);

            // 5. إضافة LIMIT و OFFSET
            $sql .= " ORDER BY p.name ASC LIMIT ? OFFSET ?";
            $params[] = $perPage;
            $params[] = $offset;

            // 6. جلب البيانات
            $balances = $this->db->select($sql, $params);

            // 7. معالجة البيانات
            $totalQuantity = 0.0;
            $totalValue = 0.0;
            $lowStockCount = 0;
            $outOfStockCount = 0;

            foreach ($balances as &$balance) {
                $balance['quantity'] = (float) $balance['quantity'];
                $balance['reserved_quantity'] = (float) $balance['reserved_quantity'];
                $balance['available_quantity'] = (float) $balance['available_quantity'];
                $balance['total_value'] = (float) $balance['total_value'];
                $balance['min_stock'] = (float) $balance['min_stock'];
                $balance['reorder_point'] = (float) $balance['reorder_point'];
                $balance['max_stock'] = (float) $balance['max_stock'];
                $balance['cost_price'] = $balance['cost_price'] !== null ? (float) $balance['cost_price'] : null;

                $balance['stock_status'] = $this->calculateStockStatus(
                    $balance['quantity'],
                    $balance['min_stock'],
                    $balance['reorder_point']
                );
                $balance['stock_status_label'] = self::STATUS_LABELS[$balance['stock_status']];
                $balance['stock_status_color'] = self::STATUS_COLORS[$balance['stock_status']];

                $totalQuantity += $balance['quantity'];
                $totalValue += $balance['total_value'];

                if ($balance['stock_status'] === 'low' || $balance['stock_status'] === 'critical') {
                    $lowStockCount++;
                }
                if ($balance['stock_status'] === 'out_of_stock') {
                    $outOfStockCount++;
                }
            }

            // 8. إرجاع الاستجابة
            Response::paginated(
                data: $balances,
                total: $total,
                page: $page,
                perPage: $perPage,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] Warehouse failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب أرصدة المخزن');
        }
    }

    // =========================================================================
    // 4. رصيد منتج في مخزن محدد (Product Warehouse)
    // =========================================================================

    /**
     * عرض رصيد منتج معين في مخزن محدد
     *
     * GET /api/stock/balances/product/{productId}/warehouse/{warehouseId}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function productWarehouse(array $params): void
    {
        try {
            $productId = $this->validateProductId($params);
            $warehouseId = $params['warehouse_id'] ?? null;

            if ($warehouseId === null || !is_numeric($warehouseId) || (int) $warehouseId <= 0) {
                Response::badRequest('معرف المخزن غير صالح');
            }
            $warehouseId = (int) $warehouseId;

            // جلب الرصيد
            $balance = $this->db->selectOne("
                SELECT
                    sb.product_id,
                    sb.warehouse_id,
                    sb.quantity,
                    sb.reserved_quantity,
                    sb.available_quantity,
                    sb.last_movement_id,
                    sb.last_movement_date,
                    sb.created_at,
                    sb.updated_at,
                    p.code AS product_code,
                    p.barcode,
                    p.name AS product_name,
                    p.min_stock,
                    p.reorder_point,
                    p.max_stock,
                    p.cost_price,
                    c.name AS category_name,
                    u.symbol AS unit_symbol,
                    w.code AS warehouse_code,
                    w.name AS warehouse_name,
                    w.type AS warehouse_type,
                    (sb.quantity * COALESCE(p.cost_price, 0)) AS total_value
                FROM stock_balances sb
                INNER JOIN products p ON sb.product_id = p.id
                INNER JOIN warehouses w ON sb.warehouse_id = w.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN units u ON p.unit_id = u.id
                WHERE sb.product_id = ? AND sb.warehouse_id = ?
                  AND p.deleted_at IS NULL
                  AND w.deleted_at IS NULL
            ", [$productId, $warehouseId]);

            if (!$balance) {
                // إذا لم يكن هناك رصيد، إرجاع صفر
                $product = $this->db->selectOne(
                    "SELECT id, code, name FROM products WHERE id = ? AND deleted_at IS NULL",
                    [$productId]
                );
                $warehouse = $this->db->selectOne(
                    "SELECT id, code, name FROM warehouses WHERE id = ? AND deleted_at IS NULL",
                    [$warehouseId]
                );

                if (!$product || !$warehouse) {
                    Response::notFound('المنتج أو المخزن غير موجود');
                }

                Response::success(
                    message: 'لا يوجد رصيد لهذا المنتج في هذا المخزن',
                    data: [
                        'balance' => [
                            'product_id'   => $productId,
                            'warehouse_id' => $warehouseId,
                            'product_name' => $product['name'],
                            'warehouse_name' => $warehouse['name'],
                            'quantity'     => 0.0,
                            'reserved_quantity' => 0.0,
                            'available_quantity' => 0.0,
                            'total_value'  => 0.0,
                            'stock_status' => 'out_of_stock',
                            'stock_status_label' => 'نفد',
                            'stock_status_color' => '#e74c3c',
                        ],
                    ]
                );
            }

            // معالجة البيانات
            $balance['quantity'] = (float) $balance['quantity'];
            $balance['reserved_quantity'] = (float) $balance['reserved_quantity'];
            $balance['available_quantity'] = (float) $balance['available_quantity'];
            $balance['total_value'] = (float) $balance['total_value'];
            $balance['min_stock'] = (float) $balance['min_stock'];
            $balance['reorder_point'] = (float) $balance['reorder_point'];
            $balance['max_stock'] = (float) $balance['max_stock'];
            $balance['cost_price'] = $balance['cost_price'] !== null ? (float) $balance['cost_price'] : null;

            $balance['stock_status'] = $this->calculateStockStatus(
                $balance['quantity'],
                $balance['min_stock'],
                $balance['reorder_point']
            );
            $balance['stock_status_label'] = self::STATUS_LABELS[$balance['stock_status']];
            $balance['stock_status_color'] = self::STATUS_COLORS[$balance['stock_status']];

            Response::success(
                message: 'تم جلب الرصيد بنجاح',
                data: ['balance' => $balance]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] ProductWarehouse failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الرصيد');
        }
    }

    // =========================================================================
    // 5. المنتجات منخفضة المخزون (Low Stock)
    // =========================================================================

    /**
     * عرض المنتجات منخفضة المخزون
     *
     * GET /api/stock/balances/low-stock
     *
     * Query Parameters:
     * - limit: حد النتائج (افتراضي: 50، حد أقصى: 200)
     * - warehouse_id: تصفية حسب المخزن
     *
     * @return void يرسل استجابة JSON
     */
    public function lowStock(): void
    {
        try {
            $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
            $warehouseId = !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null;

            $sql = "
                SELECT
                    p.id AS product_id,
                    p.code AS product_code,
                    p.barcode,
                    p.name AS product_name,
                    p.min_stock,
                    p.reorder_point,
                    p.max_stock,
                    p.cost_price,
                    c.name AS category_name,
                    u.symbol AS unit_symbol,
                    SUM(sb.quantity) AS total_quantity,
                    SUM(sb.reserved_quantity) AS total_reserved,
                    SUM(sb.available_quantity) AS total_available,
                    SUM(sb.quantity * COALESCE(p.cost_price, 0)) AS total_value,
                    GROUP_CONCAT(DISTINCT w.name SEPARATOR ', ') AS warehouses,
                    COUNT(DISTINCT sb.warehouse_id) AS warehouses_count
                FROM products p
                INNER JOIN stock_balances sb ON p.id = sb.product_id
                INNER JOIN warehouses w ON sb.warehouse_id = w.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN units u ON p.unit_id = u.id
                WHERE p.is_active = 1
                  AND p.deleted_at IS NULL
                  AND w.deleted_at IS NULL
            ";

            $params = [];

            if ($warehouseId !== null) {
                $sql .= " AND sb.warehouse_id = ?";
                $params[] = $warehouseId;
            }

            $sql .= "
                GROUP BY p.id, p.code, p.barcode, p.name, p.min_stock, p.reorder_point,
                         p.max_stock, p.cost_price, c.name, u.symbol
                HAVING total_quantity > 0 AND total_quantity <= p.reorder_point
                ORDER BY (total_quantity / NULLIF(p.reorder_point, 0)) ASC
                LIMIT ?
            ";

            $params[] = $limit;

            $products = $this->db->select($sql, $params);

            // معالجة البيانات
            foreach ($products as &$product) {
                $product['total_quantity'] = (float) $product['total_quantity'];
                $product['total_reserved'] = (float) $product['total_reserved'];
                $product['total_available'] = (float) $product['total_available'];
                $product['total_value'] = (float) $product['total_value'];
                $product['min_stock'] = (float) $product['min_stock'];
                $product['reorder_point'] = (float) $product['reorder_point'];
                $product['max_stock'] = (float) $product['max_stock'];
                $product['cost_price'] = $product['cost_price'] !== null ? (float) $product['cost_price'] : null;
                $product['warehouses_count'] = (int) $product['warehouses_count'];

                // حساب نسبة المخزون المتبقي
                if ($product['reorder_point'] > 0) {
                    $product['stock_percentage'] = round(
                        ($product['total_quantity'] / $product['reorder_point']) * 100,
                        1
                    );
                } else {
                    $product['stock_percentage'] = 0.0;
                }

                $product['stock_status'] = $this->calculateStockStatus(
                    $product['total_quantity'],
                    $product['min_stock'],
                    $product['reorder_point']
                );
                $product['stock_status_label'] = self::STATUS_LABELS[$product['stock_status']];
                $product['stock_status_color'] = self::STATUS_COLORS[$product['stock_status']];
            }

            Response::success(
                message: 'تم جلب المنتجات منخفضة المخزون بنجاح',
                data: [
                    'count'    => count($products),
                    'products' => $products,
                ]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] LowStock failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب المنتجات منخفضة المخزون');
        }
    }

    // =========================================================================
    // 6. المنتجات التي نفدت (Out of Stock)
    // =========================================================================

    /**
     * عرض المنتجات التي نفدت
     *
     * GET /api/stock/balances/out-of-stock
     *
     * @return void يرسل استجابة JSON
     */
    public function outOfStock(): void
    {
        try {
            $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));

            $products = $this->db->select("
                SELECT
                    p.id AS product_id,
                    p.code AS product_code,
                    p.barcode,
                    p.name AS product_name,
                    p.min_stock,
                    p.reorder_point,
                    c.name AS category_name,
                    u.symbol AS unit_symbol,
                    COUNT(DISTINCT sb.warehouse_id) AS warehouses_count,
                    GROUP_CONCAT(DISTINCT w.name SEPARATOR ', ') AS warehouses
                FROM products p
                LEFT JOIN stock_balances sb ON p.id = sb.product_id
                LEFT JOIN warehouses w ON sb.warehouse_id = w.id AND w.deleted_at IS NULL
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN units u ON p.unit_id = u.id
                WHERE p.is_active = 1
                  AND p.deleted_at IS NULL
                  AND (sb.quantity IS NULL OR sb.quantity = 0)
                GROUP BY p.id, p.code, p.barcode, p.name, p.min_stock, p.reorder_point,
                         c.name, u.symbol
                ORDER BY p.name ASC
                LIMIT ?
            ", [$limit]);

            // معالجة البيانات
            foreach ($products as &$product) {
                $product['warehouses_count'] = (int) $product['warehouses_count'];
                $product['stock_status'] = 'out_of_stock';
                $product['stock_status_label'] = 'نفد';
                $product['stock_status_color'] = '#e74c3c';
            }

            Response::success(
                message: 'تم جلب المنتجات التي نفدت بنجاح',
                data: [
                    'count'    => count($products),
                    'products' => $products,
                ]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] OutOfStock failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب المنتجات التي نفدت');
        }
    }

    // =========================================================================
    // 7. إحصائيات الأرصدة العامة (Statistics)
    // =========================================================================

    /**
     * جلب إحصائيات الأرصدة العامة
     *
     * GET /api/stock/balances/statistics
     *
     * @return void يرسل استجابة JSON
     */
    public function statistics(): void
    {
        try {
            $stats = $this->stockService->getStockStatistics();

            // إضافة معلومات إضافية
            $stats['status_labels'] = self::STATUS_LABELS;
            $stats['status_colors'] = self::STATUS_COLORS;

            // حساب النسب المئوية
            $totalProducts = $stats['total_products'] ?? 0;
            if ($totalProducts > 0) {
                $stats['low_stock_percentage'] = round(
                    (($stats['low_stock_count'] ?? 0) / $totalProducts) * 100,
                    1
                );
                $stats['out_of_stock_percentage'] = round(
                    (($stats['out_of_stock_count'] ?? 0) / $totalProducts) * 100,
                    1
                );
                $stats['healthy_percentage'] = round(
                    100 - $stats['low_stock_percentage'] - $stats['out_of_stock_percentage'],
                    1
                );
            } else {
                $stats['low_stock_percentage'] = 0.0;
                $stats['out_of_stock_percentage'] = 0.0;
                $stats['healthy_percentage'] = 0.0;
            }

            Response::success(
                message: 'تم جلب الإحصائيات بنجاح',
                data: $stats
            );

        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] Statistics failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الإحصائيات');
        }
    }

    // =========================================================================
    // 8. ملخص حسب المخزن (Warehouse Summary)
    // =========================================================================

    /**
     * جلب ملخص الأرصدة لكل مخزن
     *
     * GET /api/stock/balances/warehouse-summary
     *
     * @return void يرسل استجابة JSON
     */
    public function warehouseSummary(): void
    {
        try {
            $summaries = $this->db->select("
                SELECT
                    w.id AS warehouse_id,
                    w.code AS warehouse_code,
                    w.name AS warehouse_name,
                    w.type AS warehouse_type,
                    w.is_active,
                    COUNT(DISTINCT sb.product_id) AS products_count,
                    COALESCE(SUM(sb.quantity), 0) AS total_quantity,
                    COALESCE(SUM(sb.reserved_quantity), 0) AS total_reserved,
                    COALESCE(SUM(sb.available_quantity), 0) AS total_available,
                    COALESCE(SUM(sb.quantity * p.cost_price), 0) AS total_value,
                    COUNT(DISTINCT CASE WHEN sb.quantity <= p.reorder_point AND sb.quantity > 0 THEN p.id END) AS low_stock_count,
                    COUNT(DISTINCT CASE WHEN sb.quantity = 0 THEN p.id END) AS out_of_stock_count
                FROM warehouses w
                LEFT JOIN stock_balances sb ON w.id = sb.warehouse_id
                LEFT JOIN products p ON sb.product_id = p.id AND p.deleted_at IS NULL
                WHERE w.deleted_at IS NULL
                GROUP BY w.id, w.code, w.name, w.type, w.is_active
                ORDER BY w.name ASC
            ");

            // معالجة البيانات
            foreach ($summaries as &$summary) {
                $summary['products_count'] = (int) $summary['products_count'];
                $summary['total_quantity'] = (float) $summary['total_quantity'];
                $summary['total_reserved'] = (float) $summary['total_reserved'];
                $summary['total_available'] = (float) $summary['total_available'];
                $summary['total_value'] = (float) $summary['total_value'];
                $summary['low_stock_count'] = (int) $summary['low_stock_count'];
                $summary['out_of_stock_count'] = (int) $summary['out_of_stock_count'];
                $summary['is_active'] = (bool) $summary['is_active'];

                // ترجمة نوع المخزن
                $typeLabels = [
                    'main'    => 'رئيسي',
                    'sub'     => 'فرعي',
                    'cold'    => 'تبريد',
                    'freezer' => 'تجميد',
                ];
                $summary['warehouse_type_label'] = $typeLabels[$summary['warehouse_type']] ?? $summary['warehouse_type'];
            }

            Response::success(
                message: 'تم جلب ملخص المخازن بنجاح',
                data: [
                    'count'     => count($summaries),
                    'summaries' => $summaries,
                ]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] WarehouseSummary failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب ملخص المخازن');
        }
    }

    // =========================================================================
    // 9. ملخص حسب التصنيف (Category Summary)
    // =========================================================================

    /**
     * جلب ملخص الأرصدة لكل تصنيف
     *
     * GET /api/stock/balances/category-summary
     *
     * @return void يرسل استجابة JSON
     */
    public function categorySummary(): void
    {
        try {
            $summaries = $this->db->select("
                SELECT
                    c.id AS category_id,
                    c.code AS category_code,
                    c.name AS category_name,
                    COUNT(DISTINCT p.id) AS products_count,
                    COALESCE(SUM(sb.quantity), 0) AS total_quantity,
                    COALESCE(SUM(sb.available_quantity), 0) AS total_available,
                    COALESCE(SUM(sb.quantity * p.cost_price), 0) AS total_value,
                    COUNT(DISTINCT CASE WHEN sb.quantity <= p.reorder_point AND sb.quantity > 0 THEN p.id END) AS low_stock_count,
                    COUNT(DISTINCT CASE WHEN sb.quantity = 0 THEN p.id END) AS out_of_stock_count
                FROM categories c
                LEFT JOIN products p ON c.id = p.category_id AND p.deleted_at IS NULL
                LEFT JOIN stock_balances sb ON p.id = sb.product_id
                WHERE c.deleted_at IS NULL
                  AND c.is_active = 1
                GROUP BY c.id, c.code, c.name
                ORDER BY c.name ASC
            ");

            // معالجة البيانات
            foreach ($summaries as &$summary) {
                $summary['products_count'] = (int) $summary['products_count'];
                $summary['total_quantity'] = (float) $summary['total_quantity'];
                $summary['total_available'] = (float) $summary['total_available'];
                $summary['total_value'] = (float) $summary['total_value'];
                $summary['low_stock_count'] = (int) $summary['low_stock_count'];
                $summary['out_of_stock_count'] = (int) $summary['out_of_stock_count'];
            }

            Response::success(
                message: 'تم جلب ملخص التصنيفات بنجاح',
                data: [
                    'count'     => count($summaries),
                    'summaries' => $summaries,
                ]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] CategorySummary failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب ملخص التصنيفات');
        }
    }

    // =========================================================================
    // 10. تصدير الأرصدة (Export)
    // =========================================================================

    /**
     * تصدير الأرصدة بصيغ مختلفة
     *
     * GET /api/stock/balances/export
     *
     * Query Parameters:
     * - format: صيغة التصدير (csv, excel, pdf)
     * - جميع الفلاتر المدعومة في index
     *
     * @return void
     */
    public function export(): void
    {
        try {
            $format = strtolower($_GET['format'] ?? 'csv');

            if (!in_array($format, ['csv', 'excel', 'pdf'], true)) {
                $format = 'csv';
            }

            // بناء الاستعلام بدون LIMIT
            $filters = [
                'warehouse_id' => !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null,
                'product_id'   => !empty($_GET['product_id']) ? (int) $_GET['product_id'] : null,
                'category_id'  => !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null,
                'stock_status' => $_GET['stock_status'] ?? null,
                'low_stock_only' => isset($_GET['low_stock_only']) && (int) $_GET['low_stock_only'] === 1,
                'search'       => trim($_GET['search'] ?? ''),
                'sort_by'      => $_GET['sort_by'] ?? 'product_name',
                'sort_order'   => strtolower($_GET['sort_order'] ?? 'asc'),
            ];

            $this->validateFilters($filters);

            [$sql, $params] = $this->buildBalancesQuery($filters);
            $sql .= " LIMIT 10000"; // حد أقصى 10,000 سجل

            $balances = $this->db->select($sql, $params);

            // معالجة البيانات
            foreach ($balances as &$balance) {
                $this->enrichBalanceData($balance);

                // إزالة الحقول غير الضرورية للتصدير
                unset($balance['stock_status_color']);
            }

            if (empty($balances)) {
                Response::error('لا توجد بيانات للتصدير', 'NO_DATA', 404);
            }

            $filename = 'stock_balances_' . date('Y-m-d_H-i-s');

            switch ($format) {
                case 'csv':
                    Response::csv($balances, $filename . '.csv', 200);
                    break;

                case 'excel':
                    $this->exportAsExcel($balances, $filename);
                    break;

                case 'pdf':
                    $this->exportAsHtml($balances, $filename);
                    break;
            }

        } catch (Throwable $e) {
            error_log('[STOCK_BALANCE_CONTROLLER] Export failed: ' . $e->getMessage());
            Response::internalError('فشل في تصدير الأرصدة');
        }
    }

    /**
     * تصدير كـ Excel
     */
    private function exportAsExcel(array $balances, string $filename): void
    {
        $html = '<html dir="rtl"><head><meta charset="UTF-8"></head><body>';
        $html .= '<h2 style="text-align: center;">تقرير الأرصدة المخزنية - Logistox</h2>';
        $html .= '<p style="text-align: center;">تاريخ التقرير: ' . date('Y-m-d H:i:s') . '</p>';

        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%; font-family: Arial;">';
        $html .= '<tr style="background: #4a90e2; color: white;">';
        $html .= '<th>#</th>';
        $html .= '<th>كود المنتج</th>';
        $html .= '<th>اسم المنتج</th>';
        $html .= '<th>التصنيف</th>';
        $html .= '<th>المخزن</th>';
        $html .= '<th>الكمية</th>';
        $html .= '<th>المحجوز</th>';
        $html .= '<th>المتاح</th>';
        $html .= '<th>الوحدة</th>';
        $html .= '<th>سعر التكلفة</th>';
        $html .= '<th>القيمة الإجمالية</th>';
        $html .= '<th>الحالة</th>';
        $html .= '</tr>';

        $index = 1;
        foreach ($balances as $balance) {
            $html .= '<tr>';
            $html .= '<td>' . $index++ . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['product_code'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['product_name'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['category_name'] ?? '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['warehouse_name'] ?? '') . '</td>';
            $html .= '<td>' . number_format((float) ($balance['quantity'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($balance['reserved_quantity'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($balance['available_quantity'] ?? 0), 3) . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['unit_symbol'] ?? '-') . '</td>';
            $html .= '<td>' . number_format((float) ($balance['cost_price'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($balance['total_value'] ?? 0), 3) . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['stock_status_label'] ?? '') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        $html .= '</body></html>';

        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename . '.xls') . '"');
        echo "\xEF\xBB\xBF";
        echo $html;
        exit;
    }

    /**
     * تصدير كـ HTML (PDF)
     */
    private function exportAsHtml(array $balances, string $filename): void
    {
        $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>تقرير الأرصدة</title>';
        $html .= '<style>
            body { font-family: Tahoma, Arial; direction: rtl; margin: 20px; }
            h2 { text-align: center; color: #2c3e50; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 11px; }
            th, td { padding: 8px; text-align: right; border: 1px solid #bdc3c7; }
            thead tr { background: #34495e; color: white; }
            tbody tr:nth-child(even) { background: #f9f9f9; }
            .status-normal { color: #27ae60; font-weight: bold; }
            .status-low { color: #f39c12; font-weight: bold; }
            .status-critical { color: #e67e22; font-weight: bold; }
            .status-out_of_stock { color: #e74c3c; font-weight: bold; }
            @media print { body { margin: 0; } }
        </style>';
        $html .= '</head><body>';

        $html .= '<h2>تقرير الأرصدة المخزنية - Logistox</h2>';
        $html .= '<p style="text-align: center;">تاريخ التقرير: ' . date('Y-m-d H:i:s') . ' | عدد السجلات: ' . count($balances) . '</p>';

        $html .= '<table>';
        $html .= '<thead><tr>';
        $html .= '<th>#</th>';
        $html .= '<th>كود المنتج</th>';
        $html .= '<th>اسم المنتج</th>';
        $html .= '<th>التصنيف</th>';
        $html .= '<th>المخزن</th>';
        $html .= '<th>الكمية</th>';
        $html .= '<th>المتاح</th>';
        $html .= '<th>الوحدة</th>';
        $html .= '<th>القيمة</th>';
        $html .= '<th>الحالة</th>';
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        $index = 1;
        foreach ($balances as $balance) {
            $statusClass = 'status-' . ($balance['stock_status'] ?? 'normal');
            $html .= '<tr>';
            $html .= '<td>' . $index++ . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['product_code'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['product_name'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['category_name'] ?? '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['warehouse_name'] ?? '') . '</td>';
            $html .= '<td>' . number_format((float) ($balance['quantity'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($balance['available_quantity'] ?? 0), 3) . '</td>';
            $html .= '<td>' . htmlspecialchars($balance['unit_symbol'] ?? '-') . '</td>';
            $html .= '<td>' . number_format((float) ($balance['total_value'] ?? 0), 3) . '</td>';
            $html .= '<td class="' . $statusClass . '">' . htmlspecialchars($balance['stock_status_label'] ?? '') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= '</body></html>';

        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    // =========================================================================
    // Helper Methods - بناء الاستعلامات
    // =========================================================================

    /**
     * بناء استعلام الأرصدة مع الفلاتر
     *
     * @param array $filters الفلاتر
     * @return array [sql, params]
     */
    private function buildBalancesQuery(array $filters): array
    {
        $sql = "
            SELECT
                sb.product_id,
                sb.warehouse_id,
                p.code AS product_code,
                p.barcode,
                p.name AS product_name,
                c.name AS category_name,
                u.symbol AS unit_symbol,
                w.code AS warehouse_code,
                w.name AS warehouse_name,
                w.type AS warehouse_type,
                sb.quantity,
                sb.reserved_quantity,
                sb.available_quantity,
                p.min_stock,
                p.reorder_point,
                p.max_stock,
                p.cost_price,
                (sb.quantity * COALESCE(p.cost_price, 0)) AS total_value,
                sb.last_movement_date
            FROM stock_balances sb
            INNER JOIN products p ON sb.product_id = p.id
            INNER JOIN warehouses w ON sb.warehouse_id = w.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN units u ON p.unit_id = u.id
            WHERE p.deleted_at IS NULL
              AND w.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND sb.warehouse_id = ?";
            $params[] = $filters['warehouse_id'];
        }

        if (!empty($filters['product_id'])) {
            $sql .= " AND sb.product_id = ?";
            $params[] = $filters['product_id'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND p.category_id = ?";
            $params[] = $filters['category_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['low_stock_only'])) {
            $sql .= " AND sb.quantity > 0 AND sb.quantity <= p.reorder_point";
        }

        if (isset($filters['has_stock'])) {
            if ($filters['has_stock'] === 1) {
                $sql .= " AND sb.quantity > 0";
            } elseif ($filters['has_stock'] === 0) {
                $sql .= " AND sb.quantity = 0";
            }
        }

        if (!empty($filters['stock_status']) && in_array($filters['stock_status'], self::STOCK_STATUSES, true)) {
            switch ($filters['stock_status']) {
                case 'normal':
                    $sql .= " AND sb.quantity > p.reorder_point";
                    break;
                case 'low':
                    $sql .= " AND sb.quantity > p.min_stock AND sb.quantity <= p.reorder_point";
                    break;
                case 'critical':
                    $sql .= " AND sb.quantity > 0 AND sb.quantity <= p.min_stock";
                    break;
                case 'out_of_stock':
                    $sql .= " AND sb.quantity = 0";
                    break;
            }
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'product_name';
        $sortOrder = $filters['sort_order'] ?? 'asc';

        $allowedSortBy = ['product_name', 'product_code', 'quantity', 'total_value', 'warehouse_name', 'last_movement_date'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'product_name';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

        $sql .= " ORDER BY {$sortBy} {$sortOrder}";

        return [$sql, $params];
    }

    /**
     * إثراء بيانات الرصيد بمعلومات إضافية
     */
    private function enrichBalanceData(array &$balance): void
    {
        $balance['quantity'] = (float) ($balance['quantity'] ?? 0);
        $balance['reserved_quantity'] = (float) ($balance['reserved_quantity'] ?? 0);
        $balance['available_quantity'] = (float) ($balance['available_quantity'] ?? 0);
        $balance['total_value'] = (float) ($balance['total_value'] ?? 0);
        $balance['min_stock'] = (float) ($balance['min_stock'] ?? 0);
        $balance['reorder_point'] = (float) ($balance['reorder_point'] ?? 0);
        $balance['max_stock'] = (float) ($balance['max_stock'] ?? 0);
        $balance['cost_price'] = $balance['cost_price'] !== null ? (float) $balance['cost_price'] : null;

        $balance['stock_status'] = $this->calculateStockStatus(
            $balance['quantity'],
            $balance['min_stock'],
            $balance['reorder_point']
        );
        $balance['stock_status_label'] = self::STATUS_LABELS[$balance['stock_status']];
        $balance['stock_status_color'] = self::STATUS_COLORS[$balance['stock_status']];
    }

    /**
     * حساب حالة المخزون
     *
     * @param float $quantity الكمية الحالية
     * @param float $minStock الحد الأدنى
     * @param float $reorderPoint نقطة إعادة الطلب
     * @return string الحالة (normal, low, critical, out_of_stock)
     */
    private function calculateStockStatus(float $quantity, float $minStock, float $reorderPoint): string
    {
        if ($quantity <= 0) {
            return 'out_of_stock';
        }

        if ($quantity <= $minStock) {
            return 'critical';
        }

        if ($quantity <= $reorderPoint) {
            return 'low';
        }

        return 'normal';
    }

    // =========================================================================
    // Helper Methods - التحقق
    // =========================================================================

    /**
     * التحقق من صحة الفلاتر
     */
    private function validateFilters(array $filters): void
    {
        if (!empty($filters['stock_status']) && !in_array($filters['stock_status'], self::STOCK_STATUSES, true)) {
            unset($filters['stock_status']);
        }
    }

    /**
     * التحقق من صحة معرف المنتج
     */
    private function validateProductId(array $params): int
    {
        $id = $params['product_id'] ?? $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف المنتج غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة معرف المخزن
     */
    private function validateWarehouseId(array $params): int
    {
        $id = $params['warehouse_id'] ?? $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف المخزن غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }
}
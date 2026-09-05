<?php

/**
 * ================================================================
 * Logistox - Stock Movement Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/StockMovementController.php
 * الوظيفة: عرض سجل الحركات المخزنية (Ledger) - قراءة فقط
 *
 * المسؤوليات:
 * 1. عرض كل الحركات مع الفلاتر والترقيم (index)
 * 2. تفاصيل حركة معينة (show)
 * 3. حركات منتج معين (productHistory)
 * 4. حركات مخزن معين (warehouseHistory)
 * 5. حركات مستخدم معين (userHistory)
 * 6. الحركات الداخلة فقط (inbound)
 * 7. الحركات الخارجة فقط (outbound)
 * 8. إحصائيات الحركات (statistics)
 * 9. ملخص حسب نوع الحركة (typeSummary)
 * 10. تصدير سجل الحركات (export)
 *
 * أنواع الحركات المدعومة:
 * - RECEIPT: استلام (وارد)
 * - ISSUE: صرف (صادر)
 * - TRANSFER_OUT: تحويل خارج (صادر)
 * - TRANSFER_IN: تحويل داخل (وارد)
 * - RETURN_IN: مرتجع وارد
 * - RETURN_OUT: مرتجع صادر
 * - ADJUSTMENT: تسوية
 * - COUNT_CORRECTION: تصحيح جرد
 * - RESERVATION: حجز
 * - RELEASE: فك حجز
 *
 * الصلاحيات المطلوبة:
 * - stock.view: عرض الحركات
 * - stock.export: تصدير الحركات
 *
 * ملاحظات هامة:
 * - هذا Controller للقراءة فقط (Read Only)
 * - الحركات تُسجل فقط عبر InventoryService
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
 * Class StockMovementController
 *
 * Controller لعرض سجل الحركات المخزنية
 */
class StockMovementController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var StockService خدمة المخزون
     */
    private StockService $stockService;

    /**
     * @var array أنواع الحركات المسموحة
     */
    private const ALLOWED_MOVEMENT_TYPES = [
        'RECEIPT',
        'ISSUE',
        'TRANSFER_OUT',
        'TRANSFER_IN',
        'RETURN_IN',
        'RETURN_OUT',
        'ADJUSTMENT',
        'COUNT_CORRECTION',
        'RESERVATION',
        'RELEASE',
    ];

    /**
     * @var array أسماء أنواع الحركات بالعربية
     */
    private const MOVEMENT_TYPE_LABELS = [
        'RECEIPT'          => 'استلام',
        'ISSUE'            => 'صرف',
        'TRANSFER_OUT'     => 'تحويل خارج',
        'TRANSFER_IN'      => 'تحويل داخل',
        'RETURN_IN'        => 'مرتجع وارد',
        'RETURN_OUT'       => 'مرتجع صادر',
        'ADJUSTMENT'       => 'تسوية',
        'COUNT_CORRECTION' => 'تصحيح جرد',
        'RESERVATION'      => 'حجز',
        'RELEASE'          => 'فك حجز',
    ];

    /**
     * @var array أيقونات أنواع الحركات (Font Awesome)
     */
    private const MOVEMENT_TYPE_ICONS = [
        'RECEIPT'          => 'fa-arrow-down',
        'ISSUE'            => 'fa-arrow-up',
        'TRANSFER_OUT'     => 'fa-sign-out-alt',
        'TRANSFER_IN'      => 'fa-sign-in-alt',
        'RETURN_IN'        => 'fa-undo',
        'RETURN_OUT'       => 'fa-redo',
        'ADJUSTMENT'       => 'fa-balance-scale',
        'COUNT_CORRECTION' => 'fa-clipboard-check',
        'RESERVATION'      => 'fa-lock',
        'RELEASE'          => 'fa-unlock',
    ];

    /**
     * @var array ألوان أنواع الحركات
     */
    private const MOVEMENT_TYPE_COLORS = [
        'RECEIPT'          => '#27ae60', // أخضر
        'ISSUE'            => '#e74c3c', // أحمر
        'TRANSFER_OUT'     => '#e67e22', // برتقالي
        'TRANSFER_IN'      => '#3498db', // أزرق
        'RETURN_IN'        => '#16a085', // أخضر مائي
        'RETURN_OUT'       => '#d35400', // برتقالي غامق
        'ADJUSTMENT'       => '#9b59b6', // بنفسجي
        'COUNT_CORRECTION' => '#8e44ad', // بنفسجي غامق
        'RESERVATION'      => '#f39c12', // أصفر
        'RELEASE'          => '#f1c40f', // أصفر فاتح
    ];

    /**
     * @var array أنواع الحركات الداخلة (Inbound)
     */
    private const INBOUND_TYPES = [
        'RECEIPT',
        'TRANSFER_IN',
        'RETURN_IN',
        'COUNT_CORRECTION',
    ];

    /**
     * @var array أنواع الحركات الخارجة (Outbound)
     */
    private const OUTBOUND_TYPES = [
        'ISSUE',
        'TRANSFER_OUT',
        'RETURN_OUT',
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
            error_log('[STOCK_MOVEMENT_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة الحركات');
        }
    }

    // =========================================================================
    // 1. عرض كل الحركات (Index)
    // =========================================================================

    /**
     * عرض كل الحركات المخزنية مع الفلاتر والترقيم
     *
     * GET /api/stock/movements
     *
     * Query Parameters:
     * - product_id: تصفية حسب المنتج
     * - warehouse_id: تصفية حسب المخزن
     * - movement_type: تصفية حسب نوع الحركة
     * - user_id: تصفية حسب المستخدم
     * - reference_type: تصفية حسب نوع المستند المرجعي
     * - reference_id: تصفية حسب معرف المستند المرجعي
     * - from_date: من تاريخ (YYYY-MM-DD)
     * - to_date: إلى تاريخ (YYYY-MM-DD)
     * - search: بحث في رقم الحركة أو الملاحظات
     * - direction: تصفية حسب الاتجاه (inbound, outbound)
     * - page: رقم الصفحة (افتراضي: 1)
     * - per_page: عدد العناصر (افتراضي: 50، حد أقصى: 200)
     * - sort_by: ترتيب حسب (movement_date, product_name, quantity, movement_type)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            // 1. قراءة Query Parameters
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 50)));
            $offset = ($page - 1) * $perPage;

            $filters = [
                'product_id'     => !empty($_GET['product_id']) ? (int) $_GET['product_id'] : null,
                'warehouse_id'   => !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null,
                'movement_type'  => $_GET['movement_type'] ?? null,
                'user_id'        => !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null,
                'reference_type' => $_GET['reference_type'] ?? null,
                'reference_id'   => !empty($_GET['reference_id']) ? (int) $_GET['reference_id'] : null,
                'from_date'      => $_GET['from_date'] ?? null,
                'to_date'        => $_GET['to_date'] ?? null,
                'search'         => trim($_GET['search'] ?? ''),
                'direction'      => $_GET['direction'] ?? null,
                'sort_by'        => $_GET['sort_by'] ?? 'movement_date',
                'sort_order'     => strtolower($_GET['sort_order'] ?? 'desc'),
            ];

            // 2. التحقق من صحة الفلاتر
            $this->validateFilters($filters);

            // 3. بناء الاستعلام
            [$sql, $params] = $this->buildMovementsQuery($filters);

            // 4. جلب العدد الإجمالي
            $countSql = "SELECT COUNT(*) AS total FROM ({$sql}) AS subquery";
            $totalResult = $this->db->selectOne($countSql, $params);
            $total = (int) ($totalResult['total'] ?? 0);

            // 5. إضافة LIMIT و OFFSET
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $perPage;
            $params[] = $offset;

            // 6. جلب البيانات
            $movements = $this->db->select($sql, $params);

            // 7. معالجة البيانات
            foreach ($movements as &$movement) {
                $this->enrichMovementData($movement);
            }

            // 8. إرجاع الاستجابة مع الترقيم
            Response::paginated(
                data: $movements,
                total: $total,
                page: $page,
                perPage: $perPage,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[STOCK_MOVEMENT_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الحركات');
        }
    }

    // =========================================================================
    // 2. تفاصيل حركة معينة (Show)
    // =========================================================================

    /**
     * عرض تفاصيل حركة مخزنية معينة
     *
     * GET /api/stock/movements/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            $movementId = $this->validateMovementId($params);

            // جلب الحركة مع كل التفاصيل
            $movement = $this->db->selectOne("
                SELECT
                    sm.id,
                    sm.movement_number,
                    sm.movement_type,
                    sm.quantity,
                    sm.unit_cost,
                    sm.total_cost,
                    sm.balance_before,
                    sm.balance_after,
                    sm.reserved_before,
                    sm.reserved_after,
                    sm.reference_type,
                    sm.reference_id,
                    sm.batch_number,
                    sm.expiry_date,
                    sm.notes,
                    sm.movement_date,
                    sm.created_at,
                    sm.updated_at,
                    sm.product_id,
                    sm.warehouse_id,
                    sm.from_warehouse_id,
                    sm.to_warehouse_id,
                    sm.user_id,
                    p.code AS product_code,
                    p.barcode,
                    p.name AS product_name,
                    c.name AS category_name,
                    u.symbol AS unit_symbol,
                    w.code AS warehouse_code,
                    w.name AS warehouse_name,
                    w_from.name AS from_warehouse_name,
                    w_to.name AS to_warehouse_name,
                    usr.username,
                    usr.full_name AS user_name
                FROM stock_movements sm
                INNER JOIN products p ON sm.product_id = p.id
                INNER JOIN warehouses w ON sm.warehouse_id = w.id
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN units u ON p.unit_id = u.id
                LEFT JOIN warehouses w_from ON sm.from_warehouse_id = w_from.id
                LEFT JOIN warehouses w_to ON sm.to_warehouse_id = w_to.id
                LEFT JOIN users usr ON sm.user_id = usr.id
                WHERE sm.id = ?
                  AND sm.deleted_at IS NULL
            ", [$movementId]);

            if (!$movement) {
                Response::notFound('الحركة غير موجودة');
            }

            // معالجة البيانات
            $this->enrichMovementData($movement);

            // إضافة معلومات إضافية
            $movement['change'] = (float) $movement['balance_after'] - (float) $movement['balance_before'];
            $movement['is_inbound'] = in_array($movement['movement_type'], self::INBOUND_TYPES, true);
            $movement['is_outbound'] = in_array($movement['movement_type'], self::OUTBOUND_TYPES, true);

            // جلب المستند المرجعي (إذا وجد)
            if (!empty($movement['reference_type']) && !empty($movement['reference_id'])) {
                $movement['reference_document'] = $this->getReferenceDocument(
                    $movement['reference_type'],
                    (int) $movement['reference_id']
                );
            } else {
                $movement['reference_document'] = null;
            }

            Response::success(
                message: 'تم جلب تفاصيل الحركة بنجاح',
                data: ['movement' => $movement]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_MOVEMENT_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل الحركة');
        }
    }

    // =========================================================================
    // 3. حركات منتج معين (Product History)
    // =========================================================================

    /**
     * عرض سجل حركات منتج معين
     *
     * GET /api/stock/movements/product/{productId}
     *
     * Query Parameters:
     * - warehouse_id: تصفية حسب المخزن
     * - movement_type: تصفية حسب نوع الحركة
     * - from_date, to_date: نطاق التاريخ
     * - limit: حد النتائج (افتراضي: 100، حد أقصى: 500)
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function productHistory(array $params): void
    {
        try {
            $productId = $this->validateProductId($params);

            // جلب معلومات المنتج
            $product = $this->db->selectOne("
                SELECT id, code, barcode, name, min_stock, reorder_point, max_stock
                FROM products
                WHERE id = ? AND deleted_at IS NULL
            ", [$productId]);

            if (!$product) {
                Response::notFound('المنتج غير موجود');
            }

            // قراءة الفلاتر
            $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
            $warehouseId = !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null;
            $movementType = $_GET['movement_type'] ?? null;
            $fromDate = $_GET['from_date'] ?? null;
            $toDate = $_GET['to_date'] ?? null;

            // بناء الاستعلام
            $sql = "
                SELECT
                    sm.id,
                    sm.movement_number,
                    sm.movement_type,
                    sm.quantity,
                    sm.unit_cost,
                    sm.total_cost,
                    sm.balance_before,
                    sm.balance_after,
                    sm.reference_type,
                    sm.reference_id,
                    sm.notes,
                    sm.movement_date,
                    w.name AS warehouse_name,
                    w_from.name AS from_warehouse_name,
                    w_to.name AS to_warehouse_name,
                    usr.full_name AS user_name
                FROM stock_movements sm
                INNER JOIN warehouses w ON sm.warehouse_id = w.id
                LEFT JOIN warehouses w_from ON sm.from_warehouse_id = w_from.id
                LEFT JOIN warehouses w_to ON sm.to_warehouse_id = w_to.id
                LEFT JOIN users usr ON sm.user_id = usr.id
                WHERE sm.product_id = ?
                  AND sm.deleted_at IS NULL
            ";

            $params = [$productId];

            if ($warehouseId !== null) {
                $sql .= " AND sm.warehouse_id = ?";
                $params[] = $warehouseId;
            }

            if ($movementType !== null && in_array($movementType, self::ALLOWED_MOVEMENT_TYPES, true)) {
                $sql .= " AND sm.movement_type = ?";
                $params[] = $movementType;
            }

            if ($fromDate !== null) {
                $sql .= " AND sm.movement_date >= ?";
                $params[] = $fromDate . ' 00:00:00';
            }

            if ($toDate !== null) {
                $sql .= " AND sm.movement_date <= ?";
                $params[] = $toDate . ' 23:59:59';
            }

            $sql .= " ORDER BY sm.movement_date DESC, sm.id DESC LIMIT ?";
            $params[] = $limit;

            $movements = $this->db->select($sql, $params);

            // معالجة البيانات
            $totalInbound = 0.0;
            $totalOutbound = 0.0;
            $movementCount = count($movements);

            foreach ($movements as &$movement) {
                $this->enrichMovementData($movement);

                $movement['quantity'] = (float) $movement['quantity'];
                $movement['balance_before'] = (float) $movement['balance_before'];
                $movement['balance_after'] = (float) $movement['balance_after'];

                if (in_array($movement['movement_type'], self::INBOUND_TYPES, true)) {
                    $totalInbound += $movement['quantity'];
                } elseif (in_array($movement['movement_type'], self::OUTBOUND_TYPES, true)) {
                    $totalOutbound += $movement['quantity'];
                }
            }

            Response::success(
                message: 'تم جلب سجل حركات المنتج بنجاح',
                data: [
                    'product' => [
                        'id'            => (int) $product['id'],
                        'code'          => $product['code'],
                        'barcode'       => $product['barcode'],
                        'name'          => $product['name'],
                        'min_stock'     => (float) $product['min_stock'],
                        'reorder_point' => (float) $product['reorder_point'],
                        'max_stock'     => (float) $product['max_stock'],
                    ],
                    'summary' => [
                        'movement_count' => $movementCount,
                        'total_inbound'  => $totalInbound,
                        'total_outbound' => $totalOutbound,
                        'net_change'     => $totalInbound - $totalOutbound,
                    ],
                    'movements' => $movements,
                ]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_MOVEMENT_CONTROLLER] ProductHistory failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب سجل حركات المنتج');
        }
    }

    // =========================================================================
    // 4. حركات مخزن معين (Warehouse History)
    // =========================================================================

    /**
     * عرض سجل حركات مخزن معين
     *
     * GET /api/stock/movements/warehouse/{warehouseId}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function warehouseHistory(array $params): void
    {
        try {
            $warehouseId = $this->validateWarehouseId($params);

            // جلب معلومات المخزن
            $warehouse = $this->db->selectOne(
                "SELECT id, code, name, type FROM warehouses WHERE id = ? AND deleted_at IS NULL",
                [$warehouseId]
            );

            if (!$warehouse) {
                Response::notFound('المخزن غير موجود');
            }

            // قراءة الفلاتر
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 50)));
            $offset = ($page - 1) * $perPage;
            $movementType = $_GET['movement_type'] ?? null;
            $fromDate = $_GET['from_date'] ?? null;
            $toDate = $_GET['to_date'] ?? null;

            // بناء الاستعلام
            $sql = "
                SELECT
                    sm.id,
                    sm.movement_number,
                    sm.movement_type,
                    sm.quantity,
                    sm.unit_cost,
                    sm.total_cost,
                    sm.balance_before,
                    sm.balance_after,
                    sm.reference_type,
                    sm.reference_id,
                    sm.notes,
                    sm.movement_date,
                    p.code AS product_code,
                    p.name AS product_name,
                    u.symbol AS unit_symbol,
                    usr.full_name AS user_name
                FROM stock_movements sm
                INNER JOIN products p ON sm.product_id = p.id
                LEFT JOIN units u ON p.unit_id = u.id
                LEFT JOIN users usr ON sm.user_id = usr.id
                WHERE sm.warehouse_id = ?
                  AND sm.deleted_at IS NULL
            ";

            $params = [$warehouseId];

            if ($movementType !== null && in_array($movementType, self::ALLOWED_MOVEMENT_TYPES, true)) {
                $sql .= " AND sm.movement_type = ?";
                $params[] = $movementType;
            }

            if ($fromDate !== null) {
                $sql .= " AND sm.movement_date >= ?";
                $params[] = $fromDate . ' 00:00:00';
            }

            if ($toDate !== null) {
                $sql .= " AND sm.movement_date <= ?";
                $params[] = $toDate . ' 23:59:59';
            }

            // عد الإجمالي
            $countSql = "SELECT COUNT(*) AS total FROM ({$sql}) AS subquery";
            $totalResult = $this->db->selectOne($countSql, $params);
            $total = (int) ($totalResult['total'] ?? 0);

            $sql .= " ORDER BY sm.movement_date DESC, sm.id DESC LIMIT ? OFFSET ?";
            $params[] = $perPage;
            $params[] = $offset;

            $movements = $this->db->select($sql, $params);

            // معالجة البيانات
            foreach ($movements as &$movement) {
                $this->enrichMovementData($movement);
            }

            Response::paginated(
                data: $movements,
                total: $total,
                page: $page,
                perPage: $perPage,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[STOCK_MOVEMENT_CONTROLLER] WarehouseHistory failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب سجل حركات المخزن');
        }
    }

    // =========================================================================
    // 5. حركات مستخدم معين (User History)
    // =========================================================================

    /**
     * عرض سجل حركات مستخدم معين
     *
     * GET /api/stock/movements/user/{userId}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function userHistory(array $params): void
    {
        try {
            $userId = $params['user_id'] ?? null;

            if ($userId === null || !is_numeric($userId) || (int) $userId <= 0) {
                Response::badRequest('معرف المستخدم غير صالح');
            }
            $userId = (int) $userId;

            // جلب معلومات المستخدم
            $user = $this->db->selectOne(
                "SELECT id, username, full_name FROM users WHERE id = ? AND deleted_at IS NULL",
                [$userId]
            );

            if (!$user) {
                Response::notFound('المستخدم غير موجود');
            }

            // قراءة الفلاتر
            $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
            $fromDate = $_GET['from_date'] ?? null;
            $toDate = $_GET['to_date'] ?? null;

            $sql = "
                SELECT
                    sm.id,
                    sm.movement_number,
                    sm.movement_type,
                    sm.quantity,
                    sm.balance_before,
                    sm.balance_after,
                    sm.movement_date,
                    p.code AS product_code,
                    p.name AS product_name,
                    w.name AS warehouse_name
                FROM stock_movements sm
                INNER JOIN products p ON sm.product_id = p.id
                INNER JOIN warehouses w ON sm.warehouse_id = w.id
                WHERE sm.user_id = ?
                  AND sm.deleted_at IS NULL
            ";

            $params = [$userId];

            if ($fromDate !== null) {
                $sql .= " AND sm.movement_date >= ?";
                $params[] = $fromDate . ' 00:00:00';
            }

            if ($toDate !== null) {
                $sql .= " AND sm.movement_date <= ?";
                $params[] = $toDate . ' 23:59:59';
            }

            $sql .= " ORDER BY sm.movement_date DESC LIMIT ?";
            $params[] = $limit;

            $movements = $this->db->select($sql, $params);

            // معالجة البيانات
            foreach ($movements as &$movement) {
                $this->enrichMovementData($movement);
            }

            Response::success(
                message: 'تم جلب سجل حركات المستخدم بنجاح',
                data: [
                    'user'      => $user,
                    'movements' => $movements,
                    'count'     => count($movements),
                ]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_MOVEMENT_CONTROLLER] UserHistory failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب سجل حركات المستخدم');
        }
    }

    // =========================================================================
    // 6. الحركات الداخلة (Inbound)
    // =========================================================================

    /**
     * عرض الحركات الداخلة فقط (استلام، تحويل داخل، مرتجع وارد)
     *
     * GET /api/stock/movements/inbound
     *
     * @return void يرسل استجابة JSON
     */
    public function inbound(): void
    {
        try {
            $_GET['direction'] = 'inbound';
            $this->index();

        } catch (Throwable $e) {
            error_log('[STOCK_MOVEMENT_CONTROLLER] Inbound failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الحركات الداخلة');
        }
    }

    // =========================================================================
    // 7. الحركات الخارجة (Outbound)
    // =========================================================================

    /**
     * عرض الحركات الخارجة فقط (صرف، تحويل خارج، مرتجع صادر)
     *
     * GET /api/stock/movements/outbound
     *
     * @return void يرسل استجابة JSON
     */
    public function outbound(): void
    {
        try {
            $_GET['direction'] = 'outbound';
            $this->index();

        } catch (Throwable $e) {
            error_log('[STOCK_MOVEMENT_CONTROLLER] Outbound failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الحركات الخارجة');
        }
    }

    // =========================================================================
    // 8. إحصائيات الحركات (Statistics)
    // =========================================================================

    /**
     * جلب إحصائيات الحركات المخزنية
     *
     * GET /api/stock/movements/statistics
     *
     * Query Parameters:
     * - from_date: من تاريخ (افتراضي: آخر 30 يوم)
     * - to_date: إلى تاريخ (افتراضي: اليوم)
     *
     * @return void يرسل استجابة JSON
     */
    public function statistics(): void
    {
        try {
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            // 1. إجمالي الحركات
            $totalMovements = $this->db->selectOne("
                SELECT COUNT(*) AS count,
                       COALESCE(SUM(quantity), 0) AS total_quantity
                FROM stock_movements
                WHERE DATE(movement_date) BETWEEN ? AND ?
                  AND deleted_at IS NULL
            ", [$fromDate, $toDate]);

            // 2. الحركات حسب النوع
            $byType = $this->db->select("
                SELECT
                    movement_type,
                    COUNT(*) AS count,
                    COALESCE(SUM(quantity), 0) AS total_quantity,
                    COALESCE(SUM(total_cost), 0) AS total_cost
                FROM stock_movements
                WHERE DATE(movement_date) BETWEEN ? AND ?
                  AND deleted_at IS NULL
                GROUP BY movement_type
                ORDER BY count DESC
            ", [$fromDate, $toDate]);

            // معالجة البيانات
            $typeStats = [];
            foreach ($byType as $stat) {
                $typeStats[$stat['movement_type']] = [
                    'count'          => (int) $stat['count'],
                    'total_quantity' => (float) $stat['total_quantity'],
                    'total_cost'     => (float) $stat['total_cost'],
                    'label'          => self::MOVEMENT_TYPE_LABELS[$stat['movement_type']] ?? $stat['movement_type'],
                    'icon'           => self::MOVEMENT_TYPE_ICONS[$stat['movement_type']] ?? 'fa-circle',
                    'color'          => self::MOVEMENT_TYPE_COLORS[$stat['movement_type']] ?? '#95a5a6',
                ];
            }

            // 3. أكثر المنتجات حركة
            $topProducts = $this->db->select("
                SELECT
                    p.id,
                    p.code,
                    p.name,
                    COUNT(sm.id) AS movement_count,
                    COALESCE(SUM(sm.quantity), 0) AS total_quantity
                FROM stock_movements sm
                INNER JOIN products p ON sm.product_id = p.id
                WHERE DATE(sm.movement_date) BETWEEN ? AND ?
                  AND sm.deleted_at IS NULL
                GROUP BY p.id, p.code, p.name
                ORDER BY movement_count DESC
                LIMIT 10
            ", [$fromDate, $toDate]);

            // 4. أكثر المخازن نشاطاً
            $topWarehouses = $this->db->select("
                SELECT
                    w.id,
                    w.code,
                    w.name,
                    COUNT(sm.id) AS movement_count,
                    COALESCE(SUM(sm.quantity), 0) AS total_quantity
                FROM stock_movements sm
                INNER JOIN warehouses w ON sm.warehouse_id = w.id
                WHERE DATE(sm.movement_date) BETWEEN ? AND ?
                  AND sm.deleted_at IS NULL
                GROUP BY w.id, w.code, w.name
                ORDER BY movement_count DESC
                LIMIT 10
            ", [$fromDate, $toDate]);

            // 5. أكثر المستخدمين نشاطاً
            $topUsers = $this->db->select("
                SELECT
                    u.id,
                    u.username,
                    u.full_name,
                    COUNT(sm.id) AS movement_count,
                    COALESCE(SUM(sm.quantity), 0) AS total_quantity
                FROM stock_movements sm
                INNER JOIN users u ON sm.user_id = u.id
                WHERE DATE(sm.movement_date) BETWEEN ? AND ?
                  AND sm.deleted_at IS NULL
                GROUP BY u.id, u.username, u.full_name
                ORDER BY movement_count DESC
                LIMIT 10
            ", [$fromDate, $toDate]);

            // 6. الحركات اليومية (آخر 7 أيام)
            $dailyMovements = $this->db->select("
                SELECT
                    DATE(movement_date) AS date,
                    COUNT(*) AS count,
                    COALESCE(SUM(CASE WHEN movement_type IN ('RECEIPT', 'TRANSFER_IN', 'RETURN_IN', 'COUNT_CORRECTION') THEN quantity ELSE 0 END), 0) AS inbound,
                    COALESCE(SUM(CASE WHEN movement_type IN ('ISSUE', 'TRANSFER_OUT', 'RETURN_OUT') THEN quantity ELSE 0 END), 0) AS outbound
                FROM stock_movements
                WHERE movement_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                  AND deleted_at IS NULL
                GROUP BY DATE(movement_date)
                ORDER BY date ASC
            ");

            Response::success(
                message: 'تم جلب الإحصائيات بنجاح',
                data: [
                    'period' => [
                        'from' => $fromDate,
                        'to'   => $toDate,
                    ],
                    'total_movements'  => (int) ($totalMovements['count'] ?? 0),
                    'total_quantity'   => (float) ($totalMovements['total_quantity'] ?? 0),
                    'by_type'          => $typeStats,
                    'top_products'     => $topProducts,
                    'top_warehouses'   => $topWarehouses,
                    'top_users'        => $topUsers,
                    'daily_movements'  => $dailyMovements,
                ]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_MOVEMENT_CONTROLLER] Statistics failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الإحصائيات');
        }
    }

    // =========================================================================
    // 9. ملخص حسب نوع الحركة (Type Summary)
    // =========================================================================

    /**
     * جلب ملخص الحركات حسب النوع
     *
     * GET /api/stock/movements/type-summary
     *
     * @return void يرسل استجابة JSON
     */
    public function typeSummary(): void
    {
        try {
            $summaries = $this->db->select("
                SELECT
                    movement_type,
                    COUNT(*) AS total_count,
                    COALESCE(SUM(quantity), 0) AS total_quantity,
                    COALESCE(SUM(total_cost), 0) AS total_cost,
                    MIN(movement_date) AS first_movement,
                    MAX(movement_date) AS last_movement
                FROM stock_movements
                WHERE deleted_at IS NULL
                GROUP BY movement_type
                ORDER BY total_count DESC
            ");

            // معالجة البيانات
            foreach ($summaries as &$summary) {
                $summary['total_count'] = (int) $summary['total_count'];
                $summary['total_quantity'] = (float) $summary['total_quantity'];
                $summary['total_cost'] = (float) $summary['total_cost'];
                $summary['label'] = self::MOVEMENT_TYPE_LABELS[$summary['movement_type']] ?? $summary['movement_type'];
                $summary['icon'] = self::MOVEMENT_TYPE_ICONS[$summary['movement_type']] ?? 'fa-circle';
                $summary['color'] = self::MOVEMENT_TYPE_COLORS[$summary['movement_type']] ?? '#95a5a6';
                $summary['is_inbound'] = in_array($summary['movement_type'], self::INBOUND_TYPES, true);
                $summary['is_outbound'] = in_array($summary['movement_type'], self::OUTBOUND_TYPES, true);
            }

            Response::success(
                message: 'تم جلب ملخص الحركات حسب النوع بنجاح',
                data: [
                    'count'     => count($summaries),
                    'summaries' => $summaries,
                ]
            );

        } catch (Throwable $e) {
            error_log('[STOCK_MOVEMENT_CONTROLLER] TypeSummary failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب ملخص الحركات');
        }
    }

    // =========================================================================
    // 10. تصدير سجل الحركات (Export)
    // =========================================================================

    /**
     * تصدير سجل الحركات
     *
     * GET /api/stock/movements/export
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

            // بناء الفلاتر
            $filters = [
                'product_id'     => !empty($_GET['product_id']) ? (int) $_GET['product_id'] : null,
                'warehouse_id'   => !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null,
                'movement_type'  => $_GET['movement_type'] ?? null,
                'user_id'        => !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null,
                'reference_type' => $_GET['reference_type'] ?? null,
                'reference_id'   => !empty($_GET['reference_id']) ? (int) $_GET['reference_id'] : null,
                'from_date'      => $_GET['from_date'] ?? null,
                'to_date'        => $_GET['to_date'] ?? null,
                'search'         => trim($_GET['search'] ?? ''),
                'direction'      => $_GET['direction'] ?? null,
                'sort_by'        => $_GET['sort_by'] ?? 'movement_date',
                'sort_order'     => strtolower($_GET['sort_order'] ?? 'desc'),
            ];

            $this->validateFilters($filters);

            [$sql, $params] = $this->buildMovementsQuery($filters);
            $sql .= " LIMIT 10000"; // حد أقصى 10,000 سجل

            $movements = $this->db->select($sql, $params);

            // معالجة البيانات
            foreach ($movements as &$movement) {
                $this->enrichMovementData($movement);

                // إزالة الحقول غير الضرورية للتصدير
                unset($movement['movement_type_color'], $movement['movement_type_icon']);

                // ترجمة نوع الحركة
                $movement['movement_type'] = $movement['movement_type_label'] ?? $movement['movement_type'];
            }

            if (empty($movements)) {
                Response::error('لا توجد بيانات للتصدير', 'NO_DATA', 404);
            }

            $filename = 'stock_movements_' . date('Y-m-d_H-i-s');

            switch ($format) {
                case 'csv':
                    Response::csv($movements, $filename . '.csv', 200);
                    break;

                case 'excel':
                    $this->exportAsExcel($movements, $filename);
                    break;

                case 'pdf':
                    $this->exportAsHtml($movements, $filename);
                    break;
            }

        } catch (Throwable $e) {
            error_log('[STOCK_MOVEMENT_CONTROLLER] Export failed: ' . $e->getMessage());
            Response::internalError('فشل في تصدير سجل الحركات');
        }
    }

    /**
     * تصدير كـ Excel
     */
    private function exportAsExcel(array $movements, string $filename): void
    {
        $html = '<html dir="rtl"><head><meta charset="UTF-8"></head><body>';
        $html .= '<h2 style="text-align: center;">سجل الحركات المخزنية - Logistox</h2>';
        $html .= '<p style="text-align: center;">تاريخ التقرير: ' . date('Y-m-d H:i:s') . '</p>';

        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%; font-family: Arial;">';
        $html .= '<tr style="background: #4a90e2; color: white;">';
        $html .= '<th>#</th>';
        $html .= '<th>رقم الحركة</th>';
        $html .= '<th>التاريخ</th>';
        $html .= '<th>النوع</th>';
        $html .= '<th>المنتج</th>';
        $html .= '<th>المخزن</th>';
        $html .= '<th>الكمية</th>';
        $html .= '<th>الرصيد قبل</th>';
        $html .= '<th>الرصيد بعد</th>';
        $html .= '<th>المستخدم</th>';
        $html .= '<th>ملاحظات</th>';
        $html .= '</tr>';

        $index = 1;
        foreach ($movements as $movement) {
            $html .= '<tr>';
            $html .= '<td>' . $index++ . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['movement_number'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['movement_date'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['movement_type'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['product_name'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['warehouse_name'] ?? '') . '</td>';
            $html .= '<td>' . number_format((float) ($movement['quantity'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($movement['balance_before'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($movement['balance_after'] ?? 0), 3) . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['user_name'] ?? '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['notes'] ?? '-') . '</td>';
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
    private function exportAsHtml(array $movements, string $filename): void
    {
        $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>سجل الحركات</title>';
        $html .= '<style>
            body { font-family: Tahoma, Arial; direction: rtl; margin: 20px; }
            h2 { text-align: center; color: #2c3e50; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 11px; }
            th, td { padding: 8px; text-align: right; border: 1px solid #bdc3c7; }
            thead tr { background: #34495e; color: white; }
            tbody tr:nth-child(even) { background: #f9f9f9; }
            @media print { body { margin: 0; } }
        </style>';
        $html .= '</head><body>';

        $html .= '<h2>سجل الحركات المخزنية - Logistox</h2>';
        $html .= '<p style="text-align: center;">تاريخ التقرير: ' . date('Y-m-d H:i:s') . ' | عدد الحركات: ' . count($movements) . '</p>';

        $html .= '<table>';
        $html .= '<thead><tr>';
        $html .= '<th>#</th>';
        $html .= '<th>رقم الحركة</th>';
        $html .= '<th>التاريخ</th>';
        $html .= '<th>النوع</th>';
        $html .= '<th>المنتج</th>';
        $html .= '<th>المخزن</th>';
        $html .= '<th>الكمية</th>';
        $html .= '<th>الرصيد قبل</th>';
        $html .= '<th>الرصيد بعد</th>';
        $html .= '<th>المستخدم</th>';
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        $index = 1;
        foreach ($movements as $movement) {
            $html .= '<tr>';
            $html .= '<td>' . $index++ . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['movement_number'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['movement_date'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['movement_type'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['product_name'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['warehouse_name'] ?? '') . '</td>';
            $html .= '<td>' . number_format((float) ($movement['quantity'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($movement['balance_before'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($movement['balance_after'] ?? 0), 3) . '</td>';
            $html .= '<td>' . htmlspecialchars($movement['user_name'] ?? '-') . '</td>';
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
     * بناء استعلام الحركات مع الفلاتر
     *
     * @param array $filters الفلاتر
     * @return array [sql, params]
     */
    private function buildMovementsQuery(array $filters): array
    {
        $sql = "
            SELECT
                sm.id,
                sm.movement_number,
                sm.movement_type,
                sm.quantity,
                sm.unit_cost,
                sm.total_cost,
                sm.balance_before,
                sm.balance_after,
                sm.reference_type,
                sm.reference_id,
                sm.batch_number,
                sm.expiry_date,
                sm.notes,
                sm.movement_date,
                sm.product_id,
                sm.warehouse_id,
                sm.from_warehouse_id,
                sm.to_warehouse_id,
                sm.user_id,
                p.code AS product_code,
                p.barcode,
                p.name AS product_name,
                c.name AS category_name,
                u.symbol AS unit_symbol,
                w.code AS warehouse_code,
                w.name AS warehouse_name,
                w_from.name AS from_warehouse_name,
                w_to.name AS to_warehouse_name,
                usr.username,
                usr.full_name AS user_name
            FROM stock_movements sm
            INNER JOIN products p ON sm.product_id = p.id
            INNER JOIN warehouses w ON sm.warehouse_id = w.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN units u ON p.unit_id = u.id
            LEFT JOIN warehouses w_from ON sm.from_warehouse_id = w_from.id
            LEFT JOIN warehouses w_to ON sm.to_warehouse_id = w_to.id
            LEFT JOIN users usr ON sm.user_id = usr.id
            WHERE sm.deleted_at IS NULL
              AND p.deleted_at IS NULL
              AND w.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['product_id'])) {
            $sql .= " AND sm.product_id = ?";
            $params[] = $filters['product_id'];
        }

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND sm.warehouse_id = ?";
            $params[] = $filters['warehouse_id'];
        }

        if (!empty($filters['movement_type']) && in_array($filters['movement_type'], self::ALLOWED_MOVEMENT_TYPES, true)) {
            $sql .= " AND sm.movement_type = ?";
            $params[] = $filters['movement_type'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND sm.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['reference_type'])) {
            $sql .= " AND sm.reference_type = ?";
            $params[] = $filters['reference_type'];
        }

        if (!empty($filters['reference_id'])) {
            $sql .= " AND sm.reference_id = ?";
            $params[] = $filters['reference_id'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND sm.movement_date >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND sm.movement_date <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (sm.movement_number LIKE ? OR sm.notes LIKE ? OR p.name LIKE ? OR p.code LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        // فلتر الاتجاه (inbound/outbound)
        if (!empty($filters['direction'])) {
            if ($filters['direction'] === 'inbound') {
                $placeholders = implode(',', array_fill(0, count(self::INBOUND_TYPES), '?'));
                $sql .= " AND sm.movement_type IN ({$placeholders})";
                $params = array_merge($params, self::INBOUND_TYPES);
            } elseif ($filters['direction'] === 'outbound') {
                $placeholders = implode(',', array_fill(0, count(self::OUTBOUND_TYPES), '?'));
                $sql .= " AND sm.movement_type IN ({$placeholders})";
                $params = array_merge($params, self::OUTBOUND_TYPES);
            }
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'movement_date';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        $allowedSortBy = ['movement_date', 'product_name', 'quantity', 'movement_type', 'warehouse_name', 'movement_number'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'movement_date';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $sql .= " ORDER BY sm.{$sortBy} {$sortOrder}, sm.id DESC";

        return [$sql, $params];
    }

    /**
     * إثراء بيانات الحركة بمعلومات إضافية
     */
    private function enrichMovementData(array &$movement): void
    {
        $movement['quantity'] = (float) ($movement['quantity'] ?? 0);
        $movement['unit_cost'] = $movement['unit_cost'] !== null ? (float) $movement['unit_cost'] : null;
        $movement['total_cost'] = $movement['total_cost'] !== null ? (float) $movement['total_cost'] : null;
        $movement['balance_before'] = (float) ($movement['balance_before'] ?? 0);
        $movement['balance_after'] = (float) ($movement['balance_after'] ?? 0);

        $movement['movement_type_label'] = self::MOVEMENT_TYPE_LABELS[$movement['movement_type']] ?? $movement['movement_type'];
        $movement['movement_type_icon'] = self::MOVEMENT_TYPE_ICONS[$movement['movement_type']] ?? 'fa-circle';
        $movement['movement_type_color'] = self::MOVEMENT_TYPE_COLORS[$movement['movement_type']] ?? '#95a5a6';

        $movement['is_inbound'] = in_array($movement['movement_type'], self::INBOUND_TYPES, true);
        $movement['is_outbound'] = in_array($movement['movement_type'], self::OUTBOUND_TYPES, true);
    }

    /**
     * جلب المستند المرجعي
     *
     * @param string $referenceType نوع المستند (receipt, issue, transfer, return, inventory_count)
     * @param int $referenceId معرف المستند
     * @return array|null بيانات المستند
     */
    private function getReferenceDocument(string $referenceType, int $referenceId): ?array
    {
        $table = match ($referenceType) {
            'receipt'         => 'receipts',
            'issue'           => 'issues',
            'transfer'        => 'transfers',
            'return'          => 'returns',
            'inventory_count' => 'inventory_counts',
            default           => null,
        };

        if ($table === null) {
            return null;
        }

        $numberField = match ($referenceType) {
            'receipt'         => 'receipt_number',
            'issue'           => 'issue_number',
            'transfer'        => 'transfer_number',
            'return'          => 'return_number',
            'inventory_count' => 'count_number',
            default           => 'id',
        };

        return $this->db->selectOne(
            "SELECT id, {$numberField} AS number, status FROM {$table} WHERE id = ? AND deleted_at IS NULL",
            [$referenceId]
        );
    }

    // =========================================================================
    // Helper Methods - التحقق
    // =========================================================================

    /**
     * التحقق من صحة الفلاتر
     */
    private function validateFilters(array $filters): void
    {
        // التحقق من movement_type
        if (!empty($filters['movement_type']) && !in_array($filters['movement_type'], self::ALLOWED_MOVEMENT_TYPES, true)) {
            unset($filters['movement_type']);
        }

        // التحقق من direction
        if (!empty($filters['direction']) && !in_array($filters['direction'], ['inbound', 'outbound'], true)) {
            unset($filters['direction']);
        }

        // التحقق من التواريخ
        if (!empty($filters['from_date'])) {
            $dateTime = \DateTime::createFromFormat('Y-m-d', $filters['from_date']);
            if (!$dateTime || $dateTime->format('Y-m-d') !== $filters['from_date']) {
                Response::badRequest('تاريخ البداية غير صالح. الصيغة: YYYY-MM-DD');
            }
        }

        if (!empty($filters['to_date'])) {
            $dateTime = \DateTime::createFromFormat('Y-m-d', $filters['to_date']);
            if (!$dateTime || $dateTime->format('Y-m-d') !== $filters['to_date']) {
                Response::badRequest('تاريخ النهاية غير صالح. الصيغة: YYYY-MM-DD');
            }
        }
    }

    /**
     * التحقق من صحة معرف الحركة
     */
    private function validateMovementId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف الحركة غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
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
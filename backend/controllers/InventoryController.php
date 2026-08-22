<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/InventoryController.php
// الوصف: متحكم إدارة الجرد - جلسات الجرد، إضافة عناصر، اعتماد
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Services\StockService;
use Exception;

class InventoryController
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
    
    /**
     * @var StockService $stockService - محرك المخزون
     */
    private $stockService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        $this->audit = new Audit();
        $this->stockService = new StockService();
    }

    /**
     * GET /api/inventory/counts
     * جلب قائمة جلسات الجرد
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'inventory.view')) {
                errorResponse('ليس لديك صلاحية لعرض الجرد', 403);
                return;
            }

            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            $warehouse = $_GET['warehouse'] ?? '';
            $type = $_GET['type'] ?? '';
            $fromDate = $_GET['from_date'] ?? '';
            $toDate = $_GET['to_date'] ?? '';
            $sort = $_GET['sort'] ?? 'created_at';
            $order = $_GET['order'] ?? 'DESC';

            $params = [];
            $where = [];
            
            if (!empty($search)) {
                $where[] = "c.count_no LIKE :search";
                $params['search'] = "%{$search}%";
            }
            
            if (!empty($status)) {
                $where[] = "c.status = :status";
                $params['status'] = $status;
            }
            
            if (!empty($warehouse)) {
                $where[] = "c.warehouse_id = :warehouse";
                $params['warehouse'] = $warehouse;
            }
            
            if (!empty($type)) {
                $where[] = "c.count_type = :type";
                $params['type'] = $type;
            }
            
            if (!empty($fromDate)) {
                $where[] = "c.count_date >= :from_date";
                $params['from_date'] = $fromDate;
            }
            
            if (!empty($toDate)) {
                $where[] = "c.count_date <= :to_date";
                $params['to_date'] = $toDate;
            }

            $allowedSorts = ['id', 'count_no', 'count_date', 'total_items', 'status', 'created_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'created_at';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            $counts = $this->db->query("
                SELECT 
                    c.id,
                    c.count_no,
                    c.warehouse_id,
                    w.name as warehouse_name,
                    c.count_date,
                    c.count_time,
                    c.count_type,
                    c.total_items,
                    c.total_differences,
                    c.status,
                    c.user_id,
                    u.full_name as user_name,
                    c.supervisor_id,
                    s.full_name as supervisor_name,
                    c.approved_by,
                    a.full_name as approved_by_name,
                    c.approved_at,
                    c.notes,
                    c.start_time,
                    c.end_time,
                    c.created_at,
                    c.updated_at,
                    CASE 
                        WHEN c.status = 'draft' THEN 'مسودة'
                        WHEN c.status = 'in_progress' THEN 'قيد التنفيذ'
                        WHEN c.status = 'reviewed' THEN 'مراجعة'
                        WHEN c.status = 'approved' THEN 'معتمد'
                        WHEN c.status = 'cancelled' THEN 'ملغي'
                        ELSE c.status
                    END as status_label,
                    CASE 
                        WHEN c.status = 'approved' THEN 'success'
                        WHEN c.status = 'cancelled' THEN 'secondary'
                        WHEN c.status = 'in_progress' THEN 'warning'
                        WHEN c.status = 'reviewed' THEN 'info'
                        ELSE 'secondary'
                    END as status_color
                FROM inventory_counts c
                LEFT JOIN warehouses w ON w.id = c.warehouse_id
                LEFT JOIN users u ON u.id = c.user_id
                LEFT JOIN users s ON s.id = c.supervisor_id
                LEFT JOIN users a ON a.id = c.approved_by
                WHERE 1=1
                " . (!empty($where) ? 'AND ' . implode(' AND ', $where) : '') . "
                ORDER BY c.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM inventory_counts c
                WHERE 1=1
                " . (!empty($where) ? 'AND ' . implode(' AND ', $where) : '') . "
            ", $params);

            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft,
                    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
                    COUNT(CASE WHEN status = 'reviewed' THEN 1 END) as reviewed,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
                    COUNT(CASE WHEN count_type = 'full' THEN 1 END) as full,
                    COUNT(CASE WHEN count_type = 'partial' THEN 1 END) as partial,
                    COUNT(CASE WHEN count_type = 'cycle' THEN 1 END) as cycle,
                    COUNT(CASE WHEN count_type = 'spot' THEN 1 END) as spot,
                    COALESCE(SUM(total_differences), 0) as total_differences
                FROM inventory_counts
            ");

            successResponse('تم جلب قائمة جلسات الجرد', [
                'data' => $counts,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'draft' => (int)($stats['draft'] ?? 0),
                    'in_progress' => (int)($stats['in_progress'] ?? 0),
                    'reviewed' => (int)($stats['reviewed'] ?? 0),
                    'approved' => (int)($stats['approved'] ?? 0),
                    'cancelled' => (int)($stats['cancelled'] ?? 0),
                    'full' => (int)($stats['full'] ?? 0),
                    'partial' => (int)($stats['partial'] ?? 0),
                    'cycle' => (int)($stats['cycle'] ?? 0),
                    'spot' => (int)($stats['spot'] ?? 0),
                    'total_differences' => (int)($stats['total_differences'] ?? 0)
                ],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil((int)$total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            error_log('Inventory list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/inventory/counts/{id}
     * جلب بيانات جلسة جرد مع تفاصيل كاملة
     */
    public function show(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'inventory.view')) {
                errorResponse('ليس لديك صلاحية لعرض الجرد', 403);
                return;
            }

            $count = $this->getInventoryCountById($id);
            
            if (!$count) {
                errorResponse('جلسة الجرد غير موجودة');
                return;
            }

            // جلب تفاصيل الجرد
            $items = $this->db->query("
                SELECT 
                    ci.*,
                    p.code as product_code,
                    p.name as product_name,
                    p.barcode,
                    u.name as unit_name,
                    cu.full_name as counted_by_name,
                    vu.full_name as verified_by_name
                FROM inventory_count_items ci
                INNER JOIN products p ON p.id = ci.product_id
                LEFT JOIN units u ON u.id = p.unit_id
                LEFT JOIN users cu ON cu.id = ci.counted_by
                LEFT JOIN users vu ON vu.id = ci.verified_by
                WHERE ci.inventory_count_id = :count_id
                ORDER BY p.name
            ", ['count_id' => $id]);

            // جلب سجل التدقيق
            $audits = $this->db->query("
                SELECT 
                    created_at,
                    user_id,
                    (SELECT full_name FROM users WHERE id = user_id) as user_name,
                    action,
                    description
                FROM audit_logs
                WHERE reference_type = 'inventory_count'
                  AND reference_id = :reference_id
                ORDER BY created_at DESC
            ", ['reference_id' => $id]);

            successResponse('تم جلب بيانات جلسة الجرد', [
                'count' => $count,
                'items' => $items,
                'audits' => $audits,
                'summary' => [
                    'total_items' => count($items),
                    'total_differences' => array_sum(array_column($items, 'difference')),
                    'positive_differences' => array_sum(array_filter(array_column($items, 'difference'), function($d) { return $d > 0; })),
                    'negative_differences' => array_sum(array_filter(array_column($items, 'difference'), function($d) { return $d < 0; }))
                ]
            ]);

        } catch (Exception $e) {
            error_log('Inventory show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/inventory/counts
     * بدء جلسة جرد جديدة
     */
    public function create(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'inventory.create')) {
                errorResponse('ليس لديك صلاحية لبدء جلسة جرد', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            if (empty($input['warehouse_id'])) {
                errorResponse('المخزن مطلوب');
                return;
            }

            // التحقق من وجود المخزن
            $warehouse = $this->db->queryValue(
                "SELECT id FROM warehouses WHERE id = :id AND is_active = 1 AND deleted_at IS NULL",
                ['id' => $input['warehouse_id']]
            );
            
            if (!$warehouse) {
                errorResponse('المخزن غير موجود أو غير نشط');
                return;
            }

            // توليد رقم الجرد
            $countNo = $this->generateCountNumber();

            // بدء المعاملة
            $this->db->beginTransaction();

            $data = [
                'count_no' => $countNo,
                'warehouse_id' => $input['warehouse_id'],
                'count_date' => $input['count_date'] ?? date('Y-m-d'),
                'count_time' => $input['count_time'] ?? date('H:i:s'),
                'count_type' => $input['count_type'] ?? 'full',
                'status' => 'in_progress',
                'user_id' => $userId,
                'supervisor_id' => $input['supervisor_id'] ?? null,
                'notes' => $input['notes'] ?? null,
                'start_time' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ];

            $countId = $this->db->insert('inventory_counts', $data);

            // إذا كان جرد كامل، إضافة جميع الأصناف
            if ($input['count_type'] ?? 'full' === 'full') {
                $products = $this->db->query("
                    SELECT 
                        p.id,
                        COALESCE(sb.quantity, 0) as quantity,
                        p.cost_price
                    FROM products p
                    LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = :warehouse_id
                    WHERE p.is_active = 1 AND p.deleted_at IS NULL
                ", ['warehouse_id' => $input['warehouse_id']]);

                foreach ($products as $product) {
                    $this->db->insert('inventory_count_items', [
                        'inventory_count_id' => $countId,
                        'product_id' => $product['id'],
                        'system_quantity' => $product['quantity'],
                        'actual_quantity' => $product['quantity'],
                        'unit_cost' => $product['cost_price'],
                        'counted_by' => $userId,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }

                // تحديث عدد الأصناف
                $this->db->update('inventory_counts', [
                    'total_items' => count($products)
                ], ['id' => $countId]);
            }

            $this->db->commit();

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'INVENTORY_STARTED',
                'inventory',
                "بدء جلسة جرد #{$countNo}",
                [
                    'count_id' => $countId,
                    'count_no' => $countNo,
                    'warehouse_id' => $input['warehouse_id']
                ],
                'inventory_count',
                $countId
            );

            successResponse('تم بدء جلسة الجرد بنجاح', [
                'count_id' => $countId,
                'count_no' => $countNo
            ]);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Inventory create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/inventory/counts/{id}/items
     * إضافة عنصر جرد
     */
    public function addItem(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'inventory.create')) {
                errorResponse('ليس لديك صلاحية لإضافة عناصر الجرد', 403);
                return;
            }

            $count = $this->getInventoryCountById($id);
            if (!$count) {
                errorResponse('جلسة الجرد غير موجودة');
                return;
            }

            if ($count['status'] !== 'in_progress') {
                errorResponse('لا يمكن إضافة عناصر لجلسة جرد غير قيد التنفيذ');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            if (empty($input['product_id'])) {
                errorResponse('المنتج مطلوب');
                return;
            }

            // التحقق من وجود المنتج
            $product = $this->db->queryOne("
                SELECT id, cost_price FROM products WHERE id = :id AND is_active = 1 AND deleted_at IS NULL
            ", ['id' => $input['product_id']]);

            if (!$product) {
                errorResponse('المنتج غير موجود');
                return;
            }

            // جلب الرصيد الحالي
            $systemQuantity = $this->db->queryValue("
                SELECT COALESCE(quantity, 0) 
                FROM stock_balances 
                WHERE product_id = :product_id AND warehouse_id = :warehouse_id
            ", [
                'product_id' => $input['product_id'],
                'warehouse_id' => $count['warehouse_id']
            ]);

            // التحقق من عدم وجود العنصر مسبقاً
            $exists = $this->db->queryValue("
                SELECT id FROM inventory_count_items 
                WHERE inventory_count_id = :count_id AND product_id = :product_id
            ", [
                'count_id' => $id,
                'product_id' => $input['product_id']
            ]);

            if ($exists) {
                errorResponse('العنصر موجود بالفعل في جلسة الجرد');
                return;
            }

            $itemId = $this->db->insert('inventory_count_items', [
                'inventory_count_id' => $id,
                'product_id' => $input['product_id'],
                'system_quantity' => $systemQuantity,
                'actual_quantity' => $input['actual_quantity'] ?? $systemQuantity,
                'unit_cost' => $product['cost_price'],
                'location' => $input['location'] ?? null,
                'batch_number' => $input['batch_number'] ?? null,
                'counted_by' => $userId,
                'notes' => $input['notes'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // تحديث عدد الأصناف
            $this->db->execute("
                UPDATE inventory_counts 
                SET total_items = total_items + 1
                WHERE id = :id
            ", ['id' => $id]);

            $this->audit->log(
                $userId,
                'INVENTORY_ITEM_ADDED',
                'inventory',
                "إضافة عنصر جرد للمنتج",
                [
                    'count_id' => $id,
                    'product_id' => $input['product_id'],
                    'item_id' => $itemId
                ],
                'inventory_count',
                $id
            );

            successResponse('تم إضافة عنصر الجرد بنجاح', ['item_id' => $itemId]);

        } catch (Exception $e) {
            error_log('Inventory add item error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/inventory/counts/{id}/items/{itemId}
     * تحديث عنصر جرد
     */
    public function updateItem(int $id, int $itemId): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'inventory.create')) {
                errorResponse('ليس لديك صلاحية لتحديث عناصر الجرد', 403);
                return;
            }

            $count = $this->getInventoryCountById($id);
            if (!$count) {
                errorResponse('جلسة الجرد غير موجودة');
                return;
            }

            if ($count['status'] !== 'in_progress') {
                errorResponse('لا يمكن تحديث عناصر جلسة جرد غير قيد التنفيذ');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            if (!isset($input['actual_quantity'])) {
                errorResponse('الكمية الفعلية مطلوبة');
                return;
            }

            $this->db->update('inventory_count_items', [
                'actual_quantity' => $input['actual_quantity'],
                'verified_by' => $input['verified_by'] ?? null,
                'notes' => $input['notes'] ?? null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $itemId, 'inventory_count_id' => $id]);

            $this->audit->log(
                $userId,
                'INVENTORY_ITEM_UPDATED',
                'inventory',
                "تحديث عنصر جرد",
                [
                    'count_id' => $id,
                    'item_id' => $itemId,
                    'actual_quantity' => $input['actual_quantity']
                ],
                'inventory_count',
                $id
            );

            successResponse('تم تحديث عنصر الجرد بنجاح');

        } catch (Exception $e) {
            error_log('Inventory update item error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/inventory/counts/{id}/approve
     * اعتماد جلسة جرد
     */
    public function approve(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'inventory.approve')) {
                errorResponse('ليس لديك صلاحية لاعتماد جلسات الجرد', 403);
                return;
            }

            $count = $this->getInventoryCountById($id);
            if (!$count) {
                errorResponse('جلسة الجرد غير موجودة');
                return;
            }

            if ($count['status'] !== 'in_progress' && $count['status'] !== 'reviewed') {
                errorResponse('لا يمكن اعتماد جلسة جرد غير قيد التنفيذ أو مراجعة');
                return;
            }

            // جلب جميع عناصر الجرد
            $items = $this->db->query("
                SELECT * FROM inventory_count_items WHERE inventory_count_id = :count_id
            ", ['count_id' => $id]);

            if (empty($items)) {
                errorResponse('لا توجد عناصر في جلسة الجرد');
                return;

            }

            // بدء المعاملة
            $this->db->beginTransaction();

            $totalDifferences = 0;

            foreach ($items as $item) {
                $difference = $item['actual_quantity'] - $item['system_quantity'];
                
                if ($difference != 0) {
                    $totalDifferences++;
                    
                    // تحديث الرصيد
                    $this->stockService->updateStockBalance(
                        $item['product_id'],
                        $count['warehouse_id'],
                        $item['actual_quantity']
                    );

                    // تسجيل حركة التصحيح
                    $this->stockService->insertStockMovement([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $count['warehouse_id'],
                        'movement_type' => 'COUNT_CORRECTION',
                        'reference_type' => 'inventory_count',
                        'reference_id' => $id,
                        'quantity' => abs($difference),
                        'unit_cost' => $item['unit_cost'],
                        'total_cost' => abs($difference) * $item['unit_cost'],
                        'balance_before' => $item['system_quantity'],
                        'balance_after' => $item['actual_quantity'],
                        'user_id' => $userId,
                        'notes' => "تصحيح جرد: " . ($difference > 0 ? 'زيادة' : 'نقص') . " {$difference}"
                    ]);
                }
            }

            // تحديث حالة الجرد
            $this->db->update('inventory_counts', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'end_time' => date('Y-m-d H:i:s'),
                'total_differences' => $totalDifferences,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            $this->db->commit();

            $this->audit->log(
                $userId,
                'INVENTORY_APPROVED',
                'inventory',
                "اعتماد جلسة جرد #{$count['count_no']}",
                [
                    'count_id' => $id,
                    'count_no' => $count['count_no'],
                    'total_differences' => $totalDifferences
                ],
                'inventory_count',
                $id
            );

            successResponse('تم اعتماد جلسة الجرد بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Inventory approve error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/inventory/counts/{id}/cancel
     * إلغاء جلسة جرد
     */
    public function cancel(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'inventory.cancel')) {
                errorResponse('ليس لديك صلاحية لإلغاء جلسات الجرد', 403);
                return;
            }

            $count = $this->getInventoryCountById($id);
            if (!$count) {
                errorResponse('جلسة الجرد غير موجودة');
                return;
            }

            if ($count['status'] === 'approved') {
                errorResponse('لا يمكن إلغاء جلسة جرد معتمدة');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            $this->db->update('inventory_counts', [
                'status' => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            $this->audit->log(
                $userId,
                'INVENTORY_CANCELLED',
                'inventory',
                "إلغاء جلسة جرد #{$count['count_no']}",
                [
                    'count_id' => $id,
                    'count_no' => $count['count_no'],
                    'reason' => $input['reason'] ?? null
                ],
                'inventory_count',
                $id
            );

            successResponse('تم إلغاء جلسة الجرد بنجاح');

        } catch (Exception $e) {
            error_log('Inventory cancel error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/inventory/export
     * تصدير جلسات الجرد
     */
    public function export(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'inventory.export')) {
                errorResponse('ليس لديك صلاحية لتصدير الجرد', 403);
                return;
            }

            $format = $_GET['format'] ?? 'csv';
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            $counts = $this->db->query("
                SELECT 
                    c.count_no,
                    c.count_date,
                    w.name as warehouse,
                    c.count_type,
                    c.total_items,
                    c.total_differences,
                    c.status,
                    u.full_name as created_by,
                    c.created_at,
                    c.approved_at
                FROM inventory_counts c
                LEFT JOIN warehouses w ON w.id = c.warehouse_id
                LEFT JOIN users u ON u.id = c.user_id
                WHERE c.count_date BETWEEN :from_date AND :to_date
                ORDER BY c.count_date DESC
            ", [
                'from_date' => $fromDate,
                'to_date' => $toDate
            ]);

            if ($format === 'csv') {
                $this->exportCSV($counts);
            } else {
                successResponse('تم جلب بيانات التصدير', $counts);
            }

        } catch (Exception $e) {
            error_log('Inventory export error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    private function getInventoryCountById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                c.*,
                w.name as warehouse_name,
                u.full_name as user_name,
                s.full_name as supervisor_name,
                a.full_name as approved_by_name
            FROM inventory_counts c
            LEFT JOIN warehouses w ON w.id = c.warehouse_id
            LEFT JOIN users u ON u.id = c.user_id
            LEFT JOIN users s ON s.id = c.supervisor_id
            LEFT JOIN users a ON a.id = c.approved_by
            WHERE c.id = :id
        ", ['id' => $id]);
    }

    private function generateCountNumber(): string
    {
        $prefix = 'INV';
        $year = date('Y');
        $month = date('m');
        
        $last = $this->db->queryValue("
            SELECT MAX(CAST(SUBSTRING(count_no, -4) AS UNSIGNED)) 
            FROM inventory_counts 
            WHERE count_no LIKE :pattern
        ", ['pattern' => "{$prefix}{$year}{$month}%"]);

        $number = str_pad((int)$last + 1, 4, '0', STR_PAD_LEFT);
        return "{$prefix}{$year}{$month}{$number}";
    }

    private function exportCSV(array $data): void
    {
        if (empty($data)) {
            errorResponse('لا توجد بيانات للتصدير');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inventory_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['رقم الجرد', 'التاريخ', 'المخزن', 'النوع', 'عدد الأصناف', 'الفروقات', 'الحالة', 'تم الإنشاء بواسطة', 'تاريخ الإنشاء', 'تاريخ الاعتماد']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['count_no'],
                $row['count_date'],
                $row['warehouse'],
                $row['count_type'],
                $row['total_items'],
                $row['total_differences'],
                $row['status'],
                $row['created_by'],
                $row['created_at'],
                $row['approved_at'] ?? ''
            ]);
        }
        
        fclose($output);
        exit;
    }
}

// ================================================================
// انتهى الملف
// ================================================================

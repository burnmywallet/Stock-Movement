<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/TransferController.php
// الوصف: متحكم إدارة التحويلات بين المخازن - إنشاء، اعتماد، إكمال، رفض، طباعة
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Services\StockService;
use Exception;

class TransferController
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
     * GET /api/transfers
     * جلب قائمة التحويلات مع فلترة وبحث
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'transfers.view')) {
                errorResponse('ليس لديك صلاحية لعرض التحويلات', 403);
                return;
            }

            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            $fromWarehouse = $_GET['from_warehouse'] ?? '';
            $toWarehouse = $_GET['to_warehouse'] ?? '';
            $fromDate = $_GET['from_date'] ?? '';
            $toDate = $_GET['to_date'] ?? '';
            $sort = $_GET['sort'] ?? 'created_at';
            $order = $_GET['order'] ?? 'DESC';

            $params = [];
            $where = [];
            
            if (!empty($search)) {
                $where[] = "t.transfer_no LIKE :search";
                $params['search'] = "%{$search}%";
            }
            
            if (!empty($status)) {
                $where[] = "t.status = :status";
                $params['status'] = $status;
            }
            
            if (!empty($fromWarehouse)) {
                $where[] = "t.from_warehouse_id = :from_warehouse";
                $params['from_warehouse'] = $fromWarehouse;
            }
            
            if (!empty($toWarehouse)) {
                $where[] = "t.to_warehouse_id = :to_warehouse";
                $params['to_warehouse'] = $toWarehouse;
            }
            
            if (!empty($fromDate)) {
                $where[] = "t.transfer_date >= :from_date";
                $params['from_date'] = $fromDate;
            }
            
            if (!empty($toDate)) {
                $where[] = "t.transfer_date <= :to_date";
                $params['to_date'] = $toDate;
            }

            $allowedSorts = ['id', 'transfer_no', 'transfer_date', 'total_quantity', 'total_cost', 'status', 'created_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'created_at';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب التحويلات
            $transfers = $this->db->query("
                SELECT 
                    t.id,
                    t.transfer_no,
                    t.from_warehouse_id,
                    fw.name as from_warehouse_name,
                    t.to_warehouse_id,
                    tw.name as to_warehouse_name,
                    t.transfer_date,
                    t.transfer_time,
                    t.expected_date,
                    t.delivered_date,
                    t.total_items,
                    t.total_quantity,
                    t.total_cost,
                    t.status,
                    t.user_id,
                    u.full_name as user_name,
                    t.approved_by,
                    a.full_name as approved_by_name,
                    t.approved_at,
                    t.created_at,
                    t.updated_at,
                    t.notes,
                    (SELECT COUNT(*) FROM transfer_items WHERE transfer_id = t.id) as items_count,
                    CASE 
                        WHEN t.status = 'draft' THEN 'مسودة'
                        WHEN t.status = 'submitted' THEN 'مرسل'
                        WHEN t.status = 'approved' THEN 'معتمد'
                        WHEN t.status = 'rejected' THEN 'مرفوض'
                        WHEN t.status = 'cancelled' THEN 'ملغي'
                        WHEN t.status = 'completed' THEN 'مكتمل'
                        ELSE t.status
                    END as status_label,
                    CASE 
                        WHEN t.status = 'completed' THEN 'success'
                        WHEN t.status = 'approved' THEN 'primary'
                        WHEN t.status = 'rejected' THEN 'danger'
                        WHEN t.status = 'cancelled' THEN 'secondary'
                        WHEN t.status = 'draft' THEN 'warning'
                        WHEN t.status = 'submitted' THEN 'info'
                        ELSE 'secondary'
                    END as status_color
                FROM transfers t
                LEFT JOIN warehouses fw ON fw.id = t.from_warehouse_id
                LEFT JOIN warehouses tw ON tw.id = t.to_warehouse_id
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN users a ON a.id = t.approved_by
                WHERE 1=1
                " . (!empty($where) ? 'AND ' . implode(' AND ', $where) : '') . "
                ORDER BY t.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            // إجمالي التحويلات
            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM transfers t
                WHERE 1=1
                " . (!empty($where) ? 'AND ' . implode(' AND ', $where) : '') . "
            ", $params);

            // إحصائيات إضافية
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft,
                    COUNT(CASE WHEN status = 'submitted' THEN 1 END) as submitted,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                    COALESCE(SUM(total_cost), 0) as total_value,
                    COALESCE(SUM(total_quantity), 0) as total_quantity
                FROM transfers
            ");

            successResponse('تم جلب قائمة التحويلات', [
                'data' => $transfers,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'draft' => (int)($stats['draft'] ?? 0),
                    'submitted' => (int)($stats['submitted'] ?? 0),
                    'approved' => (int)($stats['approved'] ?? 0),
                    'rejected' => (int)($stats['rejected'] ?? 0),
                    'cancelled' => (int)($stats['cancelled'] ?? 0),
                    'completed' => (int)($stats['completed'] ?? 0),
                    'total_value' => (float)($stats['total_value'] ?? 0),
                    'total_quantity' => (float)($stats['total_quantity'] ?? 0)
                ],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil((int)$total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            error_log('Transfers list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/transfers/{id}
     * جلب بيانات تحويل مع تفاصيل كاملة
     */
    public function show(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'transfers.view')) {
                errorResponse('ليس لديك صلاحية لعرض التحويلات', 403);
                return;
            }

            $transfer = $this->getTransferById($id);
            
            if (!$transfer) {
                errorResponse('التحويل غير موجود');
                return;
            }

            // جلب تفاصيل التحويل
            $items = $this->db->query("
                SELECT 
                    ti.*,
                    p.code as product_code,
                    p.name as product_name,
                    p.barcode,
                    u.name as unit_name,
                    u.symbol as unit_symbol,
                    COALESCE(sb_from.quantity, 0) as from_balance,
                    COALESCE(sb_to.quantity, 0) as to_balance
                FROM transfer_items ti
                INNER JOIN products p ON p.id = ti.product_id
                LEFT JOIN units u ON u.id = p.unit_id
                LEFT JOIN stock_balances sb_from ON sb_from.product_id = p.id AND sb_from.warehouse_id = (SELECT from_warehouse_id FROM transfers WHERE id = :transfer_id)
                LEFT JOIN stock_balances sb_to ON sb_to.product_id = p.id AND sb_to.warehouse_id = (SELECT to_warehouse_id FROM transfers WHERE id = :transfer_id)
                WHERE ti.transfer_id = :transfer_id
            ", ['transfer_id' => $id]);

            // جلب سجل الموافقات والتدقيق
            $audits = $this->db->query("
                SELECT 
                    al.created_at,
                    al.user_id,
                    u.full_name as user_name,
                    al.action,
                    al.description,
                    al.details
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE al.reference_type = 'transfer'
                  AND al.reference_id = :reference_id
                ORDER BY al.created_at DESC
            ", ['reference_id' => $id]);

            // جلب سجل الحالة
            $history = $this->db->query("
                SELECT 
                    status,
                    notes,
                    created_at,
                    (SELECT full_name FROM users WHERE id = user_id) as user_name
                FROM transfer_status_history
                WHERE transfer_id = :transfer_id
                ORDER BY created_at ASC
            ", ['transfer_id' => $id]);

            successResponse('تم جلب بيانات التحويل', [
                'transfer' => $transfer,
                'items' => $items,
                'audits' => $audits,
                'history' => $history,
                'summary' => [
                    'total_items' => count($items),
                    'total_quantity' => array_sum(array_column($items, 'quantity')),
                    'total_cost' => array_sum(array_column($items, 'total_cost'))
                ]
            ]);

        } catch (Exception $e) {
            error_log('Transfer show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/transfers
     * إنشاء تحويل جديد
     */
    public function create(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'transfers.create')) {
                errorResponse('ليس لديك صلاحية لإنشاء تحويلات', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateTransferData($input);

            // التحقق من أن المخازن مختلفة
            if ($input['from_warehouse_id'] == $input['to_warehouse_id']) {
                errorResponse('لا يمكن التحويل بين نفس المخزن');
                return;
            }

            // التحقق من توفر الكميات في المخزن المصدر
            foreach ($input['items'] as $item) {
                $balance = $this->db->queryValue("
                    SELECT COALESCE(quantity, 0) 
                    FROM stock_balances 
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $input['from_warehouse_id']
                ]);

                if ($balance < $item['quantity']) {
                    errorResponse("الكمية غير متوفرة في المخزن المصدر للصنف (المتاح: {$balance})");
                    return;
                }
            }

            // توليد رقم التحويل
            $transferNo = $this->generateTransferNumber();

            // بدء المعاملة
            $this->db->beginTransaction();

            // إنشاء التحويل
            $data = [
                'transfer_no' => $transferNo,
                'from_warehouse_id' => $input['from_warehouse_id'],
                'to_warehouse_id' => $input['to_warehouse_id'],
                'transfer_date' => $input['transfer_date'] ?? date('Y-m-d'),
                'transfer_time' => $input['transfer_time'] ?? date('H:i:s'),
                'expected_date' => $input['expected_date'] ?? null,
                'notes' => $input['notes'] ?? null,
                'status' => 'draft',
                'user_id' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $transferId = $this->db->insert('transfers', $data);

            // حفظ تفاصيل التحويل
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($input['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->db->insert('transfer_items', [
                    'transfer_id' => $transferId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'batch_number' => $item['batch_number'] ?? null,
                    'serial_numbers' => $item['serial_numbers'] ?? null,
                    'notes' => $item['notes'] ?? null
                ]);

                // حجز الكمية في المخزن المصدر
                $this->db->execute("
                    INSERT INTO stock_balances (product_id, warehouse_id, quantity, reserved_quantity, updated_at)
                    VALUES (:product_id, :warehouse_id, 0, :reserved_quantity, NOW())
                    ON DUPLICATE KEY UPDATE 
                        reserved_quantity = reserved_quantity + :reserved_quantity,
                        updated_at = NOW()
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $input['from_warehouse_id'],
                    'reserved_quantity' => $item['quantity']
                ]);
            }

            // تحديث إجماليات التحويل
            $this->db->update('transfers', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $transferId]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'TRANSFER_CREATED',
                'transfers',
                "إنشاء تحويل #{$transferNo}",
                [
                    'transfer_id' => $transferId,
                    'transfer_no' => $transferNo,
                    'from_warehouse' => $input['from_warehouse_id'],
                    'to_warehouse' => $input['to_warehouse_id'],
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'transfer',
                $transferId
            );

            $this->db->commit();

            successResponse('تم إنشاء التحويل بنجاح', [
                'transfer_id' => $transferId,
                'transfer_no' => $transferNo
            ]);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Transfer create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/transfers/{id}
     * تحديث تحويل (فقط في حالة المسودة)
     */
    public function update(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'transfers.edit')) {
                errorResponse('ليس لديك صلاحية لتعديل التحويلات', 403);
                return;
            }

            $transfer = $this->getTransferById($id);
            if (!$transfer) {
                errorResponse('التحويل غير موجود');
                return;
            }

            // التحقق من الحالة (فقط المسودة يمكن تعديلها)
            if ($transfer['status'] !== 'draft') {
                errorResponse('لا يمكن تعديل التحويل بعد اعتماده أو رفضه');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateTransferData($input, true);

            // بدء المعاملة
            $this->db->beginTransaction();

            // إلغاء الحجز القديم
            $oldItems = $this->db->query(
                "SELECT product_id, quantity FROM transfer_items WHERE transfer_id = :transfer_id",
                ['transfer_id' => $id]
            );

            foreach ($oldItems as $oldItem) {
                $this->db->execute("
                    UPDATE stock_balances 
                    SET reserved_quantity = GREATEST(reserved_quantity - :quantity, 0),
                        updated_at = NOW()
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $oldItem['product_id'],
                    'warehouse_id' => $transfer['from_warehouse_id'],
                    'quantity' => $oldItem['quantity']
                ]);
            }

            // تحديث بيانات التحويل
            $data = [
                'from_warehouse_id' => $input['from_warehouse_id'] ?? $transfer['from_warehouse_id'],
                'to_warehouse_id' => $input['to_warehouse_id'] ?? $transfer['to_warehouse_id'],
                'transfer_date' => $input['transfer_date'] ?? $transfer['transfer_date'],
                'transfer_time' => $input['transfer_time'] ?? $transfer['transfer_time'],
                'expected_date' => $input['expected_date'] ?? $transfer['expected_date'],
                'notes' => $input['notes'] ?? $transfer['notes'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('transfers', $data, ['id' => $id]);

            // حذف التفاصيل القديمة
            $this->db->delete('transfer_items', ['transfer_id' => $id]);

            // إضافة التفاصيل الجديدة
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($input['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->db->insert('transfer_items', [
                    'transfer_id' => $id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'batch_number' => $item['batch_number'] ?? null,
                    'serial_numbers' => $item['serial_numbers'] ?? null,
                    'notes' => $item['notes'] ?? null
                ]);

                // حجز الكمية الجديدة
                $this->db->execute("
                    INSERT INTO stock_balances (product_id, warehouse_id, quantity, reserved_quantity, updated_at)
                    VALUES (:product_id, :warehouse_id, 0, :reserved_quantity, NOW())
                    ON DUPLICATE KEY UPDATE 
                        reserved_quantity = reserved_quantity + :reserved_quantity,
                        updated_at = NOW()
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $input['from_warehouse_id'] ?? $transfer['from_warehouse_id'],
                    'reserved_quantity' => $item['quantity']
                ]);
            }

            // تحديث الإجماليات
            $this->db->update('transfers', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'TRANSFER_UPDATED',
                'transfers',
                "تحديث تحويل #{$transfer['transfer_no']}",
                [
                    'transfer_id' => $id,
                    'transfer_no' => $transfer['transfer_no'],
                    'items_count' => $totalItems
                ],
                'transfer',
                $id
            );

            $this->db->commit();

            successResponse('تم تحديث التحويل بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Transfer update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/transfers/{id}/approve
     * اعتماد تحويل (تنفيذ حركات الخصم والإضافة)
     */
    public function approve(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'transfers.approve')) {
                errorResponse('ليس لديك صلاحية لاعتماد التحويلات', 403);
                return;
            }

            $transfer = $this->getTransferById($id);
            if (!$transfer) {
                errorResponse('التحويل غير موجود');
                return;
            }

            // التحقق من الحالة
            if ($transfer['status'] === 'approved') {
                errorResponse('التحويل معتمد بالفعل');
                return;
            }

            if ($transfer['status'] === 'rejected' || $transfer['status'] === 'cancelled') {
                errorResponse('لا يمكن اعتماد تحويل مرفوض أو ملغي');
                return;
            }

            // جلب تفاصيل التحويل
            $items = $this->db->query(
                "SELECT * FROM transfer_items WHERE transfer_id = :transfer_id",
                ['transfer_id' => $id]
            );

            if (empty($items)) {
                errorResponse('لا توجد أصناف في التحويل');
                return;
            }

            // التحقق من توفر الكميات (مرة أخرى للتأكد)
            foreach ($items as $item) {
                $balance = $this->db->queryValue("
                    SELECT COALESCE(quantity, 0) 
                    FROM stock_balances 
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['from_warehouse_id']
                ]);

                if ($balance < $item['quantity']) {
                    errorResponse("الكمية غير متوفرة في المخزن المصدر (المتاح: {$balance})");
                    return;
                }
            }

            // بدء المعاملة
            $this->db->beginTransaction();

            // تنفيذ حركات المخزون
            foreach ($items as $item) {
                // 1. خصم من المخزن المصدر
                $fromBalance = $this->db->queryValue("
                    SELECT COALESCE(quantity, 0) 
                    FROM stock_balances 
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['from_warehouse_id']
                ]);

                $fromReserved = $this->db->queryValue("
                    SELECT COALESCE(reserved_quantity, 0) 
                    FROM stock_balances 
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['from_warehouse_id']
                ]);

                $newFromBalance = $fromBalance - $item['quantity'];
                $newFromReserved = max(0, $fromReserved - $item['quantity']);

                $this->db->execute("
                    INSERT INTO stock_balances (product_id, warehouse_id, quantity, reserved_quantity, last_movement_date, updated_at)
                    VALUES (:product_id, :warehouse_id, :quantity, :reserved_quantity, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                        quantity = :quantity,
                        reserved_quantity = :reserved_quantity,
                        last_movement_date = NOW(),
                        updated_at = NOW()
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['from_warehouse_id'],
                    'quantity' => $newFromBalance,
                    'reserved_quantity' => $newFromReserved
                ]);

                // تسجيل حركة خصم
                $this->db->insert('stock_movements', [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['from_warehouse_id'],
                    'movement_type' => 'TRANSFER_OUT',
                    'reference_type' => 'transfer',
                    'reference_id' => $id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $item['quantity'] * $item['unit_cost'],
                    'balance_before' => $fromBalance,
                    'balance_after' => $newFromBalance,
                    'reserved_before' => $fromReserved,
                    'reserved_after' => $newFromReserved,
                    'movement_date' => date('Y-m-d H:i:s'),
                    'user_id' => $userId,
                    'notes' => "تحويل إلى المخزن {$transfer['to_warehouse_id']} عبر #{$transfer['transfer_no']}"
                ]);

                // 2. إضافة إلى المخزن الوجهة
                $toBalance = $this->db->queryValue("
                    SELECT COALESCE(quantity, 0) 
                    FROM stock_balances 
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['to_warehouse_id']
                ]);

                $newToBalance = $toBalance + $item['quantity'];

                $this->db->execute("
                    INSERT INTO stock_balances (product_id, warehouse_id, quantity, last_movement_date, updated_at)
                    VALUES (:product_id, :warehouse_id, :quantity, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                        quantity = :quantity,
                        last_movement_date = NOW(),
                        updated_at = NOW()
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['to_warehouse_id'],
                    'quantity' => $newToBalance
                ]);

                // تسجيل حركة إضافة
                $this->db->insert('stock_movements', [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['to_warehouse_id'],
                    'movement_type' => 'TRANSFER_IN',
                    'reference_type' => 'transfer',
                    'reference_id' => $id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $item['quantity'] * $item['unit_cost'],
                    'balance_before' => $toBalance,
                    'balance_after' => $newToBalance,
                    'movement_date' => date('Y-m-d H:i:s'),
                    'user_id' => $userId,
                    'notes' => "تحويل من المخزن {$transfer['from_warehouse_id']} عبر #{$transfer['transfer_no']}"
                ]);
            }

            // تحديث حالة التحويل
            $this->db->update('transfers', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'TRANSFER_APPROVED',
                'transfers',
                "اعتماد تحويل #{$transfer['transfer_no']}",
                [
                    'transfer_id' => $id,
                    'transfer_no' => $transfer['transfer_no'],
                    'items_count' => count($items)
                ],
                'transfer',
                $id
            );

            $this->db->commit();

            successResponse('تم اعتماد التحويل بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Transfer approve error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/transfers/{id}/complete
     * إكمال تحويل (تحديث حالة إلى مكتمل)
     */
    public function complete(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'transfers.approve')) {
                errorResponse('ليس لديك صلاحية لإكمال التحويلات', 403);
                return;
            }

            $transfer = $this->getTransferById($id);
            if (!$transfer) {
                errorResponse('التحويل غير موجود');
                return;
            }

            if ($transfer['status'] !== 'approved') {
                errorResponse('لا يمكن إكمال تحويل غير معتمد');
                return;
            }

            if ($transfer['status'] === 'completed') {
                errorResponse('التحويل مكتمل بالفعل');
                return;
            }

            // تحديث الحالة
            $this->db->update('transfers', [
                'status' => 'completed',
                'delivered_date' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'TRANSFER_COMPLETED',
                'transfers',
                "إكمال تحويل #{$transfer['transfer_no']}",
                [
                    'transfer_id' => $id,
                    'transfer_no' => $transfer['transfer_no']
                ],
                'transfer',
                $id
            );

            successResponse('تم إكمال التحويل بنجاح');

        } catch (Exception $e) {
            error_log('Transfer complete error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/transfers/{id}/reject
     * رفض تحويل
     */
    public function reject(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'transfers.approve')) {
                errorResponse('ليس لديك صلاحية لرفض التحويلات', 403);
                return;
            }

            $transfer = $this->getTransferById($id);
            if (!$transfer) {
                errorResponse('التحويل غير موجود');
                return;
            }

            if ($transfer['status'] === 'rejected') {
                errorResponse('التحويل مرفوض بالفعل');
                return;
            }

            if ($transfer['status'] === 'approved' || $transfer['status'] === 'completed') {
                errorResponse('لا يمكن رفض تحويل معتمد أو مكتمل');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // بدء المعاملة
            $this->db->beginTransaction();

            // إلغاء الحجز
            $items = $this->db->query(
                "SELECT product_id, quantity FROM transfer_items WHERE transfer_id = :transfer_id",
                ['transfer_id' => $id]
            );

            foreach ($items as $item) {
                $this->db->execute("
                    UPDATE stock_balances 
                    SET reserved_quantity = GREATEST(reserved_quantity - :quantity, 0),
                        updated_at = NOW()
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['from_warehouse_id'],
                    'quantity' => $item['quantity']
                ]);
            }

            // تحديث الحالة
            $this->db->update('transfers', [
                'status' => 'rejected',
                'rejected_by' => $userId,
                'rejected_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $input['reason'] ?? null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'TRANSFER_REJECTED',
                'transfers',
                "رفض تحويل #{$transfer['transfer_no']}",
                [
                    'transfer_id' => $id,
                    'transfer_no' => $transfer['transfer_no'],
                    'reason' => $input['reason'] ?? null
                ],
                'transfer',
                $id
            );

            $this->db->commit();

            successResponse('تم رفض التحويل بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Transfer reject error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/transfers/{id}/cancel
     * إلغاء تحويل
     */
    public function cancel(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'transfers.cancel')) {
                errorResponse('ليس لديك صلاحية لإلغاء التحويلات', 403);
                return;
            }

            $transfer = $this->getTransferById($id);
            if (!$transfer) {
                errorResponse('التحويل غير موجود');
                return;
            }

            if ($transfer['status'] === 'cancelled') {
                errorResponse('التحويل ملغي بالفعل');
                return;
            }

            if ($transfer['status'] === 'approved' || $transfer['status'] === 'completed') {
                errorResponse('لا يمكن إلغاء تحويل معتمد أو مكتمل');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // بدء المعاملة
            $this->db->beginTransaction();

            // إلغاء الحجز
            $items = $this->db->query(
                "SELECT product_id, quantity FROM transfer_items WHERE transfer_id = :transfer_id",
                ['transfer_id' => $id]
            );

            foreach ($items as $item) {
                $this->db->execute("
                    UPDATE stock_balances 
                    SET reserved_quantity = GREATEST(reserved_quantity - :quantity, 0),
                        updated_at = NOW()
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['from_warehouse_id'],
                    'quantity' => $item['quantity']
                ]);
            }

            // تحديث الحالة
            $this->db->update('transfers', [
                'status' => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'TRANSFER_CANCELLED',
                'transfers',
                "إلغاء تحويل #{$transfer['transfer_no']}",
                [
                    'transfer_id' => $id,
                    'transfer_no' => $transfer['transfer_no'],
                    'reason' => $input['reason'] ?? null
                ],
                'transfer',
                $id
            );

            $this->db->commit();

            successResponse('تم إلغاء التحويل بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Transfer cancel error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/transfers/{id}/print
     * طباعة تحويل
     */
    public function print(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'transfers.view')) {
                errorResponse('ليس لديك صلاحية لعرض التحويلات', 403);
                return;
            }

            $transfer = $this->getTransferById($id);
            if (!$transfer) {
                errorResponse('التحويل غير موجود');
                return;
            }

            // جلب تفاصيل التحويل
            $items = $this->db->query("
                SELECT 
                    ti.*,
                    p.code as product_code,
                    p.name as product_name,
                    u.name as unit_name
                FROM transfer_items ti
                INNER JOIN products p ON p.id = ti.product_id
                LEFT JOIN units u ON u.id = p.unit_id
                WHERE ti.transfer_id = :transfer_id
            ", ['transfer_id' => $id]);

            // HTML للطباعة
            $html = $this->generateTransferPrintHTML($transfer, $items);
            
            successResponse('تم جلب بيانات الطباعة', [
                'html' => $html,
                'transfer' => $transfer,
                'items' => $items
            ]);

        } catch (Exception $e) {
            error_log('Transfer print error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/transfers/export
     * تصدير التحويلات
     */
    public function export(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'transfers.export')) {
                errorResponse('ليس لديك صلاحية لتصدير التحويلات', 403);
                return;
            }

            $format = $_GET['format'] ?? 'csv';
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            $transfers = $this->db->query("
                SELECT 
                    t.transfer_no,
                    t.transfer_date,
                    fw.name as from_warehouse,
                    tw.name as to_warehouse,
                    t.total_items,
                    t.total_quantity,
                    t.total_cost,
                    t.status,
                    u.full_name as created_by,
                    t.created_at,
                    t.delivered_date
                FROM transfers t
                LEFT JOIN warehouses fw ON fw.id = t.from_warehouse_id
                LEFT JOIN warehouses tw ON tw.id = t.to_warehouse_id
                LEFT JOIN users u ON u.id = t.user_id
                WHERE t.transfer_date BETWEEN :from_date AND :to_date
                ORDER BY t.transfer_date DESC
            ", [
                'from_date' => $fromDate,
                'to_date' => $toDate
            ]);

            if ($format === 'csv') {
                $this->exportCSV($transfers);
            } elseif ($format === 'excel') {
                $this->exportExcel($transfers);
            } else {
                successResponse('تم جلب بيانات التصدير', $transfers);
            }

        } catch (Exception $e) {
            error_log('Transfer export error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على تحويل بالمعرف
     */
    private function getTransferById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                t.*,
                fw.name as from_warehouse_name,
                tw.name as to_warehouse_name,
                u.full_name as user_name,
                a.full_name as approved_by_name
            FROM transfers t
            LEFT JOIN warehouses fw ON fw.id = t.from_warehouse_id
            LEFT JOIN warehouses tw ON tw.id = t.to_warehouse_id
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN users a ON a.id = t.approved_by
            WHERE t.id = :id
        ", ['id' => $id]);
    }

    /**
     * توليد رقم تحويل
     */
    private function generateTransferNumber(): string
    {
        $prefix = 'TRF';
        $year = date('Y');
        $month = date('m');
        
        $last = $this->db->queryValue("
            SELECT MAX(CAST(SUBSTRING(transfer_no, -4) AS UNSIGNED)) 
            FROM transfers 
            WHERE transfer_no LIKE :pattern
        ", ['pattern' => "{$prefix}{$year}{$month}%"]);

        $number = str_pad((int)$last + 1, 4, '0', STR_PAD_LEFT);
        return "{$prefix}{$year}{$month}{$number}";
    }

    /**
     * التحقق من صحة بيانات التحويل
     */
    private function validateTransferData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['from_warehouse_id'])) {
            errorResponse('المخزن المصدر مطلوب');
            return;
        }
        
        if (empty($data['to_warehouse_id'])) {
            errorResponse('المخزن الوجهة مطلوب');
            return;
        }
        
        if (empty($data['items']) || !is_array($data['items'])) {
            errorResponse('الأصناف مطلوبة');
            return;
        }
        
        foreach ($data['items'] as $index => $item) {
            if (empty($item['product_id'])) {
                errorResponse("الصنف مطلوب في العنصر " . ($index + 1));
                return;
            }
            
            if (empty($item['quantity']) || $item['quantity'] <= 0) {
                errorResponse("الكمية يجب أن تكون أكبر من صفر في العنصر " . ($index + 1));
                return;
            }
            
            if (!isset($item['unit_cost']) || $item['unit_cost'] < 0) {
                errorResponse("سعر الوحدة غير صحيح في العنصر " . ($index + 1));
                return;
            }
            
            // التحقق من وجود المنتج
            $product = $this->db->queryValue(
                "SELECT id FROM products WHERE id = :id AND deleted_at IS NULL",
                ['id' => $item['product_id']]
            );
            
            if (!$product) {
                errorResponse("المنتج غير موجود في العنصر " . ($index + 1));
                return;
            }
        }
    }

    /**
     * تصدير CSV
     */
    private function exportCSV(array $data): void
    {
        if (empty($data)) {
            errorResponse('لا توجد بيانات للتصدير');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="transfers_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        $headers = array_keys($data[0]);
        fputcsv($output, $headers);
        
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }

    /**
     * تصدير Excel
     */
    private function exportExcel(array $data): void
    {
        if (empty($data)) {
            errorResponse('لا توجد بيانات للتصدير');
            return;
        }

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="transfers_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1">';
        echo '<tr style="background:#667eea;color:#fff;font-weight:bold;">';
        foreach (array_keys($data[0]) as $header) {
            echo '<th>' . $header . '</th>';
        }
        echo '</tr>';
        
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $value) {
                echo '<td>' . $value . '</td>';
            }
            echo '</tr>';
        }
        
        echo '</table>';
        exit;
    }

    /**
     * توليد HTML للطباعة
     */
    private function generateTransferPrintHTML(array $transfer, array $items): string
    {
        $html = '<!DOCTYPE html>
        <html dir="rtl" lang="ar">
        <head>
            <meta charset="UTF-8">
            <title>تحويل #' . $transfer['transfer_no'] . '</title>
            <style>
                body { font-family: "Tajawal", sans-serif; padding: 40px; background: #fff; }
                .header { text-align: center; border-bottom: 2px solid #667eea; padding-bottom: 20px; margin-bottom: 20px; }
                .header h1 { color: #667eea; margin: 0; }
                .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
                .info-item { padding: 8px; background: #f8f9fa; border-radius: 5px; }
                .info-item .label { color: #666; font-weight: bold; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th { background: #667eea; color: #fff; padding: 10px; text-align: right; }
                td { padding: 10px; border-bottom: 1px solid #ddd; }
                .total-row { background: #f8f9fa; font-weight: bold; }
                .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #888; font-size: 12px; }
                .status-completed { color: #28a745; }
                .status-approved { color: #17a2b8; }
                .status-draft { color: #ffc107; }
                .status-rejected { color: #dc3545; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>إذن تحويل</h1>
                <p>' . $transfer['transfer_no'] . '</p>
            </div>
            
            <div class="info-grid">
                <div class="info-item"><span class="label">من مخزن:</span> ' . $transfer['from_warehouse_name'] . '</div>
                <div class="info-item"><span class="label">إلى مخزن:</span> ' . $transfer['to_warehouse_name'] . '</div>
                <div class="info-item"><span class="label">التاريخ:</span> ' . $transfer['transfer_date'] . '</div>
                <div class="info-item"><span class="label">الحالة:</span> <span class="status-' . $transfer['status'] . '">' . $transfer['status_label'] . '</span></div>
                <div class="info-item"><span class="label">تم الإنشاء بواسطة:</span> ' . $transfer['user_name'] . '</div>
                <div class="info-item"><span class="label">تاريخ الإنشاء:</span> ' . $transfer['created_at'] . '</div>
                ' . (!empty($transfer['expected_date']) ? '<div class="info-item"><span class="label">التاريخ المتوقع:</span> ' . $transfer['expected_date'] . '</div>' : '') . '
                ' . (!empty($transfer['delivered_date']) ? '<div class="info-item"><span class="label">تاريخ التسليم:</span> ' . $transfer['delivered_date'] . '</div>' : '') . '
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الكود</th>
                        <th>المنتج</th>
                        <th>الكمية</th>
                        <th>الوحدة</th>
                        <th>سعر الوحدة</th>
                        <th>الإجمالي</th>
                    </tr>
                </thead>
                <tbody>';
        
        $index = 1;
        foreach ($items as $item) {
            $html .= '<tr>
                <td>' . $index++ . '</td>
                <td>' . $item['product_code'] . '</td>
                <td>' . $item['product_name'] . '</td>
                <td>' . $item['quantity'] . '</td>
                <td>' . $item['unit_name'] . '</td>
                <td>' . number_format($item['unit_cost'], 2) . '</td>
                <td>' . number_format($item['total_cost'], 2) . '</td>
            </tr>';
        }
        
        $html .= '
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="6" style="text-align:left;">الإجمالي</td>
                        <td>' . number_format($transfer['total_cost'], 2) . '</td>
                    </tr>
                </tfoot>
            </table>
            
            ' . (!empty($transfer['notes']) ? '<div style="margin: 20px 0; padding: 10px; background: #f8f9fa; border-radius: 5px;"><strong>ملاحظات:</strong> ' . $transfer['notes'] . '</div>' : '') . '
            
            <div class="footer">
                <p>نظام إدارة المخازن والمخزون المتقدم v5.0</p>
                <p>تم الطباعة في ' . date('Y-m-d H:i:s') . '</p>
            </div>
        </body>
        </html>';
        
        return $html;
    }
}

// ================================================================
// انتهى الملف
// ================================================================

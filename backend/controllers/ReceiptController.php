<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/controllers/ReceiptController.php
// الوصف: متحكم إدارة إذون الاستلام - إنشاء، تعديل، اعتماد، رفض
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Services\StockService;

class ReceiptController
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
     * GET /api/receipts
     * جلب قائمة إذون الاستلام مع فلترة وبحث
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'receipts.view')) {
                errorResponse('ليس لديك صلاحية لعرض إذون الاستلام', 403);
                return;
            }

            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            $warehouse = $_GET['warehouse'] ?? '';
            $supplier = $_GET['supplier'] ?? '';
            $fromDate = $_GET['from_date'] ?? '';
            $toDate = $_GET['to_date'] ?? '';
            $sort = $_GET['sort'] ?? 'created_at';
            $order = $_GET['order'] ?? 'DESC';

            $params = [];
            $where = [];
            
            if (!empty($search)) {
                $where[] = "r.receipt_no LIKE :search";
                $params['search'] = "%{$search}%";
            }
            
            if (!empty($status)) {
                $where[] = "r.status = :status";
                $params['status'] = $status;
            }
            
            if (!empty($warehouse)) {
                $where[] = "r.warehouse_id = :warehouse";
                $params['warehouse'] = $warehouse;
            }
            
            if (!empty($supplier)) {
                $where[] = "r.supplier_id = :supplier";
                $params['supplier'] = $supplier;
            }
            
            if (!empty($fromDate)) {
                $where[] = "r.receipt_date >= :from_date";
                $params['from_date'] = $fromDate;
            }
            
            if (!empty($toDate)) {
                $where[] = "r.receipt_date <= :to_date";
                $params['to_date'] = $toDate;
            }

            $allowedSorts = ['id', 'receipt_no', 'receipt_date', 'total_quantity', 'total_cost', 'status', 'created_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'created_at';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب الإذون
            $receipts = $this->db->query("
                SELECT 
                    r.id,
                    r.receipt_no,
                    r.warehouse_id,
                    w.name as warehouse_name,
                    r.supplier_id,
                    s.name as supplier_name,
                    r.receipt_date,
                    r.receipt_time,
                    r.total_items,
                    r.total_quantity,
                    r.total_cost,
                    r.net_total,
                    r.status,
                    r.user_id,
                    u.full_name as user_name,
                    r.approved_by,
                    a.full_name as approved_by_name,
                    r.approved_at,
                    r.created_at,
                    r.updated_at,
                    r.notes,
                    (SELECT COUNT(*) FROM receipt_items WHERE receipt_id = r.id) as items_count,
                    CASE 
                        WHEN r.status = 'draft' THEN 'مسودة'
                        WHEN r.status = 'submitted' THEN 'مرسل'
                        WHEN r.status = 'approved' THEN 'معتمد'
                        WHEN r.status = 'rejected' THEN 'مرفوض'
                        WHEN r.status = 'cancelled' THEN 'ملغي'
                        WHEN r.status = 'completed' THEN 'مكتمل'
                        ELSE r.status
                    END as status_label
                FROM receipts r
                LEFT JOIN warehouses w ON w.id = r.warehouse_id
                LEFT JOIN suppliers s ON s.id = r.supplier_id
                LEFT JOIN users u ON u.id = r.user_id
                LEFT JOIN users a ON a.id = r.approved_by
                WHERE 1=1
                " . (!empty($where) ? 'AND ' . implode(' AND ', $where) : '') . "
                ORDER BY r.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            // إجمالي الإذون
            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM receipts r
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
                FROM receipts
            ");

            successResponse('تم جلب قائمة إذون الاستلام', [
                'data' => $receipts,
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

        } catch (\Exception $e) {
            error_log('Receipts list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/receipts/{id}
     * جلب بيانات إذن استلام مع تفاصيل كاملة
     */
    public function show(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'receipts.view')) {
                errorResponse('ليس لديك صلاحية لعرض إذون الاستلام', 403);
                return;
            }

            $receipt = $this->getReceiptById($id);
            
            if (!$receipt) {
                errorResponse('إذن الاستلام غير موجود');
                return;
            }

            // جلب تفاصيل الإذن
            $items = $this->db->query("
                SELECT 
                    ri.*,
                    p.code as product_code,
                    p.name as product_name,
                    p.barcode,
                    u.name as unit_name,
                    p.min_stock,
                    p.max_stock,
                    COALESCE(sb.quantity, 0) as current_balance
                FROM receipt_items ri
                INNER JOIN products p ON p.id = ri.product_id
                LEFT JOIN units u ON u.id = p.unit_id
                LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = (SELECT warehouse_id FROM receipts WHERE id = :receipt_id)
                WHERE ri.receipt_id = :receipt_id
            ", ['receipt_id' => $id]);

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
                WHERE al.reference_type = 'receipt'
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
                FROM receipt_status_history
                WHERE receipt_id = :receipt_id
                ORDER BY created_at ASC
            ", ['receipt_id' => $id]);

            successResponse('تم جلب بيانات إذن الاستلام', [
                'receipt' => $receipt,
                'items' => $items,
                'audits' => $audits,
                'history' => $history,
                'summary' => [
                    'total_items' => count($items),
                    'total_quantity' => array_sum(array_column($items, 'quantity')),
                    'total_cost' => array_sum(array_column($items, 'total_cost'))
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Receipt show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/receipts
     * إنشاء إذن استلام جديد
     */
    public function create(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'receipts.create')) {
                errorResponse('ليس لديك صلاحية لإنشاء إذون استلام', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateReceiptData($input);

            // توليد رقم الإذن
            $receiptNo = $this->generateReceiptNumber();

            // بدء المعاملة
            $this->db->beginTransaction();

            // إنشاء الإذن
            $data = [
                'receipt_no' => $receiptNo,
                'warehouse_id' => $input['warehouse_id'],
                'supplier_id' => $input['supplier_id'],
                'receipt_date' => $input['receipt_date'] ?? date('Y-m-d'),
                'receipt_time' => $input['receipt_time'] ?? date('H:i:s'),
                'expected_date' => $input['expected_date'] ?? null,
                'po_number' => $input['po_number'] ?? null,
                'invoice_number' => $input['invoice_number'] ?? null,
                'notes' => $input['notes'] ?? null,
                'status' => 'draft',
                'user_id' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $receiptId = $this->db->insert('receipts', $data);

            // حفظ تفاصيل الإذن
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($input['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->db->insert('receipt_items', [
                    'receipt_id' => $receiptId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'received_quantity' => 0,
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'tax_rate' => $item['tax_rate'] ?? 0,
                    'tax_amount' => $item['tax_amount'] ?? 0,
                    'discount_rate' => $item['discount_rate'] ?? 0,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'batch_number' => $item['batch_number'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'serial_numbers' => $item['serial_numbers'] ?? null,
                    'notes' => $item['notes'] ?? null
                ]);
            }

            // تحديث إجماليات الإذن
            $this->db->update('receipts', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $receiptId]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RECEIPT_CREATED',
                'receipts',
                "إنشاء إذن استلام #{$receiptNo}",
                [
                    'receipt_id' => $receiptId,
                    'receipt_no' => $receiptNo,
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'receipt',
                $receiptId
            );

            $this->db->commit();

            successResponse('تم إنشاء إذن الاستلام بنجاح', [
                'receipt_id' => $receiptId,
                'receipt_no' => $receiptNo
            ]);

        } catch (\Exception $e) {
            $this->db->rollback();
            error_log('Receipt create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * PUT /api/receipts/{id}
     * تحديث إذن استلام (فقط في حالة المسودة)
     */
    public function update(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'receipts.edit')) {
                errorResponse('ليس لديك صلاحية لتعديل إذون الاستلام', 403);
                return;
            }

            $receipt = $this->getReceiptById($id);
            if (!$receipt) {
                errorResponse('إذن الاستلام غير موجود');
                return;
            }

            // التحقق من الحالة (فقط المسودة يمكن تعديلها)
            if ($receipt['status'] !== 'draft') {
                errorResponse('لا يمكن تعديل الإذن بعد اعتماده أو رفضه');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateReceiptData($input, true);

            // بدء المعاملة
            $this->db->beginTransaction();

            // تحديث بيانات الإذن
            $data = [
                'warehouse_id' => $input['warehouse_id'] ?? $receipt['warehouse_id'],
                'supplier_id' => $input['supplier_id'] ?? $receipt['supplier_id'],
                'receipt_date' => $input['receipt_date'] ?? $receipt['receipt_date'],
                'receipt_time' => $input['receipt_time'] ?? $receipt['receipt_time'],
                'expected_date' => $input['expected_date'] ?? $receipt['expected_date'],
                'po_number' => $input['po_number'] ?? $receipt['po_number'],
                'invoice_number' => $input['invoice_number'] ?? $receipt['invoice_number'],
                'notes' => $input['notes'] ?? $receipt['notes'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('receipts', $data, ['id' => $id]);

            // حذف التفاصيل القديمة
            $this->db->delete('receipt_items', ['receipt_id' => $id]);

            // إضافة التفاصيل الجديدة
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($input['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->db->insert('receipt_items', [
                    'receipt_id' => $id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'received_quantity' => 0,
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'tax_rate' => $item['tax_rate'] ?? 0,
                    'tax_amount' => $item['tax_amount'] ?? 0,
                    'discount_rate' => $item['discount_rate'] ?? 0,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'batch_number' => $item['batch_number'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'serial_numbers' => $item['serial_numbers'] ?? null,
                    'notes' => $item['notes'] ?? null
                ]);
            }

            // تحديث الإجماليات
            $this->db->update('receipts', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RECEIPT_UPDATED',
                'receipts',
                "تحديث إذن استلام #{$receipt['receipt_no']}",
                [
                    'receipt_id' => $id,
                    'receipt_no' => $receipt['receipt_no'],
                    'items_count' => $totalItems
                ],
                'receipt',
                $id
            );

            $this->db->commit();

            successResponse('تم تحديث إذن الاستلام بنجاح');

        } catch (\Exception $e) {
            $this->db->rollback();
            error_log('Receipt update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/receipts/{id}/approve
     * اعتماد إذن استلام (تنفيذ الحركات)
     */
    public function approve(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'receipts.approve')) {
                errorResponse('ليس لديك صلاحية لاعتماد إذون الاستلام', 403);
                return;
            }

            $receipt = $this->getReceiptById($id);
            if (!$receipt) {
                errorResponse('إذن الاستلام غير موجود');
                return;
            }

            // التحقق من الحالة
            if ($receipt['status'] === 'approved') {
                errorResponse('الإذن معتمد بالفعل');
                return;
            }

            if ($receipt['status'] === 'rejected' || $receipt['status'] === 'cancelled') {
                errorResponse('لا يمكن اعتماد إذن مرفوض أو ملغي');
                return;
            }

            // جلب تفاصيل الإذن
            $items = $this->db->query(
                "SELECT * FROM receipt_items WHERE receipt_id = :receipt_id",
                ['receipt_id' => $id]
            );

            if (empty($items)) {
                errorResponse('لا توجد أصناف في الإذن');
                return;
            }

            // بدء المعاملة
            $this->db->beginTransaction();

            // تنفيذ حركات المخزون
            foreach ($items as $item) {
                // جلب الرصيد الحالي
                $currentBalance = $this->db->queryValue("
                    SELECT COALESCE(quantity, 0) 
                    FROM stock_balances 
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $receipt['warehouse_id']
                ]);

                $newBalance = $currentBalance + $item['quantity'];

                // تحديث الرصيد
                $this->db->execute("
                    INSERT INTO stock_balances (product_id, warehouse_id, quantity, last_movement_date, updated_at)
                    VALUES (:product_id, :warehouse_id, :quantity, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                        quantity = :quantity,
                        last_movement_date = NOW(),
                        updated_at = NOW()
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $receipt['warehouse_id'],
                    'quantity' => $newBalance
                ]);

                // تسجيل حركة المخزون
                $this->db->insert('stock_movements', [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $receipt['warehouse_id'],
                    'movement_type' => 'RECEIPT',
                    'reference_type' => 'receipt',
                    'reference_id' => $id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $item['quantity'] * $item['unit_cost'],
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance,
                    'movement_date' => date('Y-m-d H:i:s'),
                    'user_id' => $userId,
                    'notes' => "استلام عبر الإذن #{$receipt['receipt_no']}"
                ]);

                // تحديث كمية المستلمة
                $this->db->update('receipt_items', [
                    'received_quantity' => $item['quantity']
                ], ['id' => $item['id']]);
            }

            // تحديث حالة الإذن
            $this->db->update('receipts', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RECEIPT_APPROVED',
                'receipts',
                "اعتماد إذن استلام #{$receipt['receipt_no']}",
                [
                    'receipt_id' => $id,
                    'receipt_no' => $receipt['receipt_no'],
                    'items_count' => count($items)
                ],
                'receipt',
                $id
            );

            $this->db->commit();

            successResponse('تم اعتماد إذن الاستلام بنجاح');

        } catch (\Exception $e) {
            $this->db->rollback();
            error_log('Receipt approve error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/receipts/{id}/reject
     * رفض إذن استلام
     */
    public function reject(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'receipts.approve')) {
                errorResponse('ليس لديك صلاحية لرفض إذون الاستلام', 403);
                return;
            }

            $receipt = $this->getReceiptById($id);
            if (!$receipt) {
                errorResponse('إذن الاستلام غير موجود');
                return;
            }

            if ($receipt['status'] === 'rejected') {
                errorResponse('الإذن مرفوض بالفعل');
                return;
            }

            if ($receipt['status'] === 'approved') {
                errorResponse('لا يمكن رفض إذن معتمد');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // تحديث الحالة
            $this->db->update('receipts', [
                'status' => 'rejected',
                'rejected_by' => $userId,
                'rejected_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $input['reason'] ?? null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RECEIPT_REJECTED',
                'receipts',
                "رفض إذن استلام #{$receipt['receipt_no']}",
                [
                    'receipt_id' => $id,
                    'receipt_no' => $receipt['receipt_no'],
                    'reason' => $input['reason'] ?? null
                ],
                'receipt',
                $id
            );

            successResponse('تم رفض إذن الاستلام بنجاح');

        } catch (\Exception $e) {
            error_log('Receipt reject error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/receipts/{id}/cancel
     * إلغاء إذن استلام
     */
    public function cancel(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'receipts.cancel')) {
                errorResponse('ليس لديك صلاحية لإلغاء إذون الاستلام', 403);
                return;
            }

            $receipt = $this->getReceiptById($id);
            if (!$receipt) {
                errorResponse('إذن الاستلام غير موجود');
                return;
            }

            if ($receipt['status'] === 'cancelled') {
                errorResponse('الإذن ملغي بالفعل');
                return;
            }

            if ($receipt['status'] === 'approved') {
                errorResponse('لا يمكن إلغاء إذن معتمد');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // تحديث الحالة
            $this->db->update('receipts', [
                'status' => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RECEIPT_CANCELLED',
                'receipts',
                "إلغاء إذن استلام #{$receipt['receipt_no']}",
                [
                    'receipt_id' => $id,
                    'receipt_no' => $receipt['receipt_no'],
                    'reason' => $input['reason'] ?? null
                ],
                'receipt',
                $id
            );

            successResponse('تم إلغاء إذن الاستلام بنجاح');

        } catch (\Exception $e) {
            error_log('Receipt cancel error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/receipts/export
     * تصدير إذون الاستلام
     */
    public function export(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'receipts.export')) {
                errorResponse('ليس لديك صلاحية لتصدير إذون الاستلام', 403);
                return;
            }

            $format = $_GET['format'] ?? 'csv';
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            $receipts = $this->db->query("
                SELECT 
                    r.receipt_no,
                    r.receipt_date,
                    w.name as warehouse,
                    s.name as supplier,
                    r.total_items,
                    r.total_quantity,
                    r.total_cost,
                    r.status,
                    u.full_name as created_by,
                    r.created_at
                FROM receipts r
                LEFT JOIN warehouses w ON w.id = r.warehouse_id
                LEFT JOIN suppliers s ON s.id = r.supplier_id
                LEFT JOIN users u ON u.id = r.user_id
                WHERE r.receipt_date BETWEEN :from_date AND :to_date
                ORDER BY r.receipt_date DESC
            ", [
                'from_date' => $fromDate,
                'to_date' => $toDate
            ]);

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="receipts_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
                
                fputcsv($output, ['رقم الإذن', 'التاريخ', 'المخزن', 'المورد', 'عدد الأصناف', 'الكمية', 'القيمة', 'الحالة', 'تم الإنشاء بواسطة', 'تاريخ الإنشاء']);
                
                foreach ($receipts as $row) {
                    fputcsv($output, [
                        $row['receipt_no'],
                        $row['receipt_date'],
                        $row['warehouse'],
                        $row['supplier'],
                        $row['total_items'],
                        $row['total_quantity'],
                        $row['total_cost'],
                        $row['status'],
                        $row['created_by'],
                        $row['created_at']
                    ]);
                }
                
                fclose($output);
                exit;
            }

            successResponse('تم جلب بيانات التصدير', $receipts);

        } catch (\Exception $e) {
            error_log('Receipt export error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على إذن استلام بالمعرف
     */
    private function getReceiptById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                r.*,
                w.name as warehouse_name,
                s.name as supplier_name,
                u.full_name as user_name,
                a.full_name as approved_by_name
            FROM receipts r
            LEFT JOIN warehouses w ON w.id = r.warehouse_id
            LEFT JOIN suppliers s ON s.id = r.supplier_id
            LEFT JOIN users u ON u.id = r.user_id
            LEFT JOIN users a ON a.id = r.approved_by
            WHERE r.id = :id
        ", ['id' => $id]);
    }

    /**
     * توليد رقم إذن استلام
     */
    private function generateReceiptNumber(): string
    {
        $prefix = 'REC';
        $year = date('Y');
        $month = date('m');
        
        $last = $this->db->queryValue("
            SELECT MAX(CAST(SUBSTRING(receipt_no, -4) AS UNSIGNED)) 
            FROM receipts 
            WHERE receipt_no LIKE :pattern
        ", ['pattern' => "{$prefix}{$year}{$month}%"]);

        $number = str_pad((int)$last + 1, 4, '0', STR_PAD_LEFT);
        return "{$prefix}{$year}{$month}{$number}";
    }

    /**
     * التحقق من صحة بيانات إذن الاستلام
     */
    private function validateReceiptData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['warehouse_id'])) {
            errorResponse('المخزن مطلوب');
            return;
        }
        
        if (empty($data['supplier_id'])) {
            errorResponse('المورد مطلوب');
            return;
        }
        
        if (empty($data['items']) || !is_array($data['items'])) {
            errorResponse('الأصناف مطلوبة');
            return;
        }
        
        foreach ($data['items'] as $item) {
            if (empty($item['product_id'])) {
                errorResponse('الصنف مطلوب');
                return;
            }
            
            if (empty($item['quantity']) || $item['quantity'] <= 0) {
                errorResponse('الكمية يجب أن تكون أكبر من صفر');
                return;
            }
            
            if (!isset($item['unit_cost']) || $item['unit_cost'] < 0) {
                errorResponse('سعر الوحدة غير صحيح');
                return;
            }
        }
    }
}

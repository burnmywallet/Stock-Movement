<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/IssueController.php
// الوصف: متحكم إدارة إذون الصرف - إنشاء، اعتماد، رفض، إلغاء، تسليم، طباعة
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Services\StockService;
use Exception;

class IssueController
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
     * GET /api/issues
     * جلب قائمة إذون الصرف مع فلترة وبحث
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'issues.view')) {
                errorResponse('ليس لديك صلاحية لعرض إذون الصرف', 403);
                return;
            }

            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            $warehouse = $_GET['warehouse'] ?? '';
            $recipient = $_GET['recipient'] ?? '';
            $fromDate = $_GET['from_date'] ?? '';
            $toDate = $_GET['to_date'] ?? '';
            $sort = $_GET['sort'] ?? 'created_at';
            $order = $_GET['order'] ?? 'DESC';

            $params = [];
            $where = [];
            
            if (!empty($search)) {
                $where[] = "i.issue_no LIKE :search";
                $params['search'] = "%{$search}%";
            }
            
            if (!empty($status)) {
                $where[] = "i.status = :status";
                $params['status'] = $status;
            }
            
            if (!empty($warehouse)) {
                $where[] = "i.warehouse_id = :warehouse";
                $params['warehouse'] = $warehouse;
            }
            
            if (!empty($recipient)) {
                $where[] = "i.recipient_id = :recipient";
                $params['recipient'] = $recipient;
            }
            
            if (!empty($fromDate)) {
                $where[] = "i.issue_date >= :from_date";
                $params['from_date'] = $fromDate;
            }
            
            if (!empty($toDate)) {
                $where[] = "i.issue_date <= :to_date";
                $params['to_date'] = $toDate;
            }

            $allowedSorts = ['id', 'issue_no', 'issue_date', 'total_quantity', 'total_cost', 'status', 'created_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'created_at';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب الإذون
            $issues = $this->db->query("
                SELECT 
                    i.id,
                    i.issue_no,
                    i.warehouse_id,
                    w.name as warehouse_name,
                    i.recipient_id,
                    r.name as recipient_name,
                    r.type as recipient_type,
                    i.issue_date,
                    i.issue_time,
                    i.required_date,
                    i.delivered_date,
                    i.reference_number,
                    i.department,
                    i.project_code,
                    i.total_items,
                    i.total_quantity,
                    i.total_cost,
                    i.status,
                    i.user_id,
                    u.full_name as user_name,
                    i.approved_by,
                    a.full_name as approved_by_name,
                    i.approved_at,
                    i.created_at,
                    i.updated_at,
                    i.notes,
                    (SELECT COUNT(*) FROM issue_items WHERE issue_id = i.id) as items_count,
                    CASE 
                        WHEN i.status = 'draft' THEN 'مسودة'
                        WHEN i.status = 'submitted' THEN 'مرسل'
                        WHEN i.status = 'approved' THEN 'معتمد'
                        WHEN i.status = 'rejected' THEN 'مرفوض'
                        WHEN i.status = 'cancelled' THEN 'ملغي'
                        WHEN i.status = 'delivered' THEN 'تم التسليم'
                        ELSE i.status
                    END as status_label,
                    CASE 
                        WHEN i.status = 'approved' OR i.status = 'delivered' THEN 'success'
                        WHEN i.status = 'rejected' THEN 'danger'
                        WHEN i.status = 'cancelled' THEN 'secondary'
                        WHEN i.status = 'draft' THEN 'warning'
                        WHEN i.status = 'submitted' THEN 'info'
                        ELSE 'secondary'
                    END as status_color
                FROM issues i
                LEFT JOIN warehouses w ON w.id = i.warehouse_id
                LEFT JOIN recipients r ON r.id = i.recipient_id
                LEFT JOIN users u ON u.id = i.user_id
                LEFT JOIN users a ON a.id = i.approved_by
                WHERE 1=1
                " . (!empty($where) ? 'AND ' . implode(' AND ', $where) : '') . "
                ORDER BY i.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            // إجمالي الإذون
            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM issues i
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
                    COUNT(CASE WHEN status = 'delivered' THEN 1 END) as delivered,
                    COALESCE(SUM(total_cost), 0) as total_value,
                    COALESCE(SUM(total_quantity), 0) as total_quantity
                FROM issues
            ");

            successResponse('تم جلب قائمة إذون الصرف', [
                'data' => $issues,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'draft' => (int)($stats['draft'] ?? 0),
                    'submitted' => (int)($stats['submitted'] ?? 0),
                    'approved' => (int)($stats['approved'] ?? 0),
                    'rejected' => (int)($stats['rejected'] ?? 0),
                    'cancelled' => (int)($stats['cancelled'] ?? 0),
                    'delivered' => (int)($stats['delivered'] ?? 0),
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
            error_log('Issues list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/issues/{id}
     * جلب بيانات إذن صرف مع تفاصيل كاملة
     */
    public function show(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'issues.view')) {
                errorResponse('ليس لديك صلاحية لعرض إذون الصرف', 403);
                return;
            }

            $issue = $this->getIssueById($id);
            
            if (!$issue) {
                errorResponse('إذن الصرف غير موجود');
                return;
            }

            // جلب تفاصيل الإذن
            $items = $this->db->query("
                SELECT 
                    ii.*,
                    p.code as product_code,
                    p.name as product_name,
                    p.barcode,
                    u.name as unit_name,
                    u.symbol as unit_symbol,
                    p.min_stock,
                    p.max_stock,
                    COALESCE(sb.quantity, 0) as current_balance,
                    COALESCE(sb.reserved_quantity, 0) as reserved_quantity,
                    (ii.quantity - ii.delivered_quantity) as pending_quantity
                FROM issue_items ii
                INNER JOIN products p ON p.id = ii.product_id
                LEFT JOIN units u ON u.id = p.unit_id
                LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = (SELECT warehouse_id FROM issues WHERE id = :issue_id)
                WHERE ii.issue_id = :issue_id
            ", ['issue_id' => $id]);

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
                WHERE al.reference_type = 'issue'
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
                FROM issue_status_history
                WHERE issue_id = :issue_id
                ORDER BY created_at ASC
            ", ['issue_id' => $id]);

            successResponse('تم جلب بيانات إذن الصرف', [
                'issue' => $issue,
                'items' => $items,
                'audits' => $audits,
                'history' => $history,
                'summary' => [
                    'total_items' => count($items),
                    'total_quantity' => array_sum(array_column($items, 'quantity')),
                    'total_cost' => array_sum(array_column($items, 'total_cost')),
                    'pending_quantity' => array_sum(array_column($items, 'pending_quantity'))
                ]
            ]);

        } catch (Exception $e) {
            error_log('Issue show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/issues
     * إنشاء إذن صرف جديد
     */
    public function create(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'issues.create')) {
                errorResponse('ليس لديك صلاحية لإنشاء إذون صرف', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateIssueData($input);

            // التحقق من توفر الكميات
            foreach ($input['items'] as $item) {
                $balance = $this->db->queryValue("
                    SELECT COALESCE(quantity, 0) 
                    FROM stock_balances 
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $input['warehouse_id']
                ]);

                if ($balance < $item['quantity']) {
                    errorResponse("الكمية غير متوفرة للصنف (الكمية المتاحة: {$balance})");
                    return;
                }
            }

            // توليد رقم الإذن
            $issueNo = $this->generateIssueNumber();

            // بدء المعاملة
            $this->db->beginTransaction();

            // إنشاء الإذن
            $data = [
                'issue_no' => $issueNo,
                'warehouse_id' => $input['warehouse_id'],
                'recipient_id' => $input['recipient_id'],
                'issue_date' => $input['issue_date'] ?? date('Y-m-d'),
                'issue_time' => $input['issue_time'] ?? date('H:i:s'),
                'required_date' => $input['required_date'] ?? null,
                'reference_number' => $input['reference_number'] ?? null,
                'department' => $input['department'] ?? null,
                'project_code' => $input['project_code'] ?? null,
                'notes' => $input['notes'] ?? null,
                'status' => 'draft',
                'user_id' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $issueId = $this->db->insert('issues', $data);

            // حفظ تفاصيل الإذن
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($input['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->db->insert('issue_items', [
                    'issue_id' => $issueId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'delivered_quantity' => 0,
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'batch_number' => $item['batch_number'] ?? null,
                    'serial_numbers' => $item['serial_numbers'] ?? null,
                    'notes' => $item['notes'] ?? null
                ]);

                // حجز الكمية (تحديث الرصيد المحجوز)
                $this->db->execute("
                    INSERT INTO stock_balances (product_id, warehouse_id, quantity, reserved_quantity, updated_at)
                    VALUES (:product_id, :warehouse_id, 0, :reserved_quantity, NOW())
                    ON DUPLICATE KEY UPDATE 
                        reserved_quantity = reserved_quantity + :reserved_quantity,
                        updated_at = NOW()
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $input['warehouse_id'],
                    'reserved_quantity' => $item['quantity']
                ]);
            }

            // تحديث إجماليات الإذن
            $this->db->update('issues', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $issueId]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'ISSUE_CREATED',
                'issues',
                "إنشاء إذن صرف #{$issueNo}",
                [
                    'issue_id' => $issueId,
                    'issue_no' => $issueNo,
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'issue',
                $issueId
            );

            $this->db->commit();

            successResponse('تم إنشاء إذن الصرف بنجاح', [
                'issue_id' => $issueId,
                'issue_no' => $issueNo
            ]);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Issue create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/issues/{id}
     * تحديث إذن صرف (فقط في حالة المسودة)
     */
    public function update(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'issues.edit')) {
                errorResponse('ليس لديك صلاحية لتعديل إذون الصرف', 403);
                return;
            }

            $issue = $this->getIssueById($id);
            if (!$issue) {
                errorResponse('إذن الصرف غير موجود');
                return;
            }

            // التحقق من الحالة (فقط المسودة يمكن تعديلها)
            if ($issue['status'] !== 'draft') {
                errorResponse('لا يمكن تعديل الإذن بعد اعتماده أو رفضه');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateIssueData($input, true);

            // بدء المعاملة
            $this->db->beginTransaction();

            // إلغاء حجز الكميات القديمة
            $oldItems = $this->db->query(
                "SELECT product_id, quantity FROM issue_items WHERE issue_id = :issue_id",
                ['issue_id' => $id]
            );

            foreach ($oldItems as $oldItem) {
                $this->db->execute("
                    UPDATE stock_balances 
                    SET reserved_quantity = GREATEST(reserved_quantity - :quantity, 0),
                        updated_at = NOW()
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $oldItem['product_id'],
                    'warehouse_id' => $issue['warehouse_id'],
                    'quantity' => $oldItem['quantity']
                ]);
            }

            // تحديث بيانات الإذن
            $data = [
                'warehouse_id' => $input['warehouse_id'] ?? $issue['warehouse_id'],
                'recipient_id' => $input['recipient_id'] ?? $issue['recipient_id'],
                'issue_date' => $input['issue_date'] ?? $issue['issue_date'],
                'issue_time' => $input['issue_time'] ?? $issue['issue_time'],
                'required_date' => $input['required_date'] ?? $issue['required_date'],
                'reference_number' => $input['reference_number'] ?? $issue['reference_number'],
                'department' => $input['department'] ?? $issue['department'],
                'project_code' => $input['project_code'] ?? $issue['project_code'],
                'notes' => $input['notes'] ?? $issue['notes'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('issues', $data, ['id' => $id]);

            // حذف التفاصيل القديمة
            $this->db->delete('issue_items', ['issue_id' => $id]);

            // إضافة التفاصيل الجديدة
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($input['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->db->insert('issue_items', [
                    'issue_id' => $id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'delivered_quantity' => 0,
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
                    'warehouse_id' => $input['warehouse_id'] ?? $issue['warehouse_id'],
                    'reserved_quantity' => $item['quantity']
                ]);
            }

            // تحديث الإجماليات
            $this->db->update('issues', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'ISSUE_UPDATED',
                'issues',
                "تحديث إذن صرف #{$issue['issue_no']}",
                [
                    'issue_id' => $id,
                    'issue_no' => $issue['issue_no'],
                    'items_count' => $totalItems
                ],
                'issue',
                $id
            );

            $this->db->commit();

            successResponse('تم تحديث إذن الصرف بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Issue update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/issues/{id}/approve
     * اعتماد إذن صرف (تنفيذ حركات الخصم)
     */
    public function approve(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'issues.approve')) {
                errorResponse('ليس لديك صلاحية لاعتماد إذون الصرف', 403);
                return;
            }

            $issue = $this->getIssueById($id);
            if (!$issue) {
                errorResponse('إذن الصرف غير موجود');
                return;
            }

            // التحقق من الحالة
            if ($issue['status'] === 'approved') {
                errorResponse('الإذن معتمد بالفعل');
                return;
            }

            if ($issue['status'] === 'rejected' || $issue['status'] === 'cancelled') {
                errorResponse('لا يمكن اعتماد إذن مرفوض أو ملغي');
                return;
            }

            // جلب تفاصيل الإذن
            $items = $this->db->query(
                "SELECT * FROM issue_items WHERE issue_id = :issue_id",
                ['issue_id' => $id]
            );

            if (empty($items)) {
                errorResponse('لا توجد أصناف في الإذن');
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
                    'warehouse_id' => $issue['warehouse_id']
                ]);

                if ($balance < $item['quantity']) {
                    errorResponse("الكمية غير متوفرة للصنف (الكمية المتاحة: {$balance})");
                    return;
                }
            }

            // بدء المعاملة
            $this->db->beginTransaction();

            // تنفيذ حركات المخزون (خصم الكميات)
            foreach ($items as $item) {
                // جلب الرصيد الحالي
                $currentBalance = $this->db->queryValue("
                    SELECT COALESCE(quantity, 0) 
                    FROM stock_balances 
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $issue['warehouse_id']
                ]);

                $currentReserved = $this->db->queryValue("
                    SELECT COALESCE(reserved_quantity, 0) 
                    FROM stock_balances 
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $issue['warehouse_id']
                ]);

                $newBalance = $currentBalance - $item['quantity'];
                $newReserved = max(0, $currentReserved - $item['quantity']);

                // تحديث الرصيد
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
                    'warehouse_id' => $issue['warehouse_id'],
                    'quantity' => $newBalance,
                    'reserved_quantity' => $newReserved
                ]);

                // تسجيل حركة المخزون
                $this->db->insert('stock_movements', [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $issue['warehouse_id'],
                    'movement_type' => 'ISSUE',
                    'reference_type' => 'issue',
                    'reference_id' => $id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $item['quantity'] * $item['unit_cost'],
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance,
                    'reserved_before' => $currentReserved,
                    'reserved_after' => $newReserved,
                    'movement_date' => date('Y-m-d H:i:s'),
                    'user_id' => $userId,
                    'notes' => "صرف عبر الإذن #{$issue['issue_no']}"
                ]);

                // تحديث كمية المسلمة
                $this->db->update('issue_items', [
                    'delivered_quantity' => $item['quantity']
                ], ['id' => $item['id']]);
            }

            // تحديث حالة الإذن
            $this->db->update('issues', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'ISSUE_APPROVED',
                'issues',
                "اعتماد إذن صرف #{$issue['issue_no']}",
                [
                    'issue_id' => $id,
                    'issue_no' => $issue['issue_no'],
                    'items_count' => count($items)
                ],
                'issue',
                $id
            );

            $this->db->commit();

            // التحقق من التنبيهات (مخزون منخفض، نفذ)
            $this->checkStockAlerts($issue['warehouse_id']);

            successResponse('تم اعتماد إذن الصرف بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Issue approve error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/issues/{id}/deliver
     * تسليم إذن صرف
     */
    public function deliver(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'issues.approve')) {
                errorResponse('ليس لديك صلاحية لتسليم إذون الصرف', 403);
                return;
            }

            $issue = $this->getIssueById($id);
            if (!$issue) {
                errorResponse('إذن الصرف غير موجود');
                return;
            }

            if ($issue['status'] !== 'approved') {
                errorResponse('لا يمكن تسليم إذن غير معتمد');
                return;
            }

            if ($issue['status'] === 'delivered') {
                errorResponse('الإذن تم تسليمه بالفعل');
                return;
            }

            // تحديث الحالة
            $this->db->update('issues', [
                'status' => 'delivered',
                'delivered_date' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'ISSUE_DELIVERED',
                'issues',
                "تسليم إذن صرف #{$issue['issue_no']}",
                [
                    'issue_id' => $id,
                    'issue_no' => $issue['issue_no']
                ],
                'issue',
                $id
            );

            successResponse('تم تسليم إذن الصرف بنجاح');

        } catch (Exception $e) {
            error_log('Issue deliver error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/issues/{id}/reject
     * رفض إذن صرف
     */
    public function reject(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'issues.approve')) {
                errorResponse('ليس لديك صلاحية لرفض إذون الصرف', 403);
                return;
            }

            $issue = $this->getIssueById($id);
            if (!$issue) {
                errorResponse('إذن الصرف غير موجود');
                return;
            }

            if ($issue['status'] === 'rejected') {
                errorResponse('الإذن مرفوض بالفعل');
                return;
            }

            if ($issue['status'] === 'approved' || $issue['status'] === 'delivered') {
                errorResponse('لا يمكن رفض إذن معتمد أو مسلم');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // بدء المعاملة
            $this->db->beginTransaction();

            // إلغاء الحجز
            $items = $this->db->query(
                "SELECT product_id, quantity FROM issue_items WHERE issue_id = :issue_id",
                ['issue_id' => $id]
            );

            foreach ($items as $item) {
                $this->db->execute("
                    UPDATE stock_balances 
                    SET reserved_quantity = GREATEST(reserved_quantity - :quantity, 0),
                        updated_at = NOW()
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $issue['warehouse_id'],
                    'quantity' => $item['quantity']
                ]);
            }

            // تحديث الحالة
            $this->db->update('issues', [
                'status' => 'rejected',
                'rejected_by' => $userId,
                'rejected_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $input['reason'] ?? null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'ISSUE_REJECTED',
                'issues',
                "رفض إذن صرف #{$issue['issue_no']}",
                [
                    'issue_id' => $id,
                    'issue_no' => $issue['issue_no'],
                    'reason' => $input['reason'] ?? null
                ],
                'issue',
                $id
            );

            $this->db->commit();

            successResponse('تم رفض إذن الصرف بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Issue reject error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/issues/{id}/cancel
     * إلغاء إذن صرف
     */
    public function cancel(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'issues.cancel')) {
                errorResponse('ليس لديك صلاحية لإلغاء إذون الصرف', 403);
                return;
            }

            $issue = $this->getIssueById($id);
            if (!$issue) {
                errorResponse('إذن الصرف غير موجود');
                return;
            }

            if ($issue['status'] === 'cancelled') {
                errorResponse('الإذن ملغي بالفعل');
                return;
            }

            if ($issue['status'] === 'approved' || $issue['status'] === 'delivered') {
                errorResponse('لا يمكن إلغاء إذن معتمد أو مسلم');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // بدء المعاملة
            $this->db->beginTransaction();

            // إلغاء الحجز
            $items = $this->db->query(
                "SELECT product_id, quantity FROM issue_items WHERE issue_id = :issue_id",
                ['issue_id' => $id]
            );

            foreach ($items as $item) {
                $this->db->execute("
                    UPDATE stock_balances 
                    SET reserved_quantity = GREATEST(reserved_quantity - :quantity, 0),
                        updated_at = NOW()
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $issue['warehouse_id'],
                    'quantity' => $item['quantity']
                ]);
            }

            // تحديث الحالة
            $this->db->update('issues', [
                'status' => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'ISSUE_CANCELLED',
                'issues',
                "إلغاء إذن صرف #{$issue['issue_no']}",
                [
                    'issue_id' => $id,
                    'issue_no' => $issue['issue_no'],
                    'reason' => $input['reason'] ?? null
                ],
                'issue',
                $id
            );

            $this->db->commit();

            successResponse('تم إلغاء إذن الصرف بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Issue cancel error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/issues/{id}/print
     * طباعة إذن صرف
     */
    public function print(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'issues.view')) {
                errorResponse('ليس لديك صلاحية لعرض إذون الصرف', 403);
                return;
            }

            $issue = $this->getIssueById($id);
            if (!$issue) {
                errorResponse('إذن الصرف غير موجود');
                return;
            }

            // جلب تفاصيل الإذن
            $items = $this->db->query("
                SELECT 
                    ii.*,
                    p.code as product_code,
                    p.name as product_name,
                    u.name as unit_name
                FROM issue_items ii
                INNER JOIN products p ON p.id = ii.product_id
                LEFT JOIN units u ON u.id = p.unit_id
                WHERE ii.issue_id = :issue_id
            ", ['issue_id' => $id]);

            // HTML للطباعة
            $html = $this->generateIssuePrintHTML($issue, $items);
            
            successResponse('تم جلب بيانات الطباعة', [
                'html' => $html,
                'issue' => $issue,
                'items' => $items
            ]);

        } catch (Exception $e) {
            error_log('Issue print error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/issues/export
     * تصدير إذون الصرف
     */
    public function export(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'issues.export')) {
                errorResponse('ليس لديك صلاحية لتصدير إذون الصرف', 403);
                return;
            }

            $format = $_GET['format'] ?? 'csv';
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            $issues = $this->db->query("
                SELECT 
                    i.issue_no,
                    i.issue_date,
                    w.name as warehouse,
                    r.name as recipient,
                    i.total_items,
                    i.total_quantity,
                    i.total_cost,
                    i.status,
                    u.full_name as created_by,
                    i.created_at,
                    i.delivered_date
                FROM issues i
                LEFT JOIN warehouses w ON w.id = i.warehouse_id
                LEFT JOIN recipients r ON r.id = i.recipient_id
                LEFT JOIN users u ON u.id = i.user_id
                WHERE i.issue_date BETWEEN :from_date AND :to_date
                ORDER BY i.issue_date DESC
            ", [
                'from_date' => $fromDate,
                'to_date' => $toDate
            ]);

            if ($format === 'csv') {
                $this->exportCSV($issues);
            } elseif ($format === 'excel') {
                $this->exportExcel($issues);
            } else {
                successResponse('تم جلب بيانات التصدير', $issues);
            }

        } catch (Exception $e) {
            error_log('Issue export error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على إذن صرف بالمعرف
     */
    private function getIssueById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                i.*,
                w.name as warehouse_name,
                r.name as recipient_name,
                r.type as recipient_type,
                u.full_name as user_name,
                a.full_name as approved_by_name
            FROM issues i
            LEFT JOIN warehouses w ON w.id = i.warehouse_id
            LEFT JOIN recipients r ON r.id = i.recipient_id
            LEFT JOIN users u ON u.id = i.user_id
            LEFT JOIN users a ON a.id = i.approved_by
            WHERE i.id = :id
        ", ['id' => $id]);
    }

    /**
     * توليد رقم إذن صرف
     */
    private function generateIssueNumber(): string
    {
        $prefix = 'ISS';
        $year = date('Y');
        $month = date('m');
        
        $last = $this->db->queryValue("
            SELECT MAX(CAST(SUBSTRING(issue_no, -4) AS UNSIGNED)) 
            FROM issues 
            WHERE issue_no LIKE :pattern
        ", ['pattern' => "{$prefix}{$year}{$month}%"]);

        $number = str_pad((int)$last + 1, 4, '0', STR_PAD_LEFT);
        return "{$prefix}{$year}{$month}{$number}";
    }

    /**
     * التحقق من صحة بيانات إذن الصرف
     */
    private function validateIssueData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['warehouse_id'])) {
            errorResponse('المخزن مطلوب');
            return;
        }
        
        if (empty($data['recipient_id'])) {
            errorResponse('الجهة المستلمة مطلوبة');
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
     * التحقق من تنبيهات المخزون
     */
    private function checkStockAlerts(int $warehouseId): void
    {
        // جلب الأصناف منخفضة المخزون
        $lowStockItems = $this->db->query("
            SELECT 
                p.id,
                p.name,
                p.code,
                sb.quantity,
                p.min_stock
            FROM stock_balances sb
            INNER JOIN products p ON p.id = sb.product_id
            WHERE sb.warehouse_id = :warehouse_id
              AND sb.quantity <= p.min_stock
              AND sb.quantity > 0
        ", ['warehouse_id' => $warehouseId]);

        foreach ($lowStockItems as $item) {
            $this->createNotification(
                'low_stock',
                "تنبيه: مخزون منخفض - {$item['name']}",
                "المنتج '{$item['name']}' في المخزن وصل للحد الأدنى ({$item['quantity']} / {$item['min_stock']})",
                'high',
                $item['id'],
                $warehouseId
            );
        }

        // جلب الأصناف المنفذة
        $outOfStockItems = $this->db->query("
            SELECT 
                p.id,
                p.name,
                p.code
            FROM stock_balances sb
            INNER JOIN products p ON p.id = sb.product_id
            WHERE sb.warehouse_id = :warehouse_id
              AND sb.quantity = 0
        ", ['warehouse_id' => $warehouseId]);

        foreach ($outOfStockItems as $item) {
            $this->createNotification(
                'out_of_stock',
                "⚠️ نفاذ المخزون - {$item['name']}",
                "المنتج '{$item['name']}' نفد من المخزون",
                'critical',
                $item['id'],
                $warehouseId
            );
        }
    }

    /**
     * إنشاء تنبيه
     */
    private function createNotification(string $type, string $title, string $message, string $priority, int $productId, int $warehouseId): void
    {
        // جلب المستخدمين الذين يحتاجون التنبيه
        $users = $this->db->query("
            SELECT id FROM users 
            WHERE is_active = 1 
              AND role_id IN (SELECT id FROM roles WHERE name IN ('admin', 'warehouse_manager', 'warehouse_supervisor'))
        ");

        foreach ($users as $user) {
            $this->db->insert('notifications', [
                'user_id' => $user['id'],
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'priority' => $priority,
                'reference_type' => 'product',
                'reference_id' => $productId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
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
        header('Content-Disposition: attachment; filename="issues_' . date('Y-m-d') . '.csv"');
        
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
        header('Content-Disposition: attachment; filename="issues_' . date('Y-m-d') . '.xls"');
        
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
    private function generateIssuePrintHTML(array $issue, array $items): string
    {
        $html = '<!DOCTYPE html>
        <html dir="rtl" lang="ar">
        <head>
            <meta charset="UTF-8">
            <title>إذن صرف #' . $issue['issue_no'] . '</title>
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
                .status-approved { color: #28a745; }
                .status-draft { color: #ffc107; }
                .status-rejected { color: #dc3545; }
                .status-delivered { color: #17a2b8; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>إذن صرف</h1>
                <p>' . $issue['issue_no'] . '</p>
            </div>
            
            <div class="info-grid">
                <div class="info-item"><span class="label">المخزن:</span> ' . $issue['warehouse_name'] . '</div>
                <div class="info-item"><span class="label">الجهة المستلمة:</span> ' . $issue['recipient_name'] . '</div>
                <div class="info-item"><span class="label">نوع الجهة:</span> ' . $issue['recipient_type'] . '</div>
                <div class="info-item"><span class="label">التاريخ:</span> ' . $issue['issue_date'] . '</div>
                <div class="info-item"><span class="label">الحالة:</span> <span class="status-' . $issue['status'] . '">' . $issue['status_label'] . '</span></div>
                <div class="info-item"><span class="label">تم الإنشاء بواسطة:</span> ' . $issue['user_name'] . '</div>
                <div class="info-item"><span class="label">تاريخ الإنشاء:</span> ' . $issue['created_at'] . '</div>
                ' . (!empty($issue['department']) ? '<div class="info-item"><span class="label">القسم:</span> ' . $issue['department'] . '</div>' : '') . '
                ' . (!empty($issue['project_code']) ? '<div class="info-item"><span class="label">المشروع:</span> ' . $issue['project_code'] . '</div>' : '') . '
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
                        <td>' . number_format($issue['total_cost'], 2) . '</td>
                    </tr>
                </tfoot>
            </table>
            
            ' . (!empty($issue['notes']) ? '<div style="margin: 20px 0; padding: 10px; background: #f8f9fa; border-radius: 5px;"><strong>ملاحظات:</strong> ' . $issue['notes'] . '</div>' : '') . '
            
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

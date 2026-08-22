<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/ReturnController.php
// الوصف: متحكم إدارة المرتجعات - إنشاء، اعتماد، رفض، إلغاء، طباعة
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Services\StockService;
use Exception;

class ReturnController
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
     * GET /api/returns
     * جلب قائمة المرتجعات مع فلترة وبحث
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'returns.view')) {
                errorResponse('ليس لديك صلاحية لعرض المرتجعات', 403);
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
                $where[] = "r.return_no LIKE :search";
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
            
            if (!empty($type)) {
                $where[] = "r.return_type = :type";
                $params['type'] = $type;
            }
            
            if (!empty($fromDate)) {
                $where[] = "r.return_date >= :from_date";
                $params['from_date'] = $fromDate;
            }
            
            if (!empty($toDate)) {
                $where[] = "r.return_date <= :to_date";
                $params['to_date'] = $toDate;
            }

            $allowedSorts = ['id', 'return_no', 'return_date', 'total_quantity', 'total_cost', 'status', 'created_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'created_at';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب المرتجعات
            $returns = $this->db->query("
                SELECT 
                    r.id,
                    r.return_no,
                    r.return_type,
                    CASE 
                        WHEN r.return_type = 'to_supplier' THEN 'إلى المورد'
                        WHEN r.return_type = 'from_customer' THEN 'من العميل'
                        WHEN r.return_type = 'internal' THEN 'داخلي'
                        ELSE r.return_type
                    END as return_type_label,
                    r.warehouse_id,
                    w.name as warehouse_name,
                    r.reference_type,
                    r.reference_id,
                    r.return_date,
                    r.return_time,
                    r.total_items,
                    r.total_quantity,
                    r.total_cost,
                    r.reason,
                    r.status,
                    r.user_id,
                    u.full_name as user_name,
                    r.approved_by,
                    a.full_name as approved_by_name,
                    r.approved_at,
                    r.created_at,
                    r.updated_at,
                    r.notes,
                    (SELECT COUNT(*) FROM return_items WHERE return_id = r.id) as items_count,
                    CASE 
                        WHEN r.status = 'draft' THEN 'مسودة'
                        WHEN r.status = 'submitted' THEN 'مرسل'
                        WHEN r.status = 'approved' THEN 'معتمد'
                        WHEN r.status = 'rejected' THEN 'مرفوض'
                        WHEN r.status = 'cancelled' THEN 'ملغي'
                        ELSE r.status
                    END as status_label,
                    CASE 
                        WHEN r.status = 'approved' THEN 'success'
                        WHEN r.status = 'rejected' THEN 'danger'
                        WHEN r.status = 'cancelled' THEN 'secondary'
                        WHEN r.status = 'draft' THEN 'warning'
                        WHEN r.status = 'submitted' THEN 'info'
                        ELSE 'secondary'
                    END as status_color,
                    CASE 
                        WHEN r.return_type = 'to_supplier' THEN 
                            (SELECT receipt_no FROM receipts WHERE id = r.reference_id)
                        WHEN r.return_type = 'from_customer' THEN 
                            (SELECT issue_no FROM issues WHERE id = r.reference_id)
                        ELSE NULL
                    END as reference_number
                FROM returns r
                LEFT JOIN warehouses w ON w.id = r.warehouse_id
                LEFT JOIN users u ON u.id = r.user_id
                LEFT JOIN users a ON a.id = r.approved_by
                WHERE 1=1
                " . (!empty($where) ? 'AND ' . implode(' AND ', $where) : '') . "
                ORDER BY r.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            // إجمالي المرتجعات
            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM returns r
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
                    COUNT(CASE WHEN return_type = 'to_supplier' THEN 1 END) as to_supplier,
                    COUNT(CASE WHEN return_type = 'from_customer' THEN 1 END) as from_customer,
                    COUNT(CASE WHEN return_type = 'internal' THEN 1 END) as internal,
                    COALESCE(SUM(total_cost), 0) as total_value,
                    COALESCE(SUM(total_quantity), 0) as total_quantity
                FROM returns
            ");

            successResponse('تم جلب قائمة المرتجعات', [
                'data' => $returns,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'draft' => (int)($stats['draft'] ?? 0),
                    'submitted' => (int)($stats['submitted'] ?? 0),
                    'approved' => (int)($stats['approved'] ?? 0),
                    'rejected' => (int)($stats['rejected'] ?? 0),
                    'cancelled' => (int)($stats['cancelled'] ?? 0),
                    'to_supplier' => (int)($stats['to_supplier'] ?? 0),
                    'from_customer' => (int)($stats['from_customer'] ?? 0),
                    'internal' => (int)($stats['internal'] ?? 0),
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
            error_log('Returns list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/returns/{id}
     * جلب بيانات مرتجع مع تفاصيل كاملة
     */
    public function show(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'returns.view')) {
                errorResponse('ليس لديك صلاحية لعرض المرتجعات', 403);
                return;
            }

            $return = $this->getReturnById($id);
            
            if (!$return) {
                errorResponse('المرتجع غير موجود');
                return;
            }

            // جلب تفاصيل المرتجع
            $items = $this->db->query("
                SELECT 
                    ri.*,
                    p.code as product_code,
                    p.name as product_name,
                    p.barcode,
                    u.name as unit_name,
                    u.symbol as unit_symbol,
                    COALESCE(sb.quantity, 0) as current_balance
                FROM return_items ri
                INNER JOIN products p ON p.id = ri.product_id
                LEFT JOIN units u ON u.id = p.unit_id
                LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = (SELECT warehouse_id FROM returns WHERE id = :return_id)
                WHERE ri.return_id = :return_id
            ", ['return_id' => $id]);

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
                WHERE al.reference_type = 'return'
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
                FROM return_status_history
                WHERE return_id = :return_id
                ORDER BY created_at ASC
            ", ['return_id' => $id]);

            successResponse('تم جلب بيانات المرتجع', [
                'return' => $return,
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
            error_log('Return show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/returns
     * إنشاء مرتجع جديد
     */
    public function create(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'returns.create')) {
                errorResponse('ليس لديك صلاحية لإنشاء مرتجعات', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateReturnData($input);

            // التحقق من المرجع (للمرتجعات للمورد أو من العميل)
            if (in_array($input['return_type'], ['to_supplier', 'from_customer'])) {
                $referenceExists = $this->checkReferenceExists(
                    $input['reference_type'],
                    $input['reference_id']
                );

                if (!$referenceExists) {
                    errorResponse('المرجع غير موجود');
                    return;
                }
            }

            // توليد رقم المرتجع
            $returnNo = $this->generateReturnNumber();

            // بدء المعاملة
            $this->db->beginTransaction();

            // إنشاء المرتجع
            $data = [
                'return_no' => $returnNo,
                'return_type' => $input['return_type'],
                'warehouse_id' => $input['warehouse_id'],
                'reference_type' => $input['reference_type'] ?? null,
                'reference_id' => $input['reference_id'] ?? null,
                'return_date' => $input['return_date'] ?? date('Y-m-d'),
                'return_time' => $input['return_time'] ?? date('H:i:s'),
                'reason' => $input['reason'] ?? null,
                'notes' => $input['notes'] ?? null,
                'status' => 'draft',
                'user_id' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $returnId = $this->db->insert('returns', $data);

            // حفظ تفاصيل المرتجع
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($input['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->db->insert('return_items', [
                    'return_id' => $returnId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'batch_number' => $item['batch_number'] ?? null,
                    'serial_numbers' => $item['serial_numbers'] ?? null,
                    'notes' => $item['notes'] ?? null
                ]);

                // إذا كان مرتجع للمورد، يجب التأكد من توفر الكمية
                if ($input['return_type'] === 'to_supplier') {
                    $balance = $this->db->queryValue("
                        SELECT COALESCE(quantity, 0) 
                        FROM stock_balances 
                        WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                    ", [
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $input['warehouse_id']
                    ]);

                    if ($balance < $item['quantity']) {
                        $this->db->rollback();
                        errorResponse("الكمية غير متوفرة للصنف (المتاح: {$balance})");
                        return;
                    }
                }
            }

            // تحديث إجماليات المرتجع
            $this->db->update('returns', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $returnId]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RETURN_CREATED',
                'returns',
                "إنشاء مرتجع #{$returnNo}",
                [
                    'return_id' => $returnId,
                    'return_no' => $returnNo,
                    'return_type' => $input['return_type'],
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'return',
                $returnId
            );

            $this->db->commit();

            successResponse('تم إنشاء المرتجع بنجاح', [
                'return_id' => $returnId,
                'return_no' => $returnNo
            ]);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Return create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/returns/{id}
     * تحديث مرتجع (فقط في حالة المسودة)
     */
    public function update(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'returns.edit')) {
                errorResponse('ليس لديك صلاحية لتعديل المرتجعات', 403);
                return;
            }

            $return = $this->getReturnById($id);
            if (!$return) {
                errorResponse('المرتجع غير موجود');
                return;
            }

            // التحقق من الحالة (فقط المسودة يمكن تعديلها)
            if ($return['status'] !== 'draft') {
                errorResponse('لا يمكن تعديل المرتجع بعد اعتماده أو رفضه');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateReturnData($input, true);

            // بدء المعاملة
            $this->db->beginTransaction();

            // حذف التفاصيل القديمة
            $this->db->delete('return_items', ['return_id' => $id]);

            // تحديث بيانات المرتجع
            $data = [
                'return_type' => $input['return_type'] ?? $return['return_type'],
                'warehouse_id' => $input['warehouse_id'] ?? $return['warehouse_id'],
                'reference_type' => $input['reference_type'] ?? $return['reference_type'],
                'reference_id' => $input['reference_id'] ?? $return['reference_id'],
                'return_date' => $input['return_date'] ?? $return['return_date'],
                'return_time' => $input['return_time'] ?? $return['return_time'],
                'reason' => $input['reason'] ?? $return['reason'],
                'notes' => $input['notes'] ?? $return['notes'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('returns', $data, ['id' => $id]);

            // إضافة التفاصيل الجديدة
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($input['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->db->insert('return_items', [
                    'return_id' => $id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'batch_number' => $item['batch_number'] ?? null,
                    'serial_numbers' => $item['serial_numbers'] ?? null,
                    'notes' => $item['notes'] ?? null
                ]);

                // إذا كان مرتجع للمورد، يجب التأكد من توفر الكمية
                if (($input['return_type'] ?? $return['return_type']) === 'to_supplier') {
                    $balance = $this->db->queryValue("
                        SELECT COALESCE(quantity, 0) 
                        FROM stock_balances 
                        WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                    ", [
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $input['warehouse_id'] ?? $return['warehouse_id']
                    ]);

                    if ($balance < $item['quantity']) {
                        $this->db->rollback();
                        errorResponse("الكمية غير متوفرة للصنف (المتاح: {$balance})");
                        return;
                    }
                }
            }

            // تحديث الإجماليات
            $this->db->update('returns', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RETURN_UPDATED',
                'returns',
                "تحديث مرتجع #{$return['return_no']}",
                [
                    'return_id' => $id,
                    'return_no' => $return['return_no'],
                    'items_count' => $totalItems
                ],
                'return',
                $id
            );

            $this->db->commit();

            successResponse('تم تحديث المرتجع بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Return update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/returns/{id}/approve
     * اعتماد مرتجع (تنفيذ الحركات)
     */
    public function approve(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'returns.approve')) {
                errorResponse('ليس لديك صلاحية لاعتماد المرتجعات', 403);
                return;
            }

            $return = $this->getReturnById($id);
            if (!$return) {
                errorResponse('المرتجع غير موجود');
                return;
            }

            // التحقق من الحالة
            if ($return['status'] === 'approved') {
                errorResponse('المرتجع معتمد بالفعل');
                return;
            }

            if ($return['status'] === 'rejected' || $return['status'] === 'cancelled') {
                errorResponse('لا يمكن اعتماد مرتجع مرفوض أو ملغي');
                return;
            }

            // جلب تفاصيل المرتجع
            $items = $this->db->query(
                "SELECT * FROM return_items WHERE return_id = :return_id",
                ['return_id' => $id]
            );

            if (empty($items)) {
                errorResponse('لا توجد أصناف في المرتجع');
                return;
            }

            // بدء المعاملة
            $this->db->beginTransaction();

            // تنفيذ حركات المخزون حسب نوع المرتجع
            foreach ($items as $item) {
                // جلب الرصيد الحالي
                $currentBalance = $this->db->queryValue("
                    SELECT COALESCE(quantity, 0) 
                    FROM stock_balances 
                    WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                ", [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $return['warehouse_id']
                ]);

                $movementType = '';
                $newBalance = 0;

                if ($return['return_type'] === 'to_supplier') {
                    // مرتجع للمورد - خصم من المخزون
                    $movementType = 'RETURN_OUT';
                    $newBalance = $currentBalance - $item['quantity'];
                    
                    if ($newBalance < 0) {
                        $this->db->rollback();
                        errorResponse("الكمية غير كافية للصنف (المتاح: {$currentBalance})");
                        return;
                    }
                } else {
                    // مرتجع من العميل أو داخلي - إضافة للمخزون
                    $movementType = 'RETURN_IN';
                    $newBalance = $currentBalance + $item['quantity'];
                }

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
                    'warehouse_id' => $return['warehouse_id'],
                    'quantity' => $newBalance
                ]);

                // تسجيل حركة المخزون
                $this->db->insert('stock_movements', [
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $return['warehouse_id'],
                    'movement_type' => $movementType,
                    'reference_type' => 'return',
                    'reference_id' => $id,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $item['quantity'] * $item['unit_cost'],
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance,
                    'movement_date' => date('Y-m-d H:i:s'),
                    'user_id' => $userId,
                    'notes' => "مرتجع #{$return['return_no']} - {$return['return_type_label']}"
                ]);
            }

            // تحديث حالة المرتجع
            $this->db->update('returns', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RETURN_APPROVED',
                'returns',
                "اعتماد مرتجع #{$return['return_no']}",
                [
                    'return_id' => $id,
                    'return_no' => $return['return_no'],
                    'return_type' => $return['return_type'],
                    'items_count' => count($items)
                ],
                'return',
                $id
            );

            $this->db->commit();

            // التحقق من التنبيهات
            $this->checkStockAlerts($return['warehouse_id']);

            successResponse('تم اعتماد المرتجع بنجاح');

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Return approve error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/returns/{id}/reject
     * رفض مرتجع
     */
    public function reject(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'returns.approve')) {
                errorResponse('ليس لديك صلاحية لرفض المرتجعات', 403);
                return;
            }

            $return = $this->getReturnById($id);
            if (!$return) {
                errorResponse('المرتجع غير موجود');
                return;
            }

            if ($return['status'] === 'rejected') {
                errorResponse('المرتجع مرفوض بالفعل');
                return;
            }

            if ($return['status'] === 'approved') {
                errorResponse('لا يمكن رفض مرتجع معتمد');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // تحديث الحالة
            $this->db->update('returns', [
                'status' => 'rejected',
                'rejected_by' => $userId,
                'rejected_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $input['reason'] ?? null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RETURN_REJECTED',
                'returns',
                "رفض مرتجع #{$return['return_no']}",
                [
                    'return_id' => $id,
                    'return_no' => $return['return_no'],
                    'reason' => $input['reason'] ?? null
                ],
                'return',
                $id
            );

            successResponse('تم رفض المرتجع بنجاح');

        } catch (Exception $e) {
            error_log('Return reject error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/returns/{id}/cancel
     * إلغاء مرتجع
     */
    public function cancel(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'returns.cancel')) {
                errorResponse('ليس لديك صلاحية لإلغاء المرتجعات', 403);
                return;
            }

            $return = $this->getReturnById($id);
            if (!$return) {
                errorResponse('المرتجع غير موجود');
                return;
            }

            if ($return['status'] === 'cancelled') {
                errorResponse('المرتجع ملغي بالفعل');
                return;
            }

            if ($return['status'] === 'approved') {
                errorResponse('لا يمكن إلغاء مرتجع معتمد');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // تحديث الحالة
            $this->db->update('returns', [
                'status' => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RETURN_CANCELLED',
                'returns',
                "إلغاء مرتجع #{$return['return_no']}",
                [
                    'return_id' => $id,
                    'return_no' => $return['return_no'],
                    'reason' => $input['reason'] ?? null
                ],
                'return',
                $id
            );

            successResponse('تم إلغاء المرتجع بنجاح');

        } catch (Exception $e) {
            error_log('Return cancel error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/returns/{id}/print
     * طباعة مرتجع
     */
    public function print(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'returns.view')) {
                errorResponse('ليس لديك صلاحية لعرض المرتجعات', 403);
                return;
            }

            $return = $this->getReturnById($id);
            if (!$return) {
                errorResponse('المرتجع غير موجود');
                return;
            }

            // جلب تفاصيل المرتجع
            $items = $this->db->query("
                SELECT 
                    ri.*,
                    p.code as product_code,
                    p.name as product_name,
                    u.name as unit_name
                FROM return_items ri
                INNER JOIN products p ON p.id = ri.product_id
                LEFT JOIN units u ON u.id = p.unit_id
                WHERE ri.return_id = :return_id
            ", ['return_id' => $id]);

            // HTML للطباعة
            $html = $this->generateReturnPrintHTML($return, $items);
            
            successResponse('تم جلب بيانات الطباعة', [
                'html' => $html,
                'return' => $return,
                'items' => $items
            ]);

        } catch (Exception $e) {
            error_log('Return print error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/returns/export
     * تصدير المرتجعات
     */
    public function export(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'returns.export')) {
                errorResponse('ليس لديك صلاحية لتصدير المرتجعات', 403);
                return;
            }

            $format = $_GET['format'] ?? 'csv';
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            $returns = $this->db->query("
                SELECT 
                    r.return_no,
                    r.return_date,
                    CASE 
                        WHEN r.return_type = 'to_supplier' THEN 'إلى المورد'
                        WHEN r.return_type = 'from_customer' THEN 'من العميل'
                        WHEN r.return_type = 'internal' THEN 'داخلي'
                        ELSE r.return_type
                    END as return_type,
                    w.name as warehouse,
                    r.total_items,
                    r.total_quantity,
                    r.total_cost,
                    r.reason,
                    r.status,
                    u.full_name as created_by,
                    r.created_at
                FROM returns r
                LEFT JOIN warehouses w ON w.id = r.warehouse_id
                LEFT JOIN users u ON u.id = r.user_id
                WHERE r.return_date BETWEEN :from_date AND :to_date
                ORDER BY r.return_date DESC
            ", [
                'from_date' => $fromDate,
                'to_date' => $toDate
            ]);

            if ($format === 'csv') {
                $this->exportCSV($returns);
            } elseif ($format === 'excel') {
                $this->exportExcel($returns);
            } else {
                successResponse('تم جلب بيانات التصدير', $returns);
            }

        } catch (Exception $e) {
            error_log('Return export error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على مرتجع بالمعرف
     */
    private function getReturnById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                r.*,
                w.name as warehouse_name,
                u.full_name as user_name,
                a.full_name as approved_by_name,
                CASE 
                    WHEN r.reference_type = 'receipt' THEN 
                        (SELECT receipt_no FROM receipts WHERE id = r.reference_id)
                    WHEN r.reference_type = 'issue' THEN 
                        (SELECT issue_no FROM issues WHERE id = r.reference_id)
                    ELSE NULL
                END as reference_number
            FROM returns r
            LEFT JOIN warehouses w ON w.id = r.warehouse_id
            LEFT JOIN users u ON u.id = r.user_id
            LEFT JOIN users a ON a.id = r.approved_by
            WHERE r.id = :id
        ", ['id' => $id]);
    }

    /**
     * توليد رقم مرتجع
     */
    private function generateReturnNumber(): string
    {
        $prefix = 'RET';
        $year = date('Y');
        $month = date('m');
        
        $last = $this->db->queryValue("
            SELECT MAX(CAST(SUBSTRING(return_no, -4) AS UNSIGNED)) 
            FROM returns 
            WHERE return_no LIKE :pattern
        ", ['pattern' => "{$prefix}{$year}{$month}%"]);

        $number = str_pad((int)$last + 1, 4, '0', STR_PAD_LEFT);
        return "{$prefix}{$year}{$month}{$number}";
    }

    /**
     * التحقق من وجود المرجع
     */
    private function checkReferenceExists(string $referenceType, int $referenceId): bool
    {
        $table = $referenceType === 'receipt' ? 'receipts' : 'issues';
        $exists = $this->db->queryValue(
            "SELECT id FROM {$table} WHERE id = :id",
            ['id' => $referenceId]
        );
        return (bool)$exists;
    }

    /**
     * التحقق من صحة بيانات المرتجع
     */
    private function validateReturnData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['return_type'])) {
            errorResponse('نوع المرتجع مطلوب');
            return;
        }
        
        $allowedTypes = ['to_supplier', 'from_customer', 'internal'];
        if (!in_array($data['return_type'], $allowedTypes)) {
            errorResponse('نوع المرتجع غير صحيح');
            return;
        }
        
        if (empty($data['warehouse_id'])) {
            errorResponse('المخزن مطلوب');
            return;
        }
        
        if (in_array($data['return_type'], ['to_supplier', 'from_customer'])) {
            if (empty($data['reference_type'])) {
                errorResponse('نوع المرجع مطلوب');
                return;
            }
            
            $allowedRefTypes = ['receipt', 'issue'];
            if (!in_array($data['reference_type'], $allowedRefTypes)) {
                errorResponse('نوع المرجع غير صحيح');
                return;
            }
            
            if (empty($data['reference_id'])) {
                errorResponse('المرجع مطلوب');
                return;
            }
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
        header('Content-Disposition: attachment; filename="returns_' . date('Y-m-d') . '.csv"');
        
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
        header('Content-Disposition: attachment; filename="returns_' . date('Y-m-d') . '.xls"');
        
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
    private function generateReturnPrintHTML(array $return, array $items): string
    {
        $html = '<!DOCTYPE html>
        <html dir="rtl" lang="ar">
        <head>
            <meta charset="UTF-8">
            <title>مرتجع #' . $return['return_no'] . '</title>
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
            </style>
        </head>
        <body>
            <div class="header">
                <h1>إذن مرتجع</h1>
                <p>' . $return['return_no'] . '</p>
            </div>
            
            <div class="info-grid">
                <div class="info-item"><span class="label">نوع المرتجع:</span> ' . $return['return_type_label'] . '</div>
                <div class="info-item"><span class="label">المخزن:</span> ' . $return['warehouse_name'] . '</div>
                <div class="info-item"><span class="label">التاريخ:</span> ' . $return['return_date'] . '</div>
                <div class="info-item"><span class="label">الحالة:</span> <span class="status-' . $return['status'] . '">' . $return['status_label'] . '</span></div>
                ' . (!empty($return['reference_number']) ? '<div class="info-item"><span class="label">المرجع:</span> ' . $return['reference_number'] . '</div>' : '') . '
                <div class="info-item"><span class="label">تم الإنشاء بواسطة:</span> ' . $return['user_name'] . '</div>
                <div class="info-item"><span class="label">تاريخ الإنشاء:</span> ' . $return['created_at'] . '</div>
                ' . (!empty($return['reason']) ? '<div class="info-item"><span class="label">سبب المرتجع:</span> ' . $return['reason'] . '</div>' : '') . '
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
                        <td>' . number_format($return['total_cost'], 2) . '</td>
                    </tr>
                </tfoot>
            </table>
            
            ' . (!empty($return['notes']) ? '<div style="margin: 20px 0; padding: 10px; background: #f8f9fa; border-radius: 5px;"><strong>ملاحظات:</strong> ' . $return['notes'] . '</div>' : '') . '
            
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

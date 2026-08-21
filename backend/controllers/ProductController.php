<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/controllers/ProductController.php
// الوصف: متحكم إدارة الأصناف - CRUD كامل مع ميزات متقدمة
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;

class ProductController
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

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        $this->audit = new Audit();
    }

    /**
     * GET /api/products
     * جلب قائمة الأصناف مع فلترة وبحث متقدم
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // معاملات الصفحة
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            // معاملات البحث والفلترة
            $search = $_GET['search'] ?? '';
            $category = $_GET['category'] ?? '';
            $warehouse = $_GET['warehouse'] ?? '';
            $status = $_GET['status'] ?? '';
            $minStock = $_GET['min_stock'] ?? '';
            $maxStock = $_GET['max_stock'] ?? '';
            $sort = $_GET['sort'] ?? 'created_at';
            $order = $_GET['order'] ?? 'DESC';
            $view = $_GET['view'] ?? 'list'; // list, tree, cards, icons

            // بناء شروط البحث
            $params = [];
            $where = [];
            
            if (!empty($search)) {
                $where[] = "(p.code LIKE :search OR p.name LIKE :search OR p.barcode LIKE :search)";
                $params['search'] = "%{$search}%";
            }
            
            if (!empty($category)) {
                $where[] = "p.category_id = :category";
                $params['category'] = $category;
            }
            
            if (!empty($warehouse)) {
                $where[] = "EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = p.id AND sb.warehouse_id = :warehouse)";
                $params['warehouse'] = $warehouse;
            }
            
            if ($status === 'active') {
                $where[] = "p.is_active = 1";
            } elseif ($status === 'inactive') {
                $where[] = "p.is_active = 0";
            } elseif ($status === 'low_stock') {
                $where[] = "EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = p.id AND sb.quantity <= p.min_stock AND sb.quantity > 0)";
            } elseif ($status === 'out_of_stock') {
                $where[] = "EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = p.id AND sb.quantity = 0)";
            } elseif ($status === 'over_stock') {
                $where[] = "EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = p.id AND sb.quantity >= p.max_stock)";
            }
            
            if (!empty($minStock)) {
                $where[] = "EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = p.id AND sb.quantity >= :min_stock)";
                $params['min_stock'] = $minStock;
            }
            
            if (!empty($maxStock)) {
                $where[] = "EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = p.id AND sb.quantity <= :max_stock)";
                $params['max_stock'] = $maxStock;
            }
            
            $where[] = "p.deleted_at IS NULL";
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // الحقول المسموح بالترتيب بها
            $allowedSorts = ['id', 'code', 'name', 'cost_price', 'selling_price', 'min_stock', 'created_at', 'updated_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'created_at';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب الأصناف
            $products = $this->db->query("
                SELECT 
                    p.id,
                    p.code,
                    p.barcode,
                    p.name,
                    p.description,
                    p.category_id,
                    c.name as category_name,
                    p.unit_id,
                    u.name as unit_name,
                    u.symbol as unit_symbol,
                    p.min_stock,
                    p.max_stock,
                    p.reorder_point,
                    p.reorder_quantity,
                    p.cost_price,
                    p.selling_price,
                    p.last_purchase_price,
                    p.weight,
                    p.dimensions,
                    p.is_active,
                    p.is_serialized,
                    p.is_batch_tracked,
                    p.is_expirable,
                    p.shelf_life_days,
                    p.warranty_days,
                    p.tax_rate,
                    p.notes,
                    p.created_at,
                    p.updated_at,
                    (SELECT COALESCE(SUM(sb.quantity), 0) FROM stock_balances sb WHERE sb.product_id = p.id) as total_quantity,
                    (SELECT COALESCE(SUM(sb.reserved_quantity), 0) FROM stock_balances sb WHERE sb.product_id = p.id) as total_reserved,
                    (SELECT COALESCE(SUM(sb.quantity * p.cost_price), 0) FROM stock_balances sb WHERE sb.product_id = p.id) as total_value,
                    (SELECT COUNT(DISTINCT sb.warehouse_id) FROM stock_balances sb WHERE sb.product_id = p.id AND sb.quantity > 0) as warehouses_count
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN units u ON u.id = p.unit_id
                {$whereClause}
                ORDER BY p.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            // إجمالي الأصناف
            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM products p
                {$whereClause}
            ", $params);

            // إحصائيات إضافية
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive,
                    COUNT(CASE WHEN EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = p.id AND sb.quantity <= p.min_stock AND sb.quantity > 0) THEN 1 END) as low_stock,
                    COUNT(CASE WHEN EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = p.id AND sb.quantity = 0) THEN 1 END) as out_of_stock,
                    COUNT(CASE WHEN EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.product_id = p.id AND sb.quantity >= p.max_stock) THEN 1 END) as over_stock
                FROM products p
                WHERE p.deleted_at IS NULL
            ");

            // بيانات إضافية حسب نوع العرض
            $extraData = [];
            if ($view === 'tree') {
                $extraData['tree'] = $this->buildProductTree($products);
            } elseif ($view === 'cards') {
                $extraData['cards'] = $this->formatCards($products);
            }

            successResponse('تم جلب قائمة الأصناف', [
                'data' => $products,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'active' => (int)($stats['active'] ?? 0),
                    'inactive' => (int)($stats['inactive'] ?? 0),
                    'low_stock' => (int)($stats['low_stock'] ?? 0),
                    'out_of_stock' => (int)($stats['out_of_stock'] ?? 0),
                    'over_stock' => (int)($stats['over_stock'] ?? 0)
                ],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil((int)$total / $limit)
                ],
                'extra' => $extraData
            ]);

        } catch (\Exception $e) {
            error_log('Products list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/products/{id}
     * جلب بيانات صنف مع تفاصيل كاملة
     */
    public function show(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $product = $this->getProductById($id);
            
            if (!$product) {
                errorResponse('الصنف غير موجود');
                return;
            }

            // جلب الأرصدة في جميع المخازن
            $balances = $this->db->query("
                SELECT 
                    w.id as warehouse_id,
                    w.name as warehouse_name,
                    w.code as warehouse_code,
                    COALESCE(sb.quantity, 0) as quantity,
                    COALESCE(sb.reserved_quantity, 0) as reserved_quantity,
                    COALESCE(sb.quantity - sb.reserved_quantity, 0) as available_quantity,
                    COALESCE(sb.quantity * p.cost_price, 0) as total_value,
                    sb.last_movement_date,
                    sb.updated_at
                FROM warehouses w
                LEFT JOIN stock_balances sb ON sb.warehouse_id = w.id AND sb.product_id = :product_id
                LEFT JOIN products p ON p.id = :product_id
                WHERE w.is_active = 1 AND w.deleted_at IS NULL
                ORDER BY w.is_main DESC, w.name
            ", ['product_id' => $id]);

            // جلب آخر الحركات
            $movements = $this->db->query("
                SELECT 
                    sm.id,
                    sm.movement_type,
                    CASE sm.movement_type
                        WHEN 'RECEIPT' THEN 'استلام'
                        WHEN 'ISSUE' THEN 'صرف'
                        WHEN 'TRANSFER_OUT' THEN 'تحويل خارج'
                        WHEN 'TRANSFER_IN' THEN 'تحويل داخل'
                        WHEN 'RETURN_IN' THEN 'مرتجع للمخزن'
                        WHEN 'RETURN_OUT' THEN 'مرتجع من المخزن'
                        WHEN 'ADJUSTMENT' THEN 'تسوية'
                        ELSE sm.movement_type
                    END as movement_label,
                    w.name as warehouse_name,
                    sm.quantity,
                    sm.unit_cost,
                    sm.total_cost,
                    sm.balance_before,
                    sm.balance_after,
                    sm.movement_date,
                    u.full_name as user_name,
                    sm.reference_type,
                    sm.reference_id
                FROM stock_movements sm
                INNER JOIN warehouses w ON w.id = sm.warehouse_id
                INNER JOIN users u ON u.id = sm.user_id
                WHERE sm.product_id = :product_id
                ORDER BY sm.movement_date DESC
                LIMIT 50
            ", ['product_id' => $id]);

            // جلب سجل التدقيق للصنف
            $audits = $this->db->query("
                SELECT 
                    created_at,
                    user_id,
                    (SELECT full_name FROM users WHERE id = user_id) as user_name,
                    action,
                    description
                FROM audit_logs
                WHERE reference_type = 'product'
                  AND reference_id = :reference_id
                ORDER BY created_at DESC
                LIMIT 20
            ", ['reference_id' => $id]);

            // جلب الدفعات (إذا كان الصنف يتتبع الدفعات)
            $batches = [];
            if ($product['is_batch_tracked']) {
                $batches = $this->db->query("
                    SELECT 
                        id,
                        batch_number,
                        quantity,
                        unit_cost,
                        manufacture_date,
                        expiry_date,
                        received_at,
                        is_active,
                        notes
                    FROM product_batches
                    WHERE product_id = :product_id
                    ORDER BY expiry_date ASC, received_at DESC
                ", ['product_id' => $id]);
            }

            // جلب الأرقام التسلسلية (إذا كان الصنف متسلسل)
            $serials = [];
            if ($product['is_serialized']) {
                $serials = $this->db->query("
                    SELECT 
                        id,
                        serial_number,
                        warehouse_id,
                        (SELECT name FROM warehouses WHERE id = warehouse_id) as warehouse_name,
                        status,
                        cost_price,
                        sale_price,
                        purchase_date,
                        sale_date,
                        warranty_end_date,
                        notes
                    FROM product_serials
                    WHERE product_id = :product_id
                    ORDER BY status, serial_number
                ", ['product_id' => $id]);
            }

            successResponse('تم جلب بيانات الصنف', [
                'product' => $product,
                'balances' => $balances,
                'recent_movements' => $movements,
                'audits' => $audits,
                'batches' => $batches,
                'serials' => $serials
            ]);

        } catch (\Exception $e) {
            error_log('Product show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/products
     * إنشاء صنف جديد
     */
    public function create(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateProductData($input);

            // التحقق من الكود
            if (!empty($input['code'])) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM products WHERE code = :code",
                    ['code' => $input['code']]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            } else {
                // توليد كود تلقائي
                $input['code'] = $this->generateProductCode();
            }

            // التحقق من الباركود
            if (!empty($input['barcode'])) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM products WHERE barcode = :barcode",
                    ['barcode' => $input['barcode']]
                );
                
                if ($exists) {
                    errorResponse('الباركود مستخدم بالفعل');
                    return;
                }
            }

            // إنشاء الصنف
            $data = [
                'code' => $input['code'],
                'barcode' => $input['barcode'] ?? null,
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'unit_id' => $input['unit_id'],
                'purchase_unit_id' => $input['purchase_unit_id'] ?? $input['unit_id'],
                'sale_unit_id' => $input['sale_unit_id'] ?? $input['unit_id'],
                'min_stock' => $input['min_stock'] ?? 0,
                'max_stock' => $input['max_stock'] ?? null,
                'reorder_point' => $input['reorder_point'] ?? null,
                'reorder_quantity' => $input['reorder_quantity'] ?? null,
                'cost_price' => $input['cost_price'] ?? 0,
                'selling_price' => $input['selling_price'] ?? null,
                'weight' => $input['weight'] ?? null,
                'dimensions' => $input['dimensions'] ?? null,
                'is_active' => $input['is_active'] ?? 1,
                'is_serialized' => $input['is_serialized'] ?? 0,
                'is_batch_tracked' => $input['is_batch_tracked'] ?? 0,
                'is_expirable' => $input['is_expirable'] ?? 0,
                'shelf_life_days' => $input['shelf_life_days'] ?? null,
                'warranty_days' => $input['warranty_days'] ?? null,
                'tax_rate' => $input['tax_rate'] ?? 0,
                'notes' => $input['notes'] ?? null,
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $productId = $this->db->insert('products', $data);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'PRODUCT_CREATED',
                'products',
                "إنشاء صنف جديد: {$input['name']}",
                ['product_id' => $productId, 'code' => $input['code']],
                'product',
                $productId
            );

            successResponse('تم إنشاء الصنف بنجاح', ['product_id' => $productId]);

        } catch (\Exception $e) {
            error_log('Product create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * PUT /api/products/{id}
     * تحديث بيانات صنف
     */
    public function update(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $product = $this->getProductById($id);
            if (!$product) {
                errorResponse('الصنف غير موجود');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateProductData($input, true);

            // التحقق من الكود
            if (!empty($input['code']) && $input['code'] !== $product['code']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM products WHERE code = :code AND id != :id",
                    ['code' => $input['code'], 'id' => $id]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            }

            // التحقق من الباركود
            if (!empty($input['barcode']) && $input['barcode'] !== $product['barcode']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM products WHERE barcode = :barcode AND id != :id",
                    ['barcode' => $input['barcode'], 'id' => $id]
                );
                
                if ($exists) {
                    errorResponse('الباركود مستخدم بالفعل');
                    return;
                }
            }

            // تحديث البيانات
            $data = [
                'code' => $input['code'] ?? $product['code'],
                'barcode' => $input['barcode'] ?? $product['barcode'],
                'name' => $input['name'] ?? $product['name'],
                'description' => $input['description'] ?? $product['description'],
                'category_id' => $input['category_id'] ?? $product['category_id'],
                'unit_id' => $input['unit_id'] ?? $product['unit_id'],
                'purchase_unit_id' => $input['purchase_unit_id'] ?? $product['purchase_unit_id'],
                'sale_unit_id' => $input['sale_unit_id'] ?? $product['sale_unit_id'],
                'min_stock' => $input['min_stock'] ?? $product['min_stock'],
                'max_stock' => $input['max_stock'] ?? $product['max_stock'],
                'reorder_point' => $input['reorder_point'] ?? $product['reorder_point'],
                'reorder_quantity' => $input['reorder_quantity'] ?? $product['reorder_quantity'],
                'cost_price' => $input['cost_price'] ?? $product['cost_price'],
                'selling_price' => $input['selling_price'] ?? $product['selling_price'],
                'weight' => $input['weight'] ?? $product['weight'],
                'dimensions' => $input['dimensions'] ?? $product['dimensions'],
                'is_active' => $input['is_active'] ?? $product['is_active'],
                'is_serialized' => $input['is_serialized'] ?? $product['is_serialized'],
                'is_batch_tracked' => $input['is_batch_tracked'] ?? $product['is_batch_tracked'],
                'is_expirable' => $input['is_expirable'] ?? $product['is_expirable'],
                'shelf_life_days' => $input['shelf_life_days'] ?? $product['shelf_life_days'],
                'warranty_days' => $input['warranty_days'] ?? $product['warranty_days'],
                'tax_rate' => $input['tax_rate'] ?? $product['tax_rate'],
                'notes' => $input['notes'] ?? $product['notes'],
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('products', $data, ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'PRODUCT_UPDATED',
                'products',
                "تحديث بيانات الصنف: {$product['name']}",
                ['product_id' => $id, 'code' => $product['code']],
                'product',
                $id
            );

            successResponse('تم تحديث بيانات الصنف بنجاح');

        } catch (\Exception $e) {
            error_log('Product update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/products/{id}
     * حذف صنف (حذف ناعم)
     */
    public function delete(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $product = $this->getProductById($id);
            if (!$product) {
                errorResponse('الصنف غير موجود');
                return;
            }

            // التحقق من وجود حركات للصنف
            $hasMovements = $this->db->queryValue(
                "SELECT COUNT(*) FROM stock_movements WHERE product_id = :product_id",
                ['product_id' => $id]
            );

            if ($hasMovements > 0) {
                errorResponse('لا يمكن حذف الصنف لأنه توجد له حركات مخزون');
                return;
            }

            // التحقق من وجود أرصدة
            $hasBalances = $this->db->queryValue(
                "SELECT COUNT(*) FROM stock_balances WHERE product_id = :product_id AND quantity > 0",
                ['product_id' => $id]
            );

            if ($hasBalances > 0) {
                errorResponse('لا يمكن حذف الصنف لأنه توجد له أرصدة مخزون');
                return;
            }

            // الحذف الناعم
            $this->db->softDelete('products', ['id' => $id]);

            // حذف الأرصدة
            $this->db->delete('stock_balances', ['product_id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'PRODUCT_DELETED',
                'products',
                "حذف الصنف: {$product['name']}",
                ['product_id' => $id, 'code' => $product['code']],
                'product',
                $id
            );

            successResponse('تم حذف الصنف بنجاح');

        } catch (\Exception $e) {
            error_log('Product delete error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/products/{id}/balances
     * جلب أرصدة صنف في المخازن
     */
    public function balances(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $product = $this->getProductById($id);
            if (!$product) {
                errorResponse('الصنف غير موجود');
                return;
            }

            $balances = $this->db->query("
                SELECT 
                    w.id as warehouse_id,
                    w.name as warehouse_name,
                    w.code as warehouse_code,
                    COALESCE(sb.quantity, 0) as quantity,
                    COALESCE(sb.reserved_quantity, 0) as reserved_quantity,
                    COALESCE(sb.quantity - sb.reserved_quantity, 0) as available_quantity,
                    COALESCE(sb.quantity * p.cost_price, 0) as total_value,
                    sb.last_movement_date,
                    sb.updated_at
                FROM warehouses w
                LEFT JOIN stock_balances sb ON sb.warehouse_id = w.id AND sb.product_id = :product_id
                LEFT JOIN products p ON p.id = :product_id
                WHERE w.is_active = 1 AND w.deleted_at IS NULL
                ORDER BY w.is_main DESC, w.name
            ", ['product_id' => $id]);

            successResponse('تم جلب أرصدة الصنف', $balances);

        } catch (\Exception $e) {
            error_log('Product balances error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/products/{id}/history
     * جلب تاريخ حركات صنف
     */
    public function history(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            $movements = $this->db->query("
                SELECT 
                    sm.id,
                    sm.movement_type,
                    CASE sm.movement_type
                        WHEN 'RECEIPT' THEN 'استلام'
                        WHEN 'ISSUE' THEN 'صرف'
                        WHEN 'TRANSFER_OUT' THEN 'تحويل خارج'
                        WHEN 'TRANSFER_IN' THEN 'تحويل داخل'
                        WHEN 'RETURN_IN' THEN 'مرتجع للمخزن'
                        WHEN 'RETURN_OUT' THEN 'مرتجع من المخزن'
                        WHEN 'ADJUSTMENT' THEN 'تسوية'
                        ELSE sm.movement_type
                    END as movement_label,
                    w.name as warehouse_name,
                    sm.quantity,
                    sm.unit_cost,
                    sm.total_cost,
                    sm.balance_before,
                    sm.balance_after,
                    sm.movement_date,
                    u.full_name as user_name,
                    sm.reference_type,
                    sm.reference_id,
                    sm.notes
                FROM stock_movements sm
                INNER JOIN warehouses w ON w.id = sm.warehouse_id
                INNER JOIN users u ON u.id = sm.user_id
                WHERE sm.product_id = :product_id
                  AND sm.movement_date BETWEEN :from_date AND :to_date
                ORDER BY sm.movement_date DESC
            ", [
                'product_id' => $id,
                'from_date' => $fromDate . ' 00:00:00',
                'to_date' => $toDate . ' 23:59:59'
            ]);

            // إحصائيات الحركات
            $stats = [
                'total' => count($movements),
                'total_in' => array_sum(array_filter($movements, fn($m) => in_array($m['movement_type'], ['RECEIPT', 'TRANSFER_IN', 'RETURN_IN']))),
                'total_out' => array_sum(array_filter($movements, fn($m) => in_array($m['movement_type'], ['ISSUE', 'TRANSFER_OUT', 'RETURN_OUT']))),
                'total_adjustments' => array_sum(array_filter($movements, fn($m) => $m['movement_type'] === 'ADJUSTMENT'))
            ];

            successResponse('تم جلب تاريخ حركات الصنف', [
                'data' => $movements,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            error_log('Product history error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/products/categories
     * جلب التصنيفات
     */
    public function categories(): void
    {
        try {
            $categories = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    description,
                    parent_id,
                    icon,
                    color,
                    is_active,
                    sort_order,
                    (SELECT COUNT(*) FROM products WHERE category_id = c.id AND deleted_at IS NULL) as products_count
                FROM categories c
                WHERE is_active = 1
                ORDER BY sort_order, name
            ");

            successResponse('تم جلب التصنيفات', $categories);

        } catch (\Exception $e) {
            error_log('Categories error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/products/units
     * جلب الوحدات
     */
    public function units(): void
    {
        try {
            $units = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    symbol,
                    is_base_unit,
                    conversion_factor,
                    base_unit_id,
                    precision_digits,
                    is_active
                FROM units
                WHERE is_active = 1
                ORDER BY name
            ");

            successResponse('تم جلب الوحدات', $units);

        } catch (\Exception $e) {
            error_log('Units error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/products/bulk-import
     * استيراد أصناف متعددة
     */
    public function bulkImport(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $products = $input['products'] ?? [];
            
            if (empty($products)) {
                errorResponse('لا توجد أصناف للاستيراد');
                return;
            }

            $this->db->beginTransaction();

            $imported = 0;
            $errors = [];

            foreach ($products as $product) {
                try {
                    // التحقق من البيانات
                    $this->validateProductData($product);
                    
                    // توليد كود إذا لم يوجد
                    if (empty($product['code'])) {
                        $product['code'] = $this->generateProductCode();
                    }
                    
                    // التحقق من عدم وجود كود مكرر
                    $exists = $this->db->queryValue(
                        "SELECT id FROM products WHERE code = :code",
                        ['code' => $product['code']]
                    );
                    
                    if ($exists) {
                        $errors[] = [
                            'product' => $product['name'] ?? 'غير معروف',
                            'error' => 'الكود مستخدم بالفعل'
                        ];
                        continue;
                    }
                    
                    // إنشاء الصنف
                    $data = [
                        'code' => $product['code'],
                        'barcode' => $product['barcode'] ?? null,
                        'name' => $product['name'],
                        'description' => $product['description'] ?? null,
                        'category_id' => $product['category_id'] ?? null,
                        'unit_id' => $product['unit_id'],
                        'min_stock' => $product['min_stock'] ?? 0,
                        'max_stock' => $product['max_stock'] ?? null,
                        'cost_price' => $product['cost_price'] ?? 0,
                        'selling_price' => $product['selling_price'] ?? null,
                        'is_active' => $product['is_active'] ?? 1,
                        'created_by' => $userId,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $this->db->insert('products', $data);
                    $imported++;
                    
                } catch (\Exception $e) {
                    $errors[] = [
                        'product' => $product['name'] ?? 'غير معروف',
                        'error' => $e->getMessage()
                    ];
                }
            }

            $this->db->commit();

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'PRODUCTS_BULK_IMPORT',
                'products',
                "استيراد {$imported} صنف",
                ['imported' => $imported, 'errors' => $errors]
            );

            successResponse('تم استيراد الأصناف بنجاح', [
                'imported' => $imported,
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            $this->db->rollback();
            error_log('Bulk import error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/products/export
     * تصدير الأصناف
     */
    public function export(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $format = $_GET['format'] ?? 'csv';
            $search = $_GET['search'] ?? '';
            $category = $_GET['category'] ?? '';

            $params = [];
            $where = ["p.deleted_at IS NULL"];
            
            if (!empty($search)) {
                $where[] = "(p.name LIKE :search OR p.code LIKE :search)";
                $params['search'] = "%{$search}%";
            }
            
            if (!empty($category)) {
                $where[] = "p.category_id = :category";
                $params['category'] = $category;
            }

            $products = $this->db->query("
                SELECT 
                    p.code,
                    p.barcode,
                    p.name,
                    p.description,
                    c.name as category,
                    u.name as unit,
                    p.min_stock,
                    p.max_stock,
                    p.cost_price,
                    p.selling_price,
                    p.is_active,
                    (SELECT COALESCE(SUM(sb.quantity), 0) FROM stock_balances sb WHERE sb.product_id = p.id) as total_quantity
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN units u ON u.id = p.unit_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.code
            ", $params);

            if ($format === 'csv') {
                $this->exportCSV($products);
            } elseif ($format === 'excel') {
                $this->exportExcel($products);
            } else {
                successResponse('تم جلب بيانات التصدير', $products);
            }

        } catch (\Exception $e) {
            error_log('Export error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/products/{id}/barcode
     * طباعة باركود
     */
    public function barcode(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $product = $this->getProductById($id);
            if (!$product) {
                errorResponse('الصنف غير موجود');
                return;
            }

            // توليد باركود إذا لم يكن موجوداً
            if (empty($product['barcode'])) {
                $barcode = $this->generateBarcode();
                $this->db->update('products', ['barcode' => $barcode], ['id' => $id]);
                $product['barcode'] = $barcode;
            }

            successResponse('تم جلب الباركود', [
                'product' => [
                    'id' => $product['id'],
                    'code' => $product['code'],
                    'name' => $product['name'],
                    'barcode' => $product['barcode']
                ],
                'barcode_image' => $this->generateBarcodeImage($product['barcode'])
            ]);

        } catch (\Exception $e) {
            error_log('Barcode error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على صنف بالمعرف
     */
    private function getProductById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                p.*,
                c.name as category_name,
                c.code as category_code,
                u.name as unit_name,
                u.symbol as unit_symbol,
                pu.name as purchase_unit_name,
                su.name as sale_unit_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN units u ON u.id = p.unit_id
            LEFT JOIN units pu ON pu.id = p.purchase_unit_id
            LEFT JOIN units su ON su.id = p.sale_unit_id
            WHERE p.id = :id AND p.deleted_at IS NULL
        ", ['id' => $id]);
    }

    /**
     * توليد كود صنف تلقائي
     */
    private function generateProductCode(): string
    {
        $prefix = 'P';
        $year = date('Y');
        $month = date('m');
        
        $last = $this->db->queryValue("
            SELECT MAX(CAST(SUBSTRING(code, -4) AS UNSIGNED)) 
            FROM products 
            WHERE code LIKE :pattern
        ", ['pattern' => "{$prefix}{$year}{$month}%"]);

        $number = str_pad((int)$last + 1, 4, '0', STR_PAD_LEFT);
        return "{$prefix}{$year}{$month}{$number}";
    }

    /**
     * توليد باركود
     */
    private function generateBarcode(): string
    {
        // توليد باركود EAN-13 بسيط
        $code = '629' . str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT) . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        return $code;
    }

    /**
     * توليد صورة باركود (placeholder)
     */
    private function generateBarcodeImage(string $barcode): string
    {
        // في الإنتاج، استخدم مكتبة مثل php-barcode-generator
        return "data:image/svg+xml;base64," . base64_encode("<svg><text x='0' y='20' font-family='monospace' font-size='20'>{$barcode}</text></svg>");
    }

    /**
     * بناء شجرة الأصناف
     */
    private function buildProductTree(array $products): array
    {
        $tree = [];
        foreach ($products as $product) {
            $categoryId = $product['category_id'] ?? 'uncategorized';
            if (!isset($tree[$categoryId])) {
                $tree[$categoryId] = [
                    'name' => $product['category_name'] ?? 'بدون تصنيف',
                    'products' => []
                ];
            }
            $tree[$categoryId]['products'][] = $product;
        }
        return $tree;
    }

    /**
     * تنسيق البطاقات
     */
    private function formatCards(array $products): array
    {
        $cards = [];
        foreach ($products as $product) {
            $cards[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'code' => $product['code'],
                'category' => $product['category_name'] ?? 'بدون تصنيف',
                'quantity' => $product['total_quantity'] ?? 0,
                'price' => $product['selling_price'] ?? $product['cost_price'] ?? 0,
                'image' => '/assets/images/products/default.png',
                'status' => $product['is_active'] ? 'نشط' : 'غير نشط'
            ];
        }
        return $cards;
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
        header('Content-Disposition: attachment; filename="products_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for UTF-8
        
        // رؤوس الأعمدة
        $headers = array_keys($data[0]);
        fputcsv($output, $headers);
        
        // البيانات
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
        header('Content-Disposition: attachment; filename="products_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1">';
        
        // رؤوس الأعمدة
        echo '<tr>';
        foreach (array_keys($data[0]) as $header) {
            echo '<th style="background:#667eea;color:#fff;font-weight:bold;">' . $header . '</th>';
        }
        echo '</tr>';
        
        // البيانات
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
     * التحقق من صحة بيانات الصنف
     */
    private function validateProductData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['name'])) {
            errorResponse('اسم الصنف مطلوب');
            return;
        }
        
        if (empty($data['unit_id'])) {
            errorResponse('الوحدة مطلوبة');
            return;
        }
        
        if (!is_numeric($data['unit_id'])) {
            errorResponse('الوحدة غير صحيحة');
            return;
        }
        
        // التحقق من وجود الوحدة
        $unit = $this->db->queryValue(
            "SELECT id FROM units WHERE id = :id AND is_active = 1",
            ['id' => $data['unit_id']]
        );
        
        if (!$unit) {
            errorResponse('الوحدة غير موجودة أو غير نشطة');
            return;
        }
        
        // التحقق من التصنيف (إذا تم توفيره)
        if (!empty($data['category_id'])) {
            $category = $this->db->queryValue(
                "SELECT id FROM categories WHERE id = :id AND is_active = 1",
                ['id' => $data['category_id']]
            );
            
            if (!$category) {
                errorResponse('التصنيف غير موجود أو غير نشط');
                return;
            }
        }
    }
}

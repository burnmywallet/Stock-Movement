<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/controllers/WarehouseController.php
// الوصف: متحكم إدارة المخازن - CRUD كامل مع هيكل شجري
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;

class WarehouseController
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
     * GET /api/warehouses
     * جلب قائمة المخازن مع هيكل شجري
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $view = $_GET['view'] ?? 'list'; // list, tree, cards, hierarchical
            
            // جلب جميع المخازن
            $warehouses = $this->db->query("
                SELECT 
                    w.*,
                    u.full_name as manager_name,
                    u.username as manager_username,
                    COUNT(DISTINCT sb.product_id) as products_count,
                    COALESCE(SUM(sb.quantity), 0) as total_quantity,
                    COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value,
                    COALESCE(SUM(sb.reserved_quantity), 0) as total_reserved,
                    (SELECT COUNT(*) FROM warehouses WHERE parent_id = w.id AND deleted_at IS NULL) as sub_count
                FROM warehouses w
                LEFT JOIN users u ON u.id = w.manager_id
                LEFT JOIN stock_balances sb ON sb.warehouse_id = w.id
                LEFT JOIN products p ON p.id = sb.product_id
                WHERE w.deleted_at IS NULL
                GROUP BY w.id
                ORDER BY w.is_main DESC, w.parent_id ASC, w.name
            ");

            // بناء الهيكل الشجري
            $tree = [];
            if ($view === 'tree' || $view === 'hierarchical') {
                $tree = $this->buildWarehouseTree($warehouses);
            }

            // إحصائيات إضافية
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN is_main = 1 THEN 1 END) as main,
                    COUNT(CASE WHEN type = 'sub' THEN 1 END) as sub,
                    COUNT(CASE WHEN type = 'store' THEN 1 END) as store,
                    COUNT(CASE WHEN type = 'virtual' THEN 1 END) as virtual,
                    COUNT(DISTINCT manager_id) as managers
                FROM warehouses
                WHERE deleted_at IS NULL
            ");

            // تحويل البيانات للبطاقات
            $cards = [];
            if ($view === 'cards') {
                foreach ($warehouses as $w) {
                    $cards[] = [
                        'id' => $w['id'],
                        'name' => $w['name'],
                        'code' => $w['code'],
                        'type' => $w['type'],
                        'manager' => $w['manager_name'],
                        'products_count' => $w['products_count'],
                        'total_quantity' => $w['total_quantity'],
                        'total_value' => $w['total_value'],
                        'is_active' => $w['is_active'],
                        'is_main' => $w['is_main'],
                        'sub_count' => $w['sub_count']
                    ];
                }
            }

            successResponse('تم جلب قائمة المخازن', [
                'data' => $warehouses,
                'tree' => $tree,
                'cards' => $cards,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'active' => (int)($stats['active'] ?? 0),
                    'main' => (int)($stats['main'] ?? 0),
                    'sub' => (int)($stats['sub'] ?? 0),
                    'store' => (int)($stats['store'] ?? 0),
                    'virtual' => (int)($stats['virtual'] ?? 0),
                    'managers' => (int)($stats['managers'] ?? 0)
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Warehouses list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/warehouses/{id}
     * جلب بيانات مخزن مع تفاصيل كاملة
     */
    public function show(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $warehouse = $this->getWarehouseById($id);
            
            if (!$warehouse) {
                errorResponse('المخزن غير موجود');
                return;
            }

            // جلب المخازن الفرعية
            $subWarehouses = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    type,
                    location,
                    is_active,
                    (SELECT COUNT(*) FROM stock_balances WHERE warehouse_id = w.id) as products_count
                FROM warehouses w
                WHERE parent_id = :parent_id AND deleted_at IS NULL
                ORDER BY name
            ", ['parent_id' => $id]);

            // جلب الأصناف في المخزن مع أرصدتها
            $products = $this->db->query("
                SELECT 
                    p.id,
                    p.code,
                    p.name,
                    p.barcode,
                    u.name as unit_name,
                    COALESCE(sb.quantity, 0) as quantity,
                    COALESCE(sb.reserved_quantity, 0) as reserved_quantity,
                    COALESCE(sb.quantity - sb.reserved_quantity, 0) as available_quantity,
                    p.min_stock,
                    p.max_stock,
                    p.cost_price,
                    p.selling_price,
                    COALESCE(sb.quantity * p.cost_price, 0) as total_value,
                    CASE 
                        WHEN COALESCE(sb.quantity, 0) <= 0 THEN 'out_of_stock'
                        WHEN COALESCE(sb.quantity, 0) <= p.min_stock THEN 'low_stock'
                        WHEN COALESCE(sb.quantity, 0) >= p.max_stock THEN 'over_stock'
                        ELSE 'normal'
                    END as stock_status
                FROM products p
                LEFT JOIN units u ON u.id = p.unit_id
                LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = :warehouse_id
                WHERE p.is_active = 1 AND p.deleted_at IS NULL
                ORDER BY p.name
            ", ['warehouse_id' => $id]);

            // جلب آخر الحركات في المخزن
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
                    p.code as product_code,
                    p.name as product_name,
                    sm.quantity,
                    sm.unit_cost,
                    sm.balance_before,
                    sm.balance_after,
                    sm.movement_date,
                    u.full_name as user_name
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                INNER JOIN users u ON u.id = sm.user_id
                WHERE sm.warehouse_id = :warehouse_id
                ORDER BY sm.movement_date DESC
                LIMIT 50
            ", ['warehouse_id' => $id]);

            // جلب سجل التدقيق للمخزن
            $audits = $this->db->query("
                SELECT 
                    created_at,
                    user_id,
                    (SELECT full_name FROM users WHERE id = user_id) as user_name,
                    action,
                    description
                FROM audit_logs
                WHERE reference_type = 'warehouse'
                  AND reference_id = :reference_id
                ORDER BY created_at DESC
                LIMIT 20
            ", ['reference_id' => $id]);

            successResponse('تم جلب بيانات المخزن', [
                'warehouse' => $warehouse,
                'sub_warehouses' => $subWarehouses,
                'products' => $products,
                'recent_movements' => $movements,
                'audits' => $audits,
                'stats' => [
                    'total_products' => count($products),
                    'out_of_stock' => count(array_filter($products, fn($p) => $p['stock_status'] === 'out_of_stock')),
                    'low_stock' => count(array_filter($products, fn($p) => $p['stock_status'] === 'low_stock')),
                    'over_stock' => count(array_filter($products, fn($p) => $p['stock_status'] === 'over_stock')),
                    'total_quantity' => array_sum(array_column($products, 'quantity')),
                    'total_value' => array_sum(array_column($products, 'total_value'))
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Warehouse show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/warehouses
     * إنشاء مخزن جديد
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
            $this->validateWarehouseData($input);

            // التحقق من الكود
            if (!empty($input['code'])) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM warehouses WHERE code = :code",
                    ['code' => $input['code']]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            } else {
                // توليد كود تلقائي
                $input['code'] = $this->generateWarehouseCode();
            }

            // التحقق من وجود المدير
            if (!empty($input['manager_id'])) {
                $manager = $this->db->queryValue(
                    "SELECT id FROM users WHERE id = :id AND is_active = 1",
                    ['id' => $input['manager_id']]
                );
                
                if (!$manager) {
                    errorResponse('المدير غير موجود أو غير نشط');
                    return;
                }
            }

            // التحقق من وجود المخزن الرئيسي
            if (isset($input['parent_id']) && $input['parent_id'] > 0) {
                $parent = $this->db->queryValue(
                    "SELECT id FROM warehouses WHERE id = :id AND deleted_at IS NULL",
                    ['id' => $input['parent_id']]
                );
                
                if (!$parent) {
                    errorResponse('المخزن الرئيسي غير موجود');
                    return;
                }
            }

            // إذا كان المخزن هو الرئيسي، إلغاء تعيين الرئيسي للمخازن الأخرى
            if (isset($input['is_main']) && $input['is_main']) {
                $this->db->execute("UPDATE warehouses SET is_main = 0 WHERE is_main = 1");
            }

            // إنشاء المخزن
            $data = [
                'code' => $input['code'],
                'name' => $input['name'],
                'type' => $input['type'] ?? 'main',
                'parent_id' => $input['parent_id'] ?? null,
                'location' => $input['location'] ?? null,
                'address' => $input['address'] ?? null,
                'manager_id' => $input['manager_id'] ?? null,
                'phone' => $input['phone'] ?? null,
                'email' => $input['email'] ?? null,
                'is_active' => $input['is_active'] ?? 1,
                'is_main' => $input['is_main'] ?? 0,
                'is_default' => $input['is_default'] ?? 0,
                'capacity' => $input['capacity'] ?? null,
                'notes' => $input['notes'] ?? null,
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $warehouseId = $this->db->insert('warehouses', $data);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'WAREHOUSE_CREATED',
                'warehouses',
                "إنشاء مخزن جديد: {$input['name']}",
                ['warehouse_id' => $warehouseId, 'code' => $input['code']],
                'warehouse',
                $warehouseId
            );

            successResponse('تم إنشاء المخزن بنجاح', ['warehouse_id' => $warehouseId]);

        } catch (\Exception $e) {
            error_log('Warehouse create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * PUT /api/warehouses/{id}
     * تحديث بيانات مخزن
     */
    public function update(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $warehouse = $this->getWarehouseById($id);
            if (!$warehouse) {
                errorResponse('المخزن غير موجود');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateWarehouseData($input, true);

            // التحقق من الكود
            if (!empty($input['code']) && $input['code'] !== $warehouse['code']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM warehouses WHERE code = :code AND id != :id",
                    ['code' => $input['code'], 'id' => $id]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            }

            // التحقق من وجود المدير
            if (!empty($input['manager_id'])) {
                $manager = $this->db->queryValue(
                    "SELECT id FROM users WHERE id = :id AND is_active = 1",
                    ['id' => $input['manager_id']]
                );
                
                if (!$manager) {
                    errorResponse('المدير غير موجود أو غير نشط');
                    return;
                }
            }

            // التحقق من وجود المخزن الرئيسي
            if (isset($input['parent_id']) && $input['parent_id'] > 0 && $input['parent_id'] != $id) {
                $parent = $this->db->queryValue(
                    "SELECT id FROM warehouses WHERE id = :id AND deleted_at IS NULL",
                    ['id' => $input['parent_id']]
                );
                
                if (!$parent) {
                    errorResponse('المخزن الرئيسي غير موجود');
                    return;
                }
                
                // منع جعل المخزن رئيساً لنفسه
                if ($input['parent_id'] == $id) {
                    errorResponse('لا يمكن جعل المخزن رئيساً لنفسه');
                    return;
                }
            }

            // إذا كان المخزن هو الرئيسي، إلغاء تعيين الرئيسي للمخازن الأخرى
            if (isset($input['is_main']) && $input['is_main'] && !$warehouse['is_main']) {
                $this->db->execute("UPDATE warehouses SET is_main = 0 WHERE is_main = 1");
            }

            // تحديث البيانات
            $data = [
                'code' => $input['code'] ?? $warehouse['code'],
                'name' => $input['name'] ?? $warehouse['name'],
                'type' => $input['type'] ?? $warehouse['type'],
                'parent_id' => $input['parent_id'] ?? $warehouse['parent_id'],
                'location' => $input['location'] ?? $warehouse['location'],
                'address' => $input['address'] ?? $warehouse['address'],
                'manager_id' => $input['manager_id'] ?? $warehouse['manager_id'],
                'phone' => $input['phone'] ?? $warehouse['phone'],
                'email' => $input['email'] ?? $warehouse['email'],
                'is_active' => $input['is_active'] ?? $warehouse['is_active'],
                'is_main' => $input['is_main'] ?? $warehouse['is_main'],
                'is_default' => $input['is_default'] ?? $warehouse['is_default'],
                'capacity' => $input['capacity'] ?? $warehouse['capacity'],
                'notes' => $input['notes'] ?? $warehouse['notes'],
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('warehouses', $data, ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'WAREHOUSE_UPDATED',
                'warehouses',
                "تحديث بيانات المخزن: {$warehouse['name']}",
                ['warehouse_id' => $id, 'code' => $warehouse['code']],
                'warehouse',
                $id
            );

            successResponse('تم تحديث بيانات المخزن بنجاح');

        } catch (\Exception $e) {
            error_log('Warehouse update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/warehouses/{id}
     * حذف مخزن (حذف ناعم)
     */
    public function delete(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $warehouse = $this->getWarehouseById($id);
            if (!$warehouse) {
                errorResponse('المخزن غير موجود');
                return;
            }

            // منع حذف المخزن الرئيسي
            if ($warehouse['is_main']) {
                errorResponse('لا يمكن حذف المخزن الرئيسي');
                return;
            }

            // التحقق من وجود مخازن فرعية
            $subCount = $this->db->queryValue(
                "SELECT COUNT(*) FROM warehouses WHERE parent_id = :parent_id AND deleted_at IS NULL",
                ['parent_id' => $id]
            );

            if ($subCount > 0) {
                errorResponse('لا يمكن حذف المخزن لأنه يحتوي على مخازن فرعية');
                return;
            }

            // التحقق من وجود حركات للمخزن
            $hasMovements = $this->db->queryValue(
                "SELECT COUNT(*) FROM stock_movements WHERE warehouse_id = :warehouse_id",
                ['warehouse_id' => $id]
            );

            if ($hasMovements > 0) {
                errorResponse('لا يمكن حذف المخزن لأنه توجد له حركات مخزون');
                return;
            }

            // التحقق من وجود أرصدة
            $hasBalances = $this->db->queryValue(
                "SELECT COUNT(*) FROM stock_balances WHERE warehouse_id = :warehouse_id AND quantity > 0",
                ['warehouse_id' => $id]
            );

            if ($hasBalances > 0) {
                errorResponse('لا يمكن حذف المخزن لأنه توجد به أرصدة مخزون');
                return;
            }

            // الحذف الناعم
            $this->db->softDelete('warehouses', ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'WAREHOUSE_DELETED',
                'warehouses',
                "حذف المخزن: {$warehouse['name']}",
                ['warehouse_id' => $id, 'code' => $warehouse['code']],
                'warehouse',
                $id
            );

            successResponse('تم حذف المخزن بنجاح');

        } catch (\Exception $e) {
            error_log('Warehouse delete error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/warehouses/{id}/stock
     * تقرير مخزون المخزن
     */
    public function stock(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $warehouse = $this->getWarehouseById($id);
            if (!$warehouse) {
                errorResponse('المخزن غير موجود');
                return;
            }

            // معاملات البحث والفلترة
            $search = $_GET['search'] ?? '';
            $category = $_GET['category'] ?? '';
            $status = $_GET['status'] ?? '';
            $sort = $_GET['sort'] ?? 'name';
            $order = $_GET['order'] ?? 'ASC';

            $params = ['warehouse_id' => $id];
            $where = [];

            if (!empty($search)) {
                $where[] = "(p.name LIKE :search OR p.code LIKE :search OR p.barcode LIKE :search)";
                $params['search'] = "%{$search}%";
            }

            if (!empty($category)) {
                $where[] = "p.category_id = :category";
                $params['category'] = $category;
            }

            if ($status === 'low_stock') {
                $where[] = "sb.quantity <= p.min_stock AND sb.quantity > 0";
            } elseif ($status === 'out_of_stock') {
                $where[] = "sb.quantity = 0";
            } elseif ($status === 'over_stock') {
                $where[] = "sb.quantity >= p.max_stock";
            }

            $whereClause = !empty($where) ? 'AND ' . implode(' AND ', $where) : '';

            // جلب المنتجات
            $products = $this->db->query("
                SELECT 
                    p.id,
                    p.code,
                    p.name,
                    p.barcode,
                    u.name as unit_name,
                    c.name as category_name,
                    COALESCE(sb.quantity, 0) as quantity,
                    COALESCE(sb.reserved_quantity, 0) as reserved_quantity,
                    COALESCE(sb.quantity - sb.reserved_quantity, 0) as available_quantity,
                    p.min_stock,
                    p.max_stock,
                    p.cost_price,
                    p.selling_price,
                    COALESCE(sb.quantity * p.cost_price, 0) as total_value,
                    CASE 
                        WHEN COALESCE(sb.quantity, 0) <= 0 THEN 'نفذ'
                        WHEN COALESCE(sb.quantity, 0) <= p.min_stock THEN 'منخفض'
                        WHEN COALESCE(sb.quantity, 0) >= p.max_stock THEN 'زائد'
                        ELSE 'طبيعي'
                    END as stock_status
                FROM products p
                LEFT JOIN units u ON u.id = p.unit_id
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = :warehouse_id
                WHERE p.is_active = 1 AND p.deleted_at IS NULL
                {$whereClause}
                ORDER BY p.{$sort} {$order}
            ", $params);

            // إحصائيات
            $stats = [
                'total_products' => count($products),
                'total_quantity' => array_sum(array_column($products, 'quantity')),
                'total_value' => array_sum(array_column($products, 'total_value')),
                'out_of_stock' => count(array_filter($products, fn($p) => $p['stock_status'] === 'نفذ')),
                'low_stock' => count(array_filter($products, fn($p) => $p['stock_status'] === 'منخفض')),
                'over_stock' => count(array_filter($products, fn($p) => $p['stock_status'] === 'زائد')),
                'normal' => count(array_filter($products, fn($p) => $p['stock_status'] === 'طبيعي'))
            ];

            successResponse('تم جلب تقرير مخزون المخزن', [
                'warehouse' => $warehouse,
                'products' => $products,
                'summary' => $stats
            ]);

        } catch (\Exception $e) {
            error_log('Warehouse stock error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/warehouses/{id}/sub
     * جلب المخازن الفرعية
     */
    public function subWarehouses(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $warehouse = $this->getWarehouseById($id);
            if (!$warehouse) {
                errorResponse('المخزن غير موجود');
                return;
            }

            $subWarehouses = $this->db->query("
                SELECT 
                    w.*,
                    u.full_name as manager_name,
                    COUNT(DISTINCT sb.product_id) as products_count,
                    COALESCE(SUM(sb.quantity), 0) as total_quantity,
                    COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value
                FROM warehouses w
                LEFT JOIN users u ON u.id = w.manager_id
                LEFT JOIN stock_balances sb ON sb.warehouse_id = w.id
                LEFT JOIN products p ON p.id = sb.product_id
                WHERE w.parent_id = :parent_id AND w.deleted_at IS NULL
                GROUP BY w.id
                ORDER BY w.name
            ", ['parent_id' => $id]);

            successResponse('تم جلب المخازن الفرعية', $subWarehouses);

        } catch (\Exception $e) {
            error_log('Sub warehouses error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/warehouses/{id}/report
     * تقرير كامل عن المخزن
     */
    public function report(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $format = $_GET['format'] ?? 'json';
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            $warehouse = $this->getWarehouseById($id);
            if (!$warehouse) {
                errorResponse('المخزن غير موجود');
                return;
            }

            // حركات المخزن في الفترة
            $movements = $this->db->query("
                SELECT 
                    DATE(movement_date) as date,
                    COUNT(*) as total,
                    COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                    COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues,
                    COUNT(CASE WHEN movement_type = 'TRANSFER_IN' THEN 1 END) as transfers_in,
                    COUNT(CASE WHEN movement_type = 'TRANSFER_OUT' THEN 1 END) as transfers_out,
                    COUNT(CASE WHEN movement_type = 'ADJUSTMENT' THEN 1 END) as adjustments
                FROM stock_movements
                WHERE warehouse_id = :warehouse_id
                  AND movement_date BETWEEN :from_date AND :to_date
                GROUP BY DATE(movement_date)
                ORDER BY date ASC
            ", [
                'warehouse_id' => $id,
                'from_date' => $fromDate . ' 00:00:00',
                'to_date' => $toDate . ' 23:59:59'
            ]);

            // أكثر الأصناف تداولاً
            $topProducts = $this->db->query("
                SELECT 
                    p.id,
                    p.code,
                    p.name,
                    COUNT(sm.id) as movement_count,
                    SUM(sm.quantity) as total_quantity,
                    SUM(sm.total_cost) as total_value
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                WHERE sm.warehouse_id = :warehouse_id
                  AND sm.movement_date BETWEEN :from_date AND :to_date
                GROUP BY p.id, p.code, p.name
                ORDER BY movement_count DESC
                LIMIT 10
            ", [
                'warehouse_id' => $id,
                'from_date' => $fromDate . ' 00:00:00',
                'to_date' => $toDate . ' 23:59:59'
            ]);

            // إحصائيات عامة
            $summary = $this->db->queryOne("
                SELECT 
                    COUNT(DISTINCT p.id) as total_products,
                    COALESCE(SUM(sb.quantity), 0) as total_quantity,
                    COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value,
                    COUNT(CASE WHEN sb.quantity <= 0 THEN 1 END) as out_of_stock,
                    COUNT(CASE WHEN sb.quantity <= p.min_stock AND sb.quantity > 0 THEN 1 END) as low_stock
                FROM stock_balances sb
                INNER JOIN products p ON p.id = sb.product_id
                WHERE sb.warehouse_id = :warehouse_id
            ", ['warehouse_id' => $id]);

            $reportData = [
                'warehouse' => $warehouse,
                'period' => [
                    'from' => $fromDate,
                    'to' => $toDate
                ],
                'summary' => $summary,
                'daily_movements' => $movements,
                'top_products' => $topProducts
            ];

            if ($format === 'json') {
                successResponse('تم جلب تقرير المخزن', $reportData);
            } elseif ($format === 'csv') {
                $this->exportReportCSV($movements, $topProducts);
            } else {
                successResponse('تم جلب تقرير المخزن', $reportData);
            }

        } catch (\Exception $e) {
            error_log('Warehouse report error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على مخزن بالمعرف
     */
    private function getWarehouseById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                w.*,
                u.full_name as manager_name,
                u.username as manager_username,
                p.full_name as parent_name,
                (SELECT COUNT(*) FROM warehouses WHERE parent_id = w.id AND deleted_at IS NULL) as sub_count,
                (SELECT COUNT(DISTINCT product_id) FROM stock_balances WHERE warehouse_id = w.id) as products_count,
                (SELECT COALESCE(SUM(quantity), 0) FROM stock_balances WHERE warehouse_id = w.id) as total_quantity
            FROM warehouses w
            LEFT JOIN users u ON u.id = w.manager_id
            LEFT JOIN warehouses p ON p.id = w.parent_id
            WHERE w.id = :id AND w.deleted_at IS NULL
        ", ['id' => $id]);
    }

    /**
     * توليد كود مخزن تلقائي
     */
    private function generateWarehouseCode(): string
    {
        $prefix = 'W';
        $year = date('Y');
        
        $last = $this->db->queryValue("
            SELECT MAX(CAST(SUBSTRING(code, -3) AS UNSIGNED)) 
            FROM warehouses 
            WHERE code LIKE :pattern
        ", ['pattern' => "{$prefix}{$year}%"]);

        $number = str_pad((int)$last + 1, 3, '0', STR_PAD_LEFT);
        return "{$prefix}{$year}{$number}";
    }

    /**
     * بناء هيكل شجري للمخازن
     */
    private function buildWarehouseTree(array $warehouses): array
    {
        $tree = [];
        $map = [];
        
        // ترتيب المخازن في مصفوفة مساعدة
        foreach ($warehouses as $warehouse) {
            $map[$warehouse['id']] = $warehouse;
            $map[$warehouse['id']]['children'] = [];
        }
        
        // بناء الهيكل الشجري
        foreach ($map as $id => $warehouse) {
            if ($warehouse['parent_id'] && isset($map[$warehouse['parent_id']])) {
                $map[$warehouse['parent_id']]['children'][] = &$map[$id];
            } else {
                $tree[] = &$map[$id];
            }
        }
        
        return $tree;
    }

    /**
     * تصدير تقرير CSV
     */
    private function exportReportCSV(array $movements, array $topProducts): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="warehouse_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['التاريخ', 'إجمالي', 'استلام', 'صرف', 'تحويل داخل', 'تحويل خارج', 'تسوية']);
        foreach ($movements as $row) {
            fputcsv($output, [
                $row['date'],
                $row['total'],
                $row['receipts'],
                $row['issues'],
                $row['transfers_in'],
                $row['transfers_out'],
                $row['adjustments']
            ]);
        }
        
        fputcsv($output, []);
        fputcsv($output, ['أكثر الأصناف تداولاً']);
        fputcsv($output, ['الكود', 'الاسم', 'عدد الحركات', 'الكمية', 'القيمة']);
        foreach ($topProducts as $row) {
            fputcsv($output, [
                $row['code'],
                $row['name'],
                $row['movement_count'],
                $row['total_quantity'],
                $row['total_value']
            ]);
        }
        
        fclose($output);
        exit;
    }

    /**
     * التحقق من صحة بيانات المخزن
     */
    private function validateWarehouseData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['name'])) {
            errorResponse('اسم المخزن مطلوب');
            return;
        }
        
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            errorResponse('البريد الإلكتروني غير صحيح');
            return;
        }
        
        if (!empty($data['type'])) {
            $allowedTypes = ['main', 'sub', 'store', 'virtual'];
            if (!in_array($data['type'], $allowedTypes)) {
                errorResponse('نوع المخزن غير صحيح');
                return;
            }
        }
        
        if (!empty($data['manager_id'])) {
            $manager = $this->db->queryValue(
                "SELECT id FROM users WHERE id = :id AND is_active = 1",
                ['id' => $data['manager_id']]
            );
            
            if (!$manager) {
                errorResponse('المدير غير موجود أو غير نشط');
                return;
            }
        }
    }
}

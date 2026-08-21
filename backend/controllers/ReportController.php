<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/controllers/ReportController.php
// الوصف: متحكم التقارير المتقدمة - جميع أنواع التقارير
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;

class ReportController
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
     * GET /api/reports/stock
     * تقرير أرصدة المخازن
     */
    public function stock(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية التقارير
            if (!$this->auth->hasPermission($userId, 'reports.view')) {
                errorResponse('ليس لديك صلاحية لعرض التقارير', 403);
                return;
            }

            $warehouseId = $_GET['warehouse_id'] ?? null;
            $categoryId = $_GET['category_id'] ?? null;
            $status = $_GET['status'] ?? null;
            $search = $_GET['search'] ?? '';
            $format = $_GET['format'] ?? 'json';

            $params = [];
            $where = ["p.deleted_at IS NULL"];
            
            if ($warehouseId) {
                $where[] = "sb.warehouse_id = :warehouse_id";
                $params['warehouse_id'] = $warehouseId;
            }
            
            if ($categoryId) {
                $where[] = "p.category_id = :category_id";
                $params['category_id'] = $categoryId;
            }
            
            if (!empty($search)) {
                $where[] = "(p.name LIKE :search OR p.code LIKE :search OR p.barcode LIKE :search)";
                $params['search'] = "%{$search}%";
            }
            
            if ($status) {
                switch ($status) {
                    case 'low_stock':
                        $where[] = "sb.quantity <= p.min_stock AND sb.quantity > 0";
                        break;
                    case 'out_of_stock':
                        $where[] = "sb.quantity = 0";
                        break;
                    case 'over_stock':
                        $where[] = "sb.quantity >= p.max_stock";
                        break;
                    case 'normal':
                        $where[] = "sb.quantity > p.min_stock AND sb.quantity < p.max_stock";
                        break;
                }
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $report = $this->db->query("
                SELECT 
                    p.id as product_id,
                    p.code,
                    p.name,
                    p.barcode,
                    c.name as category,
                    u.name as unit,
                    w.id as warehouse_id,
                    w.name as warehouse,
                    COALESCE(sb.quantity, 0) as balance,
                    COALESCE(sb.reserved_quantity, 0) as reserved,
                    COALESCE(sb.quantity - sb.reserved_quantity, 0) as available,
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
                    END as stock_status,
                    CASE 
                        WHEN COALESCE(sb.quantity, 0) <= 0 THEN 'danger'
                        WHEN COALESCE(sb.quantity, 0) <= p.min_stock THEN 'warning'
                        WHEN COALESCE(sb.quantity, 0) >= p.max_stock THEN 'info'
                        ELSE 'success'
                    END as status_color
                FROM products p
                INNER JOIN units u ON u.id = p.unit_id
                LEFT JOIN categories c ON c.id = p.category_id
                CROSS JOIN warehouses w
                LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = w.id
                {$whereClause}
                ORDER BY p.name, w.name
            ", $params);

            // إحصائيات إضافية
            $stats = $this->getStockStats($report);

            // تصدير حسب التنسيق
            if ($format === 'csv') {
                $this->exportStockCSV($report);
                return;
            } elseif ($format === 'excel') {
                $this->exportStockExcel($report);
                return;
            } elseif ($format === 'pdf') {
                $this->exportStockPDF($report, $stats);
                return;
            }

            successResponse('تم جلب تقرير أرصدة المخازن', [
                'data' => $report,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            error_log('Stock report error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/reports/movements
     * تقرير حركة المخزون
     */
    public function movements(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'reports.view')) {
                errorResponse('ليس لديك صلاحية لعرض التقارير', 403);
                return;
            }

            $productId = $_GET['product_id'] ?? null;
            $warehouseId = $_GET['warehouse_id'] ?? null;
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');
            $type = $_GET['type'] ?? null;
            $format = $_GET['format'] ?? 'json';

            $params = [
                'from_date' => $fromDate . ' 00:00:00',
                'to_date' => $toDate . ' 23:59:59'
            ];
            $where = ["sm.movement_date BETWEEN :from_date AND :to_date"];
            
            if ($productId) {
                $where[] = "sm.product_id = :product_id";
                $params['product_id'] = $productId;
            }
            
            if ($warehouseId) {
                $where[] = "sm.warehouse_id = :warehouse_id";
                $params['warehouse_id'] = $warehouseId;
            }
            
            if ($type) {
                $where[] = "sm.movement_type = :type";
                $params['type'] = $type;
            }
            
            $whereClause = implode(' AND ', $where);

            $report = $this->db->query("
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
                        WHEN 'COUNT_CORRECTION' THEN 'تصحيح جرد'
                        ELSE sm.movement_type
                    END as movement_label,
                    p.code as product_code,
                    p.name as product_name,
                    w.name as warehouse,
                    sm.quantity,
                    sm.unit_cost,
                    sm.total_cost,
                    sm.balance_before,
                    sm.balance_after,
                    sm.movement_date,
                    u.full_name as user_name,
                    sm.reference_type,
                    sm.reference_id,
                    sm.notes,
                    CASE 
                        WHEN sm.movement_type IN ('RECEIPT', 'TRANSFER_IN', 'RETURN_IN') THEN 'in'
                        WHEN sm.movement_type IN ('ISSUE', 'TRANSFER_OUT', 'RETURN_OUT') THEN 'out'
                        ELSE 'adjustment'
                    END as movement_direction
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                INNER JOIN warehouses w ON w.id = sm.warehouse_id
                INNER JOIN users u ON u.id = sm.user_id
                WHERE {$whereClause}
                ORDER BY sm.movement_date DESC
            ", $params);

            // حساب إحصائيات الحركات
            $stats = $this->getMovementStats($report);

            if ($format === 'csv') {
                $this->exportMovementsCSV($report);
                return;
            } elseif ($format === 'excel') {
                $this->exportMovementsExcel($report);
                return;
            }

            successResponse('تم جلب تقرير حركة المخزون', [
                'data' => $report,
                'stats' => $stats,
                'filters' => [
                    'from_date' => $fromDate,
                    'to_date' => $toDate,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'type' => $type
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Movements report error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/reports/product/{id}
     * تقرير حركة صنف معين
     */
    public function product(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'reports.view')) {
                errorResponse('ليس لديك صلاحية لعرض التقارير', 403);
                return;
            }

            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');
            $format = $_GET['format'] ?? 'json';

            // معلومات المنتج
            $product = $this->db->queryOne("
                SELECT 
                    p.*,
                    c.name as category_name,
                    u.name as unit_name,
                    (SELECT COALESCE(SUM(sb.quantity), 0) FROM stock_balances sb WHERE sb.product_id = p.id) as total_quantity,
                    (SELECT COALESCE(SUM(sb.quantity * p.cost_price), 0) FROM stock_balances sb WHERE sb.product_id = p.id) as total_value
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN units u ON u.id = p.unit_id
                WHERE p.id = :id AND p.deleted_at IS NULL
            ", ['id' => $id]);

            if (!$product) {
                errorResponse('المنتج غير موجود');
                return;
            }

            // حركات المنتج
            $movements = $this->db->query("
                SELECT 
                    sm.*,
                    w.name as warehouse_name,
                    u.full_name as user_name,
                    CASE sm.movement_type
                        WHEN 'RECEIPT' THEN 'استلام'
                        WHEN 'ISSUE' THEN 'صرف'
                        WHEN 'TRANSFER_OUT' THEN 'تحويل خارج'
                        WHEN 'TRANSFER_IN' THEN 'تحويل داخل'
                        WHEN 'RETURN_IN' THEN 'مرتجع للمخزن'
                        WHEN 'RETURN_OUT' THEN 'مرتجع من المخزن'
                        WHEN 'ADJUSTMENT' THEN 'تسوية'
                        ELSE sm.movement_type
                    END as movement_label
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

            // الأرصدة في المخازن
            $balances = $this->db->query("
                SELECT 
                    w.id as warehouse_id,
                    w.name as warehouse_name,
                    COALESCE(sb.quantity, 0) as quantity,
                    COALESCE(sb.reserved_quantity, 0) as reserved,
                    COALESCE(sb.quantity - sb.reserved_quantity, 0) as available,
                    COALESCE(sb.quantity * p.cost_price, 0) as total_value
                FROM warehouses w
                LEFT JOIN stock_balances sb ON sb.warehouse_id = w.id AND sb.product_id = :product_id
                WHERE w.is_active = 1
            ", ['product_id' => $id]);

            // إحصائيات الحركات
            $stats = [
                'total_movements' => count($movements),
                'total_in' => array_sum(array_filter($movements, fn($m) => in_array($m['movement_type'], ['RECEIPT', 'TRANSFER_IN', 'RETURN_IN']))),
                'total_out' => array_sum(array_filter($movements, fn($m) => in_array($m['movement_type'], ['ISSUE', 'TRANSFER_OUT', 'RETURN_OUT']))),
                'total_adjustments' => array_sum(array_filter($movements, fn($m) => $m['movement_type'] === 'ADJUSTMENT')),
                'first_movement' => $movements ? $movements[count($movements)-1]['movement_date'] : null,
                'last_movement' => $movements ? $movements[0]['movement_date'] : null,
                'average_daily' => $this->calculateAverageDaily($movements, $fromDate, $toDate)
            ];

            if ($format === 'csv') {
                $this->exportProductCSV($product, $movements, $balances);
                return;
            }

            successResponse('تم جلب تقرير حركة المنتج', [
                'product' => $product,
                'movements' => $movements,
                'balances' => $balances,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            error_log('Product report error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/reports/warehouse/{id}
     * تقرير مخزن محدد
     */
    public function warehouse(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'reports.view')) {
                errorResponse('ليس لديك صلاحية لعرض التقارير', 403);
                return;
            }

            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            $warehouse = $this->db->queryOne("
                SELECT * FROM warehouses WHERE id = :id AND deleted_at IS NULL
            ", ['id' => $id]);

            if (!$warehouse) {
                errorResponse('المخزن غير موجود');
                return;
            }

            // إحصائيات المخزن
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(DISTINCT p.id) as total_products,
                    COALESCE(SUM(sb.quantity), 0) as total_quantity,
                    COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value,
                    COUNT(CASE WHEN sb.quantity <= 0 THEN 1 END) as out_of_stock,
                    COUNT(CASE WHEN sb.quantity <= p.min_stock AND sb.quantity > 0 THEN 1 END) as low_stock,
                    COUNT(CASE WHEN sb.quantity >= p.max_stock THEN 1 END) as over_stock
                FROM stock_balances sb
                INNER JOIN products p ON p.id = sb.product_id
                WHERE sb.warehouse_id = :warehouse_id
            ", ['warehouse_id' => $id]);

            // حركات المخزن
            $movements = $this->db->query("
                SELECT 
                    DATE(movement_date) as date,
                    COUNT(*) as total_movements,
                    COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                    COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues,
                    COUNT(CASE WHEN movement_type = 'TRANSFER_IN' THEN 1 END) as transfers_in,
                    COUNT(CASE WHEN movement_type = 'TRANSFER_OUT' THEN 1 END) as transfers_out,
                    COUNT(CASE WHEN movement_type = 'ADJUSTMENT' THEN 1 END) as adjustments,
                    SUM(CASE WHEN movement_type IN ('RECEIPT', 'TRANSFER_IN', 'RETURN_IN') THEN quantity ELSE 0 END) as total_in,
                    SUM(CASE WHEN movement_type IN ('ISSUE', 'TRANSFER_OUT', 'RETURN_OUT') THEN quantity ELSE 0 END) as total_out
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

            successResponse('تم جلب تقرير المخزن', [
                'warehouse' => $warehouse,
                'stats' => $stats,
                'daily_movements' => $movements,
                'top_products' => $topProducts,
                'period' => [
                    'from' => $fromDate,
                    'to' => $toDate
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Warehouse report error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/reports/audit
     * تقرير سجل التدقيق
     */
    public function audit(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'reports.view') || 
                !$this->auth->hasPermission($userId, 'audit.view')) {
                errorResponse('ليس لديك صلاحية لعرض سجل التدقيق', 403);
                return;
            }

            $userIdFilter = $_GET['user_id'] ?? null;
            $action = $_GET['action'] ?? null;
            $module = $_GET['module'] ?? null;
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');
            $limit = (int)($_GET['limit'] ?? 100);
            $format = $_GET['format'] ?? 'json';

            $params = [
                'from_date' => $fromDate . ' 00:00:00',
                'to_date' => $toDate . ' 23:59:59',
                'limit' => $limit
            ];
            $where = ["al.created_at BETWEEN :from_date AND :to_date"];
            
            if ($userIdFilter) {
                $where[] = "al.user_id = :user_id";
                $params['user_id'] = $userIdFilter;
            }
            
            if ($action) {
                $where[] = "al.action = :action";
                $params['action'] = $action;
            }
            
            if ($module) {
                $where[] = "al.module = :module";
                $params['module'] = $module;
            }
            
            $whereClause = implode(' AND ', $where);

            $report = $this->db->query("
                SELECT 
                    al.id,
                    al.user_id,
                    al.username,
                    u.full_name as user_full_name,
                    al.action,
                    al.module,
                    al.description,
                    al.details,
                    al.ip_address,
                    al.created_at,
                    CASE 
                        WHEN al.action = 'LOGIN_SUCCESS' THEN 'تسجيل دخول'
                        WHEN al.action = 'LOGIN_FAILED' THEN 'محاولة دخول فاشلة'
                        WHEN al.action = 'LOGOUT' THEN 'تسجيل خروج'
                        WHEN al.action LIKE '%CREATE%' OR al.action LIKE '%CREATED%' THEN 'إنشاء'
                        WHEN al.action LIKE '%UPDATE%' OR al.action LIKE '%UPDATED%' THEN 'تحديث'
                        WHEN al.action LIKE '%DELETE%' OR al.action LIKE '%DELETED%' THEN 'حذف'
                        ELSE al.action
                    END as action_label,
                    CASE 
                        WHEN al.action = 'LOGIN_SUCCESS' THEN 'success'
                        WHEN al.action = 'LOGIN_FAILED' THEN 'danger'
                        WHEN al.action LIKE '%CREATE%' OR al.action LIKE '%CREATED%' THEN 'primary'
                        WHEN al.action LIKE '%UPDATE%' OR al.action LIKE '%UPDATED%' THEN 'warning'
                        WHEN al.action LIKE '%DELETE%' OR al.action LIKE '%DELETED%' THEN 'danger'
                        ELSE 'secondary'
                    END as action_type
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE {$whereClause}
                ORDER BY al.created_at DESC
                LIMIT :limit
            ", $params);

            // إحصائيات
            $stats = [
                'total_entries' => count($report),
                'unique_users' => count(array_unique(array_column($report, 'user_id'))),
                'unique_actions' => count(array_unique(array_column($report, 'action'))),
                'unique_modules' => count(array_unique(array_column($report, 'module'))),
                'by_action' => $this->groupBy($report, 'action_label'),
                'by_module' => $this->groupBy($report, 'module'),
                'by_user' => $this->groupBy($report, 'username')
            ];

            if ($format === 'csv') {
                $this->exportAuditCSV($report);
                return;
            }

            successResponse('تم جلب تقرير سجل التدقيق', [
                'data' => $report,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            error_log('Audit report error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/reports/summary
     * تقرير ملخص النظام
     */
    public function summary(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'reports.view')) {
                errorResponse('ليس لديك صلاحية لعرض التقارير', 403);
                return;
            }

            // إحصائيات عامة
            $general = $this->db->queryOne("
                SELECT 
                    (SELECT COUNT(*) FROM products WHERE deleted_at IS NULL) as total_products,
                    (SELECT COUNT(*) FROM warehouses WHERE deleted_at IS NULL AND is_active = 1) as total_warehouses,
                    (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_active = 1) as total_users,
                    (SELECT COUNT(*) FROM suppliers WHERE deleted_at IS NULL AND is_active = 1) as total_suppliers,
                    (SELECT COUNT(*) FROM stock_balances WHERE quantity > 0) as products_with_stock,
                    (SELECT COUNT(*) FROM stock_balances WHERE quantity = 0) as out_of_stock,
                    (SELECT COALESCE(SUM(quantity), 0) FROM stock_balances) as total_quantity,
                    (SELECT COALESCE(SUM(quantity * p.cost_price), 0) FROM stock_balances sb INNER JOIN products p ON p.id = sb.product_id) as total_value
            ");

            // حركات اليوم
            $today = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                    COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues,
                    COUNT(CASE WHEN movement_type = 'TRANSFER' THEN 1 END) as transfers,
                    COUNT(CASE WHEN movement_type = 'ADJUSTMENT' THEN 1 END) as adjustments
                FROM stock_movements
                WHERE DATE(movement_date) = CURDATE()
            ");

            // حركات الأسبوع
            $week = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                    COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues,
                    COUNT(CASE WHEN movement_type = 'TRANSFER' THEN 1 END) as transfers
                FROM stock_movements
                WHERE movement_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");

            // الأصناف الأكثر تداولاً
            $topProducts = $this->db->query("
                SELECT 
                    p.id,
                    p.name,
                    p.code,
                    COUNT(sm.id) as movement_count,
                    SUM(sm.quantity) as total_quantity
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                WHERE sm.movement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY p.id, p.name, p.code
                ORDER BY movement_count DESC
                LIMIT 10
            ");

            // المستخدمين الأكثر نشاطاً
            $topUsers = $this->db->query("
                SELECT 
                    u.id,
                    u.full_name,
                    COUNT(al.id) as actions,
                    COUNT(DISTINCT DATE(al.created_at)) as active_days,
                    COUNT(DISTINCT al.module) as modules_used
                FROM audit_logs al
                INNER JOIN users u ON u.id = al.user_id
                WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY u.id, u.full_name
                ORDER BY actions DESC
                LIMIT 10
            ");

            // حالة المخزون
            $stockStatus = $this->db->queryOne("
                SELECT 
                    COUNT(CASE WHEN sb.quantity <= p.min_stock AND sb.quantity > 0 THEN 1 END) as low_stock,
                    COUNT(CASE WHEN sb.quantity = 0 THEN 1 END) as out_of_stock,
                    COUNT(CASE WHEN sb.quantity >= p.max_stock THEN 1 END) as over_stock,
                    COUNT(CASE WHEN sb.quantity > p.min_stock AND sb.quantity < p.max_stock THEN 1 END) as normal
                FROM stock_balances sb
                INNER JOIN products p ON p.id = sb.product_id
            ");

            successResponse('تم جلب تقرير ملخص النظام', [
                'general' => $general,
                'today' => $today,
                'week' => $week,
                'top_products' => $topProducts,
                'top_users' => $topUsers,
                'stock_status' => $stockStatus,
                'generated_at' => date('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            error_log('Summary report error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/reports/top-products
     * تقرير الأصناف الأكثر تداولاً
     */
    public function topProducts(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'reports.view')) {
                errorResponse('ليس لديك صلاحية لعرض التقارير', 403);
                return;
            }

            $period = $_GET['period'] ?? '30';
            $limit = (int)($_GET['limit'] ?? 10);
            $warehouseId = $_GET['warehouse_id'] ?? null;

            $params = ['limit' => $limit];
            $where = ["sm.movement_date >= DATE_SUB(NOW(), INTERVAL :period DAY)"];
            $params['period'] = $period;

            if ($warehouseId) {
                $where[] = "sm.warehouse_id = :warehouse_id";
                $params['warehouse_id'] = $warehouseId;
            }

            $products = $this->db->query("
                SELECT 
                    p.id,
                    p.code,
                    p.name,
                    p.barcode,
                    c.name as category,
                    COUNT(sm.id) as movement_count,
                    SUM(sm.quantity) as total_quantity,
                    SUM(sm.total_cost) as total_value,
                    COUNT(DISTINCT sm.warehouse_id) as warehouses_count,
                    MIN(sm.movement_date) as first_movement,
                    MAX(sm.movement_date) as last_movement,
                    SUM(CASE WHEN sm.movement_type IN ('RECEIPT', 'TRANSFER_IN') THEN sm.quantity ELSE 0 END) as total_in,
                    SUM(CASE WHEN sm.movement_type IN ('ISSUE', 'TRANSFER_OUT') THEN sm.quantity ELSE 0 END) as total_out
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY p.id, p.code, p.name, p.barcode, c.name
                ORDER BY movement_count DESC
                LIMIT :limit
            ", $params);

            successResponse('تم جلب تقرير الأصناف الأكثر تداولاً', [
                'data' => $products,
                'meta' => [
                    'period' => $period . ' days',
                    'limit' => $limit,
                    'warehouse_id' => $warehouseId
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Top products error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/reports/inventory-value
     * تقرير قيمة المخزون
     */
    public function inventoryValue(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'reports.view')) {
                errorResponse('ليس لديك صلاحية لعرض التقارير', 403);
                return;
            }

            // قيمة المخزون الإجمالية
            $total = $this->db->queryOne("
                SELECT 
                    COALESCE(SUM(sb.quantity), 0) as total_quantity,
                    COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_cost,
                    COALESCE(SUM(sb.quantity * p.selling_price), 0) as total_sale_value,
                    COALESCE(SUM(sb.quantity * (p.selling_price - p.cost_price)), 0) as potential_profit
                FROM stock_balances sb
                INNER JOIN products p ON p.id = sb.product_id
            ");

            // قيمة المخزون حسب المخزن
            $byWarehouse = $this->db->query("
                SELECT 
                    w.id,
                    w.name,
                    COUNT(DISTINCT sb.product_id) as products_count,
                    COALESCE(SUM(sb.quantity), 0) as total_quantity,
                    COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value,
                    ROUND(COALESCE(SUM(sb.quantity * p.cost_price), 0) * 100.0 / NULLIF((SELECT COALESCE(SUM(sb2.quantity * p2.cost_price), 0) FROM stock_balances sb2 INNER JOIN products p2 ON p2.id = sb2.product_id), 0), 2) as percentage
                FROM warehouses w
                LEFT JOIN stock_balances sb ON sb.warehouse_id = w.id
                LEFT JOIN products p ON p.id = sb.product_id
                WHERE w.deleted_at IS NULL AND w.is_active = 1
                GROUP BY w.id, w.name
                ORDER BY total_value DESC
            ");

            // قيمة المخزون حسب التصنيف
            $byCategory = $this->db->query("
                SELECT 
                    c.id,
                    c.name,
                    COUNT(DISTINCT p.id) as products_count,
                    COALESCE(SUM(sb.quantity), 0) as total_quantity,
                    COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value,
                    ROUND(COALESCE(SUM(sb.quantity * p.cost_price), 0) * 100.0 / NULLIF((SELECT COALESCE(SUM(sb2.quantity * p2.cost_price), 0) FROM stock_balances sb2 INNER JOIN products p2 ON p2.id = sb2.product_id), 0), 2) as percentage
                FROM categories c
                LEFT JOIN products p ON p.category_id = c.id
                LEFT JOIN stock_balances sb ON sb.product_id = p.id
                WHERE c.deleted_at IS NULL AND c.is_active = 1
                GROUP BY c.id, c.name
                ORDER BY total_value DESC
            ");

            successResponse('تم جلب تقرير قيمة المخزون', [
                'total' => $total,
                'by_warehouse' => $byWarehouse,
                'by_category' => $byCategory,
                'generated_at' => date('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            error_log('Inventory value error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/reports/users
     * تقرير نشاط المستخدمين
     */
    public function users(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'reports.view')) {
                errorResponse('ليس لديك صلاحية لعرض التقارير', 403);
                return;
            }

            $period = $_GET['period'] ?? '30';
            $limit = (int)($_GET['limit'] ?? 20);

            $users = $this->db->query("
                SELECT 
                    u.id,
                    u.username,
                    u.full_name,
                    u.email,
                    r.name as role_name,
                    u.is_active,
                    u.last_login_at,
                    COUNT(al.id) as total_actions,
                    COUNT(DISTINCT DATE(al.created_at)) as active_days,
                    COUNT(DISTINCT al.module) as modules_used,
                    COUNT(DISTINCT al.action) as actions_types,
                    MIN(al.created_at) as first_action,
                    MAX(al.created_at) as last_action,
                    ROUND(AVG(TIMESTAMPDIFF(HOUR, al.created_at, LEAD(al.created_at) OVER (ORDER BY al.created_at))), 2) as avg_hours_between
                FROM users u
                LEFT JOIN audit_logs al ON al.user_id = u.id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE u.deleted_at IS NULL
                  AND al.created_at >= DATE_SUB(NOW(), INTERVAL :period DAY)
                GROUP BY u.id, u.username, u.full_name, u.email, r.name, u.is_active, u.last_login_at
                ORDER BY total_actions DESC
                LIMIT :limit
            ", ['period' => $period, 'limit' => $limit]);

            successResponse('تم جلب تقرير نشاط المستخدمين', [
                'data' => $users,
                'meta' => [
                    'period' => $period . ' days',
                    'limit' => $limit
                ]
            ]);

        } catch (\Exception $e) {
            error_log('Users report error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * إحصائيات الأرصدة
     */
    private function getStockStats(array $report): array
    {
        $stats = [
            'total_products' => count($report),
            'total_quantity' => 0,
            'total_value' => 0,
            'out_of_stock' => 0,
            'low_stock' => 0,
            'over_stock' => 0,
            'normal' => 0
        ];

        foreach ($report as $item) {
            $stats['total_quantity'] += $item['balance'];
            $stats['total_value'] += $item['total_value'];
            
            switch ($item['stock_status']) {
                case 'نفذ':
                    $stats['out_of_stock']++;
                    break;
                case 'منخفض':
                    $stats['low_stock']++;
                    break;
                case 'زائد':
                    $stats['over_stock']++;
                    break;
                default:
                    $stats['normal']++;
                    break;
            }
        }

        return $stats;
    }

    /**
     * إحصائيات الحركات
     */
    private function getMovementStats(array $report): array
    {
        $stats = [
            'total_movements' => count($report),
            'total_in' => 0,
            'total_out' => 0,
            'total_value' => 0,
            'by_type' => []
        ];

        foreach ($report as $item) {
            if ($item['movement_direction'] === 'in') {
                $stats['total_in'] += $item['quantity'];
            } elseif ($item['movement_direction'] === 'out') {
                $stats['total_out'] += $item['quantity'];
            }
            $stats['total_value'] += $item['total_cost'];
            
            $type = $item['movement_label'];
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = ['count' => 0, 'quantity' => 0];
            }
            $stats['by_type'][$type]['count']++;
            $stats['by_type'][$type]['quantity'] += $item['quantity'];
        }

        return $stats;
    }

    /**
     * حساب المتوسط اليومي
     */
    private function calculateAverageDaily(array $movements, string $fromDate, string $toDate): float
    {
        if (empty($movements)) {
            return 0;
        }

        $days = (strtotime($toDate) - strtotime($fromDate)) / 86400 + 1;
        return count($movements) / max($days, 1);
    }

    /**
     * تجميع حسب المفتاح
     */
    private function groupBy(array $data, string $key): array
    {
        $result = [];
        foreach ($data as $item) {
            $value = $item[$key] ?? 'غير معروف';
            if (!isset($result[$value])) {
                $result[$value] = 0;
            }
            $result[$value]++;
        }
        arsort($result);
        return $result;
    }

    /**
     * تصدير CSV لتقرير الأرصدة
     */
    private function exportStockCSV(array $data): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stock_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['الكود', 'الاسم', 'الباركود', 'التصنيف', 'المخزن', 'الكمية', 'المحجوز', 'المتاح', 'الحد الأدنى', 'الحد الأقصى', 'القيمة', 'الحالة']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['code'],
                $row['name'],
                $row['barcode'],
                $row['category'],
                $row['warehouse'],
                $row['balance'],
                $row['reserved'],
                $row['available'],
                $row['min_stock'],
                $row['max_stock'],
                $row['total_value'],
                $row['stock_status']
            ]);
        }
        
        fclose($output);
        exit;
    }

    /**
     * تصدير Excel لتقرير الأرصدة
     */
    private function exportStockExcel(array $data): void
    {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="stock_report_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1">';
        echo '<tr style="background:#667eea;color:#fff;font-weight:bold;">';
        echo '<th>الكود</th><th>الاسم</th><th>الباركود</th><th>التصنيف</th><th>المخزن</th><th>الكمية</th><th>المحجوز</th><th>المتاح</th><th>الحد الأدنى</th><th>الحد الأقصى</th><th>القيمة</th><th>الحالة</th>';
        echo '</tr>';
        
        foreach ($data as $row) {
            echo '<tr>';
            echo '<td>' . $row['code'] . '</td>';
            echo '<td>' . $row['name'] . '</td>';
            echo '<td>' . $row['barcode'] . '</td>';
            echo '<td>' . $row['category'] . '</td>';
            echo '<td>' . $row['warehouse'] . '</td>';
            echo '<td>' . $row['balance'] . '</td>';
            echo '<td>' . $row['reserved'] . '</td>';
            echo '<td>' . $row['available'] . '</td>';
            echo '<td>' . $row['min_stock'] . '</td>';
            echo '<td>' . $row['max_stock'] . '</td>';
            echo '<td>' . $row['total_value'] . '</td>';
            echo '<td>' . $row['stock_status'] . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        exit;
    }

    /**
     * تصدير PDF لتقرير الأرصدة (Placeholder)
     */
    private function exportStockPDF(array $data, array $stats): void
    {
        // يمكن استخدام مكتبة DomPDF أو TCPDF هنا
        successResponse('PDF generation requires external library', [
            'data' => $data,
            'stats' => $stats
        ]);
    }

    /**
     * تصدير CSV لتقرير الحركات
     */
    private function exportMovementsCSV(array $data): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="movements_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['التاريخ', 'النوع', 'المنتج', 'المخزن', 'الكمية', 'سعر الوحدة', 'القيمة', 'الرصيد قبل', 'الرصيد بعد', 'المستخدم', 'المرجع']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['movement_date'],
                $row['movement_label'],
                $row['product_name'],
                $row['warehouse'],
                $row['quantity'],
                $row['unit_cost'],
                $row['total_cost'],
                $row['balance_before'],
                $row['balance_after'],
                $row['user_name'],
                $row['reference_type'] . ' #' . $row['reference_id']
            ]);
        }
        
        fclose($output);
        exit;
    }

    /**
     * تصدير Excel لتقرير الحركات
     */
    private function exportMovementsExcel(array $data): void
    {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="movements_report_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1">';
        echo '<tr style="background:#667eea;color:#fff;font-weight:bold;">';
        echo '<th>التاريخ</th><th>النوع</th><th>المنتج</th><th>المخزن</th><th>الكمية</th><th>سعر الوحدة</th><th>القيمة</th><th>الرصيد قبل</th><th>الرصيد بعد</th><th>المستخدم</th><th>المرجع</th>';
        echo '</tr>';
        
        foreach ($data as $row) {
            echo '<tr>';
            echo '<td>' . $row['movement_date'] . '</td>';
            echo '<td>' . $row['movement_label'] . '</td>';
            echo '<td>' . $row['product_name'] . '</td>';
            echo '<td>' . $row['warehouse'] . '</td>';
            echo '<td>' . $row['quantity'] . '</td>';
            echo '<td>' . $row['unit_cost'] . '</td>';
            echo '<td>' . $row['total_cost'] . '</td>';
            echo '<td>' . $row['balance_before'] . '</td>';
            echo '<td>' . $row['balance_after'] . '</td>';
            echo '<td>' . $row['user_name'] . '</td>';
            echo '<td>' . $row['reference_type'] . ' #' . $row['reference_id'] . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        exit;
    }

    /**
     * تصدير CSV لتقرير المنتج
     */
    private function exportProductCSV(array $product, array $movements, array $balances): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="product_report_' . $product['code'] . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['تقرير المنتج: ' . $product['name']]);
        fputcsv($output, ['الكود', $product['code']]);
        fputcsv($output, ['الباركود', $product['barcode']]);
        fputcsv($output, ['التصنيف', $product['category_name']]);
        fputcsv($output, ['الوحدة', $product['unit_name']]);
        fputcsv($output, ['الكمية الإجمالية', $product['total_quantity']]);
        fputcsv($output, ['']);
        fputcsv($output, ['التفاصيل', 'المخزن', 'الكمية', 'المحجوز', 'المتاح', 'القيمة']);
        
        foreach ($balances as $balance) {
            fputcsv($output, [
                'رصيد',
                $balance['warehouse_name'],
                $balance['quantity'],
                $balance['reserved'],
                $balance['available'],
                $balance['total_value']
            ]);
        }
        
        fputcsv($output, ['']);
        fputcsv($output, ['التاريخ', 'النوع', 'المخزن', 'الكمية', 'سعر الوحدة', 'القيمة', 'الرصيد قبل', 'الرصيد بعد', 'المستخدم']);
        
        foreach ($movements as $movement) {
            fputcsv($output, [
                $movement['movement_date'],
                $movement['movement_label'],
                $movement['warehouse_name'],
                $movement['quantity'],
                $movement['unit_cost'],
                $movement['total_cost'],
                $movement['balance_before'],
                $movement['balance_after'],
                $movement['user_name']
            ]);
        }
        
        fclose($output);
        exit;
    }

    /**
     * تصدير CSV لتقرير التدقيق
     */
    private function exportAuditCSV(array $data): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['التاريخ', 'المستخدم', 'الإجراء', 'الوحدة', 'الوصف', 'IP', 'التفاصيل']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['created_at'],
                $row['user_full_name'] ?? $row['username'] ?? 'نظام',
                $row['action_label'],
                $row['module'],
                $row['description'],
                $row['ip_address'],
                is_array($row['details']) ? json_encode($row['details']) : $row['details']
            ]);
        }
        
        fclose($output);
        exit;
    }
}

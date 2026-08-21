<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/controllers/DashboardController.php
// الوصف: متحكم لوحة التحكم - الإحصائيات والرسوم البيانية
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;

class DashboardController
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
     * GET /api/dashboard
     * جلب جميع بيانات لوحة التحكم
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $stats = $this->getStats($userId);
            $charts = $this->getChartData();
            $recentActivities = $this->getRecentActivities();
            $alerts = $this->getAlerts($userId);
            
            successResponse('تم جلب بيانات لوحة التحكم', [
                'stats' => $stats,
                'charts' => $charts,
                'recent_activities' => $recentActivities,
                'alerts' => $alerts
            ]);

        } catch (\Exception $e) {
            error_log('Dashboard error: ' . $e->getMessage());
            errorResponse('حدث خطأ في جلب بيانات لوحة التحكم: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/dashboard/stats
     * جلب الإحصائيات فقط
     */
    public function stats(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $stats = $this->getStats($userId);
            successResponse('تم جلب الإحصائيات', $stats);

        } catch (\Exception $e) {
            error_log('Dashboard stats error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/dashboard/charts
     * جلب بيانات الرسوم البيانية
     */
    public function charts(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $charts = $this->getChartData();
            successResponse('تم جلب بيانات الرسوم البيانية', $charts);

        } catch (\Exception $e) {
            error_log('Dashboard charts error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/dashboard/alerts
     * جلب التنبيهات
     */
    public function alerts(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $alerts = $this->getAlerts($userId);
            successResponse('تم جلب التنبيهات', $alerts);

        } catch (\Exception $e) {
            error_log('Dashboard alerts error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/dashboard/activities
     * جلب آخر الأنشطة
     */
    public function activities(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $activities = $this->getRecentActivities();
            successResponse('تم جلب آخر الأنشطة', $activities);

        } catch (\Exception $e) {
            error_log('Dashboard activities error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/dashboard/status
     * جلب حالة النظام
     */
    public function status(): void
    {
        try {
            $status = [
                'server' => [
                    'status' => 'online',
                    'php_version' => PHP_VERSION,
                    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                    'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                    'uptime' => $this->getServerUptime()
                ],
                'database' => [
                    'status' => $this->checkDatabaseConnection() ? 'online' : 'offline',
                    'connections' => $this->getDatabaseConnections(),
                    'size' => $this->getDatabaseSize()
                ],
                'system' => [
                    'version' => VERSION,
                    'environment' => $_ENV['APP_ENV'] ?? 'production',
                    'timezone' => date_default_timezone_get(),
                    'current_time' => date('Y-m-d H:i:s')
                ]
            ];
            
            successResponse('تم جلب حالة النظام', $status);

        } catch (\Exception $e) {
            error_log('Dashboard status error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage());
        }
    }

    // ================================================================
    // دوال جلب البيانات
    // ================================================================

    /**
     * الحصول على الإحصائيات
     */
    private function getStats(int $userId): array
    {
        $stats = [];

        // إجمالي الأصناف
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as total, 
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive
             FROM products WHERE deleted_at IS NULL"
        );
        $stats['products'] = [
            'total' => (int)($result['total'] ?? 0),
            'active' => (int)($result['active'] ?? 0),
            'inactive' => (int)($result['inactive'] ?? 0)
        ];

        // إجمالي المخازن
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as total, 
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active 
             FROM warehouses WHERE deleted_at IS NULL"
        );
        $stats['warehouses'] = [
            'total' => (int)($result['total'] ?? 0),
            'active' => (int)($result['active'] ?? 0)
        ];

        // إجمالي المستخدمين
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as total, 
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN is_locked = 1 THEN 1 END) as locked
             FROM users WHERE deleted_at IS NULL"
        );
        $stats['users'] = [
            'total' => (int)($result['total'] ?? 0),
            'active' => (int)($result['active'] ?? 0),
            'locked' => (int)($result['locked'] ?? 0)
        ];

        // إجمالي الموردين
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as total FROM suppliers WHERE deleted_at IS NULL AND is_active = 1"
        );
        $stats['suppliers'] = (int)($result['total'] ?? 0);

        // حركات اليوم
        $result = $this->db->queryOne(
            "SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues,
                COUNT(CASE WHEN movement_type = 'TRANSFER_OUT' THEN 1 END) as transfers,
                COUNT(CASE WHEN movement_type = 'RETURN_IN' THEN 1 END) as returns_in,
                COUNT(CASE WHEN movement_type = 'ADJUSTMENT' THEN 1 END) as adjustments
             FROM stock_movements 
             WHERE DATE(movement_date) = CURDATE()"
        );
        $stats['today_movements'] = [
            'total' => (int)($result['total'] ?? 0),
            'receipts' => (int)($result['receipts'] ?? 0),
            'issues' => (int)($result['issues'] ?? 0),
            'transfers' => (int)($result['transfers'] ?? 0),
            'returns_in' => (int)($result['returns_in'] ?? 0),
            'adjustments' => (int)($result['adjustments'] ?? 0)
        ];

        // حركات الأسبوع
        $movementsWeekly = $this->db->query(
            "SELECT 
                DATE(movement_date) as date,
                COUNT(*) as total,
                COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues,
                COUNT(CASE WHEN movement_type = 'TRANSFER_OUT' THEN 1 END) as transfers
             FROM stock_movements 
             WHERE movement_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(movement_date)
             ORDER BY date ASC"
        );
        $stats['movements_weekly'] = $movementsWeekly;

        // قيمة المخزون
        $result = $this->db->queryOne(
            "SELECT 
                COALESCE(SUM(sb.quantity), 0) as total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value,
                COALESCE(SUM(sb.reserved_quantity), 0) as reserved_quantity
             FROM stock_balances sb
             INNER JOIN products p ON p.id = sb.product_id"
        );
        $stats['inventory'] = [
            'total_quantity' => (float)($result['total_quantity'] ?? 0),
            'total_value' => (float)($result['total_value'] ?? 0),
            'reserved_quantity' => (float)($result['reserved_quantity'] ?? 0)
        ];

        // حالة المخزون
        $result = $this->db->queryOne(
            "SELECT 
                COUNT(CASE WHEN sb.quantity <= 0 THEN 1 END) as out_of_stock,
                COUNT(CASE WHEN sb.quantity <= p.min_stock AND sb.quantity > 0 THEN 1 END) as low_stock,
                COUNT(CASE WHEN sb.quantity >= p.max_stock AND p.max_stock IS NOT NULL THEN 1 END) as over_stock,
                COUNT(CASE WHEN sb.quantity > p.min_stock AND (sb.quantity < p.max_stock OR p.max_stock IS NULL) THEN 1 END) as normal
             FROM stock_balances sb
             INNER JOIN products p ON p.id = sb.product_id"
        );
        $stats['stock_status'] = [
            'out_of_stock' => (int)($result['out_of_stock'] ?? 0),
            'low_stock' => (int)($result['low_stock'] ?? 0),
            'over_stock' => (int)($result['over_stock'] ?? 0),
            'normal' => (int)($result['normal'] ?? 0)
        ];

        // الأصناف المنخفضة (تفاصيل)
        $lowStockItems = $this->db->query(
            "SELECT 
                p.id, p.code, p.name,
                w.id as warehouse_id, w.name as warehouse_name,
                sb.quantity, p.min_stock,
                (p.min_stock - sb.quantity) as shortage
             FROM stock_balances sb
             INNER JOIN products p ON p.id = sb.product_id
             INNER JOIN warehouses w ON w.id = sb.warehouse_id
             WHERE sb.quantity <= p.min_stock AND sb.quantity > 0
             ORDER BY (sb.quantity / p.min_stock) ASC
             LIMIT 10"
        );
        $stats['low_stock_items'] = $lowStockItems;

        // الأصناف المنفذة (تفاصيل)
        $outOfStockItems = $this->db->query(
            "SELECT 
                p.id, p.code, p.name,
                w.id as warehouse_id, w.name as warehouse_name
             FROM stock_balances sb
             INNER JOIN products p ON p.id = sb.product_id
             INNER JOIN warehouses w ON w.id = sb.warehouse_id
             WHERE sb.quantity = 0
             LIMIT 10"
        );
        $stats['out_of_stock_items'] = $outOfStockItems;

        // الجلسات النشطة
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as active_sessions 
             FROM user_sessions 
             WHERE is_active = 1 AND expires_at > NOW()"
        );
        $stats['active_sessions'] = (int)($result['active_sessions'] ?? 0);

        // التنبيهات غير المقروءة للمستخدم
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as unread 
             FROM notifications 
             WHERE user_id = :user_id AND is_read = 0",
            ['user_id' => $userId]
        );
        $stats['unread_notifications'] = (int)($result['unread'] ?? 0);

        // إجمالي التنبيهات
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as total_notifications FROM notifications WHERE user_id = :user_id",
            ['user_id' => $userId]
        );
        $stats['total_notifications'] = (int)($result['total_notifications'] ?? 0);

        // الموردين النشطين
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as active_suppliers FROM suppliers WHERE is_active = 1 AND deleted_at IS NULL"
        );
        $stats['active_suppliers'] = (int)($result['active_suppliers'] ?? 0);

        return $stats;
    }

    /**
     * الحصول على بيانات الرسوم البيانية
     */
    private function getChartData(): array
    {
        $charts = [];

        // حركات آخر 30 يوم
        $movements = $this->db->query(
            "SELECT 
                DATE(movement_date) as date,
                COUNT(*) as total,
                COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues,
                COUNT(CASE WHEN movement_type = 'TRANSFER_OUT' THEN 1 END) as transfers,
                COUNT(CASE WHEN movement_type = 'RETURN_IN' THEN 1 END) as returns_in
             FROM stock_movements 
             WHERE movement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(movement_date)
             ORDER BY date ASC"
        );
        $charts['movements_30days'] = $movements;

        // الأصناف الأكثر تداولاً
        $topProducts = $this->db->query(
            "SELECT 
                p.id,
                p.code,
                p.name,
                COUNT(sm.id) as movement_count,
                SUM(sm.quantity) as total_quantity,
                SUM(sm.total_cost) as total_value
             FROM stock_movements sm
             INNER JOIN products p ON p.id = sm.product_id
             WHERE sm.movement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY p.id, p.code, p.name
             ORDER BY movement_count DESC
             LIMIT 10"
        );
        $charts['top_products'] = $topProducts;

        // توزيع المخزون حسب التصنيف
        $categoryDistribution = $this->db->query(
            "SELECT 
                c.id,
                c.name as category,
                COUNT(DISTINCT p.id) as product_count,
                COALESCE(SUM(sb.quantity), 0) as total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value
             FROM categories c
             INNER JOIN products p ON p.category_id = c.id
             INNER JOIN stock_balances sb ON sb.product_id = p.id
             WHERE p.deleted_at IS NULL
               AND c.is_active = 1
             GROUP BY c.id, c.name
             ORDER BY total_value DESC
             LIMIT 10"
        );
        $charts['category_distribution'] = $categoryDistribution;

        // نشاط المستخدمين
        $userActivity = $this->db->query(
            "SELECT 
                u.id,
                u.full_name,
                COUNT(sm.id) as movements,
                COUNT(DISTINCT DATE(sm.movement_date)) as active_days,
                COUNT(DISTINCT sm.warehouse_id) as warehouses_used
             FROM users u
             INNER JOIN stock_movements sm ON sm.user_id = u.id
             WHERE sm.movement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND u.is_active = 1
             GROUP BY u.id, u.full_name
             ORDER BY movements DESC
             LIMIT 10"
        );
        $charts['user_activity'] = $userActivity;

        // حركات اليوم حسب الساعة
        $hourlyMovements = $this->db->query(
            "SELECT 
                HOUR(movement_date) as hour,
                COUNT(*) as total,
                COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues
             FROM stock_movements 
             WHERE DATE(movement_date) = CURDATE()
             GROUP BY HOUR(movement_date)
             ORDER BY hour ASC"
        );
        $charts['hourly_movements'] = $hourlyMovements;

        // قيمة المخزون حسب المخزن
        $warehouseValue = $this->db->query(
            "SELECT 
                w.id,
                w.name,
                COUNT(DISTINCT sb.product_id) as product_count,
                COALESCE(SUM(sb.quantity), 0) as total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value
             FROM warehouses w
             INNER JOIN stock_balances sb ON sb.warehouse_id = w.id
             INNER JOIN products p ON p.id = sb.product_id
             WHERE w.deleted_at IS NULL
               AND w.is_active = 1
             GROUP BY w.id, w.name
             ORDER BY total_value DESC"
        );
        $charts['warehouse_value'] = $warehouseValue;

        return $charts;
    }

    /**
     * الحصول على آخر الأنشطة
     */
    private function getRecentActivities(): array
    {
        return $this->db->query(
            "SELECT 
                al.id,
                al.user_id,
                al.username,
                u.full_name as user_full_name,
                al.action,
                al.module,
                al.description,
                al.created_at,
                al.ip_address,
                CASE 
                    WHEN al.action = 'LOGIN_SUCCESS' THEN 'success'
                    WHEN al.action = 'LOGIN_FAILED' THEN 'danger'
                    WHEN al.action = 'LOGOUT' THEN 'info'
                    WHEN al.action LIKE '%CREATE%' OR al.action LIKE '%CREATED%' THEN 'primary'
                    WHEN al.action LIKE '%UPDATE%' OR al.action LIKE '%UPDATED%' THEN 'warning'
                    WHEN al.action LIKE '%DELETE%' OR al.action LIKE '%DELETED%' THEN 'danger'
                    ELSE 'secondary'
                END as type,
                CASE 
                    WHEN al.action = 'LOGIN_SUCCESS' THEN 'تسجيل دخول'
                    WHEN al.action = 'LOGIN_FAILED' THEN 'محاولة دخول فاشلة'
                    WHEN al.action = 'LOGOUT' THEN 'تسجيل خروج'
                    WHEN al.action LIKE '%CREATE%' OR al.action LIKE '%CREATED%' THEN 'إنشاء'
                    WHEN al.action LIKE '%UPDATE%' OR al.action LIKE '%UPDATED%' THEN 'تحديث'
                    WHEN al.action LIKE '%DELETE%' OR al.action LIKE '%DELETED%' THEN 'حذف'
                    ELSE al.action
                END as action_label
             FROM audit_logs al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.user_id IS NOT NULL
             ORDER BY al.created_at DESC
             LIMIT 20"
        );
    }

    /**
     * الحصول على التنبيهات
     */
    private function getAlerts(int $userId): array
    {
        $alerts = [];

        // تنبيهات المخزون المنخفض
        $lowStock = $this->db->query(
            "SELECT 
                p.id,
                p.code,
                p.name,
                w.name as warehouse,
                sb.quantity,
                p.min_stock,
                (p.min_stock - sb.quantity) as shortage
             FROM stock_balances sb
             INNER JOIN products p ON p.id = sb.product_id
             INNER JOIN warehouses w ON w.id = sb.warehouse_id
             WHERE sb.quantity <= p.min_stock
               AND sb.quantity > 0
               AND p.is_active = 1
             ORDER BY (sb.quantity / p.min_stock) ASC
             LIMIT 10"
        );
        
        foreach ($lowStock as $item) {
            $alerts[] = [
                'type' => 'warning',
                'priority' => 'high',
                'title' => 'مخزون منخفض',
                'message' => "المنتج '{$item['name']}' في مخزن '{$item['warehouse']}' وصل للحد الأدنى ({$item['quantity']} / {$item['min_stock']})",
                'link' => "/products/{$item['id']}",
                'reference_type' => 'product',
                'reference_id' => $item['id'],
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // تنبيهات المخزون الصفر
        $outOfStock = $this->db->query(
            "SELECT 
                p.id,
                p.code,
                p.name,
                w.name as warehouse
             FROM stock_balances sb
             INNER JOIN products p ON p.id = sb.product_id
             INNER JOIN warehouses w ON w.id = sb.warehouse_id
             WHERE sb.quantity = 0
               AND p.is_active = 1
             LIMIT 10"
        );
        
        foreach ($outOfStock as $item) {
            $alerts[] = [
                'type' => 'danger',
                'priority' => 'critical',
                'title' => '⚠️ نفاذ المخزون',
                'message' => "المنتج '{$item['name']}' في مخزن '{$item['warehouse']}' نفد من المخزون",
                'link' => "/products/{$item['id']}",
                'reference_type' => 'product',
                'reference_id' => $item['id'],
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // تنبيهات انتهاء الصلاحية
        $expired = $this->db->query(
            "SELECT 
                p.id,
                p.code,
                p.name,
                pb.expiry_date,
                DATEDIFF(pb.expiry_date, CURDATE()) as days_left
             FROM product_batches pb
             INNER JOIN products p ON p.id = pb.product_id
             WHERE pb.expiry_date IS NOT NULL
               AND pb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
               AND pb.quantity > 0
             ORDER BY pb.expiry_date ASC
             LIMIT 10"
        );
        
        foreach ($expired as $item) {
            $status = $item['days_left'] < 0 ? 'انتهت الصلاحية' : "تنتهي خلال {$item['days_left']} يوم";
            $alerts[] = [
                'type' => $item['days_left'] < 0 ? 'danger' : 'warning',
                'priority' => $item['days_left'] < 0 ? 'critical' : 'high',
                'title' => '⚠️ انتهاء الصلاحية',
                'message' => "المنتج '{$item['name']}' - {$status}",
                'link' => "/products/{$item['id']}",
                'reference_type' => 'product',
                'reference_id' => $item['id'],
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        // تنبيهات من قاعدة البيانات
        $dbAlerts = $this->db->query(
            "SELECT * FROM notifications 
             WHERE user_id = :user_id AND is_read = 0
             ORDER BY priority DESC, created_at DESC
             LIMIT 5",
            ['user_id' => $userId]
        );
        
        foreach ($dbAlerts as $alert) {
            $alerts[] = [
                'type' => $this->getAlertType($alert['priority']),
                'priority' => $alert['priority'],
                'title' => $alert['title'],
                'message' => $alert['message'],
                'link' => $alert['link'] ?? null,
                'reference_type' => $alert['reference_type'] ?? null,
                'reference_id' => $alert['reference_id'] ?? null,
                'created_at' => $alert['created_at']
            ];
        }

        // ترتيب التنبيهات حسب الأولوية
        usort($alerts, function($a, $b) {
            $priority = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            return ($priority[$a['priority']] ?? 4) - ($priority[$b['priority']] ?? 4);
        });

        return array_slice($alerts, 0, 20);
    }

    /**
     * الحصول على نوع التنبيه حسب الأولوية
     */
    private function getAlertType(string $priority): string
    {
        switch ($priority) {
            case 'critical':
                return 'danger';
            case 'high':
                return 'warning';
            case 'medium':
                return 'info';
            case 'low':
                return 'success';
            default:
                return 'info';
        }
    }

    /**
     * التحقق من اتصال قاعدة البيانات
     */
    private function checkDatabaseConnection(): bool
    {
        try {
            $this->db->queryOne("SELECT 1");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * الحصول على عدد اتصالات قاعدة البيانات
     */
    private function getDatabaseConnections(): int
    {
        try {
            $result = $this->db->queryOne("SHOW STATUS LIKE 'Threads_connected'");
            return (int)($result['Value'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * الحصول على حجم قاعدة البيانات
     */
    private function getDatabaseSize(): string
    {
        try {
            $result = $this->db->queryOne(
                "SELECT SUM(data_length + index_length) / 1024 / 1024 as size_mb
                 FROM information_schema.tables 
                 WHERE table_schema = :database",
                ['database' => DB_NAME]
            );
            $size = (float)($result['size_mb'] ?? 0);
            if ($size > 1024) {
                return round($size / 1024, 2) . ' GB';
            }
            return round($size, 2) . ' MB';
        } catch (\Exception $e) {
            return 'غير معروف';
        }
    }

    /**
     * الحصول على وقت تشغيل الخادم
     */
    private function getServerUptime(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'غير معروف (Windows)';
        }
        
        try {
            $uptime = shell_exec('uptime -p');
            return trim($uptime) ?: 'غير معروف';
        } catch (\Exception $e) {
            return 'غير معروف';
        }
    }
}

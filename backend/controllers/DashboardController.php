<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/DashboardController.php
// الوصف: متحكم لوحة التحكم - مع بيانات المخازن للرسم البياني وإشعارات
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Session;
use Exception;

class DashboardController
{
    /**
     * @var Database $db
     */
    private $db;
    
    /**
     * @var Session $session
     */
    private $session;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->session = new Session();
    }

    /**
     * ============================================================
     * GET /api/dashboard
     * جلب جميع بيانات لوحة التحكم
     * ============================================================
     */
    public function index(): void
    {
        try {
            $userId = $this->validateToken();
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $stats = $this->getStats();
            $charts = $this->getChartData();
            $activities = $this->getRecentActivities();
            $notifications = $this->getNotifications($userId);
            
            successResponse('تم جلب بيانات لوحة التحكم', [
                'stats' => $stats,
                'charts' => $charts,
                'recent_activities' => $activities,
                'notifications' => $notifications,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            error_log('Dashboard error: ' . $e->getMessage());
            errorResponse('حدث خطأ في جلب بيانات لوحة التحكم: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ============================================================
     * GET /api/dashboard/stats
     * جلب الإحصائيات فقط
     * ============================================================
     */
    public function stats(): void
    {
        try {
            $userId = $this->validateToken();
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $stats = $this->getStats();
            successResponse('تم جلب الإحصائيات', $stats);

        } catch (Exception $e) {
            error_log('Dashboard stats error: ' . $e->getMessage());
            errorResponse('حدث خطأ في جلب الإحصائيات: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ============================================================
     * GET /api/dashboard/charts
     * جلب بيانات الرسوم البيانية
     * ============================================================
     */
    public function charts(): void
    {
        try {
            $userId = $this->validateToken();
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $charts = $this->getChartData();
            successResponse('تم جلب بيانات الرسوم البيانية', $charts);

        } catch (Exception $e) {
            error_log('Dashboard charts error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ============================================================
     * GET /api/dashboard/activities
     * جلب آخر الأنشطة
     * ============================================================
     */
    public function activities(): void
    {
        try {
            $userId = $this->validateToken();
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $activities = $this->getRecentActivities();
            successResponse('تم جلب آخر الأنشطة', $activities);

        } catch (Exception $e) {
            error_log('Dashboard activities error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ============================================================
     * GET /api/dashboard/notifications
     * جلب الإشعارات فقط
     * ============================================================
     */
    public function notifications(): void
    {
        try {
            $userId = $this->validateToken();
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $notifications = $this->getNotifications($userId);
            successResponse('تم جلب الإشعارات', $notifications);

        } catch (Exception $e) {
            error_log('Dashboard notifications error: ' . $e->getMessage());
            errorResponse('حدث خطأ في جلب الإشعارات: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ============================================================
     * POST /api/dashboard/notifications/read
     * تعيين إشعار كمقروء
     * ============================================================
     */
    public function markNotificationRead(): void
    {
        try {
            $userId = $this->validateToken();
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $notificationId = $input['notification_id'] ?? null;

            if (!$notificationId) {
                errorResponse('معرف الإشعار مطلوب');
                return;
            }

            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$notificationId, $userId]);

            successResponse('تم تعيين الإشعار كمقروء');

        } catch (Exception $e) {
            error_log('Mark notification read error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ============================================================
     * POST /api/dashboard/notifications/read-all
     * تعيين جميع الإشعارات كمقروءة
     * ============================================================
     */
    public function markAllNotificationsRead(): void
    {
        try {
            $userId = $this->validateToken();
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$userId]);

            successResponse('تم تعيين جميع الإشعارات كمقروءة');

        } catch (Exception $e) {
            error_log('Mark all notifications read error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ============================================================
     * التحقق من التوكن وإرجاع معرف المستخدم
     * ============================================================
     */
    private function validateToken(): ?int
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        $token = str_replace('Bearer ', '', $authHeader);

        if (empty($token)) {
            return null;
        }

        $decoded = base64_decode($token);
        $parts = explode(':', $decoded);
        $userId = $parts[0] ?? 0;

        if (!$userId) {
            return null;
        }

        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("SELECT id, is_active, deleted_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user || !$user['is_active'] || $user['deleted_at'] !== null) {
                return null;
            }

            return (int)$userId;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * ============================================================
     * الحصول على الإحصائيات (مع بيانات المخازن للرسم البياني)
     * ============================================================
     */
    private function getStats(): array
    {
        $pdo = $this->db->getConnection();
        $stats = [];

        // 1. إجمالي الأصناف
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM products WHERE deleted_at IS NULL");
        $stats['products'] = ['total' => (int)$stmt->fetch()['total']];

        // 2. إجمالي المخازن
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM warehouses WHERE deleted_at IS NULL AND is_active = 1");
        $stats['warehouses'] = ['total' => (int)$stmt->fetch()['total']];

        // 3. إجمالي المستخدمين
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL AND is_active = 1");
        $stats['users'] = ['total' => (int)$stmt->fetch()['total']];

        // 4. حركات اليوم
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues,
                COUNT(CASE WHEN movement_type = 'TRANSFER_OUT' THEN 1 END) as transfers
            FROM stock_movements 
            WHERE DATE(movement_date) = CURDATE()
        ");
        $row = $stmt->fetch();
        $stats['today_movements'] = [
            'total' => (int)($row['total'] ?? 0),
            'receipts' => (int)($row['receipts'] ?? 0),
            'issues' => (int)($row['issues'] ?? 0),
            'transfers' => (int)($row['transfers'] ?? 0)
        ];

        // 5. حالة المخزون
        $stmt = $pdo->query("
            SELECT 
                COUNT(CASE WHEN sb.quantity <= 0 THEN 1 END) as out_of_stock,
                COUNT(CASE WHEN sb.quantity <= p.min_stock AND sb.quantity > 0 THEN 1 END) as low_stock,
                COUNT(CASE WHEN sb.quantity >= p.max_stock AND p.max_stock IS NOT NULL THEN 1 END) as over_stock
            FROM stock_balances sb
            INNER JOIN products p ON p.id = sb.product_id
        ");
        $row = $stmt->fetch();
        $stats['stock_status'] = [
            'out_of_stock' => (int)($row['out_of_stock'] ?? 0),
            'low_stock' => (int)($row['low_stock'] ?? 0),
            'over_stock' => (int)($row['over_stock'] ?? 0)
        ];

        // ✅ 6. بيانات المخازن للرسم البياني (الأهم)
        $warehouseBalances = $pdo->query("
            SELECT 
                w.id,
                w.name,
                COALESCE(SUM(sb.quantity), 0) as total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value
            FROM warehouses w
            LEFT JOIN stock_balances sb ON sb.warehouse_id = w.id
            LEFT JOIN products p ON p.id = sb.product_id
            WHERE w.deleted_at IS NULL AND w.is_active = 1
            GROUP BY w.id, w.name
            ORDER BY total_value DESC
        ");
        $stats['warehouse_balances'] = $warehouseBalances->fetchAll();

        return $stats;
    }

    /**
     * ============================================================
     * الحصول على بيانات الرسوم البيانية
     * ============================================================
     */
    private function getChartData(): array
    {
        $pdo = $this->db->getConnection();
        $charts = [];

        // 1. حركات آخر 7 أيام
        $stmt = $pdo->query("
            SELECT 
                DATE(movement_date) as date,
                DAYNAME(movement_date) as day_name,
                COUNT(*) as total,
                COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues,
                COUNT(CASE WHEN movement_type = 'TRANSFER_OUT' THEN 1 END) as transfers
            FROM stock_movements 
            WHERE movement_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(movement_date)
            ORDER BY date ASC
        ");
        $charts['weekly_movements'] = $stmt->fetchAll();

        // 2. حركات آخر 30 يوم
        $stmt = $pdo->query("
            SELECT 
                DATE(movement_date) as date,
                COUNT(*) as total,
                COUNT(CASE WHEN movement_type = 'RECEIPT' THEN 1 END) as receipts,
                COUNT(CASE WHEN movement_type = 'ISSUE' THEN 1 END) as issues,
                COUNT(CASE WHEN movement_type = 'TRANSFER_OUT' THEN 1 END) as transfers
            FROM stock_movements 
            WHERE movement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(movement_date)
            ORDER BY date ASC
        ");
        $charts['monthly_movements'] = $stmt->fetchAll();

        return $charts;
    }

    /**
     * ============================================================
     * الحصول على آخر الأنشطة
     * ============================================================
     */
    private function getRecentActivities(): array
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->query("
            SELECT 
                al.id,
                al.user_id,
                al.username,
                al.action,
                al.module,
                al.description,
                al.ip_address,
                al.created_at,
                u.full_name as user_name
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.user_id IS NOT NULL
            ORDER BY al.created_at DESC
            LIMIT 10
        ");
        return $stmt->fetchAll();
    }

    /**
     * ============================================================
     * الحصول على الإشعارات
     * ============================================================
     */
    private function getNotifications(int $userId): array
    {
        $pdo = $this->db->getConnection();
        
        $stmt = $pdo->prepare("
            SELECT 
                id,
                type,
                title,
                message,
                is_read,
                priority,
                reference_type,
                reference_id,
                link,
                created_at,
                CASE 
                    WHEN priority = 'critical' THEN 'danger'
                    WHEN priority = 'high' THEN 'warning'
                    WHEN priority = 'medium' THEN 'info'
                    WHEN priority = 'low' THEN 'success'
                    ELSE 'secondary'
                END as priority_color,
                CASE 
                    WHEN type = 'low_stock' THEN 'fa-exclamation-triangle'
                    WHEN type = 'out_of_stock' THEN 'fa-times-circle'
                    WHEN type = 'over_stock' THEN 'fa-arrow-up'
                    WHEN type = 'expiry_alert' THEN 'fa-clock'
                    WHEN type = 'system_warning' THEN 'fa-exclamation-circle'
                    WHEN type = 'transaction_alert' THEN 'fa-exchange-alt'
                    WHEN type = 'approval_needed' THEN 'fa-check-circle'
                    WHEN type = 'transfer_completed' THEN 'fa-check'
                    WHEN type = 'receipt_completed' THEN 'fa-arrow-down'
                    WHEN type = 'issue_completed' THEN 'fa-arrow-up'
                    WHEN type = 'inventory_completed' THEN 'fa-clipboard-check'
                    ELSE 'fa-bell'
                END as icon,
                CASE 
                    WHEN type = 'low_stock' THEN 'warning'
                    WHEN type = 'out_of_stock' THEN 'danger'
                    WHEN type = 'over_stock' THEN 'info'
                    WHEN type = 'expiry_alert' THEN 'warning'
                    WHEN type = 'system_warning' THEN 'danger'
                    WHEN type = 'transaction_alert' THEN 'info'
                    WHEN type = 'approval_needed' THEN 'primary'
                    WHEN type = 'transfer_completed' THEN 'success'
                    WHEN type = 'receipt_completed' THEN 'success'
                    WHEN type = 'issue_completed' THEN 'success'
                    WHEN type = 'inventory_completed' THEN 'success'
                    ELSE 'info'
                END as type_class
            FROM notifications 
            WHERE user_id = ?
            ORDER BY is_read ASC, priority DESC, created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$userId]);
        $notifications = $stmt->fetchAll();

        // تنسيق الوقت
        foreach ($notifications as &$notif) {
            $notif['time_ago'] = $this->timeAgo($notif['created_at']);
            $notif['is_read'] = (bool)$notif['is_read'];
        }

        return $notifications;
    }

    /**
     * ============================================================
     * حساب الوقت المنقضي
     * ============================================================
     */
    private function timeAgo(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;
        
        $units = [
            31536000 => 'سنة',
            2592000 => 'شهر',
            604800 => 'أسبوع',
            86400 => 'يوم',
            3600 => 'ساعة',
            60 => 'دقيقة',
            1 => 'ثانية'
        ];
        
        foreach ($units as $seconds => $unit) {
            if ($diff >= $seconds) {
                $count = floor($diff / $seconds);
                $text = $count . ' ' . $unit;
                if ($count > 1) {
                    $text .= $unit === 'سنة' ? 'ات' : ($unit === 'شهر' ? 'ور' : ($unit === 'أسبوع' ? 'وع' : ($unit === 'يوم' ? 'اً' : ($unit === 'ساعة' ? 'ات' : ($unit === 'دقيقة' ? 'ق' : 'ات')))));
                }
                return $text . ' ago';
            }
        }
        
        return 'الآن';
    }
}

// ================================================================
// انتهى الملف
// ================================================================

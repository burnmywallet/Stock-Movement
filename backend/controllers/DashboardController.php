<?php

/**
 * ================================================================
 * Logistox - Dashboard Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/DashboardController.php
 * الوظيفة: لوحة التحكم الرئيسية - عرض الإحصائيات والبيانات
 *
 * المسؤوليات:
 * 1. جلب الإحصائيات الرئيسية (stats)
 * 2. بيانات الرسوم البيانية (charts)
 * 3. التنبيهات والإشعارات (alerts)
 * 4. آخر الأنشطة والعمليات (activities)
 * 5. حالة النظام (status)
 *
 * ملاحظات هامة:
 * - يعتمد على جداول: products, warehouses, users, stock_balances,
 *   stock_movements, receipts, issues, transfers, notifications
 * - يستخدم استعلامات محسّنة مع JOINs و Indexes
 * - يدعم الفلاتر (warehouse_id, date_range)
 * - يحدد LIMIT للبيانات الكبيرة لتجنب الحمل الزائد
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use Throwable;
use Exception;

/**
 * Class DashboardController
 *
 * Controller للوحة التحكم الرئيسية
 */
class DashboardController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
        } catch (Throwable $e) {
            error_log('[DASHBOARD] Database connection failed: ' . $e->getMessage());
            Response::internalError('فشل الاتصال بقاعدة البيانات');
        }
    }

    // =========================================================================
    // 1. الإحصائيات الرئيسية (Stats)
    // =========================================================================

    /**
     * جلب الإحصائيات الرئيسية للوحة التحكم
     *
     * GET /api/dashboard/stats
     *
     * @return void يرسل استجابة JSON
     */
    public function stats(): void
    {
        try {
            // 1. إحصائيات المنتجات
            $productStats = $this->getProductStats();

            // 2. إحصائيات المخازن
            $warehouseStats = $this->getWarehouseStats();

            // 3. إحصائيات المستخدمين
            $userStats = $this->getUserStats();

            // 4. إحصائيات المخزون
            $stockStats = $this->getStockStats();

            // 5. إحصائيات العمليات (Receipts, Issues, Transfers)
            $operationStats = $this->getOperationStats();

            // 6. تجميع كل الإحصائيات
            $data = [
                'products'   => $productStats,
                'warehouses' => $warehouseStats,
                'users'      => $userStats,
                'stock'      => $stockStats,
                'operations' => $operationStats,
                'generated_at' => date('Y-m-d H:i:s'),
            ];

            Response::success('تم جلب الإحصائيات بنجاح', $data);

        } catch (Throwable $e) {
            error_log('[DASHBOARD] Stats failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الإحصائيات');
        }
    }

    /**
     * إحصائيات المنتجات
     */
    private function getProductStats(): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive,
                SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS deleted
            FROM products
            WHERE 1=1
        ";

        $result = $this->db->selectOne($sql);

        return [
            'total'    => (int) ($result['total'] ?? 0),
            'active'   => (int) ($result['active'] ?? 0),
            'inactive' => (int) ($result['inactive'] ?? 0),
            'deleted'  => (int) ($result['deleted'] ?? 0),
        ];
    }

    /**
     * إحصائيات المخازن
     */
    private function getWarehouseStats(): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN type = 'main' THEN 1 ELSE 0 END) AS main,
                SUM(CASE WHEN type = 'sub' THEN 1 ELSE 0 END) AS sub,
                SUM(CASE WHEN type = 'cold' THEN 1 ELSE 0 END) AS cold,
                SUM(CASE WHEN type = 'freezer' THEN 1 ELSE 0 END) AS freezer
            FROM warehouses
            WHERE deleted_at IS NULL
        ";

        $result = $this->db->selectOne($sql);

        return [
            'total'   => (int) ($result['total'] ?? 0),
            'active'  => (int) ($result['active'] ?? 0),
            'main'    => (int) ($result['main'] ?? 0),
            'sub'     => (int) ($result['sub'] ?? 0),
            'cold'    => (int) ($result['cold'] ?? 0),
            'freezer' => (int) ($result['freezer'] ?? 0),
        ];
    }

    /**
     * إحصائيات المستخدمين
     */
    private function getUserStats(): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) AS locked,
                SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS deleted,
                SUM(CASE WHEN last_login_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS active_today,
                SUM(CASE WHEN last_login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS active_week
            FROM users
            WHERE 1=1
        ";

        $result = $this->db->selectOne($sql);

        return [
            'total'       => (int) ($result['total'] ?? 0),
            'active'      => (int) ($result['active'] ?? 0),
            'locked'      => (int) ($result['locked'] ?? 0),
            'deleted'     => (int) ($result['deleted'] ?? 0),
            'active_today'=> (int) ($result['active_today'] ?? 0),
            'active_week' => (int) ($result['active_week'] ?? 0),
        ];
    }

    /**
     * إحصائيات المخزون
     */
    private function getStockStats(): array
    {
        // إجمالي الكمية والقيمة
        $totalSql = "
            SELECT
                COALESCE(SUM(sb.quantity), 0) AS total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) AS total_value,
                COUNT(DISTINCT sb.product_id) AS products_with_stock
            FROM stock_balances sb
            INNER JOIN products p ON sb.product_id = p.id
            WHERE p.deleted_at IS NULL
        ";

        $total = $this->db->selectOne($totalSql);

        // المنتجات منخفضة المخزون
        $lowStockSql = "
            SELECT COUNT(DISTINCT p.id) AS count
            FROM products p
            INNER JOIN stock_balances sb ON p.id = sb.product_id
            WHERE p.deleted_at IS NULL
              AND sb.quantity > 0
              AND sb.quantity <= p.reorder_point
        ";

        $lowStock = $this->db->selectOne($lowStockSql);

        // المنتجات نفدت
        $outOfStockSql = "
            SELECT COUNT(DISTINCT p.id) AS count
            FROM products p
            INNER JOIN stock_balances sb ON p.id = sb.product_id
            WHERE p.deleted_at IS NULL
              AND sb.quantity = 0
        ";

        $outOfStock = $this->db->selectOne($outOfStockSql);

        return [
            'total_quantity'     => (float) ($total['total_quantity'] ?? 0),
            'total_value'        => (float) ($total['total_value'] ?? 0),
            'products_with_stock'=> (int) ($total['products_with_stock'] ?? 0),
            'low_stock_count'    => (int) ($lowStock['count'] ?? 0),
            'out_of_stock_count' => (int) ($outOfStock['count'] ?? 0),
        ];
    }

    /**
     * إحصائيات العمليات (Receipts, Issues, Transfers)
     */
    private function getOperationStats(): array
    {
        // استلامات اليوم
        $receiptsToday = $this->db->selectOne("
            SELECT COUNT(*) AS count, COALESCE(SUM(total_quantity), 0) AS quantity
            FROM receipts
            WHERE DATE(created_at) = CURDATE()
              AND deleted_at IS NULL
              AND status IN ('approved', 'completed')
        ");

        // صرف اليوم
        $issuesToday = $this->db->selectOne("
            SELECT COUNT(*) AS count, COALESCE(SUM(total_quantity), 0) AS quantity
            FROM issues
            WHERE DATE(created_at) = CURDATE()
              AND deleted_at IS NULL
              AND status IN ('approved', 'completed')
        ");

        // تحويلات اليوم
        $transfersToday = $this->db->selectOne("
            SELECT COUNT(*) AS count, COALESCE(SUM(total_quantity), 0) AS quantity
            FROM transfers
            WHERE DATE(created_at) = CURDATE()
              AND deleted_at IS NULL
              AND status IN ('approved', 'completed')
        ");

        // إجمالي الشهر
        $monthStats = $this->db->selectOne("
            SELECT
                SUM(CASE WHEN type = 'RECEIPT' THEN quantity ELSE 0 END) AS total_received,
                SUM(CASE WHEN type = 'ISSUE' THEN quantity ELSE 0 END) AS total_issued,
                SUM(CASE WHEN type IN ('TRANSFER_OUT', 'TRANSFER_IN') THEN quantity ELSE 0 END) AS total_transferred
            FROM stock_movements
            WHERE MONTH(movement_date) = MONTH(CURDATE())
              AND YEAR(movement_date) = YEAR(CURDATE())
              AND deleted_at IS NULL
        ");

        return [
            'today' => [
                'receipts' => [
                    'count'    => (int) ($receiptsToday['count'] ?? 0),
                    'quantity' => (float) ($receiptsToday['quantity'] ?? 0),
                ],
                'issues' => [
                    'count'    => (int) ($issuesToday['count'] ?? 0),
                    'quantity' => (float) ($issuesToday['quantity'] ?? 0),
                ],
                'transfers' => [
                    'count'    => (int) ($transfersToday['count'] ?? 0),
                    'quantity' => (float) ($transfersToday['quantity'] ?? 0),
                ],
            ],
            'month' => [
                'total_received'   => (float) ($monthStats['total_received'] ?? 0),
                'total_issued'     => (float) ($monthStats['total_issued'] ?? 0),
                'total_transferred'=> (float) ($monthStats['total_transferred'] ?? 0),
            ],
        ];
    }

    // =========================================================================
    // 2. بيانات الرسوم البيانية (Charts)
    // =========================================================================

    /**
     * جلب بيانات الرسوم البيانية
     *
     * GET /api/dashboard/charts
     *
     * @return void يرسل استجابة JSON
     */
    public function charts(): void
    {
        try {
            // 1. حركات المخزون آخر 7 أيام
            $movementsLast7Days = $this->getMovementsLast7Days();

            // 2. توزيع المخزون حسب المخزن
            $stockByWarehouse = $this->getStockByWarehouse();

            // 3. أفضل 10 منتجات حركة
            $topProducts = $this->getTopProducts();

            $data = [
                'movements_last_7_days' => $movementsLast7Days,
                'stock_by_warehouse'    => $stockByWarehouse,
                'top_products'          => $topProducts,
            ];

            Response::success('تم جلب بيانات الرسوم البيانية بنجاح', $data);

        } catch (Throwable $e) {
            error_log('[DASHBOARD] Charts failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب بيانات الرسوم البيانية');
        }
    }

    /**
     * حركات المخزون آخر 7 أيام
     */
    private function getMovementsLast7Days(): array
    {
        $sql = "
            SELECT
                DATE(movement_date) AS date,
                movement_type,
                SUM(quantity) AS total_quantity,
                COUNT(*) AS count
            FROM stock_movements
            WHERE movement_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
              AND deleted_at IS NULL
            GROUP BY DATE(movement_date), movement_type
            ORDER BY date ASC, movement_type
        ";

        $rows = $this->db->select($sql);

        // تنظيم البيانات للرسم البياني
        $chartData = [];
        foreach ($rows as $row) {
            $date = $row['date'];
            $type = $row['movement_type'];

            if (!isset($chartData[$date])) {
                $chartData[$date] = [
                    'date' => $date,
                    'RECEIPT' => 0,
                    'ISSUE' => 0,
                    'TRANSFER_IN' => 0,
                    'TRANSFER_OUT' => 0,
                ];
            }

            $chartData[$date][$type] = (float) $row['total_quantity'];
        }

        return array_values($chartData);
    }

    /**
     * توزيع المخزون حسب المخزن
     */
    private function getStockByWarehouse(): array
    {
        $sql = "
            SELECT
                w.id,
                w.name,
                w.code,
                COUNT(DISTINCT sb.product_id) AS products_count,
                COALESCE(SUM(sb.quantity), 0) AS total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) AS total_value
            FROM warehouses w
            LEFT JOIN stock_balances sb ON w.id = sb.warehouse_id
            LEFT JOIN products p ON sb.product_id = p.id
            WHERE w.deleted_at IS NULL
              AND w.is_active = 1
            GROUP BY w.id, w.name, w.code
            ORDER BY total_quantity DESC
        ";

        return $this->db->select($sql);
    }

    /**
     * أفضل 10 منتجات حركة (آخر 30 يوم)
     */
    private function getTopProducts(): array
    {
        $sql = "
            SELECT
                p.id,
                p.code,
                p.name,
                COUNT(sm.id) AS movements_count,
                SUM(ABS(sm.quantity)) AS total_quantity
            FROM products p
            INNER JOIN stock_movements sm ON p.id = sm.product_id
            WHERE sm.movement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND sm.deleted_at IS NULL
              AND p.deleted_at IS NULL
            GROUP BY p.id, p.code, p.name
            ORDER BY total_quantity DESC
            LIMIT 10
        ";

        return $this->db->select($sql);
    }

    // =========================================================================
    // 3. التنبيهات والإشعارات (Alerts)
    // =========================================================================

    /**
     * جلب التنبيهات والإشعارات
     *
     * GET /api/dashboard/alerts
     *
     * @return void يرسل استجابة JSON
     */
    public function alerts(): void
    {
        try {
            // 1. المنتجات منخفضة المخزون
            $lowStockProducts = $this->getLowStockProducts(10);

            // 2. المنتجات نفدت
            $outOfStockProducts = $this->getOutOfStockProducts(10);

            // 3. المنتجات قريبة من انتهاء الصلاحية
            $expiringProducts = $this->getExpiringProducts(10);

            // 4. الإذونات المعلقة (Pending)
            $pendingOperations = $this->getPendingOperations();

            $data = [
                'low_stock'         => $lowStockProducts,
                'out_of_stock'      => $outOfStockProducts,
                'expiring'          => $expiringProducts,
                'pending_operations'=> $pendingOperations,
            ];

            Response::success('تم جلب التنبيهات بنجاح', $data);

        } catch (Throwable $e) {
            error_log('[DASHBOARD] Alerts failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب التنبيهات');
        }
    }

    /**
     * المنتجات منخفضة المخزون
     */
    private function getLowStockProducts(int $limit = 10): array
    {
        $sql = "
            SELECT
                p.id,
                p.code,
                p.name,
                p.reorder_point,
                p.min_stock,
                u.symbol AS unit_symbol,
                SUM(sb.quantity) AS total_quantity,
                GROUP_CONCAT(DISTINCT w.name SEPARATOR ', ') AS warehouses
            FROM products p
            INNER JOIN stock_balances sb ON p.id = sb.product_id
            INNER JOIN warehouses w ON sb.warehouse_id = w.id
            LEFT JOIN units u ON p.unit_id = u.id
            WHERE p.is_active = 1
              AND p.deleted_at IS NULL
              AND sb.quantity > 0
              AND sb.quantity <= p.reorder_point
            GROUP BY p.id, p.code, p.name, p.reorder_point, p.min_stock, u.symbol
            ORDER BY (total_quantity / NULLIF(p.reorder_point, 0)) ASC
            LIMIT ?
        ";

        return $this->db->select($sql, [$limit]);
    }

    /**
     * المنتجات نفدت
     */
    private function getOutOfStockProducts(int $limit = 10): array
    {
        $sql = "
            SELECT
                p.id,
                p.code,
                p.name,
                u.symbol AS unit_symbol,
                GROUP_CONCAT(DISTINCT w.name SEPARATOR ', ') AS warehouses
            FROM products p
            INNER JOIN stock_balances sb ON p.id = sb.product_id
            INNER JOIN warehouses w ON sb.warehouse_id = w.id
            LEFT JOIN units u ON p.unit_id = u.id
            WHERE p.is_active = 1
              AND p.deleted_at IS NULL
              AND sb.quantity = 0
            GROUP BY p.id, p.code, p.name, u.symbol
            ORDER BY p.name ASC
            LIMIT ?
        ";

        return $this->db->select($sql, [$limit]);
    }

    /**
     * المنتجات قريبة من انتهاء الصلاحية (30 يوم)
     */
    private function getExpiringProducts(int $limit = 10): array
    {
        $sql = "
            SELECT
                p.id,
                p.code,
                p.name,
                sm.batch_number,
                sm.expiry_date,
                DATEDIFF(sm.expiry_date, CURDATE()) AS days_remaining,
                SUM(sm.quantity) AS total_quantity,
                w.name AS warehouse_name
            FROM stock_movements sm
            INNER JOIN products p ON sm.product_id = p.id
            INNER JOIN warehouses w ON sm.warehouse_id = w.id
            WHERE sm.expiry_date IS NOT NULL
              AND sm.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              AND sm.movement_type = 'RECEIPT'
              AND sm.deleted_at IS NULL
              AND p.deleted_at IS NULL
            GROUP BY p.id, p.code, p.name, sm.batch_number, sm.expiry_date, w.name
            ORDER BY sm.expiry_date ASC
            LIMIT ?
        ";

        return $this->db->select($sql, [$limit]);
    }

    /**
     * العمليات المعلقة (Pending)
     */
    private function getPendingOperations(): array
    {
        // استلامات معلقة
        $pendingReceipts = $this->db->selectOne("
            SELECT COUNT(*) AS count
            FROM receipts
            WHERE status = 'pending'
              AND deleted_at IS NULL
        ");

        // صرف معلق
        $pendingIssues = $this->db->selectOne("
            SELECT COUNT(*) AS count
            FROM issues
            WHERE status = 'pending'
              AND deleted_at IS NULL
        ");

        // تحويلات معلقة
        $pendingTransfers = $this->db->selectOne("
            SELECT COUNT(*) AS count
            FROM transfers
            WHERE status = 'pending'
              AND deleted_at IS NULL
        ");

        return [
            'receipts'  => (int) ($pendingReceipts['count'] ?? 0),
            'issues'    => (int) ($pendingIssues['count'] ?? 0),
            'transfers' => (int) ($pendingTransfers['count'] ?? 0),
        ];
    }

    // =========================================================================
    // 4. آخر الأنشطة (Activities)
    // =========================================================================

    /**
     * جلب آخر الأنشطة والعمليات
     *
     * GET /api/dashboard/activities
     *
     * @return void يرسل استجابة JSON
     */
    public function activities(): void
    {
        try {
            // آخر 20 حركة مخزنية
            $recentMovements = $this->getRecentMovements(20);

            // آخر 10 عمليات استلام
            $recentReceipts = $this->getRecentReceipts(10);

            // آخر 10 عمليات صرف
            $recentIssues = $this->getRecentIssues(10);

            // آخر 10 تحويلات
            $recentTransfers = $this->getRecentTransfers(10);

            $data = [
                'movements' => $recentMovements,
                'receipts'  => $recentReceipts,
                'issues'    => $recentIssues,
                'transfers' => $recentTransfers,
            ];

            Response::success('تم جلب آخر الأنشطة بنجاح', $data);

        } catch (Throwable $e) {
            error_log('[DASHBOARD] Activities failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب آخر الأنشطة');
        }
    }

    /**
     * آخر الحركات المخزنية
     */
    private function getRecentMovements(int $limit = 20): array
    {
        $sql = "
            SELECT
                sm.id,
                sm.movement_number,
                sm.movement_type,
                sm.quantity,
                sm.movement_date,
                p.code AS product_code,
                p.name AS product_name,
                w.name AS warehouse_name,
                u.full_name AS user_name
            FROM stock_movements sm
            INNER JOIN products p ON sm.product_id = p.id
            INNER JOIN warehouses w ON sm.warehouse_id = w.id
            LEFT JOIN users u ON sm.user_id = u.id
            WHERE sm.deleted_at IS NULL
            ORDER BY sm.movement_date DESC
            LIMIT ?
        ";

        return $this->db->select($sql, [$limit]);
    }

    /**
     * آخر عمليات الاستلام
     */
    private function getRecentReceipts(int $limit = 10): array
    {
        $sql = "
            SELECT
                r.id,
                r.receipt_number,
                r.status,
                r.total_items,
                r.total_quantity,
                r.created_at,
                w.name AS warehouse_name,
                s.name AS supplier_name,
                u.full_name AS user_name
            FROM receipts r
            INNER JOIN warehouses w ON r.warehouse_id = w.id
            LEFT JOIN suppliers s ON r.supplier_id = s.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.deleted_at IS NULL
            ORDER BY r.created_at DESC
            LIMIT ?
        ";

        return $this->db->select($sql, [$limit]);
    }

    /**
     * آخر عمليات الصرف
     */
    private function getRecentIssues(int $limit = 10): array
    {
        $sql = "
            SELECT
                i.id,
                i.issue_number,
                i.status,
                i.total_items,
                i.total_quantity,
                i.created_at,
                w.name AS warehouse_name,
                rec.name AS recipient_name,
                u.full_name AS user_name
            FROM issues i
            INNER JOIN warehouses w ON i.warehouse_id = w.id
            LEFT JOIN recipients rec ON i.recipient_id = rec.id
            LEFT JOIN users u ON i.user_id = u.id
            WHERE i.deleted_at IS NULL
            ORDER BY i.created_at DESC
            LIMIT ?
        ";

        return $this->db->select($sql, [$limit]);
    }

    /**
     * آخر التحويلات
     */
    private function getRecentTransfers(int $limit = 10): array
    {
        $sql = "
            SELECT
                t.id,
                t.transfer_number,
                t.status,
                t.total_items,
                t.total_quantity,
                t.created_at,
                w_from.name AS from_warehouse,
                w_to.name AS to_warehouse,
                u.full_name AS user_name
            FROM transfers t
            INNER JOIN warehouses w_from ON t.from_warehouse_id = w_from.id
            INNER JOIN warehouses w_to ON t.to_warehouse_id = w_to.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.deleted_at IS NULL
            ORDER BY t.created_at DESC
            LIMIT ?
        ";

        return $this->db->select($sql, [$limit]);
    }

    // =========================================================================
    // 5. حالة النظام (Status)
    // =========================================================================

    /**
     * جلب حالة النظام
     *
     * GET /api/dashboard/status
     *
     * @return void يرسل استجابة JSON
     */
    public function status(): void
    {
        try {
            // 1. حالة قاعدة البيانات
            $dbStatus = $this->getDatabaseStatus();

            // 2. حالة النسخ الاحتياطي
            $backupStatus = $this->getBackupStatus();

            // 3. حالة الجلسات النشطة
            $sessionStatus = $this->getSessionStatus();

            $data = [
                'database' => $dbStatus,
                'backup'   => $backupStatus,
                'sessions' => $sessionStatus,
                'server'   => [
                    'php_version'  => PHP_VERSION,
                    'server_time'  => date('Y-m-d H:i:s'),
                    'timezone'     => date_default_timezone_get(),
                    'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                ],
            ];

            Response::success('تم جلب حالة النظام بنجاح', $data);

        } catch (Throwable $e) {
            error_log('[DASHBOARD] Status failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب حالة النظام');
        }
    }

    /**
     * حالة قاعدة البيانات
     */
    private function getDatabaseStatus(): array
    {
        try {
            $result = $this->db->selectOne("SELECT 1 AS test");

            return [
                'connected' => true,
                'version'   => $this->db->selectOne("SELECT VERSION() AS version")['version'] ?? 'Unknown',
                'database'  => getenv('DB_NAME') ?: 'inventory_system',
            ];
        } catch (Throwable $e) {
            return [
                'connected' => false,
                'error'     => $e->getMessage(),
            ];
        }
    }

    /**
     * حالة النسخ الاحتياطي
     */
    private function getBackupStatus(): array
    {
        try {
            $lastBackup = $this->db->selectOne("
                SELECT
                    filename,
                    file_size,
                    created_at,
                    status
                FROM backups
                WHERE status = 'completed'
                ORDER BY created_at DESC
                LIMIT 1
            ");

            $totalBackups = $this->db->selectOne("
                SELECT COUNT(*) AS count
                FROM backups
                WHERE status = 'completed'
            ");

            return [
                'last_backup' => $lastBackup ?: null,
                'total_backups' => (int) ($totalBackups['count'] ?? 0),
            ];
        } catch (Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * حالة الجلسات النشطة
     */
    private function getSessionStatus(): array
    {
        try {
            $activeSessions = $this->db->selectOne("
                SELECT COUNT(*) AS count
                FROM user_sessions
                WHERE is_active = 1
                  AND expires_at > NOW()
            ");

            $sessionsToday = $this->db->selectOne("
                SELECT COUNT(*) AS count
                FROM user_sessions
                WHERE DATE(created_at) = CURDATE()
            ");

            return [
                'active_sessions' => (int) ($activeSessions['count'] ?? 0),
                'sessions_today'  => (int) ($sessionsToday['count'] ?? 0),
            ];
        } catch (Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
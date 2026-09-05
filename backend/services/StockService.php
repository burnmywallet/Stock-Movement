<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;

/**
 * ============================================================================
 * Stock Service
 * مسؤول عن: قراءة الأرصدة، الحجز، فك الحجز، وتقارير المخزون
 * ============================================================================
 */
class StockService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * جلب رصيد منتج في جميع المخازن
     */
    public function getProductBalances(int $productId): array
    {
        return $this->db->fetchAll("
            SELECT
                sb.warehouse_id,
                w.code as warehouse_code,
                w.name as warehouse_name,
                sb.quantity,
                sb.reserved_quantity,
                sb.available_quantity,
                sb.last_movement_date
            FROM stock_balances sb
            JOIN warehouses w ON sb.warehouse_id = w.id
            WHERE sb.product_id = ? AND w.deleted_at IS NULL
            ORDER BY w.name
        ", [$productId]);
    }

    /**
     * جلب رصيد جميع المنتجات في مخزن معين
     */
    public function getWarehouseBalances(int $warehouseId, array $filters = []): array
    {
        $sql = "
            SELECT
                sb.product_id,
                p.code,
                p.barcode,
                p.name,
                c.name as category_name,
                u.symbol as unit_symbol,
                sb.quantity,
                sb.reserved_quantity,
                sb.available_quantity,
                p.min_stock,
                p.reorder_point,
                p.max_stock,
                p.cost_price,
                sb.last_movement_date
            FROM stock_balances sb
            JOIN products p ON sb.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN units u ON p.unit_id = u.id
            WHERE sb.warehouse_id = ?
              AND p.deleted_at IS NULL
        ";

        $params = [$warehouseId];

        if (!empty($filters['low_stock_only'])) {
            $sql .= " AND sb.quantity <= p.reorder_point";
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY p.name";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * حجز كمية من المخزون (لإذونات الصرف قيد الانتظار)
     */
    public function reserveStock(int $productId, int $warehouseId, float $quantity): void
    {
        $this->db->transaction(function (Database $db) use ($productId, $warehouseId, $quantity) {
            $balance = $db->fetch("
                SELECT quantity, reserved_quantity, available_quantity
                FROM stock_balances
                WHERE product_id = ? AND warehouse_id = ?
                FOR UPDATE
            ", [$productId, $warehouseId]);

            if (!$balance) {
                throw new Exception("الصنف غير موجود في هذا المخزن.");
            }

            $available = (float)$balance['available_quantity'];
            if ($available < $quantity) {
                throw new Exception("الرصيد المتاح غير كافٍ للحجز. المتاح: {$available}");
            }

            $db->execute("
                UPDATE stock_balances
                SET reserved_quantity = reserved_quantity + ?, updated_at = NOW()
                WHERE product_id = ? AND warehouse_id = ?
            ", [$quantity, $productId, $warehouseId]);
        });
    }

    /**
     * فك حجز الكمية (عند إلغاء أو اعتماد الإذن)
     */
    public function releaseStock(int $productId, int $warehouseId, float $quantity): void
    {
        $this->db->execute("
            UPDATE stock_balances
            SET reserved_quantity = GREATEST(0, reserved_quantity - ?), updated_at = NOW()
            WHERE product_id = ? AND warehouse_id = ?
        ", [$quantity, $productId, $warehouseId]);
    }

    /**
     * جلب آخر N حركة لمنتج
     */
    public function getProductMovementHistory(int $productId, int $warehouseId = null, int $limit = 50): array
    {
        $sql = "
            SELECT
                sm.movement_number,
                sm.movement_type,
                sm.quantity,
                sm.unit_cost,
                sm.balance_before,
                sm.balance_after,
                sm.movement_date,
                sm.reference_type,
                sm.reference_id,
                u.full_name as user_name,
                w_from.name as from_warehouse_name,
                w_to.name as to_warehouse_name
            FROM stock_movements sm
            LEFT JOIN users u ON sm.user_id = u.id
            LEFT JOIN warehouses w_from ON sm.from_warehouse_id = w_from.id
            LEFT JOIN warehouses w_to ON sm.to_warehouse_id = w_to.id
            WHERE sm.product_id = ?
        ";

        $params = [$productId];

        if ($warehouseId !== null) {
            $sql .= " AND sm.warehouse_id = ?";
            $params[] = $warehouseId;
        }

        $sql .= " ORDER BY sm.movement_date DESC LIMIT ?";
        $params[] = $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * إحصائيات عامة للمخزون (لـ Dashboard)
     */
    public function getStockStatistics(): array
    {
        $totalProducts = $this->db->fetch("SELECT COUNT(*) as count FROM products WHERE is_active = 1 AND deleted_at IS NULL")['count'] ?? 0;
        $totalWarehouses = $this->db->fetch("SELECT COUNT(*) as count FROM warehouses WHERE is_active = 1 AND deleted_at IS NULL")['count'] ?? 0;

        $totalQuantity = $this->db->fetch("
            SELECT COALESCE(SUM(quantity), 0) as total
            FROM stock_balances
        ")['total'] ?? 0;

        $totalValue = $this->db->fetch("
            SELECT COALESCE(SUM(sb.quantity * p.cost_price), 0) as total
            FROM stock_balances sb
            JOIN products p ON sb.product_id = p.id
            WHERE p.cost_price IS NOT NULL AND p.deleted_at IS NULL
        ")['total'] ?? 0;

        $lowStockCount = $this->db->fetch("
            SELECT COUNT(DISTINCT sb.product_id) as count
            FROM stock_balances sb
            JOIN products p ON sb.product_id = p.id
            WHERE sb.quantity > 0 AND sb.quantity <= p.reorder_point AND p.deleted_at IS NULL
        ")['count'] ?? 0;

        $outOfStockCount = $this->db->fetch("
            SELECT COUNT(DISTINCT sb.product_id) as count
            FROM stock_balances sb
            WHERE sb.quantity = 0
        ")['count'] ?? 0;

        return [
            'total_products' => (int)$totalProducts,
            'total_warehouses' => (int)$totalWarehouses,
            'total_quantity' => (float)$totalQuantity,
            'total_value' => (float)$totalValue,
            'low_stock_count' => (int)$lowStockCount,
            'out_of_stock_count' => (int)$outOfStockCount,
        ];
    }

    /**
     * المنتجات منخفضة المخزون
     */
    public function getLowStockProducts(int $limit = 20): array
    {
        return $this->db->fetchAll("
            SELECT
                p.id,
                p.code,
                p.name,
                p.reorder_point,
                p.min_stock,
                u.symbol as unit_symbol,
                SUM(sb.quantity) as total_quantity,
                GROUP_CONCAT(DISTINCT w.name SEPARATOR ', ') as warehouses
            FROM products p
            JOIN stock_balances sb ON p.id = sb.product_id
            JOIN warehouses w ON sb.warehouse_id = w.id
            LEFT JOIN units u ON p.unit_id = u.id
            WHERE p.is_active = 1 AND p.deleted_at IS NULL
            GROUP BY p.id, p.code, p.name, p.reorder_point, p.min_stock, u.symbol
            HAVING total_quantity <= p.reorder_point
            ORDER BY (total_quantity / NULLIF(p.reorder_point, 0)) ASC
            LIMIT ?
        ", [$limit]);
    }
}
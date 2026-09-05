<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;

/**
 * ============================================================================
 * Report Service
 * مسؤول عن: توليد تقارير الأعمال المعقدة وتجميع البيانات
 * ============================================================================
 */
class ReportService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * تقرير المخزون الحالي مع التقييم
     */
    public function inventoryReport(array $filters = []): array
    {
        $sql = "
            SELECT
                p.id,
                p.code,
                p.barcode,
                p.name as product_name,
                c.name as category_name,
                u.symbol as unit_symbol,
                w.code as warehouse_code,
                w.name as warehouse_name,
                sb.quantity,
                sb.reserved_quantity,
                sb.available_quantity,
                p.cost_price,
                (sb.quantity * COALESCE(p.cost_price, 0)) as total_value,
                p.reorder_point,
                CASE
                    WHEN sb.quantity = 0 THEN 'out_of_stock'
                    WHEN sb.quantity <= p.min_stock THEN 'critical'
                    WHEN sb.quantity <= p.reorder_point THEN 'low'
                    ELSE 'normal'
                END as stock_status
            FROM stock_balances sb
            JOIN products p ON sb.product_id = p.id
            JOIN warehouses w ON sb.warehouse_id = w.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN units u ON p.unit_id = u.id
            WHERE p.deleted_at IS NULL AND w.deleted_at IS NULL
        ";

        $params = [];

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND sb.warehouse_id = ?";
            $params[] = $filters['warehouse_id'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND p.category_id = ?";
            $params[] = $filters['category_id'];
        }

        if (!empty($filters['stock_status'])) {
            $sql .= " AND (sb.quantity = 0 OR sb.quantity <= p.reorder_point OR 1=1)";
            // يمكن تحسينها حسب الحالة المحددة
        }

        $sql .= " ORDER BY w.name, p.name";

        $data = $this->db->fetchAll($sql, $params);

        // حساب الإجماليات
        $totalQuantity = array_sum(array_column($data, 'quantity'));
        $totalValue = array_sum(array_column($data, 'total_value'));

        return [
            'items' => $data,
            'summary' => [
                'total_items' => count($data),
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue,
            ],
            'generated_at' => date('Y-m-d H:i:s'),
            'filters' => $filters,
        ];
    }

    /**
     * تقرير الحركات المخزنية
     */
    public function movementsReport(array $filters = []): array
    {
        $sql = "
            SELECT
                sm.movement_number,
                sm.movement_type,
                sm.movement_date,
                p.code as product_code,
                p.name as product_name,
                u.symbol as unit_symbol,
                w.name as warehouse_name,
                w_from.name as from_warehouse,
                w_to.name as to_warehouse,
                sm.quantity,
                sm.unit_cost,
                sm.total_cost,
                sm.balance_before,
                sm.balance_after,
                sm.reference_type,
                sm.notes,
                u_usr.full_name as user_name
            FROM stock_movements sm
            JOIN products p ON sm.product_id = p.id
            JOIN warehouses w ON sm.warehouse_id = w.id
            LEFT JOIN warehouses w_from ON sm.from_warehouse_id = w_from.id
            LEFT JOIN warehouses w_to ON sm.to_warehouse_id = w_to.id
            LEFT JOIN units u ON p.unit_id = u.id
            LEFT JOIN users u_usr ON sm.user_id = u_usr.id
            WHERE sm.deleted_at IS NULL
        ";

        $params = [];

        if (!empty($filters['from_date'])) {
            $sql .= " AND sm.movement_date >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND sm.movement_date <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        if (!empty($filters['product_id'])) {
            $sql .= " AND sm.product_id = ?";
            $params[] = $filters['product_id'];
        }

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND sm.warehouse_id = ?";
            $params[] = $filters['warehouse_id'];
        }

        if (!empty($filters['movement_type'])) {
            $sql .= " AND sm.movement_type = ?";
            $params[] = $filters['movement_type'];
        }

        $sql .= " ORDER BY sm.movement_date DESC, sm.id DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
        }

        $data = $this->db->fetchAll($sql, $params);

        // تجميع حسب النوع
        $byType = [];
        foreach ($data as $row) {
            $type = $row['movement_type'];
            if (!isset($byType[$type])) {
                $byType[$type] = ['count' => 0, 'total_quantity' => 0, 'total_cost' => 0];
            }
            $byType[$type]['count']++;
            $byType[$type]['total_quantity'] += (float)$row['quantity'];
            $byType[$type]['total_cost'] += (float)($row['total_cost'] ?? 0);
        }

        return [
            'items' => $data,
            'by_type' => $byType,
            'summary' => [
                'total_movements' => count($data),
                'total_quantity' => array_sum(array_column($data, 'quantity')),
            ],
            'generated_at' => date('Y-m-d H:i:s'),
            'filters' => $filters,
        ];
    }

    /**
     * تقرير إذونات الاستلام
     */
    public function receiptsReport(array $filters = []): array
    {
        $sql = "
            SELECT
                r.id,
                r.receipt_number,
                r.created_at,
                r.status,
                w.name as warehouse_name,
                s.name as supplier_name,
                r.supplier_invoice,
                r.total_items,
                r.total_quantity,
                r.total_cost,
                u.full_name as created_by_name,
                u_approver.full_name as approved_by_name,
                r.approved_at
            FROM receipts r
            JOIN warehouses w ON r.warehouse_id = w.id
            LEFT JOIN suppliers s ON r.supplier_id = s.id
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN users u_approver ON r.approved_by = u_approver.id
            WHERE r.deleted_at IS NULL
        ";

        $params = [];

        if (!empty($filters['from_date'])) {
            $sql .= " AND r.created_at >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND r.created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        if (!empty($filters['status'])) {
            $sql .= " AND r.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND r.warehouse_id = ?";
            $params[] = $filters['warehouse_id'];
        }

        $sql .= " ORDER BY r.created_at DESC";

        $data = $this->db->fetchAll($sql, $params);

        return [
            'items' => $data,
            'summary' => [
                'total_receipts' => count($data),
                'total_quantity' => array_sum(array_column($data, 'total_quantity')),
                'total_cost' => array_sum(array_column($data, 'total_cost')),
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * تقرير نشاط المستخدمين
     */
    public function userActivityReport(array $filters = []): array
    {
        $sql = "
            SELECT
                al.created_at,
                u.full_name as user_name,
                u.username,
                r.display_name as role_name,
                al.action,
                al.entity_type,
                al.entity_id,
                al.description,
                al.ip_address
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        $sql .= " ORDER BY al.created_at DESC LIMIT 500";

        return [
            'items' => $this->db->fetchAll($sql, $params),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }
}
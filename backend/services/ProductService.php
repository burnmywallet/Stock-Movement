<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;

class ProductService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function list(array $filters = []): array
    {
        $sql = "
            SELECT p.*, c.name as category_name, u.symbol as unit_symbol,
                   COALESCE((SELECT SUM(quantity) FROM stock_balances WHERE product_id = p.id), 0) as total_stock
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN units u ON p.unit_id = u.id
            WHERE p.deleted_at IS NULL
        ";

        $params = [];

        if (!empty($filters['search'])) {
            $sql .= " AND (p.name LIKE ? OR p.code LIKE ? OR p.barcode LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND p.category_id = ?";
            $params[] = $filters['category_id'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND p.is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $sql .= " ORDER BY p.name";

        return $this->db->fetchAll($sql, $params);
    }

    public function create(array $data, int $userId): int
    {
        $existing = $this->db->fetch("
            SELECT id FROM products WHERE (code = ? OR barcode = ?) AND deleted_at IS NULL
        ", [$data['code'], $data['barcode'] ?? null]);

        if ($existing) {
            throw new Exception('الكود أو الباركود مستخدم بالفعل.');
        }

        $this->db->execute("
            INSERT INTO products (
                code, barcode, sku, name, description, category_id, unit_id,
                min_stock, reorder_point, max_stock, cost_price,
                barcode_type, is_barcode_enabled, is_sku_enabled, is_active,
                created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ", [
            $data['code'],
            $data['barcode'] ?? null,
            $data['sku'] ?? null,
            $data['name'],
            $data['description'] ?? null,
            $data['category_id'] ?? null,
            $data['unit_id'] ?? null,
            $data['min_stock'] ?? 0,
            $data['reorder_point'] ?? 0,
            $data['max_stock'] ?? null,
            $data['cost_price'] ?? null,
            $data['barcode_type'] ?? 'EAN13',
            $data['is_barcode_enabled'] ?? 1,
            $data['is_sku_enabled'] ?? 1,
            $data['is_active'] ?? 1,
            $userId,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data, int $userId): void
    {
        $product = $this->db->fetch("SELECT id FROM products WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$product) {
            throw new Exception('المنتج غير موجود.');
        }

        $fields = [];
        $params = [];

        $allowedFields = [
            'name', 'description', 'category_id', 'unit_id', 'min_stock',
            'reorder_point', 'max_stock', 'cost_price', 'is_active', 'barcode', 'sku'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) return;

        $fields[] = "updated_at = NOW()";
        $fields[] = "updated_by = ?";
        $params[] = $userId;
        $params[] = $id;

        $this->db->execute(
            "UPDATE products SET " . implode(', ', $fields) . " WHERE id = ?",
            $params
        );
    }

    public function delete(int $id): void
    {
        // التحقق من وجود حركات
        $hasMovements = $this->db->fetch("
            SELECT COUNT(*) as count FROM stock_movements WHERE product_id = ?
        ", [$id]);

        if ((int)$hasMovements['count'] > 0) {
            throw new Exception('لا يمكن حذف منتج له حركات مخزنية. يمكنك فقط تعطيله.');
        }

        $this->db->execute("UPDATE products SET deleted_at = NOW() WHERE id = ?", [$id]);
    }
}
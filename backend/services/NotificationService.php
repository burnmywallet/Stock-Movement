<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * ============================================================================
 * Notification Service
 * مسؤول عن: إنشاء، إدارة، وتنبيهات النظام
 * ============================================================================
 */
class NotificationService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * إرسال إشعار
     */
    public function send(
        ?int $userId, // null للإشعارات العامة
        string $title,
        string $message,
        string $type = 'info',
        ?string $module = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): int {
        $this->db->execute("
            INSERT INTO notifications (
                user_id, title, message, type, module, reference_type, reference_id, is_read
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0)
        ", [$userId, $title, $message, $type, $module, $referenceType, $referenceId]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * إرسال تنبيه لكل مدراء المخازن (مثال: مخزون منخفض)
     */
    public function broadcastToManagers(string $title, string $message, string $type = 'warning'): void
    {
        // جلب معرف دور المدير
        $managerRole = $this->db->fetch("SELECT id FROM roles WHERE name = 'manager'");
        if (!$managerRole) return;

        // جلب معرف دور المشرف
        $supervisorRole = $this->db->fetch("SELECT id FROM roles WHERE name = 'supervisor'");

        $roleIds = [$managerRole['id']];
        if ($supervisorRole) {
            $roleIds[] = $supervisorRole['id'];
        }

        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));

        // جلب معرفات المستخدمين
        $users = $this->db->fetchAll("
            SELECT id FROM users WHERE role_id IN ($placeholders) AND is_active = 1
        ", $roleIds);

        foreach ($users as $user) {
            $this->send($user['id'], $title, $message, $type, 'system');
        }
    }

    /**
     * تنبيه المخزون المنخفض (يُستدعى بعد كل حركة)
     */
    public function checkLowStock(int $productId): void
    {
        $product = $this->db->fetch("
            SELECT p.id, p.code, p.name, p.reorder_point, p.min_stock,
                   SUM(sb.quantity) as total_quantity
            FROM products p
            JOIN stock_balances sb ON p.id = sb.product_id
            WHERE p.id = ? AND p.deleted_at IS NULL
            GROUP BY p.id
        ", [$productId]);

        if (!$product) return;

        $quantity = (float)($product['total_quantity'] ?? 0);
        $reorderPoint = (float)($product['reorder_point'] ?? 0);
        $minStock = (float)($product['min_stock'] ?? 0);

        if ($quantity > 0 && $quantity <= $minStock) {
            // حالة حرجة
            $this->broadcastToManagers(
                "⚠️ تنبيه حرج: {$product['name']}",
                "الصنف {$product['code']} وصل إلى مستوى حرج: {$quantity} (الحد الأدنى: {$minStock})",
                'critical_stock'
            );
        } elseif ($quantity > 0 && $quantity <= $reorderPoint) {
            // منخفض
            $this->broadcastToManagers(
                "📉 تنبيه: {$product['name']}",
                "الصنف {$product['code']} وصل إلى نقطة إعادة الطلب: {$quantity} (نقطة الطلب: {$reorderPoint})",
                'low_stock'
            );
        }
    }

    /**
     * جلب إشعارات المستخدم
     */
    public function getUserNotifications(int $userId, int $limit = 50, bool $unreadOnly = false): array
    {
        $sql = "
            SELECT id, title, message, type, module, reference_type, reference_id,
                   is_read, read_at, created_at
            FROM notifications
            WHERE user_id = ? OR user_id IS NULL
        ";

        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";

        return $this->db->fetchAll($sql, [$userId, $limit]);
    }

    /**
     * عدد الإشعارات غير المقروءة
     */
    public function getUnreadCount(int $userId): int
    {
        $result = $this->db->fetch("
            SELECT COUNT(*) as count
            FROM notifications
            WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0
        ", [$userId]);

        return (int)($result['count'] ?? 0);
    }

    /**
     * تعليم كمقروء
     */
    public function markAsRead(int $notificationId, int $userId): void
    {
        $this->db->execute("
            UPDATE notifications
            SET is_read = 1, read_at = NOW()
            WHERE id = ? AND (user_id = ? OR user_id IS NULL) AND is_read = 0
        ", [$notificationId, $userId]);
    }

    /**
     * تعليم الكل كمقروء
     */
    public function markAllAsRead(int $userId): void
    {
        $this->db->execute("
            UPDATE notifications
            SET is_read = 1, read_at = NOW()
            WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0
        ", [$userId]);
    }
}
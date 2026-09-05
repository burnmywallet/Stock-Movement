<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;

/**
 * ============================================================================
 * User Service
 * ============================================================================
 */
class UserService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function list(array $filters = []): array
    {
        $sql = "
            SELECT u.id, u.username, u.full_name, u.email, u.phone,
                   u.is_active, u.is_locked, u.locked_until,
                   u.last_login_at, u.last_login_ip, u.must_change_password,
                   r.name as role_name, r.display_name as role_display_name,
                   w.name as warehouse_name,
                   u.created_at
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN warehouses w ON u.warehouse_id = w.id
            WHERE u.deleted_at IS NULL
        ";

        $params = [];

        if (!empty($filters['search'])) {
            $sql .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['role_id'])) {
            $sql .= " AND u.role_id = ?";
            $params[] = $filters['role_id'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND u.is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $sql .= " ORDER BY u.full_name";

        return $this->db->fetchAll($sql, $params);
    }

    public function create(array $data): int
    {
        // التحقق من التفرد
        $existing = $this->db->fetch("
            SELECT id FROM users WHERE username = ? OR email = ? OR phone = ?
        ", [$data['username'], $data['email'] ?? null, $data['phone'] ?? null]);

        if ($existing) {
            throw new Exception('اسم المستخدم أو البريد الإلكتروني أو رقم الهاتف مستخدم بالفعل.');
        }

        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);

        $this->db->execute("
            INSERT INTO users (
                username, password_hash, full_name, email, phone,
                role_id, warehouse_id, is_active, must_change_password, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ", [
            $data['username'],
            $passwordHash,
            $data['full_name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['role_id'],
            $data['warehouse_id'] ?? null,
            $data['is_active'] ?? 1,
            $data['must_change_password'] ?? 1,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $user = $this->db->fetch("SELECT id, role_id FROM users WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$user) {
            throw new Exception('المستخدم غير موجود.');
        }

        // منع تعديل دور المدير الرئيسي
        if ($user['role_id'] === 1 && isset($data['role_id']) && $data['role_id'] !== 1) {
            throw new Exception('لا يمكن تغيير دور المدير الرئيسي للنظام.');
        }

        $fields = [];
        $params = [];

        foreach (['full_name', 'email', 'phone', 'role_id', 'warehouse_id', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return;
        }

        $fields[] = "updated_at = NOW()";
        $params[] = $id;

        $this->db->execute(
            "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?",
            $params
        );
    }

    public function delete(int $id): void
    {
        $user = $this->db->fetch("SELECT id, role_id, username FROM users WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$user) {
            throw new Exception('المستخدم غير موجود.');
        }

        if ($user['username'] === 'admin') {
            throw new Exception('لا يمكن حذف حساب المدير الرئيسي.');
        }

        $this->db->execute("
            UPDATE users SET deleted_at = NOW(), is_active = 0 WHERE id = ?
        ", [$id]);

        // إلغاء جميع الجلسات
        $this->db->execute("
            UPDATE user_sessions
            SET is_active = 0, revoked_at = NOW(), revoked_reason = 'USER_DELETED'
            WHERE user_id = ?
        ", [$id]);
    }

    public function resetPassword(int $id): string
    {
        $user = $this->db->fetch("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$user) {
            throw new Exception('المستخدم غير موجود.');
        }

        $newPassword = bin2hex(random_bytes(6)); // كلمة مرور مؤقتة 12 حرف
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        $this->db->execute("
            UPDATE users
            SET password_hash = ?, must_change_password = 1, updated_at = NOW()
            WHERE id = ?
        ", [$hash, $id]);

        return $newPassword;
    }
}
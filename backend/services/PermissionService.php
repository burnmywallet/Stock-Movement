<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;

/**
 * ============================================================================
 * Permission Service
 * ============================================================================
 */
class PermissionService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * جلب جميع الصلاحيات مجمعة حسب الوحدة
     */
    public function getAllGroupedByModule(): array
    {
        $permissions = $this->db->fetchAll("
            SELECT id, name, display_name, description, module
            FROM permissions
            ORDER BY module, name
        ");

        $grouped = [];
        foreach ($permissions as $p) {
            $module = $p['module'];
            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
            }
            $grouped[$module][] = $p;
        }

        return $grouped;
    }

    /**
     * صلاحيات دور معين
     */
    public function getRolePermissions(int $roleId): array
    {
        return $this->db->fetchAll("
            SELECT p.id, p.name, p.display_name, p.module
            FROM permissions p
            JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
        ", [$roleId]);
    }

    /**
     * تحديث صلاحيات دور
     */
    public function updateRolePermissions(int $roleId, array $permissionIds): void
    {
        $role = $this->db->fetch("SELECT id, is_system, name FROM roles WHERE id = ?", [$roleId]);
        if (!$role) {
            throw new Exception('الدور غير موجود.');
        }

        $this->db->transaction(function (Database $db) use ($roleId, $permissionIds) {
            // حذف جميع الصلاحيات الحالية
            $db->execute("DELETE FROM role_permissions WHERE role_id = ?", [$roleId]);

            // إضافة الصلاحيات الجديدة
            if (!empty($permissionIds)) {
                foreach ($permissionIds as $permissionId) {
                    $db->execute("
                        INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)
                    ", [$roleId, (int)$permissionId]);
                }
            }
        });
    }

    /**
     * التحقق من صلاحية مستخدم
     */
    public function userHasPermission(int $userId, string $permissionName): bool
    {
        $result = $this->db->fetch("
            SELECT COUNT(*) as count
            FROM users u
            JOIN role_permissions rp ON u.role_id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE u.id = ? AND p.name = ? AND u.is_active = 1
        ", [$userId, $permissionName]);

        return ((int)($result['count'] ?? 0)) > 0;
    }

    /**
     * جميع صلاحيات مستخدم
     */
    public function getUserPermissions(int $userId): array
    {
        return $this->db->fetchAll("
            SELECT p.name, p.display_name, p.module
            FROM users u
            JOIN role_permissions rp ON u.role_id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE u.id = ? AND u.is_active = 1
        ", [$userId]);
    }
}
<?php

/**
 * ================================================================
 * Logistox - Role Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/RoleService.php
 * الوظيفة: منطق الأعمال لإدارة الأدوار
 *
 * المسؤوليات:
 * 1. إنشاء دور جديد مع التحقق من التفرد
 * 2. جلب دور واحد مع التفاصيل
 * 3. جلب قائمة الأدوار مع عدد المستخدمين والصلاحيات
 * 4. تعديل دور موجود (ليس نظامياً)
 * 5. حذف دور (ليس نظامياً، ليس مرتبطاً بمستخدمين)
 * 6. التحقق من تفرد name و display_name
 * 7. التحقق من أن الدور نظامي (is_system)
 * 8. عد المستخدمين المرتبطين بالدور
 * 9. عد الصلاحيات المرتبطة بالدور
 *
 * قيود الحماية:
 * - منع حذف الأدوار النظامية (is_system = 1)
 * - منع حذف دور مرتبط بمستخدمين
 * - التحقق من تفرد name و display_name
 * - منع تعديل is_system بعد الإنشاء
 * ================================================================
 */

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Throwable;
use Exception;

/**
 * Class RoleService
 *
 * خدمة إدارة الأدوار
 */
class RoleService
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * Constructor
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // 1. إنشاء دور جديد (Create)
    // =========================================================================

    /**
     * إنشاء دور جديد
     *
     * @param array $data بيانات الدور
     * @return int معرف الدور الجديد
     * @throws Exception إذا فشل الإنشاء
     */
    public function create(array $data): int
    {
        // 1. التحقق من تفرد name
        if ($this->isNameTaken($data['name'])) {
            throw new Exception('اسم الدور (name) مستخدم بالفعل. يرجى استخدام اسم مختلف.');
        }

        // 2. التحقق من تفرد display_name
        if ($this->isDisplayNameTaken($data['display_name'])) {
            throw new Exception('الاسم المعروض (display_name) مستخدم بالفعل.');
        }

        // 3. بناء البيانات للإدراج
        $insertData = [
            'name'         => trim($data['name']),
            'display_name' => trim($data['display_name']),
            'description'  => !empty($data['description']) ? trim($data['description']) : null,
            'is_system'    => isset($data['is_system']) ? (int) $data['is_system'] : 0,
            'is_active'    => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        // 4. إدراج الدور
        $roleId = $this->db->insert('roles', $insertData);

        return (int) $roleId;
    }

    // =========================================================================
    // 2. جلب دور واحد (Read)
    // =========================================================================

    /**
     * جلب دور واحد مع التفاصيل
     *
     * @param int $id معرف الدور
     * @return array|null بيانات الدور أو null
     */
    public function getById(int $id): ?array
    {
        $role = $this->db->selectOne("
            SELECT
                r.id,
                r.name,
                r.display_name,
                r.description,
                r.is_system,
                r.is_active,
                r.created_at,
                r.updated_at,
                (
                    SELECT COUNT(*)
                    FROM users u
                    WHERE u.role_id = r.id AND u.deleted_at IS NULL
                ) AS users_count,
                (
                    SELECT COUNT(*)
                    FROM role_permissions rp
                    WHERE rp.role_id = r.id
                ) AS permissions_count
            FROM roles r
            WHERE r.id = ?
              AND r.deleted_at IS NULL
        ", [$id]);

        if (!$role) {
            return null;
        }

        // تحويل القيم
        $role['is_system'] = (bool) $role['is_system'];
        $role['is_active'] = (bool) $role['is_active'];
        $role['users_count'] = (int) $role['users_count'];
        $role['permissions_count'] = (int) $role['permissions_count'];

        return $role;
    }

    // =========================================================================
    // 3. جلب قائمة الأدوار (List)
    // =========================================================================

    /**
     * جلب قائمة الأدوار مع الفلاتر
     *
     * @param array $filters الفلاتر
     * @return array قائمة الأدوار
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                r.id,
                r.name,
                r.display_name,
                r.description,
                r.is_system,
                r.is_active,
                r.created_at,
                r.updated_at,
                (
                    SELECT COUNT(*)
                    FROM users u
                    WHERE u.role_id = r.id AND u.deleted_at IS NULL
                ) AS users_count,
                (
                    SELECT COUNT(*)
                    FROM role_permissions rp
                    WHERE rp.role_id = r.id
                ) AS permissions_count
            FROM roles r
            WHERE r.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (r.name LIKE ? OR r.display_name LIKE ? OR r.description LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (isset($filters['is_system'])) {
            $sql .= " AND r.is_system = ?";
            $params[] = (int) $filters['is_system'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND r.is_active = ?";
            $params[] = (int) $filters['is_active'];
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc');

        $allowedSortBy = ['name', 'display_name', 'created_at', 'updated_at'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'name';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

        $sql .= " ORDER BY r.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $roles = $this->db->select($sql, $params);

        // تحويل القيم
        foreach ($roles as &$role) {
            $role['is_system'] = (bool) $role['is_system'];
            $role['is_active'] = (bool) $role['is_active'];
            $role['users_count'] = (int) $role['users_count'];
            $role['permissions_count'] = (int) $role['permissions_count'];
        }

        return $roles;
    }

    // =========================================================================
    // 4. تعديل دور (Update)
    // =========================================================================

    /**
     * تعديل دور موجود
     *
     * @param int $id معرف الدور
     * @param array $data البيانات الجديدة
     * @return void
     * @throws Exception إذا فشل التحديث
     */
    public function update(int $id, array $data): void
    {
        // 1. جلب الدور
        $role = $this->db->selectOne(
            "SELECT id, name, display_name, is_system FROM roles WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$role) {
            throw new Exception('الدور غير موجود.');
        }

        // 2. التحقق من تفرد name (إذا تم تغييره)
        if (!empty($data['name']) && $data['name'] !== $role['name']) {
            if ($this->isNameTaken($data['name'], $id)) {
                throw new Exception('اسم الدور (name) مستخدم بالفعل.');
            }
        }

        // 3. التحقق من تفرد display_name (إذا تم تغييره)
        if (!empty($data['display_name']) && $data['display_name'] !== $role['display_name']) {
            if ($this->isDisplayNameTaken($data['display_name'], $id)) {
                throw new Exception('الاسم المعروض (display_name) مستخدم بالفعل.');
            }
        }

        // 4. بناء بيانات التحديث
        $updateData = ['updated_at' => date('Y-m-d H:i:s')];

        $allowedFields = ['name', 'display_name', 'description', 'is_active'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        // 5. تحديث الدور
        $this->db->update('roles', $updateData, ['id' => $id]);
    }

    // =========================================================================
    // 5. حذف دور (Soft Delete)
    // =========================================================================

    /**
     * حذف دور (Soft Delete)
     *
     * @param int $id معرف الدور
     * @return void
     * @throws Exception إذا فشل الحذف
     */
    public function delete(int $id): void
    {
        // 1. جلب الدور
        $role = $this->db->selectOne(
            "SELECT id, name, display_name, is_system FROM roles WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$role) {
            throw new Exception('الدور غير موجود.');
        }

        // 2. التحقق من أن الدور ليس نظامياً
        if ((int) $role['is_system'] === 1) {
            throw new Exception(
                'لا يمكن حذف دور نظامي (System Role). ' .
                "الدور '{$role['display_name']}' محمي من النظام."
            );
        }

        // 3. التحقق من عدم وجود مستخدمين مرتبطين
        $userCount = $this->getUserCount($id);
        if ($userCount > 0) {
            throw new Exception(
                "لا يمكن حذف هذا الدور لأنه مرتبط بـ {$userCount} مستخدم(ين). " .
                'يجب إعادة تعيين المستخدمين إلى أدوار أخرى أولاً.'
            );
        }

        // 4. حذف الصلاحيات المرتبطة
        $this->db->execute("DELETE FROM role_permissions WHERE role_id = ?", [$id]);

        // 5. حذف الدور (Soft Delete)
        $this->db->update('roles', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    // =========================================================================
    // 6. التحقق من الوجود والتفرد
    // =========================================================================

    /**
     * التحقق من وجود دور معين
     */
    public function exists(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM roles WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من تفرد اسم الدور
     */
    public function isNameTaken(string $name, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM roles WHERE name = ? AND deleted_at IS NULL";
        $params = [trim($name)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من تفرد الاسم المعروض
     */
    public function isDisplayNameTaken(string $displayName, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM roles WHERE display_name = ? AND deleted_at IS NULL";
        $params = [trim($displayName)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من أن الدور نظامي
     */
    public function isSystemRole(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT is_system FROM roles WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return $result && (int) $result['is_system'] === 1;
    }

    /**
     * عد المستخدمين المرتبطين بالدور
     */
    public function getUserCount(int $roleId): int
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM users WHERE role_id = ? AND deleted_at IS NULL",
            [$roleId]
        );

        return (int) ($result['count'] ?? 0);
    }

    /**
     * عد الصلاحيات المرتبطة بالدور
     */
    public function getPermissionCount(int $roleId): int
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM role_permissions WHERE role_id = ?",
            [$roleId]
        );

        return (int) ($result['count'] ?? 0);
    }

    /**
     * جلب الأدوار النشطة (للقوائم المنسدلة)
     */
    public function getActiveRoles(): array
    {
        return $this->db->select("
            SELECT id, name, display_name, is_system
            FROM roles
            WHERE is_active = 1 AND deleted_at IS NULL
            ORDER BY name ASC
        ");
    }
}
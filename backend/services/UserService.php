<?php

/**
 * ================================================================
 * Logistox - User Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/UserService.php
 * الوظيفة: منطق الأعمال لإدارة المستخدمين
 *
 * المسؤوليات:
 * 1. إنشاء مستخدم جديد مع التحقق من التفرد
 * 2. جلب مستخدم واحد مع التفاصيل
 * 3. جلب قائمة المستخدمين مع الفلاتر
 * 4. تعديل مستخدم موجود
 * 5. حذف مستخدم (Soft Delete)
 * 6. إعادة تعيين كلمة المرور
 * 7. تفعيل/تعطيل حساب
 * 8. التحقق من تفرد username, email, phone
 * 9. التحقق من وجود الدور والمخزن
 * 10. حماية المستخدم الرئيسي (admin)
 *
 * قيود الحماية:
 * - منع حذف المستخدم الرئيسي (username = 'admin')
 * - منع تغيير دور المستخدم الرئيسي
 * - التحقق من تفرد username, email, phone
 * - التحقق من وجود role_id و warehouse_id
 * ================================================================
 */

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Throwable;
use Exception;

/**
 * Class UserService
 *
 * خدمة إدارة المستخدمين
 */
class UserService
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var string اسم المستخدم الرئيسي المحمي
     */
    private const PROTECTED_USERNAME = 'admin';

    /**
     * Constructor
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // 1. إنشاء مستخدم جديد (Create)
    // =========================================================================

    /**
     * إنشاء مستخدم جديد
     *
     * @param array $data بيانات المستخدم
     * @param int $createdBy معرف المستخدم الذي أنشأ الحساب
     * @return int معرف المستخدم الجديد
     * @throws Exception إذا فشل الإنشاء
     */
    public function create(array $data, int $createdBy): int
    {
        // 1. التحقق من تفرد username
        if ($this->isUsernameTaken($data['username'])) {
            throw new Exception('اسم المستخدم مستخدم بالفعل. يرجى استخدام اسم مختلف.');
        }

        // 2. التحقق من تفرد email (إذا تم تقديمه)
        if (!empty($data['email']) && $this->isEmailTaken($data['email'])) {
            throw new Exception('البريد الإلكتروني مستخدم بالفعل.');
        }

        // 3. التحقق من تفرد phone (إذا تم تقديمه)
        if (!empty($data['phone']) && $this->isPhoneTaken($data['phone'])) {
            throw new Exception('رقم الهاتف مستخدم بالفعل.');
        }

        // 4. التحقق من وجود الدور
        $role = $this->db->selectOne(
            "SELECT id, name FROM roles WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
            [(int) $data['role_id']]
        );

        if (!$role) {
            throw new Exception('الدور المحدد غير موجود أو غير نشط.');
        }

        // 5. التحقق من وجود المخزن (إذا تم تقديمه)
        if (!empty($data['warehouse_id'])) {
            $warehouse = $this->db->selectOne(
                "SELECT id FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['warehouse_id']]
            );

            if (!$warehouse) {
                throw new Exception('المخزن المحدد غير موجود أو غير نشط.');
            }
        }

        // 6. التحقق من كلمة المرور
        if (empty($data['password'])) {
            throw new Exception('كلمة المرور مطلوبة.');
        }

        if (strlen($data['password']) < 8) {
            throw new Exception('كلمة المرور يجب أن تكون 8 أحرف على الأقل.');
        }

        // 7. تشفير كلمة المرور
        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);

        // 8. بناء البيانات للإدراج
        $insertData = [
            'username'               => trim($data['username']),
            'password_hash'          => $passwordHash,
            'full_name'              => trim($data['full_name']),
            'email'                  => !empty($data['email']) ? trim($data['email']) : null,
            'phone'                  => !empty($data['phone']) ? trim($data['phone']) : null,
            'role_id'                => (int) $data['role_id'],
            'warehouse_id'           => !empty($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            'is_active'              => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'must_change_password'   => isset($data['must_change_password']) ? (int) $data['must_change_password'] : 1,
            'allow_multiple_devices' => isset($data['allow_multiple_devices']) ? (int) $data['allow_multiple_devices'] : 1,
            'language'               => !empty($data['language']) ? trim($data['language']) : 'ar',
            'theme'                  => !empty($data['theme']) ? trim($data['theme']) : 'dark',
            'created_by'             => $createdBy,
            'updated_by'             => $createdBy,
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ];

        // 9. إدراج المستخدم
        $userId = $this->db->insert('users', $insertData);

        return (int) $userId;
    }

    // =========================================================================
    // 2. جلب مستخدم واحد (Read)
    // =========================================================================

    /**
     * جلب مستخدم واحد مع التفاصيل
     *
     * @param int $id معرف المستخدم
     * @return array|null بيانات المستخدم أو null
     */
    public function getById(int $id): ?array
    {
        $user = $this->db->selectOne("
            SELECT
                u.id,
                u.username,
                u.full_name,
                u.email,
                u.phone,
                u.role_id,
                u.warehouse_id,
                u.avatar,
                u.is_active,
                u.is_locked,
                u.locked_until,
                u.failed_login_attempts,
                u.last_login_at,
                u.last_login_ip,
                u.must_change_password,
                u.allow_multiple_devices,
                u.language,
                u.theme,
                u.created_at,
                u.updated_at,
                r.name AS role_name,
                r.display_name AS role_display_name,
                w.name AS warehouse_name,
                w.code AS warehouse_code,
                creator.full_name AS created_by_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN warehouses w ON u.warehouse_id = w.id
            LEFT JOIN users creator ON u.created_by = creator.id
            WHERE u.id = ?
              AND u.deleted_at IS NULL
        ", [$id]);

        if (!$user) {
            return null;
        }

        // إزالة البيانات الحساسة
        unset($user['password_hash']);

        // تحويل القيم
        $user['is_active'] = (bool) $user['is_active'];
        $user['is_locked'] = (bool) $user['is_locked'];
        $user['must_change_password'] = (bool) $user['must_change_password'];
        $user['allow_multiple_devices'] = (bool) $user['allow_multiple_devices'];

        return $user;
    }

    // =========================================================================
    // 3. جلب قائمة المستخدمين (List)
    // =========================================================================

    /**
     * جلب قائمة المستخدمين مع الفلاتر
     *
     * @param array $filters الفلاتر
     * @return array قائمة المستخدمين
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                u.id,
                u.username,
                u.full_name,
                u.email,
                u.phone,
                u.role_id,
                u.warehouse_id,
                u.is_active,
                u.is_locked,
                u.last_login_at,
                u.created_at,
                r.name AS role_name,
                r.display_name AS role_display_name,
                w.name AS warehouse_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN warehouses w ON u.warehouse_id = w.id
            WHERE u.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['role_id'])) {
            $sql .= " AND u.role_id = ?";
            $params[] = (int) $filters['role_id'];
        }

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND u.warehouse_id = ?";
            $params[] = (int) $filters['warehouse_id'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND u.is_active = ?";
            $params[] = (int) $filters['is_active'];
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc');

        $allowedSortBy = ['username', 'full_name', 'created_at', 'last_login_at'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'created_at';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $sql .= " ORDER BY u.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $users = $this->db->select($sql, $params);

        // تحويل القيم
        foreach ($users as &$user) {
            $user['is_active'] = (bool) $user['is_active'];
            $user['is_locked'] = (bool) $user['is_locked'];
        }

        return $users;
    }

    // =========================================================================
    // 4. تعديل مستخدم (Update)
    // =========================================================================

    /**
     * تعديل مستخدم موجود
     *
     * @param int $id معرف المستخدم
     * @param array $data البيانات الجديدة
     * @param int $updatedBy معرف المستخدم الذي يعدّل
     * @return void
     * @throws Exception إذا فشل التحديث
     */
    public function update(int $id, array $data, int $updatedBy): void
    {
        // 1. جلب المستخدم
        $user = $this->db->selectOne(
            "SELECT id, username, role_id FROM users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$user) {
            throw new Exception('المستخدم غير موجود.');
        }

        // 2. حماية المستخدم الرئيسي
        if ($user['username'] === self::PROTECTED_USERNAME) {
            // لا يمكن تغيير الدور
            if (isset($data['role_id']) && (int) $data['role_id'] !== (int) $user['role_id']) {
                throw new Exception('لا يمكن تغيير دور المستخدم الرئيسي (admin).');
            }
        }

        // 3. التحقق من تفرد email (إذا تم تغييره)
        if (!empty($data['email'])) {
            $emailTaken = $this->db->selectOne(
                "SELECT id FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL",
                [trim($data['email']), $id]
            );

            if ($emailTaken) {
                throw new Exception('البريد الإلكتروني مستخدم بالفعل.');
            }
        }

        // 4. التحقق من تفرد phone (إذا تم تغييره)
        if (!empty($data['phone'])) {
            $phoneTaken = $this->db->selectOne(
                "SELECT id FROM users WHERE phone = ? AND id != ? AND deleted_at IS NULL",
                [trim($data['phone']), $id]
            );

            if ($phoneTaken) {
                throw new Exception('رقم الهاتف مستخدم بالفعل.');
            }
        }

        // 5. التحقق من وجود الدور (إذا تم تغييره)
        if (!empty($data['role_id'])) {
            $role = $this->db->selectOne(
                "SELECT id FROM roles WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['role_id']]
            );

            if (!$role) {
                throw new Exception('الدور المحدد غير موجود أو غير نشط.');
            }
        }

        // 6. التحقق من وجود المخزن (إذا تم تغييره)
        if (isset($data['warehouse_id']) && $data['warehouse_id'] !== null) {
            $warehouse = $this->db->selectOne(
                "SELECT id FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['warehouse_id']]
            );

            if (!$warehouse) {
                throw new Exception('المخزن المحدد غير موجود أو غير نشط.');
            }
        }

        // 7. بناء بيانات التحديث
        $updateData = ['updated_by' => $updatedBy, 'updated_at' => date('Y-m-d H:i:s')];

        $allowedFields = [
            'full_name', 'email', 'phone', 'role_id', 'warehouse_id',
            'is_active', 'must_change_password', 'allow_multiple_devices',
            'language', 'theme'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        // 8. تحديث المستخدم
        $this->db->update('users', $updateData, ['id' => $id]);
    }

    // =========================================================================
    // 5. حذف مستخدم (Soft Delete)
    // =========================================================================

    /**
     * حذف مستخدم (Soft Delete)
     *
     * @param int $id معرف المستخدم
     * @return void
     * @throws Exception إذا فشل الحذف
     */
    public function delete(int $id): void
    {
        // 1. جلب المستخدم
        $user = $this->db->selectOne(
            "SELECT id, username FROM users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$user) {
            throw new Exception('المستخدم غير موجود.');
        }

        // 2. حماية المستخدم الرئيسي
        if ($user['username'] === self::PROTECTED_USERNAME) {
            throw new Exception('لا يمكن حذف المستخدم الرئيسي (admin).');
        }

        // 3. إلغاء جميع الجلسات النشطة
        $this->db->execute(
            "UPDATE user_sessions SET is_active = 0, revoked_at = NOW(), revoked_reason = 'USER_DELETED' WHERE user_id = ?",
            [$id]
        );

        // 4. حذف المستخدم (Soft Delete)
        $this->db->update('users', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    // =========================================================================
    // 6. إعادة تعيين كلمة المرور (Reset Password)
    // =========================================================================

    /**
     * إعادة تعيين كلمة المرور للمستخدم
     *
     * @param int $id معرف المستخدم
     * @param string|null $newPassword كلمة المرور الجديدة (اختياري - سيتم توليدها تلقائياً)
     * @return string كلمة المرور الجديدة
     * @throws Exception إذا فشل التحديث
     */
    public function resetPassword(int $id, ?string $newPassword = null): string
    {
        // 1. جلب المستخدم
        $user = $this->db->selectOne(
            "SELECT id, username FROM users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$user) {
            throw new Exception('المستخدم غير موجود.');
        }

        // 2. توليد كلمة مرور جديدة إذا لم يتم تقديمها
        if ($newPassword === null) {
            $newPassword = $this->generateTemporaryPassword();
        }

        // 3. التحقق من قوة كلمة المرور
        if (strlen($newPassword) < 8) {
            throw new Exception('كلمة المرور يجب أن تكون 8 أحرف على الأقل.');
        }

        // 4. تشفير كلمة المرور
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        // 5. تحديث كلمة المرور
        $this->db->update('users', [
            'password_hash'        => $passwordHash,
            'must_change_password' => 1,
            'updated_at'           => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        // 6. إلغاء جميع الجلسات الأخرى
        $this->db->execute(
            "UPDATE user_sessions SET is_active = 0, revoked_at = NOW(), revoked_reason = 'PASSWORD_RESET' WHERE user_id = ?",
            [$id]
        );

        return $newPassword;
    }

    // =========================================================================
    // 7. تفعيل/تعطيل حساب (Activate/Deactivate)
    // =========================================================================

    /**
     * تفعيل أو تعطيل حساب المستخدم
     *
     * @param int $id معرف المستخدم
     * @param bool $isActive حالة التفعيل
     * @param int $updatedBy معرف المستخدم الذي يعدّل
     * @return void
     * @throws Exception إذا فشل التحديث
     */
    public function setActive(int $id, bool $isActive, int $updatedBy): void
    {
        $user = $this->db->selectOne(
            "SELECT id, username, is_active FROM users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$user) {
            throw new Exception('المستخدم غير موجود.');
        }

        // إذا تم التعطيل، إلغاء جميع الجلسات
        if (!$isActive && $user['is_active']) {
            $this->db->execute(
                "UPDATE user_sessions SET is_active = 0, revoked_at = NOW(), revoked_reason = 'USER_DISABLED' WHERE user_id = ?",
                [$id]
            );
        }

        $this->db->update('users', [
            'is_active'  => $isActive ? 1 : 0,
            'updated_by' => $updatedBy,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    // =========================================================================
    // 8. التحقق من الوجود والتفرد
    // =========================================================================

    /**
     * التحقق من وجود مستخدم معين
     */
    public function exists(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من تفرد اسم المستخدم
     */
    public function isUsernameTaken(string $username, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM users WHERE username = ? AND deleted_at IS NULL";
        $params = [trim($username)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من تفرد البريد الإلكتروني
     */
    public function isEmailTaken(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM users WHERE email = ? AND deleted_at IS NULL";
        $params = [trim($email)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من تفرد رقم الهاتف
     */
    public function isPhoneTaken(string $phone, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM users WHERE phone = ? AND deleted_at IS NULL";
        $params = [trim($phone)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    // =========================================================================
    // 9. دوال مساعدة
    // =========================================================================

    /**
     * توليد كلمة مرور مؤقتة
     *
     * @return string كلمة المرور المؤقتة
     */
    private function generateTemporaryPassword(): string
    {
        // توليد كلمة مرور عشوائية من 12 حرف
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        $password = '';

        for ($i = 0; $i < 12; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }
}
<?php

/**
 * ================================================================
 * Logistox - Recipient Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/RecipientService.php
 * الوظيفة: منطق الأعمال للجهات المستلمة (CRUD)
 *
 * المسؤوليات:
 * 1. إنشاء جهة مستلمة جديدة مع التحقق من التفرد
 * 2. جلب جهة مستلمة واحدة مع التفاصيل
 * 3. جلب قائمة الجهات المستلمة مع الفلاتر
 * 4. تعديل جهة مستلمة موجودة
 * 5. حذف جهة مستلمة (Soft Delete) مع التحقق من الارتباطات
 * 6. التحقق من وجود ارتباطات (إذونات الصرف المرتبطة)
 * 7. التحقق من تفرد code
 * 8. جلب الجهات المستلمة النشطة (للقوائم المنسدلة)
 * 9. التحقق من صحة البريد الإلكتروني ورقم الهاتف
 *
 * قيود الحماية:
 * - منع حذف جهة مستلمة لها إذونات صرف مرتبطة (issues.recipient_id)
 * - التحقق من تفرد code
 * - التحقق من طول الحقول
 * - التحقق من صحة البريد الإلكتروني
 * - التحقق من صحة رقم الهاتف
 * - التحقق من صحة نوع الجهة (type)
 *
 * ملاحظات هامة:
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - الجهات المستلمة ليست هرمية (لا parent_id)
 * - الجهات المستلمة لها أنواع (department, employee, external, project)
 * - مرتبطون بجدول issues فقط (إذونات الصرف)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Throwable;
use Exception;

/**
 * Class RecipientService
 *
 * خدمة إدارة الجهات المستلمة
 */
class RecipientService
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var array أنواع الجهات المستلمة المسموحة
     */
    private const ALLOWED_TYPES = ['department', 'employee', 'external', 'project'];

    /**
     * Constructor
     *
     * @param Database $db اتصال قاعدة البيانات
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // 1. إنشاء جهة مستلمة جديدة (Create)
    // =========================================================================

    /**
     * إنشاء جهة مستلمة جديدة
     *
     * @param array $data بيانات الجهة المستلمة
     * @param int $userId معرف المستخدم الذي ينشئ الجهة
     * @return int معرف الجهة المستلمة الجديدة
     * @throws Exception إذا فشل الإنشاء أو كانت البيانات غير صالحة
     */
    public function create(array $data, int $userId): int
    {
        // 1. التحقق من تفرد الكود (إذا تم تقديمه)
        if (!empty($data['code'])) {
            $existing = $this->db->selectOne(
                "SELECT id FROM recipients WHERE code = ? AND deleted_at IS NULL",
                [trim($data['code'])]
            );

            if ($existing) {
                throw new Exception('كود الجهة المستلمة مستخدم بالفعل. يرجى استخدام كود مختلف.');
            }
        }

        // 2. التحقق من صحة البريد الإلكتروني (إذا تم تقديمه)
        if (!empty($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('البريد الإلكتروني غير صالح.');
            }
        }

        // 3. التحقق من صحة رقم الهاتف (إذا تم تقديمه)
        if (!empty($data['phone'])) {
            if (!$this->validatePhoneNumber($data['phone'])) {
                throw new Exception('رقم الهاتف غير صالح.');
            }
        }

        // 4. التحقق من صحة النوع
        $type = $data['type'] ?? 'department';
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new Exception('نوع الجهة المستلمة غير صالح. القيم المسموحة: ' . implode(', ', self::ALLOWED_TYPES));
        }

        // 5. بناء البيانات للإدراج
        $insertData = [
            'code'           => !empty($data['code']) ? trim($data['code']) : null,
            'name'           => trim($data['name']),
            'type'           => $type,
            'contact_person' => !empty($data['contact_person']) ? trim($data['contact_person']) : null,
            'phone'          => !empty($data['phone']) ? trim($data['phone']) : null,
            'email'          => !empty($data['email']) ? trim($data['email']) : null,
            'address'        => !empty($data['address']) ? trim($data['address']) : null,
            'notes'          => !empty($data['notes']) ? trim($data['notes']) : null,
            'is_active'      => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_by'     => $userId,
            'updated_by'     => $userId,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        // 6. إدراج الجهة المستلمة
        $recipientId = $this->db->insert('recipients', $insertData);

        return (int) $recipientId;
    }

    // =========================================================================
    // 2. جلب جهة مستلمة واحدة (Read)
    // =========================================================================

    /**
     * جلب جهة مستلمة واحدة مع التفاصيل
     *
     * @param int $id معرف الجهة المستلمة
     * @return array|null بيانات الجهة المستلمة أو null إذا لم توجد
     */
    public function getById(int $id): ?array
    {
        $sql = "
            SELECT
                r.id,
                r.code,
                r.name,
                r.type,
                r.contact_person,
                r.phone,
                r.email,
                r.address,
                r.notes,
                r.is_active,
                r.created_at,
                r.updated_at,
                r.created_by,
                r.updated_by,
                creator.full_name AS creator_name,
                (
                    SELECT COUNT(*)
                    FROM issues i
                    WHERE i.recipient_id = r.id AND i.deleted_at IS NULL
                ) AS issues_count,
                (
                    SELECT COUNT(*)
                    FROM issues i
                    WHERE i.recipient_id = r.id
                      AND i.deleted_at IS NULL
                      AND i.status IN ('approved', 'completed')
                ) AS completed_issues_count
            FROM recipients r
            LEFT JOIN users creator ON r.created_by = creator.id
            WHERE r.id = ?
              AND r.deleted_at IS NULL
        ";

        $recipient = $this->db->selectOne($sql, [$id]);

        if (!$recipient) {
            return null;
        }

        // تحويل القيم الرقمية
        $recipient['issues_count'] = (int) $recipient['issues_count'];
        $recipient['completed_issues_count'] = (int) $recipient['completed_issues_count'];

        return $recipient;
    }

    // =========================================================================
    // 3. جلب قائمة الجهات المستلمة (List)
    // =========================================================================

    /**
     * جلب قائمة الجهات المستلمة مع الفلاتر
     *
     * @param array $filters الفلاتر (search, type, is_active, sort_by, sort_order)
     * @return array قائمة الجهات المستلمة
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                r.id,
                r.code,
                r.name,
                r.type,
                r.contact_person,
                r.phone,
                r.email,
                r.address,
                r.notes,
                r.is_active,
                r.created_at,
                r.updated_at,
                (
                    SELECT COUNT(*)
                    FROM issues i
                    WHERE i.recipient_id = r.id AND i.deleted_at IS NULL
                ) AS issues_count
            FROM recipients r
            WHERE r.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (r.name LIKE ? OR r.code LIKE ? OR r.contact_person LIKE ? OR r.phone LIKE ? OR r.email LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['type']) && in_array($filters['type'], self::ALLOWED_TYPES, true)) {
            $sql .= " AND r.type = ?";
            $params[] = $filters['type'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND r.is_active = ?";
            $params[] = (int) $filters['is_active'];
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc');

        $allowedSortBy = ['name', 'code', 'type', 'contact_person', 'phone', 'created_at', 'updated_at'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'name';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

        $sql .= " ORDER BY r.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $recipients = $this->db->select($sql, $params);

        // تحويل القيم الرقمية
        foreach ($recipients as &$recipient) {
            $recipient['issues_count'] = (int) $recipient['issues_count'];
        }

        return $recipients;
    }

    // =========================================================================
    // 4. تعديل جهة مستلمة (Update)
    // =========================================================================

    /**
     * تعديل جهة مستلمة موجودة
     *
     * @param int $id معرف الجهة المستلمة
     * @param array $data البيانات المراد تحديثها
     * @param int $userId معرف المستخدم الذي يعدّل
     * @return void
     * @throws Exception إذا فشل التحديث أو كانت البيانات غير صالحة
     */
    public function update(int $id, array $data, int $userId): void
    {
        // 1. التحقق من وجود الجهة المستلمة
        $recipient = $this->db->selectOne(
            "SELECT id FROM recipients WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$recipient) {
            throw new Exception('الجهة المستلمة غير موجودة.');
        }

        // 2. التحقق من تفرد الكود (إذا تم تغييره)
        if (!empty($data['code'])) {
            $existingCode = $this->db->selectOne(
                "SELECT id FROM recipients WHERE code = ? AND id != ? AND deleted_at IS NULL",
                [trim($data['code']), $id]
            );

            if ($existingCode) {
                throw new Exception('كود الجهة المستلمة مستخدم بالفعل.');
            }
        }

        // 3. التحقق من صحة البريد الإلكتروني (إذا تم تغييره)
        if (!empty($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('البريد الإلكتروني غير صالح.');
            }
        }

        // 4. التحقق من صحة رقم الهاتف (إذا تم تغييره)
        if (!empty($data['phone'])) {
            if (!$this->validatePhoneNumber($data['phone'])) {
                throw new Exception('رقم الهاتف غير صالح.');
            }
        }

        // 5. التحقق من صحة النوع (إذا تم تغييره)
        if (!empty($data['type'])) {
            if (!in_array($data['type'], self::ALLOWED_TYPES, true)) {
                throw new Exception('نوع الجهة المستلمة غير صالح. القيم المسموحة: ' . implode(', ', self::ALLOWED_TYPES));
            }
        }

        // 6. بناء البيانات للتحديث
        $updateData = ['updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s')];

        $allowedFields = [
            'name', 'code', 'type', 'contact_person', 'phone',
            'email', 'address', 'notes', 'is_active'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        // 7. تحديث الجهة المستلمة
        $this->db->update('recipients', $updateData, ['id' => $id]);
    }

    // =========================================================================
    // 5. حذف جهة مستلمة (Soft Delete)
    // =========================================================================

    /**
     * حذف جهة مستلمة (Soft Delete)
     *
     * @param int $id معرف الجهة المستلمة
     * @return void
     * @throws Exception إذا فشل الحذف أو كانت الجهة مرتبطة بإذونات صرف
     */
    public function delete(int $id): void
    {
        // 1. التحقق من وجود الجهة المستلمة
        $recipient = $this->db->selectOne(
            "SELECT id, name, code FROM recipients WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$recipient) {
            throw new Exception('الجهة المستلمة غير موجودة.');
        }

        // 2. التحقق من عدم وجود إذونات صرف مرتبطة
        $hasIssues = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM issues WHERE recipient_id = ? AND deleted_at IS NULL",
            [$id]
        );

        if ((int) $hasIssues['count'] > 0) {
            throw new Exception(
                'لا يمكن حذف هذه الجهة المستلمة لأنها مرتبطة بإذونات صرف. ' .
                'هذه الجهة جزء من سجل تاريخي.'
            );
        }

        // 3. حذف الجهة المستلمة (Soft Delete)
        $this->db->update('recipients', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ], ['id' => $id]);
    }

    // =========================================================================
    // 6. التحقق من الوجود والتفرد
    // =========================================================================

    /**
     * التحقق من وجود جهة مستلمة معينة
     *
     * @param int $id معرف الجهة المستلمة
     * @return bool true إذا كانت الجهة موجودة
     */
    public function exists(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM recipients WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من تفرد كود الجهة المستلمة
     *
     * @param string $code كود الجهة المستلمة
     * @param int|null $excludeId استثناء جهة معينة (للتحديث)
     * @return bool true إذا كان الكود فريداً
     */
    public function isCodeUnique(string $code, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM recipients WHERE code = ? AND deleted_at IS NULL";
        $params = [trim($code)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) === 0;
    }

    /**
     * جلب كل الجهات المستلمة النشطة (للاستخدام في القوائم المنسدلة)
     *
     * @return array قائمة الجهات المستلمة النشطة
     */
    public function getActiveRecipients(): array
    {
        return $this->db->select("
            SELECT id, code, name, type, contact_person, phone, email
            FROM recipients
            WHERE is_active = 1 AND deleted_at IS NULL
            ORDER BY name ASC
        ");
    }

    /**
     * جلب الجهات المستلمة حسب النوع
     *
     * @param string $type نوع الجهة (department, employee, external, project)
     * @return array قائمة الجهات المستلمة
     */
    public function getByType(string $type): array
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return [];
        }

        return $this->db->select("
            SELECT id, code, name, type, contact_person, phone, email
            FROM recipients
            WHERE type = ? AND is_active = 1 AND deleted_at IS NULL
            ORDER BY name ASC
        ", [$type]);
    }

    /**
     * الحصول على الأنواع المسموحة
     *
     * @return array الأنواع المسموحة
     */
    public static function getAllowedTypes(): array
    {
        return self::ALLOWED_TYPES;
    }

    // =========================================================================
    // 7. دوال مساعدة
    // =========================================================================

    /**
     * التحقق من صحة رقم الهاتف
     *
     * @param string $phoneNumber رقم الهاتف
     * @return bool true إذا كان الرقم صالحاً
     */
    private function validatePhoneNumber(string $phoneNumber): bool
    {
        $cleaned = preg_replace('/[\s\-\(\)]+/', '', $phoneNumber);

        $length = strlen($cleaned);
        if ($length < 7 || $length > 15) {
            return false;
        }

        if (!preg_match('/^\+?[0-9]+$/', $cleaned)) {
            return false;
        }

        return true;
    }
}
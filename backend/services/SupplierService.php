<?php

/**
 * ================================================================
 * Logistox - Supplier Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/SupplierService.php
 * الوظيفة: منطق الأعمال للموردين (CRUD)
 *
 * المسؤوليات:
 * 1. إنشاء مورد جديد مع التحقق من التفرد
 * 2. جلب مورد واحد مع التفاصيل
 * 3. جلب قائمة الموردين مع الفلاتر
 * 4. تعديل مورد موجود
 * 5. حذف مورد (Soft Delete) مع التحقق من الارتباطات
 * 6. التحقق من وجود ارتباطات (إذونات الاستلام المرتبطة)
 * 7. التحقق من تفرد code
 * 8. جلب الموردين النشطين (للقوائم المنسدلة)
 * 9. التحقق من صحة البريد الإلكتروني وأرقام الهواتف
 *
 * قيود الحماية:
 * - منع حذف مورد له إذونات استلام مرتبطة (receipts.supplier_id)
 * - التحقق من تفرد code
 * - التحقق من طول الحقول
 * - التحقق من صحة البريد الإلكتروني
 * - التحقق من صحة أرقام الهواتف
 *
 * ملاحظات هامة:
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - الموردون ليسوا هرميين (لا parent_id)
 * - الموردون كيانات تجارية (لهم tax_number و commercial_register)
 * - مرتبطون بجدول receipts فقط
 * ================================================================
 */

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Throwable;
use Exception;

/**
 * Class SupplierService
 *
 * خدمة إدارة الموردين
 */
class SupplierService
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

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
    // 1. إنشاء مورد جديد (Create)
    // =========================================================================

    /**
     * إنشاء مورد جديد
     *
     * @param array $data بيانات المورد
     * @param int $userId معرف المستخدم الذي ينشئ المورد
     * @return int معرف المورد الجديد
     * @throws Exception إذا فشل الإنشاء أو كانت البيانات غير صالحة
     */
    public function create(array $data, int $userId): int
    {
        // 1. التحقق من تفرد الكود (إذا تم تقديمه)
        if (!empty($data['code'])) {
            $existing = $this->db->selectOne(
                "SELECT id FROM suppliers WHERE code = ? AND deleted_at IS NULL",
                [trim($data['code'])]
            );

            if ($existing) {
                throw new Exception('كود المورد مستخدم بالفعل. يرجى استخدام كود مختلف.');
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

        // 4. التحقق من صحة رقم الموبايل (إذا تم تقديمه)
        if (!empty($data['mobile'])) {
            if (!$this->validatePhoneNumber($data['mobile'])) {
                throw new Exception('رقم الموبايل غير صالح.');
            }
        }

        // 5. بناء البيانات للإدراج
        $insertData = [
            'code'               => !empty($data['code']) ? trim($data['code']) : null,
            'name'               => trim($data['name']),
            'contact_person'     => !empty($data['contact_person']) ? trim($data['contact_person']) : null,
            'phone'              => !empty($data['phone']) ? trim($data['phone']) : null,
            'mobile'             => !empty($data['mobile']) ? trim($data['mobile']) : null,
            'email'              => !empty($data['email']) ? trim($data['email']) : null,
            'address'            => !empty($data['address']) ? trim($data['address']) : null,
            'tax_number'         => !empty($data['tax_number']) ? trim($data['tax_number']) : null,
            'commercial_register'=> !empty($data['commercial_register']) ? trim($data['commercial_register']) : null,
            'notes'              => !empty($data['notes']) ? trim($data['notes']) : null,
            'is_active'          => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_by'         => $userId,
            'updated_by'         => $userId,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        // 6. إدراج المورد
        $supplierId = $this->db->insert('suppliers', $insertData);

        return (int) $supplierId;
    }

    // =========================================================================
    // 2. جلب مورد واحد (Read)
    // =========================================================================

    /**
     * جلب مورد واحد مع التفاصيل
     *
     * @param int $id معرف المورد
     * @return array|null بيانات المورد أو null إذا لم يوجد
     */
    public function getById(int $id): ?array
    {
        $sql = "
            SELECT
                s.id,
                s.code,
                s.name,
                s.contact_person,
                s.phone,
                s.mobile,
                s.email,
                s.address,
                s.tax_number,
                s.commercial_register,
                s.notes,
                s.is_active,
                s.created_at,
                s.updated_at,
                s.created_by,
                s.updated_by,
                creator.full_name AS creator_name,
                (
                    SELECT COUNT(*)
                    FROM receipts r
                    WHERE r.supplier_id = s.id AND r.deleted_at IS NULL
                ) AS receipts_count,
                (
                    SELECT COUNT(*)
                    FROM receipts r
                    WHERE r.supplier_id = s.id
                      AND r.deleted_at IS NULL
                      AND r.status IN ('approved', 'completed')
                ) AS completed_receipts_count
            FROM suppliers s
            LEFT JOIN users creator ON s.created_by = creator.id
            WHERE s.id = ?
              AND s.deleted_at IS NULL
        ";

        $supplier = $this->db->selectOne($sql, [$id]);

        if (!$supplier) {
            return null;
        }

        // تحويل القيم الرقمية
        $supplier['receipts_count'] = (int) $supplier['receipts_count'];
        $supplier['completed_receipts_count'] = (int) $supplier['completed_receipts_count'];

        return $supplier;
    }

    // =========================================================================
    // 3. جلب قائمة الموردين (List)
    // =========================================================================

    /**
     * جلب قائمة الموردين مع الفلاتر
     *
     * @param array $filters الفلاتر (search, is_active, sort_by, sort_order)
     * @return array قائمة الموردين
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                s.id,
                s.code,
                s.name,
                s.contact_person,
                s.phone,
                s.mobile,
                s.email,
                s.address,
                s.tax_number,
                s.commercial_register,
                s.notes,
                s.is_active,
                s.created_at,
                s.updated_at,
                (
                    SELECT COUNT(*)
                    FROM receipts r
                    WHERE r.supplier_id = s.id AND r.deleted_at IS NULL
                ) AS receipts_count
            FROM suppliers s
            WHERE s.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (s.name LIKE ? OR s.code LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ? OR s.mobile LIKE ? OR s.email LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND s.is_active = ?";
            $params[] = (int) $filters['is_active'];
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc');

        $allowedSortBy = ['name', 'code', 'contact_person', 'phone', 'created_at', 'updated_at'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'name';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

        $sql .= " ORDER BY s.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $suppliers = $this->db->select($sql, $params);

        // تحويل القيم الرقمية
        foreach ($suppliers as &$supplier) {
            $supplier['receipts_count'] = (int) $supplier['receipts_count'];
        }

        return $suppliers;
    }

    // =========================================================================
    // 4. تعديل مورد (Update)
    // =========================================================================

    /**
     * تعديل مورد موجود
     *
     * @param int $id معرف المورد
     * @param array $data البيانات المراد تحديثها
     * @param int $userId معرف المستخدم الذي يعدّل
     * @return void
     * @throws Exception إذا فشل التحديث أو كانت البيانات غير صالحة
     */
    public function update(int $id, array $data, int $userId): void
    {
        // 1. التحقق من وجود المورد
        $supplier = $this->db->selectOne(
            "SELECT id FROM suppliers WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$supplier) {
            throw new Exception('المورد غير موجود.');
        }

        // 2. التحقق من تفرد الكود (إذا تم تغييره)
        if (!empty($data['code'])) {
            $existingCode = $this->db->selectOne(
                "SELECT id FROM suppliers WHERE code = ? AND id != ? AND deleted_at IS NULL",
                [trim($data['code']), $id]
            );

            if ($existingCode) {
                throw new Exception('كود المورد مستخدم بالفعل.');
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

        // 5. التحقق من صحة رقم الموبايل (إذا تم تغييره)
        if (!empty($data['mobile'])) {
            if (!$this->validatePhoneNumber($data['mobile'])) {
                throw new Exception('رقم الموبايل غير صالح.');
            }
        }

        // 6. بناء البيانات للتحديث
        $updateData = ['updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s')];

        $allowedFields = [
            'name', 'code', 'contact_person', 'phone', 'mobile',
            'email', 'address', 'tax_number', 'commercial_register',
            'notes', 'is_active'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        // 7. تحديث المورد
        $this->db->update('suppliers', $updateData, ['id' => $id]);
    }

    // =========================================================================
    // 5. حذف مورد (Soft Delete)
    // =========================================================================

    /**
     * حذف مورد (Soft Delete)
     *
     * @param int $id معرف المورد
     * @return void
     * @throws Exception إذا فشل الحذف أو كان المورد مرتبطاً بإذونات استلام
     */
    public function delete(int $id): void
    {
        // 1. التحقق من وجود المورد
        $supplier = $this->db->selectOne(
            "SELECT id, name, code FROM suppliers WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$supplier) {
            throw new Exception('المورد غير موجود.');
        }

        // 2. التحقق من عدم وجود إذونات استلام مرتبطة
        $hasReceipts = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM receipts WHERE supplier_id = ? AND deleted_at IS NULL",
            [$id]
        );

        if ((int) $hasReceipts['count'] > 0) {
            throw new Exception(
                'لا يمكن حذف هذا المورد لأنه مرتبط بإذونات استلام. ' .
                'هذا المورد جزء من سجل تاريخي.'
            );
        }

        // 3. حذف المورد (Soft Delete)
        $this->db->update('suppliers', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ], ['id' => $id]);
    }

    // =========================================================================
    // 6. التحقق من الوجود والتفرد
    // =========================================================================

    /**
     * التحقق من وجود مورد معين
     *
     * @param int $id معرف المورد
     * @return bool true إذا كان المورد موجوداً
     */
    public function exists(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM suppliers WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من تفرد كود المورد
     *
     * @param string $code كود المورد
     * @param int|null $excludeId استثناء مورد معين (للتحديث)
     * @return bool true إذا كان الكود فريداً
     */
    public function isCodeUnique(string $code, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM suppliers WHERE code = ? AND deleted_at IS NULL";
        $params = [trim($code)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) === 0;
    }

    /**
     * جلب كل الموردين النشطين (للاستخدام في القوائم المنسدلة)
     *
     * @return array قائمة الموردين النشطين
     */
    public function getActiveSuppliers(): array
    {
        return $this->db->select("
            SELECT id, code, name, contact_person, phone, mobile, email
            FROM suppliers
            WHERE is_active = 1 AND deleted_at IS NULL
            ORDER BY name ASC
        ");
    }

    // =========================================================================
    // 7. دوال مساعدة
    // =========================================================================

    /**
     * التحقق من صحة رقم الهاتف المصري
     *
     * @param string $phoneNumber رقم الهاتف
     * @return bool true إذا كان الرقم صالحاً
     *
     * يدعم:
     * - أرقام مصرية: 01XXXXXXXXX (11 رقم)
     * - أرقام دولية: +20XXXXXXXXXX
     * - أرقام أرضية: 02XXXXXXXX, 03XXXXXXXX
     */
    private function validatePhoneNumber(string $phoneNumber): bool
    {
        // إزالة المسافات والشرطات
        $cleaned = preg_replace('/[\s\-\(\)]+/', '', $phoneNumber);

        // التحقق من الطول (7-15 رقم)
        $length = strlen($cleaned);
        if ($length < 7 || $length > 15) {
            return false;
        }

        // التحقق من أن الرقم يحتوي على أرقام فقط (مع + اختياري في البداية)
        if (!preg_match('/^\+?[0-9]+$/', $cleaned)) {
            return false;
        }

        return true;
    }
}
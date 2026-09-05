<?php

/**
 * ================================================================
 * Logistox - Unit Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/UnitService.php
 * الوظيفة: منطق الأعمال لوحدات القياس (CRUD)
 *
 * المسؤوليات:
 * 1. إنشاء وحدة قياس جديدة مع التحقق من التفرد
 * 2. جلب وحدة واحدة مع التفاصيل
 * 3. جلب قائمة الوحدات مع الفلاتر
 * 4. تعديل وحدة موجودة
 * 5. حذف وحدة (Soft Delete) مع التحقق من الارتباطات
 * 6. التحقق من وجود ارتباطات (منتجات مرتبطة)
 * 7. التحقق من تفرد code, name, symbol
 * 8. جلب الوحدات النشطة (للقوائم المنسدلة)
 *
 * قيود الحماية:
 * - منع حذف وحدة لها منتجات مرتبطة (products.unit_id)
 * - التحقق من تفرد code, name, symbol
 * - التحقق من طول الحقول
 *
 * ملاحظات هامة:
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - الوحدات ليست هرمية (لا parent_id)
 * - symbol حقل مطلوب وفريد (مثل: قطعة، كجم، جم، لتر)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Throwable;
use Exception;

/**
 * Class UnitService
 *
 * خدمة إدارة وحدات القياس
 */
class UnitService
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
    // 1. إنشاء وحدة جديدة (Create)
    // =========================================================================

    /**
     * إنشاء وحدة قياس جديدة
     *
     * @param array $data بيانات الوحدة
     * @param int $userId معرف المستخدم الذي ينشئ الوحدة
     * @return int معرف الوحدة الجديدة
     * @throws Exception إذا فشل الإنشاء أو كانت البيانات غير صالحة
     */
    public function create(array $data, int $userId): int
    {
        // 1. التحقق من تفرد الكود (إذا تم تقديمه)
        if (!empty($data['code'])) {
            $existing = $this->db->selectOne(
                "SELECT id FROM units WHERE code = ? AND deleted_at IS NULL",
                [trim($data['code'])]
            );

            if ($existing) {
                throw new Exception('كود الوحدة مستخدم بالفعل. يرجى استخدام كود مختلف.');
            }
        }

        // 2. التحقق من تفرد الاسم
        $existingName = $this->db->selectOne(
            "SELECT id FROM units WHERE name = ? AND deleted_at IS NULL",
            [trim($data['name'])]
        );

        if ($existingName) {
            throw new Exception('اسم الوحدة مستخدم بالفعل. يرجى استخدام اسم مختلف.');
        }

        // 3. التحقق من تفرد الرمز
        $existingSymbol = $this->db->selectOne(
            "SELECT id FROM units WHERE symbol = ? AND deleted_at IS NULL",
            [trim($data['symbol'])]
        );

        if ($existingSymbol) {
            throw new Exception('رمز الوحدة مستخدم بالفعل. يرجى استخدام رمز مختلف.');
        }

        // 4. بناء البيانات للإدراج
        $insertData = [
            'code'       => !empty($data['code']) ? trim($data['code']) : null,
            'name'       => trim($data['name']),
            'symbol'     => trim($data['symbol']),
            'is_active'  => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // 5. إدراج الوحدة
        $unitId = $this->db->insert('units', $insertData);

        return (int) $unitId;
    }

    // =========================================================================
    // 2. جلب وحدة واحدة (Read)
    // =========================================================================

    /**
     * جلب وحدة واحدة مع التفاصيل
     *
     * @param int $id معرف الوحدة
     * @return array|null بيانات الوحدة أو null إذا لم توجد
     */
    public function getById(int $id): ?array
    {
        $sql = "
            SELECT
                u.id,
                u.code,
                u.name,
                u.symbol,
                u.is_active,
                u.created_at,
                u.updated_at,
                u.created_by,
                u.updated_by,
                creator.full_name AS creator_name,
                (
                    SELECT COUNT(*)
                    FROM products p
                    WHERE p.unit_id = u.id AND p.deleted_at IS NULL
                ) AS products_count
            FROM units u
            LEFT JOIN users creator ON u.created_by = creator.id
            WHERE u.id = ?
              AND u.deleted_at IS NULL
        ";

        $unit = $this->db->selectOne($sql, [$id]);

        if (!$unit) {
            return null;
        }

        // تحويل القيم الرقمية
        $unit['products_count'] = (int) $unit['products_count'];

        return $unit;
    }

    // =========================================================================
    // 3. جلب قائمة الوحدات (List)
    // =========================================================================

    /**
     * جلب قائمة الوحدات مع الفلاتر
     *
     * @param array $filters الفلاتر (search, is_active, sort_by, sort_order)
     * @return array قائمة الوحدات
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                u.id,
                u.code,
                u.name,
                u.symbol,
                u.is_active,
                u.created_at,
                u.updated_at,
                (
                    SELECT COUNT(*)
                    FROM products p
                    WHERE p.unit_id = u.id AND p.deleted_at IS NULL
                ) AS products_count
            FROM units u
            WHERE u.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (u.name LIKE ? OR u.code LIKE ? OR u.symbol LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND u.is_active = ?";
            $params[] = (int) $filters['is_active'];
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc');

        $allowedSortBy = ['name', 'code', 'symbol', 'created_at', 'updated_at'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'name';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

        $sql .= " ORDER BY u.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $units = $this->db->select($sql, $params);

        // تحويل القيم الرقمية
        foreach ($units as &$unit) {
            $unit['products_count'] = (int) $unit['products_count'];
        }

        return $units;
    }

    // =========================================================================
    // 4. تعديل وحدة (Update)
    // =========================================================================

    /**
     * تعديل وحدة موجودة
     *
     * @param int $id معرف الوحدة
     * @param array $data البيانات المراد تحديثها
     * @param int $userId معرف المستخدم الذي يعدّل
     * @return void
     * @throws Exception إذا فشل التحديث أو كانت البيانات غير صالحة
     */
    public function update(int $id, array $data, int $userId): void
    {
        // 1. التحقق من وجود الوحدة
        $unit = $this->db->selectOne(
            "SELECT id FROM units WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$unit) {
            throw new Exception('الوحدة غير موجودة.');
        }

        // 2. التحقق من تفرد الاسم (إذا تم تغييره)
        if (!empty($data['name'])) {
            $existingName = $this->db->selectOne(
                "SELECT id FROM units WHERE name = ? AND id != ? AND deleted_at IS NULL",
                [trim($data['name']), $id]
            );

            if ($existingName) {
                throw new Exception('اسم الوحدة مستخدم بالفعل.');
            }
        }

        // 3. التحقق من تفرد الرمز (إذا تم تغييره)
        if (!empty($data['symbol'])) {
            $existingSymbol = $this->db->selectOne(
                "SELECT id FROM units WHERE symbol = ? AND id != ? AND deleted_at IS NULL",
                [trim($data['symbol']), $id]
            );

            if ($existingSymbol) {
                throw new Exception('رمز الوحدة مستخدم بالفعل.');
            }
        }

        // 4. التحقق من تفرد الكود (إذا تم تغييره)
        if (!empty($data['code'])) {
            $existingCode = $this->db->selectOne(
                "SELECT id FROM units WHERE code = ? AND id != ? AND deleted_at IS NULL",
                [trim($data['code']), $id]
            );

            if ($existingCode) {
                throw new Exception('كود الوحدة مستخدم بالفعل.');
            }
        }

        // 5. بناء البيانات للتحديث
        $updateData = ['updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s')];

        $allowedFields = ['name', 'code', 'symbol', 'is_active'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        // 6. تحديث الوحدة
        $this->db->update('units', $updateData, ['id' => $id]);
    }

    // =========================================================================
    // 5. حذف وحدة (Soft Delete)
    // =========================================================================

    /**
     * حذف وحدة (Soft Delete)
     *
     * @param int $id معرف الوحدة
     * @return void
     * @throws Exception إذا فشل الحذف أو كانت الوحدة مرتبطة بمنتجات
     */
    public function delete(int $id): void
    {
        // 1. التحقق من وجود الوحدة
        $unit = $this->db->selectOne(
            "SELECT id, name, code, symbol FROM units WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$unit) {
            throw new Exception('الوحدة غير موجودة.');
        }

        // 2. التحقق من عدم وجود منتجات مرتبطة
        $hasProducts = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM products WHERE unit_id = ? AND deleted_at IS NULL",
            [$id]
        );

        if ((int) $hasProducts['count'] > 0) {
            throw new Exception(
                'لا يمكن حذف هذه الوحدة لأنها مرتبطة بمنتجات. ' .
                'يجب إعادة تعيين المنتجات إلى وحدات أخرى أولاً.'
            );
        }

        // 3. حذف الوحدة (Soft Delete)
        $this->db->update('units', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ], ['id' => $id]);
    }

    // =========================================================================
    // 6. التحقق من الوجود والتفرد
    // =========================================================================

    /**
     * التحقق من وجود وحدة معينة
     *
     * @param int $id معرف الوحدة
     * @return bool true إذا كانت الوحدة موجودة
     */
    public function exists(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM units WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من تفرد كود الوحدة
     *
     * @param string $code كود الوحدة
     * @param int|null $excludeId استثناء وحدة معينة (للتحديث)
     * @return bool true إذا كان الكود فريداً
     */
    public function isCodeUnique(string $code, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM units WHERE code = ? AND deleted_at IS NULL";
        $params = [trim($code)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) === 0;
    }

    /**
     * التحقق من تفرد اسم الوحدة
     *
     * @param string $name اسم الوحدة
     * @param int|null $excludeId استثناء وحدة معينة (للتحديث)
     * @return bool true إذا كان الاسم فريداً
     */
    public function isNameUnique(string $name, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM units WHERE name = ? AND deleted_at IS NULL";
        $params = [trim($name)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) === 0;
    }

    /**
     * التحقق من تفرد رمز الوحدة
     *
     * @param string $symbol رمز الوحدة
     * @param int|null $excludeId استثناء وحدة معينة (للتحديث)
     * @return bool true إذا كان الرمز فريداً
     */
    public function isSymbolUnique(string $symbol, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM units WHERE symbol = ? AND deleted_at IS NULL";
        $params = [trim($symbol)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) === 0;
    }

    /**
     * جلب كل الوحدات النشطة (للاستخدام في القوائم المنسدلة)
     *
     * @return array قائمة الوحدات النشطة
     */
    public function getActiveUnits(): array
    {
        return $this->db->select("
            SELECT id, code, name, symbol
            FROM units
            WHERE is_active = 1 AND deleted_at IS NULL
            ORDER BY name ASC
        ");
    }
}
<?php

/**
 * ================================================================
 * Logistox - Return Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/ReturnService.php
 * الوظيفة: منطق الأعمال للمرتجعات (CRUD + تنفيذ فوري)
 *
 * أنواع المرتجعات:
 * - IN (وارد): بضاعة تعود إلى المخزن (من عميل/جهة مستلمة)
 *   → يزيد المخزون (مثل RECEIPT)
 * - OUT (صادر): بضاعة تُرد إلى المورد
 *   → ينقص المخزون (مثل ISSUE)
 *
 * ملاحظة مهمة:
 * المرتجعات تُنفذ فوراً عند الإنشاء (لا workflow)
 * لأن المرتجع عملية طارئة يجب معالجتها في نفس اللحظة
 *
 * قيود الحماية:
 * - التحقق من نوع المرتجع (IN/OUT)
 * - التحقق من توفر الرصيد في حالة RETURN_OUT
 * - التحقق من وجود المخزن والمنتج
 * - التحقق من الكمية الموجبة
 * - التحقق من المستند المرجعي (إذا تم تقديمه)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Throwable;
use Exception;

/**
 * Class ReturnService
 *
 * خدمة إدارة المرتجعات
 */
class ReturnService
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var array أنواع المرتجعات المسموحة
     */
    private const ALLOWED_TYPES = ['IN', 'OUT'];

    /**
     * @var array أنواع المستندات المرجعية المسموحة
     */
    private const ALLOWED_REFERENCE_TYPES = ['receipt', 'issue', 'transfer'];

    /**
     * Constructor
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // 1. إنشاء مرتجع جديد (Create + Execute)
    // =========================================================================

    /**
     * إنشاء مرتجع جديد وتنفيذه فوراً
     *
     * هذه العملية:
     * 1. تتحقق من البيانات
     * 2. تتحقق من توفر الرصيد (في حالة RETURN_OUT)
     * 3. تُنشئ سجل المرتجع
     * 4. تُنشئ حركة في stock_movements
     * 5. تُحدث stock_balances
     *
     * @param array $data بيانات المرتجع
     * @param int $userId معرف المستخدم
     * @return int معرف المرتجع الجديد
     * @throws Exception إذا فشل الإنشاء
     */
    public function create(array $data, int $userId): int
    {
        return $this->db->transaction(function (Database $db) use ($data, $userId) {
            // 1. التحقق من نوع المرتجع
            $returnType = strtoupper($data['return_type'] ?? '');
            if (!in_array($returnType, self::ALLOWED_TYPES, true)) {
                throw new Exception('نوع المرتجع غير صالح. القيم المسموحة: ' . implode(', ', self::ALLOWED_TYPES));
            }

            // 2. التحقق من وجود المخزن
            $warehouse = $db->selectOne(
                "SELECT id, name FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['warehouse_id']]
            );

            if (!$warehouse) {
                throw new Exception('المخزن المحدد غير موجود أو غير نشط.');
            }

            // 3. التحقق من وجود المنتج
            $product = $db->selectOne(
                "SELECT id, name, code FROM products WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['product_id']]
            );

            if (!$product) {
                throw new Exception('المنتج المحدد غير موجود أو غير نشط.');
            }

            // 4. التحقق من الكمية
            if (!isset($data['quantity']) || !is_numeric($data['quantity']) || (float) $data['quantity'] <= 0) {
                throw new Exception('الكمية يجب أن تكون رقماً موجباً.');
            }

            $quantity = (float) $data['quantity'];

            // 5. التحقق من unit_cost (اختياري)
            $unitCost = null;
            if (isset($data['unit_cost']) && $data['unit_cost'] !== null && $data['unit_cost'] !== '') {
                if (!is_numeric($data['unit_cost']) || (float) $data['unit_cost'] < 0) {
                    throw new Exception('سعر الوحدة يجب أن يكون رقماً غير سالب.');
                }
                $unitCost = (float) $data['unit_cost'];
            }

            // 6. حساب total_cost
            $totalCost = $unitCost !== null ? $quantity * $unitCost : null;

            // 7. التحقق من المستند المرجعي (إذا تم تقديمه)
            $referenceType = null;
            $referenceId = null;

            if (!empty($data['reference_type']) && !empty($data['reference_id'])) {
                $referenceType = strtolower($data['reference_type']);
                $referenceId = (int) $data['reference_id'];

                if (!in_array($referenceType, self::ALLOWED_REFERENCE_TYPES, true)) {
                    throw new Exception('نوع المستند المرجعي غير صالح. القيم المسموحة: ' . implode(', ', self::ALLOWED_REFERENCE_TYPES));
                }

                // التحقق من وجود المستند المرجعي
                $referenceExists = $this->validateReference($db, $referenceType, $referenceId);
                if (!$referenceExists) {
                    throw new Exception("المستند المرجعي ({$referenceType} #{$referenceId}) غير موجود.");
                }
            }

            // 8. التحقق من توفر الرصيد (في حالة RETURN_OUT)
            if ($returnType === 'OUT') {
                $balance = $db->selectOne(
                    "SELECT quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ?",
                    [(int) $data['product_id'], (int) $data['warehouse_id']]
                );

                $available = $balance ? (float) $balance['quantity'] : 0.0;

                if ($available < $quantity) {
                    throw new Exception(
                        "رصيد غير كافٍ للمنتج: {$product['name']} ({$product['code']}). " .
                        "المتاح: {$available}، المطلوب: {$quantity}"
                    );
                }
            }

            // 9. توليد رقم المرتجع
            $returnNumber = $this->generateReturnNumber($returnType);

            // 10. إدراج المرتجع
            $db->insert('returns', [
                'return_number'  => $returnNumber,
                'return_type'    => $returnType,
                'product_id'     => (int) $data['product_id'],
                'warehouse_id'   => (int) $data['warehouse_id'],
                'quantity'       => $quantity,
                'unit_cost'      => $unitCost,
                'total_cost'     => $totalCost,
                'reason'         => !empty($data['reason']) ? trim($data['reason']) : null,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'user_id'        => $userId,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);

            $returnId = (int) $db->lastInsertId();

            // 11. إنشاء حركة في stock_movements وتحديث stock_balances
            $this->processStockMovement($db, $returnId, $returnType, (int) $data['product_id'], (int) $data['warehouse_id'], $quantity, $unitCost, $totalCost, $userId);

            return $returnId;
        });
    }

    // =========================================================================
    // 2. جلب مرتجع واحد (Read)
    // =========================================================================

    /**
     * جلب مرتجع واحد مع التفاصيل
     *
     * @param int $id معرف المرتجع
     * @return array|null بيانات المرتجع أو null
     */
    public function getById(int $id): ?array
    {
        $return = $this->db->selectOne("
            SELECT
                r.id,
                r.return_number,
                r.return_type,
                r.product_id,
                r.warehouse_id,
                r.quantity,
                r.unit_cost,
                r.total_cost,
                r.reason,
                r.reference_type,
                r.reference_id,
                r.user_id,
                r.created_at,
                r.updated_at,
                p.code AS product_code,
                p.name AS product_name,
                p.barcode AS product_barcode,
                u.symbol AS unit_symbol,
                c.name AS category_name,
                w.name AS warehouse_name,
                w.code AS warehouse_code,
                creator.full_name AS created_by_name
            FROM returns r
            INNER JOIN products p ON r.product_id = p.id
            INNER JOIN warehouses w ON r.warehouse_id = w.id
            LEFT JOIN units u ON p.unit_id = u.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN users creator ON r.user_id = creator.id
            WHERE r.id = ?
              AND r.deleted_at IS NULL
        ", [$id]);

        if (!$return) {
            return null;
        }

        // تحويل القيم الرقمية
        $return['quantity'] = (float) $return['quantity'];
        $return['unit_cost'] = $return['unit_cost'] !== null ? (float) $return['unit_cost'] : null;
        $return['total_cost'] = $return['total_cost'] !== null ? (float) $return['total_cost'] : null;

        // إضافة اسم نوع المرتجع بالعربية
        $return['return_type_label'] = $return['return_type'] === 'IN' ? 'مرتجع وارد' : 'مرتجع صادر';

        return $return;
    }

    // =========================================================================
    // 3. جلب قائمة المرتجعات (List)
    // =========================================================================

    /**
     * جلب قائمة المرتجعات مع الفلاتر
     *
     * @param array $filters الفلاتر
     * @return array قائمة المرتجعات
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                r.id,
                r.return_number,
                r.return_type,
                r.product_id,
                r.warehouse_id,
                r.quantity,
                r.unit_cost,
                r.total_cost,
                r.reason,
                r.reference_type,
                r.reference_id,
                r.created_at,
                r.updated_at,
                p.code AS product_code,
                p.name AS product_name,
                u.symbol AS unit_symbol,
                w.name AS warehouse_name,
                creator.full_name AS created_by_name
            FROM returns r
            INNER JOIN products p ON r.product_id = p.id
            INNER JOIN warehouses w ON r.warehouse_id = w.id
            LEFT JOIN units u ON p.unit_id = u.id
            LEFT JOIN users creator ON r.user_id = creator.id
            WHERE r.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (r.return_number LIKE ? OR r.reason LIKE ? OR p.name LIKE ? OR p.code LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['return_type']) && in_array(strtoupper($filters['return_type']), self::ALLOWED_TYPES, true)) {
            $sql .= " AND r.return_type = ?";
            $params[] = strtoupper($filters['return_type']);
        }

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND r.warehouse_id = ?";
            $params[] = (int) $filters['warehouse_id'];
        }

        if (!empty($filters['product_id'])) {
            $sql .= " AND r.product_id = ?";
            $params[] = (int) $filters['product_id'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND r.created_at >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND r.created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc');

        $allowedSortBy = ['return_number', 'created_at', 'return_type', 'quantity', 'total_cost'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'created_at';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $sql .= " ORDER BY r.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $returns = $this->db->select($sql, $params);

        // تحويل القيم الرقمية وإضافة أسماء الأنواع بالعربية
        foreach ($returns as &$return) {
            $return['quantity'] = (float) $return['quantity'];
            $return['unit_cost'] = $return['unit_cost'] !== null ? (float) $return['unit_cost'] : null;
            $return['total_cost'] = $return['total_cost'] !== null ? (float) $return['total_cost'] : null;
            $return['return_type_label'] = $return['return_type'] === 'IN' ? 'مرتجع وارد' : 'مرتجع صادر';
        }

        return $returns;
    }

    // =========================================================================
    // 4. تعديل مرتجع (Update)
    // =========================================================================

    /**
     * تعديل مرتجع موجود
     *
     * ملاحظة: لا يمكن تعديل الكمية أو المنتج أو المخزن
     * لأن ذلك سيؤثر على حركات المخزون
     *
     * @param int $id معرف المرتجع
     * @param array $data البيانات الجديدة
     * @param int $userId معرف المستخدم
     * @return void
     * @throws Exception إذا فشل التحديث
     */
    public function update(int $id, array $data, int $userId): void
    {
        // 1. التحقق من وجود المرتجع
        $return = $this->db->selectOne(
            "SELECT id FROM returns WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$return) {
            throw new Exception('المرتجع غير موجود.');
        }

        // 2. بناء بيانات التحديث (فقط الحقول المسموحة)
        $updateData = ['updated_at' => date('Y-m-d H:i:s')];

        if (isset($data['reason'])) {
            $updateData['reason'] = !empty($data['reason']) ? trim($data['reason']) : null;
        }

        if (isset($data['unit_cost'])) {
            if ($data['unit_cost'] !== null && $data['unit_cost'] !== '') {
                if (!is_numeric($data['unit_cost']) || (float) $data['unit_cost'] < 0) {
                    throw new Exception('سعر الوحدة يجب أن يكون رقماً غير سالب.');
                }
                $updateData['unit_cost'] = (float) $data['unit_cost'];

                // إعادة حساب total_cost
                $currentReturn = $this->db->selectOne("SELECT quantity FROM returns WHERE id = ?", [$id]);
                $updateData['total_cost'] = (float) $currentReturn['quantity'] * (float) $data['unit_cost'];
            } else {
                $updateData['unit_cost'] = null;
                $updateData['total_cost'] = null;
            }
        }

        // 3. تحديث المرتجع
        $this->db->update('returns', $updateData, ['id' => $id]);
    }

    // =========================================================================
    // 5. حذف مرتجع (Soft Delete)
    // =========================================================================

    /**
     * حذف مرتجع (Soft Delete)
     *
     * ملاحظة مهمة:
     * الحذف لا يعكس حركة المخزون
     * لأن ذلك قد يسبب تعارضاً مع حركات أخرى
     *
     * @param int $id معرف المرتجع
     * @return void
     * @throws Exception إذا فشل الحذف
     */
    public function delete(int $id): void
    {
        $return = $this->db->selectOne(
            "SELECT id, return_number FROM returns WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$return) {
            throw new Exception('المرتجع غير موجود.');
        }

        // Soft Delete فقط (لا عكس حركة المخزون)
        $this->db->update('returns', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    // =========================================================================
    // 6. التحقق من الوجود
    // =========================================================================

    /**
     * التحقق من وجود مرتجع معين
     */
    public function exists(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM returns WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * جلب أنواع المرتجعات المسموحة
     */
    public static function getAllowedTypes(): array
    {
        return self::ALLOWED_TYPES;
    }

    /**
     * جلب أنواع المستندات المرجعية المسموحة
     */
    public static function getAllowedReferenceTypes(): array
    {
        return self::ALLOWED_REFERENCE_TYPES;
    }

    // =========================================================================
    // 7. دوال مساعدة
    // =========================================================================

    /**
     * التحقق من وجود مستند مرجعي
     *
     * @param Database $db اتصال قاعدة البيانات
     * @param string $referenceType نوع المستند (receipt, issue, transfer)
     * @param int $referenceId معرف المستند
     * @return bool true إذا كان المستند موجوداً
     */
    private function validateReference(Database $db, string $referenceType, int $referenceId): bool
    {
        $table = match ($referenceType) {
            'receipt'  => 'receipts',
            'issue'    => 'issues',
            'transfer' => 'transfers',
            default    => null,
        };

        if ($table === null) {
            return false;
        }

        $result = $db->selectOne(
            "SELECT COUNT(*) AS count FROM {$table} WHERE id = ? AND deleted_at IS NULL",
            [$referenceId]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * معالجة حركة المخزون للمرتجع
     *
     * @param Database $db اتصال قاعدة البيانات
     * @param int $returnId معرف المرتجع
     * @param string $returnType نوع المرتجع (IN/OUT)
     * @param int $productId معرف المنتج
     * @param int $warehouseId معرف المخزن
     * @param float $quantity الكمية
     * @param float|null $unitCost سعر الوحدة
     * @param float|null $totalCost التكلفة الإجمالية
     * @param int $userId معرف المستخدم
     */
    private function processStockMovement(
        Database $db,
        int $returnId,
        string $returnType,
        int $productId,
        int $warehouseId,
        float $quantity,
        ?float $unitCost,
        ?float $totalCost,
        int $userId
    ): void {
        // تحديد نوع الحركة
        $movementType = $returnType === 'IN' ? 'RETURN_IN' : 'RETURN_OUT';

        // جلب الرصيد الحالي مع قفله
        $balance = $db->selectOne(
            "SELECT quantity, reserved_quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? FOR UPDATE",
            [$productId, $warehouseId]
        );

        $currentQty = $balance ? (float) $balance['quantity'] : 0.0;
        $currentReserved = $balance ? (float) $balance['reserved_quantity'] : 0.0;

        // حساب الرصيد الجديد
        $newQty = $returnType === 'IN' ? $currentQty + $quantity : $currentQty - $quantity;

        // توليد رقم الحركة
        $movementNumber = $this->generateMovementNumber($movementType);

        // 1. تسجيل الحركة في stock_movements
        $db->execute("
            INSERT INTO stock_movements (
                movement_number, product_id, warehouse_id, movement_type,
                quantity, unit_cost, total_cost, balance_before, balance_after,
                reserved_before, reserved_after, reference_type, reference_id,
                user_id, movement_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ", [
            $movementNumber,
            $productId,
            $warehouseId,
            $movementType,
            $quantity,
            $unitCost,
            $totalCost,
            $currentQty,
            $newQty,
            $currentReserved,
            $currentReserved,
            'return',
            $returnId,
            $userId,
        ]);

        $movementId = (int) $db->lastInsertId();

        // 2. تحديث stock_balances
        $qtyChange = $returnType === 'IN' ? $quantity : -$quantity;

        $db->execute("
            INSERT INTO stock_balances (product_id, warehouse_id, quantity, reserved_quantity, last_movement_id, last_movement_date)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                quantity = quantity + VALUES(quantity),
                reserved_quantity = reserved_quantity + VALUES(reserved_quantity),
                last_movement_id = VALUES(last_movement_id),
                last_movement_date = NOW(),
                updated_at = NOW()
        ", [$productId, $warehouseId, $qtyChange, 0, $movementId]);
    }

    /**
     * توليد رقم مرتجع فريد
     *
     * الصيغة: RTN-IN-YYYYMMDD-XXXXXX أو RTN-OUT-YYYYMMDD-XXXXXX
     */
    private function generateReturnNumber(string $returnType): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        return "RTN-{$returnType}-{$date}-{$random}";
    }

    /**
     * توليد رقم حركة فريد
     *
     * الصيغة: RTN-YYYYMMDD-XXXXXX
     */
    private function generateMovementNumber(string $movementType): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        return "RTN-{$date}-{$random}";
    }
}
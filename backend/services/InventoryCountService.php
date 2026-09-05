<?php

/**
 * ================================================================
 * Logistox - Inventory Count Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/InventoryCountService.php
 * الوظيفة: منطق الأعمال لعمليات الجرد المخزني
 *
 * دورة حياة عملية الجرد:
 * draft → in_progress → completed → approved
 * draft → cancelled
 * in_progress → cancelled
 * completed → cancelled
 *
 * عند الاعتماد (approve):
 * 1. يتم حساب الفرق لكل بند: difference = counted_quantity - system_quantity
 * 2. لكل فرق ≠ 0، يتم إنشاء حركة COUNT_CORRECTION في stock_movements
 * 3. يتم تحديث stock_balances
 * 4. يتم تغيير حالة الجرد إلى approved
 *
 * قيود الحماية:
 * - منع إضافة بند مكرر (نفس المنتج في نفس الجرد)
 * - التحقق من وجود المخزن والمنتج
 * - التحقق من الكميات غير السالبة
 * - منع الاعتماد المزدوج
 * - منع تعديل الجرد المعتمد
 * ================================================================
 */

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Throwable;
use Exception;

/**
 * Class InventoryCountService
 *
 * خدمة إدارة عمليات الجرد المخزني
 */
class InventoryCountService
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var array حالات الجرد المسموحة
     */
    private const ALLOWED_STATUSES = ['draft', 'in_progress', 'completed', 'approved', 'cancelled'];

    /**
     * Constructor
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // 1. إنشاء عملية جرد جديدة (Create)
    // =========================================================================

    /**
     * إنشاء عملية جرد جديدة (بدون بنود)
     *
     * @param array $data بيانات الجرد
     * @param int $userId معرف المستخدم
     * @return int معرف عملية الجرد الجديدة
     * @throws Exception إذا فشل الإنشاء
     */
    public function create(array $data, int $userId): int
    {
        // 1. التحقق من وجود المخزن
        $warehouse = $this->db->selectOne(
            "SELECT id, name FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
            [(int) $data['warehouse_id']]
        );

        if (!$warehouse) {
            throw new Exception('المخزن المحدد غير موجود أو غير نشط.');
        }

        // 2. التحقق من تاريخ الجرد
        $countDate = $data['count_date'] ?? date('Y-m-d');
        $dateTime = \DateTime::createFromFormat('Y-m-d', $countDate);
        if (!$dateTime || $dateTime->format('Y-m-d') !== $countDate) {
            throw new Exception('تاريخ الجرد غير صالح. الصيغة: YYYY-MM-DD.');
        }

        // 3. توليد رقم الجرد
        $countNumber = $this->generateCountNumber();

        // 4. إدراج عملية الجرد
        $this->db->insert('inventory_counts', [
            'count_number' => $countNumber,
            'warehouse_id' => (int) $data['warehouse_id'],
            'count_date'   => $countDate,
            'status'       => 'draft',
            'started_by'   => $userId,
            'notes'        => !empty($data['notes']) ? trim($data['notes']) : null,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    // =========================================================================
    // 2. جلب عملية جرد واحدة (Read)
    // =========================================================================

    /**
     * جلب عملية جرد واحدة مع بنودها
     *
     * @param int $id معرف عملية الجرد
     * @return array|null بيانات عملية الجرد أو null
     */
    public function getById(int $id): ?array
    {
        // 1. جلب بيانات عملية الجرد
        $count = $this->db->selectOne("
            SELECT
                ic.id,
                ic.count_number,
                ic.warehouse_id,
                ic.count_date,
                ic.status,
                ic.started_by,
                ic.approved_by,
                ic.approved_at,
                ic.notes,
                ic.created_at,
                ic.updated_at,
                w.name AS warehouse_name,
                w.code AS warehouse_code,
                starter.full_name AS started_by_name,
                approver.full_name AS approved_by_name
            FROM inventory_counts ic
            INNER JOIN warehouses w ON ic.warehouse_id = w.id
            LEFT JOIN users starter ON ic.started_by = starter.id
            LEFT JOIN users approver ON ic.approved_by = approver.id
            WHERE ic.id = ?
              AND ic.deleted_at IS NULL
        ", [$id]);

        if (!$count) {
            return null;
        }

        // 2. جلب البنود
        $items = $this->db->select("
            SELECT
                ici.id,
                ici.product_id,
                ici.system_quantity,
                ici.counted_quantity,
                ici.difference_quantity,
                ici.unit_cost,
                ici.notes,
                ici.created_at,
                ici.updated_at,
                p.code AS product_code,
                p.name AS product_name,
                p.barcode AS product_barcode,
                u.symbol AS unit_symbol,
                c.name AS category_name
            FROM inventory_count_items ici
            INNER JOIN products p ON ici.product_id = p.id
            LEFT JOIN units u ON p.unit_id = u.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE ici.inventory_count_id = ?
            ORDER BY ici.id ASC
        ", [$id]);

        // 3. تحويل القيم الرقمية
        foreach ($items as &$item) {
            $item['system_quantity'] = (float) $item['system_quantity'];
            $item['counted_quantity'] = (float) $item['counted_quantity'];
            $item['difference_quantity'] = (float) $item['difference_quantity'];
            $item['unit_cost'] = $item['unit_cost'] !== null ? (float) $item['unit_cost'] : null;
        }

        $count['items'] = $items;
        $count['total_items'] = count($items);
        $count['total_differences'] = count(array_filter($items, fn($i) => (float) $i['difference_quantity'] !== 0.0));

        // إضافة اسم الحالة بالعربية
        $count['status_label'] = $this->getStatusLabels()[$count['status']] ?? $count['status'];

        return $count;
    }

    // =========================================================================
    // 3. جلب قائمة عمليات الجرد (List)
    // =========================================================================

    /**
     * جلب قائمة عمليات الجرد مع الفلاتر
     *
     * @param array $filters الفلاتر
     * @return array قائمة عمليات الجرد
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                ic.id,
                ic.count_number,
                ic.warehouse_id,
                ic.count_date,
                ic.status,
                ic.started_by,
                ic.approved_by,
                ic.approved_at,
                ic.notes,
                ic.created_at,
                ic.updated_at,
                w.name AS warehouse_name,
                starter.full_name AS started_by_name,
                approver.full_name AS approved_by_name,
                (
                    SELECT COUNT(*)
                    FROM inventory_count_items ici
                    WHERE ici.inventory_count_id = ic.id
                ) AS items_count,
                (
                    SELECT COUNT(*)
                    FROM inventory_count_items ici
                    WHERE ici.inventory_count_id = ic.id
                      AND ici.difference_quantity != 0
                ) AS differences_count
            FROM inventory_counts ic
            INNER JOIN warehouses w ON ic.warehouse_id = w.id
            LEFT JOIN users starter ON ic.started_by = starter.id
            LEFT JOIN users approver ON ic.approved_by = approver.id
            WHERE ic.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (ic.count_number LIKE ? OR ic.notes LIKE ? OR w.name LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::ALLOWED_STATUSES, true)) {
            $sql .= " AND ic.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND ic.warehouse_id = ?";
            $params[] = (int) $filters['warehouse_id'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND ic.count_date >= ?";
            $params[] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND ic.count_date <= ?";
            $params[] = $filters['to_date'];
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc');

        $allowedSortBy = ['count_number', 'count_date', 'created_at', 'status'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'created_at';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $sql .= " ORDER BY ic.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $counts = $this->db->select($sql, $params);

        // تحويل القيم الرقمية وإضافة أسماء الحالات بالعربية
        $statusLabels = $this->getStatusLabels();
        foreach ($counts as &$count) {
            $count['items_count'] = (int) $count['items_count'];
            $count['differences_count'] = (int) $count['differences_count'];
            $count['status_label'] = $statusLabels[$count['status']] ?? $count['status'];
        }

        return $counts;
    }

    // =========================================================================
    // 4. تعديل عملية جرد (Update)
    // =========================================================================

    /**
     * تعديل عملية جرد (فقط إذا كانت draft)
     *
     * @param int $id معرف عملية الجرد
     * @param array $data البيانات الجديدة
     * @param int $userId معرف المستخدم
     * @return void
     * @throws Exception إذا فشل التحديث
     */
    public function update(int $id, array $data, int $userId): void
    {
        $count = $this->db->selectOne(
            "SELECT id, status FROM inventory_counts WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$count) {
            throw new Exception('عملية الجرد غير موجودة.');
        }

        if ($count['status'] !== 'draft') {
            throw new Exception(
                'لا يمكن تعديل عملية جرد في حالة "' . $count['status'] . '". ' .
                'يمكن التعديل فقط في حالة "draft".'
            );
        }

        // بناء بيانات التحديث
        $updateData = ['updated_at' => date('Y-m-d H:i:s')];

        if (!empty($data['warehouse_id'])) {
            $warehouse = $this->db->selectOne(
                "SELECT id FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['warehouse_id']]
            );

            if (!$warehouse) {
                throw new Exception('المخزن المحدد غير موجود أو غير نشط.');
            }

            $updateData['warehouse_id'] = (int) $data['warehouse_id'];
        }

        if (!empty($data['count_date'])) {
            $dateTime = \DateTime::createFromFormat('Y-m-d', $data['count_date']);
            if (!$dateTime || $dateTime->format('Y-m-d') !== $data['count_date']) {
                throw new Exception('تاريخ الجرد غير صالح.');
            }
            $updateData['count_date'] = $data['count_date'];
        }

        if (isset($data['notes'])) {
            $updateData['notes'] = !empty($data['notes']) ? trim($data['notes']) : null;
        }

        $this->db->update('inventory_counts', $updateData, ['id' => $id]);
    }

    // =========================================================================
    // 5. بدء الجرد (Start)
    // =========================================================================

    /**
     * بدء عملية الجرد (draft → in_progress)
     *
     * عند البدء:
     * - يتم التقاط system_quantity لكل منتج من stock_balances
     * - يتم تغيير الحالة إلى in_progress
     *
     * @param int $id معرف عملية الجرد
     * @param int $userId معرف المستخدم
     * @return void
     * @throws Exception إذا فشل البدء
     */
    public function start(int $id, int $userId): void
    {
        $this->db->transaction(function (Database $db) use ($id, $userId) {
            // 1. جلب عملية الجرد مع قفلها
            $count = $db->selectOne(
                "SELECT id, status, warehouse_id FROM inventory_counts WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );

            if (!$count) {
                throw new Exception('عملية الجرد غير موجودة.');
            }

            if ($count['status'] !== 'draft') {
                throw new Exception(
                    'لا يمكن بدء عملية جرد في حالة "' . $count['status'] . '". ' .
                    'يمكن البدء فقط في حالة "draft".'
                );
            }

            // 2. التحقق من وجود بنود
            $itemsCount = $db->selectOne(
                "SELECT COUNT(*) AS count FROM inventory_count_items WHERE inventory_count_id = ?",
                [$id]
            );

            if ((int) $itemsCount['count'] === 0) {
                throw new Exception('يجب إضافة منتج واحد على الأقل قبل بدء الجرد.');
            }

            // 3. التقاط system_quantity لكل بند من stock_balances
            $items = $db->select("
                SELECT ici.id, ici.product_id
                FROM inventory_count_items ici
                WHERE ici.inventory_count_id = ?
            ", [$id]);

            foreach ($items as $item) {
                $balance = $db->selectOne(
                    "SELECT quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ?",
                    [$item['product_id'], $count['warehouse_id']]
                );

                $systemQuantity = $balance ? (float) $balance['quantity'] : 0.0;

                $db->update('inventory_count_items', [
                    'system_quantity' => $systemQuantity,
                    'updated_at'      => date('Y-m-d H:i:s'),
                ], ['id' => $item['id']]);
            }

            // 4. تغيير الحالة إلى in_progress
            $db->update('inventory_counts', [
                'status'     => 'in_progress',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
        });
    }

    // =========================================================================
    // 6. إضافة بند للجرد (Add Item)
    // =========================================================================

    /**
     * إضافة بند (منتج) لعملية الجرد
     *
     * @param int $countId معرف عملية الجرد
     * @param array $data بيانات البند
     * @return int معرف البند الجديد
     * @throws Exception إذا فشل الإضافة
     */
    public function addItem(int $countId, array $data): int
    {
        return $this->db->transaction(function (Database $db) use ($countId, $data) {
            // 1. جلب عملية الجرد
            $count = $db->selectOne(
                "SELECT id, status, warehouse_id FROM inventory_counts WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$countId]
            );

            if (!$count) {
                throw new Exception('عملية الجرد غير موجودة.');
            }

            // يمكن إضافة البنود في draft أو in_progress
            if (!in_array($count['status'], ['draft', 'in_progress'], true)) {
                throw new Exception(
                    'لا يمكن إضافة بنود لعملية جرد في حالة "' . $count['status'] . '".'
                );
            }

            // 2. التحقق من المنتج
            $productId = (int) $data['product_id'];
            $product = $db->selectOne(
                "SELECT id, name, code FROM products WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [$productId]
            );

            if (!$product) {
                throw new Exception('المنتج المحدد غير موجود أو غير نشط.');
            }

            // 3. التحقق من عدم وجود البند مسبقاً
            $existing = $db->selectOne(
                "SELECT id FROM inventory_count_items WHERE inventory_count_id = ? AND product_id = ?",
                [$countId, $productId]
            );

            if ($existing) {
                throw new Exception("المنتج '{$product['name']}' موجود بالفعل في عملية الجرد.");
            }

            // 4. التقاط system_quantity من stock_balances
            $balance = $db->selectOne(
                "SELECT quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ?",
                [$productId, $count['warehouse_id']]
            );

            $systemQuantity = $balance ? (float) $balance['quantity'] : 0.0;

            // 5. التحقق من counted_quantity (إذا تم تقديمه)
            $countedQuantity = 0.0;
            if (isset($data['counted_quantity']) && $data['counted_quantity'] !== null && $data['counted_quantity'] !== '') {
                if (!is_numeric($data['counted_quantity']) || (float) $data['counted_quantity'] < 0) {
                    throw new Exception('الكمية المعدودة يجب أن تكون رقماً غير سالب.');
                }
                $countedQuantity = (float) $data['counted_quantity'];
            }

            // 6. التحقق من unit_cost (اختياري)
            $unitCost = null;
            if (isset($data['unit_cost']) && $data['unit_cost'] !== null && $data['unit_cost'] !== '') {
                if (!is_numeric($data['unit_cost']) || (float) $data['unit_cost'] < 0) {
                    throw new Exception('سعر الوحدة يجب أن يكون رقماً غير سالب.');
                }
                $unitCost = (float) $data['unit_cost'];
            }

            // 7. إدراج البند
            $db->insert('inventory_count_items', [
                'inventory_count_id' => $countId,
                'product_id'         => $productId,
                'system_quantity'    => $systemQuantity,
                'counted_quantity'   => $countedQuantity,
                'unit_cost'          => $unitCost,
                'notes'              => !empty($data['notes']) ? trim($data['notes']) : null,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);

            return (int) $db->lastInsertId();
        });
    }

    // =========================================================================
    // 7. تعديل بند في الجرد (Update Item)
    // =========================================================================

    /**
     * تعديل بند في عملية الجرد (الكمية المعدودة، التكلفة، الملاحظات)
     *
     * @param int $countId معرف عملية الجرد
     * @param int $itemId معرف البند
     * @param array $data البيانات الجديدة
     * @return void
     * @throws Exception إذا فشل التعديل
     */
    public function updateItem(int $countId, int $itemId, array $data): void
    {
        $this->db->transaction(function (Database $db) use ($countId, $itemId, $data) {
            // 1. جلب عملية الجرد
            $count = $db->selectOne(
                "SELECT id, status FROM inventory_counts WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$countId]
            );

            if (!$count) {
                throw new Exception('عملية الجرد غير موجودة.');
            }

            if (!in_array($count['status'], ['draft', 'in_progress'], true)) {
                throw new Exception('لا يمكن تعديل البنود في حالة "' . $count['status'] . '".');
            }

            // 2. جلب البند
            $item = $db->selectOne(
                "SELECT id FROM inventory_count_items WHERE id = ? AND inventory_count_id = ?",
                [$itemId, $countId]
            );

            if (!$item) {
                throw new Exception('البند غير موجود.');
            }

            // 3. بناء بيانات التحديث
            $updateData = ['updated_at' => date('Y-m-d H:i:s')];

            if (isset($data['counted_quantity'])) {
                if ($data['counted_quantity'] !== null && $data['counted_quantity'] !== '') {
                    if (!is_numeric($data['counted_quantity']) || (float) $data['counted_quantity'] < 0) {
                        throw new Exception('الكمية المعدودة يجب أن تكون رقماً غير سالب.');
                    }
                    $updateData['counted_quantity'] = (float) $data['counted_quantity'];
                }
            }

            if (isset($data['unit_cost'])) {
                if ($data['unit_cost'] !== null && $data['unit_cost'] !== '') {
                    if (!is_numeric($data['unit_cost']) || (float) $data['unit_cost'] < 0) {
                        throw new Exception('سعر الوحدة يجب أن يكون رقماً غير سالب.');
                    }
                    $updateData['unit_cost'] = (float) $data['unit_cost'];
                } else {
                    $updateData['unit_cost'] = null;
                }
            }

            if (isset($data['notes'])) {
                $updateData['notes'] = !empty($data['notes']) ? trim($data['notes']) : null;
            }

            // 4. تحديث البند
            $db->update('inventory_count_items', $updateData, ['id' => $itemId]);
        });
    }

    // =========================================================================
    // 8. حذف بند من الجرد (Remove Item)
    // =========================================================================

    /**
     * حذف بند من عملية الجرد
     *
     * @param int $countId معرف عملية الجرد
     * @param int $itemId معرف البند
     * @return void
     * @throws Exception إذا فشل الحذف
     */
    public function removeItem(int $countId, int $itemId): void
    {
        $this->db->transaction(function (Database $db) use ($countId, $itemId) {
            $count = $db->selectOne(
                "SELECT id, status FROM inventory_counts WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$countId]
            );

            if (!$count) {
                throw new Exception('عملية الجرد غير موجودة.');
            }

            if (!in_array($count['status'], ['draft', 'in_progress'], true)) {
                throw new Exception('لا يمكن حذف البنود في حالة "' . $count['status'] . '".');
            }

            $item = $db->selectOne(
                "SELECT id FROM inventory_count_items WHERE id = ? AND inventory_count_id = ?",
                [$itemId, $countId]
            );

            if (!$item) {
                throw new Exception('البند غير موجود.');
            }

            $db->execute("DELETE FROM inventory_count_items WHERE id = ?", [$itemId]);
        });
    }

    // =========================================================================
    // 9. إكمال الجرد (Complete)
    // =========================================================================

    /**
     * إكمال عملية الجرد (in_progress → completed)
     *
     * @param int $id معرف عملية الجرد
     * @param int $userId معرف المستخدم
     * @return void
     * @throws Exception إذا فشل الإكمال
     */
    public function complete(int $id, int $userId): void
    {
        $count = $this->db->selectOne(
            "SELECT id, status FROM inventory_counts WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$count) {
            throw new Exception('عملية الجرد غير موجودة.');
        }

        if ($count['status'] !== 'in_progress') {
            throw new Exception(
                'لا يمكن إكمال عملية جرد في حالة "' . $count['status'] . '". ' .
                'يمكن الإكمال فقط في حالة "in_progress".'
            );
        }

        // التحقق من أن كل البنود لديها counted_quantity
        $incompleteItems = $this->db->selectOne("
            SELECT COUNT(*) AS count
            FROM inventory_count_items
            WHERE inventory_count_id = ?
              AND counted_quantity IS NULL
        ", [$id]);

        if ((int) $incompleteItems['count'] > 0) {
            throw new Exception(
                'لا يمكن إكمال الجرد. هناك ' . $incompleteItems['count'] . ' بند(بنود) لم يتم عدّها بعد.'
            );
        }

        $this->db->update('inventory_counts', [
            'status'     => 'completed',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    // =========================================================================
    // 10. اعتماد الجرد (Approve) - الأهم!
    // =========================================================================

    /**
     * اعتماد عملية جرد وتسوية الفروقات
     *
     * هذه العملية:
     * 1. تتحقق من حالة الجرد (completed فقط)
     * 2. لكل بند له فرق ≠ 0:
     *    - إنشاء حركة COUNT_CORRECTION في stock_movements
     *    - تحديث stock_balances
     * 3. تغيير حالة الجرد إلى approved
     *
     * @param int $id معرف عملية الجرد
     * @param int $userId معرف المستخدم الذي يعتمد
     * @return array نتيجة العملية
     * @throws Exception إذا فشل الاعتماد
     */
    public function approve(int $id, int $userId): array
    {
        return $this->db->transaction(function (Database $db) use ($id, $userId) {
            // 1. جلب عملية الجرد مع قفلها
            $count = $db->selectOne(
                "SELECT id, status, warehouse_id FROM inventory_counts WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );

            if (!$count) {
                throw new Exception('عملية الجرد غير موجودة.');
            }

            if ($count['status'] !== 'completed') {
                throw new Exception(
                    'لا يمكن اعتماد عملية جرد في حالة "' . $count['status'] . '". ' .
                    'يمكن الاعتماد فقط في حالة "completed".'
                );
            }

            // 2. جلب البنود
            $items = $db->select("
                SELECT
                    ici.id,
                    ici.product_id,
                    ici.system_quantity,
                    ici.counted_quantity,
                    ici.unit_cost,
                    p.name AS product_name,
                    p.code AS product_code
                FROM inventory_count_items ici
                INNER JOIN products p ON ici.product_id = p.id
                WHERE ici.inventory_count_id = ?
            ", [$id]);

            $correctionsMade = 0;
            $totalAdjustment = 0.0;

            // 3. معالجة كل بند له فرق
            foreach ($items as $item) {
                $difference = (float) $item['counted_quantity'] - (float) $item['system_quantity'];

                if (abs($difference) < 0.0001) {
                    continue; // لا يوجد فرق
                }

                $productId = (int) $item['product_id'];
                $warehouseId = (int) $count['warehouse_id'];
                $absDifference = abs($difference);
                $unitCost = $item['unit_cost'] !== null ? (float) $item['unit_cost'] : 0.0;
                $totalCost = $absDifference * $unitCost;

                // جلب الرصيد الحالي مع قفله
                $balance = $db->selectOne(
                    "SELECT quantity, reserved_quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ? FOR UPDATE",
                    [$productId, $warehouseId]
                );

                $currentQty = $balance ? (float) $balance['quantity'] : 0.0;
                $currentReserved = $balance ? (float) $balance['reserved_quantity'] : 0.0;
                $newQty = $currentQty + $difference;

                // توليد رقم الحركة
                $movementNumber = $this->generateMovementNumber();

                // تسجيل الحركة
                $db->execute("
                    INSERT INTO stock_movements (
                        movement_number, product_id, warehouse_id, movement_type,
                        quantity, unit_cost, total_cost, balance_before, balance_after,
                        reserved_before, reserved_after, reference_type, reference_id,
                        notes, user_id, movement_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ", [
                    $movementNumber,
                    $productId,
                    $warehouseId,
                    'COUNT_CORRECTION',
                    $absDifference,
                    $unitCost,
                    $totalCost,
                    $currentQty,
                    $newQty,
                    $currentReserved,
                    $currentReserved,
                    'inventory_count',
                    $id,
                    $difference > 0
                        ? "تسوية جرد: زيادة {$absDifference} ({$item['product_name']})"
                        : "تسوية جرد: نقصان {$absDifference} ({$item['product_name']})",
                    $userId,
                ]);

                $movementId = (int) $db->lastInsertId();

                // تحديث stock_balances
                $db->execute("
                    INSERT INTO stock_balances (product_id, warehouse_id, quantity, reserved_quantity, last_movement_id, last_movement_date)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        quantity = quantity + VALUES(quantity),
                        reserved_quantity = reserved_quantity + VALUES(reserved_quantity),
                        last_movement_id = VALUES(last_movement_id),
                        last_movement_date = NOW(),
                        updated_at = NOW()
                ", [$productId, $warehouseId, $difference, 0, $movementId]);

                $correctionsMade++;
                $totalAdjustment += $difference;
            }

            // 4. تغيير حالة الجرد إلى approved
            $db->update('inventory_counts', [
                'status'      => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ], ['id' => $id]);

            return [
                'corrections_made' => $correctionsMade,
                'total_adjustment' => $totalAdjustment,
                'total_items'      => count($items),
            ];
        });
    }

    // =========================================================================
    // 11. إلغاء الجرد (Cancel)
    // =========================================================================

    /**
     * إلغاء عملية جرد
     *
     * @param int $id معرف عملية الجرد
     * @param int $userId معرف المستخدم
     * @return void
     * @throws Exception إذا فشل الإلغاء
     */
    public function cancel(int $id, int $userId): void
    {
        $count = $this->db->selectOne(
            "SELECT id, status FROM inventory_counts WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$count) {
            throw new Exception('عملية الجرد غير موجودة.');
        }

        if ($count['status'] === 'approved') {
            throw new Exception(
                'لا يمكن إلغاء عملية جرد معتمدة. ' .
                'تم تسوية الفروقات وتحديث المخزون بناءً على هذه العملية.'
            );
        }

        $this->db->update('inventory_counts', [
            'status'     => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    // =========================================================================
    // 12. حذف عملية جرد (Soft Delete)
    // =========================================================================

    /**
     * حذف عملية جرد (فقط إذا كانت draft أو cancelled)
     *
     * @param int $id معرف عملية الجرد
     * @return void
     * @throws Exception إذا فشل الحذف
     */
    public function delete(int $id): void
    {
        $this->db->transaction(function (Database $db) use ($id) {
            $count = $db->selectOne(
                "SELECT id, status, count_number FROM inventory_counts WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );

            if (!$count) {
                throw new Exception('عملية الجرد غير موجودة.');
            }

            if (!in_array($count['status'], ['draft', 'cancelled'], true)) {
                throw new Exception(
                    'لا يمكن حذف عملية جرد في حالة "' . $count['status'] . '". ' .
                    'يمكن الحذف فقط في حالة "draft" أو "cancelled".'
                );
            }

            // حذف البنود
            $db->execute("DELETE FROM inventory_count_items WHERE inventory_count_id = ?", [$id]);

            // حذف عملية الجرد (Soft Delete)
            $db->update('inventory_counts', [
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
        });
    }

    // =========================================================================
    // 13. التحقق من الوجود
    // =========================================================================

    /**
     * التحقق من وجود عملية جرد معينة
     */
    public function exists(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM inventory_counts WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * جلب حالات الجرد المسموحة
     */
    public static function getAllowedStatuses(): array
    {
        return self::ALLOWED_STATUSES;
    }

    /**
     * جلب أسماء الحالات بالعربية
     */
    public function getStatusLabels(): array
    {
        return [
            'draft'       => 'مسودة',
            'in_progress' => 'قيد التنفيذ',
            'completed'   => 'مكتمل',
            'approved'    => 'معتمد',
            'cancelled'   => 'ملغى',
        ];
    }

    // =========================================================================
    // 14. دوال مساعدة
    // =========================================================================

    /**
     * توليد رقم جرد فريد
     *
     * الصيغة: CNT-YYYYMMDD-XXXXXX
     */
    private function generateCountNumber(): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        return "CNT-{$date}-{$random}";
    }

    /**
     * توليد رقم حركة فريد
     *
     * الصيغة: CNT-YYYYMMDD-XXXXXX
     */
    private function generateMovementNumber(): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        return "CNT-{$date}-{$random}";
    }
}
<?php

/**
 * ================================================================
 * Logistox - Transfer Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/TransferService.php
 * الوظيفة: منطق الأعمال للتحويلات المخزنية (CRUD + Approval Workflow)
 *
 * دورة حياة التحويل:
 * pending → approved → completed
 * pending → cancelled
 *
 * عند الاعتماد (approve):
 * 1. يتم التحقق من حالة التحويل (pending فقط)
 * 2. يتم التحقق من توفر الرصيد في المخزن المصدر
 * 3. يتم استدعاء InventoryService::approveTransfer()
 * 4. InventoryService يقوم بـ:
 *    - إنشاء حركة TRANSFER_OUT في stock_movements (من المصدر)
 *    - إنشاء حركة TRANSFER_IN في stock_movements (إلى الوجهة)
 *    - تحديث stock_balances للمخزنين
 * 5. يتم تغيير حالة التحويل إلى approved
 *
 * قيود الحماية:
 * - منع تحويل من مخزن إلى نفسه
 * - منع تعديل/حذف تحويل معتمد أو مكتمل
 * - التحقق من توفر الرصيد قبل الصرف
 * - التحقق من وجود المخزنين
 * - التحقق من وجود المنتجات
 * - منع الاعتماد المزدوج
 * ================================================================
 */

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Throwable;
use Exception;

/**
 * Class TransferService
 *
 * خدمة إدارة التحويلات المخزنية
 */
class TransferService
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var InventoryService محرك المخزون
     */
    private InventoryService $inventoryService;

    /**
     * @var array حالات التحويل المسموحة
     */
    private const ALLOWED_STATUSES = ['pending', 'approved', 'completed', 'cancelled'];

    /**
     * Constructor
     */
    public function __construct(Database $db, InventoryService $inventoryService)
    {
        $this->db = $db;
        $this->inventoryService = $inventoryService;
    }

    // =========================================================================
    // 1. إنشاء تحويل جديد (Create)
    // =========================================================================

    /**
     * إنشاء تحويل مخزني جديد مع بنوده
     *
     * @param array $data بيانات التحويل
     * @param int $userId معرف المستخدم
     * @return int معرف التحويل الجديد
     * @throws Exception إذا فشل الإنشاء
     */
    public function create(array $data, int $userId): int
    {
        return $this->db->transaction(function (Database $db) use ($data, $userId) {
            // 1. التحقق من وجود المخزن المصدر
            $fromWarehouse = $db->selectOne(
                "SELECT id, name FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['from_warehouse_id']]
            );

            if (!$fromWarehouse) {
                throw new Exception('المخزن المصدر المحدد غير موجود أو غير نشط.');
            }

            // 2. التحقق من وجود المخزن الوجهة
            $toWarehouse = $db->selectOne(
                "SELECT id, name FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['to_warehouse_id']]
            );

            if (!$toWarehouse) {
                throw new Exception('المخزن الوجهة المحدد غير موجود أو غير نشط.');
            }

            // 3. التحقق من أن المخزن المصدر ≠ المخزن الوجهة
            if ((int) $data['from_warehouse_id'] === (int) $data['to_warehouse_id']) {
                throw new Exception('لا يمكن التحويل من مخزن إلى نفسه. يرجى اختيار مخزن وجهة مختلف.');
            }

            // 4. التحقق من البنود
            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception('يجب إضافة بند واحد على الأقل للتحويل.');
            }

            // 5. التحقق من كل بند وحساب الإجماليات
            $totalItems = 0;
            $totalQuantity = 0.0;
            $totalCost = 0.0;
            $validatedItems = [];

            foreach ($data['items'] as $index => $item) {
                $validatedItem = $this->validateAndPrepareItem($db, $item, $index);
                $validatedItems[] = $validatedItem;

                $totalItems++;
                $totalQuantity += (float) $validatedItem['quantity'];
                $totalCost += (float) $validatedItem['total_cost'];
            }

            // 6. توليد رقم التحويل
            $transferNumber = $this->generateTransferNumber();

            // 7. إدراج التحويل
            $db->insert('transfers', [
                'transfer_number'  => $transferNumber,
                'from_warehouse_id'=> (int) $data['from_warehouse_id'],
                'to_warehouse_id'  => (int) $data['to_warehouse_id'],
                'transfer_reason'  => !empty($data['transfer_reason']) ? trim($data['transfer_reason']) : null,
                'total_items'      => $totalItems,
                'total_quantity'   => $totalQuantity,
                'total_cost'       => $totalCost,
                'notes'            => !empty($data['notes']) ? trim($data['notes']) : null,
                'status'           => 'pending',
                'user_id'          => $userId,
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);

            $transferId = (int) $db->lastInsertId();

            // 8. إدراج البنود
            foreach ($validatedItems as $item) {
                $db->insert('transfer_items', [
                    'transfer_id'=> $transferId,
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'unit_cost'  => $item['unit_cost'],
                    'total_cost' => $item['total_cost'],
                    'notes'      => $item['notes'],
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $transferId;
        });
    }

    // =========================================================================
    // 2. جلب تحويل واحد (Read)
    // =========================================================================

    /**
     * جلب تحويل مخزني واحد مع بنوده
     *
     * @param int $id معرف التحويل
     * @return array|null بيانات التحويل أو null
     */
    public function getById(int $id): ?array
    {
        // 1. جلب بيانات التحويل
        $transfer = $this->db->selectOne("
            SELECT
                t.id,
                t.transfer_number,
                t.from_warehouse_id,
                t.to_warehouse_id,
                t.transfer_reason,
                t.total_items,
                t.total_quantity,
                t.total_cost,
                t.notes,
                t.status,
                t.approved_by,
                t.approved_at,
                t.user_id,
                t.created_at,
                t.updated_at,
                w_from.name AS from_warehouse_name,
                w_from.code AS from_warehouse_code,
                w_to.name AS to_warehouse_name,
                w_to.code AS to_warehouse_code,
                creator.full_name AS created_by_name,
                approver.full_name AS approved_by_name
            FROM transfers t
            INNER JOIN warehouses w_from ON t.from_warehouse_id = w_from.id
            INNER JOIN warehouses w_to ON t.to_warehouse_id = w_to.id
            LEFT JOIN users creator ON t.user_id = creator.id
            LEFT JOIN users approver ON t.approved_by = approver.id
            WHERE t.id = ?
              AND t.deleted_at IS NULL
        ", [$id]);

        if (!$transfer) {
            return null;
        }

        // 2. جلب البنود مع الأرصدة المتاحة في المخزن المصدر
        $items = $this->db->select("
            SELECT
                ti.id,
                ti.product_id,
                ti.quantity,
                ti.unit_cost,
                ti.total_cost,
                ti.notes,
                ti.created_at,
                p.code AS product_code,
                p.name AS product_name,
                p.barcode AS product_barcode,
                u.symbol AS unit_symbol,
                c.name AS category_name,
                sb.quantity AS available_quantity
            FROM transfer_items ti
            INNER JOIN products p ON ti.product_id = p.id
            LEFT JOIN units u ON p.unit_id = u.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = t.from_warehouse_id
            WHERE ti.transfer_id = ?
            ORDER BY ti.id ASC
        ", [$id]);

        // 3. تحويل القيم الرقمية
        $transfer['total_items'] = (int) $transfer['total_items'];
        $transfer['total_quantity'] = (float) $transfer['total_quantity'];
        $transfer['total_cost'] = (float) $transfer['total_cost'];

        foreach ($items as &$item) {
            $item['quantity'] = (float) $item['quantity'];
            $item['unit_cost'] = $item['unit_cost'] !== null ? (float) $item['unit_cost'] : null;
            $item['total_cost'] = $item['total_cost'] !== null ? (float) $item['total_cost'] : null;
            $item['available_quantity'] = $item['available_quantity'] !== null ? (float) $item['available_quantity'] : 0.0;
        }

        $transfer['items'] = $items;

        return $transfer;
    }

    // =========================================================================
    // 3. جلب قائمة التحويلات (List)
    // =========================================================================

    /**
     * جلب قائمة التحويلات المخزنية مع الفلاتر
     *
     * @param array $filters الفلاتر
     * @return array قائمة التحويلات
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                t.id,
                t.transfer_number,
                t.from_warehouse_id,
                t.to_warehouse_id,
                t.transfer_reason,
                t.total_items,
                t.total_quantity,
                t.total_cost,
                t.notes,
                t.status,
                t.approved_at,
                t.created_at,
                t.updated_at,
                w_from.name AS from_warehouse_name,
                w_to.name AS to_warehouse_name,
                creator.full_name AS created_by_name,
                approver.full_name AS approved_by_name
            FROM transfers t
            INNER JOIN warehouses w_from ON t.from_warehouse_id = w_from.id
            INNER JOIN warehouses w_to ON t.to_warehouse_id = w_to.id
            LEFT JOIN users creator ON t.user_id = creator.id
            LEFT JOIN users approver ON t.approved_by = approver.id
            WHERE t.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (t.transfer_number LIKE ? OR t.transfer_reason LIKE ? OR t.notes LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::ALLOWED_STATUSES, true)) {
            $sql .= " AND t.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['from_warehouse_id'])) {
            $sql .= " AND t.from_warehouse_id = ?";
            $params[] = (int) $filters['from_warehouse_id'];
        }

        if (!empty($filters['to_warehouse_id'])) {
            $sql .= " AND t.to_warehouse_id = ?";
            $params[] = (int) $filters['to_warehouse_id'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND t.created_at >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND t.created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc');

        $allowedSortBy = ['transfer_number', 'created_at', 'status', 'total_cost', 'total_quantity'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'created_at';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $sql .= " ORDER BY t.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $transfers = $this->db->select($sql, $params);

        // تحويل القيم الرقمية
        foreach ($transfers as &$transfer) {
            $transfer['total_items'] = (int) $transfer['total_items'];
            $transfer['total_quantity'] = (float) $transfer['total_quantity'];
            $transfer['total_cost'] = (float) $transfer['total_cost'];
        }

        return $transfers;
    }

    // =========================================================================
    // 4. تعديل تحويل (Update)
    // =========================================================================

    /**
     * تعديل تحويل مخزني (فقط إذا كان pending)
     *
     * @param int $id معرف التحويل
     * @param array $data البيانات الجديدة
     * @param int $userId معرف المستخدم
     * @return void
     * @throws Exception إذا فشل التحديث
     */
    public function update(int $id, array $data, int $userId): void
    {
        $this->db->transaction(function (Database $db) use ($id, $data, $userId) {
            // 1. جلب التحويل مع قفله
            $transfer = $db->selectOne(
                "SELECT id, status, from_warehouse_id FROM transfers WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );

            if (!$transfer) {
                throw new Exception('التحويل غير موجود.');
            }

            // 2. التحقق من الحالة
            if ($transfer['status'] !== 'pending') {
                throw new Exception(
                    'لا يمكن تعديل تحويل في حالة "' . $transfer['status'] . '". ' .
                    'يمكن تعديل التحويلات في حالة "pending" فقط.'
                );
            }

            // 3. تحديد المخازن الحالية والجديدة
            $fromWarehouseId = !empty($data['from_warehouse_id']) ? (int) $data['from_warehouse_id'] : (int) $transfer['from_warehouse_id'];
            $toWarehouseId = !empty($data['to_warehouse_id']) ? (int) $data['to_warehouse_id'] : null;

            // إذا لم يتم تغيير to_warehouse_id، نجلبه من التحويل الأصلي
            if ($toWarehouseId === null) {
                $original = $db->selectOne(
                    "SELECT to_warehouse_id FROM transfers WHERE id = ?",
                    [$id]
                );
                $toWarehouseId = (int) $original['to_warehouse_id'];
            }

            // 4. التحقق من أن المخزن المصدر ≠ المخزن الوجهة
            if ($fromWarehouseId === $toWarehouseId) {
                throw new Exception('لا يمكن التحويل من مخزن إلى نفسه. يرجى اختيار مخزن وجهة مختلف.');
            }

            // 5. التحقق من وجود المخزن المصدر
            if (!empty($data['from_warehouse_id'])) {
                $fromWarehouse = $db->selectOne(
                    "SELECT id FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                    [$fromWarehouseId]
                );

                if (!$fromWarehouse) {
                    throw new Exception('المخزن المصدر المحدد غير موجود أو غير نشط.');
                }
            }

            // 6. التحقق من وجود المخزن الوجهة
            if (!empty($data['to_warehouse_id'])) {
                $toWarehouse = $db->selectOne(
                    "SELECT id FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                    [$toWarehouseId]
                );

                if (!$toWarehouse) {
                    throw new Exception('المخزن الوجهة المحدد غير موجود أو غير نشط.');
                }
            }

            // 7. بناء بيانات التحديث
            $updateData = ['updated_at' => date('Y-m-d H:i:s')];

            if (!empty($data['from_warehouse_id'])) {
                $updateData['from_warehouse_id'] = $fromWarehouseId;
            }

            if (!empty($data['to_warehouse_id'])) {
                $updateData['to_warehouse_id'] = $toWarehouseId;
            }

            if (isset($data['transfer_reason'])) {
                $updateData['transfer_reason'] = !empty($data['transfer_reason']) ? trim($data['transfer_reason']) : null;
            }

            if (isset($data['notes'])) {
                $updateData['notes'] = !empty($data['notes']) ? trim($data['notes']) : null;
            }

            // 8. تحديث البنود (إذا تم تقديمها)
            if (!empty($data['items']) && is_array($data['items'])) {
                // حذف البنود القديمة
                $db->execute("DELETE FROM transfer_items WHERE transfer_id = ?", [$id]);

                // إعادة حساب الإجماليات
                $totalItems = 0;
                $totalQuantity = 0.0;
                $totalCost = 0.0;

                foreach ($data['items'] as $index => $item) {
                    $validatedItem = $this->validateAndPrepareItem($db, $item, $index);

                    $db->insert('transfer_items', [
                        'transfer_id'=> $id,
                        'product_id' => $validatedItem['product_id'],
                        'quantity'   => $validatedItem['quantity'],
                        'unit_cost'  => $validatedItem['unit_cost'],
                        'total_cost' => $validatedItem['total_cost'],
                        'notes'      => $validatedItem['notes'],
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);

                    $totalItems++;
                    $totalQuantity += (float) $validatedItem['quantity'];
                    $totalCost += (float) $validatedItem['total_cost'];
                }

                $updateData['total_items'] = $totalItems;
                $updateData['total_quantity'] = $totalQuantity;
                $updateData['total_cost'] = $totalCost;
            }

            // 9. تحديث التحويل
            $db->update('transfers', $updateData, ['id' => $id]);
        });
    }

    // =========================================================================
    // 5. اعتماد تحويل (Approve) - الأهم!
    // =========================================================================

    /**
     * اعتماد تحويل مخزني وتحديث المخزون
     *
     * هذه العملية:
     * 1. تتحقق من حالة التحويل (pending فقط)
     * 2. تتحقق من توفر الرصيد في المخزن المصدر لكل بند
     * 3. تستدعي InventoryService::approveTransfer()
     * 4. InventoryService يقوم بـ:
     *    - إنشاء حركة TRANSFER_OUT في stock_movements (من المصدر)
     *    - إنشاء حركة TRANSFER_IN في stock_movements (إلى الوجهة)
     *    - تحديث stock_balances للمخزنين
     * 5. يتم تغيير حالة التحويل إلى approved
     *
     * @param int $id معرف التحويل
     * @param int $userId معرف المستخدم الذي يعتمد
     * @return array نتيجة العملية
     * @throws Exception إذا فشل الاعتماد
     */
    public function approve(int $id, int $userId): array
    {
        // 1. التحقق من وجود التحويل وحالته
        $transfer = $this->db->selectOne(
            "SELECT id, status, from_warehouse_id, to_warehouse_id FROM transfers WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$transfer) {
            throw new Exception('التحويل غير موجود.');
        }

        if ($transfer['status'] !== 'pending') {
            throw new Exception(
                'لا يمكن اعتماد تحويل في حالة "' . $transfer['status'] . '". ' .
                'يمكن الاعتماد فقط للتحويلات في حالة "pending".'
            );
        }

        // 2. التحقق من توفر الرصيد في المخزن المصدر لكل بند
        $items = $this->db->select("
            SELECT product_id, quantity
            FROM transfer_items
            WHERE transfer_id = ?
        ", [$id]);

        foreach ($items as $item) {
            $balance = $this->db->selectOne(
                "SELECT quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ?",
                [$item['product_id'], $transfer['from_warehouse_id']]
            );

            $available = $balance ? (float) $balance['quantity'] : 0.0;
            $required = (float) $item['quantity'];

            if ($available < $required) {
                $product = $this->db->selectOne(
                    "SELECT name, code FROM products WHERE id = ?",
                    [$item['product_id']]
                );

                throw new Exception(
                    "رصيد غير كافٍ في المخزن المصدر للمنتج: {$product['name']} ({$product['code']}). " .
                    "المتاح: {$available}، المطلوب: {$required}"
                );
            }
        }

        // 3. استدعاء InventoryService لتحديث المخزون
        // هذه الدالة تستخدم Transaction داخلياً وتقوم بـ:
        // - TRANSFER_OUT من from_warehouse
        // - TRANSFER_IN إلى to_warehouse
        $result = $this->inventoryService->approveTransfer($id, $userId);

        return $result;
    }

    // =========================================================================
    // 6. إلغاء تحويل (Cancel)
    // =========================================================================

    /**
     * إلغاء تحويل مخزني
     *
     * @param int $id معرف التحويل
     * @param int $userId معرف المستخدم
     * @return void
     * @throws Exception إذا فشل الإلغاء
     */
    public function cancel(int $id, int $userId): void
    {
        $transfer = $this->db->selectOne(
            "SELECT id, status FROM transfers WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$transfer) {
            throw new Exception('التحويل غير موجود.');
        }

        if ($transfer['status'] === 'approved' || $transfer['status'] === 'completed') {
            throw new Exception(
                'لا يمكن إلغاء تحويل معتمد أو مكتمل. ' .
                'تم تحديث المخزون بناءً على هذا التحويل.'
            );
        }

        $this->db->update('transfers', [
            'status'     => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    // =========================================================================
    // 7. حذف تحويل (Soft Delete)
    // =========================================================================

    /**
     * حذف تحويل مخزني (فقط إذا كان pending)
     *
     * @param int $id معرف التحويل
     * @return void
     * @throws Exception إذا فشل الحذف
     */
    public function delete(int $id): void
    {
        $this->db->transaction(function (Database $db) use ($id) {
            // 1. جلب التحويل مع قفله
            $transfer = $db->selectOne(
                "SELECT id, status, transfer_number FROM transfers WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );

            if (!$transfer) {
                throw new Exception('التحويل غير موجود.');
            }

            // 2. التحقق من الحالة
            if ($transfer['status'] !== 'pending') {
                throw new Exception(
                    'لا يمكن حذف تحويل في حالة "' . $transfer['status'] . '". ' .
                    'يمكن حذف التحويلات في حالة "pending" فقط.'
                );
            }

            // 3. حذف البنود
            $db->execute("DELETE FROM transfer_items WHERE transfer_id = ?", [$id]);

            // 4. حذف التحويل (Soft Delete)
            $db->update('transfers', [
                'deleted_at' => date('Y-m-d H:i:s'),
                'status'     => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
        });
    }

    // =========================================================================
    // 8. التحقق من الوجود والحالة
    // =========================================================================

    /**
     * التحقق من وجود تحويل معين
     */
    public function exists(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM transfers WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من أن التحويل في حالة pending
     */
    public function isPending(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT status FROM transfers WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return $result && $result['status'] === 'pending';
    }

    /**
     * جلب حالات التحويل المسموحة
     */
    public static function getAllowedStatuses(): array
    {
        return self::ALLOWED_STATUSES;
    }

    // =========================================================================
    // 9. دوال مساعدة
    // =========================================================================

    /**
     * التحقق من بند وإعداده للإدراج
     *
     * @param Database $db اتصال قاعدة البيانات
     * @param array $item بيانات البند
     * @param int $index رقم البند (للرسائل)
     * @return array البند المُعد
     * @throws Exception إذا كانت البيانات غير صالحة
     */
    private function validateAndPrepareItem(Database $db, array $item, int $index): array
    {
        // 1. التحقق من product_id
        if (empty($item['product_id'])) {
            throw new Exception("البند رقم " . ($index + 1) . ": معرف المنتج مطلوب.");
        }

        $product = $db->selectOne(
            "SELECT id, name, code FROM products WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
            [(int) $item['product_id']]
        );

        if (!$product) {
            throw new Exception("البند رقم " . ($index + 1) . ": المنتج غير موجود أو غير نشط.");
        }

        // 2. التحقق من quantity
        if (!isset($item['quantity']) || !is_numeric($item['quantity']) || (float) $item['quantity'] <= 0) {
            throw new Exception("البند رقم " . ($index + 1) . ": الكمية يجب أن تكون رقماً موجباً.");
        }

        $quantity = (float) $item['quantity'];

        // 3. التحقق من unit_cost (اختياري)
        $unitCost = null;
        if (isset($item['unit_cost']) && $item['unit_cost'] !== null && $item['unit_cost'] !== '') {
            if (!is_numeric($item['unit_cost']) || (float) $item['unit_cost'] < 0) {
                throw new Exception("البند رقم " . ($index + 1) . ": سعر الوحدة يجب أن يكون رقماً غير سالب.");
            }
            $unitCost = (float) $item['unit_cost'];
        }

        // 4. حساب total_cost
        $totalCost = $unitCost !== null ? $quantity * $unitCost : null;

        // 5. التحقق من notes (اختياري)
        $notes = null;
        if (!empty($item['notes'])) {
            $notes = trim($item['notes']);
            if (strlen($notes) > 1000) {
                throw new Exception("البند رقم " . ($index + 1) . ": الملاحظات يجب ألا تتجاوز 1000 حرف.");
            }
        }

        return [
            'product_id' => (int) $item['product_id'],
            'quantity'   => $quantity,
            'unit_cost'  => $unitCost,
            'total_cost' => $totalCost,
            'notes'      => $notes,
        ];
    }

    /**
     * توليد رقم تحويل فريد
     *
     * الصيغة: TRF-YYYYMMDD-XXXXXX
     * مثال: TRF-20260905-A1B2C3
     */
    private function generateTransferNumber(): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        return "TRF-{$date}-{$random}";
    }
}
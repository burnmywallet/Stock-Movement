<?php

/**
 * ================================================================
 * Logistox - Receipt Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/ReceiptService.php
 * الوظيفة: منطق الأعمال لإذونات الاستلام (CRUD + Approval Workflow)
 *
 * دورة حياة الإذن:
 * pending → approved → completed
 * pending → cancelled
 *
 * عند الاعتماد (approve):
 * 1. يتم التحقق من حالة الإذن (pending فقط)
 * 2. يتم استدعاء InventoryService::approveReceipt()
 * 3. يتم إنشاء حركات في stock_movements
 * 4. يتم تحديث stock_balances
 * 5. يتم تغيير حالة الإذن إلى approved
 *
 * قيود الحماية:
 * - منع تعديل/حذف إذن معتمد أو مكتمل
 * - التحقق من وجود المخزن والمورد
 * - التحقق من وجود المنتجات
 * - التحقق من الكميات الموجبة
 * - منع الاعتماد المزدوج
 * ================================================================
 */

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Throwable;
use Exception;

/**
 * Class ReceiptService
 *
 * خدمة إدارة إذونات الاستلام
 */
class ReceiptService
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
     * @var array حالات الإذن المسموحة
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
    // 1. إنشاء إذن استلام جديد (Create)
    // =========================================================================

    /**
     * إنشاء إذن استلام جديد مع بنوده
     *
     * @param array $data بيانات الإذن
     * @param int $userId معرف المستخدم
     * @return int معرف الإذن الجديد
     * @throws Exception إذا فشل الإنشاء
     */
    public function create(array $data, int $userId): int
    {
        return $this->db->transaction(function (Database $db) use ($data, $userId) {
            // 1. التحقق من وجود المخزن
            $warehouse = $db->selectOne(
                "SELECT id, name FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['warehouse_id']]
            );

            if (!$warehouse) {
                throw new Exception('المخزن المحدد غير موجود أو غير نشط.');
            }

            // 2. التحقق من وجود المورد (إذا تم تقديمه)
            if (!empty($data['supplier_id'])) {
                $supplier = $db->selectOne(
                    "SELECT id, name FROM suppliers WHERE id = ? AND deleted_at IS NULL",
                    [(int) $data['supplier_id']]
                );

                if (!$supplier) {
                    throw new Exception('المورد المحدد غير موجود.');
                }
            }

            // 3. التحقق من البنود
            if (empty($data['items']) || !is_array($data['items'])) {
                throw new Exception('يجب إضافة بند واحد على الأقل للإذن.');
            }

            // 4. التحقق من كل بند وحساب الإجماليات
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

            // 5. توليد رقم الإذن
            $receiptNumber = $this->generateReceiptNumber();

            // 6. إدراج الإذن
            $db->insert('receipts', [
                'receipt_number'  => $receiptNumber,
                'warehouse_id'    => (int) $data['warehouse_id'],
                'supplier_id'     => !empty($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                'supplier_name'   => $warehouse['name'] ?? null, // سيتم تحديثه لاحقاً إذا لزم
                'supplier_invoice'=> !empty($data['supplier_invoice']) ? trim($data['supplier_invoice']) : null,
                'total_items'     => $totalItems,
                'total_quantity'  => $totalQuantity,
                'total_cost'      => $totalCost,
                'notes'           => !empty($data['notes']) ? trim($data['notes']) : null,
                'status'          => 'pending',
                'user_id'         => $userId,
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            $receiptId = (int) $db->lastInsertId();

            // 7. إدراج البنود
            foreach ($validatedItems as $item) {
                $db->insert('receipt_items', [
                    'receipt_id'   => $receiptId,
                    'product_id'   => $item['product_id'],
                    'quantity'     => $item['quantity'],
                    'unit_cost'    => $item['unit_cost'],
                    'total_cost'   => $item['total_cost'],
                    'batch_number' => $item['batch_number'],
                    'expiry_date'  => $item['expiry_date'],
                    'notes'        => $item['notes'],
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
            }

            return $receiptId;
        });
    }

    // =========================================================================
    // 2. جلب إذن واحد (Read)
    // =========================================================================

    /**
     * جلب إذن استلام واحد مع بنوده
     *
     * @param int $id معرف الإذن
     * @return array|null بيانات الإذن أو null
     */
    public function getById(int $id): ?array
    {
        // 1. جلب بيانات الإذن
        $receipt = $this->db->selectOne("
            SELECT
                r.id,
                r.receipt_number,
                r.warehouse_id,
                r.supplier_id,
                r.supplier_invoice,
                r.total_items,
                r.total_quantity,
                r.total_cost,
                r.notes,
                r.status,
                r.approved_by,
                r.approved_at,
                r.user_id,
                r.created_at,
                r.updated_at,
                w.name AS warehouse_name,
                w.code AS warehouse_code,
                s.name AS supplier_name,
                s.code AS supplier_code,
                creator.full_name AS created_by_name,
                approver.full_name AS approved_by_name
            FROM receipts r
            INNER JOIN warehouses w ON r.warehouse_id = w.id
            LEFT JOIN suppliers s ON r.supplier_id = s.id
            LEFT JOIN users creator ON r.user_id = creator.id
            LEFT JOIN users approver ON r.approved_by = approver.id
            WHERE r.id = ?
              AND r.deleted_at IS NULL
        ", [$id]);

        if (!$receipt) {
            return null;
        }

        // 2. جلب البنود
        $items = $this->db->select("
            SELECT
                ri.id,
                ri.product_id,
                ri.quantity,
                ri.unit_cost,
                ri.total_cost,
                ri.batch_number,
                ri.expiry_date,
                ri.notes,
                ri.created_at,
                p.code AS product_code,
                p.name AS product_name,
                p.barcode AS product_barcode,
                u.symbol AS unit_symbol,
                c.name AS category_name
            FROM receipt_items ri
            INNER JOIN products p ON ri.product_id = p.id
            LEFT JOIN units u ON p.unit_id = u.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE ri.receipt_id = ?
            ORDER BY ri.id ASC
        ", [$id]);

        // 3. تحويل القيم الرقمية
        $receipt['total_items'] = (int) $receipt['total_items'];
        $receipt['total_quantity'] = (float) $receipt['total_quantity'];
        $receipt['total_cost'] = (float) $receipt['total_cost'];

        foreach ($items as &$item) {
            $item['quantity'] = (float) $item['quantity'];
            $item['unit_cost'] = $item['unit_cost'] !== null ? (float) $item['unit_cost'] : null;
            $item['total_cost'] = $item['total_cost'] !== null ? (float) $item['total_cost'] : null;
        }

        $receipt['items'] = $items;

        return $receipt;
    }

    // =========================================================================
    // 3. جلب قائمة الإذونات (List)
    // =========================================================================

    /**
     * جلب قائمة إذونات الاستلام مع الفلاتر
     *
     * @param array $filters الفلاتر
     * @return array قائمة الإذونات
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                r.id,
                r.receipt_number,
                r.warehouse_id,
                r.supplier_id,
                r.supplier_invoice,
                r.total_items,
                r.total_quantity,
                r.total_cost,
                r.notes,
                r.status,
                r.approved_at,
                r.created_at,
                r.updated_at,
                w.name AS warehouse_name,
                s.name AS supplier_name,
                creator.full_name AS created_by_name,
                approver.full_name AS approved_by_name
            FROM receipts r
            INNER JOIN warehouses w ON r.warehouse_id = w.id
            LEFT JOIN suppliers s ON r.supplier_id = s.id
            LEFT JOIN users creator ON r.user_id = creator.id
            LEFT JOIN users approver ON r.approved_by = approver.id
            WHERE r.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (r.receipt_number LIKE ? OR r.supplier_invoice LIKE ? OR r.notes LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::ALLOWED_STATUSES, true)) {
            $sql .= " AND r.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND r.warehouse_id = ?";
            $params[] = (int) $filters['warehouse_id'];
        }

        if (!empty($filters['supplier_id'])) {
            $sql .= " AND r.supplier_id = ?";
            $params[] = (int) $filters['supplier_id'];
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

        $allowedSortBy = ['receipt_number', 'created_at', 'status', 'total_cost', 'total_quantity'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'created_at';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $sql .= " ORDER BY r.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $receipts = $this->db->select($sql, $params);

        // تحويل القيم الرقمية
        foreach ($receipts as &$receipt) {
            $receipt['total_items'] = (int) $receipt['total_items'];
            $receipt['total_quantity'] = (float) $receipt['total_quantity'];
            $receipt['total_cost'] = (float) $receipt['total_cost'];
        }

        return $receipts;
    }

    // =========================================================================
    // 4. تعديل إذن (Update)
    // =========================================================================

    /**
     * تعديل إذن استلام (فقط إذا كان pending)
     *
     * @param int $id معرف الإذن
     * @param array $data البيانات الجديدة
     * @param int $userId معرف المستخدم
     * @return void
     * @throws Exception إذا فشل التحديث
     */
    public function update(int $id, array $data, int $userId): void
    {
        $this->db->transaction(function (Database $db) use ($id, $data, $userId) {
            // 1. جلب الإذن مع قفله
            $receipt = $db->selectOne(
                "SELECT id, status FROM receipts WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );

            if (!$receipt) {
                throw new Exception('الإذن غير موجود.');
            }

            // 2. التحقق من الحالة
            if ($receipt['status'] !== 'pending') {
                throw new Exception(
                    'لا يمكن تعديل إذن في حالة "' . $receipt['status'] . '". ' .
                    'يمكن تعديل الإذونات في حالة "pending" فقط.'
                );
            }

            // 3. التحقق من المخزن (إذا تم تغييره)
            if (!empty($data['warehouse_id'])) {
                $warehouse = $db->selectOne(
                    "SELECT id FROM warehouses WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                    [(int) $data['warehouse_id']]
                );

                if (!$warehouse) {
                    throw new Exception('المخزن المحدد غير موجود أو غير نشط.');
                }
            }

            // 4. التحقق من المورد (إذا تم تغييره)
            if (isset($data['supplier_id']) && $data['supplier_id'] !== null) {
                $supplier = $db->selectOne(
                    "SELECT id FROM suppliers WHERE id = ? AND deleted_at IS NULL",
                    [(int) $data['supplier_id']]
                );

                if (!$supplier) {
                    throw new Exception('المورد المحدد غير موجود.');
                }
            }

            // 5. بناء بيانات التحديث
            $updateData = ['updated_at' => date('Y-m-d H:i:s')];

            if (!empty($data['warehouse_id'])) {
                $updateData['warehouse_id'] = (int) $data['warehouse_id'];
            }

            if (isset($data['supplier_id'])) {
                $updateData['supplier_id'] = $data['supplier_id'] !== null ? (int) $data['supplier_id'] : null;
            }

            if (isset($data['supplier_invoice'])) {
                $updateData['supplier_invoice'] = !empty($data['supplier_invoice']) ? trim($data['supplier_invoice']) : null;
            }

            if (isset($data['notes'])) {
                $updateData['notes'] = !empty($data['notes']) ? trim($data['notes']) : null;
            }

            // 6. تحديث البنود (إذا تم تقديمها)
            if (!empty($data['items']) && is_array($data['items'])) {
                // حذف البنود القديمة
                $db->execute("DELETE FROM receipt_items WHERE receipt_id = ?", [$id]);

                // إعادة حساب الإجماليات
                $totalItems = 0;
                $totalQuantity = 0.0;
                $totalCost = 0.0;

                foreach ($data['items'] as $index => $item) {
                    $validatedItem = $this->validateAndPrepareItem($db, $item, $index);

                    $db->insert('receipt_items', [
                        'receipt_id'   => $id,
                        'product_id'   => $validatedItem['product_id'],
                        'quantity'     => $validatedItem['quantity'],
                        'unit_cost'    => $validatedItem['unit_cost'],
                        'total_cost'   => $validatedItem['total_cost'],
                        'batch_number' => $validatedItem['batch_number'],
                        'expiry_date'  => $validatedItem['expiry_date'],
                        'notes'        => $validatedItem['notes'],
                        'created_at'   => date('Y-m-d H:i:s'),
                    ]);

                    $totalItems++;
                    $totalQuantity += (float) $validatedItem['quantity'];
                    $totalCost += (float) $validatedItem['total_cost'];
                }

                $updateData['total_items'] = $totalItems;
                $updateData['total_quantity'] = $totalQuantity;
                $updateData['total_cost'] = $totalCost;
            }

            // 7. تحديث الإذن
            $db->update('receipts', $updateData, ['id' => $id]);
        });
    }

    // =========================================================================
    // 5. اعتماد إذن (Approve) - الأهم!
    // =========================================================================

    /**
     * اعتماد إذن استلام وتحديث المخزون
     *
     * هذه العملية:
     * 1. تتحقق من حالة الإذن (pending فقط)
     * 2. تستدعي InventoryService::approveReceipt()
     * 3. InventoryService يقوم بـ:
     *    - إنشاء حركات في stock_movements
     *    - تحديث stock_balances
     * 4. يتم تغيير حالة الإذن إلى approved
     *
     * @param int $id معرف الإذن
     * @param int $userId معرف المستخدم الذي يعتمد
     * @return array نتيجة العملية
     * @throws Exception إذا فشل الاعتماد
     */
    public function approve(int $id, int $userId): array
    {
        // 1. التحقق من وجود الإذن وحالته
        $receipt = $this->db->selectOne(
            "SELECT id, status, warehouse_id FROM receipts WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$receipt) {
            throw new Exception('الإذن غير موجود.');
        }

        if ($receipt['status'] !== 'pending') {
            throw new Exception(
                'لا يمكن اعتماد إذن في حالة "' . $receipt['status'] . '". ' .
                'يمكن الاعتماد فقط للإذونات في حالة "pending".'
            );
        }

        // 2. استدعاء InventoryService لتحديث المخزون
        // هذه الدالة تستخدم Transaction داخلياً
        $result = $this->inventoryService->approveReceipt($id, $userId);

        return $result;
    }

    // =========================================================================
    // 6. إلغاء إذن (Cancel)
    // =========================================================================

    /**
     * إلغاء إذن استلام
     *
     * @param int $id معرف الإذن
     * @param int $userId معرف المستخدم
     * @return void
     * @throws Exception إذا فشل الإلغاء
     */
    public function cancel(int $id, int $userId): void
    {
        $receipt = $this->db->selectOne(
            "SELECT id, status FROM receipts WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$receipt) {
            throw new Exception('الإذن غير موجود.');
        }

        if ($receipt['status'] === 'approved' || $receipt['status'] === 'completed') {
            throw new Exception(
                'لا يمكن إلغاء إذن معتمد أو مكتمل. ' .
                'تم تحديث المخزون بناءً على هذا الإذن.'
            );
        }

        $this->db->update('receipts', [
            'status'     => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    // =========================================================================
    // 7. حذف إذن (Soft Delete)
    // =========================================================================

    /**
     * حذف إذن استلام (فقط إذا كان pending)
     *
     * @param int $id معرف الإذن
     * @return void
     * @throws Exception إذا فشل الحذف
     */
    public function delete(int $id): void
    {
        $this->db->transaction(function (Database $db) use ($id) {
            // 1. جلب الإذن مع قفله
            $receipt = $db->selectOne(
                "SELECT id, status, receipt_number FROM receipts WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );

            if (!$receipt) {
                throw new Exception('الإذن غير موجود.');
            }

            // 2. التحقق من الحالة
            if ($receipt['status'] !== 'pending') {
                throw new Exception(
                    'لا يمكن حذف إذن في حالة "' . $receipt['status'] . '". ' .
                    'يمكن حذف الإذونات في حالة "pending" فقط.'
                );
            }

            // 3. حذف البنود
            $db->execute("DELETE FROM receipt_items WHERE receipt_id = ?", [$id]);

            // 4. حذف الإذن (Soft Delete)
            $db->update('receipts', [
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
     * التحقق من وجود إذن معين
     */
    public function exists(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM receipts WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من أن الإذن في حالة pending
     */
    public function isPending(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT status FROM receipts WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return $result && $result['status'] === 'pending';
    }

    /**
     * جلب حالات الإذن المسموحة
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

        // 5. التحقق من batch_number (اختياري)
        $batchNumber = null;
        if (!empty($item['batch_number'])) {
            $batchNumber = trim($item['batch_number']);
            if (strlen($batchNumber) > 100) {
                throw new Exception("البند رقم " . ($index + 1) . ": رقم اللوتة يجب ألا يتجاوز 100 حرف.");
            }
        }

        // 6. التحقق من expiry_date (اختياري)
        $expiryDate = null;
        if (!empty($item['expiry_date'])) {
            $expiryDate = trim($item['expiry_date']);
            // التحقق من صحة التاريخ
            $dateTime = \DateTime::createFromFormat('Y-m-d', $expiryDate);
            if (!$dateTime || $dateTime->format('Y-m-d') !== $expiryDate) {
                throw new Exception("البند رقم " . ($index + 1) . ": تاريخ الصلاحية غير صالح. الصيغة: YYYY-MM-DD.");
            }
        }

        // 7. التحقق من notes (اختياري)
        $notes = null;
        if (!empty($item['notes'])) {
            $notes = trim($item['notes']);
            if (strlen($notes) > 1000) {
                throw new Exception("البند رقم " . ($index + 1) . ": الملاحظات يجب ألا تتجاوز 1000 حرف.");
            }
        }

        return [
            'product_id'   => (int) $item['product_id'],
            'quantity'     => $quantity,
            'unit_cost'    => $unitCost,
            'total_cost'   => $totalCost,
            'batch_number' => $batchNumber,
            'expiry_date'  => $expiryDate,
            'notes'        => $notes,
        ];
    }

    /**
     * توليد رقم إذن فريد
     *
     * الصيغة: RCP-YYYYMMDD-XXXXXX
     * مثال: RCP-20260905-A1B2C3
     */
    private function generateReceiptNumber(): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        return "RCP-{$date}-{$random}";
    }
}
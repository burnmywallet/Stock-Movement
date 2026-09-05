<?php

/**
 * ================================================================
 * Logistox - Issue Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/IssueService.php
 * الوظيفة: منطق الأعمال لإذونات الصرف (CRUD + Approval Workflow)
 *
 * دورة حياة الإذن:
 * pending → approved → completed
 * pending → cancelled
 *
 * عند الاعتماد (approve):
 * 1. يتم التحقق من حالة الإذن (pending فقط)
 * 2. يتم التحقق من توفر الرصيد لكل بند
 * 3. يتم استدعاء InventoryService::approveIssue()
 * 4. يتم إنشاء حركات في stock_movements
 * 5. يتم تحديث stock_balances
 * 6. يتم تغيير حالة الإذن إلى approved
 *
 * قيود الحماية:
 * - منع تعديل/حذف إذن معتمد أو مكتمل
 * - التحقق من توفر الرصيد قبل الصرف
 * - التحقق من وجود المخزن والجهة المستلمة
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
 * Class IssueService
 *
 * خدمة إدارة إذونات الصرف
 */
class IssueService
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
    // 1. إنشاء إذن صرف جديد (Create)
    // =========================================================================

    /**
     * إنشاء إذن صرف جديد مع بنوده
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

            // 2. التحقق من وجود الجهة المستلمة (إذا تم تقديمها)
            if (!empty($data['recipient_id'])) {
                $recipient = $db->selectOne(
                    "SELECT id, name FROM recipients WHERE id = ? AND deleted_at IS NULL",
                    [(int) $data['recipient_id']]
                );

                if (!$recipient) {
                    throw new Exception('الجهة المستلمة المحددة غير موجودة.');
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
            $issueNumber = $this->generateIssueNumber();

            // 6. إدراج الإذن
            $db->insert('issues', [
                'issue_number'    => $issueNumber,
                'warehouse_id'    => (int) $data['warehouse_id'],
                'recipient_id'    => !empty($data['recipient_id']) ? (int) $data['recipient_id'] : null,
                'department_name' => !empty($data['department_name']) ? trim($data['department_name']) : null,
                'request_number'  => !empty($data['request_number']) ? trim($data['request_number']) : null,
                'total_items'     => $totalItems,
                'total_quantity'  => $totalQuantity,
                'total_cost'      => $totalCost,
                'notes'           => !empty($data['notes']) ? trim($data['notes']) : null,
                'status'          => 'pending',
                'user_id'         => $userId,
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            $issueId = (int) $db->lastInsertId();

            // 7. إدراج البنود
            foreach ($validatedItems as $item) {
                $db->insert('issue_items', [
                    'issue_id'   => $issueId,
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'unit_cost'  => $item['unit_cost'],
                    'total_cost' => $item['total_cost'],
                    'notes'      => $item['notes'],
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $issueId;
        });
    }

    // =========================================================================
    // 2. جلب إذن واحد (Read)
    // =========================================================================

    /**
     * جلب إذن صرف واحد مع بنوده
     *
     * @param int $id معرف الإذن
     * @return array|null بيانات الإذن أو null
     */
    public function getById(int $id): ?array
    {
        // 1. جلب بيانات الإذن
        $issue = $this->db->selectOne("
            SELECT
                i.id,
                i.issue_number,
                i.warehouse_id,
                i.recipient_id,
                i.department_name,
                i.request_number,
                i.total_items,
                i.total_quantity,
                i.total_cost,
                i.notes,
                i.status,
                i.approved_by,
                i.approved_at,
                i.user_id,
                i.created_at,
                i.updated_at,
                w.name AS warehouse_name,
                w.code AS warehouse_code,
                r.name AS recipient_name,
                r.code AS recipient_code,
                creator.full_name AS created_by_name,
                approver.full_name AS approved_by_name
            FROM issues i
            INNER JOIN warehouses w ON i.warehouse_id = w.id
            LEFT JOIN recipients r ON i.recipient_id = r.id
            LEFT JOIN users creator ON i.user_id = creator.id
            LEFT JOIN users approver ON i.approved_by = approver.id
            WHERE i.id = ?
              AND i.deleted_at IS NULL
        ", [$id]);

        if (!$issue) {
            return null;
        }

        // 2. جلب البنود
        $items = $this->db->select("
            SELECT
                ii.id,
                ii.product_id,
                ii.quantity,
                ii.unit_cost,
                ii.total_cost,
                ii.notes,
                ii.created_at,
                p.code AS product_code,
                p.name AS product_name,
                p.barcode AS product_barcode,
                u.symbol AS unit_symbol,
                c.name AS category_name,
                sb.quantity AS available_quantity
            FROM issue_items ii
            INNER JOIN products p ON ii.product_id = p.id
            LEFT JOIN units u ON p.unit_id = u.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = i.warehouse_id
            WHERE ii.issue_id = ?
            ORDER BY ii.id ASC
        ", [$id]);

        // 3. تحويل القيم الرقمية
        $issue['total_items'] = (int) $issue['total_items'];
        $issue['total_quantity'] = (float) $issue['total_quantity'];
        $issue['total_cost'] = (float) $issue['total_cost'];

        foreach ($items as &$item) {
            $item['quantity'] = (float) $item['quantity'];
            $item['unit_cost'] = $item['unit_cost'] !== null ? (float) $item['unit_cost'] : null;
            $item['total_cost'] = $item['total_cost'] !== null ? (float) $item['total_cost'] : null;
            $item['available_quantity'] = $item['available_quantity'] !== null ? (float) $item['available_quantity'] : 0.0;
        }

        $issue['items'] = $items;

        return $issue;
    }

    // =========================================================================
    // 3. جلب قائمة الإذونات (List)
    // =========================================================================

    /**
     * جلب قائمة إذونات الصرف مع الفلاتر
     *
     * @param array $filters الفلاتر
     * @return array قائمة الإذونات
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                i.id,
                i.issue_number,
                i.warehouse_id,
                i.recipient_id,
                i.department_name,
                i.request_number,
                i.total_items,
                i.total_quantity,
                i.total_cost,
                i.notes,
                i.status,
                i.approved_at,
                i.created_at,
                i.updated_at,
                w.name AS warehouse_name,
                r.name AS recipient_name,
                creator.full_name AS created_by_name,
                approver.full_name AS approved_by_name
            FROM issues i
            INNER JOIN warehouses w ON i.warehouse_id = w.id
            LEFT JOIN recipients r ON i.recipient_id = r.id
            LEFT JOIN users creator ON i.user_id = creator.id
            LEFT JOIN users approver ON i.approved_by = approver.id
            WHERE i.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (i.issue_number LIKE ? OR i.request_number LIKE ? OR i.department_name LIKE ? OR i.notes LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::ALLOWED_STATUSES, true)) {
            $sql .= " AND i.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['warehouse_id'])) {
            $sql .= " AND i.warehouse_id = ?";
            $params[] = (int) $filters['warehouse_id'];
        }

        if (!empty($filters['recipient_id'])) {
            $sql .= " AND i.recipient_id = ?";
            $params[] = (int) $filters['recipient_id'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND i.created_at >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND i.created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc');

        $allowedSortBy = ['issue_number', 'created_at', 'status', 'total_cost', 'total_quantity'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'created_at';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $sql .= " ORDER BY i.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $issues = $this->db->select($sql, $params);

        // تحويل القيم الرقمية
        foreach ($issues as &$issue) {
            $issue['total_items'] = (int) $issue['total_items'];
            $issue['total_quantity'] = (float) $issue['total_quantity'];
            $issue['total_cost'] = (float) $issue['total_cost'];
        }

        return $issues;
    }

    // =========================================================================
    // 4. تعديل إذن (Update)
    // =========================================================================

    /**
     * تعديل إذن صرف (فقط إذا كان pending)
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
            $issue = $db->selectOne(
                "SELECT id, status FROM issues WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );

            if (!$issue) {
                throw new Exception('الإذن غير موجود.');
            }

            // 2. التحقق من الحالة
            if ($issue['status'] !== 'pending') {
                throw new Exception(
                    'لا يمكن تعديل إذن في حالة "' . $issue['status'] . '". ' .
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

            // 4. التحقق من الجهة المستلمة (إذا تم تغييرها)
            if (isset($data['recipient_id']) && $data['recipient_id'] !== null) {
                $recipient = $db->selectOne(
                    "SELECT id FROM recipients WHERE id = ? AND deleted_at IS NULL",
                    [(int) $data['recipient_id']]
                );

                if (!$recipient) {
                    throw new Exception('الجهة المستلمة المحددة غير موجودة.');
                }
            }

            // 5. بناء بيانات التحديث
            $updateData = ['updated_at' => date('Y-m-d H:i:s')];

            if (!empty($data['warehouse_id'])) {
                $updateData['warehouse_id'] = (int) $data['warehouse_id'];
            }

            if (isset($data['recipient_id'])) {
                $updateData['recipient_id'] = $data['recipient_id'] !== null ? (int) $data['recipient_id'] : null;
            }

            if (isset($data['department_name'])) {
                $updateData['department_name'] = !empty($data['department_name']) ? trim($data['department_name']) : null;
            }

            if (isset($data['request_number'])) {
                $updateData['request_number'] = !empty($data['request_number']) ? trim($data['request_number']) : null;
            }

            if (isset($data['notes'])) {
                $updateData['notes'] = !empty($data['notes']) ? trim($data['notes']) : null;
            }

            // 6. تحديث البنود (إذا تم تقديمها)
            if (!empty($data['items']) && is_array($data['items'])) {
                // حذف البنود القديمة
                $db->execute("DELETE FROM issue_items WHERE issue_id = ?", [$id]);

                // إعادة حساب الإجماليات
                $totalItems = 0;
                $totalQuantity = 0.0;
                $totalCost = 0.0;

                foreach ($data['items'] as $index => $item) {
                    $validatedItem = $this->validateAndPrepareItem($db, $item, $index);

                    $db->insert('issue_items', [
                        'issue_id'   => $id,
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

            // 7. تحديث الإذن
            $db->update('issues', $updateData, ['id' => $id]);
        });
    }

    // =========================================================================
    // 5. اعتماد إذن (Approve) - الأهم!
    // =========================================================================

    /**
     * اعتماد إذن صرف وتحديث المخزون
     *
     * هذه العملية:
     * 1. تتحقق من حالة الإذن (pending فقط)
     * 2. تتحقق من توفر الرصيد لكل بند
     * 3. تستدعي InventoryService::approveIssue()
     * 4. InventoryService يقوم بـ:
     *    - إنشاء حركات في stock_movements
     *    - تحديث stock_balances
     * 5. يتم تغيير حالة الإذن إلى approved
     *
     * @param int $id معرف الإذن
     * @param int $userId معرف المستخدم الذي يعتمد
     * @return array نتيجة العملية
     * @throws Exception إذا فشل الاعتماد
     */
    public function approve(int $id, int $userId): array
    {
        // 1. التحقق من وجود الإذن وحالته
        $issue = $this->db->selectOne(
            "SELECT id, status, warehouse_id FROM issues WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$issue) {
            throw new Exception('الإذن غير موجود.');
        }

        if ($issue['status'] !== 'pending') {
            throw new Exception(
                'لا يمكن اعتماد إذن في حالة "' . $issue['status'] . '". ' .
                'يمكن الاعتماد فقط للإذونات في حالة "pending".'
            );
        }

        // 2. التحقق من توفر الرصيد لكل بند
        $items = $this->db->select("
            SELECT product_id, quantity
            FROM issue_items
            WHERE issue_id = ?
        ", [$id]);

        foreach ($items as $item) {
            $balance = $this->db->selectOne(
                "SELECT quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ?",
                [$item['product_id'], $issue['warehouse_id']]
            );

            $available = $balance ? (float) $balance['quantity'] : 0.0;
            $required = (float) $item['quantity'];

            if ($available < $required) {
                $product = $this->db->selectOne(
                    "SELECT name, code FROM products WHERE id = ?",
                    [$item['product_id']]
                );

                throw new Exception(
                    "رصيد غير كافٍ للمنتج: {$product['name']} ({$product['code']}). " .
                    "المتاح: {$available}، المطلوب: {$required}"
                );
            }
        }

        // 3. استدعاء InventoryService لتحديث المخزون
        // هذه الدالة تستخدم Transaction داخلياً
        $result = $this->inventoryService->approveIssue($id, $userId);

        return $result;
    }

    // =========================================================================
    // 6. إلغاء إذن (Cancel)
    // =========================================================================

    /**
     * إلغاء إذن صرف
     *
     * @param int $id معرف الإذن
     * @param int $userId معرف المستخدم
     * @return void
     * @throws Exception إذا فشل الإلغاء
     */
    public function cancel(int $id, int $userId): void
    {
        $issue = $this->db->selectOne(
            "SELECT id, status FROM issues WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$issue) {
            throw new Exception('الإذن غير موجود.');
        }

        if ($issue['status'] === 'approved' || $issue['status'] === 'completed') {
            throw new Exception(
                'لا يمكن إلغاء إذن معتمد أو مكتمل. ' .
                'تم تحديث المخزون بناءً على هذا الإذن.'
            );
        }

        $this->db->update('issues', [
            'status'     => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    // =========================================================================
    // 7. حذف إذن (Soft Delete)
    // =========================================================================

    /**
     * حذف إذن صرف (فقط إذا كان pending)
     *
     * @param int $id معرف الإذن
     * @return void
     * @throws Exception إذا فشل الحذف
     */
    public function delete(int $id): void
    {
        $this->db->transaction(function (Database $db) use ($id) {
            // 1. جلب الإذن مع قفله
            $issue = $db->selectOne(
                "SELECT id, status, issue_number FROM issues WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                [$id]
            );

            if (!$issue) {
                throw new Exception('الإذن غير موجود.');
            }

            // 2. التحقق من الحالة
            if ($issue['status'] !== 'pending') {
                throw new Exception(
                    'لا يمكن حذف إذن في حالة "' . $issue['status'] . '". ' .
                    'يمكن حذف الإذونات في حالة "pending" فقط.'
                );
            }

            // 3. حذف البنود
            $db->execute("DELETE FROM issue_items WHERE issue_id = ?", [$id]);

            // 4. حذف الإذن (Soft Delete)
            $db->update('issues', [
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
            "SELECT COUNT(*) AS count FROM issues WHERE id = ? AND deleted_at IS NULL",
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
            "SELECT status FROM issues WHERE id = ? AND deleted_at IS NULL",
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
     * توليد رقم إذن فريد
     *
     * الصيغة: ISS-YYYYMMDD-XXXXXX
     * مثال: ISS-20260905-A1B2C3
     */
    private function generateIssueNumber(): string
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        return "ISS-{$date}-{$random}";
    }
}
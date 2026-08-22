<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/services/StockService.php
// الوصف: محرك المخزون الأساسي - معاملات آمنة ومتكاملة
// التاريخ: 2026-08-22
// ================================================================

namespace Services;

use Core\Database;
use Core\Audit;
use Exception;

class StockService
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var Audit $audit - سجل التدقيق
     */
    private $audit;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->audit = new Audit();
    }

    /**
     * ============================================================
     * 1. عمليات الاستلام (Receipt)
     * ============================================================
     */

    /**
     * تنفيذ إذن استلام - إضافة كميات للمخزن
     */
    public function processReceipt(int $receiptId, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // جلب بيانات الإذن
            $receipt = $this->db->queryOne("
                SELECT * FROM receipts WHERE id = :id AND status = 'submitted'
            ", ['id' => $receiptId]);

            if (!$receipt) {
                throw new Exception('إذن الاستلام غير موجود أو ليس في حالة مرسل');
            }

            // جلب تفاصيل الإذن
            $items = $this->db->query("
                SELECT * FROM receipt_items WHERE receipt_id = :receipt_id
            ", ['receipt_id' => $receiptId]);

            if (empty($items)) {
                throw new Exception('لا توجد أصناف في إذن الاستلام');
            }

            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;

            foreach ($items as $item) {
                // جلب الرصيد الحالي
                $currentBalance = $this->getCurrentBalance($item['product_id'], $receipt['warehouse_id']);
                $newBalance = $currentBalance + $item['quantity'];

                // تحديث الرصيد
                $this->updateStockBalance($item['product_id'], $receipt['warehouse_id'], $newBalance);

                // تسجيل حركة المخزون
                $this->insertStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $receipt['warehouse_id'],
                    'movement_type' => 'RECEIPT',
                    'reference_type' => 'receipt',
                    'reference_id' => $receiptId,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $item['quantity'] * $item['unit_cost'],
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance,
                    'user_id' => $userId,
                    'notes' => "استلام عبر الإذن #{$receipt['receipt_no']}"
                ]);

                // تحديث كمية المستلمة
                $this->db->update('receipt_items', [
                    'received_quantity' => $item['quantity']
                ], ['id' => $item['id']]);

                $totalItems++;
                $totalQuantity += $item['quantity'];
                $totalCost += $item['quantity'] * $item['unit_cost'];

                // إذا كان الصنف يتتبع الدفعات
                if ($item['batch_number']) {
                    $this->addBatch([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $receipt['warehouse_id'],
                        'batch_number' => $item['batch_number'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_cost'],
                        'expiry_date' => $item['expiry_date'] ?? null,
                        'received_at' => date('Y-m-d')
                    ]);
                }

                // إذا كان الصنف متسلسل
                if ($item['serial_numbers']) {
                    $serials = explode(',', $item['serial_numbers']);
                    foreach ($serials as $serial) {
                        $this->addSerial([
                            'product_id' => $item['product_id'],
                            'warehouse_id' => $receipt['warehouse_id'],
                            'serial_number' => trim($serial),
                            'cost_price' => $item['unit_cost'],
                            'purchase_date' => date('Y-m-d')
                        ]);
                    }
                }
            }

            // تحديث حالة الإذن
            $this->db->update('receipts', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $receiptId]);

            // تحديث إجماليات الإذن
            $this->db->update('receipts', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $receiptId]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RECEIPT_PROCESSED',
                'stock',
                "تنفيذ إذن استلام #{$receipt['receipt_no']}",
                [
                    'receipt_id' => $receiptId,
                    'receipt_no' => $receipt['receipt_no'],
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'receipt',
                $receiptId
            );

            $this->db->commit();

            // التحقق من التنبيهات
            $this->checkStockAlerts($receipt['warehouse_id']);

            return [
                'success' => true,
                'message' => 'تم تنفيذ إذن الاستلام بنجاح',
                'data' => [
                    'receipt_id' => $receiptId,
                    'total_items' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ]
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * ============================================================
     * 2. عمليات الصرف (Issue)
     * ============================================================
     */

    /**
     * تنفيذ إذن صرف - خصم كميات من المخزن
     */
    public function processIssue(int $issueId, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // جلب بيانات الإذن
            $issue = $this->db->queryOne("
                SELECT * FROM issues WHERE id = :id AND status = 'submitted'
            ", ['id' => $issueId]);

            if (!$issue) {
                throw new Exception('إذن الصرف غير موجود أو ليس في حالة مرسل');
            }

            // جلب تفاصيل الإذن
            $items = $this->db->query("
                SELECT * FROM issue_items WHERE issue_id = :issue_id
            ", ['issue_id' => $issueId]);

            if (empty($items)) {
                throw new Exception('لا توجد أصناف في إذن الصرف');
            }

            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;

            foreach ($items as $item) {
                // التحقق من توفر الكمية
                $currentBalance = $this->getCurrentBalance($item['product_id'], $issue['warehouse_id']);
                
                if ($currentBalance < $item['quantity']) {
                    throw new Exception("الكمية غير متوفرة للصنف (المتاح: {$currentBalance})");
                }

                $newBalance = $currentBalance - $item['quantity'];

                // تحديث الرصيد
                $this->updateStockBalance($item['product_id'], $issue['warehouse_id'], $newBalance);

                // تسجيل حركة المخزون
                $this->insertStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $issue['warehouse_id'],
                    'movement_type' => 'ISSUE',
                    'reference_type' => 'issue',
                    'reference_id' => $issueId,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $item['quantity'] * $item['unit_cost'],
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance,
                    'user_id' => $userId,
                    'notes' => "صرف عبر الإذن #{$issue['issue_no']}"
                ]);

                // تحديث كمية المسلمة
                $this->db->update('issue_items', [
                    'delivered_quantity' => $item['quantity']
                ], ['id' => $item['id']]);

                $totalItems++;
                $totalQuantity += $item['quantity'];
                $totalCost += $item['quantity'] * $item['unit_cost'];
            }

            // تحديث حالة الإذن
            $this->db->update('issues', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $issueId]);

            // تحديث إجماليات الإذن
            $this->db->update('issues', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $issueId]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'ISSUE_PROCESSED',
                'stock',
                "تنفيذ إذن صرف #{$issue['issue_no']}",
                [
                    'issue_id' => $issueId,
                    'issue_no' => $issue['issue_no'],
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'issue',
                $issueId
            );

            $this->db->commit();

            // التحقق من التنبيهات
            $this->checkStockAlerts($issue['warehouse_id']);

            return [
                'success' => true,
                'message' => 'تم تنفيذ إذن الصرف بنجاح',
                'data' => [
                    'issue_id' => $issueId,
                    'total_items' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ]
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * ============================================================
     * 3. عمليات التحويل (Transfer)
     * ============================================================
     */

    /**
     * تنفيذ تحويل بين المخازن
     */
    public function processTransfer(int $transferId, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // جلب بيانات التحويل
            $transfer = $this->db->queryOne("
                SELECT * FROM transfers WHERE id = :id AND status = 'submitted'
            ", ['id' => $transferId]);

            if (!$transfer) {
                throw new Exception('التحويل غير موجود أو ليس في حالة مرسل');
            }

            // جلب تفاصيل التحويل
            $items = $this->db->query("
                SELECT * FROM transfer_items WHERE transfer_id = :transfer_id
            ", ['transfer_id' => $transferId]);

            if (empty($items)) {
                throw new Exception('لا توجد أصناف في التحويل');
            }

            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;

            foreach ($items as $item) {
                // 1. خصم من المخزن المصدر
                $fromBalance = $this->getCurrentBalance($item['product_id'], $transfer['from_warehouse_id']);
                
                if ($fromBalance < $item['quantity']) {
                    throw new Exception("الكمية غير متوفرة في المخزن المصدر (المتاح: {$fromBalance})");
                }

                $newFromBalance = $fromBalance - $item['quantity'];

                $this->updateStockBalance($item['product_id'], $transfer['from_warehouse_id'], $newFromBalance);

                // تسجيل حركة خصم
                $this->insertStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['from_warehouse_id'],
                    'movement_type' => 'TRANSFER_OUT',
                    'reference_type' => 'transfer',
                    'reference_id' => $transferId,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $item['quantity'] * $item['unit_cost'],
                    'balance_before' => $fromBalance,
                    'balance_after' => $newFromBalance,
                    'user_id' => $userId,
                    'notes' => "تحويل إلى المخزن {$transfer['to_warehouse_id']}"
                ]);

                // 2. إضافة إلى المخزن الوجهة
                $toBalance = $this->getCurrentBalance($item['product_id'], $transfer['to_warehouse_id']);
                $newToBalance = $toBalance + $item['quantity'];

                $this->updateStockBalance($item['product_id'], $transfer['to_warehouse_id'], $newToBalance);

                // تسجيل حركة إضافة
                $this->insertStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $transfer['to_warehouse_id'],
                    'movement_type' => 'TRANSFER_IN',
                    'reference_type' => 'transfer',
                    'reference_id' => $transferId,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $item['quantity'] * $item['unit_cost'],
                    'balance_before' => $toBalance,
                    'balance_after' => $newToBalance,
                    'user_id' => $userId,
                    'notes' => "تحويل من المخزن {$transfer['from_warehouse_id']}"
                ]);

                $totalItems++;
                $totalQuantity += $item['quantity'];
                $totalCost += $item['quantity'] * $item['unit_cost'];
            }

            // تحديث حالة التحويل
            $this->db->update('transfers', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $transferId]);

            // تحديث إجماليات التحويل
            $this->db->update('transfers', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $transferId]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'TRANSFER_PROCESSED',
                'stock',
                "تنفيذ تحويل #{$transfer['transfer_no']}",
                [
                    'transfer_id' => $transferId,
                    'transfer_no' => $transfer['transfer_no'],
                    'from_warehouse' => $transfer['from_warehouse_id'],
                    'to_warehouse' => $transfer['to_warehouse_id'],
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'transfer',
                $transferId
            );

            $this->db->commit();

            // التحقق من التنبيهات
            $this->checkStockAlerts($transfer['to_warehouse_id']);

            return [
                'success' => true,
                'message' => 'تم تنفيذ التحويل بنجاح',
                'data' => [
                    'transfer_id' => $transferId,
                    'total_items' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ]
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * ============================================================
     * 4. عمليات المرتجع (Return)
     * ============================================================
     */

    /**
     * تنفيذ مرتجع
     */
    public function processReturn(int $returnId, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // جلب بيانات المرتجع
            $return = $this->db->queryOne("
                SELECT * FROM returns WHERE id = :id AND status = 'submitted'
            ", ['id' => $returnId]);

            if (!$return) {
                throw new Exception('المرتجع غير موجود أو ليس في حالة مرسل');
            }

            // جلب تفاصيل المرتجع
            $items = $this->db->query("
                SELECT * FROM return_items WHERE return_id = :return_id
            ", ['return_id' => $returnId]);

            if (empty($items)) {
                throw new Exception('لا توجد أصناف في المرتجع');
            }

            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;

            foreach ($items as $item) {
                $currentBalance = $this->getCurrentBalance($item['product_id'], $return['warehouse_id']);

                if ($return['return_type'] === 'to_supplier') {
                    // مرتجع للمورد - خصم من المخزون
                    if ($currentBalance < $item['quantity']) {
                        throw new Exception("الكمية غير متوفرة (المتاح: {$currentBalance})");
                    }
                    $newBalance = $currentBalance - $item['quantity'];
                    $movementType = 'RETURN_OUT';
                } else {
                    // مرتجع من العميل أو داخلي - إضافة للمخزون
                    $newBalance = $currentBalance + $item['quantity'];
                    $movementType = 'RETURN_IN';
                }

                // تحديث الرصيد
                $this->updateStockBalance($item['product_id'], $return['warehouse_id'], $newBalance);

                // تسجيل حركة المخزون
                $this->insertStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $return['warehouse_id'],
                    'movement_type' => $movementType,
                    'reference_type' => 'return',
                    'reference_id' => $returnId,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $item['quantity'] * $item['unit_cost'],
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance,
                    'user_id' => $userId,
                    'notes' => "مرتجع #{$return['return_no']} - {$return['return_type']}"
                ]);

                $totalItems++;
                $totalQuantity += $item['quantity'];
                $totalCost += $item['quantity'] * $item['unit_cost'];
            }

            // تحديث حالة المرتجع
            $this->db->update('returns', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $returnId]);

            // تحديث إجماليات المرتجع
            $this->db->update('returns', [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_cost' => $totalCost
            ], ['id' => $returnId]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RETURN_PROCESSED',
                'stock',
                "تنفيذ مرتجع #{$return['return_no']}",
                [
                    'return_id' => $returnId,
                    'return_no' => $return['return_no'],
                    'return_type' => $return['return_type'],
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'return',
                $returnId
            );

            $this->db->commit();

            // التحقق من التنبيهات
            $this->checkStockAlerts($return['warehouse_id']);

            return [
                'success' => true,
                'message' => 'تم تنفيذ المرتجع بنجاح',
                'data' => [
                    'return_id' => $returnId,
                    'total_items' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ]
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * ============================================================
     * 5. عمليات الجرد والتسوية (Inventory & Adjustment)
     * ============================================================
     */

    /**
     * تنفيذ جرد المخزون
     */
    public function processInventoryCount(int $countId, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // جلب بيانات الجرد
            $count = $this->db->queryOne("
                SELECT * FROM inventory_counts WHERE id = :id AND status = 'in_progress'
            ", ['id' => $countId]);

            if (!$count) {
                throw new Exception('جلسة الجرد غير موجودة أو ليست قيد التنفيذ');
            }

            // جلب تفاصيل الجرد
            $items = $this->db->query("
                SELECT * FROM inventory_count_items WHERE inventory_count_id = :count_id
            ", ['count_id' => $countId]);

            if (empty($items)) {
                throw new Exception('لا توجد أصناف في الجرد');
            }

            $totalDifferences = 0;

            foreach ($items as $item) {
                $difference = $item['actual_quantity'] - $item['system_quantity'];
                
                if ($difference != 0) {
                    $totalDifferences++;
                    
                    // تحديث الرصيد
                    $this->updateStockBalance($item['product_id'], $count['warehouse_id'], $item['actual_quantity']);

                    // تسجيل حركة التصحيح
                    $this->insertStockMovement([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $count['warehouse_id'],
                        'movement_type' => 'COUNT_CORRECTION',
                        'reference_type' => 'inventory_count',
                        'reference_id' => $countId,
                        'quantity' => abs($difference),
                        'unit_cost' => $item['unit_cost'],
                        'total_cost' => abs($difference) * $item['unit_cost'],
                        'balance_before' => $item['system_quantity'],
                        'balance_after' => $item['actual_quantity'],
                        'user_id' => $userId,
                        'notes' => "تصحيح جرد: " . ($difference > 0 ? 'زيادة' : 'نقص') . " {$difference}"
                    ]);
                }
            }

            // تحديث حالة الجرد
            $this->db->update('inventory_counts', [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => date('Y-m-d H:i:s'),
                'end_time' => date('Y-m-d H:i:s'),
                'total_differences' => $totalDifferences,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $countId]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'INVENTORY_PROCESSED',
                'stock',
                "تنفيذ جرد #{$count['count_no']}",
                [
                    'count_id' => $countId,
                    'count_no' => $count['count_no'],
                    'total_differences' => $totalDifferences
                ],
                'inventory_count',
                $countId
            );

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'تم تنفيذ الجرد بنجاح',
                'data' => [
                    'count_id' => $countId,
                    'total_differences' => $totalDifferences
                ]
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * ============================================================
     * 6. الدوال الأساسية للمخزون
     * ============================================================
     */

    /**
     * الحصول على الرصيد الحالي لصنف في مخزن
     */
    public function getCurrentBalance(int $productId, int $warehouseId): float
    {
        $result = $this->db->queryValue("
            SELECT COALESCE(quantity, 0) 
            FROM stock_balances 
            WHERE product_id = :product_id AND warehouse_id = :warehouse_id
        ", [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId
        ]);

        return (float)$result;
    }

    /**
     * تحديث الرصيد الحالي
     */
    public function updateStockBalance(int $productId, int $warehouseId, float $newBalance): void
    {
        $this->db->execute("
            INSERT INTO stock_balances (product_id, warehouse_id, quantity, updated_at)
            VALUES (:product_id, :warehouse_id, :quantity, NOW())
            ON DUPLICATE KEY UPDATE 
                quantity = :quantity,
                updated_at = NOW()
        ", [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'quantity' => $newBalance
        ]);
    }

    /**
     * تسجيل حركة مخزون
     */
    private function insertStockMovement(array $data): void
    {
        $this->db->insert('stock_movements', [
            'product_id' => $data['product_id'],
            'warehouse_id' => $data['warehouse_id'],
            'movement_type' => $data['movement_type'],
            'reference_type' => $data['reference_type'],
            'reference_id' => $data['reference_id'],
            'quantity' => $data['quantity'],
            'unit_cost' => $data['unit_cost'],
            'total_cost' => $data['total_cost'] ?? ($data['quantity'] * $data['unit_cost']),
            'balance_before' => $data['balance_before'],
            'balance_after' => $data['balance_after'],
            'movement_date' => date('Y-m-d H:i:s'),
            'user_id' => $data['user_id'],
            'notes' => $data['notes'] ?? null
        ]);
    }

    /**
     * إضافة دفعة (Batch)
     */
    public function addBatch(array $data): void
    {
        $this->db->insert('product_batches', [
            'product_id' => $data['product_id'],
            'warehouse_id' => $data['warehouse_id'],
            'batch_number' => $data['batch_number'],
            'quantity' => $data['quantity'],
            'unit_cost' => $data['unit_cost'],
            'expiry_date' => $data['expiry_date'] ?? null,
            'received_at' => $data['received_at'] ?? date('Y-m-d'),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * إضافة رقم تسلسلي (Serial)
     */
    public function addSerial(array $data): void
    {
        $this->db->insert('product_serials', [
            'product_id' => $data['product_id'],
            'warehouse_id' => $data['warehouse_id'],
            'serial_number' => $data['serial_number'],
            'status' => 'in_stock',
            'cost_price' => $data['cost_price'] ?? 0,
            'purchase_date' => $data['purchase_date'] ?? date('Y-m-d'),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * التحقق من تنبيهات المخزون
     */
    public function checkStockAlerts(int $warehouseId): void
    {
        // جلب الأصناف منخفضة المخزون
        $lowStockItems = $this->db->query("
            SELECT 
                p.id,
                p.name,
                p.code,
                sb.quantity,
                p.min_stock
            FROM stock_balances sb
            INNER JOIN products p ON p.id = sb.product_id
            WHERE sb.warehouse_id = :warehouse_id
              AND sb.quantity <= p.min_stock
              AND sb.quantity > 0
        ", ['warehouse_id' => $warehouseId]);

        foreach ($lowStockItems as $item) {
            $this->createNotification(
                'low_stock',
                "تنبيه: مخزون منخفض - {$item['name']}",
                "المنتج '{$item['name']}' في المخزن وصل للحد الأدنى ({$item['quantity']} / {$item['min_stock']})",
                'high',
                $item['id'],
                $warehouseId
            );
        }

        // جلب الأصناف المنفذة
        $outOfStockItems = $this->db->query("
            SELECT 
                p.id,
                p.name,
                p.code
            FROM stock_balances sb
            INNER JOIN products p ON p.id = sb.product_id
            WHERE sb.warehouse_id = :warehouse_id
              AND sb.quantity = 0
        ", ['warehouse_id' => $warehouseId]);

        foreach ($outOfStockItems as $item) {
            $this->createNotification(
                'out_of_stock',
                "⚠️ نفاذ المخزون - {$item['name']}",
                "المنتج '{$item['name']}' نفد من المخزون",
                'critical',
                $item['id'],
                $warehouseId
            );
        }
    }

    /**
     * إنشاء تنبيه
     */
    private function createNotification(string $type, string $title, string $message, string $priority, int $productId, int $warehouseId): void
    {
        // جلب المستخدمين الذين يحتاجون التنبيه
        $users = $this->db->query("
            SELECT id FROM users 
            WHERE is_active = 1 
              AND role_id IN (SELECT id FROM roles WHERE name IN ('admin', 'warehouse_manager', 'warehouse_supervisor'))
        ");

        foreach ($users as $user) {
            $this->db->insert('notifications', [
                'user_id' => $user['id'],
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'priority' => $priority,
                'reference_type' => 'product',
                'reference_id' => $productId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * الحصول على إحصائيات المخزون للمخزن
     */
    public function getWarehouseStockStats(int $warehouseId): array
    {
        $stats = $this->db->queryOne("
            SELECT 
                COUNT(DISTINCT p.id) as total_products,
                COALESCE(SUM(sb.quantity), 0) as total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value,
                COUNT(CASE WHEN sb.quantity <= 0 THEN 1 END) as out_of_stock,
                COUNT(CASE WHEN sb.quantity <= p.min_stock AND sb.quantity > 0 THEN 1 END) as low_stock,
                COUNT(CASE WHEN sb.quantity >= p.max_stock THEN 1 END) as over_stock,
                COUNT(CASE WHEN sb.quantity > p.min_stock AND (sb.quantity < p.max_stock OR p.max_stock IS NULL) THEN 1 END) as normal
            FROM stock_balances sb
            INNER JOIN products p ON p.id = sb.product_id
            WHERE sb.warehouse_id = :warehouse_id
        ", ['warehouse_id' => $warehouseId]);

        return [
            'total_products' => (int)($stats['total_products'] ?? 0),
            'total_quantity' => (float)($stats['total_quantity'] ?? 0),
            'total_value' => (float)($stats['total_value'] ?? 0),
            'out_of_stock' => (int)($stats['out_of_stock'] ?? 0),
            'low_stock' => (int)($stats['low_stock'] ?? 0),
            'over_stock' => (int)($stats['over_stock'] ?? 0),
            'normal' => (int)($stats['normal'] ?? 0)
        ];
    }

    /**
     * الحصول على إحصائيات المخزون الإجمالية
     */
    public function getTotalStockStats(): array
    {
        $stats = $this->db->queryOne("
            SELECT 
                COUNT(DISTINCT p.id) as total_products,
                COALESCE(SUM(sb.quantity), 0) as total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) as total_value,
                COUNT(CASE WHEN sb.quantity <= 0 THEN 1 END) as out_of_stock,
                COUNT(CASE WHEN sb.quantity <= p.min_stock AND sb.quantity > 0 THEN 1 END) as low_stock,
                COUNT(CASE WHEN sb.quantity >= p.max_stock THEN 1 END) as over_stock
            FROM stock_balances sb
            INNER JOIN products p ON p.id = sb.product_id
        ");

        return [
            'total_products' => (int)($stats['total_products'] ?? 0),
            'total_quantity' => (float)($stats['total_quantity'] ?? 0),
            'total_value' => (float)($stats['total_value'] ?? 0),
            'out_of_stock' => (int)($stats['out_of_stock'] ?? 0),
            'low_stock' => (int)($stats['low_stock'] ?? 0),
            'over_stock' => (int)($stats['over_stock'] ?? 0)
        ];
    }

    /**
     * الحصول على حركات المخزون لصنف معين
     */
    public function getProductMovements(int $productId, ?int $warehouseId = null, int $limit = 50): array
    {
        $params = ['product_id' => $productId, 'limit' => $limit];
        $where = ["product_id = :product_id"];

        if ($warehouseId) {
            $where[] = "warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        return $this->db->query("
            SELECT 
                sm.*,
                w.name as warehouse_name,
                u.full_name as user_name,
                CASE sm.movement_type
                    WHEN 'RECEIPT' THEN 'استلام'
                    WHEN 'ISSUE' THEN 'صرف'
                    WHEN 'TRANSFER_OUT' THEN 'تحويل خارج'
                    WHEN 'TRANSFER_IN' THEN 'تحويل داخل'
                    WHEN 'RETURN_IN' THEN 'مرتجع للمخزن'
                    WHEN 'RETURN_OUT' THEN 'مرتجع من المخزن'
                    WHEN 'ADJUSTMENT' THEN 'تسوية'
                    WHEN 'COUNT_CORRECTION' THEN 'تصحيح جرد'
                    ELSE sm.movement_type
                END as movement_label
            FROM stock_movements sm
            INNER JOIN warehouses w ON w.id = sm.warehouse_id
            INNER JOIN users u ON u.id = sm.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sm.movement_date DESC
            LIMIT :limit
        ", $params);
    }
}

// ================================================================
// انتهى الملف
// ================================================================

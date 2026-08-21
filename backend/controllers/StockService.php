<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/services/StockService.php
// الوصف: محرك المخزون الأساسي - معاملات آمنة
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
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
     * إنشاء إذن استلام مع معاملة آمنة
     */
    public function createReceipt(array $data, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // 1. إنشاء إذن الاستلام
            $receiptId = $this->insertReceipt($data, $userId);
            
            // 2. حفظ تفاصيل الاستلام وتحديث الأرصدة
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($data['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                // حفظ تفاصيل الصنف
                $this->insertReceiptItem($receiptId, $item);
                
                // الحصول على الرصيد الحالي
                $currentBalance = $this->getCurrentBalance($item['product_id'], $data['warehouse_id']);
                $newBalance = $currentBalance + $item['quantity'];
                
                // تسجيل حركة المخزون
                $this->insertStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'movement_type' => 'RECEIPT',
                    'reference_type' => 'receipt',
                    'reference_id' => $receiptId,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance,
                    'user_id' => $userId,
                    'notes' => $data['notes'] ?? null
                ]);
                
                // تحديث الرصيد الحالي
                $this->updateStockBalance($item['product_id'], $data['warehouse_id'], $newBalance);
                
                // التحقق من التنبيهات
                $this->checkStockAlerts($item['product_id'], $data['warehouse_id'], $newBalance);
            }
            
            // 3. تحديث إجماليات الإذن
            $this->updateReceiptTotals($receiptId, $totalItems, $totalQuantity, $totalCost);
            
            // 4. تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RECEIPT_CREATED_STOCK',
                'stock',
                "إنشاء إذن استلام #{$data['receipt_no']}",
                [
                    'receipt_id' => $receiptId,
                    'receipt_no' => $data['receipt_no'],
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'receipt',
                $receiptId
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'receipt_id' => $receiptId,
                'message' => 'تم إنشاء إذن الاستلام بنجاح'
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            
            $this->audit->log(
                $userId,
                'RECEIPT_CREATE_FAILED',
                'stock',
                "فشل إنشاء إذن استلام",
                [
                    'error' => $e->getMessage(),
                    'receipt_no' => $data['receipt_no'] ?? null
                ]
            );
            
            throw $e;
        }
    }

    /**
     * إنشاء إذن صرف مع معاملة آمنة
     */
    public function createIssue(array $data, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // 1. التحقق من توفر الكميات
            foreach ($data['items'] as $item) {
                $currentBalance = $this->getCurrentBalance($item['product_id'], $data['warehouse_id']);
                
                if ($currentBalance < $item['quantity']) {
                    throw new Exception("الكمية غير متوفرة للصنف (المتاح: {$currentBalance})");
                }
            }

            // 2. إنشاء إذن الصرف
            $issueId = $this->insertIssue($data, $userId);
            
            // 3. حفظ التفاصيل وتحديث الأرصدة
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($data['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->insertIssueItem($issueId, $item);
                
                $currentBalance = $this->getCurrentBalance($item['product_id'], $data['warehouse_id']);
                $newBalance = $currentBalance - $item['quantity'];
                
                // تسجيل حركة المخزون
                $this->insertStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'movement_type' => 'ISSUE',
                    'reference_type' => 'issue',
                    'reference_id' => $issueId,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance,
                    'user_id' => $userId,
                    'notes' => $data['notes'] ?? null
                ]);
                
                $this->updateStockBalance($item['product_id'], $data['warehouse_id'], $newBalance);
                
                // التحقق من التنبيهات
                $this->checkStockAlerts($item['product_id'], $data['warehouse_id'], $newBalance);
            }
            
            // 4. تحديث إجماليات الإذن
            $this->updateIssueTotals($issueId, $totalItems, $totalQuantity, $totalCost);
            
            // 5. تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'ISSUE_CREATED_STOCK',
                'stock',
                "إنشاء إذن صرف #{$data['issue_no']}",
                [
                    'issue_id' => $issueId,
                    'issue_no' => $data['issue_no'],
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'issue',
                $issueId
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'issue_id' => $issueId,
                'message' => 'تم إنشاء إذن الصرف بنجاح'
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            
            $this->audit->log(
                $userId,
                'ISSUE_CREATE_FAILED',
                'stock',
                "فشل إنشاء إذن صرف",
                [
                    'error' => $e->getMessage(),
                    'issue_no' => $data['issue_no'] ?? null
                ]
            );
            
            throw $e;
        }
    }

    /**
     * إنشاء تحويل بين المخازن مع معاملة آمنة
     */
    public function createTransfer(array $data, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // 1. التحقق من توفر الكميات في المخزن المصدر
            foreach ($data['items'] as $item) {
                $currentBalance = $this->getCurrentBalance($item['product_id'], $data['from_warehouse_id']);
                
                if ($currentBalance < $item['quantity']) {
                    throw new Exception("الكمية غير متوفرة للصنف في المخزن المصدر (المتاح: {$currentBalance})");
                }
            }

            // 2. إنشاء التحويل
            $transferId = $this->insertTransfer($data, $userId);
            
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($data['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->insertTransferItem($transferId, $item);
                
                // 3. خصم من المخزن المصدر
                $fromBalance = $this->getCurrentBalance($item['product_id'], $data['from_warehouse_id']);
                $newFromBalance = $fromBalance - $item['quantity'];
                
                $this->insertStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $data['from_warehouse_id'],
                    'movement_type' => 'TRANSFER_OUT',
                    'reference_type' => 'transfer',
                    'reference_id' => $transferId,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'balance_before' => $fromBalance,
                    'balance_after' => $newFromBalance,
                    'user_id' => $userId,
                    'notes' => "تحويل إلى مخزن {$data['to_warehouse_id']}"
                ]);
                
                $this->updateStockBalance($item['product_id'], $data['from_warehouse_id'], $newFromBalance);
                
                // 4. إضافة إلى المخزن الوجهة
                $toBalance = $this->getCurrentBalance($item['product_id'], $data['to_warehouse_id']);
                $newToBalance = $toBalance + $item['quantity'];
                
                $this->insertStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $data['to_warehouse_id'],
                    'movement_type' => 'TRANSFER_IN',
                    'reference_type' => 'transfer',
                    'reference_id' => $transferId,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'balance_before' => $toBalance,
                    'balance_after' => $newToBalance,
                    'user_id' => $userId,
                    'notes' => "تحويل من مخزن {$data['from_warehouse_id']}"
                ]);
                
                $this->updateStockBalance($item['product_id'], $data['to_warehouse_id'], $newToBalance);
            }
            
            // 5. تحديث إجماليات التحويل
            $this->updateTransferTotals($transferId, $totalItems, $totalQuantity, $totalCost);
            
            // 6. تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'TRANSFER_CREATED_STOCK',
                'stock',
                "إنشاء تحويل #{$data['transfer_no']}",
                [
                    'transfer_id' => $transferId,
                    'transfer_no' => $data['transfer_no'],
                    'from_warehouse' => $data['from_warehouse_id'],
                    'to_warehouse' => $data['to_warehouse_id'],
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'transfer',
                $transferId
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'transfer_id' => $transferId,
                'message' => 'تم إنشاء التحويل بنجاح'
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            
            $this->audit->log(
                $userId,
                'TRANSFER_CREATE_FAILED',
                'stock',
                "فشل إنشاء تحويل",
                [
                    'error' => $e->getMessage(),
                    'transfer_no' => $data['transfer_no'] ?? null
                ]
            );
            
            throw $e;
        }
    }

    /**
     * إنشاء مرتجع مع معاملة آمنة
     */
    public function createReturn(array $data, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // 1. إنشاء المرتجع
            $returnId = $this->insertReturn($data, $userId);
            
            $totalItems = 0;
            $totalQuantity = 0;
            $totalCost = 0;
            
            foreach ($data['items'] as $item) {
                $itemTotal = $item['quantity'] * $item['unit_cost'];
                $totalQuantity += $item['quantity'];
                $totalCost += $itemTotal;
                $totalItems++;
                
                $this->insertReturnItem($returnId, $item);
                
                $currentBalance = $this->getCurrentBalance($item['product_id'], $data['warehouse_id']);
                
                if ($data['return_type'] === 'to_supplier') {
                    // مرتجع للمورد - خصم من المخزون
                    $newBalance = $currentBalance - $item['quantity'];
                    $movementType = 'RETURN_OUT';
                } else {
                    // مرتجع من العميل أو داخلي - إضافة للمخزون
                    $newBalance = $currentBalance + $item['quantity'];
                    $movementType = 'RETURN_IN';
                }
                
                // تسجيل حركة المخزون
                $this->insertStockMovement([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'movement_type' => $movementType,
                    'reference_type' => 'return',
                    'reference_id' => $returnId,
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total_cost' => $itemTotal,
                    'balance_before' => $currentBalance,
                    'balance_after' => $newBalance,
                    'user_id' => $userId,
                    'notes' => "مرتجع #{$data['return_no']} - {$data['return_type']}"
                ]);
                
                $this->updateStockBalance($item['product_id'], $data['warehouse_id'], $newBalance);
            }
            
            // 2. تحديث إجماليات المرتجع
            $this->updateReturnTotals($returnId, $totalItems, $totalQuantity, $totalCost);
            
            // 3. تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RETURN_CREATED_STOCK',
                'stock',
                "إنشاء مرتجع #{$data['return_no']}",
                [
                    'return_id' => $returnId,
                    'return_no' => $data['return_no'],
                    'return_type' => $data['return_type'],
                    'items_count' => $totalItems,
                    'total_quantity' => $totalQuantity,
                    'total_cost' => $totalCost
                ],
                'return',
                $returnId
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'return_id' => $returnId,
                'message' => 'تم إنشاء المرتجع بنجاح'
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            
            $this->audit->log(
                $userId,
                'RETURN_CREATE_FAILED',
                'stock',
                "فشل إنشاء مرتجع",
                [
                    'error' => $e->getMessage(),
                    'return_no' => $data['return_no'] ?? null
                ]
            );
            
            throw $e;
        }
    }

    /**
     * إجراء تسوية جرد
     */
    public function processInventoryAdjustment(array $data, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            $adjustmentId = $this->insertAdjustment($data, $userId);
            
            $totalItems = 0;
            $totalQuantity = 0;
            $totalValue = 0;
            
            foreach ($data['items'] as $item) {
                $currentBalance = $this->getCurrentBalance($item['product_id'], $data['warehouse_id']);
                $difference = $item['actual_quantity'] - $currentBalance;
                
                if ($difference != 0) {
                    $totalItems++;
                    $totalQuantity += abs($difference);
                    $totalValue += abs($difference) * $item['unit_cost'];
                    
                    // تسجيل حركة التصحيح
                    $this->insertStockMovement([
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $data['warehouse_id'],
                        'movement_type' => 'ADJUSTMENT',
                        'reference_type' => 'stock_adjustment',
                        'reference_id' => $adjustmentId,
                        'quantity' => abs($difference),
                        'unit_cost' => $item['unit_cost'],
                        'total_cost' => abs($difference) * $item['unit_cost'],
                        'balance_before' => $currentBalance,
                        'balance_after' => $item['actual_quantity'],
                        'user_id' => $userId,
                        'notes' => "تسوية جرد: " . ($difference > 0 ? 'زيادة' : 'نقص') . " {$difference}"
                    ]);
                    
                    // تحديث الرصيد
                    $this->updateStockBalance($item['product_id'], $data['warehouse_id'], $item['actual_quantity']);
                }
            }
            
            // تحديث إجماليات التسوية
            $this->updateAdjustmentTotals($adjustmentId, $totalItems, $totalQuantity, $totalValue);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'adjustment_id' => $adjustmentId,
                'message' => 'تمت تسوية الجرد بنجاح'
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // ================================================================
    // دوال إدراج
    // ================================================================

    private function insertReceipt(array $data, int $userId): int
    {
        return $this->db->insert('receipts', [
            'receipt_no' => $data['receipt_no'],
            'warehouse_id' => $data['warehouse_id'],
            'supplier_id' => $data['supplier_id'],
            'receipt_date' => $data['receipt_date'] ?? date('Y-m-d'),
            'receipt_time' => $data['receipt_time'] ?? date('H:i:s'),
            'notes' => $data['notes'] ?? null,
            'status' => 'draft',
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function insertReceiptItem(int $receiptId, array $item): void
    {
        $this->db->insert('receipt_items', [
            'receipt_id' => $receiptId,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_cost' => $item['unit_cost'],
            'total_cost' => $item['quantity'] * $item['unit_cost']
        ]);
    }

    private function insertIssue(array $data, int $userId): int
    {
        return $this->db->insert('issues', [
            'issue_no' => $data['issue_no'],
            'warehouse_id' => $data['warehouse_id'],
            'recipient_id' => $data['recipient_id'],
            'issue_date' => $data['issue_date'] ?? date('Y-m-d'),
            'issue_time' => $data['issue_time'] ?? date('H:i:s'),
            'notes' => $data['notes'] ?? null,
            'status' => 'draft',
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function insertIssueItem(int $issueId, array $item): void
    {
        $this->db->insert('issue_items', [
            'issue_id' => $issueId,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_cost' => $item['unit_cost'],
            'total_cost' => $item['quantity'] * $item['unit_cost']
        ]);
    }

    private function insertTransfer(array $data, int $userId): int
    {
        return $this->db->insert('transfers', [
            'transfer_no' => $data['transfer_no'],
            'from_warehouse_id' => $data['from_warehouse_id'],
            'to_warehouse_id' => $data['to_warehouse_id'],
            'transfer_date' => $data['transfer_date'] ?? date('Y-m-d'),
            'transfer_time' => $data['transfer_time'] ?? date('H:i:s'),
            'notes' => $data['notes'] ?? null,
            'status' => 'draft',
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function insertTransferItem(int $transferId, array $item): void
    {
        $this->db->insert('transfer_items', [
            'transfer_id' => $transferId,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_cost' => $item['unit_cost'],
            'total_cost' => $item['quantity'] * $item['unit_cost']
        ]);
    }

    private function insertReturn(array $data, int $userId): int
    {
        return $this->db->insert('returns', [
            'return_no' => $data['return_no'],
            'return_type' => $data['return_type'],
            'warehouse_id' => $data['warehouse_id'],
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'return_date' => $data['return_date'] ?? date('Y-m-d'),
            'return_time' => $data['return_time'] ?? date('H:i:s'),
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'draft',
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    private function insertReturnItem(int $returnId, array $item): void
    {
        $this->db->insert('return_items', [
            'return_id' => $returnId,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_cost' => $item['unit_cost'],
            'total_cost' => $item['quantity'] * $item['unit_cost']
        ]);
    }

    private function insertAdjustment(array $data, int $userId): int
    {
        return $this->db->insert('stock_adjustments', [
            'adjustment_no' => $data['adjustment_no'],
            'warehouse_id' => $data['warehouse_id'],
            'adjustment_type' => 'inventory',
            'adjustment_date' => date('Y-m-d'),
            'adjustment_time' => date('H:i:s'),
            'reason' => $data['reason'] ?? 'تسوية جرد',
            'notes' => $data['notes'] ?? null,
            'status' => 'approved',
            'user_id' => $userId,
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    // ================================================================
    // دوال تحديث
    // ================================================================

    private function updateReceiptTotals(int $receiptId, int $items, float $quantity, float $cost): void
    {
        $this->db->update('receipts', [
            'total_items' => $items,
            'total_quantity' => $quantity,
            'total_cost' => $cost
        ], ['id' => $receiptId]);
    }

    private function updateIssueTotals(int $issueId, int $items, float $quantity, float $cost): void
    {
        $this->db->update('issues', [
            'total_items' => $items,
            'total_quantity' => $quantity,
            'total_cost' => $cost
        ], ['id' => $issueId]);
    }

    private function updateTransferTotals(int $transferId, int $items, float $quantity, float $cost): void
    {
        $this->db->update('transfers', [
            'total_items' => $items,
            'total_quantity' => $quantity,
            'total_cost' => $cost
        ], ['id' => $transferId]);
    }

    private function updateReturnTotals(int $returnId, int $items, float $quantity, float $cost): void
    {
        $this->db->update('returns', [
            'total_items' => $items,
            'total_quantity' => $quantity,
            'total_cost' => $cost
        ], ['id' => $returnId]);
    }

    private function updateAdjustmentTotals(int $adjustmentId, int $items, float $quantity, float $value): void
    {
        $this->db->update('stock_adjustments', [
            'total_items' => $items,
            'total_quantity' => $quantity,
            'total_value' => $value
        ], ['id' => $adjustmentId]);
    }

    // ================================================================
    // دوال الاستعلام والمساعدة
    // ================================================================

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

    private function updateStockBalance(int $productId, int $warehouseId, float $newBalance): void
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

    private function getCurrentBalance(int $productId, int $warehouseId): float
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

    private function checkStockAlerts(int $productId, int $warehouseId, float $newBalance): void
    {
        $product = $this->db->queryOne("
            SELECT id, name, min_stock, max_stock 
            FROM products 
            WHERE id = :id AND is_active = 1
        ", ['id' => $productId]);

        if (!$product) {
            return;
        }

        $warehouse = $this->db->queryOne("
            SELECT id, name 
            FROM warehouses 
            WHERE id = :id AND is_active = 1
        ", ['id' => $warehouseId]);

        // تنبيه المخزون المنخفض
        if ($newBalance <= $product['min_stock'] && $newBalance > 0) {
            $this->createNotification(
                'low_stock',
                "تنبيه: مخزون منخفض - {$product['name']}",
                "المنتج '{$product['name']}' في مخزن '{$warehouse['name']}' وصل للحد الأدنى ({$newBalance} / {$product['min_stock']})",
                'high',
                $productId,
                $warehouseId
            );
        }

        // تنبيه نفاذ المخزون
        if ($newBalance <= 0) {
            $this->createNotification(
                'out_of_stock',
                "⚠️ نفاذ المخزون - {$product['name']}",
                "المنتج '{$product['name']}' في مخزن '{$warehouse['name']}' نفد من المخزون",
                'critical',
                $productId,
                $warehouseId
            );
        }

        // تنبيه المخزون الزائد
        if ($product['max_stock'] && $newBalance >= $product['max_stock']) {
            $this->createNotification(
                'over_stock',
                "تنبيه: مخزون زائد - {$product['name']}",
                "المنتج '{$product['name']}' في مخزن '{$warehouse['name']}' تجاوز الحد الأقصى ({$newBalance} / {$product['max_stock']})",
                'medium',
                $productId,
                $warehouseId
            );
        }
    }

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
}

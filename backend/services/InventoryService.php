<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Response;
use Throwable;
use Exception;

/**
 * ============================================================================
 * Inventory Service (محرك المخزون)
 * نظام إدارة المخازن والمخزون - Logistox v5.0
 * ============================================================================
 * 
 * المسؤوليات:
 * 1. معالجة عمليات الاستلام (Receipts) وتحديث الأرصدة.
 * 2. معالجة عمليات الصرف (Issues) مع التحقق من توفر الرصيد.
 * 3. معالجة التحويلات المخزنية (Transfers) بشكل ذري (من مخزن لآخر).
 * 4. معالجة الجرد (Inventory Counts) وتسوية الفروقات.
 * 
 * المبادئ المعمارية المطبقة:
 * - Atomic Transactions: إما أن تنجح كل الخطوات أو تفشل كلها (Rollback).
 * - Pessimistic Locking (FOR UPDATE): لمنع حالة Race Condition عند تحديث الرصيد.
 * - Ledger Pattern: لا يتم تحديث الرصيد إلا بعد تسجيل الحركة في سجل الحركات.
 * 
 * ============================================================================
 */
class InventoryService
{
    private Database $db;

    /**
     * حقن اعتمادية قاعدة البيانات (Dependency Injection)
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // 1. معالجة إذن الاستلام (Receipt Processing)
    // =========================================================================

    /**
     * اعتماد إذن استلام وتحديث المخزون
     * 
     * @param int $receiptId معرف إذن الاستلام
     * @param int $userId معرف المستخدم الذي يعتمد الإذن
     * @return array نتيجة العملية
     * @throws Exception في حالة فشل العملية أو عدم وجود بيانات
     */
    public function approveReceipt(int $receiptId, int $userId): array
    {
        return $this->db->transaction(function (Database $db) use ($receiptId, $userId) {
            
            // 1. جلب بيانات الإذن والتحقق من حالته
            $receipt = $db->fetch("
                SELECT id, warehouse_id, status, total_items, total_quantity, total_cost 
                FROM receipts 
                WHERE id = ? AND deleted_at IS NULL 
                FOR UPDATE", 
                [$receiptId]
            );

            if (!$receipt) {
                throw new Exception("إذن الاستلام غير موجود.");
            }

            if ($receipt['status'] !== 'pending') {
                throw new Exception("لا يمكن اعتماد إذن استلام حالته ليست 'قيد الانتظار' (pending).");
            }

            // 2. جلب بنود الإذن
            $items = $db->fetchAll("
                SELECT product_id, quantity, unit_cost, total_cost, batch_number, expiry_date 
                FROM receipt_items 
                WHERE receipt_id = ?", 
                [$receiptId]
            );

            if (empty($items)) {
                throw new Exception("لا يمكن اعتماد إذن استلام فارغ.");
            }

            // 3. معالجة كل بند وتحديث المخزون
            foreach ($items as $item) {
                $this->processStockIn(
                    $db,
                    $item['product_id'],
                    $receipt['warehouse_id'],
                    $item['quantity'],
                    $item['unit_cost'],
                    $item['total_cost'],
                    'RECEIPT',
                    $receiptId,
                    $userId,
                    $item['batch_number'],
                    $item['expiry_date']
                );
            }

            // 4. تحديث حالة الإذن إلى معتمد
            $db->execute("
                UPDATE receipts 
                SET status = 'approved', approved_by = ?, approved_at = NOW() 
                WHERE id = ?", 
                [$userId, $receiptId]
            );

            return [
                'success' => true,
                'message' => 'تم اعتماد إذن الاستلام وتحديث المخزون بنجاح.',
                'receipt_id' => $receiptId,
                'items_processed' => count($items)
            ];
        });
    }

    // =========================================================================
    // 2. معالجة إذن الصرف (Issue Processing)
    // =========================================================================

    /**
     * اعتماد إذن صرف وسحب من المخزون
     * 
     * @param int $issueId معرف إذن الصرف
     * @param int $userId معرف المستخدم
     * @return array نتيجة العملية
     * @throws Exception في حالة عدم كفاية الرصيد
     */
    public function approveIssue(int $issueId, int $userId): array
    {
        return $this->db->transaction(function (Database $db) use ($issueId, $userId) {
            
            $issue = $db->fetch("
                SELECT id, warehouse_id, status 
                FROM issues 
                WHERE id = ? AND deleted_at IS NULL 
                FOR UPDATE", 
                [$issueId]
            );

            if (!$issue || $issue['status'] !== 'pending') {
                throw new Exception("إذن الصرف غير موجود أو ليس في حالة 'قيد الانتظار'.");
            }

            $items = $db->fetchAll("
                SELECT product_id, quantity, unit_cost, total_cost 
                FROM issue_items 
                WHERE issue_id = ?", 
                [$issueId]
            );

            foreach ($items as $item) {
                // التحقق من توفر الرصيد قبل الصرف
                $balance = $this->getStockBalance($db, $item['product_id'], $issue['warehouse_id']);
                $available = (float)($balance['available_quantity'] ?? 0);
                $required = (float)$item['quantity'];

                if ($available < $required) {
                    throw new Exception("رصيد غير كافٍ للصنف ID: {$item['product_id']}. المتاح: {$available}, المطلوب: {$required}");
                }

                $this->processStockOut(
                    $db,
                    $item['product_id'],
                    $issue['warehouse_id'],
                    $item['quantity'],
                    $item['unit_cost'],
                    $item['total_cost'],
                    'ISSUE',
                    $issueId,
                    $userId
                );
            }

            $db->execute("
                UPDATE issues 
                SET status = 'approved', approved_by = ?, approved_at = NOW() 
                WHERE id = ?", 
                [$userId, $issueId]
            );

            return [
                'success' => true,
                'message' => 'تم اعتماد إذن الصرف وسحب الكميات بنجاح.',
                'issue_id' => $issueId
            ];
        });
    }

    // =========================================================================
    // 3. معالجة التحويل المخزني (Transfer Processing)
    // =========================================================================

    /**
     * اعتماد تحويل مخزني (سحب من مصدر وإضافة إلى وجهة)
     * 
     * @param int $transferId معرف التحويل
     * @param int $userId معرف المستخدم
     * @return array نتيجة العملية
     */
    public function approveTransfer(int $transferId, int $userId): array
    {
        return $this->db->transaction(function (Database $db) use ($transferId, $userId) {
            
            $transfer = $db->fetch("
                SELECT id, from_warehouse_id, to_warehouse_id, status 
                FROM transfers 
                WHERE id = ? AND deleted_at IS NULL 
                FOR UPDATE", 
                [$transferId]
            );

            if (!$transfer || $transfer['status'] !== 'pending') {
                throw new Exception("التحويل غير موجود أو ليس في حالة 'قيد الانتظار'.");
            }

            $items = $db->fetchAll("
                SELECT product_id, quantity, unit_cost, total_cost 
                FROM transfer_items 
                WHERE transfer_id = ?", 
                [$transferId]
            );

            foreach ($items as $item) {
                // 1. السحب من المخزن المصدر
                $balanceFrom = $this->getStockBalance($db, $item['product_id'], $transfer['from_warehouse_id']);
                if ((float)($balanceFrom['available_quantity'] ?? 0) < (float)$item['quantity']) {
                    throw new Exception("رصيد غير كافٍ في المخزن المصدر للصنف ID: {$item['product_id']}");
                }

                $this->processStockOut(
                    $db,
                    $item['product_id'],
                    $transfer['from_warehouse_id'],
                    $item['quantity'],
                    $item['unit_cost'],
                    $item['total_cost'],
                    'TRANSFER_OUT',
                    $transferId,
                    $userId,
                    $transfer['to_warehouse_id'] // كـ to_warehouse_id في السجل
                );

                // 2. الإضافة إلى المخزن الوجهة
                $this->processStockIn(
                    $db,
                    $item['product_id'],
                    $transfer['to_warehouse_id'],
                    $item['quantity'],
                    $item['unit_cost'],
                    $item['total_cost'],
                    'TRANSFER_IN',
                    $transferId,
                    $userId,
                    null,
                    null,
                    $transfer['from_warehouse_id'] // كـ from_warehouse_id في السجل
                );
            }

            $db->execute("
                UPDATE transfers 
                SET status = 'approved', approved_by = ?, approved_at = NOW() 
                WHERE id = ?", 
                [$userId, $transferId]
            );

            return [
                'success' => true,
                'message' => 'تم اعتماد التحويل المخزني بنجاح.',
                'transfer_id' => $transferId
            ];
        });
    }

    // =========================================================================
    // 4. معالجة الجرد المخزني (Inventory Count Processing)
    // =========================================================================

    /**
     * اعتماد عملية جرد وتسوية الفروقات
     * 
     * @param int $countId معرف عملية الجرد
     * @param int $userId معرف المستخدم
     * @return array نتيجة العملية
     */
    public function approveInventoryCount(int $countId, int $userId): array
    {
        return $this->db->transaction(function (Database $db) use ($countId, $userId) {
            
            $count = $db->fetch("
                SELECT id, warehouse_id, status 
                FROM inventory_counts 
                WHERE id = ? AND deleted_at IS NULL 
                FOR UPDATE", 
                [$countId]
            );

            if (!$count || !in_array($count['status'], ['in_progress', 'completed'])) {
                throw new Exception("عملية الجرد غير موجودة أو ليست في حالة قابلة للاعتماد.");
            }

            $items = $db->fetchAll("
                SELECT product_id, system_quantity, counted_quantity, unit_cost 
                FROM inventory_count_items 
                WHERE inventory_count_id = ?", 
                [$countId]
            );

            $correctionsMade = 0;

            foreach ($items as $item) {
                $difference = (float)$item['counted_quantity'] - (float)$item['system_quantity'];

                if ($difference == 0) {
                    continue; // لا يوجد فرق، لا حاجة للتحديث
                }

                $movementType = $difference > 0 ? 'COUNT_CORRECTION' : 'COUNT_CORRECTION';
                $qtyToProcess = abs($difference);
                $cost = $item['unit_cost'] ?? 0;

                if ($difference > 0) {
                    // زيادة في الرصيد (موجبة)
                    $this->processStockIn(
                        $db, $item['product_id'], $count['warehouse_id'], $qtyToProcess, $cost, ($qtyToProcess * $cost),
                        'COUNT_CORRECTION', $countId, $userId
                    );
                } else {
                    // نقص في الرصيد (سالبة)
                    $this->processStockOut(
                        $db, $item['product_id'], $count['warehouse_id'], $qtyToProcess, $cost, ($qtyToProcess * $cost),
                        'COUNT_CORRECTION', $countId, $userId
                    );
                }
                $correctionsMade++;
            }

            $db->execute("
                UPDATE inventory_counts 
                SET status = 'approved', approved_by = ?, approved_at = NOW() 
                WHERE id = ?", 
                [$userId, $countId]
            );

            return [
                'success' => true,
                'message' => "تم اعتماد الجرد وتسوية {$correctionsMade} فرق(ات) في الرصيد.",
                'count_id' => $countId
            ];
        });
    }

    // =========================================================================
    // 5. دوال مساعدة داخلية (Internal Helpers)
    // =========================================================================

    /**
     * معالجة الإضافة للمخزون (Stock IN)
     */
    private function processStockIn(
        Database $db, int $productId, int $warehouseId, float $quantity, ?float $unitCost, ?float $totalCost,
        string $movementType, int $referenceId, int $userId, ?string $batchNumber = null, ?string $expiryDate = null, ?int $fromWarehouseId = null
    ): void {
        $balance = $this->getStockBalance($db, $productId, $warehouseId);
        $currentQty = (float)($balance['quantity'] ?? 0);
        $currentReserved = (float)($balance['reserved_quantity'] ?? 0);
        
        $newQty = $currentQty + $quantity;
        $movementNumber = $this->generateMovementNumber($movementType);

        // 1. تسجيل الحركة
        $db->execute("
            INSERT INTO stock_movements (
                movement_number, product_id, warehouse_id, from_warehouse_id, to_warehouse_id,
                movement_type, quantity, unit_cost, total_cost, balance_before, balance_after,
                reserved_before, reserved_after, reference_type, reference_id, batch_number, expiry_date, user_id, movement_date
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )", [
                $movementNumber, $productId, $warehouseId, $fromWarehouseId, $warehouseId,
                $movementType, $quantity, $unitCost, $totalCost, $currentQty, $newQty,
                $currentReserved, $currentReserved, strtolower($movementType), $referenceId, $batchNumber, $expiryDate, $userId
            ]);

        $movementId = (int)$db->lastInsertId();

        // 2. تحديث الرصيد
        $this->updateBalance($db, $productId, $warehouseId, $quantity, 0, $movementId);
    }

    /**
     * معالجة السحب من المخزون (Stock OUT)
     */
    private function processStockOut(
        Database $db, int $productId, int $warehouseId, float $quantity, ?float $unitCost, ?float $totalCost,
        string $movementType, int $referenceId, int $userId, ?int $toWarehouseId = null
    ): void {
        $balance = $this->getStockBalance($db, $productId, $warehouseId);
        $currentQty = (float)($balance['quantity'] ?? 0);
        $currentReserved = (float)($balance['reserved_quantity'] ?? 0);
        
        $newQty = $currentQty - $quantity;
        $movementNumber = $this->generateMovementNumber($movementType);

        // 1. تسجيل الحركة
        $db->execute("
            INSERT INTO stock_movements (
                movement_number, product_id, warehouse_id, from_warehouse_id, to_warehouse_id,
                movement_type, quantity, unit_cost, total_cost, balance_before, balance_after,
                reserved_before, reserved_after, reference_type, reference_id, user_id, movement_date
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )", [
                $movementNumber, $productId, $warehouseId, $warehouseId, $toWarehouseId,
                $movementType, $quantity, $unitCost, $totalCost, $currentQty, $newQty,
                $currentReserved, $currentReserved, strtolower($movementType), $referenceId, $userId
            ]);

        $movementId = (int)$db->lastInsertId();

        // 2. تحديث الرصيد (طرح الكمية)
        $this->updateBalance($db, $productId, $warehouseId, -$quantity, 0, $movementId);
    }

    /**
     * جلب الرصيد الحالي مع قفل الصف (Pessimistic Locking)
     */
    private function getStockBalance(Database $db, int $productId, int $warehouseId): array
    {
        $balance = $db->fetch("
            SELECT quantity, reserved_quantity, available_quantity 
            FROM stock_balances 
            WHERE product_id = ? AND warehouse_id = ? 
            FOR UPDATE", 
            [$productId, $warehouseId]
        );

        return $balance ?: ['quantity' => 0, 'reserved_quantity' => 0, 'available_quantity' => 0];
    }

    /**
     * تحديث جدول الأرصدة وربطه بآخر حركة
     */
    private function updateBalance(Database $db, int $productId, int $warehouseId, float $qtyChange, float $reservedChange, int $movementId): void
    {
        $db->execute("
            INSERT INTO stock_balances (product_id, warehouse_id, quantity, reserved_quantity, last_movement_id, last_movement_date)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                quantity = quantity + VALUES(quantity),
                reserved_quantity = reserved_quantity + VALUES(reserved_quantity),
                last_movement_id = VALUES(last_movement_id),
                last_movement_date = NOW(),
                updated_at = NOW()
        ", [$productId, $warehouseId, $qtyChange, $reservedChange, $movementId]);
    }

    /**
     * توليد رقم حركة فريد
     */
    private function generateMovementNumber(string $type): string
    {
        $prefix = match($type) {
            'RECEIPT' => 'RCV',
            'ISSUE' => 'ISS',
            'TRANSFER_IN', 'TRANSFER_OUT' => 'TRF',
            'COUNT_CORRECTION' => 'CNT',
            default => 'MOV'
        };
        
        return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }
}
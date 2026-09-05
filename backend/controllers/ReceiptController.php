<?php

/**
 * ================================================================
 * Logistox - Receipt Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/ReceiptController.php
 * الوظيفة: إدارة إذونات الاستلام (CRUD + Approval Workflow)
 *
 * دورة حياة الإذن:
 * pending → approved → completed
 * pending → cancelled
 *
 * الصلاحيات المطلوبة:
 * - receipts.view: عرض الإذونات
 * - receipts.create: إنشاء إذن
 * - receipts.update: تعديل إذن
 * - receipts.delete: حذف إذن
 * - receipts.approve: اعتماد إذن ← الأهم!
 *
 * ملاحظات هامة:
 * - يعتمد على ReceiptService لتنفيذ منطق الأعمال
 * - يعتمد على InventoryService لتحديث المخزون عند الاعتماد
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يمنع تعديل/حذف إذن معتمد أو مكتمل
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\ReceiptService;
use App\Services\InventoryService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class ReceiptController
 *
 * Controller لإدارة إذونات الاستلام
 */
class ReceiptController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var ReceiptService خدمة إذونات الاستلام
     */
    private ReceiptService $receiptService;

    /**
     * @var InventoryService محرك المخزون
     */
    private InventoryService $inventoryService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var array حالات الإذن المسموحة
     */
    private const ALLOWED_STATUSES = ['pending', 'approved', 'completed', 'cancelled'];

    /**
     * @var array أسماء الحالات بالعربية
     */
    private const STATUS_LABELS = [
        'pending'   => 'قيد الانتظار',
        'approved'  => 'معتمد',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغى',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->inventoryService = new InventoryService($this->db);
            $this->receiptService = new ReceiptService($this->db, $this->inventoryService);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[RECEIPT_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة إذونات الاستلام');
        }
    }

    // =========================================================================
    // 1. عرض قائمة الإذونات (Index)
    // =========================================================================

    /**
     * عرض قائمة إذونات الاستلام مع الفلاتر
     *
     * GET /api/receipts
     *
     * Query Parameters:
     * - search: بحث في receipt_number, supplier_invoice, notes
     * - status: تصفية حسب الحالة (pending, approved, completed, cancelled)
     * - warehouse_id: تصفية حسب المخزن
     * - supplier_id: تصفية حسب المورد
     * - from_date: من تاريخ (YYYY-MM-DD)
     * - to_date: إلى تاريخ (YYYY-MM-DD)
     * - sort_by: ترتيب حسب (receipt_number, created_at, status, total_cost, total_quantity)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            // 1. قراءة Query Parameters
            $filters = [
                'search'       => trim($_GET['search'] ?? ''),
                'status'       => $_GET['status'] ?? null,
                'warehouse_id' => !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null,
                'supplier_id'  => !empty($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null,
                'from_date'    => $_GET['from_date'] ?? null,
                'to_date'      => $_GET['to_date'] ?? null,
                'sort_by'      => $_GET['sort_by'] ?? 'created_at',
                'sort_order'   => strtolower($_GET['sort_order'] ?? 'desc'),
            ];

            // 2. جلب البيانات
            $receipts = $this->receiptService->list($filters);

            // 3. إضافة أسماء الحالات بالعربية
            foreach ($receipts as &$receipt) {
                $receipt['status_label'] = self::STATUS_LABELS[$receipt['status']] ?? $receipt['status'];
            }

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب قائمة إذونات الاستلام بنجاح',
                data: [
                    'count'    => count($receipts),
                    'receipts' => $receipts,
                    'statuses' => self::STATUS_LABELS,
                ]
            );

        } catch (Throwable $e) {
            error_log('[RECEIPT_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة إذونات الاستلام');
        }
    }

    // =========================================================================
    // 2. إضافة إذن استلام جديد (Store)
    // =========================================================================

    /**
     * إضافة إذن استلام جديد
     *
     * POST /api/receipts
     *
     * Request Body (JSON):
     * {
     *   "warehouse_id": 1,
     *   "supplier_id": 1,
     *   "supplier_invoice": "INV-2026-001",
     *   "notes": "استلام شحنة لحوم",
     *   "items": [
     *     {
     *       "product_id": 1,
     *       "quantity": 100,
     *       "unit_cost": 150.50,
     *       "batch_number": "BATCH-001",
     *       "expiry_date": "2027-01-01",
     *       "notes": ""
     *     }
     *   ]
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function store(): void
    {
        try {
            // 1. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 2. التحقق من البيانات الأساسية
            $validationErrors = $this->validateReceiptData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات الإذن غير صالحة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إضافة الإذن
            $receiptId = $this->receiptService->create($input, $userId);

            // 5. جلب بيانات الإذن المضاف
            $receipt = $this->receiptService->getById($receiptId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RECEIPT_CREATE',
                entityType: 'receipt',
                entityId: $receiptId,
                newValues: [
                    'receipt_number' => $receipt['receipt_number'],
                    'warehouse_id'   => $receipt['warehouse_id'],
                    'supplier_id'    => $receipt['supplier_id'],
                    'total_items'    => $receipt['total_items'],
                    'total_quantity' => $receipt['total_quantity'],
                    'total_cost'     => $receipt['total_cost'],
                ],
                description: "تم إنشاء إذن استلام جديد: {$receipt['receipt_number']} ({$receipt['total_items']} بند)",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::created(
                message: 'تم إنشاء إذن الاستلام بنجاح',
                data: ['receipt' => $receipt],
                location: "/api/receipts/{$receiptId}"
            );

        } catch (Throwable $e) {
            error_log('[RECEIPT_CONTROLLER] Store failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'غير موجود')) {
                Response::notFound($e->getMessage());
            }

            Response::internalError('فشل في إنشاء إذن الاستلام: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. عرض تفاصيل إذن (Show)
    // =========================================================================

    /**
     * عرض تفاصيل إذن استلام معين
     *
     * GET /api/receipts/{id}
     *
     * @param array $params المعاملات من Router (مثل ['id' => 5])
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            // 1. التحقق من معرف الإذن
            $receiptId = $this->validateReceiptId($params);

            // 2. جلب بيانات الإذن
            $receipt = $this->receiptService->getById($receiptId);

            if (!$receipt) {
                Response::notFound('الإذن غير موجود');
            }

            // 3. إضافة اسم الحالة بالعربية
            $receipt['status_label'] = self::STATUS_LABELS[$receipt['status']] ?? $receipt['status'];

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب تفاصيل الإذن بنجاح',
                data: [
                    'receipt' => $receipt,
                ]
            );

        } catch (Throwable $e) {
            error_log('[RECEIPT_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل الإذن');
        }
    }

    // =========================================================================
    // 4. تعديل إذن (Update)
    // =========================================================================

    /**
     * تعديل إذن استلام (فقط إذا كان pending)
     *
     * PUT /api/receipts/{id}
     * PATCH /api/receipts/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            // 1. التحقق من معرف الإذن
            $receiptId = $this->validateReceiptId($params);

            // 2. جلب البيانات القديمة (للتدقيق)
            $oldReceipt = $this->receiptService->getById($receiptId);
            if (!$oldReceipt) {
                Response::notFound('الإذن غير موجود');
            }

            // 3. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 4. التحقق من البيانات
            $validationErrors = $this->validateReceiptData($input, isNew: false);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // 5. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 6. تعديل الإذن
            $this->receiptService->update($receiptId, $input, $userId);

            // 7. جلب البيانات الجديدة
            $newReceipt = $this->receiptService->getById($receiptId);

            // 8. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RECEIPT_UPDATE',
                entityType: 'receipt',
                entityId: $receiptId,
                oldValues: [
                    'receipt_number' => $oldReceipt['receipt_number'],
                    'total_items'    => $oldReceipt['total_items'],
                    'total_quantity' => $oldReceipt['total_quantity'],
                    'total_cost'     => $oldReceipt['total_cost'],
                ],
                newValues: [
                    'receipt_number' => $newReceipt['receipt_number'],
                    'total_items'    => $newReceipt['total_items'],
                    'total_quantity' => $newReceipt['total_quantity'],
                    'total_cost'     => $newReceipt['total_cost'],
                ],
                description: "تم تعديل إذن الاستلام: {$newReceipt['receipt_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 9. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تعديل الإذن بنجاح',
                data: ['receipt' => $newReceipt]
            );

        } catch (Throwable $e) {
            error_log('[RECEIPT_CONTROLLER] Update failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'لا يمكن تعديل')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'غير موجود')) {
                Response::notFound($e->getMessage());
            }

            Response::internalError('فشل في تعديل الإذن: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 5. اعتماد إذن (Approve) - الأهم!
    // =========================================================================

    /**
     * اعتماد إذن استلام وتحديث المخزون
     *
     * POST /api/receipts/{id}/approve
     *
     * هذه العملية:
     * 1. تتحقق من حالة الإذن (pending فقط)
     * 2. تستدعي InventoryService لتحديث المخزون
     * 3. يتم إنشاء حركات في stock_movements
     * 4. يتم تحديث stock_balances
     * 5. يتم تغيير حالة الإذن إلى approved
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function approve(array $params): void
    {
        try {
            // 1. التحقق من معرف الإذن
            $receiptId = $this->validateReceiptId($params);

            // 2. جلب بيانات الإذن
            $receipt = $this->receiptService->getById($receiptId);
            if (!$receipt) {
                Response::notFound('الإذن غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. اعتماد الإذن (يستدعي InventoryService داخلياً)
            $result = $this->receiptService->approve($receiptId, $userId);

            // 5. جلب البيانات المحدثة
            $updatedReceipt = $this->receiptService->getById($receiptId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RECEIPT_APPROVE',
                entityType: 'receipt',
                entityId: $receiptId,
                newValues: [
                    'receipt_number' => $updatedReceipt['receipt_number'],
                    'status'         => $updatedReceipt['status'],
                    'total_items'    => $updatedReceipt['total_items'],
                    'total_quantity' => $updatedReceipt['total_quantity'],
                    'total_cost'     => $updatedReceipt['total_cost'],
                ],
                description: "تم اعتماد إذن الاستلام: {$updatedReceipt['receipt_number']} - " .
                             "تم تحديث المخزون ({$updatedReceipt['total_items']} بند، " .
                             "{$updatedReceipt['total_quantity']} وحدة)",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم اعتماد الإذن وتحديث المخزون بنجاح',
                data: [
                    'receipt'         => $updatedReceipt,
                    'items_processed' => $result['items_processed'] ?? $updatedReceipt['total_items'],
                ]
            );

        } catch (Throwable $e) {
            error_log('[RECEIPT_CONTROLLER] Approve failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'لا يمكن اعتماد')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'رصيد غير كافٍ')) {
                Response::badRequest($e->getMessage());
            }

            Response::internalError('فشل في اعتماد الإذن: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 6. إلغاء إذن (Cancel)
    // =========================================================================

    /**
     * إلغاء إذن استلام
     *
     * POST /api/receipts/{id}/cancel
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function cancel(array $params): void
    {
        try {
            // 1. التحقق من معرف الإذن
            $receiptId = $this->validateReceiptId($params);

            // 2. جلب بيانات الإذن
            $receipt = $this->receiptService->getById($receiptId);
            if (!$receipt) {
                Response::notFound('الإذن غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إلغاء الإذن
            $this->receiptService->cancel($receiptId, $userId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RECEIPT_CANCEL',
                entityType: 'receipt',
                entityId: $receiptId,
                oldValues: ['status' => $receipt['status']],
                newValues: ['status' => 'cancelled'],
                description: "تم إلغاء إذن الاستلام: {$receipt['receipt_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم إلغاء الإذن بنجاح',
                data: null
            );

        } catch (Throwable $e) {
            error_log('[RECEIPT_CONTROLLER] Cancel failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن إلغاء')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في إلغاء الإذن: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 7. حذف إذن (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف إذن استلام (فقط إذا كان pending)
     *
     * DELETE /api/receipts/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            // 1. التحقق من معرف الإذن
            $receiptId = $this->validateReceiptId($params);

            // 2. جلب بيانات الإذن
            $receipt = $this->receiptService->getById($receiptId);
            if (!$receipt) {
                Response::notFound('الإذن غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. حذف الإذن (Soft Delete)
            $this->receiptService->delete($receiptId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RECEIPT_DELETE',
                entityType: 'receipt',
                entityId: $receiptId,
                oldValues: [
                    'receipt_number' => $receipt['receipt_number'],
                    'status'         => $receipt['status'],
                    'total_items'    => $receipt['total_items'],
                    'total_quantity' => $receipt['total_quantity'],
                ],
                description: "تم حذف إذن الاستلام (Soft Delete): {$receipt['receipt_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم حذف الإذن بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[RECEIPT_CONTROLLER] Destroy failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن حذف')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف الإذن');
        }
    }

    // =========================================================================
    // Helper Methods (دوال مساعدة)
    // =========================================================================

    /**
     * قراءة مدخلات JSON من جسم الطلب
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }

        $decoded = json_decode($input, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * التحقق من صحة معرف الإذن
     */
    private function validateReceiptId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف الإذن غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات الإذن
     */
    private function validateReceiptData(array $data, bool $isNew = true): array
    {
        $errors = [];

        // 1. التحقق من warehouse_id (مطلوب)
        if (empty($data['warehouse_id'])) {
            $errors['warehouse_id'] = 'المخزن مطلوب';
        } elseif (!is_numeric($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
            $errors['warehouse_id'] = 'معرف المخزن غير صالح';
        }

        // 2. التحقق من supplier_id (اختياري)
        if (isset($data['supplier_id']) && $data['supplier_id'] !== null && $data['supplier_id'] !== '') {
            if (!is_numeric($data['supplier_id']) || (int) $data['supplier_id'] <= 0) {
                $errors['supplier_id'] = 'معرف المورد غير صالح';
            }
        }

        // 3. التحقق من supplier_invoice (اختياري)
        if (!empty($data['supplier_invoice']) && strlen($data['supplier_invoice']) > 100) {
            $errors['supplier_invoice'] = 'رقم فاتورة المورد يجب ألا يتجاوز 100 حرف';
        }

        // 4. التحقق من notes (اختياري)
        if (!empty($data['notes']) && strlen($data['notes']) > 2000) {
            $errors['notes'] = 'الملاحظات يجب ألا تتجاوز 2000 حرف';
        }

        // 5. التحقق من البنود (مطلوبة)
        if ($isNew) {
            if (empty($data['items']) || !is_array($data['items'])) {
                $errors['items'] = 'يجب إضافة بند واحد على الأقل';
            } elseif (count($data['items']) === 0) {
                $errors['items'] = 'يجب إضافة بند واحد على الأقل';
            }
        }

        // 6. التحقق من كل بند (إذا تم تقديمها)
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $index => $item) {
                $itemErrors = $this->validateReceiptItem($item, $index);
                if (!empty($itemErrors)) {
                    $errors["items.{$index}"] = $itemErrors;
                }
            }
        }

        return $errors;
    }

    /**
     * التحقق من صحة بند في الإذن
     */
    private function validateReceiptItem(array $item, int $index): array
    {
        $errors = [];

        // 1. product_id (مطلوب)
        if (empty($item['product_id'])) {
            $errors['product_id'] = 'معرف المنتج مطلوب';
        } elseif (!is_numeric($item['product_id']) || (int) $item['product_id'] <= 0) {
            $errors['product_id'] = 'معرف المنتج غير صالح';
        }

        // 2. quantity (مطلوب، موجب)
        if (!isset($item['quantity']) || $item['quantity'] === null || $item['quantity'] === '') {
            $errors['quantity'] = 'الكمية مطلوبة';
        } elseif (!is_numeric($item['quantity']) || (float) $item['quantity'] <= 0) {
            $errors['quantity'] = 'الكمية يجب أن تكون رقماً موجباً';
        }

        // 3. unit_cost (اختياري، غير سالب)
        if (isset($item['unit_cost']) && $item['unit_cost'] !== null && $item['unit_cost'] !== '') {
            if (!is_numeric($item['unit_cost']) || (float) $item['unit_cost'] < 0) {
                $errors['unit_cost'] = 'سعر الوحدة يجب أن يكون رقماً غير سالب';
            }
        }

        // 4. batch_number (اختياري)
        if (!empty($item['batch_number']) && strlen($item['batch_number']) > 100) {
            $errors['batch_number'] = 'رقم اللوتة يجب ألا يتجاوز 100 حرف';
        }

        // 5. expiry_date (اختياري)
        if (!empty($item['expiry_date'])) {
            $dateTime = \DateTime::createFromFormat('Y-m-d', $item['expiry_date']);
            if (!$dateTime || $dateTime->format('Y-m-d') !== $item['expiry_date']) {
                $errors['expiry_date'] = 'تاريخ الصلاحية غير صالح. الصيغة: YYYY-MM-DD';
            }
        }

        // 6. notes (اختياري)
        if (!empty($item['notes']) && strlen($item['notes']) > 1000) {
            $errors['notes'] = 'الملاحظات يجب ألا تتجاوز 1000 حرف';
        }

        return $errors;
    }

    /**
     * جلب معرف المستخدم الحالي
     */
    private function getCurrentUserId(): int
    {
        if (isset($_REQUEST['user']['id'])) {
            return (int) $_REQUEST['user']['id'];
        }

        if (isset($GLOBALS['current_user_id'])) {
            return (int) $GLOBALS['current_user_id'];
        }

        error_log('[RECEIPT_CONTROLLER] Current user ID not found');
        Response::unauthorized('لم يتم العثور على بيانات المستخدم. يرجى تسجيل الدخول مرة أخرى.');
    }

    /**
     * جلب IP العميل
     */
    private function getClientIp(): string
    {
        $trustProxy = filter_var(
            getenv('TRUST_PROXY') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );

        if ($trustProxy) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $firstIp = trim($ips[0]);
                if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                    return $firstIp;
                }
            }

            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = trim($_SERVER['HTTP_X_REAL_IP']);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = trim($_SERVER['REMOTE_ADDR']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }
}
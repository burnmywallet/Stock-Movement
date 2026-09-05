<?php

/**
 * ================================================================
 * Logistox - Transfer Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/TransferController.php
 * الوظيفة: إدارة التحويلات المخزنية (CRUD + Approval Workflow)
 *
 * دورة حياة التحويل:
 * pending → approved → completed
 * pending → cancelled
 *
 * الصلاحيات المطلوبة:
 * - transfers.view: عرض التحويلات
 * - transfers.create: إنشاء تحويل
 * - transfers.update: تعديل تحويل
 * - transfers.delete: حذف تحويل
 * - transfers.approve: اعتماد تحويل ← الأهم!
 *
 * ملاحظات هامة:
 * - يعتمد على TransferService لتنفيذ منطق الأعمال
 * - يعتمد على InventoryService لتحديث المخزون عند الاعتماد
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يمنع تعديل/حذف تحويل معتمد أو مكتمل
 * - يتحقق من توفر الرصيد في المخزن المصدر قبل الاعتماد
 * - يمنع التحويل من مخزن إلى نفسه
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\TransferService;
use App\Services\InventoryService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class TransferController
 *
 * Controller لإدارة التحويلات المخزنية
 */
class TransferController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var TransferService خدمة التحويلات
     */
    private TransferService $transferService;

    /**
     * @var InventoryService محرك المخزون
     */
    private InventoryService $inventoryService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var array حالات التحويل المسموحة
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
            $this->transferService = new TransferService($this->db, $this->inventoryService);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[TRANSFER_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة التحويلات');
        }
    }

    // =========================================================================
    // 1. عرض قائمة التحويلات (Index)
    // =========================================================================

    /**
     * عرض قائمة التحويلات المخزنية مع الفلاتر
     *
     * GET /api/transfers
     *
     * Query Parameters:
     * - search: بحث في transfer_number, transfer_reason, notes
     * - status: تصفية حسب الحالة (pending, approved, completed, cancelled)
     * - from_warehouse_id: تصفية حسب المخزن المصدر
     * - to_warehouse_id: تصفية حسب المخزن الوجهة
     * - from_date: من تاريخ (YYYY-MM-DD)
     * - to_date: إلى تاريخ (YYYY-MM-DD)
     * - sort_by: ترتيب حسب (transfer_number, created_at, status, total_cost, total_quantity)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            // 1. قراءة Query Parameters
            $filters = [
                'search'             => trim($_GET['search'] ?? ''),
                'status'             => $_GET['status'] ?? null,
                'from_warehouse_id'  => !empty($_GET['from_warehouse_id']) ? (int) $_GET['from_warehouse_id'] : null,
                'to_warehouse_id'    => !empty($_GET['to_warehouse_id']) ? (int) $_GET['to_warehouse_id'] : null,
                'from_date'          => $_GET['from_date'] ?? null,
                'to_date'            => $_GET['to_date'] ?? null,
                'sort_by'            => $_GET['sort_by'] ?? 'created_at',
                'sort_order'         => strtolower($_GET['sort_order'] ?? 'desc'),
            ];

            // 2. جلب البيانات
            $transfers = $this->transferService->list($filters);

            // 3. إضافة أسماء الحالات بالعربية
            foreach ($transfers as &$transfer) {
                $transfer['status_label'] = self::STATUS_LABELS[$transfer['status']] ?? $transfer['status'];
            }

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب قائمة التحويلات بنجاح',
                data: [
                    'count'     => count($transfers),
                    'transfers' => $transfers,
                    'statuses'  => self::STATUS_LABELS,
                ]
            );

        } catch (Throwable $e) {
            error_log('[TRANSFER_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة التحويلات');
        }
    }

    // =========================================================================
    // 2. إضافة تحويل جديد (Store)
    // =========================================================================

    /**
     * إضافة تحويل مخزني جديد
     *
     * POST /api/transfers
     *
     * Request Body (JSON):
     * {
     *   "from_warehouse_id": 1,
     *   "to_warehouse_id": 2,
     *   "transfer_reason": "نقل للإنتاج",
     *   "notes": "تحويل لحوم من المخزن الرئيسي إلى مخزن الإنتاج",
     *   "items": [
     *     {
     *       "product_id": 1,
     *       "quantity": 50,
     *       "unit_cost": 150.50,
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
            $validationErrors = $this->validateTransferData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التحويل غير صالحة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إضافة التحويل
            $transferId = $this->transferService->create($input, $userId);

            // 5. جلب بيانات التحويل المضاف
            $transfer = $this->transferService->getById($transferId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'TRANSFER_CREATE',
                entityType: 'transfer',
                entityId: $transferId,
                newValues: [
                    'transfer_number'  => $transfer['transfer_number'],
                    'from_warehouse_id'=> $transfer['from_warehouse_id'],
                    'to_warehouse_id'  => $transfer['to_warehouse_id'],
                    'total_items'      => $transfer['total_items'],
                    'total_quantity'   => $transfer['total_quantity'],
                    'total_cost'       => $transfer['total_cost'],
                ],
                description: "تم إنشاء تحويل مخزني جديد: {$transfer['transfer_number']} " .
                             "(من {$transfer['from_warehouse_name']} إلى {$transfer['to_warehouse_name']}) - " .
                             "{$transfer['total_items']} بند",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::created(
                message: 'تم إنشاء التحويل المخزني بنجاح',
                data: ['transfer' => $transfer],
                location: "/api/transfers/{$transferId}"
            );

        } catch (Throwable $e) {
            error_log('[TRANSFER_CONTROLLER] Store failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'غير موجود')) {
                Response::notFound($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'مخزن إلى نفسه')) {
                Response::badRequest($e->getMessage());
            }

            Response::internalError('فشل في إنشاء التحويل: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. عرض تفاصيل تحويل (Show)
    // =========================================================================

    /**
     * عرض تفاصيل تحويل مخزني معين
     *
     * GET /api/transfers/{id}
     *
     * @param array $params المعاملات من Router (مثل ['id' => 5])
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            // 1. التحقق من معرف التحويل
            $transferId = $this->validateTransferId($params);

            // 2. جلب بيانات التحويل
            $transfer = $this->transferService->getById($transferId);

            if (!$transfer) {
                Response::notFound('التحويل غير موجود');
            }

            // 3. إضافة اسم الحالة بالعربية
            $transfer['status_label'] = self::STATUS_LABELS[$transfer['status']] ?? $transfer['status'];

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب تفاصيل التحويل بنجاح',
                data: [
                    'transfer' => $transfer,
                ]
            );

        } catch (Throwable $e) {
            error_log('[TRANSFER_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل التحويل');
        }
    }

    // =========================================================================
    // 4. تعديل تحويل (Update)
    // =========================================================================

    /**
     * تعديل تحويل مخزني (فقط إذا كان pending)
     *
     * PUT /api/transfers/{id}
     * PATCH /api/transfers/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            // 1. التحقق من معرف التحويل
            $transferId = $this->validateTransferId($params);

            // 2. جلب البيانات القديمة (للتدقيق)
            $oldTransfer = $this->transferService->getById($transferId);
            if (!$oldTransfer) {
                Response::notFound('التحويل غير موجود');
            }

            // 3. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 4. التحقق من البيانات
            $validationErrors = $this->validateTransferData($input, isNew: false);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // 5. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 6. تعديل التحويل
            $this->transferService->update($transferId, $input, $userId);

            // 7. جلب البيانات الجديدة
            $newTransfer = $this->transferService->getById($transferId);

            // 8. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'TRANSFER_UPDATE',
                entityType: 'transfer',
                entityId: $transferId,
                oldValues: [
                    'transfer_number'  => $oldTransfer['transfer_number'],
                    'from_warehouse_id'=> $oldTransfer['from_warehouse_id'],
                    'to_warehouse_id'  => $oldTransfer['to_warehouse_id'],
                    'total_items'      => $oldTransfer['total_items'],
                    'total_quantity'   => $oldTransfer['total_quantity'],
                    'total_cost'       => $oldTransfer['total_cost'],
                ],
                newValues: [
                    'transfer_number'  => $newTransfer['transfer_number'],
                    'from_warehouse_id'=> $newTransfer['from_warehouse_id'],
                    'to_warehouse_id'  => $newTransfer['to_warehouse_id'],
                    'total_items'      => $newTransfer['total_items'],
                    'total_quantity'   => $newTransfer['total_quantity'],
                    'total_cost'       => $newTransfer['total_cost'],
                ],
                description: "تم تعديل التحويل: {$newTransfer['transfer_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 9. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تعديل التحويل بنجاح',
                data: ['transfer' => $newTransfer]
            );

        } catch (Throwable $e) {
            error_log('[TRANSFER_CONTROLLER] Update failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'لا يمكن تعديل')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'مخزن إلى نفسه')) {
                Response::badRequest($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'غير موجود')) {
                Response::notFound($e->getMessage());
            }

            Response::internalError('فشل في تعديل التحويل: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 5. اعتماد تحويل (Approve) - الأهم!
    // =========================================================================

    /**
     * اعتماد تحويل مخزني وتحديث المخزون
     *
     * POST /api/transfers/{id}/approve
     *
     * هذه العملية:
     * 1. تتحقق من حالة التحويل (pending فقط)
     * 2. تتحقق من توفر الرصيد في المخزن المصدر لكل بند
     * 3. تستدعي InventoryService لتحديث المخزون
     * 4. يتم إنشاء حركتين في stock_movements:
     *    - TRANSFER_OUT من المخزن المصدر
     *    - TRANSFER_IN إلى المخزن الوجهة
     * 5. يتم تحديث stock_balances للمخزنين
     * 6. يتم تغيير حالة التحويل إلى approved
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function approve(array $params): void
    {
        try {
            // 1. التحقق من معرف التحويل
            $transferId = $this->validateTransferId($params);

            // 2. جلب بيانات التحويل
            $transfer = $this->transferService->getById($transferId);
            if (!$transfer) {
                Response::notFound('التحويل غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. اعتماد التحويل (يستدعي InventoryService داخلياً)
            $result = $this->transferService->approve($transferId, $userId);

            // 5. جلب البيانات المحدثة
            $updatedTransfer = $this->transferService->getById($transferId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'TRANSFER_APPROVE',
                entityType: 'transfer',
                entityId: $transferId,
                newValues: [
                    'transfer_number'  => $updatedTransfer['transfer_number'],
                    'status'           => $updatedTransfer['status'],
                    'from_warehouse_id'=> $updatedTransfer['from_warehouse_id'],
                    'to_warehouse_id'  => $updatedTransfer['to_warehouse_id'],
                    'total_items'      => $updatedTransfer['total_items'],
                    'total_quantity'   => $updatedTransfer['total_quantity'],
                    'total_cost'       => $updatedTransfer['total_cost'],
                ],
                description: "تم اعتماد التحويل: {$updatedTransfer['transfer_number']} - " .
                             "تم نقل {$updatedTransfer['total_items']} بند " .
                             "(إجمالي {$updatedTransfer['total_quantity']} وحدة) " .
                             "من {$updatedTransfer['from_warehouse_name']} " .
                             "إلى {$updatedTransfer['to_warehouse_name']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم اعتماد التحويل وتحديث المخزون بنجاح',
                data: [
                    'transfer'        => $updatedTransfer,
                    'items_processed' => $result['items_processed'] ?? $updatedTransfer['total_items'],
                ]
            );

        } catch (Throwable $e) {
            error_log('[TRANSFER_CONTROLLER] Approve failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'لا يمكن اعتماد')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'رصيد غير كافٍ')) {
                Response::badRequest($e->getMessage());
            }

            Response::internalError('فشل في اعتماد التحويل: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 6. إلغاء تحويل (Cancel)
    // =========================================================================

    /**
     * إلغاء تحويل مخزني
     *
     * POST /api/transfers/{id}/cancel
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function cancel(array $params): void
    {
        try {
            // 1. التحقق من معرف التحويل
            $transferId = $this->validateTransferId($params);

            // 2. جلب بيانات التحويل
            $transfer = $this->transferService->getById($transferId);
            if (!$transfer) {
                Response::notFound('التحويل غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إلغاء التحويل
            $this->transferService->cancel($transferId, $userId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'TRANSFER_CANCEL',
                entityType: 'transfer',
                entityId: $transferId,
                oldValues: ['status' => $transfer['status']],
                newValues: ['status' => 'cancelled'],
                description: "تم إلغاء التحويل: {$transfer['transfer_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم إلغاء التحويل بنجاح',
                data: null
            );

        } catch (Throwable $e) {
            error_log('[TRANSFER_CONTROLLER] Cancel failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن إلغاء')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في إلغاء التحويل: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 7. حذف تحويل (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف تحويل مخزني (فقط إذا كان pending)
     *
     * DELETE /api/transfers/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            // 1. التحقق من معرف التحويل
            $transferId = $this->validateTransferId($params);

            // 2. جلب بيانات التحويل
            $transfer = $this->transferService->getById($transferId);
            if (!$transfer) {
                Response::notFound('التحويل غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. حذف التحويل (Soft Delete)
            $this->transferService->delete($transferId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'TRANSFER_DELETE',
                entityType: 'transfer',
                entityId: $transferId,
                oldValues: [
                    'transfer_number'  => $transfer['transfer_number'],
                    'status'           => $transfer['status'],
                    'from_warehouse_id'=> $transfer['from_warehouse_id'],
                    'to_warehouse_id'  => $transfer['to_warehouse_id'],
                    'total_items'      => $transfer['total_items'],
                    'total_quantity'   => $transfer['total_quantity'],
                ],
                description: "تم حذف التحويل (Soft Delete): {$transfer['transfer_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم حذف التحويل بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[TRANSFER_CONTROLLER] Destroy failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن حذف')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف التحويل');
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
     * التحقق من صحة معرف التحويل
     */
    private function validateTransferId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف التحويل غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات التحويل
     */
    private function validateTransferData(array $data, bool $isNew = true): array
    {
        $errors = [];

        // 1. التحقق من from_warehouse_id (مطلوب)
        if (empty($data['from_warehouse_id'])) {
            $errors['from_warehouse_id'] = 'المخزن المصدر مطلوب';
        } elseif (!is_numeric($data['from_warehouse_id']) || (int) $data['from_warehouse_id'] <= 0) {
            $errors['from_warehouse_id'] = 'معرف المخزن المصدر غير صالح';
        }

        // 2. التحقق من to_warehouse_id (مطلوب)
        if (empty($data['to_warehouse_id'])) {
            $errors['to_warehouse_id'] = 'المخزن الوجهة مطلوب';
        } elseif (!is_numeric($data['to_warehouse_id']) || (int) $data['to_warehouse_id'] <= 0) {
            $errors['to_warehouse_id'] = 'معرف المخزن الوجهة غير صالح';
        }

        // 3. التحقق من أن المخزن المصدر ≠ المخزن الوجهة
        if (!empty($data['from_warehouse_id']) && !empty($data['to_warehouse_id'])) {
            if ((int) $data['from_warehouse_id'] === (int) $data['to_warehouse_id']) {
                $errors['to_warehouse_id'] = 'لا يمكن التحويل من مخزن إلى نفسه. يرجى اختيار مخزن وجهة مختلف.';
            }
        }

        // 4. التحقق من transfer_reason (اختياري)
        if (!empty($data['transfer_reason']) && strlen($data['transfer_reason']) > 500) {
            $errors['transfer_reason'] = 'سبب التحويل يجب ألا يتجاوز 500 حرف';
        }

        // 5. التحقق من notes (اختياري)
        if (!empty($data['notes']) && strlen($data['notes']) > 2000) {
            $errors['notes'] = 'الملاحظات يجب ألا تتجاوز 2000 حرف';
        }

        // 6. التحقق من البنود (مطلوبة)
        if ($isNew) {
            if (empty($data['items']) || !is_array($data['items'])) {
                $errors['items'] = 'يجب إضافة بند واحد على الأقل';
            } elseif (count($data['items']) === 0) {
                $errors['items'] = 'يجب إضافة بند واحد على الأقل';
            }
        }

        // 7. التحقق من كل بند (إذا تم تقديمها)
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $index => $item) {
                $itemErrors = $this->validateTransferItem($item, $index);
                if (!empty($itemErrors)) {
                    $errors["items.{$index}"] = $itemErrors;
                }
            }
        }

        return $errors;
    }

    /**
     * التحقق من صحة بند في التحويل
     */
    private function validateTransferItem(array $item, int $index): array
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

        // 4. notes (اختياري)
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

        error_log('[TRANSFER_CONTROLLER] Current user ID not found');
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
<?php

/**
 * ================================================================
 * Logistox - Inventory Count Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/InventoryCountController.php
 * الوظيفة: إدارة عمليات الجرد المخزني
 *
 * دورة حياة عملية الجرد:
 * draft → in_progress → completed → approved
 * draft → cancelled
 *
 * الصلاحيات المطلوبة:
 * - counts.view: عرض عمليات الجرد
 * - counts.create: إنشاء عملية جرد
 * - counts.update: تعديل عملية جرد
 * - counts.start: بدء الجرد
 * - counts.approve: اعتماد الجرد ← الأهم!
 * - counts.delete: حذف عملية جرد
 *
 * ملاحظات هامة:
 * - يعتمد على InventoryCountService لتنفيذ منطق الأعمال
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - البنود تُدار عبر endpoints منفصلة (items)
 * - عند الاعتماد، يتم تسوية الفروقات تلقائياً
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\InventoryCountService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class InventoryCountController
 *
 * Controller لإدارة عمليات الجرد المخزني
 */
class InventoryCountController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var InventoryCountService خدمة الجرد
     */
    private InventoryCountService $countService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var array حالات الجرد المسموحة
     */
    private const ALLOWED_STATUSES = ['draft', 'in_progress', 'completed', 'approved', 'cancelled'];

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->countService = new InventoryCountService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة الجرد');
        }
    }

    // =========================================================================
    // 1. عرض قائمة عمليات الجرد (Index)
    // =========================================================================

    /**
     * عرض قائمة عمليات الجرد مع الفلاتر
     *
     * GET /api/inventory-counts
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            $filters = [
                'search'       => trim($_GET['search'] ?? ''),
                'status'       => $_GET['status'] ?? null,
                'warehouse_id' => !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null,
                'from_date'    => $_GET['from_date'] ?? null,
                'to_date'      => $_GET['to_date'] ?? null,
                'sort_by'      => $_GET['sort_by'] ?? 'created_at',
                'sort_order'   => strtolower($_GET['sort_order'] ?? 'desc'),
            ];

            $counts = $this->countService->list($filters);

            Response::success(
                message: 'تم جلب قائمة عمليات الجرد بنجاح',
                data: [
                    'count'    => count($counts),
                    'counts'   => $counts,
                    'statuses' => $this->countService->getStatusLabels(),
                ]
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة عمليات الجرد');
        }
    }

    // =========================================================================
    // 2. إنشاء عملية جرد جديدة (Store)
    // =========================================================================

    /**
     * إنشاء عملية جرد جديدة (بدون بنود)
     *
     * POST /api/inventory-counts
     *
     * @return void يرسل استجابة JSON
     */
    public function store(): void
    {
        try {
            $input = $this->getJsonInput();

            $validationErrors = $this->validateCountData($input);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات عملية الجرد غير صالحة');
            }

            $userId = $this->getCurrentUserId();
            $countId = $this->countService->create($input, $userId);
            $count = $this->countService->getById($countId);

            $this->auditService->log(
                userId: $userId,
                action: 'INVENTORY_COUNT_CREATE',
                entityType: 'inventory_count',
                entityId: $countId,
                newValues: [
                    'count_number' => $count['count_number'],
                    'warehouse_id' => $count['warehouse_id'],
                    'count_date'   => $count['count_date'],
                ],
                description: "تم إنشاء عملية جرد جديدة: {$count['count_number']} في {$count['warehouse_name']} بتاريخ {$count['count_date']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::created(
                message: 'تم إنشاء عملية الجرد بنجاح',
                data: ['count' => $count],
                location: "/api/inventory-counts/{$countId}"
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] Store failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'غير موجود')) {
                Response::notFound($e->getMessage());
            }

            Response::internalError('فشل في إنشاء عملية الجرد: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. عرض تفاصيل عملية جرد (Show)
    // =========================================================================

    /**
     * عرض تفاصيل عملية جرد مع بنودها
     *
     * GET /api/inventory-counts/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            $countId = $this->validateCountId($params);
            $count = $this->countService->getById($countId);

            if (!$count) {
                Response::notFound('عملية الجرد غير موجودة');
            }

            Response::success(
                message: 'تم جلب تفاصيل عملية الجرد بنجاح',
                data: ['count' => $count]
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل عملية الجرد');
        }
    }

    // =========================================================================
    // 4. تعديل عملية جرد (Update)
    // =========================================================================

    /**
     * تعديل عملية جرد (فقط إذا كانت draft)
     *
     * PUT /api/inventory-counts/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            $countId = $this->validateCountId($params);

            $oldCount = $this->countService->getById($countId);
            if (!$oldCount) {
                Response::notFound('عملية الجرد غير موجودة');
            }

            $input = $this->getJsonInput();
            $validationErrors = $this->validateCountData($input, isNew: false);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            $userId = $this->getCurrentUserId();
            $this->countService->update($countId, $input, $userId);
            $newCount = $this->countService->getById($countId);

            $this->auditService->log(
                userId: $userId,
                action: 'INVENTORY_COUNT_UPDATE',
                entityType: 'inventory_count',
                entityId: $countId,
                oldValues: [
                    'warehouse_id' => $oldCount['warehouse_id'],
                    'count_date'   => $oldCount['count_date'],
                    'notes'        => $oldCount['notes'],
                ],
                newValues: [
                    'warehouse_id' => $newCount['warehouse_id'],
                    'count_date'   => $newCount['count_date'],
                    'notes'        => $newCount['notes'],
                ],
                description: "تم تعديل عملية الجرد: {$newCount['count_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم تعديل عملية الجرد بنجاح',
                data: ['count' => $newCount]
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] Update failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن تعديل')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في تعديل عملية الجرد: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 5. بدء الجرد (Start)
    // =========================================================================

    /**
     * بدء عملية الجرد (draft → in_progress)
     *
     * POST /api/inventory-counts/{id}/start
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function start(array $params): void
    {
        try {
            $countId = $this->validateCountId($params);

            $count = $this->countService->getById($countId);
            if (!$count) {
                Response::notFound('عملية الجرد غير موجودة');
            }

            $userId = $this->getCurrentUserId();
            $this->countService->start($countId, $userId);
            $updatedCount = $this->countService->getById($countId);

            $this->auditService->log(
                userId: $userId,
                action: 'INVENTORY_COUNT_START',
                entityType: 'inventory_count',
                entityId: $countId,
                newValues: ['status' => 'in_progress'],
                description: "تم بدء عملية الجرد: {$updatedCount['count_number']} - " .
                             "تم التقاط الأرصدة النظامية لـ {$updatedCount['total_items']} منتج",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم بدء عملية الجرد بنجاح. تم التقاط الأرصدة النظامية.',
                data: ['count' => $updatedCount]
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] Start failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن بدء')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في بدء عملية الجرد: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 6. إضافة بند للجرد (Add Item)
    // =========================================================================

    /**
     * إضافة بند (منتج) لعملية الجرد
     *
     * POST /api/inventory-counts/{id}/items
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function addItem(array $params): void
    {
        try {
            $countId = $this->validateCountId($params);

            $count = $this->countService->getById($countId);
            if (!$count) {
                Response::notFound('عملية الجرد غير موجودة');
            }

            $input = $this->getJsonInput();
            $validationErrors = $this->validateItemData($input);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات البند غير صالحة');
            }

            $userId = $this->getCurrentUserId();
            $itemId = $this->countService->addItem($countId, $input);

            // جلب البند المضاف
            $updatedCount = $this->countService->getById($countId);
            $addedItem = null;
            foreach ($updatedCount['items'] as $item) {
                if ($item['id'] === $itemId) {
                    $addedItem = $item;
                    break;
                }
            }

            $this->auditService->log(
                userId: $userId,
                action: 'INVENTORY_COUNT_ITEM_ADD',
                entityType: 'inventory_count_item',
                entityId: $itemId,
                newValues: [
                    'inventory_count_id' => $countId,
                    'product_id'         => $input['product_id'],
                    'counted_quantity'   => $input['counted_quantity'] ?? 0,
                ],
                description: "تم إضافة منتج إلى عملية الجرد: {$count['count_number']} - " .
                             "{$addedItem['product_name']} (النظامي: {$addedItem['system_quantity']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::created(
                message: 'تم إضافة البند بنجاح',
                data: [
                    'item_id' => $itemId,
                    'item'    => $addedItem,
                    'count'   => $updatedCount,
                ]
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] AddItem failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'موجود بالفعل')) {
                Response::conflict($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'غير موجود')) {
                Response::notFound($e->getMessage());
            }

            Response::internalError('فشل في إضافة البند: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 7. تعديل بند في الجرد (Update Item)
    // =========================================================================

    /**
     * تعديل بند في عملية الجرد
     *
     * PUT /api/inventory-counts/{countId}/items/{itemId}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function updateItem(array $params): void
    {
        try {
            $countId = $this->validateCountId($params);
            $itemId = $params['itemId'] ?? null;

            if ($itemId === null || !is_numeric($itemId) || (int) $itemId <= 0) {
                Response::badRequest('معرف البند غير صالح');
            }
            $itemId = (int) $itemId;

            $input = $this->getJsonInput();
            $validationErrors = $this->validateItemUpdateData($input);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            $userId = $this->getCurrentUserId();
            $this->countService->updateItem($countId, $itemId, $input);

            $updatedCount = $this->countService->getById($countId);

            $this->auditService->log(
                userId: $userId,
                action: 'INVENTORY_COUNT_ITEM_UPDATE',
                entityType: 'inventory_count_item',
                entityId: $itemId,
                description: "تم تعديل بند في عملية الجرد: {$updatedCount['count_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم تعديل البند بنجاح',
                data: ['count' => $updatedCount]
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] UpdateItem failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن تعديل')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في تعديل البند: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 8. حذف بند من الجرد (Remove Item)
    // =========================================================================

    /**
     * حذف بند من عملية الجرد
     *
     * DELETE /api/inventory-counts/{countId}/items/{itemId}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function removeItem(array $params): void
    {
        try {
            $countId = $this->validateCountId($params);
            $itemId = $params['itemId'] ?? null;

            if ($itemId === null || !is_numeric($itemId) || (int) $itemId <= 0) {
                Response::badRequest('معرف البند غير صالح');
            }
            $itemId = (int) $itemId;

            $userId = $this->getCurrentUserId();
            $this->countService->removeItem($countId, $itemId);

            $updatedCount = $this->countService->getById($countId);

            $this->auditService->log(
                userId: $userId,
                action: 'INVENTORY_COUNT_ITEM_REMOVE',
                entityType: 'inventory_count_item',
                entityId: $itemId,
                description: "تم حذف بند من عملية الجرد: {$updatedCount['count_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم حذف البند بنجاح',
                data: ['count' => $updatedCount]
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] RemoveItem failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن حذف')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف البند: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 9. إكمال الجرد (Complete)
    // =========================================================================

    /**
     * إكمال عملية الجرد (in_progress → completed)
     *
     * POST /api/inventory-counts/{id}/complete
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function complete(array $params): void
    {
        try {
            $countId = $this->validateCountId($params);

            $count = $this->countService->getById($countId);
            if (!$count) {
                Response::notFound('عملية الجرد غير موجودة');
            }

            $userId = $this->getCurrentUserId();
            $this->countService->complete($countId, $userId);
            $updatedCount = $this->countService->getById($countId);

            $this->auditService->log(
                userId: $userId,
                action: 'INVENTORY_COUNT_COMPLETE',
                entityType: 'inventory_count',
                entityId: $countId,
                newValues: ['status' => 'completed'],
                description: "تم إكمال عملية الجرد: {$updatedCount['count_number']} - " .
                             "{$updatedCount['total_items']} بند، {$updatedCount['total_differences']} فرق",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم إكمال عملية الجرد بنجاح. جاهزة للاعتماد.',
                data: ['count' => $updatedCount]
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] Complete failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن إكمال')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في إكمال عملية الجرد: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 10. اعتماد الجرد (Approve) - الأهم!
    // =========================================================================

    /**
     * اعتماد عملية جرد وتسوية الفروقات
     *
     * POST /api/inventory-counts/{id}/approve
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function approve(array $params): void
    {
        try {
            $countId = $this->validateCountId($params);

            $count = $this->countService->getById($countId);
            if (!$count) {
                Response::notFound('عملية الجرد غير موجودة');
            }

            $userId = $this->getCurrentUserId();
            $result = $this->countService->approve($countId, $userId);
            $updatedCount = $this->countService->getById($countId);

            $this->auditService->log(
                userId: $userId,
                action: 'INVENTORY_COUNT_APPROVE',
                entityType: 'inventory_count',
                entityId: $countId,
                newValues: [
                    'status'             => 'approved',
                    'corrections_made'   => $result['corrections_made'],
                    'total_adjustment'   => $result['total_adjustment'],
                ],
                description: "تم اعتماد عملية الجرد: {$updatedCount['count_number']} - " .
                             "تم تسوية {$result['corrections_made']} فرق(ات) بإجمالي " .
                             ($result['total_adjustment'] >= 0 ? 'زيادة' : 'نقصان') . " " .
                             abs($result['total_adjustment']) . " وحدة",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم اعتماد عملية الجرد وتسوية الفروقات بنجاح',
                data: [
                    'count'            => $updatedCount,
                    'corrections_made' => $result['corrections_made'],
                    'total_adjustment' => $result['total_adjustment'],
                    'total_items'      => $result['total_items'],
                ]
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] Approve failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن اعتماد')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في اعتماد عملية الجرد: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 11. إلغاء الجرد (Cancel)
    // =========================================================================

    /**
     * إلغاء عملية جرد
     *
     * POST /api/inventory-counts/{id}/cancel
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function cancel(array $params): void
    {
        try {
            $countId = $this->validateCountId($params);

            $count = $this->countService->getById($countId);
            if (!$count) {
                Response::notFound('عملية الجرد غير موجودة');
            }

            $userId = $this->getCurrentUserId();
            $this->countService->cancel($countId, $userId);

            $this->auditService->log(
                userId: $userId,
                action: 'INVENTORY_COUNT_CANCEL',
                entityType: 'inventory_count',
                entityId: $countId,
                oldValues: ['status' => $count['status']],
                newValues: ['status' => 'cancelled'],
                description: "تم إلغاء عملية الجرد: {$count['count_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم إلغاء عملية الجرد بنجاح',
                data: null
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] Cancel failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن إلغاء')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في إلغاء عملية الجرد: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 12. حذف عملية جرد (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف عملية جرد (فقط إذا كانت draft أو cancelled)
     *
     * DELETE /api/inventory-counts/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            $countId = $this->validateCountId($params);

            $count = $this->countService->getById($countId);
            if (!$count) {
                Response::notFound('عملية الجرد غير موجودة');
            }

            $userId = $this->getCurrentUserId();
            $this->countService->delete($countId);

            $this->auditService->log(
                userId: $userId,
                action: 'INVENTORY_COUNT_DELETE',
                entityType: 'inventory_count',
                entityId: $countId,
                oldValues: [
                    'count_number' => $count['count_number'],
                    'status'       => $count['status'],
                    'total_items'  => $count['total_items'],
                ],
                description: "تم حذف عملية الجرد (Soft Delete): {$count['count_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم حذف عملية الجرد بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[INVENTORY_COUNT_CONTROLLER] Destroy failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن حذف')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف عملية الجرد');
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
     * التحقق من صحة معرف عملية الجرد
     */
    private function validateCountId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف عملية الجرد غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات عملية الجرد
     */
    private function validateCountData(array $data, bool $isNew = true): array
    {
        $errors = [];

        // 1. warehouse_id (مطلوب)
        if (empty($data['warehouse_id'])) {
            $errors['warehouse_id'] = 'المخزن مطلوب';
        } elseif (!is_numeric($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
            $errors['warehouse_id'] = 'معرف المخزن غير صالح';
        }

        // 2. count_date (مطلوب للجديد، اختياري للتعديل)
        if ($isNew || !empty($data['count_date'])) {
            if (empty($data['count_date'])) {
                if ($isNew) {
                    // افتراضي: تاريخ اليوم
                }
            } else {
                $dateTime = \DateTime::createFromFormat('Y-m-d', $data['count_date']);
                if (!$dateTime || $dateTime->format('Y-m-d') !== $data['count_date']) {
                    $errors['count_date'] = 'تاريخ الجرد غير صالح. الصيغة: YYYY-MM-DD';
                }
            }
        }

        // 3. notes (اختياري)
        if (!empty($data['notes']) && strlen($data['notes']) > 2000) {
            $errors['notes'] = 'الملاحظات يجب ألا تتجاوز 2000 حرف';
        }

        return $errors;
    }

    /**
     * التحقق من صحة بيانات البند (للإضافة)
     */
    private function validateItemData(array $data): array
    {
        $errors = [];

        // 1. product_id (مطلوب)
        if (empty($data['product_id'])) {
            $errors['product_id'] = 'معرف المنتج مطلوب';
        } elseif (!is_numeric($data['product_id']) || (int) $data['product_id'] <= 0) {
            $errors['product_id'] = 'معرف المنتج غير صالح';
        }

        // 2. counted_quantity (اختياري، غير سالب)
        if (isset($data['counted_quantity']) && $data['counted_quantity'] !== null && $data['counted_quantity'] !== '') {
            if (!is_numeric($data['counted_quantity']) || (float) $data['counted_quantity'] < 0) {
                $errors['counted_quantity'] = 'الكمية المعدودة يجب أن تكون رقماً غير سالب';
            }
        }

        // 3. unit_cost (اختياري، غير سالب)
        if (isset($data['unit_cost']) && $data['unit_cost'] !== null && $data['unit_cost'] !== '') {
            if (!is_numeric($data['unit_cost']) || (float) $data['unit_cost'] < 0) {
                $errors['unit_cost'] = 'سعر الوحدة يجب أن يكون رقماً غير سالب';
            }
        }

        // 4. notes (اختياري)
        if (!empty($data['notes']) && strlen($data['notes']) > 1000) {
            $errors['notes'] = 'الملاحظات يجب ألا تتجاوز 1000 حرف';
        }

        return $errors;
    }

    /**
     * التحقق من صحة بيانات تعديل البند
     */
    private function validateItemUpdateData(array $data): array
    {
        $errors = [];

        if (isset($data['counted_quantity']) && $data['counted_quantity'] !== null && $data['counted_quantity'] !== '') {
            if (!is_numeric($data['counted_quantity']) || (float) $data['counted_quantity'] < 0) {
                $errors['counted_quantity'] = 'الكمية المعدودة يجب أن تكون رقماً غير سالب';
            }
        }

        if (isset($data['unit_cost']) && $data['unit_cost'] !== null && $data['unit_cost'] !== '') {
            if (!is_numeric($data['unit_cost']) || (float) $data['unit_cost'] < 0) {
                $errors['unit_cost'] = 'سعر الوحدة يجب أن يكون رقماً غير سالب';
            }
        }

        if (!empty($data['notes']) && strlen($data['notes']) > 1000) {
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

        error_log('[INVENTORY_COUNT_CONTROLLER] Current user ID not found');
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
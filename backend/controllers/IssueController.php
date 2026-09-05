<?php

/**
 * ================================================================
 * Logistox - Issue Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/IssueController.php
 * الوظيفة: إدارة إذونات الصرف (CRUD + Approval Workflow)
 *
 * دورة حياة الإذن:
 * pending → approved → completed
 * pending → cancelled
 *
 * الصلاحيات المطلوبة:
 * - issues.view: عرض الإذونات
 * - issues.create: إنشاء إذن
 * - issues.update: تعديل إذن
 * - issues.delete: حذف إذن
 * - issues.approve: اعتماد إذن ← الأهم!
 *
 * ملاحظات هامة:
 * - يعتمد على IssueService لتنفيذ منطق الأعمال
 * - يعتمد على InventoryService لتحديث المخزون عند الاعتماد
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يمنع تعديل/حذف إذن معتمد أو مكتمل
 * - يتحقق من توفر الرصيد قبل الاعتماد
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\IssueService;
use App\Services\InventoryService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class IssueController
 *
 * Controller لإدارة إذونات الصرف
 */
class IssueController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var IssueService خدمة إذونات الصرف
     */
    private IssueService $issueService;

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
            $this->issueService = new IssueService($this->db, $this->inventoryService);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[ISSUE_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة إذونات الصرف');
        }
    }

    // =========================================================================
    // 1. عرض قائمة الإذونات (Index)
    // =========================================================================

    /**
     * عرض قائمة إذونات الصرف مع الفلاتر
     *
     * GET /api/issues
     *
     * Query Parameters:
     * - search: بحث في issue_number, request_number, department_name, notes
     * - status: تصفية حسب الحالة (pending, approved, completed, cancelled)
     * - warehouse_id: تصفية حسب المخزن
     * - recipient_id: تصفية حسب الجهة المستلمة
     * - from_date: من تاريخ (YYYY-MM-DD)
     * - to_date: إلى تاريخ (YYYY-MM-DD)
     * - sort_by: ترتيب حسب (issue_number, created_at, status, total_cost, total_quantity)
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
                'recipient_id' => !empty($_GET['recipient_id']) ? (int) $_GET['recipient_id'] : null,
                'from_date'    => $_GET['from_date'] ?? null,
                'to_date'      => $_GET['to_date'] ?? null,
                'sort_by'      => $_GET['sort_by'] ?? 'created_at',
                'sort_order'   => strtolower($_GET['sort_order'] ?? 'desc'),
            ];

            // 2. جلب البيانات
            $issues = $this->issueService->list($filters);

            // 3. إضافة أسماء الحالات بالعربية
            foreach ($issues as &$issue) {
                $issue['status_label'] = self::STATUS_LABELS[$issue['status']] ?? $issue['status'];
            }

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب قائمة إذونات الصرف بنجاح',
                data: [
                    'count'    => count($issues),
                    'issues'   => $issues,
                    'statuses' => self::STATUS_LABELS,
                ]
            );

        } catch (Throwable $e) {
            error_log('[ISSUE_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة إذونات الصرف');
        }
    }

    // =========================================================================
    // 2. إضافة إذن صرف جديد (Store)
    // =========================================================================

    /**
     * إضافة إذن صرف جديد
     *
     * POST /api/issues
     *
     * Request Body (JSON):
     * {
     *   "warehouse_id": 1,
     *   "recipient_id": 1,
     *   "department_name": "قسم الإنتاج",
     *   "request_number": "REQ-2026-001",
     *   "notes": "صرف لحوم للإنتاج",
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
            $validationErrors = $this->validateIssueData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات الإذن غير صالحة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إضافة الإذن
            $issueId = $this->issueService->create($input, $userId);

            // 5. جلب بيانات الإذن المضاف
            $issue = $this->issueService->getById($issueId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'ISSUE_CREATE',
                entityType: 'issue',
                entityId: $issueId,
                newValues: [
                    'issue_number'   => $issue['issue_number'],
                    'warehouse_id'   => $issue['warehouse_id'],
                    'recipient_id'   => $issue['recipient_id'],
                    'total_items'    => $issue['total_items'],
                    'total_quantity' => $issue['total_quantity'],
                    'total_cost'     => $issue['total_cost'],
                ],
                description: "تم إنشاء إذن صرف جديد: {$issue['issue_number']} ({$issue['total_items']} بند)",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::created(
                message: 'تم إنشاء إذن الصرف بنجاح',
                data: ['issue' => $issue],
                location: "/api/issues/{$issueId}"
            );

        } catch (Throwable $e) {
            error_log('[ISSUE_CONTROLLER] Store failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'غير موجود')) {
                Response::notFound($e->getMessage());
            }

            Response::internalError('فشل في إنشاء إذن الصرف: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. عرض تفاصيل إذن (Show)
    // =========================================================================

    /**
     * عرض تفاصيل إذن صرف معين
     *
     * GET /api/issues/{id}
     *
     * @param array $params المعاملات من Router (مثل ['id' => 5])
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            // 1. التحقق من معرف الإذن
            $issueId = $this->validateIssueId($params);

            // 2. جلب بيانات الإذن
            $issue = $this->issueService->getById($issueId);

            if (!$issue) {
                Response::notFound('الإذن غير موجود');
            }

            // 3. إضافة اسم الحالة بالعربية
            $issue['status_label'] = self::STATUS_LABELS[$issue['status']] ?? $issue['status'];

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب تفاصيل الإذن بنجاح',
                data: [
                    'issue' => $issue,
                ]
            );

        } catch (Throwable $e) {
            error_log('[ISSUE_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل الإذن');
        }
    }

    // =========================================================================
    // 4. تعديل إذن (Update)
    // =========================================================================

    /**
     * تعديل إذن صرف (فقط إذا كان pending)
     *
     * PUT /api/issues/{id}
     * PATCH /api/issues/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            // 1. التحقق من معرف الإذن
            $issueId = $this->validateIssueId($params);

            // 2. جلب البيانات القديمة (للتدقيق)
            $oldIssue = $this->issueService->getById($issueId);
            if (!$oldIssue) {
                Response::notFound('الإذن غير موجود');
            }

            // 3. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 4. التحقق من البيانات
            $validationErrors = $this->validateIssueData($input, isNew: false);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // 5. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 6. تعديل الإذن
            $this->issueService->update($issueId, $input, $userId);

            // 7. جلب البيانات الجديدة
            $newIssue = $this->issueService->getById($issueId);

            // 8. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'ISSUE_UPDATE',
                entityType: 'issue',
                entityId: $issueId,
                oldValues: [
                    'issue_number'   => $oldIssue['issue_number'],
                    'total_items'    => $oldIssue['total_items'],
                    'total_quantity' => $oldIssue['total_quantity'],
                    'total_cost'     => $oldIssue['total_cost'],
                ],
                newValues: [
                    'issue_number'   => $newIssue['issue_number'],
                    'total_items'    => $newIssue['total_items'],
                    'total_quantity' => $newIssue['total_quantity'],
                    'total_cost'     => $newIssue['total_cost'],
                ],
                description: "تم تعديل إذن الصرف: {$newIssue['issue_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 9. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تعديل الإذن بنجاح',
                data: ['issue' => $newIssue]
            );

        } catch (Throwable $e) {
            error_log('[ISSUE_CONTROLLER] Update failed: ' . $e->getMessage());

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
     * اعتماد إذن صرف وتحديث المخزون
     *
     * POST /api/issues/{id}/approve
     *
     * هذه العملية:
     * 1. تتحقق من حالة الإذن (pending فقط)
     * 2. تتحقق من توفر الرصيد لكل بند
     * 3. تستدعي InventoryService لتحديث المخزون
     * 4. يتم إنشاء حركات في stock_movements
     * 5. يتم تحديث stock_balances
     * 6. يتم تغيير حالة الإذن إلى approved
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function approve(array $params): void
    {
        try {
            // 1. التحقق من معرف الإذن
            $issueId = $this->validateIssueId($params);

            // 2. جلب بيانات الإذن
            $issue = $this->issueService->getById($issueId);
            if (!$issue) {
                Response::notFound('الإذن غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. اعتماد الإذن (يستدعي InventoryService داخلياً)
            $result = $this->issueService->approve($issueId, $userId);

            // 5. جلب البيانات المحدثة
            $updatedIssue = $this->issueService->getById($issueId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'ISSUE_APPROVE',
                entityType: 'issue',
                entityId: $issueId,
                newValues: [
                    'issue_number'   => $updatedIssue['issue_number'],
                    'status'         => $updatedIssue['status'],
                    'total_items'    => $updatedIssue['total_items'],
                    'total_quantity' => $updatedIssue['total_quantity'],
                    'total_cost'     => $updatedIssue['total_cost'],
                ],
                description: "تم اعتماد إذن الصرف: {$updatedIssue['issue_number']} - " .
                             "تم تحديث المخزون ({$updatedIssue['total_items']} بند، " .
                             "{$updatedIssue['total_quantity']} وحدة)",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم اعتماد الإذن وتحديث المخزون بنجاح',
                data: [
                    'issue'           => $updatedIssue,
                    'items_processed' => $result['items_processed'] ?? $updatedIssue['total_items'],
                ]
            );

        } catch (Throwable $e) {
            error_log('[ISSUE_CONTROLLER] Approve failed: ' . $e->getMessage());

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
     * إلغاء إذن صرف
     *
     * POST /api/issues/{id}/cancel
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function cancel(array $params): void
    {
        try {
            // 1. التحقق من معرف الإذن
            $issueId = $this->validateIssueId($params);

            // 2. جلب بيانات الإذن
            $issue = $this->issueService->getById($issueId);
            if (!$issue) {
                Response::notFound('الإذن غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إلغاء الإذن
            $this->issueService->cancel($issueId, $userId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'ISSUE_CANCEL',
                entityType: 'issue',
                entityId: $issueId,
                oldValues: ['status' => $issue['status']],
                newValues: ['status' => 'cancelled'],
                description: "تم إلغاء إذن الصرف: {$issue['issue_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم إلغاء الإذن بنجاح',
                data: null
            );

        } catch (Throwable $e) {
            error_log('[ISSUE_CONTROLLER] Cancel failed: ' . $e->getMessage());

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
     * حذف إذن صرف (فقط إذا كان pending)
     *
     * DELETE /api/issues/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            // 1. التحقق من معرف الإذن
            $issueId = $this->validateIssueId($params);

            // 2. جلب بيانات الإذن
            $issue = $this->issueService->getById($issueId);
            if (!$issue) {
                Response::notFound('الإذن غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. حذف الإذن (Soft Delete)
            $this->issueService->delete($issueId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'ISSUE_DELETE',
                entityType: 'issue',
                entityId: $issueId,
                oldValues: [
                    'issue_number'   => $issue['issue_number'],
                    'status'         => $issue['status'],
                    'total_items'    => $issue['total_items'],
                    'total_quantity' => $issue['total_quantity'],
                ],
                description: "تم حذف إذن الصرف (Soft Delete): {$issue['issue_number']}",
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
            error_log('[ISSUE_CONTROLLER] Destroy failed: ' . $e->getMessage());

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
    private function validateIssueId(array $params): int
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
    private function validateIssueData(array $data, bool $isNew = true): array
    {
        $errors = [];

        // 1. التحقق من warehouse_id (مطلوب)
        if (empty($data['warehouse_id'])) {
            $errors['warehouse_id'] = 'المخزن مطلوب';
        } elseif (!is_numeric($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
            $errors['warehouse_id'] = 'معرف المخزن غير صالح';
        }

        // 2. التحقق من recipient_id (اختياري)
        if (isset($data['recipient_id']) && $data['recipient_id'] !== null && $data['recipient_id'] !== '') {
            if (!is_numeric($data['recipient_id']) || (int) $data['recipient_id'] <= 0) {
                $errors['recipient_id'] = 'معرف الجهة المستلمة غير صالح';
            }
        }

        // 3. التحقق من department_name (اختياري)
        if (!empty($data['department_name']) && strlen($data['department_name']) > 200) {
            $errors['department_name'] = 'اسم القسم يجب ألا يتجاوز 200 حرف';
        }

        // 4. التحقق من request_number (اختياري)
        if (!empty($data['request_number']) && strlen($data['request_number']) > 100) {
            $errors['request_number'] = 'رقم الطلب يجب ألا يتجاوز 100 حرف';
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
                $itemErrors = $this->validateIssueItem($item, $index);
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
    private function validateIssueItem(array $item, int $index): array
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

        error_log('[ISSUE_CONTROLLER] Current user ID not found');
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
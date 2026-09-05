<?php

/**
 * ================================================================
 * Logistox - Recipient Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/RecipientController.php
 * الوظيفة: إدارة الجهات المستلمة (CRUD)
 *
 * المسؤوليات:
 * 1. عرض قائمة الجهات المستلمة مع الفلاتر (index)
 * 2. جلب الجهات المستلمة النشطة (active)
 * 3. جلب الجهات المستلمة حسب النوع (byType)
 * 4. إضافة جهة مستلمة جديدة (store)
 * 5. عرض تفاصيل جهة مستلمة (show)
 * 6. تعديل جهة مستلمة (update)
 * 7. حذف جهة مستلمة - Soft Delete (destroy)
 * 8. التحقق من صحة البيانات (Validation)
 * 9. تسجيل كل العمليات في audit_logs
 *
 * ملاحظات هامة:
 * - يعتمد على RecipientService لتنفيذ منطق الأعمال
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يمنع حذف جهة لها إذونات صرف مرتبطة
 * - يتحقق من تفرد code
 * - يتحقق من صحة البريد الإلكتروني ورقم الهاتف
 * - يدعم أنواع الجهات (department, employee, external, project)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\RecipientService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class RecipientController
 *
 * Controller لإدارة الجهات المستلمة
 */
class RecipientController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var RecipientService خدمة الجهات المستلمة
     */
    private RecipientService $recipientService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var array أنواع الجهات المستلمة المسموحة
     */
    private const ALLOWED_TYPES = ['department', 'employee', 'external', 'project'];

    /**
     * @var array أسماء الأنواع بالعربية (للرسائل)
     */
    private const TYPE_LABELS = [
        'department' => 'قسم / إدارة',
        'employee'   => 'موظف',
        'external'   => 'جهة خارجية',
        'project'    => 'مشروع',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->recipientService = new RecipientService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[RECIPIENT_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة الجهات المستلمة');
        }
    }

    // =========================================================================
    // 1. عرض قائمة الجهات المستلمة (Index)
    // =========================================================================

    /**
     * عرض قائمة الجهات المستلمة مع الفلاتر
     *
     * GET /api/recipients
     *
     * Query Parameters:
     * - search: بحث في name, code, contact_person, phone, email
     * - type: تصفية حسب النوع (department, employee, external, project)
     * - is_active: تصفية حسب الحالة (1 = نشط، 0 = معطل)
     * - sort_by: ترتيب حسب (name, code, type, contact_person, phone, created_at, updated_at)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            // 1. قراءة Query Parameters
            $search = trim($_GET['search'] ?? '');
            $type = $_GET['type'] ?? null;
            $isActive = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;
            $sortBy = $_GET['sort_by'] ?? 'name';
            $sortOrder = strtolower($_GET['sort_order'] ?? 'asc');

            // 2. بناء الفلاتر
            $filters = [
                'search'      => $search,
                'type'        => $type,
                'is_active'   => $isActive,
                'sort_by'     => $sortBy,
                'sort_order'  => $sortOrder,
            ];

            // 3. جلب البيانات
            $recipients = $this->recipientService->list($filters);

            // 4. إضافة أسماء الأنواع بالعربية
            foreach ($recipients as &$recipient) {
                $recipient['type_label'] = self::TYPE_LABELS[$recipient['type']] ?? $recipient['type'];
            }

            // 5. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب قائمة الجهات المستلمة بنجاح',
                data: [
                    'count'      => count($recipients),
                    'recipients' => $recipients,
                    'types'      => self::TYPE_LABELS,
                ]
            );

        } catch (Throwable $e) {
            error_log('[RECIPIENT_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة الجهات المستلمة');
        }
    }

    // =========================================================================
    // 2. الجهات المستلمة النشطة (Active)
    // =========================================================================

    /**
     * جلب الجهات المستلمة النشطة فقط (للقوائم المنسدلة)
     *
     * GET /api/recipients/active
     *
     * @return void يرسل استجابة JSON
     */
    public function active(): void
    {
        try {
            $recipients = $this->recipientService->getActiveRecipients();

            // إضافة أسماء الأنواع بالعربية
            foreach ($recipients as &$recipient) {
                $recipient['type_label'] = self::TYPE_LABELS[$recipient['type']] ?? $recipient['type'];
            }

            Response::success(
                message: 'تم جلب الجهات المستلمة النشطة بنجاح',
                data: [
                    'count'      => count($recipients),
                    'recipients' => $recipients,
                ]
            );

        } catch (Throwable $e) {
            error_log('[RECIPIENT_CONTROLLER] Active failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الجهات المستلمة النشطة');
        }
    }

    // =========================================================================
    // 3. الجهات المستلمة حسب النوع (By Type)
    // =========================================================================

    /**
     * جلب الجهات المستلمة حسب النوع
     *
     * GET /api/recipients/type/{type}
     *
     * @param array $params المعاملات من Router (مثل ['type' => 'department'])
     * @return void يرسل استجابة JSON
     */
    public function byType(array $params): void
    {
        try {
            $type = $params['type'] ?? '';

            if (!in_array($type, self::ALLOWED_TYPES, true)) {
                Response::badRequest(
                    'نوع الجهة المستلمة غير صالح. القيم المسموحة: ' . implode(', ', self::ALLOWED_TYPES)
                );
            }

            $recipients = $this->recipientService->getByType($type);

            Response::success(
                message: 'تم جلب الجهات المستلمة بنجاح',
                data: [
                    'type'       => $type,
                    'type_label' => self::TYPE_LABELS[$type] ?? $type,
                    'count'      => count($recipients),
                    'recipients' => $recipients,
                ]
            );

        } catch (Throwable $e) {
            error_log('[RECIPIENT_CONTROLLER] ByType failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الجهات المستلمة');
        }
    }

    // =========================================================================
    // 4. إضافة جهة مستلمة جديدة (Store)
    // =========================================================================

    /**
     * إضافة جهة مستلمة جديدة
     *
     * POST /api/recipients
     *
     * Request Body (JSON):
     * {
     *   "code": "REC-001",              // اختياري - فريد
     *   "name": "قسم الإنتاج",          // مطلوب
     *   "type": "department",           // اختياري - افتراضي: department
     *   "contact_person": "أحمد محمد",  // اختياري
     *   "phone": "0212345678",          // اختياري
     *   "email": "production@company.com", // اختياري
     *   "address": "القاهرة - مصر",     // اختياري
     *   "notes": "ملاحظات إضافية",     // اختياري
     *   "is_active": true               // اختياري - افتراضي: true
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function store(): void
    {
        try {
            // 1. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 2. التحقق من البيانات
            $validationErrors = $this->validateRecipientData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات الجهة المستلمة غير صالحة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إضافة الجهة المستلمة
            $recipientId = $this->recipientService->create($input, $userId);

            // 5. جلب بيانات الجهة المستلمة المضافة
            $recipient = $this->recipientService->getById($recipientId);

            // 6. إضافة اسم النوع بالعربية
            $recipient['type_label'] = self::TYPE_LABELS[$recipient['type']] ?? $recipient['type'];

            // 7. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RECIPIENT_CREATE',
                entityType: 'recipient',
                entityId: $recipientId,
                newValues: $input,
                description: "تم إضافة جهة مستلمة جديدة: {$recipient['name']} ({$recipient['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 8. إرجاع الاستجابة الناجحة
            Response::created(
                message: 'تم إضافة الجهة المستلمة بنجاح',
                data: ['recipient' => $recipient],
                location: "/api/recipients/{$recipientId}"
            );

        } catch (Throwable $e) {
            error_log('[RECIPIENT_CONTROLLER] Store failed: ' . $e->getMessage());

            // معالجة أخطاء التفرد
            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            // معالجة أخطاء التحقق
            if (str_contains($e->getMessage(), 'غير صالح')) {
                Response::badRequest($e->getMessage());
            }

            Response::internalError('فشل في إضافة الجهة المستلمة: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 5. عرض تفاصيل جهة مستلمة (Show)
    // =========================================================================

    /**
     * عرض تفاصيل جهة مستلمة معينة
     *
     * GET /api/recipients/{id}
     *
     * @param array $params المعاملات من Router (مثل ['id' => 5])
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            // 1. التحقق من معرف الجهة المستلمة
            $recipientId = $this->validateRecipientId($params);

            // 2. جلب بيانات الجهة المستلمة
            $recipient = $this->recipientService->getById($recipientId);

            if (!$recipient) {
                Response::notFound('الجهة المستلمة غير موجودة');
            }

            // 3. إضافة اسم النوع بالعربية
            $recipient['type_label'] = self::TYPE_LABELS[$recipient['type']] ?? $recipient['type'];

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب تفاصيل الجهة المستلمة بنجاح',
                data: [
                    'recipient' => $recipient,
                ]
            );

        } catch (Throwable $e) {
            error_log('[RECIPIENT_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل الجهة المستلمة');
        }
    }

    // =========================================================================
    // 6. تعديل جهة مستلمة (Update)
    // =========================================================================

    /**
     * تعديل جهة مستلمة موجودة
     *
     * PUT /api/recipients/{id}
     * PATCH /api/recipients/{id}
     *
     * Request Body (JSON):
     * {
     *   "name": "قسم الإنتاج - محدث",   // اختياري
     *   "code": "REC-001-NEW",          // اختياري
     *   "type": "employee",             // اختياري
     *   "contact_person": "محمد أحمد",  // اختياري
     *   "phone": "0212345679",          // اختياري
     *   "email": "new@company.com",     // اختياري
     *   "address": "الجيزة - مصر",      // اختياري
     *   "notes": "ملاحظات جديدة",       // اختياري
     *   "is_active": false              // اختياري
     * }
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            // 1. التحقق من معرف الجهة المستلمة
            $recipientId = $this->validateRecipientId($params);

            // 2. جلب البيانات القديمة (للتدقيق)
            $oldRecipient = $this->recipientService->getById($recipientId);
            if (!$oldRecipient) {
                Response::notFound('الجهة المستلمة غير موجودة');
            }

            // 3. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 4. التحقق من البيانات
            $validationErrors = $this->validateRecipientData($input, isNew: false);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // 5. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 6. تعديل الجهة المستلمة
            $this->recipientService->update($recipientId, $input, $userId);

            // 7. جلب البيانات الجديدة
            $newRecipient = $this->recipientService->getById($recipientId);

            // 8. إضافة اسم النوع بالعربية
            $newRecipient['type_label'] = self::TYPE_LABELS[$newRecipient['type']] ?? $newRecipient['type'];

            // 9. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RECIPIENT_UPDATE',
                entityType: 'recipient',
                entityId: $recipientId,
                oldValues: $oldRecipient,
                newValues: $newRecipient,
                description: "تم تعديل الجهة المستلمة: {$newRecipient['name']} ({$newRecipient['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 10. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تعديل الجهة المستلمة بنجاح',
                data: ['recipient' => $newRecipient]
            );

        } catch (Throwable $e) {
            error_log('[RECIPIENT_CONTROLLER] Update failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'غير صالح')) {
                Response::badRequest($e->getMessage());
            }

            Response::internalError('فشل في تعديل الجهة المستلمة: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 7. حذف جهة مستلمة (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف جهة مستلمة (Soft Delete)
     *
     * DELETE /api/recipients/{id}
     *
     * ملاحظات:
     * - لا يتم الحذف الفعلي من قاعدة البيانات
     * - يتم تعيين deleted_at = NOW()
     * - يمنع حذف جهة لها إذونات صرف مرتبطة
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            // 1. التحقق من معرف الجهة المستلمة
            $recipientId = $this->validateRecipientId($params);

            // 2. جلب بيانات الجهة المستلمة
            $recipient = $this->recipientService->getById($recipientId);
            if (!$recipient) {
                Response::notFound('الجهة المستلمة غير موجودة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. حذف الجهة المستلمة (Soft Delete)
            $this->recipientService->delete($recipientId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RECIPIENT_DELETE',
                entityType: 'recipient',
                entityId: $recipientId,
                oldValues: $recipient,
                description: "تم حذف الجهة المستلمة (Soft Delete): {$recipient['name']} ({$recipient['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم حذف الجهة المستلمة بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[RECIPIENT_CONTROLLER] Destroy failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'إذونات صرف')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف الجهة المستلمة');
        }
    }

    // =========================================================================
    // Helper Methods (دوال مساعدة)
    // =========================================================================

    /**
     * قراءة مدخلات JSON من جسم الطلب
     *
     * @return array البيانات المقروءة
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
     * التحقق من صحة معرف الجهة المستلمة
     *
     * @param array $params المعاملات من Router
     * @return int معرف الجهة المستلمة الصحيح
     */
    private function validateRecipientId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف الجهة المستلمة غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات الجهة المستلمة
     *
     * @param array $data البيانات المراد التحقق منها
     * @param bool $isNew هل هي جهة جديدة أم تعديل
     * @return array مصفوفة أخطاء التحقق
     */
    private function validateRecipientData(array $data, bool $isNew = true): array
    {
        $errors = [];

        // 1. التحقق من الحقول المطلوبة (للجهات الجديدة فقط)
        if ($isNew) {
            if (empty($data['name'])) {
                $errors['name'] = 'اسم الجهة المستلمة مطلوب';
            } elseif (strlen($data['name']) > 200) {
                $errors['name'] = 'اسم الجهة المستلمة يجب ألا يتجاوز 200 حرف';
            }
        }

        // 2. التحقق من name (إذا تم تقديمه)
        if (!empty($data['name'])) {
            if (strlen($data['name']) > 200) {
                $errors['name'] = 'اسم الجهة المستلمة يجب ألا يتجاوز 200 حرف';
            }
        }

        // 3. التحقق من code (إذا تم تقديمه)
        if (!empty($data['code'])) {
            if (strlen($data['code']) > 50) {
                $errors['code'] = 'كود الجهة المستلمة يجب ألا يتجاوز 50 حرفاً';
            } elseif (!$this->recipientService->isCodeUnique($data['code'])) {
                $errors['code'] = 'كود الجهة المستلمة مستخدم بالفعل';
            }
        }

        // 4. التحقق من type (إذا تم تقديمه)
        if (!empty($data['type'])) {
            if (!in_array($data['type'], self::ALLOWED_TYPES, true)) {
                $errors['type'] = 'نوع الجهة المستلمة غير صالح. القيم المسموحة: ' . implode(', ', self::ALLOWED_TYPES);
            }
        }

        // 5. التحقق من contact_person (إذا تم تقديمه)
        if (!empty($data['contact_person'])) {
            if (strlen($data['contact_person']) > 100) {
                $errors['contact_person'] = 'اسم جهة الاتصال يجب ألا يتجاوز 100 حرف';
            }
        }

        // 6. التحقق من phone (إذا تم تقديمه)
        if (!empty($data['phone'])) {
            if (strlen($data['phone']) > 20) {
                $errors['phone'] = 'رقم الهاتف يجب ألا يتجاوز 20 رقماً';
            } elseif (!$this->validatePhoneNumber($data['phone'])) {
                $errors['phone'] = 'رقم الهاتف غير صالح';
            }
        }

        // 7. التحقق من email (إذا تم تقديمه)
        if (!empty($data['email'])) {
            if (strlen($data['email']) > 100) {
                $errors['email'] = 'البريد الإلكتروني يجب ألا يتجاوز 100 حرف';
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'البريد الإلكتروني غير صالح';
            }
        }

        // 8. التحقق من address (إذا تم تقديمه)
        if (!empty($data['address']) && strlen($data['address']) > 500) {
            $errors['address'] = 'العنوان يجب ألا يتجاوز 500 حرف';
        }

        // 9. التحقق من notes (إذا تم تقديمه)
        if (!empty($data['notes']) && strlen($data['notes']) > 2000) {
            $errors['notes'] = 'الملاحظات يجب ألا تتجاوز 2000 حرف';
        }

        // 10. التحقق من is_active (إذا تم تقديمه)
        if (isset($data['is_active']) && $data['is_active'] !== null) {
            if (!in_array($data['is_active'], [0, 1, true, false, '0', '1'], true)) {
                $errors['is_active'] = 'is_active يجب أن يكون 0 أو 1';
            }
        }

        return $errors;
    }

    /**
     * التحقق من صحة رقم الهاتف
     *
     * @param string $phoneNumber رقم الهاتف
     * @return bool true إذا كان الرقم صالحاً
     */
    private function validatePhoneNumber(string $phoneNumber): bool
    {
        $cleaned = preg_replace('/[\s\-\(\)]+/', '', $phoneNumber);

        $length = strlen($cleaned);
        if ($length < 7 || $length > 15) {
            return false;
        }

        if (!preg_match('/^\+?[0-9]+$/', $cleaned)) {
            return false;
        }

        return true;
    }

    /**
     * جلب معرف المستخدم الحالي من AuthMiddleware
     *
     * @return int معرف المستخدم
     */
    private function getCurrentUserId(): int
    {
        if (isset($_REQUEST['user']['id'])) {
            return (int) $_REQUEST['user']['id'];
        }

        if (isset($GLOBALS['current_user_id'])) {
            return (int) $GLOBALS['current_user_id'];
        }

        error_log('[RECIPIENT_CONTROLLER] Current user ID not found');
        Response::unauthorized('لم يتم العثور على بيانات المستخدم. يرجى تسجيل الدخول مرة أخرى.');
    }

    /**
     * جلب IP العميل
     *
     * @return string IP العميل
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
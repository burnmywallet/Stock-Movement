<?php

/**
 * ================================================================
 * Logistox - Supplier Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/SupplierController.php
 * الوظيفة: إدارة الموردين (CRUD)
 *
 * المسؤوليات:
 * 1. عرض قائمة الموردين مع الفلاتر (index)
 * 2. جلب الموردين النشطين (active)
 * 3. إضافة مورد جديد (store)
 * 4. عرض تفاصيل مورد (show)
 * 5. تعديل مورد (update)
 * 6. حذف مورد - Soft Delete (destroy)
 * 7. التحقق من صحة البيانات (Validation)
 * 8. تسجيل كل العمليات في audit_logs
 *
 * ملاحظات هامة:
 * - يعتمد على SupplierService لتنفيذ منطق الأعمال
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يمنع حذف مورد له إذونات استلام مرتبطة
 * - يتحقق من تفرد code
 * - يتحقق من صحة البريد الإلكتروني وأرقام الهواتف
 * - الموردون ليسوا هرميين (لا parent_id)
 * - الموردون كيانات تجارية (لهم tax_number و commercial_register)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\SupplierService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class SupplierController
 *
 * Controller لإدارة الموردين
 */
class SupplierController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var SupplierService خدمة الموردين
     */
    private SupplierService $supplierService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->supplierService = new SupplierService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[SUPPLIER_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة الموردين');
        }
    }

    // =========================================================================
    // 1. عرض قائمة الموردين (Index)
    // =========================================================================

    /**
     * عرض قائمة الموردين مع الفلاتر
     *
     * GET /api/suppliers
     *
     * Query Parameters:
     * - search: بحث في name, code, contact_person, phone, mobile, email
     * - is_active: تصفية حسب الحالة (1 = نشط، 0 = معطل)
     * - sort_by: ترتيب حسب (name, code, contact_person, phone, created_at, updated_at)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            // 1. قراءة Query Parameters
            $search = trim($_GET['search'] ?? '');
            $isActive = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;
            $sortBy = $_GET['sort_by'] ?? 'name';
            $sortOrder = strtolower($_GET['sort_order'] ?? 'asc');

            // 2. بناء الفلاتر
            $filters = [
                'search'      => $search,
                'is_active'   => $isActive,
                'sort_by'     => $sortBy,
                'sort_order'  => $sortOrder,
            ];

            // 3. جلب البيانات
            $suppliers = $this->supplierService->list($filters);

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب قائمة الموردين بنجاح',
                data: [
                    'count'     => count($suppliers),
                    'suppliers' => $suppliers,
                ]
            );

        } catch (Throwable $e) {
            error_log('[SUPPLIER_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة الموردين');
        }
    }

    // =========================================================================
    // 2. الموردين النشطين (Active)
    // =========================================================================

    /**
     * جلب الموردين النشطين فقط (للقوائم المنسدلة)
     *
     * GET /api/suppliers/active
     *
     * @return void يرسل استجابة JSON
     */
    public function active(): void
    {
        try {
            $suppliers = $this->supplierService->getActiveSuppliers();

            Response::success(
                message: 'تم جلب الموردين النشطين بنجاح',
                data: [
                    'count'     => count($suppliers),
                    'suppliers' => $suppliers,
                ]
            );

        } catch (Throwable $e) {
            error_log('[SUPPLIER_CONTROLLER] Active failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الموردين النشطين');
        }
    }

    // =========================================================================
    // 3. إضافة مورد جديد (Store)
    // =========================================================================

    /**
     * إضافة مورد جديد
     *
     * POST /api/suppliers
     *
     * Request Body (JSON):
     * {
     *   "code": "SUP-001",              // اختياري - فريد
     *   "name": "شركة اللحوم المصرية",  // مطلوب
     *   "contact_person": "أحمد محمد",  // اختياري
     *   "phone": "0212345678",          // اختياري
     *   "mobile": "01012345678",        // اختياري
     *   "email": "info@supplier.com",   // اختياري
     *   "address": "القاهرة - مصر",     // اختياري
     *   "tax_number": "123-456-789",    // اختياري
     *   "commercial_register": "12345", // اختياري
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
            $validationErrors = $this->validateSupplierData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات المورد غير صالحة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إضافة المورد
            $supplierId = $this->supplierService->create($input, $userId);

            // 5. جلب بيانات المورد المضاف
            $supplier = $this->supplierService->getById($supplierId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'SUPPLIER_CREATE',
                entityType: 'supplier',
                entityId: $supplierId,
                newValues: $input,
                description: "تم إضافة مورد جديد: {$supplier['name']} ({$supplier['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::created(
                message: 'تم إضافة المورد بنجاح',
                data: ['supplier' => $supplier],
                location: "/api/suppliers/{$supplierId}"
            );

        } catch (Throwable $e) {
            error_log('[SUPPLIER_CONTROLLER] Store failed: ' . $e->getMessage());

            // معالجة أخطاء التفرد
            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            // معالجة أخطاء التحقق
            if (str_contains($e->getMessage(), 'غير صالح')) {
                Response::badRequest($e->getMessage());
            }

            Response::internalError('فشل في إضافة المورد: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 4. عرض تفاصيل مورد (Show)
    // =========================================================================

    /**
     * عرض تفاصيل مورد معين
     *
     * GET /api/suppliers/{id}
     *
     * @param array $params المعاملات من Router (مثل ['id' => 5])
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            // 1. التحقق من معرف المورد
            $supplierId = $this->validateSupplierId($params);

            // 2. جلب بيانات المورد
            $supplier = $this->supplierService->getById($supplierId);

            if (!$supplier) {
                Response::notFound('المورد غير موجود');
            }

            // 3. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب تفاصيل المورد بنجاح',
                data: [
                    'supplier' => $supplier,
                ]
            );

        } catch (Throwable $e) {
            error_log('[SUPPLIER_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل المورد');
        }
    }

    // =========================================================================
    // 5. تعديل مورد (Update)
    // =========================================================================

    /**
     * تعديل مورد موجود
     *
     * PUT /api/suppliers/{id}
     * PATCH /api/suppliers/{id}
     *
     * Request Body (JSON):
     * {
     *   "name": "شركة اللحوم المصرية - محدث",  // اختياري
     *   "code": "SUP-001-NEW",                 // اختياري
     *   "contact_person": "محمد أحمد",         // اختياري
     *   "phone": "0212345679",                 // اختياري
     *   "mobile": "01012345679",               // اختياري
     *   "email": "new@supplier.com",           // اختياري
     *   "address": "الجيزة - مصر",             // اختياري
     *   "tax_number": "987-654-321",           // اختياري
     *   "commercial_register": "54321",        // اختياري
     *   "notes": "ملاحظات جديدة",              // اختياري
     *   "is_active": false                     // اختياري
     * }
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            // 1. التحقق من معرف المورد
            $supplierId = $this->validateSupplierId($params);

            // 2. جلب البيانات القديمة (للتدقيق)
            $oldSupplier = $this->supplierService->getById($supplierId);
            if (!$oldSupplier) {
                Response::notFound('المورد غير موجود');
            }

            // 3. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 4. التحقق من البيانات
            $validationErrors = $this->validateSupplierData($input, isNew: false);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // 5. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 6. تعديل المورد
            $this->supplierService->update($supplierId, $input, $userId);

            // 7. جلب البيانات الجديدة
            $newSupplier = $this->supplierService->getById($supplierId);

            // 8. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'SUPPLIER_UPDATE',
                entityType: 'supplier',
                entityId: $supplierId,
                oldValues: $oldSupplier,
                newValues: $newSupplier,
                description: "تم تعديل المورد: {$newSupplier['name']} ({$newSupplier['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 9. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تعديل المورد بنجاح',
                data: ['supplier' => $newSupplier]
            );

        } catch (Throwable $e) {
            error_log('[SUPPLIER_CONTROLLER] Update failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'غير صالح')) {
                Response::badRequest($e->getMessage());
            }

            Response::internalError('فشل في تعديل المورد: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 6. حذف مورد (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف مورد (Soft Delete)
     *
     * DELETE /api/suppliers/{id}
     *
     * ملاحظات:
     * - لا يتم الحذف الفعلي من قاعدة البيانات
     * - يتم تعيين deleted_at = NOW()
     * - يمنع حذف مورد له إذونات استلام مرتبطة
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            // 1. التحقق من معرف المورد
            $supplierId = $this->validateSupplierId($params);

            // 2. جلب بيانات المورد
            $supplier = $this->supplierService->getById($supplierId);
            if (!$supplier) {
                Response::notFound('المورد غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. حذف المورد (Soft Delete)
            $this->supplierService->delete($supplierId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'SUPPLIER_DELETE',
                entityType: 'supplier',
                entityId: $supplierId,
                oldValues: $supplier,
                description: "تم حذف المورد (Soft Delete): {$supplier['name']} ({$supplier['code']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم حذف المورد بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[SUPPLIER_CONTROLLER] Destroy failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'إذونات استلام')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف المورد');
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
     * التحقق من صحة معرف المورد
     *
     * @param array $params المعاملات من Router
     * @return int معرف المورد الصحيح
     */
    private function validateSupplierId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف المورد غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات المورد
     *
     * @param array $data البيانات المراد التحقق منها
     * @param bool $isNew هل هو مورد جديد أم تعديل
     * @return array مصفوفة أخطاء التحقق
     */
    private function validateSupplierData(array $data, bool $isNew = true): array
    {
        $errors = [];

        // 1. التحقق من الحقول المطلوبة (للموردين الجدد فقط)
        if ($isNew) {
            if (empty($data['name'])) {
                $errors['name'] = 'اسم المورد مطلوب';
            } elseif (strlen($data['name']) > 200) {
                $errors['name'] = 'اسم المورد يجب ألا يتجاوز 200 حرف';
            }
        }

        // 2. التحقق من name (إذا تم تقديمه)
        if (!empty($data['name'])) {
            if (strlen($data['name']) > 200) {
                $errors['name'] = 'اسم المورد يجب ألا يتجاوز 200 حرف';
            }
        }

        // 3. التحقق من code (إذا تم تقديمه)
        if (!empty($data['code'])) {
            if (strlen($data['code']) > 50) {
                $errors['code'] = 'كود المورد يجب ألا يتجاوز 50 حرفاً';
            } elseif (!$this->supplierService->isCodeUnique($data['code'])) {
                $errors['code'] = 'كود المورد مستخدم بالفعل';
            }
        }

        // 4. التحقق من contact_person (إذا تم تقديمه)
        if (!empty($data['contact_person'])) {
            if (strlen($data['contact_person']) > 100) {
                $errors['contact_person'] = 'اسم جهة الاتصال يجب ألا يتجاوز 100 حرف';
            }
        }

        // 5. التحقق من phone (إذا تم تقديمه)
        if (!empty($data['phone'])) {
            if (strlen($data['phone']) > 20) {
                $errors['phone'] = 'رقم الهاتف يجب ألا يتجاوز 20 رقماً';
            } elseif (!$this->validatePhoneNumber($data['phone'])) {
                $errors['phone'] = 'رقم الهاتف غير صالح';
            }
        }

        // 6. التحقق من mobile (إذا تم تقديمه)
        if (!empty($data['mobile'])) {
            if (strlen($data['mobile']) > 20) {
                $errors['mobile'] = 'رقم الموبايل يجب ألا يتجاوز 20 رقماً';
            } elseif (!$this->validatePhoneNumber($data['mobile'])) {
                $errors['mobile'] = 'رقم الموبايل غير صالح';
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

        // 9. التحقق من tax_number (إذا تم تقديمه)
        if (!empty($data['tax_number'])) {
            if (strlen($data['tax_number']) > 50) {
                $errors['tax_number'] = 'الرقم الضريبي يجب ألا يتجاوز 50 حرفاً';
            }
        }

        // 10. التحقق من commercial_register (إذا تم تقديمه)
        if (!empty($data['commercial_register'])) {
            if (strlen($data['commercial_register']) > 50) {
                $errors['commercial_register'] = 'السجل التجاري يجب ألا يتجاوز 50 حرفاً';
            }
        }

        // 11. التحقق من notes (إذا تم تقديمه)
        if (!empty($data['notes']) && strlen($data['notes']) > 2000) {
            $errors['notes'] = 'الملاحظات يجب ألا تتجاوز 2000 حرف';
        }

        // 12. التحقق من is_active (إذا تم تقديمه)
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
        // إزالة المسافات والشرطات
        $cleaned = preg_replace('/[\s\-\(\)]+/', '', $phoneNumber);

        // التحقق من الطول (7-15 رقم)
        $length = strlen($cleaned);
        if ($length < 7 || $length > 15) {
            return false;
        }

        // التحقق من أن الرقم يحتوي على أرقام فقط (مع + اختياري في البداية)
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

        error_log('[SUPPLIER_CONTROLLER] Current user ID not found');
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
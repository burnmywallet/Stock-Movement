<?php

/**
 * ================================================================
 * Logistox - Unit Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/UnitController.php
 * الوظيفة: إدارة وحدات القياس (CRUD)
 *
 * المسؤوليات:
 * 1. عرض قائمة الوحدات مع الفلاتر (index)
 * 2. جلب الوحدات النشطة (active)
 * 3. إضافة وحدة جديدة (store)
 * 4. عرض تفاصيل وحدة (show)
 * 5. تعديل وحدة (update)
 * 6. حذف وحدة - Soft Delete (destroy)
 * 7. التحقق من صحة البيانات (Validation)
 * 8. تسجيل كل العمليات في audit_logs
 *
 * ملاحظات هامة:
 * - يعتمد على UnitService لتنفيذ منطق الأعمال
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يمنع حذف وحدة لها منتجات مرتبطة
 * - يتحقق من تفرد code, name, symbol
 * - الوحدات ليست هرمية (لا parent_id)
 * - symbol حقل مطلوب وفريد (مثل: قطعة، كجم، جم، لتر)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\UnitService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class UnitController
 *
 * Controller لإدارة وحدات القياس
 */
class UnitController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var UnitService خدمة الوحدات
     */
    private UnitService $unitService;

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
            $this->unitService = new UnitService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[UNIT_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة القياسات');
        }
    }

    // =========================================================================
    // 1. عرض قائمة الوحدات (Index)
    // =========================================================================

    /**
     * عرض قائمة الوحدات مع الفلاتر
     *
     * GET /api/units
     *
     * Query Parameters:
     * - search: بحث في name, code, symbol
     * - is_active: تصفية حسب الحالة (1 = نشط، 0 = معطل)
     * - sort_by: ترتيب حسب (name, code, symbol, created_at, updated_at)
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
            $units = $this->unitService->list($filters);

            // 4. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب قائمة الوحدات بنجاح',
                data: [
                    'count' => count($units),
                    'units' => $units,
                ]
            );

        } catch (Throwable $e) {
            error_log('[UNIT_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة الوحدات');
        }
    }

    // =========================================================================
    // 2. الوحدات النشطة (Active)
    // =========================================================================

    /**
     * جلب الوحدات النشطة فقط (للقوائم المنسدلة)
     *
     * GET /api/units/active
     *
     * @return void يرسل استجابة JSON
     */
    public function active(): void
    {
        try {
            $units = $this->unitService->getActiveUnits();

            Response::success(
                message: 'تم جلب الوحدات النشطة بنجاح',
                data: [
                    'count' => count($units),
                    'units' => $units,
                ]
            );

        } catch (Throwable $e) {
            error_log('[UNIT_CONTROLLER] Active failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الوحدات النشطة');
        }
    }

    // =========================================================================
    // 3. إضافة وحدة جديدة (Store)
    // =========================================================================

    /**
     * إضافة وحدة قياس جديدة
     *
     * POST /api/units
     *
     * Request Body (JSON):
     * {
     *   "code": "U-007",          // اختياري - فريد
     *   "name": "طن",             // مطلوب - فريد
     *   "symbol": "طن",           // مطلوب - فريد
     *   "is_active": true         // اختياري - افتراضي: true
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
            $validationErrors = $this->validateUnitData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات الوحدة غير صالحة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إضافة الوحدة
            $unitId = $this->unitService->create($input, $userId);

            // 5. جلب بيانات الوحدة المضافة
            $unit = $this->unitService->getById($unitId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'UNIT_CREATE',
                entityType: 'unit',
                entityId: $unitId,
                newValues: $input,
                description: "تم إضافة وحدة قياس جديدة: {$unit['name']} ({$unit['symbol']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::created(
                message: 'تم إضافة الوحدة بنجاح',
                data: ['unit' => $unit],
                location: "/api/units/{$unitId}"
            );

        } catch (Throwable $e) {
            error_log('[UNIT_CONTROLLER] Store failed: ' . $e->getMessage());

            // معالجة أخطاء التفرد
            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            Response::internalError('فشل في إضافة الوحدة: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 4. عرض تفاصيل وحدة (Show)
    // =========================================================================

    /**
     * عرض تفاصيل وحدة معينة
     *
     * GET /api/units/{id}
     *
     * @param array $params المعاملات من Router (مثل ['id' => 5])
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            // 1. التحقق من معرف الوحدة
            $unitId = $this->validateUnitId($params);

            // 2. جلب بيانات الوحدة
            $unit = $this->unitService->getById($unitId);

            if (!$unit) {
                Response::notFound('الوحدة غير موجودة');
            }

            // 3. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب تفاصيل الوحدة بنجاح',
                data: [
                    'unit' => $unit,
                ]
            );

        } catch (Throwable $e) {
            error_log('[UNIT_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل الوحدة');
        }
    }

    // =========================================================================
    // 5. تعديل وحدة (Update)
    // =========================================================================

    /**
     * تعديل وحدة موجودة
     *
     * PUT /api/units/{id}
     * PATCH /api/units/{id}
     *
     * Request Body (JSON):
     * {
     *   "name": "طن متري",        // اختياري
     *   "code": "U-007-NEW",      // اختياري
     *   "symbol": "طن",           // اختياري
     *   "is_active": false        // اختياري
     * }
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            // 1. التحقق من معرف الوحدة
            $unitId = $this->validateUnitId($params);

            // 2. جلب البيانات القديمة (للتدقيق)
            $oldUnit = $this->unitService->getById($unitId);
            if (!$oldUnit) {
                Response::notFound('الوحدة غير موجودة');
            }

            // 3. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 4. التحقق من البيانات
            $validationErrors = $this->validateUnitData($input, isNew: false, excludeId: $unitId);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // 5. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 6. تعديل الوحدة
            $this->unitService->update($unitId, $input, $userId);

            // 7. جلب البيانات الجديدة
            $newUnit = $this->unitService->getById($unitId);

            // 8. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'UNIT_UPDATE',
                entityType: 'unit',
                entityId: $unitId,
                oldValues: $oldUnit,
                newValues: $newUnit,
                description: "تم تعديل الوحدة: {$newUnit['name']} ({$newUnit['symbol']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 9. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تعديل الوحدة بنجاح',
                data: ['unit' => $newUnit]
            );

        } catch (Throwable $e) {
            error_log('[UNIT_CONTROLLER] Update failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            Response::internalError('فشل في تعديل الوحدة: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 6. حذف وحدة (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف وحدة (Soft Delete)
     *
     * DELETE /api/units/{id}
     *
     * ملاحظات:
     * - لا يتم الحذف الفعلي من قاعدة البيانات
     * - يتم تعيين deleted_at = NOW()
     * - يمنع حذف وحدة لها منتجات مرتبطة
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            // 1. التحقق من معرف الوحدة
            $unitId = $this->validateUnitId($params);

            // 2. جلب بيانات الوحدة
            $unit = $this->unitService->getById($unitId);
            if (!$unit) {
                Response::notFound('الوحدة غير موجودة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. حذف الوحدة (Soft Delete)
            $this->unitService->delete($unitId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'UNIT_DELETE',
                entityType: 'unit',
                entityId: $unitId,
                oldValues: $unit,
                description: "تم حذف الوحدة (Soft Delete): {$unit['name']} ({$unit['symbol']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم حذف الوحدة بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[UNIT_CONTROLLER] Destroy failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'منتجات')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف الوحدة');
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
     * التحقق من صحة معرف الوحدة
     *
     * @param array $params المعاملات من Router
     * @return int معرف الوحدة الصحيح
     */
    private function validateUnitId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف الوحدة غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات الوحدة
     *
     * @param array $data البيانات المراد التحقق منها
     * @param bool $isNew هل هي وحدة جديدة أم تعديل
     * @param int|null $excludeId استثناء وحدة معينة (للتحديث)
     * @return array مصفوفة أخطاء التحقق
     */
    private function validateUnitData(array $data, bool $isNew = true, ?int $excludeId = null): array
    {
        $errors = [];

        // 1. التحقق من الحقول المطلوبة (للوحدات الجديدة فقط)
        if ($isNew) {
            if (empty($data['name'])) {
                $errors['name'] = 'اسم الوحدة مطلوب';
            } elseif (strlen($data['name']) > 50) {
                $errors['name'] = 'اسم الوحدة يجب ألا يتجاوز 50 حرفاً';
            } elseif (!$this->unitService->isNameUnique($data['name'])) {
                $errors['name'] = 'اسم الوحدة مستخدم بالفعل';
            }

            if (empty($data['symbol'])) {
                $errors['symbol'] = 'رمز الوحدة مطلوب';
            } elseif (strlen($data['symbol']) > 10) {
                $errors['symbol'] = 'رمز الوحدة يجب ألا يتجاوز 10 أحرف';
            } elseif (!$this->unitService->isSymbolUnique($data['symbol'])) {
                $errors['symbol'] = 'رمز الوحدة مستخدم بالفعل';
            }
        }

        // 2. التحقق من name (إذا تم تقديمه)
        if (!empty($data['name'])) {
            if (strlen($data['name']) > 50) {
                $errors['name'] = 'اسم الوحدة يجب ألا يتجاوز 50 حرفاً';
            } elseif (!$this->unitService->isNameUnique($data['name'], $excludeId)) {
                $errors['name'] = 'اسم الوحدة مستخدم بالفعل';
            }
        }

        // 3. التحقق من symbol (إذا تم تقديمه)
        if (!empty($data['symbol'])) {
            if (strlen($data['symbol']) > 10) {
                $errors['symbol'] = 'رمز الوحدة يجب ألا يتجاوز 10 أحرف';
            } elseif (!$this->unitService->isSymbolUnique($data['symbol'], $excludeId)) {
                $errors['symbol'] = 'رمز الوحدة مستخدم بالفعل';
            }
        }

        // 4. التحقق من code (إذا تم تقديمه)
        if (!empty($data['code'])) {
            if (strlen($data['code']) > 50) {
                $errors['code'] = 'كود الوحدة يجب ألا يتجاوز 50 حرفاً';
            } elseif (!$this->unitService->isCodeUnique($data['code'], $excludeId)) {
                $errors['code'] = 'كود الوحدة مستخدم بالفعل';
            }
        }

        // 5. التحقق من is_active (إذا تم تقديمه)
        if (isset($data['is_active']) && $data['is_active'] !== null) {
            if (!in_array($data['is_active'], [0, 1, true, false, '0', '1'], true)) {
                $errors['is_active'] = 'is_active يجب أن يكون 0 أو 1';
            }
        }

        return $errors;
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

        error_log('[UNIT_CONTROLLER] Current user ID not found');
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
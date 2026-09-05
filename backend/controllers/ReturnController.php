<?php

/**
 * ================================================================
 * Logistox - Return Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/ReturnController.php
 * الوظيفة: إدارة المرتجعات (CRUD + تنفيذ فوري)
 *
 * أنواع المرتجعات:
 * - IN (وارد): بضاعة تعود إلى المخزن
 * - OUT (صادر): بضاعة تُرد إلى المورد
 *
 * الصلاحيات المطلوبة:
 * - returns.view: عرض المرتجعات
 * - returns.create: إنشاء مرتجع
 * - returns.update: تعديل مرتجع
 * - returns.delete: حذف مرتجع
 *
 * ملاحظات هامة:
 * - يعتمد على ReturnService لتنفيذ منطق الأعمال
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - المرتجعات تُنفذ فوراً (لا workflow)
 * - لا يمكن تعديل الكمية أو المنتج أو المخزن بعد الإنشاء
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\ReturnService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class ReturnController
 *
 * Controller لإدارة المرتجعات
 */
class ReturnController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var ReturnService خدمة المرتجعات
     */
    private ReturnService $returnService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var array أنواع المرتجعات المسموحة
     */
    private const ALLOWED_TYPES = ['IN', 'OUT'];

    /**
     * @var array أسماء الأنواع بالعربية
     */
    private const TYPE_LABELS = [
        'IN'  => 'مرتجع وارد',
        'OUT' => 'مرتجع صادر',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->returnService = new ReturnService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[RETURN_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة المرتجعات');
        }
    }

    // =========================================================================
    // 1. عرض قائمة المرتجعات (Index)
    // =========================================================================

    /**
     * عرض قائمة المرتجعات مع الفلاتر
     *
     * GET /api/returns
     *
     * Query Parameters:
     * - search: بحث في return_number, reason, product_name, product_code
     * - return_type: تصفية حسب النوع (IN, OUT)
     * - warehouse_id: تصفية حسب المخزن
     * - product_id: تصفية حسب المنتج
     * - from_date: من تاريخ (YYYY-MM-DD)
     * - to_date: إلى تاريخ (YYYY-MM-DD)
     * - sort_by: ترتيب حسب (return_number, created_at, return_type, quantity, total_cost)
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
                'return_type'  => $_GET['return_type'] ?? null,
                'warehouse_id' => !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null,
                'product_id'   => !empty($_GET['product_id']) ? (int) $_GET['product_id'] : null,
                'from_date'    => $_GET['from_date'] ?? null,
                'to_date'      => $_GET['to_date'] ?? null,
                'sort_by'      => $_GET['sort_by'] ?? 'created_at',
                'sort_order'   => strtolower($_GET['sort_order'] ?? 'desc'),
            ];

            // 2. جلب البيانات
            $returns = $this->returnService->list($filters);

            // 3. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب قائمة المرتجعات بنجاح',
                data: [
                    'count'  => count($returns),
                    'returns'=> $returns,
                    'types'  => self::TYPE_LABELS,
                ]
            );

        } catch (Throwable $e) {
            error_log('[RETURN_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة المرتجعات');
        }
    }

    // =========================================================================
    // 2. إضافة مرتجع جديد (Store) - يُنفذ فوراً
    // =========================================================================

    /**
     * إضافة مرتجع جديد وتنفيذه فوراً
     *
     * POST /api/returns
     *
     * Request Body (JSON):
     * {
     *   "return_type": "IN",
     *   "product_id": 1,
     *   "warehouse_id": 1,
     *   "quantity": 10,
     *   "unit_cost": 150.50,
     *   "reason": "مرتجع من العميل بسبب سوء التخزين",
     *   "reference_type": "issue",
     *   "reference_id": 5
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
            $validationErrors = $this->validateReturnData($input);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات المرتجع غير صالحة');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. إضافة المرتجع (يُنفذ فوراً ويحدث المخزون)
            $returnId = $this->returnService->create($input, $userId);

            // 5. جلب بيانات المرتجع المضاف
            $return = $this->returnService->getById($returnId);

            // 6. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RETURN_CREATE',
                entityType: 'return',
                entityId: $returnId,
                newValues: [
                    'return_number' => $return['return_number'],
                    'return_type'   => $return['return_type'],
                    'product_id'    => $return['product_id'],
                    'warehouse_id'  => $return['warehouse_id'],
                    'quantity'      => $return['quantity'],
                    'unit_cost'     => $return['unit_cost'],
                    'total_cost'    => $return['total_cost'],
                ],
                description: "تم إنشاء مرتجع {$return['return_type_label']}: {$return['return_number']} - " .
                             "{$return['product_name']} ({$return['quantity']} {$return['unit_symbol']}) " .
                             "في {$return['warehouse_name']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 7. إرجاع الاستجابة الناجحة
            Response::created(
                message: "تم إنشاء المرتجع وتحديث المخزون بنجاح",
                data: ['return' => $return],
                location: "/api/returns/{$returnId}"
            );

        } catch (Throwable $e) {
            error_log('[RETURN_CONTROLLER] Store failed: ' . $e->getMessage());

            // معالجة أخطاء خاصة
            if (str_contains($e->getMessage(), 'غير موجود')) {
                Response::notFound($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'رصيد غير كافٍ')) {
                Response::badRequest($e->getMessage());
            }

            Response::internalError('فشل في إنشاء المرتجع: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. عرض تفاصيل مرتجع (Show)
    // =========================================================================

    /**
     * عرض تفاصيل مرتجع معين
     *
     * GET /api/returns/{id}
     *
     * @param array $params المعاملات من Router (مثل ['id' => 5])
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            // 1. التحقق من معرف المرتجع
            $returnId = $this->validateReturnId($params);

            // 2. جلب بيانات المرتجع
            $return = $this->returnService->getById($returnId);

            if (!$return) {
                Response::notFound('المرتجع غير موجود');
            }

            // 3. إرجاع الاستجابة
            Response::success(
                message: 'تم جلب تفاصيل المرتجع بنجاح',
                data: [
                    'return' => $return,
                ]
            );

        } catch (Throwable $e) {
            error_log('[RETURN_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل المرتجع');
        }
    }

    // =========================================================================
    // 4. تعديل مرتجع (Update)
    // =========================================================================

    /**
     * تعديل مرتجع موجود
     *
     * PUT /api/returns/{id}
     * PATCH /api/returns/{id}
     *
     * ملاحظة: يمكن تعديل reason و unit_cost فقط
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            // 1. التحقق من معرف المرتجع
            $returnId = $this->validateReturnId($params);

            // 2. جلب البيانات القديمة (للتدقيق)
            $oldReturn = $this->returnService->getById($returnId);
            if (!$oldReturn) {
                Response::notFound('المرتجع غير موجود');
            }

            // 3. قراءة بيانات الطلب
            $input = $this->getJsonInput();

            // 4. التحقق من البيانات (فقط الحقول المسموحة)
            $validationErrors = $this->validateReturnUpdateData($input);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            // 5. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 6. تعديل المرتجع
            $this->returnService->update($returnId, $input, $userId);

            // 7. جلب البيانات الجديدة
            $newReturn = $this->returnService->getById($returnId);

            // 8. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RETURN_UPDATE',
                entityType: 'return',
                entityId: $returnId,
                oldValues: [
                    'reason'    => $oldReturn['reason'],
                    'unit_cost' => $oldReturn['unit_cost'],
                    'total_cost'=> $oldReturn['total_cost'],
                ],
                newValues: [
                    'reason'    => $newReturn['reason'],
                    'unit_cost' => $newReturn['unit_cost'],
                    'total_cost'=> $newReturn['total_cost'],
                ],
                description: "تم تعديل المرتجع: {$newReturn['return_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 9. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم تعديل المرتجع بنجاح',
                data: ['return' => $newReturn]
            );

        } catch (Throwable $e) {
            error_log('[RETURN_CONTROLLER] Update failed: ' . $e->getMessage());
            Response::internalError('فشل في تعديل المرتجع: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 5. حذف مرتجع (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف مرتجع (Soft Delete)
     *
     * DELETE /api/returns/{id}
     *
     * ملاحظة: الحذف لا يعكس حركة المخزون
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            // 1. التحقق من معرف المرتجع
            $returnId = $this->validateReturnId($params);

            // 2. جلب بيانات المرتجع
            $return = $this->returnService->getById($returnId);
            if (!$return) {
                Response::notFound('المرتجع غير موجود');
            }

            // 3. جلب معرف المستخدم الحالي
            $userId = $this->getCurrentUserId();

            // 4. حذف المرتجع (Soft Delete)
            $this->returnService->delete($returnId);

            // 5. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $userId,
                action: 'RETURN_DELETE',
                entityType: 'return',
                entityId: $returnId,
                oldValues: [
                    'return_number' => $return['return_number'],
                    'return_type'   => $return['return_type'],
                    'product_name'  => $return['product_name'],
                    'quantity'      => $return['quantity'],
                ],
                description: "تم حذف المرتجع (Soft Delete): {$return['return_number']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. إرجاع الاستجابة الناجحة
            Response::success(
                message: 'تم حذف المرتجع بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[RETURN_CONTROLLER] Destroy failed: ' . $e->getMessage());
            Response::internalError('فشل في حذف المرتجع');
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
     * التحقق من صحة معرف المرتجع
     */
    private function validateReturnId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف المرتجع غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات المرتجع (للإنشاء)
     */
    private function validateReturnData(array $data): array
    {
        $errors = [];

        // 1. return_type (مطلوب)
        if (empty($data['return_type'])) {
            $errors['return_type'] = 'نوع المرتجع مطلوب';
        } elseif (!in_array(strtoupper($data['return_type']), self::ALLOWED_TYPES, true)) {
            $errors['return_type'] = 'نوع المرتجع غير صالح. القيم المسموحة: ' . implode(', ', self::ALLOWED_TYPES);
        }

        // 2. product_id (مطلوب)
        if (empty($data['product_id'])) {
            $errors['product_id'] = 'معرف المنتج مطلوب';
        } elseif (!is_numeric($data['product_id']) || (int) $data['product_id'] <= 0) {
            $errors['product_id'] = 'معرف المنتج غير صالح';
        }

        // 3. warehouse_id (مطلوب)
        if (empty($data['warehouse_id'])) {
            $errors['warehouse_id'] = 'المخزن مطلوب';
        } elseif (!is_numeric($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
            $errors['warehouse_id'] = 'معرف المخزن غير صالح';
        }

        // 4. quantity (مطلوب، موجب)
        if (!isset($data['quantity']) || $data['quantity'] === null || $data['quantity'] === '') {
            $errors['quantity'] = 'الكمية مطلوبة';
        } elseif (!is_numeric($data['quantity']) || (float) $data['quantity'] <= 0) {
            $errors['quantity'] = 'الكمية يجب أن تكون رقماً موجباً';
        }

        // 5. unit_cost (اختياري، غير سالب)
        if (isset($data['unit_cost']) && $data['unit_cost'] !== null && $data['unit_cost'] !== '') {
            if (!is_numeric($data['unit_cost']) || (float) $data['unit_cost'] < 0) {
                $errors['unit_cost'] = 'سعر الوحدة يجب أن يكون رقماً غير سالب';
            }
        }

        // 6. reason (اختياري)
        if (!empty($data['reason']) && strlen($data['reason']) > 2000) {
            $errors['reason'] = 'السبب يجب ألا يتجاوز 2000 حرف';
        }

        // 7. reference_type (اختياري)
        if (!empty($data['reference_type'])) {
            $allowedRefTypes = ['receipt', 'issue', 'transfer'];
            if (!in_array(strtolower($data['reference_type']), $allowedRefTypes, true)) {
                $errors['reference_type'] = 'نوع المستند المرجعي غير صالح. القيم المسموحة: ' . implode(', ', $allowedRefTypes);
            }
        }

        // 8. reference_id (اختياري، لكن مطلوب إذا تم تقديم reference_type)
        if (!empty($data['reference_type']) && empty($data['reference_id'])) {
            $errors['reference_id'] = 'معرف المستند المرجعي مطلوب عند تقديم نوع المستند';
        } elseif (!empty($data['reference_id'])) {
            if (!is_numeric($data['reference_id']) || (int) $data['reference_id'] <= 0) {
                $errors['reference_id'] = 'معرف المستند المرجعي غير صالح';
            }
        }

        return $errors;
    }

    /**
     * التحقق من صحة بيانات التعديل
     */
    private function validateReturnUpdateData(array $data): array
    {
        $errors = [];

        // 1. reason (اختياري)
        if (isset($data['reason']) && !empty($data['reason']) && strlen($data['reason']) > 2000) {
            $errors['reason'] = 'السبب يجب ألا يتجاوز 2000 حرف';
        }

        // 2. unit_cost (اختياري، غير سالب)
        if (isset($data['unit_cost']) && $data['unit_cost'] !== null && $data['unit_cost'] !== '') {
            if (!is_numeric($data['unit_cost']) || (float) $data['unit_cost'] < 0) {
                $errors['unit_cost'] = 'سعر الوحدة يجب أن يكون رقماً غير سالب';
            }
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

        error_log('[RETURN_CONTROLLER] Current user ID not found');
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
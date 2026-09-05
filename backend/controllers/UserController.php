<?php

/**
 * ================================================================
 * Logistox - User Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/UserController.php
 * الوظيفة: إدارة المستخدمين (CRUD + Reset Password + Activate/Deactivate)
 *
 * الصلاحيات المطلوبة:
 * - users.view: عرض المستخدمين
 * - users.create: إنشاء مستخدم
 * - users.update: تعديل مستخدم
 * - users.delete: حذف مستخدم
 * - users.manage: إدارة المستخدمين (reset password, activate/deactivate)
 *
 * ملاحظات هامة:
 * - يعتمد على UserService لتنفيذ منطق الأعمال
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يحمي المستخدم الرئيسي (admin) من الحذف أو تغيير الدور
 * - كلمة المرور تُشفّر باستخدام BCrypt
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\UserService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class UserController
 *
 * Controller لإدارة المستخدمين
 */
class UserController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var UserService خدمة المستخدمين
     */
    private UserService $userService;

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
            $this->userService = new UserService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[USER_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة المستخدمين');
        }
    }

    // =========================================================================
    // 1. عرض قائمة المستخدمين (Index)
    // =========================================================================

    /**
     * عرض قائمة المستخدمين مع الفلاتر
     *
     * GET /api/users
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            $filters = [
                'search'       => trim($_GET['search'] ?? ''),
                'role_id'      => !empty($_GET['role_id']) ? (int) $_GET['role_id'] : null,
                'warehouse_id' => !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null,
                'is_active'    => isset($_GET['is_active']) ? (int) $_GET['is_active'] : null,
                'sort_by'      => $_GET['sort_by'] ?? 'created_at',
                'sort_order'   => strtolower($_GET['sort_order'] ?? 'desc'),
            ];

            $users = $this->userService->list($filters);

            Response::success(
                message: 'تم جلب قائمة المستخدمين بنجاح',
                data: [
                    'count' => count($users),
                    'users' => $users,
                ]
            );

        } catch (Throwable $e) {
            error_log('[USER_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة المستخدمين');
        }
    }

    // =========================================================================
    // 2. إضافة مستخدم جديد (Store)
    // =========================================================================

    /**
     * إضافة مستخدم جديد
     *
     * POST /api/users
     *
     * @return void يرسل استجابة JSON
     */
    public function store(): void
    {
        try {
            $input = $this->getJsonInput();

            $validationErrors = $this->validateUserData($input, isNew: true);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات المستخدم غير صالحة');
            }

            $currentUserId = $this->getCurrentUserId();
            $userId = $this->userService->create($input, $currentUserId);
            $user = $this->userService->getById($userId);

            $this->auditService->log(
                userId: $currentUserId,
                action: 'USER_CREATE',
                entityType: 'user',
                entityId: $userId,
                newValues: [
                    'username'  => $user['username'],
                    'full_name' => $user['full_name'],
                    'role_id'   => $user['role_id'],
                    'email'     => $user['email'],
                ],
                description: "تم إنشاء مستخدم جديد: {$user['username']} ({$user['full_name']}) - الدور: {$user['role_display_name']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::created(
                message: 'تم إنشاء المستخدم بنجاح',
                data: ['user' => $user],
                location: "/api/users/{$userId}"
            );

        } catch (Throwable $e) {
            error_log('[USER_CONTROLLER] Store failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'غير موجود')) {
                Response::notFound($e->getMessage());
            }

            Response::internalError('فشل في إنشاء المستخدم: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. عرض تفاصيل مستخدم (Show)
    // =========================================================================

    /**
     * عرض تفاصيل مستخدم معين
     *
     * GET /api/users/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            $userId = $this->validateUserId($params);
            $user = $this->userService->getById($userId);

            if (!$user) {
                Response::notFound('المستخدم غير موجود');
            }

            Response::success(
                message: 'تم جلب تفاصيل المستخدم بنجاح',
                data: ['user' => $user]
            );

        } catch (Throwable $e) {
            error_log('[USER_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل المستخدم');
        }
    }

    // =========================================================================
    // 4. تعديل مستخدم (Update)
    // =========================================================================

    /**
     * تعديل مستخدم موجود
     *
     * PUT /api/users/{id}
     * PATCH /api/users/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function update(array $params): void
    {
        try {
            $userId = $this->validateUserId($params);

            $oldUser = $this->userService->getById($userId);
            if (!$oldUser) {
                Response::notFound('المستخدم غير موجود');
            }

            $input = $this->getJsonInput();
            $validationErrors = $this->validateUserData($input, isNew: false, excludeId: $userId);
            if (!empty($validationErrors)) {
                Response::validationError($validationErrors, 'بيانات التعديل غير صالحة');
            }

            $currentUserId = $this->getCurrentUserId();
            $this->userService->update($userId, $input, $currentUserId);
            $newUser = $this->userService->getById($userId);

            $this->auditService->log(
                userId: $currentUserId,
                action: 'USER_UPDATE',
                entityType: 'user',
                entityId: $userId,
                oldValues: [
                    'full_name' => $oldUser['full_name'],
                    'email'     => $oldUser['email'],
                    'role_id'   => $oldUser['role_id'],
                ],
                newValues: [
                    'full_name' => $newUser['full_name'],
                    'email'     => $newUser['email'],
                    'role_id'   => $newUser['role_id'],
                ],
                description: "تم تعديل المستخدم: {$newUser['username']} ({$newUser['full_name']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم تعديل المستخدم بنجاح',
                data: ['user' => $newUser]
            );

        } catch (Throwable $e) {
            error_log('[USER_CONTROLLER] Update failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن تغيير دور')) {
                Response::forbidden($e->getMessage());
            }

            if (str_contains($e->getMessage(), 'مستخدم بالفعل')) {
                Response::conflict($e->getMessage());
            }

            Response::internalError('فشل في تعديل المستخدم: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 5. حذف مستخدم (Destroy - Soft Delete)
    // =========================================================================

    /**
     * حذف مستخدم (Soft Delete)
     *
     * DELETE /api/users/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            $userId = $this->validateUserId($params);

            $user = $this->userService->getById($userId);
            if (!$user) {
                Response::notFound('المستخدم غير موجود');
            }

            $currentUserId = $this->getCurrentUserId();
            $this->userService->delete($userId);

            $this->auditService->log(
                userId: $currentUserId,
                action: 'USER_DELETE',
                entityType: 'user',
                entityId: $userId,
                oldValues: [
                    'username'  => $user['username'],
                    'full_name' => $user['full_name'],
                    'role_name' => $user['role_display_name'],
                ],
                description: "تم حذف المستخدم (Soft Delete): {$user['username']} ({$user['full_name']})",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم حذف المستخدم بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[USER_CONTROLLER] Destroy failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'لا يمكن حذف')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في حذف المستخدم');
        }
    }

    // =========================================================================
    // 6. إعادة تعيين كلمة المرور (Reset Password)
    // =========================================================================

    /**
     * إعادة تعيين كلمة المرور للمستخدم
     *
     * POST /api/users/{id}/reset-password
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function resetPassword(array $params): void
    {
        try {
            $userId = $this->validateUserId($params);

            $user = $this->userService->getById($userId);
            if (!$user) {
                Response::notFound('المستخدم غير موجود');
            }

            $input = $this->getJsonInput();
            $newPassword = $input['new_password'] ?? null;

            $currentUserId = $this->getCurrentUserId();
            $generatedPassword = $this->userService->resetPassword($userId, $newPassword);

            $this->auditService->log(
                userId: $currentUserId,
                action: 'USER_RESET_PASSWORD',
                entityType: 'user',
                entityId: $userId,
                description: "تم إعادة تعيين كلمة المرور للمستخدم: {$user['username']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // إرجاع كلمة المرور الجديدة (فقط للمدير)
            Response::success(
                message: 'تم إعادة تعيين كلمة المرور بنجاح',
                data: [
                    'user_id'         => $userId,
                    'new_password'    => $generatedPassword,
                    'must_change'     => true,
                    'message_to_user' => "يرجى إبلاغ المستخدم {$user['username']} بكلمة المرور الجديدة وطلب تغييرها عند أول تسجيل دخول.",
                ]
            );

        } catch (Throwable $e) {
            error_log('[USER_CONTROLLER] ResetPassword failed: ' . $e->getMessage());
            Response::internalError('فشل في إعادة تعيين كلمة المرور: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 7. تفعيل/تعطيل حساب (Set Active)
    // =========================================================================

    /**
     * تفعيل أو تعطيل حساب المستخدم
     *
     * POST /api/users/{id}/set-active
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function setActive(array $params): void
    {
        try {
            $userId = $this->validateUserId($params);

            $user = $this->userService->getById($userId);
            if (!$user) {
                Response::notFound('المستخدم غير موجود');
            }

            $input = $this->getJsonInput();

            if (!isset($input['is_active'])) {
                Response::badRequest('حقل is_active مطلوب');
            }

            $isActive = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);

            $currentUserId = $this->getCurrentUserId();
            $this->userService->setActive($userId, $isActive, $currentUserId);

            $action = $isActive ? 'تفعيل' : 'تعطيل';

            $this->auditService->log(
                userId: $currentUserId,
                action: 'USER_SET_ACTIVE',
                entityType: 'user',
                entityId: $userId,
                oldValues: ['is_active' => $user['is_active']],
                newValues: ['is_active' => $isActive],
                description: "تم {$action} حساب المستخدم: {$user['username']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: "تم {$action} الحساب بنجاح",
                data: [
                    'user_id'   => $userId,
                    'is_active' => $isActive,
                ]
            );

        } catch (Throwable $e) {
            error_log('[USER_CONTROLLER] SetActive failed: ' . $e->getMessage());
            Response::internalError('فشل في تحديث حالة الحساب: ' . $e->getMessage());
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
     * التحقق من صحة معرف المستخدم
     */
    private function validateUserId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف المستخدم غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * التحقق من صحة بيانات المستخدم
     */
    private function validateUserData(array $data, bool $isNew = true, ?int $excludeId = null): array
    {
        $errors = [];

        // 1. username (مطلوب للجديد)
        if ($isNew) {
            if (empty($data['username'])) {
                $errors['username'] = 'اسم المستخدم مطلوب';
            } elseif (strlen($data['username']) < 3) {
                $errors['username'] = 'اسم المستخدم يجب أن يكون 3 أحرف على الأقل';
            } elseif (strlen($data['username']) > 50) {
                $errors['username'] = 'اسم المستخدم يجب ألا يتجاوز 50 حرفاً';
            } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
                $errors['username'] = 'اسم المستخدم يجب أن يحتوي على أحرف وأرقام وشرطة سفلية فقط';
            } elseif ($this->userService->isUsernameTaken($data['username'])) {
                $errors['username'] = 'اسم المستخدم مستخدم بالفعل';
            }
        }

        // 2. full_name (مطلوب)
        if ($isNew || isset($data['full_name'])) {
            if (empty($data['full_name'])) {
                $errors['full_name'] = 'الاسم الكامل مطلوب';
            } elseif (strlen($data['full_name']) > 100) {
                $errors['full_name'] = 'الاسم الكامل يجب ألا يتجاوز 100 حرف';
            }
        }

        // 3. password (مطلوب للجديد)
        if ($isNew) {
            if (empty($data['password'])) {
                $errors['password'] = 'كلمة المرور مطلوبة';
            } elseif (strlen($data['password']) < 8) {
                $errors['password'] = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
            }
        }

        // 4. email (اختياري)
        if (!empty($data['email'])) {
            if (strlen($data['email']) > 100) {
                $errors['email'] = 'البريد الإلكتروني يجب ألا يتجاوز 100 حرف';
            } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'البريد الإلكتروني غير صالح';
            } elseif ($this->userService->isEmailTaken($data['email'], $excludeId)) {
                $errors['email'] = 'البريد الإلكتروني مستخدم بالفعل';
            }
        }

        // 5. phone (اختياري)
        if (!empty($data['phone'])) {
            if (strlen($data['phone']) > 20) {
                $errors['phone'] = 'رقم الهاتف يجب ألا يتجاوز 20 رقماً';
            } elseif ($this->userService->isPhoneTaken($data['phone'], $excludeId)) {
                $errors['phone'] = 'رقم الهاتف مستخدم بالفعل';
            }
        }

        // 6. role_id (مطلوب)
        if ($isNew || isset($data['role_id'])) {
            if (empty($data['role_id'])) {
                $errors['role_id'] = 'الدور مطلوب';
            } elseif (!is_numeric($data['role_id']) || (int) $data['role_id'] <= 0) {
                $errors['role_id'] = 'معرف الدور غير صالح';
            }
        }

        // 7. warehouse_id (اختياري)
        if (isset($data['warehouse_id']) && $data['warehouse_id'] !== null && $data['warehouse_id'] !== '') {
            if (!is_numeric($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
                $errors['warehouse_id'] = 'معرف المخزن غير صالح';
            }
        }

        // 8. is_active (اختياري)
        if (isset($data['is_active']) && $data['is_active'] !== null) {
            if (!in_array($data['is_active'], [0, 1, true, false, '0', '1'], true)) {
                $errors['is_active'] = 'is_active يجب أن يكون 0 أو 1';
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

        error_log('[USER_CONTROLLER] Current user ID not found');
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
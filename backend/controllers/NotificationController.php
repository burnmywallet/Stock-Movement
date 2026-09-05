<?php

/**
 * ================================================================
 * Logistox - Notification Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/NotificationController.php
 * الوظيفة: إدارة إشعارات المستخدم الحالي
 *
 * المسؤوليات:
 * 1. عرض قائمة إشعارات المستخدم الحالي (index)
 * 2. عرض الإشعارات غير المقروءة فقط (unread)
 * 3. عداد الإشعارات غير المقروءة (unreadCount) - للـ Badge
 * 4. تعليم إشعار واحد كمقروء (markAsRead)
 * 5. تعليم عدة إشعارات كمقروءة (markMultipleAsRead) - Batch
 * 6. تعليم كل الإشعارات كمقروءة (markAllAsRead)
 * 7. حذف إشعار (destroy)
 * 8. حذف عدة إشعارات (destroyMultiple) - Batch
 * 9. حذف كل الإشعارات (destroyAll)
 *
 * الصلاحيات المطلوبة:
 * - لا تحتاج صلاحيات خاصة (أي مستخدم مسجل دخول يرى إشعاراته)
 * - ملاحظة: كل مستخدم يرى إشعاراته فقط (عزل كامل)
 *
 * أنواع الإشعارات المدعومة:
 * - info: معلومات عامة
 * - success: عملية ناجحة
 * - warning: تحذير
 * - error: خطأ
 * - low_stock: مخزون منخفض
 * - critical_stock: مخزون حرج
 *
 * قيود الأمان:
 * - منع IDOR: المستخدم لا يمكنه الوصول لإشعارات مستخدمين آخرين
 * - التحقق من ملكية الإشعار قبل كل عملية
 * - تسجيل كل العمليات في audit_logs
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\NotificationService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class NotificationController
 *
 * Controller لإدارة إشعارات المستخدم الحالي
 */
class NotificationController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var NotificationService خدمة الإشعارات
     */
    private NotificationService $notificationService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var array أنواع الإشعارات المسموحة
     */
    private const ALLOWED_TYPES = [
        'info',
        'success',
        'warning',
        'error',
        'low_stock',
        'critical_stock',
    ];

    /**
     * @var array أسماء الأنواع بالعربية
     */
    private const TYPE_LABELS = [
        'info'           => 'معلومات',
        'success'        => 'نجاح',
        'warning'        => 'تحذير',
        'error'          => 'خطأ',
        'low_stock'      => 'مخزون منخفض',
        'critical_stock' => 'مخزون حرج',
    ];

    /**
     * @var array أيقونات الأنواع (Font Awesome)
     */
    private const TYPE_ICONS = [
        'info'           => 'fa-info-circle',
        'success'        => 'fa-check-circle',
        'warning'        => 'fa-exclamation-triangle',
        'error'          => 'fa-times-circle',
        'low_stock'      => 'fa-arrow-down',
        'critical_stock' => 'fa-exclamation-circle',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->notificationService = new NotificationService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[NOTIFICATION_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة الإشعارات');
        }
    }

    // =========================================================================
    // 1. عرض قائمة الإشعارات (Index)
    // =========================================================================

    /**
     * عرض قائمة إشعارات المستخدم الحالي مع الفلاتر والترقيم
     *
     * GET /api/notifications
     *
     * Query Parameters:
     * - type: تصفية حسب النوع (info, success, warning, error, low_stock, critical_stock)
     * - is_read: تصفية حسب الحالة (0 = غير مقروء، 1 = مقروء)
     * - page: رقم الصفحة (افتراضي: 1)
     * - per_page: عدد العناصر في الصفحة (افتراضي: 20، حد أقصى: 100)
     * - search: بحث في العنوان والرسالة
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            $userId = $this->getCurrentUserId();

            // 1. قراءة Query Parameters
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
            $type = $_GET['type'] ?? null;
            $isRead = isset($_GET['is_read']) ? (int) $_GET['is_read'] : null;
            $search = trim($_GET['search'] ?? '');

            // 2. التحقق من صحة النوع
            if ($type !== null && !in_array($type, self::ALLOWED_TYPES, true)) {
                $type = null;
            }

            // 3. بناء الفلاتر
            $filters = [
                'user_id'  => $userId,
                'type'     => $type,
                'is_read'  => $isRead,
                'search'   => $search,
            ];

            // 4. جلب الإشعارات مع الترقيم
            $result = $this->notificationService->getUserNotificationsPaginated(
                $userId,
                $filters,
                $page,
                $perPage
            );

            // 5. إضافة معلومات إضافية لكل إشعار
            foreach ($result['notifications'] as &$notification) {
                $notification['type_label'] = self::TYPE_LABELS[$notification['type']] ?? $notification['type'];
                $notification['type_icon'] = self::TYPE_ICONS[$notification['type']] ?? 'fa-bell';
            }

            // 6. إرجاع الاستجابة
            Response::paginated(
                data: $result['notifications'],
                total: $result['total'],
                page: $page,
                perPage: $perPage,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[NOTIFICATION_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة الإشعارات');
        }
    }

    // =========================================================================
    // 2. الإشعارات غير المقروءة (Unread)
    // =========================================================================

    /**
     * عرض الإشعارات غير المقروءة فقط
     *
     * GET /api/notifications/unread
     *
     * Query Parameters:
     * - limit: حد النتائج (افتراضي: 20، حد أقصى: 100)
     *
     * @return void يرسل استجابة JSON
     */
    public function unread(): void
    {
        try {
            $userId = $this->getCurrentUserId();
            $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));

            $notifications = $this->notificationService->getUserNotifications($userId, $limit, true);

            // إضافة معلومات إضافية
            foreach ($notifications as &$notification) {
                $notification['type_label'] = self::TYPE_LABELS[$notification['type']] ?? $notification['type'];
                $notification['type_icon'] = self::TYPE_ICONS[$notification['type']] ?? 'fa-bell';
            }

            Response::success(
                message: 'تم جلب الإشعارات غير المقروءة بنجاح',
                data: [
                    'count'         => count($notifications),
                    'notifications' => $notifications,
                ]
            );

        } catch (Throwable $e) {
            error_log('[NOTIFICATION_CONTROLLER] Unread failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الإشعارات غير المقروءة');
        }
    }

    // =========================================================================
    // 3. عداد الإشعارات غير المقروءة (Unread Count) - للـ Badge
    // =========================================================================

    /**
     * جلب عدد الإشعارات غير المقروءة
     *
     * GET /api/notifications/unread-count
     *
     * هذه الدالة مهمة جداً لعرض Badge في الواجهة الأمامية
     * تُستدعى بشكل دوري (polling) أو عبر WebSocket
     *
     * @return void يرسل استجابة JSON
     */
    public function unreadCount(): void
    {
        try {
            $userId = $this->getCurrentUserId();

            $count = $this->notificationService->getUnreadCount($userId);

            // تقسيم العد حسب النوع (مفيد لعرض badges ملونة)
            $countByType = $this->notificationService->getUnreadCountByType($userId);

            Response::success(
                message: 'تم جلب عداد الإشعارات بنجاح',
                data: [
                    'total'        => $count,
                    'by_type'      => $countByType,
                    'has_unread'   => $count > 0,
                ]
            );

        } catch (Throwable $e) {
            error_log('[NOTIFICATION_CONTROLLER] UnreadCount failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب عداد الإشعارات');
        }
    }

    // =========================================================================
    // 4. تعليم إشعار كمقروء (Mark as Read)
    // =========================================================================

    /**
     * تعليم إشعار واحد كمقروء
     *
     * PATCH /api/notifications/{id}/read
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function markAsRead(array $params): void
    {
        try {
            $userId = $this->getCurrentUserId();
            $notificationId = $this->validateNotificationId($params);

            // التحقق من ملكية الإشعار
            $this->verifyOwnership($notificationId, $userId);

            // تعليم كمقروء
            $this->notificationService->markAsRead($notificationId, $userId);

            Response::success(
                message: 'تم تعليم الإشعار كمقروء بنجاح',
                data: [
                    'notification_id' => $notificationId,
                    'is_read'         => true,
                    'read_at'         => date('Y-m-d H:i:s'),
                ]
            );

        } catch (Throwable $e) {
            $this->handleNotificationError($e, 'MarkAsRead');
        }
    }

    // =========================================================================
    // 5. تعليم عدة إشعارات كمقروءة (Batch Mark as Read)
    // =========================================================================

    /**
     * تعليم عدة إشعارات كمقروءة دفعة واحدة
     *
     * POST /api/notifications/mark-multiple-read
     *
     * Request Body (JSON):
     * {
     *   "notification_ids": [1, 2, 3, 5, 8]
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function markMultipleAsRead(): void
    {
        try {
            $userId = $this->getCurrentUserId();
            $input = $this->getJsonInput();

            // 1. التحقق من وجود notification_ids
            if (empty($input['notification_ids']) || !is_array($input['notification_ids'])) {
                Response::badRequest('حقل notification_ids مطلوب ويجب أن يكون مصفوفة');
            }

            $notificationIds = $input['notification_ids'];

            // 2. التحقق من صحة المعرفات
            $validatedIds = [];
            foreach ($notificationIds as $id) {
                if (!is_numeric($id) || (int) $id <= 0) {
                    continue;
                }
                $validatedIds[] = (int) $id;
            }

            if (empty($validatedIds)) {
                Response::badRequest('لا توجد معرفات إشعارات صالحة');
            }

            // 3. حد أقصى 100 إشعار في العملية الواحدة
            if (count($validatedIds) > 100) {
                Response::badRequest('لا يمكن تعليم أكثر من 100 إشعار في العملية الواحدة');
            }

            // 4. التحقق من الملكية لكل الإشعارات
            $ownedIds = $this->getOwnedNotificationIds($userId, $validatedIds);

            if (empty($ownedIds)) {
                Response::forbidden('لا تملك أي من الإشعارات المحددة');
            }

            // 5. تعليم كمقروء
            $markedCount = $this->notificationService->markMultipleAsRead($userId, $ownedIds);

            Response::success(
                message: "تم تعليم {$markedCount} إشعار(ات) كمقروء بنجاح",
                data: [
                    'marked_count'     => $markedCount,
                    'notification_ids' => $ownedIds,
                ]
            );

        } catch (Throwable $e) {
            $this->handleNotificationError($e, 'MarkMultipleAsRead');
        }
    }

    // =========================================================================
    // 6. تعليم كل الإشعارات كمقروءة (Mark All as Read)
    // =========================================================================

    /**
     * تعليم كل إشعارات المستخدم كمقروءة
     *
     * POST /api/notifications/read-all
     *
     * @return void يرسل استجابة JSON
     */
    public function markAllAsRead(): void
    {
        try {
            $userId = $this->getCurrentUserId();

            $markedCount = $this->notificationService->markAllAsRead($userId);

            Response::success(
                message: "تم تعليم {$markedCount} إشعار(ات) كمقروء بنجاح",
                data: [
                    'marked_count' => $markedCount,
                ]
            );

        } catch (Throwable $e) {
            $this->handleNotificationError($e, 'MarkAllAsRead');
        }
    }

    // =========================================================================
    // 7. حذف إشعار (Destroy)
    // =========================================================================

    /**
     * حذف إشعار معين
     *
     * DELETE /api/notifications/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            $userId = $this->getCurrentUserId();
            $notificationId = $this->validateNotificationId($params);

            // التحقق من ملكية الإشعار
            $notification = $this->verifyOwnership($notificationId, $userId);

            // حذف الإشعار (Soft Delete)
            $this->notificationService->delete($notificationId, $userId);

            Response::success(
                message: 'تم حذف الإشعار بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            $this->handleNotificationError($e, 'Destroy');
        }
    }

    // =========================================================================
    // 8. حذف عدة إشعارات (Batch Destroy)
    // =========================================================================

    /**
     * حذف عدة إشعارات دفعة واحدة
     *
     * POST /api/notifications/destroy-multiple
     *
     * Request Body (JSON):
     * {
     *   "notification_ids": [1, 2, 3, 5, 8]
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function destroyMultiple(): void
    {
        try {
            $userId = $this->getCurrentUserId();
            $input = $this->getJsonInput();

            // 1. التحقق من وجود notification_ids
            if (empty($input['notification_ids']) || !is_array($input['notification_ids'])) {
                Response::badRequest('حقل notification_ids مطلوب ويجب أن يكون مصفوفة');
            }

            $notificationIds = $input['notification_ids'];

            // 2. التحقق من صحة المعرفات
            $validatedIds = [];
            foreach ($notificationIds as $id) {
                if (!is_numeric($id) || (int) $id <= 0) {
                    continue;
                }
                $validatedIds[] = (int) $id;
            }

            if (empty($validatedIds)) {
                Response::badRequest('لا توجد معرفات إشعارات صالحة');
            }

            // 3. حد أقصى 100 إشعار في العملية الواحدة
            if (count($validatedIds) > 100) {
                Response::badRequest('لا يمكن حذف أكثر من 100 إشعار في العملية الواحدة');
            }

            // 4. التحقق من الملكية
            $ownedIds = $this->getOwnedNotificationIds($userId, $validatedIds);

            if (empty($ownedIds)) {
                Response::forbidden('لا تملك أي من الإشعارات المحددة');
            }

            // 5. حذف الإشعارات
            $deletedCount = $this->notificationService->deleteMultiple($userId, $ownedIds);

            Response::success(
                message: "تم حذف {$deletedCount} إشعار(ات) بنجاح",
                data: [
                    'deleted_count'    => $deletedCount,
                    'notification_ids' => $ownedIds,
                ]
            );

        } catch (Throwable $e) {
            $this->handleNotificationError($e, 'DestroyMultiple');
        }
    }

    // =========================================================================
    // 9. حذف كل الإشعارات (Destroy All)
    // =========================================================================

    /**
     * حذف كل إشعارات المستخدم
     *
     * DELETE /api/notifications/all
     *
     * @return void يرسل استجابة JSON
     */
    public function destroyAll(): void
    {
        try {
            $userId = $this->getCurrentUserId();

            $deletedCount = $this->notificationService->deleteAll($userId);

            Response::success(
                message: "تم حذف {$deletedCount} إشعار(ات) بنجاح",
                data: [
                    'deleted_count' => $deletedCount,
                ]
            );

        } catch (Throwable $e) {
            $this->handleNotificationError($e, 'DestroyAll');
        }
    }

    // =========================================================================
    // 10. عرض تفاصيل إشعار (Show)
    // =========================================================================

    /**
     * عرض تفاصيل إشعار معين (ويقوم بتعليمه كمقروء تلقائياً)
     *
     * GET /api/notifications/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            $userId = $this->getCurrentUserId();
            $notificationId = $this->validateNotificationId($params);

            // التحقق من ملكية الإشعار
            $notification = $this->verifyOwnership($notificationId, $userId);

            // إذا كان غير مقروء، تعليمه كمقروء تلقائياً
            if (!(int) ($notification['is_read'] ?? 0)) {
                $this->notificationService->markAsRead($notificationId, $userId);
                $notification['is_read'] = 1;
                $notification['read_at'] = date('Y-m-d H:i:s');
            }

            // إضافة معلومات إضافية
            $notification['type_label'] = self::TYPE_LABELS[$notification['type']] ?? $notification['type'];
            $notification['type_icon'] = self::TYPE_ICONS[$notification['type']] ?? 'fa-bell';

            Response::success(
                message: 'تم جلب تفاصيل الإشعار بنجاح',
                data: [
                    'notification' => $notification,
                ]
            );

        } catch (Throwable $e) {
            $this->handleNotificationError($e, 'Show');
        }
    }

    // =========================================================================
    // Helper Methods - الأمان والتحقق
    // =========================================================================

    /**
     * التحقق من ملكية الإشعار للمستخدم الحالي
     *
     * هذه الدالة تمنع ثغرة IDOR (Insecure Direct Object Reference)
     * حيث يحاول المستخدم الوصول لإشعارات مستخدمين آخرين
     *
     * @param int $notificationId معرف الإشعار
     * @param int $userId معرف المستخدم الحالي
     * @return array بيانات الإشعار
     * @throws Exception إذا لم يكن المستخدم يملك الإشعار
     */
    private function verifyOwnership(int $notificationId, int $userId): array
    {
        $notification = $this->db->selectOne(
            "SELECT id, user_id, title, message, type, is_read FROM notifications WHERE id = ? AND deleted_at IS NULL",
            [$notificationId]
        );

        if (!$notification) {
            throw new Exception('الإشعار غير موجود', 404);
        }

        // التحقق من أن الإشعار يخص المستخدم الحالي أو هو إشعار عام (user_id IS NULL)
        if ((int) $notification['user_id'] !== $userId && $notification['user_id'] !== null) {
            throw new Exception('لا تملك صلاحية الوصول إلى هذا الإشعار', 403);
        }

        return $notification;
    }

    /**
     * جلب معرفات الإشعارات التي يملكها المستخدم
     *
     * @param int $userId معرف المستخدم
     * @param array $notificationIds معرفات الإشعارات للتحقق
     * @return array معرفات الإشعارات المملوكة للمستخدم
     */
    private function getOwnedNotificationIds(int $userId, array $notificationIds): array
    {
        if (empty($notificationIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
        $params = array_merge([$userId], $notificationIds);

        $owned = $this->db->select(
            "SELECT id FROM notifications
             WHERE user_id = ?
               AND id IN ({$placeholders})
               AND deleted_at IS NULL",
            $params
        );

        return array_column($owned, 'id');
    }

    // =========================================================================
    // Helper Methods - عامة
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
     * التحقق من صحة معرف الإشعار
     */
    private function validateNotificationId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف الإشعار غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
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

        error_log('[NOTIFICATION_CONTROLLER] Current user ID not found');
        Response::unauthorized('لم يتم العثور على بيانات المستخدم. يرجى تسجيل الدخول مرة أخرى.');
    }

    /**
     * معالجة أخطاء الإشعارات بشكل موحد
     */
    private function handleNotificationError(Throwable $e, string $operation): void
    {
        error_log("[NOTIFICATION_CONTROLLER] {$operation} failed: " . $e->getMessage());

        $code = $e->getCode();

        if ($code === 404 || str_contains($e->getMessage(), 'غير موجود')) {
            Response::notFound($e->getMessage());
        }

        if ($code === 403 || str_contains($e->getMessage(), 'لا تملك صلاحية')) {
            Response::forbidden($e->getMessage());
        }

        Response::internalError('فشلت العملية: ' . $e->getMessage());
    }
}
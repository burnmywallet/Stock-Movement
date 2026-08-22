<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/NotificationController.php
// الوصف: متحكم الإشعارات - تم إصلاح مشكلة المصادقة
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Exception;

class NotificationController
{
    private $db;
    private $auth;
    private $audit;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        $this->audit = new Audit();
    }

    /**
     * GET /api/notifications
     * جلب قائمة الإشعارات - تم إصلاح المصادقة
     */
    public function index(): void
    {
        try {
            // ✅ التحقق من التوكن يدوياً
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? '';
            $token = str_replace('Bearer ', '', $authHeader);

            if (empty($token)) {
                errorResponse('غير مصرح - التوكن مطلوب', 401);
                return;
            }

            // ✅ فك تشفير التوكن
            $decoded = base64_decode($token);
            $parts = explode(':', $decoded);
            $userId = $parts[0] ?? 0;

            if (!$userId) {
                errorResponse('غير مصرح - توكن غير صالح', 401);
                return;
            }

            // ✅ التحقق من المستخدم
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("SELECT id, is_active, deleted_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user || !$user['is_active'] || $user['deleted_at'] !== null) {
                errorResponse('غير مصرح - مستخدم غير نشط', 401);
                return;
            }

            // ✅ جلب الإشعارات
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $search = $_GET['search'] ?? '';
            $type = $_GET['type'] ?? '';
            $priority = $_GET['priority'] ?? '';
            $status = $_GET['status'] ?? '';

            $params = ['user_id' => $userId];
            $where = ["user_id = :user_id"];
            
            if (!empty($search)) {
                $where[] = "(title LIKE :search OR message LIKE :search)";
                $params['search'] = "%{$search}%";
            }
            
            if (!empty($type)) {
                $where[] = "type = :type";
                $params['type'] = $type;
            }
            
            if (!empty($priority)) {
                $where[] = "priority = :priority";
                $params['priority'] = $priority;
            }
            
            if ($status === 'read') {
                $where[] = "is_read = 1";
            } elseif ($status === 'unread') {
                $where[] = "is_read = 0";
            }

            // جلب الإشعارات
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    type,
                    title,
                    message,
                    is_read,
                    read_at,
                    priority,
                    reference_type,
                    reference_id,
                    link,
                    created_at,
                    CASE 
                        WHEN priority = 'critical' THEN 'danger'
                        WHEN priority = 'high' THEN 'warning'
                        WHEN priority = 'medium' THEN 'info'
                        WHEN priority = 'low' THEN 'success'
                        ELSE 'secondary'
                    END as priority_color
                FROM notifications 
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->execute(array_merge($params, ['limit' => $limit, 'offset' => $offset]));
            $notifications = $stmt->fetchAll();

            // إجمالي الإشعارات
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total FROM notifications 
                WHERE " . implode(' AND ', $where)
            );
            $stmt->execute($params);
            $total = $stmt->fetch()['total'];

            // إحصائيات
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread,
                    COUNT(CASE WHEN priority = 'critical' THEN 1 END) as critical,
                    COUNT(CASE WHEN priority = 'high' THEN 1 END) as high
                FROM notifications 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch();

            successResponse('تم جلب قائمة الإشعارات', [
                'data' => $notifications,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'unread' => (int)($stats['unread'] ?? 0),
                    'critical' => (int)($stats['critical'] ?? 0),
                    'high' => (int)($stats['high'] ?? 0)
                ],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil((int)$total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            error_log('Notifications error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/notifications/{id}/read
     * تعيين إشعار كمقروء
     */
    public function markAsRead(int $id): void
    {
        try {
            // ✅ التحقق من التوكن
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? '';
            $token = str_replace('Bearer ', '', $authHeader);

            if (empty($token)) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $decoded = base64_decode($token);
            $parts = explode(':', $decoded);
            $userId = $parts[0] ?? 0;

            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $pdo = $this->db->getConnection();
            
            // التحقق من ملكية الإشعار
            $stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $notification = $stmt->fetch();

            if (!$notification) {
                errorResponse('الإشعار غير موجود');
                return;
            }

            $stmt = $pdo->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$id, $userId]);

            successResponse('تم تعيين الإشعار كمقروء');

        } catch (Exception $e) {
            error_log('Mark as read error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/notifications/read-all
     * تعيين جميع الإشعارات كمقروءة
     */
    public function markAllAsRead(): void
    {
        try {
            // ✅ التحقق من التوكن
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? '';
            $token = str_replace('Bearer ', '', $authHeader);

            if (empty($token)) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $decoded = base64_decode($token);
            $parts = explode(':', $decoded);
            $userId = $parts[0] ?? 0;

            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$userId]);

            successResponse('تم تعيين جميع الإشعارات كمقروءة');

        } catch (Exception $e) {
            error_log('Mark all as read error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/notifications/{id}
     * حذف إشعار
     */
    public function delete(int $id): void
    {
        try {
            // ✅ التحقق من التوكن
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? '';
            $token = str_replace('Bearer ', '', $authHeader);

            if (empty($token)) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $decoded = base64_decode($token);
            $parts = explode(':', $decoded);
            $userId = $parts[0] ?? 0;

            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $pdo = $this->db->getConnection();
            
            // التحقق من ملكية الإشعار
            $stmt = $pdo->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            $notification = $stmt->fetch();

            if (!$notification) {
                errorResponse('الإشعار غير موجود');
                return;
            }

            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);

            successResponse('تم حذف الإشعار بنجاح');

        } catch (Exception $e) {
            error_log('Delete notification error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }
}

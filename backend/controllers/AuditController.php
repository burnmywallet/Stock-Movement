<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/AuditController.php
// الوصف: متحكم سجل التدقيق المتقدم - عرض، فلترة، بحث، تصدير، تنظيف
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Exception;

class AuditController
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var Auth $auth - نظام المصادقة
     */
    private $auth;
    
    /**
     * @var Audit $audit - سجل التدقيق
     */
    private $audit;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        $this->audit = new Audit();
    }

    /**
     * GET /api/audit/logs
     * جلب سجل التدقيق مع فلترة وبحث متقدم
     */
    public function logs(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية عرض سجل التدقيق
            if (!$this->auth->hasPermission($userId, 'audit.view')) {
                errorResponse('ليس لديك صلاحية لعرض سجل التدقيق', 403);
                return;
            }

            // معاملات الصفحة
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = ($page - 1) * $limit;

            // معاملات الفلترة
            $search = $_GET['search'] ?? '';
            $action = $_GET['action'] ?? '';
            $module = $_GET['module'] ?? '';
            $userIdFilter = $_GET['user_id_filter'] ?? '';
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');
            $referenceType = $_GET['reference_type'] ?? '';
            $referenceId = $_GET['reference_id'] ?? '';
            $sort = $_GET['sort'] ?? 'created_at';
            $order = $_GET['order'] ?? 'DESC';

            $params = [
                'from_date' => $fromDate . ' 00:00:00',
                'to_date' => $toDate . ' 23:59:59'
            ];
            $where = ["al.created_at BETWEEN :from_date AND :to_date"];

            // البحث النصي
            if (!empty($search)) {
                $where[] = "(al.username LIKE :search OR al.action LIKE :search OR al.module LIKE :search OR al.description LIKE :search)";
                $params['search'] = "%{$search}%";
            }

            // فلترة حسب الإجراء
            if (!empty($action)) {
                $where[] = "al.action = :action";
                $params['action'] = $action;
            }

            // فلترة حسب الوحدة
            if (!empty($module)) {
                $where[] = "al.module = :module";
                $params['module'] = $module;
            }

            // فلترة حسب المستخدم
            if (!empty($userIdFilter)) {
                $where[] = "al.user_id = :user_id_filter";
                $params['user_id_filter'] = $userIdFilter;
            }

            // فلترة حسب المرجع
            if (!empty($referenceType)) {
                $where[] = "al.reference_type = :reference_type";
                $params['reference_type'] = $referenceType;
            }

            if (!empty($referenceId)) {
                $where[] = "al.reference_id = :reference_id";
                $params['reference_id'] = $referenceId;
            }

            // ترتيب النتائج
            $allowedSorts = ['id', 'created_at', 'action', 'module', 'username'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'created_at';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب السجلات
            $logs = $this->db->query("
                SELECT 
                    al.id,
                    al.user_id,
                    al.username,
                    al.action,
                    al.module,
                    al.description,
                    al.details,
                    al.ip_address,
                    al.user_agent,
                    al.reference_type,
                    al.reference_id,
                    al.created_at,
                    u.full_name as user_full_name,
                    u.email as user_email,
                    u.avatar as user_avatar,
                    CASE 
                        WHEN al.action LIKE '%CREATE%' OR al.action LIKE '%CREATED%' THEN 'success'
                        WHEN al.action LIKE '%UPDATE%' OR al.action LIKE '%UPDATED%' THEN 'warning'
                        WHEN al.action LIKE '%DELETE%' OR al.action LIKE '%DELETED%' THEN 'danger'
                        WHEN al.action LIKE '%APPROVE%' OR al.action LIKE '%APPROVED%' THEN 'primary'
                        WHEN al.action LIKE '%REJECT%' OR al.action LIKE '%REJECTED%' THEN 'danger'
                        WHEN al.action LIKE '%CANCEL%' OR al.action LIKE '%CANCELLED%' THEN 'secondary'
                        WHEN al.action LIKE '%LOGIN%' THEN 'info'
                        WHEN al.action LIKE '%LOGOUT%' THEN 'secondary'
                        WHEN al.action LIKE '%PERMISSION%' THEN 'danger'
                        ELSE 'info'
                    END as action_type,
                    CASE 
                        WHEN al.action LIKE '%CREATE%' OR al.action LIKE '%CREATED%' THEN 'إنشاء'
                        WHEN al.action LIKE '%UPDATE%' OR al.action LIKE '%UPDATED%' THEN 'تحديث'
                        WHEN al.action LIKE '%DELETE%' OR al.action LIKE '%DELETED%' THEN 'حذف'
                        WHEN al.action LIKE '%APPROVE%' OR al.action LIKE '%APPROVED%' THEN 'اعتماد'
                        WHEN al.action LIKE '%REJECT%' OR al.action LIKE '%REJECTED%' THEN 'رفض'
                        WHEN al.action LIKE '%CANCEL%' OR al.action LIKE '%CANCELLED%' THEN 'إلغاء'
                        WHEN al.action LIKE '%LOGIN%' THEN 'تسجيل دخول'
                        WHEN al.action LIKE '%LOGOUT%' THEN 'تسجيل خروج'
                        WHEN al.action LIKE '%PERMISSION%' THEN 'صلاحية'
                        ELSE al.action
                    END as action_label
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY al.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            // معالجة التفاصيل (JSON)
            foreach ($logs as &$log) {
                if ($log['details']) {
                    $log['details'] = json_decode($log['details'], true);
                }
                $log['time_ago'] = $this->timeAgo($log['created_at']);
            }

            // إجمالي السجلات
            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM audit_logs al
                WHERE " . implode(' AND ', $where) . "
            ", $params);

            // إحصائيات إضافية
            $stats = $this->getAuditStats($fromDate, $toDate);

            successResponse('تم جلب سجل التدقيق', [
                'data' => $logs,
                'stats' => $stats,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil((int)$total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            error_log('Audit logs error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/audit/logs/{id}
     * جلب تفاصيل سجل تدقيق محدد
     */
    public function show(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'audit.view')) {
                errorResponse('ليس لديك صلاحية لعرض سجل التدقيق', 403);
                return;
            }

            $log = $this->db->queryOne("
                SELECT 
                    al.*,
                    u.full_name as user_full_name,
                    u.email as user_email,
                    u.avatar as user_avatar,
                    u.username,
                    CASE 
                        WHEN al.action LIKE '%CREATE%' OR al.action LIKE '%CREATED%' THEN 'success'
                        WHEN al.action LIKE '%UPDATE%' OR al.action LIKE '%UPDATED%' THEN 'warning'
                        WHEN al.action LIKE '%DELETE%' OR al.action LIKE '%DELETED%' THEN 'danger'
                        WHEN al.action LIKE '%APPROVE%' OR al.action LIKE '%APPROVED%' THEN 'primary'
                        WHEN al.action LIKE '%REJECT%' OR al.action LIKE '%REJECTED%' THEN 'danger'
                        WHEN al.action LIKE '%CANCEL%' OR al.action LIKE '%CANCELLED%' THEN 'secondary'
                        WHEN al.action LIKE '%LOGIN%' THEN 'info'
                        ELSE 'info'
                    END as action_type
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE al.id = :id
            ", ['id' => $id]);

            if (!$log) {
                errorResponse('السجل غير موجود');
                return;
            }

            // معالجة التفاصيل
            if ($log['details']) {
                $log['details'] = json_decode($log['details'], true);
            }

            // جلب السجلات المرتبطة (قبل وبعد)
            $related = $this->db->query("
                SELECT 
                    id,
                    action,
                    module,
                    description,
                    created_at,
                    user_id,
                    username
                FROM audit_logs 
                WHERE (reference_type = :ref_type AND reference_id = :ref_id)
                   OR user_id = :user_id
                AND id != :id
                ORDER BY created_at DESC
                LIMIT 10
            ", [
                'ref_type' => $log['reference_type'],
                'ref_id' => $log['reference_id'],
                'user_id' => $log['user_id'],
                'id' => $id
            ]);

            successResponse('تم جلب تفاصيل السجل', [
                'log' => $log,
                'related' => $related,
                'time_ago' => $this->timeAgo($log['created_at'])
            ]);

        } catch (Exception $e) {
            error_log('Audit show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/audit/stats
     * إحصائيات سجل التدقيق
     */
    public function stats(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'audit.view')) {
                errorResponse('ليس لديك صلاحية لعرض إحصائيات سجل التدقيق', 403);
                return;
            }

            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            $stats = $this->getAuditStats($fromDate, $toDate);

            successResponse('تم جلب إحصائيات سجل التدقيق', $stats);

        } catch (Exception $e) {
            error_log('Audit stats error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/audit/modules
     * جلب قائمة الوحدات المتاحة
     */
    public function modules(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'audit.view')) {
                errorResponse('ليس لديك صلاحية لعرض سجل التدقيق', 403);
                return;
            }

            $modules = $this->db->query("
                SELECT DISTINCT module, COUNT(*) as count
                FROM audit_logs
                GROUP BY module
                ORDER BY module
            ");

            successResponse('تم جلب قائمة الوحدات', $modules);

        } catch (Exception $e) {
            error_log('Audit modules error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/audit/actions
     * جلب قائمة الإجراءات المتاحة
     */
    public function actions(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'audit.view')) {
                errorResponse('ليس لديك صلاحية لعرض سجل التدقيق', 403);
                return;
            }

            $actions = $this->db->query("
                SELECT DISTINCT action, COUNT(*) as count
                FROM audit_logs
                GROUP BY action
                ORDER BY action
            ");

            successResponse('تم جلب قائمة الإجراءات', $actions);

        } catch (Exception $e) {
            error_log('Audit actions error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/audit/export
     * تصدير سجل التدقيق
     */
    public function export(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'audit.view')) {
                errorResponse('ليس لديك صلاحية لتصدير سجل التدقيق', 403);
                return;
            }

            $format = $_GET['format'] ?? 'csv';
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');
            $action = $_GET['action'] ?? '';
            $module = $_GET['module'] ?? '';

            $params = [
                'from_date' => $fromDate . ' 00:00:00',
                'to_date' => $toDate . ' 23:59:59'
            ];
            $where = ["al.created_at BETWEEN :from_date AND :to_date"];

            if (!empty($action)) {
                $where[] = "al.action = :action";
                $params['action'] = $action;
            }

            if (!empty($module)) {
                $where[] = "al.module = :module";
                $params['module'] = $module;
            }

            $logs = $this->db->query("
                SELECT 
                    al.id,
                    al.username,
                    al.action,
                    al.module,
                    al.description,
                    al.ip_address,
                    al.created_at,
                    u.full_name as user_name
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY al.created_at DESC
            ", $params);

            if ($format === 'csv') {
                $this->exportCSV($logs);
            } elseif ($format === 'excel') {
                $this->exportExcel($logs);
            } else {
                successResponse('تم جلب بيانات التصدير', $logs);
            }

        } catch (Exception $e) {
            error_log('Audit export error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/audit/cleanup
     * تنظيف السجلات القديمة (للأدمن فقط)
     */
    public function cleanup(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية الأدمن
            if (!$this->auth->hasPermission($userId, 'admin')) {
                errorResponse('ليس لديك صلاحية لتنظيف سجل التدقيق', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $days = $input['days'] ?? 90;

            if ($days < 30) {
                errorResponse('لا يمكن حذف سجلات أقل من 30 يوماً');
                return;
            }

            $count = $this->audit->cleanup($days);

            // تسجيل في سجل التدقيق (ironic)
            $this->audit->log(
                $userId,
                'AUDIT_CLEANUP',
                'audit',
                "تنظيف سجل التدقيق: حذف {$count} سجل أقدم من {$days} يوم",
                ['days' => $days, 'deleted' => $count]
            );

            successResponse('تم تنظيف سجل التدقيق بنجاح', [
                'deleted' => $count,
                'days' => $days
            ]);

        } catch (Exception $e) {
            error_log('Audit cleanup error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على إحصائيات التدقيق
     */
    private function getAuditStats(string $fromDate, string $toDate): array
    {
        $params = [
            'from_date' => $fromDate . ' 00:00:00',
            'to_date' => $toDate . ' 23:59:59'
        ];

        $stats = [];

        // إجمالي السجلات
        $stats['total'] = (int)$this->db->queryValue("
            SELECT COUNT(*) FROM audit_logs
            WHERE created_at BETWEEN :from_date AND :to_date
        ", $params);

        // حسب الإجراء
        $actions = $this->db->query("
            SELECT action, COUNT(*) as count
            FROM audit_logs
            WHERE created_at BETWEEN :from_date AND :to_date
            GROUP BY action
            ORDER BY count DESC
            LIMIT 10
        ", $params);
        $stats['by_action'] = $actions;

        // حسب الوحدة
        $modules = $this->db->query("
            SELECT module, COUNT(*) as count
            FROM audit_logs
            WHERE created_at BETWEEN :from_date AND :to_date
            GROUP BY module
            ORDER BY count DESC
        ", $params);
        $stats['by_module'] = $modules;

        // حسب المستخدم
        $users = $this->db->query("
            SELECT 
                u.full_name,
                COUNT(al.id) as count
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.created_at BETWEEN :from_date AND :to_date
            GROUP BY al.user_id
            ORDER BY count DESC
            LIMIT 10
        ", $params);
        $stats['by_user'] = $users;

        // حسب اليوم
        $daily = $this->db->query("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as count
            FROM audit_logs
            WHERE created_at BETWEEN :from_date AND :to_date
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ", $params);
        $stats['daily'] = $daily;

        // السجلات اليوم
        $stats['today'] = (int)$this->db->queryValue("
            SELECT COUNT(*) FROM audit_logs
            WHERE DATE(created_at) = CURDATE()
        ");

        // السجلات هذا الأسبوع
        $stats['week'] = (int)$this->db->queryValue("
            SELECT COUNT(*) FROM audit_logs
            WHERE YEARWEEK(created_at) = YEARWEEK(NOW())
        ");

        // السجلات هذا الشهر
        $stats['month'] = (int)$this->db->queryValue("
            SELECT COUNT(*) FROM audit_logs
            WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())
        ");

        return $stats;
    }

    /**
     * حساب الوقت المنقضي
     */
    private function timeAgo(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;
        
        $units = [
            31536000 => 'سنة',
            2592000 => 'شهر',
            604800 => 'أسبوع',
            86400 => 'يوم',
            3600 => 'ساعة',
            60 => 'دقيقة',
            1 => 'ثانية'
        ];
        
        foreach ($units as $seconds => $unit) {
            if ($diff >= $seconds) {
                $count = floor($diff / $seconds);
                $text = $count . ' ' . $unit;
                if ($count > 1) {
                    $text .= $unit === 'سنة' ? 'ات' : ($unit === 'شهر' ? 'ور' : ($unit === 'أسبوع' ? 'وع' : ($unit === 'يوم' ? 'اً' : ($unit === 'ساعة' ? 'ات' : ($unit === 'دقيقة' ? 'ق' : 'ات')))));
                }
                return $text . ' ago';
            }
        }
        
        return 'الآن';
    }

    /**
     * تصدير CSV
     */
    private function exportCSV(array $data): void
    {
        if (empty($data)) {
            errorResponse('لا توجد بيانات للتصدير');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['#', 'المستخدم', 'الإجراء', 'الوحدة', 'الوصف', 'IP', 'التاريخ']);
        
        $index = 1;
        foreach ($data as $row) {
            fputcsv($output, [
                $index++,
                $row['user_name'] ?? $row['username'] ?? 'نظام',
                $row['action'],
                $row['module'],
                $row['description'],
                $row['ip_address'],
                $row['created_at']
            ]);
        }
        
        fclose($output);
        exit;
    }

    /**
     * تصدير Excel
     */
    private function exportExcel(array $data): void
    {
        if (empty($data)) {
            errorResponse('لا توجد بيانات للتصدير');
            return;
        }

        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1">';
        echo '<tr style="background:#667eea;color:#fff;font-weight:bold;">';
        echo '<th>#</th><th>المستخدم</th><th>الإجراء</th><th>الوحدة</th><th>الوصف</th><th>IP</th><th>التاريخ</th>';
        echo '</tr>';
        
        $index = 1;
        foreach ($data as $row) {
            echo '<tr>';
            echo '<td>' . $index++ . '</td>';
            echo '<td>' . ($row['user_name'] ?? $row['username'] ?? 'نظام') . '</td>';
            echo '<td>' . $row['action'] . '</td>';
            echo '<td>' . $row['module'] . '</td>';
            echo '<td>' . $row['description'] . '</td>';
            echo '<td>' . $row['ip_address'] . '</td>';
            echo '<td>' . $row['created_at'] . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        exit;
    }
}

// ================================================================
// انتهى الملف
// ================================================================

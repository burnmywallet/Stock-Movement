<?php

/**
 * ================================================================
 * Logistox - Audit Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/AuditController.php
 * الوظيفة: عرض وتحليل سجل التدقيق (Immutable Audit Logs)
 *
 * المسؤوليات:
 * 1. عرض قائمة سجلات التدقيق مع الفلاتر والترقيم (index)
 * 2. عرض تفاصيل سجل معين (show)
 * 3. تصدير السجلات (export) - CSV, Excel, PDF
 * 4. إحصائيات السجلات (statistics)
 * 5. نشاط مستخدم معين (userActivity)
 * 6. تاريخ كيان معين (entityHistory) - مثل منتج، مستخدم، مخزن
 * 7. تنظيف السجلات القديمة (cleanup) - للمدير فقط
 *
 * الصلاحيات المطلوبة:
 * - audit.view: عرض السجلات
 * - audit.export: تصدير السجلات
 * - audit.cleanup: تنظيف السجلات القديمة (Super Admin فقط)
 *
 * قيود الأمان:
 * - السجلات غير قابلة للتعديل أو الحذف (Immutable)
 * - لا يمكن إنشاء سجلات يدوياً (فقط عبر النظام)
 * - تسجيل كل عمليات الوصول للسجلات
 * - حماية خاصة لـ cleanup (Super Admin فقط)
 *
 * ملاحظات هامة:
 * - يعتمد على AuditService لتجميع البيانات
 * - يستخدم ReportService للتصدير
 * - يدعم الفلاتر المتقدمة (تاريخ، مستخدم، إجراء، كيان)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\AuditService;
use App\Services\ReportService;
use Throwable;
use Exception;

/**
 * Class AuditController
 *
 * Controller لعرض وتحليل سجل التدقيق
 */
class AuditController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var ReportService خدمة التقارير (للتصدير)
     */
    private ReportService $reportService;

    /**
     * @var array الإجراءات المسموحة للتصفية
     */
    private const ALLOWED_ACTIONS = [
        'LOGIN_SUCCESS',
        'LOGIN_FAILED',
        'LOGOUT',
        'PRODUCT_CREATE',
        'PRODUCT_UPDATE',
        'PRODUCT_DELETE',
        'WAREHOUSE_CREATE',
        'WAREHOUSE_UPDATE',
        'WAREHOUSE_DELETE',
        'RECEIPT_CREATE',
        'RECEIPT_UPDATE',
        'RECEIPT_APPROVE',
        'RECEIPT_CANCEL',
        'RECEIPT_DELETE',
        'ISSUE_CREATE',
        'ISSUE_UPDATE',
        'ISSUE_APPROVE',
        'ISSUE_CANCEL',
        'ISSUE_DELETE',
        'TRANSFER_CREATE',
        'TRANSFER_UPDATE',
        'TRANSFER_APPROVE',
        'TRANSFER_CANCEL',
        'TRANSFER_DELETE',
        'RETURN_CREATE',
        'RETURN_UPDATE',
        'RETURN_DELETE',
        'INVENTORY_COUNT_CREATE',
        'INVENTORY_COUNT_UPDATE',
        'INVENTORY_COUNT_START',
        'INVENTORY_COUNT_COMPLETE',
        'INVENTORY_COUNT_APPROVE',
        'INVENTORY_COUNT_CANCEL',
        'INVENTORY_COUNT_DELETE',
        'INVENTORY_COUNT_ITEM_ADD',
        'INVENTORY_COUNT_ITEM_UPDATE',
        'INVENTORY_COUNT_ITEM_REMOVE',
        'USER_CREATE',
        'USER_UPDATE',
        'USER_DELETE',
        'USER_RESET_PASSWORD',
        'USER_SET_ACTIVE',
        'ROLE_CREATE',
        'ROLE_UPDATE',
        'ROLE_DELETE',
        'ROLE_PERMISSIONS_UPDATE',
        'REPORT_EXPORT',
        'BACKUP_CREATE',
        'BACKUP_RESTORE',
        'BACKUP_DELETE',
        'SETTINGS_UPDATE',
        'ACCESS_DENIED',
    ];

    /**
     * @var array أنواع الكيانات المسموحة
     */
    private const ALLOWED_ENTITY_TYPES = [
        'product',
        'warehouse',
        'category',
        'unit',
        'supplier',
        'recipient',
        'receipt',
        'issue',
        'transfer',
        'return',
        'inventory_count',
        'inventory_count_item',
        'user',
        'role',
        'permission',
        'report',
        'backup',
        'setting',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->auditService = new AuditService($this->db);
            $this->reportService = new ReportService($this->db);
        } catch (Throwable $e) {
            error_log('[AUDIT_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة التدقيق');
        }
    }

    // =========================================================================
    // 1. عرض قائمة السجلات (Index)
    // =========================================================================

    /**
     * عرض قائمة سجلات التدقيق مع الفلاتر والترقيم
     *
     * GET /api/audit
     *
     * Query Parameters:
     * - user_id: تصفية حسب المستخدم
     * - action: تصفية حسب الإجراء
     * - entity_type: تصفية حسب نوع الكيان
     * - entity_id: تصفية حسب معرف الكيان
     * - from_date: من تاريخ (YYYY-MM-DD)
     * - to_date: إلى تاريخ (YYYY-MM-DD)
     * - search: بحث في الوصف
     * - ip_address: تصفية حسب عنوان IP
     * - page: رقم الصفحة (افتراضي: 1)
     * - per_page: عدد العناصر في الصفحة (افتراضي: 50، حد أقصى: 200)
     * - sort_by: ترتيب حسب (created_at, user_id, action, entity_type)
     * - sort_order: ترتيب تصاعدي/تنازلي (asc, desc)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            // 1. قراءة Query Parameters
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 50)));

            $filters = [
                'user_id'     => !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null,
                'action'      => $_GET['action'] ?? null,
                'entity_type' => $_GET['entity_type'] ?? null,
                'entity_id'   => !empty($_GET['entity_id']) ? (int) $_GET['entity_id'] : null,
                'from_date'   => $_GET['from_date'] ?? null,
                'to_date'     => $_GET['to_date'] ?? null,
                'search'      => trim($_GET['search'] ?? ''),
                'ip_address'  => $_GET['ip_address'] ?? null,
                'sort_by'     => $_GET['sort_by'] ?? 'created_at',
                'sort_order'  => strtolower($_GET['sort_order'] ?? 'desc'),
            ];

            // 2. التحقق من صحة الفلاتر
            $this->validateFilters($filters);

            // 3. جلب السجلات مع الترقيم
            $offset = ($page - 1) * $perPage;
            $logs = $this->auditService->getLogs($filters, $perPage, $offset);
            $total = $this->auditService->countLogs($filters);

            // 4. إضافة معلومات إضافية
            foreach ($logs as &$log) {
                $log['action_label'] = $this->translateAction($log['action']);
                $log['entity_type_label'] = $this->translateEntityType($log['entity_type']);

                // فك تشفير old_values و new_values
                if (!empty($log['old_values']) && is_string($log['old_values'])) {
                    $log['old_values'] = json_decode($log['old_values'], true);
                }
                if (!empty($log['new_values']) && is_string($log['new_values'])) {
                    $log['new_values'] = json_decode($log['new_values'], true);
                }
            }

            // 5. إرجاع الاستجابة
            Response::paginated(
                data: $logs,
                total: $total,
                page: $page,
                perPage: $perPage,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[AUDIT_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب سجلات التدقيق');
        }
    }

    // =========================================================================
    // 2. عرض تفاصيل سجل (Show)
    // =========================================================================

    /**
     * عرض تفاصيل سجل تدقيق معين
     *
     * GET /api/audit/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function show(array $params): void
    {
        try {
            $logId = $this->validateLogId($params);

            $log = $this->auditService->getById($logId);

            if (!$log) {
                Response::notFound('السجل غير موجود');
            }

            // إضافة معلومات إضافية
            $log['action_label'] = $this->translateAction($log['action']);
            $log['entity_type_label'] = $this->translateEntityType($log['entity_type']);

            // فك تشفير old_values و new_values
            if (!empty($log['old_values']) && is_string($log['old_values'])) {
                $log['old_values'] = json_decode($log['old_values'], true);
            }
            if (!empty($log['new_values']) && is_string($log['new_values'])) {
                $log['new_values'] = json_decode($log['new_values'], true);
            }

            Response::success(
                message: 'تم جلب تفاصيل السجل بنجاح',
                data: [
                    'log' => $log,
                ]
            );

        } catch (Throwable $e) {
            error_log('[AUDIT_CONTROLLER] Show failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تفاصيل السجل');
        }
    }

    // =========================================================================
    // 3. تصدير السجلات (Export)
    // =========================================================================

    /**
     * تصدير سجلات التدقيق
     *
     * GET /api/audit/export
     *
     * Query Parameters:
     * - format: صيغة التصدير (csv, excel, pdf)
     * - جميع الفلاتر المدعومة في index
     *
     * @return void
     */
    public function export(): void
    {
        try {
            $format = strtolower($_GET['format'] ?? 'csv');

            if (!in_array($format, ['csv', 'excel', 'pdf'], true)) {
                $format = 'csv';
            }

            $filters = [
                'user_id'     => !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null,
                'action'      => $_GET['action'] ?? null,
                'entity_type' => $_GET['entity_type'] ?? null,
                'entity_id'   => !empty($_GET['entity_id']) ? (int) $_GET['entity_id'] : null,
                'from_date'   => $_GET['from_date'] ?? null,
                'to_date'     => $_GET['to_date'] ?? null,
                'search'      => trim($_GET['search'] ?? ''),
                'ip_address'  => $_GET['ip_address'] ?? null,
                'sort_by'     => $_GET['sort_by'] ?? 'created_at',
                'sort_order'  => strtolower($_GET['sort_order'] ?? 'desc'),
                'limit'       => 10000, // حد أقصى 10,000 سجل
            ];

            $this->validateFilters($filters);

            // جلب السجلات
            $logs = $this->auditService->getLogs($filters, $filters['limit'], 0);

            // إضافة معلومات إضافية
            foreach ($logs as &$log) {
                $log['action_label'] = $this->translateAction($log['action']);
                $log['entity_type_label'] = $this->translateEntityType($log['entity_type']);

                // تحويل JSON إلى نص للقراءة
                if (is_array($log['old_values'])) {
                    $log['old_values'] = json_encode($log['old_values'], JSON_UNESCAPED_UNICODE);
                }
                if (is_array($log['new_values'])) {
                    $log['new_values'] = json_encode($log['new_values'], JSON_UNESCAPED_UNICODE);
                }
            }

            // تصدير حسب الصيغة
            $filename = 'audit_log_' . date('Y-m-d_H-i-s');

            switch ($format) {
                case 'csv':
                    Response::csv($logs, $filename . '.csv', 200);
                    break;

                case 'excel':
                    $this->exportAsExcel($logs, $filename);
                    break;

                case 'pdf':
                    $this->exportAsHtml($logs, $filename);
                    break;
            }

        } catch (Throwable $e) {
            error_log('[AUDIT_CONTROLLER] Export failed: ' . $e->getMessage());
            Response::internalError('فشل في تصدير السجلات');
        }
    }

    /**
     * تصدير كـ Excel
     */
    private function exportAsExcel(array $logs, string $filename): void
    {
        if (empty($logs)) {
            Response::error('لا توجد بيانات للتصدير', 'NO_DATA', 404);
        }

        $html = '<html dir="rtl"><head><meta charset="UTF-8"></head><body>';
        $html .= '<h2 style="text-align: center;">سجل التدقيق - Logistox</h2>';
        $html .= '<p style="text-align: center;">تاريخ التقرير: ' . date('Y-m-d H:i:s') . '</p>';

        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%; font-family: Arial;">';
        $html .= '<tr style="background: #4a90e2; color: white;">';
        $html .= '<th>#</th>';
        $html .= '<th>التاريخ</th>';
        $html .= '<th>المستخدم</th>';
        $html .= '<th>الإجراء</th>';
        $html .= '<th>نوع الكيان</th>';
        $html .= '<th>معرف الكيان</th>';
        $html .= '<th>الوصف</th>';
        $html .= '<th>IP</th>';
        $html .= '</tr>';

        $index = 1;
        foreach ($logs as $log) {
            $html .= '<tr>';
            $html .= '<td>' . $index++ . '</td>';
            $html .= '<td>' . htmlspecialchars($log['created_at'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($log['user_name'] ?? $log['username'] ?? '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($log['action_label'] ?? $log['action'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($log['entity_type_label'] ?? $log['entity_type'] ?? '-') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($log['entity_id'] ?? '-')) . '</td>';
            $html .= '<td>' . htmlspecialchars($log['description'] ?? '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($log['ip_address'] ?? '-') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        $html .= '</body></html>';

        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename . '.xls') . '"');
        echo "\xEF\xBB\xBF"; // BOM
        echo $html;
        exit;
    }

    /**
     * تصدير كـ HTML (PDF)
     */
    private function exportAsHtml(array $logs, string $filename): void
    {
        if (empty($logs)) {
            Response::error('لا توجد بيانات للتصدير', 'NO_DATA', 404);
        }

        $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>سجل التدقيق</title>';
        $html .= '<style>
            body { font-family: Tahoma, Arial; direction: rtl; margin: 20px; }
            h2 { text-align: center; color: #2c3e50; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 11px; }
            th, td { padding: 8px; text-align: right; border: 1px solid #bdc3c7; }
            thead tr { background: #34495e; color: white; }
            tbody tr:nth-child(even) { background: #f9f9f9; }
            @media print { body { margin: 0; } }
        </style>';
        $html .= '</head><body>';

        $html .= '<h2>سجل التدقيق - Logistox</h2>';
        $html .= '<p style="text-align: center;">تاريخ التقرير: ' . date('Y-m-d H:i:s') . ' | عدد السجلات: ' . count($logs) . '</p>';

        $html .= '<table>';
        $html .= '<thead><tr>';
        $html .= '<th>#</th>';
        $html .= '<th>التاريخ</th>';
        $html .= '<th>المستخدم</th>';
        $html .= '<th>الإجراء</th>';
        $html .= '<th>نوع الكيان</th>';
        $html .= '<th>معرف الكيان</th>';
        $html .= '<th>الوصف</th>';
        $html .= '<th>IP</th>';
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        $index = 1;
        foreach ($logs as $log) {
            $html .= '<tr>';
            $html .= '<td>' . $index++ . '</td>';
            $html .= '<td>' . htmlspecialchars($log['created_at'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($log['user_name'] ?? $log['username'] ?? '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($log['action_label'] ?? $log['action'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($log['entity_type_label'] ?? $log['entity_type'] ?? '-') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($log['entity_id'] ?? '-')) . '</td>';
            $html .= '<td>' . htmlspecialchars($log['description'] ?? '-') . '</td>';
            $html .= '<td>' . htmlspecialchars($log['ip_address'] ?? '-') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= '</body></html>';

        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    // =========================================================================
    // 4. إحصائيات السجلات (Statistics)
    // =========================================================================

    /**
     * جلب إحصائيات سجلات التدقيق
     *
     * GET /api/audit/statistics
     *
     * Query Parameters:
     * - from_date: من تاريخ
     * - to_date: إلى تاريخ
     *
     * @return void يرسل استجابة JSON
     */
    public function statistics(): void
    {
        try {
            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');

            // 1. إجمالي السجلات
            $totalLogs = $this->db->selectOne("
                SELECT COUNT(*) AS count
                FROM audit_logs
                WHERE DATE(created_at) BETWEEN ? AND ?
            ", [$fromDate, $toDate]);

            // 2. أكثر الإجراءات تكراراً
            $topActions = $this->db->select("
                SELECT
                    action,
                    COUNT(*) AS count
                FROM audit_logs
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY action
                ORDER BY count DESC
                LIMIT 10
            ", [$fromDate, $toDate]);

            // 3. أكثر المستخدمين نشاطاً
            $topUsers = $this->db->select("
                SELECT
                    u.id,
                    u.username,
                    u.full_name,
                    COUNT(al.id) AS action_count
                FROM audit_logs al
                INNER JOIN users u ON al.user_id = u.id
                WHERE DATE(al.created_at) BETWEEN ? AND ?
                GROUP BY u.id, u.username, u.full_name
                ORDER BY action_count DESC
                LIMIT 10
            ", [$fromDate, $toDate]);

            // 4. أكثر الكيانات تعديلاً
            $topEntities = $this->db->select("
                SELECT
                    entity_type,
                    COUNT(*) AS count
                FROM audit_logs
                WHERE DATE(created_at) BETWEEN ? AND ?
                  AND entity_type IS NOT NULL
                GROUP BY entity_type
                ORDER BY count DESC
                LIMIT 10
            ", [$fromDate, $toDate]);

            // 5. محاولات الدخول الفاشلة
            $failedLogins = $this->db->selectOne("
                SELECT COUNT(*) AS count
                FROM audit_logs
                WHERE action = 'LOGIN_FAILED'
                  AND DATE(created_at) BETWEEN ? AND ?
            ", [$fromDate, $toDate]);

            // 6. محاولات الوصول المرفوضة
            $accessDenied = $this->db->selectOne("
                SELECT COUNT(*) AS count
                FROM audit_logs
                WHERE action = 'ACCESS_DENIED'
                  AND DATE(created_at) BETWEEN ? AND ?
            ", [$fromDate, $toDate]);

            Response::success(
                message: 'تم جلب الإحصائيات بنجاح',
                data: [
                    'period' => [
                        'from' => $fromDate,
                        'to'   => $toDate,
                    ],
                    'total_logs'      => (int) ($totalLogs['count'] ?? 0),
                    'top_actions'     => $topActions,
                    'top_users'       => $topUsers,
                    'top_entities'    => $topEntities,
                    'failed_logins'   => (int) ($failedLogins['count'] ?? 0),
                    'access_denied'   => (int) ($accessDenied['count'] ?? 0),
                ]
            );

        } catch (Throwable $e) {
            error_log('[AUDIT_CONTROLLER] Statistics failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الإحصائيات');
        }
    }

    // =========================================================================
    // 5. نشاط مستخدم معين (User Activity)
    // =========================================================================

    /**
     * جلب سجل نشاط مستخدم معين
     *
     * GET /api/audit/user/{userId}
     *
     * Query Parameters:
     * - from_date: من تاريخ
     * - to_date: إلى تاريخ
     * - limit: حد النتائج (افتراضي: 100، حد أقصى: 1000)
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function userActivity(array $params): void
    {
        try {
            $userId = $params['user_id'] ?? null;

            if ($userId === null || !is_numeric($userId) || (int) $userId <= 0) {
                Response::badRequest('معرف المستخدم غير صالح');
            }
            $userId = (int) $userId;

            // التحقق من وجود المستخدم
            $user = $this->db->selectOne(
                "SELECT id, username, full_name FROM users WHERE id = ? AND deleted_at IS NULL",
                [$userId]
            );

            if (!$user) {
                Response::notFound('المستخدم غير موجود');
            }

            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');
            $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 100)));

            $logs = $this->auditService->getLogs([
                'user_id'   => $userId,
                'from_date' => $fromDate,
                'to_date'   => $toDate,
            ], $limit, 0);

            // إضافة معلومات إضافية
            foreach ($logs as &$log) {
                $log['action_label'] = $this->translateAction($log['action']);
                $log['entity_type_label'] = $this->translateEntityType($log['entity_type']);
            }

            Response::success(
                message: 'تم جلب نشاط المستخدم بنجاح',
                data: [
                    'user'  => $user,
                    'logs'  => $logs,
                    'count' => count($logs),
                ]
            );

        } catch (Throwable $e) {
            error_log('[AUDIT_CONTROLLER] UserActivity failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب نشاط المستخدم');
        }
    }

    // =========================================================================
    // 6. تاريخ كيان معين (Entity History)
    // =========================================================================

    /**
     * جلب تاريخ كيان معين (مثل منتج، مستخدم، مخزن)
     *
     * GET /api/audit/entity/{entityType}/{entityId}
     *
     * Query Parameters:
     * - from_date: من تاريخ
     * - to_date: إلى تاريخ
     * - limit: حد النتائج (افتراضي: 100، حد أقصى: 1000)
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function entityHistory(array $params): void
    {
        try {
            $entityType = $params['entity_type'] ?? null;
            $entityId = $params['entity_id'] ?? null;

            if ($entityType === null || !in_array($entityType, self::ALLOWED_ENTITY_TYPES, true)) {
                Response::badRequest('نوع الكيان غير صالح');
            }

            if ($entityId === null || !is_numeric($entityId) || (int) $entityId <= 0) {
                Response::badRequest('معرف الكيان غير صالح');
            }
            $entityId = (int) $entityId;

            $fromDate = $_GET['from_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $toDate = $_GET['to_date'] ?? date('Y-m-d');
            $limit = min(1000, max(1, (int) ($_GET['limit'] ?? 100)));

            $logs = $this->auditService->getLogs([
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'from_date'   => $fromDate,
                'to_date'     => $toDate,
            ], $limit, 0);

            // إضافة معلومات إضافية
            foreach ($logs as &$log) {
                $log['action_label'] = $this->translateAction($log['action']);
                $log['entity_type_label'] = $this->translateEntityType($log['entity_type']);
            }

            Response::success(
                message: 'تم جلب تاريخ الكيان بنجاح',
                data: [
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'logs'        => $logs,
                    'count'       => count($logs),
                ]
            );

        } catch (Throwable $e) {
            error_log('[AUDIT_CONTROLLER] EntityHistory failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب تاريخ الكيان');
        }
    }

    // =========================================================================
    // 7. تنظيف السجلات القديمة (Cleanup) - Super Admin فقط
    // =========================================================================

    /**
     * تنظيف السجلات القديمة
     *
     * POST /api/audit/cleanup
     *
     * Request Body (JSON):
     * {
     *   "days_to_keep": 90,
     *   "confirm": true
     * }
     *
     * ملاحظة: هذه العملية خطيرة ولا يمكن التراجع عنها
     *
     * @return void يرسل استجابة JSON
     */
    public function cleanup(): void
    {
        try {
            $currentUserId = $this->getCurrentUserId();

            // التحقق من أن المستخدم هو Super Admin
            $user = $this->db->selectOne(
                "SELECT role_id FROM users WHERE id = ? AND deleted_at IS NULL",
                [$currentUserId]
            );

            if (!$user || (int) $user['role_id'] !== 1) {
                Response::forbidden('هذه العملية متاحة لمدير النظام فقط');
            }

            $input = $this->getJsonInput();

            $daysToKeep = (int) ($input['days_to_keep'] ?? 90);
            $confirm = (bool) ($input['confirm'] ?? false);

            if ($daysToKeep < 30) {
                Response::badRequest('يجب الاحتفاظ بالسجلات لمدة 30 يوماً على الأقل');
            }

            if (!$confirm) {
                Response::badRequest('يجب تأكيد العملية بإرسال confirm: true');
            }

            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));

            // عد السجلات التي سيتم حذفها
            $countResult = $this->db->selectOne(
                "SELECT COUNT(*) AS count FROM audit_logs WHERE created_at < ?",
                [$cutoffDate]
            );

            $logsToDelete = (int) ($countResult['count'] ?? 0);

            if ($logsToDelete === 0) {
                Response::success(
                    message: 'لا توجد سجلات أقدم من ' . $daysToKeep . ' يوم',
                    data: [
                        'deleted_count' => 0,
                    ]
                );
            }

            // حذف السجلات القديمة
            $this->db->execute(
                "DELETE FROM audit_logs WHERE created_at < ?",
                [$cutoffDate]
            );

            // تسجيل العملية
            $this->auditService->log(
                userId: $currentUserId,
                action: 'AUDIT_CLEANUP',
                entityType: null,
                entityId: null,
                description: "تم تنظيف {$logsToDelete} سجل تدقيق أقدم من {$daysToKeep} يوم",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: "تم حذف {$logsToDelete} سجل(ات) بنجاح",
                data: [
                    'deleted_count' => $logsToDelete,
                    'cutoff_date'   => $cutoffDate,
                ]
            );

        } catch (Throwable $e) {
            error_log('[AUDIT_CONTROLLER] Cleanup failed: ' . $e->getMessage());
            Response::internalError('فشل في تنظيف السجلات: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Helper Methods - التحقق
    // =========================================================================

    /**
     * التحقق من صحة الفلاتر
     */
    private function validateFilters(array $filters): void
    {
        // التحقق من action
        if (!empty($filters['action']) && !in_array($filters['action'], self::ALLOWED_ACTIONS, true)) {
            // إذا كان الإجراء غير معروف، نزيله من الفلاتر
            unset($filters['action']);
        }

        // التحقق من entity_type
        if (!empty($filters['entity_type']) && !in_array($filters['entity_type'], self::ALLOWED_ENTITY_TYPES, true)) {
            unset($filters['entity_type']);
        }

        // التحقق من التواريخ
        if (!empty($filters['from_date'])) {
            $dateTime = \DateTime::createFromFormat('Y-m-d', $filters['from_date']);
            if (!$dateTime || $dateTime->format('Y-m-d') !== $filters['from_date']) {
                Response::badRequest('تاريخ البداية غير صالح. الصيغة: YYYY-MM-DD');
            }
        }

        if (!empty($filters['to_date'])) {
            $dateTime = \DateTime::createFromFormat('Y-m-d', $filters['to_date']);
            if (!$dateTime || $dateTime->format('Y-m-d') !== $filters['to_date']) {
                Response::badRequest('تاريخ النهاية غير صالح. الصيغة: YYYY-MM-DD');
            }
        }
    }

    /**
     * التحقق من صحة معرف السجل
     */
    private function validateLogId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف السجل غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    // =========================================================================
    // Helper Methods - الترجمة
    // =========================================================================

    /**
     * ترجمة اسم الإجراء إلى العربية
     */
    private function translateAction(string $action): string
    {
        $translations = [
            'LOGIN_SUCCESS'                  => 'تسجيل دخول ناجح',
            'LOGIN_FAILED'                   => 'محاولة دخول فاشلة',
            'LOGOUT'                         => 'تسجيل خروج',
            'PRODUCT_CREATE'                 => 'إضافة منتج',
            'PRODUCT_UPDATE'                 => 'تعديل منتج',
            'PRODUCT_DELETE'                 => 'حذف منتج',
            'WAREHOUSE_CREATE'               => 'إضافة مخزن',
            'WAREHOUSE_UPDATE'               => 'تعديل مخزن',
            'WAREHOUSE_DELETE'               => 'حذف مخزن',
            'RECEIPT_CREATE'                 => 'إنشاء إذن استلام',
            'RECEIPT_UPDATE'                 => 'تعديل إذن استلام',
            'RECEIPT_APPROVE'                => 'اعتماد إذن استلام',
            'RECEIPT_CANCEL'                 => 'إلغاء إذن استلام',
            'RECEIPT_DELETE'                 => 'حذف إذن استلام',
            'ISSUE_CREATE'                   => 'إنشاء إذن صرف',
            'ISSUE_UPDATE'                   => 'تعديل إذن صرف',
            'ISSUE_APPROVE'                  => 'اعتماد إذن صرف',
            'ISSUE_CANCEL'                   => 'إلغاء إذن صرف',
            'ISSUE_DELETE'                   => 'حذف إذن صرف',
            'TRANSFER_CREATE'                => 'إنشاء تحويل',
            'TRANSFER_UPDATE'                => 'تعديل تحويل',
            'TRANSFER_APPROVE'               => 'اعتماد تحويل',
            'TRANSFER_CANCEL'                => 'إلغاء تحويل',
            'TRANSFER_DELETE'                => 'حذف تحويل',
            'RETURN_CREATE'                  => 'إنشاء مرتجع',
            'RETURN_UPDATE'                  => 'تعديل مرتجع',
            'RETURN_DELETE'                  => 'حذف مرتجع',
            'INVENTORY_COUNT_CREATE'         => 'إنشاء عملية جرد',
            'INVENTORY_COUNT_UPDATE'         => 'تعديل عملية جرد',
            'INVENTORY_COUNT_START'          => 'بدء الجرد',
            'INVENTORY_COUNT_COMPLETE'       => 'إكمال الجرد',
            'INVENTORY_COUNT_APPROVE'        => 'اعتماد الجرد',
            'INVENTORY_COUNT_CANCEL'         => 'إلغاء الجرد',
            'INVENTORY_COUNT_DELETE'         => 'حذف عملية جرد',
            'INVENTORY_COUNT_ITEM_ADD'       => 'إضافة بند للجرد',
            'INVENTORY_COUNT_ITEM_UPDATE'    => 'تعديل بند في الجرد',
            'INVENTORY_COUNT_ITEM_REMOVE'    => 'حذف بند من الجرد',
            'USER_CREATE'                    => 'إنشاء مستخدم',
            'USER_UPDATE'                    => 'تعديل مستخدم',
            'USER_DELETE'                    => 'حذف مستخدم',
            'USER_RESET_PASSWORD'            => 'إعادة تعيين كلمة المرور',
            'USER_SET_ACTIVE'                => 'تفعيل/تعطيل مستخدم',
            'ROLE_CREATE'                    => 'إنشاء دور',
            'ROLE_UPDATE'                    => 'تعديل دور',
            'ROLE_DELETE'                    => 'حذف دور',
            'ROLE_PERMISSIONS_UPDATE'        => 'تحديث صلاحيات الدور',
            'REPORT_EXPORT'                  => 'تصدير تقرير',
            'BACKUP_CREATE'                  => 'إنشاء نسخة احتياطية',
            'BACKUP_RESTORE'                 => 'استعادة نسخة احتياطية',
            'BACKUP_DELETE'                  => 'حذف نسخة احتياطية',
            'SETTINGS_UPDATE'                => 'تحديث الإعدادات',
            'ACCESS_DENIED'                  => 'محاولة وصول مرفوضة',
            'AUDIT_CLEANUP'                  => 'تنظيف سجلات التدقيق',
        ];

        return $translations[$action] ?? $action;
    }

    /**
     * ترجمة نوع الكيان إلى العربية
     */
    private function translateEntityType(?string $entityType): string
    {
        if ($entityType === null) {
            return '-';
        }

        $translations = [
            'product'              => 'منتج',
            'warehouse'            => 'مخزن',
            'category'             => 'تصنيف',
            'unit'                 => 'وحدة',
            'supplier'             => 'مورد',
            'recipient'            => 'جهة مستلمة',
            'receipt'              => 'إذن استلام',
            'issue'                => 'إذن صرف',
            'transfer'             => 'تحويل',
            'return'               => 'مرتجع',
            'inventory_count'      => 'عملية جرد',
            'inventory_count_item' => 'بند جرد',
            'user'                 => 'مستخدم',
            'role'                 => 'دور',
            'permission'           => 'صلاحية',
            'report'               => 'تقرير',
            'backup'               => 'نسخة احتياطية',
            'setting'              => 'إعداد',
        ];

        return $translations[$entityType] ?? $entityType;
    }

    // =========================================================================
    // Helper Methods - عامة
    // =========================================================================

    /**
     * قراءة مدخلات JSON
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

        error_log('[AUDIT_CONTROLLER] Current user ID not found');
        Response::unauthorized('لم يتم العثور على بيانات المستخدم');
    }

    /**
     * جلب IP العميل
     */
    private function getClientIp(): string
    {
        if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return trim($_SERVER['REMOTE_ADDR']);
        }

        return '0.0.0.0';
    }
}
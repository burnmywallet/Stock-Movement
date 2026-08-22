<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/RecipientController.php
// الوصف: متحكم إدارة الجهات المستلمة - CRUD كامل مع بحث وتصدير
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Exception;

class RecipientController
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
     * GET /api/recipients
     * جلب قائمة الجهات المستلمة مع فلترة وبحث
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية العرض
            if (!$this->auth->hasPermission($userId, 'recipients.view')) {
                errorResponse('ليس لديك صلاحية لعرض الجهات المستلمة', 403);
                return;
            }

            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $search = $_GET['search'] ?? '';
            $type = $_GET['type'] ?? '';
            $status = $_GET['status'] ?? '';
            $sort = $_GET['sort'] ?? 'name';
            $order = $_GET['order'] ?? 'ASC';

            $params = [];
            $where = ["r.deleted_at IS NULL"];
            
            if (!empty($search)) {
                $where[] = "(r.name LIKE :search OR r.code LIKE :search OR r.contact_person LIKE :search OR r.email LIKE :search)";
                $params['search'] = "%{$search}%";
            }
            
            if (!empty($type)) {
                $where[] = "r.type = :type";
                $params['type'] = $type;
            }
            
            if ($status === 'active') {
                $where[] = "r.is_active = 1";
            } elseif ($status === 'inactive') {
                $where[] = "r.is_active = 0";
            }

            $allowedSorts = ['id', 'code', 'name', 'type', 'created_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'name';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب الجهات
            $recipients = $this->db->query("
                SELECT 
                    r.id,
                    r.code,
                    r.name,
                    r.type,
                    CASE 
                        WHEN r.type = 'internal' THEN 'داخلي'
                        WHEN r.type = 'external' THEN 'خارجي'
                        WHEN r.type = 'customer' THEN 'عميل'
                        WHEN r.type = 'department' THEN 'قسم'
                        WHEN r.type = 'project' THEN 'مشروع'
                        ELSE r.type
                    END as type_label,
                    r.contact_person,
                    r.phone,
                    r.email,
                    r.address,
                    r.is_active,
                    r.notes,
                    r.created_at,
                    r.updated_at,
                    (SELECT COUNT(*) FROM issues WHERE recipient_id = r.id) as issue_count,
                    (SELECT COUNT(*) FROM issues WHERE recipient_id = r.id AND status = 'approved') as approved_issues_count,
                    (SELECT COALESCE(SUM(total_cost), 0) FROM issues WHERE recipient_id = r.id AND status = 'approved') as total_issues_value,
                    (SELECT MAX(issue_date) FROM issues WHERE recipient_id = r.id AND status = 'approved') as last_issue_date
                FROM recipients r
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            // إجمالي الجهات
            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM recipients r
                WHERE " . implode(' AND ', $where) . "
            ", $params);

            // إحصائيات إضافية
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive,
                    COUNT(CASE WHEN type = 'internal' THEN 1 END) as internal,
                    COUNT(CASE WHEN type = 'external' THEN 1 END) as external,
                    COUNT(CASE WHEN type = 'customer' THEN 1 END) as customer,
                    COUNT(CASE WHEN type = 'department' THEN 1 END) as department,
                    COUNT(CASE WHEN type = 'project' THEN 1 END) as project
                FROM recipients
                WHERE deleted_at IS NULL
            ");

            successResponse('تم جلب قائمة الجهات المستلمة', [
                'data' => $recipients,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'active' => (int)($stats['active'] ?? 0),
                    'inactive' => (int)($stats['inactive'] ?? 0),
                    'internal' => (int)($stats['internal'] ?? 0),
                    'external' => (int)($stats['external'] ?? 0),
                    'customer' => (int)($stats['customer'] ?? 0),
                    'department' => (int)($stats['department'] ?? 0),
                    'project' => (int)($stats['project'] ?? 0)
                ],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil((int)$total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            error_log('Recipients list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/recipients/{id}
     * جلب بيانات جهة مستلمة مع تفاصيل كاملة
     */
    public function show(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية العرض
            if (!$this->auth->hasPermission($userId, 'recipients.view')) {
                errorResponse('ليس لديك صلاحية لعرض الجهات المستلمة', 403);
                return;
            }

            $recipient = $this->getRecipientById($id);
            
            if (!$recipient) {
                errorResponse('الجهة غير موجودة');
                return;
            }

            // جلب سجل الصرف للجهة
            $issues = $this->db->query("
                SELECT 
                    i.id,
                    i.issue_no,
                    i.issue_date,
                    i.total_items,
                    i.total_quantity,
                    i.total_cost,
                    i.status,
                    i.created_at,
                    u.full_name as created_by,
                    w.name as warehouse_name
                FROM issues i
                LEFT JOIN users u ON u.id = i.user_id
                LEFT JOIN warehouses w ON w.id = i.warehouse_id
                WHERE i.recipient_id = :recipient_id
                ORDER BY i.issue_date DESC
                LIMIT 50
            ", ['recipient_id' => $id]);

            // جلب سجل التدقيق للجهة
            $audits = $this->db->query("
                SELECT 
                    created_at,
                    user_id,
                    (SELECT full_name FROM users WHERE id = user_id) as user_name,
                    action,
                    description
                FROM audit_logs
                WHERE reference_type = 'recipient'
                  AND reference_id = :reference_id
                ORDER BY created_at DESC
                LIMIT 20
            ", ['reference_id' => $id]);

            successResponse('تم جلب بيانات الجهة المستلمة', [
                'recipient' => $recipient,
                'issues' => $issues,
                'audits' => $audits,
                'summary' => [
                    'total_issues' => count($issues),
                    'total_value' => array_sum(array_column($issues, 'total_cost')),
                    'last_issue' => $issues[0]['issue_date'] ?? null
                ]
            ]);

        } catch (Exception $e) {
            error_log('Recipient show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/recipients
     * إنشاء جهة مستلمة جديدة
     */
    public function create(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية الإنشاء
            if (!$this->auth->hasPermission($userId, 'recipients.create')) {
                errorResponse('ليس لديك صلاحية لإنشاء جهات مستلمة', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateRecipientData($input);

            // التحقق من الكود
            if (!empty($input['code'])) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM recipients WHERE code = :code",
                    ['code' => $input['code']]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            } else {
                // توليد كود تلقائي
                $input['code'] = $this->generateRecipientCode($input['name']);
            }

            // التحقق من البريد الإلكتروني
            if (!empty($input['email'])) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM recipients WHERE email = :email",
                    ['email' => $input['email']]
                );
                
                if ($exists) {
                    errorResponse('البريد الإلكتروني مستخدم بالفعل');
                    return;
                }
            }

            // إنشاء الجهة
            $data = [
                'code' => $input['code'],
                'name' => $input['name'],
                'type' => $input['type'] ?? 'internal',
                'contact_person' => $input['contact_person'] ?? null,
                'phone' => $input['phone'] ?? null,
                'email' => $input['email'] ?? null,
                'address' => $input['address'] ?? null,
                'is_active' => $input['is_active'] ?? 1,
                'notes' => $input['notes'] ?? null,
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $recipientId = $this->db->insert('recipients', $data);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RECIPIENT_CREATED',
                'recipients',
                "إنشاء جهة مستلمة جديدة: {$input['name']}",
                ['recipient_id' => $recipientId, 'code' => $input['code']],
                'recipient',
                $recipientId
            );

            successResponse('تم إنشاء الجهة المستلمة بنجاح', ['recipient_id' => $recipientId]);

        } catch (Exception $e) {
            error_log('Recipient create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/recipients/{id}
     * تحديث بيانات جهة مستلمة
     */
    public function update(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية التحديث
            if (!$this->auth->hasPermission($userId, 'recipients.edit')) {
                errorResponse('ليس لديك صلاحية لتحديث الجهات المستلمة', 403);
                return;
            }

            $recipient = $this->getRecipientById($id);
            if (!$recipient) {
                errorResponse('الجهة غير موجودة');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateRecipientData($input, true);

            // التحقق من الكود
            if (!empty($input['code']) && $input['code'] !== $recipient['code']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM recipients WHERE code = :code AND id != :id",
                    ['code' => $input['code'], 'id' => $id]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            }

            // التحقق من البريد الإلكتروني
            if (!empty($input['email']) && $input['email'] !== $recipient['email']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM recipients WHERE email = :email AND id != :id",
                    ['email' => $input['email'], 'id' => $id]
                );
                
                if ($exists) {
                    errorResponse('البريد الإلكتروني مستخدم بالفعل');
                    return;
                }
            }

            // تحديث البيانات
            $data = [
                'code' => $input['code'] ?? $recipient['code'],
                'name' => $input['name'] ?? $recipient['name'],
                'type' => $input['type'] ?? $recipient['type'],
                'contact_person' => $input['contact_person'] ?? $recipient['contact_person'],
                'phone' => $input['phone'] ?? $recipient['phone'],
                'email' => $input['email'] ?? $recipient['email'],
                'address' => $input['address'] ?? $recipient['address'],
                'is_active' => $input['is_active'] ?? $recipient['is_active'],
                'notes' => $input['notes'] ?? $recipient['notes'],
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('recipients', $data, ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RECIPIENT_UPDATED',
                'recipients',
                "تحديث بيانات الجهة المستلمة: {$recipient['name']}",
                ['recipient_id' => $id, 'code' => $recipient['code']],
                'recipient',
                $id
            );

            successResponse('تم تحديث بيانات الجهة المستلمة بنجاح');

        } catch (Exception $e) {
            error_log('Recipient update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/recipients/{id}
     * حذف جهة مستلمة (حذف ناعم)
     */
    public function delete(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية الحذف
            if (!$this->auth->hasPermission($userId, 'recipients.delete')) {
                errorResponse('ليس لديك صلاحية لحذف الجهات المستلمة', 403);
                return;
            }

            $recipient = $this->getRecipientById($id);
            if (!$recipient) {
                errorResponse('الجهة غير موجودة');
                return;
            }

            // التحقق من وجود حركات صرف للجهة
            $hasIssues = $this->db->queryValue(
                "SELECT COUNT(*) FROM issues WHERE recipient_id = :recipient_id",
                ['recipient_id' => $id]
            );

            if ($hasIssues > 0) {
                errorResponse('لا يمكن حذف الجهة لأنها مستخدمة في عمليات صرف');
                return;
            }

            // الحذف الناعم
            $this->db->softDelete('recipients', ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'RECIPIENT_DELETED',
                'recipients',
                "حذف الجهة المستلمة: {$recipient['name']}",
                ['recipient_id' => $id, 'code' => $recipient['code']],
                'recipient',
                $id
            );

            successResponse('تم حذف الجهة المستلمة بنجاح');

        } catch (Exception $e) {
            error_log('Recipient delete error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/recipients/export
     * تصدير الجهات المستلمة
     */
    public function export(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية التصدير
            if (!$this->auth->hasPermission($userId, 'recipients.export')) {
                errorResponse('ليس لديك صلاحية لتصدير الجهات المستلمة', 403);
                return;
            }

            $format = $_GET['format'] ?? 'csv';
            $type = $_GET['type'] ?? '';
            $status = $_GET['status'] ?? '';

            $params = [];
            $where = ["deleted_at IS NULL"];
            
            if (!empty($type)) {
                $where[] = "type = :type";
                $params['type'] = $type;
            }
            
            if ($status === 'active') {
                $where[] = "is_active = 1";
            } elseif ($status === 'inactive') {
                $where[] = "is_active = 0";
            }

            $recipients = $this->db->query("
                SELECT 
                    code,
                    name,
                    type,
                    contact_person,
                    phone,
                    email,
                    address,
                    is_active,
                    notes,
                    created_at
                FROM recipients
                WHERE " . implode(' AND ', $where) . "
                ORDER BY name
            ", $params);

            if ($format === 'csv') {
                $this->exportCSV($recipients);
            } elseif ($format === 'excel') {
                $this->exportExcel($recipients);
            } else {
                successResponse('تم جلب بيانات التصدير', $recipients);
            }

        } catch (Exception $e) {
            error_log('Recipient export error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/recipients/types
     * جلب أنواع الجهات المستلمة
     */
    public function types(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $types = [
                ['value' => 'internal', 'label' => 'داخلي'],
                ['value' => 'external', 'label' => 'خارجي'],
                ['value' => 'customer', 'label' => 'عميل'],
                ['value' => 'department', 'label' => 'قسم'],
                ['value' => 'project', 'label' => 'مشروع']
            ];

            successResponse('تم جلب أنواع الجهات المستلمة', $types);

        } catch (Exception $e) {
            error_log('Recipient types error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على جهة مستلمة بالمعرف
     */
    private function getRecipientById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                r.*,
                u.full_name as created_by_name,
                (SELECT COUNT(*) FROM issues WHERE recipient_id = r.id) as issue_count,
                (SELECT COALESCE(SUM(total_cost), 0) FROM issues WHERE recipient_id = r.id AND status = 'approved') as total_issues_value
            FROM recipients r
            LEFT JOIN users u ON u.id = r.created_by
            WHERE r.id = :id AND r.deleted_at IS NULL
        ", ['id' => $id]);
    }

    /**
     * توليد كود جهة مستلمة تلقائي
     */
    private function generateRecipientCode(string $name): string
    {
        $prefix = 'REC';
        $year = date('Y');
        $month = date('m');
        
        $last = $this->db->queryValue("
            SELECT MAX(CAST(SUBSTRING(code, -4) AS UNSIGNED)) 
            FROM recipients 
            WHERE code LIKE :pattern
        ", ['pattern' => "{$prefix}{$year}{$month}%"]);

        $number = str_pad((int)$last + 1, 4, '0', STR_PAD_LEFT);
        return "{$prefix}{$year}{$month}{$number}";
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
        header('Content-Disposition: attachment; filename="recipients_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        $headers = array_keys($data[0]);
        fputcsv($output, $headers);
        
        foreach ($data as $row) {
            fputcsv($output, $row);
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
        header('Content-Disposition: attachment; filename="recipients_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1">';
        echo '<tr style="background:#667eea;color:#fff;font-weight:bold;">';
        foreach (array_keys($data[0]) as $header) {
            echo '<th>' . $this->translateHeader($header) . '</th>';
        }
        echo '</tr>';
        
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $value) {
                echo '<td>' . $value . '</td>';
            }
            echo '</tr>';
        }
        
        echo '</table>';
        exit;
    }

    /**
     * ترجمة رؤوس الأعمدة
     */
    private function translateHeader(string $header): string
    {
        $map = [
            'code' => 'الكود',
            'name' => 'الاسم',
            'type' => 'النوع',
            'contact_person' => 'شخص الاتصال',
            'phone' => 'الهاتف',
            'email' => 'البريد الإلكتروني',
            'address' => 'العنوان',
            'is_active' => 'نشط',
            'notes' => 'ملاحظات',
            'created_at' => 'تاريخ الإنشاء'
        ];
        return $map[$header] ?? $header;
    }

    /**
     * التحقق من صحة بيانات الجهة المستلمة
     */
    private function validateRecipientData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['name'])) {
            errorResponse('اسم الجهة المستلمة مطلوب');
            return;
        }
        
        if (!empty($data['type'])) {
            $allowedTypes = ['internal', 'external', 'customer', 'department', 'project'];
            if (!in_array($data['type'], $allowedTypes)) {
                errorResponse('نوع الجهة غير صحيح');
                return;
            }
        }
        
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            errorResponse('البريد الإلكتروني غير صحيح');
            return;
        }
        
        if (!empty($data['phone']) && !preg_match('/^[0-9+\-\s()]{7,20}$/', $data['phone'])) {
            errorResponse('رقم الهاتف غير صحيح');
            return;
        }
    }
}

// ================================================================
// انتهى الملف
// ================================================================

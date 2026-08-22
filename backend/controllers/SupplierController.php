<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/SupplierController.php
// الوصف: متحكم إدارة الموردين - CRUD كامل مع بحث وتصدير
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Exception;

class SupplierController
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
     * GET /api/suppliers
     * جلب قائمة الموردين مع فلترة وبحث
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
            if (!$this->auth->hasPermission($userId, 'suppliers.view')) {
                errorResponse('ليس لديك صلاحية لعرض الموردين', 403);
                return;
            }

            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            $city = $_GET['city'] ?? '';
            $rating = $_GET['rating'] ?? '';
            $sort = $_GET['sort'] ?? 'name';
            $order = $_GET['order'] ?? 'ASC';

            $params = [];
            $where = ["s.deleted_at IS NULL"];
            
            if (!empty($search)) {
                $where[] = "(s.name LIKE :search OR s.code LIKE :search OR s.contact_person LIKE :search OR s.email LIKE :search OR s.phone LIKE :search)";
                $params['search'] = "%{$search}%";
            }
            
            if ($status === 'active') {
                $where[] = "s.is_active = 1";
            } elseif ($status === 'inactive') {
                $where[] = "s.is_active = 0";
            }
            
            if (!empty($city)) {
                $where[] = "s.city LIKE :city";
                $params['city'] = "%{$city}%";
            }
            
            if (!empty($rating)) {
                $where[] = "s.rating >= :rating";
                $params['rating'] = $rating;
            }

            $allowedSorts = ['id', 'code', 'name', 'city', 'rating', 'created_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'name';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب الموردين
            $suppliers = $this->db->query("
                SELECT 
                    s.id,
                    s.code,
                    s.name,
                    s.contact_person,
                    s.phone,
                    s.mobile,
                    s.email,
                    s.website,
                    s.address,
                    s.city,
                    s.country,
                    s.postal_code,
                    s.tax_number,
                    s.commercial_register,
                    s.payment_terms,
                    s.credit_limit,
                    s.is_active,
                    s.rating,
                    s.notes,
                    s.created_at,
                    s.updated_at,
                    (SELECT COUNT(*) FROM receipts WHERE supplier_id = s.id) as receipt_count,
                    (SELECT COALESCE(SUM(total_cost), 0) FROM receipts WHERE supplier_id = s.id AND status = 'approved') as total_purchases,
                    (SELECT COUNT(*) FROM returns WHERE reference_type = 'receipt' AND reference_id IN (SELECT id FROM receipts WHERE supplier_id = s.id)) as return_count,
                    (SELECT MAX(receipt_date) FROM receipts WHERE supplier_id = s.id AND status = 'approved') as last_purchase_date
                FROM suppliers s
                WHERE " . implode(' AND ', $where) . "
                ORDER BY s.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            // إجمالي الموردين
            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM suppliers s
                WHERE " . implode(' AND ', $where) . "
            ", $params);

            // إحصائيات إضافية
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive,
                    COUNT(DISTINCT city) as cities_count,
                    COUNT(DISTINCT country) as countries_count,
                    ROUND(AVG(rating), 1) as avg_rating,
                    COUNT(CASE WHEN rating >= 4 THEN 1 END) as top_rated,
                    COUNT(CASE WHEN rating <= 2 THEN 1 END) as low_rated
                FROM suppliers
                WHERE deleted_at IS NULL
            ");

            successResponse('تم جلب قائمة الموردين', [
                'data' => $suppliers,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'active' => (int)($stats['active'] ?? 0),
                    'inactive' => (int)($stats['inactive'] ?? 0),
                    'cities_count' => (int)($stats['cities_count'] ?? 0),
                    'countries_count' => (int)($stats['countries_count'] ?? 0),
                    'avg_rating' => (float)($stats['avg_rating'] ?? 0),
                    'top_rated' => (int)($stats['top_rated'] ?? 0),
                    'low_rated' => (int)($stats['low_rated'] ?? 0)
                ],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil((int)$total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            error_log('Suppliers list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/suppliers/{id}
     * جلب بيانات مورد مع تفاصيل كاملة
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
            if (!$this->auth->hasPermission($userId, 'suppliers.view')) {
                errorResponse('ليس لديك صلاحية لعرض الموردين', 403);
                return;
            }

            $supplier = $this->getSupplierById($id);
            
            if (!$supplier) {
                errorResponse('المورد غير موجود');
                return;
            }

            // جلب سجل المشتريات
            $purchases = $this->db->query("
                SELECT 
                    r.id,
                    r.receipt_no,
                    r.receipt_date,
                    r.total_items,
                    r.total_quantity,
                    r.total_cost,
                    r.status,
                    r.created_at,
                    u.full_name as created_by
                FROM receipts r
                LEFT JOIN users u ON u.id = r.user_id
                WHERE r.supplier_id = :supplier_id
                ORDER BY r.receipt_date DESC
                LIMIT 50
            ", ['supplier_id' => $id]);

            // جلب سجل المرتجعات للمورد
            $returns = $this->db->query("
                SELECT 
                    ret.id,
                    ret.return_no,
                    ret.return_date,
                    ret.total_items,
                    ret.total_quantity,
                    ret.total_cost,
                    ret.reason,
                    ret.status,
                    ret.created_at
                FROM returns ret
                WHERE ret.reference_type = 'receipt' 
                  AND ret.reference_id IN (SELECT id FROM receipts WHERE supplier_id = :supplier_id)
                ORDER BY ret.return_date DESC
                LIMIT 20
            ", ['supplier_id' => $id]);

            // جلب سجل المدفوعات (إذا كان هناك جدول)
            // يمكن إضافته لاحقاً

            // جلب سجل التدقيق للمورد
            $audits = $this->db->query("
                SELECT 
                    created_at,
                    user_id,
                    (SELECT full_name FROM users WHERE id = user_id) as user_name,
                    action,
                    description
                FROM audit_logs
                WHERE reference_type = 'supplier'
                  AND reference_id = :reference_id
                ORDER BY created_at DESC
                LIMIT 20
            ", ['reference_id' => $id]);

            successResponse('تم جلب بيانات المورد', [
                'supplier' => $supplier,
                'purchases' => $purchases,
                'returns' => $returns,
                'audits' => $audits,
                'summary' => [
                    'total_purchases' => count($purchases),
                    'total_value' => array_sum(array_column($purchases, 'total_cost')),
                    'total_returns' => count($returns),
                    'last_purchase' => $purchases[0]['receipt_date'] ?? null
                ]
            ]);

        } catch (Exception $e) {
            error_log('Supplier show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/suppliers
     * إنشاء مورد جديد
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
            if (!$this->auth->hasPermission($userId, 'suppliers.create')) {
                errorResponse('ليس لديك صلاحية لإنشاء موردين', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateSupplierData($input);

            // التحقق من الكود
            if (!empty($input['code'])) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM suppliers WHERE code = :code",
                    ['code' => $input['code']]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            } else {
                // توليد كود تلقائي
                $input['code'] = $this->generateSupplierCode();
            }

            // التحقق من البريد الإلكتروني
            if (!empty($input['email'])) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM suppliers WHERE email = :email",
                    ['email' => $input['email']]
                );
                
                if ($exists) {
                    errorResponse('البريد الإلكتروني مستخدم بالفعل');
                    return;
                }
            }

            // إنشاء المورد
            $data = [
                'code' => $input['code'],
                'name' => $input['name'],
                'contact_person' => $input['contact_person'] ?? null,
                'phone' => $input['phone'] ?? null,
                'mobile' => $input['mobile'] ?? null,
                'email' => $input['email'] ?? null,
                'website' => $input['website'] ?? null,
                'address' => $input['address'] ?? null,
                'city' => $input['city'] ?? null,
                'country' => $input['country'] ?? null,
                'postal_code' => $input['postal_code'] ?? null,
                'tax_number' => $input['tax_number'] ?? null,
                'commercial_register' => $input['commercial_register'] ?? null,
                'payment_terms' => $input['payment_terms'] ?? null,
                'credit_limit' => $input['credit_limit'] ?? 0,
                'is_active' => $input['is_active'] ?? 1,
                'rating' => $input['rating'] ?? 3,
                'notes' => $input['notes'] ?? null,
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $supplierId = $this->db->insert('suppliers', $data);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'SUPPLIER_CREATED',
                'suppliers',
                "إنشاء مورد جديد: {$input['name']}",
                ['supplier_id' => $supplierId, 'code' => $input['code']],
                'supplier',
                $supplierId
            );

            successResponse('تم إنشاء المورد بنجاح', ['supplier_id' => $supplierId]);

        } catch (Exception $e) {
            error_log('Supplier create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/suppliers/{id}
     * تحديث بيانات مورد
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
            if (!$this->auth->hasPermission($userId, 'suppliers.edit')) {
                errorResponse('ليس لديك صلاحية لتحديث الموردين', 403);
                return;
            }

            $supplier = $this->getSupplierById($id);
            if (!$supplier) {
                errorResponse('المورد غير موجود');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateSupplierData($input, true);

            // التحقق من الكود
            if (!empty($input['code']) && $input['code'] !== $supplier['code']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM suppliers WHERE code = :code AND id != :id",
                    ['code' => $input['code'], 'id' => $id]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            }

            // التحقق من البريد الإلكتروني
            if (!empty($input['email']) && $input['email'] !== $supplier['email']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM suppliers WHERE email = :email AND id != :id",
                    ['email' => $input['email'], 'id' => $id]
                );
                
                if ($exists) {
                    errorResponse('البريد الإلكتروني مستخدم بالفعل');
                    return;
                }
            }

            // تحديث البيانات
            $data = [
                'code' => $input['code'] ?? $supplier['code'],
                'name' => $input['name'] ?? $supplier['name'],
                'contact_person' => $input['contact_person'] ?? $supplier['contact_person'],
                'phone' => $input['phone'] ?? $supplier['phone'],
                'mobile' => $input['mobile'] ?? $supplier['mobile'],
                'email' => $input['email'] ?? $supplier['email'],
                'website' => $input['website'] ?? $supplier['website'],
                'address' => $input['address'] ?? $supplier['address'],
                'city' => $input['city'] ?? $supplier['city'],
                'country' => $input['country'] ?? $supplier['country'],
                'postal_code' => $input['postal_code'] ?? $supplier['postal_code'],
                'tax_number' => $input['tax_number'] ?? $supplier['tax_number'],
                'commercial_register' => $input['commercial_register'] ?? $supplier['commercial_register'],
                'payment_terms' => $input['payment_terms'] ?? $supplier['payment_terms'],
                'credit_limit' => $input['credit_limit'] ?? $supplier['credit_limit'],
                'is_active' => $input['is_active'] ?? $supplier['is_active'],
                'rating' => $input['rating'] ?? $supplier['rating'],
                'notes' => $input['notes'] ?? $supplier['notes'],
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('suppliers', $data, ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'SUPPLIER_UPDATED',
                'suppliers',
                "تحديث بيانات المورد: {$supplier['name']}",
                ['supplier_id' => $id, 'code' => $supplier['code']],
                'supplier',
                $id
            );

            successResponse('تم تحديث بيانات المورد بنجاح');

        } catch (Exception $e) {
            error_log('Supplier update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/suppliers/{id}
     * حذف مورد (حذف ناعم)
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
            if (!$this->auth->hasPermission($userId, 'suppliers.delete')) {
                errorResponse('ليس لديك صلاحية لحذف الموردين', 403);
                return;
            }

            $supplier = $this->getSupplierById($id);
            if (!$supplier) {
                errorResponse('المورد غير موجود');
                return;
            }

            // التحقق من وجود مشتريات للمورد
            $hasPurchases = $this->db->queryValue(
                "SELECT COUNT(*) FROM receipts WHERE supplier_id = :supplier_id",
                ['supplier_id' => $id]
            );

            if ($hasPurchases > 0) {
                errorResponse('لا يمكن حذف المورد لأنه توجد له مشتريات مسجلة');
                return;
            }

            // الحذف الناعم
            $this->db->softDelete('suppliers', ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'SUPPLIER_DELETED',
                'suppliers',
                "حذف المورد: {$supplier['name']}",
                ['supplier_id' => $id, 'code' => $supplier['code']],
                'supplier',
                $id
            );

            successResponse('تم حذف المورد بنجاح');

        } catch (Exception $e) {
            error_log('Supplier delete error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/suppliers/export
     * تصدير الموردين
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
            if (!$this->auth->hasPermission($userId, 'suppliers.export')) {
                errorResponse('ليس لديك صلاحية لتصدير الموردين', 403);
                return;
            }

            $format = $_GET['format'] ?? 'csv';
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';

            $params = [];
            $where = ["deleted_at IS NULL"];
            
            if (!empty($search)) {
                $where[] = "(name LIKE :search OR code LIKE :search OR contact_person LIKE :search)";
                $params['search'] = "%{$search}%";
            }
            
            if ($status === 'active') {
                $where[] = "is_active = 1";
            } elseif ($status === 'inactive') {
                $where[] = "is_active = 0";
            }

            $suppliers = $this->db->query("
                SELECT 
                    code,
                    name,
                    contact_person,
                    phone,
                    mobile,
                    email,
                    website,
                    address,
                    city,
                    country,
                    tax_number,
                    commercial_register,
                    payment_terms,
                    credit_limit,
                    is_active,
                    rating,
                    created_at
                FROM suppliers
                WHERE " . implode(' AND ', $where) . "
                ORDER BY name
            ", $params);

            if ($format === 'csv') {
                $this->exportCSV($suppliers);
            } elseif ($format === 'excel') {
                $this->exportExcel($suppliers);
            } else {
                successResponse('تم جلب بيانات التصدير', $suppliers);
            }

        } catch (Exception $e) {
            error_log('Supplier export error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على مورد بالمعرف
     */
    private function getSupplierById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                s.*,
                u.full_name as created_by_name,
                (SELECT COUNT(*) FROM receipts WHERE supplier_id = s.id) as receipt_count,
                (SELECT COALESCE(SUM(total_cost), 0) FROM receipts WHERE supplier_id = s.id AND status = 'approved') as total_purchases
            FROM suppliers s
            LEFT JOIN users u ON u.id = s.created_by
            WHERE s.id = :id AND s.deleted_at IS NULL
        ", ['id' => $id]);
    }

    /**
     * توليد كود مورد تلقائي
     */
    private function generateSupplierCode(): string
    {
        $prefix = 'SUP';
        $year = date('Y');
        $month = date('m');
        
        $last = $this->db->queryValue("
            SELECT MAX(CAST(SUBSTRING(code, -4) AS UNSIGNED)) 
            FROM suppliers 
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
        header('Content-Disposition: attachment; filename="suppliers_' . date('Y-m-d') . '.csv"');
        
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
        header('Content-Disposition: attachment; filename="suppliers_' . date('Y-m-d') . '.xls"');
        
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
            'name' => 'اسم المورد',
            'contact_person' => 'شخص الاتصال',
            'phone' => 'الهاتف',
            'mobile' => 'الجوال',
            'email' => 'البريد الإلكتروني',
            'website' => 'الموقع الإلكتروني',
            'address' => 'العنوان',
            'city' => 'المدينة',
            'country' => 'الدولة',
            'tax_number' => 'الرقم الضريبي',
            'commercial_register' => 'السجل التجاري',
            'payment_terms' => 'شروط الدفع',
            'credit_limit' => 'الحد الائتماني',
            'is_active' => 'نشط',
            'rating' => 'التقييم',
            'created_at' => 'تاريخ الإنشاء'
        ];
        return $map[$header] ?? $header;
    }

    /**
     * التحقق من صحة بيانات المورد
     */
    private function validateSupplierData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['name'])) {
            errorResponse('اسم المورد مطلوب');
            return;
        }
        
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            errorResponse('البريد الإلكتروني غير صحيح');
            return;
        }
        
        if (!empty($data['phone']) && !preg_match('/^[0-9+\-\s()]{7,20}$/', $data['phone'])) {
            errorResponse('رقم الهاتف غير صحيح');
            return;
        }
        
        if (!empty($data['mobile']) && !preg_match('/^[0-9+\-\s()]{7,20}$/', $data['mobile'])) {
            errorResponse('رقم الجوال غير صحيح');
            return;
        }
        
        if (isset($data['rating']) && ($data['rating'] < 1 || $data['rating'] > 5)) {
            errorResponse('التقييم يجب أن يكون بين 1 و 5');
            return;
        }
        
        if (isset($data['credit_limit']) && $data['credit_limit'] < 0) {
            errorResponse('الحد الائتماني لا يمكن أن يكون سالباً');
            return;
        }
    }
}

// ================================================================
// انتهى الملف
// ================================================================

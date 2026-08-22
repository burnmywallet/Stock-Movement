<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/CategoryController.php
// الوصف: متحكم إدارة التصنيفات - CRUD كامل مع هيكل شجري
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Exception;

class CategoryController
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
     * GET /api/categories
     * جلب قائمة التصنيفات مع هيكل شجري
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
            if (!$this->auth->hasPermission($userId, 'categories.view')) {
                errorResponse('ليس لديك صلاحية لعرض التصنيفات', 403);
                return;
            }

            $view = $_GET['view'] ?? 'list'; // list, tree, cards
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            $sort = $_GET['sort'] ?? 'sort_order';
            $order = $_GET['order'] ?? 'ASC';

            $params = [];
            $where = ["c.deleted_at IS NULL"];
            
            if (!empty($search)) {
                $where[] = "(c.name LIKE :search OR c.code LIKE :search)";
                $params['search'] = "%{$search}%";
            }
            
            if ($status === 'active') {
                $where[] = "c.is_active = 1";
            } elseif ($status === 'inactive') {
                $where[] = "c.is_active = 0";
            }

            $allowedSorts = ['id', 'code', 'name', 'sort_order', 'created_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'sort_order';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب التصنيفات
            $categories = $this->db->query("
                SELECT 
                    c.id,
                    c.code,
                    c.name,
                    c.description,
                    c.parent_id,
                    c.icon,
                    c.color,
                    c.is_active,
                    c.sort_order,
                    c.created_at,
                    c.updated_at,
                    p.name as parent_name,
                    (SELECT COUNT(*) FROM categories WHERE parent_id = c.id AND deleted_at IS NULL) as sub_count,
                    (SELECT COUNT(*) FROM products WHERE category_id = c.id AND deleted_at IS NULL) as products_count,
                    (SELECT COUNT(*) FROM products WHERE category_id = c.id AND deleted_at IS NULL AND is_active = 1) as active_products_count
                FROM categories c
                LEFT JOIN categories p ON p.id = c.parent_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.{$sort} {$order}
            ", $params);

            // بناء الهيكل الشجري
            $tree = [];
            if ($view === 'tree' || $view === 'hierarchical') {
                $tree = $this->buildCategoryTree($categories);
            }

            // إحصائيات إضافية
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive,
                    COUNT(CASE WHEN parent_id IS NULL THEN 1 END) as root,
                    COUNT(CASE WHEN parent_id IS NOT NULL THEN 1 END) as sub,
                    COUNT(DISTINCT parent_id) as parents_count
                FROM categories
                WHERE deleted_at IS NULL
            ");

            // تحويل البيانات للبطاقات
            $cards = [];
            if ($view === 'cards') {
                foreach ($categories as $c) {
                    $cards[] = [
                        'id' => $c['id'],
                        'name' => $c['name'],
                        'code' => $c['code'],
                        'description' => $c['description'],
                        'icon' => $c['icon'],
                        'color' => $c['color'],
                        'is_active' => $c['is_active'],
                        'products_count' => $c['products_count'],
                        'sub_count' => $c['sub_count']
                    ];
                }
            }

            successResponse('تم جلب قائمة التصنيفات', [
                'data' => $categories,
                'tree' => $tree,
                'cards' => $cards,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'active' => (int)($stats['active'] ?? 0),
                    'inactive' => (int)($stats['inactive'] ?? 0),
                    'root' => (int)($stats['root'] ?? 0),
                    'sub' => (int)($stats['sub'] ?? 0),
                    'parents_count' => (int)($stats['parents_count'] ?? 0)
                ]
            ]);

        } catch (Exception $e) {
            error_log('Categories list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/categories/{id}
     * جلب بيانات تصنيف مع تفاصيل كاملة
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
            if (!$this->auth->hasPermission($userId, 'categories.view')) {
                errorResponse('ليس لديك صلاحية لعرض التصنيفات', 403);
                return;
            }

            $category = $this->getCategoryById($id);
            
            if (!$category) {
                errorResponse('التصنيف غير موجود');
                return;
            }

            // جلب التصنيفات الفرعية
            $subCategories = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    description,
                    icon,
                    color,
                    is_active,
                    sort_order,
                    (SELECT COUNT(*) FROM products WHERE category_id = c.id AND deleted_at IS NULL) as products_count
                FROM categories c
                WHERE parent_id = :parent_id AND deleted_at IS NULL
                ORDER BY sort_order, name
            ", ['parent_id' => $id]);

            // جلب المنتجات في هذا التصنيف
            $products = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    barcode,
                    is_active,
                    cost_price,
                    selling_price,
                    created_at
                FROM products
                WHERE category_id = :category_id AND deleted_at IS NULL
                ORDER BY name
                LIMIT 50
            ", ['category_id' => $id]);

            // جلب سجل التدقيق للتصنيف
            $audits = $this->db->query("
                SELECT 
                    created_at,
                    user_id,
                    (SELECT full_name FROM users WHERE id = user_id) as user_name,
                    action,
                    description
                FROM audit_logs
                WHERE reference_type = 'category'
                  AND reference_id = :reference_id
                ORDER BY created_at DESC
                LIMIT 20
            ", ['reference_id' => $id]);

            successResponse('تم جلب بيانات التصنيف', [
                'category' => $category,
                'sub_categories' => $subCategories,
                'products' => $products,
                'audits' => $audits,
                'stats' => [
                    'sub_count' => count($subCategories),
                    'products_count' => count($products),
                    'total_products' => $category['products_count']
                ]
            ]);

        } catch (Exception $e) {
            error_log('Category show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/categories
     * إنشاء تصنيف جديد
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
            if (!$this->auth->hasPermission($userId, 'categories.create')) {
                errorResponse('ليس لديك صلاحية لإنشاء تصنيفات', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateCategoryData($input);

            // التحقق من الكود
            if (!empty($input['code'])) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM categories WHERE code = :code",
                    ['code' => $input['code']]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            } else {
                // توليد كود تلقائي
                $input['code'] = $this->generateCategoryCode($input['name']);
            }

            // التحقق من وجود التصنيف الرئيسي
            if (isset($input['parent_id']) && $input['parent_id'] > 0) {
                $parent = $this->db->queryValue(
                    "SELECT id FROM categories WHERE id = :id AND deleted_at IS NULL",
                    ['id' => $input['parent_id']]
                );
                
                if (!$parent) {
                    errorResponse('التصنيف الرئيسي غير موجود');
                    return;
                }
            }

            // إنشاء التصنيف
            $data = [
                'code' => $input['code'],
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'parent_id' => $input['parent_id'] ?? null,
                'icon' => $input['icon'] ?? null,
                'color' => $input['color'] ?? null,
                'is_active' => $input['is_active'] ?? 1,
                'sort_order' => $input['sort_order'] ?? 0,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $categoryId = $this->db->insert('categories', $data);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'CATEGORY_CREATED',
                'categories',
                "إنشاء تصنيف جديد: {$input['name']}",
                ['category_id' => $categoryId, 'code' => $input['code']],
                'category',
                $categoryId
            );

            successResponse('تم إنشاء التصنيف بنجاح', ['category_id' => $categoryId]);

        } catch (Exception $e) {
            error_log('Category create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/categories/{id}
     * تحديث بيانات تصنيف
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
            if (!$this->auth->hasPermission($userId, 'categories.edit')) {
                errorResponse('ليس لديك صلاحية لتحديث التصنيفات', 403);
                return;
            }

            $category = $this->getCategoryById($id);
            if (!$category) {
                errorResponse('التصنيف غير موجود');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateCategoryData($input, true);

            // التحقق من الكود
            if (!empty($input['code']) && $input['code'] !== $category['code']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM categories WHERE code = :code AND id != :id",
                    ['code' => $input['code'], 'id' => $id]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            }

            // التحقق من وجود التصنيف الرئيسي
            if (isset($input['parent_id']) && $input['parent_id'] > 0) {
                // منع جعل التصنيف رئيساً لنفسه
                if ($input['parent_id'] == $id) {
                    errorResponse('لا يمكن جعل التصنيف رئيساً لنفسه');
                    return;
                }
                
                $parent = $this->db->queryValue(
                    "SELECT id FROM categories WHERE id = :id AND deleted_at IS NULL",
                    ['id' => $input['parent_id']]
                );
                
                if (!$parent) {
                    errorResponse('التصنيف الرئيسي غير موجود');
                    return;
                }
            }

            // تحديث البيانات
            $data = [
                'code' => $input['code'] ?? $category['code'],
                'name' => $input['name'] ?? $category['name'],
                'description' => $input['description'] ?? $category['description'],
                'parent_id' => $input['parent_id'] ?? $category['parent_id'],
                'icon' => $input['icon'] ?? $category['icon'],
                'color' => $input['color'] ?? $category['color'],
                'is_active' => $input['is_active'] ?? $category['is_active'],
                'sort_order' => $input['sort_order'] ?? $category['sort_order'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('categories', $data, ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'CATEGORY_UPDATED',
                'categories',
                "تحديث بيانات التصنيف: {$category['name']}",
                ['category_id' => $id, 'code' => $category['code']],
                'category',
                $id
            );

            successResponse('تم تحديث بيانات التصنيف بنجاح');

        } catch (Exception $e) {
            error_log('Category update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/categories/{id}
     * حذف تصنيف (حذف ناعم)
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
            if (!$this->auth->hasPermission($userId, 'categories.delete')) {
                errorResponse('ليس لديك صلاحية لحذف التصنيفات', 403);
                return;
            }

            $category = $this->getCategoryById($id);
            if (!$category) {
                errorResponse('التصنيف غير موجود');
                return;
            }

            // التحقق من وجود تصنيفات فرعية
            $subCount = $this->db->queryValue(
                "SELECT COUNT(*) FROM categories WHERE parent_id = :parent_id AND deleted_at IS NULL",
                ['parent_id' => $id]
            );

            if ($subCount > 0) {
                errorResponse('لا يمكن حذف التصنيف لأنه يحتوي على تصنيفات فرعية');
                return;
            }

            // التحقق من وجود منتجات في التصنيف
            $productsCount = $this->db->queryValue(
                "SELECT COUNT(*) FROM products WHERE category_id = :category_id AND deleted_at IS NULL",
                ['category_id' => $id]
            );

            if ($productsCount > 0) {
                errorResponse('لا يمكن حذف التصنيف لأنه يحتوي على منتجات');
                return;
            }

            // الحذف الناعم
            $this->db->softDelete('categories', ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'CATEGORY_DELETED',
                'categories',
                "حذف التصنيف: {$category['name']}",
                ['category_id' => $id, 'code' => $category['code']],
                'category',
                $id
            );

            successResponse('تم حذف التصنيف بنجاح');

        } catch (Exception $e) {
            error_log('Category delete error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/categories/tree
     * جلب هيكل شجري كامل للتصنيفات
     */
    public function tree(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية العرض
            if (!$this->auth->hasPermission($userId, 'categories.view')) {
                errorResponse('ليس لديك صلاحية لعرض التصنيفات', 403);
                return;
            }

            $categories = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    description,
                    parent_id,
                    icon,
                    color,
                    is_active,
                    sort_order,
                    (SELECT COUNT(*) FROM products WHERE category_id = c.id AND deleted_at IS NULL) as products_count
                FROM categories c
                WHERE deleted_at IS NULL AND is_active = 1
                ORDER BY sort_order, name
            ");

            $tree = $this->buildCategoryTree($categories);

            successResponse('تم جلب هيكل التصنيفات', $tree);

        } catch (Exception $e) {
            error_log('Category tree error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/categories/export
     * تصدير التصنيفات
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
            if (!$this->auth->hasPermission($userId, 'categories.export')) {
                errorResponse('ليس لديك صلاحية لتصدير التصنيفات', 403);
                return;
            }

            $format = $_GET['format'] ?? 'csv';

            $categories = $this->db->query("
                SELECT 
                    code,
                    name,
                    description,
                    parent_id,
                    icon,
                    color,
                    is_active,
                    sort_order,
                    (SELECT name FROM categories WHERE id = c.parent_id) as parent_name,
                    (SELECT COUNT(*) FROM products WHERE category_id = c.id AND deleted_at IS NULL) as products_count,
                    created_at
                FROM categories c
                WHERE deleted_at IS NULL
                ORDER BY sort_order, name
            ");

            if ($format === 'csv') {
                $this->exportCSV($categories);
            } elseif ($format === 'excel') {
                $this->exportExcel($categories);
            } else {
                successResponse('تم جلب بيانات التصدير', $categories);
            }

        } catch (Exception $e) {
            error_log('Category export error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على تصنيف بالمعرف
     */
    private function getCategoryById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                c.*,
                p.name as parent_name,
                (SELECT COUNT(*) FROM categories WHERE parent_id = c.id AND deleted_at IS NULL) as sub_count,
                (SELECT COUNT(*) FROM products WHERE category_id = c.id AND deleted_at IS NULL) as products_count
            FROM categories c
            LEFT JOIN categories p ON p.id = c.parent_id
            WHERE c.id = :id AND c.deleted_at IS NULL
        ", ['id' => $id]);
    }

    /**
     * توليد كود تصنيف تلقائي
     */
    private function generateCategoryCode(string $name): string
    {
        // أخذ أول 3 أحرف من الاسم
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 3));
        
        // إذا كان الكود فارغاً، استخدم 'CAT'
        if (empty($code)) {
            $code = 'CAT';
        }
        
        // التحقق من عدم وجود الكود
        $exists = $this->db->queryValue(
            "SELECT id FROM categories WHERE code = :code",
            ['code' => $code]
        );
        
        if ($exists) {
            // إضافة رقم عشوائي
            $code .= str_pad(rand(1, 99), 2, '0', STR_PAD_LEFT);
            
            // التحقق مرة أخرى
            $exists = $this->db->queryValue(
                "SELECT id FROM categories WHERE code = :code",
                ['code' => $code]
            );
            
            if ($exists) {
                $code .= rand(100, 999);
            }
        }
        
        return $code;
    }

    /**
     * بناء هيكل شجري للتصنيفات
     */
    private function buildCategoryTree(array $categories): array
    {
        $tree = [];
        $map = [];
        
        // ترتيب التصنيفات في مصفوفة مساعدة
        foreach ($categories as $category) {
            $map[$category['id']] = $category;
            $map[$category['id']]['children'] = [];
        }
        
        // بناء الهيكل الشجري
        foreach ($map as $id => $category) {
            if ($category['parent_id'] && isset($map[$category['parent_id']])) {
                $map[$category['parent_id']]['children'][] = &$map[$id];
            } else {
                $tree[] = &$map[$id];
            }
        }
        
        return $tree;
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
        header('Content-Disposition: attachment; filename="categories_' . date('Y-m-d') . '.csv"');
        
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
        header('Content-Disposition: attachment; filename="categories_' . date('Y-m-d') . '.xls"');
        
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
            'name' => 'اسم التصنيف',
            'description' => 'الوصف',
            'parent_id' => 'معرف الأب',
            'parent_name' => 'التصنيف الأب',
            'icon' => 'الأيقونة',
            'color' => 'اللون',
            'is_active' => 'نشط',
            'sort_order' => 'ترتيب',
            'products_count' => 'عدد المنتجات',
            'created_at' => 'تاريخ الإنشاء'
        ];
        return $map[$header] ?? $header;
    }

    /**
     * التحقق من صحة بيانات التصنيف
     */
    private function validateCategoryData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['name'])) {
            errorResponse('اسم التصنيف مطلوب');
            return;
        }
        
        if (!empty($data['color']) && !preg_match('/^#[a-fA-F0-9]{6}$/', $data['color'])) {
            errorResponse('لون التصنيف غير صحيح (يجب أن يكون بتنسيق HEX مثل #FF0000)');
            return;
        }
        
        if (isset($data['sort_order']) && $data['sort_order'] < 0) {
            errorResponse('ترتيب التصنيف لا يمكن أن يكون سالباً');
            return;
        }
    }
}

// ================================================================
// انتهى الملف
// ================================================================

<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/UnitController.php
// الوصف: متحكم إدارة الوحدات - CRUD كامل مع دعم التحويل بين الوحدات
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Exception;

class UnitController
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
     * GET /api/units
     * جلب قائمة الوحدات مع فلترة وبحث
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
            if (!$this->auth->hasPermission($userId, 'units.view')) {
                errorResponse('ليس لديك صلاحية لعرض الوحدات', 403);
                return;
            }

            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            $isBaseUnit = $_GET['is_base_unit'] ?? '';
            $sort = $_GET['sort'] ?? 'name';
            $order = $_GET['order'] ?? 'ASC';

            $params = [];
            $where = ["u.deleted_at IS NULL"];
            
            if (!empty($search)) {
                $where[] = "(u.name LIKE :search OR u.code LIKE :search OR u.symbol LIKE :search)";
                $params['search'] = "%{$search}%";
            }
            
            if ($status === 'active') {
                $where[] = "u.is_active = 1";
            } elseif ($status === 'inactive') {
                $where[] = "u.is_active = 0";
            }
            
            if ($isBaseUnit === '1') {
                $where[] = "u.is_base_unit = 1";
            } elseif ($isBaseUnit === '0') {
                $where[] = "u.is_base_unit = 0";
            }

            $allowedSorts = ['id', 'code', 'name', 'symbol', 'is_base_unit', 'created_at'];
            $sort = in_array($sort, $allowedSorts) ? $sort : 'name';
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

            // جلب الوحدات
            $units = $this->db->query("
                SELECT 
                    u.id,
                    u.code,
                    u.name,
                    u.name_plural,
                    u.symbol,
                    u.is_base_unit,
                    u.conversion_factor,
                    u.base_unit_id,
                    u.precision_digits,
                    u.is_active,
                    u.created_at,
                    u.updated_at,
                    bu.name as base_unit_name,
                    bu.symbol as base_unit_symbol,
                    (SELECT COUNT(*) FROM products WHERE unit_id = u.id AND deleted_at IS NULL) as products_count,
                    (SELECT COUNT(*) FROM products WHERE purchase_unit_id = u.id AND deleted_at IS NULL) as purchase_products_count,
                    (SELECT COUNT(*) FROM products WHERE sale_unit_id = u.id AND deleted_at IS NULL) as sale_products_count
                FROM units u
                LEFT JOIN units bu ON bu.id = u.base_unit_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY u.{$sort} {$order}
                LIMIT :limit OFFSET :offset
            ", array_merge($params, ['limit' => $limit, 'offset' => $offset]));

            // إجمالي الوحدات
            $total = $this->db->queryValue("
                SELECT COUNT(*) FROM units u
                WHERE " . implode(' AND ', $where) . "
            ", $params);

            // إحصائيات إضافية
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                    COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive,
                    COUNT(CASE WHEN is_base_unit = 1 THEN 1 END) as base_units,
                    COUNT(CASE WHEN is_base_unit = 0 THEN 1 END) as derived_units,
                    COUNT(DISTINCT base_unit_id) as base_unit_groups
                FROM units
                WHERE deleted_at IS NULL
            ");

            successResponse('تم جلب قائمة الوحدات', [
                'data' => $units,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'active' => (int)($stats['active'] ?? 0),
                    'inactive' => (int)($stats['inactive'] ?? 0),
                    'base_units' => (int)($stats['base_units'] ?? 0),
                    'derived_units' => (int)($stats['derived_units'] ?? 0),
                    'base_unit_groups' => (int)($stats['base_unit_groups'] ?? 0)
                ],
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil((int)$total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            error_log('Units list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/units/{id}
     * جلب بيانات وحدة مع تفاصيل كاملة
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
            if (!$this->auth->hasPermission($userId, 'units.view')) {
                errorResponse('ليس لديك صلاحية لعرض الوحدات', 403);
                return;
            }

            $unit = $this->getUnitById($id);
            
            if (!$unit) {
                errorResponse('الوحدة غير موجودة');
                return;
            }

            // جلب الوحدات المشتقة (التي تعتمد على هذه الوحدة)
            $derivedUnits = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    symbol,
                    conversion_factor,
                    precision_digits,
                    is_active
                FROM units
                WHERE base_unit_id = :unit_id AND deleted_at IS NULL
                ORDER BY name
            ", ['unit_id' => $id]);

            // جلب المنتجات التي تستخدم هذه الوحدة
            $products = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    is_active
                FROM products
                WHERE unit_id = :unit_id AND deleted_at IS NULL
                LIMIT 20
            ", ['unit_id' => $id]);

            // جلب المنتجات التي تستخدم هذه الوحدة كوحدة شراء
            $purchaseProducts = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    is_active
                FROM products
                WHERE purchase_unit_id = :unit_id AND deleted_at IS NULL
                LIMIT 20
            ", ['unit_id' => $id]);

            // جلب المنتجات التي تستخدم هذه الوحدة كوحدة بيع
            $saleProducts = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    is_active
                FROM products
                WHERE sale_unit_id = :unit_id AND deleted_at IS NULL
                LIMIT 20
            ", ['unit_id' => $id]);

            // سجل التدقيق
            $audits = $this->db->query("
                SELECT 
                    created_at,
                    user_id,
                    (SELECT full_name FROM users WHERE id = user_id) as user_name,
                    action,
                    description
                FROM audit_logs
                WHERE reference_type = 'unit'
                  AND reference_id = :reference_id
                ORDER BY created_at DESC
                LIMIT 20
            ", ['reference_id' => $id]);

            successResponse('تم جلب بيانات الوحدة', [
                'unit' => $unit,
                'derived_units' => $derivedUnits,
                'products' => $products,
                'purchase_products' => $purchaseProducts,
                'sale_products' => $saleProducts,
                'audits' => $audits
            ]);

        } catch (Exception $e) {
            error_log('Unit show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/units
     * إنشاء وحدة جديدة
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
            if (!$this->auth->hasPermission($userId, 'units.create')) {
                errorResponse('ليس لديك صلاحية لإنشاء وحدات', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateUnitData($input);

            // التحقق من الكود
            if (!empty($input['code'])) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM units WHERE code = :code",
                    ['code' => $input['code']]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            } else {
                // توليد كود تلقائي
                $input['code'] = $this->generateUnitCode($input['name']);
            }

            // التحقق من الوحدة الأساسية
            if ($input['is_base_unit'] ?? false) {
                // إذا كانت الوحدة أساسية، لا يمكن أن يكون لها base_unit_id
                $input['base_unit_id'] = null;
                $input['conversion_factor'] = 1;
            } else {
                // إذا كانت وحدة مشتقة، يجب تحديد الوحدة الأساسية
                if (empty($input['base_unit_id'])) {
                    errorResponse('الوحدة المشتقة يجب أن تحدد وحدة أساسية');
                    return;
                }
                
                // التحقق من وجود الوحدة الأساسية
                $baseUnit = $this->db->queryValue(
                    "SELECT id FROM units WHERE id = :id AND is_base_unit = 1 AND deleted_at IS NULL",
                    ['id' => $input['base_unit_id']]
                );
                
                if (!$baseUnit) {
                    errorResponse('الوحدة الأساسية غير موجودة أو ليست وحدة أساسية');
                    return;
                }
                
                if (empty($input['conversion_factor']) || $input['conversion_factor'] <= 0) {
                    errorResponse('معامل التحويل مطلوب للوحدات المشتقة');
                    return;
                }
            }

            // إنشاء الوحدة
            $data = [
                'code' => $input['code'],
                'name' => $input['name'],
                'name_plural' => $input['name_plural'] ?? null,
                'symbol' => $input['symbol'] ?? null,
                'is_base_unit' => $input['is_base_unit'] ?? 0,
                'conversion_factor' => $input['conversion_factor'] ?? 1,
                'base_unit_id' => $input['base_unit_id'] ?? null,
                'precision_digits' => $input['precision_digits'] ?? 2,
                'is_active' => $input['is_active'] ?? 1,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $unitId = $this->db->insert('units', $data);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'UNIT_CREATED',
                'units',
                "إنشاء وحدة جديدة: {$input['name']}",
                ['unit_id' => $unitId, 'code' => $input['code']],
                'unit',
                $unitId
            );

            successResponse('تم إنشاء الوحدة بنجاح', ['unit_id' => $unitId]);

        } catch (Exception $e) {
            error_log('Unit create error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/units/{id}
     * تحديث بيانات وحدة
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
            if (!$this->auth->hasPermission($userId, 'units.edit')) {
                errorResponse('ليس لديك صلاحية لتحديث الوحدات', 403);
                return;
            }

            $unit = $this->getUnitById($id);
            if (!$unit) {
                errorResponse('الوحدة غير موجودة');
                return;
            }

            // منع تغيير الوحدات المستخدمة في المنتجات
            $productsCount = $this->db->queryValue(
                "SELECT COUNT(*) FROM products WHERE unit_id = :unit_id AND deleted_at IS NULL",
                ['unit_id' => $id]
            );
            
            if ($productsCount > 0) {
                errorResponse('لا يمكن تعديل وحدة مستخدمة في منتجات');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            // التحقق من البيانات
            $this->validateUnitData($input, true);

            // التحقق من الكود
            if (!empty($input['code']) && $input['code'] !== $unit['code']) {
                $exists = $this->db->queryValue(
                    "SELECT id FROM units WHERE code = :code AND id != :id",
                    ['code' => $input['code'], 'id' => $id]
                );
                
                if ($exists) {
                    errorResponse('الكود مستخدم بالفعل');
                    return;
                }
            }

            // إذا كانت الوحدة أساسية، لا يمكن تغييرها إلى مشتقة
            if ($unit['is_base_unit'] && isset($input['is_base_unit']) && !$input['is_base_unit']) {
                // التحقق من وجود وحدات مشتقة تعتمد عليها
                $derivedCount = $this->db->queryValue(
                    "SELECT COUNT(*) FROM units WHERE base_unit_id = :unit_id AND deleted_at IS NULL",
                    ['unit_id' => $id]
                );
                
                if ($derivedCount > 0) {
                    errorResponse('لا يمكن تغيير وحدة أساسية إلى مشتقة لأن هناك وحدات تعتمد عليها');
                    return;
                }
            }

            // إذا كانت وحدة مشتقة وتريد تغيير الوحدة الأساسية
            if (!$unit['is_base_unit'] && isset($input['base_unit_id']) && $input['base_unit_id'] != $unit['base_unit_id']) {
                $baseUnit = $this->db->queryValue(
                    "SELECT id FROM units WHERE id = :id AND is_base_unit = 1 AND deleted_at IS NULL",
                    ['id' => $input['base_unit_id']]
                );
                
                if (!$baseUnit) {
                    errorResponse('الوحدة الأساسية غير موجودة أو ليست وحدة أساسية');
                    return;
                }
            }

            // تحديث البيانات
            $data = [
                'code' => $input['code'] ?? $unit['code'],
                'name' => $input['name'] ?? $unit['name'],
                'name_plural' => $input['name_plural'] ?? $unit['name_plural'],
                'symbol' => $input['symbol'] ?? $unit['symbol'],
                'is_base_unit' => $input['is_base_unit'] ?? $unit['is_base_unit'],
                'conversion_factor' => $input['conversion_factor'] ?? $unit['conversion_factor'],
                'base_unit_id' => $input['base_unit_id'] ?? $unit['base_unit_id'],
                'precision_digits' => $input['precision_digits'] ?? $unit['precision_digits'],
                'is_active' => $input['is_active'] ?? $unit['is_active'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // إذا أصبحت الوحدة أساسية، إزالة base_unit_id وتعيين معامل التحويل إلى 1
            if ($data['is_base_unit']) {
                $data['base_unit_id'] = null;
                $data['conversion_factor'] = 1;
            }

            $this->db->update('units', $data, ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'UNIT_UPDATED',
                'units',
                "تحديث بيانات الوحدة: {$unit['name']}",
                ['unit_id' => $id, 'code' => $unit['code']],
                'unit',
                $id
            );

            successResponse('تم تحديث بيانات الوحدة بنجاح');

        } catch (Exception $e) {
            error_log('Unit update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/units/{id}
     * حذف وحدة (حذف ناعم)
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
            if (!$this->auth->hasPermission($userId, 'units.delete')) {
                errorResponse('ليس لديك صلاحية لحذف الوحدات', 403);
                return;
            }

            $unit = $this->getUnitById($id);
            if (!$unit) {
                errorResponse('الوحدة غير موجودة');
                return;
            }

            // التحقق من استخدام الوحدة في المنتجات
            $productsCount = $this->db->queryValue(
                "SELECT COUNT(*) FROM products WHERE unit_id = :unit_id AND deleted_at IS NULL",
                ['unit_id' => $id]
            );
            
            if ($productsCount > 0) {
                errorResponse('لا يمكن حذف الوحدة لأنها مستخدمة في منتجات');
                return;
            }

            // التحقق من وجود وحدات مشتقة تعتمد عليها
            if ($unit['is_base_unit']) {
                $derivedCount = $this->db->queryValue(
                    "SELECT COUNT(*) FROM units WHERE base_unit_id = :unit_id AND deleted_at IS NULL",
                    ['unit_id' => $id]
                );
                
                if ($derivedCount > 0) {
                    errorResponse('لا يمكن حذف الوحدة الأساسية لأن هناك وحدات تعتمد عليها');
                    return;
                }
            }

            // الحذف الناعم
            $this->db->softDelete('units', ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'UNIT_DELETED',
                'units',
                "حذف الوحدة: {$unit['name']}",
                ['unit_id' => $id, 'code' => $unit['code']],
                'unit',
                $id
            );

            successResponse('تم حذف الوحدة بنجاح');

        } catch (Exception $e) {
            error_log('Unit delete error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/units/convert
     * تحويل بين وحدتين
     */
    public function convert(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $fromUnitId = (int)($_GET['from_unit'] ?? 0);
            $toUnitId = (int)($_GET['to_unit'] ?? 0);
            $quantity = (float)($_GET['quantity'] ?? 1);

            if (!$fromUnitId || !$toUnitId) {
                errorResponse('الوحدة المصدر والوحدة الهدف مطلوبتين');
                return;
            }

            $fromUnit = $this->getUnitById($fromUnitId);
            $toUnit = $this->getUnitById($toUnitId);

            if (!$fromUnit || !$toUnit) {
                errorResponse('إحدى الوحدات غير موجودة');
                return;
            }

            // حساب معامل التحويل
            $conversionFactor = $this->calculateConversionFactor($fromUnit, $toUnit);
            
            $result = $quantity * $conversionFactor;

            successResponse('تم حساب التحويل', [
                'from' => [
                    'id' => $fromUnit['id'],
                    'name' => $fromUnit['name'],
                    'symbol' => $fromUnit['symbol']
                ],
                'to' => [
                    'id' => $toUnit['id'],
                    'name' => $toUnit['name'],
                    'symbol' => $toUnit['symbol']
                ],
                'quantity' => $quantity,
                'result' => $result,
                'conversion_factor' => $conversionFactor,
                'formula' => "{$quantity} {$fromUnit['symbol']} = {$result} {$toUnit['symbol']}"
            ]);

        } catch (Exception $e) {
            error_log('Unit convert error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/units/base
     * جلب الوحدات الأساسية
     */
    public function base(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $units = $this->db->query("
                SELECT 
                    id,
                    code,
                    name,
                    symbol,
                    precision_digits,
                    is_active
                FROM units
                WHERE is_base_unit = 1 AND deleted_at IS NULL AND is_active = 1
                ORDER BY name
            ");

            successResponse('تم جلب الوحدات الأساسية', $units);

        } catch (Exception $e) {
            error_log('Base units error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على وحدة بالمعرف
     */
    private function getUnitById(int $id): ?array
    {
        return $this->db->queryOne("
            SELECT 
                u.*,
                bu.name as base_unit_name,
                bu.symbol as base_unit_symbol
            FROM units u
            LEFT JOIN units bu ON bu.id = u.base_unit_id
            WHERE u.id = :id AND u.deleted_at IS NULL
        ", ['id' => $id]);
    }

    /**
     * توليد كود وحدة تلقائي
     */
    private function generateUnitCode(string $name): string
    {
        // أخذ أول حرفين من الاسم
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 2));
        
        // إذا كان الكود فارغاً، استخدم 'UN'
        if (empty($code)) {
            $code = 'UN';
        }
        
        // التحقق من عدم وجود الكود
        $exists = $this->db->queryValue(
            "SELECT id FROM units WHERE code = :code",
            ['code' => $code]
        );
        
        if ($exists) {
            // إضافة رقم عشوائي
            $code .= str_pad(rand(1, 99), 2, '0', STR_PAD_LEFT);
            
            // التحقق مرة أخرى
            $exists = $this->db->queryValue(
                "SELECT id FROM units WHERE code = :code",
                ['code' => $code]
            );
            
            if ($exists) {
                $code .= rand(100, 999);
            }
        }
        
        return $code;
    }

    /**
     * حساب معامل التحويل بين وحدتين
     */
    private function calculateConversionFactor(array $fromUnit, array $toUnit): float
    {
        // إذا كانت نفس الوحدة
        if ($fromUnit['id'] == $toUnit['id']) {
            return 1;
        }

        // إذا كانت الوحدة المصدر أساسية والهدف مشتقة
        if ($fromUnit['is_base_unit'] && !$toUnit['is_base_unit']) {
            return 1 / $toUnit['conversion_factor'];
        }

        // إذا كانت الوحدة المصدر مشتقة والهدف أساسية
        if (!$fromUnit['is_base_unit'] && $toUnit['is_base_unit']) {
            return $fromUnit['conversion_factor'];
        }

        // إذا كانت كلتا الوحدتين مشتقتين ونفس الأساس
        if (!$fromUnit['is_base_unit'] && !$toUnit['is_base_unit']) {
            if ($fromUnit['base_unit_id'] == $toUnit['base_unit_id']) {
                return $fromUnit['conversion_factor'] / $toUnit['conversion_factor'];
            }
            
            // أساس مختلف - نحتاج إلى التحويل عبر الوحدة الأساسية
            $fromBaseFactor = $fromUnit['conversion_factor'];
            $toBaseFactor = $toUnit['conversion_factor'];
            
            // جلب الوحدات الأساسية
            $fromBase = $this->getUnitById($fromUnit['base_unit_id']);
            $toBase = $this->getUnitById($toUnit['base_unit_id']);
            
            if (!$fromBase || !$toBase) {
                throw new Exception('لا يمكن تحويل الوحدات من مجموعات مختلفة');
            }
            
            // تحويل إلى الوحدة الأساسية ثم إلى الوحدة الهدف
            return ($fromBaseFactor / $toBaseFactor);
        }

        // حالة غير متوقعة
        throw new Exception('لا يمكن حساب معامل التحويل');
    }

    /**
     * التحقق من صحة بيانات الوحدة
     */
    private function validateUnitData(array $data, bool $isUpdate = false): void
    {
        if (empty($data['name'])) {
            errorResponse('اسم الوحدة مطلوب');
            return;
        }
        
        if (!empty($data['symbol']) && strlen($data['symbol']) > 10) {
            errorResponse('رمز الوحدة لا يمكن أن يتجاوز 10 أحرف');
            return;
        }
        
        if (isset($data['is_base_unit']) && !$data['is_base_unit']) {
            if (empty($data['base_unit_id'])) {
                errorResponse('الوحدة المشتقة يجب أن تحدد وحدة أساسية');
                return;
            }
            
            if (empty($data['conversion_factor']) || $data['conversion_factor'] <= 0) {
                errorResponse('معامل التحويل يجب أن يكون أكبر من صفر');
                return;
            }
        }
        
        if (isset($data['precision_digits']) && ($data['precision_digits'] < 0 || $data['precision_digits'] > 6)) {
            errorResponse('عدد الأرقام العشرية يجب أن يكون بين 0 و 6');
            return;
        }
    }
}

// ================================================================
// انتهى الملف
// ================================================================

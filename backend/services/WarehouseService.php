<?php

/**
 * ================================================================
 * Logistox - Warehouse Service
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/services/WarehouseService.php
 * الوظيفة: منطق الأعمال للمخازن (CRUD + تحقق + حسابات)
 *
 * المسؤوليات:
 * 1. إنشاء مخزن جديد مع التحقق من التفرد
 * 2. جلب مخزن واحد مع التفاصيل
 * 3. جلب قائمة المخازن مع الفلاتر
 * 4. تعديل مخزن موجود
 * 5. حذف مخزن (Soft Delete) مع التحقق من الارتباطات
 * 6. التحقق من وجود ارتباطات (أرصدة، حركات، مستندات، مستخدمين)
 * 7. جلب هيكل المخازن الهرمي (Parent-Child)
 * 8. حساب إحصائيات المخزن (عدد المنتجات، الكمية، القيمة)
 *
 * قيود الحماية:
 * - منع حذف مخزن له أرصدة مخزنية (stock_balances)
 * - منع حذف مخزن له حركات مخزنية (stock_movements)
 * - منع حذف مخزن له مستندات (receipts, issues, transfers)
 * - منع حذف مخزن رئيسي له فروع (parent_id)
 * - منع حذف مخزن مرتبط بمستخدم (manager_id)
 * - التحقق من تفرد code
 * - التحقق من وجود parent_id و manager_id
 *
 * ملاحظات هامة:
 * - يستخدم Soft Delete (deleted_at) بدلاً من الحذف الفعلي
 * - يدعم الهيكل الهرمي (Parent-Child) للمخازن
 * - يحسب الإحصائيات ديناميكياً من stock_balances
 * ================================================================
 */

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Throwable;
use Exception;

/**
 * Class WarehouseService
 *
 * خدمة إدارة المخازن
 */
class WarehouseService
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var array أنواع المخازن المسموحة
     */
    private const ALLOWED_TYPES = ['main', 'sub', 'cold', 'freezer'];

    /**
     * Constructor
     *
     * @param Database $db اتصال قاعدة البيانات
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // =========================================================================
    // 1. إنشاء مخزن جديد (Create)
    // =========================================================================

    /**
     * إنشاء مخزن جديد
     *
     * @param array $data بيانات المخزن
     * @param int $userId معرف المستخدم الذي ينشئ المخزن
     * @return int معرف المخزن الجديد
     * @throws Exception إذا فشل الإنشاء أو كانت البيانات غير صالحة
     */
    public function create(array $data, int $userId): int
    {
        // 1. التحقق من تفرد الكود
        $existing = $this->db->selectOne(
            "SELECT id FROM warehouses WHERE code = ? AND deleted_at IS NULL",
            [trim($data['code'])]
        );

        if ($existing) {
            throw new Exception('كود المخزن مستخدم بالفعل. يرجى استخدام كود مختلف.');
        }

        // 2. التحقق من وجود parent_id (إذا تم تقديمه)
        if (!empty($data['parent_id'])) {
            $parent = $this->db->selectOne(
                "SELECT id FROM warehouses WHERE id = ? AND deleted_at IS NULL",
                [(int) $data['parent_id']]
            );

            if (!$parent) {
                throw new Exception('المخزن الرئيسي غير موجود.');
            }

            // منع إنشاء حلقة دائرية (Circular Reference)
            if ($this->isDescendant((int) $data['parent_id'], (int) ($data['id'] ?? 0))) {
                throw new Exception('لا يمكن تعيين مخزن رئيسي يكون فرعاً لهذا المخزن.');
            }
        }

        // 3. التحقق من وجود manager_id (إذا تم تقديمه)
        if (!empty($data['manager_id'])) {
            $manager = $this->db->selectOne(
                "SELECT id FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['manager_id']]
            );

            if (!$manager) {
                throw new Exception('المدير المحدد غير موجود أو غير نشط.');
            }
        }

        // 4. بناء البيانات للإدراج
        $insertData = [
            'code'       => trim($data['code']),
            'name'       => trim($data['name']),
            'type'       => $data['type'] ?? 'main',
            'parent_id'  => !empty($data['parent_id']) ? (int) $data['parent_id'] : null,
            'location'   => trim($data['location'] ?? ''),
            'address'    => trim($data['address'] ?? ''),
            'manager_id' => !empty($data['manager_id']) ? (int) $data['manager_id'] : null,
            'capacity'   => !empty($data['capacity']) ? (float) $data['capacity'] : null,
            'is_active'  => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // 5. إدراج المخزن
        $warehouseId = $this->db->insert('warehouses', $insertData);

        return (int) $warehouseId;
    }

    // =========================================================================
    // 2. جلب مخزن واحد (Read)
    // =========================================================================

    /**
     * جلب مخزن واحد مع التفاصيل
     *
     * @param int $id معرف المخزن
     * @return array|null بيانات المخزن أو null إذا لم يوجد
     */
    public function getById(int $id): ?array
    {
        $sql = "
            SELECT
                w.id,
                w.code,
                w.name,
                w.type,
                w.parent_id,
                w.location,
                w.address,
                w.manager_id,
                w.capacity,
                w.is_active,
                w.created_at,
                w.updated_at,
                w.created_by,
                w.updated_by,
                parent.code AS parent_code,
                parent.name AS parent_name,
                manager.id AS manager_id_check,
                manager.full_name AS manager_name,
                creator.full_name AS creator_name,
                COUNT(DISTINCT sb.product_id) AS products_count,
                COALESCE(SUM(sb.quantity), 0) AS total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) AS total_value,
                (
                    SELECT COUNT(*)
                    FROM warehouses child
                    WHERE child.parent_id = w.id AND child.deleted_at IS NULL
                ) AS children_count
            FROM warehouses w
            LEFT JOIN warehouses parent ON w.parent_id = parent.id
            LEFT JOIN users manager ON w.manager_id = manager.id
            LEFT JOIN users creator ON w.created_by = creator.id
            LEFT JOIN stock_balances sb ON w.id = sb.warehouse_id
            LEFT JOIN products p ON sb.product_id = p.id AND p.deleted_at IS NULL
            WHERE w.id = ?
              AND w.deleted_at IS NULL
            GROUP BY w.id, w.code, w.name, w.type, w.parent_id, w.location, w.address,
                     w.manager_id, w.capacity, w.is_active, w.created_at, w.updated_at,
                     w.created_by, w.updated_by, parent.code, parent.name,
                     manager.id, manager.full_name, creator.full_name
        ";

        $warehouse = $this->db->selectOne($sql, [$id]);

        if (!$warehouse) {
            return null;
        }

        // تحويل القيم الرقمية
        $warehouse['products_count'] = (int) $warehouse['products_count'];
        $warehouse['total_quantity'] = (float) $warehouse['total_quantity'];
        $warehouse['total_value'] = (float) $warehouse['total_value'];
        $warehouse['children_count'] = (int) $warehouse['children_count'];
        $warehouse['capacity'] = $warehouse['capacity'] !== null ? (float) $warehouse['capacity'] : null;

        return $warehouse;
    }

    // =========================================================================
    // 3. جلب قائمة المخازن (List)
    // =========================================================================

    /**
     * جلب قائمة المخازن مع الفلاتر
     *
     * @param array $filters الفلاتر (search, type, is_active, parent_id, sort_by, sort_order)
     * @return array قائمة المخازن
     */
    public function list(array $filters = []): array
    {
        $sql = "
            SELECT
                w.id,
                w.code,
                w.name,
                w.type,
                w.parent_id,
                w.location,
                w.address,
                w.manager_id,
                w.capacity,
                w.is_active,
                w.created_at,
                w.updated_at,
                parent.code AS parent_code,
                parent.name AS parent_name,
                manager.full_name AS manager_name,
                COUNT(DISTINCT sb.product_id) AS products_count,
                COALESCE(SUM(sb.quantity), 0) AS total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) AS total_value
            FROM warehouses w
            LEFT JOIN warehouses parent ON w.parent_id = parent.id
            LEFT JOIN users manager ON w.manager_id = manager.id
            LEFT JOIN stock_balances sb ON w.id = sb.warehouse_id
            LEFT JOIN products p ON sb.product_id = p.id AND p.deleted_at IS NULL
            WHERE w.deleted_at IS NULL
        ";

        $params = [];

        // تطبيق الفلاتر
        if (!empty($filters['search'])) {
            $sql .= " AND (w.name LIKE ? OR w.code LIKE ? OR w.location LIKE ?)";
            $searchParam = "%{$filters['search']}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if (!empty($filters['type']) && in_array($filters['type'], self::ALLOWED_TYPES, true)) {
            $sql .= " AND w.type = ?";
            $params[] = $filters['type'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND w.is_active = ?";
            $params[] = (int) $filters['is_active'];
        }

        if (!empty($filters['parent_id'])) {
            $sql .= " AND w.parent_id = ?";
            $params[] = (int) $filters['parent_id'];
        }

        // التجميع
        $sql .= " GROUP BY w.id, w.code, w.name, w.type, w.parent_id, w.location, w.address,
                             w.manager_id, w.capacity, w.is_active, w.created_at, w.updated_at,
                             parent.code, parent.name, manager.full_name";

        // الترتيب
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = strtolower($filters['sort_order'] ?? 'asc');

        $allowedSortBy = ['name', 'code', 'type', 'created_at', 'updated_at'];
        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'name';
        }

        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }

        $sql .= " ORDER BY w.{$sortBy} {$sortOrder}";

        // جلب البيانات
        $warehouses = $this->db->select($sql, $params);

        // تحويل القيم الرقمية
        foreach ($warehouses as &$warehouse) {
            $warehouse['products_count'] = (int) $warehouse['products_count'];
            $warehouse['total_quantity'] = (float) $warehouse['total_quantity'];
            $warehouse['total_value'] = (float) $warehouse['total_value'];
            $warehouse['capacity'] = $warehouse['capacity'] !== null ? (float) $warehouse['capacity'] : null;
        }

        return $warehouses;
    }

    // =========================================================================
    // 4. تعديل مخزن (Update)
    // =========================================================================

    /**
     * تعديل مخزن موجود
     *
     * @param int $id معرف المخزن
     * @param array $data البيانات المراد تحديثها
     * @param int $userId معرف المستخدم الذي يعدّل
     * @return void
     * @throws Exception إذا فشل التحديث أو كانت البيانات غير صالحة
     */
    public function update(int $id, array $data, int $userId): void
    {
        // 1. التحقق من وجود المخزن
        $warehouse = $this->db->selectOne(
            "SELECT id FROM warehouses WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$warehouse) {
            throw new Exception('المخزن غير موجود.');
        }

        // 2. التحقق من parent_id (إذا تم تقديمه)
        if (isset($data['parent_id']) && $data['parent_id'] !== null) {
            if ((int) $data['parent_id'] === $id) {
                throw new Exception('لا يمكن تعيين المخزن كأب لنفسه.');
            }

            $parent = $this->db->selectOne(
                "SELECT id FROM warehouses WHERE id = ? AND deleted_at IS NULL",
                [(int) $data['parent_id']]
            );

            if (!$parent) {
                throw new Exception('المخزن الرئيسي غير موجود.');
            }

            // منع إنشاء حلقة دائرية
            if ($this->isDescendant((int) $data['parent_id'], $id)) {
                throw new Exception('لا يمكن تعيين مخزن رئيسي يكون فرعاً لهذا المخزن.');
            }
        }

        // 3. التحقق من manager_id (إذا تم تقديمه)
        if (isset($data['manager_id']) && $data['manager_id'] !== null) {
            $manager = $this->db->selectOne(
                "SELECT id FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
                [(int) $data['manager_id']]
            );

            if (!$manager) {
                throw new Exception('المدير المحدد غير موجود أو غير نشط.');
            }
        }

        // 4. بناء البيانات للتحديث
        $updateData = ['updated_by' => $userId, 'updated_at' => date('Y-m-d H:i:s')];

        $allowedFields = ['name', 'type', 'parent_id', 'location', 'address', 'manager_id', 'capacity', 'is_active'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        // 5. تحديث المخزن
        $this->db->update('warehouses', $updateData, ['id' => $id]);
    }

    // =========================================================================
    // 5. حذف مخزن (Soft Delete)
    // =========================================================================

    /**
     * حذف مخزن (Soft Delete)
     *
     * @param int $id معرف المخزن
     * @return void
     * @throws Exception إذا فشل الحذف أو كان المخزن مرتبطاً ببيانات أخرى
     */
    public function delete(int $id): void
    {
        // 1. التحقق من وجود المخزن
        $warehouse = $this->db->selectOne(
            "SELECT id, name, code FROM warehouses WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        if (!$warehouse) {
            throw new Exception('المخزن غير موجود.');
        }

        // 2. التحقق من عدم وجود ارتباطات

        // 2.1. التحقق من الأرصدة المخزنية
        $hasBalances = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM stock_balances WHERE warehouse_id = ?",
            [$id]
        );

        if ((int) $hasBalances['count'] > 0) {
            throw new Exception(
                'لا يمكن حذف هذا المخزن لأنه يحتوي على أرصدة مخزنية. ' .
                'يجب نقل أو تصفية المخزون أولاً.'
            );
        }

        // 2.2. التحقق من الحركات المخزنية
        $hasMovements = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM stock_movements WHERE warehouse_id = ? OR from_warehouse_id = ? OR to_warehouse_id = ?",
            [$id, $id, $id]
        );

        if ((int) $hasMovements['count'] > 0) {
            throw new Exception(
                'لا يمكن حذف هذا المخزن لأنه يحتوي على حركات مخزنية. ' .
                'هذا المخزن جزء من سجل تاريخي.'
            );
        }

        // 2.3. التحقق من المستندات (receipts, issues, transfers)
        $hasReceipts = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM receipts WHERE warehouse_id = ? AND deleted_at IS NULL",
            [$id]
        );

        if ((int) $hasReceipts['count'] > 0) {
            throw new Exception('لا يمكن حذف هذا المخزن لأنه يحتوي على إذونات استلام.');
        }

        $hasIssues = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM issues WHERE warehouse_id = ? AND deleted_at IS NULL",
            [$id]
        );

        if ((int) $hasIssues['count'] > 0) {
            throw new Exception('لا يمكن حذف هذا المخزن لأنه يحتوي على إذونات صرف.');
        }

        $hasTransfers = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM transfers WHERE from_warehouse_id = ? OR to_warehouse_id = ? AND deleted_at IS NULL",
            [$id, $id]
        );

        if ((int) $hasTransfers['count'] > 0) {
            throw new Exception('لا يمكن حذف هذا المخزن لأنه يحتوي على تحويلات مخزنية.');
        }

        // 2.4. التحقق من الفروع (إذا كان مخزناً رئيسياً)
        $hasChildren = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM warehouses WHERE parent_id = ? AND deleted_at IS NULL",
            [$id]
        );

        if ((int) $hasChildren['count'] > 0) {
            throw new Exception(
                'لا يمكن حذف هذا المخزن لأنه يحتوي على مخازن فرعية. ' .
                'يجب حذف الفروع أولاً أو إعادة تعيينها.'
            );
        }

        // 2.5. التحقق من المستخدمين المرتبطين
        $hasUsers = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM users WHERE warehouse_id = ? AND deleted_at IS NULL",
            [$id]
        );

        if ((int) $hasUsers['count'] > 0) {
            throw new Exception(
                'لا يمكن حذف هذا المخزن لأنه مرتبط بمستخدمين. ' .
                'يجب إعادة تعيين المستخدمين إلى مخازن أخرى أولاً.'
            );
        }

        // 3. حذف المخزن (Soft Delete)
        $this->db->update('warehouses', [
            'deleted_at' => date('Y-m-d H:i:s'),
            'is_active'  => 0,
        ], ['id' => $id]);
    }

    // =========================================================================
    // 6. التحقق من الهيكل الهرمي
    // =========================================================================

    /**
     * التحقق من أن مخزن معين هو فرع (descendant) لمخزن آخر
     *
     * @param int $potentialParentId معرف المخزن المحتمل أن يكون أباً
     * @param int $childId معرف المخزن المحتمل أن يكون ابناً
     * @return bool true إذا كان potentialParentId فرعاً لـ childId
     */
    private function isDescendant(int $potentialParentId, int $childId): bool
    {
        if ($potentialParentId === $childId) {
            return true;
        }

        $currentId = $potentialParentId;
        $visited = [];

        while ($currentId !== null) {
            // منع الحلقة اللانهائية
            if (in_array($currentId, $visited, true)) {
                return true; // تم اكتشاف حلقة دائرية
            }

            $visited[] = $currentId;

            $parent = $this->db->selectOne(
                "SELECT parent_id FROM warehouses WHERE id = ? AND deleted_at IS NULL",
                [$currentId]
            );

            if (!$parent || $parent['parent_id'] === null) {
                break;
            }

            if ((int) $parent['parent_id'] === $childId) {
                return true; // تم العثور على الحلقة
            }

            $currentId = (int) $parent['parent_id'];
        }

        return false;
    }

    // =========================================================================
    // 7. جلب الهيكل الهرمي
    // =========================================================================

    /**
     * جلب الهيكل الهرمي للمخازن (شجرة)
     *
     * @return array الهيكل الهرمي
     */
    public function getHierarchy(): array
    {
        // جلب كل المخازن
        $warehouses = $this->db->select("
            SELECT id, code, name, type, parent_id, is_active
            FROM warehouses
            WHERE deleted_at IS NULL
            ORDER BY parent_id, name
        ");

        // بناء الشجرة
        $tree = [];
        $indexed = [];

        // فهرسة كل المخازن
        foreach ($warehouses as $warehouse) {
            $warehouse['children'] = [];
            $indexed[$warehouse['id']] = $warehouse;
        }

        // بناء الشجرة
        foreach ($indexed as $id => $warehouse) {
            if ($warehouse['parent_id'] === null) {
                // مخزن رئيسي (جذر)
                $tree[] = &$indexed[$id];
            } else {
                // مخزن فرعي
                if (isset($indexed[$warehouse['parent_id']])) {
                    $indexed[$warehouse['parent_id']]['children'][] = &$indexed[$id];
                }
            }
        }

        return $tree;
    }

    // =========================================================================
    // 8. إحصائيات المخزن
    // =========================================================================

    /**
     * جلب إحصائيات مخزن معين
     *
     * @param int $warehouseId معرف المخزن
     * @return array الإحصائيات
     */
    public function getStatistics(int $warehouseId): array
    {
        $stats = $this->db->selectOne("
            SELECT
                COUNT(DISTINCT sb.product_id) AS products_count,
                COALESCE(SUM(sb.quantity), 0) AS total_quantity,
                COALESCE(SUM(sb.quantity * p.cost_price), 0) AS total_value,
                COALESCE(AVG(sb.quantity), 0) AS avg_quantity_per_product,
                COALESCE(MAX(sb.quantity), 0) AS max_quantity,
                COALESCE(MIN(sb.quantity), 0) AS min_quantity
            FROM stock_balances sb
            INNER JOIN products p ON sb.product_id = p.id AND p.deleted_at IS NULL
            WHERE sb.warehouse_id = ?
        ", [$warehouseId]);

        // المنتجات منخفضة المخزون في هذا المخزن
        $lowStock = $this->db->selectOne("
            SELECT COUNT(DISTINCT p.id) AS count
            FROM products p
            INNER JOIN stock_balances sb ON p.id = sb.product_id
            WHERE sb.warehouse_id = ?
              AND p.deleted_at IS NULL
              AND sb.quantity > 0
              AND sb.quantity <= p.reorder_point
        ", [$warehouseId]);

        // المنتجات نفدت في هذا المخزن
        $outOfStock = $this->db->selectOne("
            SELECT COUNT(DISTINCT p.id) AS count
            FROM products p
            INNER JOIN stock_balances sb ON p.id = sb.product_id
            WHERE sb.warehouse_id = ?
              AND p.deleted_at IS NULL
              AND sb.quantity = 0
        ", [$warehouseId]);

        return [
            'products_count'       => (int) ($stats['products_count'] ?? 0),
            'total_quantity'       => (float) ($stats['total_quantity'] ?? 0),
            'total_value'          => (float) ($stats['total_value'] ?? 0),
            'avg_quantity_per_product' => (float) ($stats['avg_quantity_per_product'] ?? 0),
            'max_quantity'         => (float) ($stats['max_quantity'] ?? 0),
            'min_quantity'         => (float) ($stats['min_quantity'] ?? 0),
            'low_stock_count'      => (int) ($lowStock['count'] ?? 0),
            'out_of_stock_count'   => (int) ($outOfStock['count'] ?? 0),
        ];
    }

    // =========================================================================
    // 9. التحقق من الوجود
    // =========================================================================

    /**
     * التحقق من وجود مخزن معين
     *
     * @param int $id معرف المخزن
     * @return bool true إذا كان المخزن موجوداً
     */
    public function exists(int $id): bool
    {
        $result = $this->db->selectOne(
            "SELECT COUNT(*) AS count FROM warehouses WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );

        return ((int) ($result['count'] ?? 0)) > 0;
    }

    /**
     * التحقق من تفرد كود المخزن
     *
     * @param string $code كود المخزن
     * @param int|null $excludeId استثناء مخزن معين (للتحديث)
     * @return bool true إذا كان الكود فريداً
     */
    public function isCodeUnique(string $code, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM warehouses WHERE code = ? AND deleted_at IS NULL";
        $params = [trim($code)];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->selectOne($sql, $params);

        return ((int) ($result['count'] ?? 0)) === 0;
    }
}
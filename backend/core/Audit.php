<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/core/Audit.php
// الوصف: نظام سجل التدقيق المتقدم - تسجيل كل حركة في النظام
// التاريخ: 2026-08-22
// ================================================================

namespace Core;

use Exception;

class Audit
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private static $db;
    
    /**
     * @var array $config - إعدادات التدقيق
     */
    private static $config = [];
    
    /**
     * @var bool $enabled - هل نظام التدقيق مفعل
     */
    private static $enabled = true;
    
    /**
     * @var array $excludedActions - الإجراءات المستثناة من التسجيل
     */
    private static $excludedActions = [
        'PAGE_VIEW',
        'HEARTBEAT',
        'PING'
    ];
    
    /**
     * @var array $excludedModules - الوحدات المستثناة من التسجيل
     */
    private static $excludedModules = [
        'system',
        'health'
    ];

    /**
     * تهيئة النظام
     */
    private static function init(): void
    {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
        self::loadConfig();
    }

    /**
     * تحميل الإعدادات
     */
    private static function loadConfig(): void
    {
        try {
            $settings = self::$db->query(
                "SELECT setting_key, setting_value FROM system_settings 
                 WHERE setting_key IN ('audit_enabled', 'audit_log_actions', 'audit_retention_days')"
            );
            
            foreach ($settings as $setting) {
                switch ($setting['setting_key']) {
                    case 'audit_enabled':
                        self::$enabled = $setting['setting_value'] === 'true';
                        break;
                    case 'audit_retention_days':
                        self::$config['retention_days'] = (int)$setting['setting_value'];
                        break;
                }
            }
        } catch (Exception $e) {
            // استخدام القيم الافتراضية
        }
        
        self::$config['retention_days'] = self::$config['retention_days'] ?? 90;
    }

    /**
     * تسجيل حدث في سجل التدقيق
     */
    public static function log(
        ?int $userId,
        string $action,
        string $module,
        string $description,
        ?array $details = null,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): int {
        // التحقق من تفعيل النظام
        if (!self::$enabled) {
            return 0;
        }
        
        // التحقق من الإجراءات المستثناة
        if (in_array($action, self::$excludedActions)) {
            return 0;
        }
        
        // التحقق من الوحدات المستثناة
        if (in_array($module, self::$excludedModules)) {
            return 0;
        }
        
        self::init();
        
        $ip = getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $username = null;
        $sessionId = null;
        
        // جلب معلومات المستخدم
        if ($userId) {
            try {
                $user = self::$db->queryOne(
                    "SELECT username FROM users WHERE id = :id",
                    ['id' => $userId]
                );
                $username = $user ? $user['username'] : null;
            } catch (Exception $e) {
                // السكوت عن الخطأ
            }
        }
        
        // جلب معرف الجلسة
        try {
            if (isset($_REQUEST['session_id'])) {
                $sessionId = (int)$_REQUEST['session_id'];
            } elseif (function_exists('session_id') && session_status() === PHP_SESSION_ACTIVE) {
                $sessionId = session_id();
            }
        } catch (Exception $e) {
            // السكوت عن الخطأ
        }
        
        // معالجة التفاصيل
        $detailsJson = null;
        if ($details !== null) {
            $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);
            if ($detailsJson === false) {
                $detailsJson = json_encode(['error' => 'Failed to encode details']);
            }
        }
        
        // إدراج السجل
        try {
            return self::$db->insert('audit_logs', [
                'user_id' => $userId,
                'username' => $username,
                'session_id' => $sessionId,
                'action' => $action,
                'module' => $module,
                'description' => $description,
                'details' => $detailsJson,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'reference_type' => $referenceType,
                'reference_id' => (string)$referenceId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            // تسجيل الخطأ في سجل النظام
            error_log("Audit log error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * تسجيل حدث تسجيل دخول ناجح
     */
    public static function loginSuccess(int $userId, string $username): int
    {
        return self::log(
            $userId,
            'LOGIN_SUCCESS',
            'auth',
            "تسجيل دخول ناجح للمستخدم {$username}",
            ['username' => $username]
        );
    }

    /**
     * تسجيل محاولة تسجيل دخول فاشلة
     */
    public static function loginFailed(string $username, ?string $reason = null): int
    {
        return self::log(
            null,
            'LOGIN_FAILED',
            'auth',
            "محاولة تسجيل دخول فاشلة للمستخدم {$username}",
            ['username' => $username, 'reason' => $reason]
        );
    }

    /**
     * تسجيل حدث تسجيل خروج
     */
    public static function logoutSuccess(int $userId, string $username): int
    {
        return self::log(
            $userId,
            'LOGOUT',
            'auth',
            "تسجيل خروج المستخدم {$username}",
            ['username' => $username]
        );
    }

    /**
     * تسجيل تغيير كلمة المرور
     */
    public static function passwordChanged(int $userId, string $username): int
    {
        return self::log(
            $userId,
            'PASSWORD_CHANGED',
            'auth',
            "تغيير كلمة مرور المستخدم {$username}",
            ['username' => $username]
        );
    }

    /**
     * تسجيل إنشاء مستخدم
     */
    public static function userCreated(int $userId, string $username, int $createdBy): int
    {
        return self::log(
            $createdBy,
            'USER_CREATED',
            'users',
            "إنشاء مستخدم جديد {$username}",
            ['username' => $username, 'user_id' => $userId]
        );
    }

    /**
     * تسجيل تحديث مستخدم
     */
    public static function userUpdated(int $userId, string $username, int $updatedBy): int
    {
        return self::log(
            $updatedBy,
            'USER_UPDATED',
            'users',
            "تحديث بيانات المستخدم {$username}",
            ['username' => $username, 'user_id' => $userId]
        );
    }

    /**
     * تسجيل حذف مستخدم
     */
    public static function userDeleted(int $userId, string $username, int $deletedBy): int
    {
        return self::log(
            $deletedBy,
            'USER_DELETED',
            'users',
            "حذف المستخدم {$username}",
            ['username' => $username, 'user_id' => $userId]
        );
    }

    /**
     * تسجيل إنشاء منتج
     */
    public static function productCreated(int $productId, string $productName, int $userId): int
    {
        return self::log(
            $userId,
            'PRODUCT_CREATED',
            'products',
            "إنشاء منتج جديد {$productName}",
            ['product_id' => $productId, 'product_name' => $productName]
        );
    }

    /**
     * تسجيل تحديث منتج
     */
    public static function productUpdated(int $productId, string $productName, int $userId): int
    {
        return self::log(
            $userId,
            'PRODUCT_UPDATED',
            'products',
            "تحديث بيانات المنتج {$productName}",
            ['product_id' => $productId, 'product_name' => $productName]
        );
    }

    /**
     * تسجيل حذف منتج
     */
    public static function productDeleted(int $productId, string $productName, int $userId): int
    {
        return self::log(
            $userId,
            'PRODUCT_DELETED',
            'products',
            "حذف المنتج {$productName}",
            ['product_id' => $productId, 'product_name' => $productName]
        );
    }

    /**
     * تسجيل إنشاء مخزن
     */
    public static function warehouseCreated(int $warehouseId, string $warehouseName, int $userId): int
    {
        return self::log(
            $userId,
            'WAREHOUSE_CREATED',
            'warehouses',
            "إنشاء مخزن جديد {$warehouseName}",
            ['warehouse_id' => $warehouseId, 'warehouse_name' => $warehouseName]
        );
    }

    /**
     * تسجيل تحديث مخزن
     */
    public static function warehouseUpdated(int $warehouseId, string $warehouseName, int $userId): int
    {
        return self::log(
            $userId,
            'WAREHOUSE_UPDATED',
            'warehouses',
            "تحديث بيانات المخزن {$warehouseName}",
            ['warehouse_id' => $warehouseId, 'warehouse_name' => $warehouseName]
        );
    }

    /**
     * تسجيل حذف مخزن
     */
    public static function warehouseDeleted(int $warehouseId, string $warehouseName, int $userId): int
    {
        return self::log(
            $userId,
            'WAREHOUSE_DELETED',
            'warehouses',
            "حذف المخزن {$warehouseName}",
            ['warehouse_id' => $warehouseId, 'warehouse_name' => $warehouseName]
        );
    }

    /**
     * تسجيل حركة مخزون
     */
    public static function stockMovement(
        int $productId,
        int $warehouseId,
        string $movementType,
        float $quantity,
        int $userId,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): int {
        return self::log(
            $userId,
            'STOCK_MOVEMENT',
            'stock',
            "حركة مخزون: {$movementType} - كمية {$quantity}",
            [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'movement_type' => $movementType,
                'quantity' => $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId
            ],
            $referenceType,
            $referenceId
        );
    }

    /**
     * تسجيل إذن استلام
     */
    public static function receiptCreated(int $receiptId, string $receiptNo, int $userId): int
    {
        return self::log(
            $userId,
            'RECEIPT_CREATED',
            'receipts',
            "إنشاء إذن استلام #{$receiptNo}",
            ['receipt_id' => $receiptId, 'receipt_no' => $receiptNo],
            'receipt',
            $receiptId
        );
    }

    /**
     * تسجيل اعتماد إذن استلام
     */
    public static function receiptApproved(int $receiptId, string $receiptNo, int $userId): int
    {
        return self::log(
            $userId,
            'RECEIPT_APPROVED',
            'receipts',
            "اعتماد إذن استلام #{$receiptNo}",
            ['receipt_id' => $receiptId, 'receipt_no' => $receiptNo],
            'receipt',
            $receiptId
        );
    }

    /**
     * تسجيل إذن صرف
     */
    public static function issueCreated(int $issueId, string $issueNo, int $userId): int
    {
        return self::log(
            $userId,
            'ISSUE_CREATED',
            'issues',
            "إنشاء إذن صرف #{$issueNo}",
            ['issue_id' => $issueId, 'issue_no' => $issueNo],
            'issue',
            $issueId
        );
    }

    /**
     * تسجيل اعتماد إذن صرف
     */
    public static function issueApproved(int $issueId, string $issueNo, int $userId): int
    {
        return self::log(
            $userId,
            'ISSUE_APPROVED',
            'issues',
            "اعتماد إذن صرف #{$issueNo}",
            ['issue_id' => $issueId, 'issue_no' => $issueNo],
            'issue',
            $issueId
        );
    }

    /**
     * تسجيل تحويل
     */
    public static function transferCreated(int $transferId, string $transferNo, int $userId): int
    {
        return self::log(
            $userId,
            'TRANSFER_CREATED',
            'transfers',
            "إنشاء تحويل #{$transferNo}",
            ['transfer_id' => $transferId, 'transfer_no' => $transferNo],
            'transfer',
            $transferId
        );
    }

    /**
     * تسجيل اعتماد تحويل
     */
    public static function transferApproved(int $transferId, string $transferNo, int $userId): int
    {
        return self::log(
            $userId,
            'TRANSFER_APPROVED',
            'transfers',
            "اعتماد تحويل #{$transferNo}",
            ['transfer_id' => $transferId, 'transfer_no' => $transferNo],
            'transfer',
            $transferId
        );
    }

    /**
     * تسجيل مرتجع
     */
    public static function returnCreated(int $returnId, string $returnNo, int $userId): int
    {
        return self::log(
            $userId,
            'RETURN_CREATED',
            'returns',
            "إنشاء مرتجع #{$returnNo}",
            ['return_id' => $returnId, 'return_no' => $returnNo],
            'return',
            $returnId
        );
    }

    /**
     * تسجيل اعتماد مرتجع
     */
    public static function returnApproved(int $returnId, string $returnNo, int $userId): int
    {
        return self::log(
            $userId,
            'RETURN_APPROVED',
            'returns',
            "اعتماد مرتجع #{$returnNo}",
            ['return_id' => $returnId, 'return_no' => $returnNo],
            'return',
            $returnId
        );
    }

    /**
     * تسجيل رفض صلاحية
     */
    public static function permissionDenied(int $userId, string $permission): int
    {
        return self::log(
            $userId,
            'PERMISSION_DENIED',
            'rbac',
            "محاولة وصول بدون صلاحية: {$permission}",
            ['permission' => $permission]
        );
    }

    /**
     * تسجيل تغيير صلاحيات
     */
    public static function permissionChanged(int $userId, string $username, array $permissions): int
    {
        return self::log(
            $userId,
            'PERMISSION_CHANGED',
            'rbac',
            "تغيير صلاحيات المستخدم {$username}",
            ['username' => $username, 'permissions' => $permissions]
        );
    }

    /**
     * الحصول على سجلات التدقيق مع فلترة
     */
    public static function getLogs(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        self::init();
        
        $sql = "SELECT * FROM audit_logs WHERE 1=1";
        $params = [];

        if (isset($filters['user_id'])) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $filters['user_id'];
        }

        if (isset($filters['action'])) {
            $sql .= " AND action = :action";
            $params['action'] = $filters['action'];
        }

        if (isset($filters['module'])) {
            $sql .= " AND module = :module";
            $params['module'] = $filters['module'];
        }

        if (isset($filters['reference_type'])) {
            $sql .= " AND reference_type = :reference_type";
            $params['reference_type'] = $filters['reference_type'];
        }

        if (isset($filters['reference_id'])) {
            $sql .= " AND reference_id = :reference_id";
            $params['reference_id'] = $filters['reference_id'];
        }

        if (isset($filters['search'])) {
            $sql .= " AND (description LIKE :search OR username LIKE :search)";
            $params['search'] = "%{$filters['search']}%";
        }

        if (isset($filters['start_date'])) {
            $sql .= " AND created_at >= :start_date";
            $params['start_date'] = $filters['start_date'];
        }

        if (isset($filters['end_date'])) {
            $sql .= " AND created_at <= :end_date";
            $params['end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        return self::$db->query($sql, $params);
    }

    /**
     * الحصول على عدد السجلات
     */
    public static function countLogs(array $filters = []): int
    {
        self::init();
        
        $sql = "SELECT COUNT(*) FROM audit_logs WHERE 1=1";
        $params = [];

        if (isset($filters['user_id'])) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $filters['user_id'];
        }

        if (isset($filters['action'])) {
            $sql .= " AND action = :action";
            $params['action'] = $filters['action'];
        }

        if (isset($filters['module'])) {
            $sql .= " AND module = :module";
            $params['module'] = $filters['module'];
        }

        if (isset($filters['start_date'])) {
            $sql .= " AND created_at >= :start_date";
            $params['start_date'] = $filters['start_date'];
        }

        if (isset($filters['end_date'])) {
            $sql .= " AND created_at <= :end_date";
            $params['end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        return (int)self::$db->queryValue($sql, $params);
    }

    /**
     * الحصول على إحصائيات التدقيق
     */
    public static function getStats(): array
    {
        self::init();
        
        $stats = [];
        
        // إجمالي السجلات
        $result = self::$db->queryOne("SELECT COUNT(*) as total FROM audit_logs");
        $stats['total'] = (int)($result['total'] ?? 0);
        
        // السجلات اليوم
        $result = self::$db->queryOne(
            "SELECT COUNT(*) as today FROM audit_logs WHERE DATE(created_at) = CURDATE()"
        );
        $stats['today'] = (int)($result['today'] ?? 0);
        
        // السجلات هذا الأسبوع
        $result = self::$db->queryOne(
            "SELECT COUNT(*) as week FROM audit_logs WHERE YEARWEEK(created_at) = YEARWEEK(NOW())"
        );
        $stats['week'] = (int)($result['week'] ?? 0);
        
        // السجلات هذا الشهر
        $result = self::$db->queryOne(
            "SELECT COUNT(*) as month FROM audit_logs WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
        );
        $stats['month'] = (int)($result['month'] ?? 0);
        
        // أكثر الإجراءات
        $actions = self::$db->query(
            "SELECT action, COUNT(*) as count FROM audit_logs GROUP BY action ORDER BY count DESC LIMIT 10"
        );
        $stats['top_actions'] = $actions;
        
        // أكثر الوحدات
        $modules = self::$db->query(
            "SELECT module, COUNT(*) as count FROM audit_logs GROUP BY module ORDER BY count DESC LIMIT 10"
        );
        $stats['top_modules'] = $modules;
        
        // أكثر المستخدمين نشاطاً
        $users = self::$db->query(
            "SELECT u.full_name, COUNT(al.id) as count 
             FROM audit_logs al 
             LEFT JOIN users u ON u.id = al.user_id 
             GROUP BY al.user_id 
             ORDER BY count DESC LIMIT 10"
        );
        $stats['top_users'] = $users;
        
        return $stats;
    }

    /**
     * تنظيف السجلات القديمة
     */
    public static function cleanup(?int $days = null): int
    {
        self::init();
        
        $retentionDays = $days ?? self::$config['retention_days'] ?? 90;
        
        return self::$db->execute(
            "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['days' => $retentionDays]
        );
    }

    /**
     * تمكين/تعطيل نظام التدقيق
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * إضافة إجراء مستثنى
     */
    public static function addExcludedAction(string $action): void
    {
        if (!in_array($action, self::$excludedActions)) {
            self::$excludedActions[] = $action;
        }
    }

    /**
     * إضافة وحدة مستثناة
     */
    public static function addExcludedModule(string $module): void
    {
        if (!in_array($module, self::$excludedModules)) {
            self::$excludedModules[] = $module;
        }
    }

    /**
     * الحصول على جميع الإجراءات المتاحة
     */
    public static function getActions(): array
    {
        self::init();
        return self::$db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action");
    }

    /**
     * الحصول على جميع الوحدات المتاحة
     */
    public static function getModules(): array
    {
        self::init();
        return self::$db->query("SELECT DISTINCT module FROM audit_logs ORDER BY module");
    }

    /**
     * تصدير السجلات إلى CSV
     */
    public static function exportToCsv(array $filters = []): string
    {
        self::init();
        
        $logs = self::getLogs($filters, 10000, 0);
        
        if (empty($logs)) {
            return '';
        }
        
        $csv = "ID,المستخدم,الإجراء,الوحدة,الوصف,IP,التاريخ\n";
        
        foreach ($logs as $log) {
            $csv .= implode(',', [
                $log['id'],
                $log['username'] ?? 'نظام',
                $log['action'],
                $log['module'],
                '"' . str_replace('"', '""', $log['description'] ?? '') . '"',
                $log['ip_address'] ?? '',
                $log['created_at']
            ]) . "\n";
        }
        
        return $csv;
    }
}

// ================================================================
// انتهى الملف
// ================================================================

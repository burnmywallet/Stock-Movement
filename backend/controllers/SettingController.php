<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/SettingController.php
// الوصف: متحكم إدارة الإعدادات المتقدمة - CRUD مع صلاحيات للأدمن فقط
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Exception;

class SettingController
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
    
    /**
     * @var array $systemSettings - إعدادات النظام المحملة
     */
    private $systemSettings = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        $this->audit = new Audit();
        $this->loadAllSettings();
    }

    /**
     * تحميل جميع الإعدادات من قاعدة البيانات
     */
    private function loadAllSettings(): void
    {
        try {
            $settings = $this->db->query("
                SELECT setting_key, setting_value, setting_type 
                FROM system_settings
            ");
            
            foreach ($settings as $setting) {
                $value = $setting['setting_value'];
                switch ($setting['setting_type']) {
                    case 'boolean':
                        $value = $value === 'true';
                        break;
                    case 'number':
                        $value = (float)$value;
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }
                $this->systemSettings[$setting['setting_key']] = $value;
            }
        } catch (Exception $e) {
            // استخدام القيم الافتراضية
        }
    }

    /**
     * GET /api/settings
     * جلب جميع إعدادات النظام (للأدمن فقط)
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية الإعدادات (للأدمن فقط)
            if (!$this->auth->hasPermission($userId, 'settings.view')) {
                errorResponse('ليس لديك صلاحية لعرض إعدادات النظام', 403);
                return;
            }

            $group = $_GET['group'] ?? '';
            $search = $_GET['search'] ?? '';

            $params = [];
            $where = [];

            if (!empty($group)) {
                $where[] = "setting_group = :group";
                $params['group'] = $group;
            }

            if (!empty($search)) {
                $where[] = "(setting_key LIKE :search OR description LIKE :search)";
                $params['search'] = "%{$search}%";
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $settings = $this->db->query("
                SELECT 
                    id,
                    setting_key,
                    setting_value,
                    setting_group,
                    setting_type,
                    is_editable,
                    is_encrypted,
                    description,
                    validation_rules,
                    sort_order,
                    updated_at,
                    (SELECT full_name FROM users WHERE id = updated_by) as updated_by_name
                FROM system_settings
                {$whereClause}
                ORDER BY setting_group, sort_order, setting_key
            ", $params);

            // تجميع الإعدادات حسب المجموعة
            $grouped = [];
            foreach ($settings as $setting) {
                $groupName = $setting['setting_group'];
                if (!isset($grouped[$groupName])) {
                    $grouped[$groupName] = [];
                }
                // إذا كان الإعداد مشفراً، إخفاء القيمة
                if ($setting['is_encrypted']) {
                    $setting['setting_value'] = '********';
                }
                $grouped[$groupName][] = $setting;
            }

            // إحصائيات الإعدادات
            $stats = $this->db->queryOne("
                SELECT 
                    COUNT(*) as total,
                    COUNT(DISTINCT setting_group) as groups,
                    COUNT(CASE WHEN is_editable = 1 THEN 1 END) as editable,
                    COUNT(CASE WHEN is_encrypted = 1 THEN 1 END) as encrypted
                FROM system_settings
            ");

            successResponse('تم جلب إعدادات النظام', [
                'data' => $grouped,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'groups' => (int)($stats['groups'] ?? 0),
                    'editable' => (int)($stats['editable'] ?? 0),
                    'encrypted' => (int)($stats['encrypted'] ?? 0)
                ]
            ]);

        } catch (Exception $e) {
            error_log('Settings list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/settings/{key}
     * جلب إعداد محدد
     */
    public function show(string $key): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'settings.view')) {
                errorResponse('ليس لديك صلاحية لعرض إعدادات النظام', 403);
                return;
            }

            $setting = $this->db->queryOne("
                SELECT 
                    setting_key,
                    setting_value,
                    setting_group,
                    setting_type,
                    is_editable,
                    is_encrypted,
                    description,
                    validation_rules,
                    updated_at,
                    (SELECT full_name FROM users WHERE id = updated_by) as updated_by_name
                FROM system_settings
                WHERE setting_key = :key
            ", ['key' => $key]);

            if (!$setting) {
                errorResponse('الإعداد غير موجود');
                return;
            }

            // إذا كان الإعداد مشفراً، إخفاء القيمة
            if ($setting['is_encrypted']) {
                $setting['setting_value'] = '********';
            }

            successResponse('تم جلب الإعداد', $setting);

        } catch (Exception $e) {
            error_log('Setting show error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/settings/{key}
     * تحديث إعداد محدد (للأدمن فقط)
     */
    public function update(string $key): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            // التحقق من صلاحية التعديل (للأدمن فقط)
            if (!$this->auth->hasPermission($userId, 'settings.edit')) {
                errorResponse('ليس لديك صلاحية لتعديل إعدادات النظام', 403);
                return;
            }

            // جلب الإعداد الحالي
            $current = $this->db->queryOne("
                SELECT setting_key, setting_value, setting_type, is_editable, is_encrypted
                FROM system_settings
                WHERE setting_key = :key
            ", ['key' => $key]);

            if (!$current) {
                errorResponse('الإعداد غير موجود');
                return;
            }

            if (!$current['is_editable']) {
                errorResponse('هذا الإعداد غير قابل للتعديل');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $newValue = $input['value'] ?? null;

            if ($newValue === null) {
                errorResponse('القيمة مطلوبة');
                return;
            }

            // التحقق من صحة القيمة حسب النوع
            $this->validateSettingValue($current, $newValue);

            // معالجة القيمة
            $processedValue = $this->processSettingValue($current, $newValue);

            // حفظ القيمة القديمة للتدقيق
            $oldValue = $current['setting_value'];

            // تحديث الإعداد
            $this->db->update('system_settings', [
                'setting_value' => $processedValue,
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['setting_key' => $key]);

            // تحديث الذاكرة المؤقتة
            $this->systemSettings[$key] = $this->castValue($processedValue, $current['setting_type']);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'SETTING_UPDATED',
                'settings',
                "تحديث إعداد: {$key}",
                [
                    'setting_key' => $key,
                    'old_value' => $this->isSensitive($key) ? '********' : $oldValue,
                    'new_value' => $this->isSensitive($key) ? '********' : $processedValue,
                    'setting_type' => $current['setting_type']
                ]
            );

            successResponse('تم تحديث الإعداد بنجاح', [
                'setting_key' => $key,
                'value' => $this->isSensitive($key) ? '********' : $processedValue
            ]);

        } catch (Exception $e) {
            error_log('Setting update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/settings/batch
     * تحديث إعدادات متعددة (للأدمن فقط)
     */
    public function batchUpdate(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'settings.edit')) {
                errorResponse('ليس لديك صلاحية لتعديل إعدادات النظام', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $settings = $input['settings'] ?? [];

            if (empty($settings)) {
                errorResponse('لا توجد إعدادات للتحديث');
                return;
            }

            $updated = 0;
            $errors = [];

            $this->db->beginTransaction();

            foreach ($settings as $key => $value) {
                try {
                    // جلب الإعداد الحالي
                    $current = $this->db->queryOne("
                        SELECT setting_key, setting_value, setting_type, is_editable, is_encrypted
                        FROM system_settings
                        WHERE setting_key = :key
                    ", ['key' => $key]);

                    if (!$current) {
                        $errors[] = "الإعداد '{$key}' غير موجود";
                        continue;
                    }

                    if (!$current['is_editable']) {
                        $errors[] = "الإعداد '{$key}' غير قابل للتعديل";
                        continue;
                    }

                    // التحقق من صحة القيمة
                    $this->validateSettingValue($current, $value);

                    // معالجة القيمة
                    $processedValue = $this->processSettingValue($current, $value);

                    // تحديث الإعداد
                    $this->db->update('system_settings', [
                        'setting_value' => $processedValue,
                        'updated_by' => $userId,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['setting_key' => $key]);

                    // تحديث الذاكرة المؤقتة
                    $this->systemSettings[$key] = $this->castValue($processedValue, $current['setting_type']);

                    $updated++;

                } catch (Exception $e) {
                    $errors[] = "الإعداد '{$key}': " . $e->getMessage();
                }
            }

            $this->db->commit();

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'SETTINGS_BATCH_UPDATED',
                'settings',
                "تحديث {$updated} إعداد",
                [
                    'updated' => $updated,
                    'errors' => $errors
                ]
            );

            successResponse('تم تحديث الإعدادات بنجاح', [
                'updated' => $updated,
                'errors' => $errors
            ]);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Batch update error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/settings/reset
     * إعادة تعيين الإعدادات إلى القيم الافتراضية (للأدمن فقط)
     */
    public function reset(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'settings.edit')) {
                errorResponse('ليس لديك صلاحية لإعادة تعيين إعدادات النظام', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $key = $input['key'] ?? null;

            if ($key) {
                // إعادة تعيين إعداد واحد
                $current = $this->db->queryOne("
                    SELECT setting_key, default_value, setting_type, is_editable
                    FROM system_settings
                    WHERE setting_key = :key
                ", ['key' => $key]);

                if (!$current) {
                    errorResponse('الإعداد غير موجود');
                    return;
                }

                if (!$current['is_editable']) {
                    errorResponse('هذا الإعداد غير قابل للتعديل');
                    return;
                }

                $this->db->update('system_settings', [
                    'setting_value' => $current['default_value'],
                    'updated_by' => $userId,
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['setting_key' => $key]);

                // تحديث الذاكرة المؤقتة
                $this->systemSettings[$key] = $this->castValue($current['default_value'], $current['setting_type']);

                $this->audit->log(
                    $userId,
                    'SETTING_RESET',
                    'settings',
                    "إعادة تعيين إعداد: {$key}",
                    ['setting_key' => $key]
                );

                successResponse('تم إعادة تعيين الإعداد بنجاح');

            } else {
                // إعادة تعيين جميع الإعدادات
                $this->db->execute("
                    UPDATE system_settings 
                    SET setting_value = default_value,
                        updated_by = :user_id,
                        updated_at = NOW()
                    WHERE is_editable = 1
                ", ['user_id' => $userId]);

                // إعادة تحميل الإعدادات
                $this->loadAllSettings();

                $this->audit->log(
                    $userId,
                    'SETTINGS_ALL_RESET',
                    'settings',
                    "إعادة تعيين جميع الإعدادات إلى القيم الافتراضية"
                );

                successResponse('تم إعادة تعيين جميع الإعدادات بنجاح');
            }

        } catch (Exception $e) {
            error_log('Reset settings error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/settings/app
     * جلب إعدادات التطبيق (للجميع)
     */
    public function appSettings(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            $appSettings = [
                'company_name' => $this->get('company_name', 'شركة المخازن المتطورة'),
                'company_phone' => $this->get('company_phone', ''),
                'company_email' => $this->get('company_email', ''),
                'company_address' => $this->get('company_address', ''),
                'timezone' => $this->get('timezone', 'Asia/Riyadh'),
                'currency' => $this->get('currency', 'SAR'),
                'currency_symbol' => $this->get('currency_symbol', 'ر.س'),
                'date_format' => $this->get('date_format', 'Y-m-d'),
                'time_format' => $this->get('time_format', 'H:i:s'),
                'decimal_places' => (int)$this->get('decimal_places', 2),
                'language' => $this->get('language', 'ar'),
                'app_theme' => $this->get('app_theme', 'dark'),
                'version' => $this->get('version', '5.0.0')
            ];

            successResponse('تم جلب إعدادات التطبيق', $appSettings);

        } catch (Exception $e) {
            error_log('App settings error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/settings/security
     * جلب إعدادات الأمان (للأدمن فقط)
     */
    public function securitySettings(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'settings.view')) {
                errorResponse('ليس لديك صلاحية لعرض إعدادات الأمان', 403);
                return;
            }

            $securitySettings = [
                'single_session_enabled' => $this->get('single_session_enabled', 'true') === 'true',
                'session_timeout' => (int)$this->get('session_timeout', 3600),
                'max_login_attempts' => (int)$this->get('max_login_attempts', 5),
                'lockout_duration' => (int)$this->get('lockout_duration', 30),
                'password_expiry_days' => (int)$this->get('password_expiry_days', 90),
                'force_ssl' => $this->get('force_ssl', 'false') === 'true',
                'csrf_protection' => $this->get('csrf_protection', 'true') === 'true',
                'two_factor_auth' => $this->get('two_factor_auth', 'false') === 'true'
            ];

            successResponse('تم جلب إعدادات الأمان', $securitySettings);

        } catch (Exception $e) {
            error_log('Security settings error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/settings/backup
     * جلب إعدادات النسخ الاحتياطي (للأدمن فقط)
     */
    public function backupSettings(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'settings.view')) {
                errorResponse('ليس لديك صلاحية لعرض إعدادات النسخ الاحتياطي', 403);
                return;
            }

            $backupSettings = [
                'auto_backup_enabled' => $this->get('auto_backup_enabled', 'true') === 'true',
                'auto_backup_time' => $this->get('auto_backup_time', '23:00'),
                'backup_retention_days' => (int)$this->get('backup_retention_days', 30),
                'backup_path' => $this->get('backup_path', '/var/backups/inventory/'),
                'backup_compress' => $this->get('backup_compress', 'true') === 'true'
            ];

            successResponse('تم جلب إعدادات النسخ الاحتياطي', $backupSettings);

        } catch (Exception $e) {
            error_log('Backup settings error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة
    // ================================================================

    /**
     * الحصول على قيمة إعداد
     */
    public function get(string $key, $default = null)
    {
        return $this->systemSettings[$key] ?? $default;
    }

    /**
     * تعيين قيمة إعداد (للاستخدام الداخلي)
     */
    public function set(string $key, $value): void
    {
        $this->systemSettings[$key] = $value;
    }

    /**
     * التحقق من صحة قيمة الإعداد
     */
    private function validateSettingValue(array $setting, $value): void
    {
        $type = $setting['setting_type'];
        $rules = json_decode($setting['validation_rules'] ?? '{}', true);

        switch ($type) {
            case 'number':
                if (!is_numeric($value)) {
                    throw new Exception('القيمة يجب أن تكون رقماً');
                }
                if (isset($rules['min']) && $value < $rules['min']) {
                    throw new Exception("القيمة يجب أن تكون أكبر من أو تساوي {$rules['min']}");
                }
                if (isset($rules['max']) && $value > $rules['max']) {
                    throw new Exception("القيمة يجب أن تكون أصغر من أو تساوي {$rules['max']}");
                }
                break;

            case 'boolean':
                if (!in_array($value, [true, false, 'true', 'false', 1, 0, '1', '0'])) {
                    throw new Exception('القيمة يجب أن تكون true أو false');
                }
                break;

            case 'string':
                if (!is_string($value)) {
                    throw new Exception('القيمة يجب أن تكون نصاً');
                }
                if (isset($rules['min_length']) && strlen($value) < $rules['min_length']) {
                    throw new Exception("النص يجب أن يكون على الأقل {$rules['min_length']} أحرف");
                }
                if (isset($rules['max_length']) && strlen($value) > $rules['max_length']) {
                    throw new Exception("النص يجب أن لا يتجاوز {$rules['max_length']} أحرف");
                }
                if (isset($rules['pattern']) && !preg_match($rules['pattern'], $value)) {
                    throw new Exception('النص لا يطابق النمط المطلوب');
                }
                break;

            case 'json':
                if (is_string($value)) {
                    json_decode($value);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception('قيمة JSON غير صالحة');
                    }
                } elseif (!is_array($value)) {
                    throw new Exception('القيمة يجب أن تكون JSON صالح');
                }
                break;
        }
    }

    /**
     * معالجة قيمة الإعداد حسب النوع
     */
    private function processSettingValue(array $setting, $value): string
    {
        $type = $setting['setting_type'];

        switch ($type) {
            case 'boolean':
                return in_array($value, [true, 'true', 1, '1']) ? 'true' : 'false';
            case 'number':
                return (string)$value;
            case 'json':
                if (is_array($value)) {
                    return json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                return $value;
            default:
                return (string)$value;
        }
    }

    /**
     * تحويل القيمة حسب النوع
     */
    private function castValue(string $value, string $type)
    {
        switch ($type) {
            case 'number':
                return (float)$value;
            case 'boolean':
                return $value === 'true';
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    /**
     * التحقق من أن الإعداد حساس (كلمة مرور، مفتاح، إلخ)
     */
    private function isSensitive(string $key): bool
    {
        $sensitiveKeys = [
            'mail_password',
            'jwt_secret',
            'api_key',
            'secret_key',
            'password',
            'token'
        ];

        foreach ($sensitiveKeys as $sensitive) {
            if (strpos($key, $sensitive) !== false) {
                return true;
            }
        }
        return false;
    }
}

// ================================================================
// انتهى الملف
// ================================================================

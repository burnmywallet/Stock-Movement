<?php

/**
 * ================================================================
 * Logistox - Setting Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/SettingController.php
 * الوظيفة: إدارة إعدادات النظام
 *
 * المسؤوليات:
 * 1. عرض كل الإعدادات مجمعة حسب الفئة (index)
 * 2. تحديث الإعدادات (batch update)
 * 3. معلومات الشركة (company)
 * 4. تحديث معلومات الشركة (updateCompany)
 * 5. قائمة الثيمات المتاحة (themes)
 * 6. تغيير الثيم الحالي (updateTheme)
 * 7. عرض الفئات المتاحة (categories)
 * 8. إعادة تعيين الإعدادات الافتراضية (reset) - Super Admin فقط
 *
 * الصلاحيات المطلوبة:
 * - settings.view: عرض الإعدادات
 * - settings.update: تحديث الإعدادات
 *
 * قيود الأمان:
 * - حماية الإعدادات الحساسة (APP_KEY, DB_*) من التعديل
 * - التحقق من صحة القيم حسب النوع (text, number, boolean, json, email)
 * - تسجيل كل تغيير في audit_logs مع old/new values
 * - Cache invalidation بعد التحديث
 * - إعادة التعيين متاحة لـ Super Admin فقط
 *
 * ملاحظات هامة:
 * - يعتمد على SettingService لتجميع البيانات
 * - يعتمد على AuditService لتسجيل العمليات
 * - الإعدادات مقسمة إلى فئات: company, auth, backup, notifications, themes, inventory
 * - كل إعداد له type محدد يحدد طريقة التحقق
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\SettingService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class SettingController
 *
 * Controller لإدارة إعدادات النظام
 */
class SettingController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var SettingService خدمة الإعدادات
     */
    private SettingService $settingService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var array الإعدادات الحساسة المحمية من التعديل
     */
    private const PROTECTED_SETTINGS = [
        'app.key',
        'db.host',
        'db.port',
        'db.name',
        'db.username',
        'db.password',
        'app.env',
        'app.debug',
    ];

    /**
     * @var array فئات الإعدادات
     */
    private const SETTING_CATEGORIES = [
        'company'       => 'معلومات الشركة',
        'auth'          => 'المصادقة والأمان',
        'backup'        => 'النسخ الاحتياطي',
        'notifications' => 'الإشعارات',
        'themes'        => 'الثيمات والواجهة',
        'inventory'     => 'إعدادات المخزون',
        'reports'       => 'التقارير',
        'system'        => 'إعدادات النظام',
    ];

    /**
     * @var array الإعدادات الافتراضية
     */
    private const DEFAULT_SETTINGS = [
        'company.name'              => 'شركة البركة لتوريد وتصنيع اللحوم',
        'company.address'           => 'جمهورية مصر العربية',
        'company.phone'             => '01286187173',
        'company.email'             => 'info@albaraka.com',
        'company.currency'          => 'EGP',
        'company.currency_symbol'   => 'ج.م',
        'auth.session_timeout'      => '1800',
        'auth.max_login_attempts'   => '5',
        'auth.lockout_duration'     => '15',
        'backup.auto_backup'        => '1',
        'backup.retention_days'     => '30',
        'notifications.enabled'     => '1',
        'themes.default'            => 'dark',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->settingService = new SettingService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[SETTING_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة الإعدادات');
        }
    }

    // =========================================================================
    // 1. عرض كل الإعدادات (Index)
    // =========================================================================

    /**
     * عرض كل الإعدادات مجمعة حسب الفئة
     *
     * GET /api/settings
     *
     * Query Parameters:
     * - category: تصفية حسب الفئة (company, auth, backup, etc.)
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            $category = $_GET['category'] ?? null;

            // جلب كل الإعدادات
            $settings = $this->db->select("
                SELECT id, `key`, `value`, type, description, is_active, updated_at
                FROM settings
                WHERE is_active = 1
                ORDER BY `key` ASC
            ");

            // تنظيم الإعدادات حسب الفئة
            $grouped = [];
            foreach ($settings as $setting) {
                // استخراج الفئة من المفتاح (مثل: company.name → company)
                $parts = explode('.', $setting['key']);
                $settingCategory = $parts[0] ?? 'other';

                // فلترة حسب الفئة إذا تم تحديدها
                if ($category !== null && $settingCategory !== $category) {
                    continue;
                }

                // التحقق من صحة القيمة حسب النوع
                $setting['value'] = $this->castValue($setting['value'], $setting['type']);
                $setting['category'] = $settingCategory;
                $setting['category_label'] = self::SETTING_CATEGORIES[$settingCategory] ?? $settingCategory;

                // إخفاء القيم الحساسة
                if (in_array($setting['key'], self::PROTECTED_SETTINGS, true)) {
                    $setting['value'] = '********';
                    $setting['protected'] = true;
                } else {
                    $setting['protected'] = false;
                }

                $grouped[$settingCategory][] = $setting;
            }

            // إضافة معلومات الفئة
            $categories = [];
            foreach ($grouped as $cat => $catSettings) {
                $categories[$cat] = [
                    'key'         => $cat,
                    'label'       => self::SETTING_CATEGORIES[$cat] ?? $cat,
                    'count'       => count($catSettings),
                    'settings'    => $catSettings,
                ];
            }

            Response::success(
                message: 'تم جلب الإعدادات بنجاح',
                data: [
                    'categories' => $categories,
                    'total'      => count($settings),
                    'filtered'   => $category !== null,
                ]
            );

        } catch (Throwable $e) {
            error_log('[SETTING_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب الإعدادات');
        }
    }

    // =========================================================================
    // 2. تحديث الإعدادات (Update)
    // =========================================================================

    /**
     * تحديث الإعدادات (batch update)
     *
     * PUT /api/settings
     *
     * Request Body (JSON):
     * {
     *   "settings": {
     *     "company.name": "اسم الشركة الجديد",
     *     "auth.session_timeout": "3600",
     *     "backup.retention_days": "60"
     *   }
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function update(): void
    {
        try {
            $currentUserId = $this->getCurrentUserId();
            $input = $this->getJsonInput();

            // 1. التحقق من وجود settings
            if (empty($input['settings']) || !is_array($input['settings'])) {
                Response::badRequest('حقل settings مطلوب ويجب أن يكون مصفوفة');
            }

            $settings = $input['settings'];

            // 2. التحقق من كل إعداد
            $updates = [];
            $errors = [];
            $oldValues = [];
            $newValues = [];

            foreach ($settings as $key => $value) {
                // 2.1. التحقق من صحة المفتاح
                if (!is_string($key) || !preg_match('/^[a-z0-9_.]+$/', $key)) {
                    $errors[$key] = 'اسم الإعداد غير صالح';
                    continue;
                }

                // 2.2. حماية الإعدادات الحساسة
                if (in_array($key, self::PROTECTED_SETTINGS, true)) {
                    $errors[$key] = 'هذا الإعداد محمي ولا يمكن تعديله';
                    continue;
                }

                // 2.3. جلب الإعداد الحالي
                $setting = $this->db->selectOne(
                    "SELECT id, `key`, `value`, type FROM settings WHERE `key` = ? AND is_active = 1",
                    [$key]
                );

                if (!$setting) {
                    $errors[$key] = 'الإعداد غير موجود';
                    continue;
                }

                // 2.4. التحقق من صحة القيمة حسب النوع
                $validationResult = $this->validateValueByType($value, $setting['type']);
                if ($validationResult !== true) {
                    $errors[$key] = $validationResult;
                    continue;
                }

                // 2.5. حفظ القيم القديمة والجديدة للتدقيق
                $oldValues[$key] = $setting['value'];
                $newValues[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;

                $updates[] = [
                    'key'   => $key,
                    'value' => $newValues[$key],
                ];
            }

            // 3. إذا كان هناك أخطاء، إرجاعها
            if (!empty($errors)) {
                Response::validationError($errors, 'بعض الإعدادات غير صالحة');
            }

            // 4. إذا لم يكن هناك تحديثات
            if (empty($updates)) {
                Response::success(
                    message: 'لا توجد تغييرات لتطبيقها',
                    data: ['updated_count' => 0]
                );
            }

            // 5. تنفيذ التحديثات
            $updatedCount = 0;
            foreach ($updates as $update) {
                $this->db->execute(
                    "UPDATE settings SET `value` = ?, updated_at = NOW() WHERE `key` = ?",
                    [$update['value'], $update['key']]
                );
                $updatedCount++;
            }

            // 6. مسح Cache الإعدادات
            $this->settingService->clearCache();

            // 7. تسجيل العملية في audit_logs
            $this->auditService->log(
                userId: $currentUserId,
                action: 'SETTINGS_UPDATE',
                entityType: 'setting',
                entityId: null,
                oldValues: $oldValues,
                newValues: $newValues,
                description: "تم تحديث {$updatedCount} إعداد(ات) في النظام",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: "تم تحديث {$updatedCount} إعداد(ات) بنجاح",
                data: [
                    'updated_count' => $updatedCount,
                    'updated_keys'  => array_keys($newValues),
                ]
            );

        } catch (Throwable $e) {
            error_log('[SETTING_CONTROLLER] Update failed: ' . $e->getMessage());
            Response::internalError('فشل في تحديث الإعدادات: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 3. معلومات الشركة (Company)
    // =========================================================================

    /**
     * جلب معلومات الشركة
     *
     * GET /api/settings/company
     *
     * @return void يرسل استجابة JSON
     */
    public function company(): void
    {
        try {
            $companyInfo = $this->settingService->getCompanyInfo();

            // إضافة معلومات إضافية
            $companyInfo['logo_url'] = $this->getLogoUrl($companyInfo['logo'] ?? null);

            Response::success(
                message: 'تم جلب معلومات الشركة بنجاح',
                data: ['company' => $companyInfo]
            );

        } catch (Throwable $e) {
            error_log('[SETTING_CONTROLLER] Company failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب معلومات الشركة');
        }
    }

    // =========================================================================
    // 4. تحديث معلومات الشركة (Update Company)
    // =========================================================================

    /**
     * تحديث معلومات الشركة
     *
     * PUT /api/settings/company
     *
     * Request Body (JSON):
     * {
     *   "name": "اسم الشركة الجديد",
     *   "address": "العنوان الجديد",
     *   "phone": "01234567890",
     *   "email": "info@newcompany.com",
     *   "currency": "EGP",
     *   "currency_symbol": "ج.م"
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function updateCompany(): void
    {
        try {
            $currentUserId = $this->getCurrentUserId();
            $input = $this->getJsonInput();

            // 1. التحقق من البيانات
            $errors = $this->validateCompanyData($input);
            if (!empty($errors)) {
                Response::validationError($errors, 'بيانات الشركة غير صالحة');
            }

            // 2. جلب القيم القديمة
            $oldValues = [];
            $newValues = [];

            $companyKeys = [
                'company.name',
                'company.address',
                'company.phone',
                'company.email',
                'company.currency',
                'company.currency_symbol',
            ];

            foreach ($companyKeys as $key) {
                $field = str_replace('company.', '', $key);
                if (isset($input[$field])) {
                    $oldSetting = $this->db->selectOne(
                        "SELECT `value` FROM settings WHERE `key` = ?",
                        [$key]
                    );
                    $oldValues[$key] = $oldSetting['value'] ?? null;
                    $newValues[$key] = trim($input[$field]);
                }
            }

            // 3. تنفيذ التحديث
            $updatedCount = 0;
            foreach ($newValues as $key => $value) {
                $this->db->execute(
                    "UPDATE settings SET `value` = ?, updated_at = NOW() WHERE `key` = ?",
                    [$value, $key]
                );
                $updatedCount++;
            }

            // 4. مسح Cache
            $this->settingService->clearCache();

            // 5. تسجيل العملية
            $this->auditService->log(
                userId: $currentUserId,
                action: 'SETTINGS_UPDATE',
                entityType: 'setting',
                entityId: null,
                oldValues: $oldValues,
                newValues: $newValues,
                description: "تم تحديث معلومات الشركة ({$updatedCount} حقول)",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // 6. جلب البيانات المحدثة
            $companyInfo = $this->settingService->getCompanyInfo();
            $companyInfo['logo_url'] = $this->getLogoUrl($companyInfo['logo'] ?? null);

            Response::success(
                message: 'تم تحديث معلومات الشركة بنجاح',
                data: [
                    'company'       => $companyInfo,
                    'updated_count' => $updatedCount,
                ]
            );

        } catch (Throwable $e) {
            error_log('[SETTING_CONTROLLER] UpdateCompany failed: ' . $e->getMessage());
            Response::internalError('فشل في تحديث معلومات الشركة: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 5. قائمة الثيمات (Themes)
    // =========================================================================

    /**
     * جلب قائمة الثيمات المتاحة
     *
     * GET /api/settings/themes
     *
     * @return void يرسل استجابة JSON
     */
    public function themes(): void
    {
        try {
            $themes = $this->db->select("
                SELECT id, name, display_name, icon, colors, is_default, is_active
                FROM themes
                WHERE is_active = 1
                ORDER BY is_default DESC, name ASC
            ");

            // معالجة البيانات
            foreach ($themes as &$theme) {
                $theme['is_default'] = (bool) $theme['is_default'];

                // فك تشفير colors
                if (!empty($theme['colors']) && is_string($theme['colors'])) {
                    $theme['colors'] = json_decode($theme['colors'], true) ?: [];
                }
            }

            // جلب الثيم الحالي
            $currentTheme = $this->settingService->get('themes.default', 'dark');

            Response::success(
                message: 'تم جلب قائمة الثيمات بنجاح',
                data: [
                    'themes'        => $themes,
                    'current_theme' => $currentTheme,
                    'count'         => count($themes),
                ]
            );

        } catch (Throwable $e) {
            error_log('[SETTING_CONTROLLER] Themes failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة الثيمات');
        }
    }

    // =========================================================================
    // 6. تغيير الثيم (Update Theme)
    // =========================================================================

    /**
     * تغيير الثيم الحالي
     *
     * PUT /api/settings/theme
     *
     * Request Body (JSON):
     * {
     *   "theme": "dark"
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function updateTheme(): void
    {
        try {
            $currentUserId = $this->getCurrentUserId();
            $input = $this->getJsonInput();

            $themeName = $input['theme'] ?? null;

            if (empty($themeName)) {
                Response::badRequest('حقل theme مطلوب');
            }

            // التحقق من وجود الثيم
            $theme = $this->db->selectOne(
                "SELECT id, name, display_name FROM themes WHERE name = ? AND is_active = 1",
                [$themeName]
            );

            if (!$theme) {
                Response::notFound('الثيم غير موجود أو غير نشط');
            }

            // جلب القيمة القديمة
            $oldValue = $this->settingService->get('themes.default', 'dark');

            // تحديث الثيم
            $this->db->execute(
                "UPDATE settings SET `value` = ?, updated_at = NOW() WHERE `key` = 'themes.default'",
                [$themeName]
            );

            // مسح Cache
            $this->settingService->clearCache();

            // تسجيل العملية
            $this->auditService->log(
                userId: $currentUserId,
                action: 'SETTINGS_UPDATE',
                entityType: 'setting',
                entityId: null,
                oldValues: ['themes.default' => $oldValue],
                newValues: ['themes.default' => $themeName],
                description: "تم تغيير الثيم من '{$oldValue}' إلى '{$theme['display_name']}'",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: "تم تغيير الثيم إلى '{$theme['display_name']}' بنجاح",
                data: [
                    'theme'       => $themeName,
                    'theme_label' => $theme['display_name'],
                ]
            );

        } catch (Throwable $e) {
            error_log('[SETTING_CONTROLLER] UpdateTheme failed: ' . $e->getMessage());
            Response::internalError('فشل في تغيير الثيم: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 7. الفئات المتاحة (Categories)
    // =========================================================================

    /**
     * جلب قائمة فئات الإعدادات
     *
     * GET /api/settings/categories
     *
     * @return void يرسل استجابة JSON
     */
    public function categories(): void
    {
        try {
            $categories = [];

            foreach (self::SETTING_CATEGORIES as $key => $label) {
                // عد الإعدادات في كل فئة
                $count = $this->db->selectOne(
                    "SELECT COUNT(*) AS count FROM settings WHERE `key` LIKE ? AND is_active = 1",
                    ["{$key}.%"]
                );

                $categories[] = [
                    'key'   => $key,
                    'label' => $label,
                    'count' => (int) ($count['count'] ?? 0),
                ];
            }

            Response::success(
                message: 'تم جلب فئات الإعدادات بنجاح',
                data: [
                    'categories' => $categories,
                    'total'      => count($categories),
                ]
            );

        } catch (Throwable $e) {
            error_log('[SETTING_CONTROLLER] Categories failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب فئات الإعدادات');
        }
    }

    // =========================================================================
    // 8. إعادة تعيين الإعدادات (Reset) - Super Admin فقط
    // =========================================================================

    /**
     * إعادة تعيين الإعدادات إلى القيم الافتراضية
     *
     * POST /api/settings/reset
     *
     * Request Body (JSON):
     * {
     *   "confirm": true,
     *   "category": "auth" // اختياري - إعادة تعيين فئة معينة فقط
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function reset(): void
    {
        try {
            $currentUserId = $this->getCurrentUserId();

            // 1. التحقق من أن المستخدم هو Super Admin
            $this->requireSuperAdmin($currentUserId);

            // 2. قراءة البيانات
            $input = $this->getJsonInput();

            if (empty($input['confirm']) || $input['confirm'] !== true) {
                Response::badRequest(
                    'يجب تأكيد العملية بإرسال confirm: true. ' .
                    'تحذير: هذه العملية ستعيد الإعدادات إلى القيم الافتراضية!'
                );
            }

            $category = $input['category'] ?? null;

            // 3. التحقق من صحة الفئة
            if ($category !== null && !array_key_exists($category, self::SETTING_CATEGORIES)) {
                Response::badRequest('الفئة غير صالحة');
            }

            // 4. جلب الإعدادات المتأثرة
            $sql = "SELECT `key`, `value` FROM settings WHERE is_active = 1";
            $params = [];

            if ($category !== null) {
                $sql .= " AND `key` LIKE ?";
                $params[] = "{$category}.%";
            }

            $currentSettings = $this->db->select($sql, $params);
            $oldValues = [];
            foreach ($currentSettings as $setting) {
                $oldValues[$setting['key']] = $setting['value'];
            }

            // 5. تطبيق القيم الافتراضية
            $newValues = [];
            $resetCount = 0;

            foreach (self::DEFAULT_SETTINGS as $key => $defaultValue) {
                // فلترة حسب الفئة
                if ($category !== null) {
                    if (!str_starts_with($key, "{$category}.")) {
                        continue;
                    }
                }

                // حماية الإعدادات الحساسة
                if (in_array($key, self::PROTECTED_SETTINGS, true)) {
                    continue;
                }

                $this->db->execute(
                    "UPDATE settings SET `value` = ?, updated_at = NOW() WHERE `key` = ?",
                    [$defaultValue, $key]
                );

                $newValues[$key] = $defaultValue;
                $resetCount++;
            }

            // 6. مسح Cache
            $this->settingService->clearCache();

            // 7. تسجيل العملية
            $this->auditService->log(
                userId: $currentUserId,
                action: 'SETTINGS_RESET',
                entityType: 'setting',
                entityId: null,
                oldValues: $oldValues,
                newValues: $newValues,
                description: "تم إعادة تعيين {$resetCount} إعداد(ات) إلى القيم الافتراضية" .
                             ($category !== null ? " في الفئة '{$category}'" : ''),
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            $message = $category !== null
                ? "تم إعادة تعيين إعدادات الفئة '{$category}' إلى القيم الافتراضية"
                : 'تم إعادة تعيين كل الإعدادات إلى القيم الافتراضية';

            Response::success(
                message: $message,
                data: [
                    'reset_count' => $resetCount,
                    'category'    => $category,
                ]
            );

        } catch (Throwable $e) {
            error_log('[SETTING_CONTROLLER] Reset failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'مدير النظام فقط')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في إعادة تعيين الإعدادات: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Helper Methods - التحقق من القيم
    // =========================================================================

    /**
     * التحقق من صحة القيمة حسب النوع
     *
     * @param mixed $value القيمة للتحقق
     * @param string $type نوع الإعداد (text, number, boolean, json, email)
     * @return true|string true إذا كانت القيمة صالحة، أو رسالة خطأ
     */
    private function validateValueByType(mixed $value, string $type): true|string
    {
        switch ($type) {
            case 'text':
                if (!is_string($value) && !is_numeric($value)) {
                    return 'يجب أن تكون القيمة نصاً';
                }
                if (strlen((string) $value) > 5000) {
                    return 'القيمة طويلة جداً (الحد الأقصى 5000 حرف)';
                }
                return true;

            case 'number':
                if (!is_numeric($value)) {
                    return 'يجب أن تكون القيمة رقماً';
                }
                return true;

            case 'boolean':
                if (!in_array($value, [0, 1, true, false, '0', '1'], true)) {
                    return 'يجب أن تكون القيمة 0 أو 1';
                }
                return true;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return 'البريد الإلكتروني غير صالح';
                }
                return true;

            case 'json':
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                        return 'JSON غير صالح';
                    }
                } elseif (!is_array($value)) {
                    return 'يجب أن تكون القيمة JSON أو مصفوفة';
                }
                return true;

            default:
                return true;
        }
    }

    /**
     * تحويل القيمة حسب النوع
     *
     * @param mixed $value القيمة الأصلية
     * @param string $type نوع القيمة
     * @return mixed القيمة المحولة
     */
    private function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'number'  => is_numeric($value) ? (float) $value : $value,
            'boolean' => (bool) $value,
            'json'    => is_string($value) ? (json_decode($value, true) ?? $value) : $value,
            default   => $value,
        };
    }

    /**
     * التحقق من صحة بيانات الشركة
     *
     * @param array $data البيانات للتحقق
     * @return array مصفوفة الأخطاء
     */
    private function validateCompanyData(array $data): array
    {
        $errors = [];

        if (isset($data['name'])) {
            if (empty($data['name'])) {
                $errors['name'] = 'اسم الشركة مطلوب';
            } elseif (strlen($data['name']) > 200) {
                $errors['name'] = 'اسم الشركة يجب ألا يتجاوز 200 حرف';
            }
        }

        if (isset($data['address']) && strlen($data['address']) > 500) {
            $errors['address'] = 'العنوان يجب ألا يتجاوز 500 حرف';
        }

        if (isset($data['phone'])) {
            if (!empty($data['phone'])) {
                $cleaned = preg_replace('/[\s\-\(\)]+/', '', $data['phone']);
                if (strlen($cleaned) < 7 || strlen($cleaned) > 20) {
                    $errors['phone'] = 'رقم الهاتف غير صالح';
                }
            }
        }

        if (isset($data['email'])) {
            if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'البريد الإلكتروني غير صالح';
            }
        }

        if (isset($data['currency']) && strlen($data['currency']) > 10) {
            $errors['currency'] = 'رمز العملة يجب ألا يتجاوز 10 أحرف';
        }

        if (isset($data['currency_symbol']) && strlen($data['currency_symbol']) > 10) {
            $errors['currency_symbol'] = 'رمز العملة يجب ألا يتجاوز 10 أحرف';
        }

        return $errors;
    }

    // =========================================================================
    // Helper Methods - الأمان
    // =========================================================================

    /**
     * التحقق من أن المستخدم هو Super Admin
     */
    private function requireSuperAdmin(int $userId): void
    {
        $user = $this->db->selectOne(
            "SELECT id, role_id FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$user || (int) $user['role_id'] !== 1) {
            throw new Exception('هذه العملية متاحة لمدير النظام فقط (Super Admin)');
        }
    }

    /**
     * الحصول على رابط الشعار
     */
    private function getLogoUrl(?string $logo): ?string
    {
        if (empty($logo)) {
            return null;
        }

        // إذا كان الرابط مطلقاً
        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return $logo;
        }

        // إذا كان مساراً نسبياً
        return $logo;
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

        error_log('[SETTING_CONTROLLER] Current user ID not found');
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
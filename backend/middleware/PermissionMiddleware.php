<?php

/**
 * ================================================================
 * Logistox - Permission Middleware
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/middleware/PermissionMiddleware.php
 * الوظيفة: حارس البوابة الثاني - التحقق من صلاحيات المستخدم
 *
 * المسؤوليات:
 * 1. استقبال اسم الصلاحية المطلوبة من Router (مثل 'products.create')
 * 2. التحقق من أن AuthMiddleware قد مر أولاً
 * 3. البحث في role_permissions للتأكد من صلاحية الدور
 * 4. دعم Super Admin (role_id = 1 يملك كل الصلاحيات)
 * 5. دعم الصلاحيات البرية (Wildcard) مثل 'products.*'
 * 6. Cache للـ permissions لتقليل استعلامات DB
 * 7. تسجيل محاولات الوصول المرفوضة في audit_logs
 * 8. إرجاع 403 Forbidden مع رسالة واضحة
 *
 * ملاحظات هامة:
 * - يجب أن يسبقه AuthMiddleware في سلسلة الـ Middleware
 * - يعتمد على جداول: role_permissions, permissions, users, audit_logs
 * - يستخدم Cache على مستوى الطلب (Request-level) لتقليل الاستعلامات
 * - يدعم الصلاحيات البرية (Wildcard) للأدوار الخاصة
 * ================================================================
 */

declare(strict_types=1);

namespace App\Middleware;

use Core\Database;
use Core\Response;
use Throwable;
use Exception;

/**
 * Class PermissionMiddleware
 *
 * Middleware للتحقق من صلاحيات المستخدم بناءً على دوره
 */
class PermissionMiddleware
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var string اسم الصلاحية المطلوبة (مثل 'products.create')
     */
    private string $requiredPermission;

    /**
     * @var array Cache للـ permissions على مستوى الطلب
     *
     * يخزن الصلاحيات لكل دور لتجنب استعلامات DB المتكررة
     * في نفس الطلب (مثلاً إذا كان هناك عدة مسارات في نفس الـ request)
     *
     * البنية:
     * [
     *   'role_id' => [
     *     'products.view' => true,
     *     'products.create' => true,
     *     ...
     *   ]
     * ]
     */
    private static array $permissionsCache = [];

    /**
     * @var int معرف دور Super Admin (يملك كل الصلاحيات)
     *
     * هذا الدور محمي في قاعدة البيانات بـ is_system = 1
     * ولا يمكن حذفه أو تعديله
     */
    private const SUPER_ADMIN_ROLE_ID = 1;

    /**
     * @var array قائمة الصلاحيات البرية (Wildcard)
     *
     * هذه الصلاحيات تعني "كل الصلاحيات في هذا الموديول"
     * مثال: 'products.*' تعني products.view, products.create, products.update, products.delete
     */
    private const WILDCARD_PERMISSIONS = [
        'products.*',
        'warehouses.*',
        'categories.*',
        'units.*',
        'suppliers.*',
        'recipients.*',
        'receipts.*',
        'issues.*',
        'transfers.*',
        'returns.*',
        'counts.*',
        'users.*',
        'roles.*',
        'permissions.*',
        'settings.*',
        'backup.*',
        'reports.*',
        'audit.*',
        'dashboard.*',
        'stock.*',
    ];

    /**
     * Constructor
     *
     * @param string $requiredPermission اسم الصلاحية المطلوبة
     *                                   (مثل 'products.create' أو 'products.*')
     *
     * @throws Exception إذا كان اسم الصلاحية فارغاً أو غير صالح
     */
    public function __construct(string $requiredPermission)
    {
        if (empty($requiredPermission)) {
            throw new Exception('اسم الصلاحية المطلوبة لا يمكن أن يكون فارغاً');
        }

        // التحقق من صحة شكل اسم الصلاحية
        if (!$this->validatePermissionFormat($requiredPermission)) {
            throw new Exception(
                "اسم الصلاحية '{$requiredPermission}' غير صالح. " .
                "يجب أن يكون بالشكل: module.action (مثل: products.create)"
            );
        }

        $this->requiredPermission = $requiredPermission;

        try {
            $this->db = Database::getInstance();
        } catch (Throwable $e) {
            error_log('[PERMISSION_MIDDLEWARE] Database connection failed: ' . $e->getMessage());
            Response::internalError('فشل الاتصال بخدمة الصلاحيات');
        }
    }

    /**
     * نقطة الدخول الرئيسية للـ Middleware
     *
     * @param array $request مصفوفة الطلب القادمة من Router
     *                       يجب أن تحتوي على $request['user'] من AuthMiddleware
     * @return bool true للسماح بالمرور، أو إنهاء الطلب عبر Response
     */
    public function handle(array $request): bool
    {
        // 1. التحقق من أن AuthMiddleware قد مر أولاً
        if (!isset($request['user']) || !is_array($request['user'])) {
            error_log('[PERMISSION_MIDDLEWARE] AuthMiddleware must run before PermissionMiddleware');
            Response::internalError('خطأ في تكوين نظام الأمان. يرجى التواصل مع الدعم الفني.');
        }

        $user = $request['user'];

        // 2. التحقق من وجود بيانات المستخدم الأساسية
        if (empty($user['id']) || empty($user['role_id'])) {
            error_log('[PERMISSION_MIDDLEWARE] Invalid user data in request');
            Response::unauthorized('بيانات المستخدم غير مكتملة. يرجى تسجيل الدخول مرة أخرى.');
        }

        $userId = (int) $user['id'];
        $roleId = (int) $user['role_id'];
        $username = $user['username'] ?? 'unknown';

        // 3. Super Admin يتجاوز كل الصلاحيات
        if ($this->isSuperAdmin($roleId)) {
            return true;
        }

        // 4. التحقق من الصلاحية المطلوبة
        $hasPermission = $this->checkPermission($roleId, $this->requiredPermission);

        if (!$hasPermission) {
            // تسجيل محاولة الوصول المرفوضة
            $this->logAccessDenied($request, $userId, $username, $this->requiredPermission);

            // إرجاع رسالة واضحة
            $permissionDisplayName = $this->getPermissionDisplayName($this->requiredPermission);
            Response::forbidden(
                "لا تملك الصلاحية المطلوبة: '{$permissionDisplayName}'\n" .
                "يرجى التواصل مع مدير النظام للحصول على الصلاحية المناسبة."
            );
        }

        // 5. السماح بالمرور
        return true;
    }

    // =========================================================================
    // التحقق من الصلاحيات
    // =========================================================================

    /**
     * التحقق من أن الدور يملك الصلاحية المطلوبة
     *
     * @param int $roleId معرف الدور
     * @param string $permissionName اسم الصلاحية
     * @return bool true إذا كان الدور يملك الصلاحية
     *
     * يستخدم Cache لتقليل استعلامات DB
     */
    private function checkPermission(int $roleId, string $permissionName): bool
    {
        // 1. التحقق من Cache أولاً
        if (isset(self::$permissionsCache[$roleId][$permissionName])) {
            return self::$permissionsCache[$roleId][$permissionName];
        }

        // 2. جلب كل صلاحيات الدور من قاعدة البيانات
        $permissions = $this->loadRolePermissions($roleId);

        // 3. تخزينها في Cache
        self::$permissionsCache[$roleId] = $permissions;

        // 4. التحقق من الصلاحية المطلوبة
        return isset($permissions[$permissionName]) && $permissions[$permissionName] === true;
    }

    /**
     * جلب كل صلاحيات الدور من قاعدة البيانات
     *
     * @param int $roleId معرف الدور
     * @return array مصفوفة من الصلاحيات [permission_name => true]
     *
     * يدعم الصلاحيات البرية (Wildcard):
     * - إذا كان الدور يملك 'products.*'، فسيتم اعتبار أنه يملك:
     *   products.view, products.create, products.update, products.delete
     */
    private function loadRolePermissions(int $roleId): array
    {
        try {
            // جلب الصلاحيات المباشرة للدور
            $sql = "
                SELECT DISTINCT p.name, p.module
                FROM role_permissions rp
                INNER JOIN permissions p ON rp.permission_id = p.id
                WHERE rp.role_id = ?
            ";

            $rows = $this->db->select($sql, [$roleId]);

            $permissions = [];

            foreach ($rows as $row) {
                $permName = $row['name'];
                $permissions[$permName] = true;

                // إذا كانت الصلاحية برية (Wildcard)، نشرحها
                if ($this->isWildcardPermission($permName)) {
                    $expanded = $this->expandWildcardPermission($permName, $rows);
                    foreach ($expanded as $expandedPerm) {
                        $permissions[$expandedPerm] = true;
                    }
                }
            }

            return $permissions;

        } catch (Throwable $e) {
            error_log('[PERMISSION_MIDDLEWARE] Failed to load permissions: ' . $e->getMessage());
            Response::internalError('فشل في تحميل الصلاحيات. يرجى المحاولة مرة أخرى.');
        }
    }

    /**
     * التحقق من أن الصلاحية برية (Wildcard)
     *
     * @param string $permissionName اسم الصلاحية
     * @return bool true إذا كانت برية
     */
    private function isWildcardPermission(string $permissionName): bool
    {
        return str_ends_with($permissionName, '.*');
    }

    /**
     * شرح الصلاحية البرية إلى صلاحيات محددة
     *
     * مثال:
     * 'products.*' → ['products.view', 'products.create', 'products.update', 'products.delete']
     *
     * @param string $wildcardPermission الصلاحية البرية
     * @param array $allPermissions كل الصلاحيات المتاحة في النظام
     * @return array الصلاحيات المشروحة
     */
    private function expandWildcardPermission(string $wildcardPermission, array $allPermissions): array
    {
        $module = str_replace('.*', '', $wildcardPermission);
        $expanded = [];

        foreach ($allPermissions as $perm) {
            $permName = $perm['name'];
            $permModule = $perm['module'];

            // إذا كانت الصلاحية تنتمي لنفس الموديول
            if ($permModule === $module && !str_ends_with($permName, '.*')) {
                $expanded[] = $permName;
            }
        }

        return $expanded;
    }

    // =========================================================================
    // Super Admin
    // =========================================================================

    /**
     * التحقق من أن الدور هو Super Admin
     *
     * @param int $roleId معرف الدور
     * @return bool true إذا كان Super Admin
     *
     * Super Admin (role_id = 1) يملك كل الصلاحيات تلقائياً
     * دون الحاجة للتحقق من جدول role_permissions
     */
    private function isSuperAdmin(int $roleId): bool
    {
        return $roleId === self::SUPER_ADMIN_ROLE_ID;
    }

    // =========================================================================
    // التحقق من صحة الصلاحية
    // =========================================================================

    /**
     * التحقق من صحة شكل اسم الصلاحية
     *
     * @param string $permissionName اسم الصلاحية
     * @return bool true إذا كان الشكل صحيحاً
     *
     * الشكل المقبول:
     * - module.action (مثل: products.create)
     * - module.* (مثل: products.*)
     *
     * يجب أن يحتوي على:
     * - أحرف صغيرة (a-z)
     * - أرقام (0-9)
     * - نقطة (.) واحدة على الأقل
     * - شرطة سفلية (_) مسموحة
     */
    private function validatePermissionFormat(string $permissionName): bool
    {
        // يجب أن يحتوي على نقطة واحدة على الأقل
        if (!str_contains($permissionName, '.')) {
            return false;
        }

        // يجب أن يتبع النمط: module.action أو module.*
        return (bool) preg_match('/^[a-z0-9_]+\.[a-z0-9_*]+$/', $permissionName);
    }

    // =========================================================================
    // دوال مساعدة
    // =========================================================================

    /**
     * الحصول على اسم الصلاحية المعروض (بالعربية)
     *
     * @param string $permissionName اسم الصلاحية
     * @return string الاسم المعروض
     *
     * يحاول جلب الاسم من قاعدة البيانات، وإذا فشل يستخدم الاسم الإنجليزي
     */
    private function getPermissionDisplayName(string $permissionName): string
    {
        try {
            $sql = "SELECT display_name FROM permissions WHERE name = ? LIMIT 1";
            $result = $this->db->selectOne($sql, [$permissionName]);

            if ($result && !empty($result['display_name'])) {
                return $result['display_name'];
            }
        } catch (Throwable $e) {
            // فشل في جلب الاسم، نستخدم الاسم الإنجليزي
        }

        return $permissionName;
    }

    /**
     * تسجيل محاولة الوصول المرفوضة
     *
     * @param array $request بيانات الطلب
     * @param int $userId معرف المستخدم
     * @param string $username اسم المستخدم
     * @param string $permissionName الصلاحية المطلوبة
     *
     * يتم حفظ هذه المعلومات في:
     * 1. error.log (للمطورين)
     * 2. audit_logs (للمدققين)
     */
    private function logAccessDenied(
        array $request,
        int $userId,
        string $username,
        string $permissionName
    ): void {
        try {
            $ip = $request['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $method = $request['method'] ?? 'UNKNOWN';
            $path = $request['path'] ?? 'UNKNOWN';
            $userAgent = $request['user_agent'] ?? 'Unknown';

            // 1. تسجيل في error.log
            $message = sprintf(
                "[ACCESS DENIED] User: %s (ID: %d) | Permission: %s | Method: %s | Path: %s | IP: %s",
                $username,
                $userId,
                $permissionName,
                $method,
                $path,
                $ip
            );
            error_log($message);

            // 2. تسجيل في audit_logs
            $this->db->insert('audit_logs', [
                'user_id'     => $userId,
                'action'      => 'ACCESS_DENIED',
                'entity_type' => 'permission',
                'entity_id'   => null,
                'description' => sprintf(
                    "محاولة وصول مرفوضة. الصلاحية المطلوبة: %s | المسار: %s %s | IP: %s",
                    $permissionName,
                    $method,
                    $path,
                    $ip
                ),
                'old_values'  => null,
                'new_values'  => json_encode([
                    'permission' => $permissionName,
                    'method'     => $method,
                    'path'       => $path,
                    'ip'         => $ip,
                    'user_agent' => substr($userAgent, 0, 200),
                ], JSON_UNESCAPED_UNICODE),
                'ip_address'  => $ip,
                'user_agent'  => substr($userAgent, 0, 255),
            ]);

        } catch (Throwable $e) {
            // فشل التسجيل لا يجب أن يكسر الطلب
            error_log('[PERMISSION_MIDDLEWARE] Failed to log access denied: ' . $e->getMessage());
        }
    }

    /**
     * مسح Cache الصلاحيات
     *
     * يُستخدم عند تغيير صلاحيات دور معين
     * لضمان تحميل الصلاحيات الجديدة في الطلب التالي
     *
     * @param int|null $roleId معرف الدور (null لمسح كل الـ Cache)
     */
    public static function clearCache(?int $roleId = null): void
    {
        if ($roleId === null) {
            self::$permissionsCache = [];
        } else {
            unset(self::$permissionsCache[$roleId]);
        }
    }

    /**
     * الحصول على إحصائيات الـ Cache
     *
     * مفيد للتطوير والتصحيح
     *
     * @return array إحصائيات الـ Cache
     */
    public static function getCacheStats(): array
    {
        $totalRoles = count(self::$permissionsCache);
        $totalPermissions = 0;

        foreach (self::$permissionsCache as $roleId => $permissions) {
            $totalPermissions += count($permissions);
        }

        return [
            'cached_roles'       => $totalRoles,
            'cached_permissions' => $totalPermissions,
            'memory_usage_kb'    => round(memory_get_usage(true) / 1024, 2),
        ];
    }
}
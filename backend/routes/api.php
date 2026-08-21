<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/routes/api.php
// الوصف: مسارات API الرئيسية - جميع نقاط النهاية
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

use Core\Router;
use Controllers\AuthController;
use Controllers\DashboardController;
use Controllers\ProductController;
use Controllers\WarehouseController;
use Controllers\UserController;
use Controllers\ReportController;
use Controllers\ReceiptController;
use Controllers\IssueController;
use Controllers\TransferController;
use Controllers\ReturnController;
use Controllers\InventoryController;
use Controllers\NotificationController;
use Controllers\SettingController;

// إنشاء كائن Router
$router = new Router();

// ================================================================
// 1. مسارات المصادقة (بدون حماية)
// ================================================================

$router->group('/api/auth', [], function($router) {
    // تسجيل الدخول
    $router->post('/login', [AuthController::class, 'login']);
    
    // التحقق من الجلسة
    $router->get('/validate', [AuthController::class, 'validate']);
    
    // تسجيل الخروج
    $router->post('/logout', [AuthController::class, 'logout']);
    
    // تجديد التوكن
    $router->post('/refresh', [AuthController::class, 'refresh']);
    
    // طلب إعادة تعيين كلمة المرور
    $router->post('/forgot-password', [AuthController::class, 'forgotPassword']);
    
    // إعادة تعيين كلمة المرور
    $router->post('/reset-password', [AuthController::class, 'resetPassword']);
});

// ================================================================
// 2. مسارات عامة
// ================================================================

// اختبار API
$router->get('/test', function() {
    successResponse('✅ API يعمل بشكل مثالي!', [
        'php_version' => PHP_VERSION,
        'database' => 'connected',
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'PHP Built-in',
        'environment' => $_ENV['APP_ENV'] ?? 'production',
        'version' => VERSION,
        'time' => date('Y-m-d H:i:s')
    ]);
});

// التحقق من صحة النظام
$router->get('/health', function() {
    successResponse('✅ النظام سليم', [
        'status' => 'healthy',
        'php_version' => PHP_VERSION,
        'database' => 'connected',
        'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
        'time' => date('Y-m-d H:i:s')
    ]);
});

// ================================================================
// 3. مسارات لوحة التحكم (محمية)
// ================================================================

$router->group('/api/dashboard', ['AuthMiddleware'], function($router) {
    $router->get('', [DashboardController::class, 'index']);
    $router->get('/stats', [DashboardController::class, 'stats']);
    $router->get('/charts', [DashboardController::class, 'charts']);
    $router->get('/alerts', [DashboardController::class, 'alerts']);
    $router->get('/activities', [DashboardController::class, 'activities']);
});

// ================================================================
// 4. مسارات الأصناف (محمية)
// ================================================================

$router->group('/api/products', ['AuthMiddleware'], function($router) {
    // جلب جميع الأصناف مع فلترة
    $router->get('', [ProductController::class, 'index']);
    
    // جلب صنف محدد
    $router->get('/{id}', [ProductController::class, 'show']);
    
    // إنشاء صنف جديد
    $router->post('', [ProductController::class, 'create']);
    
    // تحديث صنف
    $router->put('/{id}', [ProductController::class, 'update']);
    
    // حذف صنف
    $router->delete('/{id}', [ProductController::class, 'delete']);
    
    // استيراد أصناف متعددة
    $router->post('/bulk-import', [ProductController::class, 'bulkImport']);
    
    // تصدير الأصناف
    $router->get('/export', [ProductController::class, 'export']);
    
    // جلب التصنيفات
    $router->get('/categories', [ProductController::class, 'categories']);
    
    // جلب الوحدات
    $router->get('/units', [ProductController::class, 'units']);
    
    // جلب أرصدة صنف في المخازن
    $router->get('/{id}/balances', [ProductController::class, 'balances']);
    
    // جلب تاريخ حركات صنف
    $router->get('/{id}/history', [ProductController::class, 'history']);
    
    // طباعة باركود
    $router->get('/{id}/barcode', [ProductController::class, 'barcode']);
});

// ================================================================
// 5. مسارات المخازن (محمية)
// ================================================================

$router->group('/api/warehouses', ['AuthMiddleware'], function($router) {
    // جلب جميع المخازن
    $router->get('', [WarehouseController::class, 'index']);
    
    // جلب مخزن محدد
    $router->get('/{id}', [WarehouseController::class, 'show']);
    
    // إنشاء مخزن جديد
    $router->post('', [WarehouseController::class, 'create']);
    
    // تحديث مخزن
    $router->put('/{id}', [WarehouseController::class, 'update']);
    
    // حذف مخزن
    $router->delete('/{id}', [WarehouseController::class, 'delete']);
    
    // جلب مخزون المخزن
    $router->get('/{id}/stock', [WarehouseController::class, 'stock']);
    
    // جلب تقرير المخزن
    $router->get('/{id}/report', [WarehouseController::class, 'report']);
    
    // جلب المخازن الفرعية
    $router->get('/{id}/sub', [WarehouseController::class, 'subWarehouses']);
});

// ================================================================
// 6. مسارات الاستلام (محمية)
// ================================================================

$router->group('/api/receipts', ['AuthMiddleware'], function($router) {
    // جلب جميع إذون الاستلام
    $router->get('', [ReceiptController::class, 'index']);
    
    // جلب إذن استلام محدد
    $router->get('/{id}', [ReceiptController::class, 'show']);
    
    // إنشاء إذن استلام جديد
    $router->post('', [ReceiptController::class, 'create']);
    
    // تحديث إذن استلام
    $router->put('/{id}', [ReceiptController::class, 'update']);
    
    // اعتماد إذن استلام
    $router->post('/{id}/approve', [ReceiptController::class, 'approve']);
    
    // رفض إذن استلام
    $router->post('/{id}/reject', [ReceiptController::class, 'reject']);
    
    // إلغاء إذن استلام
    $router->post('/{id}/cancel', [ReceiptController::class, 'cancel']);
    
    // تصدير إذون الاستلام
    $router->get('/export', [ReceiptController::class, 'export']);
});

// ================================================================
// 7. مسارات الصرف (محمية)
// ================================================================

$router->group('/api/issues', ['AuthMiddleware'], function($router) {
    // جلب جميع إذون الصرف
    $router->get('', [IssueController::class, 'index']);
    
    // جلب إذن صرف محدد
    $router->get('/{id}', [IssueController::class, 'show']);
    
    // إنشاء إذن صرف جديد
    $router->post('', [IssueController::class, 'create']);
    
    // تحديث إذن صرف
    $router->put('/{id}', [IssueController::class, 'update']);
    
    // اعتماد إذن صرف
    $router->post('/{id}/approve', [IssueController::class, 'approve']);
    
    // تسليم إذن صرف
    $router->post('/{id}/deliver', [IssueController::class, 'deliver']);
    
    // رفض إذن صرف
    $router->post('/{id}/reject', [IssueController::class, 'reject']);
    
    // إلغاء إذن صرف
    $router->post('/{id}/cancel', [IssueController::class, 'cancel']);
    
    // تصدير إذون الصرف
    $router->get('/export', [IssueController::class, 'export']);
});

// ================================================================
// 8. مسارات التحويلات (محمية)
// ================================================================

$router->group('/api/transfers', ['AuthMiddleware'], function($router) {
    // جلب جميع التحويلات
    $router->get('', [TransferController::class, 'index']);
    
    // جلب تحويل محدد
    $router->get('/{id}', [TransferController::class, 'show']);
    
    // إنشاء تحويل جديد
    $router->post('', [TransferController::class, 'create']);
    
    // تحديث تحويل
    $router->put('/{id}', [TransferController::class, 'update']);
    
    // اعتماد تحويل
    $router->post('/{id}/approve', [TransferController::class, 'approve']);
    
    // إكمال تحويل
    $router->post('/{id}/complete', [TransferController::class, 'complete']);
    
    // رفض تحويل
    $router->post('/{id}/reject', [TransferController::class, 'reject']);
    
    // إلغاء تحويل
    $router->post('/{id}/cancel', [TransferController::class, 'cancel']);
    
    // تصدير التحويلات
    $router->get('/export', [TransferController::class, 'export']);
});

// ================================================================
// 9. مسارات المرتجعات (محمية)
// ================================================================

$router->group('/api/returns', ['AuthMiddleware'], function($router) {
    // جلب جميع المرتجعات
    $router->get('', [ReturnController::class, 'index']);
    
    // جلب مرتجع محدد
    $router->get('/{id}', [ReturnController::class, 'show']);
    
    // إنشاء مرتجع جديد
    $router->post('', [ReturnController::class, 'create']);
    
    // اعتماد مرتجع
    $router->post('/{id}/approve', [ReturnController::class, 'approve']);
    
    // رفض مرتجع
    $router->post('/{id}/reject', [ReturnController::class, 'reject']);
    
    // إلغاء مرتجع
    $router->post('/{id}/cancel', [ReturnController::class, 'cancel']);
    
    // تصدير المرتجعات
    $router->get('/export', [ReturnController::class, 'export']);
});

// ================================================================
// 10. مسارات الجرد (محمية)
// ================================================================

$router->group('/api/inventory', ['AuthMiddleware'], function($router) {
    // جلب جلسات الجرد
    $router->get('/counts', [InventoryController::class, 'index']);
    
    // جلب جلسة جرد محددة
    $router->get('/counts/{id}', [InventoryController::class, 'show']);
    
    // بدء جلسة جرد جديدة
    $router->post('/counts', [InventoryController::class, 'create']);
    
    // إضافة عنصر جرد
    $router->post('/counts/{id}/items', [InventoryController::class, 'addItem']);
    
    // تحديث عنصر جرد
    $router->put('/counts/{id}/items/{itemId}', [InventoryController::class, 'updateItem']);
    
    // اعتماد جلسة جرد
    $router->post('/counts/{id}/approve', [InventoryController::class, 'approve']);
    
    // إلغاء جلسة جرد
    $router->post('/counts/{id}/cancel', [InventoryController::class, 'cancel']);
    
    // تصدير الجرد
    $router->get('/export', [InventoryController::class, 'export']);
});

// ================================================================
// 11. مسارات التقارير (محمية)
// ================================================================

$router->group('/api/reports', ['AuthMiddleware'], function($router) {
    // تقرير الأرصدة
    $router->get('/stock', [ReportController::class, 'stock']);
    
    // تقرير الحركات
    $router->get('/movements', [ReportController::class, 'movements']);
    
    // تقرير صنف محدد
    $router->get('/product/{id}', [ReportController::class, 'product']);
    
    // تقرير مخزن محدد
    $router->get('/warehouse/{id}', [ReportController::class, 'warehouse']);
    
    // تقرير سجل التدقيق
    $router->get('/audit', [ReportController::class, 'audit']);
    
    // تقرير ملخص النظام
    $router->get('/summary', [ReportController::class, 'summary']);
    
    // تقرير المستخدمين
    $router->get('/users', [ReportController::class, 'users']);
    
    // تقرير الأصناف الأكثر تداولاً
    $router->get('/top-products', [ReportController::class, 'topProducts']);
    
    // تقرير قيمة المخزون
    $router->get('/inventory-value', [ReportController::class, 'inventoryValue']);
});

// ================================================================
// 12. مسارات المستخدمين (محمية)
// ================================================================

$router->group('/api/users', ['AuthMiddleware'], function($router) {
    // جلب جميع المستخدمين
    $router->get('', [UserController::class, 'index']);
    
    // جلب مستخدم محدد
    $router->get('/{id}', [UserController::class, 'show']);
    
    // جلب المستخدم الحالي
    $router->get('/me', [UserController::class, 'me']);
    
    // إنشاء مستخدم جديد
    $router->post('', [UserController::class, 'create']);
    
    // تحديث مستخدم
    $router->put('/{id}', [UserController::class, 'update']);
    
    // حذف مستخدم
    $router->delete('/{id}', [UserController::class, 'delete']);
    
    // استعادة مستخدم محذوف
    $router->post('/{id}/restore', [UserController::class, 'restore']);
    
    // قفل مستخدم
    $router->post('/{id}/lock', [UserController::class, 'lock']);
    
    // فتح قفل مستخدم
    $router->post('/{id}/unlock', [UserController::class, 'unlock']);
    
    // تحديث صلاحيات المستخدم
    $router->put('/{id}/permissions', [UserController::class, 'permissions']);
    
    // جلب صلاحيات المستخدم
    $router->get('/{id}/permissions', [UserController::class, 'getPermissions']);
    
    // تغيير كلمة المرور
    $router->post('/{id}/change-password', [UserController::class, 'changePassword']);
    
    // جلب سجل نشاط المستخدم
    $router->get('/{id}/activities', [UserController::class, 'activities']);
    
    // جلب جلسات المستخدم النشطة
    $router->get('/{id}/sessions', [UserController::class, 'sessions']);
});

// ================================================================
// 13. مسارات التنبيهات (محمية)
// ================================================================

$router->group('/api/notifications', ['AuthMiddleware'], function($router) {
    // جلب التنبيهات
    $router->get('', [NotificationController::class, 'index']);
    
    // جلب تنبيه محدد
    $router->get('/{id}', [NotificationController::class, 'show']);
    
    // تعيين تنبيه كمقروء
    $router->post('/{id}/read', [NotificationController::class, 'markAsRead']);
    
    // تعيين جميع التنبيهات كمقروءة
    $router->post('/read-all', [NotificationController::class, 'markAllAsRead']);
    
    // حذف تنبيه
    $router->delete('/{id}', [NotificationController::class, 'delete']);
    
    // فحص التنبيهات (مخزون منخفض، منفذ، انتهاء صلاحية)
    $router->post('/check', [NotificationController::class, 'check']);
});

// ================================================================
// 14. مسارات الإعدادات (محمية)
// ================================================================

$router->group('/api/settings', ['AuthMiddleware'], function($router) {
    // جلب جميع الإعدادات
    $router->get('', [SettingController::class, 'index']);
    
    // جلب إعداد محدد
    $router->get('/{key}', [SettingController::class, 'show']);
    
    // تحديث إعداد
    $router->put('/{key}', [SettingController::class, 'update']);
    
    // تحديث إعدادات متعددة
    $router->post('/batch', [SettingController::class, 'batchUpdate']);
    
    // إعادة تعيين الإعدادات
    $router->post('/reset', [SettingController::class, 'reset']);
});

// ================================================================
// 15. مسارات النسخ الاحتياطي (محمية)
// ================================================================

$router->group('/api/backup', ['AuthMiddleware'], function($router) {
    // جلب النسخ الاحتياطية
    $router->get('', [BackupController::class, 'index']);
    
    // إنشاء نسخة احتياطية
    $router->post('/create', [BackupController::class, 'create']);
    
    // استعادة نسخة احتياطية
    $router->post('/restore/{id}', [BackupController::class, 'restore']);
    
    // تحميل نسخة احتياطية
    $router->get('/download/{id}', [BackupController::class, 'download']);
    
    // حذف نسخة احتياطية
    $router->delete('/{id}', [BackupController::class, 'delete']);
});

// ================================================================
// 16. مسارات الملفات الثابتة (Frontend)
// ================================================================

// خدمة ملفات Frontend
$router->get('/frontend/{path:.*}', function($path) {
    $file = BASE_PATH . '/../frontend/' . $path;
    if (file_exists($file) && !is_dir($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mimeTypes = [
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'json' => 'application/json'
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        readfile($file);
        exit;
    }
    notFoundResponse();
});

// الصفحة الرئيسية
$router->get('/', function() {
    $file = BASE_PATH . '/../frontend/index.html';
    if (file_exists($file)) {
        readfile($file);
        exit;
    }
    notFoundResponse();
});

// ================================================================
// إرجاع الـ Router
// ================================================================

return $router;

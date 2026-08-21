<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/public/index.php
// الوصف: مدخل API الرئيسي - نقطة الدخول الوحيدة للنظام
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

// ================================================================
// 1. إعدادات PHP الأساسية
// ================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('date.timezone', 'Asia/Riyadh');

// ================================================================
// 2. تعريف الثوابت
// ================================================================

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', __DIR__);
define('DS', DIRECTORY_SEPARATOR);
define('START_TIME', microtime(true));
define('VERSION', '5.0.0');

// ================================================================
// 3. تحميل ملف .env
// ================================================================

$envFile = BASE_PATH . DS . '.env';
if (file_exists($envFile)) {
    $lines = file($envFile);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $_ENV[$key] = trim($value);
        putenv("$key=" . trim($value));
    }
}

// ================================================================
// 4. تحميل Autoloader
// ================================================================

spl_autoload_register(function ($class) {
    $prefix = '';
    $base_dir = BASE_PATH . DS;
    
    // التحقق من وجود Namespace
    if (strpos($class, '\\') !== false) {
        $parts = explode('\\', $class);
        $className = array_pop($parts);
        $namespace = implode(DS, $parts);
        
        // محاولة البحث في المجلدات المختلفة
        $directories = ['core', 'controllers', 'models', 'middleware', 'services', 'helpers', 'validators', 'repositories'];
        foreach ($directories as $dir) {
            $file = $base_dir . $dir . DS . $className . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    } else {
        // بدون Namespace
        $directories = ['core', 'controllers', 'models', 'middleware', 'services', 'helpers', 'validators', 'repositories'];
        foreach ($directories as $dir) {
            $file = $base_dir . $dir . DS . $class . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// ================================================================
// 5. دوال مساعدة عامة
// ================================================================

/**
 * إرسال استجابة JSON متقدمة
 */
function jsonResponse($success, $message, $data = null, $code = 200, $meta = null, $errors = null) {
    header('Content-Type: application/json');
    http_response_code($code);
    
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => VERSION,
        'execution_time' => round((microtime(true) - START_TIME) * 1000, 2) . 'ms'
    ];
    
    if ($data !== null) $response['data'] = $data;
    if ($meta !== null) $response['meta'] = $meta;
    if ($errors !== null) $response['errors'] = $errors;
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * استجابة نجاح
 */
function successResponse($message, $data = null, $meta = null) {
    jsonResponse(true, $message, $data, 200, $meta);
}

/**
 * استجابة خطأ
 */
function errorResponse($message, $code = 400, $data = null, $errors = null) {
    jsonResponse(false, $message, $data, $code, null, $errors);
}

/**
 * استجابة 404
 */
function notFoundResponse($message = 'المسار غير موجود') {
    jsonResponse(false, $message, null, 404);
}

/**
 * استجابة 401 (غير مصرح)
 */
function unauthorizedResponse($message = 'غير مصرح') {
    jsonResponse(false, $message, null, 401);
}

/**
 * استجابة 403 (ممنوع)
 */
function forbiddenResponse($message = 'ليس لديك صلاحية') {
    jsonResponse(false, $message, null, 403);
}

/**
 * استجابة 422 (تحقق من البيانات)
 */
function validationErrorResponse($errors, $message = 'بيانات غير صالحة') {
    jsonResponse(false, $message, null, 422, null, $errors);
}

/**
 * تسجيل الأخطاء
 */
function logError($message, $context = []) {
    $logFile = BASE_PATH . DS . 'logs' . DS . 'error.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $log = date('Y-m-d H:i:s') . ' - ' . $message . ' - ' . json_encode($context, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    error_log($log, 3, $logFile);
}

/**
 * تسجيل المعلومات
 */
function logInfo($message, $context = []) {
    if (!($_ENV['APP_DEBUG'] ?? false)) {
        return;
    }
    
    $logFile = BASE_PATH . DS . 'logs' . DS . 'info.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $log = date('Y-m-d H:i:s') . ' - ' . $message . ' - ' . json_encode($context, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    error_log($log, 3, $logFile);
}

/**
 * الحصول على IP العميل
 */
function getClientIP() {
    $ip = '0.0.0.0';
    
    if (isset($_SERVER['HTTP_CLIENT_IP']) && !empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * التحقق من طلب AJAX
 */
function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// ================================================================
// 6. معالجة CORS
// ================================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With, Origin');
header('Access-Control-Expose-Headers: Authorization');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ================================================================
// 7. معالجة الأخطاء
// ================================================================

// معالجة الأخطاء غير المتوقعة
set_exception_handler(function($e) {
    logError($e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    if ($_ENV['APP_DEBUG'] ?? false) {
        errorResponse($e->getMessage(), 500, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    } else {
        errorResponse('حدث خطأ داخلي في الخادم', 500);
    }
});

// معالجة الأخطاء العادية
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logError($errstr, ['file' => $errfile, 'line' => $errline, 'code' => $errno]);
    
    if ($_ENV['APP_DEBUG'] ?? false) {
        errorResponse($errstr, 500, ['file' => $errfile, 'line' => $errline]);
    }
    
    return true;
});

// ================================================================
// 8. التوجيه
// ================================================================

try {
    // الحصول على المسار والطريقة
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];
    
    // إزالة base path
    $basePath = $_ENV['BASE_PATH'] ?? '/inventory-system';
    if (!empty($basePath) && strpos($path, $basePath) === 0) {
        $path = substr($path, strlen($basePath));
        $path = '/' . ltrim($path, '/');
    }
    
    logInfo("Request: {$method} {$path}", [
        'ip' => getClientIP(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    // تحميل ملف التوجيه
    $routerFile = BASE_PATH . DS . 'routes' . DS . 'api.php';
    
    if (!file_exists($routerFile)) {
        throw new Exception('ملف التوجيه غير موجود: ' . $routerFile);
    }
    
    $router = require_once $routerFile;
    
    if (!isset($router) || !is_object($router)) {
        throw new Exception('ملف التوجيه يجب أن يعيد كائن Router');
    }
    
    // تنفيذ التوجيه
    $router->dispatch($method, $path);
    
} catch (Exception $e) {
    logError('Routing error: ' . $e->getMessage(), [
        'path' => $path ?? 'unknown',
        'method' => $method ?? 'unknown'
    ]);
    
    if ($_ENV['APP_DEBUG'] ?? false) {
        errorResponse($e->getMessage(), 500);
    } else {
        errorResponse('حدث خطأ في معالجة الطلب', 500);
    }
}

// ================================================================
// 9. إحصائيات الأداء
// ================================================================

if ($_ENV['APP_DEBUG'] ?? false) {
    $endTime = microtime(true);
    $executionTime = round(($endTime - START_TIME) * 1000, 2);
    $memoryUsage = memory_get_peak_usage(true) / 1024 / 1024;
    
    logInfo("Performance", [
        'execution_time' => $executionTime . 'ms',
        'memory_usage' => round($memoryUsage, 2) . 'MB',
        'path' => $path ?? 'unknown',
        'method' => $method ?? 'unknown'
    ]);
}

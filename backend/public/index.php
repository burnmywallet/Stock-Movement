<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/public/index.php
// الوصف: مدخل API الرئيسي - نقطة الدخول الوحيدة للنظام
// التاريخ: 2026-08-22
// ================================================================

// ================================================================
// 1. إعدادات PHP الأساسية
// ================================================================

error_reporting(E_ALL);

// ✅ تم الإصلاح: إخفاء الأخطاء في Production
$isProduction = ($_ENV['APP_ENV'] ?? 'production') === 'production';
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

ini_set('memory_limit', '256M');
ini_set('max_execution_time', '300');
ini_set('max_input_time', '300');
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');

// ✅ تم الإصلاح: Timezone من .env
$timezone = $_ENV['APP_TIMEZONE'] ?? $_ENV['timezone'] ?? 'Asia/Riyadh';
date_default_timezone_set($timezone);

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
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// ================================================================
// 4. تحميل Autoloader
// ================================================================

spl_autoload_register(function ($class) {
    $base_dir = BASE_PATH . DS;
    
    // التحقق من وجود Namespace
    if (strpos($class, '\\') !== false) {
        $parts = explode('\\', $class);
        $className = array_pop($parts);
        $namespace = implode(DS, $parts);
        
        $directories = ['core', 'controllers', 'models', 'middleware', 'services', 'helpers', 'validators', 'repositories'];
        foreach ($directories as $dir) {
            $file = $base_dir . $dir . DS . $className . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    } else {
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

// ================================================================
// 6. معالجة CORS (مُحدّث)
// ================================================================

// ✅ تم الإصلاح: استخدام الإعدادات من .env
$allowedOrigins = [];
$corsOrigins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
if (!empty($corsOrigins)) {
    $allowedOrigins = array_map('trim', explode(',', $corsOrigins));
}

// إضافة النطاقات المحلية دائماً للتطوير
$allowedOrigins[] = 'http://localhost';
$allowedOrigins[] = 'http://localhost:8080';
$allowedOrigins[] = 'http://localhost:8081';
$allowedOrigins[] = 'http://127.0.0.1';
$allowedOrigins[] = 'http://127.0.0.1:8080';

// إزالة التكرارات
$allowedOrigins = array_unique($allowedOrigins);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// التحقق من Origin
if (in_array('*', $allowedOrigins)) {
    header('Access-Control-Allow-Origin: *');
} elseif (!empty($origin) && in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    // في الإنتاج، منع الطلبات من نطاقات غير مسموحة
    $isProduction = ($_ENV['APP_ENV'] ?? 'production') === 'production';
    if (!$isProduction && !empty($origin)) {
        // في التطوير، نسمح مؤقتاً
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('X-CORS-Warning: Development mode - origin auto-allowed');
    }
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With, Origin, X-CSRF-Token');
header('Access-Control-Expose-Headers: Authorization, X-Session-Id, X-User-Id');
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
    
    $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    if ($isDebug) {
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
    
    $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    if ($isDebug) {
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
    
    // ✅ تم الإصلاح: معالجة Base Path ديناميكياً
    $basePath = $_ENV['BASE_PATH'] ?? '';
    if (!empty($basePath) && strpos($path, $basePath) === 0) {
        $path = substr($path, strlen($basePath));
        $path = '/' . ltrim($path, '/');
    }
    
    // ✅ تم الإصلاح: دعم المسارات بدون base path
    if (empty($path) || $path === '/') {
        $path = '/';
    }
    
    // معالجة الطلبات المباشرة للملفات الثابتة
    if (strpos($path, '/frontend/') === 0) {
        $file = BASE_PATH . '/../frontend/' . substr($path, 10);
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
                'json' => 'application/json',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf' => 'font/ttf'
            ];
            header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
            header('Cache-Control: public, max-age=86400');
            readfile($file);
            exit;
        }
    }
    
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
    
    $isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    if ($isDebug) {
        errorResponse($e->getMessage(), 500);
    } else {
        errorResponse('حدث خطأ في معالجة الطلب', 500);
    }
}

// ================================================================
// انتهى الملف
// ================================================================

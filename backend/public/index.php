<?php

/**
 * ============================================================================
 * Logistox / Stock-Movement v5.0
 * Advanced Inventory Management System
 * ============================================================================
 *
 * File: backend/public/index.php
 * Purpose: API Front Controller (نقطة الدخول الوحيدة)
 *
 * Responsibilities:
 *     - Load environment configuration (.env)
 *     - Configure PHP error reporting & timezone
 *     - Configure CORS & Security Headers
 *     - Register PSR-4 Autoloader
 *     - Initialize Router & Middleware
 *     - Load API routes
 *     - Dispatch the request
 *
 * IMPORTANT:
 *     هذا الملف لا يحتوي على Business Logic.
 *     لا يحتوي على بيانات وهمية أو Hardcoded values.
 * ============================================================================
 */

declare(strict_types=1);
// ============================================================================
// 1. PATHS & CONSTANTS
// ============================================================================
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', __DIR__);
define('PROJECT_ROOT', dirname(BASE_PATH));

// ============================================================================
// 2. LOAD ENVIRONMENT (.env)
// ============================================================================
$envFile = PROJECT_ROOT . '/.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
} else {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'ملف .env غير موجود. يرجى إنشاؤه بناءً على .env.example',
        'code' => 'ENV_FILE_MISSING'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
// ============================================================================
// 2.5. FIX REQUEST URI FOR SUBDIRECTORIES (إصلاح مسار المشاريع الفرعية)
// ============================================================================
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

// الحصول على مسار المجلد من SCRIPT_NAME
$scriptDir = dirname($scriptName);

// إزالة مسار المجلد الفرعي من REQUEST_URI
// مثال: /inventory-system/backend/public/api/auth/login → /api/auth/login
if ($scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($requestUri, $scriptDir)) {
    $_SERVER['REQUEST_URI'] = substr($requestUri, strlen($scriptDir));
}
// ============================================================================
// 3. PHP CONFIGURATION
// ============================================================================
$appDebug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

// إعدادات الأخطاء
error_reporting($appDebug ? E_ALL : 0);
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('display_startup_errors', $appDebug ? '1' : '0');
ini_set('log_errors', '1');

// المنطقة الزمنية
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Africa/Cairo');

// ============================================================================
// 4. SECURITY & CORS HEADERS
// ============================================================================
header('Content-Type: application/json; charset=UTF-8');

$allowedOrigins = getenv('CORS_ALLOWED_ORIGINS') ?: '*';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($allowedOrigins === '*' || in_array($origin, array_map('trim', explode(',', $allowedOrigins)))) {
    header("Access-Control-Allow-Origin: " . ($allowedOrigins === '*' ? '*' : $origin));
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
header('Access-Control-Max-Age: 86400');

// معالجة طلبات OPTIONS (Preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================================================
// 5. AUTOLOADER (PSR-4)
// ============================================================================
spl_autoload_register(function (string $class): void {
    $prefixes = [
        'App\\Controllers\\' => BASE_PATH . '/controllers/',
        'App\\Services\\'    => BASE_PATH . '/services/',
        'App\\Middleware\\'  => BASE_PATH . '/middleware/',
        'App\\Helpers\\'     => BASE_PATH . '/helpers/',
        'App\\Exceptions\\'  => BASE_PATH . '/exceptions/',
        'Core\\'             => BASE_PATH . '/core/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relativeClass = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
            
            if (is_file($file) && is_readable($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// ============================================================================
// 6. ROUTER INITIALIZATION
// ============================================================================
try {
    // تهيئة الـ Router مع مسار API الأساسي
    $router = new Core\Router(getenv('API_PREFIX') ?: '/api');

    // تسجيل الـ Middleware Aliases
    $router->addMiddleware('auth', App\Middleware\AuthMiddleware::class);
    $router->addMiddleware('permission', App\Middleware\PermissionMiddleware::class);

    // ========================================================================
    // 7. LOAD ROUTES
    // ========================================================================
    $routesFile = BASE_PATH . '/routes/api.php';
    if (!is_file($routesFile) || !is_readable($routesFile)) {
        throw new RuntimeException('ملف مسارات API غير موجود أو غير قابل للقراءة.');
    }
    
    require $routesFile;

    // ========================================================================
    // 8. DISPATCH REQUEST
    // ========================================================================
    $router->dispatch();

} catch (Throwable $e) {
    // ========================================================================
    // 9. GLOBAL ERROR HANDLER
    // ========================================================================
    error_log(sprintf(
        '[FATAL ERROR] %s in %s:%d | Trace: %s',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    http_response_code(500);
    
    $response = [
        'success'   => false,
        'message'   => $appDebug ? $e->getMessage() : 'حدث خطأ داخلي غير متوقع في الخادم',
        'code'      => 'INTERNAL_SERVER_ERROR',
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    if ($appDebug) {
        $response['file'] = $e->getFile();
        $response['line'] = $e->getLine();
        $response['trace'] = $e->getTraceAsString();
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
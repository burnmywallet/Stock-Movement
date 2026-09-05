<?php
/**
 * ============================================================================
 * Logistox / Stock-Movement
 * Advanced Inventory Management System
 * ============================================================================
 *
 * File:
 *     backend/public/index.php
 *
 * Purpose:
 *     API Front Controller
 *
 * Responsibilities:
 *     - Load environment configuration
 *     - Configure PHP/API security
 *     - Configure CORS
 *     - Register project autoloading
 *     - Load Router / Response
 *     - Load API routes
 *     - Dispatch the request
 *
 * IMPORTANT:
 *     هذا الملف لا يحتوي على Business Logic.
 *     لا يحتوي على بيانات وهمية.
 *     لا يحتوي على Login تجريبي.
 *     لا يحتوي على بيانات أصناف ثابتة.
 *
 * Architecture:
 *
 *     Frontend
 *         |
 *         v
 *     /api/*
 *         |
 *         v
 *     backend/public/index.php
 *         |
 *         v
 *     backend/routes/api.php
 *         |
 *         v
 *     Controllers / Services
 *         |
 *         v
 *     Models
 *         |
 *         v
 *     MySQL
 *
 * ============================================================================
 */

declare(strict_types=1);


// ============================================================================
// 1. PATHS
// ============================================================================

/**
 * backend/
 */
const BACKEND_PATH = __DIR__ . '/..';

/**
 * project root
 *
 * inventory-system/
 */
const PROJECT_ROOT = __DIR__ . '/../..';

/**
 * API base path
 */
const API_BASE_PATH = '/api';


// ============================================================================
// 2. LOAD ENVIRONMENT
// ============================================================================

/**
 * Load .env manually.
 *
 * في المرحلة الحالية المشروع لا يعتمد على Composer dotenv.
 * لذلك يتم تحميل المتغيرات من .env بطريقة بسيطة وآمنة نسبيًا.
 *
 * ملاحظة:
 * لا نطبع أي قيمة من قيم البيئة للمستخدم.
 */
function loadEnvironment(string $envFile): void
{
    if (!is_file($envFile) || !is_readable($envFile)) {
        return;
    }

    $lines = file(
        $envFile,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {

        $line = trim($line);

        // Skip empty lines
        if ($line === '') {
            continue;
        }

        // Skip comments
        if (str_starts_with($line, '#')) {
            continue;
        }

        // Ignore invalid lines
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        /**
         * إزالة quotation marks البسيطة.
         *
         * مثال:
         *
         * APP_ENV="production"
         *
         * تصبح:
         *
         * production
         */
        if (
            strlen($value) >= 2 &&
            (
                ($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
                ($value[0] === "'" && $value[strlen($value) - 1] === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        /**
         * لا نستبدل Environment Variable موجود بالفعل.
         *
         * هذا يسمح بتجاوز .env من إعدادات النظام.
         */
        if (getenv($key) !== false) {
            continue;
        }

        putenv($key . '=' . $value);

        $_ENV[$key] = $value;
    }
}


$envFile = PROJECT_ROOT . '/.env';

loadEnvironment($envFile);


// ============================================================================
// 3. APPLICATION CONFIGURATION
// ============================================================================

$appDebug = filter_var(
    getenv('APP_DEBUG') ?: 'false',
    FILTER_VALIDATE_BOOLEAN
);

$appTimezone = getenv('APP_TIMEZONE') ?: 'Africa/Cairo';

$appVersion = getenv('APP_VERSION') ?: '5.0.0';


// ============================================================================
// 4. PHP ERROR CONFIGURATION
// ============================================================================

/**
 * تسجيل كل الأخطاء.
 *
 * في Production:
 *     لا يتم عرض الخطأ للمستخدم.
 *
 * في Development:
 *     يمكن السماح بعرض الأخطاء إذا كان APP_DEBUG=true.
 */
error_reporting(E_ALL);

ini_set(
    'display_errors',
    $appDebug ? '1' : '0'
);

ini_set(
    'display_startup_errors',
    $appDebug ? '1' : '0'
);

ini_set(
    'log_errors',
    '1'
);


// ============================================================================
// 5. TIMEZONE
// ============================================================================

if (
    in_array(
        $appTimezone,
        timezone_identifiers_list(),
        true
    )
) {
    date_default_timezone_set($appTimezone);
} else {
    date_default_timezone_set('Africa/Cairo');
}


// ============================================================================
// 6. RESPONSE HEADERS
// ============================================================================

header(
    'Content-Type: application/json; charset=UTF-8'
);

header(
    'X-Content-Type-Options: nosniff'
);

header(
    'X-Frame-Options: SAMEORIGIN'
);

header(
    'Referrer-Policy: strict-origin-when-cross-origin'
);

header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

header(
    'Pragma: no-cache'
);


// ============================================================================
// 7. CORS
// ============================================================================

/**
 * Allowed origins are read from:
 *
 * CORS_ALLOWED_ORIGINS
 *
 * Example:
 *
 * CORS_ALLOWED_ORIGINS=http://192.168.1.8,http://localhost
 */
$origin = trim(
    $_SERVER['HTTP_ORIGIN'] ?? ''
);

$allowedOriginsRaw = getenv(
    'CORS_ALLOWED_ORIGINS'
) ?: '';

$allowedOrigins = [];

if ($allowedOriginsRaw !== '') {

    $allowedOrigins = array_values(
        array_filter(
            array_map(
                'trim',
                explode(',', $allowedOriginsRaw)
            ),
            static function (string $value): bool {
                return $value !== '';
            }
        )
    );
}


/**
 * لا نستخدم:
 *
 * Access-Control-Allow-Origin: *
 *
 * لأن المشروع يستخدم Authentication / Sessions.
 */
if (
    $origin !== '' &&
    in_array($origin, $allowedOrigins, true)
) {

    header(
        'Access-Control-Allow-Origin: ' . $origin
    );

    header(
        'Access-Control-Allow-Credentials: true'
    );

    header(
        'Vary: Origin'
    );
}


header(
    'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS'
);

header(
    'Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With'
);

header(
    'Access-Control-Max-Age: 86400'
);


// ============================================================================
// 8. OPTIONS / PREFLIGHT
// ============================================================================

$requestMethod = strtoupper(
    $_SERVER['REQUEST_METHOD'] ?? 'GET'
);

if ($requestMethod === 'OPTIONS') {

    http_response_code(204);

    exit;
}


// ============================================================================
// 9. HTTP METHOD VALIDATION
// ============================================================================

$allowedMethods = [
    'GET',
    'POST',
    'PUT',
    'PATCH',
    'DELETE',
    'HEAD',
];

if (
    !in_array(
        $requestMethod,
        $allowedMethods,
        true
    )
) {

    apiError(
        'طريقة الطلب غير مسموحة',
        'METHOD_NOT_ALLOWED',
        405
    );
}


// ============================================================================
// 10. AUTOLOADER
// ============================================================================

/**
 * Simple PSR-like autoloader.
 *
 * Current namespace:
 *
 *     Core\
 *
 * Example:
 *
 *     Core\Database
 *
 * will load:
 *
 *     backend/core/Database.php
 */
spl_autoload_register(
    static function (string $class): void {

        $prefix = 'Core\\';

        if (
            strncmp(
                $class,
                $prefix,
                strlen($prefix)
            ) !== 0
        ) {
            return;
        }

        $relativeClass = substr(
            $class,
            strlen($prefix)
        );

        $file = BACKEND_PATH .
            '/core/' .
            str_replace(
                '\\',
                '/',
                $relativeClass
            ) .
            '.php';

        if (
            is_file($file) &&
            is_readable($file)
        ) {
            require_once $file;
        }
    }
);


// ============================================================================
// 11. LOAD REQUIRED CORE CLASSES
// ============================================================================

$responseClass = BACKEND_PATH . '/core/Response.php';
$routerClass   = BACKEND_PATH . '/core/Router.php';

if (
    !is_file($responseClass) ||
    !is_readable($responseClass)
) {

    apiError(
        'ملف Response غير موجود',
        'CORE_RESPONSE_MISSING',
        500
    );
}

if (
    !is_file($routerClass) ||
    !is_readable($routerClass)
) {

    apiError(
        'ملف Router غير موجود',
        'CORE_ROUTER_MISSING',
        500
    );
}

require_once $responseClass;
require_once $routerClass;


use Core\Router;


// ============================================================================
// 12. COMMON API ERROR FUNCTION
// ============================================================================

/**
 * Return a standardized API error.
 *
 * Standard:
 *
 * {
 *     "success": false,
 *     "data": null,
 *     "message": "...",
 *     "code": "...",
 *     "timestamp": "...",
 *     "version": "..."
 * }
 */
function apiError(
    string $message,
    string $code,
    int $status = 500
): never {

    /**
     * حماية من HTTP status غير صالح.
     */
    if (
        $status < 100 ||
        $status > 599
    ) {
        $status = 500;
    }

    http_response_code($status);

    $response = [
        'success'   => false,
        'data'      => null,
        'message'   => $message,
        'code'      => $code,
        'timestamp' => date('Y-m-d H:i:s'),
        'version'   => getenv('APP_VERSION') ?: '5.0.0',
    ];

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


// ============================================================================
// 13. REQUEST URI NORMALIZATION
// ============================================================================

/**
 * Apache .htaccess قد يحول:
 *
 *     /api/products
 *
 * إلى:
 *
 *     /backend/public/index.php?route=products
 *
 * لذلك نعيد بناء REQUEST_URI.
 */
if (
    isset($_GET['route']) &&
    $_GET['route'] !== ''
) {

    $route = trim(
        (string) $_GET['route'],
        '/'
    );

    /**
     * منع path traversal.
     *
     * لا يجب أن يحتوي route على:
     *
     * ../
     * ..\
     */
    if (
        str_contains($route, '..') ||
        str_contains($route, '\\')
    ) {

        apiError(
            'مسار الطلب غير صالح',
            'INVALID_ROUTE',
            400
        );
    }

    $_SERVER['REQUEST_URI'] =
        API_BASE_PATH .
        '/' .
        $route;
}


// ============================================================================
// 14. ROUTES FILE
// ============================================================================

$routesFile =
    BACKEND_PATH .
    '/routes/api.php';


if (
    !is_file($routesFile) ||
    !is_readable($routesFile)
) {

    apiError(
        'ملف مسارات API غير موجود',
        'ROUTES_FILE_MISSING',
        500
    );
}


// ============================================================================
// 15. CREATE ROUTER
// ============================================================================

try {

    $router = new Router(
        API_BASE_PATH
    );

} catch (Throwable $exception) {

    error_log(
        '[API ROUTER INIT] ' .
        $exception->getMessage()
    );

    apiError(
        $appDebug
            ? $exception->getMessage()
            : 'تعذر تشغيل نظام المسارات',
        'ROUTER_INIT_ERROR',
        500
    );
}


// ============================================================================
// 16. NOT FOUND HANDLER
// ============================================================================

$router->setNotFoundHandler(
    static function (): void {

        apiError(
            'المسار غير موجود',
            'ROUTE_NOT_FOUND',
            404
        );
    }
);


// ============================================================================
// 17. ROUTER ERROR HANDLER
// ============================================================================

$router->setErrorHandler(
    static function (
        Throwable $exception
    ) use ($appDebug): void {

        error_log(
            sprintf(
                '[API ROUTER ERROR] %s in %s:%d',
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            )
        );

        /**
         * Development
         */
        if ($appDebug) {

            apiError(
                $exception->getMessage(),
                'INTERNAL_ERROR',
                500
            );
        }

        /**
         * Production
         */
        apiError(
            'حدث خطأ داخلي في الخادم',
            'INTERNAL_ERROR',
            500
        );
    }
);


// ============================================================================
// 18. LOAD API ROUTES
// ============================================================================

try {

    /**
     * api.php يجب أن يقوم بتسجيل المسارات على:
     *
     * $router
     *
     * ولا يجب أن يحتوي على:
     *
     * - Database queries
     * - HTML
     * - Mock data
     * - Business logic
     * - Passwords
     * - Hardcoded users
     */

    require $routesFile;

} catch (Throwable $exception) {

    error_log(
        sprintf(
            "[API ROUTES LOAD] %s in %s:%d\n%s",
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        )
    );

    apiError(
        $appDebug
            ? $exception->getMessage()
            : 'تعذر تحميل مسارات النظام',
        'ROUTES_LOAD_ERROR',
        500
    );
}


// ============================================================================
// 19. DISPATCH REQUEST
// ============================================================================

try {

    $router->dispatch();

} catch (Throwable $exception) {

    error_log(
        sprintf(
            "[API DISPATCH] %s in %s:%d\n%s",
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        )
    );

    if ($appDebug) {

        apiError(
            $exception->getMessage(),
            'DISPATCH_ERROR',
            500
        );
    }

    apiError(
        'حدث خطأ داخلي في الخادم',
        'INTERNAL_ERROR',
        500
    );
}


// ============================================================================
// 20. SAFETY FALLBACK
// ============================================================================

/**
 * في الحالة الطبيعية Router::dispatch()
 * يجب أن ينهي الطلب أو يرسل Response.
 *
 * إذا رجع بدون Response لأي سبب،
 * لا نترك الطلب بدون JSON response.
 */
if (
    !headers_sent() &&
    http_response_code() === 200
) {

    apiError(
        'لم يتم إنشاء استجابة صحيحة من API',
        'EMPTY_API_RESPONSE',
        500
    );
}

<?php
/**
 * ================================================================
 * Logistox - إعدادات قاعدة البيانات
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 * 
 * الملف: backend/config/database.php
 * الوظيفة: إعدادات اتصال قاعدة البيانات مع دعم متعدد
 * ================================================================
 */

// ================================================================
// 1. منع الوصول المباشر
// ================================================================


// ================================================================
// 2. تحميل متغيرات البيئة
// ================================================================

$envFile = dirname(__DIR__, 2) . '/.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// ================================================================
// 3. إعدادات قاعدة البيانات
// ================================================================

return [
    /*
    |--------------------------------------------------------------------------
    | الاتصال الأساسي
    |--------------------------------------------------------------------------
    */
    'default' => 'mysql',

    /*
    |--------------------------------------------------------------------------
    | اتصالات قاعدة البيانات
    |--------------------------------------------------------------------------
    */
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: 'localhost',
            'port' => getenv('DB_PORT') ?: 3306,
            'database' => getenv('DB_NAME') ?: 'inventory_system',
            'username' => getenv('DB_USER') ?: 'angel',
            'password' => getenv('DB_PASS') ?: 'Lecico10@',
            'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
            'collation' => getenv('DB_COLLATION') ?: 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ],
            'read' => [
                'host' => getenv('DB_READ_HOST') ?: getenv('DB_HOST') ?: 'localhost',
            ],
            'write' => [
                'host' => getenv('DB_WRITE_HOST') ?: getenv('DB_HOST') ?: 'localhost',
            ],
            'sticky' => true,
            'timezone' => '+02:00', // توقيت مصر
        ],

        'mysql_test' => [
            'driver' => 'mysql',
            'host' => getenv('DB_TEST_HOST') ?: 'localhost',
            'port' => getenv('DB_TEST_PORT') ?: 3306,
            'database' => getenv('DB_TEST_NAME') ?: 'inventory_system_test',
            'username' => getenv('DB_TEST_USER') ?: 'angel',
            'password' => getenv('DB_TEST_PASS') ?: 'Lecico10@',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | إعدادات الم Migrations
    |--------------------------------------------------------------------------
    */
    'migrations' => [
        'table' => 'migrations',
        'path' => dirname(__DIR__, 2) . '/database/migrations',
    ],

    /*
    |--------------------------------------------------------------------------
    | إعدادات Redis (للتخزين المؤقت)
    |--------------------------------------------------------------------------
    */
    'redis' => [
        'client' => 'predis',
        'default' => [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'port' => getenv('REDIS_PORT') ?: 6379,
            'database' => 0,
        ],
        'cache' => [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'port' => getenv('REDIS_PORT') ?: 6379,
            'database' => 1,
        ],
        'session' => [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'port' => getenv('REDIS_PORT') ?: 6379,
            'database' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | إعدادات السجلات
    |--------------------------------------------------------------------------
    */
    'log' => [
        'enabled' => true,
        'path' => dirname(__DIR__, 2) . '/logs/database.log',
        'slow_query_threshold' => 1000, // ميلي ثانية
        'max_log_size' => 10485760, // 10 MB
    ],

    /*
    |--------------------------------------------------------------------------
    | إعدادات النسخ الاحتياطي
    |--------------------------------------------------------------------------
    */
    'backup' => [
        'enabled' => true,
        'path' => dirname(__DIR__, 2) . '/backups/database',
        'compress' => true,
        'retention' => 30, // أيام
        'tables' => [
            'users', 'roles', 'permissions', 'role_permissions',
            'products', 'categories', 'units', 'warehouses',
            'receipts', 'receipt_items', 'issues', 'issue_items',
            'transfers', 'transfer_items', 'returns', 'return_items',
            'stock_balances', 'stock_movements',
            'suppliers', 'recipients',
            'notifications', 'notification_settings',
            'audit_logs', 'auth_logs', 'user_sessions',
            'themes', 'system_settings',
        ],
    ],
];

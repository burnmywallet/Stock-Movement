<?php

declare(strict_types=1);

/**
 * ============================================================================
 * Logistox / Stock-Movement
 * Advanced Inventory Management System v5.0
 * ============================================================================
 *
 * File:
 *     backend/routes/api.php
 *
 * Purpose:
 *     تسجيل جميع API Routes فقط.
 *
 * IMPORTANT:
 *     هذا الملف لا يحتوي على:
 *       - Mock Data
 *       - Hardcoded Users
 *       - Hardcoded Passwords
 *       - Database Queries
 *       - Business Logic
 *       - أسعار
 *
 * Architecture:
 *
 *     index.php
 *        ↓
 *     api.php
 *        ↓
 *     Middleware
 *        ↓
 *     Controllers
 *        ↓
 *     Services / Models
 *        ↓
 *     Database
 *
 * ============================================================================
 */

use Core\Router;

/** @var Router $router */


/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
|
| المسارات التي لا تحتاج تسجيل دخول.
|
*/


/**
 * Health Check
 *
 * يستخدم للتأكد أن API يعمل.
 */
$router->get('/health', function (): void {

    $response = [
        'status' => 'healthy',
        'application' => 'Logistox',
        'version' => getenv('APP_VERSION') ?: '5.0.0',
        'php_version' => PHP_VERSION,
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    jsonResponse(
        true,
        $response,
        'النظام يعمل بشكل طبيعي',
        null,
        200
    );
});


/**
 * API Test
 */
$router->get('/test', function (): void {

    jsonResponse(
        true,
        [
            'api' => 'online',
            'application' => 'Logistox',
            'version' => getenv('APP_VERSION') ?: '5.0.0',
            'php_version' => PHP_VERSION,
            'environment' => getenv('APP_ENV') ?: 'production',
            'timestamp' => date('Y-m-d H:i:s'),
        ],
        'API يعمل بنجاح',
        null,
        200
    );
});


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| لا نضع auth middleware على login.
|
| التنفيذ الحقيقي للمصادقة يجب أن يكون داخل AuthController.
|
*/


$router->group('/auth', function (Router $router): void {

    /**
     * Login
     *
     * POST /api/auth/login
     */
    $router->post('/login', function (): void {

        dispatchController(
            'AuthController',
            'login'
        );
    });


    /**
     * Logout
     *
     * POST /api/auth/logout
     */
    $router->post('/logout', function (): void {

        dispatchController(
            'AuthController',
            'logout'
        );
    });


    /**
     * Current User
     *
     * GET /api/auth/me
     */
    $router->get('/me', function (): void {

        dispatchController(
            'AuthController',
            'me'
        );
    });


    /**
     * Validate Session
     *
     * GET /api/auth/validate
     */
    $router->get('/validate', function (): void {

        dispatchController(
            'AuthController',
            'validate'
        );
    });
});


/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
*/


$router->group(
    '/dashboard',
    ['auth'],
    function (Router $router): void {

        /**
         * Dashboard statistics
         *
         * GET /api/dashboard/stats
         */
        $router->get('/stats', function (): void {

            dispatchController(
                'DashboardController',
                'stats'
            );
        });


        /**
         * Dashboard charts
         *
         * GET /api/dashboard/charts
         */
        $router->get('/charts', function (): void {

            dispatchController(
                'DashboardController',
                'charts'
            );
        });


        /**
         * Dashboard alerts
         *
         * GET /api/dashboard/alerts
         */
        $router->get('/alerts', function (): void {

            dispatchController(
                'DashboardController',
                'alerts'
            );
        });


        /**
         * Recent activities
         *
         * GET /api/dashboard/activities
         */
        $router->get('/activities', function (): void {

            dispatchController(
                'DashboardController',
                'activities'
            );
        });


        /**
         * System status
         *
         * GET /api/dashboard/status
         */
        $router->get('/status', function (): void {

            dispatchController(
                'DashboardController',
                'status'
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Products
|--------------------------------------------------------------------------
|
| الأصناف.
|
*/


$router->group(
    '/products',
    ['auth'],
    function (Router $router): void {

        /**
         * List products
         *
         * GET /api/products
         */
        $router->get('', function (): void {

            dispatchController(
                'ProductController',
                'index'
            );
        });


        /**
         * Create product
         *
         * POST /api/products
         */
        $router->post('', function (): void {

            dispatchController(
                'ProductController',
                'store'
            );
        });


        /**
         * Search products
         *
         * GET /api/products/search
         */
        $router->get('/search', function (): void {

            dispatchController(
                'ProductController',
                'search'
            );
        });


        /**
         * Low stock products
         *
         * GET /api/products/low-stock
         */
        $router->get('/low-stock', function (): void {

            dispatchController(
                'ProductController',
                'lowStock'
            );
        });


        /**
         * Product categories
         *
         * GET /api/products/categories
         *
         * هذا Route للتوافق المؤقت.
         * لاحقًا يمكن للواجهة استخدام /categories مباشرة.
         */
        $router->get('/categories', function (): void {

            dispatchController(
                'CategoryController',
                'index'
            );
        });


        /**
         * Product units
         *
         * GET /api/products/units
         *
         * للتوافق مع الواجهة الحالية.
         */
        $router->get('/units', function (): void {

            dispatchController(
                'UnitController',
                'index'
            );
        });


        /**
         * Get product
         *
         * GET /api/products/{id}
         */
        $router->get('/{id}', function ($id): void {

            dispatchController(
                'ProductController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });


        /**
         * Update product
         *
         * PUT /api/products/{id}
         */
        $router->put('/{id}', function ($id): void {

            dispatchController(
                'ProductController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        /**
         * Partial update product
         *
         * PATCH /api/products/{id}
         */
        $router->patch('/{id}', function ($id): void {

            dispatchController(
                'ProductController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        /**
         * Delete / deactivate product
         *
         * DELETE /api/products/{id}
         */
        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'ProductController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/


$router->group(
    '/categories',
    ['auth'],
    function (Router $router): void {

        $router->get('', function (): void {

            dispatchController(
                'CategoryController',
                'index'
            );
        });


        $router->post('', function (): void {

            dispatchController(
                'CategoryController',
                'store'
            );
        });


        $router->get('/{id}', function ($id): void {

            dispatchController(
                'CategoryController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->put('/{id}', function ($id): void {

            dispatchController(
                'CategoryController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->patch('/{id}', function ($id): void {

            dispatchController(
                'CategoryController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'CategoryController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Units
|--------------------------------------------------------------------------
*/


$router->group(
    '/units',
    ['auth'],
    function (Router $router): void {

        $router->get('', function (): void {

            dispatchController(
                'UnitController',
                'index'
            );
        });


        $router->post('', function (): void {

            dispatchController(
                'UnitController',
                'store'
            );
        });


        $router->get('/{id}', function ($id): void {

            dispatchController(
                'UnitController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->put('/{id}', function ($id): void {

            dispatchController(
                'UnitController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->patch('/{id}', function ($id): void {

            dispatchController(
                'UnitController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'UnitController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Warehouses
|--------------------------------------------------------------------------
*/


$router->group(
    '/warehouses',
    ['auth'],
    function (Router $router): void {

        /**
         * List warehouses
         */
        $router->get('', function (): void {

            dispatchController(
                'WarehouseController',
                'index'
            );
        });


        /**
         * Create warehouse
         */
        $router->post('', function (): void {

            dispatchController(
                'WarehouseController',
                'store'
            );
        });


        /**
         * Summary
         */
        $router->get('/summary', function (): void {

            dispatchController(
                'WarehouseController',
                'summary'
            );
        });


        /**
         * Warehouse details
         */
        $router->get('/{id}', function ($id): void {

            dispatchController(
                'WarehouseController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });


        /**
         * Update warehouse
         */
        $router->put('/{id}', function ($id): void {

            dispatchController(
                'WarehouseController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->patch('/{id}', function ($id): void {

            dispatchController(
                'WarehouseController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        /**
         * Delete / deactivate warehouse
         */
        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'WarehouseController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });


        /**
         * Warehouse stock
         */
        $router->get('/{id}/stock', function ($id): void {

            dispatchController(
                'WarehouseController',
                'stock',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Receipts
|--------------------------------------------------------------------------
|
| إذن استلام بضائع.
|
*/


$router->group(
    '/receipts',
    ['auth'],
    function (Router $router): void {

        /**
         * List receipts
         */
        $router->get('', function (): void {

            dispatchController(
                'ReceiptController',
                'index'
            );
        });


        /**
         * Create receipt
         */
        $router->post('', function (): void {

            dispatchController(
                'ReceiptController',
                'store'
            );
        });


        /**
         * Receipt details
         */
        $router->get('/{id}', function ($id): void {

            dispatchController(
                'ReceiptController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });


        /**
         * Update receipt
         *
         * يفضل منع تعديل مستند مرحّل من Controller.
         */
        $router->put('/{id}', function ($id): void {

            dispatchController(
                'ReceiptController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        /**
         * Delete / cancel receipt
         */
        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'ReceiptController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Issues
|--------------------------------------------------------------------------
|
| أذون صرف / خروج بضائع.
|
*/


$router->group(
    '/issues',
    ['auth'],
    function (Router $router): void {

        $router->get('', function (): void {

            dispatchController(
                'IssueController',
                'index'
            );
        });


        $router->post('', function (): void {

            dispatchController(
                'IssueController',
                'store'
            );
        });


        $router->get('/{id}', function ($id): void {

            dispatchController(
                'IssueController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->put('/{id}', function ($id): void {

            dispatchController(
                'IssueController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'IssueController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Transfers
|--------------------------------------------------------------------------
|
| تحويل بين المخازن.
|
*/


$router->group(
    '/transfers',
    ['auth'],
    function (Router $router): void {

        $router->get('', function (): void {

            dispatchController(
                'TransferController',
                'index'
            );
        });


        $router->post('', function (): void {

            dispatchController(
                'TransferController',
                'store'
            );
        });


        $router->get('/{id}', function ($id): void {

            dispatchController(
                'TransferController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->put('/{id}', function ($id): void {

            dispatchController(
                'TransferController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'TransferController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Returns
|--------------------------------------------------------------------------
|
| المرتجعات.
|
*/


$router->group(
    '/returns',
    ['auth'],
    function (Router $router): void {

        $router->get('', function (): void {

            dispatchController(
                'ReturnController',
                'index'
            );
        });


        $router->post('', function (): void {

            dispatchController(
                'ReturnController',
                'store'
            );
        });


        $router->get('/{id}', function ($id): void {

            dispatchController(
                'ReturnController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->put('/{id}', function ($id): void {

            dispatchController(
                'ReturnController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'ReturnController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Stock Balances
|--------------------------------------------------------------------------
|
| الأرصدة الحالية.
|
*/


$router->group(
    '/stock-balances',
    ['auth'],
    function (Router $router): void {

        /**
         * All balances
         */
        $router->get('', function (): void {

            dispatchController(
                'StockBalanceController',
                'index'
            );
        });


        /**
         * Product balance
         */
        $router->get('/product/{productId}', function ($productId): void {

            dispatchController(
                'StockBalanceController',
                'product',
                [
                    'product_id' => routeId($productId)
                ]
            );
        });


        /**
         * Warehouse balance
         */
        $router->get('/warehouse/{warehouseId}', function ($warehouseId): void {

            dispatchController(
                'StockBalanceController',
                'warehouse',
                [
                    'warehouse_id' => routeId($warehouseId)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Stock Movements
|--------------------------------------------------------------------------
|
| حركة المخزون.
|
*/


$router->group(
    '/stock-movements',
    ['auth'],
    function (Router $router): void {

        /**
         * List movements
         */
        $router->get('', function (): void {

            dispatchController(
                'StockMovementController',
                'index'
            );
        });


        /**
         * Movement details
         */
        $router->get('/{id}', function ($id): void {

            dispatchController(
                'StockMovementController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/


$router->group(
    '/notifications',
    ['auth'],
    function (Router $router): void {

        /**
         * Notifications list
         */
        $router->get('', function (): void {

            dispatchController(
                'NotificationController',
                'index'
            );
        });


        /**
         * Unread notifications
         */
        $router->get('/unread', function (): void {

            dispatchController(
                'NotificationController',
                'unread'
            );
        });


        /**
         * Mark notification as read
         */
        $router->patch('/{id}/read', function ($id): void {

            dispatchController(
                'NotificationController',
                'markAsRead',
                [
                    'id' => routeId($id)
                ]
            );
        });


        /**
         * Mark all as read
         */
        $router->post('/read-all', function (): void {

            dispatchController(
                'NotificationController',
                'markAllAsRead'
            );
        });


        /**
         * Delete notification
         */
        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'NotificationController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Users
|--------------------------------------------------------------------------
|
| إدارة المستخدمين.
|
| الحماية الفعلية بالصلاحيات يجب أن تتم داخل Middleware/Controller.
|
*/


$router->group(
    '/users',
    ['auth'],
    function (Router $router): void {

        $router->get('', function (): void {

            dispatchController(
                'UserController',
                'index'
            );
        });


        $router->post('', function (): void {

            dispatchController(
                'UserController',
                'store'
            );
        });


        $router->get('/{id}', function ($id): void {

            dispatchController(
                'UserController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->put('/{id}', function ($id): void {

            dispatchController(
                'UserController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->patch('/{id}', function ($id): void {

            dispatchController(
                'UserController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'UserController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Roles & Permissions
|--------------------------------------------------------------------------
*/


$router->group(
    '/roles',
    ['auth'],
    function (Router $router): void {

        $router->get('', function (): void {

            dispatchController(
                'RoleController',
                'index'
            );
        });


        $router->post('', function (): void {

            dispatchController(
                'RoleController',
                'store'
            );
        });


        $router->get('/{id}', function ($id): void {

            dispatchController(
                'RoleController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->put('/{id}', function ($id): void {

            dispatchController(
                'RoleController',
                'update',
                [
                    'id' => routeId($id)
                ]
            );
        });


        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'RoleController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/**
 * Permissions
 */
$router->group(
    '/permissions',
    ['auth'],
    function (Router $router): void {

        $router->get('', function (): void {

            dispatchController(
                'PermissionController',
                'index'
            );
        });


        $router->get('/role/{roleId}', function ($roleId): void {

            dispatchController(
                'PermissionController',
                'role',
                [
                    'role_id' => routeId($roleId)
                ]
            );
        });


        $router->put('/role/{roleId}', function ($roleId): void {

            dispatchController(
                'PermissionController',
                'updateRole',
                [
                    'role_id' => routeId($roleId)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Reports
|--------------------------------------------------------------------------
|
| تقارير رسمية.
|
*/


$router->group(
    '/reports',
    ['auth'],
    function (Router $router): void {

        /**
         * General report
         */
        $router->get('/inventory', function (): void {

            dispatchController(
                'ReportController',
                'inventory'
            );
        });


        /**
         * Stock movements report
         */
        $router->get('/movements', function (): void {

            dispatchController(
                'ReportController',
                'movements'
            );
        });


        /**
         * Receipts report
         */
        $router->get('/receipts', function (): void {

            dispatchController(
                'ReportController',
                'receipts'
            );
        });


        /**
         * Issues report
         */
        $router->get('/issues', function (): void {

            dispatchController(
                'ReportController',
                'issues'
            );
        });


        /**
         * Transfers report
         */
        $router->get('/transfers', function (): void {

            dispatchController(
                'ReportController',
                'transfers'
            );
        });


        /**
         * Returns report
         */
        $router->get('/returns', function (): void {

            dispatchController(
                'ReportController',
                'returns'
            );
        });


        /**
         * Low stock report
         */
        $router->get('/low-stock', function (): void {

            dispatchController(
                'ReportController',
                'lowStock'
            );
        });


        /**
         * Export report
         */
        $router->post('/export', function (): void {

            dispatchController(
                'ReportController',
                'export'
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Audit Logs
|--------------------------------------------------------------------------
*/


$router->group(
    '/audit',
    ['auth'],
    function (Router $router): void {

        /**
         * Audit log list
         */
        $router->get('', function (): void {

            dispatchController(
                'AuditController',
                'index'
            );
        });


        /**
         * Audit log details
         */
        $router->get('/{id}', function ($id): void {

            dispatchController(
                'AuditController',
                'show',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
*/


$router->group(
    '/settings',
    ['auth'],
    function (Router $router): void {

        /**
         * Get settings
         */
        $router->get('', function (): void {

            dispatchController(
                'SettingsController',
                'index'
            );
        });


        /**
         * Update settings
         */
        $router->put('', function (): void {

            dispatchController(
                'SettingsController',
                'update'
            );
        });


        /**
         * Company information
         */
        $router->get('/company', function (): void {

            dispatchController(
                'SettingsController',
                'company'
            );
        });


        /**
         * Update company information
         */
        $router->put('/company', function (): void {

            dispatchController(
                'SettingsController',
                'updateCompany'
            );
        });


        /**
         * Themes
         */
        $router->get('/themes', function (): void {

            dispatchController(
                'SettingsController',
                'themes'
            );
        });


        /**
         * Update theme
         */
        $router->put('/theme', function (): void {

            dispatchController(
                'SettingsController',
                'updateTheme'
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Backup
|--------------------------------------------------------------------------
*/


$router->group(
    '/backup',
    ['auth'],
    function (Router $router): void {

        /**
         * Backup status
         */
        $router->get('/status', function (): void {

            dispatchController(
                'BackupController',
                'status'
            );
        });


        /**
         * Create database backup
         */
        $router->post('/create', function (): void {

            dispatchController(
                'BackupController',
                'create'
            );
        });


        /**
         * List backups
         */
        $router->get('', function (): void {

            dispatchController(
                'BackupController',
                'index'
            );
        });


        /**
         * Delete backup
         */
        $router->delete('/{id}', function ($id): void {

            dispatchController(
                'BackupController',
                'destroy',
                [
                    'id' => routeId($id)
                ]
            );
        });
    }
);


/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/


/**
 * Dispatch Controller
 *
 * هذه الدالة تفصل الـ Routes عن الـ Controllers.
 *
 * مثال:
 *
 * dispatchController(
 *     'ProductController',
 *     'index'
 * );
 *
 * النتيجة:
 *
 * Controller:
 *     backend/controllers/ProductController.php
 *
 * Method:
 *     index()
 *
 * ----------------------------------------------------------------------------
 */
function dispatchController(
    string $controller,
    string $method,
    array $parameters = []
): void {

    /**
     * Controller directory.
     */
    $controllerDirectory =
        dirname(__DIR__) .
        '/controllers';


    /**
     * Controller file.
     */
    $controllerFile =
        $controllerDirectory .
        '/' .
        $controller .
        '.php';


    /**
     * لو الـ Controller غير موجود.
     *
     * لا نرجع Fatal Error.
     */
    if (
        !is_file($controllerFile) ||
        !is_readable($controllerFile)
    ) {

        jsonResponse(
            false,
            null,
            'الخدمة المطلوبة غير متوفرة حاليًا',
            'CONTROLLER_NOT_IMPLEMENTED',
            501
        );
    }


    require_once $controllerFile;


    /**
     * محاولة تحديد Namespace/Class.
     *
     * أولاً:
     *
     * Controllers\ProductController
     *
     * ثم:
     *
     * ProductController
     */
    $classCandidates = [
        'Controllers\\' . $controller,
        'App\\Controllers\\' . $controller,
        $controller,
    ];


    $resolvedClass = null;


    foreach ($classCandidates as $candidate) {

        if (class_exists($candidate)) {

            $resolvedClass = $candidate;

            break;
        }
    }


    if ($resolvedClass === null) {

        jsonResponse(
            false,
            null,
            'تعريف الـ Controller غير موجود',
            'CONTROLLER_CLASS_NOT_FOUND',
            501
        );
    }


    try {

        $instance = new $resolvedClass();

    } catch (Throwable $exception) {

        error_log(
            sprintf(
                '[CONTROLLER INIT] %s: %s',
                $controller,
                $exception->getMessage()
            )
        );

        jsonResponse(
            false,
            null,
            'تعذر تشغيل الخدمة المطلوبة',
            'CONTROLLER_INIT_ERROR',
            500
        );
    }


    if (
        !method_exists(
            $instance,
            $method
        )
    ) {

        jsonResponse(
            false,
            null,
            'العملية المطلوبة غير متوفرة',
            'CONTROLLER_METHOD_NOT_FOUND',
            501
        );
    }


    try {

        /**
         * تمرير parameters كـ associative array.
         *
         * Controller يقدر يستقبل:
         *
         * index()
         * show(array $params)
         */
        if (!empty($parameters)) {

            $result = $instance->{$method}(
                $parameters
            );

        } else {

            $result = $instance->{$method}();
        }


        /**
         * لو الـ Controller قام بالفعل بإرسال Response.
         *
         * لا نرسل Response ثاني.
         */
        if ($result === null) {

            return;
        }


        /**
         * دعم Controllers التي ترجع array.
         */
        if (is_array($result)) {

            jsonResponse(
                true,
                $result,
                null,
                null,
                200
            );

            return;
        }


        /**
         * دعم Controllers التي ترجع scalar.
         */
        jsonResponse(
            true,
            $result,
            null,
            null,
            200
        );

    } catch (Throwable $exception) {

        error_log(
            sprintf(
                '[CONTROLLER ERROR] %s::%s - %s in %s:%d',
                $controller,
                $method,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            )
        );


        $debug = filter_var(
            getenv('APP_DEBUG') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );


        jsonResponse(
            false,
            null,
            $debug
                ? $exception->getMessage()
                : 'حدث خطأ أثناء تنفيذ العملية',
            'CONTROLLER_ERROR',
            500
        );
    }
}


/**
 * Validate route ID.
 *
 * كل IDs في النظام يجب أن تكون أرقام صحيحة موجبة.
 */
function routeId($value): int
{
    if (
        !is_numeric($value) ||
        (int) $value <= 0 ||
        (string) (int) $value !== (string) $value &&
        !ctype_digit((string) $value)
    ) {

        jsonResponse(
            false,
            null,
            'معرف السجل غير صالح',
            'INVALID_ID',
            400
        );
    }


    return (int) $value;
}


/**
 * Unified JSON Response
 *
 * لا نعتمد هنا على Mock Response.
 *
 * ويمكن لاحقًا توحيدها بالكامل مع Core\Response.
 */
function jsonResponse(
    bool $success,
    mixed $data = null,
    ?string $message = null,
    ?string $code = null,
    int $status = 200
): never {

    http_response_code($status);


    $response = [
        'success' => $success,
        'data' => $data,
        'message' => $message,
    ];


    if ($code !== null) {

        $response['code'] = $code;
    }


    $response['timestamp'] =
        date('Y-m-d H:i:s');


    $response['version'] =
        getenv('APP_VERSION') ?: '5.0.0';


    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );


    exit;
}

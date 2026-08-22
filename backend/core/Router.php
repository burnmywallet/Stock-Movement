<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/core/Router.php
// الوصف: نظام التوجيه المتقدم مع دعم الميدل وير والمعلمات
// التاريخ: 2026-08-22
// ================================================================

namespace Core;

use Exception;

class Router
{
    /**
     * @var array $routes - جميع المسارات المسجلة
     */
    private $routes = [];
    
    /**
     * @var array $middleware - الميدل وير العام
     */
    private $globalMiddleware = [];
    
    /**
     * @var string $prefix - بادئة المسار الحالية
     */
    private $prefix = '';
    
    /**
     * @var array $groupMiddleware - ميدل وير المجموعة
     */
    private $groupMiddleware = [];
    
    /**
     * @var array $routeParams - معلمات المسار
     */
    private $routeParams = [];
    
    /**
     * @var string|null $currentRouteName - اسم المسار الحالي
     */
    private $currentRouteName = null;
    
    /**
     * @var array $namedRoutes - المسارات المسماة
     */
    private $namedRoutes = [];
    
    /**
     * @var array $routeGroups - مجموعات المسارات
     */
    private $routeGroups = [];
    
    /**
     * @var string|null $currentGroup - المجموعة الحالية
     */
    private $currentGroup = null;
    
    /**
     * @var bool $isDebug - وضع التصحيح
     */
    private $isDebug = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->isDebug = ($_ENV['APP_DEBUG'] ?? false) === 'true';
    }

    /**
     * إضافة مسار جديد
     */
    public function add(
        string $method,
        string $path,
        callable|array $handler,
        array $options = []
    ): self {
        // إضافة البادئة
        $fullPath = $this->prefix . $path;
        
        // دمج الميدل وير
        $middleware = array_merge(
            $this->globalMiddleware,
            $this->groupMiddleware,
            $options['middleware'] ?? []
        );
        
        // معالجة المعلمات
        $pathWithParams = $this->parsePathParams($fullPath);
        
        // تسجيل المسار
        $route = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'pattern' => $pathWithParams['pattern'],
            'params' => $pathWithParams['params'],
            'handler' => $handler,
            'middleware' => $middleware,
            'options' => $options,
            'name' => $options['name'] ?? null,
            'group' => $this->currentGroup
        ];
        
        $this->routes[] = $route;
        
        // تسجيل المسار المسمى
        if (isset($options['name'])) {
            $this->namedRoutes[$options['name']] = $route;
        }
        
        return $this;
    }

    /**
     * مسار GET
     */
    public function get(string $path, callable|array $handler, array $options = []): self
    {
        return $this->add('GET', $path, $handler, $options);
    }

    /**
     * مسار POST
     */
    public function post(string $path, callable|array $handler, array $options = []): self
    {
        return $this->add('POST', $path, $handler, $options);
    }

    /**
     * مسار PUT
     */
    public function put(string $path, callable|array $handler, array $options = []): self
    {
        return $this->add('PUT', $path, $handler, $options);
    }

    /**
     * مسار DELETE
     */
    public function delete(string $path, callable|array $handler, array $options = []): self
    {
        return $this->add('DELETE', $path, $handler, $options);
    }

    /**
     * مسار PATCH
     */
    public function patch(string $path, callable|array $handler, array $options = []): self
    {
        return $this->add('PATCH', $path, $handler, $options);
    }

    /**
     * مسار OPTIONS
     */
    public function options(string $path, callable|array $handler, array $options = []): self
    {
        return $this->add('OPTIONS', $path, $handler, $options);
    }

    /**
     * مسار ANY (جميع الطرق)
     */
    public function any(string $path, callable|array $handler, array $options = []): self
    {
        return $this->add('ANY', $path, $handler, $options);
    }

    /**
     * مجموعة مسارات
     */
    public function group(string $prefix, array $middleware = [], callable $callback): self
    {
        $previousPrefix = $this->prefix;
        $previousGroupMiddleware = $this->groupMiddleware;
        $previousGroup = $this->currentGroup;
        
        $this->prefix = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge(
            $previousGroupMiddleware,
            $middleware
        );
        $this->currentGroup = $prefix;
        
        // تسجيل المجموعة
        $groupId = count($this->routeGroups);
        $this->routeGroups[$groupId] = [
            'prefix' => $this->prefix,
            'middleware' => $this->groupMiddleware,
            'name' => $prefix
        ];
        
        // تنفيذ callback مع $this
        $callback($this);
        
        // استعادة الحالة السابقة
        $this->prefix = $previousPrefix;
        $this->groupMiddleware = $previousGroupMiddleware;
        $this->currentGroup = $previousGroup;
        
        return $this;
    }

    /**
     * تنفيذ التوجيه
     */
    public function dispatch(string $method, string $path): mixed
    {
        // تنظيف المسار
        $path = '/' . ltrim($path, '/');
        
        // إزالة base path
        $basePath = $_ENV['BASE_PATH'] ?? '';
        if (!empty($basePath) && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
            $path = '/' . ltrim($path, '/');
        }
        
        $method = strtoupper($method);
        
        // معالجة طلب OPTIONS (CORS)
        if ($method === 'OPTIONS') {
            $this->sendOptionsResponse();
            return null;
        }
        
        // تسجيل الطلب في وضع التصحيح
        if ($this->isDebug) {
            logInfo("Routing: {$method} {$path}", [
                'ip' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        }
        
        // البحث عن مسار مطابق
        $matchedRoute = null;
        $matchedParams = [];
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }
            
            // مطابقة المسار مع المعلمات
            if (preg_match($route['pattern'], $path, $matches)) {
                // استخراج المعلمات
                $params = $this->extractParams($route, $matches);
                $matchedRoute = $route;
                $matchedParams = $params;
                break;
            }
        }
        
        if (!$matchedRoute) {
            $this->sendNotFoundResponse($path);
            return null;
        }
        
        // تخزين معلمات المسار
        $this->routeParams = $matchedParams;
        
        // تنفيذ الميدل وير العام
        $this->executeGlobalMiddleware();
        
        // تنفيذ ميدل وير المسار
        foreach ($matchedRoute['middleware'] as $middleware) {
            $result = $this->executeMiddleware($middleware);
            if ($result === false) {
                return null;
            }
        }
        
        // تنفيذ المعالج
        return $this->executeHandler($matchedRoute['handler']);
    }

    /**
     * تحليل معلمات المسار
     */
    private function parsePathParams(string $path): array
    {
        $params = [];
        $pattern = $path;
        
        // استبدال المعلمات
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)(?::([^}]+))?\}/',
            function($matches) use (&$params) {
                $name = $matches[1];
                $constraint = $matches[2] ?? '[^/]+';
                $params[] = $name;
                return "(?P<{$name}>{$constraint})";
            },
            $pattern
        );
        
        return [
            'pattern' => '#^' . $pattern . '$#',
            'params' => $params
        ];
    }

    /**
     * استخراج معلمات المسار
     */
    private function extractParams(array $route, array $matches): array
    {
        $params = [];
        foreach ($route['params'] as $name) {
            if (isset($matches[$name])) {
                $params[$name] = $matches[$name];
            }
        }
        return $params;
    }

    /**
     * تنفيذ الميدل وير
     */
    private function executeMiddleware(string|callable $middleware): bool
    {
        try {
            if (is_string($middleware)) {
                // التحقق من وجود الكلاس
                $mwClass = "\\Middleware\\{$middleware}";
                if (class_exists($mwClass)) {
                    $mw = new $mwClass();
                    if (method_exists($mw, 'handle')) {
                        return $mw->handle($this->routeParams);
                    }
                }
                
                // محاولة البحث عن الكلاس بدون Namespace
                if (class_exists($middleware)) {
                    $mw = new $middleware();
                    if (method_exists($mw, 'handle')) {
                        return $mw->handle($this->routeParams);
                    }
                }
                
                throw new Exception("Middleware '{$middleware}' not found");
                
            } elseif (is_callable($middleware)) {
                return $middleware($this->routeParams) !== false;
            }
            
            return true;
            
        } catch (Exception $e) {
            if ($this->isDebug) {
                errorResponse('Middleware Error: ' . $e->getMessage(), 500);
            } else {
                errorResponse('حدث خطأ في المصادقة', 500);
            }
            return false;
        }
    }

    /**
     * تنفيذ الميدل وير العام
     */
    private function executeGlobalMiddleware(): void
    {
        foreach ($this->globalMiddleware as $middleware) {
            $this->executeMiddleware($middleware);
        }
    }

    /**
     * تنفيذ المعالج
     */
    private function executeHandler(callable|array $handler): mixed
    {
        try {
            if (is_array($handler)) {
                $controller = $handler[0];
                $method = $handler[1];
                
                // إنشاء كائن المتحكم
                if (is_string($controller)) {
                    $controllerClass = "\\Controllers\\{$controller}";
                    if (!class_exists($controllerClass)) {
                        // محاولة بدون Namespace
                        if (class_exists($controller)) {
                            $controllerClass = $controller;
                        } else {
                            throw new Exception("Controller '{$controller}' not found");
                        }
                    }
                    $controller = new $controllerClass();
                }
                
                if (is_object($controller) && method_exists($controller, $method)) {
                    // تمرير المعلمات كـ array
                    $params = array_values($this->routeParams);
                    return $controller->$method(...$params);
                } else {
                    throw new Exception("Method '{$method}' not found in controller");
                }
                
            } elseif (is_callable($handler)) {
                // تمرير المعلمات
                $params = array_values($this->routeParams);
                return $handler(...$params);
            }
            
            throw new Exception('Invalid handler type');
            
        } catch (Exception $e) {
            if ($this->isDebug) {
                errorResponse('Handler Error: ' . $e->getMessage(), 500);
            } else {
                errorResponse('حدث خطأ في معالجة الطلب', 500);
            }
            return null;
        }
    }

    /**
     * إضافة ميدل وير عام
     */
    public function middleware(array $middleware): self
    {
        $this->globalMiddleware = array_merge($this->globalMiddleware, $middleware);
        return $this;
    }

    /**
     * الحصول على معلمات المسار الحالي
     */
    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    /**
     * الحصول على مسار مسمى
     */
    public function getNamedRoute(string $name): ?array
    {
        return $this->namedRoutes[$name] ?? null;
    }

    /**
     * توليد URL لمسار مسمى
     */
    public function route(string $name, array $params = []): string
    {
        $route = $this->getNamedRoute($name);
        if (!$route) {
            throw new Exception("Route '{$name}' not found");
        }
        
        $url = $route['path'];
        foreach ($params as $key => $value) {
            $url = str_replace("{{$key}}", $value, $url);
        }
        
        // إزالة أي معلمات متبقية
        $url = preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $url);
        $url = preg_replace('/\/+/', '/', $url);
        
        return $url;
    }

    /**
     * الحصول على جميع المسارات
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * الحصول على جميع المسارات المسماة
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    /**
     * إرسال استجابة OPTIONS
     */
    private function sendOptionsResponse(): void
    {
        header('HTTP/1.1 200 OK');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With, Origin');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Max-Age: 86400');
        exit;
    }

    /**
     * إرسال استجابة 404
     */
    private function sendNotFoundResponse(string $path): void
    {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: application/json');
        
        if ($this->isDebug) {
            $response = [
                'success' => false,
                'message' => 'المسار غير موجود',
                'code' => 'ROUTE_NOT_FOUND',
                'path' => $path,
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'available_routes' => array_map(function($route) {
                    return $route['method'] . ' ' . $route['path'];
                }, $this->routes),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } else {
            $response = [
                'success' => false,
                'message' => 'المسار غير موجود',
                'code' => 'ROUTE_NOT_FOUND',
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * تنظيف المسارات
     */
    public function clear(): void
    {
        $this->routes = [];
        $this->namedRoutes = [];
        $this->routeParams = [];
        $this->currentRouteName = null;
        $this->routeGroups = [];
    }

    /**
     * الحصول على معلومات المسار الحالي
     */
    public function getCurrentRouteInfo(): ?array
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }
            
            if (preg_match($route['pattern'], $path, $matches)) {
                return [
                    'name' => $route['name'],
                    'path' => $route['path'],
                    'method' => $route['method'],
                    'params' => $this->extractParams($route, $matches),
                    'group' => $route['group']
                ];
            }
        }
        
        return null;
    }

    /**
     * التحقق من وجود مسار
     */
    public function hasRoute(string $path, string $method = 'GET'): bool
    {
        $method = strtoupper($method);
        foreach ($this->routes as $route) {
            if (($route['method'] === $method || $route['method'] === 'ANY') && 
                preg_match($route['pattern'], $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * الحصول على المسارات حسب المجموعة
     */
    public function getRoutesByGroup(string $group): array
    {
        return array_filter($this->routes, function($route) use ($group) {
            return $route['group'] === $group;
        });
    }

    /**
     * الحصول على إحصائيات المسارات
     */
    public function getStats(): array
    {
        $stats = [
            'total' => count($this->routes),
            'by_method' => [],
            'by_group' => [],
            'named' => count($this->namedRoutes)
        ];
        
        foreach ($this->routes as $route) {
            $method = $route['method'];
            if (!isset($stats['by_method'][$method])) {
                $stats['by_method'][$method] = 0;
            }
            $stats['by_method'][$method]++;
            
            if ($route['group']) {
                if (!isset($stats['by_group'][$route['group']])) {
                    $stats['by_group'][$route['group']] = 0;
                }
                $stats['by_group'][$route['group']]++;
            }
        }
        
        return $stats;
    }

    /**
     * إنشاء مجموعة من المسارات (Shortcut)
     */
    public function resource(string $prefix, string $controller): self
    {
        $this->group($prefix, [], function($router) use ($controller, $prefix) {
            // Index - GET /api/{prefix}
            $router->get('', [$controller, 'index']);
            
            // Create - POST /api/{prefix}
            $router->post('', [$controller, 'create']);
            
            // Show - GET /api/{prefix}/{id}
            $router->get('/{id}', [$controller, 'show']);
            
            // Update - PUT /api/{prefix}/{id}
            $router->put('/{id}', [$controller, 'update']);
            
            // Delete - DELETE /api/{prefix}/{id}
            $router->delete('/{id}', [$controller, 'delete']);
        });
        
        return $this;
    }

    /**
     * إنشاء API Resource (مع صلاحيات)
     */
    public function apiResource(string $prefix, string $controller, array $permissions = []): self
    {
        $middleware = !empty($permissions) ? ['AuthMiddleware', 'PermissionMiddleware'] : ['AuthMiddleware'];
        
        $this->group($prefix, $middleware, function($router) use ($controller, $permissions) {
            $router->get('', [$controller, 'index']);
            $router->post('', [$controller, 'create']);
            $router->get('/{id}', [$controller, 'show']);
            $router->put('/{id}', [$controller, 'update']);
            $router->delete('/{id}', [$controller, 'delete']);
        }, ['permissions' => $permissions]);
        
        return $this;
    }

    /**
     * تسجيل مجموعة من المسارات لـ CRUD
     */
    public function crud(string $prefix, string $controller): self
    {
        return $this->resource($prefix, $controller);
    }
}

// ================================================================
// انتهى الملف
// ================================================================

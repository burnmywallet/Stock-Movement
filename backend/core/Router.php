<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/core/Router.php
// الوصف: نظام التوجيه المتقدم مع دعم الميدل وير والمعلمات
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
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
    private $middleware = [];
    
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
            'name' => $options['name'] ?? null
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
        
        $this->prefix = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge(
            $previousGroupMiddleware,
            $middleware
        );
        
        // تسجيل المجموعة
        $groupId = count($this->routeGroups);
        $this->routeGroups[$groupId] = [
            'prefix' => $this->prefix,
            'middleware' => $this->groupMiddleware
        ];
        
        $callback($this);
        
        $this->prefix = $previousPrefix;
        $this->groupMiddleware = $previousGroupMiddleware;
        
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
        
        // البحث عن مسار مطابق
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }
            
            // مطابقة المسار مع المعلمات
            if (preg_match($route['pattern'], $path, $matches)) {
                // استخراج المعلمات
                $this->routeParams = $this->extractParams($route, $matches);
                
                // تنفيذ الميدل وير العام
                $this->executeMiddleware();
                
                // تنفيذ ميدل وير المسار
                foreach ($route['middleware'] as $middleware) {
                    $this->executeMiddleware($middleware);
                }
                
                // تنفيذ المعالج
                return $this->executeHandler($route['handler']);
            }
        }
        
        // لم يتم العثور على مسار
        $this->sendNotFoundResponse();
        return null;
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
    private function executeMiddleware(string|callable|null $middleware = null): void
    {
        if (is_null($middleware)) {
            // تنفيذ جميع الميدل وير العام
            foreach ($this->middleware as $mw) {
                $this->executeMiddleware($mw);
            }
            return;
        }
        
        if (is_string($middleware)) {
            // إنشاء كائن الميدل وير
            $mwClass = "\\Middleware\\{$middleware}";
            if (class_exists($mwClass)) {
                $mw = new $mwClass();
                if (method_exists($mw, 'handle')) {
                    $mw->handle($this->routeParams);
                }
            }
        } elseif (is_callable($middleware)) {
            $middleware($this->routeParams);
        }
    }

    /**
     * تنفيذ المعالج
     */
    private function executeHandler(callable|array $handler): mixed
    {
        if (is_array($handler)) {
            $controller = $handler[0];
            $method = $handler[1];
            
            // إنشاء كائن المتحكم
            if (is_string($controller)) {
                $controllerClass = "\\Controllers\\{$controller}";
                if (class_exists($controllerClass)) {
                    $controller = new $controllerClass();
                }
            }
            
            if (is_object($controller) && method_exists($controller, $method)) {
                return $controller->$method(...array_values($this->routeParams));
            }
        } elseif (is_callable($handler)) {
            return $handler(...array_values($this->routeParams));
        }
        
        throw new Exception('Invalid handler');
    }

    /**
     * إضافة ميدل وير عام
     */
    public function middleware(array $middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
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
        
        return $url;
    }

    /**
     * إرسال استجابة OPTIONS
     */
    private function sendOptionsResponse(): void
    {
        header('HTTP/1.1 200 OK');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
        header('Access-Control-Allow-Origin: *');
        exit;
    }

    /**
     * إرسال استجابة 404
     */
    private function sendNotFoundResponse(): void
    {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'المسار غير موجود',
            'code' => 'ROUTE_NOT_FOUND',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * الحصول على جميع المسارات المسجلة
     */
    public function getRoutes(): array
    {
        return $this->routes;
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
                    'params' => $this->extractParams($route, $matches)
                ];
            }
        }
        
        return null;
    }
}

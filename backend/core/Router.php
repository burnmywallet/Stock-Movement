<?php

declare(strict_types=1);

/**
 * ============================================================================
 * Logistox / Stock-Movement
 * Advanced Inventory Management System v5.0
 * ============================================================================
 *
 * File:
 *     backend/core/Router.php
 *
 * Purpose:
 *     Central API Router
 *
 * Responsibilities:
 *     - Register routes
 *     - Route groups
 *     - Route parameters
 *     - Middleware
 *     - Named routes
 *     - HTTP method handling
 *     - Request preparation
 *     - 404 handling
 *     - Exception handling
 *
 * IMPORTANT:
 *     Router لا يحتوي على Business Logic.
 *     Router لا يتصل بقاعدة البيانات.
 *     Router لا يحتوي على بيانات Mock.
 *
 * ============================================================================
 */

namespace Core;

use Throwable;


/**
 * ============================================================================
 * Router
 * ============================================================================
 */
class Router
{
    /**
     * All registered routes.
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private array $routes = [];


    /**
     * Registered middleware aliases.
     *
     * @var array<string,mixed>
     */
    private array $middlewares = [];


    /**
     * Current route group prefix.
     */
    private string $currentGroup = '';


    /**
     * Current group middleware.
     *
     * @var array<int,mixed>
     */
    private array $currentMiddlewares = [];


    /**
     * API base path.
     */
    private string $basePath = '';


    /**
     * Parameters extracted from current route.
     *
     * @var array<string,string|null>
     */
    private array $routeParams = [];


    /**
     * Current matched route.
     */
    private string $currentRoute = '';


    /**
     * Allowed HTTP methods.
     *
     * @var array<int,string>
     */
    private array $allowedMethods = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS',
        'HEAD',
    ];


    /**
     * Default 404 handler.
     */
    private $notFoundHandler = null;


    /**
     * Default error handler.
     */
    private $errorHandler = null;


    /**
     * Named routes.
     *
     * @var array<string,array<string,string>>
     */
    private array $namedRoutes = [];


    /**
     * Constructor.
     */
    public function __construct(string $basePath = '')
    {
        $this->basePath = $this->normalizeBasePath($basePath);

        $this->setupDefaultHandlers();
    }


    // =========================================================================
    // ROUTE REGISTRATION
    // =========================================================================


    /**
     * Register GET route.
     */
    public function get(string $path, callable $callback): self
    {
        return $this->addRoute(
            'GET',
            $path,
            $callback
        );
    }


    /**
     * Register POST route.
     */
    public function post(string $path, callable $callback): self
    {
        return $this->addRoute(
            'POST',
            $path,
            $callback
        );
    }


    /**
     * Register PUT route.
     */
    public function put(string $path, callable $callback): self
    {
        return $this->addRoute(
            'PUT',
            $path,
            $callback
        );
    }


    /**
     * Register PATCH route.
     */
    public function patch(string $path, callable $callback): self
    {
        return $this->addRoute(
            'PATCH',
            $path,
            $callback
        );
    }


    /**
     * Register DELETE route.
     */
    public function delete(string $path, callable $callback): self
    {
        return $this->addRoute(
            'DELETE',
            $path,
            $callback
        );
    }


    /**
     * Register OPTIONS route.
     */
    public function options(string $path, callable $callback): self
    {
        return $this->addRoute(
            'OPTIONS',
            $path,
            $callback
        );
    }


    /**
     * Register HEAD route.
     */
    public function head(string $path, callable $callback): self
    {
        return $this->addRoute(
            'HEAD',
            $path,
            $callback
        );
    }


    /**
     * Register route for all supported methods.
     */
    public function any(string $path, callable $callback): self
    {
        foreach ($this->allowedMethods as $method) {

            $this->addRoute(
                $method,
                $path,
                $callback
            );
        }

        return $this;
    }


    /**
     * Register route for selected methods.
     *
     * Supports:
     *
     * ['GET', 'POST']
     *
     * or:
     *
     * "GET|POST"
     */
    public function match(
        array|string $methods,
        string $path,
        callable $callback
    ): self {

        if (is_string($methods)) {

            $methods = explode(
                '|',
                $methods
            );
        }


        foreach ($methods as $method) {

            $method = strtoupper(
                trim($method)
            );


            if (
                !in_array(
                    $method,
                    $this->allowedMethods,
                    true
                )
            ) {

                throw new \InvalidArgumentException(
                    "HTTP method '{$method}' is not supported."
                );
            }


            $this->addRoute(
                $method,
                $path,
                $callback
            );
        }


        return $this;
    }


    /**
     * Add route internally.
     */
    private function addRoute(
        string $method,
        string $path,
        callable $callback
    ): self {

        $method = strtoupper(
            trim($method)
        );


        if (
            !in_array(
                $method,
                $this->allowedMethods,
                true
            )
        ) {

            throw new \InvalidArgumentException(
                "HTTP method '{$method}' is not supported."
            );
        }


        if ($path === '') {

            $path = '/';
        }


        /**
         * Apply current group prefix.
         */
        if ($this->currentGroup !== '') {

            $path =
                $this->currentGroup .
                '/' .
                ltrim($path, '/');
        }


        $path = $this->normalizePath(
            $path
        );


        /**
         * Prevent accidental duplicate routes.
         */
        if (
            $this->hasExactRoute(
                $method,
                $path
            )
        ) {

            throw new \RuntimeException(
                "Duplicate route detected: {$method} {$path}"
            );
        }


        $this->routes[$method][] = [
            'method' => $method,
            'path' => $path,
            'callback' => $callback,
            'middlewares' => $this->currentMiddlewares,
            'name' => null,
        ];


        return $this;
    }


    /**
     * Check exact route duplication.
     */
    private function hasExactRoute(
        string $method,
        string $path
    ): bool {

        if (!isset($this->routes[$method])) {

            return false;
        }


        foreach (
            $this->routes[$method]
            as $route
        ) {

            if ($route['path'] === $path) {

                return true;
            }
        }


        return false;
    }


    // =========================================================================
    // NAMED ROUTES
    // =========================================================================


    /**
     * Name the most recently registered route.
     */
    public function name(string $name): self
    {
        if ($name === '') {

            throw new \InvalidArgumentException(
                'Route name cannot be empty.'
            );
        }


        foreach (
            array_reverse(
                array_keys($this->routes)
            )
            as $method
        ) {

            if (
                !empty(
                    $this->routes[$method]
                )
            ) {

                $index =
                    array_key_last(
                        $this->routes[$method]
                    );


                $this->routes[$method][$index]['name'] =
                    $name;


                $this->namedRoutes[$name] = [
                    'method' => $method,
                    'path' =>
                        $this->routes[$method][$index]['path'],
                ];


                return $this;
            }
        }


        return $this;
    }


    /**
     * Generate URL from route name.
     */
    public function route(
        string $name,
        array $params = []
    ): ?string {

        if (
            !isset(
                $this->namedRoutes[$name]
            )
        ) {

            return null;
        }


        $path =
            $this->namedRoutes[$name]['path'];


        foreach ($params as $key => $value) {

            $placeholder =
                '{' .
                $key .
                '}';


            $path = str_replace(
                $placeholder,
                rawurlencode(
                    (string) $value
                ),
                $path
            );
        }


        /**
         * Verify that all route parameters
         * have been replaced.
         */
        if (
            preg_match(
                '/\{[a-zA-Z0-9_]+\}/',
                $path
            )
        ) {

            return null;
        }


        return $path;
    }


    /**
     * Alias.
     */
    public function getRouteByName(
        string $name,
        array $params = []
    ): ?string {

        return $this->route(
            $name,
            $params
        );
    }


    // =========================================================================
    // GROUPS
    // =========================================================================


    /**
     * Register a route group.
     *
     * Supported:
     *
     * group('/api', function ($router) {})
     *
     * group('/api', ['auth'], function ($router) {})
     */
    public function group(
        string $path,
        array|callable $middlewares = [],
        ?callable $callback = null
    ): self {

        /**
         * group('/x', function(){})
         */
        if (
            is_callable($middlewares) &&
            $callback === null
        ) {

            $callback = $middlewares;

            $middlewares = [];
        }


        $oldGroup =
            $this->currentGroup;


        $oldMiddlewares =
            $this->currentMiddlewares;


        /**
         * Build group prefix.
         */
        if ($path !== '') {

            if (
                $this->currentGroup === ''
            ) {

                $this->currentGroup =
                    $this->normalizePath($path);

            } else {

                $this->currentGroup =
                    $this->normalizePath(
                        $this->currentGroup .
                        '/' .
                        ltrim($path, '/')
                    );
            }
        }


        /**
         * Add group middleware.
         */
        $this->currentMiddlewares =
            array_merge(
                $this->currentMiddlewares,
                array_values($middlewares)
            );


        try {

            if (
                $callback !== null
            ) {

                $callback($this);
            }

        } finally {

            /**
             * Always restore previous group,
             * even when an exception occurs.
             */
            $this->currentGroup =
                $oldGroup;


            $this->currentMiddlewares =
                $oldMiddlewares;
        }


        return $this;
    }


    /**
     * Add prefix to current group.
     */
    public function prefix(string $prefix): self
    {
        if ($prefix === '') {

            return $this;
        }


        if ($this->currentGroup === '') {

            $this->currentGroup =
                $this->normalizePath(
                    $prefix
                );

        } else {

            $this->currentGroup =
                $this->normalizePath(
                    $this->currentGroup .
                    '/' .
                    ltrim($prefix, '/')
                );
        }


        return $this;
    }


    /**
     * Add middleware to current group.
     */
    public function middleware(
        string|array $middlewares
    ): self {

        if (
            is_string($middlewares)
        ) {

            $middlewares = [
                $middlewares
            ];
        }


        $this->currentMiddlewares =
            array_merge(
                $this->currentMiddlewares,
                array_values($middlewares)
            );


        return $this;
    }


    // =========================================================================
    // MIDDLEWARE
    // =========================================================================


    /**
     * Register middleware alias.
     *
     * Example:
     *
     * $router->addMiddleware(
     *     'auth',
     *     AuthMiddleware::class
     * );
     */
    public function addMiddleware(
        string $name,
        mixed $middleware
    ): self {

        if ($name === '') {

            throw new \InvalidArgumentException(
                'Middleware name cannot be empty.'
            );
        }


        $this->middlewares[$name] =
            $middleware;


        return $this;
    }


    /**
     * Check whether middleware exists.
     */
    public function hasMiddleware(
        string $name
    ): bool {

        return isset(
            $this->middlewares[$name]
        );
    }


    /**
     * Resolve middleware.
     */
    private function resolveMiddleware(
        mixed $middleware
    ): mixed {

        /**
         * Registered alias.
         */
        if (
            is_string($middleware) &&
            isset(
                $this->middlewares[$middleware]
            )
        ) {

            return $this->middlewares[$middleware];
        }


        /**
         * Fully-qualified middleware class.
         */
        if (
            is_string($middleware) &&
            class_exists($middleware)
        ) {

            return $middleware;
        }


        /**
         * Callable middleware.
         */
        if (
            is_callable($middleware)
        ) {

            return $middleware;
        }


        return null;
    }


    /**
     * Execute route middleware.
     *
     * Middleware may return:
     *
     * true
     * null
     *
     * to continue.
     *
     * false
     * to stop.
     */
    private function runMiddlewares(
        array $middlewares,
        array $request
    ): bool {

        foreach (
            $middlewares
            as $middleware
        ) {

            $resolved =
                $this->resolveMiddleware(
                    $middleware
                );


            /**
             * Missing middleware must NOT be
             * silently ignored.
             */
            if ($resolved === null) {

                throw new \RuntimeException(
                    sprintf(
                        'Middleware "%s" is not registered or does not exist.',
                        is_string($middleware)
                            ? $middleware
                            : get_debug_type($middleware)
                    )
                );
            }


            /**
             * Class middleware.
             */
            if (
                is_string($resolved) &&
                class_exists($resolved)
            ) {

                $instance =
                    new $resolved();


                if (
                    !method_exists(
                        $instance,
                        'handle'
                    )
                ) {

                    throw new \RuntimeException(
                        "Middleware '{$resolved}' must define handle()."
                    );
                }


                $result =
                    $instance->handle(
                        $request
                    );


                if ($result === false) {

                    return false;
                }


                continue;
            }


            /**
             * Callable middleware.
             */
            if (
                is_callable($resolved)
            ) {

                $result =
                    $resolved(
                        $request
                    );


                if ($result === false) {

                    return false;
                }
            }
        }


        return true;
    }


    // =========================================================================
    // DISPATCH
    // =========================================================================


    /**
     * Dispatch current HTTP request.
     */
    public function dispatch(): void
    {
        try {

            $method =
                strtoupper(
                    $_SERVER['REQUEST_METHOD']
                    ?? 'GET'
                );


            if (
                !in_array(
                    $method,
                    $this->allowedMethods,
                    true
                )
            ) {

                $this->abort(
                    'طريقة الطلب غير مسموحة',
                    'METHOD_NOT_ALLOWED',
                    405
                );
            }


            $uri =
                $_SERVER['REQUEST_URI']
                ?? '/';


            $path =
                parse_url(
                    $uri,
                    PHP_URL_PATH
                );


            if (
                !is_string($path) ||
                $path === ''
            ) {

                $path = '/';
            }


            /**
             * Remove configured base path.
             */
            $path =
                $this->removeBasePath(
                    $path
                );


            /**
             * Normalize path.
             */
            $path =
                $this->normalizePath(
                    $path
                );


            /**
             * Reset parameters for every request.
             */
            $this->routeParams = [];


            $matchedRoute =
                null;


            /**
             * Search matching route.
             */
            foreach (
                $this->routes[$method] ?? []
                as $route
            ) {

                if (
                    $this->matchRoute(
                        $route['path'],
                        $path
                    )
                ) {

                    $matchedRoute =
                        $route;

                    break;
                }
            }


            /**
             * No route.
             */
            if (
                $matchedRoute === null
            ) {

                /**
                 * HEAD may fall back to GET.
                 */
                if (
                    $method === 'HEAD'
                ) {

                    foreach (
                        $this->routes['GET'] ?? []
                        as $route
                    ) {

                        if (
                            $this->matchRoute(
                                $route['path'],
                                $path
                            )
                        ) {

                            $matchedRoute =
                                $route;

                            break;
                        }
                    }
                }
            }


            if (
                $matchedRoute === null
            ) {

                $this->handleNotFound();

                return;
            }


            $this->currentRoute =
                $matchedRoute['path'];


            /**
             * Build request object.
             */
            $request =
                $this->prepareRequest(
                    $method,
                    $path
                );


            /**
             * Execute middleware.
             */
            if (
                !$this->runMiddlewares(
                    $matchedRoute['middlewares'],
                    $request
                )
            ) {

                return;
            }


            /**
             * Execute route callback.
             */
            $this->executeCallback(
                $matchedRoute['callback']
            );

        } catch (Throwable $exception) {

            $this->handleError(
                $exception
            );
        }
    }


    // =========================================================================
    // REQUEST
    // =========================================================================


    /**
     * Prepare normalized request data.
     */
    private function prepareRequest(
        string $method,
        string $path
    ): array {

        return [
            'method' => $method,

            'path' => $path,

            'params' => $this->routeParams,

            'query' => $_GET,

            'body' =>
                $this->getRequestBody(),

            'headers' =>
                $this->getRequestHeaders(),

            'ip' =>
                $this->getClientIp(),

            'user_agent' =>
                $_SERVER['HTTP_USER_AGENT']
                ?? '',

            'content_type' =>
                $_SERVER['CONTENT_TYPE']
                ?? '',

            'content_length' =>
                $_SERVER['CONTENT_LENGTH']
                ?? null,
        ];
    }


    /**
     * Read request headers.
     */
    private function getRequestHeaders(): array
    {
        /**
         * Apache / PHP-FPM.
         */
        if (
            function_exists('getallheaders')
        ) {

            $headers =
                getallheaders();


            if (
                is_array($headers)
            ) {

                return $headers;
            }
        }


        /**
         * Fallback for environments where
         * getallheaders() is unavailable.
         */
        $headers = [];


        foreach (
            $_SERVER
            as $key => $value
        ) {

            if (
                !str_starts_with(
                    $key,
                    'HTTP_'
                )
            ) {

                continue;
            }


            $name =
                str_replace(
                    ' ',
                    '-',
                    ucwords(
                        strtolower(
                            str_replace(
                                '_',
                                ' ',
                                substr(
                                    $key,
                                    5
                                )
                            )
                        )
                    )
                );


            $headers[$name] =
                (string) $value;
        }


        return $headers;
    }


    /**
     * Read request body.
     */
    private function getRequestBody(): array
    {
        $contentType =
            strtolower(
                $_SERVER['CONTENT_TYPE']
                ?? ''
            );


        /**
         * JSON request.
         */
        if (
            str_contains(
                $contentType,
                'application/json'
            )
        ) {

            $input =
                file_get_contents(
                    'php://input'
                );


            if (
                $input === false ||
                trim($input) === ''
            ) {

                return [];
            }


            try {

                $data =
                    json_decode(
                        $input,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );


                return is_array($data)
                    ? $data
                    : [];

            } catch (\JsonException $exception) {

                throw new \RuntimeException(
                    'صيغة JSON غير صالحة.',
                    0,
                    $exception
                );
            }
        }


        /**
         * Multipart forms.
         */
        if (
            str_contains(
                $contentType,
                'multipart/form-data'
            )
        ) {

            return $_POST;
        }


        /**
         * Standard form.
         */
        return $_POST;
    }


    /**
     * Get client IP.
     *
     * لا نعتمد على X-Forwarded-For إلا إذا
     * كان Proxy موثوقًا مفعلاً من إعدادات النظام.
     */
    private function getClientIp(): string
    {
        $remote =
            $_SERVER['REMOTE_ADDR']
            ?? '';


        $trustedProxy =
            filter_var(
                getenv('TRUST_PROXY')
                ?: 'false',
                FILTER_VALIDATE_BOOLEAN
            );


        if (
            $trustedProxy &&
            !empty(
                $_SERVER['HTTP_X_FORWARDED_FOR']
            )
        ) {

            $forwarded =
                explode(
                    ',',
                    $_SERVER[
                        'HTTP_X_FORWARDED_FOR'
                    ]
                );


            foreach (
                $forwarded
                as $candidate
            ) {

                $candidate =
                    trim($candidate);


                if (
                    filter_var(
                        $candidate,
                        FILTER_VALIDATE_IP
                    )
                ) {

                    return $candidate;
                }
            }
        }


        if (
            filter_var(
                $remote,
                FILTER_VALIDATE_IP
            )
        ) {

            return $remote;
        }


        return '';
    }


    // =========================================================================
    // ROUTE MATCHING
    // =========================================================================


    /**
     * Match route against request path.
     *
     * Supports:
     *
     * /products
     *
     * /products/{id}
     *
     * /warehouses/{warehouseId}/stock
     */
    private function matchRoute(
        string $routePath,
        string $requestPath
    ): bool {

        $routePath =
            $this->normalizePath(
                $routePath
            );


        $requestPath =
            $this->normalizePath(
                $requestPath
            );


        /**
         * Exact route.
         */
        if (
            $routePath === $requestPath
        ) {

            $this->routeParams = [];

            return true;
        }


        /**
         * No parameters.
         */
        if (
            !str_contains(
                $routePath,
                '{'
            )
        ) {

            return false;
        }


        /**
         * Extract parameter names.
         */
        preg_match_all(
            '/\{([a-zA-Z0-9_]+)\}/',
            $routePath,
            $parameterMatches
        );


        $parameterNames =
            $parameterMatches[1]
            ?? [];


        /**
         * Escape route path.
         */
        $pattern =
            preg_quote(
                $routePath,
                '#'
            );


        /**
         * Replace escaped parameters.
         */
        $pattern =
            preg_replace(
                '/\\\\\{[a-zA-Z0-9_]+\\\\\}/',
                '([a-zA-Z0-9_-]+)',
                $pattern
            );


        if (
            !is_string($pattern)
        ) {

            return false;
        }


        $pattern =
            '#^' .
            $pattern .
            '$#';


        $matches = [];


        if (
            preg_match(
                $pattern,
                $requestPath,
                $matches
            ) !== 1
        ) {

            return false;
        }


        array_shift(
            $matches
        );


        $this->routeParams = [];


        foreach (
            $parameterNames
            as $index => $name
        ) {

            $this->routeParams[$name] =
                $matches[$index]
                ?? null;
        }


        return true;
    }


    // =========================================================================
    // CALLBACK EXECUTION
    // =========================================================================


    /**
     * Execute route callback.
     *
     * Primary API routes use closures.
     */
    private function executeCallback(
        callable $callback
    ): void {

        /**
         * Route callback may accept:
         *
         * - no arguments
         * - route parameters
         * - request
         *
         * We inspect the callback signature
         * instead of blindly passing parameters.
         */
        $reflection =
            $this->reflectCallable(
                $callback
            );


        $parameters =
            $reflection->getParameters();


        $arguments = [];


        foreach (
            $parameters
            as $parameter
        ) {

            $name =
                $parameter->getName();


            /**
             * Named route parameter.
             */
            if (
                array_key_exists(
                    $name,
                    $this->routeParams
                )
            ) {

                $arguments[] =
                    $this->routeParams[$name];

                continue;
            }


            /**
             * Request parameter.
             */
            if (
                $name === 'request'
            ) {

                $arguments[] =
                    $this->prepareRequest(
                        $_SERVER['REQUEST_METHOD']
                        ?? 'GET',
                        $this->getCurrentRequestPath()
                    );

                continue;
            }


            /**
             * If optional parameter,
             * allow default value.
             */
            if (
                $parameter->isOptional()
            ) {

                continue;
            }


            /**
             * Unknown required parameter.
             */
            throw new \RuntimeException(
                sprintf(
                    'Unable to resolve callback parameter "$%s".',
                    $name
                )
            );
        }


        call_user_func_array(
            $callback,
            $arguments
        );
    }


    /**
     * Reflect callable.
     */
    private function reflectCallable(
        callable $callback
    ): \ReflectionFunctionAbstract {

        if (
            is_array($callback)
        ) {

            return new \ReflectionMethod(
                $callback[0],
                $callback[1]
            );
        }


        if (
            is_string($callback) &&
            str_contains(
                $callback,
                '@'
            )
        ) {

            [$class, $method] =
                explode(
                    '@',
                    $callback,
                    2
                );


            return new \ReflectionMethod(
                $class,
                $method
            );
        }


        if (
            $callback instanceof \Closure
        ) {

            return new \ReflectionFunction(
                $callback
            );
        }


        if (
            is_object($callback) &&
            method_exists(
                $callback,
                '__invoke'
            )
        ) {

            return new \ReflectionMethod(
                $callback,
                '__invoke'
            );
        }


        return new \ReflectionFunction(
            $callback
        );
    }


    /**
     * Current request path.
     */
    private function getCurrentRequestPath(): string
    {
        $uri =
            $_SERVER['REQUEST_URI']
            ?? '/';


        $path =
            parse_url(
                $uri,
                PHP_URL_PATH
            );


        return is_string($path)
            ? $this->normalizePath($path)
            : '/';
    }


    // =========================================================================
    // PATH HANDLING
    // =========================================================================


    /**
     * Normalize path.
     */
    private function normalizePath(
        string $path
    ): string {

        $path =
            trim($path);


        if ($path === '') {

            return '/';
        }


        /**
         * Convert backslashes to slashes.
         */
        $path =
            str_replace(
                '\\',
                '/',
                $path
            );


        /**
         * Remove duplicate slashes.
         */
        $path =
            preg_replace(
                '#/{2,}#',
                '/',
                $path
            );


        /**
         * Remove query string if present.
         */
        $path =
            explode(
                '?',
                $path,
                2
            )[0];


        /**
         * Ensure leading slash.
         */
        $path =
            '/' .
            ltrim(
                $path,
                '/'
            );


        /**
         * Remove trailing slash,
         * except root.
         */
        if (
            $path !== '/'
        ) {

            $path =
                rtrim(
                    $path,
                    '/'
                );
        }


        return $path;
    }


    /**
     * Normalize base path.
     */
    private function normalizeBasePath(
        string $basePath
    ): string {

        if (
            $basePath === ''
        ) {

            return '';
        }


        $basePath =
            $this->normalizePath(
                $basePath
            );


        if (
            $basePath === '/'
        ) {

            return '';
        }


        return $basePath;
    }


    /**
     * Remove configured base path.
     */
    private function removeBasePath(
        string $path
    ): string {

        $path =
            $this->normalizePath(
                $path
            );


        if (
            $this->basePath === ''
        ) {

            return $path;
        }


        if (
            $path === $this->basePath
        ) {

            return '/';
        }


        $prefix =
            $this->basePath .
            '/';


        if (
            str_starts_with(
                $path,
                $prefix
            )
        ) {

            return $this->normalizePath(
                substr(
                    $path,
                    strlen(
                        $this->basePath
                    )
                )
            );
        }


        return $path;
    }


    // =========================================================================
    // ERROR HANDLING
    // =========================================================================


    /**
     * Setup default handlers.
     */
    private function setupDefaultHandlers(): void
    {
        $this->notFoundHandler =
            function (): void {

                $this->abort(
                    'المسار غير موجود',
                    'ROUTE_NOT_FOUND',
                    404
                );
            };


        $this->errorHandler =
            function (
                Throwable $exception
            ): void {

                error_log(
                    sprintf(
                        '[ROUTER ERROR] %s in %s:%d',
                        $exception->getMessage(),
                        $exception->getFile(),
                        $exception->getLine()
                    )
                );


                $debug =
                    filter_var(
                        getenv('APP_DEBUG')
                        ?: 'false',
                        FILTER_VALIDATE_BOOLEAN
                    );


                $this->abort(
                    $debug
                        ? $exception->getMessage()
                        : 'حدث خطأ داخلي في الخادم',
                    'INTERNAL_ERROR',
                    500
                );
            };
    }


    /**
     * Set 404 handler.
     */
    public function setNotFoundHandler(
        callable $callback
    ): self {

        $this->notFoundHandler =
            $callback;

        return $this;
    }


    /**
     * Set exception handler.
     */
    public function setErrorHandler(
        callable $callback
    ): self {

        $this->errorHandler =
            $callback;

        return $this;
    }


    /**
     * Handle 404.
     */
    private function handleNotFound(): void
    {
        if (
            is_callable(
                $this->notFoundHandler
            )
        ) {

            call_user_func(
                $this->notFoundHandler
            );

            return;
        }


        $this->abort(
            'المسار غير موجود',
            'ROUTE_NOT_FOUND',
            404
        );
    }


    /**
     * Handle exception.
     */
    private function handleError(
        Throwable $exception
    ): void {

        if (
            is_callable(
                $this->errorHandler
            )
        ) {

            call_user_func(
                $this->errorHandler,
                $exception
            );

            return;
        }


        /**
         * Last-resort fallback.
         */
        error_log(
            '[ROUTER FATAL] ' .
            $exception->getMessage()
        );


        $this->abort(
            'حدث خطأ داخلي في الخادم',
            'INTERNAL_ERROR',
            500
        );
    }


    /**
     * Unified error response.
     */
    private function abort(
        string $message,
        string $code,
        int $status
    ): never {

        http_response_code(
            $status
        );


        header(
            'Content-Type: application/json; charset=UTF-8'
        );


        echo json_encode(
            [
                'success' => false,

                'data' => null,

                'message' => $message,

                'code' => $code,

                'timestamp' =>
                    date('Y-m-d H:i:s'),

                'version' =>
                    getenv('APP_VERSION')
                    ?: '5.0.0',
            ],
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );


        exit;
    }


    // =========================================================================
    // ROUTE INFORMATION
    // =========================================================================


    /**
     * Get all routes.
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }


    /**
     * Get named routes.
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }


    /**
     * Get current route.
     */
    public function getCurrentRoute(): string
    {
        return $this->currentRoute;
    }


    /**
     * Get current route parameters.
     */
    public function getRouteParams(): array
    {
        return $this->routeParams;
    }


    /**
     * Check route existence.
     */
    public function hasRoute(
        string $method,
        string $path
    ): bool {

        $method =
            strtoupper(
                $method
            );


        $path =
            $this->normalizePath(
                $path
            );


        if (
            !isset(
                $this->routes[$method]
            )
        ) {

            return false;
        }


        foreach (
            $this->routes[$method]
            as $route
        ) {

            if (
                $route['path'] === $path
            ) {

                return true;
            }
        }


        return false;
    }


    /**
     * Remove route.
     */
    public function removeRoute(
        string $method,
        string $path
    ): bool {

        $method =
            strtoupper(
                $method
            );


        $path =
            $this->normalizePath(
                $path
            );


        if (
            !isset(
                $this->routes[$method]
            )
        ) {

            return false;
        }


        foreach (
            $this->routes[$method]
            as $index => $route
        ) {

            if (
                $route['path'] === $path
            ) {

                unset(
                    $this->routes[$method][$index]
                );


                $this->routes[$method] =
                    array_values(
                        $this->routes[$method]
                    );


                return true;
            }
        }


        return false;
    }


    /**
     * Return routes as JSON.
     */
    public function toJson(): string
    {
        return json_encode(
            $this->routes,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }


    /**
     * Development-only route dump.
     */
    public function dump(): void
    {
        echo '<pre>';
        print_r(
            $this->routes
        );
        echo '</pre>';
    }
}

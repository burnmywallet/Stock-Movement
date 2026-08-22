<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/middleware/CorsMiddleware.php
// الوصف: ميدل وير إدارة CORS - التحكم في الطلبات من نطاقات مختلفة
// التاريخ: 2026-08-22
// ================================================================

namespace Middleware;

use Exception;

class CorsMiddleware
{
    /**
     * @var array $allowedOrigins - النطاقات المسموحة
     */
    private $allowedOrigins = [];
    
    /**
     * @var array $allowedMethods - الطرق المسموحة
     */
    private $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
    
    /**
     * @var array $allowedHeaders - الرؤوس المسموحة
     */
    private $allowedHeaders = [
        'Authorization',
        'Content-Type',
        'Accept',
        'X-Requested-With',
        'Origin',
        'X-CSRF-Token',
        'X-API-Key',
        'X-Session-Token'
    ];
    
    /**
     * @var array $exposedHeaders - الرؤوس المكشوفة
     */
    private $exposedHeaders = [
        'Authorization',
        'X-Session-Id',
        'X-User-Id',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset'
    ];
    
    /**
     * @var int $maxAge - مدة التخزين المؤقت للـ Preflight (بالثواني)
     */
    private $maxAge = 86400; // 24 ساعة
    
    /**
     * @var bool $allowCredentials - السماح بإرسال بيانات الاعتماد
     */
    private $allowCredentials = true;
    
    /**
     * @var bool $debug - وضع التصحيح
     */
    private $debug = false;

    public function __construct()
    {
        $this->debug = ($_ENV['APP_DEBUG'] ?? false) === 'true';
        $this->loadSettings();
    }

    /**
     * تحميل الإعدادات من البيئة
     */
    private function loadSettings(): void
    {
        // قراءة النطاقات المسموحة من البيئة
        $origins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
        if (!empty($origins)) {
            $this->allowedOrigins = array_map('trim', explode(',', $origins));
        }
        
        // إضافة النطاق المحلي
        $appUrl = $_ENV['APP_URL'] ?? 'http://localhost';
        if (!in_array($appUrl, $this->allowedOrigins)) {
            $this->allowedOrigins[] = $appUrl;
        }
        
        // إضافة localhost للتصحيح
        if ($this->debug) {
            $this->allowedOrigins[] = 'http://localhost';
            $this->allowedOrigins[] = 'http://localhost:8080';
            $this->allowedOrigins[] = 'http://localhost:8081';
            $this->allowedOrigins[] = 'http://127.0.0.1';
            $this->allowedOrigins[] = 'http://127.0.0.1:8080';
        }
        
        // السماح بجميع النطاقات في التطوير
        if ($_ENV['APP_ENV'] ?? 'production' === 'development') {
            $this->allowedOrigins[] = '*';
        }
    }

    /**
     * معالجة الطلب - تعيين رؤوس CORS
     */
    public function handle(): bool
    {
        // الحصول على Origin
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // معالجة طلب OPTIONS (Preflight)
        if ($requestMethod === 'OPTIONS') {
            $this->handleOptionsRequest($origin);
            exit;
        }

        // تعيين رؤوس CORS للطلبات العادية
        $this->setCorsHeaders($origin);
        
        // معالجة الطلبات من نطاقات غير مسموحة
        if (!$this->isOriginAllowed($origin) && $origin !== '') {
            if ($this->debug) {
                // في وضع التصحيح، نسمح بجميع النطاقات مع تحذير
                header('X-CORS-Warning: Origin not allowed - ' . $origin);
            } else {
                // في الإنتاج، نمنع الطلب
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Origin not allowed',
                    'code' => 'CORS_ORIGIN_DENIED',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                exit;
            }
        }

        // التحقق من الطريقة
        if (!in_array($requestMethod, $this->allowedMethods)) {
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed',
                'code' => 'CORS_METHOD_NOT_ALLOWED',
                'allowed_methods' => $this->allowedMethods,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            exit;
        }

        return true;
    }

    /**
     * معالجة طلب OPTIONS (Preflight)
     */
    private function handleOptionsRequest(string $origin): void
    {
        // تعيين رؤوس CORS
        $this->setCorsHeaders($origin);
        
        // رؤوس إضافية لـ Preflight
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        header('Access-Control-Max-Age: ' . $this->maxAge);
        
        // رؤوس الأمان
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        http_response_code(200);
        echo '';
    }

    /**
     * تعيين رؤوس CORS
     */
    private function setCorsHeaders(string $origin): void
    {
        // تحديد Origin المسموح
        if ($this->isOriginAllowed($origin) || $origin === '') {
            $allowedOrigin = $origin ?: '*';
        } else {
            $allowedOrigin = in_array('*', $this->allowedOrigins) ? '*' : $this->allowedOrigins[0] ?? '*';
        }
        
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        header('Access-Control-Expose-Headers: ' . implode(', ', $this->exposedHeaders));
        
        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }
        
        // رؤوس إضافية للأمان
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // رؤوس الأداء
        header('Vary: Origin');
    }

    /**
     * التحقق من Origin المسموح
     */
    private function isOriginAllowed(string $origin): bool
    {
        // السماح لجميع النطاقات
        if (in_array('*', $this->allowedOrigins)) {
            return true;
        }
        
        // التحقق المباشر
        if (in_array($origin, $this->allowedOrigins)) {
            return true;
        }
        
        // التحقق من النطاقات الفرعية (مثل: *.example.com)
        foreach ($this->allowedOrigins as $allowed) {
            if (strpos($allowed, '*') !== false) {
                $pattern = str_replace('*', '.*', preg_quote($allowed, '/'));
                if (preg_match('/^' . $pattern . '$/', $origin)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * إضافة Origin مسموح
     */
    public function addAllowedOrigin(string $origin): self
    {
        if (!in_array($origin, $this->allowedOrigins)) {
            $this->allowedOrigins[] = $origin;
        }
        return $this;
    }

    /**
     * إزالة Origin مسموح
     */
    public function removeAllowedOrigin(string $origin): self
    {
        $key = array_search($origin, $this->allowedOrigins);
        if ($key !== false) {
            unset($this->allowedOrigins[$key]);
        }
        return $this;
    }

    /**
     * إضافة طريقة مسموحة
     */
    public function addAllowedMethod(string $method): self
    {
        $method = strtoupper($method);
        if (!in_array($method, $this->allowedMethods)) {
            $this->allowedMethods[] = $method;
        }
        return $this;
    }

    /**
     * إزالة طريقة مسموحة
     */
    public function removeAllowedMethod(string $method): self
    {
        $method = strtoupper($method);
        $key = array_search($method, $this->allowedMethods);
        if ($key !== false) {
            unset($this->allowedMethods[$key]);
        }
        return $this;
    }

    /**
     * إضافة رأس مسموح
     */
    public function addAllowedHeader(string $header): self
    {
        if (!in_array($header, $this->allowedHeaders)) {
            $this->allowedHeaders[] = $header;
        }
        return $this;
    }

    /**
     * إزالة رأس مسموح
     */
    public function removeAllowedHeader(string $header): self
    {
        $key = array_search($header, $this->allowedHeaders);
        if ($key !== false) {
            unset($this->allowedHeaders[$key]);
        }
        return $this;
    }

    /**
     * إضافة رأس مكشوف
     */
    public function addExposedHeader(string $header): self
    {
        if (!in_array($header, $this->exposedHeaders)) {
            $this->exposedHeaders[] = $header;
        }
        return $this;
    }

    /**
     * إزالة رأس مكشوف
     */
    public function removeExposedHeader(string $header): self
    {
        $key = array_search($header, $this->exposedHeaders);
        if ($key !== false) {
            unset($this->exposedHeaders[$key]);
        }
        return $this;
    }

    /**
     * تعيين مدة التخزين المؤقت
     */
    public function setMaxAge(int $seconds): self
    {
        $this->maxAge = $seconds;
        return $this;
    }

    /**
     * تفعيل/تعطيل إرسال بيانات الاعتماد
     */
    public function setAllowCredentials(bool $allow): self
    {
        $this->allowCredentials = $allow;
        return $this;
    }

    /**
     * الحصول على النطاقات المسموحة
     */
    public function getAllowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    /**
     * الحصول على الطرق المسموحة
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

    /**
     * الحصول على الرؤوس المسموحة
     */
    public function getAllowedHeaders(): array
    {
        return $this->allowedHeaders;
    }

    /**
     * الحصول على الرؤوس المكشوفة
     */
    public function getExposedHeaders(): array
    {
        return $this->exposedHeaders;
    }

    /**
     * التحقق من وجود Origin في القائمة
     */
    public function originExists(string $origin): bool
    {
        return $this->isOriginAllowed($origin);
    }

    /**
     * إعادة تعيين جميع الإعدادات إلى الافتراضية
     */
    public function reset(): self
    {
        $this->allowedOrigins = [];
        $this->allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
        $this->allowedHeaders = [
            'Authorization',
            'Content-Type',
            'Accept',
            'X-Requested-With',
            'Origin',
            'X-CSRF-Token',
            'X-API-Key',
            'X-Session-Token'
        ];
        $this->exposedHeaders = [
            'Authorization',
            'X-Session-Id',
            'X-User-Id',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset'
        ];
        $this->maxAge = 86400;
        $this->allowCredentials = true;
        $this->loadSettings();
        return $this;
    }

    /**
     * الحصول على معلومات CORS للتطوير
     */
    public function getInfo(): array
    {
        return [
            'allowed_origins' => $this->allowedOrigins,
            'allowed_methods' => $this->allowedMethods,
            'allowed_headers' => $this->allowedHeaders,
            'exposed_headers' => $this->exposedHeaders,
            'max_age' => $this->maxAge,
            'allow_credentials' => $this->allowCredentials,
            'debug' => $this->debug
        ];
    }
}

// ================================================================
// انتهى الملف
// ================================================================

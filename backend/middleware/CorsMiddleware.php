<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/middleware/CorsMiddleware.php
// الوصف: ميدل وير إدارة CORS
// الإصدار: 2.0 Production Ready
// التاريخ: 2026-08-20
// ================================================================

namespace Middleware;

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
    private $allowedHeaders = ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'];
    
    /**
     * @var array $exposedHeaders - الرؤوس المكشوفة
     */
    private $exposedHeaders = ['Authorization'];
    
    /**
     * @var int $maxAge - مدة التخزين المؤقت
     */
    private $maxAge = 86400;

    public function __construct()
    {
        $this->loadSettings();
    }

    /**
     * تحميل الإعدادات
     */
    private function loadSettings(): void
    {
        // قراءة النطاقات المسموحة من البيئة
        $origins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
        if (!empty($origins)) {
            $this->allowedOrigins = array_map('trim', explode(',', $origins));
        }
        
        // إضافة النطاق المحلي
        $this->allowedOrigins[] = $_ENV['APP_URL'] ?? 'http://localhost';
        $this->allowedOrigins[] = '*'; // للشبكة المحلية
    }

    /**
     * معالجة الطلب
     */
    public function handle(): bool
    {
        // الحصول على Origin
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // التحقق من Origin
        if ($this->isOriginAllowed($origin)) {
            $this->setCorsHeaders($origin);
        } else {
            // إذا لم يكن Origin مسموحاً، استخدم القيم الافتراضية للشبكة المحلية
            $this->setCorsHeaders('*');
        }

        // معالجة طلب OPTIONS (Preflight)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->handleOptionsRequest();
            exit;
        }

        return true;
    }

    /**
     * التحقق من Origin
     */
    private function isOriginAllowed(string $origin): bool
    {
        // السماح لجميع النطاقات في الشبكة المحلية
        if (in_array('*', $this->allowedOrigins)) {
            return true;
        }
        
        return in_array($origin, $this->allowedOrigins);
    }

    /**
     * تعيين رؤوس CORS
     */
    private function setCorsHeaders(string $origin): void
    {
        header("Access-Control-Allow-Origin: {$origin}");
        header("Access-Control-Allow-Methods: " . implode(', ', $this->allowedMethods));
        header("Access-Control-Allow-Headers: " . implode(', ', $this->allowedHeaders));
        header("Access-Control-Expose-Headers: " . implode(', ', $this->exposedHeaders));
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Max-Age: {$this->maxAge}");
        
        // إضافة رؤوس أمان إضافية
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    }

    /**
     * معالجة طلب OPTIONS
     */
    private function handleOptionsRequest(): void
    {
        http_response_code(200);
        echo '';
        exit;
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
     * تعيين مدة التخزين المؤقت
     */
    public function setMaxAge(int $seconds): self
    {
        $this->maxAge = $seconds;
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
}

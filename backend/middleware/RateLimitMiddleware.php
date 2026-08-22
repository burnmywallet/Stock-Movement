<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/middleware/RateLimitMiddleware.php
// الوصف: ميدل وير تحديد معدل الطلبات - منع الهجمات والتجاوزات
// التاريخ: 2026-08-22
// ================================================================

namespace Middleware;

use Core\Database;
use Core\Audit;
use Exception;

class RateLimitMiddleware
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var Audit $audit - سجل التدقيق
     */
    private $audit;
    
    /**
     * @var int $maxRequests - الحد الأقصى للطلبات
     */
    private $maxRequests = 60;
    
    /**
     * @var int $windowMinutes - النافذة الزمنية بالدقائق
     */
    private $windowMinutes = 1;
    
    /**
     * @var int $maxRequestsPerHour - الحد الأقصى للطلبات في الساعة
     */
    private $maxRequestsPerHour = 1000;
    
    /**
     * @var int $maxRequestsPerDay - الحد الأقصى للطلبات في اليوم
     */
    private $maxRequestsPerDay = 10000;
    
    /**
     * @var array $excludedRoutes - المسارات المستثناة
     */
    private $excludedRoutes = [
        '/api/auth/login',
        '/api/auth/validate',
        '/api/health',
        '/test'
    ];
    
    /**
     * @var array $excludedIps - عناوين IP المستثناة
     */
    private $excludedIps = [
        '127.0.0.1',
        '::1'
    ];
    
    /**
     * @var bool $enabled - تفعيل/تعطيل الميدل وير
     */
    private $enabled = true;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->audit = new Audit();
        $this->loadSettings();
    }

    /**
     * تحميل الإعدادات من قاعدة البيانات
     */
    private function loadSettings(): void
    {
        try {
            $settings = $this->db->query("
                SELECT setting_key, setting_value FROM system_settings 
                WHERE setting_key IN (
                    'rate_limit_enabled',
                    'rate_limit_requests', 
                    'rate_limit_window',
                    'rate_limit_per_hour',
                    'rate_limit_per_day'
                )
            ");
            
            foreach ($settings as $setting) {
                switch ($setting['setting_key']) {
                    case 'rate_limit_enabled':
                        $this->enabled = $setting['setting_value'] === 'true';
                        break;
                    case 'rate_limit_requests':
                        $this->maxRequests = (int)$setting['setting_value'];
                        break;
                    case 'rate_limit_window':
                        $this->windowMinutes = (int)$setting['setting_value'];
                        break;
                    case 'rate_limit_per_hour':
                        $this->maxRequestsPerHour = (int)$setting['setting_value'];
                        break;
                    case 'rate_limit_per_day':
                        $this->maxRequestsPerDay = (int)$setting['setting_value'];
                        break;
                }
            }
        } catch (Exception $e) {
            // استخدام القيم الافتراضية
        }
    }

    /**
     * معالجة الطلب - التحقق من معدل الطلبات
     */
    public function handle(): bool
    {
        // التحقق من التفعيل
        if (!$this->enabled) {
            return true;
        }

        $path = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($path, PHP_URL_PATH);
        
        // إزالة base path
        $basePath = $_ENV['BASE_PATH'] ?? '';
        if (!empty($basePath) && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
            $path = '/' . ltrim($path, '/');
        }
        
        // التحقق من المسارات المستثناة
        if ($this->isExcludedRoute($path)) {
            return true;
        }

        // الحصول على معرف المستخدم أو IP
        $identifier = $this->getIdentifier();
        
        if (!$identifier) {
            return true;
        }

        // التحقق من IPs المستثناة
        if ($this->isExcludedIp($identifier)) {
            return true;
        }

        // التحقق من معدل الطلبات (الدقيقة)
        if ($this->isRateLimited($identifier, $this->windowMinutes, $this->maxRequests)) {
            $this->rateLimited(
                'تم تجاوز الحد الأقصى للطلبات في الدقيقة',
                $this->windowMinutes,
                $this->maxRequests
            );
            return false;
        }

        // التحقق من معدل الطلبات (الساعة)
        if ($this->isRateLimited($identifier, 60, $this->maxRequestsPerHour)) {
            $this->rateLimited(
                'تم تجاوز الحد الأقصى للطلبات في الساعة',
                60,
                $this->maxRequestsPerHour
            );
            return false;
        }

        // التحقق من معدل الطلبات (اليوم)
        if ($this->isRateLimited($identifier, 1440, $this->maxRequestsPerDay)) {
            $this->rateLimited(
                'تم تجاوز الحد الأقصى للطلبات في اليوم',
                1440,
                $this->maxRequestsPerDay
            );
            return false;
        }

        // تسجيل الطلب
        $this->logRequest($identifier);

        // تعيين رؤوس معدل الطلبات
        $this->setRateLimitHeaders($identifier);

        return true;
    }

    /**
     * الحصول على معرف المستخدم
     */
    private function getIdentifier(): ?string
    {
        // استخدام معرف المستخدم إذا كان مسجلاً
        if (isset($_REQUEST['user_id'])) {
            return 'user:' . $_REQUEST['user_id'];
        }
        
        // استخدام التوكن إذا كان موجوداً
        $token = $this->getTokenFromRequest();
        if ($token) {
            return 'token:' . substr(hash('sha256', $token), 0, 16);
        }
        
        // استخدام IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return 'ip:' . $ip;
    }

    /**
     * التحقق من تجاوز الحد
     */
    private function isRateLimited(string $identifier, int $windowMinutes, int $maxRequests): bool
    {
        try {
            $windowStart = date('Y-m-d H:i:s', strtotime("-{$windowMinutes} minutes"));
            
            $count = $this->db->queryValue("
                SELECT COUNT(*) FROM rate_limit_logs 
                WHERE identifier = :identifier 
                  AND created_at > :window_start
            ", [
                'identifier' => $identifier,
                'window_start' => $windowStart
            ]);
            
            return ($count ?? 0) >= $maxRequests;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * تسجيل الطلب
     */
    private function logRequest(string $identifier): void
    {
        try {
            $this->db->insert('rate_limit_logs', [
                'identifier' => $identifier,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'user_id' => $_REQUEST['user_id'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // تنظيف السجلات القديمة (نسبة صغيرة من الطلبات)
            if (rand(1, 100) <= 5) {
                $this->cleanupOldLogs();
            }
        } catch (Exception $e) {
            // السكوت عن الخطأ - لا نريد تعطيل الطلب بسبب فشل التسجيل
        }
    }

    /**
     * تنظيف السجلات القديمة
     */
    private function cleanupOldLogs(): void
    {
        try {
            $this->db->execute(
                "DELETE FROM rate_limit_logs 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
            );
        } catch (Exception $e) {
            // السكوت عن الخطأ
        }
    }

    /**
     * تعيين رؤوس معدل الطلبات
     */
    private function setRateLimitHeaders(string $identifier): void
    {
        try {
            $windowStart = date('Y-m-d H:i:s', strtotime("-{$this->windowMinutes} minutes"));
            
            $count = $this->db->queryValue("
                SELECT COUNT(*) FROM rate_limit_logs 
                WHERE identifier = :identifier 
                  AND created_at > :window_start
            ", [
                'identifier' => $identifier,
                'window_start' => $windowStart
            ]);
            
            $remaining = max(0, $this->maxRequests - (int)$count);
            
            header('X-RateLimit-Limit: ' . $this->maxRequests);
            header('X-RateLimit-Remaining: ' . $remaining);
            header('X-RateLimit-Reset: ' . (time() + ($this->windowMinutes * 60)));
        } catch (Exception $e) {
            // السكوت عن الخطأ
        }
    }

    /**
     * التحقق من المسار المستثنى
     */
    private function isExcludedRoute(string $path): bool
    {
        foreach ($this->excludedRoutes as $excluded) {
            if ($path === $excluded || strpos($path, $excluded) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * التحقق من IP المستثنى
     */
    private function isExcludedIp(string $identifier): bool
    {
        $ip = str_replace('ip:', '', $identifier);
        return in_array($ip, $this->excludedIps);
    }

    /**
     * الحصول على التوكن من الطلب
     */
    private function getTokenFromRequest(): ?string
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        
        if (strpos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }
        
        return $_COOKIE['auth_token'] ?? null;
    }

    /**
     * إرسال استجابة تجاوز الحد
     */
    private function rateLimited(string $message, int $windowMinutes, int $maxRequests): void
    {
        $retryAfter = $windowMinutes * 60;
        
        // تسجيل محاولة التجاوز
        try {
            $this->audit->log(
                $_REQUEST['user_id'] ?? null,
                'RATE_LIMIT_EXCEEDED',
                'security',
                'تجاوز الحد الأقصى للطلبات',
                [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    'path' => $_SERVER['REQUEST_URI'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                    'window_minutes' => $windowMinutes,
                    'max_requests' => $maxRequests
                ]
            );
        } catch (Exception $e) {
            // السكوت عن الخطأ
        }

        header('HTTP/1.1 429 Too Many Requests');
        header('Content-Type: application/json');
        header("Retry-After: {$retryAfter}");
        header("X-RateLimit-Limit: {$maxRequests}");
        header("X-RateLimit-Reset: " . (time() + $retryAfter));
        
        $response = [
            'success' => false,
            'message' => $message,
            'code' => 'RATE_LIMITED',
            'retry_after' => $retryAfter,
            'retry_after_minutes' => ceil($retryAfter / 60),
            'timestamp' => date('Y-m-d H:i:s'),
            'details' => [
                'limit' => $maxRequests,
                'window_minutes' => $windowMinutes,
                'suggestion' => 'يرجى الانتظار قبل إرسال المزيد من الطلبات'
            ]
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * تعيين الحد الأقصى للطلبات
     */
    public function setMaxRequests(int $max): self
    {
        $this->maxRequests = $max;
        return $this;
    }

    /**
     * تعيين النافذة الزمنية
     */
    public function setWindowMinutes(int $minutes): self
    {
        $this->windowMinutes = $minutes;
        return $this;
    }

    /**
     * تعيين الحد الأقصى للطلبات في الساعة
     */
    public function setMaxRequestsPerHour(int $max): self
    {
        $this->maxRequestsPerHour = $max;
        return $this;
    }

    /**
     * تعيين الحد الأقصى للطلبات في اليوم
     */
    public function setMaxRequestsPerDay(int $max): self
    {
        $this->maxRequestsPerDay = $max;
        return $this;
    }

    /**
     * إضافة مسار مستثنى
     */
    public function addExcludedRoute(string $route): self
    {
        if (!in_array($route, $this->excludedRoutes)) {
            $this->excludedRoutes[] = $route;
        }
        return $this;
    }

    /**
     * إضافة IP مستثنى
     */
    public function addExcludedIp(string $ip): self
    {
        if (!in_array($ip, $this->excludedIps)) {
            $this->excludedIps[] = $ip;
        }
        return $this;
    }

    /**
     * تفعيل/تعطيل الميدل وير
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * الحصول على إحصائيات معدل الطلبات
     */
    public function getStats(string $identifier): array
    {
        try {
            // الدقيقة
            $windowStart = date('Y-m-d H:i:s', strtotime("-{$this->windowMinutes} minutes"));
            $minuteCount = (int)$this->db->queryValue("
                SELECT COUNT(*) FROM rate_limit_logs 
                WHERE identifier = :identifier 
                  AND created_at > :window_start
            ", [
                'identifier' => $identifier,
                'window_start' => $windowStart
            ]);

            // الساعة
            $hourStart = date('Y-m-d H:i:s', strtotime('-1 hour'));
            $hourCount = (int)$this->db->queryValue("
                SELECT COUNT(*) FROM rate_limit_logs 
                WHERE identifier = :identifier 
                  AND created_at > :hour_start
            ", [
                'identifier' => $identifier,
                'hour_start' => $hourStart
            ]);

            // اليوم
            $dayStart = date('Y-m-d H:i:s', strtotime('-1 day'));
            $dayCount = (int)$this->db->queryValue("
                SELECT COUNT(*) FROM rate_limit_logs 
                WHERE identifier = :identifier 
                  AND created_at > :day_start
            ", [
                'identifier' => $identifier,
                'day_start' => $dayStart
            ]);

            return [
                'window' => [
                    'limit' => $this->maxRequests,
                    'window_minutes' => $this->windowMinutes,
                    'current' => $minuteCount,
                    'remaining' => max(0, $this->maxRequests - $minuteCount),
                    'reset_at' => date('Y-m-d H:i:s', time() + ($this->windowMinutes * 60))
                ],
                'hour' => [
                    'limit' => $this->maxRequestsPerHour,
                    'current' => $hourCount,
                    'remaining' => max(0, $this->maxRequestsPerHour - $hourCount)
                ],
                'day' => [
                    'limit' => $this->maxRequestsPerDay,
                    'current' => $dayCount,
                    'remaining' => max(0, $this->maxRequestsPerDay - $dayCount)
                ],
                'total_requests' => $minuteCount + $hourCount + $dayCount
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Could not fetch rate limit stats',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * تنظيف جميع السجلات القديمة يدوياً
     */
    public function manualCleanup(int $days = 7): int
    {
        try {
            return $this->db->execute(
                "DELETE FROM rate_limit_logs 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)",
                ['days' => $days]
            );
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * الحصول على معلومات الميدل وير
     */
    public function getInfo(): array
    {
        return [
            'enabled' => $this->enabled,
            'max_requests' => $this->maxRequests,
            'window_minutes' => $this->windowMinutes,
            'max_requests_per_hour' => $this->maxRequestsPerHour,
            'max_requests_per_day' => $this->maxRequestsPerDay,
            'excluded_routes' => $this->excludedRoutes,
            'excluded_ips' => $this->excludedIps
        ];
    }
}

// ================================================================
// انتهى الملف
// ================================================================

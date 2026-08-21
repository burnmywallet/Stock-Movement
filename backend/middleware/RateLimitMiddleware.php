<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/middleware/RateLimitMiddleware.php
// الوصف: ميدل وير تحديد معدل الطلبات
// الإصدار: 2.0 Production Ready
// التاريخ: 2026-08-20
// ================================================================

namespace Middleware;

use Core\Database;

class RateLimitMiddleware
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var int $maxRequests - الحد الأقصى للطلبات
     */
    private $maxRequests = 60;
    
    /**
     * @var int $windowMinutes - النافذة الزمنية بالدقائق
     */
    private $windowMinutes = 1;
    
    /**
     * @var array $excludedRoutes - المسارات المستثناة
     */
    private $excludedRoutes = [
        '/api/auth/login',
        '/api/auth/validate'
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->loadSettings();
    }

    /**
     * تحميل الإعدادات
     */
    private function loadSettings(): void
    {
        try {
            $settings = $this->db->queryOne(
                "SELECT setting_key, setting_value FROM system_settings 
                 WHERE setting_key IN ('rate_limit_requests', 'rate_limit_window')"
            );
            
            foreach ($settings ?? [] as $setting) {
                switch ($setting['setting_key']) {
                    case 'rate_limit_requests':
                        $this->maxRequests = (int)$setting['setting_value'];
                        break;
                    case 'rate_limit_window':
                        $this->windowMinutes = (int)$setting['setting_value'];
                        break;
                }
            }
        } catch (\Exception $e) {
            // استخدام القيم الافتراضية
        }
    }

    /**
     * معالجة الطلب
     */
    public function handle(): bool
    {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($path, PHP_URL_PATH);
        
        // التحقق من المسارات المستثناة
        if ($this->isExcludedRoute($path)) {
            return true;
        }

        $identifier = $this->getIdentifier();
        
        if (!$identifier) {
            return true;
        }

        // التحقق من معدل الطلبات
        if ($this->isRateLimited($identifier)) {
            $this->rateLimited('تم تجاوز الحد الأقصى للطلبات. حاول مرة أخرى لاحقاً.');
            return false;
        }

        // تسجيل الطلب
        $this->logRequest($identifier);

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
        
        // استخدام IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return 'ip:' . $ip;
    }

    /**
     * التحقق من تجاوز الحد
     */
    private function isRateLimited(string $identifier): bool
    {
        $windowStart = date('Y-m-d H:i:s', strtotime("-{$this->windowMinutes} minutes"));
        
        $count = $this->db->queryValue(
            "SELECT COUNT(*) FROM rate_limit_logs 
             WHERE identifier = :identifier 
               AND created_at > :window_start",
            [
                'identifier' => $identifier,
                'window_start' => $windowStart
            ]
        );
        
        return ($count ?? 0) >= $this->maxRequests;
    }

    /**
     * تسجيل الطلب
     */
    private function logRequest(string $identifier): void
    {
        $this->db->insert('rate_limit_logs', [
            'identifier' => $identifier,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // تنظيف السجلات القديمة
        $this->cleanupOldLogs();
    }

    /**
     * تنظيف السجلات القديمة
     */
    private function cleanupOldLogs(): void
    {
        if (rand(1, 100) <= 10) { // 10% من الطلبات
            $this->db->execute(
                "DELETE FROM rate_limit_logs 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
            );
        }
    }

    /**
     * التحقق من المسار المستثنى
     */
    private function isExcludedRoute(string $path): bool
    {
        foreach ($this->excludedRoutes as $excluded) {
            if (strpos($path, $excluded) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * إرسال استجابة تجاوز الحد
     */
    private function rateLimited(string $message): void
    {
        header('HTTP/1.1 429 Too Many Requests');
        header('Content-Type: application/json');
        header("Retry-After: {$this->windowMinutes * 60}");
        
        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => 'RATE_LIMITED',
            'retry_after' => $this->windowMinutes * 60,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
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
     * إضافة مسار مستثنى
     */
    public function addExcludedRoute(string $route): self
    {
        $this->excludedRoutes[] = $route;
        return $this;
    }

    /**
     * الحصول على إحصائيات معدل الطلبات
     */
    public function getStats(string $identifier): array
    {
        $windowStart = date('Y-m-d H:i:s', strtotime("-{$this->windowMinutes} minutes"));
        
        $count = $this->db->queryValue(
            "SELECT COUNT(*) FROM rate_limit_logs 
             WHERE identifier = :identifier 
               AND created_at > :window_start",
            [
                'identifier' => $identifier,
                'window_start' => $windowStart
            ]
        );
        
        $total = $this->db->queryValue(
            "SELECT COUNT(*) FROM rate_limit_logs 
             WHERE identifier = :identifier",
            ['identifier' => $identifier]
        );
        
        return [
            'current_window_requests' => (int)$count,
            'max_requests' => $this->maxRequests,
            'window_minutes' => $this->windowMinutes,
            'total_requests' => (int)$total,
            'remaining' => max(0, $this->maxRequests - (int)$count),
            'reset_at' => date('Y-m-d H:i:s', strtotime("+{$this->windowMinutes} minutes"))
        ];
    }
}

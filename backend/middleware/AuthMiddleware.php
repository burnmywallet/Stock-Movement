<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/middleware/AuthMiddleware.php
// الوصف: ميدل وير التحقق من المصادقة
// الإصدار: 2.0 Production Ready
// التاريخ: 2026-08-20
// ================================================================

namespace Middleware;

use Core\Auth;
use Core\Audit;
use Core\Database;

class AuthMiddleware
{
    /**
     * @var Auth $auth - نظام المصادقة
     */
    private $auth;
    
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var array $excludedRoutes - المسارات المستثناة
     */
    private $excludedRoutes = [
        '/api/auth/login',
        '/api/auth/validate',
        '/api/auth/forgot-password',
        '/api/auth/reset-password',
        '/api/auth/register'
    ];

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
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

        // الحصول على التوكن من الرأس
        $token = $this->getTokenFromRequest();
        
        if (!$token) {
            $this->unauthorized('لم يتم توفير رمز المصادقة');
            return false;
        }

        // التحقق من صحة الجلسة
        $session = $this->auth->validateSession($token);
        
        if (!$session) {
            // تسجيل محاولة وصول غير مصرح بها
            $this->logUnauthorizedAttempt($token);
            $this->unauthorized('جلسة غير صالحة أو منتهية');
            return false;
        }

        // التحقق من قفل الحساب
        if ($this->isAccountLocked($session['user_id'])) {
            $this->unauthorized('الحساب مقفل مؤقتاً');
            return false;
        }

        // التحقق من انتهاء صلاحية كلمة المرور
        if ($this->isPasswordExpired($session['user_id'])) {
            $this->unauthorized('انتهت صلاحية كلمة المرور', true);
            return false;
        }

        // تخزين معلومات الجلسة والمستخدم
        $_REQUEST['user'] = $session;
        $_REQUEST['user_id'] = $session['user_id'];
        $_REQUEST['session_id'] = $session['id'];
        $_REQUEST['auth_token'] = $token;

        // تحديث وقت آخر نشاط
        $this->updateLastActivity($session['id']);

        // تسجيل الوصول
        $this->logAccess($session['user_id'], $path);

        return true;
    }

    /**
     * الحصول على التوكن من الطلب
     */
    private function getTokenFromRequest(): ?string
    {
        // من رأس Authorization
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        
        if (strpos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }

        // من معامل URL
        if (isset($_GET['token'])) {
            return $_GET['token'];
        }

        // من كوكي
        if (isset($_COOKIE['auth_token'])) {
            return $_COOKIE['auth_token'];
        }

        return null;
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
     * التحقق من قفل الحساب
     */
    private function isAccountLocked(int $userId): bool
    {
        $result = $this->db->queryOne(
            "SELECT locked_until FROM users WHERE id = :id",
            ['id' => $userId]
        );
        
        if (!$result || !$result['locked_until']) {
            return false;
        }
        
        return strtotime($result['locked_until']) > time();
    }

    /**
     * التحقق من انتهاء صلاحية كلمة المرور
     */
    private function isPasswordExpired(int $userId): bool
    {
        $result = $this->db->queryOne(
            "SELECT last_password_change, password_expiry_days 
             FROM users WHERE id = :id",
            ['id' => $userId]
        );
        
        if (!$result || !$result['last_password_change']) {
            return true;
        }
        
        $expiryDays = $result['password_expiry_days'] ?? 90;
        $expiryDate = strtotime($result['last_password_change']) + ($expiryDays * 86400);
        
        return $expiryDate < time();
    }

    /**
     * تحديث آخر نشاط
     */
    private function updateLastActivity(int $sessionId): void
    {
        $this->db->execute(
            "UPDATE user_sessions 
             SET last_activity = NOW(), request_count = request_count + 1 
             WHERE id = :id",
            ['id' => $sessionId]
        );
    }

    /**
     * تسجيل محاولة وصول غير مصرح بها
     */
    private function logUnauthorizedAttempt(?string $token): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        Audit::log(
            null,
            'UNAUTHORIZED_ACCESS',
            'auth',
            'محاولة وصول غير مصرح بها',
            [
                'ip' => $ip,
                'user_agent' => $userAgent,
                'token_hash' => $token ? substr(hash('sha256', $token), 0, 10) : null
            ]
        );
    }

    /**
     * تسجيل الوصول الناجح
     */
    private function logAccess(int $userId, string $path): void
    {
        // تسجيل فقط نسبة صغيرة من الطلبات لتجنب تضخم السجل
        if (rand(1, 100) <= 5) {
            Audit::log(
                $userId,
                'API_ACCESS',
                'api',
                "طلب API إلى {$path}",
                [
                    'path' => $path,
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]
            );
        }
    }

    /**
     * إرسال استجابة غير مصرح بها
     */
    private function unauthorized(string $message, bool $forcePasswordChange = false): void
    {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'message' => $message,
            'code' => 'UNAUTHORIZED',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($forcePasswordChange) {
            $response['force_password_change'] = true;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * إضافة مسار مستثنى
     */
    public function addExcludedRoute(string $route): void
    {
        $this->excludedRoutes[] = $route;
    }

    /**
     * إزالة مسار مستثنى
     */
    public function removeExcludedRoute(string $route): void
    {
        $key = array_search($route, $this->excludedRoutes);
        if ($key !== false) {
            unset($this->excludedRoutes[$key]);
        }
    }
}

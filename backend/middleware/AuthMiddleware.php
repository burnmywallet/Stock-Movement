<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/middleware/AuthMiddleware.php
// الوصف: ميدل وير التحقق من المصادقة - التأكد من أن المستخدم مسجل الدخول
// التاريخ: 2026-08-22
// ================================================================

namespace Middleware;

use Core\Auth;
use Core\Audit;
use Core\Database;
use Core\Session;
use Exception;

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
     * @var Session $session - إدارة الجلسات
     */
    private $session;
    
    /**
     * @var array $excludedRoutes - المسارات المستثناة (لا تتطلب مصادقة)
     */
    private $excludedRoutes = [
        '/api/auth/login',
        '/api/auth/validate',
        '/api/auth/forgot-password',
        '/api/auth/reset-password',
        '/api/auth/register',
        '/api/health',
        '/test',
        '/'
    ];
    
    /**
     * @var array $publicRoutes - المسارات العامة (تتطلب مصادقة اختيارية)
     */
    private $publicRoutes = [];

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->session = new Session();
    }

    /**
     * معالجة الطلب - التحقق من المصادقة
     */
    public function handle(array $params = []): bool
    {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($path, PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
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

        // التحقق من المسارات العامة
        if ($this->isPublicRoute($path)) {
            // التحقق من التوكن إذا كان موجوداً (اختياري)
            $token = $this->getTokenFromRequest();
            if ($token) {
                $session = $this->auth->validateSession($token);
                if ($session) {
                    $_REQUEST['user'] = $session;
                    $_REQUEST['user_id'] = $session['user_id'];
                    $_REQUEST['session_id'] = $session['id'];
                    $_REQUEST['auth_token'] = $token;
                }
            }
            return true;
        }

        // الحصول على التوكن من الطلب
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
            $this->unauthorized('الحساب مقفل مؤقتاً. الرجاء التواصل مع المسؤول');
            return false;
        }

        // التحقق من انتهاء صلاحية كلمة المرور
        if ($this->isPasswordExpired($session['user_id'])) {
            $this->unauthorized('انتهت صلاحية كلمة المرور. يجب تغييرها', true);
            return false;
        }

        // التحقق من نشاط الحساب
        if (!$session['is_active']) {
            $this->unauthorized('الحساب غير نشط. الرجاء التواصل مع المسؤول');
            return false;
        }

        // التحقق من الحذف الناعم
        if ($session['deleted_at'] !== null) {
            $this->unauthorized('الحساب غير موجود');
            return false;
        }

        // تخزين معلومات الجلسة والمستخدم في الطلب
        $_REQUEST['user'] = $session;
        $_REQUEST['user_id'] = $session['user_id'];
        $_REQUEST['session_id'] = $session['id'];
        $_REQUEST['auth_token'] = $token;
        $_REQUEST['user_role'] = $session['role_name'];

        // تحديث وقت آخر نشاط
        $this->updateLastActivity($session['id']);

        // تسجيل الوصول (نسبة صغيرة فقط)
        if (rand(1, 100) <= 5) {
            $this->logAccess($session['user_id'], $path);
        }

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

        // من معامل URL (للتطوير فقط)
        if (isset($_GET['token'])) {
            return $_GET['token'];
        }

        // من كوكي
        if (isset($_COOKIE['auth_token'])) {
            return $_COOKIE['auth_token'];
        }

        // من جلسة PHP
        if (isset($_SESSION['auth_token'])) {
            return $_SESSION['auth_token'];
        }

        return null;
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
     * التحقق من المسار العام
     */
    private function isPublicRoute(string $path): bool
    {
        foreach ($this->publicRoutes as $public) {
            if ($path === $public || strpos($path, $public) === 0) {
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
        try {
            $result = $this->db->queryOne(
                "SELECT locked_until FROM users WHERE id = :id",
                ['id' => $userId]
            );
            
            if (!$result || !$result['locked_until']) {
                return false;
            }
            
            return strtotime($result['locked_until']) > time();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * التحقق من انتهاء صلاحية كلمة المرور
     */
    private function isPasswordExpired(int $userId): bool
    {
        try {
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
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * تحديث آخر نشاط للجلسة
     */
    private function updateLastActivity(int $sessionId): void
    {
        try {
            $this->db->execute("
                UPDATE user_sessions 
                SET last_activity = NOW(), 
                    request_count = request_count + 1,
                    last_request_url = :url
                WHERE id = :id
            ", [
                'id' => $sessionId,
                'url' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
        } catch (Exception $e) {
            // السكوت عن الخطأ - لا نريد تعطيل الطلب بسبب فشل التحديث
        }
    }

    /**
     * تسجيل محاولة وصول غير مصرح بها
     */
    private function logUnauthorizedAttempt(?string $token): void
    {
        try {
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
                    'token_hash' => $token ? substr(hash('sha256', $token), 0, 10) : null,
                    'path' => $_SERVER['REQUEST_URI'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
                ]
            );
        } catch (Exception $e) {
            // السكوت عن الخطأ
        }
    }

    /**
     * تسجيل الوصول الناجح
     */
    private function logAccess(int $userId, string $path): void
    {
        try {
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
        } catch (Exception $e) {
            // السكوت عن الخطأ
        }
    }

    /**
     * إرسال استجابة غير مصرح بها (401)
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
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
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
     * إزالة مسار مستثنى
     */
    public function removeExcludedRoute(string $route): self
    {
        $key = array_search($route, $this->excludedRoutes);
        if ($key !== false) {
            unset($this->excludedRoutes[$key]);
        }
        return $this;
    }

    /**
     * إضافة مسار عام
     */
    public function addPublicRoute(string $route): self
    {
        $this->publicRoutes[] = $route;
        return $this;
    }

    /**
     * الحصول على قائمة المسارات المستثناة
     */
    public function getExcludedRoutes(): array
    {
        return $this->excludedRoutes;
    }

    /**
     * الحصول على قائمة المسارات العامة
     */
    public function getPublicRoutes(): array
    {
        return $this->publicRoutes;
    }

    /**
     * التحقق من وجود جلسة نشطة للمستخدم
     */
    public function hasActiveSession(int $userId): bool
    {
        try {
            $result = $this->db->queryValue("
                SELECT COUNT(*) FROM user_sessions 
                WHERE user_id = :user_id AND is_active = 1 AND expires_at > NOW()
            ", ['user_id' => $userId]);
            
            return (int)$result > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * الحصول على عدد الجلسات النشطة للمستخدم
     */
    public function getActiveSessionsCount(int $userId): int
    {
        try {
            $result = $this->db->queryValue("
                SELECT COUNT(*) FROM user_sessions 
                WHERE user_id = :user_id AND is_active = 1 AND expires_at > NOW()
            ", ['user_id' => $userId]);
            
            return (int)$result;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * إنهاء جميع جلسات المستخدم (باستثناء الجلسة الحالية)
     */
    public function terminateAllOtherSessions(int $userId, int $currentSessionId): int
    {
        try {
            return $this->db->execute("
                UPDATE user_sessions 
                SET is_active = 0, 
                    logout_at = NOW(),
                    terminated_by = 'security',
                    terminated_reason = 'تم إنهاء الجلسات الأخرى'
                WHERE user_id = :user_id 
                  AND id != :session_id 
                  AND is_active = 1
            ", [
                'user_id' => $userId,
                'session_id' => $currentSessionId
            ]);
        } catch (Exception $e) {
            return 0;
        }
    }
}

// ================================================================
// انتهى الملف
// ================================================================

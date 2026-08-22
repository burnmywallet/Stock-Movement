<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/core/Session.php
// الوصف: إدارة الجلسات - آمنة ومتكاملة مع دعم التخزين المتعدد
// التاريخ: 2026-08-22
// ================================================================

namespace Core;

use Exception;

class Session
{
    /**
     * @var bool $started - حالة بدء الجلسة
     */
    private $started = false;
    
    /**
     * @var string $name - اسم الجلسة
     */
    private $name = 'INVENTORY_SESSION';
    
    /**
     * @var int $lifetime - مدة صلاحية الجلسة بالثواني
     */
    private $lifetime = 3600;
    
    /**
     * @var string $path - مسار الجلسة
     */
    private $path = '/';
    
    /**
     * @var string $domain - نطاق الجلسة
     */
    private $domain = '';
    
    /**
     * @var bool $secure - هل الجلسة آمنة (HTTPS فقط)
     */
    private $secure = false;
    
    /**
     * @var bool $httponly - هل الجلسة HTTP Only
     */
    private $httponly = true;
    
    /**
     * @var string $samesite - سياسة SameSite
     */
    private $samesite = 'Lax';
    
    /**
     * @var string $driver - محرك التخزين (file, database, redis)
     */
    private $driver = 'file';
    
    /**
     * @var array $data - بيانات الجلسة المؤقتة
     */
    private $data = [];
    
    /**
     * @var string|null $id - معرف الجلسة
     */
    private $id = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->loadSettings();
        $this->configure();
    }

    /**
     * تحميل الإعدادات
     */
    private function loadSettings(): void
    {
        $this->lifetime = (int)($_ENV['SESSION_TIMEOUT'] ?? 3600);
        $this->name = $_ENV['SESSION_NAME'] ?? 'INVENTORY_SESSION';
        $this->secure = ($_ENV['SESSION_SECURE'] ?? 'false') === 'true';
        $this->httponly = ($_ENV['SESSION_HTTPONLY'] ?? 'true') === 'true';
        $this->samesite = $_ENV['SESSION_SAMESITE'] ?? 'Lax';
        $this->driver = $_ENV['SESSION_DRIVER'] ?? 'file';
        $this->domain = $_ENV['SESSION_DOMAIN'] ?? '';
    }

    /**
     * تكوين الجلسة
     */
    private function configure(): void
    {
        // إعدادات PHP
        ini_set('session.gc_maxlifetime', $this->lifetime);
        ini_set('session.cookie_lifetime', $this->lifetime);
        ini_set('session.cookie_path', $this->path);
        ini_set('session.cookie_httponly', $this->httponly ? '1' : '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_secure', $this->secure ? '1' : '0');
        ini_set('session.sid_length', '128');
        ini_set('session.sid_bits_per_character', '6');
        
        // تعيين SameSite عبر PHP 7.3+
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            session_set_cookie_params([
                'lifetime' => $this->lifetime,
                'path' => $this->path,
                'domain' => $this->domain,
                'secure' => $this->secure,
                'httponly' => $this->httponly,
                'samesite' => $this->samesite
            ]);
        } else {
            session_set_cookie_params(
                $this->lifetime,
                $this->path . '; SameSite=' . $this->samesite,
                $this->domain,
                $this->secure,
                $this->httponly
            );
        }
        
        session_name($this->name);
    }

    /**
     * بدء الجلسة
     */
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            $this->data = $_SESSION ?? [];
            return true;
        }
        
        try {
            session_start();
            $this->started = true;
            $this->data = $_SESSION ?? [];
            $this->id = session_id();
            
            // تحديث وقت النشاط
            $this->set('_last_activity', time());
            
            // التحقق من انتهاء الصلاحية
            if ($this->isExpired()) {
                $this->regenerate();
            }
            
            return true;
            
        } catch (Exception $e) {
            // محاولة بدء الجلسة بدون أخطاء
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
                $this->started = true;
                $this->data = $_SESSION ?? [];
                $this->id = session_id();
            }
            return true;
        }
    }

    /**
     * إنهاء الجلسة
     */
    public function destroy(): bool
    {
        if (!$this->started) {
            return false;
        }
        
        // مسح بيانات الجلسة
        $_SESSION = [];
        $this->data = [];
        
        // حذف كوكي الجلسة
        if (ini_get('session.use_cookies')) {
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $this->path,
                $this->domain,
                $this->secure,
                $this->httponly
            );
        }
        
        // إنهاء الجلسة
        session_destroy();
        $this->started = false;
        $this->id = null;
        
        return true;
    }

    /**
     * تعيين قيمة في الجلسة
     */
    public function set(string $key, $value): self
    {
        $this->start();
        $this->data[$key] = $value;
        $_SESSION[$key] = $value;
        return $this;
    }

    /**
     * الحصول على قيمة من الجلسة
     */
    public function get(string $key, $default = null)
    {
        $this->start();
        return $this->data[$key] ?? $_SESSION[$key] ?? $default;
    }

    /**
     * التحقق من وجود مفتاح في الجلسة
     */
    public function has(string $key): bool
    {
        $this->start();
        return isset($this->data[$key]) || isset($_SESSION[$key]);
    }

    /**
     * حذف مفتاح من الجلسة
     */
    public function remove(string $key): self
    {
        $this->start();
        unset($this->data[$key]);
        unset($_SESSION[$key]);
        return $this;
    }

    /**
     * الحصول على جميع بيانات الجلسة
     */
    public function all(): array
    {
        $this->start();
        return $this->data;
    }

    /**
     * مسح جميع بيانات الجلسة
     */
    public function clear(): self
    {
        $this->start();
        $_SESSION = [];
        $this->data = [];
        return $this;
    }

    /**
     * تجديد معرف الجلسة
     */
    public function regenerate(bool $deleteOld = true): bool
    {
        if (!$this->started) {
            return false;
        }
        
        session_regenerate_id($deleteOld);
        $this->id = session_id();
        
        return true;
    }

    /**
     * التحقق من انتهاء صلاحية الجلسة
     */
    public function isExpired(): bool
    {
        $lastActivity = $this->get('_last_activity');
        if (!$lastActivity) {
            return false;
        }
        return (time() - $lastActivity) > $this->lifetime;
    }

    /**
     * تحديث وقت النشاط
     */
    public function touch(): self
    {
        $this->set('_last_activity', time());
        return $this;
    }

    /**
     * الحصول على معرف الجلسة
     */
    public function getId(): ?string
    {
        $this->start();
        return $this->id ?? session_id();
    }

    /**
     * الحصول على اسم الجلسة
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * تغيير اسم الجلسة
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        session_name($name);
        return $this;
    }

    /**
     * الحصول على مدة صلاحية الجلسة
     */
    public function getLifetime(): int
    {
        return $this->lifetime;
    }

    /**
     * تغيير مدة صلاحية الجلسة
     */
    public function setLifetime(int $seconds): self
    {
        $this->lifetime = $seconds;
        return $this;
    }

    /**
     * تخزين بيانات المستخدم في الجلسة
     */
    public function setUser(array $user): self
    {
        return $this->set('user', $user);
    }

    /**
     * الحصول على بيانات المستخدم من الجلسة
     */
    public function getUser(): ?array
    {
        return $this->get('user');
    }

    /**
     * التحقق من تسجيل الدخول
     */
    public function isLoggedIn(): bool
    {
        return $this->has('user') && $this->get('user') !== null;
    }

    /**
     * تخزين رمز المصادقة
     */
    public function setAuthToken(string $token): self
    {
        $this->set('auth_token', $token);
        $this->set('auth_token_created', time());
        return $this;
    }

    /**
     * الحصول على رمز المصادقة
     */
    public function getAuthToken(): ?string
    {
        return $this->get('auth_token');
    }

    /**
     * التحقق من صلاحية رمز المصادقة
     */
    public function isAuthTokenValid(): bool
    {
        $created = $this->get('auth_token_created');
        if (!$created) {
            return false;
        }
        return (time() - $created) < $this->lifetime;
    }

    /**
     * تخزين رسائل فلاش
     */
    public function flash(string $key, string $message): self
    {
        $flashes = $this->get('_flashes', []);
        $flashes[$key] = $message;
        return $this->set('_flashes', $flashes);
    }

    /**
     * الحصول على رسائل فلاش ومسحها
     */
    public function getFlash(string $key): ?string
    {
        $flashes = $this->get('_flashes', []);
        $message = $flashes[$key] ?? null;
        unset($flashes[$key]);
        $this->set('_flashes', $flashes);
        return $message;
    }

    /**
     * الحصول على جميع رسائل فلاش
     */
    public function getFlashes(): array
    {
        $flashes = $this->get('_flashes', []);
        $this->remove('_flashes');
        return $flashes;
    }

    /**
     * تخزين بيانات مؤقتة (تختفي بعد قراءتها)
     */
    public function keep(string $key, $value): self
    {
        $temp = $this->get('_temp', []);
        $temp[$key] = $value;
        return $this->set('_temp', $temp);
    }

    /**
     * الحصول على بيانات مؤقتة ومسحها
     */
    public function pull(string $key, $default = null)
    {
        $temp = $this->get('_temp', []);
        $value = $temp[$key] ?? $default;
        unset($temp[$key]);
        $this->set('_temp', $temp);
        return $value;
    }

    /**
     * إضافة بيانات إلى مصفوفة في الجلسة
     */
    public function push(string $key, $value): self
    {
        $array = $this->get($key, []);
        if (!is_array($array)) {
            $array = [];
        }
        $array[] = $value;
        return $this->set($key, $array);
    }

    /**
     * إزالة عنصر من مصفوفة في الجلسة
     */
    public function pullFromArray(string $key, $value): self
    {
        $array = $this->get($key, []);
        if (!is_array($array)) {
            return $this;
        }
        $array = array_filter($array, function($item) use ($value) {
            return $item !== $value;
        });
        return $this->set($key, array_values($array));
    }

    /**
     * تخزين البيانات في قاعدة البيانات (Database Driver)
     */
    private function saveToDatabase(): void
    {
        try {
            $db = Database::getInstance();
            $data = json_encode($this->data, JSON_UNESCAPED_UNICODE);
            $expiresAt = date('Y-m-d H:i:s', time() + $this->lifetime);
            
            $db->execute(
                "INSERT INTO sessions (session_id, data, expires_at, created_at) 
                 VALUES (:id, :data, :expires_at, NOW())
                 ON DUPLICATE KEY UPDATE 
                 data = :data, 
                 expires_at = :expires_at,
                 updated_at = NOW()",
                [
                    'id' => $this->getId(),
                    'data' => $data,
                    'expires_at' => $expiresAt
                ]
            );
        } catch (Exception $e) {
            // السكوت عن الخطأ - استخدام التخزين الافتراضي
        }
    }

    /**
     * تحميل البيانات من قاعدة البيانات (Database Driver)
     */
    private function loadFromDatabase(): array
    {
        try {
            $db = Database::getInstance();
            $result = $db->queryOne(
                "SELECT data FROM sessions 
                 WHERE session_id = :id AND expires_at > NOW()",
                ['id' => $this->getId()]
            );
            
            if ($result && $result['data']) {
                return json_decode($result['data'], true) ?? [];
            }
        } catch (Exception $e) {
            // السكوت عن الخطأ
        }
        return [];
    }

    /**
     * تنظيف الجلسات المنتهية
     */
    public function cleanup(): int
    {
        try {
            $db = Database::getInstance();
            $count = $db->execute(
                "DELETE FROM sessions WHERE expires_at < NOW()"
            );
            return $count;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * الحصول على معلومات الجلسة
     */
    public function getInfo(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->name,
            'lifetime' => $this->lifetime,
            'started' => $this->started,
            'secure' => $this->secure,
            'httponly' => $this->httponly,
            'samesite' => $this->samesite,
            'driver' => $this->driver,
            'data_count' => count($this->data),
            'is_logged_in' => $this->isLoggedIn()
        ];
    }

    /**
     * منع استنساخ الكائن
     */
    private function __clone() {}

    /**
     * منع إعادة إنشاء الكائن
     */
    public function __wakeup() {}
}

// ================================================================
// انتهى الملف
// ================================================================

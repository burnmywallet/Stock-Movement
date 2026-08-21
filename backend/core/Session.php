<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/core/Session.php
// الوصف: إدارة الجلسات
// الإصدار: 2.0 Production Ready
// ================================================================

namespace Core;

class Session
{
    private $started = false;
    private $name = 'INVENTORY_SESSION';
    private $lifetime = 3600;

    public function __construct()
    {
        $this->lifetime = (int)($_ENV['SESSION_TIMEOUT'] ?? 3600);
        session_name($this->name);
        $this->configure();
    }

    private function configure()
    {
        ini_set('session.gc_maxlifetime', $this->lifetime);
        ini_set('session.cookie_lifetime', $this->lifetime);
        session_set_cookie_params(
            $this->lifetime,
            '/',
            '',
            false,
            true
        );
    }

    public function start()
    {
        if ($this->started) return true;
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->started = true;
        return true;
    }

    public function destroy()
    {
        if (!$this->started) return false;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', time() - 42000);
        }
        session_destroy();
        $this->started = false;
        return true;
    }

    public function set($key, $value)
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function get($key, $default = null)
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function has($key)
    {
        $this->start();
        return isset($_SESSION[$key]);
    }

    public function remove($key)
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function all()
    {
        $this->start();
        return $_SESSION;
    }

    public function clear()
    {
        $this->start();
        $_SESSION = [];
    }

    public function regenerateId()
    {
        if (!$this->started) return false;
        return session_regenerate_id(true);
    }

    public function setUser($user)
    {
        $this->set('user', $user);
    }

    public function getUser()
    {
        return $this->get('user');
    }

    public function isLoggedIn()
    {
        return $this->has('user') && $this->get('user') !== null;
    }

    public function setAuthToken($token)
    {
        $this->set('auth_token', $token);
        $this->set('auth_token_created', time());
    }

    public function getAuthToken()
    {
        return $this->get('auth_token');
    }

    public function isAuthTokenValid()
    {
        $created = $this->get('auth_token_created');
        if (!$created) return false;
        return (time() - $created) < $this->lifetime;
    }

    public function touch()
    {
        $this->set('last_activity', time());
    }

    public function lastActivity()
    {
        return $this->get('last_activity');
    }

    public function isExpired()
    {
        $last = $this->lastActivity();
        if (!$last) return false;
        return (time() - $last) > $this->lifetime;
    }

    public function getId()
    {
        $this->start();
        return session_id();
    }

    public function getName()
    {
        return $this->name;
    }
}

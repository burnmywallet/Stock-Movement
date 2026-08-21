<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/core/Audit.php
// الوصف: نظام سجل التدقيق
// الإصدار: 2.0 Production Ready
// ================================================================

namespace Core;

class Audit
{
    private static $db;

    private static function init()
    {
        if (self::$db === null) {
            self::$db = Database::getInstance();
        }
    }

    public static function log($userId, $action, $module, $description, $details = null, $referenceType = null, $referenceId = null)
    {
        self::init();
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $username = null;

        if ($userId) {
            $user = self::$db->queryOne("SELECT username FROM users WHERE id = :id", ['id' => $userId]);
            $username = $user ? $user['username'] : null;
        }

        return self::$db->insert('audit_logs', [
            'user_id' => $userId,
            'username' => $username,
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public static function loginSuccess($userId, $username)
    {
        return self::log($userId, 'LOGIN_SUCCESS', 'auth', "تسجيل دخول ناجح للمستخدم {$username}");
    }

    public static function loginFailed($username, $reason = null)
    {
        return self::log(null, 'LOGIN_FAILED', 'auth', "محاولة تسجيل دخول فاشلة للمستخدم {$username}", ['reason' => $reason]);
    }

    public static function logoutSuccess($userId, $username)
    {
        return self::log($userId, 'LOGOUT', 'auth', "تسجيل خروج المستخدم {$username}");
    }

    public static function passwordChanged($userId, $username)
    {
        return self::log($userId, 'PASSWORD_CHANGED', 'auth', "تغيير كلمة مرور المستخدم {$username}");
    }

    public static function userCreated($userId, $username, $createdBy)
    {
        return self::log($createdBy, 'USER_CREATED', 'users', "إنشاء مستخدم جديد {$username}");
    }

    public static function userDisabled($userId, $username, $disabledBy)
    {
        return self::log($disabledBy, 'USER_DISABLED', 'users', "تعطيل المستخدم {$username}");
    }

    public static function getLogs($filters = [], $limit = 100, $offset = 0)
    {
        self::init();
        
        $sql = "SELECT * FROM audit_logs WHERE 1=1";
        $params = [];

        if (isset($filters['user_id'])) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $filters['user_id'];
        }

        if (isset($filters['action'])) {
            $sql .= " AND action = :action";
            $params['action'] = $filters['action'];
        }

        if (isset($filters['module'])) {
            $sql .= " AND module = :module";
            $params['module'] = $filters['module'];
        }

        if (isset($filters['start_date'])) {
            $sql .= " AND created_at >= :start_date";
            $params['start_date'] = $filters['start_date'];
        }

        if (isset($filters['end_date'])) {
            $sql .= " AND created_at <= :end_date";
            $params['end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        return self::$db->query($sql, $params);
    }

    public static function cleanup($days = 90)
    {
        self::init();
        return self::$db->execute(
            "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['days' => $days]
        );
    }
}

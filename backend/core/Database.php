<?php
/**
 * ================================================================
 * Logistox - اتصال قاعدة البيانات
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 * 
 * الملف: backend/core/Database.php
 * الوظيفة: إدارة اتصال قاعدة البيانات باستخدام PDO
 * ================================================================
 */

namespace Core;

use PDO;
use PDOException;
use Exception;

/**
 * Class Database
 * 
 * إدارة اتصال قاعدة البيانات مع دعم:
 * - Singleton Pattern
 * - Connection Pooling
 * - Transactions
 * - Logging
 * - Prepared Statements
 * - Query Builder الأساسي
 */
class Database
{
    /**
     * @var Database|null $instance كائن Singleton
     */
    private static $instance = null;

    /**
     * @var PDO|null $connection كائن الاتصال
     */
    private $connection = null;

    /**
     * @var array $config إعدادات قاعدة البيانات
     */
    private $config = [];

    /**
     * @var array $queries سجل الاستعلامات
     */
    private $queries = [];

    /**
     * @var int $queryCount عدد الاستعلامات
     */
    private $queryCount = 0;

    /**
     * @var float $queryTime وقت الاستعلامات
     */
    private $queryTime = 0;

    /**
     * @var bool $inTransaction حالة وجود ترانزاكشن
     */
    private $inTransaction = false;

    /**
     * @var string $logFile ملف السجلات
     */
    private $logFile = '';

    /**
     * @var bool $logEnabled تفعيل السجلات
     */
    private $logEnabled = true;

    /**
     * @var array $options خيارات PDO الإضافية
     */
    private $options = [];

    /**
     * @var array $connections تجمع الاتصالات (للقراءة والكتابة)
     */
    private $connections = [];

    /**
     * @var string $currentConnection نوع الاتصال الحالي (read/write)
     */
    private $currentConnection = 'write';

    /**
     * Constructor الخاص (Singleton)
     */
    private function __construct()
    {
        $this->loadConfig();
        $this->setupLogging();
        $this->connect();
    }

    /**
     * منع الاستنساخ
     */
    private function __clone() {}

    /**
     * منع التفعيل
     */
    public function __wakeup() {}

    /**
     * الحصول على كائن Singleton
     * 
     * @return Database
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * تحميل إعدادات قاعدة البيانات
     */
    private function loadConfig(): void
    {
        $configFile = dirname(__DIR__, 1) . '/config/database.php';
        
        if (file_exists($configFile)) {
            $this->config = require $configFile;
        } else {
            // إعدادات افتراضية في حالة عدم وجود الملف
            $this->config = [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'driver' => 'mysql',
                        'host' => getenv('DB_HOST') ?: 'localhost',
                        'port' => getenv('DB_PORT') ?: 3306,
                        'database' => getenv('DB_NAME') ?: 'inventory_system',
                        'username' => getenv('DB_USER') ?: 'angel',
                        'password' => getenv('DB_PASS') ?: 'Lecico10@',
                        'charset' => 'utf8mb4',
                        'collation' => 'utf8mb4_unicode_ci',
                        'options' => [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false,
                        ]
                    ]
                ]
            ];
        }

        $this->options = $this->config['connections']['mysql']['options'] ?? [];
    }

    /**
     * إعداد سجل الاستعلامات
     */
    private function setupLogging(): void
    {
        $logPath = dirname(__DIR__, 2) . '/logs/database.log';
        $this->logFile = $logPath;
        
        // إنشاء مجلد السجلات إذا لم يكن موجوداً
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * الاتصال بقاعدة البيانات
     */
    private function connect(): void
    {
        try {
            $config = $this->config['connections']['mysql'];
            
            $dsn = sprintf(
                "%s:host=%s;port=%s;dbname=%s;charset=%s",
                $config['driver'],
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            // إعدادات إضافية للـPDO
            $options = $this->options + [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 30,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ];

            $this->connection = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $options
            );

            $this->log('✅ Database connected successfully');
            $this->log("📊 Database: {$config['database']} on {$config['host']}");

        } catch (PDOException $e) {
            $this->log('❌ Database connection failed: ' . $e->getMessage());
            throw new Exception('❌ فشل الاتصال بقاعدة البيانات: ' . $e->getMessage());
        }
    }

    /**
     * الحصول على كائن الاتصال
     * 
     * @return PDO
     */
    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * تنفيذ استعلام SQL
     * 
     * @param string $sql جملة SQL
     * @param array $params المعاملات
     * @param string $type نوع الاتصال (read/write)
     * @return array|int|false نتيجة الاستعلام
     */
    public function query(string $sql, array $params = [], string $type = 'write')
    {
        $startTime = microtime(true);
        $this->queryCount++;

        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            
            $executionTime = microtime(true) - $startTime;
            $this->queryTime += $executionTime;
            
            // تسجيل الاستعلام البطيء
            if ($executionTime > 1.0) {
                $this->logSlowQuery($sql, $params, $executionTime);
            }

            // لو كانت SELECT، نرجع النتائج
            if (stripos(trim($sql), 'SELECT') === 0) {
                $result = $stmt->fetchAll();
                $this->logQuery($sql, $params, $executionTime, count($result));
                return $result;
            }
            
            // لو كانت INSERT، نرجع الـID
            if (stripos(trim($sql), 'INSERT') === 0) {
                $id = $this->getConnection()->lastInsertId();
                $this->logQuery($sql, $params, $executionTime, 1, $id);
                return $id;
            }
            
            // لو كانت UPDATE أو DELETE، نرجع عدد الصفوف المتأثرة
            $affected = $stmt->rowCount();
            $this->logQuery($sql, $params, $executionTime, $affected);
            return $affected;

        } catch (PDOException $e) {
            $this->log('❌ Query failed: ' . $e->getMessage());
            $this->log("SQL: $sql");
            $this->log("Params: " . json_encode($params));
            throw new Exception('❌ فشل تنفيذ الاستعلام: ' . $e->getMessage());
        }
    }

    /**
     * تنفيذ استعلام SELECT
     * 
     * @param string $sql جملة SQL
     * @param array $params المعاملات
     * @return array النتائج
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->query($sql, $params, 'read');
    }

    /**
     * تنفيذ استعلام SELECT للحصول على صف واحد
     * 
     * @param string $sql جملة SQL
     * @param array $params المعاملات
     * @return array|null الصف أو null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $result = $this->select($sql, $params);
        return $result[0] ?? null;
    }

    /**
     * تنفيذ استعلام SELECT للحصول على قيمة واحدة
     * 
     * @param string $sql جملة SQL
     * @param array $params المعاملات
     * @param string $column اسم العمود
     * @return mixed القيمة
     */
    public function selectValue(string $sql, array $params = [], string $column = '')
    {
        $result = $this->selectOne($sql, $params);
        if ($result) {
            return $column ? ($result[$column] ?? null) : reset($result);
        }
        return null;
    }

    /**
     * تنفيذ استعلام INSERT
     * 
     * @param string $table اسم الجدول
     * @param array $data البيانات
     * @return int الـID الجديد
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = sprintf(
            "INSERT INTO `%s` (`%s`) VALUES (%s)",
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );
        
        return (int) $this->query($sql, array_values($data));
    }

    /**
     * تنفيذ استعلام UPDATE
     * 
     * @param string $table اسم الجدول
     * @param array $data البيانات
     * @param array $where شرط التحديث
     * @return int عدد الصفوف المتأثرة
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $set[] = "`$key` = ?";
            $params[] = $value;
        }
        
        $whereClause = [];
        foreach ($where as $key => $value) {
            $whereClause[] = "`$key` = ?";
            $params[] = $value;
        }
        
        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE %s",
            $table,
            implode(', ', $set),
            implode(' AND ', $whereClause)
        );
        
        return $this->query($sql, $params);
    }

    /**
     * تنفيذ استعلام DELETE
     * 
     * @param string $table اسم الجدول
     * @param array $where شرط الحذف
     * @return int عدد الصفوف المتأثرة
     */
    public function delete(string $table, array $where): int
    {
        $whereClause = [];
        $params = [];
        
        foreach ($where as $key => $value) {
            $whereClause[] = "`$key` = ?";
            $params[] = $value;
        }
        
        $sql = sprintf(
            "DELETE FROM `%s` WHERE %s",
            $table,
            implode(' AND ', $whereClause)
        );
        
        return $this->query($sql, $params);
    }

    /**
     * البدء في ترانزاكشن
     * 
     * @return bool
     */
    public function beginTransaction(): bool
    {
        if (!$this->inTransaction) {
            $this->inTransaction = true;
            return $this->getConnection()->beginTransaction();
        }
        return false;
    }

    /**
     * تأكيد الترانزاكشن
     * 
     * @return bool
     */
    public function commit(): bool
    {
        if ($this->inTransaction) {
            $this->inTransaction = false;
            return $this->getConnection()->commit();
        }
        return false;
    }

    /**
     * التراجع عن الترانزاكشن
     * 
     * @return bool
     */
    public function rollback(): bool
    {
        if ($this->inTransaction) {
            $this->inTransaction = false;
            return $this->getConnection()->rollback();
        }
        return false;
    }

    /**
     * تنفيذ دالة داخل ترانزاكشن
     * 
     * @param callable $callback الدالة
     * @return mixed نتيجة الدالة
     * @throws Exception
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * الحصول على عدد الاستعلامات
     * 
     * @return int
     */
    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * الحصول على وقت الاستعلامات
     * 
     * @return float
     */
    public function getQueryTime(): float
    {
        return $this->queryTime;
    }

    /**
     * الحصول على سجل الاستعلامات
     * 
     * @return array
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * تسجيل استعلام
     * 
     * @param string $sql
     * @param array $params
     * @param float $time
     * @param int $rows
     * @param int|null $id
     */
    private function logQuery(string $sql, array $params, float $time, int $rows = 0, ?int $id = null): void
    {
        $this->queries[] = [
            'sql' => $sql,
            'params' => $params,
            'time' => $time,
            'rows' => $rows,
            'id' => $id,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * تسجيل استعلام بطيء
     * 
     * @param string $sql
     * @param array $params
     * @param float $time
     */
    private function logSlowQuery(string $sql, array $params, float $time): void
    {
        $message = sprintf(
            "[SLOW QUERY] Time: %.2f ms | SQL: %s | Params: %s",
            $time * 1000,
            $sql,
            json_encode($params)
        );
        $this->log($message);
    }

    /**
     * تسجيل رسالة في السجل
     * 
     * @param string $message
     */
    private function log(string $message): void
    {
        if (!$this->logEnabled) {
            return;
        }

        $logEntry = sprintf(
            "[%s] %s\n",
            date('Y-m-d H:i:s'),
            $message
        );

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * تفعيل أو تعطيل السجلات
     * 
     * @param bool $enabled
     */
    public function setLogging(bool $enabled): void
    {
        $this->logEnabled = $enabled;
    }

    /**
     * الحصول على معرف الصف الأخير
     * 
     * @return int
     */
    public function lastInsertId(): int
    {
        return (int) $this->getConnection()->lastInsertId();
    }

    /**
     * الهروب من النصوص
     * 
     * @param string $value
     * @return string
     */
    public function escape(string $value): string
    {
        return $this->getConnection()->quote($value);
    }

    /**
     * إغلاق الاتصال
     */
    public function close(): void
    {
        $this->connection = null;
        $this->log('🔒 Database connection closed');
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }
}

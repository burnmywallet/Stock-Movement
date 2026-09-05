<?php
/**
 * ================================================================
 * Logistox - Database Core (Unified & Compatible)
 * نظام إدارة المخازن والمخزون v5.1.0
 * ================================================================
 */

namespace Core;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?self $instance = null;
    private ?PDO $connection = null;
    private array $config = [];
    private array $queries = [];
    private int $queryCount = 0;
    private float $queryTime = 0.0;
    private bool $inTransaction = false;
    private string $logFile = '';
    private bool $logEnabled = true;

    private function __construct()
    {
        $this->loadConfig();
        $this->setupLogging();
        $this->connect();
    }

    private function __clone() {}
    public function __wakeup() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfig(): void
    {
        $configFile = dirname(__DIR__, 1) . '/config/database.php';
        if (file_exists($configFile)) {
            $this->config = require $configFile;
        } else {
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
    }

    private function setupLogging(): void
    {
        $logPath = dirname(__DIR__, 2) . '/logs/database.log';
        $this->logFile = $logPath;
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    private function connect(): void
    {
        try {
            $config = $this->config['connections']['mysql'];
            $dsn = sprintf(
                "%s:host=%s;port=%s;dbname=%s;charset=%s",
                $config['driver'], $config['host'], $config['port'], $config['database'], $config['charset']
            );

            $options = ($config['options'] ?? []) + [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];

            $this->connection = new PDO($dsn, $config['username'], $config['password'], $options);
            $this->log('✅ Database connected successfully');

        } catch (PDOException $e) {
            $this->log('❌ Database connection failed: ' . $e->getMessage());
            throw new Exception('❌ فشل الاتصال بقاعدة البيانات: ' . $e->getMessage());
        }
    }

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    // =========================================================================
    // 1. Compatibility Layer (لدعم Services القديمة)
    // =========================================================================

    /**
     * تنفيذ استعلام (INSERT, UPDATE, DELETE) وإرجاع عدد الصفوف المتأثرة
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * جلب سجل واحد
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * جلب جميع السجلات
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * إرجاع كائن PDOStatement خام (للاستخدامات المتقدمة)
     */
    public function statement(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // =========================================================================
    // 2. Modern Query Builder (للاستخدام الجديد)
    // =========================================================================

    public function select(string $sql, array $params = []): array
    {
        return $this->fetchAll($sql, $params);
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        return $this->fetch($sql, $params);
    }

    public function selectValue(string $sql, array $params = [], string $column = '')
    {
        $result = $this->selectOne($sql, $params);
        if ($result) {
            return $column ? ($result[$column] ?? null) : reset($result);
        }
        return null;
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = sprintf("INSERT INTO `%s` (`%s`) VALUES (%s)", $table, implode('`, `', $columns), implode(', ', $placeholders));
        
        $this->execute($sql, array_values($data));
        return (int) $this->lastInsertId();
    }

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
        $sql = sprintf("UPDATE `%s` SET %s WHERE %s", $table, implode(', ', $set), implode(' AND ', $whereClause));
        return $this->execute($sql, $params);
    }

    public function delete(string $table, array $where): int
    {
        $whereClause = [];
        $params = [];
        foreach ($where as $key => $value) {
            $whereClause[] = "`$key` = ?";
            $params[] = $value;
        }
        $sql = sprintf("DELETE FROM `%s` WHERE %s", $table, implode(' AND ', $whereClause));
        return $this->execute($sql, $params);
    }

    // =========================================================================
    // 3. Transactions
    // =========================================================================

    public function beginTransaction(): bool
    {
        if (!$this->inTransaction) {
            $this->inTransaction = true;
            return $this->getConnection()->beginTransaction();
        }
        return false;
    }

    public function commit(): bool
    {
        if ($this->inTransaction) {
            $this->inTransaction = false;
            return $this->getConnection()->commit();
        }
        return false;
    }

    public function rollback(): bool
    {
        if ($this->inTransaction) {
            $this->inTransaction = false;
            return $this->getConnection()->rollBack();
        }
        return false;
    }

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

    // =========================================================================
    // 4. Helpers & Logging
    // =========================================================================

    public function lastInsertId(): int
    {
        return (int) $this->getConnection()->lastInsertId();
    }

    public function escape(string $value): string
    {
        return $this->getConnection()->quote($value);
    }

    private function log(string $message): void
    {
        if (!$this->logEnabled) return;
        $logEntry = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
        @file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function close(): void
    {
        $this->connection = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
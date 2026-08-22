<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/core/Database.php
// الوصف: اتصال قاعدة البيانات - PDO مع دعم المعاملات والمحفزات
// التاريخ: 2026-08-22
// ================================================================

namespace Core;

use PDO;
use PDOException;
use Exception;

class Database
{
    /**
     * @var Database|null $instance - Singleton instance
     */
    private static $instance = null;
    
    /**
     * @var PDO $connection - اتصال PDO
     */
    private $connection;
    
    /**
     * @var array $config - إعدادات قاعدة البيانات
     */
    private $config;
    
    /**
     * @var int $transactionLevel - مستوى المعاملة (للتداخل)
     */
    private $transactionLevel = 0;
    
    /**
     * @var array $queryLog - سجل الاستعلامات (للتطوير)
     */
    private $queryLog = [];
    
    /**
     * @var bool $logQueries - تفعيل تسجيل الاستعلامات
     */
    private $logQueries = false;

    /**
     * Constructor - Private for Singleton
     */
    private function __construct()
    {
        $this->config = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'database' => $_ENV['DB_NAME'] ?? 'inventory_system',
            'username' => $_ENV['DB_USER'] ?? 'angel',
            'password' => $_ENV['DB_PASS'] ?? 'Lecico10@',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'port' => $_ENV['DB_PORT'] ?? 3306,
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        ];
        
        $this->logQueries = ($_ENV['APP_DEBUG'] ?? false) === 'true';
        $this->connect();
    }

    /**
     * Get Singleton Instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Connect to Database
     */
    private function connect(): void
    {
        try {
            $dsn = "mysql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['database']};charset={$this->config['charset']}";
            $this->connection = new PDO($dsn, $this->config['username'], $this->config['password'], $this->config['options']);
        } catch (PDOException $e) {
            die(json_encode([
                'success' => false,
                'message' => 'Database Connection Error: ' . $e->getMessage(),
                'code' => 'DB_CONNECTION_FAILED'
            ]));
        }
    }

    /**
     * Get PDO Connection
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * Execute Query and Return All Results
     */
    public function query(string $sql, array $params = []): array
    {
        $this->logQuery($sql, $params);
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute Query and Return One Result
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->logQuery($sql, $params);
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Execute Query and Return Single Value
     */
    public function queryValue(string $sql, array $params = [])
    {
        $this->logQuery($sql, $params);
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * Execute Query (INSERT, UPDATE, DELETE)
     */
    public function execute(string $sql, array $params = []): int
    {
        $this->logQuery($sql, $params);
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Insert Record
     */
    public function insert(string $table, array $data): int
    {
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":$f", $fields);
        $sql = "INSERT INTO `$table` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        $this->execute($sql, $data);
        return (int)$this->connection->lastInsertId();
    }

    /**
     * Insert Multiple Records (Bulk Insert)
     */
    public function insertBulk(string $table, array $data): int
    {
        if (empty($data)) {
            return 0;
        }
        
        $fields = array_keys($data[0]);
        $placeholders = array_map(fn($f) => ":$f", $fields);
        $sql = "INSERT INTO `$table` (`" . implode('`, `', $fields) . "`) VALUES ";
        
        $values = [];
        $params = [];
        foreach ($data as $index => $row) {
            $rowPlaceholders = [];
            foreach ($fields as $field) {
                $key = "{$field}_{$index}";
                $rowPlaceholders[] = ":$key";
                $params[$key] = $row[$field] ?? null;
            }
            $values[] = "(" . implode(', ', $rowPlaceholders) . ")";
        }
        
        $sql .= implode(', ', $values);
        $this->execute($sql, $params);
        return (int)$this->connection->lastInsertId();
    }

    /**
     * Update Record
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $set[] = "`$key` = :set_$key";
            $params["set_$key"] = $value;
        }
        
        $conditions = [];
        foreach ($where as $key => $value) {
            $conditions[] = "`$key` = :where_$key";
            $params["where_$key"] = $value;
        }
        
        $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $conditions);
        return $this->execute($sql, $params);
    }

    /**
     * Delete Record (Permanent)
     */
    public function delete(string $table, array $where): int
    {
        $conditions = [];
        $params = [];
        
        foreach ($where as $key => $value) {
            $conditions[] = "`$key` = :$key";
            $params[$key] = $value;
        }
        
        $sql = "DELETE FROM `$table` WHERE " . implode(' AND ', $conditions);
        return $this->execute($sql, $params);
    }

    /**
     * Soft Delete Record (Set deleted_at)
     */
    public function softDelete(string $table, array $where): int
    {
        return $this->update($table, ['deleted_at' => date('Y-m-d H:i:s')], $where);
    }

    /**
     * Check if Record Exists
     */
    public function exists(string $table, array $where): bool
    {
        $conditions = [];
        $params = [];
        
        foreach ($where as $key => $value) {
            $conditions[] = "`$key` = :$key";
            $params[$key] = $value;
        }
        
        $sql = "SELECT 1 FROM `$table` WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
        return (bool)$this->queryValue($sql, $params);
    }

    /**
     * Count Records
     */
    public function count(string $table, array $where = []): int
    {
        $conditions = [];
        $params = [];
        
        foreach ($where as $key => $value) {
            $conditions[] = "`$key` = :$key";
            $params[$key] = $value;
        }
        
        $sql = "SELECT COUNT(*) FROM `$table`";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        return (int)$this->queryValue($sql, $params);
    }

    /**
     * Begin Transaction
     */
    public function beginTransaction(): bool
    {
        if ($this->transactionLevel === 0) {
            $this->connection->beginTransaction();
        } else {
            $this->connection->exec("SAVEPOINT level_{$this->transactionLevel}");
        }
        $this->transactionLevel++;
        return true;
    }

    /**
     * Commit Transaction
     */
    public function commit(): bool
    {
        if ($this->transactionLevel === 0) {
            throw new Exception('No active transaction to commit');
        }
        
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            return $this->connection->commit();
        }
        return true;
    }

    /**
     * Rollback Transaction
     */
    public function rollback(): bool
    {
        if ($this->transactionLevel === 0) {
            throw new Exception('No active transaction to rollback');
        }
        
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            return $this->connection->rollBack();
        } else {
            $this->connection->exec("ROLLBACK TO SAVEPOINT level_{$this->transactionLevel}");
            return true;
        }
    }

    /**
     * Get Last Insert ID
     */
    public function lastInsertId(): int
    {
        return (int)$this->connection->lastInsertId();
    }

    /**
     * Get Table Schema
     */
    public function getTableSchema(string $table): array
    {
        return $this->query("DESCRIBE `$table`");
    }

    /**
     * Get Table Columns
     */
    public function getTableColumns(string $table): array
    {
        $schema = $this->getTableSchema($table);
        return array_column($schema, 'Field');
    }

    /**
     * Truncate Table (Warning: Deletes All Data)
     */
    public function truncate(string $table): int
    {
        return $this->execute("TRUNCATE TABLE `$table`");
    }

    /**
     * Get Database Size
     */
    public function getDatabaseSize(): float
    {
        $result = $this->queryOne("
            SELECT SUM(data_length + index_length) / 1024 / 1024 as size_mb
            FROM information_schema.tables
            WHERE table_schema = :database
        ", ['database' => $this->config['database']]);
        
        return (float)($result['size_mb'] ?? 0);
    }

    /**
     * Get Table List
     */
    public function getTables(): array
    {
        $result = $this->query("SHOW TABLES");
        return array_column($result, 'Tables_in_' . $this->config['database']);
    }

    /**
     * Get Table Row Count
     */
    public function getTableRowCount(string $table): int
    {
        return (int)$this->queryValue("SELECT COUNT(*) FROM `$table`");
    }

    /**
     * Escape String (for manual queries)
     */
    public function escape(string $string): string
    {
        return $this->connection->quote($string);
    }

    /**
     * Log Query (for debugging)
     */
    private function logQuery(string $sql, array $params = []): void
    {
        if (!$this->logQueries) {
            return;
        }
        
        $this->queryLog[] = [
            'sql' => $sql,
            'params' => $params,
            'time' => microtime(true),
            'memory' => memory_get_usage()
        ];
        
        // Limit log size
        if (count($this->queryLog) > 1000) {
            array_shift($this->queryLog);
        }
    }

    /**
     * Get Query Log
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * Clear Query Log
     */
    public function clearQueryLog(): void
    {
        $this->queryLog = [];
    }

    /**
     * Enable/Disable Query Logging
     */
    public function setQueryLogging(bool $enabled): void
    {
        $this->logQueries = $enabled;
    }

    /**
     * Get Connection Status
     */
    public function isConnected(): bool
    {
        try {
            $this->connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Reconnect if Connection Lost
     */
    public function reconnect(): void
    {
        if (!$this->isConnected()) {
            $this->connect();
        }
    }

    /**
     * Get Database Stats
     */
    public function getStats(): array
    {
        $tables = $this->getTables();
        $totalRows = 0;
        $totalSize = 0;
        
        foreach ($tables as $table) {
            $totalRows += $this->getTableRowCount($table);
        }
        
        $totalSize = $this->getDatabaseSize();
        
        return [
            'tables' => count($tables),
            'total_rows' => $totalRows,
            'total_size_mb' => round($totalSize, 2),
            'connection_status' => $this->isConnected() ? 'connected' : 'disconnected'
        ];
    }

    /**
     * Execute Raw SQL (Use with caution)
     */
    public function raw(string $sql): int
    {
        $this->logQuery($sql);
        return $this->connection->exec($sql);
    }

    /**
     * Get PDO Error Info
     */
    public function errorInfo(): array
    {
        return $this->connection->errorInfo();
    }

    /**
     * Prevent cloning of Singleton
     */
    private function __clone() {}

    /**
     * Prevent unserializing of Singleton
     */
    public function __wakeup() {}
}

// ================================================================
// انتهى الملف
// ================================================================

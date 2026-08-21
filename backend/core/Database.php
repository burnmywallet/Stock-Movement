<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/core/Database.php
// الوصف: فئة الاتصال بقاعدة البيانات مع دعم المعاملات
// الإصدار: 2.0 Production Ready
// ================================================================

namespace Core;

use PDO;
use PDOException;

class Database
{
    private static $instance = null;
    private $connection;
    private $config;
    private $transactionLevel = 0;
    private $queryLog = [];

    private function __construct()
    {
        $this->config = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'database' => $_ENV['DB_NAME'] ?? 'inventory_system',
            'username' => $_ENV['DB_USER'] ?? 'angel',
            'password' => $_ENV['DB_PASS'] ?? 'Lecico10@',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]
        ];
        $this->connect();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect()
    {
        try {
            $dsn = "mysql:host={$this->config['host']};dbname={$this->config['database']};charset={$this->config['charset']}";
            $this->connection = new PDO($dsn, $this->config['username'], $this->config['password'], $this->config['options']);
        } catch (PDOException $e) {
            die(json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]));
        }
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function query($sql, $params = [])
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryOne($sql, $params = [])
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function queryValue($sql, $params = [])
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function execute($sql, $params = [])
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert($table, $data)
    {
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":$f", $fields);
        $sql = "INSERT INTO `$table` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        $this->execute($sql, $data);
        return (int)$this->connection->lastInsertId();
    }

    public function update($table, $data, $where)
    {
        $set = [];
        $params = [];
        foreach ($data as $k => $v) {
            $set[] = "`$k` = :set_$k";
            $params["set_$k"] = $v;
        }
        $w = [];
        foreach ($where as $k => $v) {
            $w[] = "`$k` = :where_$k";
            $params["where_$k"] = $v;
        }
        $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $w);
        return $this->execute($sql, $params);
    }

    public function delete($table, $where)
    {
        $w = [];
        $params = [];
        foreach ($where as $k => $v) {
            $w[] = "`$k` = :$k";
            $params[$k] = $v;
        }
        $sql = "DELETE FROM `$table` WHERE " . implode(' AND ', $w);
        return $this->execute($sql, $params);
    }

    public function beginTransaction()
    {
        if ($this->transactionLevel === 0) {
            $this->connection->beginTransaction();
        } else {
            $this->connection->exec("SAVEPOINT level_{$this->transactionLevel}");
        }
        $this->transactionLevel++;
        return true;
    }

    public function commit()
    {
        if ($this->transactionLevel === 0) throw new \Exception('No active transaction');
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            $this->connection->commit();
        }
        return true;
    }

    public function rollback()
    {
        if ($this->transactionLevel === 0) throw new \Exception('No active transaction');
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            $this->connection->rollBack();
        } else {
            $this->connection->exec("ROLLBACK TO SAVEPOINT level_{$this->transactionLevel}");
        }
        return true;
    }

    public function softDelete($table, $where)
    {
        return $this->update($table, ['deleted_at' => date('Y-m-d H:i:s')], $where);
    }

    public function getQueryLog()
    {
        return $this->queryLog;
    }
}

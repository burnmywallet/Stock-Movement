<?php
/**
 * ================================================================
 * Logistox - إعدادات قاعدة البيانات
 * ================================================================
 */

// ===== إعدادات الاتصال =====
define('DB_HOST', 'localhost');
define('DB_NAME', 'inventory_system');
define('DB_USER', 'angel');
define('DB_PASS', 'Lecico10@');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATION', 'utf8mb4_unicode_ci');

// ===== إعدادات PDO =====
define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => false,
]);

// ================================================================
// دالة الحصول على اتصال قاعدة البيانات
// ================================================================
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, DB_OPTIONS);
            
            // تعيين الترميز
            $pdo->exec("SET NAMES " . DB_CHARSET . " COLLATE " . DB_COLLATION);
            
            // تعيين المنطقة الزمنية
            $pdo->exec("SET time_zone = '+02:00'");
            
        } catch (PDOException $e) {
            // تسجيل الخطأ
            error_log('Database Connection Error: ' . $e->getMessage());
            
            // إرجاع استجابة JSON
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'فشل الاتصال بقاعدة البيانات',
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            exit;
        }
    }
    
    return $pdo;
}

// ================================================================
// دوال مساعدة لقاعدة البيانات
// ================================================================

/**
 * تنفيذ استعلام وإرجاع صف واحد
 */
function dbFetchOne($query, $params = []) {
    $stmt = getDBConnection()->prepare($query);
    $stmt->execute($params);
    return $stmt->fetch();
}

/**
 * تنفيذ استعلام وإرجاع جميع الصفوف
 */
function dbFetchAll($query, $params = []) {
    $stmt = getDBConnection()->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * تنفيذ استعلام وإرجاع قيمة واحدة
 */
function dbFetchValue($query, $params = []) {
    $stmt = getDBConnection()->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * تنفيذ استعلام INSERT وإرجاع آخر ID
 */
function dbInsert($query, $params = []) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $pdo->lastInsertId();
}

/**
 * تنفيذ استعلام UPDATE/DELETE وإرجاع عدد الصفوف المتأثرة
 */
function dbExecute($query, $params = []) {
    $stmt = getDBConnection()->prepare($query);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * بدء معاملة
 */
function dbBeginTransaction() {
    return getDBConnection()->beginTransaction();
}

/**
 * تأكيد المعاملة
 */
function dbCommit() {
    return getDBConnection()->commit();
}

/**
 * تراجع عن المعاملة
 */
function dbRollBack() {
    return getDBConnection()->rollBack();
}

// ================================================================
// تصدير الدوال
// ================================================================
return [
    'connection' => 'getDBConnection',
    'fetchOne' => 'dbFetchOne',
    'fetchAll' => 'dbFetchAll',
    'fetchValue' => 'dbFetchValue',
    'insert' => 'dbInsert',
    'execute' => 'dbExecute',
    'beginTransaction' => 'dbBeginTransaction',
    'commit' => 'dbCommit',
    'rollBack' => 'dbRollBack'
];

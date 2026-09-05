<?php
/**
 * ================================================================
 * Logistox API - نظام إدارة المخازن والمخزون v5.0
 * API موحد - المصدر الوحيد للبيانات
 * ================================================================
 */

// ===== إعدادات CORS =====
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ===== إعدادات قاعدة البيانات =====
define('DB_HOST', 'localhost');
define('DB_NAME', 'inventory_system');
define('DB_USER', 'angel');
define('DB_PASS', 'Lecico10@');

// ================================================================
// دوال مساعدة عامة
// ================================================================

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            sendError('فشل الاتصال بقاعدة البيانات: ' . $e->getMessage(), 'DB_ERROR', 500);
        }
    }
    return $pdo;
}

function sendSuccess($data = [], $message = 'تم بنجاح', $status = 200) {
    http_response_code($status);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

function sendError($message, $code = 'ERROR', $status = 400) {
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'code' => $code,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

function getInput() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function getBearerToken() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        return str_replace('Bearer ', '', $headers['Authorization']);
    }
    return null;
}

function authenticate() {
    $token = getBearerToken();
    if (!$token) {
        sendError('غير مصرح - يرجى تسجيل الدخول', 'UNAUTHORIZED', 401);
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, username, full_name, role_id, is_active FROM users WHERE remember_token = ? AND is_active = 1 AND deleted_at IS NULL");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendError('جلسة غير صالحة', 'INVALID_TOKEN', 401);
    }
    
    return $user;
}

function checkPermission($user, $permission) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.id
        WHERE rp.role_id = ? AND p.name = ?
    ");
    $stmt->execute([$user['role_id'], $permission]);
    $result = $stmt->fetch();
    return $result['count'] > 0;
}

function requirePermission($user, $permission) {
    if (!checkPermission($user, $permission)) {
        sendError('ليس لديك صلاحية لتنفيذ هذا الإجراء', 'FORBIDDEN', 403);
    }
}

function logActivity($userId, $action, $description = '', $entityType = null, $entityId = null, $oldValues = null, $newValues = null) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, description, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $oldValues ? json_encode($oldValues) : null,
        $newValues ? json_encode($newValues) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $description
    ]);
}

function generateToken($userId) {
    return bin2hex(random_bytes(32));
}

function validateRequired($data, $fields) {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            sendError('حقل "' . $field . '" مطلوب', 'MISSING_FIELD', 400);
        }
    }
}

function generateMovementNumber($type) {
    $prefix = [
        'RECEIPT' => 'RCV',
        'ISSUE' => 'ISS',
        'TRANSFER_OUT' => 'TRF-OUT',
        'TRANSFER_IN' => 'TRF-IN',
        'RETURN_IN' => 'RTN-IN',
        'RETURN_OUT' => 'RTN-OUT',
        'ADJUSTMENT' => 'ADJ',
        'COUNT_CORRECTION' => 'CNT',
        'RESERVATION' => 'RES',
        'RELEASE' => 'REL'
    ];
    $prefix = $prefix[$type] ?? 'MOV';
    $date = date('Ymd');
    $random = strtoupper(substr(uniqid(), -4));
    return $prefix . '-' . $date . '-' . $random;
}

function generateReceiptNumber() {
    return 'RCV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
}

function generateIssueNumber() {
    return 'ISS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
}

function generateTransferNumber() {
    return 'TRF-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
}

function generateReturnNumber() {
    return 'RTN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
}

// ================================================================
// تحليل المسار
// ================================================================

$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

if (preg_match('#/api/(.*)#', $requestUri, $matches)) {
    $path = $matches[1];
} else {
    $path = '';
}

$path = trim($path, '/');
$segments = explode('/', $path);
$resource = $segments[0] ?? '';
$action = $segments[1] ?? null;
$id = $segments[2] ?? null;

// ================================================================
// 1. TEST
// ================================================================
if ($resource === 'test') {
    sendSuccess([
        'php_version' => PHP_VERSION,
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'database' => 'connected',
        'timestamp' => date('Y-m-d H:i:s')
    ], '✅ API يعمل بشكل مثالي');
}

// ================================================================
// 2. AUTH - المصادقة
// ================================================================

// تسجيل الدخول
if ($resource === 'auth' && $action === 'login' && $requestMethod === 'POST') {
    $input = getInput();
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    
    if (!$username || !$password) {
        sendError('يرجى إدخال اسم المستخدم وكلمة المرور', 'MISSING_FIELDS', 400);
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, username, password_hash, full_name, role_id, is_active, is_locked, locked_until, failed_login_attempts FROM users WHERE username = ? AND deleted_at IS NULL");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendError('اسم المستخدم أو كلمة المرور غير صحيحة', 'INVALID_CREDENTIALS', 401);
    }
    
    if ($user['is_active'] != 1) {
        sendError('الحساب معطل، يرجى التواصل مع المدير', 'ACCOUNT_DISABLED', 403);
    }
    
    if ($user['is_locked'] == 1 && strtotime($user['locked_until']) > time()) {
        sendError('الحساب مقفل مؤقتاً. حاول بعد ' . ceil((strtotime($user['locked_until']) - time()) / 60) . ' دقيقة', 'ACCOUNT_LOCKED', 403);
    }
    
    if (!password_verify($password, $user['password_hash'])) {
        $failedAttempts = $user['failed_login_attempts'] + 1;
        
        $stmt = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'auth.max_login_attempts'");
        $maxAttempts = (int)($stmt->fetch()['value'] ?? 5);
        
        $stmt = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'auth.lockout_duration'");
        $lockDuration = (int)($stmt->fetch()['value'] ?? 15);
        
        if ($failedAttempts >= $maxAttempts) {
            $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = ?, is_locked = 1, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?");
            $stmt->execute([$failedAttempts, $lockDuration, $user['id']]);
            
            $stmt = $pdo->prepare("INSERT INTO login_history (user_id, username, ip_address, user_agent, is_success, failure_reason) VALUES (?, ?, ?, ?, 0, 'account_locked')");
            $stmt->execute([$user['id'], $username, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
            
            sendError('تم قفل الحساب بسبب محاولات دخول فاشلة متكررة', 'ACCOUNT_LOCKED', 403);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?");
            $stmt->execute([$failedAttempts, $user['id']]);
        }
        
        $stmt = $pdo->prepare("INSERT INTO login_history (user_id, username, ip_address, user_agent, is_success, failure_reason) VALUES (?, ?, ?, ?, 0, 'invalid_credentials')");
        $stmt->execute([$user['id'], $username, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
        
        sendError('اسم المستخدم أو كلمة المرور غير صحيحة', 'INVALID_CREDENTIALS', 401);
    }
    
    $token = generateToken($user['id']);
    
    $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, last_login_at = NOW(), last_login_ip = ?, failed_login_attempts = 0, is_locked = 0, locked_until = NULL WHERE id = ?");
    $stmt->execute([$token, $_SERVER['REMOTE_ADDR'] ?? null, $user['id']]);
    
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    $stmt = $pdo->prepare("INSERT INTO user_sessions (user_id, token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user['id'], $token, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, $expiresAt]);
    
    $stmt = $pdo->prepare("SELECT name, display_name FROM roles WHERE id = ?");
    $stmt->execute([$user['role_id']]);
    $role = $stmt->fetch();
    
    $stmt = $pdo->prepare("
        SELECT p.name 
        FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.id
        WHERE rp.role_id = ?
    ");
    $stmt->execute([$user['role_id']]);
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    logActivity($user['id'], 'LOGIN_SUCCESS', 'تسجيل دخول ناجح');
    
    $stmt = $pdo->prepare("INSERT INTO login_history (user_id, username, ip_address, user_agent, is_success) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$user['id'], $username, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);
    
    sendSuccess([
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $role['name'] ?? 'user',
            'role_display' => $role['display_name'] ?? 'مستخدم',
            'permissions' => $permissions,
            'must_change_password' => (bool)($user['must_change_password'] ?? false)
        ]
    ], 'تم تسجيل الدخول بنجاح');
}

// تسجيل الخروج
if ($resource === 'auth' && $action === 'logout' && $requestMethod === 'POST') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    
    logActivity($user['id'], 'LOGOUT', 'تسجيل خروج');
    
    sendSuccess([], 'تم تسجيل الخروج بنجاح');
}

// التحقق من الجلسة
if ($resource === 'auth' && $action === 'me' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT p.name FROM role_permissions rp JOIN permissions p ON rp.permission_id = p.id WHERE rp.role_id = ?");
    $stmt->execute([$user['role_id']]);
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    sendSuccess([
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role_id' => (int)$user['role_id'],
        'permissions' => $permissions
    ]);
}

// استرجاع كلمة المرور
if ($resource === 'auth' && $action === 'forgot-password' && $requestMethod === 'POST') {
    $input = getInput();
    $email = trim($input['email'] ?? '');
    
    if (!$email) {
        sendError('يرجى إدخال البريد الإلكتروني', 'MISSING_FIELDS', 400);
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, username, full_name, security_question_1, security_question_2, security_question_3 FROM users WHERE email = ? AND deleted_at IS NULL");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendError('البريد الإلكتروني غير مسجل', 'EMAIL_NOT_FOUND', 404);
    }
    
    sendSuccess([
        'user_id' => (int)$user['id'],
        'username' => $user['username'],
        'security_questions' => [
            'question_1' => $user['security_question_1'],
            'question_2' => $user['security_question_2'],
            'question_3' => $user['security_question_3']
        ]
    ], 'تم العثور على المستخدم');
}

// التحقق من إجابات الأمان
if ($resource === 'auth' && $action === 'verify-security-answers' && $requestMethod === 'POST') {
    $input = getInput();
    $userId = (int)($input['user_id'] ?? 0);
    $answers = $input['answers'] ?? [];
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, security_answer_1, security_answer_2, security_answer_3 FROM users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendError('المستخدم غير موجود', 'NOT_FOUND', 404);
    }
    
    $valid = true;
    if (isset($answers['answer_1']) && !password_verify($answers['answer_1'], $user['security_answer_1'])) $valid = false;
    if (isset($answers['answer_2']) && !password_verify($answers['answer_2'], $user['security_answer_2'])) $valid = false;
    if (isset($answers['answer_3']) && !password_verify($answers['answer_3'], $user['security_answer_3'])) $valid = false;
    
    if (!$valid) {
        sendError('إجابات الأمان غير صحيحة', 'INVALID_ANSWERS', 400);
    }
    
    $resetToken = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
    $stmt->execute([$resetToken, $userId]);
    
    sendSuccess(['reset_token' => $resetToken], 'تم التحقق بنجاح');
}

// إعادة تعيين كلمة المرور
if ($resource === 'auth' && $action === 'reset-password' && $requestMethod === 'POST') {
    $input = getInput();
    $resetToken = trim($input['reset_token'] ?? '');
    $newPassword = trim($input['new_password'] ?? '');
    
    if (!$resetToken || !$newPassword) {
        sendError('يرجى إدخال رمز إعادة التعيين وكلمة المرور الجديدة', 'MISSING_FIELDS', 400);
    }
    
    if (strlen($newPassword) < 8) {
        sendError('كلمة المرور يجب أن تكون 8 أحرف على الأقل', 'PASSWORD_TOO_SHORT', 400);
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE remember_token = ? AND deleted_at IS NULL");
    $stmt->execute([$resetToken]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendError('رمز إعادة التعيين غير صالح', 'INVALID_TOKEN', 400);
    }
    
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, remember_token = NULL, must_change_password = 0, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$passwordHash, $user['id']]);
    
    logActivity($user['id'], 'PASSWORD_RESET', 'إعادة تعيين كلمة المرور');
    
    sendSuccess([], 'تم إعادة تعيين كلمة المرور بنجاح');
}

// ================================================================
// 3. DASHBOARD - لوحة التحكم
// ================================================================

if ($resource === 'dashboard' && $action === 'stats' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products WHERE deleted_at IS NULL");
    $stats['products'] = (int)$stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM warehouses WHERE deleted_at IS NULL");
    $stats['warehouses'] = (int)$stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE deleted_at IS NULL");
    $stats['users'] = (int)$stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM stock_movements");
    $stats['movements'] = (int)$stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM products WHERE min_stock > 0 AND (SELECT COALESCE(SUM(quantity),0) FROM stock_balances WHERE product_id = products.id) <= min_stock");
    $stats['low_stock'] = (int)$stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT p.id) as total FROM products p LEFT JOIN stock_balances sb ON p.id = sb.product_id WHERE COALESCE(SUM(sb.quantity),0) = 0");
    $stats['out_of_stock'] = count($stmt->fetchAll());
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM stock_movements WHERE DATE(created_at) = CURDATE()");
    $stats['today_movements'] = (int)$stmt->fetch()['total'];
    
    $stats['last_update'] = date('Y-m-d H:i:s');
    
    sendSuccess($stats, 'تم جلب الإحصائيات');
}

if ($resource === 'dashboard' && $action === 'activities' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT al.*, u.full_name as user_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $activities = $stmt->fetchAll();
    
    sendSuccess($activities, 'تم جلب الأنشطة');
}

// ================================================================
// 4. PRODUCTS - الأصناف
// ================================================================

// جلب قائمة الأصناف
if ($resource === 'products' && $requestMethod === 'GET' && !$action) {
    $user = authenticate();
    
    $pdo = getDB();
    
    $query = "SELECT 
        p.id, p.code, p.barcode, p.sku, p.name, p.description, 
        c.name as category, c.id as category_id,
        u.name as unit, u.id as unit_id,
        p.min_stock, p.max_stock, p.is_active,
        p.barcode_type, p.is_barcode_enabled, p.is_sku_enabled,
        COALESCE(SUM(sb.quantity), 0) as stock,
        p.created_at, p.updated_at
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN units u ON p.unit_id = u.id
        LEFT JOIN stock_balances sb ON p.id = sb.product_id
        WHERE p.deleted_at IS NULL";
    
    if (isset($_GET['category_id'])) {
        $query .= " AND p.category_id = " . (int)$_GET['category_id'];
    }
    
    if (isset($_GET['status'])) {
        if ($_GET['status'] === 'active') {
            $query .= " AND p.is_active = 1";
        } elseif ($_GET['status'] === 'inactive') {
            $query .= " AND p.is_active = 0";
        }
    }
    
    if (isset($_GET['search'])) {
        $search = $pdo->quote('%' . $_GET['search'] . '%');
        $query .= " AND (p.name LIKE $search OR p.code LIKE $search OR p.barcode LIKE $search OR p.sku LIKE $search)";
    }
    
    $query .= " GROUP BY p.id ORDER BY p.id DESC";
    
    $stmt = $pdo->query($query);
    $products = $stmt->fetchAll();
    
    $result = array_map(function($p) {
        return [
            'id' => (int)$p['id'],
            'code' => $p['code'],
            'barcode' => $p['barcode'],
            'sku' => $p['sku'],
            'name' => $p['name'],
            'description' => $p['description'],
            'category' => $p['category'] ?? 'غير مصنف',
            'category_id' => $p['category_id'] ? (int)$p['category_id'] : null,
            'unit' => $p['unit'] ?? 'وحدة',
            'unit_id' => $p['unit_id'] ? (int)$p['unit_id'] : null,
            'min_stock' => (float)$p['min_stock'],
            'max_stock' => $p['max_stock'] ? (float)$p['max_stock'] : null,
            'stock' => (float)$p['stock'],
            'is_active' => (bool)$p['is_active'],
            'barcode_type' => $p['barcode_type'],
            'is_barcode_enabled' => (bool)$p['is_barcode_enabled'],
            'is_sku_enabled' => (bool)$p['is_sku_enabled'],
            'created_at' => $p['created_at'],
            'updated_at' => $p['updated_at']
        ];
    }, $products);
    
    sendSuccess($result, 'تم جلب الأصناف');
}

// جلب صنف واحد
if ($resource === 'products' && $action && $requestMethod === 'GET' && is_numeric($action)) {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category, u.name as unit
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN units u ON p.unit_id = u.id
        WHERE p.id = ? AND p.deleted_at IS NULL
    ");
    $stmt->execute([$action]);
    $product = $stmt->fetch();
    
    if (!$product) {
        sendError('المنتج غير موجود', 'NOT_FOUND', 404);
    }
    
    sendSuccess($product);
}

// إضافة صنف جديد
if ($resource === 'products' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'products.create');
    
    $input = getInput();
    validateRequired($input, ['name', 'code']);
    
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE code = ? AND deleted_at IS NULL");
    $stmt->execute([$input['code']]);
    if ($stmt->fetch()['count'] > 0) {
        sendError('كود المنتج موجود مسبقاً', 'DUPLICATE_CODE', 400);
    }
    
    if (isset($input['barcode']) && !empty($input['barcode'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products WHERE barcode = ? AND deleted_at IS NULL");
        $stmt->execute([$input['barcode']]);
        if ($stmt->fetch()['count'] > 0) {
            sendError('الباركود موجود مسبقاً', 'DUPLICATE_BARCODE', 400);
        }
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO products (code, barcode, sku, name, description, category_id, unit_id, min_stock, max_stock, barcode_type, is_barcode_enabled, is_sku_enabled, is_active, created_at, updated_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
    ");
    $stmt->execute([
        $input['code'],
        $input['barcode'] ?? null,
        $input['sku'] ?? null,
        $input['name'],
        $input['description'] ?? '',
        $input['category_id'] ?? null,
        $input['unit_id'] ?? null,
        $input['min_stock'] ?? 0,
        $input['max_stock'] ?? null,
        $input['barcode_type'] ?? 'EAN13',
        $input['is_barcode_enabled'] ?? 1,
        $input['is_sku_enabled'] ?? 1,
        $input['is_active'] ?? 1,
        $user['id']
    ]);
    
    $newId = $pdo->lastInsertId();
    
    logActivity($user['id'], 'PRODUCT_CREATED', 'إضافة منتج جديد: ' . $input['name'], 'product', $newId, null, $input);
    
    sendSuccess(['id' => (int)$newId], 'تم إضافة المنتج بنجاح', 201);
}

// تحديث صنف
if ($resource === 'products' && $action && $requestMethod === 'PUT' && is_numeric($action)) {
    $user = authenticate();
    requirePermission($user, 'products.update');
    
    $input = getInput();
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$action]);
    $product = $stmt->fetch();
    
    if (!$product) {
        sendError('المنتج غير موجود', 'NOT_FOUND', 404);
    }
    
    $oldValues = $product;
    
    $stmt = $pdo->prepare("
        UPDATE products 
        SET name = ?, description = ?, category_id = ?, unit_id = ?, min_stock = ?, max_stock = ?, barcode = ?, sku = ?, barcode_type = ?, is_barcode_enabled = ?, is_sku_enabled = ?, is_active = ?, updated_at = NOW(), updated_by = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $input['name'] ?? $product['name'],
        $input['description'] ?? $product['description'],
        $input['category_id'] ?? $product['category_id'],
        $input['unit_id'] ?? $product['unit_id'],
        $input['min_stock'] ?? $product['min_stock'],
        $input['max_stock'] ?? $product['max_stock'],
        $input['barcode'] ?? $product['barcode'],
        $input['sku'] ?? $product['sku'],
        $input['barcode_type'] ?? $product['barcode_type'],
        $input['is_barcode_enabled'] ?? $product['is_barcode_enabled'],
        $input['is_sku_enabled'] ?? $product['is_sku_enabled'],
        $input['is_active'] ?? $product['is_active'],
        $user['id'],
        $action
    ]);
    
    logActivity($user['id'], 'PRODUCT_UPDATED', 'تعديل منتج: ' . $product['name'], 'product', $action, $oldValues, $input);
    
    sendSuccess([], 'تم تحديث المنتج بنجاح');
}

// حذف صنف
if ($resource === 'products' && $action && $requestMethod === 'DELETE' && is_numeric($action)) {
    $user = authenticate();
    requirePermission($user, 'products.delete');
    
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$action]);
    $product = $stmt->fetch();
    
    if (!$product) {
        sendError('المنتج غير موجود', 'NOT_FOUND', 404);
    }
    
    $oldValues = $product;
    
    $stmt = $pdo->prepare("UPDATE products SET deleted_at = NOW(), updated_by = ? WHERE id = ?");
    $stmt->execute([$user['id'], $action]);
    
    logActivity($user['id'], 'PRODUCT_DELETED', 'حذف منتج: ' . $product['name'], 'product', $action, $oldValues, null);
    
    sendSuccess([], 'تم حذف المنتج بنجاح');
}

// ================================================================
// 5. WAREHOUSES - المخازن
// ================================================================

// جلب قائمة المخازن
if ($resource === 'warehouses' && $requestMethod === 'GET' && !$action) {
    $user = authenticate();
    
    $pdo = getDB();
    
    $query = "SELECT 
        w.id, w.code, w.name, w.type, w.location, w.address, w.is_active,
        w.parent_id, pw.name as parent_name,
        u.full_name as manager_name,
        COUNT(DISTINCT sb.product_id) as stock_count,
        w.capacity, w.created_at
        FROM warehouses w
        LEFT JOIN users u ON w.manager_id = u.id
        LEFT JOIN warehouses pw ON w.parent_id = pw.id
        LEFT JOIN stock_balances sb ON w.id = sb.warehouse_id
        WHERE w.deleted_at IS NULL";
    
    if (isset($_GET['search'])) {
        $search = $pdo->quote('%' . $_GET['search'] . '%');
        $query .= " AND (w.name LIKE $search OR w.code LIKE $search)";
    }
    
    if (isset($_GET['type'])) {
        $query .= " AND w.type = '" . $pdo->quote($_GET['type']) . "'";
    }
    
    $query .= " GROUP BY w.id ORDER BY w.id DESC";
    
    $stmt = $pdo->query($query);
    $warehouses = $stmt->fetchAll();
    
    $result = array_map(function($w) {
        return [
            'id' => (int)$w['id'],
            'code' => $w['code'],
            'name' => $w['name'],
            'type' => $w['type'] ?? 'main',
            'location' => $w['location'] ?? '',
            'address' => $w['address'] ?? '',
            'parent_id' => $w['parent_id'] ? (int)$w['parent_id'] : null,
            'parent_name' => $w['parent_name'] ?? '',
            'manager_name' => $w['manager_name'] ?? '',
            'stock_count' => (int)$w['stock_count'],
            'capacity' => $w['capacity'] ? (float)$w['capacity'] : null,
            'is_active' => (bool)$w['is_active'],
            'created_at' => $w['created_at']
        ];
    }, $warehouses);
    
    sendSuccess($result, 'تم جلب المخازن');
}

// جلب مخزن واحد
if ($resource === 'warehouses' && $action && $requestMethod === 'GET' && is_numeric($action)) {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT w.*, u.full_name as manager_name, pw.name as parent_name
        FROM warehouses w
        LEFT JOIN users u ON w.manager_id = u.id
        LEFT JOIN warehouses pw ON w.parent_id = pw.id
        WHERE w.id = ? AND w.deleted_at IS NULL
    ");
    $stmt->execute([$action]);
    $warehouse = $stmt->fetch();
    
    if (!$warehouse) {
        sendError('المخزن غير موجود', 'NOT_FOUND', 404);
    }
    
    sendSuccess($warehouse);
}

// إضافة مخزن
if ($resource === 'warehouses' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'warehouses.create');
    
    $input = getInput();
    validateRequired($input, ['name', 'code']);
    
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM warehouses WHERE code = ? AND deleted_at IS NULL");
    $stmt->execute([$input['code']]);
    if ($stmt->fetch()['count'] > 0) {
        sendError('كود المخزن موجود مسبقاً', 'DUPLICATE_CODE', 400);
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO warehouses (code, name, type, parent_id, location, address, manager_id, capacity, is_active, created_at, updated_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
    ");
    $stmt->execute([
        $input['code'],
        $input['name'],
        $input['type'] ?? 'main',
        $input['parent_id'] ?? null,
        $input['location'] ?? '',
        $input['address'] ?? '',
        $input['manager_id'] ?? null,
        $input['capacity'] ?? null,
        $input['is_active'] ?? 1,
        $user['id']
    ]);
    
    $newId = $pdo->lastInsertId();
    
    if (isset($input['parent_id']) && $input['parent_id']) {
        $stmt = $pdo->prepare("INSERT INTO warehouse_branches (warehouse_id, parent_id) VALUES (?, ?)");
        $stmt->execute([$newId, $input['parent_id']]);
    }
    
    logActivity($user['id'], 'WAREHOUSE_CREATED', 'إضافة مخزن جديد: ' . $input['name'], 'warehouse', $newId, null, $input);
    
    sendSuccess(['id' => (int)$newId], 'تم إضافة المخزن بنجاح', 201);
}

// تحديث مخزن
if ($resource === 'warehouses' && $action && $requestMethod === 'PUT' && is_numeric($action)) {
    $user = authenticate();
    requirePermission($user, 'warehouses.update');
    
    $input = getInput();
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$action]);
    $warehouse = $stmt->fetch();
    
    if (!$warehouse) {
        sendError('المخزن غير موجود', 'NOT_FOUND', 404);
    }
    
    $oldValues = $warehouse;
    
    $stmt = $pdo->prepare("
        UPDATE warehouses 
        SET name = ?, type = ?, parent_id = ?, location = ?, address = ?, manager_id = ?, capacity = ?, is_active = ?, updated_at = NOW(), updated_by = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $input['name'] ?? $warehouse['name'],
        $input['type'] ?? $warehouse['type'],
        $input['parent_id'] ?? $warehouse['parent_id'],
        $input['location'] ?? $warehouse['location'],
        $input['address'] ?? $warehouse['address'],
        $input['manager_id'] ?? $warehouse['manager_id'],
        $input['capacity'] ?? $warehouse['capacity'],
        $input['is_active'] ?? $warehouse['is_active'],
        $user['id'],
        $action
    ]);
    
    if (isset($input['parent_id'])) {
        $stmt = $pdo->prepare("DELETE FROM warehouse_branches WHERE warehouse_id = ?");
        $stmt->execute([$action]);
        
        if ($input['parent_id']) {
            $stmt = $pdo->prepare("INSERT INTO warehouse_branches (warehouse_id, parent_id) VALUES (?, ?)");
            $stmt->execute([$action, $input['parent_id']]);
        }
    }
    
    logActivity($user['id'], 'WAREHOUSE_UPDATED', 'تعديل مخزن: ' . $warehouse['name'], 'warehouse', $action, $oldValues, $input);
    
    sendSuccess([], 'تم تحديث المخزن بنجاح');
}

// حذف مخزن
if ($resource === 'warehouses' && $action && $requestMethod === 'DELETE' && is_numeric($action)) {
    $user = authenticate();
    requirePermission($user, 'warehouses.delete');
    
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT * FROM warehouses WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$action]);
    $warehouse = $stmt->fetch();
    
    if (!$warehouse) {
        sendError('المخزن غير موجود', 'NOT_FOUND', 404);
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM stock_movements WHERE warehouse_id = ?");
    $stmt->execute([$action]);
    if ($stmt->fetch()['count'] > 0) {
        sendError('لا يمكن حذف المخزن لوجود حركات مرتبطة به', 'HAS_MOVEMENTS', 400);
    }
    
    $oldValues = $warehouse;
    
    $stmt = $pdo->prepare("UPDATE warehouses SET deleted_at = NOW(), updated_by = ? WHERE id = ?");
    $stmt->execute([$user['id'], $action]);
    
    $stmt = $pdo->prepare("DELETE FROM warehouse_branches WHERE warehouse_id = ? OR parent_id = ?");
    $stmt->execute([$action, $action]);
    
    logActivity($user['id'], 'WAREHOUSE_DELETED', 'حذف مخزن: ' . $warehouse['name'], 'warehouse', $action, $oldValues, null);
    
    sendSuccess([], 'تم حذف المخزن بنجاح');
}

// ================================================================
// 6. USERS - المستخدمين
// ================================================================

// جلب قائمة المستخدمين
if ($resource === 'users' && $requestMethod === 'GET' && !$action) {
    $user = authenticate();
    requirePermission($user, 'users.view');
    
    $pdo = getDB();
    
    $query = "SELECT 
        u.id, u.username, u.full_name, u.email, u.phone,
        r.name as role, r.display_name as role_display,
        w.name as warehouse,
        u.is_active, u.is_locked, u.failed_login_attempts,
        u.last_login_at, u.last_login_ip, u.created_at,
        u.must_change_password
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN warehouses w ON u.warehouse_id = w.id
        WHERE u.deleted_at IS NULL";
    
    $query .= " ORDER BY u.id DESC";
    
    $stmt = $pdo->query($query);
    $users = $stmt->fetchAll();
    
    $result = array_map(function($u) {
        return [
            'id' => (int)$u['id'],
            'username' => $u['username'],
            'full_name' => $u['full_name'],
            'email' => $u['email'],
            'phone' => $u['phone'],
            'role' => $u['role'] ?? 'user',
            'role_display' => $u['role_display'] ?? 'مستخدم',
            'warehouse' => $u['warehouse'] ?? '',
            'is_active' => (bool)$u['is_active'],
            'is_locked' => (bool)$u['is_locked'],
            'failed_login_attempts' => (int)$u['failed_login_attempts'],
            'last_login_at' => $u['last_login_at'],
            'last_login_ip' => $u['last_login_ip'],
            'created_at' => $u['created_at'],
            'must_change_password' => (bool)$u['must_change_password']
        ];
    }, $users);
    
    sendSuccess($result, 'تم جلب المستخدمين');
}

// إضافة مستخدم
if ($resource === 'users' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'users.create');
    
    $input = getInput();
    validateRequired($input, ['username', 'password', 'full_name', 'role_id']);
    
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ? AND deleted_at IS NULL");
    $stmt->execute([$input['username']]);
    if ($stmt->fetch()['count'] > 0) {
        sendError('اسم المستخدم موجود مسبقاً', 'DUPLICATE_USERNAME', 400);
    }
    
    if (isset($input['email']) && !empty($input['email'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$input['email']]);
        if ($stmt->fetch()['count'] > 0) {
            sendError('البريد الإلكتروني موجود مسبقاً', 'DUPLICATE_EMAIL', 400);
        }
    }
    
    $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (username, password_hash, full_name, email, phone, role_id, warehouse_id, is_active, must_change_password, created_at, updated_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
    ");
    $stmt->execute([
        $input['username'],
        $passwordHash,
        $input['full_name'],
        $input['email'] ?? null,
        $input['phone'] ?? null,
        $input['role_id'],
        $input['warehouse_id'] ?? null,
        $input['is_active'] ?? 1,
        $input['must_change_password'] ?? 1,
        $user['id']
    ]);
    
    $newId = $pdo->lastInsertId();
    
    logActivity($user['id'], 'USER_CREATED', 'إضافة مستخدم جديد: ' . $input['username'], 'user', $newId, null, $input);
    
    sendSuccess(['id' => (int)$newId], 'تم إضافة المستخدم بنجاح', 201);
}

// تحديث مستخدم
if ($resource === 'users' && $action && $requestMethod === 'PUT' && is_numeric($action)) {
    $user = authenticate();
    requirePermission($user, 'users.update');
    
    $input = getInput();
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$action]);
    $userData = $stmt->fetch();
    
    if (!$userData) {
        sendError('المستخدم غير موجود', 'NOT_FOUND', 404);
    }
    
    $oldValues = $userData;
    
    $stmt = $pdo->prepare("
        UPDATE users 
        SET full_name = ?, email = ?, phone = ?, role_id = ?, warehouse_id = ?, is_active = ?, updated_at = NOW(), updated_by = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $input['full_name'] ?? $userData['full_name'],
        $input['email'] ?? $userData['email'],
        $input['phone'] ?? $userData['phone'],
        $input['role_id'] ?? $userData['role_id'],
        $input['warehouse_id'] ?? $userData['warehouse_id'],
        $input['is_active'] ?? $userData['is_active'],
        $user['id'],
        $action
    ]);
    
    if (isset($input['password']) && !empty($input['password'])) {
        $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
        $stmt->execute([$passwordHash, $action]);
    }
    
    logActivity($user['id'], 'USER_UPDATED', 'تعديل مستخدم: ' . $userData['username'], 'user', $action, $oldValues, $input);
    
    sendSuccess([], 'تم تحديث المستخدم بنجاح');
}

// حذف مستخدم
if ($resource === 'users' && $action && $requestMethod === 'DELETE' && is_numeric($action)) {
    $user = authenticate();
    requirePermission($user, 'users.delete');
    
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$action]);
    $userData = $stmt->fetch();
    
    if (!$userData) {
        sendError('المستخدم غير موجود', 'NOT_FOUND', 404);
    }
    
    $oldValues = $userData;
    
    $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), updated_by = ? WHERE id = ?");
    $stmt->execute([$user['id'], $action]);
    
    logActivity($user['id'], 'USER_DELETED', 'حذف مستخدم: ' . $userData['username'], 'user', $action, $oldValues, null);
    
    sendSuccess([], 'تم حذف المستخدم بنجاح');
}

// ================================================================
// 7. CATEGORIES - التصنيفات
// ================================================================

if ($resource === 'categories' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("SELECT * FROM categories WHERE deleted_at IS NULL ORDER BY name");
    $categories = $stmt->fetchAll();
    
    sendSuccess($categories, 'تم جلب التصنيفات');
}

if ($resource === 'categories' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'products.create');
    
    $input = getInput();
    validateRequired($input, ['name']);
    
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO categories (name, description, parent_id, is_active, created_at, updated_at, created_by) VALUES (?, ?, ?, ?, NOW(), NOW(), ?)");
    $stmt->execute([$input['name'], $input['description'] ?? '', $input['parent_id'] ?? null, $input['is_active'] ?? 1, $user['id']]);
    
    $newId = $pdo->lastInsertId();
    
    logActivity($user['id'], 'CATEGORY_CREATED', 'إضافة تصنيف: ' . $input['name'], 'category', $newId);
    
    sendSuccess(['id' => (int)$newId], 'تم إضافة التصنيف بنجاح', 201);
}

// ================================================================
// 8. UNITS - الوحدات
// ================================================================

if ($resource === 'units' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("SELECT * FROM units WHERE deleted_at IS NULL ORDER BY name");
    $units = $stmt->fetchAll();
    
    sendSuccess($units, 'تم جلب الوحدات');
}

if ($resource === 'units' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'products.create');
    
    $input = getInput();
    validateRequired($input, ['name', 'symbol']);
    
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO units (name, symbol, is_active, created_at, updated_at, created_by) VALUES (?, ?, ?, NOW(), NOW(), ?)");
    $stmt->execute([$input['name'], $input['symbol'], $input['is_active'] ?? 1, $user['id']]);
    
    $newId = $pdo->lastInsertId();
    
    logActivity($user['id'], 'UNIT_CREATED', 'إضافة وحدة: ' . $input['name'], 'unit', $newId);
    
    sendSuccess(['id' => (int)$newId], 'تم إضافة الوحدة بنجاح', 201);
}

// ================================================================
// 9. MOVEMENTS - الحركات المخزنية
// ================================================================

if ($resource === 'movements' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT sm.*, p.name as product_name, p.code as product_code, w.name as warehouse_name,
        fw.name as from_warehouse_name, tw.name as to_warehouse_name,
        u.full_name as user_name
        FROM stock_movements sm
        LEFT JOIN products p ON sm.product_id = p.id
        LEFT JOIN warehouses w ON sm.warehouse_id = w.id
        LEFT JOIN warehouses fw ON sm.from_warehouse_id = fw.id
        LEFT JOIN warehouses tw ON sm.to_warehouse_id = tw.id
        LEFT JOIN users u ON sm.user_id = u.id
        ORDER BY sm.created_at DESC
        LIMIT 100
    ");
    $movements = $stmt->fetchAll();
    
    sendSuccess($movements, 'تم جلب الحركات');
}

// إنشاء حركة مخزنية
if ($resource === 'movements' && $requestMethod === 'POST') {
    $user = authenticate();
    
    $input = getInput();
    validateRequired($input, ['product_id', 'warehouse_id', 'type', 'quantity']);
    
    $pdo = getDB();
    $pdo->beginTransaction();
    
    try {
        $quantity = (float)$input['quantity'];
        $movementNumber = generateMovementNumber($input['type']);
        
        $stmt = $pdo->prepare("
            INSERT INTO stock_movements (movement_number, product_id, warehouse_id, from_warehouse_id, to_warehouse_id, type, quantity, notes, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $movementNumber,
            $input['product_id'],
            $input['warehouse_id'],
            $input['from_warehouse_id'] ?? null,
            $input['to_warehouse_id'] ?? null,
            $input['type'],
            $quantity,
            $input['notes'] ?? '',
            $user['id']
        ]);
        
        $movementId = $pdo->lastInsertId();
        
        switch ($input['type']) {
            case 'RECEIPT':
            case 'RETURN_IN':
                $stmt = $pdo->prepare("
                    INSERT INTO stock_balances (product_id, warehouse_id, quantity, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()
                ");
                $stmt->execute([$input['product_id'], $input['warehouse_id'], $quantity]);
                break;
                
            case 'ISSUE':
            case 'RETURN_OUT':
                $stmt = $pdo->prepare("
                    UPDATE stock_balances 
                    SET quantity = quantity - ?, updated_at = NOW()
                    WHERE product_id = ? AND warehouse_id = ?
                ");
                $stmt->execute([$quantity, $input['product_id'], $input['warehouse_id']]);
                break;
                
            case 'TRANSFER_OUT':
                $stmt = $pdo->prepare("
                    UPDATE stock_balances 
                    SET quantity = quantity - ?, updated_at = NOW()
                    WHERE product_id = ? AND warehouse_id = ?
                ");
                $stmt->execute([$quantity, $input['product_id'], $input['warehouse_id']]);
                break;
                
            case 'TRANSFER_IN':
                $stmt = $pdo->prepare("
                    INSERT INTO stock_balances (product_id, warehouse_id, quantity, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()
                ");
                $stmt->execute([$input['product_id'], $input['to_warehouse_id'], $quantity]);
                break;
                
            case 'ADJUSTMENT':
            case 'COUNT_CORRECTION':
                $stmt = $pdo->prepare("
                    UPDATE stock_balances 
                    SET quantity = ?, updated_at = NOW()
                    WHERE product_id = ? AND warehouse_id = ?
                ");
                $stmt->execute([$quantity, $input['product_id'], $input['warehouse_id']]);
                break;
        }
        
        logActivity($user['id'], 'MOVEMENT_CREATED', 'حركة مخزنية: ' . $input['type'] . ' بكمية ' . $quantity, 'movement', $movementId, null, $input);
        
        $pdo->commit();
        
        sendSuccess(['id' => (int)$movementId, 'movement_number' => $movementNumber], 'تم تسجيل الحركة بنجاح', 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendError('خطأ في تسجيل الحركة: ' . $e->getMessage(), 'MOVEMENT_ERROR', 500);
    }
}

// ================================================================
// 10. STOCK BALANCES - الأرصدة
// ================================================================

if ($resource === 'stock-balances' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT sb.*, p.name as product_name, p.code as product_code, w.name as warehouse_name
        FROM stock_balances sb
        LEFT JOIN products p ON sb.product_id = p.id
        LEFT JOIN warehouses w ON sb.warehouse_id = w.id
        ORDER BY p.name
    ");
    $balances = $stmt->fetchAll();
    
    sendSuccess($balances, 'تم جلب الأرصدة');
}

// ================================================================
// 11. NOTIFICATIONS - الإشعارات
// ================================================================

if ($resource === 'notifications' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? OR user_id IS NULL
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
    
    sendSuccess($notifications, 'تم جلب الإشعارات');
}

if ($resource === 'notifications' && $action === 'read' && $requestMethod === 'POST') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    
    sendSuccess([], 'تم تعيين الإشعار كمقروء');
}

// ================================================================
// 12. REPORTS - التقارير
// ================================================================

if ($resource === 'reports' && $action === 'products-by-category' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'reports.view');
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT c.name as category, COUNT(p.id) as product_count, COALESCE(SUM(sb.quantity), 0) as total_quantity
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id AND p.deleted_at IS NULL
        LEFT JOIN stock_balances sb ON p.id = sb.product_id
        WHERE c.deleted_at IS NULL
        GROUP BY c.id
        ORDER BY total_quantity DESC
    ");
    $reports = $stmt->fetchAll();
    
    sendSuccess($reports, 'تم جلب التقرير');
}

if ($resource === 'reports' && $action === 'daily-movements' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'reports.view');
    
    $pdo = getDB();
    $date = $_GET['date'] ?? date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT sm.type, COUNT(*) as movement_count, SUM(sm.quantity) as total_quantity
        FROM stock_movements sm
        WHERE DATE(sm.created_at) = ?
        GROUP BY sm.type
    ");
    $stmt->execute([$date]);
    $reports = $stmt->fetchAll();
    
    sendSuccess($reports, 'تم جلب التقرير');
}

if ($resource === 'reports' && $action === 'low-stock' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'reports.view');
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT p.id, p.code, p.name, p.min_stock, COALESCE(SUM(sb.quantity), 0) as current_stock,
        (p.min_stock - COALESCE(SUM(sb.quantity), 0)) as shortage
        FROM products p
        LEFT JOIN stock_balances sb ON p.id = sb.product_id
        WHERE p.deleted_at IS NULL AND p.min_stock > 0
        GROUP BY p.id
        HAVING current_stock <= p.min_stock
        ORDER BY shortage DESC
    ");
    $reports = $stmt->fetchAll();
    
    sendSuccess($reports, 'تم جلب التقرير');
}

if ($resource === 'reports' && $action === 'out-of-stock' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'reports.view');
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT p.id, p.code, p.name, p.min_stock
        FROM products p
        LEFT JOIN stock_balances sb ON p.id = sb.product_id
        WHERE p.deleted_at IS NULL
        GROUP BY p.id
        HAVING COALESCE(SUM(sb.quantity), 0) = 0
    ");
    $reports = $stmt->fetchAll();
    
    sendSuccess($reports, 'تم جلب التقرير');
}

if ($resource === 'reports' && $action === 'product-movements' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'reports.view');
    
    $productId = (int)($_GET['product_id'] ?? 0);
    $pdo = getDB();
    
    $stmt = $pdo->prepare("
        SELECT sm.*, w.name as warehouse_name, u.full_name as user_name
        FROM stock_movements sm
        LEFT JOIN warehouses w ON sm.warehouse_id = w.id
        LEFT JOIN users u ON sm.user_id = u.id
        WHERE sm.product_id = ?
        ORDER BY sm.created_at DESC
    ");
    $stmt->execute([$productId]);
    $reports = $stmt->fetchAll();
    
    sendSuccess($reports, 'تم جلب التقرير');
}

if ($resource === 'reports' && $action === 'warehouse-movements' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'reports.view');
    
    $warehouseId = (int)($_GET['warehouse_id'] ?? 0);
    $pdo = getDB();
    
    $stmt = $pdo->prepare("
        SELECT sm.*, p.name as product_name, u.full_name as user_name
        FROM stock_movements sm
        LEFT JOIN products p ON sm.product_id = p.id
        LEFT JOIN users u ON sm.user_id = u.id
        WHERE sm.warehouse_id = ?
        ORDER BY sm.created_at DESC
    ");
    $stmt->execute([$warehouseId]);
    $reports = $stmt->fetchAll();
    
    sendSuccess($reports, 'تم جلب التقرير');
}

if ($resource === 'reports' && $action === 'returns' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'reports.view');
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT r.*, p.name as product_name, w.name as warehouse_name, u.full_name as user_name
        FROM returns r
        LEFT JOIN products p ON r.product_id = p.id
        LEFT JOIN warehouses w ON r.warehouse_id = w.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.deleted_at IS NULL
        ORDER BY r.created_at DESC
    ");
    $reports = $stmt->fetchAll();
    
    sendSuccess($reports, 'تم جلب التقرير');
}

if ($resource === 'reports' && $action === 'users-activity' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'reports.view');
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT u.username, u.full_name, COUNT(al.id) as activity_count, MAX(al.created_at) as last_activity
        FROM users u
        LEFT JOIN audit_logs al ON u.id = al.user_id
        WHERE u.deleted_at IS NULL
        GROUP BY u.id
        ORDER BY activity_count DESC
    ");
    $reports = $stmt->fetchAll();
    
    sendSuccess($reports, 'تم جلب التقرير');
}

if ($resource === 'reports' && $action === 'warehouses-status' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'reports.view');
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT w.id, w.code, w.name, w.type, w.is_active,
        COUNT(DISTINCT p.id) as total_products,
        COALESCE(SUM(sb.quantity), 0) as total_quantity
        FROM warehouses w
        LEFT JOIN stock_balances sb ON w.id = sb.warehouse_id
        LEFT JOIN products p ON sb.product_id = p.id
        WHERE w.deleted_at IS NULL
        GROUP BY w.id
        ORDER BY w.name
    ");
    $reports = $stmt->fetchAll();
    
    sendSuccess($reports, 'تم جلب التقرير');
}

// ================================================================
// 13. AUDIT - سجل التدقيق
// ================================================================

if ($resource === 'audit' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'audit.view');
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT al.*, u.full_name as user_name, u.username as username
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 100
    ");
    $logs = $stmt->fetchAll();
    
    sendSuccess($logs, 'تم جلب سجل التدقيق');
}

// ================================================================
// 14. SETTINGS - الإعدادات
// ================================================================

if ($resource === 'settings' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("SELECT * FROM settings");
    $settings = $stmt->fetchAll();
    
    $result = [];
    foreach ($settings as $setting) {
        $result[$setting['key']] = $setting['value'];
    }
    
    sendSuccess($result, 'تم جلب الإعدادات');
}

if ($resource === 'settings' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'settings.update');
    
    $input = getInput();
    $pdo = getDB();
    
    foreach ($input as $key => $value) {
        $stmt = $pdo->prepare("
            INSERT INTO settings (`key`, `value`, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()
        ");
        $stmt->execute([$key, $value]);
    }
    
    logActivity($user['id'], 'SETTINGS_UPDATED', 'تحديث الإعدادات', 'settings', null, null, $input);
    
    sendSuccess([], 'تم حفظ الإعدادات بنجاح');
}

// ================================================================
// 15. ROLES - الأدوار
// ================================================================

if ($resource === 'roles' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("SELECT * FROM roles WHERE deleted_at IS NULL ORDER BY id");
    $roles = $stmt->fetchAll();
    
    sendSuccess($roles, 'تم جلب الأدوار');
}

// ================================================================
// 16. PERMISSIONS - الصلاحيات
// ================================================================

if ($resource === 'permissions' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("SELECT * FROM permissions ORDER BY module, name");
    $permissions = $stmt->fetchAll();
    
    sendSuccess($permissions, 'تم جلب الصلاحيات');
}

if ($resource === 'permissions' && $action === 'roles' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT rp.role_id, rp.permission_id, r.name as role_name, p.name as permission_name
        FROM role_permissions rp
        JOIN roles r ON rp.role_id = r.id
        JOIN permissions p ON rp.permission_id = p.id
        ORDER BY r.id, p.module, p.name
    ");
    $permissions = $stmt->fetchAll();
    
    sendSuccess($permissions, 'تم جلب صلاحيات الأدوار');
}

if ($resource === 'permissions' && $action === 'update' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'permissions.manage');
    
    $input = getInput();
    $roleId = (int)($input['role_id'] ?? 0);
    $permissionIds = $input['permission_ids'] ?? [];
    
    if (!$roleId) {
        sendError('يرجى تحديد الدور', 'MISSING_ROLE', 400);
    }
    
    $pdo = getDB();
    
    $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
    $stmt->execute([$roleId]);
    
    if (!empty($permissionIds)) {
        $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($permissionIds as $permissionId) {
            $stmt->execute([$roleId, $permissionId]);
        }
    }
    
    logActivity($user['id'], 'PERMISSIONS_UPDATED', 'تحديث صلاحيات الدور #' . $roleId, 'permissions', $roleId, null, $input);
    
    sendSuccess([], 'تم تحديث الصلاحيات بنجاح');
}

// ================================================================
// 17. THEMES - الثيمات
// ================================================================

if ($resource === 'themes' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("SELECT * FROM themes WHERE is_active = 1 ORDER BY is_default DESC");
    $themes = $stmt->fetchAll();
    
    sendSuccess($themes, 'تم جلب الثيمات');
}

// ================================================================
// 18. BACKUP - النسخ الاحتياطي
// ================================================================

if ($resource === 'backup' && $requestMethod === 'GET') {
    $user = authenticate();
    requirePermission($user, 'settings.view');
    
    $pdo = getDB();
    $stmt = $pdo->query("SELECT * FROM backups ORDER BY created_at DESC LIMIT 10");
    $backups = $stmt->fetchAll();
    
    sendSuccess($backups, 'تم جلب النسخ الاحتياطية');
}

if ($resource === 'backup' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'settings.update');
    
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = __DIR__ . '/../backups/' . $filename;
    
    if (!is_dir(dirname($filepath))) {
        mkdir(dirname($filepath), 0755, true);
    }
    
    $command = sprintf(
        'mysqldump -u%s -p%s %s > %s 2>&1',
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_NAME),
        escapeshellarg($filepath)
    );
    
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        sendError('فشل إنشاء النسخة الاحتياطية', 'BACKUP_FAILED', 500);
    }
    
    $stmt = $pdo->prepare("INSERT INTO backups (filename, file_size, type, status, created_by, created_at, completed_at) VALUES (?, ?, 'manual', 'completed', ?, NOW(), NOW())");
    $stmt->execute([$filename, filesize($filepath), $user['id']]);
    
    logActivity($user['id'], 'BACKUP_CREATED', 'إنشاء نسخة احتياطية', 'backup', $pdo->lastInsertId());
    
    sendSuccess(['filename' => $filename, 'file_size' => filesize($filepath)], 'تم إنشاء النسخة الاحتياطية بنجاح', 201);
}

// ================================================================
// 19. RETURNS - المرتجعات
// ================================================================

if ($resource === 'returns' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT r.*, p.name as product_name, w.name as warehouse_name, u.full_name as user_name
        FROM returns r
        LEFT JOIN products p ON r.product_id = p.id
        LEFT JOIN warehouses w ON r.warehouse_id = w.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.deleted_at IS NULL
        ORDER BY r.created_at DESC
    ");
    $returns = $stmt->fetchAll();
    
    sendSuccess($returns, 'تم جلب المرتجعات');
}

if ($resource === 'returns' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'returns.create');
    
    $input = getInput();
    validateRequired($input, ['product_id', 'warehouse_id', 'quantity', 'return_type']);
    
    $pdo = getDB();
    $pdo->beginTransaction();
    
    try {
        $returnNumber = generateReturnNumber();
        $quantity = (float)$input['quantity'];
        
        $stmt = $pdo->prepare("
            INSERT INTO returns (return_number, return_type, product_id, warehouse_id, quantity, reason, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $returnNumber,
            $input['return_type'],
            $input['product_id'],
            $input['warehouse_id'],
            $quantity,
            $input['reason'] ?? '',
            $user['id']
        ]);
        
        $returnId = $pdo->lastInsertId();
        
        if ($input['return_type'] === 'IN') {
            $stmt = $pdo->prepare("
                INSERT INTO stock_balances (product_id, warehouse_id, quantity, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()
            ");
            $stmt->execute([$input['product_id'], $input['warehouse_id'], $quantity]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE stock_balances 
                SET quantity = quantity - ?, updated_at = NOW()
                WHERE product_id = ? AND warehouse_id = ?
            ");
            $stmt->execute([$quantity, $input['product_id'], $input['warehouse_id']]);
        }
        
        $movementType = $input['return_type'] === 'IN' ? 'RETURN_IN' : 'RETURN_OUT';
        $movementNumber = generateMovementNumber($movementType);
        
        $stmt = $pdo->prepare("
            INSERT INTO stock_movements (movement_number, product_id, warehouse_id, type, quantity, reference_type, reference_id, notes, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, 'return', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $movementNumber,
            $input['product_id'],
            $input['warehouse_id'],
            $movementType,
            $quantity,
            $returnId,
            $input['reason'] ?? '',
            $user['id']
        ]);
        
        logActivity($user['id'], 'RETURN_CREATED', 'إنشاء مرتجع: ' . $returnNumber, 'return', $returnId, null, $input);
        
        $pdo->commit();
        
        sendSuccess(['id' => (int)$returnId, 'return_number' => $returnNumber], 'تم إنشاء المرتجع بنجاح', 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendError('خطأ في إنشاء المرتجع: ' . $e->getMessage(), 'RETURN_ERROR', 500);
    }
}

// ================================================================
// 20. RECEIPTS - إذونات الاستلام
// ================================================================

if ($resource === 'receipts' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT r.*, w.name as warehouse_name, u.full_name as user_name
        FROM receipts r
        LEFT JOIN warehouses w ON r.warehouse_id = w.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.deleted_at IS NULL
        ORDER BY r.created_at DESC
    ");
    $receipts = $stmt->fetchAll();
    
    sendSuccess($receipts, 'تم جلب إذونات الاستلام');
}

if ($resource === 'receipts' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'receipts.create');
    
    $input = getInput();
    validateRequired($input, ['warehouse_id']);
    
    $pdo = getDB();
    $pdo->beginTransaction();
    
    try {
        $receiptNumber = generateReceiptNumber();
        
        $stmt = $pdo->prepare("
            INSERT INTO receipts (receipt_number, warehouse_id, supplier_name, notes, status, user_id, created_at)
            VALUES (?, ?, ?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([
            $receiptNumber,
            $input['warehouse_id'],
            $input['supplier_name'] ?? '',
            $input['notes'] ?? '',
            $user['id']
        ]);
        
        $receiptId = $pdo->lastInsertId();
        
        if (isset($input['items']) && is_array($input['items'])) {
            $stmt = $pdo->prepare("INSERT INTO receipt_items (receipt_id, product_id, quantity, notes) VALUES (?, ?, ?, ?)");
            foreach ($input['items'] as $item) {
                $stmt->execute([$receiptId, $item['product_id'], $item['quantity'], $item['notes'] ?? '']);
            }
        }
        
        logActivity($user['id'], 'RECEIPT_CREATED', 'إنشاء إذن استلام: ' . $receiptNumber, 'receipt', $receiptId, null, $input);
        
        $pdo->commit();
        
        sendSuccess(['id' => (int)$receiptId, 'receipt_number' => $receiptNumber], 'تم إنشاء إذن الاستلام بنجاح', 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendError('خطأ في إنشاء إذن الاستلام: ' . $e->getMessage(), 'RECEIPT_ERROR', 500);
    }
}

// اعتماد إذن استلام
if ($resource === 'receipts' && $action === 'approve' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'receipts.update');
    
    $pdo = getDB();
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM receipts WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $receipt = $stmt->fetch();
        
        if (!$receipt) {
            sendError('إذن الاستلام غير موجود أو تم اعتماده مسبقاً', 'NOT_FOUND', 404);
        }
        
        $stmt = $pdo->prepare("UPDATE receipts SET status = 'completed' WHERE id = ?");
        $stmt->execute([$id]);
        
        $stmt = $pdo->prepare("SELECT * FROM receipt_items WHERE receipt_id = ?");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();
        
        foreach ($items as $item) {
            $movementNumber = generateMovementNumber('RECEIPT');
            
            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (movement_number, product_id, warehouse_id, type, quantity, reference_type, reference_id, user_id, created_at)
                VALUES (?, ?, ?, 'RECEIPT', ?, 'receipt', ?, ?, NOW())
            ");
            $stmt->execute([
                $movementNumber,
                $item['product_id'],
                $receipt['warehouse_id'],
                $item['quantity'],
                $id,
                $user['id']
            ]);
            
            $stmt = $pdo->prepare("
                INSERT INTO stock_balances (product_id, warehouse_id, quantity, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()
            ");
            $stmt->execute([$item['product_id'], $receipt['warehouse_id'], $item['quantity']]);
        }
        
        logActivity($user['id'], 'RECEIPT_APPROVED', 'اعتماد إذن استلام: ' . $receipt['receipt_number'], 'receipt', $id);
        
        $pdo->commit();
        
        sendSuccess([], 'تم اعتماد إذن الاستلام بنجاح');
    } catch (Exception $e) {
        $pdo->rollBack();
        sendError('خطأ في اعتماد إذن الاستلام: ' . $e->getMessage(), 'RECEIPT_ERROR', 500);
    }
}

// ================================================================
// 21. ISSUES - إذونات الصرف
// ================================================================

if ($resource === 'issues' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT i.*, w.name as warehouse_name, u.full_name as user_name
        FROM issues i
        LEFT JOIN warehouses w ON i.warehouse_id = w.id
        LEFT JOIN users u ON i.user_id = u.id
        WHERE i.deleted_at IS NULL
        ORDER BY i.created_at DESC
    ");
    $issues = $stmt->fetchAll();
    
    sendSuccess($issues, 'تم جلب إذونات الصرف');
}

if ($resource === 'issues' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'issues.create');
    
    $input = getInput();
    validateRequired($input, ['warehouse_id']);
    
    $pdo = getDB();
    $pdo->beginTransaction();
    
    try {
        $issueNumber = generateIssueNumber();
        
        $stmt = $pdo->prepare("
            INSERT INTO issues (issue_number, warehouse_id, department_name, notes, status, user_id, created_at)
            VALUES (?, ?, ?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([
            $issueNumber,
            $input['warehouse_id'],
            $input['department_name'] ?? '',
            $input['notes'] ?? '',
            $user['id']
        ]);
        
        $issueId = $pdo->lastInsertId();
        
        if (isset($input['items']) && is_array($input['items'])) {
            $stmt = $pdo->prepare("INSERT INTO issue_items (issue_id, product_id, quantity, notes) VALUES (?, ?, ?, ?)");
            foreach ($input['items'] as $item) {
                $stmt->execute([$issueId, $item['product_id'], $item['quantity'], $item['notes'] ?? '']);
            }
        }
        
        logActivity($user['id'], 'ISSUE_CREATED', 'إنشاء إذن صرف: ' . $issueNumber, 'issue', $issueId, null, $input);
        
        $pdo->commit();
        
        sendSuccess(['id' => (int)$issueId, 'issue_number' => $issueNumber], 'تم إنشاء إذن الصرف بنجاح', 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendError('خطأ في إنشاء إذن الصرف: ' . $e->getMessage(), 'ISSUE_ERROR', 500);
    }
}

// اعتماد إذن صرف
if ($resource === 'issues' && $action === 'approve' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'issues.update');
    
    $pdo = getDB();
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM issues WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $issue = $stmt->fetch();
        
        if (!$issue) {
            sendError('إذن الصرف غير موجود أو تم اعتماده مسبقاً', 'NOT_FOUND', 404);
        }
        
        $stmt = $pdo->prepare("UPDATE issues SET status = 'completed' WHERE id = ?");
        $stmt->execute([$id]);
        
        $stmt = $pdo->prepare("SELECT * FROM issue_items WHERE issue_id = ?");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();
        
        foreach ($items as $item) {
            // التحقق من الرصيد
            $stmt = $pdo->prepare("SELECT quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ?");
            $stmt->execute([$item['product_id'], $issue['warehouse_id']]);
            $balance = $stmt->fetch();
            
            if (!$balance || $balance['quantity'] < $item['quantity']) {
                throw new Exception('الرصيد غير كافٍ للمنتج #' . $item['product_id']);
            }
            
            $movementNumber = generateMovementNumber('ISSUE');
            
            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (movement_number, product_id, warehouse_id, type, quantity, reference_type, reference_id, user_id, created_at)
                VALUES (?, ?, ?, 'ISSUE', ?, 'issue', ?, ?, NOW())
            ");
            $stmt->execute([
                $movementNumber,
                $item['product_id'],
                $issue['warehouse_id'],
                $item['quantity'],
                $id,
                $user['id']
            ]);
            
            $stmt = $pdo->prepare("
                UPDATE stock_balances 
                SET quantity = quantity - ?, updated_at = NOW()
                WHERE product_id = ? AND warehouse_id = ?
            ");
            $stmt->execute([$item['quantity'], $item['product_id'], $issue['warehouse_id']]);
        }
        
        logActivity($user['id'], 'ISSUE_APPROVED', 'اعتماد إذن صرف: ' . $issue['issue_number'], 'issue', $id);
        
        $pdo->commit();
        
        sendSuccess([], 'تم اعتماد إذن الصرف بنجاح');
    } catch (Exception $e) {
        $pdo->rollBack();
        sendError('خطأ في اعتماد إذن الصرف: ' . $e->getMessage(), 'ISSUE_ERROR', 500);
    }
}

// ================================================================
// 22. TRANSFERS - التحويلات
// ================================================================

if ($resource === 'transfers' && $requestMethod === 'GET') {
    $user = authenticate();
    
    $pdo = getDB();
    $stmt = $pdo->query("
        SELECT t.*, fw.name as from_warehouse_name, tw.name as to_warehouse_name, u.full_name as user_name
        FROM transfers t
        LEFT JOIN warehouses fw ON t.from_warehouse_id = fw.id
        LEFT JOIN warehouses tw ON t.to_warehouse_id = tw.id
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.deleted_at IS NULL
        ORDER BY t.created_at DESC
    ");
    $transfers = $stmt->fetchAll();
    
    sendSuccess($transfers, 'تم جلب التحويلات');
}

if ($resource === 'transfers' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'transfers.create');
    
    $input = getInput();
    validateRequired($input, ['from_warehouse_id', 'to_warehouse_id']);
    
    $pdo = getDB();
    $pdo->beginTransaction();
    
    try {
        $transferNumber = generateTransferNumber();
        
        $stmt = $pdo->prepare("
            INSERT INTO transfers (transfer_number, from_warehouse_id, to_warehouse_id, notes, status, user_id, created_at)
            VALUES (?, ?, ?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([
            $transferNumber,
            $input['from_warehouse_id'],
            $input['to_warehouse_id'],
            $input['notes'] ?? '',
            $user['id']
        ]);
        
        $transferId = $pdo->lastInsertId();
        
        if (isset($input['items']) && is_array($input['items'])) {
            $stmt = $pdo->prepare("INSERT INTO transfer_items (transfer_id, product_id, quantity, notes) VALUES (?, ?, ?, ?)");
            foreach ($input['items'] as $item) {
                $stmt->execute([$transferId, $item['product_id'], $item['quantity'], $item['notes'] ?? '']);
            }
        }
        
        logActivity($user['id'], 'TRANSFER_CREATED', 'إنشاء تحويل: ' . $transferNumber, 'transfer', $transferId, null, $input);
        
        $pdo->commit();
        
        sendSuccess(['id' => (int)$transferId, 'transfer_number' => $transferNumber], 'تم إنشاء التحويل بنجاح', 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        sendError('خطأ في إنشاء التحويل: ' . $e->getMessage(), 'TRANSFER_ERROR', 500);
    }
}

// اعتماد تحويل
if ($resource === 'transfers' && $action === 'approve' && $requestMethod === 'POST') {
    $user = authenticate();
    requirePermission($user, 'transfers.update');
    
    $pdo = getDB();
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $transfer = $stmt->fetch();
        
        if (!$transfer) {
            sendError('التحويل غير موجود أو تم اعتماده مسبقاً', 'NOT_FOUND', 404);
        }
        
        $stmt = $pdo->prepare("UPDATE transfers SET status = 'completed' WHERE id = ?");
        $stmt->execute([$id]);
        
        $stmt = $pdo->prepare("SELECT * FROM transfer_items WHERE transfer_id = ?");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();
        
        foreach ($items as $item) {
            // التحقق من الرصيد في المخزن المصدر
            $stmt = $pdo->prepare("SELECT quantity FROM stock_balances WHERE product_id = ? AND warehouse_id = ?");
            $stmt->execute([$item['product_id'], $transfer['from_warehouse_id']]);
            $balance = $stmt->fetch();
            
            if (!$balance || $balance['quantity'] < $item['quantity']) {
                throw new Exception('الرصيد غير كافٍ في المخزن المصدر للمنتج #' . $item['product_id']);
            }
            
            // حركة خروج من المخزن المصدر
            $movementNumberOut = generateMovementNumber('TRANSFER_OUT');
            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (movement_number, product_id, warehouse_id, type, quantity, reference_type, reference_id, user_id, created_at)
                VALUES (?, ?, ?, 'TRANSFER_OUT', ?, 'transfer', ?, ?, NOW())
            ");
            $stmt->execute([
                $movementNumberOut,
                $item['product_id'],
                $transfer['from_warehouse_id'],
                $item['quantity'],
                $id,
                $user['id']
            ]);
            
            $stmt = $pdo->prepare("
                UPDATE stock_balances 
                SET quantity = quantity - ?, updated_at = NOW()
                WHERE product_id = ? AND warehouse_id = ?
            ");
            $stmt->execute([$item['quantity'], $item['product_id'], $transfer['from_warehouse_id']]);
            
            // حركة دخول إلى المخزن الهدف
            $movementNumberIn = generateMovementNumber('TRANSFER_IN');
            $stmt = $pdo->prepare("
                INSERT INTO stock_movements (movement_number, product_id, warehouse_id, type, quantity, reference_type, reference_id, user_id, created_at)
                VALUES (?, ?, ?, 'TRANSFER_IN', ?, 'transfer', ?, ?, NOW())
            ");
            $stmt->execute([
                $movementNumberIn,
                $item['product_id'],
                $transfer['to_warehouse_id'],
                $item['quantity'],
                $id,
                $user['id']
            ]);
            
            $stmt = $pdo->prepare("
                INSERT INTO stock_balances (product_id, warehouse_id, quantity, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()
            ");
            $stmt->execute([$item['product_id'], $transfer['to_warehouse_id'], $item['quantity']]);
        }
        
        logActivity($user['id'], 'TRANSFER_APPROVED', 'اعتماد تحويل: ' . $transfer['transfer_number'], 'transfer', $id);
        
        $pdo->commit();
        
        sendSuccess([], 'تم اعتماد التحويل بنجاح');
    } catch (Exception $e) {
        $pdo->rollBack();
        sendError('خطأ في اعتماد التحويل: ' . $e->getMessage(), 'TRANSFER_ERROR', 500);
    }
}

// ================================================================
// 404 - مسار غير موجود
// ================================================================
sendError('المسار غير موجود: ' . $path, 'ROUTE_NOT_FOUND', 404);

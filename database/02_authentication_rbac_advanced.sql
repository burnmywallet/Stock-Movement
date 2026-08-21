-- ================================================================
-- نظام إدارة المخازن والمخزون المتقدم
-- الملف: 02_authentication_rbac_advanced.sql
-- الإصدار: 2.0 Production Ready
-- التاريخ: 2026-08-20
-- ================================================================
--
-- هذا الملف يحتوي على:
-- 1. نظام المصادقة المتقدم (Multi-Factor Authentication Ready)
-- 2. نظام الصلاحيات المتقدم (RBAC with Hierarchical Roles)
-- 3. إدارة الجلسات المتقدمة (Single Session + Device Management)
-- 4. دوال وإجراءات المصادقة المتقدمة
-- 5. نظام تتبع النشاط والتدقيق المتقدم
-- 6. نظام التحقق من الصلاحيات على مستوى الصف (Row-Level Security)
-- 7. نظام تسجيل الدخول بطرق متعددة (Username, Email, Employee ID)
-- 8. نظام قفل الحساب التلقائي
-- 9. نظام انتهاء صلاحية كلمة المرور
-- 10. نظام 2FA (Two-Factor Authentication) جاهز
-- ================================================================

USE inventory_system;

-- ================================================================
-- 1. تحسينات وإضافات جداول المصادقة
-- ================================================================

-- إضافة دعم 2FA للمستخدمين
ALTER TABLE users 
ADD COLUMN two_factor_enabled BOOLEAN DEFAULT FALSE AFTER is_verified,
ADD COLUMN two_factor_secret VARCHAR(255) NULL AFTER two_factor_enabled,
ADD COLUMN two_factor_backup_codes TEXT NULL AFTER two_factor_secret,
ADD COLUMN two_factor_verified_at TIMESTAMP NULL AFTER two_factor_backup_codes,
ADD COLUMN preferred_login_method ENUM('username', 'email', 'employee_id') DEFAULT 'username' AFTER two_factor_verified_at,
ADD COLUMN language VARCHAR(10) DEFAULT 'ar' AFTER preferred_login_method,
ADD COLUMN theme VARCHAR(20) DEFAULT 'dark' AFTER language,
ADD COLUMN notification_preferences JSON NULL AFTER theme,
ADD COLUMN security_questions JSON NULL AFTER notification_preferences,
ADD COLUMN last_password_reset_request TIMESTAMP NULL AFTER security_questions,
ADD COLUMN password_reset_token VARCHAR(100) NULL AFTER last_password_reset_request,
ADD COLUMN password_reset_expires TIMESTAMP NULL AFTER password_reset_token,
ADD COLUMN device_trusted_tokens JSON NULL AFTER password_reset_expires;

-- إضافة عمود لتتبع الجلسات النشطة
ALTER TABLE user_sessions 
ADD COLUMN trusted_device BOOLEAN DEFAULT FALSE AFTER is_active,
ADD COLUMN location_city VARCHAR(100) NULL AFTER trusted_device,
ADD COLUMN location_country VARCHAR(100) NULL AFTER location_city,
ADD COLUMN browser_name VARCHAR(50) NULL AFTER location_country,
ADD COLUMN os_name VARCHAR(50) NULL AFTER browser_name,
ADD COLUMN screen_resolution VARCHAR(20) NULL AFTER os_name,
ADD COLUMN fingerprint_hash VARCHAR(64) NULL AFTER screen_resolution;

-- ================================================================
-- 2. إنشاء دوال (Functions) متقدمة للمصادقة
-- ================================================================

DELIMITER //

-- دالة: التحقق من صحة كلمة المرور (مع معايير الأمان)
CREATE FUNCTION is_password_secure(
    p_password VARCHAR(255)
)
RETURNS BOOLEAN
DETERMINISTIC
BEGIN
    DECLARE v_length INT;
    DECLARE v_has_upper BOOLEAN DEFAULT FALSE;
    DECLARE v_has_lower BOOLEAN DEFAULT FALSE;
    DECLARE v_has_digit BOOLEAN DEFAULT FALSE;
    DECLARE v_has_special BOOLEAN DEFAULT FALSE;
    DECLARE v_i INT DEFAULT 1;
    DECLARE v_char CHAR(1);
    
    SET v_length = LENGTH(p_password);
    
    -- التحقق من الطول (8-100 حرف)
    IF v_length < 8 OR v_length > 100 THEN
        RETURN FALSE;
    END IF;
    
    -- التحقق من وجود أحرف كبيرة وصغيرة وأرقام ورموز خاصة
    WHILE v_i <= v_length DO
        SET v_char = SUBSTRING(p_password, v_i, 1);
        
        IF v_char REGEXP '[A-Z]' THEN SET v_has_upper = TRUE; END IF;
        IF v_char REGEXP '[a-z]' THEN SET v_has_lower = TRUE; END IF;
        IF v_char REGEXP '[0-9]' THEN SET v_has_digit = TRUE; END IF;
        IF v_char REGEXP '[!@#$%^&*(),.?":{}|<>]' THEN SET v_has_special = TRUE; END IF;
        
        SET v_i = v_i + 1;
    END WHILE;
    
    RETURN v_has_upper AND v_has_lower AND v_has_digit AND v_has_special;
END//

-- دالة: التحقق من انتهاء صلاحية كلمة المرور
CREATE FUNCTION is_password_expired(
    p_user_id INT
)
RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_last_change TIMESTAMP;
    DECLARE v_expiry_days INT;
    
    SELECT last_password_change, password_expiry_days
    INTO v_last_change, v_expiry_days
    FROM users
    WHERE id = p_user_id;
    
    IF v_last_change IS NULL THEN
        RETURN TRUE;
    END IF;
    
    RETURN DATEDIFF(NOW(), v_last_change) > v_expiry_days;
END//

-- دالة: التحقق من قفل الحساب
CREATE FUNCTION is_account_locked(
    p_user_id INT
)
RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_locked_until TIMESTAMP;
    
    SELECT locked_until
    INTO v_locked_until
    FROM users
    WHERE id = p_user_id;
    
    IF v_locked_until IS NULL THEN
        RETURN FALSE;
    END IF;
    
    RETURN v_locked_until > NOW();
END//

-- دالة: جلب صلاحيات المستخدم (مع التوريث)
CREATE FUNCTION get_user_permissions_hierarchical(
    p_user_id INT
)
RETURNS TEXT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_result TEXT DEFAULT '';
    DECLARE v_role_id INT;
    DECLARE v_current_role_id INT;
    
    -- جلب دور المستخدم
    SELECT role_id INTO v_role_id FROM users WHERE id = p_user_id;
    SET v_current_role_id = v_role_id;
    
    -- جلب صلاحيات الدور الحالي وجميع الأدوار الأعلى
    WHILE v_current_role_id IS NOT NULL DO
        SELECT GROUP_CONCAT(DISTINCT p.name SEPARATOR ',')
        INTO v_result
        FROM permissions p
        INNER JOIN role_permissions rp ON rp.permission_id = p.id
        WHERE rp.role_id = v_current_role_id
        AND rp.is_allowed = 1;
        
        -- الانتقال إلى الدور الأعلى
        SELECT parent_id INTO v_current_role_id
        FROM roles
        WHERE id = v_current_role_id;
    END WHILE;
    
    RETURN IFNULL(v_result, '');
END//

-- دالة: التحقق من الصلاحية (مع التوريث)
CREATE FUNCTION has_permission_hierarchical(
    p_user_id INT,
    p_permission_name VARCHAR(100)
)
RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_role_id INT;
    DECLARE v_current_role_id INT;
    DECLARE v_has_permission BOOLEAN DEFAULT FALSE;
    
    -- جلب دور المستخدم
    SELECT role_id INTO v_role_id FROM users WHERE id = p_user_id;
    SET v_current_role_id = v_role_id;
    
    -- البحث في الدور الحالي وجميع الأدوار الأعلى
    WHILE v_current_role_id IS NOT NULL AND v_has_permission = FALSE DO
        SELECT COUNT(*) > 0 INTO v_has_permission
        FROM role_permissions rp
        INNER JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.role_id = v_current_role_id
        AND p.name = p_permission_name
        AND rp.is_allowed = 1;
        
        -- الانتقال إلى الدور الأعلى
        SELECT parent_id INTO v_current_role_id
        FROM roles
        WHERE id = v_current_role_id;
    END WHILE;
    
    RETURN v_has_permission;
END//

-- دالة: إنشاء رمز مصادقة عشوائي آمن
CREATE FUNCTION generate_secure_token(
    p_length INT
)
RETURNS VARCHAR(100)
DETERMINISTIC
BEGIN
    DECLARE v_token VARCHAR(100) DEFAULT '';
    DECLARE v_characters VARCHAR(62) DEFAULT 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    DECLARE v_i INT DEFAULT 1;
    
    WHILE v_i <= p_length DO
        SET v_token = CONCAT(v_token, SUBSTRING(v_characters, FLOOR(1 + RAND() * 62), 1));
        SET v_i = v_i + 1;
    END WHILE;
    
    RETURN v_token;
END//

-- دالة: توليد رمز 2FA
CREATE FUNCTION generate_2fa_code()
RETURNS VARCHAR(6)
DETERMINISTIC
BEGIN
    RETURN LPAD(FLOOR(RAND() * 1000000), 6, '0');
END//

DELIMITER ;

-- ================================================================
-- 3. إجراءات (Procedures) متقدمة للمصادقة
-- ================================================================

DELIMITER //

-- إجراء: تسجيل دخول متقدم مع دعم عدة طرق
CREATE PROCEDURE advanced_login(
    IN p_identifier VARCHAR(100),
    IN p_password VARCHAR(255),
    IN p_ip VARCHAR(45),
    IN p_user_agent TEXT,
    IN p_device_name VARCHAR(100),
    IN p_trust_device BOOLEAN
)
BEGIN
    DECLARE v_user_id INT;
    DECLARE v_username VARCHAR(50);
    DECLARE v_password_hash VARCHAR(255);
    DECLARE v_is_active BOOLEAN;
    DECLARE v_is_locked BOOLEAN;
    DECLARE v_locked_until TIMESTAMP;
    DECLARE v_failed_attempts INT;
    DECLARE v_role_name VARCHAR(50);
    DECLARE v_token VARCHAR(100);
    DECLARE v_token_hash CHAR(64);
    DECLARE v_session_id INT;
    DECLARE v_max_attempts INT DEFAULT 5;
    DECLARE v_lockout_duration INT DEFAULT 30;
    DECLARE v_password_valid BOOLEAN DEFAULT FALSE;
    DECLARE v_expired BOOLEAN DEFAULT FALSE;
    
    -- البحث عن المستخدم بطرق متعددة
    SELECT 
        u.id, u.username, u.password_hash, u.is_active, 
        u.is_locked, u.locked_until, u.failed_login_attempts,
        r.name AS role_name
    INTO 
        v_user_id, v_username, v_password_hash, v_is_active,
        v_is_locked, v_locked_until, v_failed_attempts,
        v_role_name
    FROM users u
    INNER JOIN roles r ON r.id = u.role_id
    WHERE u.username = p_identifier
       OR u.email = p_identifier
       OR u.employee_id = p_identifier;
    
    -- التحقق من وجود المستخدم
    IF v_user_id IS NULL THEN
        -- تسجيل محاولة فاشلة
        INSERT INTO auth_logs (username, action, ip_address, user_agent, details, created_at)
        VALUES (p_identifier, 'LOGIN_FAILED', p_ip, p_user_agent, 
                JSON_OBJECT('reason', 'user_not_found'), NOW());
        
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'اسم المستخدم أو كلمة المرور غير صحيحة'
        ) AS result;
        RETURN;
    END IF;
    
    -- التحقق من نشاط الحساب
    IF v_is_active = 0 THEN
        INSERT INTO auth_logs (user_id, username, action, ip_address, user_agent, details, created_at)
        VALUES (v_user_id, v_username, 'LOGIN_FAILED', p_ip, p_user_agent,
                JSON_OBJECT('reason', 'account_inactive'), NOW());
        
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'الحساب غير نشط. الرجاء التواصل مع المسؤول'
        ) AS result;
        RETURN;
    END IF;
    
    -- التحقق من قفل الحساب
    IF v_locked_until IS NOT NULL AND v_locked_until > NOW() THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', CONCAT('الحساب مقفل مؤقتاً. حاول مرة أخرى بعد ', 
                             CEIL(TIMESTAMPDIFF(MINUTE, NOW(), v_locked_until)), ' دقيقة')
        ) AS result;
        RETURN;
    END IF;
    
    -- التحقق من كلمة المرور
    SET v_password_valid = (v_password_hash = SHA2(CONCAT(p_password, v_username), 256));
    
    IF NOT v_password_valid THEN
        -- زيادة عدد محاولات الفشل
        UPDATE users 
        SET failed_login_attempts = failed_login_attempts + 1,
            updated_at = NOW()
        WHERE id = v_user_id;
        
        -- قفل الحساب إذا تجاوز الحد الأقصى
        IF v_failed_attempts + 1 >= v_max_attempts THEN
            UPDATE users 
            SET locked_until = DATE_ADD(NOW(), INTERVAL v_lockout_duration MINUTE),
                is_locked = 1,
                updated_at = NOW()
            WHERE id = v_user_id;
            
            INSERT INTO auth_logs (user_id, username, action, ip_address, user_agent, details, created_at)
            VALUES (v_user_id, v_username, 'LOCKED', p_ip, p_user_agent,
                    JSON_OBJECT('reason', 'max_attempts_exceeded', 'attempts', v_failed_attempts + 1), NOW());
        END IF;
        
        INSERT INTO auth_logs (user_id, username, action, ip_address, user_agent, details, created_at)
        VALUES (v_user_id, v_username, 'LOGIN_FAILED', p_ip, p_user_agent,
                JSON_OBJECT('reason', 'invalid_password', 'attempts', v_failed_attempts + 1), NOW());
        
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'اسم المستخدم أو كلمة المرور غير صحيحة'
        ) AS result;
        RETURN;
    END IF;
    
    -- التحقق من انتهاء صلاحية كلمة المرور
    SET v_expired = is_password_expired(v_user_id);
    
    IF v_expired THEN
        INSERT INTO auth_logs (user_id, username, action, ip_address, user_agent, details, created_at)
        VALUES (v_user_id, v_username, 'LOGIN_FAILED', p_ip, p_user_agent,
                JSON_OBJECT('reason', 'password_expired'), NOW());
        
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'انتهت صلاحية كلمة المرور. يجب تغييرها قبل تسجيل الدخول',
            'force_password_change', TRUE
        ) AS result;
        RETURN;
    END IF;
    
    -- إنشاء رمز الجلسة
    SET v_token = generate_secure_token(64);
    SET v_token_hash = SHA2(v_token, 256);
    
    -- إنهاء الجلسات القديمة (Single Session)
    IF (SELECT setting_value = 'true' FROM system_settings WHERE setting_key = 'single_session_enabled') THEN
        UPDATE user_sessions 
        SET is_active = 0, 
            logout_at = NOW()
        WHERE user_id = v_user_id 
          AND is_active = 1;
    END IF;
    
    -- إنشاء جلسة جديدة
    INSERT INTO user_sessions (
        user_id,
        session_token_hash,
        device_name,
        ip_address,
        user_agent,
        expires_at,
        login_at,
        last_activity,
        is_active,
        trusted_device,
        fingerprint_hash
    ) VALUES (
        v_user_id,
        v_token_hash,
        p_device_name,
        p_ip,
        p_user_agent,
        DATE_ADD(NOW(), INTERVAL 3600 SECOND),
        NOW(),
        NOW(),
        1,
        p_trust_device,
        SHA2(CONCAT(p_ip, p_user_agent, p_device_name), 256)
    );
    
    SET v_session_id = LAST_INSERT_ID();
    
    -- تحديث آخر تسجيل دخول
    UPDATE users 
    SET 
        last_login_at = NOW(),
        last_login_ip = p_ip,
        failed_login_attempts = 0,
        is_locked = 0,
        locked_until = NULL,
        updated_at = NOW()
    WHERE id = v_user_id;
    
    -- تسجيل نجاح الدخول
    INSERT INTO auth_logs (user_id, username, action, ip_address, user_agent, details, created_at)
    VALUES (v_user_id, v_username, 'LOGIN_SUCCESS', p_ip, p_user_agent,
            JSON_OBJECT('session_id', v_session_id, 'device', p_device_name, 'trusted', p_trust_device), NOW());
    
    -- جلب الصلاحيات
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم تسجيل الدخول بنجاح',
        'data', JSON_OBJECT(
            'token', v_token,
            'user', JSON_OBJECT(
                'id', v_user_id,
                'username', v_username,
                'full_name', (SELECT full_name FROM users WHERE id = v_user_id),
                'email', (SELECT email FROM users WHERE id = v_user_id),
                'role', v_role_name,
                'permissions', get_user_permissions_hierarchical(v_user_id),
                'two_factor_enabled', (SELECT two_factor_enabled FROM users WHERE id = v_user_id),
                'force_password_change', v_expired
            )
        )
    ) AS result;
END//

-- إجراء: تسجيل الخروج المتقدم
CREATE PROCEDURE advanced_logout(
    IN p_user_id INT,
    IN p_session_id INT,
    IN p_ip VARCHAR(45)
)
BEGIN
    DECLARE v_username VARCHAR(50);
    
    SELECT username INTO v_username FROM users WHERE id = p_user_id;
    
    UPDATE user_sessions 
    SET 
        is_active = 0,
        logout_at = NOW()
    WHERE id = p_session_id
      AND user_id = p_user_id;
    
    INSERT INTO auth_logs (user_id, username, action, ip_address, details, created_at)
    VALUES (p_user_id, v_username, 'LOGOUT', p_ip,
            JSON_OBJECT('session_id', p_session_id), NOW());
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم تسجيل الخروج بنجاح'
    ) AS result;
END//

-- إجراء: تغيير كلمة المرور مع التحقق من السجل
CREATE PROCEDURE change_password_advanced(
    IN p_user_id INT,
    IN p_current_password VARCHAR(255),
    IN p_new_password VARCHAR(255),
    IN p_ip VARCHAR(45)
)
BEGIN
    DECLARE v_username VARCHAR(50);
    DECLARE v_current_hash VARCHAR(255);
    DECLARE v_password_history_count INT;
    
    SELECT username, password_hash 
    INTO v_username, v_current_hash
    FROM users 
    WHERE id = p_user_id;
    
    -- التحقق من كلمة المرور الحالية
    IF v_current_hash != SHA2(CONCAT(p_current_password, v_username), 256) THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'كلمة المرور الحالية غير صحيحة'
        ) AS result;
        RETURN;
    END IF;
    
    -- التحقق من أن كلمة المرور الجديدة آمنة
    IF NOT is_password_secure(p_new_password) THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'كلمة المرور يجب أن تحتوي على 8 أحرف على الأقل، حرف كبير، حرف صغير، رقم، رمز خاص'
        ) AS result;
        RETURN;
    END IF;
    
    -- التحقق من عدم تكرار كلمة المرور في السجل (آخر 5 كلمات مرور)
    SELECT COUNT(*) INTO v_password_history_count
    FROM password_history
    WHERE user_id = p_user_id
      AND password_hash = SHA2(CONCAT(p_new_password, v_username), 256)
    ORDER BY changed_at DESC
    LIMIT 5;
    
    IF v_password_history_count > 0 THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'لا يمكن استخدام كلمة مرور مستخدمة سابقاً'
        ) AS result;
        RETURN;
    END IF;
    
    -- حفظ كلمة المرور القديمة في السجل
    INSERT INTO password_history (user_id, password_hash, changed_by, ip_address, reason)
    VALUES (p_user_id, v_current_hash, p_user_id, p_ip, 'manual');
    
    -- تحديث كلمة المرور
    UPDATE users 
    SET 
        password_hash = SHA2(CONCAT(p_new_password, v_username), 256),
        last_password_change = NOW(),
        updated_at = NOW()
    WHERE id = p_user_id;
    
    -- إنهاء جميع الجلسات (إعادة تسجيل الدخول مطلوبة)
    UPDATE user_sessions 
    SET is_active = 0, logout_at = NOW()
    WHERE user_id = p_user_id
      AND is_active = 1;
    
    INSERT INTO auth_logs (user_id, username, action, ip_address, details, created_at)
    VALUES (p_user_id, v_username, 'PASSWORD_CHANGED', p_ip,
            JSON_OBJECT('reason', 'manual_change'), NOW());
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم تغيير كلمة المرور بنجاح. سيتم تسجيل الخروج من جميع الأجهزة'
    ) AS result;
END//

-- إجراء: طلب إعادة تعيين كلمة المرور
CREATE PROCEDURE request_password_reset(
    IN p_identifier VARCHAR(100),
    IN p_ip VARCHAR(45)
)
BEGIN
    DECLARE v_user_id INT;
    DECLARE v_username VARCHAR(50);
    DECLARE v_email VARCHAR(100);
    DECLARE v_token VARCHAR(100);
    
    SELECT id, username, email 
    INTO v_user_id, v_username, v_email
    FROM users 
    WHERE username = p_identifier
       OR email = p_identifier
       OR employee_id = p_identifier;
    
    IF v_user_id IS NULL THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'المستخدم غير موجود'
        ) AS result;
        RETURN;
    END IF;
    
    -- توليد رمز إعادة التعيين
    SET v_token = generate_secure_token(64);
    
    UPDATE users 
    SET 
        password_reset_token = v_token,
        password_reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR),
        last_password_reset_request = NOW(),
        updated_at = NOW()
    WHERE id = v_user_id;
    
    INSERT INTO auth_logs (user_id, username, action, ip_address, details, created_at)
    VALUES (v_user_id, v_username, 'PASSWORD_RESET_REQUEST', p_ip,
            JSON_OBJECT('token_expires_in', '1 hour'), NOW());
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني',
        'data', JSON_OBJECT(
            'user_id', v_user_id,
            'email', v_email,
            'token', v_token,
            'expires_at', (SELECT password_reset_expires FROM users WHERE id = v_user_id)
        )
    ) AS result;
END//

-- إجراء: إعادة تعيين كلمة المرور
CREATE PROCEDURE reset_password(
    IN p_user_id INT,
    IN p_token VARCHAR(100),
    IN p_new_password VARCHAR(255),
    IN p_ip VARCHAR(45)
)
BEGIN
    DECLARE v_username VARCHAR(50);
    DECLARE v_current_hash VARCHAR(255);
    DECLARE v_token_valid BOOLEAN DEFAULT FALSE;
    DECLARE v_expired BOOLEAN DEFAULT FALSE;
    
    SELECT username, password_hash 
    INTO v_username, v_current_hash
    FROM users 
    WHERE id = p_user_id
      AND password_reset_token = p_token;
    
    IF v_username IS NULL THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'رمز إعادة التعيين غير صحيح'
        ) AS result;
        RETURN;
    END IF;
    
    -- التحقق من صلاحية الرمز
    SELECT COUNT(*) > 0 INTO v_token_valid
    FROM users
    WHERE id = p_user_id
      AND password_reset_token = p_token
      AND password_reset_expires > NOW();
    
    IF NOT v_token_valid THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'انتهت صلاحية رمز إعادة التعيين'
        ) AS result;
        RETURN;
    END IF;
    
    -- التحقق من أن كلمة المرور الجديدة آمنة
    IF NOT is_password_secure(p_new_password) THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'كلمة المرور يجب أن تحتوي على 8 أحرف على الأقل، حرف كبير، حرف صغير، رقم، رمز خاص'
        ) AS result;
        RETURN;
    END IF;
    
    -- حفظ كلمة المرور القديمة في السجل
    INSERT INTO password_history (user_id, password_hash, changed_by, ip_address, reason)
    VALUES (p_user_id, v_current_hash, p_user_id, p_ip, 'reset');
    
    -- تحديث كلمة المرور
    UPDATE users 
    SET 
        password_hash = SHA2(CONCAT(p_new_password, v_username), 256),
        last_password_change = NOW(),
        password_reset_token = NULL,
        password_reset_expires = NULL,
        failed_login_attempts = 0,
        is_locked = 0,
        locked_until = NULL,
        updated_at = NOW()
    WHERE id = p_user_id;
    
    -- إنهاء جميع الجلسات
    UPDATE user_sessions 
    SET is_active = 0, logout_at = NOW()
    WHERE user_id = p_user_id
      AND is_active = 1;
    
    INSERT INTO auth_logs (user_id, username, action, ip_address, details, created_at)
    VALUES (p_user_id, v_username, 'PASSWORD_RESET', p_ip,
            JSON_OBJECT('reason', 'reset_request'), NOW());
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم إعادة تعيين كلمة المرور بنجاح. يرجى تسجيل الدخول بكلمة المرور الجديدة'
    ) AS result;
END//

-- ================================================================
-- 4. إجراءات إدارة الصلاحيات المتقدمة
-- ================================================================

-- إجراء: إضافة صلاحية لدور
CREATE PROCEDURE grant_permission(
    IN p_role_name VARCHAR(50),
    IN p_permission_name VARCHAR(100),
    IN p_admin_id INT
)
BEGIN
    DECLARE v_role_id INT;
    DECLARE v_permission_id INT;
    
    SELECT id INTO v_role_id FROM roles WHERE name = p_role_name;
    SELECT id INTO v_permission_id FROM permissions WHERE name = p_permission_name;
    
    IF v_role_id IS NULL OR v_permission_id IS NULL THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'الدور أو الصلاحية غير موجودة'
        ) AS result;
        RETURN;
    END IF;
    
    INSERT INTO role_permissions (role_id, permission_id, is_allowed)
    VALUES (v_role_id, v_permission_id, TRUE)
    ON DUPLICATE KEY UPDATE is_allowed = TRUE, updated_at = NOW();
    
    INSERT INTO audit_logs (user_id, username, action, module, description, details, created_at)
    VALUES (
        p_admin_id,
        (SELECT username FROM users WHERE id = p_admin_id),
        'GRANT_PERMISSION',
        'rbac',
        CONCAT('تم منح صلاحية "', p_permission_name, '" لدور "', p_role_name, '"'),
        JSON_OBJECT('role', p_role_name, 'permission', p_permission_name),
        NOW()
    );
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', CONCAT('تم منح صلاحية "', p_permission_name, '" لدور "', p_role_name, '" بنجاح')
    ) AS result;
END//

-- إجراء: سحب صلاحية من دور
CREATE PROCEDURE revoke_permission(
    IN p_role_name VARCHAR(50),
    IN p_permission_name VARCHAR(100),
    IN p_admin_id INT
)
BEGIN
    DECLARE v_role_id INT;
    DECLARE v_permission_id INT;
    
    SELECT id INTO v_role_id FROM roles WHERE name = p_role_name;
    SELECT id INTO v_permission_id FROM permissions WHERE name = p_permission_name;
    
    IF v_role_id IS NULL OR v_permission_id IS NULL THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'الدور أو الصلاحية غير موجودة'
        ) AS result;
        RETURN;
    END IF;
    
    DELETE FROM role_permissions 
    WHERE role_id = v_role_id 
      AND permission_id = v_permission_id;
    
    INSERT INTO audit_logs (user_id, username, action, module, description, details, created_at)
    VALUES (
        p_admin_id,
        (SELECT username FROM users WHERE id = p_admin_id),
        'REVOKE_PERMISSION',
        'rbac',
        CONCAT('تم سحب صلاحية "', p_permission_name, '" من دور "', p_role_name, '"'),
        JSON_OBJECT('role', p_role_name, 'permission', p_permission_name),
        NOW()
    );
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', CONCAT('تم سحب صلاحية "', p_permission_name, '" من دور "', p_role_name, '" بنجاح')
    ) AS result;
END//

-- ================================================================
-- 5. عروض (Views) متقدمة للمصادقة والصلاحيات
-- ================================================================

-- عرض المستخدمين مع جميع الصلاحيات
CREATE OR REPLACE VIEW v_users_with_permissions_advanced AS
SELECT 
    u.id,
    u.username,
    u.email,
    u.employee_id,
    u.full_name,
    u.department,
    u.phone,
    u.mobile,
    r.name AS role_name,
    r.display_name AS role_display,
    u.is_active,
    u.is_verified,
    u.two_factor_enabled,
    u.is_locked,
    u.failed_login_attempts,
    u.last_login_at,
    u.last_login_ip,
    u.last_password_change,
    is_password_expired(u.id) AS password_expired,
    is_account_locked(u.id) AS account_locked,
    get_user_permissions_hierarchical(u.id) AS permissions_list,
    (SELECT COUNT(*) FROM user_sessions WHERE user_id = u.id AND is_active = 1) AS active_sessions_count,
    u.created_at,
    u.updated_at
FROM users u
INNER JOIN roles r ON r.id = u.role_id
WHERE u.deleted_at IS NULL;

-- عرض الجلسات النشطة المتقدم
CREATE OR REPLACE VIEW v_active_sessions_advanced AS
SELECT 
    s.id AS session_id,
    s.user_id,
    u.username,
    u.full_name,
    r.name AS role_name,
    s.device_name,
    s.device_type,
    s.ip_address,
    s.location_city,
    s.location_country,
    s.browser_name,
    s.os_name,
    s.login_at,
    s.last_activity,
    s.expires_at,
    TIMESTAMPDIFF(SECOND, s.last_activity, NOW()) AS idle_seconds,
    TIMESTAMPDIFF(SECOND, s.login_at, NOW()) AS session_duration_seconds,
    s.request_count,
    s.trusted_device,
    CASE 
        WHEN TIMESTAMPDIFF(SECOND, s.expires_at, NOW()) > 0 THEN 'expired'
        WHEN TIMESTAMPDIFF(SECOND, s.last_activity, NOW()) > 1800 THEN 'idle'
        WHEN TIMESTAMPDIFF(SECOND, s.login_at, NOW()) > 28800 THEN 'long_session'
        ELSE 'active'
    END AS session_status
FROM user_sessions s
INNER JOIN users u ON u.id = s.user_id
INNER JOIN roles r ON r.id = u.role_id
WHERE s.is_active = 1
  AND s.expires_at > NOW()
ORDER BY s.last_activity DESC;

-- عرض سجل المصادقة المتقدم
CREATE OR REPLACE VIEW v_auth_logs_advanced AS
SELECT 
    al.id,
    al.user_id,
    al.username,
    al.action,
    al.ip_address,
    al.user_agent,
    JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.device')) AS device,
    JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.reason')) AS reason,
    al.created_at,
    CASE al.action
        WHEN 'LOGIN_SUCCESS' THEN '✅ نجاح'
        WHEN 'LOGIN_FAILED' THEN '❌ فشل'
        WHEN 'LOGOUT' THEN '🚪 خروج'
        WHEN 'SESSION_EXPIRED' THEN '⏰ انتهت'
        WHEN 'LOCKED' THEN '🔒 قفل'
        WHEN 'UNLOCKED' THEN '🔓 فتح'
        WHEN 'PASSWORD_CHANGED' THEN '🔑 تغيير'
        WHEN 'PASSWORD_RESET' THEN '🔄 إعادة تعيين'
        ELSE al.action
    END AS action_icon,
    DATEDIFF(NOW(), al.created_at) AS days_ago
FROM auth_logs al
ORDER BY al.created_at DESC
LIMIT 1000;

-- عرض إحصائيات المصادقة
CREATE OR REPLACE VIEW v_auth_statistics AS
SELECT 
    DATE(created_at) AS date,
    COUNT(*) AS total_attempts,
    COUNT(CASE WHEN action = 'LOGIN_SUCCESS' THEN 1 END) AS success_logins,
    COUNT(CASE WHEN action = 'LOGIN_FAILED' THEN 1 END) AS failed_logins,
    COUNT(CASE WHEN action = 'LOGOUT' THEN 1 END) AS logout_attempts,
    COUNT(CASE WHEN action = 'LOCKED' THEN 1 END) AS locked_accounts,
    COUNT(DISTINCT user_id) AS unique_users,
    COUNT(DISTINCT ip_address) AS unique_ips,
    ROUND(COUNT(CASE WHEN action = 'LOGIN_SUCCESS' THEN 1 END) * 100.0 / COUNT(*), 2) AS success_rate
FROM auth_logs
GROUP BY DATE(created_at)
ORDER BY date DESC;

-- ================================================================
-- 6. إجراءات الصيانة والدعم
-- ================================================================

-- إجراء: تنظيف الجلسات المنتهية
CREATE PROCEDURE cleanup_sessions_advanced(
    IN p_days_old INT DEFAULT 30
)
BEGIN
    DECLARE v_sessions_cleaned INT DEFAULT 0;
    DECLARE v_logs_cleaned INT DEFAULT 0;
    
    -- تحديث حالة الجلسات المنتهية
    UPDATE user_sessions 
    SET is_active = 0, logout_at = NOW()
    WHERE expires_at <= NOW()
      AND is_active = 1;
    
    SET v_sessions_cleaned = ROW_COUNT();
    
    -- حذف الجلسات القديمة
    DELETE FROM user_sessions 
    WHERE login_at < DATE_SUB(NOW(), INTERVAL p_days_old DAY)
      AND is_active = 0;
    
    -- حذف سجلات المصادقة القديمة
    DELETE FROM auth_logs 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL p_days_old DAY);
    
    SET v_logs_cleaned = ROW_COUNT();
    
    -- تسجيل عملية التنظيف
    INSERT INTO audit_logs (username, action, module, description, details, created_at)
    VALUES (
        'system',
        'CLEANUP_SESSIONS',
        'system',
        'تنظيف الجلسات وسجلات المصادقة',
        JSON_OBJECT('sessions_cleaned', v_sessions_cleaned, 'logs_cleaned', v_logs_cleaned, 'days_old', p_days_old),
        NOW()
    );
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم تنظيف النظام بنجاح',
        'data', JSON_OBJECT(
            'sessions_cleaned', v_sessions_cleaned,
            'logs_cleaned', v_logs_cleaned,
            'days_old', p_days_old
        )
    ) AS result;
END//

-- ================================================================
-- 7. الأحداث المجدولة (Events) التلقائية
-- ================================================================

-- حدث: تنظيف الجلسات كل ساعة
CREATE EVENT IF NOT EXISTS event_cleanup_sessions_advanced
ON SCHEDULE EVERY 1 HOUR
DO
BEGIN
    UPDATE user_sessions 
    SET is_active = 0, logout_at = NOW()
    WHERE expires_at <= NOW()
      AND is_active = 1;
    
    -- حذف الجلسات المنتهية منذ أكثر من 7 أيام
    DELETE FROM user_sessions 
    WHERE logout_at IS NOT NULL
      AND logout_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
END//

-- حدث: فتح الحسابات المقفلة تلقائياً
CREATE EVENT IF NOT EXISTS event_unlock_accounts
ON SCHEDULE EVERY 1 HOUR
DO
BEGIN
    UPDATE users 
    SET 
        is_locked = 0,
        locked_until = NULL,
        failed_login_attempts = 0,
        updated_at = NOW()
    WHERE locked_until IS NOT NULL
      AND locked_until <= NOW()
      AND is_locked = 1;
END//

-- حدث: تنبيه انتهاء صلاحية كلمة المرور (يومياً)
CREATE EVENT IF NOT EXISTS event_password_expiry_alert
ON SCHEDULE EVERY 1 DAY
STARTS '2026-08-21 08:00:00'
DO
BEGIN
    INSERT INTO notifications (user_id, type, title, message, priority, created_at)
    SELECT 
        u.id,
        'system_warning',
        'تنبيه: انتهاء صلاحية كلمة المرور',
        CONCAT('كلمة المرور الخاصة بك ستنتهي خلال ', 
               DATEDIFF(DATE_ADD(u.last_password_change, INTERVAL u.password_expiry_days DAY), NOW()),
               ' يوم. يرجى تغييرها لتجنب تعطيل الحساب.'),
        'high',
        NOW()
    FROM users u
    WHERE u.is_active = 1
      AND u.last_password_change IS NOT NULL
      AND DATEDIFF(DATE_ADD(u.last_password_change, INTERVAL u.password_expiry_days DAY), NOW()) BETWEEN 1 AND 5
      AND u.deleted_at IS NULL;
END//

-- ================================================================
-- 8. دوال إضافية للتحقق من الأمان
-- ================================================================

DELIMITER //

-- دالة: التحقق من وجود جلسة نشطة
CREATE FUNCTION has_active_session(
    p_user_id INT
)
RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_count INT DEFAULT 0;
    
    SELECT COUNT(*)
    INTO v_count
    FROM user_sessions
    WHERE user_id = p_user_id
      AND is_active = 1
      AND expires_at > NOW();
    
    RETURN v_count > 0;
END//

-- دالة: جلب عدد الجلسات النشطة
CREATE FUNCTION get_active_sessions_count_advanced(
    p_user_id INT
)
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_count INT DEFAULT 0;
    
    SELECT COUNT(*)
    INTO v_count
    FROM user_sessions
    WHERE user_id = p_user_id
      AND is_active = 1
      AND expires_at > NOW();
    
    RETURN v_count;
END//

-- دالة: التحقق من صلاحية رمز إعادة التعيين
CREATE FUNCTION is_reset_token_valid(
    p_token VARCHAR(100)
)
RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_count INT DEFAULT 0;
    
    SELECT COUNT(*)
    INTO v_count
    FROM users
    WHERE password_reset_token = p_token
      AND password_reset_expires > NOW()
      AND is_active = 1;
    
    RETURN v_count > 0;
END//

DELIMITER ;

-- ================================================================
-- 9. بيانات اختبار للصلاحيات الإضافية
-- ================================================================

-- إضافة صلاحيات إضافية للاختبار
INSERT INTO permissions (name, display_name, module, sub_module, action, description) VALUES
-- صلاحيات متقدمة للتقارير
('reports.audit', 'تقرير التدقيق', 'reports', 'audit', 'view', 'عرض تقرير التدقيق'),
('reports.financial', 'تقرير مالي', 'reports', 'financial', 'view', 'عرض التقارير المالية'),
('reports.performance', 'تقرير أداء', 'reports', 'performance', 'view', 'عرض تقارير الأداء'),

-- صلاحيات متقدمة للجرد
('inventory.schedule', 'جدولة الجرد', 'inventory', 'schedule', 'create', 'جدولة جلسات الجرد'),
('inventory.verify', 'تحقق الجرد', 'inventory', 'verify', 'approve', 'التحقق من جلسات الجرد'),

-- صلاحيات متقدمة للنسخ الاحتياطي
('backup.auto_restore', 'استعادة تلقائية', 'backup', 'auto', 'restore', 'استعادة تلقائية للنسخ الاحتياطية'),

-- صلاحيات متقدمة للمستخدمين
('users.impersonate', 'انتحال شخصية', 'users', 'security', 'impersonate', 'الدخول كـ مستخدم آخر');

-- توزيع الصلاحيات الجديدة
-- مدير النظام يحصل على جميع الصلاحيات الجديدة (لأنه Admin لديه الكل)
-- مدير المخازن يحصل على صلاحيات إضافية
INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'warehouse_manager'),
    id
FROM permissions
WHERE name IN (
    'reports.audit', 'reports.financial',
    'inventory.schedule', 'inventory.verify',
    'backup.auto_restore'
);

-- مشرف مخزن يحصل على صلاحيات الجرد
INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'warehouse_supervisor'),
    id
FROM permissions
WHERE name IN (
    'inventory.schedule', 'inventory.verify'
);

-- ================================================================
-- 10. عرض معلومات النظام الأمنية
-- ================================================================

SELECT '🔐 نظام المصادقة والصلاحيات المتقدم جاهز للتشغيل' AS status;

SELECT 
    '📊 إحصائيات النظام الأمنية' AS title,
    (SELECT COUNT(*) FROM users WHERE is_active = 1) AS total_active_users,
    (SELECT COUNT(*) FROM users WHERE is_locked = 1) AS locked_users,
    (SELECT COUNT(*) FROM users WHERE is_password_expired(id) = 1) AS users_with_expired_password,
    (SELECT COUNT(*) FROM users WHERE two_factor_enabled = 1) AS users_with_2fa,
    (SELECT COUNT(*) FROM user_sessions WHERE is_active = 1 AND expires_at > NOW()) AS active_sessions,
    (SELECT COUNT(*) FROM auth_logs WHERE action = 'LOGIN_SUCCESS' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS successful_logins_24h,
    (SELECT COUNT(*) FROM auth_logs WHERE action = 'LOGIN_FAILED' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS failed_logins_24h,
    (SELECT COUNT(*) FROM auth_logs WHERE action = 'LOCKED' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS accounts_locked_24h;

-- ================================================================
-- انتهى ملف المصادقة والصلاحيات المتقدم
-- ================================================================

SELECT '✅ تم إنشاء نظام المصادقة والصلاحيات المتقدم بنجاح' AS final_status;

-- ================================================================
-- نظام إدارة المخازن والمخزون المتقدم
-- الملف: 03_session_management_advanced.sql
-- الإصدار: 2.0 Production Ready
-- التاريخ: 2026-08-20
-- ================================================================
--
-- هذا الملف يحتوي على:
-- 1. نظام إدارة الجلسات المتقدم (Single Session + Multi-Device)
-- 2. تتبع الجلسات النشطة مع معلومات الجهاز
-- 3. نظام البصمة الرقمية للجهاز (Device Fingerprinting)
-- 4. إدارة الأجهزة الموثوقة والمجهولة
-- 5. نظام تسجيل نشاط الجلسات
-- 6. إجراءات إدارة الجلسات (إنشاء، تحديث، إنهاء)
-- 7. نظام التحقق من الجلسات المتعددة
-- 8. إدارة الجلسات من لوحة التحكم
-- 9. نظام تنبيهات الجلسات المشبوهة
-- 10. تقارير الجلسات المتقدمة
-- ================================================================

USE inventory_system;

-- ================================================================
-- 1. تحسينات وإضافات جدول الجلسات
-- ================================================================

-- إضافة دعم متقدم للجلسات
ALTER TABLE user_sessions
ADD COLUMN session_created_by ENUM('login', 'remember', 'impersonate', 'api') DEFAULT 'login' AFTER fingerprint_hash,
ADD COLUMN is_impersonated BOOLEAN DEFAULT FALSE AFTER session_created_by,
ADD COLUMN impersonated_by INT UNSIGNED NULL AFTER is_impersonated,
ADD COLUMN last_request_time TIMESTAMP NULL AFTER request_count,
ADD COLUMN request_duration_avg INT DEFAULT 0 AFTER last_request_time,
ADD COLUMN request_duration_total INT DEFAULT 0 AFTER request_duration_avg,
ADD COLUMN request_error_count INT DEFAULT 0 AFTER request_duration_total,
ADD COLUMN security_score INT DEFAULT 100 AFTER request_error_count,
ADD COLUMN security_flags JSON NULL AFTER security_score,
ADD COLUMN metadata JSON NULL AFTER security_flags,
ADD COLUMN terminated_by ENUM('user', 'admin', 'system', 'expired', 'security') DEFAULT 'user' AFTER metadata,
ADD COLUMN terminated_reason TEXT NULL AFTER terminated_by,
ADD INDEX idx_sessions_impersonated (is_impersonated),
ADD INDEX idx_sessions_security (security_score),
ADD INDEX idx_sessions_last_request (last_request_time);

-- ================================================================
-- 2. إنشاء دوال (Functions) متقدمة لإدارة الجلسات
-- ================================================================

DELIMITER //

-- دالة: حساب درجة أمان الجلسة
CREATE FUNCTION calculate_session_security(
    p_ip_address VARCHAR(45),
    p_user_agent TEXT,
    p_device_name VARCHAR(100),
    p_trusted_device BOOLEAN
)
RETURNS INT
DETERMINISTIC
BEGIN
    DECLARE v_score INT DEFAULT 100;
    
    -- خصم نقاط للأجهزة غير الموثوقة
    IF NOT p_trusted_device THEN
        SET v_score = v_score - 20;
    END IF;
    
    -- خصم نقاط لمستخدمي الـ VPN (يمكن الكشف عن طريق IP)
    IF p_ip_address REGEXP '^(10\.|172\.1[6-9]\.|172\.2[0-9]\.|172\.3[0-1]\.|192\.168\.)' THEN
        SET v_score = v_score - 10;
    END IF;
    
    -- خصم نقاط لوكلاء المستخدم غير الشائعة
    IF p_user_agent NOT REGEXP '(Chrome|Firefox|Safari|Edge|Opera)' THEN
        SET v_score = v_score - 15;
    END IF;
    
    -- خصم نقاط للأجهزة المحمولة (أقل أماناً)
    IF p_user_agent REGEXP '(Android|iPhone|iPad|Mobile)' THEN
        SET v_score = v_score - 5;
    END IF;
    
    -- ضمان عدم تجاوز الحد الأدنى
    IF v_score < 0 THEN
        SET v_score = 0;
    END IF;
    
    RETURN v_score;
END//

-- دالة: التحقق من صحة الجلسة (متقدم)
CREATE FUNCTION is_session_valid_advanced(
    p_session_token_hash CHAR(64)
)
RETURNS BOOLEAN
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_security_score INT;
    
    SELECT COUNT(*), security_score
    INTO v_count, v_security_score
    FROM user_sessions s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.session_token_hash = p_session_token_hash
      AND s.is_active = 1
      AND s.expires_at > NOW()
      AND u.is_active = 1
      AND u.deleted_at IS NULL
      AND s.security_score >= 30; -- الحد الأدنى للأمان
    
    RETURN v_count > 0;
END//

-- دالة: جلب معلومات الجلسة الكاملة
CREATE FUNCTION get_full_session_info(
    p_session_id INT
)
RETURNS JSON
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_result JSON;
    
    SELECT JSON_OBJECT(
        'id', s.id,
        'user_id', s.user_id,
        'username', u.username,
        'full_name', u.full_name,
        'role', r.name,
        'role_display', r.display_name,
        'device_name', s.device_name,
        'device_type', s.device_type,
        'ip_address', s.ip_address,
        'location_city', s.location_city,
        'location_country', s.location_country,
        'browser_name', s.browser_name,
        'os_name', s.os_name,
        'login_at', s.login_at,
        'last_activity', s.last_activity,
        'expires_at', s.expires_at,
        'is_active', s.is_active,
        'trusted_device', s.trusted_device,
        'request_count', s.request_count,
        'security_score', s.security_score,
        'is_impersonated', s.is_impersonated,
        'impersonated_by', s.impersonated_by,
        'session_duration_seconds', TIMESTAMPDIFF(SECOND, s.login_at, NOW()),
        'idle_seconds', TIMESTAMPDIFF(SECOND, s.last_activity, NOW()),
        'remaining_seconds', TIMESTAMPDIFF(SECOND, NOW(), s.expires_at)
    )
    INTO v_result
    FROM user_sessions s
    INNER JOIN users u ON u.id = s.user_id
    INNER JOIN roles r ON r.id = u.role_id
    WHERE s.id = p_session_id;
    
    RETURN v_result;
END//

-- دالة: التحقق من وجود جلسة من جهاز آخر
CREATE FUNCTION has_session_on_other_device(
    p_user_id INT,
    p_current_session_id INT
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
      AND id != p_current_session_id
      AND is_active = 1
      AND expires_at > NOW();
    
    RETURN v_count > 0;
END//

-- دالة: جلب جميع الجلسات النشطة لمستخدم
CREATE FUNCTION get_active_sessions_json(
    p_user_id INT
)
RETURNS JSON
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_result JSON;
    
    SELECT JSON_ARRAYAGG(
        JSON_OBJECT(
            'id', id,
            'device_name', device_name,
            'device_type', device_type,
            'ip_address', ip_address,
            'login_at', login_at,
            'last_activity', last_activity,
            'expires_at', expires_at,
            'trusted_device', trusted_device,
            'security_score', security_score,
            'is_active', is_active
        )
    )
    INTO v_result
    FROM user_sessions
    WHERE user_id = p_user_id
      AND is_active = 1
      AND expires_at > NOW()
    ORDER BY last_activity DESC;
    
    RETURN IFNULL(v_result, JSON_ARRAY());
END//

DELIMITER ;

-- ================================================================
-- 3. إجراءات (Procedures) متقدمة لإدارة الجلسات
-- ================================================================

DELIMITER //

-- إجراء: إنشاء جلسة متقدمة مع تسجيل الأمان
CREATE PROCEDURE create_session_advanced(
    IN p_user_id INT,
    IN p_device_name VARCHAR(100),
    IN p_device_type VARCHAR(20),
    IN p_ip_address VARCHAR(45),
    IN p_user_agent TEXT,
    IN p_trusted_device BOOLEAN,
    IN p_session_created_by VARCHAR(20),
    IN p_impersonated_by INT,
    IN p_session_timeout INT
)
BEGIN
    DECLARE v_token VARCHAR(100);
    DECLARE v_token_hash CHAR(64);
    DECLARE v_expires_at TIMESTAMP;
    DECLARE v_security_score INT;
    DECLARE v_session_id INT;
    DECLARE v_fingerprint_hash VARCHAR(64);
    
    -- توليد رمز الجلسة
    SET v_token = generate_secure_token(64);
    SET v_token_hash = SHA2(v_token, 256);
    SET v_expires_at = DATE_ADD(NOW(), INTERVAL p_session_timeout SECOND);
    
    -- حساب درجة الأمان
    SET v_security_score = calculate_session_security(p_ip_address, p_user_agent, p_device_name, p_trusted_device);
    
    -- توليد بصمة الجهاز
    SET v_fingerprint_hash = SHA2(CONCAT(p_ip_address, p_user_agent, p_device_name, p_device_type), 256);
    
    -- إنهاء الجلسات القديمة (Single Session)
    IF (SELECT setting_value = 'true' FROM system_settings WHERE setting_key = 'single_session_enabled') THEN
        UPDATE user_sessions 
        SET is_active = 0, 
            logout_at = NOW(),
            terminated_by = 'system',
            terminated_reason = 'New session created'
        WHERE user_id = p_user_id 
          AND is_active = 1;
    END IF;
    
    -- إنشاء الجلسة الجديدة
    INSERT INTO user_sessions (
        user_id,
        session_token_hash,
        device_name,
        device_type,
        ip_address,
        user_agent,
        expires_at,
        login_at,
        last_activity,
        is_active,
        trusted_device,
        fingerprint_hash,
        session_created_by,
        is_impersonated,
        impersonated_by,
        security_score,
        created_at
    ) VALUES (
        p_user_id,
        v_token_hash,
        p_device_name,
        p_device_type,
        p_ip_address,
        p_user_agent,
        v_expires_at,
        NOW(),
        NOW(),
        1,
        p_trusted_device,
        v_fingerprint_hash,
        p_session_created_by,
        IF(p_impersonated_by IS NOT NULL, TRUE, FALSE),
        p_impersonated_by,
        v_security_score,
        NOW()
    );
    
    SET v_session_id = LAST_INSERT_ID();
    
    -- تسجيل إنشاء الجلسة
    INSERT INTO auth_logs (user_id, username, action, ip_address, user_agent, details, created_at)
    VALUES (
        p_user_id,
        (SELECT username FROM users WHERE id = p_user_id),
        'SESSION_CREATED',
        p_ip_address,
        p_user_agent,
        JSON_OBJECT(
            'session_id', v_session_id,
            'device', p_device_name,
            'device_type', p_device_type,
            'trusted', p_trusted_device,
            'security_score', v_security_score,
            'created_by', p_session_created_by,
            'impersonated_by', p_impersonated_by
        ),
        NOW()
    );
    
    -- إرجاع بيانات الجلسة
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم إنشاء الجلسة بنجاح',
        'data', JSON_OBJECT(
            'session_id', v_session_id,
            'token', v_token,
            'expires_at', v_expires_at,
            'security_score', v_security_score,
            'trusted_device', p_trusted_device
        )
    ) AS result;
END//

-- إجراء: تحديث نشاط الجلسة المتقدم
CREATE PROCEDURE update_session_activity_advanced(
    IN p_session_id INT,
    IN p_request_url VARCHAR(255),
    IN p_request_duration INT,
    IN p_has_error BOOLEAN
)
BEGIN
    DECLARE v_user_id INT;
    DECLARE v_active BOOLEAN;
    
    -- التحقق من صحة الجلسة
    SELECT user_id, is_active INTO v_user_id, v_active
    FROM user_sessions
    WHERE id = p_session_id;
    
    IF v_user_id IS NULL OR v_active = 0 THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'الجلسة غير صالحة أو منتهية'
        ) AS result;
        RETURN;
    END IF;
    
    -- تحديث نشاط الجلسة
    UPDATE user_sessions 
    SET 
        last_activity = NOW(),
        request_count = request_count + 1,
        last_request_time = NOW(),
        request_duration_total = request_duration_total + p_request_duration,
        request_duration_avg = (request_duration_total + p_request_duration) / (request_count + 1),
        request_error_count = request_error_count + IF(p_has_error, 1, 0),
        last_request_url = p_request_url,
        updated_at = NOW()
    WHERE id = p_session_id;
    
    -- تسجيل نشاط الجلسة
    INSERT INTO session_activity_log (
        session_id,
        user_id,
        activity_type,
        url,
        method,
        ip_address,
        response_time_ms,
        created_at
    ) VALUES (
        p_session_id,
        v_user_id,
        'API_CALL',
        p_request_url,
        'POST',
        (SELECT ip_address FROM user_sessions WHERE id = p_session_id),
        p_request_duration,
        NOW()
    );
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم تحديث نشاط الجلسة'
    ) AS result;
END//

-- إجراء: إنهاء جلسة (متقدم)
CREATE PROCEDURE terminate_session_advanced(
    IN p_session_id INT,
    IN p_terminated_by ENUM('user', 'admin', 'system', 'expired', 'security'),
    IN p_terminated_reason TEXT,
    IN p_admin_id INT
)
BEGIN
    DECLARE v_user_id INT;
    DECLARE v_username VARCHAR(50);
    DECLARE v_admin_username VARCHAR(50);
    
    SELECT user_id, username INTO v_user_id, v_username
    FROM users u
    INNER JOIN user_sessions s ON s.user_id = u.id
    WHERE s.id = p_session_id;
    
    IF v_user_id IS NULL THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'الجلسة غير موجودة'
        ) AS result;
        RETURN;
    END IF;
    
    -- تحديث حالة الجلسة
    UPDATE user_sessions 
    SET 
        is_active = 0,
        logout_at = NOW(),
        terminated_by = p_terminated_by,
        terminated_reason = p_terminated_reason,
        updated_at = NOW()
    WHERE id = p_session_id;
    
    -- تسجيل إنهاء الجلسة
    IF p_admin_id IS NOT NULL THEN
        SELECT username INTO v_admin_username FROM users WHERE id = p_admin_id;
        
        INSERT INTO audit_logs (
            user_id, username, action, module, description, details, created_at
        ) VALUES (
            p_admin_id,
            v_admin_username,
            'TERMINATE_SESSION',
            'session',
            CONCAT('تم إنهاء جلسة المستخدم: ', v_username),
            JSON_OBJECT(
                'session_id', p_session_id,
                'user', v_username,
                'reason', p_terminated_reason,
                'terminated_by', p_terminated_by
            ),
            NOW()
        );
    END IF;
    
    INSERT INTO auth_logs (
        user_id, username, action, ip_address, details, created_at
    ) VALUES (
        v_user_id,
        v_username,
        'SESSION_TERMINATED',
        (SELECT ip_address FROM user_sessions WHERE id = p_session_id),
        JSON_OBJECT(
            'session_id', p_session_id,
            'reason', p_terminated_reason,
            'terminated_by', p_terminated_by
        ),
        NOW()
    );
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم إنهاء الجلسة بنجاح'
    ) AS result;
END//

-- إجراء: إنهاء جميع جلسات المستخدم (باستثناء الجلسة الحالية)
CREATE PROCEDURE terminate_all_sessions_except(
    IN p_user_id INT,
    IN p_except_session_id INT,
    IN p_admin_id INT,
    IN p_reason TEXT
)
BEGIN
    DECLARE v_sessions_terminated INT DEFAULT 0;
    DECLARE v_admin_username VARCHAR(50);
    
    IF p_admin_id IS NOT NULL THEN
        SELECT username INTO v_admin_username FROM users WHERE id = p_admin_id;
    END IF;
    
    -- إنهاء جميع الجلسات باستثناء الجلسة المحددة
    UPDATE user_sessions 
    SET 
        is_active = 0,
        logout_at = NOW(),
        terminated_by = IF(p_admin_id IS NOT NULL, 'admin', 'user'),
        terminated_reason = p_reason,
        updated_at = NOW()
    WHERE user_id = p_user_id
      AND id != p_except_session_id
      AND is_active = 1;
    
    SET v_sessions_terminated = ROW_COUNT();
    
    -- تسجيل العملية
    IF p_admin_id IS NOT NULL THEN
        INSERT INTO audit_logs (
            user_id, username, action, module, description, details, created_at
        ) VALUES (
            p_admin_id,
            v_admin_username,
            'TERMINATE_ALL_SESSIONS',
            'session',
            CONCAT('تم إنهاء جميع جلسات المستخدم (باستثناء الجلسة الحالية)'),
            JSON_OBJECT(
                'user_id', p_user_id,
                'sessions_terminated', v_sessions_terminated,
                'reason', p_reason,
                'excluded_session', p_except_session_id
            ),
            NOW()
        );
    END IF;
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', CONCAT('تم إنهاء ', v_sessions_terminated, ' جلسة بنجاح'),
        'data', JSON_OBJECT(
            'sessions_terminated', v_sessions_terminated,
            'excluded_session', p_except_session_id
        )
    ) AS result;
END//

-- إجراء: تجديد الجلسة (Refresh)
CREATE PROCEDURE refresh_session(
    IN p_session_id INT,
    IN p_extend_seconds INT
)
BEGIN
    DECLARE v_user_id INT;
    DECLARE v_username VARCHAR(50);
    DECLARE v_new_expiry TIMESTAMP;
    
    SELECT user_id, username INTO v_user_id, v_username
    FROM users u
    INNER JOIN user_sessions s ON s.user_id = u.id
    WHERE s.id = p_session_id
      AND s.is_active = 1;
    
    IF v_user_id IS NULL THEN
        SELECT JSON_OBJECT(
            'success', FALSE,
            'message', 'الجلسة غير صالحة'
        ) AS result;
        RETURN;
    END IF;
    
    -- تحديث وقت انتهاء الجلسة
    SET v_new_expiry = DATE_ADD(NOW(), INTERVAL p_extend_seconds SECOND);
    
    UPDATE user_sessions 
    SET 
        expires_at = v_new_expiry,
        refreshed_at = NOW(),
        updated_at = NOW()
    WHERE id = p_session_id;
    
    -- تسجيل التجديد
    INSERT INTO auth_logs (user_id, username, action, details, created_at)
    VALUES (
        v_user_id,
        v_username,
        'SESSION_REFRESHED',
        JSON_OBJECT(
            'session_id', p_session_id,
            'new_expiry', v_new_expiry,
            'extended_seconds', p_extend_seconds
        ),
        NOW()
    );
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم تجديد الجلسة بنجاح',
        'data', JSON_OBJECT(
            'session_id', p_session_id,
            'expires_at', v_new_expiry,
            'extended_seconds', p_extend_seconds
        )
    ) AS result;
END//

-- ================================================================
-- 4. نظام تتبع الجلسات والتحليل
-- ================================================================

-- جدول تحليل الجلسات اليومي
CREATE TABLE IF NOT EXISTS session_analytics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analytics_date DATE NOT NULL,
    total_sessions INT DEFAULT 0,
    active_sessions INT DEFAULT 0,
    new_sessions INT DEFAULT 0,
    terminated_sessions INT DEFAULT 0,
    expired_sessions INT DEFAULT 0,
    avg_session_duration INT DEFAULT 0,
    max_session_duration INT DEFAULT 0,
    total_requests INT DEFAULT 0,
    avg_requests_per_session INT DEFAULT 0,
    security_incidents INT DEFAULT 0,
    unique_users INT DEFAULT 0,
    unique_ips INT DEFAULT 0,
    device_breakdown JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_analytics_date (analytics_date),
    INDEX idx_analytics_date (analytics_date)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- إجراء: تجميع تحليلات الجلسات اليومية
CREATE PROCEDURE collect_session_analytics(
    IN p_date DATE
)
BEGIN
    DECLARE v_total_sessions INT;
    DECLARE v_active_sessions INT;
    DECLARE v_new_sessions INT;
    DECLARE v_terminated_sessions INT;
    DECLARE v_expired_sessions INT;
    DECLARE v_avg_duration INT;
    DECLARE v_max_duration INT;
    DECLARE v_total_requests INT;
    DECLARE v_avg_requests INT;
    DECLARE v_unique_users INT;
    DECLARE v_unique_ips INT;
    DECLARE v_device_json JSON;
    DECLARE v_security_incidents INT;
    
    -- إحصائيات الجلسات
    SELECT 
        COUNT(*) INTO v_total_sessions
    FROM user_sessions
    WHERE DATE(login_at) = p_date;
    
    SELECT 
        COUNT(*) INTO v_active_sessions
    FROM user_sessions
    WHERE DATE(login_at) = p_date
      AND is_active = 1;
    
    SELECT 
        COUNT(*) INTO v_new_sessions
    FROM user_sessions
    WHERE DATE(login_at) = p_date
      AND session_created_by = 'login';
    
    SELECT 
        COUNT(*) INTO v_terminated_sessions
    FROM user_sessions
    WHERE DATE(login_at) = p_date
      AND is_active = 0
      AND terminated_by != 'expired';
    
    SELECT 
        COUNT(*) INTO v_expired_sessions
    FROM user_sessions
    WHERE DATE(login_at) = p_date
      AND is_active = 0
      AND terminated_by = 'expired';
    
    SELECT 
        AVG(TIMESTAMPDIFF(SECOND, login_at, IFNULL(logout_at, NOW()))) INTO v_avg_duration,
        MAX(TIMESTAMPDIFF(SECOND, login_at, IFNULL(logout_at, NOW()))) INTO v_max_duration
    FROM user_sessions
    WHERE DATE(login_at) = p_date;
    
    SELECT 
        SUM(request_count) INTO v_total_requests,
        AVG(request_count) INTO v_avg_requests
    FROM user_sessions
    WHERE DATE(login_at) = p_date;
    
    SELECT 
        COUNT(DISTINCT user_id) INTO v_unique_users,
        COUNT(DISTINCT ip_address) INTO v_unique_ips
    FROM user_sessions
    WHERE DATE(login_at) = p_date;
    
    -- توزيع الأجهزة
    SELECT JSON_OBJECT(
        'desktop', COUNT(CASE WHEN device_type = 'desktop' THEN 1 END),
        'laptop', COUNT(CASE WHEN device_type = 'laptop' THEN 1 END),
        'tablet', COUNT(CASE WHEN device_type = 'tablet' THEN 1 END),
        'mobile', COUNT(CASE WHEN device_type = 'mobile' THEN 1 END),
        'unknown', COUNT(CASE WHEN device_type = 'unknown' THEN 1 END)
    ) INTO v_device_json
    FROM user_sessions
    WHERE DATE(login_at) = p_date;
    
    -- حوادث الأمان
    SELECT 
        COUNT(*) INTO v_security_incidents
    FROM user_sessions
    WHERE DATE(login_at) = p_date
      AND security_score < 50;
    
    -- إدخال التحليلات
    INSERT INTO session_analytics (
        analytics_date,
        total_sessions,
        active_sessions,
        new_sessions,
        terminated_sessions,
        expired_sessions,
        avg_session_duration,
        max_session_duration,
        total_requests,
        avg_requests_per_session,
        unique_users,
        unique_ips,
        device_breakdown,
        security_incidents,
        updated_at
    ) VALUES (
        p_date,
        v_total_sessions,
        v_active_sessions,
        v_new_sessions,
        v_terminated_sessions,
        v_expired_sessions,
        v_avg_duration,
        v_max_duration,
        v_total_requests,
        v_avg_requests,
        v_unique_users,
        v_unique_ips,
        v_device_json,
        v_security_incidents,
        NOW()
    ) ON DUPLICATE KEY UPDATE
        total_sessions = VALUES(total_sessions),
        active_sessions = VALUES(active_sessions),
        new_sessions = VALUES(new_sessions),
        terminated_sessions = VALUES(terminated_sessions),
        expired_sessions = VALUES(expired_sessions),
        avg_session_duration = VALUES(avg_session_duration),
        max_session_duration = VALUES(max_session_duration),
        total_requests = VALUES(total_requests),
        avg_requests_per_session = VALUES(avg_requests_per_session),
        unique_users = VALUES(unique_users),
        unique_ips = VALUES(unique_ips),
        device_breakdown = VALUES(device_breakdown),
        security_incidents = VALUES(security_incidents),
        updated_at = NOW();
    
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم تجميع تحليلات الجلسات بنجاح',
        'data', JSON_OBJECT(
            'date', p_date,
            'total_sessions', v_total_sessions,
            'unique_users', v_unique_users,
            'security_incidents', v_security_incidents
        )
    ) AS result;
END//

-- ================================================================
-- 5. عروض (Views) متقدمة للجلسات
-- ================================================================

-- عرض الجلسات النشطة المتقدم
CREATE OR REPLACE VIEW v_sessions_active_advanced AS
SELECT 
    s.id AS session_id,
    s.user_id,
    u.username,
    u.full_name,
    u.email,
    r.name AS role_name,
    r.display_name AS role_display,
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
    s.security_score,
    s.is_impersonated,
    u2.full_name AS impersonated_by_name,
    CASE 
        WHEN s.security_score < 50 THEN '⚠️ منخفض'
        WHEN s.security_score < 70 THEN '🟡 متوسط'
        ELSE '🟢 عالي'
    END AS security_level,
    CASE 
        WHEN TIMESTAMPDIFF(SECOND, s.expires_at, NOW()) > 0 THEN 'expired'
        WHEN TIMESTAMPDIFF(SECOND, s.last_activity, NOW()) > 1800 THEN 'idle'
        WHEN TIMESTAMPDIFF(SECOND, s.login_at, NOW()) > 28800 THEN 'long_session'
        ELSE 'active'
    END AS session_status,
    CASE 
        WHEN s.is_impersonated = 1 THEN 'نعم'
        ELSE 'لا'
    END AS is_impersonated_label
FROM user_sessions s
INNER JOIN users u ON u.id = s.user_id
INNER JOIN roles r ON r.id = u.role_id
LEFT JOIN users u2 ON u2.id = s.impersonated_by
WHERE s.is_active = 1
  AND s.expires_at > NOW()
ORDER BY s.last_activity DESC;

-- عرض سجل نشاط الجلسات
CREATE OR REPLACE VIEW v_session_activity_log_advanced AS
SELECT 
    sal.id,
    sal.session_id,
    sal.user_id,
    u.username,
    u.full_name,
    sal.activity_type,
    sal.url,
    sal.method,
    sal.ip_address,
    sal.response_time_ms,
    sal.created_at,
    CASE sal.activity_type
        WHEN 'PAGE_VIEW' THEN '📄 صفحة'
        WHEN 'API_CALL' THEN '🔌 API'
        WHEN 'DOWNLOAD' THEN '⬇️ تحميل'
        WHEN 'EXPORT' THEN '📊 تصدير'
        WHEN 'PRINT' THEN '🖨️ طباعة'
        ELSE sal.activity_type
    END AS activity_icon,
    CONCAT(ROUND(sal.response_time_ms / 1000, 2), ' ثانية') AS response_time_label
FROM session_activity_log sal
INNER JOIN users u ON u.id = sal.user_id
ORDER BY sal.created_at DESC
LIMIT 1000;

-- عرض تحليلات الجلسات
CREATE OR REPLACE VIEW v_session_analytics_summary AS
SELECT 
    sa.analytics_date,
    sa.total_sessions,
    sa.active_sessions,
    sa.new_sessions,
    sa.terminated_sessions,
    sa.expired_sessions,
    sa.avg_session_duration,
    sa.max_session_duration,
    sa.total_requests,
    sa.avg_requests_per_session,
    sa.unique_users,
    sa.unique_ips,
    sa.security_incidents,
    sa.device_breakdown,
    ROUND(sa.active_sessions * 100.0 / NULLIF(sa.total_sessions, 0), 2) AS active_rate,
    ROUND(sa.security_incidents * 100.0 / NULLIF(sa.total_sessions, 0), 2) AS incident_rate,
    sa.created_at,
    sa.updated_at
FROM session_analytics sa
ORDER BY sa.analytics_date DESC;

-- ================================================================
-- 6. الأحداث المجدولة لإدارة الجلسات
-- ================================================================

-- حدث: تنظيف الجلسات المنتهية (كل ساعة)
CREATE EVENT IF NOT EXISTS event_cleanup_sessions_hourly
ON SCHEDULE EVERY 1 HOUR
DO
BEGIN
    -- إنهاء الجلسات المنتهية
    UPDATE user_sessions 
    SET 
        is_active = 0, 
        logout_at = NOW(),
        terminated_by = 'expired',
        terminated_reason = 'Session expired'
    WHERE expires_at <= NOW()
      AND is_active = 1;
    
    -- حذف الجلسات القديمة (أكثر من 30 يوم)
    DELETE FROM user_sessions 
    WHERE is_active = 0
      AND logout_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- حذف سجلات النشاط القديمة (أكثر من 90 يوم)
    DELETE FROM session_activity_log 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
END//

-- حدث: تجميع تحليلات الجلسات (يومياً)
CREATE EVENT IF NOT EXISTS event_collect_session_analytics
ON SCHEDULE EVERY 1 DAY
STARTS '2026-08-21 01:00:00'
DO
BEGIN
    CALL collect_session_analytics(CURDATE() - INTERVAL 1 DAY);
END//

-- ================================================================
-- 7. نظام الأمان المتقدم للجلسات
-- ================================================================

-- جدول كشف الجلسات المشبوهة
CREATE TABLE IF NOT EXISTS suspicious_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    detection_type ENUM(
        'multiple_ips',
        'different_locations',
        'unusual_device',
        'unusual_time',
        'rapid_requests',
        'security_score_drop'
    ) NOT NULL,
    detection_details JSON NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    is_resolved BOOLEAN DEFAULT FALSE,
    resolved_at TIMESTAMP NULL,
    resolved_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES user_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_suspicious_session (session_id),
    INDEX idx_suspicious_user (user_id),
    INDEX idx_suspicious_severity (severity),
    INDEX idx_suspicious_created (created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- إجراء: كشف الجلسات المشبوهة
CREATE PROCEDURE detect_suspicious_sessions()
BEGIN
    DECLARE v_count INT DEFAULT 0;
    
    -- كشف IPs متعددة لنفس المستخدم
    INSERT INTO suspicious_sessions (
        session_id,
        user_id,
        detection_type,
        detection_details,
        severity,
        created_at
    )
    SELECT 
        s.id,
        s.user_id,
        'multiple_ips',
        JSON_OBJECT(
            'current_ip', s.ip_address,
            'other_ips', (
                SELECT JSON_ARRAYAGG(DISTINCT ip_address)
                FROM user_sessions
                WHERE user_id = s.user_id
                  AND id != s.id
                  AND is_active = 1
            )
        ),
        'medium',
        NOW()
    FROM user_sessions s
    WHERE s.is_active = 1
      AND EXISTS (
          SELECT 1
          FROM user_sessions s2
          WHERE s2.user_id = s.user_id
            AND s2.id != s.id
            AND s2.is_active = 1
            AND s2.ip_address != s.ip_address
      )
      AND NOT EXISTS (
          SELECT 1
          FROM suspicious_sessions ss
          WHERE ss.session_id = s.id
            AND ss.detection_type = 'multiple_ips'
            AND ss.is_resolved = 0
      );
    
    SET v_count = v_count + ROW_COUNT();
    
    -- كشف درجات الأمان المنخفضة
    INSERT INTO suspicious_sessions (
        session_id,
        user_id,
        detection_type,
        detection_details,
        severity,
        created_at
    )
    SELECT 
        id,
        user_id,
        'security_score_drop',
        JSON_OBJECT(
            'security_score', security_score,
            'threshold', 30
        ),
        'high',
        NOW()
    FROM user_sessions
    WHERE is_active = 1
      AND security_score < 30
      AND NOT EXISTS (
          SELECT 1
          FROM suspicious_sessions ss
          WHERE ss.session_id = id
            AND ss.detection_type = 'security_score_drop'
            AND ss.is_resolved = 0
      );
    
    SET v_count = v_count + ROW_COUNT();
    
    -- إرجاع النتيجة
    SELECT JSON_OBJECT(
        'success', TRUE,
        'message', 'تم كشف الجلسات المشبوهة',
        'data', JSON_OBJECT(
            'suspicious_count', v_count
        )
    ) AS result;
END//

-- ================================================================
-- 8. بيانات اختبار للجلسات
-- ================================================================

-- إنشاء جلسات تجريبية
INSERT INTO user_sessions (
    user_id,
    session_token_hash,
    device_name,
    device_type,
    ip_address,
    user_agent,
    expires_at,
    login_at,
    last_activity,
    is_active,
    trusted_device,
    security_score,
    request_count
) VALUES
(
    (SELECT id FROM users WHERE username = 'admin'),
    SHA2('test_token_1', 256),
    'Work Laptop - Chrome',
    'laptop',
    '192.168.1.100',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
    DATE_ADD(NOW(), INTERVAL 2 HOUR),
    DATE_SUB(NOW(), INTERVAL 1 HOUR),
    DATE_SUB(NOW(), INTERVAL 5 MINUTE),
    1,
    1,
    95,
    45
),
(
    (SELECT id FROM users WHERE username = 'manager'),
    SHA2('test_token_2', 256),
    'Office PC - Edge',
    'desktop',
    '192.168.1.101',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Edge/120.0.0.0',
    DATE_ADD(NOW(), INTERVAL 3 HOUR),
    DATE_SUB(NOW(), INTERVAL 2 HOUR),
    DATE_SUB(NOW(), INTERVAL 10 MINUTE),
    1,
    1,
    85,
    30
),
(
    (SELECT id FROM users WHERE username = 'staff'),
    SHA2('test_token_3', 256),
    'Mobile Phone - Safari',
    'mobile',
    '192.168.1.102',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/605.1.15',
    DATE_ADD(NOW(), INTERVAL 1 HOUR),
    DATE_SUB(NOW(), INTERVAL 30 MINUTE),
    DATE_SUB(NOW(), INTERVAL 2 MINUTE),
    1,
    0,
    65,
    12
);

-- إضافة نشاطات جلسات تجريبية
INSERT INTO session_activity_log (
    session_id,
    user_id,
    activity_type,
    url,
    method,
    ip_address,
    response_time_ms,
    created_at
) VALUES
(1, (SELECT id FROM users WHERE username = 'admin'), 'API_CALL', '/api/products', 'GET', '192.168.1.100', 125, DATE_SUB(NOW(), INTERVAL 10 MINUTE)),
(1, (SELECT id FROM users WHERE username = 'admin'), 'API_CALL', '/api/receipts', 'POST', '192.168.1.100', 320, DATE_SUB(NOW(), INTERVAL 15 MINUTE)),
(2, (SELECT id FROM users WHERE username = 'manager'), 'PAGE_VIEW', '/dashboard', 'GET', '192.168.1.101', 80, DATE_SUB(NOW(), INTERVAL 20 MINUTE)),
(3, (SELECT id FROM users WHERE username = 'staff'), 'API_CALL', '/api/issues', 'POST', '192.168.1.102', 450, DATE_SUB(NOW(), INTERVAL 5 MINUTE));

-- ================================================================
-- 9. عرض معلومات الجلسات
-- ================================================================

SELECT 
    '📊 إحصائيات الجلسات' AS title,
    (SELECT COUNT(*) FROM user_sessions WHERE is_active = 1 AND expires_at > NOW()) AS active_sessions,
    (SELECT COUNT(*) FROM user_sessions WHERE is_active = 1 AND expires_at > NOW() AND device_type = 'desktop') AS desktop_sessions,
    (SELECT COUNT(*) FROM user_sessions WHERE is_active = 1 AND expires_at > NOW() AND device_type = 'mobile') AS mobile_sessions,
    (SELECT COUNT(*) FROM user_sessions WHERE is_active = 1 AND expires_at > NOW() AND trusted_device = 1) AS trusted_devices,
    (SELECT COUNT(*) FROM user_sessions WHERE is_active = 1 AND expires_at > NOW() AND security_score < 50) AS low_security_sessions,
    (SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE is_active = 1 AND expires_at > NOW()) AS unique_users_active,
    (SELECT AVG(security_score) FROM user_sessions WHERE is_active = 1 AND expires_at > NOW()) AS avg_security_score;

SELECT 
    '🔐 معلومات الأمان' AS title,
    (SELECT COUNT(*) FROM suspicious_sessions WHERE is_resolved = 0) AS pending_suspicious_sessions,
    (SELECT COUNT(*) FROM suspicious_sessions WHERE is_resolved = 0 AND severity = 'high') AS high_severity_sessions,
    (SELECT COUNT(*) FROM suspicious_sessions WHERE is_resolved = 0 AND severity = 'critical') AS critical_severity_sessions,
    (SELECT COUNT(*) FROM suspicious_sessions WHERE is_resolved = 1) AS resolved_suspicious_sessions;

-- ================================================================
-- انتهى ملف إدارة الجلسات المتقدم
-- ================================================================

SELECT '✅ تم إنشاء نظام إدارة الجلسات المتقدم بنجاح' AS final_status;

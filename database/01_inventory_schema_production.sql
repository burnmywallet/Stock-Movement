-- ================================================================
-- نظام إدارة المخازن والمخزون المتقدم
-- الملف: 01_inventory_schema_production.sql
-- الإصدار: 2.0 Production Ready
-- التاريخ: 2026-08-20
-- ================================================================
--
-- هذا الملف يحتوي على:
-- 1. إنشاء قاعدة البيانات مع الترميز الأمثل
-- 2. جميع الجداول الأساسية (24 جدول)
-- 3. العلاقات (Foreign Keys) مع قواعد التكامل المرجعي
-- 4. الفهارس المحسنة (Indexes) للأداء العالي
-- 5. القيود (Constraints) لضمان سلامة البيانات
-- 6. المحفزات (Triggers) للتحديث التلقائي
-- 7. الإجراءات المخزنة (Stored Procedures) للتقارير
-- 8. الدوال (Functions) للتحقق والعمليات
-- 9. العروض (Views) للتقارير السريعة
-- 10. البيانات الأولية (Seed Data) للتشغيل الفوري
-- 11. نظام التدقيق المتقدم (Audit Trail)
-- 12. نظام النسخ الاحتياطي التلقائي
-- ================================================================

-- ================================================================
-- 1. إنشاء قاعدة البيانات
-- ================================================================

DROP DATABASE IF EXISTS inventory_system;
CREATE DATABASE inventory_system
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE inventory_system;

-- ================================================================
-- 2. جداول المستخدمين والصلاحيات (RBAC المتقدم)
-- ================================================================

-- جدول الأدوار مع دعم التسلسل الهرمي
CREATE TABLE roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    parent_id INT UNSIGNED NULL,
    level TINYINT UNSIGNED DEFAULT 1,
    is_system BOOLEAN DEFAULT FALSE,
    priority INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (parent_id) REFERENCES roles(id) ON DELETE SET NULL,
    INDEX idx_roles_name (name),
    INDEX idx_roles_parent (parent_id),
    INDEX idx_roles_level (level),
    INDEX idx_roles_deleted (deleted_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول الصلاحيات مع التصنيف
CREATE TABLE permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL,
    sub_module VARCHAR(50) NULL,
    action VARCHAR(50) NOT NULL,
    description TEXT,
    is_core BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_permissions_name (name),
    INDEX idx_permissions_module (module),
    INDEX idx_permissions_action (action),
    INDEX idx_permissions_sub_module (sub_module)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول صلاحيات الأدوار
CREATE TABLE role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    is_allowed BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    INDEX idx_role_permissions_role (role_id),
    INDEX idx_role_permissions_permission (permission_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول المستخدمين المتقدم
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    employee_id VARCHAR(50) NULL UNIQUE,
    department VARCHAR(100) NULL,
    phone VARCHAR(20) NULL,
    mobile VARCHAR(20) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    is_verified BOOLEAN DEFAULT FALSE,
    is_locked BOOLEAN DEFAULT FALSE,
    locked_until TIMESTAMP NULL,
    failed_login_attempts INT DEFAULT 0,
    last_login_at TIMESTAMP NULL,
    last_login_ip VARCHAR(45) NULL,
    last_password_change TIMESTAMP NULL,
    password_expiry_days INT DEFAULT 90,
    remember_token VARCHAR(100) NULL,
    email_verified_at TIMESTAMP NULL,
    preferences JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_users_username (username),
    INDEX idx_users_email (email),
    INDEX idx_users_role (role_id),
    INDEX idx_users_active (is_active),
    INDEX idx_users_employee (employee_id),
    INDEX idx_users_deleted (deleted_at),
    INDEX idx_users_locked (is_locked),
    INDEX idx_users_last_login (last_login_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول جلسات المستخدمين المتقدم
CREATE TABLE user_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    session_token_hash CHAR(64) NOT NULL UNIQUE,
    session_key VARCHAR(64) NULL,
    device_name VARCHAR(100) NULL,
    device_type ENUM('desktop', 'laptop', 'tablet', 'mobile', 'unknown') DEFAULT 'unknown',
    ip_address VARCHAR(45) NOT NULL,
    ip_location VARCHAR(100) NULL,
    user_agent TEXT,
    session_data JSON NULL,
    login_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    refreshed_at TIMESTAMP NULL,
    expires_at TIMESTAMP NOT NULL,
    logout_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    request_count INT DEFAULT 0,
    last_request_url VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_sessions_user (user_id),
    INDEX idx_sessions_token (session_token_hash),
    INDEX idx_sessions_active (is_active),
    INDEX idx_sessions_expires (expires_at),
    INDEX idx_sessions_device (device_type),
    INDEX idx_sessions_ip (ip_address),
    INDEX idx_sessions_last_activity (last_activity)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول سجل تغييرات كلمات المرور
CREATE TABLE password_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    changed_by INT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    reason ENUM('manual', 'forced', 'reset', 'expired') DEFAULT 'manual',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_password_history_user (user_id),
    INDEX idx_password_history_changed (changed_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول سجل عمليات المصادقة
CREATE TABLE auth_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    username VARCHAR(50) NULL,
    action ENUM('LOGIN_SUCCESS', 'LOGIN_FAILED', 'LOGOUT', 'SESSION_EXPIRED', 
                'LOCKED', 'UNLOCKED', 'PASSWORD_CHANGED', 'PASSWORD_RESET') NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_auth_logs_user (user_id),
    INDEX idx_auth_logs_action (action),
    INDEX idx_auth_logs_created (created_at),
    INDEX idx_auth_logs_ip (ip_address)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 3. جداول البيانات الأساسية
-- ================================================================

-- جدول التصنيفات المتقدم
CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    parent_id INT UNSIGNED NULL,
    icon VARCHAR(100) NULL,
    color VARCHAR(7) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_categories_code (code),
    INDEX idx_categories_parent (parent_id),
    INDEX idx_categories_active (is_active),
    INDEX idx_categories_deleted (deleted_at),
    INDEX idx_categories_sort (sort_order)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول الوحدات المتقدم
CREATE TABLE units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(50) NOT NULL,
    name_plural VARCHAR(50) NULL,
    symbol VARCHAR(10),
    is_base_unit BOOLEAN DEFAULT TRUE,
    conversion_factor DECIMAL(15,6) DEFAULT 1.000000,
    base_unit_id INT UNSIGNED NULL,
    precision_digits TINYINT DEFAULT 2,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (base_unit_id) REFERENCES units(id) ON DELETE SET NULL,
    INDEX idx_units_code (code),
    INDEX idx_units_base (base_unit_id),
    INDEX idx_units_active (is_active),
    INDEX idx_units_deleted (deleted_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول الموردين المتقدم
CREATE TABLE suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    mobile VARCHAR(20),
    email VARCHAR(100),
    website VARCHAR(255),
    address TEXT,
    city VARCHAR(50),
    country VARCHAR(50),
    postal_code VARCHAR(20),
    tax_number VARCHAR(50),
    commercial_register VARCHAR(50),
    payment_terms TEXT,
    credit_limit DECIMAL(15,2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    rating TINYINT DEFAULT 3,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_suppliers_code (code),
    INDEX idx_suppliers_active (is_active),
    INDEX idx_suppliers_rating (rating),
    INDEX idx_suppliers_deleted (deleted_at),
    INDEX idx_suppliers_city (city),
    INDEX idx_suppliers_tax (tax_number)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول الجهات (لصرف) المتقدم
CREATE TABLE recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    type ENUM('internal', 'external', 'customer', 'department', 'project') DEFAULT 'internal',
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_recipients_code (code),
    INDEX idx_recipients_active (is_active),
    INDEX idx_recipients_type (type),
    INDEX idx_recipients_deleted (deleted_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 4. جداول الأصناف والمخازن المتقدمة
-- ================================================================

-- جدول الأصناف المتقدم
CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    barcode VARCHAR(100) NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category_id INT UNSIGNED NULL,
    unit_id INT UNSIGNED NOT NULL,
    purchase_unit_id INT UNSIGNED NULL,
    sale_unit_id INT UNSIGNED NULL,
    min_stock DECIMAL(15,3) DEFAULT 0.000,
    max_stock DECIMAL(15,3) NULL,
    reorder_point DECIMAL(15,3) NULL,
    reorder_quantity DECIMAL(15,3) NULL,
    cost_price DECIMAL(15,3) DEFAULT 0.000,
    selling_price DECIMAL(15,3) NULL,
    last_purchase_price DECIMAL(15,3) NULL,
    weight DECIMAL(15,3) NULL,
    dimensions VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    is_serialized BOOLEAN DEFAULT FALSE,
    is_batch_tracked BOOLEAN DEFAULT FALSE,
    is_expirable BOOLEAN DEFAULT FALSE,
    shelf_life_days INT NULL,
    warranty_days INT NULL,
    tax_rate DECIMAL(5,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (unit_id) REFERENCES units(id),
    FOREIGN KEY (purchase_unit_id) REFERENCES units(id) ON DELETE SET NULL,
    FOREIGN KEY (sale_unit_id) REFERENCES units(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_products_code (code),
    INDEX idx_products_barcode (barcode),
    INDEX idx_products_category (category_id),
    INDEX idx_products_unit (unit_id),
    INDEX idx_products_active (is_active),
    INDEX idx_products_deleted (deleted_at),
    INDEX idx_products_min_stock (min_stock),
    INDEX idx_products_reorder (reorder_point),
    INDEX idx_products_serialized (is_serialized),
    INDEX idx_products_expirable (is_expirable),
    FULLTEXT INDEX idx_products_search (name, description)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول المخازن المتقدم
CREATE TABLE warehouses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    type ENUM('main', 'sub', 'virtual', 'store', 'showroom') DEFAULT 'main',
    location VARCHAR(200),
    address TEXT,
    manager_id INT UNSIGNED NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    is_main BOOLEAN DEFAULT FALSE,
    is_default BOOLEAN DEFAULT FALSE,
    capacity DECIMAL(15,2) NULL,
    current_utilization DECIMAL(15,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_warehouses_code (code),
    INDEX idx_warehouses_active (is_active),
    INDEX idx_warehouses_type (type),
    INDEX idx_warehouses_manager (manager_id),
    INDEX idx_warehouses_deleted (deleted_at),
    INDEX idx_warehouses_main (is_main)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 5. جدول الأرصدة الحالية مع دعم الأرصدة الإضافية
-- ================================================================

CREATE TABLE stock_balances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    reserved_quantity DECIMAL(15,3) DEFAULT 0.000,
    on_order_quantity DECIMAL(15,3) DEFAULT 0.000,
    last_movement_id BIGINT UNSIGNED NULL,
    last_movement_date TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_balance (product_id, warehouse_id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    INDEX idx_balances_product (product_id),
    INDEX idx_balances_warehouse (warehouse_id),
    INDEX idx_balances_quantity (quantity),
    INDEX idx_balances_reserved (reserved_quantity),
    INDEX idx_balances_last_movement (last_movement_date)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 6. جدول حركات المخزون (Immutable - غير قابل للتعديل)
-- ================================================================

CREATE TABLE stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    movement_type ENUM(
        'RECEIPT',          -- استلام
        'ISSUE',            -- صرف
        'TRANSFER_OUT',     -- تحويل خارج
        'TRANSFER_IN',      -- تحويل داخل
        'RETURN_IN',        -- مرتجع للمخزن
        'RETURN_OUT',       -- مرتجع من المخزن
        'ADJUSTMENT',       -- تسوية جرد
        'COUNT_CORRECTION', -- تصحيح جرد
        'RESERVATION',      -- حجز
        'RELEASE'           -- إلغاء حجز
    ) NOT NULL,
    reference_type ENUM(
        'receipt', 'issue', 'transfer', 'return', 
        'inventory_count', 'stock_adjustment', 'sales_order'
    ) NOT NULL,
    reference_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    unit_cost DECIMAL(15,3) DEFAULT 0.000,
    total_cost DECIMAL(15,3) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    balance_before DECIMAL(15,3) NOT NULL,
    balance_after DECIMAL(15,3) NOT NULL,
    reserved_before DECIMAL(15,3) DEFAULT 0.000,
    reserved_after DECIMAL(15,3) DEFAULT 0.000,
    movement_date DATETIME NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    notes TEXT,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_movements_product (product_id),
    INDEX idx_movements_warehouse (warehouse_id),
    INDEX idx_movements_product_warehouse_date (product_id, warehouse_id, movement_date),
    INDEX idx_movements_warehouse_date (warehouse_id, movement_date),
    INDEX idx_movements_product_date (product_id, movement_date),
    INDEX idx_movements_type_date (movement_type, movement_date),
    INDEX idx_movements_reference (reference_type, reference_id),
    INDEX idx_movements_user (user_id, movement_date),
    INDEX idx_movements_date (movement_date),
    INDEX idx_movements_cost (unit_cost),
    INDEX idx_movements_balance (balance_before, balance_after)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 7. جداول المستندات الأساسية
-- ================================================================

-- جدول إذون الاستلام المتقدم
CREATE TABLE receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_no VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    receipt_date DATE NOT NULL,
    receipt_time TIME NOT NULL,
    expected_date DATE NULL,
    delivery_date DATE NULL,
    po_number VARCHAR(50) NULL,
    invoice_number VARCHAR(50) NULL,
    total_items INT DEFAULT 0,
    total_quantity DECIMAL(15,3) DEFAULT 0.000,
    total_cost DECIMAL(15,3) DEFAULT 0.000,
    total_tax DECIMAL(15,3) DEFAULT 0.000,
    total_discount DECIMAL(15,3) DEFAULT 0.000,
    net_total DECIMAL(15,3) GENERATED ALWAYS AS (total_cost + total_tax - total_discount) STORED,
    notes TEXT,
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'cancelled', 'completed') DEFAULT 'draft',
    approval_level INT DEFAULT 0,
    user_id INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    rejected_by INT UNSIGNED NULL,
    rejected_at TIMESTAMP NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_receipts_no (receipt_no),
    INDEX idx_receipts_warehouse (warehouse_id),
    INDEX idx_receipts_supplier (supplier_id),
    INDEX idx_receipts_date (receipt_date),
    INDEX idx_receipts_status (status),
    INDEX idx_receipts_po (po_number),
    INDEX idx_receipts_created (created_at),
    INDEX idx_receipts_approved (approved_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- تفاصيل إذن الاستلام
CREATE TABLE receipt_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    received_quantity DECIMAL(15,3) DEFAULT 0.000,
    unit_cost DECIMAL(15,3) NOT NULL,
    total_cost DECIMAL(15,3) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    tax_rate DECIMAL(5,2) DEFAULT 0.00,
    tax_amount DECIMAL(15,3) DEFAULT 0.000,
    discount_rate DECIMAL(5,2) DEFAULT 0.00,
    discount_amount DECIMAL(15,3) DEFAULT 0.000,
    net_cost DECIMAL(15,3) GENERATED ALWAYS AS (total_cost + tax_amount - discount_amount) STORED,
    batch_number VARCHAR(50) NULL,
    expiry_date DATE NULL,
    serial_numbers TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_receipt_items_receipt (receipt_id),
    INDEX idx_receipt_items_product (product_id),
    INDEX idx_receipt_items_batch (batch_number),
    INDEX idx_receipt_items_expiry (expiry_date)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول إذون الصرف المتقدم
CREATE TABLE issues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issue_no VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    issue_date DATE NOT NULL,
    issue_time TIME NOT NULL,
    required_date DATE NULL,
    delivered_date DATE NULL,
    reference_number VARCHAR(50) NULL,
    department VARCHAR(100) NULL,
    project_code VARCHAR(50) NULL,
    total_items INT DEFAULT 0,
    total_quantity DECIMAL(15,3) DEFAULT 0.000,
    total_cost DECIMAL(15,3) DEFAULT 0.000,
    notes TEXT,
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'cancelled', 'delivered') DEFAULT 'draft',
    user_id INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    rejected_by INT UNSIGNED NULL,
    rejected_at TIMESTAMP NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (recipient_id) REFERENCES recipients(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_issues_no (issue_no),
    INDEX idx_issues_warehouse (warehouse_id),
    INDEX idx_issues_recipient (recipient_id),
    INDEX idx_issues_date (issue_date),
    INDEX idx_issues_status (status),
    INDEX idx_issues_project (project_code),
    INDEX idx_issues_created (created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- تفاصيل إذن الصرف
CREATE TABLE issue_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issue_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    delivered_quantity DECIMAL(15,3) DEFAULT 0.000,
    unit_cost DECIMAL(15,3) NOT NULL,
    total_cost DECIMAL(15,3) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    batch_number VARCHAR(50) NULL,
    serial_numbers TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_issue_items_issue (issue_id),
    INDEX idx_issue_items_product (product_id),
    INDEX idx_issue_items_batch (batch_number)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 8. جداول التحويلات والمرتجعات
-- ================================================================

-- جدول التحويلات المتقدم
CREATE TABLE transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_no VARCHAR(50) NOT NULL UNIQUE,
    from_warehouse_id INT UNSIGNED NOT NULL,
    to_warehouse_id INT UNSIGNED NOT NULL,
    transfer_date DATE NOT NULL,
    transfer_time TIME NOT NULL,
    expected_date DATE NULL,
    delivered_date DATE NULL,
    total_items INT DEFAULT 0,
    total_quantity DECIMAL(15,3) DEFAULT 0.000,
    total_cost DECIMAL(15,3) DEFAULT 0.000,
    notes TEXT,
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'cancelled', 'completed') DEFAULT 'draft',
    user_id INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    rejected_by INT UNSIGNED NULL,
    rejected_at TIMESTAMP NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (to_warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_transfers_no (transfer_no),
    INDEX idx_transfers_from (from_warehouse_id),
    INDEX idx_transfers_to (to_warehouse_id),
    INDEX idx_transfers_date (transfer_date),
    INDEX idx_transfers_status (status),
    INDEX idx_transfers_created (created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- تفاصيل التحويل
CREATE TABLE transfer_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    unit_cost DECIMAL(15,3) NOT NULL,
    total_cost DECIMAL(15,3) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    batch_number VARCHAR(50) NULL,
    serial_numbers TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transfer_id) REFERENCES transfers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_transfer_items_transfer (transfer_id),
    INDEX idx_transfer_items_product (product_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول المرتجعات المتقدم
CREATE TABLE returns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_no VARCHAR(50) NOT NULL UNIQUE,
    return_type ENUM('to_supplier', 'from_customer', 'internal') NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    reference_type ENUM('receipt', 'issue', 'transfer') NOT NULL,
    reference_id BIGINT UNSIGNED NOT NULL,
    return_date DATE NOT NULL,
    return_time TIME NOT NULL,
    total_items INT DEFAULT 0,
    total_quantity DECIMAL(15,3) DEFAULT 0.000,
    total_cost DECIMAL(15,3) DEFAULT 0.000,
    reason TEXT,
    notes TEXT,
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'cancelled') DEFAULT 'draft',
    user_id INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    rejected_by INT UNSIGNED NULL,
    rejected_at TIMESTAMP NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_returns_no (return_no),
    INDEX idx_returns_warehouse (warehouse_id),
    INDEX idx_returns_reference (reference_type, reference_id),
    INDEX idx_returns_date (return_date),
    INDEX idx_returns_status (status),
    INDEX idx_returns_type (return_type)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- تفاصيل المرتجع
CREATE TABLE return_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    unit_cost DECIMAL(15,3) NOT NULL,
    total_cost DECIMAL(15,3) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    batch_number VARCHAR(50) NULL,
    serial_numbers TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_return_items_return (return_id),
    INDEX idx_return_items_product (product_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 9. جداول الجرد والتسويات المتقدمة
-- ================================================================

-- جلسات الجرد المتقدمة
CREATE TABLE inventory_counts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    count_no VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id INT UNSIGNED NOT NULL,
    count_date DATE NOT NULL,
    count_time TIME NOT NULL,
    count_type ENUM('full', 'partial', 'cycle', 'spot') DEFAULT 'full',
    total_items INT DEFAULT 0,
    total_differences INT DEFAULT 0,
    status ENUM('draft', 'in_progress', 'reviewed', 'approved', 'cancelled') DEFAULT 'draft',
    user_id INT UNSIGNED NOT NULL,
    supervisor_id INT UNSIGNED NULL,
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    notes TEXT,
    start_time TIMESTAMP NULL,
    end_time TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_inventory_warehouse (warehouse_id),
    INDEX idx_inventory_date (count_date),
    INDEX idx_inventory_status (status),
    INDEX idx_inventory_type (count_type),
    INDEX idx_inventory_user (user_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- تفاصيل الجرد
CREATE TABLE inventory_count_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_count_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    system_quantity DECIMAL(15,3) NOT NULL,
    actual_quantity DECIMAL(15,3) NOT NULL,
    difference DECIMAL(15,3) GENERATED ALWAYS AS (actual_quantity - system_quantity) STORED,
    unit_cost DECIMAL(15,3) DEFAULT 0.000,
    location VARCHAR(100) NULL,
    batch_number VARCHAR(50) NULL,
    counted_by INT UNSIGNED NULL,
    verified_by INT UNSIGNED NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (inventory_count_id) REFERENCES inventory_counts(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (counted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_inventory_items_count (inventory_count_id),
    INDEX idx_inventory_items_product (product_id),
    INDEX idx_inventory_items_location (location),
    INDEX idx_inventory_items_diff (difference)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول تسويات المخزون المتقدم
CREATE TABLE stock_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    adjustment_no VARCHAR(50) NOT NULL UNIQUE,
    warehouse_id INT UNSIGNED NOT NULL,
    adjustment_type ENUM('increase', 'decrease', 'write_off', 'write_in') NOT NULL,
    adjustment_date DATE NOT NULL,
    adjustment_time TIME NOT NULL,
    reason TEXT NOT NULL,
    total_items INT DEFAULT 0,
    total_quantity DECIMAL(15,3) DEFAULT 0.000,
    total_value DECIMAL(15,3) DEFAULT 0.000,
    notes TEXT,
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'cancelled') DEFAULT 'draft',
    user_id INT UNSIGNED NOT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    rejected_by INT UNSIGNED NULL,
    rejected_at TIMESTAMP NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_adjustments_warehouse (warehouse_id),
    INDEX idx_adjustments_date (adjustment_date),
    INDEX idx_adjustments_status (status),
    INDEX idx_adjustments_type (adjustment_type),
    INDEX idx_adjustments_created (created_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- تفاصيل التسوية
CREATE TABLE stock_adjustment_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    adjustment_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    unit_cost DECIMAL(15,3) DEFAULT 0.000,
    total_cost DECIMAL(15,3) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
    balance_before DECIMAL(15,3) NOT NULL,
    balance_after DECIMAL(15,3) NOT NULL,
    batch_number VARCHAR(50) NULL,
    location VARCHAR(100) NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (adjustment_id) REFERENCES stock_adjustments(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_adjustment_items_adjustment (adjustment_id),
    INDEX idx_adjustment_items_product (product_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 10. جداول المراقبة والتنبيهات المتقدمة
-- ================================================================

-- سجل التدقيق المتقدم (Audit Log)
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    username VARCHAR(50) NULL,
    session_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50) NOT NULL,
    sub_module VARCHAR(50) NULL,
    description TEXT,
    details JSON,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT,
    reference_type VARCHAR(50) NULL,
    reference_id VARCHAR(50) NULL,
    is_system BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (session_id) REFERENCES user_sessions(id) ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_module (module),
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_reference (reference_type, reference_id),
    INDEX idx_audit_session (session_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول التنبيهات المتقدم
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM(
        'low_stock',
        'out_of_stock',
        'over_stock',
        'expiry_alert',
        'system_warning',
        'transaction_alert',
        'approval_needed',
        'transfer_completed',
        'receipt_completed',
        'issue_completed',
        'inventory_completed'
    ) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    is_dismissed BOOLEAN DEFAULT FALSE,
    dismissed_at TIMESTAMP NULL,
    link VARCHAR(255) NULL,
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_read (is_read),
    INDEX idx_notifications_created (created_at),
    INDEX idx_notifications_priority (priority),
    INDEX idx_notifications_reference (reference_type, reference_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول إعدادات التنبيهات
CREATE TABLE notification_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    is_enabled BOOLEAN DEFAULT TRUE,
    is_email_enabled BOOLEAN DEFAULT FALSE,
    is_sms_enabled BOOLEAN DEFAULT FALSE,
    threshold_value DECIMAL(15,3) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_notification_setting (user_id, notification_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notification_settings_user (user_id),
    INDEX idx_notification_settings_type (notification_type)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 11. جداول النسخ الاحتياطي والإعدادات
-- ================================================================

-- سجل النسخ الاحتياطي المتقدم
CREATE TABLE backup_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backup_type ENUM('manual', 'automatic', 'pre_restore') NOT NULL,
    backup_file VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED DEFAULT 0,
    file_hash VARCHAR(64) NULL,
    status ENUM('pending', 'running', 'completed', 'failed', 'restored') NOT NULL,
    started_at TIMESTAMP NOT NULL,
    completed_at TIMESTAMP NULL,
    restored_at TIMESTAMP NULL,
    restored_by INT UNSIGNED NULL,
    restore_details JSON NULL,
    error_message TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_backup_type (backup_type),
    INDEX idx_backup_status (status),
    INDEX idx_backup_started (started_at),
    INDEX idx_backup_restored (restored_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول إعدادات النظام المتقدم
CREATE TABLE system_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_group VARCHAR(50) NOT NULL,
    setting_type ENUM('string', 'number', 'boolean', 'json', 'file') DEFAULT 'string',
    is_editable BOOLEAN DEFAULT TRUE,
    is_encrypted BOOLEAN DEFAULT FALSE,
    description TEXT,
    validation_rules TEXT NULL,
    sort_order INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_settings_key (setting_key),
    INDEX idx_settings_group (setting_group),
    INDEX idx_settings_type (setting_type),
    INDEX idx_settings_sort (sort_order)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 12. جداول الدعم والميزات الإضافية
-- ================================================================

-- جدول الأصناف مع تتبع الدفعات
CREATE TABLE product_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    batch_number VARCHAR(50) NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    unit_cost DECIMAL(15,3) NOT NULL,
    manufacture_date DATE NULL,
    expiry_date DATE NULL,
    received_at DATE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_batch (warehouse_id, batch_number),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    INDEX idx_batches_product (product_id),
    INDEX idx_batches_warehouse (warehouse_id),
    INDEX idx_batches_number (batch_number),
    INDEX idx_batches_expiry (expiry_date),
    INDEX idx_batches_active (is_active)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول تتبع الأرقام التسلسلية
CREATE TABLE product_serials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    serial_number VARCHAR(100) NOT NULL UNIQUE,
    status ENUM('in_stock', 'reserved', 'sold', 'returned', 'damaged', 'lost') DEFAULT 'in_stock',
    cost_price DECIMAL(15,3) NOT NULL,
    sale_price DECIMAL(15,3) NULL,
    purchase_date DATE NULL,
    sale_date DATE NULL,
    warranty_end_date DATE NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    INDEX idx_serials_product (product_id),
    INDEX idx_serials_warehouse (warehouse_id),
    INDEX idx_serials_number (serial_number),
    INDEX idx_serials_status (status),
    INDEX idx_serials_warranty (warranty_end_date)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- جدول حركات الدفعات (تتبع كل دفعة)
CREATE TABLE batch_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    movement_type ENUM('RECEIPT', 'ISSUE', 'TRANSFER_OUT', 'TRANSFER_IN') NOT NULL,
    reference_type VARCHAR(50) NOT NULL,
    reference_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(15,3) NOT NULL,
    balance_before DECIMAL(15,3) NOT NULL,
    balance_after DECIMAL(15,3) NOT NULL,
    movement_date DATETIME NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES product_batches(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_batch_movements_batch (batch_id),
    INDEX idx_batch_movements_product (product_id),
    INDEX idx_batch_movements_warehouse (warehouse_id),
    INDEX idx_batch_movements_reference (reference_type, reference_id),
    INDEX idx_batch_movements_date (movement_date)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

-- ================================================================
-- 13. العروض (Views) للتقارير السريعة
-- ================================================================

-- عرض الأرصدة المجمعة مع حالة المخزون
CREATE OR REPLACE VIEW v_product_balances AS
SELECT 
    p.id AS product_id,
    p.code,
    p.barcode,
    p.name,
    p.description,
    c.name AS category_name,
    u.name AS unit_name,
    w.id AS warehouse_id,
    w.name AS warehouse_name,
    COALESCE(sb.quantity, 0) AS balance,
    COALESCE(sb.reserved_quantity, 0) AS reserved,
    COALESCE(sb.quantity - sb.reserved_quantity, 0) AS available,
    p.min_stock,
    p.max_stock,
    p.reorder_point,
    p.cost_price,
    p.selling_price,
    CASE 
        WHEN COALESCE(sb.quantity, 0) <= 0 THEN 'out_of_stock'
        WHEN COALESCE(sb.quantity, 0) <= p.min_stock THEN 'low_stock'
        WHEN COALESCE(sb.quantity, 0) >= p.max_stock THEN 'over_stock'
        WHEN COALESCE(sb.quantity, 0) <= p.reorder_point THEN 'reorder'
        ELSE 'normal'
    END AS stock_status,
    CASE 
        WHEN COALESCE(sb.quantity, 0) <= 0 THEN '🔴'
        WHEN COALESCE(sb.quantity, 0) <= p.min_stock THEN '🟠'
        WHEN COALESCE(sb.quantity, 0) >= p.max_stock THEN '🔵'
        WHEN COALESCE(sb.quantity, 0) <= p.reorder_point THEN '🟡'
        ELSE '🟢'
    END AS status_icon
FROM products p
INNER JOIN units u ON u.id = p.unit_id
LEFT JOIN categories c ON c.id = p.category_id
CROSS JOIN warehouses w
LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = w.id
WHERE p.deleted_at IS NULL
  AND w.deleted_at IS NULL
  AND p.is_active = 1
  AND w.is_active = 1;

-- عرض آخر الحركات
CREATE OR REPLACE VIEW v_recent_movements AS
SELECT 
    sm.id,
    sm.movement_type,
    p.code AS product_code,
    p.name AS product_name,
    w.name AS warehouse_name,
    sm.quantity,
    sm.unit_cost,
    sm.total_cost,
    sm.balance_before,
    sm.balance_after,
    sm.movement_date,
    u.full_name AS user_name,
    sm.reference_type,
    sm.reference_id,
    CASE sm.movement_type
        WHEN 'RECEIPT' THEN '📥 استلام'
        WHEN 'ISSUE' THEN '📤 صرف'
        WHEN 'TRANSFER_OUT' THEN '🔄 تحويل خارج'
        WHEN 'TRANSFER_IN' THEN '🔄 تحويل داخل'
        WHEN 'RETURN_IN' THEN '↩️ مرتجع داخل'
        WHEN 'RETURN_OUT' THEN '↪️ مرتجع خارج'
        WHEN 'ADJUSTMENT' THEN '⚖️ تسوية'
        WHEN 'COUNT_CORRECTION' THEN '📊 تصحيح جرد'
        ELSE sm.movement_type
    END AS movement_label
FROM stock_movements sm
INNER JOIN products p ON p.id = sm.product_id
INNER JOIN warehouses w ON w.id = sm.warehouse_id
INNER JOIN users u ON u.id = sm.user_id
WHERE p.deleted_at IS NULL
  AND w.deleted_at IS NULL
ORDER BY sm.movement_date DESC
LIMIT 200;

-- عرض إحصائيات المخزون
CREATE OR REPLACE VIEW v_inventory_stats AS
SELECT 
    w.id AS warehouse_id,
    w.name AS warehouse_name,
    COUNT(DISTINCT p.id) AS total_products,
    COUNT(DISTINCT CASE WHEN sb.quantity > 0 THEN p.id END) AS products_in_stock,
    COUNT(DISTINCT CASE WHEN sb.quantity <= 0 THEN p.id END) AS out_of_stock,
    COUNT(DISTINCT CASE WHEN sb.quantity <= p.min_stock AND sb.quantity > 0 THEN p.id END) AS low_stock,
    COUNT(DISTINCT CASE WHEN sb.quantity >= p.max_stock THEN p.id END) AS over_stock,
    SUM(sb.quantity) AS total_quantity,
    SUM(sb.quantity * p.cost_price) AS total_value,
    SUM(CASE WHEN sb.quantity <= p.min_stock AND sb.quantity > 0 THEN 1 ELSE 0 END) AS low_stock_count
FROM warehouses w
CROSS JOIN products p
LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = w.id
WHERE p.deleted_at IS NULL
  AND w.deleted_at IS NULL
  AND p.is_active = 1
  AND w.is_active = 1
GROUP BY w.id, w.name;

-- عرض تقرير أداء المستخدمين
CREATE OR REPLACE VIEW v_user_performance AS
SELECT 
    u.id,
    u.username,
    u.full_name,
    r.name AS role_name,
    COUNT(DISTINCT sm.id) AS total_movements,
    COUNT(DISTINCT CASE WHEN sm.movement_type = 'RECEIPT' THEN sm.id END) AS total_receipts,
    COUNT(DISTINCT CASE WHEN sm.movement_type = 'ISSUE' THEN sm.id END) AS total_issues,
    COUNT(DISTINCT CASE WHEN sm.movement_type = 'TRANSFER_OUT' THEN sm.id END) AS total_transfers,
    COUNT(DISTINCT DATE(sm.movement_date)) AS active_days,
    MIN(sm.movement_date) AS first_activity,
    MAX(sm.movement_date) AS last_activity,
    u.last_login_at
FROM users u
LEFT JOIN roles r ON r.id = u.role_id
LEFT JOIN stock_movements sm ON sm.user_id = u.id
WHERE u.deleted_at IS NULL
  AND u.is_active = 1
GROUP BY u.id, u.username, u.full_name, r.name, u.last_login_at;

-- ================================================================
-- 14. المحفزات (Triggers) للتحديث التلقائي
-- ================================================================

-- محفز لتحديث الرصيد عند إضافة حركة
DELIMITER //

CREATE TRIGGER after_stock_movement_insert
AFTER INSERT ON stock_movements
FOR EACH ROW
BEGIN
    INSERT INTO stock_balances (product_id, warehouse_id, quantity, last_movement_id, last_movement_date, updated_at)
    VALUES (NEW.product_id, NEW.warehouse_id, NEW.balance_after, NEW.id, NEW.movement_date, NOW())
    ON DUPLICATE KEY UPDATE
        quantity = NEW.balance_after,
        last_movement_id = NEW.id,
        last_movement_date = NEW.movement_date,
        updated_at = NOW();
    
    -- تحديث حالة المخزون في المنتج (إذا لزم الأمر)
    UPDATE products 
    SET updated_at = NOW() 
    WHERE id = NEW.product_id;
    
    -- إنشاء تنبيه إذا كان المخزون منخفض
    IF NEW.balance_after <= (
        SELECT min_stock FROM products WHERE id = NEW.product_id
    ) AND NEW.balance_after > 0 THEN
        INSERT INTO notifications (user_id, type, title, message, priority, reference_type, reference_id)
        SELECT 
            u.id,
            'low_stock',
            CONCAT('تنبيه: مخزون منخفض - ', p.name),
            CONCAT('المنتج "', p.name, '" في المخزن "', w.name, '" وصل للحد الأدنى (', p.min_stock, '). الرصيد الحالي: ', NEW.balance_after),
            'high',
            'product',
            p.id
        FROM products p
        CROSS JOIN warehouses w
        INNER JOIN users u ON u.role_id IN (SELECT id FROM roles WHERE name IN ('admin', 'warehouse_manager'))
        WHERE p.id = NEW.product_id
          AND w.id = NEW.warehouse_id
          AND u.is_active = 1;
    END IF;
    
    -- إنشاء تنبيه إذا كان المخزون صفر
    IF NEW.balance_after = 0 THEN
        INSERT INTO notifications (user_id, type, title, message, priority, reference_type, reference_id)
        SELECT 
            u.id,
            'out_of_stock',
            CONCAT('⚠️ نفاذ المخزون - ', p.name),
            CONCAT('المنتج "', p.name, '" في المخزن "', w.name, '" نفد من المخزون. الرجاء إعادة التوريد.'),
            'critical',
            'product',
            p.id
        FROM products p
        CROSS JOIN warehouses w
        INNER JOIN users u ON u.role_id IN (SELECT id FROM roles WHERE name IN ('admin', 'warehouse_manager'))
        WHERE p.id = NEW.product_id
          AND w.id = NEW.warehouse_id
          AND u.is_active = 1;
    END IF;
END//

DELIMITER ;

-- ================================================================
-- 15. الإجراءات المخزنة (Stored Procedures) للتقارير
-- ================================================================

-- إجراء: تقرير حركة صنف بين تاريخين
DELIMITER //

CREATE PROCEDURE GetProductMovementsReport(
    IN p_product_id INT,
    IN p_warehouse_id INT,
    IN p_start_date DATE,
    IN p_end_date DATE
)
BEGIN
    SELECT 
        sm.id,
        sm.movement_type,
        CASE sm.movement_type
            WHEN 'RECEIPT' THEN 'استلام'
            WHEN 'ISSUE' THEN 'صرف'
            WHEN 'TRANSFER_OUT' THEN 'تحويل خارج'
            WHEN 'TRANSFER_IN' THEN 'تحويل داخل'
            WHEN 'RETURN_IN' THEN 'مرتجع'
            WHEN 'RETURN_OUT' THEN 'مرتجع خارج'
            WHEN 'ADJUSTMENT' THEN 'تسوية'
            ELSE sm.movement_type
        END AS movement_ar,
        sm.quantity,
        sm.unit_cost,
        sm.total_cost,
        sm.balance_before,
        sm.balance_after,
        sm.movement_date,
        u.full_name AS user_name,
        sm.reference_type,
        sm.reference_id,
        sm.notes
    FROM stock_movements sm
    INNER JOIN users u ON u.id = sm.user_id
    WHERE sm.product_id = p_product_id
      AND (p_warehouse_id IS NULL OR sm.warehouse_id = p_warehouse_id)
      AND DATE(sm.movement_date) BETWEEN p_start_date AND p_end_date
    ORDER BY sm.movement_date DESC;
END//

DELIMITER ;

-- إجراء: تقرير أرصدة المخازن
DELIMITER //

CREATE PROCEDURE GetWarehouseStockReport(
    IN p_warehouse_id INT
)
BEGIN
    SELECT 
        p.code,
        p.name,
        p.barcode,
        c.name AS category,
        u.name AS unit,
        COALESCE(sb.quantity, 0) AS balance,
        COALESCE(sb.reserved_quantity, 0) AS reserved,
        COALESCE(sb.quantity - sb.reserved_quantity, 0) AS available,
        p.min_stock,
        p.max_stock,
        p.cost_price,
        p.selling_price,
        COALESCE(sb.quantity * p.cost_price, 0) AS total_value,
        CASE 
            WHEN COALESCE(sb.quantity, 0) <= 0 THEN 'نفذ'
            WHEN COALESCE(sb.quantity, 0) <= p.min_stock THEN 'منخفض'
            WHEN COALESCE(sb.quantity, 0) >= p.max_stock THEN 'زائد'
            ELSE 'طبيعي'
        END AS stock_status
    FROM products p
    INNER JOIN units u ON u.id = p.unit_id
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = p_warehouse_id
    WHERE p.deleted_at IS NULL
      AND p.is_active = 1
    ORDER BY p.name;
END//

DELIMITER ;

-- إجراء: تقرير قيمة المخزون الإجمالية
DELIMITER //

CREATE PROCEDURE GetInventoryTotalValue()
BEGIN
    SELECT 
        w.id AS warehouse_id,
        w.name AS warehouse_name,
        COUNT(DISTINCT p.id) AS total_products,
        SUM(sb.quantity) AS total_quantity,
        SUM(sb.quantity * p.cost_price) AS total_value,
        SUM(sb.quantity * p.selling_price) AS total_sale_value,
        (SUM(sb.quantity * p.selling_price) - SUM(sb.quantity * p.cost_price)) AS potential_profit
    FROM warehouses w
    CROSS JOIN products p
    LEFT JOIN stock_balances sb ON sb.product_id = p.id AND sb.warehouse_id = w.id
    WHERE p.deleted_at IS NULL
      AND w.deleted_at IS NULL
      AND p.is_active = 1
      AND w.is_active = 1
    GROUP BY w.id, w.name
    ORDER BY total_value DESC;
END//

DELIMITER ;

-- ================================================================
-- 16. البيانات الأولية (Seed Data) للتشغيل الفوري
-- ================================================================

-- الأدوار الأساسية
INSERT INTO roles (name, display_name, description, is_system, level) VALUES
('admin', 'مدير النظام', 'صلاحية كاملة على النظام', TRUE, 1),
('warehouse_manager', 'مدير المخازن', 'يدير جميع المخازن والحركات', TRUE, 2),
('warehouse_supervisor', 'مشرف مخزن', 'يشرف على عمليات المخازن', TRUE, 3),
('warehouse_staff', 'موظف مخزن', 'صلاحيات محدودة في المخازن', TRUE, 4),
('viewer', 'مشاهدة فقط', 'يمكنه المشاهدة فقط دون إجراء عمليات', TRUE, 5),
('auditor', 'مدقق', 'صلاحيات المراجعة والتدقيق', TRUE, 3);

-- الصلاحيات الأساسية (كل الموديولات)
INSERT INTO permissions (name, display_name, module, sub_module, action, description) VALUES
-- الأصناف
('products.view', 'عرض الأصناف', 'products', NULL, 'view', 'مشاهدة الأصناف'),
('products.create', 'إنشاء صنف', 'products', NULL, 'create', 'إضافة صنف جديد'),
('products.edit', 'تعديل صنف', 'products', NULL, 'edit', 'تعديل بيانات الصنف'),
('products.delete', 'حذف صنف', 'products', NULL, 'delete', 'حذف أو تعطيل صنف'),
('products.export', 'تصدير الأصناف', 'products', NULL, 'export', 'تصدير الأصناف'),
('products.barcode', 'طباعة باركود', 'products', NULL, 'barcode', 'طباعة باركود'),

-- المخازن
('warehouses.view', 'عرض المخازن', 'warehouses', NULL, 'view', 'مشاهدة المخازن'),
('warehouses.create', 'إنشاء مخزن', 'warehouses', NULL, 'create', 'إضافة مخزن'),
('warehouses.edit', 'تعديل مخزن', 'warehouses', NULL, 'edit', 'تعديل مخزن'),
('warehouses.delete', 'حذف مخزن', 'warehouses', NULL, 'delete', 'حذف مخزن'),
('warehouses.export', 'تصدير المخازن', 'warehouses', NULL, 'export', 'تصدير البيانات'),

-- الاستلام
('receipts.view', 'عرض الاستلام', 'receipts', NULL, 'view', 'مشاهدة إذون الاستلام'),
('receipts.create', 'إنشاء استلام', 'receipts', NULL, 'create', 'إنشاء إذن استلام'),
('receipts.edit', 'تعديل استلام', 'receipts', NULL, 'edit', 'تعديل إذن استلام'),
('receipts.approve', 'اعتماد استلام', 'receipts', NULL, 'approve', 'اعتماد إذن الاستلام'),
('receipts.cancel', 'إلغاء استلام', 'receipts', NULL, 'cancel', 'إلغاء إذن استلام'),
('receipts.export', 'تصدير الاستلام', 'receipts', NULL, 'export', 'تصدير إذون الاستلام'),

-- الصرف
('issues.view', 'عرض الصرف', 'issues', NULL, 'view', 'مشاهدة إذون الصرف'),
('issues.create', 'إنشاء صرف', 'issues', NULL, 'create', 'إنشاء إذن صرف'),
('issues.edit', 'تعديل صرف', 'issues', NULL, 'edit', 'تعديل إذن صرف'),
('issues.approve', 'اعتماد صرف', 'issues', NULL, 'approve', 'اعتماد إذن الصرف'),
('issues.cancel', 'إلغاء صرف', 'issues', NULL, 'cancel', 'إلغاء إذن صرف'),
('issues.export', 'تصدير الصرف', 'issues', NULL, 'export', 'تصدير إذون الصرف'),

-- التحويل
('transfers.view', 'عرض التحويل', 'transfers', NULL, 'view', 'مشاهدة التحويلات'),
('transfers.create', 'إنشاء تحويل', 'transfers', NULL, 'create', 'إنشاء تحويل'),
('transfers.approve', 'اعتماد تحويل', 'transfers', NULL, 'approve', 'اعتماد التحويل'),
('transfers.cancel', 'إلغاء تحويل', 'transfers', NULL, 'cancel', 'إلغاء تحويل'),
('transfers.export', 'تصدير التحويل', 'transfers', NULL, 'export', 'تصدير التحويلات'),

-- المرتجع
('returns.view', 'عرض المرتجع', 'returns', NULL, 'view', 'مشاهدة المرتجعات'),
('returns.create', 'إنشاء مرتجع', 'returns', NULL, 'create', 'إنشاء مرتجع'),
('returns.approve', 'اعتماد مرتجع', 'returns', NULL, 'approve', 'اعتماد المرتجع'),
('returns.cancel', 'إلغاء مرتجع', 'returns', NULL, 'cancel', 'إلغاء مرتجع'),
('returns.export', 'تصدير المرتجع', 'returns', NULL, 'export', 'تصدير المرتجعات'),

-- الجرد
('inventory.view', 'عرض الجرد', 'inventory', NULL, 'view', 'مشاهدة جلسات الجرد'),
('inventory.create', 'إنشاء جرد', 'inventory', NULL, 'create', 'بدء جلسة جرد'),
('inventory.approve', 'اعتماد جرد', 'inventory', NULL, 'approve', 'اعتماد جلسة الجرد'),
('inventory.cancel', 'إلغاء جرد', 'inventory', NULL, 'cancel', 'إلغاء جلسة جرد'),
('inventory.export', 'تصدير الجرد', 'inventory', NULL, 'export', 'تصدير جلسات الجرد'),

-- التقارير
('reports.view', 'عرض التقارير', 'reports', NULL, 'view', 'مشاهدة جميع التقارير'),
('reports.export', 'تصدير التقارير', 'reports', NULL, 'export', 'تصدير التقارير'),
('reports.dashboard', 'لوحة التحكم', 'reports', NULL, 'dashboard', 'عرض لوحة التحكم'),
('reports.print', 'طباعة التقارير', 'reports', NULL, 'print', 'طباعة التقارير'),

-- المستخدمين
('users.view', 'عرض المستخدمين', 'users', NULL, 'view', 'مشاهدة المستخدمين'),
('users.create', 'إنشاء مستخدم', 'users', NULL, 'create', 'إضافة مستخدم'),
('users.edit', 'تعديل مستخدم', 'users', NULL, 'edit', 'تعديل مستخدم'),
('users.delete', 'حذف مستخدم', 'users', NULL, 'delete', 'حذف مستخدم'),
('users.permissions', 'إدارة صلاحيات', 'users', NULL, 'permissions', 'تعديل صلاحيات المستخدمين'),
('users.export', 'تصدير المستخدمين', 'users', NULL, 'export', 'تصدير بيانات المستخدمين'),

-- سجل التدقيق
('audit.view', 'عرض سجل التدقيق', 'audit', NULL, 'view', 'مشاهدة سجل التدقيق'),
('audit.export', 'تصدير سجل التدقيق', 'audit', NULL, 'export', 'تصدير سجل التدقيق'),

-- النسخ الاحتياطي
('backup.view', 'عرض النسخ الاحتياطي', 'backup', NULL, 'view', 'مشاهدة النسخ الاحتياطية'),
('backup.create', 'إنشاء نسخة احتياطية', 'backup', NULL, 'create', 'إنشاء نسخة احتياطية'),
('backup.restore', 'استعادة نسخة احتياطية', 'backup', NULL, 'restore', 'استعادة قاعدة البيانات'),
('backup.export', 'تصدير النسخ', 'backup', NULL, 'export', 'تصدير النسخ الاحتياطية'),
('backup.schedule', 'جدولة النسخ', 'backup', NULL, 'schedule', 'إدارة جدولة النسخ الاحتياطي'),

-- إعدادات النظام
('settings.view', 'عرض الإعدادات', 'settings', NULL, 'view', 'مشاهدة إعدادات النظام'),
('settings.edit', 'تعديل الإعدادات', 'settings', NULL, 'edit', 'تعديل إعدادات النظام');

-- صلاحيات الأدوار (Admin)
INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'admin'),
    id
FROM permissions;

-- صلاحيات مدير المخازن
INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'warehouse_manager'),
    id
FROM permissions
WHERE name IN (
    'products.view', 'products.create', 'products.edit', 'products.export', 'products.barcode',
    'warehouses.view', 'warehouses.create', 'warehouses.edit', 'warehouses.export',
    'receipts.view', 'receipts.create', 'receipts.edit', 'receipts.approve', 'receipts.cancel', 'receipts.export',
    'issues.view', 'issues.create', 'issues.edit', 'issues.approve', 'issues.cancel', 'issues.export',
    'transfers.view', 'transfers.create', 'transfers.approve', 'transfers.cancel', 'transfers.export',
    'returns.view', 'returns.create', 'returns.approve', 'returns.cancel', 'returns.export',
    'inventory.view', 'inventory.create', 'inventory.approve', 'inventory.export',
    'reports.view', 'reports.export', 'reports.dashboard', 'reports.print',
    'audit.view', 'audit.export'
);

-- صلاحيات مشرف مخزن
INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'warehouse_supervisor'),
    id
FROM permissions
WHERE name IN (
    'products.view', 'products.create', 'products.edit',
    'warehouses.view',
    'receipts.view', 'receipts.create', 'receipts.edit', 'receipts.approve',
    'issues.view', 'issues.create', 'issues.edit', 'issues.approve',
    'transfers.view', 'transfers.create', 'transfers.approve',
    'returns.view', 'returns.create', 'returns.approve',
    'inventory.view', 'inventory.create', 'inventory.approve',
    'reports.view', 'reports.export', 'reports.dashboard'
);

-- صلاحيات موظف مخزن
INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'warehouse_staff'),
    id
FROM permissions
WHERE name IN (
    'products.view',
    'warehouses.view',
    'receipts.view', 'receipts.create',
    'issues.view', 'issues.create',
    'transfers.view', 'transfers.create',
    'returns.view', 'returns.create',
    'inventory.view', 'inventory.create',
    'reports.view', 'reports.dashboard'
);

-- صلاحيات المشاهدة فقط
INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'viewer'),
    id
FROM permissions
WHERE name IN (
    'products.view',
    'warehouses.view',
    'receipts.view',
    'issues.view',
    'transfers.view',
    'returns.view',
    'inventory.view',
    'reports.view'
);

-- صلاحيات المدقق
INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE name = 'auditor'),
    id
FROM permissions
WHERE name IN (
    'products.view',
    'warehouses.view',
    'receipts.view',
    'issues.view',
    'transfers.view',
    'returns.view',
    'inventory.view',
    'reports.view', 'reports.export', 'reports.print',
    'audit.view', 'audit.export'
);

-- ================================================================
-- 17. المستخدمون الافتراضيون
-- ================================================================

-- كلمة المرور لجميع المستخدمين: password
INSERT INTO users (username, email, password_hash, full_name, role_id, is_active, is_verified) VALUES
('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مدير النظام', 
    (SELECT id FROM roles WHERE name = 'admin'), TRUE, TRUE),
('manager', 'manager@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مدير المخازن', 
    (SELECT id FROM roles WHERE name = 'warehouse_manager'), TRUE, TRUE),
('supervisor', 'supervisor@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مشرف مخزن', 
    (SELECT id FROM roles WHERE name = 'warehouse_supervisor'), TRUE, TRUE),
('staff', 'staff@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'موظف مخزن', 
    (SELECT id FROM roles WHERE name = 'warehouse_staff'), TRUE, TRUE),
('viewer', 'viewer@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مستخدم مشاهدة', 
    (SELECT id FROM roles WHERE name = 'viewer'), TRUE, TRUE),
('auditor', 'auditor@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مدقق', 
    (SELECT id FROM roles WHERE name = 'auditor'), TRUE, TRUE);

-- ================================================================
-- 18. إعدادات النظام الافتراضية
-- ================================================================

INSERT INTO system_settings (setting_key, setting_value, setting_group, setting_type, description) VALUES
('company_name', 'شركة المخازن المتطورة', 'general', 'string', 'اسم الشركة'),
('company_logo', '/assets/images/logo.png', 'general', 'file', 'شعار الشركة'),
('company_address', 'الرياض - المملكة العربية السعودية', 'general', 'string', 'عنوان الشركة'),
('company_phone', '+966 11 222 3333', 'general', 'string', 'رقم هاتف الشركة'),
('company_email', 'info@company.com', 'general', 'string', 'البريد الإلكتروني للشركة'),
('company_tax_number', '3012345678', 'general', 'string', 'الرقم الضريبي'),
('timezone', 'Asia/Riyadh', 'general', 'string', 'المنطقة الزمنية'),
('date_format', 'Y-m-d', 'general', 'string', 'تنسيق التاريخ'),
('time_format', 'H:i:s', 'general', 'string', 'تنسيق الوقت'),
('currency', 'SAR', 'general', 'string', 'العملة الأساسية'),
('currency_symbol', 'ر.س', 'general', 'string', 'رمز العملة'),
('decimal_places', '2', 'general', 'number', 'عدد الأرقام العشرية'),
('thousands_separator', ',', 'general', 'string', 'فاصل الآلاف'),

-- إعدادات المخزون
('default_warehouse', '1', 'inventory', 'number', 'المخزن الافتراضي'),
('low_stock_threshold', '10', 'inventory', 'number', 'نسبة تنبيه المخزون المنخفض'),
('auto_reorder', 'true', 'inventory', 'boolean', 'إعادة الطلب التلقائي'),
('negative_stock_allowed', 'false', 'inventory', 'boolean', 'السماح بالمخزون السلبي'),

-- إعدادات النسخ الاحتياطي
('auto_backup_enabled', 'true', 'backup', 'boolean', 'تفعيل النسخ الاحتياطي التلقائي'),
('auto_backup_time', '23:00', 'backup', 'string', 'وقت النسخ الاحتياطي التلقائي'),
('backup_retention_days', '30', 'backup', 'number', 'عدد أيام الاحتفاظ بالنسخ الاحتياطية'),
('backup_path', '/var/backups/inventory/', 'backup', 'string', 'مسار النسخ الاحتياطي'),
('backup_compress', 'true', 'backup', 'boolean', 'ضغط النسخ الاحتياطية'),

-- إعدادات الأمان
('single_session_enabled', 'true', 'security', 'boolean', 'تفعيل الجلسة الواحدة'),
('session_timeout', '3600', 'security', 'number', 'مدة انتهاء الجلسة بالثواني'),
('max_login_attempts', '5', 'security', 'number', 'عدد محاولات الدخول الفاشلة'),
('lockout_duration', '30', 'security', 'number', 'مدة قفل الحساب بالدقائق'),
('password_expiry_days', '90', 'security', 'number', 'عدد أيام صلاحية كلمة المرور'),
('force_ssl', 'true', 'security', 'boolean', 'فرض استخدام SSL'),
('csrf_protection', 'true', 'security', 'boolean', 'تفعيل حماية CSRF'),

-- إعدادات البريد الإلكتروني
('mail_host', 'smtp.gmail.com', 'mail', 'string', 'خادم البريد'),
('mail_port', '587', 'mail', 'number', 'منفذ البريد'),
('mail_username', '', 'mail', 'string', 'اسم المستخدم للبريد'),
('mail_password', '', 'mail', 'string', 'كلمة مرور البريد'),
('mail_encryption', 'tls', 'mail', 'string', 'نوع التشفير'),
('mail_from_address', 'noreply@company.com', 'mail', 'string', 'بريد المرسل'),
('mail_from_name', 'نظام المخازن', 'mail', 'string', 'اسم المرسل'),

-- إعدادات التقارير
('report_logo', '/assets/images/logo.png', 'report', 'file', 'شعار التقارير'),
('report_footer', 'نظام إدارة المخازن - جميع الحقوق محفوظة', 'report', 'string', 'تذييل التقارير'),
('report_page_size', 'A4', 'report', 'string', 'حجم صفحة التقارير'),

-- إعدادات النظام
('maintenance_mode', 'false', 'system', 'boolean', 'وضع الصيانة'),
('debug_mode', 'false', 'system', 'boolean', 'وضع التصحيح'),
('version', '2.0.0', 'system', 'string', 'إصدار النظام'),
('last_updated', NOW(), 'system', 'string', 'آخر تحديث');

-- ================================================================
-- 19. عرض معلومات النظام
-- ================================================================

SELECT '✅ نظام إدارة المخازن والمخزون - جاهز للتشغيل' AS status;
SELECT 
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'inventory_system') AS total_tables,
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'inventory_system') AS total_columns,
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'inventory_system' AND table_type = 'VIEW') AS total_views,
    (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = 'inventory_system') AS total_triggers,
    (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = 'inventory_system') AS total_procedures;

-- ================================================================
-- انتهى ملف قاعدة البيانات
-- ================================================================

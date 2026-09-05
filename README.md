# Logistox

## Advanced Inventory & Warehouse Management System

<div align="center">

**نظام Logistox المتقدم لإدارة المخازن والمخزون**

[![Version](https://img.shields.io/badge/version-5.0.0-blue.svg)](#)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](#)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1.svg)](#)
[![Architecture](https://img.shields.io/badge/Architecture-Modular-success.svg)](#architecture)
[![Interface](https://img.shields.io/badge/Interface-Arabic%20RTL-informational.svg)](#)
[![Deployment](https://img.shields.io/badge/Deployment-Linux%20%7C%20Windows-lightgrey.svg)](#installation)
[![Network](https://img.shields.io/badge/Network-LAN%20Ready-success.svg)](#lan-deployment)
[![License](https://img.shields.io/badge/License-Proprietary-red.svg)](#license)

**Arabic RTL • Responsive • Multi-Warehouse • Permission-Based**

</div>

---

## Navigation | التنقل

* [Overview | نظرة عامة](#overview--نظرة-عامة)
* [Key Features | المميزات](#key-features--المميزات)
* [Architecture](#architecture)
* [Inventory Workflow | دورة المخزون](#inventory-workflow--دورة-المخزون)
* [Requirements | المتطلبات](#requirements--المتطلبات)
* [Installation | التثبيت](#installation--التثبيت)
* [Linux](#linux)
* [Windows](#windows)
* [Apache Configuration](#apache-configuration)
* [PHP Configuration](#php-configuration)
* [MySQL Configuration](#mysql--database-configuration)
* [Database Setup](#database-setup)
* [LAN Deployment](#lan-deployment)
* [API](#api)
* [Security](#security)
* [Production Checklist](#production-checklist)
* [Backup & Recovery](#backup--recovery)
* [Development Guidelines](#development-guidelines)
* [Troubleshooting](#troubleshooting)
* [Roadmap](#roadmap)
* [Version History](#version-history)
* [Project Information](#project-information)
* [License](#license)
* [Support](#support)

---

# Overview | نظرة عامة

**Logistox** is an advanced Inventory & Warehouse Management System designed to centralize warehouse operations, stock balances, inventory movements, users, permissions, notifications, auditing, and operational reporting.

**Logistox** هو نظام متقدم لإدارة المخازن والمخزون، يهدف إلى توفير منصة مركزية لإدارة الأصناف والمخازن والأرصدة والحركات والصلاحيات والتقارير وسجل العمليات.

The system is designed for **LAN-based organizational environments**, with a modular structure suitable for deployment on Linux and Windows.

تم تصميم النظام ليكون مناسبًا للعمل داخل **الشبكات المحلية LAN** مع بنية قابلة للتوسع والتطوير.

### Primary Objectives | الأهداف الرئيسية

* Centralized inventory management | إدارة مركزية للمخزون.
* Multi-warehouse management | إدارة عدة مخازن.
* Product and category management | إدارة الأصناف والمجموعات.
* Unit management | إدارة وحدات القياس.
* Stock balance tracking | متابعة الأرصدة.
* Receiving, issuing and transfers | الاستلام والصرف والتحويل.
* Returns and inventory counting | المرتجعات والجرد.
* Stock reconciliation | تسوية فروق الجرد.
* User and permission management | إدارة المستخدمين والصلاحيات.
* Audit logging | تسجيل العمليات المهمة.
* Low-stock notifications | التنبيهات الخاصة بالمخزون المنخفض.
* Operational reporting | التقارير التشغيلية والإدارية.
* LAN deployment | التشغيل داخل الشبكة المحلية.
* API-based architecture | بنية تعتمد على API.

---

# Key Features | المميزات الرئيسية

## Product Management | إدارة الأصناف

* Create products | إنشاء الأصناف.
* Update products | تعديل الأصناف.
* Delete products according to permissions | حذف الأصناف وفق الصلاحيات.
* Product search | البحث عن الأصناف.
* Product categorization | تصنيف الأصناف.
* Product codes | أكواد الأصناف.
* Unit assignment | ربط الصنف بوحدة قياس.
* Barcode support where implemented | دعم Barcode حسب التنفيذ الفعلي.
* Product status.
* Product movement history | سجل حركة الصنف.

---

## Warehouse Management | إدارة المخازن

* Multiple warehouses | مخازن متعددة.
* Create / Update / Delete | إنشاء / تعديل / حذف.
* Hierarchical warehouse structure | هيكل هرمي للمخازن.
* Warehouse responsibility assignment.
* Warehouse stock balances.
* Consolidated stock view.
* Detailed stock view.
* Warehouse movement history.

### Stock Views | طرق عرض الأرصدة

**Consolidated View — عرض مجمع**

يعرض إجمالي رصيد الصنف عبر جميع المخازن.

**Detailed View — عرض تفصيلي**

يعرض رصيد الصنف موزعًا حسب كل مخزن.

---

# Inventory Operations | العمليات المخزنية

## Goods Receipt | إذن استلام

تسجيل الأصناف الواردة إلى المخزن مع حفظ تفاصيل العملية.

## Goods Issue | إذن صرف

تسجيل الأصناف الخارجة من المخزن.

## Stock Transfer | تحويل مخزني

نقل الأصناف من مخزن إلى مخزن آخر مع تسجيل المصدر والوجهة.

## Returns | المرتجعات

تسجيل عمليات إرجاع الأصناف.

## Inventory Count | الجرد

مقارنة الكمية الفعلية الموجودة بالمخزن مع الكمية المسجلة بالنظام.

## Stock Adjustment | التسوية

معالجة فروق الجرد من خلال عملية موثقة ومصرح بها.

---

# Units | وحدات القياس

يدعم النظام مفهوم وحدات القياس للأصناف، مثل:

* Piece — قطعة
* Carton — كرتونة
* Kilogram — كيلو
* Gram — جرام
* Liter — لتر
* Box — صندوق
* Unit — وحدة

ويمكن إضافة وحدات أخرى طبقًا لطبيعة النشاط.

---

# Users & Permissions | المستخدمون والصلاحيات

يعتمد النظام على مفهوم **Role-Based Access Control — RBAC**.

### Permission Types

| Permission            | الوظيفة           |
| --------------------- | ----------------- |
| View                  | عرض               |
| Create                | إضافة             |
| Update                | تعديل             |
| Delete                | حذف               |
| Approve               | اعتماد            |
| Print                 | طباعة             |
| Export                | تصدير             |
| User Management       | إدارة المستخدمين  |
| Permission Management | إدارة الصلاحيات   |
| Warehouse Management  | إدارة المخازن     |
| Product Management    | إدارة الأصناف     |
| Inventory Operations  | العمليات المخزنية |
| Reports               | التقارير          |
| System Settings       | إعدادات النظام    |

### Example Roles

```text
Administrator
Manager
Supervisor
Staff
Viewer
Auditor
```

يمكن تخصيص الصلاحيات حسب سياسة المؤسسة.

---

# Dashboard | لوحة التحكم

يمكن أن تعرض لوحة التحكم:

* إجمالي الأصناف.
* عدد المخازن.
* ملخص الأرصدة.
* آخر الحركات.
* الأصناف منخفضة المخزون.
* التنبيهات.
* نشاط المستخدمين.
* إحصائيات النظام.

---

# Advanced Search | البحث المتقدم

يدعم التصميم البحث والتصفية باستخدام عدة معايير، مثل:

* Product Code
* Product Name
* Warehouse
* Movement Type
* User
* Date
* Document Number
* Document Status

---

# Reports | التقارير

التقارير مصممة لتكون مناسبة للاستخدام الإداري والمخزني والطباعة الرسمية.

### Core Reports

| Report             | التقرير                |
| ------------------ | ---------------------- |
| Current Stock      | أرصدة المخزون          |
| Product Movement   | حركة صنف               |
| Warehouse Movement | حركة مخزن              |
| Receiving          | الاستلام               |
| Issuing            | الصرف                  |
| Transfers          | التحويلات              |
| Returns            | المرتجعات              |
| Inventory Count    | الجرد                  |
| Stock Adjustment   | فروق وتسويات الجرد     |
| Low Stock          | الأصناف منخفضة المخزون |
| User Activity      | نشاط المستخدمين        |
| Audit Log          | سجل العمليات           |

### Output Formats

حسب المكونات الفعلية المتوفرة في الإصدار:

```text
Print
PDF
Excel
CSV
```

> يجب اعتبار إمكانيات التصدير الفعلية الموجودة في نسخة المشروع هي المرجع النهائي.

---

# Notifications | الإشعارات

يمكن للنظام التعامل مع أنواع مختلفة من التنبيهات، مثل:

* Low Stock.
* Critical Stock.
* Operational Notifications.
* System Notifications.
* Administrative Notifications.

---

# Architecture

يعتمد Logistox على بنية Modular تجمع بين:

```text
┌───────────────────────────────────────┐
│              Client Layer             │
│        Browser / Desktop / Mobile     │
└───────────────────┬───────────────────┘
                    │
                    ▼
┌───────────────────────────────────────┐
│             Frontend Layer            │
│        HTML / CSS / JavaScript        │
│             Arabic / RTL              │
└───────────────────┬───────────────────┘
                    │ HTTP / API
                    ▼
┌───────────────────────────────────────┐
│              API Layer                │
│          Routing / Requests           │
│        Authentication / Access        │
└───────────────────┬───────────────────┘
                    │
                    ▼
┌───────────────────────────────────────┐
│             Backend Layer             │
│                PHP                    │
│       Business Logic / Services       │
│        Validation / Permissions       │
└───────────────────┬───────────────────┘
                    │ PDO / SQL
                    ▼
┌───────────────────────────────────────┐
│             Database Layer             │
│             MySQL / MariaDB           │
│                                       │
│ Products • Warehouses • Stock         │
│ Movements • Users • Permissions       │
│ Audit Logs • Notifications            │
└───────────────────────────────────────┘
```

### Logical Components

```text
Frontend
   │
   ├── Authentication
   ├── Dashboard
   ├── Products
   ├── Warehouses
   ├── Inventory
   ├── Reports
   └── Administration
            │
            ▼
         REST/API
            │
            ▼
         Backend
            │
     ┌──────┴──────┐
     ▼             ▼
 Business Logic   Security
     │             │
     └──────┬──────┘
            ▼
          MySQL
```

---

# Inventory Workflow | دورة المخزون

يجب أن تكون الأرصدة مبنية على حركات يمكن تتبعها.

```text
Opening Balance
       │
       ▼
   Receipt
       │
       ▼
 Stock Balance
       │
 ┌─────┼─────────────┐
 ▼     ▼             ▼
Issue Transfer      Return
 │       │             │
 └───────┴──────┬──────┘
                ▼
          Current Balance
```

### Inventory Integrity Rule

لا يجب تعديل الرصيد بشكل يدوي غير موثق.

أي تغيير مؤثر على المخزون يجب أن يرتبط بحركة أو عملية جرد/تسوية معتمدة.

---

# Database Design

المجالات الأساسية في قاعدة البيانات تشمل:

```text
Users
Roles
Permissions

Products
Categories
Units

Warehouses
Stock
Movements

Receipts
Issues
Transfers
Returns

Inventory Counts
Notifications
Audit Logs
```

> **Database Schema هو المرجع النهائي**. يجب عدم افتراض وجود جدول أو عمود لمجرد ظهوره في هذا التوثيق.

---

# Audit Logging

العمليات الحساسة يجب أن تكون قابلة للتتبع.

### Typical Audit Data

```text
User
Action
Module
Record ID
Date / Time
IP Address
Result
```

يساعد ذلك في:

* المراجعة.
* تتبع الأخطاء.
* معرفة المستخدم الذي نفذ العملية.
* التحقيق في العمليات غير المعتادة.
* مراقبة النشاط الإداري.

---

# Requirements | المتطلبات

## Server

| Component | Minimum            |
| --------- | ------------------ |
| PHP       | 8.2+               |
| MySQL     | 8.0+               |
| MariaDB   | Compatible version |
| Apache    | 2.4+               |
| Nginx     | PHP-FPM compatible |

## PHP Extensions

```text
PDO
pdo_mysql
mbstring
json
openssl
session
fileinfo
```

> المتطلبات النهائية تعتمد على الإصدار الفعلي والمكونات المستخدمة في المشروع.

---

# Installation | التثبيت

## 1. Clone Repository

```bash
git clone https://github.com/burnmywallet/Stock-Movement.git
cd Stock-Movement
```

---

# Linux

## Debian / Ubuntu / Kali

Update package lists:

```bash
sudo apt update
```

Install PHP:

```bash
sudo apt install php php-cli php-mysql php-mbstring php-curl php-xml php-zip php-gd
```

Install MySQL:

```bash
sudo apt install mysql-server
```

Install Apache:

```bash
sudo apt install apache2 libapache2-mod-php
```

Verify:

```bash
php -v
mysql --version
apache2 -v
```

Enable Apache:

```bash
sudo systemctl enable apache2
sudo systemctl start apache2
```

---

## Linux Application Startup

If the project includes `run.sh`:

```bash
chmod +x run.sh
```

Start:

```bash
./run.sh start
```

Status:

```bash
./run.sh status
```

Logs:

```bash
./run.sh tail
```

Restart:

```bash
./run.sh restart
```

Stop:

```bash
./run.sh stop
```

Backup:

```bash
./run.sh backup
```

---

# Windows

## Required Components

Install:

1. PHP 8.2+
2. MySQL 8.0+
3. Apache 2.4+
4. Git
5. Optional: Composer if required by the installed project version.

### Verify PHP

Open PowerShell:

```powershell
php -v
```

### Verify MySQL

```powershell
mysql --version
```

### Clone

```powershell
git clone https://github.com/burnmywallet/Stock-Movement.git
cd Stock-Movement
```

### PHP Built-in Development Server

For development/testing only:

```powershell
php -S 127.0.0.1:8080 -t backend/public
```

Then open:

```text
http://127.0.0.1:8080/
```

> For production or a permanent LAN installation on Windows, Apache/IIS should be configured as the web server instead of relying on PHP's built-in development server.

---

# MySQL & Database Configuration

## Create Database

```sql
CREATE DATABASE logistox
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

## Create Dedicated Database User

Do not use the MySQL root account for the application.

Example:

```sql
CREATE USER 'logistox_app'@'localhost'
IDENTIFIED BY 'CHANGE_THIS_PASSWORD';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX
ON logistox.*
TO 'logistox_app'@'localhost';

FLUSH PRIVILEGES;
```

> Adjust privileges according to the actual migrations/install process of the project.

---

# Database Setup

After creating the database, import the project's actual SQL/schema file.

Example:

```bash
mysql -u logistox_app -p logistox < database.sql
```

Verify:

```bash
mysql -u logistox_app -p logistox
```

Then:

```sql
SHOW TABLES;
```

> Replace `database.sql` with the actual SQL file included in the project.

---

# Application Configuration

Use environment-specific configuration.

Example:

```env
APP_ENV=production
APP_DEBUG=false

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=logistox
DB_USERNAME=logistox_app
DB_PASSWORD=CHANGE_THIS_PASSWORD
```

### Important

Never commit credentials to Git.

```text
.env
```

should be excluded through:

```text
.gitignore
```

---

# Apache Configuration

The public web root should point to:

```text
backend/public
```

Example Apache VirtualHost:

```apache
<VirtualHost *:80>
    ServerName logistox.local

    DocumentRoot /var/www/html/inventory-system/backend/public

    <Directory /var/www/html/inventory-system/backend/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/logistox_error.log
    CustomLog ${APACHE_LOG_DIR}/logistox_access.log combined
</VirtualHost>
```

Enable required Apache modules:

```bash
sudo a2enmod rewrite
sudo a2enmod headers
```

Enable the site:

```bash
sudo a2ensite logistox.conf
```

Check configuration:

```bash
sudo apache2ctl configtest
```

Expected:

```text
Syntax OK
```

Restart:

```bash
sudo systemctl restart apache2
```

Check status:

```bash
sudo systemctl status apache2
```

---

# PHP Configuration

Check the active configuration:

```bash
php --ini
```

Check loaded modules:

```bash
php -m
```

Confirm important extensions:

```bash
php -m | grep -E 'PDO|pdo_mysql|mbstring|openssl|fileinfo'
```

Recommended production settings:

```ini
display_errors = Off
log_errors = On
expose_php = Off
```

Do not enable verbose error output in production.

---

# LAN Deployment

Logistox can be deployed inside a company LAN.

Example server:

```text
192.168.1.8
```

Users can access the application through the web server:

```text
http://192.168.1.8/
```

### Recommended LAN Architecture

```text
                 Company LAN
                      │
       ┌──────────────┼──────────────┐
       │              │              │
       ▼              ▼              ▼
    Client 1       Client 2       Client 3
       │              │              │
       └──────────────┼──────────────┘
                      ▼
              Apache / Web Server
                      │
                      ▼
                 Logistox API
                      │
                      ▼
                   MySQL
```

### LAN Security Rules

* Use a static server IP.
* Restrict firewall access.
* Do not expose MySQL directly to client devices.
* Users should communicate with the application through HTTP/HTTPS.
* Use a dedicated database account.
* Restrict administrative access.
* Enable HTTPS where appropriate.

---

# API

Logistox exposes a backend API for frontend communication and future integrations.

## Health / Test Endpoint

```http
GET /api/test
```

Example response:

```json
{
    "success": true,
    "version": "5.0.0"
}
```

## API Domains

The current documentation groups the API around domains such as:

```text
/api/auth
/api/products
/api/warehouses
/api/units
/api/movements
/api/receipts
/api/issues
/api/transfers
/api/returns
/api/inventory
/api/reports
/api/users
/api/permissions
```

> The **actual Router/Routes implementation is authoritative**. Do not treat every domain above as proof that every endpoint currently exists.

## API Testing

Example:

```bash
curl -i http://127.0.0.1:8080/api/test
```

Expected successful response:

```json
{
    "success": true,
    "version": "5.0.0"
}
```

---

# Security

Security should be applied at both application and infrastructure levels.

## Application Security

* Password hashing.
* Prepared SQL statements.
* Input validation.
* Output escaping.
* CSRF protection where applicable.
* Session management.
* Role-based authorization.
* Permission checks.
* Audit logging.
* Restricted sensitive operations.
* Configurable CORS.
* Security headers.

## Database Security

* Dedicated application database user.
* Least-privilege permissions.
* No root credentials in application configuration.
* No public database exposure.
* Regular backups.
* Tested restore procedures.

## Server Security

* Firewall enabled.
* Unnecessary ports closed.
* Apache/Nginx hardened.
* Debug disabled.
* Sensitive files protected.
* HTTPS enabled when required.
* Logs monitored.

---

# Production Checklist

Before deploying to a real environment:

```text
[ ] Disable APP_DEBUG
[ ] Change default credentials
[ ] Create dedicated database user
[ ] Protect .env / configuration files
[ ] Verify file permissions
[ ] Configure firewall
[ ] Configure Apache/Nginx
[ ] Enable HTTPS where appropriate
[ ] Configure database backup
[ ] Test database restore
[ ] Review user roles
[ ] Review permissions
[ ] Test authentication
[ ] Test receiving
[ ] Test issuing
[ ] Test transfers
[ ] Test returns
[ ] Test inventory counting
[ ] Test stock adjustments
[ ] Test audit logging
[ ] Review application logs
[ ] Review Apache logs
[ ] Verify LAN access
[ ] Verify database is not publicly exposed
```

---

# Backup & Recovery

A production inventory system should maintain a tested backup policy.

Recommended strategy:

```text
Application
    │
    ▼
Database Backup
    │
    ├── Daily Backup
    ├── Retention Policy
    ├── Timestamped Files
    └── Separate Storage
```

Example MySQL backup:

```bash
mysqldump -u logistox_app -p logistox > logistox_backup.sql
```

Restore:

```bash
mysql -u logistox_app -p logistox < logistox_backup.sql
```

### Backup Rule

A backup is not considered reliable until a restore test has been successfully completed.

---

# Audit & Inventory Integrity

Inventory data should follow a traceable operational model:

```text
Transaction
     │
     ├── Create Movement
     │
     ├── Update Stock
     │
     └── Create Audit Log
             │
             ▼
           COMMIT
```

If any required operation fails:

```text
ROLLBACK
```

This prevents partially completed inventory transactions from leaving inconsistent database state.

---

# Development Guidelines

When developing or extending Logistox:

1. Keep business logic separated from presentation.
2. Validate all external input.
3. Use prepared SQL statements.
4. Check permissions before sensitive operations.
5. Log important administrative operations.
6. Use database transactions for multi-step inventory operations.
7. Never change stock without a traceable operation.
8. Never store plain-text passwords.
9. Never commit `.env`.
10. Test API endpoints before frontend integration.
11. Keep the database schema authoritative.
12. Avoid duplicating business logic between frontend and backend.

---

# Project Structure

The project structure may vary between releases.

Typical structure:

```text
inventory-system/
│
├── backend/
│   ├── core/
│   ├── routes/
│   ├── public/
│   └── ...
│
├── frontend/
│   ├── assets/
│   ├── css/
│   ├── js/
│   ├── login.html
│   ├── dashboard.html
│   └── ...
│
├── database/
│   └── ...
│
├── config/
│   └── ...
│
├── scripts/
│   └── ...
│
├── run.sh
├── .env.example
├── .gitignore
└── README.md
```

---

# Troubleshooting

## PHP

```bash
php -v
```

## PHP Extensions

```bash
php -m
```

## Apache Configuration

```bash
sudo apache2ctl configtest
```

## Apache Status

```bash
sudo systemctl status apache2
```

## Listening Ports

```bash
sudo ss -tulpn
```

## API Test

```bash
curl -i http://127.0.0.1:8080/api/test
```

## Apache Logs

```bash
sudo tail -f /var/log/apache2/error.log
```

## Application Logs

```bash
./run.sh tail
```

---

# Roadmap

The roadmap below describes the intended development direction and should not be interpreted as proof that every item is already implemented.

## v5.x — Stabilization

* [ ] Complete module integration.
* [ ] Validate all API routes.
* [ ] Complete permission matrix.
* [ ] Improve inventory transaction integrity.
* [ ] Expand audit logging.
* [ ] Improve error handling.
* [ ] Standardize API responses.
* [ ] Improve reporting.

## v5.x — UX & Operations

* [ ] Advanced inventory search.
* [ ] Improved warehouse tree.
* [ ] Better stock dashboards.
* [ ] Enhanced notification center.
* [ ] Responsive inventory counting.
* [ ] Improved print-ready reports.

## Future

* [ ] Advanced reporting engine.
* [ ] Extended API documentation.
* [ ] Automated database backup management.
* [ ] Advanced session/device management.
* [ ] Additional integrations.
* [ ] Improved Windows deployment tooling.
* [ ] Docker-based deployment option.

---

# Version History

## 5.0.0

**Current documented version**

### Highlights

* Modular inventory architecture.
* Multi-warehouse model.
* Product and unit management.
* Inventory movement model.
* Receiving.
* Issuing.
* Transfers.
* Returns.
* Inventory counting.
* Stock adjustments.
* User and permission concepts.
* Audit logging.
* Notifications.
* API layer.
* LAN deployment model.
* Arabic RTL interface.

> The implementation status of individual modules must always be verified against the current source code.

---

# Project Information

| Field      | Value                                   |
| ---------- | --------------------------------------- |
| Project    | Logistox                                |
| Version    | 5.0.0                                   |
| Type       | Inventory & Warehouse Management System |
| Backend    | PHP                                     |
| Frontend   | HTML / CSS / JavaScript                 |
| Database   | MySQL / MariaDB                         |
| Interface  | Arabic RTL                              |
| Deployment | Linux / Windows                         |
| Network    | LAN Ready                               |
| Developer  | BurnMyWallet — Abdelrahman.KH           |
| Repository | Stock-Movement                          |

---

# License

**Proprietary Software**

This project is proprietary software.

Redistribution, resale, modification, or commercial use outside the authorized organization is not permitted without explicit permission from the project owner.

---

# Support

## Technical Support / Development

**BurnMyWallet**

Phone:

```text
01286187173
```

Email:

```text
info@albaraka.com
```

> Verify contact information before publishing this repository publicly.

---

<div align="center">

## Logistox

**Advanced Inventory & Warehouse Management System**

**نظام إدارة المخازن والمخزون المتقدم**

`Version 5.0.0`

**Arabic RTL • LAN Ready • Modular Architecture**

</div>

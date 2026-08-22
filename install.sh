#!/bin/bash
# ================================================================
# نقل وتشغيل مشروع Stock-Movement على Apache
# ================================================================

echo "🚀 جاري نقل مشروع Stock-Movement..."

# 1. حذف النسخة القديمة
sudo rm -rf /var/www/html/inventory-system

# 2. التأكد من وجود المشروع
if [ ! -d "$HOME/Desktop/Stock-Movement" ]; then
    echo "❌ خطأ: المجلد غير موجود:"
    echo "$HOME/Desktop/Stock-Movement"
    exit 1
fi

# 3. نسخ المشروع
sudo cp -r "$HOME/Desktop/Stock-Movement" /var/www/html/inventory-system

# 4. الصلاحيات الأساسية
sudo chown -R www-data:www-data /var/www/html/inventory-system
sudo chmod -R 755 /var/www/html/inventory-system

# 5. إنشاء مجلدات التخزين
sudo mkdir -p /var/www/html/inventory-system/backend/logs
sudo mkdir -p /var/www/html/inventory-system/backups

sudo chown -R www-data:www-data \
    /var/www/html/inventory-system/backend/logs \
    /var/www/html/inventory-system/backups

sudo chmod -R 775 \
    /var/www/html/inventory-system/backend/logs \
    /var/www/html/inventory-system/backups

# ================================================================
# Apache VirtualHost
# ================================================================

echo "🌐 إعداد Apache..."

sudo tee /etc/apache2/sites-available/inventory-system.conf > /dev/null << 'EOF'
<VirtualHost *:80>

    ServerName inventory.local

    DocumentRoot /var/www/html/inventory-system/backend/public

    <Directory /var/www/html/inventory-system/backend/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <Directory /var/www/html/inventory-system/frontend>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/inventory-error.log
    CustomLog ${APACHE_LOG_DIR}/inventory-access.log combined

</VirtualHost>
EOF

# تفعيل الموقع والموديولات
sudo a2ensite inventory-system.conf
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod expires

# فحص Apache قبل إعادة التشغيل
if ! sudo apache2ctl configtest; then
    echo "❌ يوجد خطأ في إعداد Apache"
    exit 1
fi

sudo systemctl restart apache2

# ================================================================
# ملف .env
# ================================================================

echo "⚙️ إنشاء ملف .env..."

cd /var/www/html/inventory-system || exit 1

sudo tee .env > /dev/null << 'EOF'
DB_HOST=localhost
DB_NAME=inventory_system
DB_USER=root
DB_PASS=

APP_NAME="نظام المخازن"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://inventory.local

TIMEZONE=Asia/Riyadh

SINGLE_SESSION_ENABLED=true
SESSION_TIMEOUT=3600
EOF

# ================================================================
# Database
# ================================================================

echo "🗄️ إنشاء قاعدة البيانات..."

sudo mysql -u root -e "
CREATE DATABASE IF NOT EXISTS inventory_system
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
"

DATABASE_DIR="/var/www/html/inventory-system/database"

# التحقق من وجود ملفات SQL
if [ ! -d "$DATABASE_DIR" ]; then
    echo "❌ مجلد database غير موجود:"
    echo "$DATABASE_DIR"
    exit 1
fi

echo "📥 استيراد قاعدة البيانات..."

for SQL_FILE in \
    01_inventory_schema_production.sql \
    02_authentication_rbac_advanced.sql \
    03_session_management_advanced.sql \
    04_sample_data_comprehensive.sql
do

    if [ -f "$DATABASE_DIR/$SQL_FILE" ]; then
        echo "➡️ استيراد $SQL_FILE ..."
        sudo mysql -u root inventory_system < "$DATABASE_DIR/$SQL_FILE"

        if [ $? -ne 0 ]; then
            echo "❌ فشل استيراد: $SQL_FILE"
            exit 1
        fi
    else
        echo "⚠️ الملف غير موجود: $SQL_FILE"
    fi

done

# ================================================================
# Hosts
# ================================================================

echo "🔗 إعداد inventory.local..."

if ! grep -q "inventory.local" /etc/hosts; then
    echo "127.0.0.1 inventory.local" | sudo tee -a /etc/hosts > /dev/null
fi

# ================================================================
# النتيجة
# ================================================================

echo ""
echo "================================================"
echo "✅ تم نقل وتشغيل المشروع بنجاح!"
echo "================================================"
echo ""
echo "📁 المشروع:"
echo "/var/www/html/inventory-system"
echo ""
echo "🌐 الرابط:"
echo "http://inventory.local/"
echo ""
echo "👤 المستخدم:"
echo "admin"
echo ""
echo "🔒 كلمة المرور:"
echo "password"
echo ""
echo "📝 Apache Error Log:"
echo "/var/log/apache2/inventory-error.log"
echo ""
echo "📝 Apache Access Log:"
echo "/var/log/apache2/inventory-access.log"
echo ""
echo "================================================"

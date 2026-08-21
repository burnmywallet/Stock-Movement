#!/bin/bash
# سكربت النقل الكامل

echo "🚀 جاري نقل المشروع..."

# 1. حذف القديم
sudo rm -rf /var/www/html/inventory-system

# 2. نسخ المجلد
sudo cp -r ~/Desktop/AK.WH /var/www/html/inventory-system

# 3. الصلاحيات
sudo chown -R www-data:www-data /var/www/html/inventory-system
sudo chmod -R 755 /var/www/html/inventory-system
sudo mkdir -p /var/www/html/inventory-system/backend/logs
sudo mkdir -p /var/www/html/inventory-system/backups
sudo chmod -R 775 /var/www/html/inventory-system/backend/logs
sudo chmod -R 775 /var/www/html/inventory-system/backups

# 4. Apache
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

sudo a2ensite inventory-system.conf
sudo a2enmod rewrite headers expires
sudo systemctl restart apache2

# 5. .env
cd /var/www/html/inventory-system
sudo tee .env > /dev/null << 'EOF'
DB_HOST=localhost
DB_NAME=inventory_system
DB_USER=root
DB_PASS=
APP_NAME="نظام المخازن"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost/inventory-system
TIMEZONE=Asia/Riyadh
SINGLE_SESSION_ENABLED=true
SESSION_TIMEOUT=3600
EOF

# 6. قاعدة البيانات
sudo mysql -u root -e "CREATE DATABASE IF NOT EXISTS inventory_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cd /var/www/html/inventory-system/database
sudo mysql -u root inventory_system < 01_inventory_schema_production.sql
sudo mysql -u root inventory_system < 02_authentication_rbac_advanced.sql
sudo mysql -u root inventory_system < 03_session_management_advanced.sql
sudo mysql -u root inventory_system < 04_sample_data_comprehensive.sql

echo "✅ تم النقل بنجاح!"
echo "🔗 افتح: http://localhost/frontend/pages/login.html"
echo "👤 المستخدم: admin"
echo "🔒 كلمة المرور: password"

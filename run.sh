#!/bin/bash

# ================================================================
# نظام إدارة المخازن المتقدم - Stock-Movement
# سكربت التشغيل المتطور
# الإصدار: 5.0 Ultimate
# ================================================================

set -u

# ================================================================
# 1. إعدادات التشغيل
# ================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m'
BOLD='\033[1m'

# ------------------------------------------------
# إعدادات المشروع
# ------------------------------------------------

PROJECT_DIR="$HOME/Desktop/Stock-Movement"

PORT=8080

PID_FILE="/tmp/inventory-server.pid"

LOG_DIR="$PROJECT_DIR/logs"
ACCESS_LOG="$LOG_DIR/access.log"
ERROR_LOG="$LOG_DIR/error.log"
MAIN_LOG="$LOG_DIR/server.log"
LOG_TXT="$LOG_DIR/log.txt"

ENV_FILE="$PROJECT_DIR/.env"

PHP_VERSION="غير معروف"

if command -v php >/dev/null 2>&1; then
    PHP_VERSION=$(php -v 2>/dev/null | head -1 | cut -d' ' -f2)
fi


# ================================================================
# 2. دوال السجل
# ================================================================

log_message() {

    local message="$1"
    local timestamp

    timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    mkdir -p "$LOG_DIR"

    echo "[$timestamp] $message" | tee -a "$MAIN_LOG" "$LOG_TXT"
}


print_success() {

    echo -e "${GREEN}✅ $1${NC}" | tee -a "$LOG_TXT"

}


print_error() {

    echo -e "${RED}❌ $1${NC}" | tee -a "$LOG_TXT"

}


print_warning() {

    echo -e "${YELLOW}⚠️  $1${NC}" | tee -a "$LOG_TXT"

}


print_info() {

    echo -e "${CYAN}ℹ️  $1${NC}" | tee -a "$LOG_TXT"

}


print_separator() {

    echo -e "${BLUE}--------------------------------------------------------${NC}"

}


# ================================================================
# 3. Banner
# ================================================================

print_banner() {

    clear 2>/dev/null || true

    mkdir -p "$LOG_DIR"

    echo -e "${BLUE}========================================================${NC}"
    echo -e "${BLUE}   🚀 نظام إدارة المخازن المتقدم${NC}"
    echo -e "${GREEN}   Stock-Movement - v5.0 Ultimate${NC}"
    echo -e "${BLUE}========================================================${NC}"

    echo -e "${WHITE}   PHP       : ${GREEN}$PHP_VERSION${NC}"
    echo -e "${WHITE}   المنفذ    : ${GREEN}$PORT${NC}"
    echo -e "${WHITE}   المشروع   : ${GREEN}$PROJECT_DIR${NC}"
    echo -e "${WHITE}   البيئة    : ${GREEN}$ENV_FILE${NC}"

    echo -e "${BLUE}========================================================${NC}"

    {
        echo "========================================================"
        echo "Stock-Movement - v5.0 Ultimate"
        echo "PHP: $PHP_VERSION"
        echo "PORT: $PORT"
        echo "PROJECT: $PROJECT_DIR"
        echo "========================================================"
    } >> "$LOG_TXT"
}


# ================================================================
# 4. التحقق من المشروع
# ================================================================

check_project() {

    print_info "التحقق من المشروع..."

    if [ ! -d "$PROJECT_DIR" ]; then

        print_error "مجلد المشروع غير موجود:"
        echo "$PROJECT_DIR"

        echo ""
        echo "تأكد أن المشروع موجود هنا:"
        echo "$HOME/Desktop/Stock-Movement"

        return 1
    fi

    print_success "مجلد المشروع موجود"

    if [ ! -d "$PROJECT_DIR/backend" ]; then
        print_error "مجلد backend غير موجود!"
        return 1
    fi

    if [ ! -d "$PROJECT_DIR/backend/public" ]; then
        print_error "مجلد backend/public غير موجود!"
        return 1
    fi

    if [ ! -f "$PROJECT_DIR/backend/public/index.php" ]; then
        print_error "backend/public/index.php غير موجود!"
        return 1
    fi

    print_success "هيكل المشروع سليم"

    return 0
}


# ================================================================
# 5. التحقق من المتطلبات
# ================================================================

check_requirements() {

    print_info "التحقق من متطلبات النظام..."

    # PHP
    if ! command -v php >/dev/null 2>&1; then

        print_error "PHP غير مثبت!"

        echo ""
        echo "ثبت PHP بالأمر:"
        echo "sudo apt install php php-cli php-mysql php-mbstring php-xml"

        return 1
    fi

    PHP_VERSION=$(php -v 2>/dev/null | head -1 | cut -d' ' -f2)

    print_success "PHP موجود: $PHP_VERSION"


    # Composer
    if command -v composer >/dev/null 2>&1; then
        print_success "Composer موجود"
    else
        print_warning "Composer غير مثبت - سيتم تخطي Composer"
    fi


    # MySQL
    if command -v mysql >/dev/null 2>&1; then
        print_success "MySQL Client موجود"
    else
        print_error "mysql غير مثبت!"

        echo ""
        echo "ثبت MySQL/MariaDB Client:"
        echo "sudo apt install mariadb-client"

        return 1
    fi


    # lsof
    if command -v lsof >/dev/null 2>&1; then
        print_success "lsof موجود"
    else
        print_warning "lsof غير مثبت"
        echo "لتثبيته: sudo apt install lsof"
    fi


    return 0
}


# ================================================================
# 6. قراءة .env
# ================================================================

load_env() {

    print_info "تحميل إعدادات .env..."

    if [ ! -f "$ENV_FILE" ]; then

        print_warning "ملف .env غير موجود!"

        return 1
    fi


    # القيم الافتراضية
    DB_HOST="localhost"
    DB_NAME="inventory_system"
    DB_USER="root"
    DB_PASS=""


    # قراءة القيم من .env
    while IFS='=' read -r key value; do

        # تجاهل التعليقات والأسطر الفارغة
        case "$key" in
            ""|\#*)
                continue
                ;;
        esac

        # إزالة المسافات
        key=$(echo "$key" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        value=$(echo "$value" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')

        # إزالة علامات الاقتباس
        value="${value%\"}"
        value="${value#\"}"

        case "$key" in

            DB_HOST)
                DB_HOST="$value"
                ;;

            DB_NAME)
                DB_NAME="$value"
                ;;

            DB_USER)
                DB_USER="$value"
                ;;

            DB_PASS)
                DB_PASS="$value"
                ;;

        esac

    done < "$ENV_FILE"


    print_success "تم تحميل إعدادات قاعدة البيانات"

    echo -e "   Host     : ${GREEN}$DB_HOST${NC}"
    echo -e "   Database : ${GREEN}$DB_NAME${NC}"
    echo -e "   User     : ${GREEN}$DB_USER${NC}"
    echo -e "   Password : ${GREEN}********${NC}"

    return 0
}


# ================================================================
# 7. إنشاء .env إذا لم يكن موجودًا
# ================================================================

create_env() {

    if [ -f "$ENV_FILE" ]; then
        return 0
    fi

    print_info "إنشاء ملف .env..."

    cat > "$ENV_FILE" << 'EOF'
DB_HOST=localhost
DB_NAME=inventory_system
DB_USER=root
DB_PASS=

APP_NAME="نظام المخازن"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost:8080

TIMEZONE=Asia/Riyadh

SINGLE_SESSION_ENABLED=true
SESSION_TIMEOUT=3600
EOF

    chmod 640 "$ENV_FILE"

    print_success "تم إنشاء .env"

    return 0
}


# ================================================================
# 8. إعداد المجلدات
# ================================================================

setup_directories() {

    print_info "إعداد مجلدات النظام..."

    mkdir -p "$LOG_DIR"
    mkdir -p "$PROJECT_DIR/backups"
    mkdir -p "$PROJECT_DIR/tmp"

    touch "$ACCESS_LOG"
    touch "$ERROR_LOG"
    touch "$MAIN_LOG"
    touch "$LOG_TXT"


    # الصلاحيات
    chmod 755 "$PROJECT_DIR" 2>/dev/null || true

    chmod 775 "$LOG_DIR" 2>/dev/null || true
    chmod 775 "$PROJECT_DIR/backups" 2>/dev/null || true
    chmod 775 "$PROJECT_DIR/tmp" 2>/dev/null || true

    chmod 664 "$ACCESS_LOG" 2>/dev/null || true
    chmod 664 "$ERROR_LOG" 2>/dev/null || true
    chmod 664 "$MAIN_LOG" 2>/dev/null || true
    chmod 664 "$LOG_TXT" 2>/dev/null || true

    print_success "تم إعداد المجلدات"

    return 0
}


# ================================================================
# 9. تنظيف المنفذ
# ================================================================

clean_port() {

    print_info "فحص المنفذ $PORT..."

    local pids=""

    if command -v lsof >/dev/null 2>&1; then

        pids=$(lsof -t -i:"$PORT" 2>/dev/null || true)

        if [ -n "$pids" ]; then

            for pid in $pids; do

                if kill -0 "$pid" 2>/dev/null; then

                    kill "$pid" 2>/dev/null || true

                    sleep 1

                    if kill -0 "$pid" 2>/dev/null; then
                        sudo kill -9 "$pid" 2>/dev/null || true
                    fi

                    print_success "تم إنهاء العملية PID: $pid"

                fi

            done

        fi

    else

        print_warning "lsof غير موجود - تخطي فحص المنفذ"

    fi


    # إنهاء السيرفر الخاص بالسكريبت فقط
    pkill -f "php -S 0.0.0.0:$PORT" 2>/dev/null || true
    pkill -f "php -S localhost:$PORT" 2>/dev/null || true


    sleep 1


    if command -v lsof >/dev/null 2>&1; then

        if lsof -i:"$PORT" >/dev/null 2>&1; then

            print_error "المنفذ $PORT لا يزال مشغولاً!"

            return 1

        fi

    fi


    print_success "المنفذ $PORT جاهز"

    return 0
}


# ================================================================
# 10. التحقق من قاعدة البيانات
# ================================================================

check_database() {

    print_info "التحقق من قاعدة البيانات..."


    if ! load_env; then

        print_warning "لا يمكن تحميل .env"

        return 1

    fi


    local MYSQL_CMD=(mysql)

    if [ -n "${DB_HOST:-}" ]; then
        MYSQL_CMD+=("-h$DB_HOST")
    fi

    if [ -n "${DB_USER:-}" ]; then
        MYSQL_CMD+=("-u$DB_USER")
    fi


    if [ -n "${DB_PASS:-}" ]; then

        if "${MYSQL_CMD[@]}" -p"$DB_PASS" \
            -e "USE \`$DB_NAME\`; SELECT 1;" \
            >/dev/null 2>&1; then

            print_success "الاتصال بقاعدة البيانات ناجح!"

            return 0

        fi

    else

        if "${MYSQL_CMD[@]}" \
            -e "USE \`$DB_NAME\`; SELECT 1;" \
            >/dev/null 2>&1; then

            print_success "الاتصال بقاعدة البيانات ناجح!"

            return 0

        fi

    fi


    print_warning "قاعدة البيانات غير متاحة أو بيانات الاتصال غير صحيحة"

    return 1
}


# ================================================================
# 11. تهيئة قاعدة البيانات
# ================================================================

init_database() {

    print_info "تهيئة قاعدة البيانات..."


    if ! load_env; then
        return 1
    fi


    local MYSQL_CMD=(mysql)

    if [ -n "${DB_HOST:-}" ]; then
        MYSQL_CMD+=("-h$DB_HOST")
    fi

    if [ -n "${DB_USER:-}" ]; then
        MYSQL_CMD+=("-u$DB_USER")
    fi


    print_info "إنشاء قاعدة البيانات: $DB_NAME"


    local CREATE_RESULT=0


    if [ -n "${DB_PASS:-}" ]; then

        "${MYSQL_CMD[@]}" -p"$DB_PASS" \
            -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
            >/dev/null 2>&1 || CREATE_RESULT=$?

    else

        "${MYSQL_CMD[@]}" \
            -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
            >/dev/null 2>&1 || CREATE_RESULT=$?

    fi


    if [ "$CREATE_RESULT" -ne 0 ]; then

        print_error "فشل إنشاء قاعدة البيانات"

        echo ""
        echo "تأكد من:"
        echo "1. تشغيل MySQL/MariaDB"
        echo "2. صحة DB_USER"
        echo "3. صحة DB_PASS"
        echo "4. صلاحيات المستخدم"

        return 1
    fi


    print_success "قاعدة البيانات جاهزة: $DB_NAME"


    # ------------------------------------------------
    # استيراد ملفات SQL
    # ------------------------------------------------

    if [ ! -d "$PROJECT_DIR/database" ]; then

        print_warning "مجلد database غير موجود"

        return 0
    fi


    local SQL_FOUND=false


    for sql_file in "$PROJECT_DIR/database"/*.sql; do

        if [ ! -f "$sql_file" ]; then
            continue
        fi


        SQL_FOUND=true

        print_info "استيراد: $(basename "$sql_file")"


        local IMPORT_RESULT=0


        if [ -n "${DB_PASS:-}" ]; then

            "${MYSQL_CMD[@]}" -p"$DB_PASS" "$DB_NAME" \
                < "$sql_file" \
                >/dev/null 2>&1 || IMPORT_RESULT=$?

        else

            "${MYSQL_CMD[@]}" "$DB_NAME" \
                < "$sql_file" \
                >/dev/null 2>&1 || IMPORT_RESULT=$?

        fi


        if [ "$IMPORT_RESULT" -eq 0 ]; then

            print_success "تم استيراد $(basename "$sql_file")"

        else

            print_warning "فشل استيراد $(basename "$sql_file")"

        fi

    done


    if [ "$SQL_FOUND" = false ]; then
        print_warning "لم يتم العثور على ملفات SQL داخل database"
    fi


    return 0
}


# ================================================================
# 12. تشغيل السيرفر
# ================================================================

start_server() {

    print_info "تشغيل الخادم على المنفذ $PORT..."


    if ! check_project; then
        return 1
    fi


    cd "$PROJECT_DIR" || return 1


    # التأكد من وجود index.php
    if [ ! -f "backend/public/index.php" ]; then

        print_error "backend/public/index.php غير موجود!"

        return 1
    fi


    # منع تشغيل أكثر من نسخة
    if [ -f "$PID_FILE" ]; then

        local OLD_PID

        OLD_PID=$(cat "$PID_FILE" 2>/dev/null || true)

        if [ -n "$OLD_PID" ] && kill -0 "$OLD_PID" 2>/dev/null; then

            print_warning "الخادم يعمل بالفعل (PID: $OLD_PID)"

            return 0

        fi

        rm -f "$PID_FILE"

    fi


    # تشغيل PHP Server
    nohup php \
        -S "0.0.0.0:$PORT" \
        -t "$PROJECT_DIR/backend/public" \
        >> "$ACCESS_LOG" \
        2>> "$ERROR_LOG" &


    local SERVER_PID=$!

    echo "$SERVER_PID" > "$PID_FILE"


    sleep 2


    # التحقق
    if kill -0 "$SERVER_PID" 2>/dev/null; then

        print_success "الخادم يعمل"
        print_success "PID: $SERVER_PID"

        log_message "Server started - PID: $SERVER_PID - PORT: $PORT"

        return 0

    fi


    print_error "فشل تشغيل الخادم"

    echo ""
    echo "آخر أخطاء PHP:"
    tail -20 "$ERROR_LOG" 2>/dev/null || true

    rm -f "$PID_FILE"

    return 1
}


# ================================================================
# 13. عرض معلومات النظام
# ================================================================

show_info() {

    local IP

    IP=$(hostname -I 2>/dev/null | awk '{print $1}')


    echo ""

    echo -e "${GREEN}========================================================${NC}"
    echo -e "${GREEN}        ✅ تم تشغيل Stock-Movement بنجاح${NC}"
    echo -e "${GREEN}========================================================${NC}"

    echo ""

    echo -e "${BLUE}🔗 روابط الوصول:${NC}"

    echo -e "   🌐 المحلي:"
    echo -e "      ${GREEN}http://localhost:$PORT${NC}"

    if [ -n "$IP" ]; then
        echo -e "   📱 الشبكة:"
        echo -e "      ${GREEN}http://$IP:$PORT${NC}"
    fi

    echo ""

    echo -e "   🔐 تسجيل الدخول:"
    echo -e "      ${GREEN}http://localhost:$PORT/frontend/pages/login.html${NC}"

    echo ""

    echo -e "   📊 لوحة التحكم:"
    echo -e "      ${GREEN}http://localhost:$PORT/frontend/pages/dashboard.html${NC}"

    echo ""

    echo -e "   🧪 اختبار API:"
    echo -e "      ${GREEN}http://localhost:$PORT/test${NC}"

    echo ""

    echo -e "${BLUE}🔑 بيانات الدخول:${NC}"
    echo -e "   👤 المستخدم: ${GREEN}admin${NC}"
    echo -e "   🔒 كلمة المرور: ${GREEN}password${NC}"

    echo ""

    echo -e "${BLUE}📂 المسارات:${NC}"
    echo -e "   📁 المشروع:"
    echo -e "      ${GREEN}$PROJECT_DIR${NC}"

    echo -e "   📋 Logs:"
    echo -e "      ${GREEN}$LOG_DIR${NC}"

    echo -e "   📦 Backups:"
    echo -e "      ${GREEN}$PROJECT_DIR/backups${NC}"

    echo ""

    echo -e "${YELLOW}📌 أوامر مفيدة:${NC}"

    echo -e "   تشغيل:"
    echo -e "      ${WHITE}$0 start${NC}"

    echo -e "   إيقاف:"
    echo -e "      ${WHITE}$0 stop${NC}"

    echo -e "   إعادة تشغيل:"
    echo -e "      ${WHITE}$0 restart${NC}"

    echo -e "   الحالة:"
    echo -e "      ${WHITE}$0 status${NC}"

    echo -e "   السجلات:"
    echo -e "      ${WHITE}$0 tail${NC}"

    echo -e "   النسخة الاحتياطية:"
    echo -e "      ${WHITE}$0 backup${NC}"

    echo ""

    echo -e "${GREEN}========================================================${NC}"
}


# ================================================================
# 14. عرض Logs مباشرة
# ================================================================

tail_logs() {

    print_info "عرض السجلات في الوقت الفعلي"
    print_info "اضغط Ctrl+C للخروج"

    echo ""

    echo -e "${BLUE}========================================================${NC}"

    echo -e "${YELLOW}📋 Access Log${NC}"

    echo -e "${BLUE}--------------------------------------------------------${NC}"

    tail -f "$ACCESS_LOG" 2>/dev/null &

    local TAIL_ACCESS_PID=$!


    echo -e "${RED}🐞 Error Log${NC}"

    echo -e "${BLUE}--------------------------------------------------------${NC}"

    tail -f "$ERROR_LOG" 2>/dev/null &

    local TAIL_ERROR_PID=$!


    trap '
        kill "$TAIL_ACCESS_PID" "$TAIL_ERROR_PID" 2>/dev/null || true
        exit 0
    ' INT TERM


    wait "$TAIL_ACCESS_PID" "$TAIL_ERROR_PID"
}


# ================================================================
# 15. إيقاف الخادم
# ================================================================

stop_server() {

    print_info "إيقاف الخادم..."


    if [ -f "$PID_FILE" ]; then

        local PID

        PID=$(cat "$PID_FILE" 2>/dev/null || true)


        if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then

            kill "$PID" 2>/dev/null || true

            sleep 1


            if kill -0 "$PID" 2>/dev/null; then
                kill -9 "$PID" 2>/dev/null || true
            fi

            print_success "تم إيقاف الخادم PID: $PID"

        else

            print_warning "العملية غير موجودة"

        fi


        rm -f "$PID_FILE"

    fi


    # تنظيف أي PHP Server على نفس المنفذ
    pkill -f "php -S 0.0.0.0:$PORT" 2>/dev/null || true
    pkill -f "php -S localhost:$PORT" 2>/dev/null || true


    sleep 1


    print_success "تم إيقاف الخادم"

    return 0
}


# ================================================================
# 16. إعادة التشغيل
# ================================================================

restart_server() {

    print_info "إعادة تشغيل الخادم..."

    stop_server

    sleep 2

    clean_port || return 1

    start_server || return 1

    show_info
}


# ================================================================
# 17. حالة الخادم
# ================================================================

status_server() {

    echo ""

    if [ -f "$PID_FILE" ]; then

        local PID

        PID=$(cat "$PID_FILE" 2>/dev/null || true)


        if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then

            echo -e "${GREEN}✅ الخادم يعمل${NC}"

            echo -e "${CYAN}PID       : $PID${NC}"
            echo -e "${CYAN}PORT      : $PORT${NC}"
            echo -e "${CYAN}PROJECT   : $PROJECT_DIR${NC}"
            echo -e "${CYAN}LOGS      : $LOG_DIR${NC}"

            if command -v lsof >/dev/null 2>&1; then

                echo ""

                echo -e "${BLUE}Port information:${NC}"

                lsof -i:"$PORT" 2>/dev/null || true

            fi

            return 0

        fi

    fi


    echo -e "${RED}❌ الخادم متوقف${NC}"

    return 1
}


# ================================================================
# 18. عرض Logs
# ================================================================

show_logs() {

    if [ -f "$ACCESS_LOG" ]; then

        echo -e "${CYAN}📋 آخر 50 سطر من Access Log:${NC}"

        echo -e "${BLUE}--------------------------------------------------------${NC}"

        tail -50 "$ACCESS_LOG"

    else

        print_warning "Access Log غير موجود"

    fi
}


# ================================================================
# 19. عرض Errors
# ================================================================

show_errors() {

    if [ -f "$ERROR_LOG" ]; then

        echo -e "${RED}🐞 آخر 50 سطر من Error Log:${NC}"

        echo -e "${BLUE}--------------------------------------------------------${NC}"

        tail -50 "$ERROR_LOG"

    else

        print_warning "Error Log غير موجود"

    fi
}


# ================================================================
# 20. عرض كل Logs
# ================================================================

show_all_logs() {

    if [ -f "$LOG_TXT" ]; then

        echo -e "${MAGENTA}📄 السجل الكامل للنظام:${NC}"

        echo -e "${BLUE}========================================================${NC}"

        cat "$LOG_TXT"

    else

        print_warning "لا يوجد سجل كامل"

    fi
}


# ================================================================
# 21. Backup
# ================================================================

backup() {

    print_info "إنشاء نسخة احتياطية..."


    local BACKUP_DIR="$PROJECT_DIR/backups"

    local DATE

    DATE=$(date +%Y-%m-%d_%H-%M-%S)


    local BACKUP_FILE="$BACKUP_DIR/backup_$DATE.tar.gz"


    mkdir -p "$BACKUP_DIR"


    tar -czf "$BACKUP_FILE" \
        -C "$PROJECT_DIR" \
        --exclude="backups" \
        --exclude="logs" \
        --exclude="tmp" \
        --exclude=".git" \
        .


    if [ -f "$BACKUP_FILE" ]; then

        local SIZE

        SIZE=$(du -h "$BACKUP_FILE" | cut -f1)

        print_success "تم إنشاء النسخة الاحتياطية"

        echo -e "📦 الملف:"
        echo -e "${GREEN}$BACKUP_FILE${NC}"

        echo -e "📏 الحجم:"
        echo -e "${GREEN}$SIZE${NC}"

        return 0

    fi


    print_error "فشل إنشاء النسخة الاحتياطية"

    return 1
}


# ================================================================
# 22. تحديث النظام
# ================================================================

update_system() {

    print_info "تحديث النظام..."


    cd "$PROJECT_DIR" || return 1


    # Composer
    if command -v composer >/dev/null 2>&1; then

        if [ -f "$PROJECT_DIR/composer.json" ]; then

            print_info "تحديث Composer..."

            composer update \
                --no-dev \
                --optimize-autoloader

            if [ $? -eq 0 ]; then
                print_success "تم تحديث Composer"
            else
                print_warning "حدث خطأ أثناء تحديث Composer"
            fi

        fi

    fi


    # Migrations
    if [ -d "$PROJECT_DIR/database/migrations" ]; then

        if ! load_env; then
            print_warning "تعذر تحميل .env للمigrations"
            return 1
        fi


        local MYSQL_CMD=(mysql)

        [ -n "${DB_HOST:-}" ] && MYSQL_CMD+=("-h$DB_HOST")
        [ -n "${DB_USER:-}" ] && MYSQL_CMD+=("-u$DB_USER")


        for migration in "$PROJECT_DIR/database/migrations"/*.sql; do

            if [ ! -f "$migration" ]; then
                continue
            fi


            print_info "تطبيق migration: $(basename "$migration")"


            if [ -n "${DB_PASS:-}" ]; then

                "${MYSQL_CMD[@]}" \
                    -p"$DB_PASS" \
                    "$DB_NAME" \
                    < "$migration"

            else

                "${MYSQL_CMD[@]}" \
                    "$DB_NAME" \
                    < "$migration"

            fi


            if [ $? -eq 0 ]; then
                print_success "تم تطبيق $(basename "$migration")"
            else
                print_warning "فشل تطبيق $(basename "$migration")"
            fi

        done

    fi


    print_success "تم تحديث النظام"

    return 0
}


# ================================================================
# 23. فحص النظام
# ================================================================

system_check() {

    print_info "إجراء فحص شامل للنظام..."

    echo ""

    check_project || return 1

    echo ""

    check_requirements || return 1

    echo ""

    load_env || true

    echo ""

    status_server || true

    echo ""

    print_success "انتهى الفحص"

    return 0
}


# ================================================================
# 24. المساعدة
# ================================================================

show_help() {

    echo -e "${BLUE}========================================================${NC}"

    echo -e "${WHITE}   🚀 Stock-Movement - سكربت التشغيل${NC}"

    echo -e "${BLUE}========================================================${NC}"

    echo ""

    echo -e "${CYAN}الاستخدام:${NC}"

    echo "  $0 [command]"

    echo ""

    echo -e "${CYAN}الأوامر:${NC}"

    echo -e "  ${GREEN}start${NC}       تشغيل الخادم"

    echo -e "  ${RED}stop${NC}        إيقاف الخادم"

    echo -e "  ${YELLOW}restart${NC}     إعادة تشغيل الخادم"

    echo -e "  ${BLUE}status${NC}      حالة الخادم"

    echo -e "  ${MAGENTA}logs${NC}        عرض Access Log"

    echo -e "  ${RED}errors${NC}      عرض Error Log"

    echo -e "  ${CYAN}all-logs${NC}    عرض السجل الكامل"

    echo -e "  ${WHITE}tail${NC}        Logs مباشرة"

    echo -e "  ${GREEN}backup${NC}      إنشاء Backup"

    echo -e "  ${YELLOW}update${NC}      تحديث النظام"

    echo -e "  ${CYAN}check${NC}       فحص النظام"

    echo -e "  ${WHITE}help${NC}        عرض المساعدة"

    echo ""

    echo -e "${CYAN}أمثلة:${NC}"

    echo "  $0 start"

    echo "  $0 status"

    echo "  $0 restart"

    echo "  $0 tail"

    echo "  $0 backup"

    echo ""

    echo -e "${BLUE}========================================================${NC}"
}


# ================================================================
# 25. الوظيفة الرئيسية
# ================================================================

main() {

    case "${1:-start}" in


        start)

            print_banner

            check_project || exit 1

            check_requirements || exit 1

            setup_directories

            create_env

            load_env || exit 1

            clean_port || exit 1

            if ! check_database; then

                print_warning "سيتم محاولة تهيئة قاعدة البيانات..."

                init_database || exit 1

            fi

            start_server || exit 1

            show_info

            ;;


        stop)

            print_banner

            stop_server

            ;;


        restart)

            print_banner

            check_project || exit 1

            check_requirements || exit 1

            setup_directories

            restart_server

            ;;


        status)

            print_banner

            status_server

            ;;


        logs)

            print_banner

            show_logs

            ;;


        errors)

            print_banner

            show_errors

            ;;


        all-logs)

            print_banner

            show_all_logs

            ;;


        tail)

            print_banner

            tail_logs

            ;;


        backup)

            print_banner

            check_project || exit 1

            setup_directories

            backup

            ;;


        update)

            print_banner

            check_project || exit 1

            update_system

            ;;


        check)

            print_banner

            system_check

            ;;


        help|--help|-h)

            show_help

            ;;


        *)

            echo -e "${RED}❌ أمر غير معروف: $1${NC}"

            echo ""

            show_help

            exit 1

            ;;

    esac
}


# ================================================================
# 26. التنفيذ
# ================================================================

START_TIME=$(date +%s)

main "$@"

END_TIME=$(date +%s)

EXECUTION_TIME=$((END_TIME - START_TIME))


echo ""

echo -e "${BLUE}⏱️  وقت التنفيذ: ${GREEN}${EXECUTION_TIME} ثانية${NC}"

echo ""

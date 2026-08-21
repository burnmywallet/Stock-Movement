#!/bin/bash

# ================================================================
# نظام إدارة المخازن المتقدم - سكربت التشغيل المتطور
# الإصدار: 4.0 Ultimate
# ================================================================

# ================================================================
# 1. إعدادات التشغيل
# ================================================================

# الألوان
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# المتغيرات
PROJECT_DIR="$HOME/Desktop/AK.WH"
PORT=8080
PHP_VERSION=$(php -v | head -1 | cut -d' ' -f2)
LOG_DIR="$PROJECT_DIR/logs"
PID_FILE="/tmp/inventory-server.pid"
ACCESS_LOG="$LOG_DIR/access.log"
ERROR_LOG="$LOG_DIR/error.log"
MAIN_LOG="$LOG_DIR/server.log"
LOG_TXT="$PROJECT_DIR/logs/log.txt"

# ================================================================
# 2. دوال مساعدة
# ================================================================

log_message() {
    local message="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] $message" | tee -a "$MAIN_LOG" "$LOG_TXT"
}

print_banner() {
    clear
    echo -e "${BLUE}========================================================${NC}" | tee -a "$LOG_TXT"
    echo -e "${BLUE}   🚀 نظام إدارة المخازن المتقدم - ${GREEN}v4.0.0${NC}" | tee -a "$LOG_TXT"
    echo -e "${BLUE}========================================================${NC}" | tee -a "$LOG_TXT"
    echo -e "${WHITE}   PHP: ${GREEN}$PHP_VERSION${NC}" | tee -a "$LOG_TXT"
    echo -e "${WHITE}   المنفذ: ${GREEN}$PORT${NC}" | tee -a "$LOG_TXT"
    echo -e "${WHITE}   المشروع: ${GREEN}$PROJECT_DIR${NC}" | tee -a "$LOG_TXT"
    echo -e "${BLUE}========================================================${NC}" | tee -a "$LOG_TXT"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}" | tee -a "$LOG_TXT"
    log_message "✅ $1"
}

print_error() {
    echo -e "${RED}❌ $1${NC}" | tee -a "$LOG_TXT"
    log_message "❌ $1"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}" | tee -a "$LOG_TXT"
    log_message "⚠️ $1"
}

print_info() {
    echo -e "${CYAN}ℹ️  $1${NC}" | tee -a "$LOG_TXT"
    log_message "ℹ️ $1"
}

print_separator() {
    echo -e "${BLUE}--------------------------------------------------------${NC}" | tee -a "$LOG_TXT"
}

# ================================================================
# 3. التحقق من المتطلبات
# ================================================================

check_requirements() {
    print_info "التحقق من متطلبات النظام..."
    
    # التحقق من PHP
    if ! command -v php &> /dev/null; then
        print_error "PHP غير مثبت! يرجى تثبيت PHP أولاً."
        exit 1
    fi
    print_success "PHP موجود: $PHP_VERSION"
    
    # التحقق من Composer
    if ! command -v composer &> /dev/null; then
        print_warning "Composer غير مثبت! سيتم استخدام PHP فقط."
    else
        print_success "Composer موجود"
    fi
    
    # التحقق من MySQL
    if ! command -v mysql &> /dev/null; then
        print_warning "MySQL غير مثبت! تأكد من تشغيل قاعدة البيانات."
    else
        print_success "MySQL موجود"
    fi
}

# ================================================================
# 4. إعداد المجلدات
# ================================================================

setup_directories() {
    print_info "إعداد مجلدات النظام..."
    
    mkdir -p "$LOG_DIR"
    mkdir -p "$PROJECT_DIR/backups"
    mkdir -p "$PROJECT_DIR/tmp"
    
    # إنشاء ملفات السجلات
    touch "$ACCESS_LOG"
    touch "$ERROR_LOG"
    touch "$MAIN_LOG"
    touch "$LOG_TXT"
    
    chmod -R 755 "$PROJECT_DIR"
    chmod -R 775 "$LOG_DIR"
    chmod -R 775 "$PROJECT_DIR/backups"
    chmod -R 775 "$PROJECT_DIR/tmp"
    chmod 666 "$LOG_TXT"
    
    print_success "تم إعداد المجلدات"
}

# ================================================================
# 5. تنظيف المنفذ
# ================================================================

clean_port() {
    print_info "تنظيف المنفذ $PORT..."
    
    # قتل العمليات باستخدام المنفذ
    local pids=$(lsof -t -i:$PORT 2>/dev/null)
    if [ -n "$pids" ]; then
        for pid in $pids; do
            sudo kill -9 $pid 2>/dev/null && print_success "قتل العملية PID: $pid"
        done
    fi
    
    # قتل عمليات PHP المتبقية
    sudo pkill -f "php -S 0.0.0.0:$PORT" 2>/dev/null
    sudo killall php 2>/dev/null
    
    sleep 1
    
    if lsof -i:$PORT > /dev/null 2>&1; then
        print_error "المنفذ $PORT لا يزال مشغولاً!"
        return 1
    else
        print_success "المنفذ $PORT جاهز!"
        return 0
    fi
}

# ================================================================
# 6. التحقق من قاعدة البيانات
# ================================================================

check_database() {
    print_info "التحقق من قاعدة البيانات..."
    
    # قراءة بيانات الاتصال من .env
    if [ -f "$PROJECT_DIR/.env" ]; then
        DB_HOST=$(grep -E '^DB_HOST=' "$PROJECT_DIR/.env" | cut -d'=' -f2)
        DB_NAME=$(grep -E '^DB_NAME=' "$PROJECT_DIR/.env" | cut -d'=' -f2)
        DB_USER=$(grep -E '^DB_USER=' "$PROJECT_DIR/.env" | cut -d'=' -f2)
        DB_PASS=$(grep -E '^DB_PASS=' "$PROJECT_DIR/.env" | cut -d'=' -f2)
    else
        DB_HOST="localhost"
        DB_NAME="inventory_system"
        DB_USER="angel"
        DB_PASS="Lecico10@"
    fi
    
    # اختبار الاتصال
    if mysql -u"$DB_USER" -p"$DB_PASS" -h"$DB_HOST" -e "USE $DB_NAME; SELECT 1;" 2>/dev/null; then
        print_success "الاتصال بقاعدة البيانات ناجح!"
        return 0
    else
        print_warning "فشل الاتصال بقاعدة البيانات! سيتم محاولة الإنشاء..."
        return 1
    fi
}

# ================================================================
# 7. تهيئة قاعدة البيانات
# ================================================================

init_database() {
    print_info "تهيئة قاعدة البيانات..."
    
    DB_NAME="inventory_system"
    DB_USER="angel"
    DB_PASS="Lecico10@"
    
    # إنشاء قاعدة البيانات
    mysql -u"$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
    
    if [ $? -eq 0 ]; then
        print_success "تم إنشاء قاعدة البيانات $DB_NAME"
    else
        print_warning "فشل إنشاء قاعدة البيانات!"
        return 1
    fi
    
    # استيراد ملفات SQL
    if [ -d "$PROJECT_DIR/database" ]; then
        for sql_file in "$PROJECT_DIR/database"/*.sql; do
            if [ -f "$sql_file" ]; then
                print_info "استيراد: $(basename $sql_file)"
                mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$sql_file" 2>/dev/null
                if [ $? -eq 0 ]; then
                    print_success "تم استيراد $(basename $sql_file)"
                else
                    print_warning "فشل استيراد $(basename $sql_file)"
                fi
            fi
        done
    fi
    
    return 0
}

# ================================================================
# 8. تشغيل الخادم مع عرض السجلات
# ================================================================

start_server() {
    print_info "تشغيل الخادم على المنفذ $PORT..."
    
    cd "$PROJECT_DIR"
    
    # إنشاء ملف index.php إذا لم يكن موجوداً
    if [ ! -f "backend/public/index.php" ]; then
        print_error "ملف index.php غير موجود!"
        exit 1
    fi
    
    # تشغيل الخادم في الخلفية مع تسجيل PID
    nohup php -S 0.0.0.0:$PORT -t backend/public > >(tee -a "$ACCESS_LOG") 2> >(tee -a "$ERROR_LOG" >&2) &
    echo $! > "$PID_FILE"
    
    sleep 2
    
    # التحقق من التشغيل
    if ps -p $(cat "$PID_FILE") > /dev/null 2>&1; then
        print_success "الخادم يعمل (PID: $(cat $PID_FILE))"
        return 0
    else
        print_error "فشل تشغيل الخادم!"
        return 1
    fi
}

# ================================================================
# 9. عرض المعلومات
# ================================================================

show_info() {
    local IP=$(hostname -I | awk '{print $1}')
    
    echo "" | tee -a "$LOG_TXT"
    echo -e "${GREEN}========================================================${NC}" | tee -a "$LOG_TXT"
    echo -e "${GREEN}   ✅ تم تشغيل النظام بنجاح!   ${NC}" | tee -a "$LOG_TXT"
    echo -e "${GREEN}========================================================${NC}" | tee -a "$LOG_TXT"
    echo "" | tee -a "$LOG_TXT"
    echo -e "${BLUE}🔗 روابط الوصول:${NC}" | tee -a "$LOG_TXT"
    echo -e "   🌐 المحلي: ${GREEN}http://localhost:$PORT${NC}" | tee -a "$LOG_TXT"
    echo -e "   📱 الشبكة: ${GREEN}http://$IP:$PORT${NC}" | tee -a "$LOG_TXT"
    echo -e "   🔐 تسجيل الدخول: ${GREEN}http://localhost:$PORT/frontend/pages/login.html${NC}" | tee -a "$LOG_TXT"
    echo -e "   📊 لوحة التحكم: ${GREEN}http://localhost:$PORT/frontend/pages/dashboard.html${NC}" | tee -a "$LOG_TXT"
    echo -e "   🧪 اختبار API: ${GREEN}http://localhost:$PORT/test${NC}" | tee -a "$LOG_TXT"
    echo "" | tee -a "$LOG_TXT"
    echo -e "${BLUE}🔑 بيانات الدخول:${NC}" | tee -a "$LOG_TXT"
    echo -e "   👤 ${WHITE}المستخدم:${GREEN} admin${NC}" | tee -a "$LOG_TXT"
    echo -e "   🔒 ${WHITE}كلمة المرور:${GREEN} password${NC}" | tee -a "$LOG_TXT"
    echo "" | tee -a "$LOG_TXT"
    echo -e "${BLUE}📂 مسارات مهمة:${NC}" | tee -a "$LOG_TXT"
    echo -e "   📁 المشروع: ${GREEN}$PROJECT_DIR${NC}" | tee -a "$LOG_TXT"
    echo -e "   📋 السجلات: ${GREEN}$LOG_DIR${NC}" | tee -a "$LOG_TXT"
    echo -e "   📦 النسخ الاحتياطي: ${GREEN}$PROJECT_DIR/backups${NC}" | tee -a "$LOG_TXT"
    echo -e "   📄 ملف السجل الكامل: ${GREEN}$LOG_TXT${NC}" | tee -a "$LOG_TXT"
    echo "" | tee -a "$LOG_TXT"
    echo -e "${YELLOW}📌 أوامر مفيدة:${NC}" | tee -a "$LOG_TXT"
    echo -e "   🛑 إيقاف الخادم: ${WHITE}kill -9 $(cat $PID_FILE 2>/dev/null)${NC}" | tee -a "$LOG_TXT"
    echo -e "   📋 عرض السجلات: ${WHITE}tail -f $ACCESS_LOG${NC}" | tee -a "$LOG_TXT"
    echo -e "   🐞 عرض الأخطاء: ${WHITE}tail -f $ERROR_LOG${NC}" | tee -a "$LOG_TXT"
    echo -e "   📄 عرض السجل الكامل: ${WHITE}cat $LOG_TXT${NC}" | tee -a "$LOG_TXT"
    echo -e "   🔄 إعادة التشغيل: ${WHITE}$0 restart${NC}" | tee -a "$LOG_TXT"
    echo "" | tee -a "$LOG_TXT"
    echo -e "${BLUE}========================================================${NC}" | tee -a "$LOG_TXT"
}

# ================================================================
# 10. عرض السجلات في الوقت الفعلي
# ================================================================

tail_logs() {
    print_info "عرض السجلات في الوقت الفعلي (Ctrl+C للخروج)..."
    echo -e "${BLUE}========================================================${NC}"
    echo -e "${YELLOW}📋 سجل الوصول:${NC}"
    echo -e "${BLUE}--------------------------------------------------------${NC}"
    tail -f "$ACCESS_LOG" 2>/dev/null &
    TAIL_PID=$!
    
    echo -e "${RED}🐞 سجل الأخطاء:${NC}"
    echo -e "${BLUE}--------------------------------------------------------${NC}"
    tail -f "$ERROR_LOG" 2>/dev/null &
    TAIL_ERROR_PID=$!
    
    # الانتظار حتى يتم إيقاف السكربت
    wait $TAIL_PID $TAIL_ERROR_PID
}

# ================================================================
# 11. إيقاف الخادم
# ================================================================

stop_server() {
    print_info "إيقاف الخادم..."
    
    if [ -f "$PID_FILE" ]; then
        local PID=$(cat "$PID_FILE")
        if ps -p $PID > /dev/null 2>&1; then
            kill -9 $PID 2>/dev/null
            print_success "تم إيقاف الخادم (PID: $PID)"
        fi
        rm -f "$PID_FILE"
    else
        # قتل جميع عمليات PHP
        sudo pkill -f "php -S 0.0.0.0:$PORT" 2>/dev/null
        print_success "تم إيقاف جميع عمليات PHP"
    fi
    
    clean_port > /dev/null 2>&1
}

# ================================================================
# 12. إعادة التشغيل
# ================================================================

restart_server() {
    print_info "إعادة تشغيل الخادم..."
    stop_server
    sleep 2
    start_server
    show_info
}

# ================================================================
# 13. حالة الخادم
# ================================================================

status_server() {
    if [ -f "$PID_FILE" ]; then
        local PID=$(cat "$PID_FILE")
        if ps -p $PID > /dev/null 2>&1; then
            echo -e "${GREEN}✅ الخادم يعمل (PID: $PID)${NC}" | tee -a "$LOG_TXT"
            echo -e "${CYAN}ℹ️  المنفذ: $PORT${NC}" | tee -a "$LOG_TXT"
            echo -e "${CYAN}ℹ️  المشروع: $PROJECT_DIR${NC}" | tee -a "$LOG_TXT"
            echo -e "${CYAN}ℹ️  السجلات: $ACCESS_LOG${NC}" | tee -a "$LOG_TXT"
            return 0
        fi
    fi
    echo -e "${RED}❌ الخادم متوقف${NC}" | tee -a "$LOG_TXT"
    return 1
}

# ================================================================
# 14. عرض السجلات
# ================================================================

show_logs() {
    if [ -f "$ACCESS_LOG" ]; then
        echo -e "${CYAN}📋 آخر 50 سطر من سجل الوصول:${NC}" | tee -a "$LOG_TXT"
        echo -e "${BLUE}--------------------------------------------------------${NC}" | tee -a "$LOG_TXT"
        tail -50 "$ACCESS_LOG" | tee -a "$LOG_TXT"
    else
        print_warning "لا يوجد سجل وصول"
    fi
}

show_errors() {
    if [ -f "$ERROR_LOG" ]; then
        echo -e "${RED}🐞 آخر 50 سطر من سجل الأخطاء:${NC}" | tee -a "$LOG_TXT"
        echo -e "${BLUE}--------------------------------------------------------${NC}" | tee -a "$LOG_TXT"
        tail -50 "$ERROR_LOG" | tee -a "$LOG_TXT"
    else
        print_warning "لا يوجد سجل أخطاء"
    fi
}

show_all_logs() {
    if [ -f "$LOG_TXT" ]; then
        echo -e "${MAGENTA}📄 السجل الكامل للنظام:${NC}" | tee -a "$LOG_TXT"
        echo -e "${BLUE}========================================================${NC}" | tee -a "$LOG_TXT"
        cat "$LOG_TXT" | tee -a "$LOG_TXT"
    else
        print_warning "لا يوجد سجل كامل"
    fi
}

# ================================================================
# 15. النسخ الاحتياطي
# ================================================================

backup() {
    print_info "إنشاء نسخة احتياطية..."
    
    local BACKUP_DIR="$PROJECT_DIR/backups"
    local DATE=$(date +%Y-%m-%d_%H-%M-%S)
    local BACKUP_FILE="$BACKUP_DIR/backup_$DATE.tar.gz"
    
    mkdir -p "$BACKUP_DIR"
    
    tar -czf "$BACKUP_FILE" -C "$PROJECT_DIR" \
        --exclude="backups" \
        --exclude="logs" \
        --exclude="tmp" \
        --exclude=".git" \
        . 2>/dev/null
    
    if [ -f "$BACKUP_FILE" ]; then
        local SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
        print_success "تم إنشاء النسخة الاحتياطية: $BACKUP_FILE ($SIZE)"
    else
        print_error "فشل إنشاء النسخة الاحتياطية!"
    fi
}

# ================================================================
# 16. تحديث النظام
# ================================================================

update_system() {
    print_info "تحديث النظام..."
    
    # تحديث التبعيات
    if command -v composer &> /dev/null; then
        composer update --no-dev --optimize-autoloader
        print_success "تم تحديث Composer"
    fi
    
    # تحديث قاعدة البيانات
    if [ -d "$PROJECT_DIR/database/migrations" ]; then
        for migration in "$PROJECT_DIR/database/migrations"/*.sql; do
            if [ -f "$migration" ]; then
                print_info "تطبيق التحديث: $(basename $migration)"
                mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migration" 2>/dev/null
            fi
        done
    fi
    
    print_success "تم تحديث النظام!"
}

# ================================================================
# 17. سكربت المساعدة
# ================================================================

show_help() {
    echo -e "${BLUE}========================================================${NC}"
    echo -e "${WHITE}   🚀 نظام إدارة المخازن - سكربت التشغيل${NC}"
    echo -e "${BLUE}========================================================${NC}"
    echo ""
    echo -e "${CYAN}الاستخدام:${NC}"
    echo -e "  $0 ${GREEN}[start|stop|restart|status|logs|errors|all-logs|tail|backup|update|help]${NC}"
    echo ""
    echo -e "${CYAN}الأوامر:${NC}"
    echo -e "  ${GREEN}start${NC}      - تشغيل الخادم"
    echo -e "  ${RED}stop${NC}       - إيقاف الخادم"
    echo -e "  ${YELLOW}restart${NC}    - إعادة تشغيل الخادم"
    echo -e "  ${BLUE}status${NC}     - عرض حالة الخادم"
    echo -e "  ${MAGENTA}logs${NC}       - عرض سجل الوصول"
    echo -e "  ${RED}errors${NC}     - عرض سجل الأخطاء"
    echo -e "  ${CYAN}all-logs${NC}   - عرض السجل الكامل"
    echo -e "  ${WHITE}tail${NC}       - عرض السجلات في الوقت الفعلي"
    echo -e "  ${GREEN}backup${NC}     - إنشاء نسخة احتياطية"
    echo -e "  ${YELLOW}update${NC}     - تحديث النظام"
    echo -e "  ${WHITE}help${NC}       - عرض هذه المساعدة"
    echo ""
}

# ================================================================
# 18. الوظيفة الرئيسية
# ================================================================

main() {
    case "$1" in
        start)
            print_banner
            check_requirements
            setup_directories
            clean_port
            check_database || init_database
            start_server
            show_info
            ;;
        stop)
            print_banner
            stop_server
            print_success "تم إيقاف الخادم"
            ;;
        restart)
            print_banner
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
            backup
            ;;
        update)
            print_banner
            update_system
            ;;
        help|--help|-h)
            show_help
            ;;
        *)
            # إذا لم يتم تحديد أمر، تشغيل مباشر مع عرض السجلات
            print_banner
            check_requirements
            setup_directories
            clean_port
            check_database || init_database
            start_server
            show_info
            # عرض السجلات في الخلفية
            echo -e "${YELLOW}📋 عرض السجلات في الوقت الفعلي...${NC}"
            echo -e "${BLUE}========================================================${NC}"
            tail -f "$ACCESS_LOG" 2>/dev/null &
            TAIL_PID=$!
            tail -f "$ERROR_LOG" 2>/dev/null &
            TAIL_ERROR_PID=$!
            wait $TAIL_PID $TAIL_ERROR_PID
            ;;
    esac
}

# ================================================================
# 19. تنفيذ
# ================================================================

# تسجيل الوقت
START_TIME=$(date +%s)

# تنفيذ الوظيفة الرئيسية
main "$@"

# حساب وقت التنفيذ
END_TIME=$(date +%s)
EXECUTION_TIME=$((END_TIME - START_TIME))
echo -e "${BLUE}⏱️  وقت التنفيذ: ${GREEN}${EXECUTION_TIME} ثانية${NC}" | tee -a "$LOG_TXT"

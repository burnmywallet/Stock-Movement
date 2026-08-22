/**
 * ============================================================
 * التطبيق الرئيسي - نظام المخازن v5.0
 * الملف: frontend/assets/js/app.js
 * الوصف: دوال مساعدة عامة للتطبيق - Toast، Modal، Formatter، إلخ
 * التاريخ: 2026-08-22
 * ============================================================
 */

const App = {
    /**
     * ============================================================
     * الإعدادات الأساسية
     * ============================================================
     */
    
    config: {
        debug: false,
        version: '5.0.0',
        dateFormat: 'Y-m-d',
        timeFormat: 'H:i:s',
        currency: 'EGP',
        currencySymbol: 'ج.م'
    },

    /**
     * ============================================================
     * Toast Notifications
     * ============================================================
     */
    
    toast: {
        show: function(message, type = 'info', duration = 3500) {
            const existing = document.querySelector('.toast-custom');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.className = `toast-custom ${type}`;
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i> ${message}`;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.4s ease';
                setTimeout(() => toast.remove(), 400);
            }, duration);
        },

        success: function(message, duration = 3500) {
            this.show(message, 'success', duration);
        },

        error: function(message, duration = 3500) {
            this.show(message, 'error', duration);
        },

        warning: function(message, duration = 3500) {
            this.show(message, 'warning', duration);
        },

        info: function(message, duration = 3500) {
            this.show(message, 'info', duration);
        }
    },

    /**
     * ============================================================
     * Modal
     * ============================================================
     */
    
    modal: {
        open: function(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        },

        close: function(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('show');
                document.body.style.overflow = '';
            }
        },

        toggle: function(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.toggle('show');
                document.body.style.overflow = modal.classList.contains('show') ? 'hidden' : '';
            }
        }
    },

    /**
     * ============================================================
     * Formatter
     * ============================================================
     */
    
    format: {
        /**
         * تنسيق التاريخ
         */
        date: function(date, format = null) {
            if (!date) return '-';
            const d = new Date(date);
            if (isNaN(d.getTime())) return '-';
            
            format = format || App.config.dateFormat;
            
            const map = {
                'Y': d.getFullYear(),
                'm': String(d.getMonth() + 1).padStart(2, '0'),
                'd': String(d.getDate()).padStart(2, '0'),
                'H': String(d.getHours()).padStart(2, '0'),
                'i': String(d.getMinutes()).padStart(2, '0'),
                's': String(d.getSeconds()).padStart(2, '0')
            };
            
            let result = format;
            for (const [key, value] of Object.entries(map)) {
                result = result.replace(key, value);
            }
            return result;
        },

        /**
         * تنسيق الوقت
         */
        time: function(date) {
            return this.date(date, 'H:i:s');
        },

        /**
         * تنسيق العملة
         */
        currency: function(amount, symbol = null) {
            if (amount === null || amount === undefined) return '-';
            symbol = symbol || App.config.currencySymbol;
            return Number(amount).toLocaleString('ar-EG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' ' + symbol;
        },

        /**
         * تنسيق الرقم
         */
        number: function(number, decimals = 2) {
            if (number === null || number === undefined) return '-';
            return Number(number).toLocaleString('ar-EG', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
        },

        /**
         * تنسيق الوقت المنقضي
         */
        timeAgo: function(date) {
            if (!date) return '-';
            const diff = Math.floor((Date.now() - new Date(date).getTime()) / 1000);
            
            if (diff < 60) return 'منذ لحظات';
            if (diff < 3600) return 'منذ ' + Math.floor(diff / 60) + ' دقيقة';
            if (diff < 86400) return 'منذ ' + Math.floor(diff / 3600) + ' ساعة';
            if (diff < 604800) return 'منذ ' + Math.floor(diff / 86400) + ' يوم';
            if (diff < 2592000) return 'منذ ' + Math.floor(diff / 604800) + ' أسبوع';
            if (diff < 31536000) return 'منذ ' + Math.floor(diff / 2592000) + ' شهر';
            return 'منذ ' + Math.floor(diff / 31536000) + ' سنة';
        },

        /**
         * اختصار النص
         */
        truncate: function(text, length = 100, suffix = '...') {
            if (!text) return '';
            if (text.length <= length) return text;
            return text.substring(0, length) + suffix;
        },

        /**
         * تحويل النص إلى HTML آمن
         */
        escape: function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * تحويل النص إلى Slug
         */
        slug: function(text) {
            if (!text) return '';
            return text
                .toLowerCase()
                .replace(/[^\w\s]/g, '')
                .replace(/\s+/g, '-');
        }
    },

    /**
     * ============================================================
     * Validation
     * ============================================================
     */
    
    validate: {
        /**
         * التحقق من البريد الإلكتروني
         */
        email: function(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },

        /**
         * التحقق من رقم الهاتف
         */
        phone: function(phone) {
            return /^[0-9+\-\s()]{7,20}$/.test(phone);
        },

        /**
         * التحقق من رقم الهوية
         */
        id: function(id) {
            return /^[0-9]{10,14}$/.test(id);
        },

        /**
         * التحقق من كلمة المرور (8 أحرف، حرف كبير، حرف صغير، رقم، رمز خاص)
         */
        password: function(password) {
            return password.length >= 8 &&
                   /[A-Z]/.test(password) &&
                   /[a-z]/.test(password) &&
                   /[0-9]/.test(password) &&
                   /[!@#$%^&*(),.?":{}|<>]/.test(password);
        },

        /**
         * التحقق من التاريخ
         */
        date: function(date) {
            const d = new Date(date);
            return !isNaN(d.getTime());
        },

        /**
         * التحقق من الرقم
         */
        number: function(value) {
            return !isNaN(parseFloat(value)) && isFinite(value);
        },

        /**
         * التحقق من الحقل الفارغ
         */
        required: function(value) {
            if (value === null || value === undefined) return false;
            if (typeof value === 'string') return value.trim() !== '';
            if (Array.isArray(value)) return value.length > 0;
            return true;
        }
    },

    /**
     * ============================================================
     * DOM Helpers
     * ============================================================
     */
    
    dom: {
        /**
         * الحصول على عنصر
         */
        get: function(selector) {
            return document.querySelector(selector);
        },

        /**
         * الحصول على جميع العناصر
         */
        getAll: function(selector) {
            return document.querySelectorAll(selector);
        },

        /**
         * إضافة مستمع حدث
         */
        on: function(selector, event, callback) {
            const elements = typeof selector === 'string' ? this.getAll(selector) : [selector];
            elements.forEach(el => {
                if (el) el.addEventListener(event, callback);
            });
        },

        /**
         * إظهار عنصر
         */
        show: function(selector) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.style.display = '';
        },

        /**
         * إخفاء عنصر
         */
        hide: function(selector) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.style.display = 'none';
        },

        /**
         * تبديل حالة العنصر
         */
        toggle: function(selector) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) {
                el.style.display = el.style.display === 'none' ? '' : 'none';
            }
        },

        /**
         * إضافة كلاس
         */
        addClass: function(selector, className) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.classList.add(className);
        },

        /**
         * إزالة كلاس
         */
        removeClass: function(selector, className) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.classList.remove(className);
        },

        /**
         * تبديل كلاس
         */
        toggleClass: function(selector, className) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.classList.toggle(className);
        },

        /**
         * تعيين HTML
         */
        html: function(selector, content) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.innerHTML = content;
        },

        /**
         * تعيين النص
         */
        text: function(selector, content) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.textContent = content;
        },

        /**
         * تعيين القيمة
         */
        val: function(selector, value) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) {
                if (value === undefined) return el.value;
                el.value = value;
            }
        }
    },

    /**
     * ============================================================
     * Storage
     * ============================================================
     */
    
    storage: {
        /**
         * تخزين بيانات
         */
        set: function(key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
            } catch (e) {
                console.warn('Storage set error:', e);
            }
        },

        /**
         * جلب بيانات
         */
        get: function(key, defaultValue = null) {
            try {
                const item = localStorage.getItem(key);
                return item ? JSON.parse(item) : defaultValue;
            } catch (e) {
                return defaultValue;
            }
        },

        /**
         * حذف بيانات
         */
        remove: function(key) {
            localStorage.removeItem(key);
        },

        /**
         * مسح جميع البيانات
         */
        clear: function() {
            localStorage.clear();
        },

        /**
         * التحقق من وجود بيانات
         */
        has: function(key) {
            return localStorage.getItem(key) !== null;
        }
    },

    /**
     * ============================================================
     * URL Helpers
     * ============================================================
     */
    
    url: {
        /**
         * الحصول على معلمات URL
         */
        params: function() {
            const params = new URLSearchParams(window.location.search);
            const result = {};
            for (const [key, value] of params) {
                result[key] = value;
            }
            return result;
        },

        /**
         * الحصول على معلمة محددة
         */
        param: function(key, defaultValue = null) {
            const params = new URLSearchParams(window.location.search);
            return params.get(key) || defaultValue;
        },

        /**
         * إضافة معلمة إلى URL
         */
        addParam: function(key, value) {
            const url = new URL(window.location.href);
            url.searchParams.set(key, value);
            return url.toString();
        },

        /**
         * إزالة معلمة من URL
         */
        removeParam: function(key) {
            const url = new URL(window.location.href);
            url.searchParams.delete(key);
            return url.toString();
        }
    },

    /**
     * ============================================================
     * System Info
     * ============================================================
     */
    
    system: {
        /**
         * الحصول على معلومات النظام
         */
        info: function() {
            return {
                version: App.config.version,
                platform: navigator.platform,
                userAgent: navigator.userAgent,
                language: navigator.language,
                screen: {
                    width: window.innerWidth,
                    height: window.innerHeight
                },
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                online: navigator.onLine
            };
        },

        /**
         * التحقق من الاتصال بالإنترنت
         */
        isOnline: function() {
            return navigator.onLine;
        },

        /**
         * إعادة تحميل الصفحة
         */
        reload: function() {
            window.location.reload();
        },

        /**
         * الانتقال إلى صفحة
         */
        redirect: function(url) {
            window.location.href = url;
        }
    },

    /**
     * ============================================================
     * عمليات المستخدم
     * ============================================================
     */
    
    user: {
        /**
         * الحصول على بيانات المستخدم
         */
        get: function() {
            try {
                return JSON.parse(localStorage.getItem('user'));
            } catch {
                return null;
            }
        },

        /**
         * التحقق من تسجيل الدخول
         */
        isLoggedIn: function() {
            return !!localStorage.getItem('auth_token');
        },

        /**
         * التحقق من الصلاحية
         */
        hasPermission: function(permission) {
            const user = this.get();
            if (!user) return false;
            if (user.role === 'admin') return true;
            return (user.permissions || []).includes(permission);
        },

        /**
         * التحقق من الدور
         */
        hasRole: function(role) {
            const user = this.get();
            if (!user) return false;
            return user.role === role;
        },

        /**
         * تسجيل الخروج
         */
        logout: function() {
            localStorage.removeItem('auth_token');
            localStorage.removeItem('user');
            window.location.href = '/frontend/pages/login.html';
        }
    },

    /**
     * ============================================================
     * تهيئة التطبيق
     * ============================================================
     */
    
    init: function() {
        console.log('%c🚀 تطبيق المخازن v' + this.config.version + ' جاهز', 'font-size:20px;font-weight:bold;color:#667eea;');
        console.log('%c👨‍💻 المطور: عبد الرحمن خميس (burnMyWallet)', 'font-size:14px;color:#aaa;');
        console.log('%c📧 abdelrahman.khamis@hotmail.com', 'font-size:12px;color:#666;');
        
        // إضافة حدث للتحقق من الاتصال
        window.addEventListener('online', function() {
            App.toast.success('✅ تم استعادة الاتصال بالإنترنت');
        });
        window.addEventListener('offline', function() {
            App.toast.error('❌ تم فقدان الاتصال بالإنترنت');
        });

        // إضافة حدث للرموز المغلقة تلقائياً
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toast-custom .close')) {
                const toast = e.target.closest('.toast-custom');
                if (toast) {
                    toast.style.opacity = '0';
                    toast.style.transition = 'opacity 0.4s ease';
                    setTimeout(() => toast.remove(), 400);
                }
            }
        });

        return this;
    }
};

// تهيئة التطبيق تلقائياً
if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', function() {
        App.init();
    });
}

// تصدير App للاستخدام العالمي
if (typeof window !== 'undefined') {
    window.App = App;
}

// ================================================================
// انتهى الملف
// ================================================================
/**
 * ============================================================
 * التطبيق الرئيسي - نظام المخازن v5.0
 * الملف: frontend/assets/js/app.js
 * الوصف: دوال مساعدة عامة مع نظام الثيم المتقدم والإشعارات
 * التاريخ: 2026-08-22
 * ============================================================
 */

const App = {
    // ============================================================
    // الإعدادات الأساسية
    // ============================================================
    
    config: {
        debug: false,
        version: '5.0.0',
        dateFormat: 'Y-m-d',
        timeFormat: 'H:i:s',
        currency: 'EGP',
        currencySymbol: 'ج.م',
        apiBaseUrl: null
    },

    // ============================================================
    // التهيئة
    // ============================================================
    
    init: function() {
        // تحديد مسار API
        this.config.apiBaseUrl = this.getApiBaseUrl();
        
        // تهيئة الثيم
        this.theme.init();
        
        // تهيئة الإشعارات
        this.notifications.init();
        
        // إضافة مستمعي الأحداث العامة
        this.bindEvents();
        
        console.log('%c🚀 تطبيق المخازن v' + this.config.version + ' جاهز', 'font-size:20px;font-weight:bold;color:#667eea;');
        console.log('%c👨‍💻 المطور: عبد الرحمن خميس (burnMyWallet)', 'font-size:14px;color:#aaa;');
        console.log('%c📧 abdelrahman.khamis@hotmail.com', 'font-size:12px;color:#666;');
        console.log('%c🔗 API Base URL: ' + this.config.apiBaseUrl, 'font-size:12px;color:#28a745;');
        
        return this;
    },

    /**
     * الحصول على مسار API الصحيح
     */
    getApiBaseUrl: function() {
        const currentPath = window.location.pathname;
        
        if (currentPath.includes('/pages/')) {
            return '../../api';
        }
        
        if (currentPath.includes('/frontend/')) {
            return '../api';
        }
        
        if (currentPath.includes('/Stock-Movement/')) {
            return '/Stock-Movement/api';
        }
        
        return '/api';
    },

    /**
     * ربط الأحداث العامة
     */
    bindEvents: function() {
        // التحقق من الاتصال بالإنترنت
        window.addEventListener('online', function() {
            App.toast.success('✅ تم استعادة الاتصال بالإنترنت');
        });
        window.addEventListener('offline', function() {
            App.toast.error('❌ تم فقدان الاتصال بالإنترنت');
        });

        // إغلاق الـ Toast عند النقر على زر الإغلاق
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toast-custom .close')) {
                const toast = e.target.closest('.toast-custom');
                if (toast) {
                    toast.style.opacity = '0';
                    toast.style.transition = 'opacity 0.4s ease';
                    setTimeout(() => toast.remove(), 400);
                }
            }
        });

        // إغلاق القوائم عند النقر خارجها
        document.addEventListener('click', function(e) {
            const sidebar = document.querySelector('.sidebar');
            const toggle = document.querySelector('.sidebar-toggle');
            if (sidebar && sidebar.classList.contains('open')) {
                if (!sidebar.contains(e.target) && !toggle?.contains(e.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
    },

    // ============================================================
    // نظام الثيم (Dark/Light Mode)
    // ============================================================
    
    theme: {
        current: 'dark',
        initialized: false,

        /**
         * تهيئة الثيم
         */
        init: function() {
            // محاولة جلب الثيم من localStorage أولاً
            const savedTheme = localStorage.getItem('app_theme');
            
            if (savedTheme) {
                this.current = savedTheme;
                this.apply(savedTheme);
                this.initialized = true;
                return;
            }

            // إذا لم يكن محفوظاً، جلب من قاعدة البيانات
            this.loadFromDatabase();
        },

        /**
         * جلب الثيم من قاعدة البيانات
         */
        loadFromDatabase: async function() {
            try {
                const token = localStorage.getItem('auth_token');
                if (!token) {
                    this.apply('dark');
                    return;
                }

                const baseUrl = App.getApiBaseUrl();
                const response = await fetch(baseUrl + '/users/theme', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await response.json();

                if (data.success && data.data && data.data.theme) {
                    this.current = data.data.theme;
                    this.apply(data.data.theme);
                    localStorage.setItem('app_theme', data.data.theme);
                } else {
                    this.apply('dark');
                }
            } catch (error) {
                console.warn('Could not load theme from database, using default:', error);
                this.apply('dark');
            }
            this.initialized = true;
        },

        /**
         * تطبيق الثيم
         */
        apply: function(theme) {
            const root = document.documentElement;
            
            if (theme === 'light') {
                root.style.setProperty('--bg-dark', '#f0f2f5');
                root.style.setProperty('--bg-card', 'rgba(255,255,255,0.9)');
                root.style.setProperty('--border-color', 'rgba(0,0,0,0.08)');
                root.style.setProperty('--text-primary', '#1a2332');
                root.style.setProperty('--text-secondary', 'rgba(0,0,0,0.6)');
                root.style.setProperty('--text-muted', 'rgba(0,0,0,0.3)');
                root.style.setProperty('--sidebar-bg', 'rgba(255,255,255,0.98)');
                root.style.setProperty('--input-bg', 'rgba(0,0,0,0.04)');
                root.style.setProperty('--shadow-color', 'rgba(0,0,0,0.1)');
                
                // تحديث أيقونة الثيم
                const themeIcon = document.querySelector('#themeToggle i');
                if (themeIcon) themeIcon.className = 'fas fa-sun';
                
                // إضافة كلاس للـ body
                document.body.classList.add('light-mode');
                document.body.classList.remove('dark-mode');
            } else {
                // Dark mode (default)
                root.style.setProperty('--bg-dark', '#0a0e1a');
                root.style.setProperty('--bg-card', 'rgba(255,255,255,0.03)');
                root.style.setProperty('--border-color', 'rgba(255,255,255,0.05)');
                root.style.setProperty('--text-primary', '#ffffff');
                root.style.setProperty('--text-secondary', 'rgba(255,255,255,0.6)');
                root.style.setProperty('--text-muted', 'rgba(255,255,255,0.3)');
                root.style.setProperty('--sidebar-bg', 'rgba(10,14,26,0.97)');
                root.style.setProperty('--input-bg', 'rgba(255,255,255,0.04)');
                root.style.setProperty('--shadow-color', 'rgba(0,0,0,0.5)');
                
                const themeIcon = document.querySelector('#themeToggle i');
                if (themeIcon) themeIcon.className = 'fas fa-moon';
                
                document.body.classList.add('dark-mode');
                document.body.classList.remove('light-mode');
            }

            this.current = theme;
            localStorage.setItem('app_theme', theme);
        },

        /**
         * تبديل الثيم
         */
        toggle: async function() {
            const newTheme = this.current === 'dark' ? 'light' : 'dark';
            
            // تطبيق الثيم محلياً
            this.apply(newTheme);
            
            // حفظ في قاعدة البيانات
            try {
                const token = localStorage.getItem('auth_token');
                if (!token) {
                    App.toast.warning('⚠️ تم تغيير الثيم محلياً فقط');
                    return;
                }

                const baseUrl = App.getApiBaseUrl();
                const response = await fetch(baseUrl + '/users/theme', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({ theme: newTheme })
                });
                const data = await response.json();
                
                if (data.success) {
                    // تحديث بيانات المستخدم
                    const user = API.getUser ? API.getUser() : null;
                    if (user) {
                        user.theme = newTheme;
                        if (API.setUser) API.setUser(user);
                    }
                    App.toast.success('✅ تم تغيير الثيم إلى ' + (newTheme === 'dark' ? 'الداكن' : 'الفاتح'));
                } else {
                    App.toast.warning('⚠️ تم تغيير الثيم محلياً فقط');
                }
            } catch (error) {
                console.warn('Could not save theme to database:', error);
                App.toast.warning('⚠️ تم تغيير الثيم محلياً فقط');
            }
        },

        /**
         * الحصول على الثيم الحالي
         */
        get: function() {
            return this.current;
        },

        /**
         * التحقق من الوضع الداكن
         */
        isDark: function() {
            return this.current === 'dark';
        },

        /**
         * التحقق من الوضع الفاتح
         */
        isLight: function() {
            return this.current === 'light';
        }
    },

    // ============================================================
    // نظام الإشعارات (Notifications)
    // ============================================================
    
    notifications: {
        count: 0,
        items: [],
        initialized: false,

        /**
         * تهيئة الإشعارات
         */
        init: function() {
            // جلب عدد الإشعارات غير المقروءة
            this.fetchCount();
            
            // تحديث كل 60 ثانية
            setInterval(() => this.fetchCount(), 60000);
            
            this.initialized = true;
        },

        /**
         * جلب عدد الإشعارات غير المقروءة
         */
        fetchCount: async function() {
            try {
                const token = localStorage.getItem('auth_token');
                if (!token) return;

                const baseUrl = App.getApiBaseUrl();
                const response = await fetch(baseUrl + '/dashboard/notifications', {
                    headers: { 'Authorization': 'Bearer ' + token }
                });
                const data = await response.json();

                if (data.success && data.data) {
                    this.items = data.data;
                    this.count = this.items.filter(n => !n.is_read).length;
                    this.updateBadge();
                }
            } catch (error) {
                console.warn('Could not fetch notifications:', error);
            }
        },

        /**
         * تحديث شارة الإشعارات
         */
        updateBadge: function() {
            const badge = document.getElementById('notifCount');
            const dot = document.querySelector('#notifBtn .dot');
            
            if (this.count > 0) {
                if (badge) {
                    badge.textContent = this.count;
                    badge.style.display = 'flex';
                }
                if (dot) dot.style.display = 'block';
            } else {
                if (badge) badge.style.display = 'none';
                if (dot) dot.style.display = 'none';
            }
        },

        /**
         * تعيين إشعار كمقروء
         */
        markAsRead: async function(id) {
            try {
                const token = localStorage.getItem('auth_token');
                if (!token) return;

                const baseUrl = App.getApiBaseUrl();
                const response = await fetch(baseUrl + '/dashboard/notifications/read', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + token
                    },
                    body: JSON.stringify({ notification_id: id })
                });
                const data = await response.json();

                if (data.success) {
                    // تحديث محلياً
                    const notif = this.items.find(n => n.id === id);
                    if (notif) {
                        notif.is_read = 1;
                        this.count = Math.max(0, this.count - 1);
                        this.updateBadge();
                    }
                    App.toast.success('✅ تم تعيين الإشعار كمقروء');
                }
            } catch (error) {
                console.warn('Could not mark notification as read:', error);
            }
        },

        /**
         * تعيين جميع الإشعارات كمقروءة
         */
        markAllAsRead: async function() {
            try {
                const token = localStorage.getItem('auth_token');
                if (!token) return;

                const baseUrl = App.getApiBaseUrl();
                const response = await fetch(baseUrl + '/dashboard/notifications/read-all', {
                    method: 'POST',
                    headers: {
                        'Authorization': 'Bearer ' + token
                    }
                });
                const data = await response.json();

                if (data.success) {
                    this.items.forEach(n => n.is_read = 1);
                    this.count = 0;
                    this.updateBadge();
                    App.toast.success('✅ تم تعيين جميع الإشعارات كمقروءة');
                }
            } catch (error) {
                console.warn('Could not mark all as read:', error);
            }
        },

        /**
         * الحصول على الإشعارات
         */
        getItems: function() {
            return this.items;
        },

        /**
         * الحصول على عدد الإشعارات غير المقروءة
         */
        getCount: function() {
            return this.count;
        }
    },

    // ============================================================
    // Toast Notifications
    // ============================================================
    
    toast: {
        show: function(message, type = 'info', duration = 3500) {
            const existing = document.querySelector('.toast-custom');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.className = `toast-custom ${type}`;
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i> ${message}`;
            
            // إضافة زر إغلاق
            const closeBtn = document.createElement('button');
            closeBtn.className = 'close';
            closeBtn.innerHTML = '<i class="fas fa-times"></i>';
            closeBtn.style.cssText = 'background:none;border:none;color:rgba(255,255,255,0.5);cursor:pointer;font-size:16px;margin-right:auto;padding:0 4px;';
            toast.appendChild(closeBtn);
            
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.4s ease';
                setTimeout(() => toast.remove(), 400);
            }, duration);
        },

        success: function(message, duration = 3500) {
            this.show(message, 'success', duration);
        },

        error: function(message, duration = 3500) {
            this.show(message, 'error', duration);
        },

        warning: function(message, duration = 3500) {
            this.show(message, 'warning', duration);
        },

        info: function(message, duration = 3500) {
            this.show(message, 'info', duration);
        }
    },

    // ============================================================
    // Modal
    // ============================================================
    
    modal: {
        open: function(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        },

        close: function(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('show');
                document.body.style.overflow = '';
            }
        },

        toggle: function(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.toggle('show');
                document.body.style.overflow = modal.classList.contains('show') ? 'hidden' : '';
            }
        }
    },

    // ============================================================
    // Formatter
    // ============================================================
    
    format: {
        date: function(date, format = null) {
            if (!date) return '-';
            const d = new Date(date);
            if (isNaN(d.getTime())) return '-';
            
            format = format || App.config.dateFormat;
            
            const map = {
                'Y': d.getFullYear(),
                'm': String(d.getMonth() + 1).padStart(2, '0'),
                'd': String(d.getDate()).padStart(2, '0'),
                'H': String(d.getHours()).padStart(2, '0'),
                'i': String(d.getMinutes()).padStart(2, '0'),
                's': String(d.getSeconds()).padStart(2, '0')
            };
            
            let result = format;
            for (const [key, value] of Object.entries(map)) {
                result = result.replace(key, value);
            }
            return result;
        },

        time: function(date) {
            return this.date(date, 'H:i:s');
        },

        currency: function(amount, symbol = null) {
            if (amount === null || amount === undefined) return '-';
            symbol = symbol || App.config.currencySymbol;
            return Number(amount).toLocaleString('ar-EG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' ' + symbol;
        },

        number: function(number, decimals = 2) {
            if (number === null || number === undefined) return '-';
            return Number(number).toLocaleString('ar-EG', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
        },

        timeAgo: function(date) {
            if (!date) return '-';
            const diff = Math.floor((Date.now() - new Date(date).getTime()) / 1000);
            
            if (diff < 60) return 'منذ لحظات';
            if (diff < 3600) return 'منذ ' + Math.floor(diff / 60) + ' دقيقة';
            if (diff < 86400) return 'منذ ' + Math.floor(diff / 3600) + ' ساعة';
            if (diff < 604800) return 'منذ ' + Math.floor(diff / 86400) + ' يوم';
            if (diff < 2592000) return 'منذ ' + Math.floor(diff / 604800) + ' أسبوع';
            if (diff < 31536000) return 'منذ ' + Math.floor(diff / 2592000) + ' شهر';
            return 'منذ ' + Math.floor(diff / 31536000) + ' سنة';
        },

        truncate: function(text, length = 100, suffix = '...') {
            if (!text) return '';
            if (text.length <= length) return text;
            return text.substring(0, length) + suffix;
        },

        escape: function(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        slug: function(text) {
            if (!text) return '';
            return text
                .toLowerCase()
                .replace(/[^\w\s]/g, '')
                .replace(/\s+/g, '-');
        }
    },

    // ============================================================
    // Validation
    // ============================================================
    
    validate: {
        email: function(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },

        phone: function(phone) {
            return /^[0-9+\-\s()]{7,20}$/.test(phone);
        },

        id: function(id) {
            return /^[0-9]{10,14}$/.test(id);
        },

        password: function(password) {
            return password.length >= 8 &&
                   /[A-Z]/.test(password) &&
                   /[a-z]/.test(password) &&
                   /[0-9]/.test(password) &&
                   /[!@#$%^&*(),.?":{}|<>]/.test(password);
        },

        date: function(date) {
            const d = new Date(date);
            return !isNaN(d.getTime());
        },

        number: function(value) {
            return !isNaN(parseFloat(value)) && isFinite(value);
        },

        required: function(value) {
            if (value === null || value === undefined) return false;
            if (typeof value === 'string') return value.trim() !== '';
            if (Array.isArray(value)) return value.length > 0;
            return true;
        }
    },

    // ============================================================
    // DOM Helpers
    // ============================================================
    
    dom: {
        get: function(selector) {
            return document.querySelector(selector);
        },

        getAll: function(selector) {
            return document.querySelectorAll(selector);
        },

        on: function(selector, event, callback) {
            const elements = typeof selector === 'string' ? this.getAll(selector) : [selector];
            elements.forEach(el => {
                if (el) el.addEventListener(event, callback);
            });
        },

        show: function(selector) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.style.display = '';
        },

        hide: function(selector) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.style.display = 'none';
        },

        toggle: function(selector) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) {
                el.style.display = el.style.display === 'none' ? '' : 'none';
            }
        },

        addClass: function(selector, className) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.classList.add(className);
        },

        removeClass: function(selector, className) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.classList.remove(className);
        },

        toggleClass: function(selector, className) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.classList.toggle(className);
        },

        html: function(selector, content) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.innerHTML = content;
        },

        text: function(selector, content) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) el.textContent = content;
        },

        val: function(selector, value) {
            const el = typeof selector === 'string' ? this.get(selector) : selector;
            if (el) {
                if (value === undefined) return el.value;
                el.value = value;
            }
        }
    },

    // ============================================================
    // Storage
    // ============================================================
    
    storage: {
        set: function(key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
            } catch (e) {
                console.warn('Storage set error:', e);
            }
        },

        get: function(key, defaultValue = null) {
            try {
                const item = localStorage.getItem(key);
                return item ? JSON.parse(item) : defaultValue;
            } catch (e) {
                return defaultValue;
            }
        },

        remove: function(key) {
            localStorage.removeItem(key);
        },

        clear: function() {
            localStorage.clear();
        },

        has: function(key) {
            return localStorage.getItem(key) !== null;
        }
    },

    // ============================================================
    // URL Helpers
    // ============================================================
    
    url: {
        params: function() {
            const params = new URLSearchParams(window.location.search);
            const result = {};
            for (const [key, value] of params) {
                result[key] = value;
            }
            return result;
        },

        param: function(key, defaultValue = null) {
            const params = new URLSearchParams(window.location.search);
            return params.get(key) || defaultValue;
        },

        addParam: function(key, value) {
            const url = new URL(window.location.href);
            url.searchParams.set(key, value);
            return url.toString();
        },

        removeParam: function(key) {
            const url = new URL(window.location.href);
            url.searchParams.delete(key);
            return url.toString();
        },

        redirect: function(url) {
            window.location.href = url;
        },

        reload: function() {
            window.location.reload();
        }
    },

    // ============================================================
    // System Info
    // ============================================================
    
    system: {
        info: function() {
            return {
                version: App.config.version,
                platform: navigator.platform,
                userAgent: navigator.userAgent,
                language: navigator.language,
                screen: {
                    width: window.innerWidth,
                    height: window.innerHeight
                },
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                online: navigator.onLine
            };
        },

        isOnline: function() {
            return navigator.onLine;
        }
    },

    // ============================================================
    // عمليات المستخدم
    // ============================================================
    
    user: {
        get: function() {
            try {
                return JSON.parse(localStorage.getItem('user'));
            } catch {
                return null;
            }
        },

        isLoggedIn: function() {
            return !!localStorage.getItem('auth_token');
        },

        logout: function() {
            localStorage.removeItem('auth_token');
            localStorage.removeItem('user');
            localStorage.removeItem('app_theme');
            window.location.href = '/frontend/pages/login.html';
        }
    }
};

// ============================================================
// تهيئة التطبيق تلقائياً
// ============================================================

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', function() {
        App.init();
    });
}

// ============================================================
// تصدير App للاستخدام العالمي
// ============================================================

if (typeof window !== 'undefined') {
    window.App = App;
}

// ============================================================
// انتهى الملف
// ============================================================

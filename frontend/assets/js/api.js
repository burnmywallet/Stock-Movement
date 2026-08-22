/**
 * ============================================================
 * واجهة API الموحدة - نظام المخازن v5.0
 * الملف: frontend/assets/js/api.js
 * الوصف: دوال الاتصال بـ API الخلفية - موحدة لجميع الصفحات
 * التاريخ: 2026-08-22
 * ============================================================
 */

const API = {
    // ============================================================
    // الإعدادات الأساسية
    // ============================================================
    
    baseUrl: null,
    token: null,

    /**
     * تهيئة API - تحديد المسار الصحيح
     */
    init() {
        this.baseUrl = this.getBaseUrl();
        this.token = localStorage.getItem('auth_token');
        console.log('🔗 API Base URL:', this.baseUrl);
        return this;
    },

    /**
     * الحصول على مسار API الصحيح حسب بيئة التشغيل
     */
    getBaseUrl() {
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
     * الحصول على التوكن من localStorage
     */
    getToken() {
        return localStorage.getItem('auth_token');
    },

    /**
     * تخزين التوكن
     */
    setToken(token) {
        localStorage.setItem('auth_token', token);
        this.token = token;
    },

    /**
     * حذف التوكن
     */
    removeToken() {
        localStorage.removeItem('auth_token');
        this.token = null;
    },

    /**
     * الحصول على بيانات المستخدم
     */
    getUser() {
        try {
            return JSON.parse(localStorage.getItem('user'));
        } catch {
            return null;
        }
    },

    /**
     * تخزين بيانات المستخدم
     */
    setUser(user) {
        localStorage.setItem('user', JSON.stringify(user));
    },

    /**
     * ✅ التحقق من وجود توكن صالح
     */
    isAuthenticated() {
        return !!this.getToken();
    },

    /**
     * ✅ الحصول على ثيم المستخدم
     */
    getTheme() {
        const user = this.getUser();
        return user?.theme || 'dark';
    },

    /**
     * ✅ تحديث ثيم المستخدم
     */
    async updateTheme(theme) {
        try {
            const result = await this.request('/users/theme', 'POST', { theme });
            if (result) {
                const user = this.getUser();
                if (user) {
                    user.theme = theme;
                    this.setUser(user);
                }
                return true;
            }
            return false;
        } catch (error) {
            console.error('Error updating theme:', error);
            return false;
        }
    },

    /**
     * طلب API موحد
     */
    async request(endpoint, method = 'GET', data = null, options = {}) {
        const url = this.baseUrl + endpoint;
        const token = this.getToken();

        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            ...options.headers
        };

        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }

        const fetchOptions = {
            method: method,
            headers: headers,
            ...options
        };

        if (data && method !== 'GET') {
            fetchOptions.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(url, fetchOptions);
            const result = await response.json();

            // ✅ إذا كان التوكن غير صالح
            if (response.status === 401) {
                this.removeToken();
                if (!window.location.pathname.includes('login.html')) {
                    window.location.href = '/frontend/pages/login.html';
                }
                throw new Error('جلسة غير صالحة');
            }

            if (!result.success) {
                throw new Error(result.message || 'حدث خطأ');
            }

            return result.data;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },

    // ============================================================
    // المصادقة (Authentication)
    // ============================================================
    
    auth: {
        /**
         * تسجيل الدخول
         */
        login: (username, password, remember = false, deviceName = null) => {
            return API.request('/auth/login', 'POST', {
                username,
                password,
                remember,
                device_name: deviceName || navigator.userAgent || 'Unknown Device'
            });
        },

        /**
         * تسجيل الخروج
         */
        logout: () => {
            return API.request('/auth/logout', 'POST');
        },

        /**
         * التحقق من صحة الجلسة
         */
        validate: () => {
            return API.request('/auth/validate');
        },

        /**
         * تجديد التوكن
         */
        refresh: (refreshToken) => {
            return API.request('/auth/refresh', 'POST', { refresh_token: refreshToken });
        },

        /**
         * تغيير كلمة المرور
         */
        changePassword: (currentPassword, newPassword, confirmPassword) => {
            return API.request('/auth/change-password', 'POST', {
                current_password: currentPassword,
                new_password: newPassword,
                confirm_password: confirmPassword
            });
        },

        /**
         * طلب إعادة تعيين كلمة المرور
         */
        forgotPassword: (email) => {
            return API.request('/auth/forgot-password', 'POST', { email });
        },

        /**
         * إعادة تعيين كلمة المرور
         */
        resetPassword: (token, password, confirmPassword) => {
            return API.request('/auth/reset-password', 'POST', {
                token,
                password,
                confirm_password: confirmPassword
            });
        },

        /**
         * جلب معلومات المستخدم الحالي
         */
        me: () => {
            return API.request('/auth/me');
        },

        /**
         * جلب الجلسات النشطة
         */
        sessions: () => {
            return API.request('/auth/sessions');
        },

        /**
         * إنهاء جلسة محددة
         */
        terminateSession: (sessionId) => {
            return API.request('/auth/sessions/terminate', 'POST', { session_id: sessionId });
        },

        /**
         * إنهاء جميع الجلسات
         */
        terminateAllSessions: () => {
            return API.request('/auth/sessions/terminate-all', 'POST');
        }
    },

    // ============================================================
    // لوحة التحكم (Dashboard)
    // ============================================================
    
    dashboard: {
        /**
         * جلب جميع بيانات لوحة التحكم
         */
        index: () => API.request('/dashboard'),

        /**
         * جلب الإحصائيات
         */
        stats: () => API.request('/dashboard/stats'),

        /**
         * جلب بيانات الرسوم البيانية
         */
        charts: () => API.request('/dashboard/charts'),

        /**
         * جلب التنبيهات
         */
        alerts: () => API.request('/dashboard/alerts'),

        /**
         * جلب آخر الأنشطة
         */
        activities: () => API.request('/dashboard/activities'),

        /**
         * جلب حالة النظام
         */
        status: () => API.request('/dashboard/status'),

        /**
         * جلب الإشعارات
         */
        notifications: () => API.request('/dashboard/notifications'),

        /**
         * تعيين إشعار كمقروء
         */
        markNotificationRead: (notificationId) => {
            return API.request('/dashboard/notifications/read', 'POST', { notification_id: notificationId });
        },

        /**
         * تعيين جميع الإشعارات كمقروءة
         */
        markAllNotificationsRead: () => {
            return API.request('/dashboard/notifications/read-all', 'POST');
        }
    },

    // ============================================================
    // المستخدمين (Users) - مع دعم الثيم
    // ============================================================
    
    users: {
        /**
         * جلب قائمة المستخدمين
         */
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/users' + (query ? '?' + query : ''));
        },

        /**
         * جلب مستخدم محدد
         */
        get: (id) => API.request('/users/' + id),

        /**
         * إنشاء مستخدم جديد
         */
        create: (data) => API.request('/users', 'POST', data),

        /**
         * تحديث مستخدم
         */
        update: (id, data) => API.request('/users/' + id, 'PUT', data),

        /**
         * حذف مستخدم
         */
        delete: (id) => API.request('/users/' + id, 'DELETE'),

        /**
         * استعادة مستخدم محذوف
         */
        restore: (id) => API.request('/users/' + id + '/restore', 'POST'),

        /**
         * قفل مستخدم
         */
        lock: (id) => API.request('/users/' + id + '/lock', 'POST'),

        /**
         * فتح قفل مستخدم
         */
        unlock: (id) => API.request('/users/' + id + '/unlock', 'POST'),

        /**
         * تحديث صلاحيات المستخدم
         */
        updatePermissions: (id, permissions, roleId) => {
            return API.request('/users/' + id + '/permissions', 'PUT', { permissions, role_id: roleId });
        },

        /**
         * جلب صلاحيات المستخدم
         */
        getPermissions: (id) => API.request('/users/' + id + '/permissions'),

        /**
         * تغيير كلمة المرور
         */
        changePassword: (id, currentPassword, newPassword, confirmPassword) => {
            return API.request('/users/' + id + '/change-password', 'POST', {
                current_password: currentPassword,
                new_password: newPassword,
                confirm_password: confirmPassword
            });
        },

        /**
         * جلب سجل نشاط المستخدم
         */
        activities: (id, params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/users/' + id + '/activities' + (query ? '?' + query : ''));
        },

        /**
         * جلب جلسات المستخدم النشطة
         */
        sessions: (id) => API.request('/users/' + id + '/sessions'),

        /**
         * تصدير المستخدمين
         */
        export: (format = 'csv', params = {}) => {
            const query = new URLSearchParams({ format, ...params }).toString();
            return API.request('/users/export' + (query ? '?' + query : ''));
        },

        /**
         * ✅ تحديث ثيم المستخدم
         */
        updateTheme: (theme) => {
            return API.request('/users/theme', 'POST', { theme });
        },

        /**
         * ✅ جلب ثيم المستخدم
         */
        getTheme: () => {
            return API.request('/users/theme');
        }
    },

    // ============================================================
    // الأصناف (Products)
    // ============================================================
    
    products: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/products' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/products/' + id),
        create: (data) => API.request('/products', 'POST', data),
        update: (id, data) => API.request('/products/' + id, 'PUT', data),
        delete: (id) => API.request('/products/' + id, 'DELETE'),
        bulkImport: (products) => API.request('/products/bulk-import', 'POST', { products }),
        export: (format = 'csv', params = {}) => {
            const query = new URLSearchParams({ format, ...params }).toString();
            return API.request('/products/export' + (query ? '?' + query : ''));
        },
        categories: () => API.request('/products/categories'),
        units: () => API.request('/products/units'),
        balances: (id) => API.request('/products/' + id + '/balances'),
        history: (id, params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/products/' + id + '/history' + (query ? '?' + query : ''));
        },
        barcode: (id) => API.request('/products/' + id + '/barcode')
    },

    // ============================================================
    // المخازن (Warehouses)
    // ============================================================
    
    warehouses: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/warehouses' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/warehouses/' + id),
        create: (data) => API.request('/warehouses', 'POST', data),
        update: (id, data) => API.request('/warehouses/' + id, 'PUT', data),
        delete: (id) => API.request('/warehouses/' + id, 'DELETE'),
        stock: (id, params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/warehouses/' + id + '/stock' + (query ? '?' + query : ''));
        },
        report: (id, params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/warehouses/' + id + '/report' + (query ? '?' + query : ''));
        },
        sub: (id) => API.request('/warehouses/' + id + '/sub'),
        summary: (id) => API.request('/warehouses/' + id + '/summary'),
        detailed: (id, params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/warehouses/' + id + '/detailed' + (query ? '?' + query : ''));
        }
    },

    // ============================================================
    // الموردين (Suppliers)
    // ============================================================
    
    suppliers: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/suppliers' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/suppliers/' + id),
        create: (data) => API.request('/suppliers', 'POST', data),
        update: (id, data) => API.request('/suppliers/' + id, 'PUT', data),
        delete: (id) => API.request('/suppliers/' + id, 'DELETE'),
        export: (format = 'csv') => API.request('/suppliers/export?format=' + format)
    },

    // ============================================================
    // الوحدات (Units)
    // ============================================================
    
    units: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/units' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/units/' + id),
        create: (data) => API.request('/units', 'POST', data),
        update: (id, data) => API.request('/units/' + id, 'PUT', data),
        delete: (id) => API.request('/units/' + id, 'DELETE'),
        convert: (fromUnit, toUnit, quantity = 1) => {
            return API.request('/units/convert?from_unit=' + fromUnit + '&to_unit=' + toUnit + '&quantity=' + quantity);
        },
        base: () => API.request('/units/base')
    },

    // ============================================================
    // التصنيفات (Categories)
    // ============================================================
    
    categories: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/categories' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/categories/' + id),
        create: (data) => API.request('/categories', 'POST', data),
        update: (id, data) => API.request('/categories/' + id, 'PUT', data),
        delete: (id) => API.request('/categories/' + id, 'DELETE'),
        tree: () => API.request('/categories/tree'),
        export: (format = 'csv') => API.request('/categories/export?format=' + format)
    },

    // ============================================================
    // الجهات المستلمة (Recipients)
    // ============================================================
    
    recipients: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/recipients' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/recipients/' + id),
        create: (data) => API.request('/recipients', 'POST', data),
        update: (id, data) => API.request('/recipients/' + id, 'PUT', data),
        delete: (id) => API.request('/recipients/' + id, 'DELETE'),
        export: (format = 'csv') => API.request('/recipients/export?format=' + format),
        types: () => API.request('/recipients/types')
    },

    // ============================================================
    // الاستلام (Receipts)
    // ============================================================
    
    receipts: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/receipts' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/receipts/' + id),
        create: (data) => API.request('/receipts', 'POST', data),
        update: (id, data) => API.request('/receipts/' + id, 'PUT', data),
        approve: (id) => API.request('/receipts/' + id + '/approve', 'POST'),
        reject: (id, reason) => API.request('/receipts/' + id + '/reject', 'POST', { reason }),
        cancel: (id, reason) => API.request('/receipts/' + id + '/cancel', 'POST', { reason }),
        export: (format = 'csv', params = {}) => {
            const query = new URLSearchParams({ format, ...params }).toString();
            return API.request('/receipts/export' + (query ? '?' + query : ''));
        },
        print: (id) => API.request('/receipts/' + id + '/print')
    },

    // ============================================================
    // الصرف (Issues)
    // ============================================================
    
    issues: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/issues' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/issues/' + id),
        create: (data) => API.request('/issues', 'POST', data),
        update: (id, data) => API.request('/issues/' + id, 'PUT', data),
        approve: (id) => API.request('/issues/' + id + '/approve', 'POST'),
        deliver: (id) => API.request('/issues/' + id + '/deliver', 'POST'),
        reject: (id, reason) => API.request('/issues/' + id + '/reject', 'POST', { reason }),
        cancel: (id, reason) => API.request('/issues/' + id + '/cancel', 'POST', { reason }),
        export: (format = 'csv', params = {}) => {
            const query = new URLSearchParams({ format, ...params }).toString();
            return API.request('/issues/export' + (query ? '?' + query : ''));
        },
        print: (id) => API.request('/issues/' + id + '/print')
    },

    // ============================================================
    // التحويلات (Transfers)
    // ============================================================
    
    transfers: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/transfers' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/transfers/' + id),
        create: (data) => API.request('/transfers', 'POST', data),
        update: (id, data) => API.request('/transfers/' + id, 'PUT', data),
        approve: (id) => API.request('/transfers/' + id + '/approve', 'POST'),
        complete: (id) => API.request('/transfers/' + id + '/complete', 'POST'),
        reject: (id, reason) => API.request('/transfers/' + id + '/reject', 'POST', { reason }),
        cancel: (id, reason) => API.request('/transfers/' + id + '/cancel', 'POST', { reason }),
        export: (format = 'csv', params = {}) => {
            const query = new URLSearchParams({ format, ...params }).toString();
            return API.request('/transfers/export' + (query ? '?' + query : ''));
        },
        print: (id) => API.request('/transfers/' + id + '/print')
    },

    // ============================================================
    // المرتجعات (Returns)
    // ============================================================
    
    returns: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/returns' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/returns/' + id),
        create: (data) => API.request('/returns', 'POST', data),
        update: (id, data) => API.request('/returns/' + id, 'PUT', data),
        approve: (id) => API.request('/returns/' + id + '/approve', 'POST'),
        reject: (id, reason) => API.request('/returns/' + id + '/reject', 'POST', { reason }),
        cancel: (id, reason) => API.request('/returns/' + id + '/cancel', 'POST', { reason }),
        export: (format = 'csv', params = {}) => {
            const query = new URLSearchParams({ format, ...params }).toString();
            return API.request('/returns/export' + (query ? '?' + query : ''));
        },
        print: (id) => API.request('/returns/' + id + '/print')
    },

    // ============================================================
    // الجرد (Inventory)
    // ============================================================
    
    inventory: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/inventory/counts' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/inventory/counts/' + id),
        create: (data) => API.request('/inventory/counts', 'POST', data),
        addItem: (id, data) => API.request('/inventory/counts/' + id + '/items', 'POST', data),
        updateItem: (id, itemId, data) => {
            return API.request('/inventory/counts/' + id + '/items/' + itemId, 'PUT', data);
        },
        approve: (id) => API.request('/inventory/counts/' + id + '/approve', 'POST'),
        cancel: (id, reason) => API.request('/inventory/counts/' + id + '/cancel', 'POST', { reason }),
        export: (format = 'csv', params = {}) => {
            const query = new URLSearchParams({ format, ...params }).toString();
            return API.request('/inventory/export' + (query ? '?' + query : ''));
        }
    },

    // ============================================================
    // التقارير (Reports)
    // ============================================================
    
    reports: {
        stock: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/reports/stock' + (query ? '?' + query : ''));
        },
        movements: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/reports/movements' + (query ? '?' + query : ''));
        },
        product: (id, params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/reports/product/' + id + (query ? '?' + query : ''));
        },
        warehouse: (id, params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/reports/warehouse/' + id + (query ? '?' + query : ''));
        },
        audit: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/reports/audit' + (query ? '?' + query : ''));
        },
        summary: () => API.request('/reports/summary'),
        users: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/reports/users' + (query ? '?' + query : ''));
        },
        topProducts: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/reports/top-products' + (query ? '?' + query : ''));
        },
        inventoryValue: () => API.request('/reports/inventory-value'),
        byPeriod: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/reports/period' + (query ? '?' + query : ''));
        },
        byProduct: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/reports/by-product' + (query ? '?' + query : ''));
        },
        bySupplier: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/reports/by-supplier' + (query ? '?' + query : ''));
        }
    },

    // ============================================================
    // سجل التدقيق (Audit)
    // ============================================================
    
    audit: {
        logs: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/audit/logs' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/audit/logs/' + id),
        stats: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/audit/stats' + (query ? '?' + query : ''));
        },
        export: (format = 'csv', params = {}) => {
            const query = new URLSearchParams({ format, ...params }).toString();
            return API.request('/audit/export' + (query ? '?' + query : ''));
        },
        modules: () => API.request('/audit/modules'),
        actions: () => API.request('/audit/actions'),
        cleanup: (days) => API.request('/audit/cleanup', 'POST', { days })
    },

    // ============================================================
    // الإشعارات (Notifications)
    // ============================================================
    
    notifications: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/notifications' + (query ? '?' + query : ''));
        },
        get: (id) => API.request('/notifications/' + id),
        markAsRead: (id) => API.request('/notifications/' + id + '/read', 'POST'),
        markAllAsRead: () => API.request('/notifications/read-all', 'POST'),
        delete: (id) => API.request('/notifications/' + id, 'DELETE'),
        check: () => API.request('/notifications/check', 'POST'),
        stats: () => API.request('/notifications/stats')
    },

    // ============================================================
    // الإعدادات (Settings)
    // ============================================================
    
    settings: {
        list: (params = {}) => {
            const query = new URLSearchParams(params).toString();
            return API.request('/settings' + (query ? '?' + query : ''));
        },
        get: (key) => API.request('/settings/' + key),
        update: (key, value) => API.request('/settings/' + key, 'PUT', { value }),
        batchUpdate: (settings) => API.request('/settings/batch', 'POST', { settings }),
        reset: (key = null) => API.request('/settings/reset', 'POST', { key }),
        app: () => API.request('/settings/app'),
        security: () => API.request('/settings/security'),
        backup: () => API.request('/settings/backup')
    },

    // ============================================================
    // النسخ الاحتياطي (Backup)
    // ============================================================
    
    backup: {
        list: () => API.request('/backup'),
        create: (type = 'manual', compress = true, notes = null) => {
            return API.request('/backup/create', 'POST', { type, compress, notes });
        },
        restore: (id, confirm = true) => {
            return API.request('/backup/restore/' + id, 'POST', { confirm });
        },
        download: (id) => {
            const token = API.getToken();
            window.open(API.baseUrl + '/backup/download/' + id + '?token=' + token, '_blank');
        },
        delete: (id) => API.request('/backup/' + id, 'DELETE'),
        schedule: (enabled, time, frequency) => {
            return API.request('/backup/schedule', 'POST', { enabled, time, frequency });
        }
    },

    // ============================================================
    // دوال مساعدة للاستخدام السريع
    // ============================================================
    
    /**
     * جلب البيانات مع معالجة الخطأ تلقائياً
     */
    async safeRequest(endpoint, method = 'GET', data = null, options = {}) {
        try {
            return await this.request(endpoint, method, data, options);
        } catch (error) {
            console.error('Safe Request Error:', error);
            return null;
        }
    },

    /**
     * التحقق من صلاحية المستخدم
     */
    hasPermission(permission) {
        const user = this.getUser();
        if (!user) return false;
        if (user.role === 'admin') return true;
        return (user.permissions || []).includes(permission);
    },

    /**
     * التحقق من دور المستخدم
     */
    hasRole(role) {
        const user = this.getUser();
        if (!user) return false;
        return user.role === role;
    },

    /**
     * ✅ جلب الثيم من localStorage أو المستخدم
     */
    getCurrentTheme() {
        const saved = localStorage.getItem('app_theme');
        if (saved) return saved;
        const user = this.getUser();
        return user?.theme || 'dark';
    },

    /**
     * ✅ تطبيق الثيم على الصفحة
     */
    applyTheme(theme) {
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
        } else {
            root.style.setProperty('--bg-dark', '#0a0e1a');
            root.style.setProperty('--bg-card', 'rgba(255,255,255,0.03)');
            root.style.setProperty('--border-color', 'rgba(255,255,255,0.05)');
            root.style.setProperty('--text-primary', '#ffffff');
            root.style.setProperty('--text-secondary', 'rgba(255,255,255,0.6)');
            root.style.setProperty('--text-muted', 'rgba(255,255,255,0.3)');
            root.style.setProperty('--sidebar-bg', 'rgba(10,14,26,0.97)');
            root.style.setProperty('--input-bg', 'rgba(255,255,255,0.04)');
        }
        localStorage.setItem('app_theme', theme);
        
        // تحديث أيقونة الثيم
        const themeIcon = document.querySelector('#themeToggle i');
        if (themeIcon) {
            themeIcon.className = theme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
        }
    },

    /**
     * ✅ تبديل الثيم وحفظه في قاعدة البيانات
     */
    async toggleTheme() {
        const current = this.getCurrentTheme();
        const newTheme = current === 'dark' ? 'light' : 'dark';
        
        // تطبيق محلياً
        this.applyTheme(newTheme);
        
        // حفظ في قاعدة البيانات
        try {
            await this.updateTheme(newTheme);
            return true;
        } catch (error) {
            console.warn('Could not save theme to database:', error);
            return false;
        }
    }
};

// ============================================================
// تهيئة API
// ============================================================

API.init();

// ============================================================
// تصدير API للاستخدام العالمي
// ============================================================

if (typeof window !== 'undefined') {
    window.API = API;
}

console.log('%c✅ API v5.0 جاهز للاستخدام', 'color:#28a745;font-size:16px;font-weight:bold;');
console.log('%c🔗 Base URL: ' + API.baseUrl, 'color:#667eea;font-size:12px;');

// ============================================================
// انتهى الملف
// ============================================================

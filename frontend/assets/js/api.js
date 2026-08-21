/**
 * ============================================================
 * نظام إدارة المخازن والمخزون المتقدم
 * الملف: frontend/assets/js/api.js
 * الوصف: إدارة طلبات API - جميع نقاط النهاية
 * الإصدار: 5.0 Ultimate
 * ============================================================
 */

const API = {
    // ================================================================
    // الإعدادات الأساسية
    // ================================================================
    
    baseUrl: window.location.origin + '/api',
    timeout: 30000,
    retryAttempts: 3,
    retryDelay: 1000,
    
    // ================================================================
    // الرؤوس
    // ================================================================
    
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    },
    
    // ================================================================
    // إدارة التوكن
    // ================================================================
    
    getToken: function() {
        return localStorage.getItem('auth_token');
    },
    
    setToken: function(token) {
        localStorage.setItem('auth_token', token);
    },
    
    removeToken: function() {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('user');
        localStorage.removeItem('refresh_token');
    },
    
    getUser: function() {
        try {
            return JSON.parse(localStorage.getItem('user'));
        } catch {
            return null;
        }
    },
    
    setUser: function(user) {
        localStorage.setItem('user', JSON.stringify(user));
    },
    
    getRefreshToken: function() {
        return localStorage.getItem('refresh_token');
    },
    
    setRefreshToken: function(token) {
        localStorage.setItem('refresh_token', token);
    },
    
    // ================================================================
    // بناء الرؤوس
    // ================================================================
    
    getHeaders: function() {
        const headers = { ...this.headers };
        const token = this.getToken();
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }
        return headers;
    },
    
    // ================================================================
    // معالجة الاستجابة
    // ================================================================
    
    handleResponse: async function(response, retryCount = 0) {
        if (response.status === 401) {
            if (retryCount < this.retryAttempts) {
                const refreshed = await this.refreshToken();
                if (refreshed) {
                    const newResponse = await fetch(response.url, {
                        method: response.method || 'GET',
                        headers: this.getHeaders(),
                        body: response._bodyInit || null
                    });
                    return this.handleResponse(newResponse, retryCount + 1);
                }
            }
            this.removeToken();
            window.location.href = '/frontend/pages/login.html';
            throw new Error('جلسة غير صالحة. يرجى تسجيل الدخول مرة أخرى.');
        }
        
        if (response.status === 429) {
            throw new Error('تم تجاوز الحد الأقصى للطلبات. حاول مرة أخرى لاحقاً.');
        }
        
        if (response.status === 403) {
            throw new Error('ليس لديك صلاحية للقيام بهذه العملية.');
        }
        
        if (response.status === 404) {
            throw new Error('المسار غير موجود أو العنصر غير متوفر.');
        }
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.message || 'حدث خطأ في الطلب');
        }
        
        if (!data.success) {
            throw new Error(data.message || 'حدث خطأ في الطلب');
        }
        
        return data;
    },
    
    // ================================================================
    // تجديد التوكن
    // ================================================================
    
    refreshToken: async function() {
        try {
            const refreshToken = this.getRefreshToken();
            if (!refreshToken) return false;
            
            const response = await fetch(this.baseUrl + '/auth/refresh', {
                method: 'POST',
                headers: this.headers,
                body: JSON.stringify({ refresh_token: refreshToken })
            });
            
            if (!response.ok) return false;
            
            const data = await response.json();
            if (data.success && data.data) {
                this.setToken(data.data.token);
                if (data.data.refresh_token) {
                    this.setRefreshToken(data.data.refresh_token);
                }
                return true;
            }
            return false;
            
        } catch (error) {
            console.error('Refresh token error:', error);
            return false;
        }
    },
    
    // ================================================================
    // الطلبات الأساسية
    // ================================================================
    
    request: async function(method, endpoint, data = null, options = {}) {
        const url = this.baseUrl + endpoint;
        const config = {
            method: method,
            headers: this.getHeaders(),
            ...options
        };
        
        if (data && method !== 'GET' && method !== 'DELETE') {
            config.body = JSON.stringify(data);
        }
        
        if (method === 'GET' && data) {
            const params = new URLSearchParams(data);
            return this.request(method, endpoint + '?' + params.toString(), null, options);
        }
        
        const response = await fetch(url, config);
        return this.handleResponse(response);
    },
    
    get: async function(endpoint, params = null) {
        return this.request('GET', endpoint, params);
    },
    
    post: async function(endpoint, data = {}) {
        return this.request('POST', endpoint, data);
    },
    
    put: async function(endpoint, data = {}) {
        return this.request('PUT', endpoint, data);
    },
    
    patch: async function(endpoint, data = {}) {
        return this.request('PATCH', endpoint, data);
    },
    
    delete: async function(endpoint) {
        return this.request('DELETE', endpoint);
    },
    
    // ================================================================
    // مسارات المصادقة
    // ================================================================
    
    auth: {
        login: async function(username, password, deviceName = null) {
            return API.post('/auth/login', {
                username,
                password,
                device_name: deviceName || navigator.userAgent
            });
        },
        
        logout: async function() {
            return API.post('/auth/logout');
        },
        
        validate: async function() {
            return API.get('/auth/validate');
        },
        
        refresh: async function() {
            return API.post('/auth/refresh', {
                refresh_token: API.getRefreshToken()
            });
        },
        
        me: async function() {
            return API.get('/auth/me');
        },
        
        changePassword: async function(currentPassword, newPassword, confirmPassword) {
            return API.post('/auth/change-password', {
                current_password: currentPassword,
                new_password: newPassword,
                confirm_password: confirmPassword
            });
        },
        
        forgotPassword: async function(email) {
            return API.post('/auth/forgot-password', { email });
        },
        
        resetPassword: async function(token, password, confirmPassword) {
            return API.post('/auth/reset-password', {
                token,
                password,
                confirm_password: confirmPassword
            });
        },
        
        sessions: async function() {
            return API.get('/auth/sessions');
        },
        
        terminateSession: async function(sessionId) {
            return API.post('/auth/sessions/terminate', { session_id: sessionId });
        },
        
        terminateAllSessions: async function() {
            return API.post('/auth/sessions/terminate-all');
        }
    },
    
    // ================================================================
    // مسارات لوحة التحكم
    // ================================================================
    
    dashboard: {
        get: async function() {
            return API.get('/dashboard');
        },
        
        stats: async function() {
            // محاكاة بيانات الإحصائيات
            return {
                success: true,
                data: {
                    warehouses: 5,
                    products: 38,
                    users: 6,
                    total_transfers: 42,
                    daily_transfers: 3,
                    low_stock: 7,
                    last_update: 'منذ 5 دقائق',
                    categories: 8,
                    units: 4,
                    receipts: 12,
                    issues: 8,
                    returns: 3
                }
            };
        },
        
        charts: async function() {
            // محاكاة بيانات الرسوم البيانية
            return {
                success: true,
                data: {
                    weekly: [
                        { day: '2026-08-15', count: 45, day_name: 'Saturday' },
                        { day: '2026-08-16', count: 58, day_name: 'Sunday' },
                        { day: '2026-08-17', count: 32, day_name: 'Monday' },
                        { day: '2026-08-18', count: 72, day_name: 'Tuesday' },
                        { day: '2026-08-19', count: 42, day_name: 'Wednesday' },
                        { day: '2026-08-20', count: 64, day_name: 'Thursday' },
                        { day: '2026-08-21', count: 52, day_name: 'Friday' }
                    ]
                }
            };
        },
        
        alerts: async function() {
            return {
                success: true,
                data: [
                    { id: 1, message: 'تنبيه: صنف XYZ منخفض', type: 'warning' },
                    { id: 2, message: 'تم إضافة مستخدم جديد', type: 'info' }
                ]
            };
        },
        
        activities: async function() {
            return {
                success: true,
                data: [
                    { id: 1, user_name: 'أحمد', action: 'add', entity: 'products', date: new Date().toISOString(), time: 'منذ 5 دقائق' },
                    { id: 2, user_name: 'محمد', action: 'transfer', entity: 'warehouses', date: new Date().toISOString(), time: 'منذ 15 دقيقة' },
                    { id: 3, user_name: 'سارة', action: 'update', entity: 'products', date: new Date().toISOString(), time: 'منذ 32 دقيقة' },
                    { id: 4, user_name: 'علي', action: 'delete', entity: 'products', date: new Date().toISOString(), time: 'منذ ساعة' },
                    { id: 5, user_name: 'نورة', action: 'login', entity: 'auth', date: new Date().toISOString(), time: 'منذ ساعتين' }
                ]
            };
        },
        
        status: async function() {
            return API.get('/dashboard/status');
        }
    },
    
    // ================================================================
    // مسارات الأصناف
    // ================================================================
    
    products: {
        list: async function(params = {}) {
            return API.get('/products', params);
        },
        
        get: async function(id) {
            return API.get('/products/' + id);
        },
        
        create: async function(data) {
            return API.post('/products', data);
        },
        
        update: async function(id, data) {
            return API.put('/products/' + id, data);
        },
        
        delete: async function(id) {
            return API.delete('/products/' + id);
        },
        
        categories: async function() {
            return API.get('/products/categories');
        },
        
        units: async function() {
            return API.get('/products/units');
        },
        
        balances: async function(id) {
            return API.get('/products/' + id + '/balances');
        },
        
        history: async function(id, params = {}) {
            return API.get('/products/' + id + '/history', params);
        },
        
        barcode: async function(id) {
            return API.get('/products/' + id + '/barcode');
        },
        
        bulkImport: async function(products) {
            return API.post('/products/bulk-import', { products });
        },
        
        export: async function(params = {}) {
            return API.get('/products/export', params);
        }
    },
    
    // ================================================================
    // مسارات المخازن
    // ================================================================
    
    warehouses: {
        list: async function(params = {}) {
            return API.get('/warehouses', params);
        },
        
        get: async function(id) {
            return API.get('/warehouses/' + id);
        },
        
        create: async function(data) {
            return API.post('/warehouses', data);
        },
        
        update: async function(id, data) {
            return API.put('/warehouses/' + id, data);
        },
        
        delete: async function(id) {
            return API.delete('/warehouses/' + id);
        },
        
        stock: async function(id, params = {}) {
            return API.get('/warehouses/' + id + '/stock', params);
        },
        
        report: async function(id, params = {}) {
            return API.get('/warehouses/' + id + '/report', params);
        },
        
        sub: async function(id) {
            return API.get('/warehouses/' + id + '/sub');
        }
    },
    
    // ================================================================
    // مسارات المستخدمين
    // ================================================================
    
    users: {
        list: async function(params = {}) {
            return API.get('/users', params);
        },
        
        get: async function(id) {
            return API.get('/users/' + id);
        },
        
        me: async function() {
            return API.get('/users/me');
        },
        
        create: async function(data) {
            return API.post('/users', data);
        },
        
        update: async function(id, data) {
            return API.put('/users/' + id, data);
        },
        
        delete: async function(id) {
            return API.delete('/users/' + id);
        },
        
        restore: async function(id) {
            return API.post('/users/' + id + '/restore');
        },
        
        lock: async function(id) {
            return API.post('/users/' + id + '/lock');
        },
        
        unlock: async function(id) {
            return API.post('/users/' + id + '/unlock');
        },
        
        permissions: async function(id, data) {
            return API.put('/users/' + id + '/permissions', data);
        },
        
        getPermissions: async function(id) {
            return API.get('/users/' + id + '/permissions');
        },
        
        changePassword: async function(id, data) {
            return API.post('/users/' + id + '/change-password', data);
        },
        
        activities: async function(id, params = {}) {
            return API.get('/users/' + id + '/activities', params);
        },
        
        sessions: async function(id) {
            return API.get('/users/' + id + '/sessions');
        }
    },
    
    // ================================================================
    // مسارات التقارير
    // ================================================================
    
    reports: {
        stock: async function(params = {}) {
            return API.get('/reports/stock', params);
        },
        
        movements: async function(params = {}) {
            return API.get('/reports/movements', params);
        },
        
        product: async function(id, params = {}) {
            return API.get('/reports/product/' + id, params);
        },
        
        warehouse: async function(id, params = {}) {
            return API.get('/reports/warehouse/' + id, params);
        },
        
        audit: async function(params = {}) {
            return API.get('/reports/audit', params);
        },
        
        summary: async function() {
            return API.get('/reports/summary');
        },
        
        topProducts: async function(params = {}) {
            return API.get('/reports/top-products', params);
        },
        
        inventoryValue: async function() {
            return API.get('/reports/inventory-value');
        },
        
        users: async function(params = {}) {
            return API.get('/reports/users', params);
        }
    },
    
    // ================================================================
    // مسارات الاستلام
    // ================================================================
    
    receipts: {
        list: async function(params = {}) {
            return API.get('/receipts', params);
        },
        
        get: async function(id) {
            return API.get('/receipts/' + id);
        },
        
        create: async function(data) {
            return API.post('/receipts', data);
        },
        
        update: async function(id, data) {
            return API.put('/receipts/' + id, data);
        },
        
        approve: async function(id) {
            return API.post('/receipts/' + id + '/approve');
        },
        
        reject: async function(id, reason = null) {
            return API.post('/receipts/' + id + '/reject', { reason });
        },
        
        cancel: async function(id, reason = null) {
            return API.post('/receipts/' + id + '/cancel', { reason });
        },
        
        export: async function(params = {}) {
            return API.get('/receipts/export', params);
        }
    },
    
    // ================================================================
    // مسارات الصرف
    // ================================================================
    
    issues: {
        list: async function(params = {}) {
            return API.get('/issues', params);
        },
        
        get: async function(id) {
            return API.get('/issues/' + id);
        },
        
        create: async function(data) {
            return API.post('/issues', data);
        },
        
        update: async function(id, data) {
            return API.put('/issues/' + id, data);
        },
        
        approve: async function(id) {
            return API.post('/issues/' + id + '/approve');
        },
        
        deliver: async function(id) {
            return API.post('/issues/' + id + '/deliver');
        },
        
        reject: async function(id, reason = null) {
            return API.post('/issues/' + id + '/reject', { reason });
        },
        
        cancel: async function(id, reason = null) {
            return API.post('/issues/' + id + '/cancel', { reason });
        },
        
        export: async function(params = {}) {
            return API.get('/issues/export', params);
        }
    },
    
    // ================================================================
    // مسارات التحويلات
    // ================================================================
    
    transfers: {
        list: async function(params = {}) {
            return API.get('/transfers', params);
        },
        
        get: async function(id) {
            return API.get('/transfers/' + id);
        },
        
        create: async function(data) {
            return API.post('/transfers', data);
        },
        
        update: async function(id, data) {
            return API.put('/transfers/' + id, data);
        },
        
        approve: async function(id) {
            return API.post('/transfers/' + id + '/approve');
        },
        
        complete: async function(id) {
            return API.post('/transfers/' + id + '/complete');
        },
        
        reject: async function(id, reason = null) {
            return API.post('/transfers/' + id + '/reject', { reason });
        },
        
        cancel: async function(id, reason = null) {
            return API.post('/transfers/' + id + '/cancel', { reason });
        },
        
        export: async function(params = {}) {
            return API.get('/transfers/export', params);
        }
    },
    
    // ================================================================
    // مسارات المرتجعات
    // ================================================================
    
    returns: {
        list: async function(params = {}) {
            return API.get('/returns', params);
        },
        
        get: async function(id) {
            return API.get('/returns/' + id);
        },
        
        create: async function(data) {
            return API.post('/returns', data);
        },
        
        approve: async function(id) {
            return API.post('/returns/' + id + '/approve');
        },
        
        reject: async function(id, reason = null) {
            return API.post('/returns/' + id + '/reject', { reason });
        },
        
        cancel: async function(id, reason = null) {
            return API.post('/returns/' + id + '/cancel', { reason });
        },
        
        export: async function(params = {}) {
            return API.get('/returns/export', params);
        }
    },
    
    // ================================================================
    // مسارات التنبيهات
    // ================================================================
    
    notifications: {
        list: async function(params = {}) {
            return API.get('/notifications', params);
        },
        
        get: async function(id) {
            return API.get('/notifications/' + id);
        },
        
        markAsRead: async function(id) {
            return API.post('/notifications/' + id + '/read');
        },
        
        markAllAsRead: async function() {
            return API.post('/notifications/read-all');
        },
        
        delete: async function(id) {
            return API.delete('/notifications/' + id);
        },
        
        check: async function() {
            return API.post('/notifications/check');
        }
    },
    
    // ================================================================
    // مسارات الإعدادات
    // ================================================================
    
    settings: {
        list: async function() {
            return API.get('/settings');
        },
        
        get: async function(key) {
            return API.get('/settings/' + key);
        },
        
        update: async function(key, value) {
            return API.put('/settings/' + key, { value });
        },
        
        batchUpdate: async function(settings) {
            return API.post('/settings/batch', { settings });
        },
        
        reset: async function() {
            return API.post('/settings/reset');
        }
    },
    
    // ================================================================
    // مسارات النسخ الاحتياطي
    // ================================================================
    
    backup: {
        list: async function() {
            return API.get('/backup');
        },
        
        create: async function() {
            return API.post('/backup/create');
        },
        
        restore: async function(id) {
            return API.post('/backup/restore/' + id);
        },
        
        download: async function(id) {
            return API.get('/backup/download/' + id);
        },
        
        delete: async function(id) {
            return API.delete('/backup/' + id);
        }
    },
    
    // ================================================================
    // مسارات الجرد
    // ================================================================
    
    inventory: {
        counts: async function(params = {}) {
            return API.get('/inventory/counts', params);
        },
        
        getCount: async function(id) {
            return API.get('/inventory/counts/' + id);
        },
        
        createCount: async function(data) {
            return API.post('/inventory/counts', data);
        },
        
        addItem: async function(id, data) {
            return API.post('/inventory/counts/' + id + '/items', data);
        },
        
        updateItem: async function(id, itemId, data) {
            return API.put('/inventory/counts/' + id + '/items/' + itemId, data);
        },
        
        approveCount: async function(id) {
            return API.post('/inventory/counts/' + id + '/approve');
        },
        
        cancelCount: async function(id) {
            return API.post('/inventory/counts/' + id + '/cancel');
        },
        
        export: async function(params = {}) {
            return API.get('/inventory/export', params);
        }
    },
    
    // ================================================================
    // مسارات الأدوات المساعدة
    // ================================================================
    
    utils: {
        health: async function() {
            return API.get('/health');
        },
        
        test: async function() {
            return API.get('/test');
        },
        
        search: async function(query, type = 'all') {
            return API.get('/search', { q: query, type });
        },
        
        upload: async function(file, type = 'image') {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('type', type);
            
            return fetch(API.baseUrl + '/upload', {
                method: 'POST',
                headers: {
                    'Authorization': 'Bearer ' + API.getToken()
                },
                body: formData
            }).then(r => r.json());
        }
    }
};

// ================================================================
// تصدير API
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = API;
}

console.log('%c✅ API تم تحميلها بنجاح', 'font-size:14px;color:#28a745;');
console.log('%c🔗 Base URL: ' + API.baseUrl, 'font-size:12px;color:#666;');

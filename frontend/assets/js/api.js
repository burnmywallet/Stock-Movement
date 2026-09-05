/**
 * ================================================================
 * Logistox - عميل API المتقدم
 * نظام إدارة المخازن والمخزون v5.0
 * ================================================================
 */

// منع التكرار
if (typeof window.Api === 'undefined') {

class ApiClient {
    constructor() {
        this.baseUrl = window.APP_CONFIG?.API?.BASE_URL || '/api';
        this.token = localStorage.getItem('auth_token') || null;
        this.defaultHeaders = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };
    }

    // ================================================================
    // إدارة التوكن
    // ================================================================
    setToken(token) {
        this.token = token;
        if (token) {
            localStorage.setItem('auth_token', token);
        } else {
            localStorage.removeItem('auth_token');
            localStorage.removeItem('user');
        }
    }

    getToken() {
        return this.token || localStorage.getItem('auth_token') || null;
    }

    isAuthenticated() {
        return !!this.getToken();
    }

    // ================================================================
    // إعداد الهيدرز
    // ================================================================
    getHeaders(includeAuth = true) {
        const headers = { ...this.defaultHeaders };
        
        if (includeAuth) {
            const token = this.getToken();
            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            }
        }
        
        return headers;
    }

    // ================================================================
    // الطلب الأساسي
    // ================================================================
    async request(method, endpoint, data = null, options = {}) {
        const url = this.baseUrl + endpoint;
        const config = {
            method: method,
            headers: this.getHeaders(options.includeAuth !== false)
        };

        if (data && ['POST', 'PUT', 'PATCH'].includes(method)) {
            config.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(url, config);
            
            // إذا كانت الجلسة غير صالحة
            if (response.status === 401) {
                this.setToken(null);
                localStorage.removeItem('user');
                if (window.location.pathname !== '/inventory-system/frontend/pages/login.html') {
                    window.location.href = '/inventory-system/frontend/pages/login.html';
                }
                throw new Error('انتهت الجلسة. يرجى تسجيل الدخول مرة أخرى.');
            }

            // قراءة الاستجابة
            let result;
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                result = await response.json();
            } else {
                const text = await response.text();
                try {
                    result = JSON.parse(text);
                } catch (e) {
                    throw new Error('استجابة غير صالحة من الخادم');
                }
            }

            // التحقق من النجاح
            if (!response.ok || !result.success) {
                throw new Error(result.message || `خطأ ${response.status}: ${response.statusText}`);
            }

            return result.data || result;

        } catch (error) {
            if (error.message === 'Failed to fetch') {
                throw new Error('تعذر الاتصال بالخادم. تأكد من تشغيل الخدمة.');
            }
            throw error;
        }
    }

    // ================================================================
    // طرق HTTP الأساسية
    // ================================================================
    get(endpoint, options = {}) {
        return this.request('GET', endpoint, null, options);
    }

    post(endpoint, data, options = {}) {
        return this.request('POST', endpoint, data, options);
    }

    put(endpoint, data, options = {}) {
        return this.request('PUT', endpoint, data, options);
    }

    delete(endpoint, options = {}) {
        return this.request('DELETE', endpoint, null, options);
    }

    patch(endpoint, data, options = {}) {
        return this.request('PATCH', endpoint, data, options);
    }

    // ================================================================
    // المصادقة
    // ================================================================

    // تسجيل الدخول
    async login(username, password, remember = false) {
        try {
            const result = await this.post('/auth/login', { username, password, remember });
            this.setToken(result.token);
            localStorage.setItem('user', JSON.stringify(result.user));
            return result;
        } catch (error) {
            throw error;
        }
    }

    // تسجيل الخروج
    async logout() {
        try {
            await this.post('/auth/logout');
        } catch (e) {
            // تجاهل الأخطاء عند الخروج
        }
        this.setToken(null);
    }

    // التحقق من الجلسة
    async me() {
        return this.get('/auth/me');
    }

    // استرجاع كلمة المرور
    async forgotPassword(email) {
        return this.post('/auth/forgot-password', { email });
    }

    // التحقق من إجابات الأمان
    async verifySecurityAnswers(data) {
        return this.post('/auth/verify-security-answers', data);
    }

    // إعادة تعيين كلمة المرور
    async resetPassword(data) {
        return this.post('/auth/reset-password', data);
    }

    // ================================================================
    // لوحة التحكم
    // ================================================================
    async getDashboardStats() {
        return this.get('/dashboard/stats');
    }

    async getDashboardActivities() {
        return this.get('/dashboard/activities');
    }

    // ================================================================
    // الأصناف
    // ================================================================
    async getProducts(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return this.get('/products' + (queryString ? '?' + queryString : ''));
    }

    async getProduct(id) {
        return this.get('/products/' + id);
    }

    async createProduct(data) {
        return this.post('/products', data);
    }

    async updateProduct(id, data) {
        return this.put('/products/' + id, data);
    }

    async deleteProduct(id) {
        return this.delete('/products/' + id);
    }

    // ================================================================
    // المخازن
    // ================================================================
    async getWarehouses(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return this.get('/warehouses' + (queryString ? '?' + queryString : ''));
    }

    async getWarehouse(id) {
        return this.get('/warehouses/' + id);
    }

    async createWarehouse(data) {
        return this.post('/warehouses', data);
    }

    async updateWarehouse(id, data) {
        return this.put('/warehouses/' + id, data);
    }

    async deleteWarehouse(id) {
        return this.delete('/warehouses/' + id);
    }

    // ================================================================
    // المستخدمون
    // ================================================================
    async getUsers() {
        return this.get('/users');
    }

    async getUser(id) {
        return this.get('/users/' + id);
    }

    async createUser(data) {
        return this.post('/users', data);
    }

    async updateUser(id, data) {
        return this.put('/users/' + id, data);
    }

    async deleteUser(id) {
        return this.delete('/users/' + id);
    }

    // ================================================================
    // التصنيفات
    // ================================================================
    async getCategories() {
        return this.get('/categories');
    }

    async createCategory(data) {
        return this.post('/categories', data);
    }

    async updateCategory(id, data) {
        return this.put('/categories/' + id, data);
    }

    async deleteCategory(id) {
        return this.delete('/categories/' + id);
    }

    // ================================================================
    // الوحدات
    // ================================================================
    async getUnits() {
        return this.get('/units');
    }

    async createUnit(data) {
        return this.post('/units', data);
    }

    async updateUnit(id, data) {
        return this.put('/units/' + id, data);
    }

    async deleteUnit(id) {
        return this.delete('/units/' + id);
    }

    // ================================================================
    // الحركات
    // ================================================================
    async getMovements(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return this.get('/movements' + (queryString ? '?' + queryString : ''));
    }

    async createMovement(data) {
        return this.post('/movements', data);
    }

    // ================================================================
    // الأرصدة
    // ================================================================
    async getStockBalances() {
        return this.get('/stock-balances');
    }

    async getStockBalance(productId, warehouseId) {
        return this.get(`/stock-balances?product_id=${productId}&warehouse_id=${warehouseId}`);
    }

    // ================================================================
    // الإشعارات
    // ================================================================
    async getNotifications() {
        return this.get('/notifications');
    }

    async markNotificationRead(id) {
        return this.post('/notifications/' + id + '/read');
    }

    async markAllNotificationsRead() {
        return this.post('/notifications/read-all');
    }

    // ================================================================
    // التقارير
    // ================================================================
    async getReport(type, params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return this.get('/reports/' + type + (queryString ? '?' + queryString : ''));
    }

    async exportReport(type, format, params = {}) {
        const queryString = new URLSearchParams({ ...params, format }).toString();
        const token = this.getToken();
        
        const response = await fetch(`${this.baseUrl}/reports/${type}/export?${queryString}`, {
            headers: {
                'Authorization': 'Bearer ' + token
            }
        });
        
        if (!response.ok) {
            throw new Error('خطأ في تصدير التقرير');
        }
        
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `report_${type}_${new Date().getTime()}.${format}`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
    }

    // ================================================================
    // سجل التدقيق
    // ================================================================
    async getAuditLogs(params = {}) {
        const queryString = new URLSearchParams(params).toString();
        return this.get('/audit' + (queryString ? '?' + queryString : ''));
    }

    // ================================================================
    // الإعدادات
    // ================================================================
    async getSettings() {
        return this.get('/settings');
    }

    async saveSettings(data) {
        return this.post('/settings', data);
    }

    // ================================================================
    // الأدوار والصلاحيات
    // ================================================================
    async getRoles() {
        return this.get('/roles');
    }

    async getPermissions() {
        return this.get('/permissions');
    }

    async getRolePermissions() {
        return this.get('/permissions/roles');
    }

    async updateRolePermissions(data) {
        return this.post('/permissions/update', data);
    }

    // ================================================================
    // الثيمات
    // ================================================================
    async getThemes() {
        return this.get('/themes');
    }

    // ================================================================
    // المرتجعات
    // ================================================================
    async getReturns() {
        return this.get('/returns');
    }

    async createReturn(data) {
        return this.post('/returns', data);
    }

    // ================================================================
    // النسخ الاحتياطي
    // ================================================================
    async getBackups() {
        return this.get('/backup');
    }

    async createBackup() {
        return this.post('/backup');
    }

    // ================================================================
    // إذونات الاستلام
    // ================================================================
    async getReceipts() {
        return this.get('/receipts');
    }

    async createReceipt(data) {
        return this.post('/receipts', data);
    }

    async approveReceipt(id) {
        return this.post('/receipts/' + id + '/approve');
    }

    // ================================================================
    // إذونات الصرف
    // ================================================================
    async getIssues() {
        return this.get('/issues');
    }

    async createIssue(data) {
        return this.post('/issues', data);
    }

    async approveIssue(id) {
        return this.post('/issues/' + id + '/approve');
    }

    // ================================================================
    // التحويلات
    // ================================================================
    async getTransfers() {
        return this.get('/transfers');
    }

    async createTransfer(data) {
        return this.post('/transfers', data);
    }

    async approveTransfer(id) {
        return this.post('/transfers/' + id + '/approve');
    }

    // ================================================================
    // تصدير البيانات
    // ================================================================
    async exportData(type, format = 'csv') {
        try {
            const response = await fetch(`${this.baseUrl}/export/${type}?format=${format}`, {
                headers: this.getHeaders()
            });
            
            if (!response.ok) {
                throw new Error('خطأ في تصدير البيانات');
            }
            
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${type}_export_${new Date().getTime()}.${format}`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(url);
            
            return true;
        } catch (error) {
            throw error;
        }
    }

    // ================================================================
    // بحث شامل
    // ================================================================
    async globalSearch(query, modules = []) {
        const params = new URLSearchParams({ query, modules: modules.join(',') });
        return this.get('/search?' + params.toString());
    }

    // ================================================================
    // رفع ملف
    // ================================================================
    async uploadFile(file, endpoint = '/upload') {
        const formData = new FormData();
        formData.append('file', file);
        
        const token = this.getToken();
        const response = await fetch(this.baseUrl + endpoint, {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + token
            },
            body: formData
        });
        
        const result = await response.json();
        
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'خطأ في رفع الملف');
        }
        
        return result.data;
    }

    // ================================================================
    // قراءة الباركود
    // ================================================================
    async getProductByBarcode(barcode) {
        return this.get('/products/barcode/' + barcode);
    }

    // ================================================================
    // تنزيل ملف
    // ================================================================
    async downloadFile(endpoint, filename) {
        const token = this.getToken();
        const response = await fetch(this.baseUrl + endpoint, {
            headers: {
                'Authorization': 'Bearer ' + token
            }
        });
        
        if (!response.ok) {
            throw new Error('خطأ في تنزيل الملف');
        }
        
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
    }
}

// إنشاء نسخة عالمية
window.Api = new ApiClient();

} // نهاية منع التكرار

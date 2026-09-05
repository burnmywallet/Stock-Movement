/**
 * ============================================================================
 * Logistox - Advanced API Client v5.1
 * نظام إدارة المخازن والمخزون
 * ============================================================================
 *
 * المميزات:
 * - Bearer Token Authentication
 * - Password Recovery (Forgot/Reset)
 * - Advanced Error Handling
 * - Request/Response Interceptors
 * - Timeout & Retry Logic
 * - Global Event System
 * ============================================================================
 */

const API = (() => {
    'use strict';

    // ========================================================================
    // Configuration
    // ========================================================================
    const CONFIG = {
        baseURL: '/inventory-system/backend/public/api',
        timeout: 30000,
        maxRetries: 2,
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
    };

    // ========================================================================
    // Event System (للتنبيهات المتقدمة)
    // ========================================================================
    const events = {
        listeners: {},
        on(event, callback) {
            if (!this.listeners[event]) this.listeners[event] = [];
            this.listeners[event].push(callback);
        },
        off(event, callback) {
            if (!this.listeners[event]) return;
            this.listeners[event] = this.listeners[event].filter(cb => cb !== callback);
        },
        emit(event, data) {
            if (!this.listeners[event]) return;
            this.listeners[event].forEach(cb => {
                try { cb(data); } catch (e) { console.error('Event error:', e); }
            });
        }
    };

    // ========================================================================
    // Token & User Management
    // ========================================================================
    const storage = {
        get: (key) => {
            try { return localStorage.getItem(key); } catch { return null; }
        },
        set: (key, value) => {
            try { localStorage.setItem(key, value); } catch { console.warn('Storage full'); }
        },
        remove: (key) => {
            try { localStorage.removeItem(key); } catch {}
        },
        getJSON: (key) => {
            const val = storage.get(key);
            if (!val) return null;
            try { return JSON.parse(val); } catch { return null; }
        },
        setJSON: (key, value) => {
            storage.set(key, JSON.stringify(value));
        }
    };

    const getToken = () => storage.get('logistox_token');
    const setToken = (token) => storage.set('logistox_token', token);
    const clearToken = () => storage.remove('logistox_token');
    
    const getUser = () => storage.getJSON('logistox_user');
    const setUser = (user) => storage.setJSON('logistox_user', user);
    const clearUser = () => storage.remove('logistox_user');

    const getPermissions = () => storage.getJSON('logistox_permissions') || [];
    const setPermissions = (perms) => storage.setJSON('logistox_permissions', perms);
    const clearPermissions = () => storage.remove('logistox_permissions');

    // ========================================================================
    // Request Builder
    // ========================================================================
    const buildHeaders = (customHeaders = {}) => {
        const headers = { ...CONFIG.headers, ...customHeaders };
        const token = getToken();
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }
        return headers;
    };

    const buildURL = (endpoint, params = {}) => {
        const url = new URL(`${CONFIG.baseURL}${endpoint}`, window.location.origin);
        Object.keys(params).forEach(key => {
            const val = params[key];
            if (val !== null && val !== undefined && val !== '') {
                url.searchParams.append(key, val);
            }
        });
        return url.toString();
    };

    // ========================================================================
    // Retry Logic
    // ========================================================================
    const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    const fetchWithRetry = async (url, options, retries = CONFIG.maxRetries) => {
        for (let i = 0; i <= retries; i++) {
            try {
                const response = await fetch(url, options);
                return response;
            } catch (error) {
                if (i === retries) throw error;
                if (error.name === 'AbortError') throw error;
                await sleep(1000 * (i + 1));
            }
        }
    };

    // ========================================================================
    // Core Request Function
    // ========================================================================
    const request = async (method, endpoint, options = {}) => {
        const { 
            data = null, 
            params = {}, 
            headers = {}, 
            timeout = CONFIG.timeout,
            skipAuth = false,
            raw = false
        } = options;

        // Emit request start
        events.emit('request:start', { method, endpoint });

        const url = buildURL(endpoint, params);
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        try {
            const fetchOptions = {
                method,
                headers: skipAuth ? CONFIG.headers : buildHeaders(headers),
                signal: controller.signal,
                credentials: 'same-origin',
            };

            if (data !== null) {
                fetchOptions.body = data instanceof FormData ? data : JSON.stringify(data);
                if (!(data instanceof FormData)) {
                    fetchOptions.headers['Content-Type'] = 'application/json';
                }
            }

            const response = await fetchWithRetry(url, fetchOptions);
            clearTimeout(timeoutId);

            // Parse response
            const contentType = response.headers.get('content-type') || '';
            let responseData;

            if (raw) {
                responseData = response;
            } else if (contentType.includes('application/json')) {
                responseData = await response.json();
            } else {
                responseData = await response.text();
            }

            // Handle errors
            if (!response.ok) {
                // 401: Unauthorized (تجاهلها في صفحة login)
                if (response.status === 401 && !endpoint.includes('/auth/login')) {
                    clearToken();
                    clearUser();
                    clearPermissions();
                    
                    if (window.location.pathname.includes('login.html') === false) {
                        events.emit('auth:expired');
                        window.location.href = '/inventory-system/frontend/login.html?expired=1';
                    }
                    throw new Error('انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى.');
                }

                // 403: Forbidden
                if (response.status === 403) {
                    throw new Error(responseData?.message || 'لا تملك صلاحية للوصول إلى هذا المورد');
                }

                // 404: Not Found
                if (response.status === 404) {
                    throw new Error(responseData?.message || 'المورد المطلوب غير موجود');
                }

                // 422: Validation Error
                if (response.status === 422) {
                    const error = new Error(responseData?.message || 'بيانات غير صالحة');
                    error.validationErrors = responseData?.errors || {};
                    error.code = 'VALIDATION_ERROR';
                    throw error;
                }

                // 500: Server Error
                if (response.status >= 500) {
                    throw new Error(responseData?.message || 'خطأ في الخادم. يرجى المحاولة لاحقاً');
                }

                // Other errors
                throw new Error(responseData?.message || `خطأ في الخادم (${response.status})`);
            }

            // Emit success
            events.emit('request:success', { method, endpoint, data: responseData });

            return responseData;

        } catch (error) {
            clearTimeout(timeoutId);

            if (error.name === 'AbortError') {
                throw new Error('انتهت مهلة الطلب. يرجى المحاولة مرة أخرى.');
            }

            if (error.name === 'TypeError' && error.message.includes('fetch')) {
                throw new Error('لا يمكن الاتصال بالخادم. تحقق من اتصالك بالإنترنت.');
            }

            events.emit('request:error', { method, endpoint, error });
            throw error;
        }
    };

    // ========================================================================
    // Public API Methods
    // ========================================================================
    const api = {
        // HTTP Methods
        get: (endpoint, params = {}, options = {}) => 
            request('GET', endpoint, { ...options, params }),
        
        post: (endpoint, data = null, options = {}) => 
            request('POST', endpoint, { ...options, data }),
        
        put: (endpoint, data = null, options = {}) => 
            request('PUT', endpoint, { ...options, data }),
        
        patch: (endpoint, data = null, options = {}) => 
            request('PATCH', endpoint, { ...options, data }),
        
        delete: (endpoint, options = {}) => 
            request('DELETE', endpoint, options),

        // File Upload
        upload: (endpoint, formData, options = {}) =>
            request('POST', endpoint, { ...options, data: formData }),

        // Download
        download: async (endpoint, params = {}, filename = 'download') => {
            const url = buildURL(endpoint, params);
            const response = await fetch(url, {
                headers: buildHeaders(),
                credentials: 'same-origin',
            });

            if (!response.ok) throw new Error('فشل في تحميل الملف');

            const blob = await response.blob();
            const blobUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(blobUrl);
        },

        // Auth Management
        getToken,
        setToken,
        clearToken,
        getUser,
        setUser,
        clearUser,
        getPermissions,
        setPermissions,
        clearPermissions,
        isAuthenticated: () => !!getToken(),

        // Password Recovery
        forgotPassword: async (email) => {
            return request('POST', '/auth/forgot-password', { 
                data: { email },
                skipAuth: true 
            });
        },

        resetPassword: async (token, newPassword, confirmPassword) => {
            return request('POST', '/auth/reset-password', { 
                data: { token, new_password: newPassword, confirm_password: confirmPassword },
                skipAuth: true 
            });
        },

        // Event System
        on: (event, callback) => events.on(event, callback),
        off: (event, callback) => events.off(event, callback),

        // Configuration
        configure: (config) => Object.assign(CONFIG, config),
        getConfig: () => ({ ...CONFIG }),
    };

    // ========================================================================
    // Export
    // ========================================================================
    window.API = api;
    return api;
})();
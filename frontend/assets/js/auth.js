/**
 * ============================================================================
 * Logistox - Authentication & Session Manager v5.1
 * نظام إدارة المخازن والمخزون
 * ============================================================================
 *
 * المسؤوليات:
 * - تسجيل الدخول / الخروج
 * - استعادة كلمة المرور (خطوتين: بريد → كلمة سر جديدة)
 * - إدارة الصلاحيات
 * - التحقق من الجلسة
 * - التنبيهات المتقدمة (Toast Notifications)
 * - اختصارات لوحة المفاتيح (Keyboard Shortcuts)
 * ============================================================================
 */

const Auth = (() => {
    'use strict';

    // ========================================================================
    // State Management
    // ========================================================================
    const state = {
        currentUser: null,
        permissions: [],
        isChecking: false,
        recoveryToken: null,
        recoveryEmail: null,
    };

    // ========================================================================
    // Toast Notification System (التنبيهات المتقدمة)
    // ========================================================================
    const Toast = {
        container: null,
        
        init() {
            if (this.container) return;
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.style.cssText = `
                position: fixed;
                top: 20px;
                left: 20px;
                z-index: 99999;
                display: flex;
                flex-direction: column;
                gap: 10px;
                pointer-events: none;
                max-width: 400px;
            `;
            document.body.appendChild(this.container);
        },

        show(message, type = 'info', duration = 4000, options = {}) {
            this.init();
            
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-times-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle',
                loading: 'fa-spinner fa-spin',
            };

            const colors = {
                success: { bg: '#10b981', border: '#059669' },
                error: { bg: '#ef4444', border: '#dc2626' },
                warning: { bg: '#f59e0b', border: '#d97706' },
                info: { bg: '#3b82f6', border: '#2563eb' },
                loading: { bg: '#6b7280', border: '#4b5563' },
            };

            const toast = document.createElement('div');
            const color = colors[type] || colors.info;
            
            toast.style.cssText = `
                background: ${color.bg};
                border-left: 4px solid ${color.border};
                color: white;
                padding: 14px 18px;
                border-radius: 8px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                display: flex;
                align-items: center;
                gap: 12px;
                pointer-events: auto;
                animation: slideInLeft 0.3s ease;
                font-family: 'Cairo', sans-serif;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
            `;

            toast.innerHTML = `
                <i class="fas ${icons[type] || icons.info}" style="font-size: 18px;"></i>
                <span style="flex: 1;">${message}</span>
                ${options.action ? `<button class="toast-action" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-weight: 700;">${options.actionText || 'تأكيد'}</button>` : ''}
                <button class="toast-close" style="background: none; border: none; color: white; cursor: pointer; font-size: 16px;"><i class="fas fa-times"></i></button>
            `;

            // Close button
            toast.querySelector('.toast-close').addEventListener('click', () => {
                this.remove(toast);
            });

            // Action button
            if (options.action && typeof options.action === 'function') {
                toast.querySelector('.toast-action').addEventListener('click', (e) => {
                    e.stopPropagation();
                    options.action();
                    this.remove(toast);
                });
            }

            // Click to dismiss
            toast.addEventListener('click', (e) => {
                if (!e.target.closest('button')) this.remove(toast);
            });

            this.container.appendChild(toast);

            // Auto remove
            if (duration > 0 && type !== 'loading') {
                setTimeout(() => this.remove(toast), duration);
            }

            return toast;
        },

        remove(toast) {
            if (!toast || !toast.parentNode) return;
            toast.style.animation = 'slideOutLeft 0.3s ease';
            setTimeout(() => {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
        },

        success(message, duration = 3000) {
            return this.show(message, 'success', duration);
        },

        error(message, duration = 5000) {
            return this.show(message, 'error', duration);
        },

        warning(message, duration = 4000) {
            return this.show(message, 'warning', duration);
        },

        info(message, duration = 3000) {
            return this.show(message, 'info', duration);
        },

        loading(message) {
            return this.show(message, 'loading', 0);
        },
    };

    // ========================================================================
    // Keyboard Shortcuts (اختصارات لوحة المفاتيح)
    // ========================================================================
    const Shortcuts = {
        handlers: {},
        isInputFocused: false,

        init() {
            // Track input focus
            document.addEventListener('focusin', (e) => {
                if (e.target.matches('input, textarea, select, [contenteditable]')) {
                    this.isInputFocused = true;
                }
            });
            document.addEventListener('focusout', () => {
                this.isInputFocused = false;
            });

            // Global keydown
            document.addEventListener('keydown', (e) => {
                // Ignore if input is focused (except Escape and F-keys)
                if (this.isInputFocused && 
                    e.key !== 'Escape' && 
                    !e.key.startsWith('F') && 
                    !(e.ctrlKey || e.metaKey)) {
                    return;
                }

                const key = this.buildKey(e);
                if (this.handlers[key]) {
                    e.preventDefault();
                    this.handlers[key](e);
                }
            });
        },

        buildKey(e) {
            const parts = [];
            if (e.ctrlKey || e.metaKey) parts.push('ctrl');
            if (e.shiftKey) parts.push('shift');
            if (e.altKey) parts.push('alt');
            
            const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
            parts.push(key);
            
            return parts.join('+');
        },

        register(key, handler, description = '') {
            this.handlers[key] = handler;
            console.log(`[Shortcuts] Registered: ${key} → ${description}`);
        },

        unregister(key) {
            delete this.handlers[key];
        },

        showHelp() {
            const shortcuts = Object.entries(this.handlers).map(([key, _, desc]) => ({
                key,
                description: desc || 'إجراء',
            }));

            let html = '<div style="padding: 20px;">';
            html += '<h3 style="margin-bottom: 15px; color: #2563eb;"><i class="fas fa-keyboard"></i> اختصارات لوحة المفاتيح</h3>';
            html += '<table style="width: 100%; border-collapse: collapse;">';
            html += '<thead><tr style="background: #f3f4f6;"><th style="padding: 10px; text-align: right;">الاختصار</th><th style="padding: 10px; text-align: right;">الوصف</th></tr></thead>';
            html += '<tbody>';
            
            shortcuts.forEach(s => {
                html += `<tr style="border-bottom: 1px solid #e5e7eb;">
                    <td style="padding: 10px;"><code style="background: #1f2937; color: #10b981; padding: 4px 8px; border-radius: 4px; font-family: monospace;">${s.key}</code></td>
                    <td style="padding: 10px;">${s.description}</td>
                </tr>`;
            });
            
            html += '</tbody></table></div>';

            Toast.show(html, 'info', 10000);
        },

        registerDefaults() {
            // Escape: إغلاق النوافذ المنبثقة
            this.register('escape', () => {
                const modals = document.querySelectorAll('.modal.show, .dropdown-menu.show');
                modals.forEach(m => {
                    if (m.classList.contains('show')) {
                        m.classList.remove('show');
                    }
                });
            }, 'إغلاق النوافذ');

            // F1: مساعدة
            this.register('f1', () => this.showHelp(), 'عرض المساعدة');

            // Ctrl+S: حفظ
            this.register('ctrl+s', () => {
                document.dispatchEvent(new CustomEvent('shortcut:save'));
            }, 'حفظ');

            // Ctrl+P: طباعة
            this.register('ctrl+p', () => {
                document.dispatchEvent(new CustomEvent('shortcut:print'));
            }, 'طباعة');

            // Ctrl+E: تصدير
            this.register('ctrl+e', () => {
                document.dispatchEvent(new CustomEvent('shortcut:export'));
            }, 'تصدير');

            // Ctrl+N: جديد
            this.register('ctrl+n', () => {
                document.dispatchEvent(new CustomEvent('shortcut:new'));
            }, 'إنشاء جديد');

            // Ctrl+F: بحث
            this.register('ctrl+f', () => {
                const searchInput = document.querySelector('input[type="search"], input.search-input');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
            }, 'بحث');

            // Ctrl+R: تحديث
            this.register('ctrl+r', (e) => {
                // منع تحديث المتصفح الافتراضي
                if (e.ctrlKey) {
                    e.preventDefault();
                    document.dispatchEvent(new CustomEvent('shortcut:refresh'));
                }
            }, 'تحديث');

            // Alt+Left: رجوع
            this.register('alt+arrowleft', () => {
                document.dispatchEvent(new CustomEvent('shortcut:back'));
            }, 'رجوع');
        },
    };

    // ========================================================================
    // Authentication Core
    // ========================================================================
    const login = async (username, password) => {
        try {
            const response = await API.post('/auth/login', { username, password });
            
            if (response.success && response.data) {
                const { token, user, expires_at, must_change_password } = response.data;
                
                API.setToken(token);
                API.setUser(user);
                state.currentUser = user;

                // تحميل الصلاحيات
                await loadPermissions(user.id);

                // حفظ بيانات الجلسة
                localStorage.setItem('logistox_expires_at', expires_at);
                localStorage.setItem('logistox_must_change_password', must_change_password ? 'true' : 'false');

                Toast.success(`مرحباً ${user.full_name || user.username}! تم تسجيل الدخول بنجاح`);
                
                return { success: true, data: response.data };
            }
            
            throw new Error(response.message || 'فشل تسجيل الدخول');
        } catch (error) {
            Toast.error(error.message || 'بيانات الدخول غير صحيحة');
            return { success: false, message: error.message };
        }
    };

    const logout = async () => {
        try {
            if (API.isAuthenticated()) {
                await API.post('/auth/logout');
            }
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            clearSession();
            Toast.info('تم تسجيل الخروج بنجاح');
            setTimeout(() => {
                window.location.href = '/inventory-system/frontend/login.html';
            }, 500);
        }
    };

    const clearSession = () => {
        API.clearToken();
        API.clearUser();
        API.clearPermissions();
        localStorage.removeItem('logistox_expires_at');
        localStorage.removeItem('logistox_must_change_password');
        state.currentUser = null;
        state.permissions = [];
    };

    // ========================================================================
    // Password Recovery (خطوتين: بريد → كلمة سر جديدة)
    // ========================================================================
    const forgotPassword = async (email) => {
        try {
            const response = await API.forgotPassword(email);
            
            if (response.success) {
                // حفظ بيانات الاستعادة
                state.recoveryEmail = email;
                state.recoveryToken = response.data?.token || response.data?.reset_token;
                
                Toast.success('تم إرسال رابط إعادة التعيين إلى بريدك الإلكتروني');
                return { success: true, data: response.data };
            }
            
            throw new Error(response.message || 'فشل إرسال رابط الاستعادة');
        } catch (error) {
            Toast.error(error.message);
            return { success: false, message: error.message };
        }
    };

    const resetPassword = async (newPassword, confirmPassword) => {
        try {
            // التحقق من تطابق كلمة المرور
            if (newPassword !== confirmPassword) {
                throw new Error('كلمتا المرور غير متطابقتين');
            }

            // التحقق من قوة كلمة المرور
            if (newPassword.length < 8) {
                throw new Error('يجب أن تكون كلمة المرور 8 أحرف على الأقل');
            }

            if (!state.recoveryToken) {
                throw new Error('رمز الاستعادة غير صالح. يرجى البدء من جديد.');
            }

            const response = await API.resetPassword(
                state.recoveryToken,
                newPassword,
                confirmPassword
            );

            if (response.success) {
                Toast.success('تم تغيير كلمة المرور بنجاح. يمكنك الآن تسجيل الدخول.');
                
                // مسح بيانات الاستعادة
                state.recoveryToken = null;
                state.recoveryEmail = null;
                
                return { success: true };
            }

            throw new Error(response.message || 'فشل تغيير كلمة المرور');
        } catch (error) {
            Toast.error(error.message);
            return { success: false, message: error.message };
        }
    };

    const setRecoveryToken = (token) => {
        state.recoveryToken = token;
    };

    const getRecoveryToken = () => state.recoveryToken;

    // ========================================================================
    // Permissions
    // ========================================================================
    const loadPermissions = async (userId) => {
        try {
            const response = await API.get(`/permissions/user/${userId}`);
            if (response.success && response.data.permissions) {
                const permissionNames = response.data.permissions.map(p => p.name);
                API.setPermissions(permissionNames);
                state.permissions = permissionNames;
            }
        } catch (error) {
            console.error('Failed to load permissions:', error);
        }
    };

    const hasPermission = (permission) => {
        const user = API.getUser();
        if (!user) return false;
        
        // Super Admin يملك كل الصلاحيات
        if (user.role_name === 'admin' || user.role_id === 1) return true;
        
        const permissions = API.getPermissions();
        return permissions.includes(permission);
    };

    const hasAnyPermission = (permissions) => {
        return permissions.some(p => hasPermission(p));
    };

    const hasAllPermissions = (permissions) => {
        return permissions.every(p => hasPermission(p));
    };

    // ========================================================================
    // Session Management
    // ========================================================================
    const isAuthenticated = () => {
        if (!API.isAuthenticated()) return false;
        
        const expiresAt = localStorage.getItem('logistox_expires_at');
        if (expiresAt) {
            const now = new Date();
            const expires = new Date(expiresAt);
            if (now >= expires) {
                clearSession();
                return false;
            }
        }
        
        return true;
    };

    const requireAuth = () => {
        if (!isAuthenticated()) {
            Toast.warning('يجب تسجيل الدخول أولاً');
            setTimeout(() => {
                window.location.href = '/inventory-system/frontend/login.html';
            }, 1000);
            return false;
        }
        return true;
    };

    const getCurrentUser = () => API.getUser();

    const refreshToken = async () => {
        try {
            const response = await API.get('/auth/me');
            if (response.success && response.data.user) {
                API.setUser(response.data.user);
                state.currentUser = response.data.user;
                return response.data.user;
            }
        } catch (error) {
            console.error('Token refresh failed:', error);
        }
        return null;
    };

    const mustChangePassword = () => {
        return localStorage.getItem('logistox_must_change_password') === 'true';
    };

    // ========================================================================
    // UI Helpers
    // ========================================================================
    const updateUI = () => {
        const user = getCurrentUser();
        if (!user) return;

        const elements = {
            userName: document.getElementById('userName'),
            userRole: document.getElementById('userRole'),
            userInitials: document.getElementById('userInitials'),
            welcomeName: document.getElementById('welcomeName'),
            dropdownUserName: document.getElementById('dropdownUserName'),
            dropdownUserEmail: document.getElementById('dropdownUserEmail'),
        };

        const fullName = user.full_name || user.username || 'المستخدم';
        const roleName = user.role_display_name || user.role_name || 'مستخدم';
        const initials = fullName.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();

        if (elements.userName) elements.userName.textContent = fullName;
        if (elements.userRole) elements.userRole.textContent = roleName;
        if (elements.userInitials) elements.userInitials.textContent = initials;
        if (elements.welcomeName) elements.welcomeName.textContent = fullName.split(' ')[0];
        if (elements.dropdownUserName) elements.dropdownUserName.textContent = fullName;
        if (elements.dropdownUserEmail) elements.dropdownUserEmail.textContent = user.email || user.username;
    };

    const showLoading = (elementId, message = 'جاري التحميل...') => {
        const el = document.getElementById(elementId);
        if (el) {
            el.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: var(--primary-color);"></i>
                    <p class="mt-2">${message}</p>
                </div>
            `;
        }
    };

    const showError = (elementId, message = 'حدث خطأ في تحميل البيانات') => {
        const el = document.getElementById(elementId);
        if (el) {
            el.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle" style="font-size: 32px; color: var(--danger-color);"></i>
                    <p class="mt-2">${message}</p>
                    <button class="btn btn-sm btn-primary mt-2" onclick="location.reload()">
                        <i class="fas fa-redo"></i> إعادة المحاولة
                    </button>
                </div>
            `;
        }
    };

    const showEmpty = (elementId, message = 'لا توجد بيانات', icon = 'fa-inbox') => {
        const el = document.getElementById(elementId);
        if (el) {
            el.innerHTML = `
                <div class="empty-state">
                    <i class="fas ${icon}" style="font-size: 32px; opacity: 0.3;"></i>
                    <p class="mt-2">${message}</p>
                </div>
            `;
        }
    };

    // ========================================================================
    // Format Helpers
    // ========================================================================
    const formatNumber = (num) => {
        return new Intl.NumberFormat('ar-EG').format(num || 0);
    };

    const formatCurrency = (num, currency = 'ج.م') => {
        const value = parseFloat(num) || 0;
        if (value >= 1000000) {
            return (value / 1000000).toFixed(2) + ' م ' + currency;
        }
        if (value >= 1000) {
            return (value / 1000).toFixed(2) + ' ألف ' + currency;
        }
        return formatNumber(value) + ' ' + currency;
    };

    const formatDate = (dateStr, format = 'long') => {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        const options = format === 'long' 
            ? { year: 'numeric', month: 'long', day: 'numeric' }
            : { year: 'numeric', month: '2-digit', day: '2-digit' };
        return date.toLocaleDateString('ar-EG', options);
    };

    const formatDateTime = (dateStr) => {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleString('ar-EG', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const timeAgo = (dateStr) => {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);

        if (seconds < 60) return 'الآن';
        if (seconds < 3600) return `منذ ${Math.floor(seconds / 60)} دقيقة`;
        if (seconds < 86400) return `منذ ${Math.floor(seconds / 3600)} ساعة`;
        if (seconds < 604800) return `منذ ${Math.floor(seconds / 86400)} يوم`;
        return formatDate(dateStr, 'short');
    };

    // ========================================================================
    // Confirmation Dialogs
    // ========================================================================
    const confirm = (message, options = {}) => {
        return new Promise((resolve) => {
            const modal = document.createElement('div');
            modal.className = 'modal fade show';
            modal.style.cssText = 'display: block; background: rgba(0,0,0,0.5); z-index: 99998;';
            
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="border-radius: 16px; overflow: hidden;">
                        <div class="modal-header" style="background: ${options.type === 'danger' ? '#ef4444' : options.type === 'warning' ? '#f59e0b' : '#3b82f6'}; color: white; border: none;">
                            <h5 class="modal-title">
                                <i class="fas ${options.icon || 'fa-question-circle'}"></i>
                                ${options.title || 'تأكيد'}
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" style="padding: 24px; font-size: 15px;">
                            ${message}
                        </div>
                        <div class="modal-footer" style="border: none; padding: 16px 24px;">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal" style="border-radius: 8px;">
                                <i class="fas fa-times"></i> إلغاء
                            </button>
                            <button type="button" class="btn ${options.type === 'danger' ? 'btn-danger' : 'btn-primary'}" id="confirm-btn" style="border-radius: 8px;">
                                <i class="fas fa-check"></i> ${options.confirmText || 'تأكيد'}
                            </button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            const closeModal = () => {
                document.body.removeChild(modal);
            };

            modal.querySelector('[data-dismiss="modal"]').addEventListener('click', () => {
                closeModal();
                resolve(false);
            });

            modal.querySelector('#confirm-btn').addEventListener('click', () => {
                closeModal();
                resolve(true);
            });

            // Close on backdrop click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    closeModal();
                    resolve(false);
                }
            });
        });
    };

    // ========================================================================
    // Initialize
    // ========================================================================
    const init = () => {
        // Initialize Toast
        Toast.init();

        // Initialize Shortcuts
        Shortcuts.init();
        Shortcuts.registerDefaults();

        // Listen for auth expiration
        API.on('auth:expired', () => {
            Toast.warning('انتهت صلاحية الجلسة', 3000);
        });

        // Listen for request errors
        API.on('request:error', ({ error }) => {
            console.error('[API Error]', error);
        });

        // Update UI if user is logged in
        if (isAuthenticated()) {
            state.currentUser = getCurrentUser();
            state.permissions = API.getPermissions();
        }

        console.log('✅ Auth Manager initialized');
    };

    // ========================================================================
    // Public API
    // ========================================================================
    return {
        // Auth
        login,
        logout,
        isAuthenticated,
        requireAuth,
        getCurrentUser,
        refreshToken,
        mustChangePassword,

        // Password Recovery
        forgotPassword,
        resetPassword,
        setRecoveryToken,
        getRecoveryToken,

        // Permissions
        hasPermission,
        hasAnyPermission,
        hasAllPermissions,
        loadPermissions,

        // UI
        Toast,
        Shortcuts,
        updateUI,
        showLoading,
        showError,
        showEmpty,
        confirm,

        // Formatters
        formatNumber,
        formatCurrency,
        formatDate,
        formatDateTime,
        timeAgo,

        // Init
        init,
    };
})();

// ============================================================================
// CSS Animations for Toast
// ============================================================================
const toastStyles = document.createElement('style');
toastStyles.textContent = `
    @keyframes slideInLeft {
        from { opacity: 0; transform: translateX(-100%); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes slideOutLeft {
        from { opacity: 1; transform: translateX(0); }
        to { opacity: 0; transform: translateX(-100%); }
    }
    #toast-container .toast-action:hover {
        background: rgba(255,255,255,0.3) !important;
    }
    #toast-container .toast-close:hover {
        opacity: 0.8;
    }
`;
document.head.appendChild(toastStyles);

// Auto-init when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => Auth.init());
} else {
    Auth.init();
}

window.Auth = Auth;
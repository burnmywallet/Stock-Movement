// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/auth.js
// الوصف: نظام المصادقة وإدارة الجلسات في الواجهة الأمامية
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

/**
 * نظام المصادقة المتقدم - إدارة تسجيل الدخول والجلسات
 */
const Auth = {
    // ================================================================
    // التحقق من المصادقة
    // ================================================================
    
    /**
     * التحقق من وجود جلسة نشطة
     */
    isAuthenticated: function() {
        const token = API.getToken();
        if (!token) return false;
        
        const user = API.getUser();
        if (!user) return false;
        
        // التحقق من صلاحية التوكن
        return this.isTokenValid();
    },
    
    /**
     * التحقق من صلاحية التوكن
     */
    isTokenValid: function() {
        const token = API.getToken();
        if (!token) return false;
        
        try {
            // فك تشفير التوكن (JWT)
            const payload = JSON.parse(atob(token.split('.')[1]));
            const exp = payload.exp || payload.expires_in;
            
            if (exp) {
                const now = Math.floor(Date.now() / 1000);
                return now < exp;
            }
            
            return true;
        } catch {
            // إذا لم يكن JWT، نعتبره صالحاً
            return true;
        }
    },
    
    /**
     * الحصول على المستخدم الحالي
     */
    getUser: function() {
        return API.getUser();
    },
    
    /**
     * تحديث بيانات المستخدم
     */
    setUser: function(user) {
        API.setUser(user);
    },
    
    // ================================================================
    // التحقق من الصلاحيات
    // ================================================================
    
    /**
     * التحقق من صلاحية المستخدم
     */
    hasPermission: function(permission) {
        const user = this.getUser();
        if (!user) return false;
        
        // المدير لديه جميع الصلاحيات
        if (user.role === 'admin') return true;
        
        // التحقق من الصلاحيات
        if (user.permissions && Array.isArray(user.permissions)) {
            return user.permissions.includes(permission);
        }
        
        return false;
    },
    
    /**
     * التحقق من صلاحية متعددة (جميعها مطلوبة)
     */
    hasAllPermissions: function(permissions) {
        if (!Array.isArray(permissions)) return false;
        return permissions.every(p => this.hasPermission(p));
    },
    
    /**
     * التحقق من صلاحية متعددة (واحدة منها مطلوبة)
     */
    hasAnyPermission: function(permissions) {
        if (!Array.isArray(permissions)) return false;
        return permissions.some(p => this.hasPermission(p));
    },
    
    // ================================================================
    // عمليات المصادقة
    // ================================================================
    
    /**
     * تسجيل الدخول
     */
    login: async function(username, password, remember = false) {
        try {
            const result = await API.auth.login(username, password);
            
            if (result.success && result.data) {
                // حفظ التوكن
                API.setToken(result.data.token);
                if (result.data.refresh_token) {
                    API.setRefreshToken(result.data.refresh_token);
                }
                
                // حفظ بيانات المستخدم
                if (result.data.user) {
                    API.setUser(result.data.user);
                }
                
                // حفظ تذكرني
                if (remember) {
                    localStorage.setItem('remember_me', 'true');
                } else {
                    localStorage.removeItem('remember_me');
                }
                
                return {
                    success: true,
                    user: result.data.user,
                    token: result.data.token
                };
            }
            
            return {
                success: false,
                message: result.message || 'فشل تسجيل الدخول'
            };
            
        } catch (error) {
            return {
                success: false,
                message: error.message || 'حدث خطأ في تسجيل الدخول'
            };
        }
    },
    
    /**
     * تسجيل الخروج
     */
    logout: async function() {
        try {
            await API.auth.logout();
        } catch (error) {
            // تجاهل أخطاء تسجيل الخروج
            console.warn('Logout error:', error.message);
        }
        
        // مسح البيانات المحلية
        API.removeToken();
        localStorage.removeItem('remember_me');
        
        // إعادة التوجيه إلى صفحة تسجيل الدخول
        window.location.href = '/frontend/pages/login.html';
    },
    
    /**
     * التحقق من صحة الجلسة
     */
    validateSession: async function() {
        try {
            const result = await API.auth.validate();
            
            if (result.success) {
                // تحديث بيانات المستخدم
                if (result.data && result.data.user) {
                    API.setUser(result.data.user);
                }
                return {
                    valid: true,
                    user: result.data?.user || null
                };
            }
            
            // جلسة غير صالحة
            await this.handleInvalidSession();
            return { valid: false };
            
        } catch (error) {
            // جلسة غير صالحة
            await this.handleInvalidSession();
            return { valid: false };
        }
    },
    
    /**
     * معالجة جلسة غير صالحة
     */
    handleInvalidSession: async function() {
        // محاولة تجديد التوكن
        try {
            const refreshed = await API.refreshToken();
            if (refreshed) {
                // التوكن مجدد، تحقق مرة أخرى
                const result = await API.auth.validate();
                if (result.success) {
                    if (result.data && result.data.user) {
                        API.setUser(result.data.user);
                    }
                    return;
                }
            }
        } catch (error) {
            // تجاهل
        }
        
        // فشل التجديد، تسجيل الخروج
        API.removeToken();
        localStorage.removeItem('remember_me');
        
        // إعادة التوجيه إلى صفحة تسجيل الدخول
        if (!window.location.pathname.includes('/login.html')) {
            window.location.href = '/frontend/pages/login.html';
        }
    },
    
    // ================================================================
    // إدارة كلمة المرور
    // ================================================================
    
    /**
     * تغيير كلمة المرور
     */
    changePassword: async function(currentPassword, newPassword, confirmPassword) {
        try {
            const result = await API.auth.changePassword(
                currentPassword,
                newPassword,
                confirmPassword
            );
            
            return {
                success: true,
                message: result.message || 'تم تغيير كلمة المرور بنجاح'
            };
            
        } catch (error) {
            return {
                success: false,
                message: error.message || 'حدث خطأ في تغيير كلمة المرور'
            };
        }
    },
    
    /**
     * طلب إعادة تعيين كلمة المرور
     */
    forgotPassword: async function(email) {
        try {
            const result = await API.auth.forgotPassword(email);
            
            return {
                success: true,
                message: result.message || 'تم إرسال رابط إعادة التعيين إلى بريدك الإلكتروني'
            };
            
        } catch (error) {
            return {
                success: false,
                message: error.message || 'حدث خطأ في طلب إعادة التعيين'
            };
        }
    },
    
    /**
     * إعادة تعيين كلمة المرور
     */
    resetPassword: async function(token, password, confirmPassword) {
        try {
            const result = await API.auth.resetPassword(token, password, confirmPassword);
            
            return {
                success: true,
                message: result.message || 'تم إعادة تعيين كلمة المرور بنجاح'
            };
            
        } catch (error) {
            return {
                success: false,
                message: error.message || 'حدث خطأ في إعادة تعيين كلمة المرور'
            };
        }
    },
    
    // ================================================================
    // إدارة الجلسات
    // ================================================================
    
    /**
     * جلب الجلسات النشطة
     */
    getSessions: async function() {
        try {
            const result = await API.auth.sessions();
            return {
                success: true,
                data: result.data || []
            };
        } catch (error) {
            return {
                success: false,
                message: error.message || 'حدث خطأ في جلب الجلسات'
            };
        }
    },
    
    /**
     * إنهاء جلسة محددة
     */
    terminateSession: async function(sessionId) {
        try {
            const result = await API.auth.terminateSession(sessionId);
            return {
                success: true,
                message: result.message || 'تم إنهاء الجلسة بنجاح'
            };
        } catch (error) {
            return {
                success: false,
                message: error.message || 'حدث خطأ في إنهاء الجلسة'
            };
        }
    },
    
    /**
     * إنهاء جميع الجلسات (باستثناء الحالية)
     */
    terminateAllSessions: async function() {
        try {
            const result = await API.auth.terminateAllSessions();
            return {
                success: true,
                message: result.message || 'تم إنهاء جميع الجلسات بنجاح'
            };
        } catch (error) {
            return {
                success: false,
                message: error.message || 'حدث خطأ في إنهاء الجلسات'
            };
        }
    },
    
    // ================================================================
    // واجهة المستخدم
    // ================================================================
    
    /**
     * تحديث واجهة المستخدم بعد تسجيل الدخول
     */
    updateUI: function() {
        const user = this.getUser();
        if (!user) return;
        
        // تحديث اسم المستخدم
        document.querySelectorAll('.user-name, #userName, #sidebarUserName').forEach(el => {
            if (el) el.textContent = user.full_name || user.username;
        });
        
        // تحديث الدور
        const roleMap = {
            'admin': 'مدير النظام',
            'warehouse_manager': 'مدير المخازن',
            'warehouse_supervisor': 'مشرف مخزن',
            'warehouse_staff': 'موظف مخزن',
            'viewer': 'مشاهدة'
        };
        
        document.querySelectorAll('.user-role, #userRole, #sidebarUserRole').forEach(el => {
            if (el) el.textContent = roleMap[user.role] || user.role;
        });
        
        // تحديث الصورة الشخصية
        document.querySelectorAll('.user-avatar, #userAvatar, #sidebarUserAvatar').forEach(el => {
            if (el) {
                const initial = (user.full_name || user.username || 'م')[0];
                el.textContent = initial;
            }
        });
        
        // تحديث رسالة الترحيب
        document.querySelectorAll('.welcome-message, #welcomeMessage').forEach(el => {
            if (el) el.textContent = 'مرحباً ' + (user.full_name || user.username);
        });
        
        // إظهار/إخفاء عناصر حسب الصلاحيات
        this.updatePermissionsUI(user);
    },
    
    /**
     * تحديث واجهة المستخدم حسب الصلاحيات
     */
    updatePermissionsUI: function(user) {
        // عناصر تحتاج صلاحية admin
        document.querySelectorAll('.permission-admin').forEach(el => {
            el.style.display = (user.role === 'admin') ? '' : 'none';
        });
        
        // عناصر تحتاج صلاحية manager
        document.querySelectorAll('.permission-manager').forEach(el => {
            const hasPermission = user.role === 'admin' || user.role === 'warehouse_manager';
            el.style.display = hasPermission ? '' : 'none';
        });
        
        // عناصر تحتاج صلاحية محددة
        document.querySelectorAll('[data-permission]').forEach(el => {
            const permission = el.dataset.permission;
            el.style.display = this.hasPermission(permission) ? '' : 'none';
        });
    },
    
    /**
     * عرض نموذج تسجيل الدخول
     */
    showLoginModal: function() {
        const modal = document.getElementById('loginModal');
        if (!modal) {
            window.location.href = '/frontend/pages/login.html';
            return;
        }
        
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        this.setupLoginForm();
    },
    
    /**
     * إعداد نموذج تسجيل الدخول
     */
    setupLoginForm: function() {
        const form = document.getElementById('loginForm');
        if (!form) return;
        
        // إزالة المستمعين القديمين
        const newForm = form.cloneNode(true);
        form.parentNode.replaceChild(newForm, form);
        
        newForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const username = document.getElementById('loginUsername').value.trim();
            const password = document.getElementById('loginPassword').value;
            const remember = document.getElementById('rememberMe')?.checked || false;
            const alertEl = document.getElementById('loginAlert');
            const btn = document.getElementById('loginBtn');
            const btnText = document.getElementById('loginBtnText');
            const spinner = document.getElementById('loginSpinner');
            
            // التحقق من البيانات
            if (!username || !password) {
                alertEl.textContent = 'يرجى إدخال اسم المستخدم وكلمة المرور';
                alertEl.classList.remove('d-none');
                return;
            }
            
            // عرض حالة التحميل
            btn.disabled = true;
            btnText.textContent = 'جاري تسجيل الدخول...';
            spinner.classList.remove('d-none');
            alertEl.classList.add('d-none');
            
            // تسجيل الدخول
            const result = await Auth.login(username, password, remember);
            
            // إخفاء حالة التحميل
            btn.disabled = false;
            btnText.textContent = 'تسجيل الدخول';
            spinner.classList.add('d-none');
            
            if (result.success) {
                // إغلاق النافذة
                const modal = document.getElementById('loginModal');
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) bsModal.hide();
                
                // تحديث الواجهة
                Auth.updateUI();
                
                // عرض رسالة نجاح
                Swal.fire({
                    icon: 'success',
                    title: 'مرحباً ' + result.user.full_name,
                    text: 'تم تسجيل الدخول بنجاح',
                    timer: 2000,
                    showConfirmButton: false
                });
                
                // إعادة تحميل الصفحة
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
                
            } else {
                alertEl.textContent = result.message;
                alertEl.classList.remove('d-none');
            }
        });
    },
    
    /**
     * تبديل إظهار كلمة المرور
     */
    togglePasswordVisibility: function(inputId = 'password', iconId = 'eyeIcon') {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        
        if (!input || !icon) return;
        
        const type = input.type === 'password' ? 'text' : 'password';
        input.type = type;
        icon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    },
    
    // ================================================================
    // أدوات مساعدة
    // ================================================================
    
    /**
     * التحقق من صلاحية التوكن (متقدم)
     */
    checkTokenValidity: function() {
        const token = API.getToken();
        if (!token) return { valid: false, reason: 'no_token' };
        
        try {
            // فك تشفير التوكن
            const payload = JSON.parse(atob(token.split('.')[1]));
            const exp = payload.exp || payload.expires_in;
            
            if (exp) {
                const now = Math.floor(Date.now() / 1000);
                const timeLeft = exp - now;
                
                if (timeLeft < 0) {
                    return { valid: false, reason: 'expired', timeLeft: timeLeft };
                }
                
                if (timeLeft < 300) {
                    return { valid: true, expiresSoon: true, timeLeft: timeLeft };
                }
                
                return { valid: true, timeLeft: timeLeft };
            }
            
            return { valid: true };
            
        } catch (error) {
            return { valid: false, reason: 'invalid_token' };
        }
    },
    
    /**
     * تحديث التوكن تلقائياً قبل انتهائه
     */
    autoRefresh: function() {
        const status = this.checkTokenValidity();
        
        if (status.valid && status.expiresSoon) {
            // تجديد التوكن قبل 5 دقائق من انتهائه
            API.refreshToken().then(success => {
                if (success) {
                    console.log('🔄 تم تجديد التوكن بنجاح');
                }
            });
        }
    }
};

// ================================================================
// بدء التحقق التلقائي للتوكن
// ================================================================

// التحقق كل دقيقة
setInterval(() => {
    Auth.autoRefresh();
}, 60000);

// التحقق عند العودة إلى الصفحة
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        Auth.autoRefresh();
    }
});

// ================================================================
// تصدير Auth
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = Auth;
}

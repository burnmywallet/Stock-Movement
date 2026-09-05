/**
 * ================================================================
 * Logistox - إدارة المصادقة والصلاحيات
 * نظام إدارة المخازن والمخزون v5.0
 * ================================================================
 */

// منع التكرار
if (typeof window.Auth === 'undefined') {

const Auth = {
    // ================================================================
    // التحقق من المصادقة
    // ================================================================
    isAuthenticated() {
        return localStorage.getItem('auth_token') !== null;
    },

    // ================================================================
    // الحصول على المستخدم الحالي
    // ================================================================
    getUser() {
        try {
            const data = localStorage.getItem('user');
            return data ? JSON.parse(data) : null;
        } catch (e) {
            return null;
        }
    },

    // ================================================================
    // حفظ المستخدم
    // ================================================================
    setUser(user) {
        localStorage.setItem('user', JSON.stringify(user));
    },

    // ================================================================
    // تحديث بيانات المستخدم
    // ================================================================
    updateUser(updates) {
        const currentUser = this.getUser();
        if (currentUser) {
            const updatedUser = { ...currentUser, ...updates };
            this.setUser(updatedUser);
            return updatedUser;
        }
        return null;
    },

    // ================================================================
    // تسجيل الدخول
    // ================================================================
    async login(username, password, remember = false) {
        try {
            const result = await window.Api.login(username, password, remember);
            this.setUser(result.user);
            return {
                success: true,
                user: result.user,
                message: 'تم تسجيل الدخول بنجاح'
            };
        } catch (error) {
            return {
                success: false,
                error: error.message
            };
        }
    },

    // ================================================================
    // تسجيل الخروج
    // ================================================================
    async logout() {
        try {
            await window.Api.logout();
        } catch (error) {
            console.error('Logout error:', error);
        }
        
        localStorage.removeItem('auth_token');
        localStorage.removeItem('user');
        
        window.location.href = '/inventory-system/frontend/pages/login.html';
    },

    // ================================================================
    // التحقق من الوصول
    // ================================================================
    checkAccess() {
        if (!this.isAuthenticated()) {
            window.location.href = '/inventory-system/frontend/pages/login.html';
            return false;
        }
        return true;
    },

    // ================================================================
    // الحصول على الصلاحيات
    // ================================================================
    getPermissions() {
        const user = this.getUser();
        return user ? (user.permissions || []) : [];
    },

    // ================================================================
    // التحقق من الصلاحية
    // ================================================================
    hasPermission(permission) {
        const user = this.getUser();
        if (!user) return false;
        
        // المدير لديه كل الصلاحيات
        if (user.role === 'admin') return true;
        
        // التحقق من الصلاحيات
        return user.permissions && user.permissions.includes(permission);
    },

    // ================================================================
    // التحقق من أي صلاحية من قائمة
    // ================================================================
    hasAnyPermission(permissions) {
        return permissions.some(permission => this.hasPermission(permission));
    },

    // ================================================================
    // التحقق من كل الصلاحيات
    // ================================================================
    hasAllPermissions(permissions) {
        return permissions.every(permission => this.hasPermission(permission));
    },

    // ================================================================
    // طلب صلاحية (مع رسالة خطأ)
    // ================================================================
    requirePermission(permission) {
        if (!this.hasPermission(permission)) {
            window.showToast('⚠️ ليس لديك صلاحية لتنفيذ هذا الإجراء', 'warning');
            return false;
        }
        return true;
    },

    // ================================================================
    // إخفاء عناصر غير مصرح بها
    // ================================================================
    hideUnauthorizedElements() {
        document.querySelectorAll('[data-permission]').forEach(element => {
            const permission = element.getAttribute('data-permission');
            if (!this.hasPermission(permission)) {
                element.style.display = 'none';
            }
        });
    },

    // ================================================================
    // التحقق من تغيير كلمة المرور
    // ================================================================
    mustChangePassword() {
        const user = this.getUser();
        return user ? user.must_change_password : false;
    },

    // ================================================================
    // عرض نافذة تغيير كلمة المرور
    // ================================================================
    showChangePasswordModal() {
        if (this.mustChangePassword()) {
            const modal = document.getElementById('changePasswordModal');
            if (modal) {
                modal.classList.add('show');
            } else {
                this.createChangePasswordModal();
            }
        }
    },

    // ================================================================
    // إنشاء نافذة تغيير كلمة المرور
    // ================================================================
    createChangePasswordModal() {
        const modalHTML = `
            <div class="modal-overlay" id="changePasswordModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>🔒 تغيير كلمة المرور</h3>
                        <button class="close-btn" onclick="this.closest('.modal-overlay').classList.remove('show')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p style="margin-bottom:20px;color:var(--text-secondary);">
                            يجب عليك تغيير كلمة المرور قبل المتابعة
                        </p>
                        <form id="changePasswordForm">
                            <div class="form-group">
                                <label>كلمة المرور الحالية</label>
                                <input type="password" id="currentPassword" required>
                            </div>
                            <div class="form-group">
                                <label>كلمة المرور الجديدة</label>
                                <input type="password" id="newPassword" required minlength="8">
                            </div>
                            <div class="form-group">
                                <label>تأكيد كلمة المرور الجديدة</label>
                                <input type="password" id="confirmNewPassword" required minlength="8">
                            </div>
                            <button type="submit" class="btn-primary" style="width:100%;margin-top:15px;">
                                <i class="fas fa-save"></i> حفظ كلمة المرور
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        `;
        
        // إضافة المودال للصفحة
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        
        // إضافة معالج النموذج
        document.getElementById('changePasswordForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const currentPassword = document.getElementById('currentPassword').value;
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmNewPassword').value;
            
            // التحقق من تطابق كلمة المرور
            if (newPassword !== confirmPassword) {
                window.showToast('❌ كلمتا المرور غير متطابقتين', 'error');
                return;
            }
            
            try {
                const user = Auth.getUser();
                const result = await window.Api.put('/users/' + user.id + '/password', {
                    current_password: currentPassword,
                    new_password: newPassword
                });
                
                // تحديث المستخدم
                Auth.updateUser({ must_change_password: false });
                
                // إغلاق المودال
                document.getElementById('changePasswordModal').classList.remove('show');
                
                window.showToast('✅ تم تغيير كلمة المرور بنجاح', 'success');
            } catch (error) {
                window.showToast('❌ ' + error.message, 'error');
            }
        });
    },

    // ================================================================
    // تسجيل نشاط
    // ================================================================
    logActivity(action, description = '') {
        console.log(`📋 [${new Date().toLocaleString('ar-EG')}] ${action}: ${description}`);
    },

    // ================================================================
    // تجديد الجلسة
    // ================================================================
    async refreshSession() {
        try {
            const user = await window.Api.me();
            this.setUser(user);
            return true;
        } catch (error) {
            this.logout();
            return false;
        }
    },

    // ================================================================
    // إعداد مستمعي الأحداث
    // ================================================================
    init() {
        // التحقق من المصادقة
        if (this.isAuthenticated()) {
            // تجديد الجلسة
            this.refreshSession();
        }
        
        // مستمع لتغيير حالة المصادقة
        window.addEventListener('storage', (e) => {
            if (e.key === 'auth_token') {
                if (!e.newValue) {
                    window.location.href = '/inventory-system/frontend/pages/login.html';
                }
            }
        });
    }
};

// تصدير
window.Auth = Auth;

} // نهاية منع التكرار

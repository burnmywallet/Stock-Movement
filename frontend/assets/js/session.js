// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/session.js
// الوصف: إدارة الجلسات المتقدمة - جلسة واحدة، أجهزة متعددة
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

/**
 * إدارة الجلسات المتقدمة
 */
const SessionManager = {
    // ================================================================
    // الحالة
    // ================================================================
    
    sessions: [],
    currentSessionId: null,
    refreshInterval: null,
    
    // ================================================================
    // التحميل
    // ================================================================
    
    /**
     * تحميل إدارة الجلسات
     */
    load: function() {
        this.loadSessions();
        this.setupEvents();
        this.startAutoRefresh();
    },
    
    /**
     * تحميل الجلسات
     */
    loadSessions: function() {
        API.auth.sessions()
            .then(response => {
                if (response.success) {
                    this.sessions = response.data || [];
                    this.renderSessions();
                }
            })
            .catch(error => {
                console.error('Error loading sessions:', error);
            });
    },
    
    /**
     * عرض الجلسات
     */
    renderSessions: function() {
        const container = document.getElementById('sessionsContainer');
        if (!container) return;
        
        if (this.sessions.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-window-restore fa-2x mb-2" style="opacity:0.3;"></i>
                    <p>لا توجد جلسات نشطة</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        this.sessions.forEach(session => {
            const isCurrent = session.id == this.currentSessionId;
            const isActive = session.is_active && new Date(session.expires_at) > new Date();
            
            html += `
                <div class="session-item ${isCurrent ? 'current' : ''}">
                    <div class="session-icon">
                        <i class="fas fa-${this.getDeviceIcon(session.device_type)}"></i>
                    </div>
                    <div class="session-info">
                        <div class="session-device">
                            ${session.device_name || 'جهاز غير معروف'}
                            ${isCurrent ? '<span class="badge bg-success ms-2">الحالية</span>' : ''}
                        </div>
                        <div class="session-details">
                            <span><i class="fas fa-ip"></i> ${session.ip_address || '0.0.0.0'}</span>
                            <span><i class="fas fa-clock"></i> ${App.formatDate(session.login_at)}</span>
                            <span><i class="fas fa-hourglass-half"></i> ${this.getSessionDuration(session.login_at)}</span>
                        </div>
                        <div class="session-status">
                            <span class="badge ${isActive ? 'bg-success' : 'bg-secondary'}">
                                ${isActive ? 'نشطة' : 'منتهية'}
                            </span>
                            ${session.trusted_device ? '<span class="badge bg-info">جهاز موثوق</span>' : ''}
                            <span class="badge bg-secondary">الأمان: ${session.security_score || 0}%</span>
                        </div>
                    </div>
                    <div class="session-actions">
                        ${!isCurrent && isActive ? `
                            <button class="btn btn-sm btn-danger" onclick="SessionManager.terminateSession(${session.id})">
                                <i class="fas fa-times"></i> إنهاء
                            </button>
                        ` : ''}
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    },
    
    /**
     * الحصول على أيقونة الجهاز
     */
    getDeviceIcon: function(type) {
        const icons = {
            'desktop': 'desktop',
            'laptop': 'laptop',
            'tablet': 'tablet-alt',
            'mobile': 'mobile-alt',
            'unknown': 'question-circle'
        };
        return icons[type] || 'question-circle';
    },
    
    /**
     * حساب مدة الجلسة
     */
    getSessionDuration: function(loginAt) {
        const start = new Date(loginAt);
        const now = new Date();
        const diff = Math.floor((now - start) / 1000);
        
        const hours = Math.floor(diff / 3600);
        const minutes = Math.floor((diff % 3600) / 60);
        
        if (hours > 0) {
            return `${hours} ساعة ${minutes} دقيقة`;
        }
        return `${minutes} دقيقة`;
    },
    
    // ================================================================
    // الأحداث
    // ================================================================
    
    /**
     * إعداد الأحداث
     */
    setupEvents: function() {
        // تحديث يدوي
        document.getElementById('refreshSessions')?.addEventListener('click', () => {
            this.loadSessions();
        });
        
        // إنهاء جميع الجلسات
        document.getElementById('terminateAllSessions')?.addEventListener('click', () => {
            this.terminateAllSessions();
        });
    },
    
    /**
     * بدء التحديث التلقائي
     */
    startAutoRefresh: function() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
        this.refreshInterval = setInterval(() => {
            this.loadSessions();
        }, 30000);
    },
    
    /**
     * إيقاف التحديث التلقائي
     */
    stopAutoRefresh: function() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    },
    
    // ================================================================
    // إدارة الجلسات
    // ================================================================
    
    /**
     * إنهاء جلسة محددة
     */
    terminateSession: function(sessionId) {
        Swal.fire({
            title: 'إنهاء الجلسة',
            text: 'هل أنت متأكد من رغبتك في إنهاء هذه الجلسة؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، إنهاء',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#dc3545'
        }).then(result => {
            if (result.isConfirmed) {
                API.auth.terminateSession(sessionId)
                    .then(response => {
                        if (response.success) {
                            App.showToast('تم إنهاء الجلسة بنجاح', 'success');
                            this.loadSessions();
                        } else {
                            App.showToast(response.message || 'فشل إنهاء الجلسة', 'error');
                        }
                    })
                    .catch(error => {
                        App.showToast(error.message || 'حدث خطأ في إنهاء الجلسة', 'error');
                    });
            }
        });
    },
    
    /**
     * إنهاء جميع الجلسات (باستثناء الحالية)
     */
    terminateAllSessions: function() {
        Swal.fire({
            title: 'إنهاء جميع الجلسات',
            text: 'سيتم إنهاء جميع الجلسات النشطة (باستثناء الجلسة الحالية). هل أنت متأكد؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، إنهاء الكل',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#dc3545'
        }).then(result => {
            if (result.isConfirmed) {
                API.auth.terminateAllSessions()
                    .then(response => {
                        if (response.success) {
                            App.showToast('تم إنهاء جميع الجلسات بنجاح', 'success');
                            this.loadSessions();
                        } else {
                            App.showToast(response.message || 'فشل إنهاء الجلسات', 'error');
                        }
                    })
                    .catch(error => {
                        App.showToast(error.message || 'حدث خطأ في إنهاء الجلسات', 'error');
                    });
            }
        });
    },
    
    // ================================================================
    // معلومات الجلسة الحالية
    // ================================================================
    
    /**
     * تحديث معلومات الجلسة الحالية
     */
    updateCurrentSession: function() {
        const token = API.getToken();
        if (!token) return;
        
        try {
            const payload = JSON.parse(atob(token.split('.')[1]));
            this.currentSessionId = payload.session_id || payload.jti;
        } catch {
            // تجاهل
        }
    },
    
    /**
     * التحقق من انتهاء الجلسة
     */
    checkSessionExpiry: function() {
        const token = API.getToken();
        if (!token) return false;
        
        try {
            const payload = JSON.parse(atob(token.split('.')[1]));
            const exp = payload.exp || payload.expires_in;
            if (exp) {
                const now = Math.floor(Date.now() / 1000);
                return now >= exp;
            }
            return false;
        } catch {
            return false;
        }
    },
    
    /**
     * إعادة توجيه عند انتهاء الجلسة
     */
    handleSessionExpiry: function() {
        App.showToast('انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى.', 'warning');
        setTimeout(() => {
            Auth.logout();
        }, 2000);
    }
};

// ================================================================
// دوال عامة للاستخدام من HTML
// ================================================================

function refreshSessions() {
    SessionManager.loadSessions();
}

function terminateSession(id) {
    SessionManager.terminateSession(id);
}

function terminateAllSessions() {
    SessionManager.terminateAllSessions();
}

// ================================================================
// تصدير SessionManager
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = SessionManager;
}

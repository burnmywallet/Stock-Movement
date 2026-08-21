// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/app.js
// الوصف: التطبيق الرئيسي - إدارة الصفحات والتنقل والتهيئة
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

/**
 * التطبيق الرئيسي - إدارة النظام بالكامل
 */
const App = {
    // ================================================================
    // الحالة العامة
    // ================================================================
    
    currentPage: 'dashboard',
    initialized: false,
    sidebarOpen: false,
    isLoading: false,
    refreshInterval: null,
    
    // ================================================================
    // التهيئة
    // ================================================================
    
    /**
     * تهيئة التطبيق
     */
    init: function() {
        if (this.initialized) return;
        
        // التحقق من المصادقة
        if (!Auth.isAuthenticated()) {
            this.showLogin();
            return;
        }
        
        // إخفاء مؤشر التحميل
        this.hideLoading();
        
        // تحديث واجهة المستخدم
        Auth.updateUI();
        
        // إعداد التنقل
        this.setupNavigation();
        
        // إعداد تسجيل الخروج
        this.setupLogout();
        
        // إعداد الشريط الجانبي
        this.setupSidebar();
        
        // إعداد الإشعارات
        this.setupNotifications();
        
        // تحميل الصفحة الافتراضية
        this.loadPage('dashboard');
        
        // تحديث تلقائي كل 60 ثانية
        this.refreshInterval = setInterval(() => this.refreshData(), 60000);
        
        this.initialized = true;
        
        console.log('✅ تطبيق المخازن المتقدم جاهز');
    },
    
    /**
     * إظهار شاشة تسجيل الدخول
     */
    showLogin: function() {
        window.location.href = '/frontend/pages/login.html';
    },
    
    /**
     * إخفاء مؤشر التحميل
     */
    hideLoading: function() {
        const spinner = document.getElementById('loading-spinner');
        if (spinner) {
            spinner.classList.add('hidden');
            setTimeout(() => {
                spinner.style.display = 'none';
            }, 500);
        }
    },
    
    /**
     * إظهار مؤشر التحميل
     */
    showLoading: function() {
        const spinner = document.getElementById('loading-spinner');
        if (spinner) {
            spinner.style.display = 'flex';
            spinner.classList.remove('hidden');
        }
    },
    
    // ================================================================
    // التنقل
    // ================================================================
    
    /**
     * إعداد التنقل
     */
    setupNavigation: function() {
        // روابط الشريط الجانبي
        document.querySelectorAll('.sidebar-menu a, .navbar-nav a[href^="#"]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const href = link.getAttribute('href');
                if (href && href.startsWith('#')) {
                    const page = href.substring(1);
                    this.loadPage(page);
                    // إغلاق الشريط الجانبي على الموبايل
                    if (window.innerWidth <= 992) {
                        this.toggleSidebar(false);
                    }
                }
            });
        });
        
        // معالجة تغيير الـ Hash
        window.addEventListener('hashchange', () => {
            const hash = window.location.hash.substring(1);
            if (hash) {
                this.loadPage(hash);
            }
        });
    },
    
    /**
     * تحميل صفحة
     */
    loadPage: function(page) {
        // تحديث الصفحة الحالية
        this.currentPage = page;
        
        // تحديث عنوان الصفحة
        this.updatePageTitle(page);
        
        // تحديث الـ Hash
        if (window.location.hash !== '#' + page) {
            window.location.hash = page;
        }
        
        // تحديث العناصر النشطة في القائمة
        this.updateActiveMenu(page);
        
        // إظهار محتوى الصفحة
        this.showPageContent(page);
        
        // تحميل بيانات الصفحة
        this.loadPageData(page);
    },
    
    /**
     * تحديث عنوان الصفحة
     */
    updatePageTitle: function(page) {
        const titles = {
            'dashboard': 'لوحة التحكم',
            'products': 'إدارة الأصناف',
            'warehouses': 'إدارة المخازن',
            'receipts': 'إذون الاستلام',
            'issues': 'إذون الصرف',
            'transfers': 'التحويلات',
            'returns': 'المرتجعات',
            'inventory': 'الجرد',
            'reports': 'التقارير',
            'users': 'المستخدمين',
            'audit': 'سجل التدقيق',
            'settings': 'الإعدادات',
            'backup': 'النسخ الاحتياطي'
        };
        
        const title = titles[page] || page;
        document.title = `${title} - نظام المخازن المتقدم`;
    },
    
    /**
     * تحديث العناصر النشطة في القائمة
     */
    updateActiveMenu: function(page) {
        document.querySelectorAll('.sidebar-menu li, .navbar-nav .nav-item').forEach(li => {
            li.classList.remove('active');
        });
        
        document.querySelectorAll(`.sidebar-menu a[href="#${page}"], .navbar-nav a[href="#${page}"]`).forEach(link => {
            const parent = link.closest('li');
            if (parent) {
                parent.classList.add('active');
            }
        });
    },
    
    /**
     * إظهار محتوى الصفحة
     */
    showPageContent: function(page) {
        // إخفاء جميع الصفحات
        document.querySelectorAll('.page-content').forEach(el => {
            el.classList.remove('active');
        });
        
        // إظهار الصفحة المطلوبة
        const pageEl = document.getElementById(page + 'Page');
        if (pageEl) {
            pageEl.classList.add('active');
        } else {
            // إذا لم يتم العثور على الصفحة، حاول تحميلها ديناميكياً
            this.loadDynamicPage(page);
        }
    },
    
    /**
     * تحميل صفحة ديناميكية
     */
    loadDynamicPage: function(page) {
        const parts = page.split('/');
        const module = parts[0];
        const action = parts[1] || 'index';
        
        // محاولة تحميل الصفحة من خلال الـ Controllers
        switch (module) {
            case 'reports':
                if (action === 'stock') {
                    Reports.loadStock();
                } else if (action === 'movements') {
                    Reports.loadMovements();
                } else if (action === 'audit') {
                    Reports.loadAudit();
                }
                break;
            case 'products':
                Products.load();
                break;
            case 'warehouses':
                Warehouses.load();
                break;
            case 'users':
                Users.load();
                break;
            default:
                this.show404();
        }
    },
    
    /**
     * تحميل بيانات الصفحة
     */
    loadPageData: function(page) {
        // استخدام الـ Controllers المناسبة
        switch (page) {
            case 'dashboard':
                if (typeof Dashboard !== 'undefined' && Dashboard.load) {
                    Dashboard.load();
                }
                break;
            case 'products':
                if (typeof Products !== 'undefined' && Products.load) {
                    Products.load();
                }
                break;
            case 'warehouses':
                if (typeof Warehouses !== 'undefined' && Warehouses.load) {
                    Warehouses.load();
                }
                break;
            case 'receipts':
                if (typeof Receipts !== 'undefined' && Receipts.load) {
                    Receipts.load();
                }
                break;
            case 'issues':
                if (typeof Issues !== 'undefined' && Issues.load) {
                    Issues.load();
                }
                break;
            case 'transfers':
                if (typeof Transfers !== 'undefined' && Transfers.load) {
                    Transfers.load();
                }
                break;
            case 'returns':
                if (typeof Returns !== 'undefined' && Returns.load) {
                    Returns.load();
                }
                break;
            case 'users':
                if (typeof Users !== 'undefined' && Users.load) {
                    Users.load();
                }
                break;
            case 'reports':
                if (typeof Reports !== 'undefined' && Reports.load) {
                    Reports.load();
                }
                break;
            case 'settings':
                if (typeof Settings !== 'undefined' && Settings.load) {
                    Settings.load();
                }
                break;
            default:
                // محاولة تحميل وحدة غير معروفة
                console.warn(`الصفحة "${page}" ليس لها معالج محدد`);
        }
    },
    
    /**
     * عرض صفحة 404
     */
    show404: function() {
        const container = document.getElementById('mainContent');
        if (container) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="fas fa-exclamation-triangle fa-4x text-warning mb-4"></i>
                    <h2 class="text-white">الصفحة غير موجودة</h2>
                    <p class="text-muted">عذراً، الصفحة التي تبحث عنها غير موجودة</p>
                    <button class="btn btn-primary mt-3" onclick="App.loadPage('dashboard')">
                        <i class="fas fa-arrow-right me-2"></i> العودة إلى لوحة التحكم
                    </button>
                </div>
            `;
        }
    },
    
    // ================================================================
    // الشريط الجانبي
    // ================================================================
    
    /**
     * إعداد الشريط الجانبي
     */
    setupSidebar: function() {
        // زر فتح/إغلاق الشريط الجانبي
        const toggleBtn = document.getElementById('sidebarToggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                this.toggleSidebar();
            });
        }
        
        // إغلاق الشريط عند النقر خارجه على الموبايل
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 992) {
                const sidebar = document.getElementById('sidebar');
                const toggle = document.getElementById('sidebarToggle');
                if (sidebar && !sidebar.contains(e.target) && toggle && !toggle.contains(e.target)) {
                    this.toggleSidebar(false);
                }
            }
        });
        
        // إعادة تعيين حالة الشريط عند تغيير حجم النافذة
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                this.toggleSidebar(false);
            }
        });
    },
    
    /**
     * تبديل الشريط الجانبي
     */
    toggleSidebar: function(open) {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        
        if (typeof open === 'boolean') {
            if (open) {
                sidebar.classList.add('open');
                this.sidebarOpen = true;
            } else {
                sidebar.classList.remove('open');
                this.sidebarOpen = false;
            }
        } else {
            sidebar.classList.toggle('open');
            this.sidebarOpen = sidebar.classList.contains('open');
        }
    },
    
    // ================================================================
    // تسجيل الخروج
    // ================================================================
    
    /**
     * إعداد زر تسجيل الخروج
     */
    setupLogout: function() {
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.confirmLogout();
            });
        }
    },
    
    /**
     * تأكيد تسجيل الخروج
     */
    confirmLogout: async function() {
        const result = await Swal.fire({
            title: 'تسجيل الخروج',
            text: 'هل أنت متأكد من رغبتك في تسجيل الخروج؟',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم، تسجيل الخروج',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#DC3545'
        });
        
        if (result.isConfirmed) {
            await Auth.logout();
        }
    },
    
    // ================================================================
    // الإشعارات
    // ================================================================
    
    /**
     * إعداد الإشعارات
     */
    setupNotifications: function() {
        // التحقق من الإشعارات كل 30 ثانية
        setInterval(() => {
            this.fetchNotifications();
        }, 30000);
        
        // جلب الإشعارات عند التحميل
        this.fetchNotifications();
    },
    
    /**
     * جلب الإشعارات
     */
    fetchNotifications: async function() {
        try {
            const result = await API.notifications.list();
            if (result.success && result.data) {
                this.updateNotificationBadge(result.data);
            }
        } catch (error) {
            // تجاهل
        }
    },
    
    /**
     * تحديث شارة الإشعارات
     */
    updateNotificationBadge: function(notifications) {
        const unread = notifications.filter(n => !n.is_read).length;
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            badge.textContent = unread;
            badge.style.display = unread > 0 ? 'inline' : 'none';
        }
    },
    
    // ================================================================
    // تحديث البيانات
    // ================================================================
    
    /**
     * تحديث البيانات
     */
    refreshData: function() {
        // التحقق من أن الصفحة مرئية
        if (document.hidden) return;
        
        // تحديث الصفحة الحالية
        this.loadPageData(this.currentPage);
    },
    
    // ================================================================
    // أدوات مساعدة
    // ================================================================
    
    /**
     * عرض إشعار (Toast)
     */
    showToast: function(message, type = 'success', duration = 3000) {
        const existing = document.querySelector('.toast');
        if (existing) existing.remove();
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.4s ease';
            setTimeout(() => toast.remove(), 400);
        }, duration);
    },
    
    /**
     * عرض تنبيه (Alert)
     */
    showAlert: function(message, type = 'info') {
        Swal.fire({
            icon: type,
            title: message,
            timer: 3000,
            showConfirmButton: false
        });
    },
    
    /**
     * عرض تأكيد (Confirm)
     */
    showConfirm: async function(message, title = 'تأكيد') {
        const result = await Swal.fire({
            title: title,
            text: message,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم',
            cancelButtonText: 'إلغاء'
        });
        return result.isConfirmed;
    },
    
    /**
     * تنسيق العملة
     */
    formatCurrency: function(amount) {
        return new Intl.NumberFormat('ar-SA', {
            style: 'currency',
            currency: 'SAR',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);
    },
    
    /**
     * تنسيق التاريخ
     */
    formatDate: function(date) {
        if (!date) return '-';
        const d = new Date(date);
        return d.toLocaleDateString('ar-SA', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    },
    
    /**
     * تنسيق التاريخ فقط
     */
    formatDateOnly: function(date) {
        if (!date) return '-';
        const d = new Date(date);
        return d.toLocaleDateString('ar-SA', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    },
    
    /**
     * تنسيق رقم
     */
    formatNumber: function(number) {
        return new Intl.NumberFormat('ar-SA').format(number);
    },
    
    /**
     * الحصول على حالة التصنيف
     */
    getStatusBadge: function(status) {
        const badges = {
            'draft': '<span class="badge bg-secondary">مسودة</span>',
            'submitted': '<span class="badge bg-info">مرسل</span>',
            'approved': '<span class="badge bg-success">معتمد</span>',
            'rejected': '<span class="badge bg-danger">مرفوض</span>',
            'cancelled': '<span class="badge bg-dark">ملغي</span>',
            'completed': '<span class="badge bg-primary">مكتمل</span>',
            'delivered': '<span class="badge bg-success">تم التسليم</span>',
            'in_progress': '<span class="badge bg-warning">قيد التنفيذ</span>',
            'reviewed': '<span class="badge bg-info">مراجعة</span>',
            'active': '<span class="badge bg-success">نشط</span>',
            'inactive': '<span class="badge bg-secondary">غير نشط</span>',
            'out_of_stock': '<span class="badge bg-danger">نفذ</span>',
            'low_stock': '<span class="badge bg-warning">منخفض</span>',
            'over_stock': '<span class="badge bg-info">زائد</span>',
            'normal': '<span class="badge bg-success">طبيعي</span>'
        };
        return badges[status] || `<span class="badge bg-secondary">${status}</span>`;
    },
    
    /**
     * الحصول على لون الحالة
     */
    getStatusColor: function(status) {
        const colors = {
            'draft': 'secondary',
            'submitted': 'info',
            'approved': 'success',
            'rejected': 'danger',
            'cancelled': 'dark',
            'completed': 'primary',
            'delivered': 'success',
            'in_progress': 'warning',
            'reviewed': 'info',
            'active': 'success',
            'inactive': 'secondary',
            'out_of_stock': 'danger',
            'low_stock': 'warning',
            'over_stock': 'info',
            'normal': 'success'
        };
        return colors[status] || 'secondary';
    },
    
    /**
     * الحصول على تسمية نوع الحركة
     */
    getMovementLabel: function(type) {
        const labels = {
            'RECEIPT': 'استلام',
            'ISSUE': 'صرف',
            'TRANSFER_OUT': 'تحويل خارج',
            'TRANSFER_IN': 'تحويل داخل',
            'RETURN_IN': 'مرتجع للمخزن',
            'RETURN_OUT': 'مرتجع من المخزن',
            'ADJUSTMENT': 'تسوية',
            'COUNT_CORRECTION': 'تصحيح جرد'
        };
        return labels[type] || type;
    },
    
    /**
     * الحصول على أيقونة نوع الحركة
     */
    getMovementIcon: function(type) {
        const icons = {
            'RECEIPT': 'fa-arrow-down text-success',
            'ISSUE': 'fa-arrow-up text-danger',
            'TRANSFER_OUT': 'fa-exchange-alt text-warning',
            'TRANSFER_IN': 'fa-exchange-alt text-info',
            'RETURN_IN': 'fa-undo text-primary',
            'RETURN_OUT': 'fa-undo text-warning',
            'ADJUSTMENT': 'fa-balance-scale text-secondary',
            'COUNT_CORRECTION': 'fa-check-double text-success'
        };
        return icons[type] || 'fa-circle';
    },
    
    /**
     * تصدير البيانات كـ CSV
     */
    exportCSV: function(data, filename = 'export.csv') {
        if (!data || data.length === 0) {
            this.showToast('لا توجد بيانات للتصدير', 'warning');
            return;
        }
        
        const headers = Object.keys(data[0]);
        let csv = headers.join(',') + '\n';
        
        data.forEach(row => {
            csv += headers.map(h => {
                let val = row[h] || '';
                if (typeof val === 'string' && val.includes(',')) {
                    val = '"' + val + '"';
                }
                return val;
            }).join(',') + '\n';
        });
        
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
        URL.revokeObjectURL(link.href);
        
        this.showToast('✅ تم التصدير بنجاح');
    },
    
    /**
     * تصدير البيانات كـ Excel
     */
    exportExcel: function(data, filename = 'export.xls') {
        if (!data || data.length === 0) {
            this.showToast('لا توجد بيانات للتصدير', 'warning');
            return;
        }
        
        let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
        html += '<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets>';
        html += '<x:ExcelWorksheet><x:Name>Sheet1</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>';
        html += '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
        html += '<table border="1">';
        html += '<tr>' + Object.keys(data[0]).map(h => `<th style="background:#667eea;color:#fff;font-weight:bold;">${h}</th>`).join('') + '</tr>';
        
        data.forEach(row => {
            html += '<tr>' + Object.values(row).map(v => `<td>${v}</td>`).join('') + '</tr>';
        });
        
        html += '</table></body></html>';
        
        const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
        URL.revokeObjectURL(link.href);
        
        this.showToast('✅ تم التصدير بنجاح');
    },
    
    /**
     * طباعة العنصر
     */
    printElement: function(elementId) {
        const element = document.getElementById(elementId);
        if (!element) {
            this.showToast('العنصر غير موجود', 'error');
            return;
        }
        
        const printWindow = window.open('', '_blank', 'width=1000,height=800');
        printWindow.document.write(`
            <html>
                <head>
                    <title>طباعة</title>
                    <link rel="stylesheet" href="/assets/css/style.css">
                    <style>
                        body { padding: 30px; direction: rtl; font-family: 'Tajawal', sans-serif; }
                        .no-print { display: none; }
                        @media print { .no-print { display: none; } }
                    </style>
                </head>
                <body>
                    ${element.innerHTML}
                    <div class="no-print text-center mt-3">
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i> طباعة
                        </button>
                        <button class="btn btn-secondary" onclick="window.close()">
                            <i class="fas fa-times me-2"></i> إغلاق
                        </button>
                    </div>
                </body>
            </html>
        `);
        printWindow.document.close();
    },
    
    /**
     * تنظيف الموارد
     */
    destroy: function() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
        
        // تنظيف الـ Controllers
        if (typeof Dashboard !== 'undefined' && Dashboard.destroy) {
            Dashboard.destroy();
        }
        
        this.initialized = false;
    }
};

// ================================================================
// تهيئة التطبيق عند تحميل الصفحة
// ================================================================

document.addEventListener('DOMContentLoaded', function() {
    App.init();
});

// ================================================================
// تصدير App
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = App;
}

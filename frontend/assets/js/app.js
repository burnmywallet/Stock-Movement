/**
 * ============================================================================
 * Logistox - Main Application
 * ============================================================================
 */

const App = (() => {
    // ========================================================================
    // Initialization
    // ========================================================================
    const init = async () => {
        console.log('🚀 Initializing Logistox...');

        // تحميل الثيم المحفوظ
        loadTheme();

        // إذا كنا في صفحة dashboard
        if (window.location.pathname.includes('index.html') || 
            window.location.pathname === '/inventory-system/frontend/') {
            
            if (!Auth.requireAuth()) return;
            
            await loadLayout();
            await loadDashboard();
        }
    };

    // ========================================================================
    // Layout Loading
    // ========================================================================
    const loadLayout = async () => {
        try {
            // تحميل Sidebar
            const sidebarResponse = await fetch('/inventory-system/frontend/templates/sidebar.html');
            const sidebarHTML = await sidebarResponse.text();
            document.getElementById('sidebar-container').innerHTML = sidebarHTML;

            // تحميل Navbar
            const navbarResponse = await fetch('/inventory-system/frontend/templates/navbar.html');
            const navbarHTML = await navbarResponse.text();
            document.getElementById('navbar-container').innerHTML = navbarHTML;

            // تحديث معلومات المستخدم
            updateUserUI();

            // تفعيل التنقل
            setupNavigation();

            // تحميل الإشعارات
            loadNotifications();

        } catch (error) {
            console.error('Failed to load layout:', error);
            showAlert('فشل في تحميل الواجهة', 'danger');
        }
    };

    // ========================================================================
    // User UI
    // ========================================================================
    const updateUserUI = () => {
        const user = Auth.getCurrentUser();
        if (!user) return;

        // تحديث اسم المستخدم
        const userNameEl = document.getElementById('user-full-name');
        if (userNameEl) userNameEl.textContent = user.full_name;

        const userRoleEl = document.getElementById('user-role');
        if (userRoleEl) userRoleEl.textContent = user.role_display_name || user.role_name;

        const userInitials = document.getElementById('user-initials');
        if (userInitials) {
            const initials = user.full_name.split(' ').map(n => n[0]).join('').substring(0, 2);
            userInitials.textContent = initials;
        }
    };

    // ========================================================================
    // Navigation
    // ========================================================================
    const setupNavigation = () => {
        document.querySelectorAll('[data-page]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = link.dataset.page;
                loadPage(page);
                
                // تحديث الحالة النشطة
                document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
                link.classList.add('active');
            });
        });
    };

    const loadPage = async (pageName) => {
        const contentArea = document.getElementById('main-content');
        if (!contentArea) return;

        contentArea.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">جاري التحميل...</span>
                </div>
                <p class="mt-3 text-muted">جاري تحميل الصفحة...</p>
            </div>
        `;

        try {
            const response = await fetch(`/inventory-system/frontend/pages/${pageName}.html`);
            if (!response.ok) throw new Error('الصفحة غير موجودة');
            
            const html = await response.text();
            contentArea.innerHTML = html;

            // تحميل JavaScript الخاص بالصفحة
            const scriptPath = `/inventory-system/frontend/assets/js/modules/${pageName}.js`;
            try {
                const script = document.createElement('script');
                script.src = scriptPath;
                script.onload = () => {
                    if (window[`init${capitalize(pageName)}`]) {
                        window[`init${capitalize(pageName)}`]();
                    }
                };
                document.body.appendChild(script);
            } catch (e) {
                console.log('Page script not found:', scriptPath);
            }

        } catch (error) {
            contentArea.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    فشل في تحميل الصفحة: ${error.message}
                </div>
            `;
        }
    };

    // ========================================================================
    // Dashboard
    // ========================================================================
    const loadDashboard = async () => {
        try {
            const statsResponse = await API.get('/dashboard/stats');
            if (statsResponse.success) {
                renderDashboardStats(statsResponse.data);
            }

            const alertsResponse = await API.get('/dashboard/alerts');
            if (alertsResponse.success) {
                renderDashboardAlerts(alertsResponse.data);
            }

        } catch (error) {
            console.error('Failed to load dashboard:', error);
        }
    };

    const renderDashboardStats = (data) => {
        const stats = [
            { 
                label: 'إجمالي المنتجات', 
                value: data.products?.total || 0, 
                icon: 'fa-boxes', 
                color: 'primary' 
            },
            { 
                label: 'المخازن النشطة', 
                value: data.warehouses?.active || 0, 
                icon: 'fa-warehouse', 
                color: 'success' 
            },
            { 
                label: 'المستخدمون', 
                value: data.users?.active || 0, 
                icon: 'fa-users', 
                color: 'info' 
            },
            { 
                label: 'المخزون المنخفض', 
                value: data.stock?.low_stock_count || 0, 
                icon: 'fa-exclamation-triangle', 
                color: 'warning' 
            },
        ];

        const container = document.getElementById('stats-cards');
        if (!container) return;

        container.innerHTML = stats.map(stat => `
            <div class="col-md-6 col-xl-3">
                <div class="card stat-card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">${stat.label}</h6>
                                <h2 class="mb-0 fw-bold">${formatNumber(stat.value)}</h2>
                            </div>
                            <div class="stat-icon bg-${stat.color}-light text-${stat.color}">
                                <i class="fas ${stat.icon}"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
    };

    const renderDashboardAlerts = (data) => {
        const alertsContainer = document.getElementById('alerts-container');
        if (!alertsContainer) return;

        let alertsHTML = '';

        // Low Stock
        if (data.low_stock && data.low_stock.length > 0) {
            alertsHTML += `
                <div class="alert alert-warning">
                    <h6><i class="fas fa-exclamation-triangle"></i> منتجات منخفضة المخزون</h6>
                    <ul class="mb-0">
                        ${data.low_stock.slice(0, 5).map(p => `
                            <li>${p.name} - ${p.total_quantity} ${p.unit_symbol || ''} 
                                (نقطة الطلب: ${p.reorder_point})</li>
                        `).join('')}
                    </ul>
                </div>
            `;
        }

        // Pending Operations
        if (data.pending_operations) {
            const { receipts, issues, transfers } = data.pending_operations;
            const total = receipts + issues + transfers;
            if (total > 0) {
                alertsHTML += `
                    <div class="alert alert-info">
                        <h6><i class="fas fa-clock"></i> عمليات قيد الانتظار</h6>
                        <div class="d-flex gap-3">
                            ${receipts > 0 ? `<span><strong>${receipts}</strong> استلام</span>` : ''}
                            ${issues > 0 ? `<span><strong>${issues}</strong> صرف</span>` : ''}
                            ${transfers > 0 ? `<span><strong>${transfers}</strong> تحويل</span>` : ''}
                        </div>
                    </div>
                `;
            }
        }

        alertsContainer.innerHTML = alertsHTML || '<div class="alert alert-success"><i class="fas fa-check-circle"></i> لا توجد تنبيهات حالياً</div>';
    };

    // ========================================================================
    // Theme Management
    // ========================================================================
    const loadTheme = () => {
        const theme = localStorage.getItem('logistox_theme') || 'dark';
        setTheme(theme);
    };

    const setTheme = (theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('logistox_theme', theme);
        
        const themeLink = document.getElementById('theme-css');
        if (themeLink) {
            themeLink.href = `/inventory-system/frontend/assets/css/themes/${theme}.css`;
        }
    };

    const toggleTheme = () => {
        const current = localStorage.getItem('logistox_theme') || 'dark';
        setTheme(current === 'dark' ? 'light' : 'dark');
    };

    // ========================================================================
    // Notifications
    // ========================================================================
    const loadNotifications = async () => {
        try {
            const response = await API.get('/notifications/unread-count');
            if (response.success) {
                updateNotificationBadge(response.data.total);
            }
        } catch (error) {
            console.error('Failed to load notifications:', error);
        }
    };

    const updateNotificationBadge = (count) => {
        const badge = document.getElementById('notification-badge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
    };

    // ========================================================================
    // Helpers
    // ========================================================================
    const showAlert = (message, type = 'info') => {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        alertDiv.style.cssText = 'top: 20px; left: 50%; transform: translateX(-50%); z-index: 9999; min-width: 300px;';
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(alertDiv);
        setTimeout(() => alertDiv.remove(), 5000);
    };

    const formatNumber = (num) => {
        return new Intl.NumberFormat('ar-EG').format(num);
    };

    const capitalize = (str) => str.charAt(0).toUpperCase() + str.slice(1);

    // ========================================================================
    // Public API
    // ========================================================================
    return {
        init,
        loadPage,
        setTheme,
        toggleTheme,
        showAlert,
        formatNumber,
    };
})();

// بدء التطبيق عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', () => {
    App.init();
});

window.App = App;
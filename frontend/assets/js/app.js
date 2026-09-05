/**
 * ================================================================
 * Logistox - التطبيق الرئيسي
 * نظام إدارة المخازن والمخزون v5.0
 * ================================================================
 */

// منع التكرار
if (typeof window.initApp === 'undefined') {

// ================================================================
// تهيئة التطبيق
// ================================================================
function initApp() {
    console.log('%c📊 Logistox v5.0', 'font-size:22px;font-weight:bold;color:#667eea;');
    console.log('%c🏢 ' + window.APP_CONFIG.COMPANY.NAME, 'font-size:14px;color:#aaa;');
    console.log('%c👨‍💻 Developed by ' + window.APP_CONFIG.DEVELOPER.NAME + ' (' + window.APP_CONFIG.DEVELOPER.ALIAS + ')', 'font-size:12px;color:#aaa;');
    console.log('%c🔗 API URL: ' + window.APP_CONFIG.API.BASE_URL, 'font-size:12px;color:#667eea;');
    console.log('%c🔐 حالة المصادقة: ' + (window.Auth.isAuthenticated() ? '✅ نشطة' : '❌ غير نشطة'), 'font-size:12px;color:' + (window.Auth.isAuthenticated() ? '#28a745' : '#dc3545') + ';');

    // تهيئة الثيم
    window.ThemeManager.init();

    // التحقق من المصادقة
    if (!window.Auth.checkAccess()) {
        return;
    }

    // تهيئة WebSocket
    window.WebSocketManager.init();

    // تحديث بيانات المستخدم
    updateUserProfile();

    // إعداد مستمعي الأحداث
    setupEventListeners();

    // إعداد الإشعارات
    setupNotifications();

    // إعداد البحث العام
    setupGlobalSearch();

    // إعداد اختصارات لوحة المفاتيح
    setupKeyboardShortcuts();

    // إعداد التحديث التلقائي
    setupAutoRefresh();

    // إعداد اللغة
    setupLanguage();

    // إعداد وضع النظام
    setupSystemMode();

    // إعداد النسخ الاحتياطي التلقائي
    setupAutoBackup();
}

// ================================================================
// تحديث ملف المستخدم
// ================================================================
function updateUserProfile() {
    const user = window.Auth.getUser();
    if (!user) return;

    // تحديث اسم المستخدم
    const userName = document.getElementById('userName');
    if (userName) {
        userName.textContent = user.full_name || user.username;
    }

    // تحديث الدور
    const userRole = document.getElementById('userRole');
    if (userRole) {
        userRole.textContent = user.role_display || user.role || 'مستخدم';
    }

    // تحديث الصورة الرمزية
    const avatar = document.getElementById('userAvatar');
    if (avatar) {
        avatar.textContent = (user.full_name || user.username || 'م').charAt(0);
    }
}

// ================================================================
// إعداد مستمعي الأحداث
// ================================================================
function setupEventListeners() {
    // زر تسجيل الخروج
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            if (confirm('هل أنت متأكد من تسجيل الخروج؟')) {
                await window.Auth.logout();
            }
        });
    }

    // زر تحديث البيانات
    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            window.showToast('🔄 جاري تحديث البيانات...', 'warning');
            setTimeout(() => {
                window.location.reload();
            }, 500);
        });
    }

    // زر الإشعارات
    const notifBtn = document.getElementById('notifBtn');
    if (notifBtn) {
        notifBtn.addEventListener('click', () => {
            window.location.href = '/inventory-system/frontend/pages/notifications.html';
        });
    }

    // زر تبديل الثيم
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const theme = window.ThemeManager.toggleTheme();
            window.showToast('🎨 تم التبديل إلى ' + theme.display_name, 'success');
        });
    }

    // زر القائمة الجانبية
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.toggle('open');
                sidebarToggle.querySelector('i').classList.toggle('fa-bars');
                sidebarToggle.querySelector('i').classList.toggle('fa-times');
            }
        });
    }

    // مستمع لتغيير حجم النافذة
    window.addEventListener('resize', () => {
        if (window.innerWidth > 992) {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.remove('open');
            }
            const sidebarToggle = document.getElementById('sidebarToggle');
            if (sidebarToggle) {
                sidebarToggle.querySelector('i').classList.remove('fa-times');
                sidebarToggle.querySelector('i').classList.add('fa-bars');
            }
        }
    });

    // مستمع لتغيير حالة الاتصال
    window.addEventListener('online', () => {
        window.showToast('✅ تم استعادة الاتصال بالإنترنت', 'success');
        window.WebSocketManager.reconnect();
    });

    window.addEventListener('offline', () => {
        window.showToast('⚠️ فقد الاتصال بالإنترنت', 'warning');
        window.WebSocketManager.disconnect();
    });

    // مستمع لتغيير المصادقة
    window.addEventListener('auth:login', () => {
        window.WebSocketManager.connect();
        updateUserProfile();
    });

    window.addEventListener('auth:logout', () => {
        window.WebSocketManager.disconnect();
    });
}

// ================================================================
// إعداد الإشعارات
// ================================================================
function setupNotifications() {
    // التحقق من صلاحيات إشعارات المتصفح
    if ('Notification' in window && window.APP_CONFIG.NOTIFICATIONS.BROWSER_ENABLED) {
        Notification.requestPermission().then(permission => {
            if (permission === 'granted') {
                console.log('✅ إشعارات المتصفح مفعلة');
            }
        });
    }

    // تحديث عداد الإشعارات
    updateNotificationsBadge();
    
    // تحديث دوري لعداد الإشعارات
    setInterval(updateNotificationsBadge, 60000);
}

// ================================================================
// تحديث عداد الإشعارات
// ================================================================
async function updateNotificationsBadge() {
    try {
        const notifications = await window.Api.getNotifications();
        const unreadCount = notifications.filter(n => !n.is_read).length;
        
        const notifCount = document.getElementById('notifCount');
        const notifDot = document.getElementById('notifDot');
        
        if (notifCount) {
            if (unreadCount > 0) {
                notifCount.textContent = unreadCount;
                notifCount.classList.add('active');
            } else {
                notifCount.classList.remove('active');
            }
        }
        
        if (notifDot) {
            notifDot.classList.toggle('active', unreadCount > 0);
        }
    } catch (error) {
        console.error('Error loading notifications:', error);
    }
}

// ================================================================
// إعداد البحث العام
// ================================================================
function setupGlobalSearch() {
    const searchInput = document.getElementById('globalSearch');
    if (!searchInput) return;

    let searchTimer = null;

    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.trim();
        
        // إلغاء المؤقت السابق
        clearTimeout(searchTimer);
        
        // تأخير البحث 300ms (debounce)
        searchTimer = setTimeout(async () => {
            if (query.length >= 2) {
                await performGlobalSearch(query);
            } else {
                hideSearchResults();
            }
        }, 300);
    });

    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            searchInput.value = '';
            hideSearchResults();
        }
    });
}

// ================================================================
// تنفيذ البحث العام
// ================================================================
async function performGlobalSearch(query) {
    try {
        const results = await window.Api.globalSearch(query);
        
        const searchResultsContainer = document.getElementById('searchResults');
        if (searchResultsContainer) {
            showSearchResults(results, searchResultsContainer);
        }
        
        const currentPage = window.location.pathname.split('/').pop().replace('.html', '');
        if (currentPage === 'products') {
            window.loadProducts({ search: query });
        } else if (currentPage === 'warehouses') {
            window.loadWarehouses({ search: query });
        } else if (currentPage === 'users') {
            window.loadUsers({ search: query });
        }
    } catch (error) {
        console.error('Search error:', error);
    }
}

// ================================================================
// عرض نتائج البحث
// ================================================================
function showSearchResults(results, container) {
    const html = results.map(result => `
        <div class="search-result-item" onclick="window.location.href='${result.url}'">
            <i class="fas ${result.icon}"></i>
            <div>
                <div class="result-title">${result.title}</div>
                <div class="result-subtitle">${result.subtitle}</div>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = html;
    container.classList.add('show');
}

// ================================================================
// إخفاء نتائج البحث
// ================================================================
function hideSearchResults() {
    const container = document.getElementById('searchResults');
    if (container) {
        container.classList.remove('show');
        container.innerHTML = '';
    }
}

// ================================================================
// إعداد اختصارات لوحة المفاتيح
// ================================================================
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
        // Ctrl + K للبحث
        if (e.ctrlKey && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('globalSearch');
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
        
        // Ctrl + R للتحديث
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            const refreshBtn = document.getElementById('refreshBtn');
            if (refreshBtn) {
                refreshBtn.click();
            }
        }
        
        // Ctrl + T لتبديل الثيم
        if (e.ctrlKey && e.key === 't') {
            e.preventDefault();
            const themeToggle = document.getElementById('themeToggle');
            if (themeToggle) {
                themeToggle.click();
            }
        }
        
        // Ctrl + L لتسجيل الخروج
        if (e.ctrlKey && e.key === 'l') {
            e.preventDefault();
            const logoutBtn = document.getElementById('logoutBtn');
            if (logoutBtn) {
                logoutBtn.click();
            }
        }
        
        // Esc لإغلاق أي مودال
        if (e.key === 'Escape') {
            const modals = document.querySelectorAll('.modal-overlay.show');
            modals.forEach(modal => {
                modal.classList.remove('show');
            });
        }
    });
}

// ================================================================
// إعداد التحديث التلقائي
// ================================================================
function setupAutoRefresh() {
    // تحديث البيانات كل 60 ثانية
    setInterval(async () => {
        const currentPage = window.location.pathname.split('/').pop().replace('.html', '');
        
        try {
            switch (currentPage) {
                case 'dashboard':
                    if (typeof window.loadDashboard === 'function') await window.loadDashboard();
                    break;
                case 'products':
                    if (typeof window.loadProducts === 'function') await window.loadProducts();
                    break;
                case 'warehouses':
                    if (typeof window.loadWarehouses === 'function') await window.loadWarehouses();
                    break;
                case 'users':
                    if (typeof window.loadUsers === 'function') await window.loadUsers();
                    break;
                case 'receipts':
                    if (typeof window.loadReceipts === 'function') await window.loadReceipts();
                    break;
                case 'issues':
                    if (typeof window.loadIssues === 'function') await window.loadIssues();
                    break;
                case 'transfers':
                    if (typeof window.loadTransfers === 'function') await window.loadTransfers();
                    break;
                case 'returns':
                    if (typeof window.loadReturns === 'function') await window.loadReturns();
                    break;
                case 'stock-balances':
                    if (typeof window.loadStockBalances === 'function') await window.loadStockBalances();
                    break;
                case 'stock-movements':
                    if (typeof window.loadStockMovements === 'function') await window.loadStockMovements();
                    break;
                case 'notifications':
                    if (typeof window.loadNotifications === 'function') await window.loadNotifications();
                    break;
            }
        } catch (error) {
            console.error('Auto refresh error:', error);
        }
    }, 60000);
}

// ================================================================
// إعداد اللغة
// ================================================================
function setupLanguage() {
    const savedLang = localStorage.getItem('app_language') || 'ar';
    applyLanguage(savedLang);
    
    window.addEventListener('language:change', (e) => {
        applyLanguage(e.detail);
    });
}

// ================================================================
// تطبيق اللغة
// ================================================================
function applyLanguage(lang) {
    localStorage.setItem('app_language', lang);
    
    document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.lang = lang;
    
    document.querySelectorAll('[data-i18n]').forEach(element => {
        const key = element.getAttribute('data-i18n');
        const translation = window.t(key);
        if (translation) {
            element.textContent = translation;
        }
    });
    
    const pageTitle = document.querySelector('.page-title h1');
    if (pageTitle) {
        const titleKey = pageTitle.getAttribute('data-i18n');
        if (titleKey) {
            pageTitle.textContent = window.t(titleKey);
        }
    }
    
    document.querySelectorAll('.sidebar .nav-item').forEach(item => {
        const key = item.getAttribute('data-i18n');
        if (key) {
            const textElement = item.querySelector('span');
            if (textElement) {
                textElement.textContent = window.t(key);
            }
        }
    });
    
    window.dispatchEvent(new CustomEvent('language:applied', { detail: lang }));
}

// ================================================================
// إعداد وضع النظام
// ================================================================
function setupSystemMode() {
    const autoTheme = localStorage.getItem('auto_theme');
    if (autoTheme === 'true') {
        window.ThemeManager.initAutoTheme();
    }
    
    setInterval(() => {
        if (localStorage.getItem('auto_theme') === 'true') {
            window.ThemeManager.initAutoTheme();
        }
    }, 300000);
}

// ================================================================
// إعداد النسخ الاحتياطي التلقائي
// ================================================================
function setupAutoBackup() {
    const autoBackup = localStorage.getItem('auto_backup') === 'true';
    if (!autoBackup) return;
    
    const lastBackup = localStorage.getItem('last_backup');
    const now = new Date();
    
    if (!lastBackup) {
        createAutoBackup();
        return;
    }
    
    const lastBackupDate = new Date(lastBackup);
    const daysSinceLastBackup = Math.floor((now - lastBackupDate) / (1000 * 60 * 60 * 24));
    
    if (daysSinceLastBackup >= 1) {
        createAutoBackup();
    }
}

// ================================================================
// إنشاء نسخة احتياطية تلقائية
// ================================================================
async function createAutoBackup() {
    try {
        await window.Api.createBackup();
        localStorage.setItem('last_backup', new Date().toISOString());
        console.log('✅ تم إنشاء نسخة احتياطية تلقائية');
    } catch (error) {
        console.error('Auto backup error:', error);
    }
}

// ================================================================
// عرض بيانات المستخدم
// ================================================================
function showUserInfo() {
    const user = window.Auth.getUser();
    if (!user) return;
    
    const modalBody = document.getElementById('modalBody');
    if (!modalBody) return;
    
    modalBody.innerHTML = `
        <div style="text-align:center;margin-bottom:20px;">
            <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;font-size:32px;color:white;margin:0 auto 15px;">
                ${(user.full_name || user.username || 'م').charAt(0)}
            </div>
            <h3 style="color:var(--text-primary);margin-bottom:5px;">${user.full_name || 'مستخدم'}</h3>
            <p style="color:var(--text-muted);font-size:13px;">@${user.username}</p>
        </div>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:20px;">
            <div style="background:var(--bg-card);padding:15px;border-radius:10px;border:1px solid var(--border-color);">
                <div style="color:var(--text-muted);font-size:12px;margin-bottom:5px;">الدور</div>
                <div style="color:var(--text-primary);font-weight:600;">${user.role_display || user.role || 'مستخدم'}</div>
            </div>
            <div style="background:var(--bg-card);padding:15px;border-radius:10px;border:1px solid var(--border-color);">
                <div style="color:var(--text-muted);font-size:12px;margin-bottom:5px;">البريد الإلكتروني</div>
                <div style="color:var(--text-primary);font-weight:600;">${user.email || 'غير متوفر'}</div>
            </div>
            <div style="background:var(--bg-card);padding:15px;border-radius:10px;border:1px solid var(--border-color);">
                <div style="color:var(--text-muted);font-size:12px;margin-bottom:5px;">الهاتف</div>
                <div style="color:var(--text-primary);font-weight:600;">${user.phone || 'غير متوفر'}</div>
            </div>
            <div style="background:var(--bg-card);padding:15px;border-radius:10px;border:1px solid var(--border-color);">
                <div style="color:var(--text-muted);font-size:12px;margin-bottom:5px;">المخزن</div>
                <div style="color:var(--text-primary);font-weight:600;">${user.warehouse || 'الكل'}</div>
            </div>
        </div>
        
        <div style="display:flex;gap:10px;justify-content:center;">
            <button class="btn btn-primary" onclick="window.location.href='${window.APP_CONFIG.PAGES.SETTINGS}'">
                <i class="fas fa-cog"></i> الإعدادات
            </button>
            <button class="btn btn-secondary" onclick="closeModal()">
                <i class="fas fa-times"></i> إغلاق
            </button>
        </div>
    `;
    
    document.getElementById('modalTitle').textContent = '👤 معلومات المستخدم';
    document.getElementById('activityModal').classList.add('show');
}

// ================================================================
// عرض الإعدادات السريعة
// ================================================================
function showQuickSettings() {
    const modalBody = document.getElementById('modalBody');
    if (!modalBody) return;
    
    modalBody.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
            <div style="background:var(--bg-card);padding:20px;border-radius:12px;text-align:center;cursor:pointer;border:1px solid var(--border-color);transition:var(--transition);" onclick="window.ThemeManager.toggleTheme()">
                <i class="fas fa-palette" style="font-size:28px;color:var(--primary);margin-bottom:10px;"></i>
                <div style="font-size:14px;font-weight:600;">تغيير الثيم</div>
            </div>
            <div style="background:var(--bg-card);padding:20px;border-radius:12px;text-align:center;cursor:pointer;border:1px solid var(--border-color);transition:var(--transition);" onclick="window.location.href='${window.APP_CONFIG.PAGES.SETTINGS}'">
                <i class="fas fa-cog" style="font-size:28px;color:var(--success);margin-bottom:10px;"></i>
                <div style="font-size:14px;font-weight:600;">الإعدادات</div>
            </div>
            <div style="background:var(--bg-card);padding:20px;border-radius:12px;text-align:center;cursor:pointer;border:1px solid var(--border-color);transition:var(--transition);" onclick="window.location.href='${window.APP_CONFIG.PAGES.REPORTS}'">
                <i class="fas fa-chart-bar" style="font-size:28px;color:var(--warning);margin-bottom:10px;"></i>
                <div style="font-size:14px;font-weight:600;">التقارير</div>
            </div>
            <div style="background:var(--bg-card);padding:20px;border-radius:12px;text-align:center;cursor:pointer;border:1px solid var(--border-color);transition:var(--transition);" onclick="window.location.href='${window.APP_CONFIG.PAGES.BACKUP}'">
                <i class="fas fa-database" style="font-size:28px;color:var(--info);margin-bottom:10px;"></i>
                <div style="font-size:14px;font-weight:600;">النسخ الاحتياطي</div>
            </div>
        </div>
    `;
    
    document.getElementById('modalTitle').textContent = '⚡ إعدادات سريعة';
    document.getElementById('activityModal').classList.add('show');
}

// ================================================================
// إغلاق المودال
// ================================================================
function closeModal() {
    document.getElementById('activityModal').classList.remove('show');
}

// ================================================================
// تصدير الدوال العامة
// ================================================================
window.initApp = initApp;
window.updateUserProfile = updateUserProfile;
window.showUserInfo = showUserInfo;
window.showQuickSettings = showQuickSettings;
window.closeModal = closeModal;
window.updateNotificationsBadge = updateNotificationsBadge;
window.applyLanguage = applyLanguage;

// ================================================================
// تشغيل التطبيق عند تحميل الصفحة
// ================================================================
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.initApp === 'function') {
        window.initApp();
    }
});

} // نهاية منع التكرار

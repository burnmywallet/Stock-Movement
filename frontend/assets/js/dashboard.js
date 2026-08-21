// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/dashboard.js
// الوصف: لوحة التحكم - إحصائيات ورسوم بيانية وتنبيهات
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

/**
 * لوحة التحكم - إدارة وعرض جميع البيانات
 */
const Dashboard = {
    // ================================================================
    // الحالة
    // ================================================================
    
    charts: {},
    refreshInterval: null,
    statsData: null,
    
    // ================================================================
    // التحميل
    // ================================================================
    
    /**
     * تحميل لوحة التحكم
     */
    load: function() {
        this.loadStats();
        this.loadCharts();
        this.loadAlerts();
        this.loadActivities();
        this.loadSystemStatus();
        
        // تحديث تلقائي كل 30 ثانية
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
        this.refreshInterval = setInterval(() => this.refresh(), 30000);
    },
    
    /**
     * تحديث لوحة التحكم
     */
    refresh: function() {
        this.loadStats();
        this.loadAlerts();
        this.loadActivities();
        this.loadSystemStatus();
    },
    
    // ================================================================
    // الإحصائيات
    // ================================================================
    
    /**
     * تحميل الإحصائيات
     */
    loadStats: function() {
        API.dashboard.stats()
            .then(response => {
                if (response.success && response.data) {
                    this.statsData = response.data;
                    this.updateStats(response.data);
                }
            })
            .catch(error => {
                console.error('Error loading stats:', error);
            });
    },
    
    /**
     * تحديث الإحصائيات
     */
    updateStats: function(stats) {
        // تحديث البطاقات الرئيسية
        this.updateStatCard('statProducts', stats.products?.total || 0);
        this.updateStatCard('statWarehouses', stats.warehouses?.active || 0);
        this.updateStatCard('statUsers', stats.users?.active || 0);
        this.updateStatCard('statMovements', stats.today_movements?.total || 0);
        
        // تحديث التقدم
        this.updateProgress('progressProducts', stats.products?.total || 0, 200);
        this.updateProgress('progressWarehouses', stats.warehouses?.active || 0, 20);
        this.updateProgress('progressUsers', stats.users?.active || 0, 20);
        this.updateProgress('progressMovements', stats.today_movements?.total || 0, 50);
        
        // تحديث التغييرات
        this.updateChange('changeProducts', stats.products?.total || 0);
        this.updateChange('changeWarehouses', stats.warehouses?.active || 0);
        this.updateChange('changeUsers', stats.users?.active || 0);
        this.updateChange('changeMovements', stats.today_movements?.total || 0);
        
        // تحديث شارة الأصناف
        const badge = document.querySelector('.sidebar-menu a[href="#products"] .badge');
        if (badge) {
            badge.textContent = stats.products?.total || 0;
        }
        
        // تحديث شارة التنبيهات
        const alertBadge = document.getElementById('notificationBadge');
        if (alertBadge) {
            const count = stats.unread_notifications || 0;
            alertBadge.textContent = count;
            alertBadge.style.display = count > 0 ? 'inline' : 'none';
        }
        
        // تحديث الأصناف المنخفضة والمنفذة
        document.getElementById('lowStockCount').textContent = stats.low_stock || 0;
        document.getElementById('outStockCount').textContent = stats.out_of_stock || 0;
        document.getElementById('alertCount').textContent = stats.unread_notifications || 0;
        
        // عرض الأصناف المنخفضة
        this.renderLowStockItems(stats.low_stock_items || []);
        
        // عرض الأصناف المنفذة
        this.renderOutOfStockItems(stats.out_of_stock_items || []);
        
        // عرض التنبيهات
        this.renderAlerts(stats.alerts || []);
        
        // عرض آخر الحركات
        this.renderRecentMovements(stats.recent_movements || []);
        
        // عرض ملخص المخزون
        this.renderSummary(stats);
    },
    
    /**
     * تحديث بطاقة إحصائية
     */
    updateStatCard: function(id, value) {
        const el = document.getElementById(id);
        if (el) {
            this.animateNumber(el, value);
        }
    },
    
    /**
     * تحديث شريط التقدم
     */
    updateProgress: function(id, value, max) {
        const el = document.getElementById(id);
        if (el) {
            const percentage = Math.min((value / max) * 100, 100);
            el.style.width = percentage + '%';
        }
    },
    
    /**
     * تحديث التغيير
     */
    updateChange: function(id, value) {
        const el = document.getElementById(id);
        if (el) {
            // محاكاة حساب التغيير (يمكن استبدالها ببيانات حقيقية)
            const change = Math.round((Math.random() * 10) - 5);
            const isUp = change >= 0;
            el.className = `change ${isUp ? 'up' : 'down'}`;
            el.innerHTML = `<i class="fas fa-${isUp ? 'arrow-up' : 'arrow-down'}"></i> ${Math.abs(change)}%`;
        }
    },
    
    /**
     * عرض الأصناف المنخفضة
     */
    renderLowStockItems: function(items) {
        const container = document.getElementById('lowStockContainer');
        if (!container) return;
        
        if (!items || items.length === 0) {
            container.innerHTML = '<div class="empty">✅ لا توجد أصناف منخفضة</div>';
            return;
        }
        
        container.innerHTML = items.map(item => `
            <div class="activity-item">
                <div class="icon orange">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="content">
                    <div class="text">
                        <strong>${item.name}</strong>
                        <span style="color: #ffc107; font-size: 12px;">
                            (${item.quantity} / ${item.min_stock})
                        </span>
                    </div>
                    <div class="time">${item.warehouse_name}</div>
                </div>
            </div>
        `).join('');
    },
    
    /**
     * عرض الأصناف المنفذة
     */
    renderOutOfStockItems: function(items) {
        const container = document.getElementById('outStockContainer');
        if (!container) return;
        
        if (!items || items.length === 0) {
            container.innerHTML = '<div class="empty">✅ لا توجد أصناف منفذة</div>';
            return;
        }
        
        container.innerHTML = items.map(item => `
            <div class="activity-item">
                <div class="icon red">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="content">
                    <div class="text">
                        <strong>${item.name}</strong>
                        <span style="color: #dc3545; font-size: 12px;">(نفذ)</span>
                    </div>
                    <div class="time">${item.warehouse_name}</div>
                </div>
            </div>
        `).join('');
    },
    
    /**
     * عرض التنبيهات
     */
    renderAlerts: function(alerts) {
        const container = document.getElementById('alertsContainer');
        if (!container) return;
        
        if (!alerts || alerts.length === 0) {
            container.innerHTML = '<div class="empty">🔔 لا توجد تنبيهات</div>';
            return;
        }
        
        container.innerHTML = alerts.map(alert => {
            const priorityIcon = this.getPriorityIcon(alert.priority);
            return `
                <div class="alert-item">
                    <div class="icon">${priorityIcon}</div>
                    <div class="content">
                        <div class="title">${alert.title}</div>
                        <div class="message">${alert.message}</div>
                    </div>
                    <div class="time">${App.formatDate(alert.created_at)}</div>
                </div>
            `;
        }).join('');
    },
    
    /**
     * عرض آخر الحركات
     */
    renderRecentMovements: function(movements) {
        const container = document.getElementById('movementsContainer');
        if (!container) return;
        
        if (!movements || movements.length === 0) {
            container.innerHTML = '<div class="empty">لا توجد حركات</div>';
            return;
        }
        
        container.innerHTML = movements.map(m => {
            const iconClass = this.getMovementIconClass(m.movement_type);
            const label = App.getMovementLabel(m.movement_type);
            return `
                <div class="activity-item">
                    <div class="icon ${iconClass}">
                        <i class="fas fa-${m.movement_type === 'RECEIPT' ? 'arrow-down' : m.movement_type === 'ISSUE' ? 'arrow-up' : 'exchange-alt'}"></i>
                    </div>
                    <div class="content">
                        <div class="text">
                            <strong>${m.user_name || 'نظام'}</strong>
                            <span class="badge-type ${m.movement_type === 'RECEIPT' ? 'receipt' : m.movement_type === 'ISSUE' ? 'issue' : 'transfer'}">
                                ${label}
                            </span>
                            ${m.product_name} (${m.quantity})
                        </div>
                        <div class="time">${App.formatDate(m.movement_date)}</div>
                    </div>
                </div>
            `;
        }).join('');
    },
    
    /**
     * عرض ملخص المخزون
     */
    renderSummary: function(stats) {
        const container = document.getElementById('summaryContainer');
        if (!container) return;
        
        container.innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="text-align:center;padding:12px;background:rgba(255,255,255,0.02);border-radius:10px;">
                    <div style="color:rgba(255,255,255,0.3);font-size:12px;">الكمية الإجمالية</div>
                    <div style="color:#fff;font-size:22px;font-weight:700;">
                        ${App.formatNumber(stats.total_quantity || 0)}
                    </div>
                </div>
                <div style="text-align:center;padding:12px;background:rgba(255,255,255,0.02);border-radius:10px;">
                    <div style="color:rgba(255,255,255,0.3);font-size:12px;">القيمة الإجمالية</div>
                    <div style="color:#28a745;font-size:22px;font-weight:700;">
                        ${App.formatCurrency(stats.total_value || 0)}
                    </div>
                </div>
                <div style="text-align:center;padding:12px;background:rgba(255,255,255,0.02);border-radius:10px;">
                    <div style="color:rgba(255,255,255,0.3);font-size:12px;">المحجوز</div>
                    <div style="color:#ffc107;font-size:22px;font-weight:700;">
                        ${App.formatNumber(stats.reserved_quantity || 0)}
                    </div>
                </div>
                <div style="text-align:center;padding:12px;background:rgba(255,255,255,0.02);border-radius:10px;">
                    <div style="color:rgba(255,255,255,0.3);font-size:12px;">المتاح</div>
                    <div style="color:#17a2b8;font-size:22px;font-weight:700;">
                        ${App.formatNumber((stats.total_quantity || 0) - (stats.reserved_quantity || 0))}
                    </div>
                </div>
            </div>
        `;
    },
    
    // ================================================================
    // الرسوم البيانية
    // ================================================================
    
    /**
     * تحميل الرسوم البيانية
     */
    loadCharts: function() {
        API.dashboard.charts()
            .then(response => {
                if (response.success && response.data) {
                    this.renderCharts(response.data);
                }
            })
            .catch(error => {
                console.error('Error loading charts:', error);
            });
    },
    
    /**
     * عرض الرسوم البيانية
     */
    renderCharts: function(data) {
        // عرض حركات 30 يوم
        if (data.movements_30days && data.movements_30days.length > 0) {
            this.renderMovementsChart(data.movements_30days);
        }
        
        // عرض حالة المخزون (دائري)
        this.renderStockStatusChart();
    },
    
    /**
     * عرض رسم بياني للحركات
     */
    renderMovementsChart: function(movements) {
        const ctx = document.getElementById('movementsChart');
        if (!ctx) return;
        
        // تدمير الرسم البياني السابق
        if (this.charts.movements) {
            this.charts.movements.destroy();
        }
        
        const labels = movements.map(m => {
            const date = new Date(m.date);
            return date.toLocaleDateString('ar-SA', { weekday: 'short', day: 'numeric' });
        });
        
        const receipts = movements.map(m => m.receipts || 0);
        const issues = movements.map(m => m.issues || 0);
        const transfers = movements.map(m => m.transfers || 0);
        
        this.charts.movements = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'استلام',
                        data: receipts,
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: '#28a745',
                        borderWidth: 2,
                        borderRadius: 4,
                        barPercentage: 0.8
                    },
                    {
                        label: 'صرف',
                        data: issues,
                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        borderColor: '#dc3545',
                        borderWidth: 2,
                        borderRadius: 4,
                        barPercentage: 0.8
                    },
                    {
                        label: 'تحويل',
                        data: transfers,
                        backgroundColor: 'rgba(255, 193, 7, 0.7)',
                        borderColor: '#ffc107',
                        borderWidth: 2,
                        borderRadius: 4,
                        barPercentage: 0.8
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            color: 'rgba(255, 255, 255, 0.6)',
                            font: { family: 'Tajawal', size: 12 },
                            padding: 15,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.3)',
                            font: { family: 'Tajawal', size: 11 },
                            maxTicksLimit: 15
                        }
                    },
                    y: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.3)',
                            font: { family: 'Tajawal', size: 11 },
                            stepSize: 1
                        },
                        beginAtZero: true
                    }
                },
                animation: {
                    duration: 1000,
                    easing: 'easeOutQuart'
                }
            }
        });
    },
    
    /**
     * عرض رسم بياني لحالة المخزون (دائري)
     */
    renderStockStatusChart: function() {
        const ctx = document.getElementById('stockStatusChart');
        if (!ctx) return;
        
        // تدمير الرسم البياني السابق
        if (this.charts.stockStatus) {
            this.charts.stockStatus.destroy();
        }
        
        // الحصول على البيانات من الإحصائيات
        const stats = this.statsData || {};
        const stockStatus = stats.stock_status || {};
        
        const normal = stockStatus.normal || 0;
        const low = stockStatus.low_stock || 0;
        const out = stockStatus.out_of_stock || 0;
        const over = stockStatus.over_stock || 0;
        
        this.charts.stockStatus = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['طبيعي', 'منخفض', 'نفذ', 'زائد'],
                datasets: [{
                    data: [normal, low, out, over],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#17a2b8'],
                    borderWidth: 2,
                    borderColor: 'rgba(10, 14, 26, 0.8)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: 'rgba(255, 255, 255, 0.6)',
                            font: { family: 'Tajawal', size: 12 },
                            padding: 15,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }
                },
                cutout: '65%',
                animation: {
                    duration: 1000,
                    easing: 'easeOutQuart'
                }
            }
        });
    },
    
    // ================================================================
    // التنبيهات والنشاطات
    // ================================================================
    
    /**
     * تحميل التنبيهات
     */
    loadAlerts: function() {
        API.dashboard.alerts()
            .then(response => {
                if (response.success && response.data) {
                    // يتم عرضها من خلال updateStats
                }
            })
            .catch(error => {
                console.error('Error loading alerts:', error);
            });
    },
    
    /**
     * تحميل النشاطات
     */
    loadActivities: function() {
        API.dashboard.activities()
            .then(response => {
                if (response.success && response.data) {
                    // يتم عرضها من خلال updateStats
                }
            })
            .catch(error => {
                console.error('Error loading activities:', error);
            });
    },
    
    /**
     * تحميل حالة النظام
     */
    loadSystemStatus: function() {
        API.dashboard.status()
            .then(response => {
                if (response.success && response.data) {
                    this.updateSystemStatus(response.data);
                }
            })
            .catch(error => {
                console.error('Error loading system status:', error);
            });
    },
    
    /**
     * تحديث حالة النظام
     */
    updateSystemStatus: function(status) {
        // حالة الخادم
        const serverStatus = document.getElementById('serverStatus');
        const serverDot = document.getElementById('serverDot');
        if (serverStatus && serverDot) {
            const isOnline = status.server?.status === 'online';
            serverStatus.textContent = isOnline ? 'متصل' : 'غير متصل';
            serverDot.className = `status-dot ${isOnline ? 'online' : 'offline'}`;
        }
        
        // حالة قاعدة البيانات
        const dbStatus = document.getElementById('dbStatus');
        const dbDot = document.getElementById('dbDot');
        if (dbStatus && dbDot) {
            const isOnline = status.database?.status === 'online';
            dbStatus.textContent = isOnline ? 'متصل' : 'غير متصل';
            dbDot.className = `status-dot ${isOnline ? 'online' : 'offline'}`;
        }
        
        // إصدار النظام
        const versionEl = document.getElementById('systemVersion');
        if (versionEl) {
            versionEl.textContent = `v${status.system?.version || '5.0.0'}`;
        }
        
        // وقت التشغيل
        const uptimeEl = document.getElementById('uptime');
        if (uptimeEl) {
            uptimeEl.textContent = status.server?.uptime || 'جاري التحميل...';
        }
        
        // المستخدمين النشطين
        const usersEl = document.getElementById('activeUsers');
        if (usersEl) {
            const count = status.database?.connections || 0;
            usersEl.textContent = count;
        }
        
        // إجمالي الأصناف
        const productsEl = document.getElementById('totalProducts');
        if (productsEl) {
            productsEl.textContent = this.statsData?.products?.total || 0;
        }
    },
    
    // ================================================================
    // أدوات مساعدة
    // ================================================================
    
    /**
     * الحصول على أيقونة الأولوية
     */
    getPriorityIcon: function(priority) {
        const icons = {
            'critical': '🔴',
            'high': '🟠',
            'medium': '🟡',
            'low': '🔵'
        };
        return icons[priority] || '⚪';
    },
    
    /**
     * الحصول على فئة أيقونة الحركة
     */
    getMovementIconClass: function(type) {
        const classes = {
            'RECEIPT': 'green',
            'ISSUE': 'red',
            'TRANSFER_OUT': 'orange',
            'TRANSFER_IN': 'blue',
            'RETURN_IN': 'purple',
            'RETURN_OUT': 'orange',
            'ADJUSTMENT': 'secondary',
            'COUNT_CORRECTION': 'green'
        };
        return classes[type] || 'secondary';
    },
    
    /**
     * تحديث رقم مع تأثير عد تنازلي
     */
    animateNumber: function(el, target, duration = 1000) {
        const start = parseInt(el.textContent.replace(/,/g, '')) || 0;
        const diff = target - start;
        const startTime = performance.now();
        
        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const current = Math.round(start + diff * eased);
            el.textContent = current.toLocaleString();
            
            if (progress < 1) {
                requestAnimationFrame(update);
            }
        }
        
        requestAnimationFrame(update);
    },
    
    /**
     * تنظيف الموارد
     */
    destroy: function() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
        
        // تدمير الرسوم البيانية
        Object.values(this.charts).forEach(chart => {
            if (chart) chart.destroy();
        });
        this.charts = {};
    }
};

// ================================================================
// تصدير Dashboard
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = Dashboard;
}

// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/reports.js
// الوصف: إدارة التقارير المتقدمة - عرض وتصدير
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

/**
 * إدارة التقارير - جميع أنواع التقارير
 */
const Reports = {
    // ================================================================
    // الحالة
    // ================================================================
    
    currentData: null,
    currentType: 'stock',
    charts: {},
    table: null,
    
    // ================================================================
    // التحميل
    // ================================================================
    
    /**
     * تحميل صفحة التقارير
     */
    load: function() {
        this.loadStockReport();
        this.setupEvents();
        this.loadSelectData();
    },
    
    /**
     * إعداد الأحداث
     */
    setupEvents: function() {
        // زر إنشاء التقرير
        document.getElementById('reportGenerateBtn')?.addEventListener('click', () => {
            this.generateReport();
        });
        
        // زر التصدير
        document.getElementById('reportExportBtn')?.addEventListener('click', () => {
            this.exportReport();
        });
        
        // زر الطباعة
        document.getElementById('reportPrintBtn')?.addEventListener('click', () => {
            this.printReport();
        });
        
        // تغيير نوع التقرير
        document.getElementById('reportType')?.addEventListener('change', () => {
            this.onReportTypeChange();
        });
    },
    
    /**
     * تحميل بيانات القوائم
     */
    loadSelectData: function() {
        // تحميل المنتجات للتقارير
        API.products.list({ limit: 100 })
            .then(response => {
                if (response.success) {
                    this.populateProductSelect(response.data || []);
                }
            })
            .catch(error => console.error('Error loading products:', error));
        
        // تحميل المخازن
        API.warehouses.list()
            .then(response => {
                if (response.success) {
                    this.populateWarehouseSelect(response.data || []);
                }
            })
            .catch(error => console.error('Error loading warehouses:', error));
        
        // تحميل المستخدمين
        API.users.list({ limit: 100 })
            .then(response => {
                if (response.success) {
                    this.populateUserSelect(response.data || []);
                }
            })
            .catch(error => console.error('Error loading users:', error));
    },
    
    /**
     * تعبئة قائمة المنتجات
     */
    populateProductSelect: function(products) {
        const select = document.getElementById('reportProduct');
        if (!select) return;
        const currentValue = select.value;
        select.innerHTML = '<option value="">كل المنتجات</option>';
        products.forEach(p => {
            const option = document.createElement('option');
            option.value = p.id;
            option.textContent = `${p.code} - ${p.name}`;
            if (p.id == currentValue) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    },
    
    /**
     * تعبئة قائمة المخازن
     */
    populateWarehouseSelect: function(warehouses) {
        const selects = document.querySelectorAll('#reportWarehouse, #reportWarehouseFilter');
        selects.forEach(select => {
            if (!select) return;
            const currentValue = select.value;
            select.innerHTML = '<option value="">كل المخازن</option>';
            warehouses.filter(w => w.is_active).forEach(w => {
                const option = document.createElement('option');
                option.value = w.id;
                option.textContent = w.name;
                if (w.id == currentValue) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        });
    },
    
    /**
     * تعبئة قائمة المستخدمين
     */
    populateUserSelect: function(users) {
        const select = document.getElementById('reportUser');
        if (!select) return;
        const currentValue = select.value;
        select.innerHTML = '<option value="">كل المستخدمين</option>';
        users.forEach(u => {
            const option = document.createElement('option');
            option.value = u.id;
            option.textContent = u.full_name || u.username;
            if (u.id == currentValue) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    },
    
    // ================================================================
    // تغيير نوع التقرير
    // ================================================================
    
    /**
     * عند تغيير نوع التقرير
     */
    onReportTypeChange: function() {
        const type = document.getElementById('reportType')?.value || 'stock';
        this.currentType = type;
        
        // إظهار/إخفاء الفلاتر المناسبة
        document.querySelectorAll('.report-filters').forEach(el => {
            el.style.display = 'none';
        });
        
        const filterContainer = document.getElementById(`filters-${type}`);
        if (filterContainer) {
            filterContainer.style.display = 'block';
        }
        
        // توليد التقرير تلقائياً
        this.generateReport();
    },
    
    // ================================================================
    // توليد التقارير
    // ================================================================
    
    /**
     * توليد التقرير
     */
    generateReport: function() {
        const type = this.currentType;
        
        switch (type) {
            case 'stock':
                this.loadStockReport();
                break;
            case 'movements':
                this.loadMovementsReport();
                break;
            case 'product':
                this.loadProductReport();
                break;
            case 'warehouse':
                this.loadWarehouseReport();
                break;
            case 'audit':
                this.loadAuditReport();
                break;
            case 'summary':
                this.loadSummaryReport();
                break;
            case 'top-products':
                this.loadTopProductsReport();
                break;
            case 'inventory-value':
                this.loadInventoryValueReport();
                break;
            case 'users':
                this.loadUsersReport();
                break;
            default:
                this.loadStockReport();
        }
    },
    
    /**
     * تحميل تقرير الأرصدة
     */
    loadStockReport: function() {
        const params = {
            warehouse_id: document.getElementById('reportWarehouse')?.value || '',
            category_id: document.getElementById('reportCategory')?.value || '',
            status: document.getElementById('reportStatus')?.value || '',
            search: document.getElementById('reportSearch')?.value || ''
        };
        
        this.showLoading();
        
        API.reports.stock(params)
            .then(response => {
                if (response.success) {
                    this.currentData = response.data;
                    this.renderStockReport(response.data);
                }
            })
            .catch(error => {
                this.showError(error.message || 'حدث خطأ في تحميل التقرير');
            })
            .finally(() => {
                this.hideLoading();
            });
    },
    
    /**
     * تحميل تقرير الحركات
     */
    loadMovementsReport: function() {
        const params = {
            product_id: document.getElementById('reportProduct')?.value || '',
            warehouse_id: document.getElementById('reportWarehouse')?.value || '',
            from_date: document.getElementById('reportFromDate')?.value || '',
            to_date: document.getElementById('reportToDate')?.value || '',
            type: document.getElementById('reportMovementType')?.value || ''
        };
        
        this.showLoading();
        
        API.reports.movements(params)
            .then(response => {
                if (response.success) {
                    this.currentData = response.data;
                    this.renderMovementsReport(response.data);
                }
            })
            .catch(error => {
                this.showError(error.message || 'حدث خطأ في تحميل التقرير');
            })
            .finally(() => {
                this.hideLoading();
            });
    },
    
    /**
     * تحميل تقرير منتج
     */
    loadProductReport: function() {
        const productId = document.getElementById('reportProduct')?.value;
        if (!productId) {
            this.showError('يرجى اختيار منتج');
            return;
        }
        
        const params = {
            from_date: document.getElementById('reportFromDate')?.value || '',
            to_date: document.getElementById('reportToDate')?.value || ''
        };
        
        this.showLoading();
        
        API.reports.product(productId, params)
            .then(response => {
                if (response.success) {
                    this.currentData = response.data;
                    this.renderProductReport(response.data);
                }
            })
            .catch(error => {
                this.showError(error.message || 'حدث خطأ في تحميل التقرير');
            })
            .finally(() => {
                this.hideLoading();
            });
    },
    
    /**
     * تحميل تقرير مخزن
     */
    loadWarehouseReport: function() {
        const warehouseId = document.getElementById('reportWarehouse')?.value;
        if (!warehouseId) {
            this.showError('يرجى اختيار مخزن');
            return;
        }
        
        const params = {
            from_date: document.getElementById('reportFromDate')?.value || '',
            to_date: document.getElementById('reportToDate')?.value || ''
        };
        
        this.showLoading();
        
        API.reports.warehouse(warehouseId, params)
            .then(response => {
                if (response.success) {
                    this.currentData = response.data;
                    this.renderWarehouseReport(response.data);
                }
            })
            .catch(error => {
                this.showError(error.message || 'حدث خطأ في تحميل التقرير');
            })
            .finally(() => {
                this.hideLoading();
            });
    },
    
    /**
     * تحميل تقرير التدقيق
     */
    loadAuditReport: function() {
        const params = {
            user_id: document.getElementById('reportUser')?.value || '',
            action: document.getElementById('reportAction')?.value || '',
            module: document.getElementById('reportModule')?.value || '',
            from_date: document.getElementById('reportFromDate')?.value || '',
            to_date: document.getElementById('reportToDate')?.value || '',
            limit: document.getElementById('reportLimit')?.value || 100
        };
        
        this.showLoading();
        
        API.reports.audit(params)
            .then(response => {
                if (response.success) {
                    this.currentData = response.data;
                    this.renderAuditReport(response.data);
                }
            })
            .catch(error => {
                this.showError(error.message || 'حدث خطأ في تحميل التقرير');
            })
            .finally(() => {
                this.hideLoading();
            });
    },
    
    /**
     * تحميل تقرير الملخص
     */
    loadSummaryReport: function() {
        this.showLoading();
        
        API.reports.summary()
            .then(response => {
                if (response.success) {
                    this.currentData = response.data;
                    this.renderSummaryReport(response.data);
                }
            })
            .catch(error => {
                this.showError(error.message || 'حدث خطأ في تحميل التقرير');
            })
            .finally(() => {
                this.hideLoading();
            });
    },
    
    /**
     * تحميل تقرير الأصناف الأكثر تداولاً
     */
    loadTopProductsReport: function() {
        const params = {
            period: document.getElementById('reportPeriod')?.value || 30,
            limit: document.getElementById('reportLimit')?.value || 10,
            warehouse_id: document.getElementById('reportWarehouse')?.value || ''
        };
        
        this.showLoading();
        
        API.reports.topProducts(params)
            .then(response => {
                if (response.success) {
                    this.currentData = response.data;
                    this.renderTopProductsReport(response.data);
                }
            })
            .catch(error => {
                this.showError(error.message || 'حدث خطأ في تحميل التقرير');
            })
            .finally(() => {
                this.hideLoading();
            });
    },
    
    /**
     * تحميل تقرير قيمة المخزون
     */
    loadInventoryValueReport: function() {
        this.showLoading();
        
        API.reports.inventoryValue()
            .then(response => {
                if (response.success) {
                    this.currentData = response.data;
                    this.renderInventoryValueReport(response.data);
                }
            })
            .catch(error => {
                this.showError(error.message || 'حدث خطأ في تحميل التقرير');
            })
            .finally(() => {
                this.hideLoading();
            });
    },
    
    /**
     * تحميل تقرير المستخدمين
     */
    loadUsersReport: function() {
        const params = {
            period: document.getElementById('reportPeriod')?.value || 30,
            limit: document.getElementById('reportLimit')?.value || 20
        };
        
        this.showLoading();
        
        API.reports.users(params)
            .then(response => {
                if (response.success) {
                    this.currentData = response.data;
                    this.renderUsersReport(response.data);
                }
            })
            .catch(error => {
                this.showError(error.message || 'حدث خطأ في تحميل التقرير');
            })
            .finally(() => {
                this.hideLoading();
            });
    },
    
    // ================================================================
    // عرض التقارير
    // ================================================================
    
    /**
     * عرض تقرير الأرصدة
     */
    renderStockReport: function(data) {
        const container = document.getElementById('reportContainer');
        if (!container) return;
        
        const items = data.data || [];
        const stats = data.stats || {};
        
        if (items.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-5">
                    <i class="fas fa-file-alt fa-3x mb-3" style="opacity:0.3;"></i>
                    <h4 style="color:rgba(255,255,255,0.3);">لا توجد بيانات</h4>
                    <p style="color:rgba(255,255,255,0.15);">لم يتم العثور على بيانات تطابق معايير البحث</p>
                </div>
            `;
            return;
        }
        
        let html = `
            <div class="report-summary row mb-4">
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${App.formatNumber(stats.total_products || 0)}</h5>
                            <small class="text-muted">إجمالي الأصناف</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${App.formatNumber(stats.total_quantity || 0)}</h5>
                            <small class="text-muted">الكمية الإجمالية</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${App.formatCurrency(stats.total_value || 0)}</h5>
                            <small class="text-muted">القيمة الإجمالية</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${stats.out_of_stock || 0}</h5>
                            <small class="text-muted">أصناف نفذت</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="reportTable">
                    <thead>
                        <tr>
                            <th>الكود</th>
                            <th>الاسم</th>
                            <th>التصنيف</th>
                            <th>المخزن</th>
                            <th>الكمية</th>
                            <th>المتاح</th>
                            <th>القيمة</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${items.map(item => `
                            <tr>
                                <td><span style="color:rgba(255,255,255,0.4);font-size:12px;">${item.code || '-'}</span></td>
                                <td>${item.name || '-'}</td>
                                <td>${item.category || '-'}</td>
                                <td>${item.warehouse || '-'}</td>
                                <td>${App.formatNumber(item.balance)}</td>
                                <td>${App.formatNumber(item.available)}</td>
                                <td>${App.formatCurrency(item.total_value)}</td>
                                <td>${App.getStatusBadge(item.stock_status)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        
        container.innerHTML = html;
        this.initTable();
    },
    
    /**
     * عرض تقرير الحركات
     */
    renderMovementsReport: function(data) {
        const container = document.getElementById('reportContainer');
        if (!container) return;
        
        const items = data.data || [];
        const stats = data.stats || {};
        
        if (items.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-5">
                    <i class="fas fa-file-alt fa-3x mb-3" style="opacity:0.3;"></i>
                    <h4 style="color:rgba(255,255,255,0.3);">لا توجد حركات</h4>
                    <p style="color:rgba(255,255,255,0.15);">لم يتم العثور على حركات تطابق معايير البحث</p>
                </div>
            `;
            return;
        }
        
        let html = `
            <div class="report-summary row mb-4">
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${stats.total_movements || 0}</h5>
                            <small class="text-muted">إجمالي الحركات</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${App.formatNumber(stats.total_in || 0)}</h5>
                            <small class="text-muted">إجمالي الوارد</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${App.formatNumber(stats.total_out || 0)}</h5>
                            <small class="text-muted">إجمالي المنصرف</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${App.formatCurrency(stats.total_value || 0)}</h5>
                            <small class="text-muted">القيمة الإجمالية</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="reportTable">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>النوع</th>
                            <th>المنتج</th>
                            <th>المخزن</th>
                            <th>الكمية</th>
                            <th>سعر الوحدة</th>
                            <th>القيمة</th>
                            <th>المستخدم</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${items.map(item => `
                            <tr>
                                <td>${App.formatDate(item.movement_date)}</td>
                                <td>${App.getMovementLabel(item.movement_type)}</td>
                                <td>${item.product_name || '-'}</td>
                                <td>${item.warehouse || '-'}</td>
                                <td>${App.formatNumber(item.quantity)}</td>
                                <td>${App.formatCurrency(item.unit_cost)}</td>
                                <td>${App.formatCurrency(item.total_cost)}</td>
                                <td>${item.user_name || '-'}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        
        container.innerHTML = html;
        this.initTable();
    },
    
    /**
     * عرض تقرير المنتج
     */
    renderProductReport: function(data) {
        const container = document.getElementById('reportContainer');
        if (!container) return;
        
        const product = data.product || {};
        const movements = data.movements || [];
        const balances = data.balances || [];
        const stats = data.stats || {};
        
        let balanceHtml = '';
        if (balances.length > 0) {
            balanceHtml = balances.map(b => `
                <tr>
                    <td>${b.warehouse_name}</td>
                    <td>${App.formatNumber(b.quantity)}</td>
                    <td>${App.formatNumber(b.reserved)}</td>
                    <td>${App.formatNumber(b.available)}</td>
                    <td>${App.formatCurrency(b.total_value)}</td>
                </tr>
            `).join('');
        } else {
            balanceHtml = '<tr><td colspan="5" class="text-center text-muted">لا توجد أرصدة</td></tr>';
        }
        
        let movementHtml = '';
        if (movements.length > 0) {
            movementHtml = movements.map(m => `
                <tr>
                    <td>${App.formatDate(m.movement_date)}</td>
                    <td>${App.getMovementLabel(m.movement_type)}</td>
                    <td>${m.warehouse_name}</td>
                    <td>${App.formatNumber(m.quantity)}</td>
                    <td>${App.formatCurrency(m.unit_cost)}</td>
                    <td>${App.formatCurrency(m.balance_after)}</td>
                    <td>${m.user_name}</td>
                </tr>
            `).join('');
        } else {
            movementHtml = '<tr><td colspan="7" class="text-center text-muted">لا توجد حركات</td></tr>';
        }
        
        container.innerHTML = `
            <div class="report-summary mb-4">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>المنتج:</strong> ${product.name}</p>
                                <p><strong>الكود:</strong> ${product.code || '-'}</p>
                                <p><strong>الباركود:</strong> ${product.barcode || '-'}</p>
                                <p><strong>التصنيف:</strong> ${product.category_name || '-'}</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>الوحدة:</strong> ${product.unit_name || '-'}</p>
                                <p><strong>الكمية الإجمالية:</strong> ${App.formatNumber(product.total_quantity || 0)}</p>
                                <p><strong>القيمة الإجمالية:</strong> ${App.formatCurrency(product.total_value || 0)}</p>
                                <p><strong>إجمالي الحركات:</strong> ${stats.total_movements || 0}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <h6 class="mb-2">الأرصدة في المخازن</h6>
                    <div class="table-responsive" style="max-height:200px;overflow-y:auto;">
                        <table class="table table-sm">
                            <thead><tr><th>المخزن</th><th>الكمية</th><th>محجوز</th><th>متاح</th><th>القيمة</th></tr></thead>
                            <tbody>${balanceHtml}</tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="mb-2">الحركات</h6>
                    <div class="table-responsive" style="max-height:200px;overflow-y:auto;">
                        <table class="table table-sm">
                            <thead><tr><th>التاريخ</th><th>النوع</th><th>المخزن</th><th>الكمية</th><th>القيمة</th></tr></thead>
                            <tbody>${movementHtml}</tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    },
    
    /**
     * عرض تقرير المخزن
     */
    renderWarehouseReport: function(data) {
        const container = document.getElementById('reportContainer');
        if (!container) return;
        
        const warehouse = data.warehouse || {};
        const stats = data.stats || {};
        const dailyMovements = data.daily_movements || [];
        const topProducts = data.top_products || [];
        
        let dailyHtml = '';
        if (dailyMovements.length > 0) {
            dailyHtml = dailyMovements.map(d => `
                <tr>
                    <td>${d.date}</td>
                    <td>${d.total_movements || 0}</td>
                    <td>${d.receipts || 0}</td>
                    <td>${d.issues || 0}</td>
                    <td>${d.transfers_in || 0}</td>
                    <td>${d.transfers_out || 0}</td>
                    <td>${d.adjustments || 0}</td>
                </tr>
            `).join('');
        } else {
            dailyHtml = '<tr><td colspan="7" class="text-center text-muted">لا توجد حركات يومية</td></tr>';
        }
        
        let topHtml = '';
        if (topProducts.length > 0) {
            topHtml = topProducts.map(p => `
                <tr>
                    <td>${p.code || '-'}</td>
                    <td>${p.name}</td>
                    <td>${p.movement_count || 0}</td>
                    <td>${App.formatNumber(p.total_quantity || 0)}</td>
                    <td>${App.formatCurrency(p.total_value || 0)}</td>
                </tr>
            `).join('');
        } else {
            topHtml = '<tr><td colspan="5" class="text-center text-muted">لا توجد منتجات</td></tr>';
        }
        
        container.innerHTML = `
            <div class="report-summary mb-4">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>المخزن:</strong> ${warehouse.name}</p>
                                <p><strong>الكود:</strong> ${warehouse.code || '-'}</p>
                                <p><strong>النوع:</strong> ${warehouse.type || '---'}</p>
                                <p><strong>الموقع:</strong> ${warehouse.location || '---'}</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>إجمالي الأصناف:</strong> ${stats.total_products || 0}</p>
                                <p><strong>الكمية الإجمالية:</strong> ${App.formatNumber(stats.total_quantity || 0)}</p>
                                <p><strong>القيمة الإجمالية:</strong> ${App.formatCurrency(stats.total_value || 0)}</p>
                                <p><strong>منخفض/نفذ:</strong> ${(stats.low_stock || 0) + (stats.out_of_stock || 0)}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-8">
                    <h6 class="mb-2">الحركات اليومية</h6>
                    <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                        <table class="table table-sm">
                            <thead><tr><th>التاريخ</th><th>الإجمالي</th><th>استلام</th><th>صرف</th><th>تحويل داخل</th><th>تحويل خارج</th><th>تسوية</th></tr></thead>
                            <tbody>${dailyHtml}</tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-4">
                    <h6 class="mb-2">أكثر الأصناف تداولاً</h6>
                    <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                        <table class="table table-sm">
                            <thead><tr><th>الكود</th><th>الاسم</th><th>الحركات</th><th>الكمية</th><th>القيمة</th></tr></thead>
                            <tbody>${topHtml}</tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    },
    
    /**
     * عرض تقرير التدقيق
     */
    renderAuditReport: function(data) {
        const container = document.getElementById('reportContainer');
        if (!container) return;
        
        const items = data.data || [];
        const stats = data.stats || {};
        
        if (items.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-5">
                    <i class="fas fa-file-alt fa-3x mb-3" style="opacity:0.3;"></i>
                    <h4 style="color:rgba(255,255,255,0.3);">لا توجد سجلات تدقيق</h4>
                    <p style="color:rgba(255,255,255,0.15);">لم يتم العثور على سجلات تطابق معايير البحث</p>
                </div>
            `;
            return;
        }
        
        let html = `
            <div class="report-summary row mb-4">
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${stats.total_entries || 0}</h5>
                            <small class="text-muted">إجمالي السجلات</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${stats.unique_users || 0}</h5>
                            <small class="text-muted">مستخدمين</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${stats.unique_actions || 0}</h5>
                            <small class="text-muted">إجراءات</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${stats.unique_modules || 0}</h5>
                            <small class="text-muted">وحدات</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="reportTable">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>المستخدم</th>
                            <th>الإجراء</th>
                            <th>الوحدة</th>
                            <th>الوصف</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${items.map(item => `
                            <tr>
                                <td>${App.formatDate(item.created_at)}</td>
                                <td>${item.user_full_name || item.username || '-'}</td>
                                <td><span class="badge bg-${item.action_type || 'secondary'}">${item.action_label || item.action}</span></td>
                                <td>${item.module || '-'}</td>
                                <td>${item.description || '-'}</td>
                                <td><span style="font-size:11px;color:rgba(255,255,255,0.3);">${item.ip_address || '-'}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        
        container.innerHTML = html;
        this.initTable();
    },
    
    /**
     * عرض تقرير الملخص
     */
    renderSummaryReport: function(data) {
        const container = document.getElementById('reportContainer');
        if (!container) return;
        
        const general = data.general || {};
        const today = data.today || {};
        const week = data.week || {};
        const topProducts = data.top_products || [];
        const topUsers = data.top_users || [];
        const stockStatus = data.stock_status || {};
        
        let topProductHtml = '';
        if (topProducts.length > 0) {
            topProductHtml = topProducts.map(p => `
                <div class="activity-item">
                    <div class="icon blue"><i class="fas fa-box"></i></div>
                    <div class="content">
                        <div class="text"><strong>${p.name}</strong> (${p.code})</div>
                        <div class="time">${p.movement_count || 0} حركة · ${App.formatNumber(p.total_quantity || 0)} قطعة</div>
                    </div>
                </div>
            `).join('');
        } else {
            topProductHtml = '<div class="text-center text-muted py-2">لا توجد بيانات</div>';
        }
        
        let topUserHtml = '';
        if (topUsers.length > 0) {
            topUserHtml = topUsers.map(u => `
                <div class="activity-item">
                    <div class="icon purple"><i class="fas fa-user"></i></div>
                    <div class="content">
                        <div class="text"><strong>${u.full_name}</strong></div>
                        <div class="time">${u.actions || 0} عملية · ${u.active_days || 0} يوم</div>
                    </div>
                </div>
            `).join('');
        } else {
            topUserHtml = '<div class="text-center text-muted py-2">لا توجد بيانات</div>';
        }
        
        container.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><h5><i class="fas fa-chart-pie"></i> إحصائيات عامة</h5></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6"><p><strong>الأصناف:</strong> ${general.total_products || 0}</p></div>
                                <div class="col-6"><p><strong>المخازن:</strong> ${general.total_warehouses || 0}</p></div>
                                <div class="col-6"><p><strong>المستخدمين:</strong> ${general.total_users || 0}</p></div>
                                <div class="col-6"><p><strong>الموردين:</strong> ${general.total_suppliers || 0}</p></div>
                                <div class="col-6"><p><strong>الكمية الإجمالية:</strong> ${App.formatNumber(general.total_quantity || 0)}</p></div>
                                <div class="col-6"><p><strong>القيمة الإجمالية:</strong> ${App.formatCurrency(general.total_value || 0)}</p></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><h5><i class="fas fa-chart-bar"></i> حركات اليوم</h5></div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6"><p><strong>الإجمالي:</strong> ${today.total || 0}</p></div>
                                <div class="col-6"><p><strong>استلام:</strong> ${today.receipts || 0}</p></div>
                                <div class="col-6"><p><strong>صرف:</strong> ${today.issues || 0}</p></div>
                                <div class="col-6"><p><strong>تحويل:</strong> ${today.transfers || 0}</p></div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-6"><p><strong>حركات الأسبوع:</strong> ${week.total || 0}</p></div>
                                <div class="col-6"><p><strong>متوسط اليوم:</strong> ${Math.round((week.total || 0) / 7)}</p></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><h5><i class="fas fa-crown"></i> أكثر الأصناف تداولاً</h5></div>
                        <div class="card-body" style="max-height:250px;overflow-y:auto;">
                            ${topProductHtml}
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><h5><i class="fas fa-users-cog"></i> أكثر المستخدمين نشاطاً</h5></div>
                        <div class="card-body" style="max-height:250px;overflow-y:auto;">
                            ${topUserHtml}
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header"><h5><i class="fas fa-tasks"></i> حالة المخزون</h5></div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-3">
                                    <div style="font-size:24px;font-weight:700;color:#28a745;">${stockStatus.normal || 0}</div>
                                    <small class="text-muted">طبيعي</small>
                                </div>
                                <div class="col-3">
                                    <div style="font-size:24px;font-weight:700;color:#ffc107;">${stockStatus.low_stock || 0}</div>
                                    <small class="text-muted">منخفض</small>
                                </div>
                                <div class="col-3">
                                    <div style="font-size:24px;font-weight:700;color:#dc3545;">${stockStatus.out_of_stock || 0}</div>
                                    <small class="text-muted">نفذ</small>
                                </div>
                                <div class="col-3">
                                    <div style="font-size:24px;font-weight:700;color:#17a2b8;">${stockStatus.over_stock || 0}</div>
                                    <small class="text-muted">زائد</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },
    
    /**
     * عرض تقرير الأصناف الأكثر تداولاً
     */
    renderTopProductsReport: function(data) {
        const container = document.getElementById('reportContainer');
        if (!container) return;
        
        const items = data.data || [];
        const meta = data.meta || {};
        
        if (items.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-5">
                    <i class="fas fa-file-alt fa-3x mb-3" style="opacity:0.3;"></i>
                    <h4 style="color:rgba(255,255,255,0.3);">لا توجد بيانات</h4>
                </div>
            `;
            return;
        }
        
        let html = `
            <div class="report-summary mb-4">
                <div class="card">
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <p><strong>الفترة:</strong> ${meta.period || '30 يوم'}</p>
                            </div>
                            <div class="col-md-4">
                                <p><strong>عدد المنتجات:</strong> ${items.length}</p>
                            </div>
                            <div class="col-md-4">
                                <p><strong>إجمالي الحركات:</strong> ${items.reduce((s, i) => s + (i.movement_count || 0), 0)}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="reportTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الكود</th>
                            <th>الاسم</th>
                            <th>التصنيف</th>
                            <th>عدد الحركات</th>
                            <th>الكمية</th>
                            <th>القيمة</th>
                            <th>المخازن</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${items.map((item, index) => `
                            <tr>
                                <td>${index + 1}</td>
                                <td>${item.code || '-'}</td>
                                <td>${item.name || '-'}</td>
                                <td>${item.category || '-'}</td>
                                <td><span class="badge bg-primary">${item.movement_count || 0}</span></td>
                                <td>${App.formatNumber(item.total_quantity || 0)}</td>
                                <td>${App.formatCurrency(item.total_value || 0)}</td>
                                <td>${item.warehouses_count || 0}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        
        container.innerHTML = html;
        this.initTable();
    },
    
    /**
     * عرض تقرير قيمة المخزون
     */
    renderInventoryValueReport: function(data) {
        const container = document.getElementById('reportContainer');
        if (!container) return;
        
        const total = data.total || {};
        const byWarehouse = data.by_warehouse || [];
        const byCategory = data.by_category || [];
        
        let whHtml = '';
        if (byWarehouse.length > 0) {
            whHtml = byWarehouse.map(w => `
                <tr>
                    <td>${w.name}</td>
                    <td>${w.products_count || 0}</td>
                    <td>${App.formatNumber(w.total_quantity || 0)}</td>
                    <td>${App.formatCurrency(w.total_value || 0)}</td>
                    <td>${w.percentage || 0}%</td>
                </tr>
            `).join('');
        } else {
            whHtml = '<tr><td colspan="5" class="text-center text-muted">لا توجد بيانات</td></tr>';
        }
        
        let catHtml = '';
        if (byCategory.length > 0) {
            catHtml = byCategory.map(c => `
                <tr>
                    <td>${c.name}</td>
                    <td>${c.products_count || 0}</td>
                    <td>${App.formatNumber(c.total_quantity || 0)}</td>
                    <td>${App.formatCurrency(c.total_value || 0)}</td>
                    <td>${c.percentage || 0}%</td>
                </tr>
            `).join('');
        } else {
            catHtml = '<tr><td colspan="5" class="text-center text-muted">لا توجد بيانات</td></tr>';
        }
        
        container.innerHTML = `
            <div class="report-summary row mb-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${App.formatNumber(total.total_quantity || 0)}</h5>
                            <small class="text-muted">الكمية الإجمالية</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${App.formatCurrency(total.total_cost || 0)}</h5>
                            <small class="text-muted">تكلفة الشراء</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <h5>${App.formatCurrency(total.potential_profit || 0)}</h5>
                            <small class="text-muted">الربح المحتمل</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><h5><i class="fas fa-warehouse"></i> حسب المخزن</h5></div>
                        <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                            <table class="table table-sm">
                                <thead><tr><th>المخزن</th><th>الأصناف</th><th>الكمية</th><th>القيمة</th><th>النسبة</th></tr></thead>
                                <tbody>${whHtml}</tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header"><h5><i class="fas fa-tags"></i> حسب التصنيف</h5></div>
                        <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                            <table class="table table-sm">
                                <thead><tr><th>التصنيف</th><th>الأصناف</th><th>الكمية</th><th>القيمة</th><th>النسبة</th></tr></thead>
                                <tbody>${catHtml}</tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },
    
    /**
     * عرض تقرير المستخدمين
     */
    renderUsersReport: function(data) {
        const container = document.getElementById('reportContainer');
        if (!container) return;
        
        const items = data.data || [];
        const meta = data.meta || {};
        
        if (items.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-5">
                    <i class="fas fa-file-alt fa-3x mb-3" style="opacity:0.3;"></i>
                    <h4 style="color:rgba(255,255,255,0.3);">لا توجد بيانات</h4>
                </div>
            `;
            return;
        }
        
        container.innerHTML = `
            <div class="report-summary mb-4">
                <div class="card">
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <p><strong>الفترة:</strong> ${meta.period || '30 يوم'}</p>
                            </div>
                            <div class="col-md-4">
                                <p><strong>عدد المستخدمين:</strong> ${items.length}</p>
                            </div>
                            <div class="col-md-4">
                                <p><strong>إجمالي العمليات:</strong> ${items.reduce((s, i) => s + (i.total_actions || 0), 0)}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="reportTable">
                    <thead>
                        <tr>
                            <th>المستخدم</th>
                            <th>الدور</th>
                            <th>العمليات</th>
                            <th>أيام النشاط</th>
                            <th>الوحدات</th>
                            <th>آخر نشاط</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${items.map(item => `
                            <tr>
                                <td><strong>${item.full_name}</strong><br><span style="font-size:11px;color:rgba(255,255,255,0.3);">${item.username}</span></td>
                                <td>${item.role_name || '---'}</td>
                                <td><span class="badge bg-primary">${item.total_actions || 0}</span></td>
                                <td>${item.active_days || 0}</td>
                                <td>${item.modules_used || 0}</td>
                                <td>${item.last_action ? App.formatDate(item.last_action) : '---'}</td>
                                <td>${item.is_active ? '<span class="badge bg-success">نشط</span>' : '<span class="badge bg-secondary">غير نشط</span>'}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        
        this.initTable();
    },
    
    // ================================================================
    // أدوات مساعدة
    // ================================================================
    
    /**
     * تهيئة الجدول
     */
    initTable: function() {
        if (this.table) {
            this.table.destroy();
        }
        
        const table = document.getElementById('reportTable');
        if (!table) return;
        
        this.table = $(table).DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/ar.json'
            },
            responsive: true,
            pageLength: 20,
            lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
            order: [[0, 'desc']],
            scrollX: true
        });
    },
    
    /**
     * إظهار التحميل
     */
    showLoading: function() {
        const container = document.getElementById('reportContainer');
        if (container) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                    <p class="mt-3 text-muted">جاري تحميل التقرير...</p>
                </div>
            `;
        }
    },
    
    /**
     * إخفاء التحميل
     */
    hideLoading: function() {
        // يتم التعامل معه في دوال العرض
    },
    
    /**
     * عرض خطأ
     */
    showError: function(message) {
        const container = document.getElementById('reportContainer');
        if (container) {
            container.innerHTML = `
                <div class="text-center text-muted py-5">
                    <i class="fas fa-exclamation-circle fa-3x mb-3 text-danger"></i>
                    <h4 style="color:#dc3545;">حدث خطأ</h4>
                    <p>${message}</p>
                    <button class="btn btn-primary btn-sm mt-2" onclick="Reports.generateReport()">
                        <i class="fas fa-sync me-1"></i> إعادة المحاولة
                    </button>
                </div>
            `;
        }
    },
    
    // ================================================================
    // تصدير وطباعة
    // ================================================================
    
    /**
     * تصدير التقرير
     */
    exportReport: function() {
        if (!this.currentData) {
            App.showToast('لا توجد بيانات للتصدير', 'warning');
            return;
        }
        
        const format = document.getElementById('exportFormat')?.value || 'csv';
        const type = this.currentType;
        const data = this.currentData.data || this.currentData;
        
        if (!data || data.length === 0) {
            App.showToast('لا توجد بيانات للتصدير', 'warning');
            return;
        }
        
        const filename = `${type}_report_${new Date().toISOString().slice(0,10)}`;
        
        if (format === 'csv') {
            App.exportCSV(data, `${filename}.csv`);
        } else if (format === 'excel') {
            App.exportExcel(data, `${filename}.xls`);
        } else {
            App.showToast('صيغة غير مدعومة', 'error');
        }
    },
    
    /**
     * طباعة التقرير
     */
    printReport: function() {
        const container = document.getElementById('reportContainer');
        if (!container || !container.innerHTML.trim()) {
            App.showToast('لا توجد بيانات للطباعة', 'warning');
            return;
        }
        
        App.printElement('reportContainer');
    }
};

// ================================================================
// دوال عامة للاستخدام من HTML
// ================================================================

function generateReport() {
    Reports.generateReport();
}

function exportReport() {
    Reports.exportReport();
}

function printReport() {
    Reports.printReport();
}

// ================================================================
// تصدير Reports
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = Reports;
}

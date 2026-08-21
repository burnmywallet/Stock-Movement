// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/warehouses.js
// الوصف: إدارة المخازن - CRUD كامل مع هيكل شجري
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

/**
 * إدارة المخازن - جميع العمليات
 */
const Warehouses = {
    // ================================================================
    // الحالة
    // ================================================================
    
    data: [],
    viewMode: 'list', // list, cards, tree, hierarchical
    currentId: null,
    
    // ================================================================
    // التحميل
    // ================================================================
    
    /**
     * تحميل صفحة المخازن
     */
    load: function() {
        this.loadList();
        this.setupEvents();
        this.loadViewMode();
    },
    
    /**
     * تحميل قائمة المخازن
     */
    loadList: function() {
        const container = document.getElementById('warehousesContainer');
        if (!container) return;
        
        container.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">جاري التحميل...</span>
                </div>
                <p class="mt-2 text-muted">جاري تحميل المخازن...</p>
            </div>
        `;
        
        const params = { view: this.viewMode };
        
        API.warehouses.list(params)
            .then(response => {
                if (response.success && response.data) {
                    this.data = response.data;
                    this.renderList(response);
                }
            })
            .catch(error => {
                console.error('Error loading warehouses:', error);
                container.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-exclamation-circle fa-2x mb-2 text-danger"></i>
                        <p>حدث خطأ في تحميل المخازن</p>
                        <button class="btn btn-primary btn-sm mt-2" onclick="Warehouses.loadList()">
                            <i class="fas fa-sync me-1"></i> إعادة المحاولة
                        </button>
                    </div>
                `;
            });
    },
    
    /**
     * عرض قائمة المخازن
     */
    renderList: function(response) {
        const container = document.getElementById('warehousesContainer');
        if (!container) return;
        
        const warehouses = response.data || [];
        const stats = response.stats || {};
        const tree = response.tree || [];
        const cards = response.cards || [];
        
        // تحديث الإحصائيات
        this.updateStats(stats);
        
        if (warehouses.length === 0) {
            container.innerHTML = `
                <div class="col-12">
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-warehouse fa-3x mb-3" style="opacity:0.3;"></i>
                        <h3 style="color:rgba(255,255,255,0.3);">لا توجد مخازن</h3>
                        <p style="color:rgba(255,255,255,0.15);">قم بإضافة مخزن جديد باستخدام الزر أعلاه</p>
                        <button class="btn btn-primary mt-2" onclick="showCreateWarehouse()">
                            <i class="fas fa-plus me-1"></i> إضافة مخزن
                        </button>
                    </div>
                </div>
            `;
            return;
        }
        
        if (this.viewMode === 'tree' || this.viewMode === 'hierarchical') {
            this.renderTreeView(container, tree || this.buildTree(warehouses));
        } else if (this.viewMode === 'cards') {
            this.renderCardsView(container, cards || warehouses);
        } else {
            this.renderListView(container, warehouses);
        }
    },
    
    /**
     * عرض القائمة
     */
    renderListView: function(container, warehouses) {
        let html = '';
        warehouses.forEach(w => {
            const subs = this.data.filter(s => s.parent_id === w.id);
            const typeLabels = { main: 'رئيسي', sub: 'فرعي', store: 'متجر', virtual: 'افتراضي' };
            const typeColors = { main: 'main', sub: 'sub', store: 'store', virtual: 'store' };
            
            html += `
                <div class="col-xl-4 col-lg-6 col-md-6 col-sm-12">
                    <div class="card warehouse-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="card-title">
                                        ${w.is_main ? '<i class="fas fa-star text-warning me-1"></i>' : ''}
                                        ${w.name}
                                    </h5>
                                    <p class="card-subtitle text-muted small">
                                        <i class="fas fa-code me-1"></i> ${w.code || '-'}
                                        ${w.location ? `· <i class="fas fa-location-dot me-1"></i>${w.location}` : ''}
                                    </p>
                                </div>
                                <div>
                                    <span class="badge ${w.is_active ? 'bg-success' : 'bg-secondary'} me-1">
                                        ${w.is_active ? 'نشط' : 'غير نشط'}
                                    </span>
                                    <span class="badge ${typeColors[w.type] || 'main'}">
                                        ${typeLabels[w.type] || w.type}
                                    </span>
                                </div>
                            </div>
                            
                            ${w.manager_name ? `
                                <p class="mt-2 mb-1">
                                    <i class="fas fa-user me-1"></i>
                                    <strong>المدير:</strong> ${w.manager_name}
                                </p>
                            ` : ''}
                            
                            <div class="row mt-3">
                                <div class="col-4">
                                    <div class="text-center">
                                        <h6 class="mb-0">${App.formatNumber(w.products_count || 0)}</h6>
                                        <small class="text-muted">أصناف</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="text-center">
                                        <h6 class="mb-0">${App.formatNumber(w.total_quantity || 0)}</h6>
                                        <small class="text-muted">الكمية</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="text-center">
                                        <h6 class="mb-0">${App.formatCurrency(w.total_value || 0)}</h6>
                                        <small class="text-muted">القيمة</small>
                                    </div>
                                </div>
                            </div>
                            
                            ${subs.length > 0 ? `
                                <div class="mt-2" style="font-size:12px;color:rgba(255,255,255,0.3);">
                                    <i class="fas fa-sitemap me-1"></i>
                                    ${subs.length} مخزن فرعي
                                </div>
                            ` : ''}
                            
                            <div class="mt-3">
                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn btn-sm btn-primary flex-fill" onclick="Warehouses.viewStock(${w.id})">
                                        <i class="fas fa-boxes me-1"></i> المخزون
                                    </button>
                                    <button class="btn btn-sm btn-info" onclick="Warehouses.view(${w.id})">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="Warehouses.edit(${w.id})">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    ${!w.is_main ? `
                                        <button class="btn btn-sm btn-danger" onclick="Warehouses.deleteWarehouse(${w.id})">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        // إضافة زر إضافة مخزن
        html += `
            <div class="col-xl-4 col-lg-6 col-md-6 col-sm-12">
                <div class="card warehouse-card h-100 border-dashed" 
                     style="border: 2px dashed rgba(255,255,255,0.1); cursor: pointer;"
                     onclick="showCreateWarehouse()">
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div class="text-center">
                            <i class="fas fa-plus-circle fa-3x text-primary mb-2" style="opacity:0.5;"></i>
                            <h6 style="color:rgba(255,255,255,0.3);">إضافة مخزن جديد</h6>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        container.innerHTML = `<div class="row g-3">${html}</div>`;
    },
    
    /**
     * عرض البطاقات
     */
    renderCardsView: function(container, cards) {
        let html = '';
        cards.forEach(w => {
            const typeLabels = { main: 'رئيسي', sub: 'فرعي', store: 'متجر', virtual: 'افتراضي' };
            const typeColors = { main: 'primary', sub: 'success', store: 'warning', virtual: 'info' };
            
            html += `
                <div class="col-xl-3 col-lg-4 col-md-6 col-sm-12">
                    <div class="card warehouse-card h-100">
                        <div class="card-body text-center">
                            <div class="mb-2">
                                <i class="fas fa-warehouse fa-2x" style="color:${w.is_main ? '#ffc107' : '#667eea'};"></i>
                            </div>
                            <h5 class="card-title">${w.name}</h5>
                            <div class="d-flex justify-content-center gap-1 mb-2">
                                <span class="badge ${w.is_active ? 'bg-success' : 'bg-secondary'}">
                                    ${w.is_active ? 'نشط' : 'غير نشط'}
                                </span>
                                <span class="badge bg-${typeColors[w.type] || 'primary'}">
                                    ${typeLabels[w.type] || w.type}
                                </span>
                            </div>
                            <div class="row mt-2">
                                <div class="col-4">
                                    <div style="font-size:18px;font-weight:700;color:#fff;">${w.products_count || 0}</div>
                                    <small class="text-muted">أصناف</small>
                                </div>
                                <div class="col-4">
                                    <div style="font-size:18px;font-weight:700;color:#fff;">${App.formatNumber(w.total_quantity || 0)}</div>
                                    <small class="text-muted">الكمية</small>
                                </div>
                                <div class="col-4">
                                    <div style="font-size:18px;font-weight:700;color:#28a745;">${App.formatCurrency(w.total_value || 0)}</div>
                                    <small class="text-muted">القيمة</small>
                                </div>
                            </div>
                            <div class="mt-3">
                                <div class="d-flex gap-1 justify-content-center flex-wrap">
                                    <button class="btn btn-sm btn-primary" onclick="Warehouses.viewStock(${w.id})">
                                        <i class="fas fa-boxes"></i>
                                    </button>
                                    <button class="btn btn-sm btn-info" onclick="Warehouses.view(${w.id})">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="Warehouses.edit(${w.id})">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = `<div class="row g-3">${html}</div>`;
    },
    
    /**
     * عرض الشجري
     */
    renderTreeView: function(container, tree) {
        const buildTreeHtml = (nodes, level = 0) => {
            let html = '';
            nodes.forEach(node => {
                const children = node.children || [];
                const hasChildren = children.length > 0;
                
                html += `
                    <div class="tree-item ${level === 0 ? 'active' : ''}" style="padding-right: ${level * 20}px;">
                        <span class="icon"><i class="fas fa-${node.type === 'main' ? 'building' : 'warehouse'}"></i></span>
                        <span class="name">${node.name}</span>
                        <span class="count">${node.products_count || 0} صنف · ${App.formatNumber(node.total_quantity || 0)} قطعة</span>
                        ${hasChildren ? `
                            <span class="expand" onclick="Warehouses.toggleTree(this)">
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        ` : ''}
                    </div>
                    ${hasChildren ? `
                        <div class="children">
                            ${buildTreeHtml(children, level + 1)}
                        </div>
                    ` : ''}
                `;
            });
            return html;
        };
        
        container.innerHTML = `
            <div class="tree-view">
                ${buildTreeHtml(tree)}
            </div>
        `;
    },
    
    /**
     * تبديل عرض الشجري
     */
    toggleTree: function(el) {
        const children = el.closest('.tree-item').nextElementSibling;
        if (children) {
            const isHidden = children.style.display === 'none';
            children.style.display = isHidden ? 'block' : 'none';
            el.querySelector('i').className = isHidden ? 'fas fa-chevron-down' : 'fas fa-chevron-left';
        }
    },
    
    /**
     * بناء هيكل شجري
     */
    buildTree: function(warehouses) {
        const map = {};
        const roots = [];
        
        warehouses.forEach(w => {
            map[w.id] = { ...w, children: [] };
        });
        
        warehouses.forEach(w => {
            if (w.parent_id && map[w.parent_id]) {
                map[w.parent_id].children.push(map[w.id]);
            } else {
                roots.push(map[w.id]);
            }
        });
        
        return roots;
    },
    
    // ================================================================
    // الأحداث
    // ================================================================
    
    /**
     * إعداد الأحداث
     */
    setupEvents: function() {
        // البحث
        document.getElementById('searchWarehouse')?.addEventListener('keyup', debounce(() => {
            this.loadList();
        }, 500));
        
        // فلترة النوع
        document.getElementById('filterType')?.addEventListener('change', () => {
            this.loadList();
        });
        
        // فلترة الحالة
        document.getElementById('filterStatus')?.addEventListener('change', () => {
            this.loadList();
        });
        
        // تغيير وضع العرض
        document.querySelectorAll('.view-toggle .toggle-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const view = this.dataset.view;
                Warehouses.setViewMode(view);
            });
        });
    },
    
    /**
     * تحميل وضع العرض
     */
    loadViewMode: function() {
        const saved = localStorage.getItem('warehouses_view_mode') || 'list';
        this.setViewMode(saved);
    },
    
    /**
     * تعيين وضع العرض
     */
    setViewMode: function(mode) {
        this.viewMode = mode;
        localStorage.setItem('warehouses_view_mode', mode);
        
        document.querySelectorAll('.view-toggle .toggle-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === mode);
        });
        
        this.loadList();
    },
    
    // ================================================================
    // عرض وتفاصيل
    // ================================================================
    
    /**
     * عرض تفاصيل المخزن
     */
    view: function(id) {
        API.warehouses.get(id)
            .then(response => {
                if (response.success && response.data) {
                    this.showDetailsModal(response.data);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات المخزن'
                });
            });
    },
    
    /**
     * عرض نافذة التفاصيل
     */
    showDetailsModal: function(data) {
        const warehouse = data.warehouse;
        const products = data.products || [];
        const stats = data.stats || {};
        
        let productHtml = '';
        if (products.length > 0) {
            productHtml = products.slice(0, 20).map(p => `
                <tr>
                    <td>${p.code || '-'}</td>
                    <td>${p.name}</td>
                    <td>${App.formatNumber(p.quantity)}</td>
                    <td>${App.formatNumber(p.available_quantity)}</td>
                    <td>${App.formatCurrency(p.total_value)}</td>
                    <td><span class="badge bg-${p.stock_status === 'normal' ? 'success' : p.stock_status === 'low_stock' ? 'warning' : p.stock_status === 'out_of_stock' ? 'danger' : 'info'}">${p.stock_status === 'normal' ? 'طبيعي' : p.stock_status === 'low_stock' ? 'منخفض' : p.stock_status === 'out_of_stock' ? 'نفذ' : 'زائد'}</span></td>
                </tr>
            `).join('');
        } else {
            productHtml = '<tr><td colspan="6" class="text-center text-muted">لا توجد أصناف</td></tr>';
        }
        
        Swal.fire({
            title: warehouse.name,
            width: '900px',
            showCloseButton: true,
            showConfirmButton: false,
            html: `
                <div class="text-start" style="direction:rtl;">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>الكود:</strong> ${warehouse.code || '-'}</p>
                            <p><strong>النوع:</strong> ${warehouse.type || '---'}</p>
                            <p><strong>الموقع:</strong> ${warehouse.location || '---'}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>المدير:</strong> ${warehouse.manager_name || '---'}</p>
                            <p><strong>الحالة:</strong> ${warehouse.is_active ? 'نشط' : 'غير نشط'}</p>
                            <p><strong>رئيسي:</strong> ${warehouse.is_main ? 'نعم' : 'لا'}</p>
                        </div>
                    </div>
                    ${warehouse.address ? `<p><strong>العنوان:</strong> ${warehouse.address}</p>` : ''}
                    ${warehouse.notes ? `<p><strong>ملاحظات:</strong> ${warehouse.notes}</p>` : ''}
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="text-center p-2" style="background:rgba(255,255,255,0.02);border-radius:8px;">
                                <div style="color:rgba(255,255,255,0.3);font-size:12px;">إجمالي الأصناف</div>
                                <div style="color:#fff;font-size:20px;font-weight:700;">${stats.total_products || 0}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-2" style="background:rgba(255,255,255,0.02);border-radius:8px;">
                                <div style="color:rgba(255,255,255,0.3);font-size:12px;">الكمية الإجمالية</div>
                                <div style="color:#fff;font-size:20px;font-weight:700;">${App.formatNumber(stats.total_quantity || 0)}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center p-2" style="background:rgba(255,255,255,0.02);border-radius:8px;">
                                <div style="color:rgba(255,255,255,0.3);font-size:12px;">القيمة الإجمالية</div>
                                <div style="color:#28a745;font-size:20px;font-weight:700;">${App.formatCurrency(stats.total_value || 0)}</div>
                            </div>
                        </div>
                    </div>
                    
                    <h6 class="mt-3">الأصناف في المخزن</h6>
                    <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                        <table class="table table-sm">
                            <thead><tr><th>الكود</th><th>الاسم</th><th>الكمية</th><th>متاح</th><th>القيمة</th><th>الحالة</th></tr></thead>
                            <tbody>${productHtml}</tbody>
                        </table>
                        ${products.length > 20 ? `<div class="text-center text-muted small mt-1">... وعرض ${products.length - 20} صنف آخر</div>` : ''}
                    </div>
                </div>
            `
        });
    },
    
    /**
     * عرض مخزون المخزن
     */
    viewStock: function(id) {
        API.warehouses.stock(id)
            .then(response => {
                if (response.success && response.data) {
                    this.showStockModal(response.data);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب مخزون المخزن'
                });
            });
    },
    
    /**
     * عرض نافذة المخزون
     */
    showStockModal: function(data) {
        const warehouse = data.warehouse;
        const products = data.products || [];
        const summary = data.summary || {};
        
        let productHtml = '';
        if (products.length > 0) {
            productHtml = products.map(p => {
                const statusColor = p.stock_status === 'نفذ' ? 'danger' : 
                                   p.stock_status === 'منخفض' ? 'warning' : 
                                   p.stock_status === 'زائد' ? 'info' : 'success';
                return `
                    <tr>
                        <td>${p.code || '-'}</td>
                        <td>${p.name}</td>
                        <td>${App.formatNumber(p.quantity)}</td>
                        <td>${App.formatNumber(p.available_quantity)}</td>
                        <td>${p.unit_name || '-'}</td>
                        <td>${App.formatCurrency(p.cost_price)}</td>
                        <td>${App.formatCurrency(p.total_value)}</td>
                        <td><span class="badge bg-${statusColor}">${p.stock_status}</span></td>
                    </tr>
                `;
            }).join('');
        } else {
            productHtml = '<tr><td colspan="8" class="text-center text-muted">لا توجد أصناف</td></tr>';
        }
        
        Swal.fire({
            title: `مخزون المخزن: ${warehouse.name}`,
            width: '950px',
            showCloseButton: true,
            showConfirmButton: false,
            html: `
                <div class="text-start" style="direction:rtl;">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="text-center p-2" style="background:rgba(255,255,255,0.02);border-radius:8px;">
                                <div style="color:rgba(255,255,255,0.3);font-size:11px;">إجمالي الأصناف</div>
                                <div style="color:#fff;font-size:18px;font-weight:700;">${summary.total_products || 0}</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-2" style="background:rgba(255,255,255,0.02);border-radius:8px;">
                                <div style="color:rgba(255,255,255,0.3);font-size:11px;">الكمية الإجمالية</div>
                                <div style="color:#fff;font-size:18px;font-weight:700;">${App.formatNumber(summary.total_quantity || 0)}</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-2" style="background:rgba(255,255,255,0.02);border-radius:8px;">
                                <div style="color:rgba(255,255,255,0.3);font-size:11px;">القيمة الإجمالية</div>
                                <div style="color:#28a745;font-size:18px;font-weight:700;">${App.formatCurrency(summary.total_value || 0)}</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-2" style="background:rgba(255,255,255,0.02);border-radius:8px;">
                                <div style="color:rgba(255,255,255,0.3);font-size:11px;">منخفض/نفذ</div>
                                <div style="color:#ffc107;font-size:18px;font-weight:700;">${(summary.low_stock || 0) + (summary.out_of_stock || 0)}</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                        <table class="table table-sm">
                            <thead><tr><th>الكود</th><th>الاسم</th><th>الكمية</th><th>متاح</th><th>الوحدة</th><th>سعر الشراء</th><th>القيمة</th><th>الحالة</th></tr></thead>
                            <tbody>${productHtml}</tbody>
                        </table>
                    </div>
                </div>
            `
        });
    },
    
    // ================================================================
    // إنشاء وتعديل
    // ================================================================
    
    /**
     * عرض نافذة إنشاء مخزن
     */
    showCreate: function(type = 'main') {
        this.currentId = null;
        this.resetForm(type);
        this.populateParentSelect();
        
        document.getElementById('modalTitle').textContent = type === 'sub' ? 'إضافة مخزن فرعي' : 'إضافة مخزن جديد';
        document.getElementById('whType').value = type;
        
        const modal = new bootstrap.Modal(document.getElementById('warehouseModal'));
        modal.show();
    },
    
    /**
     * تعديل مخزن
     */
    edit: function(id) {
        API.warehouses.get(id)
            .then(response => {
                if (response.success && response.data.warehouse) {
                    this.fillForm(response.data.warehouse);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات المخزن'
                });
            });
    },
    
    /**
     * إعادة تعيين النموذج
     */
    resetForm: function(type = 'main') {
        document.getElementById('warehouseForm').reset();
        document.getElementById('warehouseId').value = '';
        document.getElementById('whType').value = type;
        document.getElementById('whActive').checked = true;
        document.getElementById('whMain').checked = false;
        document.getElementById('whParent').value = '';
    },
    
    /**
     * تعبئة النموذج للتعديل
     */
    fillForm: function(warehouse) {
        document.getElementById('modalTitle').textContent = 'تعديل مخزن';
        document.getElementById('warehouseId').value = warehouse.id;
        document.getElementById('whName').value = warehouse.name || '';
        document.getElementById('whCode').value = warehouse.code || '';
        document.getElementById('whType').value = warehouse.type || 'main';
        document.getElementById('whParent').value = warehouse.parent_id || '';
        document.getElementById('whLocation').value = warehouse.location || '';
        document.getElementById('whAddress').value = warehouse.address || '';
        document.getElementById('whManager').value = warehouse.manager_id || '';
        document.getElementById('whPhone').value = warehouse.phone || '';
        document.getElementById('whEmail').value = warehouse.email || '';
        document.getElementById('whCapacity').value = warehouse.capacity || '';
        document.getElementById('whNotes').value = warehouse.notes || '';
        document.getElementById('whActive').checked = warehouse.is_active !== 0;
        document.getElementById('whMain').checked = warehouse.is_main === 1;
        document.getElementById('whDefault').checked = warehouse.is_default === 1;
        
        this.populateParentSelect(warehouse.parent_id);
        
        const modal = new bootstrap.Modal(document.getElementById('warehouseModal'));
        modal.show();
    },
    
    /**
     * تعبئة قائمة المخازن الرئيسية
     */
    populateParentSelect: function(selectedId = null) {
        const select = document.getElementById('whParent');
        select.innerHTML = '<option value="">لا يوجد (مخزن رئيسي)</option>';
        
        this.data.filter(w => w.type === 'main' && w.is_active).forEach(w => {
            const option = document.createElement('option');
            option.value = w.id;
            option.textContent = w.name;
            if (w.id == selectedId) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    },
    
    /**
     * حفظ المخزن
     */
    save: function() {
        const name = document.getElementById('whName').value.trim();
        if (!name) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يرجى إدخال اسم المخزن'
            });
            return;
        }
        
        const data = {
            name: name,
            code: document.getElementById('whCode').value.trim() || undefined,
            type: document.getElementById('whType').value,
            parent_id: document.getElementById('whParent').value || null,
            location: document.getElementById('whLocation').value.trim() || null,
            address: document.getElementById('whAddress').value.trim() || null,
            manager_id: document.getElementById('whManager').value || null,
            phone: document.getElementById('whPhone').value.trim() || null,
            email: document.getElementById('whEmail').value.trim() || null,
            capacity: document.getElementById('whCapacity').value || null,
            notes: document.getElementById('whNotes').value.trim() || null,
            is_active: document.getElementById('whActive').checked ? 1 : 0,
            is_main: document.getElementById('whMain').checked ? 1 : 0,
            is_default: document.getElementById('whDefault').checked ? 1 : 0
        };
        
        const id = document.getElementById('warehouseId').value;
        const isEdit = id && id !== '';
        
        const saveFn = isEdit ? API.warehouses.update(id, data) : API.warehouses.create(data);
        
        saveFn
            .then(response => {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'تم الحفظ بنجاح',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    bootstrap.Modal.getInstance(document.getElementById('warehouseModal')).hide();
                    this.loadList();
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في حفظ البيانات'
                });
            });
    },
    
    // ================================================================
    // حذف
    // ================================================================
    
    /**
     * حذف مخزن
     */
    deleteWarehouse: function(id) {
        Swal.fire({
            title: 'تأكيد الحذف',
            text: 'هل أنت متأكد من رغبتك في حذف هذا المخزن؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، حذف',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#DC3545'
        }).then(result => {
            if (result.isConfirmed) {
                API.warehouses.delete(id)
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'تم الحذف بنجاح',
                                timer: 1500,
                                showConfirmButton: false
                            });
                            this.loadList();
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'خطأ',
                            text: error.message || 'حدث خطأ في حذف المخزن'
                        });
                    });
            }
        });
    },
    
    // ================================================================
    // إحصائيات
    // ================================================================
    
    /**
     * تحديث الإحصائيات
     */
    updateStats: function(stats) {
        if (!stats) return;
        
        // تحديث شارة المخازن في القائمة الجانبية
        const badge = document.querySelector('.sidebar-menu a[href="#warehouses"] .badge');
        if (badge) {
            badge.textContent = stats.total || 0;
        }
        
        // تحديث عدد المخازن في رأس الصفحة
        const countEl = document.getElementById('warehousesCount');
        if (countEl) {
            countEl.textContent = stats.total || 0;
        }
    }
};

// ================================================================
// دوال عامة للاستخدام من HTML
// ================================================================

function showCreateWarehouse() {
    Warehouses.showCreate('main');
}

function showCreateSubWarehouse() {
    Warehouses.showCreate('sub');
}

function saveWarehouse() {
    Warehouses.save();
}

// ================================================================
// تصدير Warehouses
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = Warehouses;
}

// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/products.js
// الوصف: إدارة الأصناف - CRUD كامل مع بحث وفلترة
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

/**
 * إدارة الأصناف - جميع العمليات
 */
const Products = {
    // ================================================================
    // الحالة
    // ================================================================
    
    table: null,
    categories: [],
    units: [],
    warehouses: [],
    currentId: null,
    items: [],
    viewMode: 'list', // list, cards, tree, icons
    
    // ================================================================
    // التحميل
    // ================================================================
    
    /**
     * تحميل صفحة الأصناف
     */
    load: function() {
        this.loadTable();
        this.loadSelectData();
        this.setupEvents();
        this.loadViewMode();
    },
    
    /**
     * تحميل الجدول
     */
    loadTable: function() {
        const table = document.getElementById('productsTable');
        if (!table) return;
        
        // تدمير الجدول السابق
        if (this.table) {
            this.table.destroy();
        }
        
        // الحصول على معاملات الفلترة
        const search = document.getElementById('productsSearch')?.value || '';
        const category = document.getElementById('productsCategory')?.value || '';
        const status = document.getElementById('productsStatus')?.value || '';
        const warehouse = document.getElementById('productsWarehouse')?.value || '';
        
        this.table = $(table).DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: API.baseUrl + '/products',
                type: 'GET',
                data: function(d) {
                    d.search = search;
                    d.category = category;
                    d.status = status;
                    d.warehouse = warehouse;
                    d.limit = d.length || 20;
                    d.page = (d.start / d.length) + 1;
                    d.view = Products.viewMode;
                },
                dataSrc: function(json) {
                    if (json.success) {
                        Products.updateStats(json.stats);
                        return json.data || [];
                    }
                    return [];
                }
            },
            columns: [
                { 
                    data: 'code',
                    render: function(data) {
                        return `<span style="color:rgba(255,255,255,0.4);font-size:12px;">${data || '-'}</span>`;
                    }
                },
                { 
                    data: 'name',
                    render: function(data, type, row) {
                        return `
                            <div style="color:#fff;font-weight:500;">${data}</div>
                            <div style="color:rgba(255,255,255,0.3);font-size:11px;">${row.barcode || ''}</div>
                        `;
                    }
                },
                { data: 'category_name', defaultContent: '-' },
                { data: 'unit_name', defaultContent: '-' },
                { 
                    data: 'total_quantity',
                    render: function(data) {
                        const qty = data || 0;
                        const color = qty <= 0 ? '#dc3545' : qty <= 10 ? '#ffc107' : '#28a745';
                        return `<span style="color:${color};font-weight:600;">${App.formatNumber(qty)}</span>`;
                    }
                },
                { 
                    data: 'cost_price',
                    render: function(data) {
                        return App.formatCurrency(data || 0);
                    }
                },
                {
                    data: 'is_active',
                    render: function(data) {
                        return data ? 
                            '<span class="badge bg-success">نشط</span>' : 
                            '<span class="badge bg-secondary">غير نشط</span>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    render: function(data) {
                        return `
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-sm btn-info" onclick="Products.view(${data.id})" title="عرض">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-warning" onclick="Products.edit(${data.id})" title="تعديل">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-success" onclick="Products.printBarcode(${data.id})" title="طباعة باركود">
                                    <i class="fas fa-barcode"></i>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="Products.deleteProduct(${data.id})" title="حذف">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        `;
                    }
                }
            ],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/ar.json'
            },
            order: [[0, 'asc']],
            responsive: true,
            pageLength: 20,
            lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
            drawCallback: function() {
                // تحديث الإحصائيات
                Products.updateStatsFromTable();
            }
        });
    },
    
    /**
     * تحميل بيانات القوائم المنسدلة
     */
    loadSelectData: function() {
        // تحميل التصنيفات
        API.products.categories()
            .then(response => {
                if (response.success) {
                    this.categories = response.data || [];
                    this.populateCategories();
                }
            })
            .catch(error => console.error('Error loading categories:', error));
        
        // تحميل الوحدات
        API.products.units()
            .then(response => {
                if (response.success) {
                    this.units = response.data || [];
                    this.populateUnits();
                }
            })
            .catch(error => console.error('Error loading units:', error));
        
        // تحميل المخازن
        API.warehouses.list()
            .then(response => {
                if (response.success) {
                    this.warehouses = response.data || [];
                    this.populateWarehouses();
                }
            })
            .catch(error => console.error('Error loading warehouses:', error));
    },
    
    /**
     * تعبئة قوائم التصنيفات
     */
    populateCategories: function() {
        const selects = document.querySelectorAll('#productCategory, #productsCategory, #filterCategory');
        selects.forEach(select => {
            if (!select) return;
            const currentValue = select.value;
            select.innerHTML = '<option value="">اختر التصنيف</option>';
            this.categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.id;
                option.textContent = cat.name;
                if (cat.id == currentValue) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        });
    },
    
    /**
     * تعبئة قوائم الوحدات
     */
    populateUnits: function() {
        const selects = document.querySelectorAll('#productUnit');
        selects.forEach(select => {
            if (!select) return;
            const currentValue = select.value;
            select.innerHTML = '<option value="">اختر الوحدة</option>';
            this.units.forEach(unit => {
                const option = document.createElement('option');
                option.value = unit.id;
                option.textContent = unit.name;
                if (unit.id == currentValue) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        });
    },
    
    /**
     * تعبئة قوائم المخازن
     */
    populateWarehouses: function() {
        const selects = document.querySelectorAll('#productWarehouse, #productsWarehouse');
        selects.forEach(select => {
            if (!select) return;
            const currentValue = select.value;
            select.innerHTML = '<option value="">اختر المخزن</option>';
            this.warehouses.filter(w => w.is_active).forEach(wh => {
                const option = document.createElement('option');
                option.value = wh.id;
                option.textContent = wh.name;
                if (wh.id == currentValue) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        });
    },
    
    /**
     * إعداد الأحداث
     */
    setupEvents: function() {
        // بحث
        const searchInput = document.getElementById('productsSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', debounce(() => {
                this.loadTable();
            }, 500));
        }
        
        // فلترة التصنيف
        const categorySelect = document.getElementById('productsCategory');
        if (categorySelect) {
            categorySelect.addEventListener('change', () => {
                this.loadTable();
            });
        }
        
        // فلترة الحالة
        const statusSelect = document.getElementById('productsStatus');
        if (statusSelect) {
            statusSelect.addEventListener('change', () => {
                this.loadTable();
            });
        }
        
        // فلترة المخزن
        const warehouseSelect = document.getElementById('productsWarehouse');
        if (warehouseSelect) {
            warehouseSelect.addEventListener('change', () => {
                this.loadTable();
            });
        }
        
        // تغيير وضع العرض
        document.querySelectorAll('.view-toggle .toggle-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const view = this.dataset.view;
                Products.setViewMode(view);
            });
        });
        
        // زر التحديث
        document.getElementById('refreshProducts')?.addEventListener('click', () => {
            this.loadTable();
        });
        
        // زر التصدير
        document.getElementById('exportProducts')?.addEventListener('click', () => {
            this.exportProducts();
        });
    },
    
    /**
     * تحميل وضع العرض
     */
    loadViewMode: function() {
        const saved = localStorage.getItem('products_view_mode') || 'list';
        this.setViewMode(saved);
    },
    
    /**
     * تعيين وضع العرض
     */
    setViewMode: function(mode) {
        this.viewMode = mode;
        localStorage.setItem('products_view_mode', mode);
        
        // تحديث الأزرار
        document.querySelectorAll('.view-toggle .toggle-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === mode);
        });
        
        // إعادة تحميل الجدول
        this.loadTable();
    },
    
    // ================================================================
    // عرض وتفاصيل
    // ================================================================
    
    /**
     * عرض تفاصيل الصنف
     */
    view: function(id) {
        API.products.get(id)
            .then(response => {
                if (response.success && response.data) {
                    this.showDetailsModal(response.data);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات الصنف'
                });
            });
    },
    
    /**
     * عرض نافذة التفاصيل
     */
    showDetailsModal: function(data) {
        const product = data.product;
        const balances = data.balances || [];
        const movements = data.recent_movements || [];
        
        let balanceHtml = '';
        if (balances.length > 0) {
            balanceHtml = balances.map(b => `
                <tr>
                    <td>${b.warehouse_name}</td>
                    <td>${App.formatNumber(b.quantity)}</td>
                    <td>${App.formatNumber(b.reserved_quantity)}</td>
                    <td>${App.formatNumber(b.available_quantity)}</td>
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
                    <td>${App.getMovementLabel(m.movement_type)}</td>
                    <td>${m.warehouse_name}</td>
                    <td>${App.formatNumber(m.quantity)}</td>
                    <td>${App.formatCurrency(m.unit_cost)}</td>
                    <td>${App.formatCurrency(m.balance_after)}</td>
                    <td>${App.formatDate(m.movement_date)}</td>
                    <td>${m.user_name}</td>
                </tr>
            `).join('');
        } else {
            movementHtml = '<tr><td colspan="7" class="text-center text-muted">لا توجد حركات</td></tr>';
        }
        
        Swal.fire({
            title: product.name,
            width: '900px',
            showCloseButton: true,
            showConfirmButton: false,
            html: `
                <div class="text-start" style="direction:rtl;">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>الكود:</strong> ${product.code || '-'}</p>
                            <p><strong>الباركود:</strong> ${product.barcode || '-'}</p>
                            <p><strong>التصنيف:</strong> ${product.category_name || '-'}</p>
                            <p><strong>الوحدة:</strong> ${product.unit_name || '-'}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>سعر الشراء:</strong> ${App.formatCurrency(product.cost_price)}</p>
                            <p><strong>سعر البيع:</strong> ${App.formatCurrency(product.selling_price)}</p>
                            <p><strong>الحد الأدنى:</strong> ${App.formatNumber(product.min_stock)}</p>
                            <p><strong>الحالة:</strong> ${product.is_active ? 'نشط' : 'غير نشط'}</p>
                        </div>
                    </div>
                    ${product.description ? `<p><strong>الوصف:</strong> ${product.description}</p>` : ''}
                    ${product.notes ? `<p><strong>ملاحظات:</strong> ${product.notes}</p>` : ''}
                    
                    <h6 class="mt-3">الأرصدة في المخازن</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>المخزن</th><th>الكمية</th><th>محجوز</th><th>متاح</th><th>القيمة</th></tr></thead>
                            <tbody>${balanceHtml}</tbody>
                        </table>
                    </div>
                    
                    <h6 class="mt-3">آخر الحركات</h6>
                    <div class="table-responsive" style="max-height:200px;overflow-y:auto;">
                        <table class="table table-sm">
                            <thead><tr><th>النوع</th><th>المخزن</th><th>الكمية</th><th>سعر الوحدة</th><th>الرصيد بعد</th><th>التاريخ</th><th>المستخدم</th></tr></thead>
                            <tbody>${movementHtml}</tbody>
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
     * عرض نافذة إنشاء صنف
     */
    showCreate: function() {
        this.currentId = null;
        this.resetForm();
        this.populateCategories();
        this.populateUnits();
        this.populateWarehouses();
        
        document.getElementById('modalTitle').textContent = 'إضافة صنف جديد';
        const modal = new bootstrap.Modal(document.getElementById('productModal'));
        modal.show();
    },
    
    /**
     * تعديل صنف
     */
    edit: function(id) {
        API.products.get(id)
            .then(response => {
                if (response.success && response.data.product) {
                    this.fillForm(response.data.product);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات الصنف'
                });
            });
    },
    
    /**
     * إعادة تعيين النموذج
     */
    resetForm: function() {
        document.getElementById('productForm').reset();
        document.getElementById('productId').value = '';
        document.getElementById('productCode').value = '';
        document.getElementById('productStatus').value = '1';
        document.getElementById('productMinStock').value = '0';
        document.getElementById('productCostPrice').value = '0';
        document.getElementById('productTaxRate').value = '0';
        document.getElementById('productActive').checked = true;
        document.getElementById('productSerialized').checked = false;
        document.getElementById('productBatch').checked = false;
        document.getElementById('productExpirable').checked = false;
    },
    
    /**
     * تعبئة النموذج للتعديل
     */
    fillForm: function(product) {
        this.currentId = product.id;
        document.getElementById('modalTitle').textContent = 'تعديل صنف';
        document.getElementById('productId').value = product.id;
        document.getElementById('productCode').value = product.code || '';
        document.getElementById('productBarcode').value = product.barcode || '';
        document.getElementById('productName').value = product.name || '';
        document.getElementById('productCategory').value = product.category_id || '';
        document.getElementById('productUnit').value = product.unit_id || '';
        document.getElementById('productWarehouse').value = product.warehouse_id || '';
        document.getElementById('productMinStock').value = product.min_stock || 0;
        document.getElementById('productMaxStock').value = product.max_stock || '';
        document.getElementById('productReorderPoint').value = product.reorder_point || '';
        document.getElementById('productReorderQuantity').value = product.reorder_quantity || '';
        document.getElementById('productCostPrice').value = product.cost_price || 0;
        document.getElementById('productSellingPrice').value = product.selling_price || '';
        document.getElementById('productWeight').value = product.weight || '';
        document.getElementById('productDimensions').value = product.dimensions || '';
        document.getElementById('productStatus').value = product.is_active ? '1' : '0';
        document.getElementById('productTaxRate').value = product.tax_rate || 0;
        document.getElementById('productNotes').value = product.notes || '';
        document.getElementById('productDescription').value = product.description || '';
        document.getElementById('productShelfLife').value = product.shelf_life_days || '';
        document.getElementById('productWarranty').value = product.warranty_days || '';
        document.getElementById('productActive').checked = product.is_active === 1;
        document.getElementById('productSerialized').checked = product.is_serialized === 1;
        document.getElementById('productBatch').checked = product.is_batch_tracked === 1;
        document.getElementById('productExpirable').checked = product.is_expirable === 1;
        
        this.populateCategories();
        this.populateUnits();
        this.populateWarehouses();
        
        const modal = new bootstrap.Modal(document.getElementById('productModal'));
        modal.show();
    },
    
    /**
     * حفظ الصنف
     */
    save: function() {
        const form = document.getElementById('productForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        
        const data = {
            code: document.getElementById('productCode').value.trim() || undefined,
            barcode: document.getElementById('productBarcode').value.trim() || undefined,
            name: document.getElementById('productName').value.trim(),
            description: document.getElementById('productDescription').value.trim() || undefined,
            category_id: document.getElementById('productCategory').value || undefined,
            unit_id: parseInt(document.getElementById('productUnit').value),
            warehouse_id: document.getElementById('productWarehouse').value || undefined,
            min_stock: parseFloat(document.getElementById('productMinStock').value) || 0,
            max_stock: parseFloat(document.getElementById('productMaxStock').value) || undefined,
            reorder_point: parseFloat(document.getElementById('productReorderPoint').value) || undefined,
            reorder_quantity: parseFloat(document.getElementById('productReorderQuantity').value) || undefined,
            cost_price: parseFloat(document.getElementById('productCostPrice').value) || 0,
            selling_price: parseFloat(document.getElementById('productSellingPrice').value) || undefined,
            weight: parseFloat(document.getElementById('productWeight').value) || undefined,
            dimensions: document.getElementById('productDimensions').value.trim() || undefined,
            is_active: document.getElementById('productActive').checked ? 1 : 0,
            is_serialized: document.getElementById('productSerialized').checked ? 1 : 0,
            is_batch_tracked: document.getElementById('productBatch').checked ? 1 : 0,
            is_expirable: document.getElementById('productExpirable').checked ? 1 : 0,
            shelf_life_days: parseInt(document.getElementById('productShelfLife').value) || undefined,
            warranty_days: parseInt(document.getElementById('productWarranty').value) || undefined,
            tax_rate: parseFloat(document.getElementById('productTaxRate').value) || 0,
            notes: document.getElementById('productNotes').value.trim() || undefined
        };
        
        const id = document.getElementById('productId').value;
        const isEdit = id && id !== '';
        
        const saveFn = isEdit ? API.products.update(id, data) : API.products.create(data);
        
        saveFn
            .then(response => {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'تم الحفظ بنجاح',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    bootstrap.Modal.getInstance(document.getElementById('productModal')).hide();
                    this.loadTable();
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
     * حذف صنف
     */
    deleteProduct: function(id) {
        Swal.fire({
            title: 'تأكيد الحذف',
            text: 'هل أنت متأكد من رغبتك في حذف هذا الصنف؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، حذف',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#DC3545'
        }).then(result => {
            if (result.isConfirmed) {
                API.products.delete(id)
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'تم الحذف بنجاح',
                                timer: 1500,
                                showConfirmButton: false
                            });
                            this.loadTable();
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'خطأ',
                            text: error.message || 'حدث خطأ في حذف الصنف'
                        });
                    });
            }
        });
    },
    
    // ================================================================
    // تصدير وطباعة
    // ================================================================
    
    /**
     * تصدير الأصناف
     */
    exportProducts: function() {
        const format = document.getElementById('exportFormat')?.value || 'csv';
        const search = document.getElementById('productsSearch')?.value || '';
        const category = document.getElementById('productsCategory')?.value || '';
        
        API.products.export({ format, search, category })
            .then(response => {
                if (response.success && response.data) {
                    if (format === 'csv') {
                        App.exportCSV(response.data, `products_${new Date().toISOString().slice(0,10)}.csv`);
                    } else if (format === 'excel') {
                        App.exportExcel(response.data, `products_${new Date().toISOString().slice(0,10)}.xls`);
                    } else {
                        Swal.fire({
                            icon: 'info',
                            title: 'بيانات الأصناف',
                            html: `<pre style="text-align:right;max-height:400px;overflow:auto;">${JSON.stringify(response.data, null, 2)}</pre>`,
                            width: '800px'
                        });
                    }
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في تصدير البيانات'
                });
            });
    },
    
    /**
     * طباعة باركود
     */
    printBarcode: function(id) {
        API.products.barcode(id)
            .then(response => {
                if (response.success && response.data) {
                    const product = response.data.product;
                    const barcode = response.data.barcode_image;
                    
                    const printWindow = window.open('', '_blank', 'width=400,height=300');
                    printWindow.document.write(`
                        <html>
                            <head>
                                <title>باركود - ${product.name}</title>
                                <style>
                                    body { font-family: 'Tajawal', sans-serif; text-align: center; padding: 30px; direction: rtl; }
                                    .barcode-container { border: 1px solid #ddd; padding: 20px; border-radius: 10px; max-width: 300px; margin: 0 auto; }
                                    .product-name { font-size: 16px; font-weight: bold; margin-bottom: 10px; }
                                    .product-code { color: #666; font-size: 12px; margin-bottom: 10px; }
                                    .barcode-image { margin: 10px auto; }
                                    .barcode-number { font-family: 'Courier New', monospace; font-size: 18px; letter-spacing: 2px; }
                                </style>
                            </head>
                            <body>
                                <div class="barcode-container">
                                    <div class="product-name">${product.name}</div>
                                    <div class="product-code">${product.code || ''}</div>
                                    <div class="barcode-image">${barcode}</div>
                                    <div class="barcode-number">${product.barcode}</div>
                                    <div style="margin-top:15px;font-size:11px;color:#999;">نظام المخازن المتقدم</div>
                                </div>
                                <div style="margin-top:20px;">
                                    <button onclick="window.print()" style="padding:10px 30px;background:#667eea;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px;">
                                        <i class="fas fa-print"></i> طباعة
                                    </button>
                                    <button onclick="window.close()" style="padding:10px 30px;background:#6c757d;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px;margin-right:10px;">
                                        <i class="fas fa-times"></i> إغلاق
                                    </button>
                                </div>
                            </body>
                        </html>
                    `);
                    printWindow.document.close();
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب الباركود'
                });
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
        
        // تحديث شارة الأصناف في القائمة الجانبية
        const badge = document.querySelector('.sidebar-menu a[href="#products"] .badge');
        if (badge) {
            badge.textContent = stats.total || 0;
        }
        
        // تحديث عدد الأصناف في رأس الصفحة
        const countEl = document.getElementById('productsCount');
        if (countEl) {
            countEl.textContent = stats.total || 0;
        }
    },
    
    /**
     * تحديث الإحصائيات من الجدول
     */
    updateStatsFromTable: function() {
        // يمكن جلب الإحصائيات من الجدول
        const info = this.table?.page.info();
        if (info) {
            const badge = document.querySelector('.sidebar-menu a[href="#products"] .badge');
            if (badge) {
                badge.textContent = info.recordsTotal || 0;
            }
        }
    }
};

// ================================================================
// دوال عامة للاستخدام من HTML
// ================================================================

function showCreateProduct() {
    Products.showCreate();
}

function saveProduct() {
    Products.save();
}

function searchProducts() {
    Products.loadTable();
}

function exportProducts() {
    Products.exportProducts();
}

// ================================================================
// Debounce utility
// ================================================================

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ================================================================
// تصدير Products
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = Products;
}

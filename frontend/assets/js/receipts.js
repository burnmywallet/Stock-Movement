// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/receipts.js
// الوصف: إدارة إذون الاستلام
// الإصدار: 2.0 Production Ready
// التاريخ: 2026-08-20
// ================================================================

const Receipts = {
    // DataTable instance
    table: null,
    
    // Cache data
    warehouses: [],
    suppliers: [],
    products: [],
    
    // Current receipt items
    items: [],
    
    // Current receipt ID for editing
    currentId: null,
    
    /**
     * Load receipts page
     */
    load: function() {
        this.loadTable();
        this.loadSelectData();
        this.setupEvents();
    },
    
    /**
     * Load receipts table
     */
    loadTable: function() {
        const table = document.getElementById('receiptsTable');
        if (!table) return;
        
        if (this.table) {
            this.table.destroy();
        }
        
        const status = document.getElementById('receiptsStatus')?.value || '';
        const warehouse = document.getElementById('receiptsWarehouse')?.value || '';
        const fromDate = document.getElementById('receiptsFromDate')?.value || '';
        const toDate = document.getElementById('receiptsToDate')?.value || '';
        
        this.table = $(table).DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: API.baseUrl + '/receipts',
                type: 'GET',
                data: function(d) {
                    d.status = status;
                    d.warehouse = warehouse;
                    d.from_date = fromDate;
                    d.to_date = toDate;
                    d.limit = d.length || 20;
                    d.page = (d.start / d.length) + 1;
                },
                dataSrc: function(json) {
                    if (json.success) {
                        return json.data || [];
                    }
                    return [];
                }
            },
            columns: [
                { data: 'receipt_no' },
                { data: 'warehouse_name', defaultContent: '-' },
                { data: 'supplier_name', defaultContent: '-' },
                { 
                    data: 'receipt_date',
                    render: function(data) {
                        return App.formatDateOnly(data);
                    }
                },
                { 
                    data: 'total_quantity',
                    render: function(data) {
                        return App.formatNumber(data || 0);
                    }
                },
                { 
                    data: 'total_cost',
                    render: function(data) {
                        return App.formatCurrency(data || 0);
                    }
                },
                {
                    data: 'status',
                    render: function(data) {
                        return App.getStatusBadge(data);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    render: function(data) {
                        let buttons = `
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-primary" onclick="Receipts.show(${data.id})">
                                    <i class="fas fa-eye"></i>
                                </button>
                        `;
                        
                        if (data.status === 'draft') {
                            buttons += `
                                <button class="btn btn-warning" onclick="Receipts.edit(${data.id})">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-success" onclick="Receipts.approve(${data.id})">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-danger" onclick="Receipts.cancel(${data.id})">
                                    <i class="fas fa-times"></i>
                                </button>
                            `;
                        } else if (data.status === 'submitted') {
                            buttons += `
                                <button class="btn btn-success" onclick="Receipts.approve(${data.id})">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-danger" onclick="Receipts.reject(${data.id})">
                                    <i class="fas fa-ban"></i>
                                </button>
                            `;
                        }
                        
                        buttons += `</div>`;
                        return buttons;
                    }
                }
            ],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/ar.json'
            },
            order: [[3, 'desc']],
            responsive: true,
            pageLength: 20,
            lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]]
        });
    },
    
    /**
     * Load select data
     */
    loadSelectData: function() {
        // Load warehouses
        API.warehouses.list()
            .then(response => {
                if (response.success) {
                    this.warehouses = response.data || [];
                    this.populateWarehouses();
                }
            })
            .catch(error => console.error('Error loading warehouses:', error));
        
        // Load suppliers
        // Note: You would need to add a suppliers endpoint
        // For now, we'll use a simple list
        this.loadSuppliers();
    },
    
    /**
     * Load suppliers
     */
    loadSuppliers: function() {
        // This would be an API call to get suppliers
        // For now, we'll use mock data or fetch from existing data
        // You can implement the suppliers API endpoint similarly
        API.get('/suppliers')
            .then(response => {
                if (response.success) {
                    this.suppliers = response.data || [];
                    this.populateSuppliers();
                }
            })
            .catch(() => {
                // Fallback: use some default suppliers
                this.suppliers = [
                    { id: 1, name: 'شركة التقنية الحديثة' },
                    { id: 2, name: 'مؤسسة الغذاء الصحي' },
                    { id: 3, name: 'شركة البناء المتين' }
                ];
                this.populateSuppliers();
            });
    },
    
    /**
     * Populate warehouse selects
     */
    populateWarehouses: function() {
        const selects = document.querySelectorAll('#receiptWarehouse, #receiptsWarehouse');
        selects.forEach(select => {
            const currentValue = select.value;
            select.innerHTML = '<option value="">اختر المخزن</option>';
            this.warehouses.forEach(w => {
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
     * Populate supplier selects
     */
    populateSuppliers: function() {
        const selects = document.querySelectorAll('#receiptSupplier');
        selects.forEach(select => {
            const currentValue = select.value;
            select.innerHTML = '<option value="">اختر المورد</option>';
            this.suppliers.forEach(s => {
                const option = document.createElement('option');
                option.value = s.id;
                option.textContent = s.name;
                if (s.id == currentValue) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        });
    },
    
    /**
     * Setup events
     */
    setupEvents: function() {
        // Filter events
        document.getElementById('receiptsStatus')?.addEventListener('change', () => this.loadTable());
        document.getElementById('receiptsWarehouse')?.addEventListener('change', () => this.loadTable());
        document.getElementById('receiptsFromDate')?.addEventListener('change', () => this.loadTable());
        document.getElementById('receiptsToDate')?.addEventListener('change', () => this.loadTable());
        
        // Search
        document.getElementById('receiptsSearch')?.addEventListener('keyup', debounce(() => {
            this.loadTable();
        }, 500));
    },
    
    /**
     * Show receipt details
     */
    show: function(id) {
        API.receipts.get(id)
            .then(response => {
                if (response.success && response.data) {
                    this.showDetailsModal(response.data);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات الإذن'
                });
            });
    },
    
    /**
     * Show details modal
     */
    showDetailsModal: function(data) {
        const receipt = data.receipt;
        const items = data.items || [];
        
        let itemsHtml = '';
        items.forEach(item => {
            itemsHtml += `
                <tr>
                    <td>${item.product_code || '-'}</td>
                    <td>${item.product_name}</td>
                    <td>${App.formatNumber(item.quantity)}</td>
                    <td>${App.formatCurrency(item.unit_cost)}</td>
                    <td>${App.formatCurrency(item.total_cost)}</td>
                    <td>${item.unit_name || '-'}</td>
                </tr>
            `;
        });
        
        Swal.fire({
            title: `إذن استلام #${receipt.receipt_no}`,
            html: `
                <div class="text-start">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>المخزن:</strong> ${receipt.warehouse_name || '-'}</p>
                            <p><strong>المورد:</strong> ${receipt.supplier_name || '-'}</p>
                            <p><strong>التاريخ:</strong> ${App.formatDate(receipt.receipt_date)}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>الحالة:</strong> ${App.getStatusBadge(receipt.status)}</p>
                            <p><strong>الكمية الإجمالية:</strong> ${App.formatNumber(receipt.total_quantity)}</p>
                            <p><strong>القيمة الإجمالية:</strong> ${App.formatCurrency(receipt.total_cost)}</p>
                        </div>
                    </div>
                    
                    ${receipt.notes ? `<p><strong>ملاحظات:</strong> ${receipt.notes}</p>` : ''}
                    
                    <h6 class="mt-3">الأصناف</h6>
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>الكود</th>
                                    <th>الاسم</th>
                                    <th>الكمية</th>
                                    <th>سعر الوحدة</th>
                                    <th>الإجمالي</th>
                                    <th>الوحدة</th>
                                </tr>
                            </thead>
                            <tbody>${itemsHtml || '<tr><td colspan="6" class="text-center">لا توجد أصناف</td></tr>'}</tbody>
                        </table>
                    </div>
                    
                    ${data.audits && data.audits.length > 0 ? `
                        <h6 class="mt-3">سجل العمليات</h6>
                        <div class="small">
                            ${data.audits.map(a => `
                                <div class="d-flex justify-content-between border-bottom py-1">
                                    <span>${a.description || a.action}</span>
                                    <span class="text-muted">${a.user_name || 'نظام'} - ${App.formatDate(a.created_at)}</span>
                                </div>
                            `).join('')}
                        </div>
                    ` : ''}
                </div>
            `,
            width: '800px',
            confirmButtonText: 'إغلاق'
        });
    },
    
    /**
     * Show create receipt modal
     */
    showCreate: function() {
        this.currentId = null;
        this.items = [];
        this.resetForm();
        this.populateWarehouses();
        this.populateSuppliers();
        this.updateItemsTable();
        
        const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
        modal.show();
    },
    
    /**
     * Reset form
     */
    resetForm: function() {
        document.getElementById('receiptId').value = '';
        document.getElementById('receiptWarehouse').value = '';
        document.getElementById('receiptSupplier').value = '';
        document.getElementById('receiptDate').value = new Date().toISOString().split('T')[0];
        document.getElementById('receiptTime').value = new Date().toTimeString().slice(0, 5);
        document.getElementById('receiptNotes').value = '';
        document.getElementById('receiptProductSearch').value = '';
        document.getElementById('receiptProductId').value = '';
        document.getElementById('receiptQuantity').value = '';
        document.getElementById('receiptUnitCost').value = '';
        document.getElementById('receiptItemsContainer').innerHTML = '';
        this.items = [];
        this.updateTotals();
    },
    
    /**
     * Edit receipt
     */
    edit: function(id) {
        API.receipts.get(id)
            .then(response => {
                if (response.success && response.data) {
                    this.fillForm(response.data);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات الإذن'
                });
            });
    },
    
    /**
     * Fill form for edit
     */
    fillForm: function(data) {
        const receipt = data.receipt;
        const items = data.items || [];
        
        this.currentId = receipt.id;
        this.items = items.map(item => ({
            product_id: item.product_id,
            product_code: item.product_code,
            product_name: item.product_name,
            quantity: item.quantity,
            unit_cost: item.unit_cost
        }));
        
        document.getElementById('receiptId').value = receipt.id;
        document.getElementById('receiptWarehouse').value = receipt.warehouse_id || '';
        document.getElementById('receiptSupplier').value = receipt.supplier_id || '';
        document.getElementById('receiptDate').value = receipt.receipt_date || new Date().toISOString().split('T')[0];
        document.getElementById('receiptTime').value = receipt.receipt_time || new Date().toTimeString().slice(0, 5);
        document.getElementById('receiptNotes').value = receipt.notes || '';
        
        this.populateWarehouses();
        this.populateSuppliers();
        this.updateItemsTable();
        this.updateTotals();
        
        const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
        modal.show();
    },
    
    /**
     * Add item to receipt
     */
    addItem: function() {
        const productId = document.getElementById('receiptProductId').value;
        const productName = document.getElementById('receiptProductSearch').value;
        const quantity = parseFloat(document.getElementById('receiptQuantity').value);
        const unitCost = parseFloat(document.getElementById('receiptUnitCost').value);
        
        if (!productId || !productName) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يرجى اختيار صنف'
            });
            return;
        }
        
        if (!quantity || quantity <= 0) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'الكمية يجب أن تكون أكبر من صفر'
            });
            return;
        }
        
        if (!unitCost || unitCost < 0) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'سعر الوحدة غير صحيح'
            });
            return;
        }
        
        // Check if item already exists
        const existing = this.items.find(i => i.product_id == productId);
        if (existing) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'هذا الصنف مضاف مسبقاً'
            });
            return;
        }
        
        this.items.push({
            product_id: productId,
            product_code: '',
            product_name: productName,
            quantity: quantity,
            unit_cost: unitCost
        });
        
        // Clear inputs
        document.getElementById('receiptProductSearch').value = '';
        document.getElementById('receiptProductId').value = '';
        document.getElementById('receiptQuantity').value = '';
        document.getElementById('receiptUnitCost').value = '';
        
        this.updateItemsTable();
        this.updateTotals();
    },
    
    /**
     * Remove item from receipt
     */
    removeItem: function(index) {
        this.items.splice(index, 1);
        this.updateItemsTable();
        this.updateTotals();
    },
    
    /**
     * Update items table
     */
    updateItemsTable: function() {
        const container = document.getElementById('receiptItemsContainer');
        if (!container) return;
        
        if (this.items.length === 0) {
            container.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted py-3">
                        <i class="fas fa-box-open fa-2x d-block mb-2"></i>
                        لا توجد أصناف مضافة
                    </td>
                </tr>
            `;
            return;
        }
        
        let html = '';
        this.items.forEach((item, index) => {
            html += `
                <tr>
                    <td>${item.product_code || '-'}</td>
                    <td>${item.product_name}</td>
                    <td>${App.formatNumber(item.quantity)}</td>
                    <td>${App.formatCurrency(item.unit_cost)}</td>
                    <td>${App.formatCurrency(item.quantity * item.unit_cost)}</td>
                    <td>
                        <button class="btn btn-sm btn-danger" onclick="Receipts.removeItem(${index})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
        
        container.innerHTML = html;
    },
    
    /**
     * Update totals
     */
    updateTotals: function() {
        const totalItems = this.items.length;
        const totalQuantity = this.items.reduce((sum, i) => sum + i.quantity, 0);
        const totalCost = this.items.reduce((sum, i) => sum + (i.quantity * i.unit_cost), 0);
        
        document.getElementById('receiptTotalItems').textContent = totalItems;
        document.getElementById('receiptTotalQuantity').textContent = App.formatNumber(totalQuantity);
        document.getElementById('receiptTotalCost').textContent = App.formatCurrency(totalCost);
    },
    
    /**
     * Search product for receipt
     */
    searchProduct: function() {
        const search = document.getElementById('receiptProductSearch').value.trim();
        if (search.length < 2) {
            return;
        }
        
        API.products.list({ search: search, limit: 10 })
            .then(response => {
                if (response.success && response.data && response.data.length > 0) {
                    // Show product selection dropdown
                    this.showProductSuggestions(response.data);
                } else {
                    Swal.fire({
                        icon: 'info',
                        title: 'لم يتم العثور على منتج',
                        text: 'يرجى التحقق من الاسم أو الكود'
                    });
                }
            })
            .catch(error => {
                console.error('Error searching products:', error);
            });
    },
    
    /**
     * Show product suggestions
     */
    showProductSuggestions: function(products) {
        const suggestions = products.map(p => `
            <div class="dropdown-item" onclick="Receipts.selectProduct(${p.id}, '${p.name}')">
                <strong>${p.code}</strong> - ${p.name}
                <span class="text-muted">(${App.formatNumber(p.total_quantity || 0)} في المخزن)</span>
            </div>
        `).join('');
        
        // Simple implementation using a temporary div
        const container = document.createElement('div');
        container.className = 'dropdown-menu show';
        container.style.cssText = 'position: absolute; top: 100%; left: 0; right: 0; z-index: 1000;';
        container.innerHTML = suggestions;
        
        const input = document.getElementById('receiptProductSearch');
        const parent = input.parentElement;
        parent.style.position = 'relative';
        
        // Remove existing dropdown
        const existing = parent.querySelector('.dropdown-menu');
        if (existing) existing.remove();
        
        parent.appendChild(container);
        
        // Close on click outside
        setTimeout(() => {
            document.addEventListener('click', function closeDropdown(e) {
                if (!parent.contains(e.target)) {
                    container.remove();
                    document.removeEventListener('click', closeDropdown);
                }
            });
        }, 100);
    },
    
    /**
     * Select product
     */
    selectProduct: function(id, name) {
        document.getElementById('receiptProductId').value = id;
        document.getElementById('receiptProductSearch').value = name;
        
        // Remove dropdown
        const dropdown = document.querySelector('#receiptProductSearch + .dropdown-menu');
        if (dropdown) dropdown.remove();
        
        // Focus on quantity
        document.getElementById('receiptQuantity').focus();
    },
    
    /**
     * Save receipt
     */
    save: function() {
        const warehouseId = document.getElementById('receiptWarehouse').value;
        const supplierId = document.getElementById('receiptSupplier').value;
        const date = document.getElementById('receiptDate').value;
        const time = document.getElementById('receiptTime').value;
        const notes = document.getElementById('receiptNotes').value;
        
        if (!warehouseId) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يرجى اختيار المخزن'
            });
            return;
        }
        
        if (!supplierId) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يرجى اختيار المورد'
            });
            return;
        }
        
        if (this.items.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يجب إضافة صنف واحد على الأقل'
            });
            return;
        }
        
        const data = {
            warehouse_id: parseInt(warehouseId),
            supplier_id: parseInt(supplierId),
            receipt_date: date,
            receipt_time: time,
            notes: notes || undefined,
            items: this.items.map(item => ({
                product_id: parseInt(item.product_id),
                quantity: item.quantity,
                unit_cost: item.unit_cost
            }))
        };
        
        const id = document.getElementById('receiptId').value;
        const isEdit = id && id !== '';
        
        const saveFn = isEdit ? API.receipts.update(id, data) : API.receipts.create(data);
        
        saveFn
            .then(response => {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'تم الحفظ بنجاح',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    bootstrap.Modal.getInstance(document.getElementById('receiptModal')).hide();
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
    
    /**
     * Approve receipt
     */
    approve: function(id) {
        Swal.fire({
            title: 'اعتماد الإذن',
            text: 'هل أنت متأكد من رغبتك في اعتماد هذا الإذن؟',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم، اعتماد',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#28A745'
        }).then(result => {
            if (result.isConfirmed) {
                API.receipts.approve(id)
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'تم الاعتماد بنجاح',
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
                            text: error.message || 'حدث خطأ في اعتماد الإذن'
                        });
                    });
            }
        });
    },
    
    /**
     * Reject receipt
     */
    reject: function(id) {
        Swal.fire({
            title: 'رفض الإذن',
            input: 'textarea',
            inputLabel: 'سبب الرفض (اختياري)',
            inputPlaceholder: 'أدخل سبب الرفض...',
            showCancelButton: true,
            confirmButtonText: 'نعم، رفض',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#DC3545'
        }).then(result => {
            if (result.isConfirmed) {
                API.receipts.reject(id, result.value)
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'تم الرفض بنجاح',
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
                            text: error.message || 'حدث خطأ في رفض الإذن'
                        });
                    });
            }
        });
    },
    
    /**
     * Cancel receipt
     */
    cancel: function(id) {
        Swal.fire({
            title: 'إلغاء الإذن',
            text: 'هل أنت متأكد من رغبتك في إلغاء هذا الإذن؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، إلغاء',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#DC3545'
        }).then(result => {
            if (result.isConfirmed) {
                API.receipts.cancel(id)
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'تم الإلغاء بنجاح',
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
                            text: error.message || 'حدث خطأ في إلغاء الإذن'
                        });
                    });
            }
        });
    }
};

// ================================================================
// Global Functions
// ================================================================

function showCreateReceipt() {
    Receipts.showCreate();
}

function saveReceipt() {
    Receipts.save();
}

function searchReceiptProduct() {
    Receipts.searchProduct();
}

function addReceiptItem() {
    Receipts.addItem();
}

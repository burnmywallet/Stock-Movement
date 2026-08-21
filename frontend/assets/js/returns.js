// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/returns.js
// الوصف: إدارة المرتجعات
// الإصدار: 2.0 Production Ready
// التاريخ: 2026-08-20
// ================================================================

const Returns = {
    // DataTable instance
    table: null,
    
    // Cache data
    warehouses: [],
    products: [],
    referenceDocuments: [],
    
    // Current return items
    items: [],
    
    // Current return ID for editing
    currentId: null,
    
    /**
     * Load returns page
     */
    load: function() {
        this.loadTable();
        this.loadSelectData();
        this.setupEvents();
    },
    
    /**
     * Load returns table
     */
    loadTable: function() {
        const table = document.getElementById('returnsTable');
        if (!table) return;
        
        if (this.table) {
            this.table.destroy();
        }
        
        const status = document.getElementById('returnsStatus')?.value || '';
        const warehouse = document.getElementById('returnsWarehouse')?.value || '';
        const type = document.getElementById('returnsType')?.value || '';
        const fromDate = document.getElementById('returnsFromDate')?.value || '';
        const toDate = document.getElementById('returnsToDate')?.value || '';
        
        this.table = $(table).DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: API.baseUrl + '/returns',
                type: 'GET',
                data: function(d) {
                    d.status = status;
                    d.warehouse = warehouse;
                    d.type = type;
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
                { data: 'return_no' },
                { 
                    data: 'return_type_label',
                    defaultContent: '-'
                },
                { data: 'warehouse_name', defaultContent: '-' },
                { 
                    data: 'return_date',
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
                { data: 'reason', defaultContent: '-' },
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
                                <button class="btn btn-primary" onclick="Returns.show(${data.id})">
                                    <i class="fas fa-eye"></i>
                                </button>
                        `;
                        
                        if (data.status === 'draft') {
                            buttons += `
                                <button class="btn btn-success" onclick="Returns.approve(${data.id})">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-danger" onclick="Returns.cancel(${data.id})">
                                    <i class="fas fa-times"></i>
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
     * Populate warehouse selects
     */
    populateWarehouses: function() {
        const selects = document.querySelectorAll('#returnWarehouse, #returnsWarehouse');
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
     * Setup events
     */
    setupEvents: function() {
        document.getElementById('returnsStatus')?.addEventListener('change', () => this.loadTable());
        document.getElementById('returnsWarehouse')?.addEventListener('change', () => this.loadTable());
        document.getElementById('returnsType')?.addEventListener('change', () => this.loadTable());
        document.getElementById('returnsFromDate')?.addEventListener('change', () => this.loadTable());
        document.getElementById('returnsToDate')?.addEventListener('change', () => this.loadTable());
        
        document.getElementById('returnsSearch')?.addEventListener('keyup', debounce(() => {
            this.loadTable();
        }, 500));
    },
    
    /**
     * Show return details
     */
    show: function(id) {
        API.returns.get(id)
            .then(response => {
                if (response.success && response.data) {
                    this.showDetailsModal(response.data);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات المرتجع'
                });
            });
    },
    
    /**
     * Show details modal
     */
    showDetailsModal: function(data) {
        const returnData = data.return;
        const items = data.items || [];
        
        const typeLabels = {
            'to_supplier': 'إلى المورد',
            'from_customer': 'من العميل',
            'internal': 'داخلي'
        };
        
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
            title: `مرتجع #${returnData.return_no}`,
            html: `
                <div class="text-start">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>النوع:</strong> ${typeLabels[returnData.return_type] || returnData.return_type}</p>
                            <p><strong>المخزن:</strong> ${returnData.warehouse_name || '-'}</p>
                            <p><strong>التاريخ:</strong> ${App.formatDate(returnData.return_date)}</p>
                            ${returnData.reference_number ? `<p><strong>المرجع:</strong> ${returnData.reference_number}</p>` : ''}
                        </div>
                        <div class="col-md-6">
                            <p><strong>الحالة:</strong> ${App.getStatusBadge(returnData.status)}</p>
                            <p><strong>الكمية الإجمالية:</strong> ${App.formatNumber(returnData.total_quantity)}</p>
                            <p><strong>القيمة الإجمالية:</strong> ${App.formatCurrency(returnData.total_cost)}</p>
                        </div>
                    </div>
                    
                    ${returnData.reason ? `<p><strong>السبب:</strong> ${returnData.reason}</p>` : ''}
                    ${returnData.notes ? `<p><strong>ملاحظات:</strong> ${returnData.notes}</p>` : ''}
                    
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
                </div>
            `,
            width: '800px',
            confirmButtonText: 'إغلاق'
        });
    },
    
    /**
     * Show create return modal
     */
    showCreate: function() {
        this.currentId = null;
        this.items = [];
        this.referenceDocuments = [];
        this.resetForm();
        this.populateWarehouses();
        this.updateItemsTable();
        
        const modal = new bootstrap.Modal(document.getElementById('returnModal'));
        modal.show();
    },
    
    /**
     * Reset form
     */
    resetForm: function() {
        document.getElementById('returnId').value = '';
        document.getElementById('returnType').value = '';
        document.getElementById('returnWarehouse').value = '';
        document.getElementById('returnReferenceType').value = '';
        document.getElementById('returnReferenceId').value = '';
        document.getElementById('returnDate').value = new Date().toISOString().split('T')[0];
        document.getElementById('returnTime').value = new Date().toTimeString().slice(0, 5);
        document.getElementById('returnReason').value = '';
        document.getElementById('returnNotes').value = '';
        document.getElementById('returnProductSearch').value = '';
        document.getElementById('returnProductId').value = '';
        document.getElementById('returnQuantity').value = '';
        document.getElementById('returnUnitCost').value = '';
        document.getElementById('returnItemsContainer').innerHTML = '';
        this.items = [];
        this.updateTotals();
        
        // Hide reference section initially
        document.getElementById('returnReferenceSection').style.display = 'none';
    },
    
    /**
     * On return type change
     */
    onTypeChange: function() {
        const type = document.getElementById('returnType').value;
        const referenceSection = document.getElementById('returnReferenceSection');
        const referenceTypeLabel = document.getElementById('returnReferenceTypeLabel');
        
        if (type === 'to_supplier') {
            referenceSection.style.display = 'block';
            referenceTypeLabel.textContent = 'رقم إذن الاستلام';
            document.getElementById('returnReferenceType').value = 'receipt';
            this.loadReceipts();
        } else if (type === 'from_customer' || type === 'internal') {
            referenceSection.style.display = 'block';
            referenceTypeLabel.textContent = 'رقم إذن الصرف';
            document.getElementById('returnReferenceType').value = 'issue';
            this.loadIssues();
        } else {
            referenceSection.style.display = 'none';
        }
    },
    
    /**
     * Load receipts for reference
     */
    loadReceipts: function() {
        API.receipts.list({ status: 'approved', limit: 50 })
            .then(response => {
                if (response.success) {
                    this.referenceDocuments = response.data || [];
                    this.populateReferenceSelect();
                }
            })
            .catch(error => console.error('Error loading receipts:', error));
    },
    
    /**
     * Load issues for reference
     */
    loadIssues: function() {
        API.issues.list({ status: 'approved', limit: 50 })
            .then(response => {
                if (response.success) {
                    this.referenceDocuments = response.data || [];
                    this.populateReferenceSelect();
                }
            })
            .catch(error => console.error('Error loading issues:', error));
    },
    
    /**
     * Populate reference select
     */
    populateReferenceSelect: function() {
        const select = document.getElementById('returnReferenceId');
        const currentValue = select.value;
        select.innerHTML = '<option value="">اختر المرجع</option>';
        this.referenceDocuments.forEach(doc => {
            const option = document.createElement('option');
            option.value = doc.id;
            option.textContent = doc.receipt_no || doc.issue_no || `#${doc.id}`;
            if (doc.id == currentValue) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    },
    
    /**
     * Add item to return
     */
    addItem: function() {
        const productId = document.getElementById('returnProductId').value;
        const productName = document.getElementById('returnProductSearch').value;
        const quantity = parseFloat(document.getElementById('returnQuantity').value);
        const unitCost = parseFloat(document.getElementById('returnUnitCost').value);
        
        if (!productId || !productName) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى اختيار صنف' });
            return;
        }
        
        if (!quantity || quantity <= 0) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'الكمية يجب أن تكون أكبر من صفر' });
            return;
        }
        
        if (!unitCost || unitCost < 0) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'سعر الوحدة غير صحيح' });
            return;
        }
        
        const existing = this.items.find(i => i.product_id == productId);
        if (existing) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'هذا الصنف مضاف مسبقاً' });
            return;
        }
        
        this.items.push({
            product_id: productId,
            product_code: '',
            product_name: productName,
            quantity: quantity,
            unit_cost: unitCost
        });
        
        document.getElementById('returnProductSearch').value = '';
        document.getElementById('returnProductId').value = '';
        document.getElementById('returnQuantity').value = '';
        document.getElementById('returnUnitCost').value = '';
        
        this.updateItemsTable();
        this.updateTotals();
    },
    
    /**
     * Remove item from return
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
        const container = document.getElementById('returnItemsContainer');
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
                        <button class="btn btn-sm btn-danger" onclick="Returns.removeItem(${index})">
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
        
        document.getElementById('returnTotalItems').textContent = totalItems;
        document.getElementById('returnTotalQuantity').textContent = App.formatNumber(totalQuantity);
        document.getElementById('returnTotalCost').textContent = App.formatCurrency(totalCost);
    },
    
    /**
     * Search product for return
     */
    searchProduct: function() {
        const search = document.getElementById('returnProductSearch').value.trim();
        if (search.length < 2) return;
        
        API.products.list({ search: search, limit: 10 })
            .then(response => {
                if (response.success && response.data && response.data.length > 0) {
                    this.showProductSuggestions(response.data);
                }
            })
            .catch(error => console.error('Error searching products:', error));
    },
    
    /**
     * Show product suggestions
     */
    showProductSuggestions: function(products) {
        const suggestions = products.map(p => `
            <div class="dropdown-item" onclick="Returns.selectProduct(${p.id}, '${p.name}')">
                <strong>${p.code}</strong> - ${p.name}
                <span class="text-muted">(${App.formatNumber(p.total_quantity || 0)} في المخزن)</span>
            </div>
        `).join('');
        
        const container = document.createElement('div');
        container.className = 'dropdown-menu show';
        container.style.cssText = 'position: absolute; top: 100%; left: 0; right: 0; z-index: 1000;';
        container.innerHTML = suggestions;
        
        const input = document.getElementById('returnProductSearch');
        const parent = input.parentElement;
        parent.style.position = 'relative';
        
        const existing = parent.querySelector('.dropdown-menu');
        if (existing) existing.remove();
        parent.appendChild(container);
        
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
        document.getElementById('returnProductId').value = id;
        document.getElementById('returnProductSearch').value = name;
        
        const dropdown = document.querySelector('#returnProductSearch + .dropdown-menu');
        if (dropdown) dropdown.remove();
        
        document.getElementById('returnQuantity').focus();
    },
    
    /**
     * Save return
     */
    save: function() {
        const type = document.getElementById('returnType').value;
        const warehouseId = document.getElementById('returnWarehouse').value;
        const referenceType = document.getElementById('returnReferenceType').value;
        const referenceId = document.getElementById('returnReferenceId').value;
        const date = document.getElementById('returnDate').value;
        const time = document.getElementById('returnTime').value;
        const reason = document.getElementById('returnReason').value;
        const notes = document.getElementById('returnNotes').value;
        
        if (!type) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى اختيار نوع المرتجع' });
            return;
        }
        
        if (!warehouseId) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى اختيار المخزن' });
            return;
        }
        
        if (type !== 'internal') {
            if (!referenceId) {
                Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى اختيار المرجع' });
                return;
            }
        }
        
        if (this.items.length === 0) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يجب إضافة صنف واحد على الأقل' });
            return;
        }
        
        const data = {
            return_type: type,
            warehouse_id: parseInt(warehouseId),
            reference_type: referenceType || 'receipt',
            reference_id: referenceId ? parseInt(referenceId) : 0,
            return_date: date,
            return_time: time,
            reason: reason || undefined,
            notes: notes || undefined,
            items: this.items.map(item => ({
                product_id: parseInt(item.product_id),
                quantity: item.quantity,
                unit_cost: item.unit_cost
            }))
        };
        
        API.returns.create(data)
            .then(response => {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'تم الحفظ بنجاح',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    bootstrap.Modal.getInstance(document.getElementById('returnModal')).hide();
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
     * Approve return
     */
    approve: function(id) {
        Swal.fire({
            title: 'اعتماد المرتجع',
            text: 'هل أنت متأكد من رغبتك في اعتماد هذا المرتجع؟',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم، اعتماد',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#28A745'
        }).then(result => {
            if (result.isConfirmed) {
                API.returns.approve(id)
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
                            text: error.message || 'حدث خطأ في اعتماد المرتجع'
                        });
                    });
            }
        });
    },
    
    /**
     * Cancel return
     */
    cancel: function(id) {
        Swal.fire({
            title: 'إلغاء المرتجع',
            text: 'هل أنت متأكد من رغبتك في إلغاء هذا المرتجع؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، إلغاء',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#DC3545'
        }).then(result => {
            if (result.isConfirmed) {
                API.returns.cancel(id)
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
                            text: error.message || 'حدث خطأ في إلغاء المرتجع'
                        });
                    });
            }
        });
    }
};

// ================================================================
// Global Functions
// ================================================================

function showCreateReturn() {
    Returns.showCreate();
}

function saveReturn() {
    Returns.save();
}

function onReturnTypeChange() {
    Returns.onTypeChange();
}

function searchReturnProduct() {
    Returns.searchProduct();
}

function addReturnItem() {
    Returns.addItem();
}

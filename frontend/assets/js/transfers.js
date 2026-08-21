// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/transfers.js
// الوصف: إدارة التحويلات بين المخازن
// الإصدار: 2.0 Production Ready
// التاريخ: 2026-08-20
// ================================================================

const Transfers = {
    // DataTable instance
    table: null,
    
    // Cache data
    warehouses: [],
    products: [],
    
    // Current transfer items
    items: [],
    
    // Current transfer ID for editing
    currentId: null,
    
    /**
     * Load transfers page
     */
    load: function() {
        this.loadTable();
        this.loadSelectData();
        this.setupEvents();
    },
    
    /**
     * Load transfers table
     */
    loadTable: function() {
        const table = document.getElementById('transfersTable');
        if (!table) return;
        
        if (this.table) {
            this.table.destroy();
        }
        
        const status = document.getElementById('transfersStatus')?.value || '';
        const fromWarehouse = document.getElementById('transfersFromWarehouse')?.value || '';
        const toWarehouse = document.getElementById('transfersToWarehouse')?.value || '';
        const fromDate = document.getElementById('transfersFromDate')?.value || '';
        const toDate = document.getElementById('transfersToDate')?.value || '';
        
        this.table = $(table).DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: API.baseUrl + '/transfers',
                type: 'GET',
                data: function(d) {
                    d.status = status;
                    d.from_warehouse = fromWarehouse;
                    d.to_warehouse = toWarehouse;
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
                { data: 'transfer_no' },
                { data: 'from_warehouse_name', defaultContent: '-' },
                { data: 'to_warehouse_name', defaultContent: '-' },
                { 
                    data: 'transfer_date',
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
                                <button class="btn btn-primary" onclick="Transfers.show(${data.id})">
                                    <i class="fas fa-eye"></i>
                                </button>
                        `;
                        
                        if (data.status === 'draft') {
                            buttons += `
                                <button class="btn btn-warning" onclick="Transfers.edit(${data.id})">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-success" onclick="Transfers.approve(${data.id})">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-danger" onclick="Transfers.cancel(${data.id})">
                                    <i class="fas fa-times"></i>
                                </button>
                            `;
                        } else if (data.status === 'approved') {
                            buttons += `
                                <button class="btn btn-primary" onclick="Transfers.complete(${data.id})">
                                    <i class="fas fa-check-double"></i>
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
        const selects = document.querySelectorAll(
            '#transferFromWarehouse, #transferToWarehouse, ' +
            '#transfersFromWarehouse, #transfersToWarehouse'
        );
        
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
        document.getElementById('transfersStatus')?.addEventListener('change', () => this.loadTable());
        document.getElementById('transfersFromWarehouse')?.addEventListener('change', () => this.loadTable());
        document.getElementById('transfersToWarehouse')?.addEventListener('change', () => this.loadTable());
        document.getElementById('transfersFromDate')?.addEventListener('change', () => this.loadTable());
        document.getElementById('transfersToDate')?.addEventListener('change', () => this.loadTable());
        
        document.getElementById('transfersSearch')?.addEventListener('keyup', debounce(() => {
            this.loadTable();
        }, 500));
    },
    
    /**
     * Show transfer details
     */
    show: function(id) {
        API.transfers.get(id)
            .then(response => {
                if (response.success && response.data) {
                    this.showDetailsModal(response.data);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات التحويل'
                });
            });
    },
    
    /**
     * Show details modal
     */
    showDetailsModal: function(data) {
        const transfer = data.transfer;
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
            title: `تحويل #${transfer.transfer_no}`,
            html: `
                <div class="text-start">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>من مخزن:</strong> ${transfer.from_warehouse_name || '-'}</p>
                            <p><strong>إلى مخزن:</strong> ${transfer.to_warehouse_name || '-'}</p>
                            <p><strong>التاريخ:</strong> ${App.formatDate(transfer.transfer_date)}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>الحالة:</strong> ${App.getStatusBadge(transfer.status)}</p>
                            <p><strong>الكمية الإجمالية:</strong> ${App.formatNumber(transfer.total_quantity)}</p>
                            <p><strong>القيمة الإجمالية:</strong> ${App.formatCurrency(transfer.total_cost)}</p>
                        </div>
                    </div>
                    
                    ${transfer.notes ? `<p><strong>ملاحظات:</strong> ${transfer.notes}</p>` : ''}
                    
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
     * Show create transfer modal
     */
    showCreate: function() {
        this.currentId = null;
        this.items = [];
        this.resetForm();
        this.populateWarehouses();
        this.updateItemsTable();
        
        const modal = new bootstrap.Modal(document.getElementById('transferModal'));
        modal.show();
    },
    
    /**
     * Reset form
     */
    resetForm: function() {
        document.getElementById('transferId').value = '';
        document.getElementById('transferFromWarehouse').value = '';
        document.getElementById('transferToWarehouse').value = '';
        document.getElementById('transferDate').value = new Date().toISOString().split('T')[0];
        document.getElementById('transferTime').value = new Date().toTimeString().slice(0, 5);
        document.getElementById('transferNotes').value = '';
        document.getElementById('transferProductSearch').value = '';
        document.getElementById('transferProductId').value = '';
        document.getElementById('transferQuantity').value = '';
        document.getElementById('transferUnitCost').value = '';
        document.getElementById('transferItemsContainer').innerHTML = '';
        this.items = [];
        this.updateTotals();
    },
    
    /**
     * Edit transfer
     */
    edit: function(id) {
        API.transfers.get(id)
            .then(response => {
                if (response.success && response.data) {
                    this.fillForm(response.data);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات التحويل'
                });
            });
    },
    
    /**
     * Fill form for edit
     */
    fillForm: function(data) {
        const transfer = data.transfer;
        const items = data.items || [];
        
        this.currentId = transfer.id;
        this.items = items.map(item => ({
            product_id: item.product_id,
            product_code: item.product_code,
            product_name: item.product_name,
            quantity: item.quantity,
            unit_cost: item.unit_cost
        }));
        
        document.getElementById('transferId').value = transfer.id;
        document.getElementById('transferFromWarehouse').value = transfer.from_warehouse_id || '';
        document.getElementById('transferToWarehouse').value = transfer.to_warehouse_id || '';
        document.getElementById('transferDate').value = transfer.transfer_date || new Date().toISOString().split('T')[0];
        document.getElementById('transferTime').value = transfer.transfer_time || new Date().toTimeString().slice(0, 5);
        document.getElementById('transferNotes').value = transfer.notes || '';
        
        this.populateWarehouses();
        this.updateItemsTable();
        this.updateTotals();
        
        const modal = new bootstrap.Modal(document.getElementById('transferModal'));
        modal.show();
    },
    
    /**
     * Add item to transfer
     */
    addItem: function() {
        const productId = document.getElementById('transferProductId').value;
        const productName = document.getElementById('transferProductSearch').value;
        const quantity = parseFloat(document.getElementById('transferQuantity').value);
        const unitCost = parseFloat(document.getElementById('transferUnitCost').value);
        
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
        
        document.getElementById('transferProductSearch').value = '';
        document.getElementById('transferProductId').value = '';
        document.getElementById('transferQuantity').value = '';
        document.getElementById('transferUnitCost').value = '';
        
        this.updateItemsTable();
        this.updateTotals();
    },
    
    /**
     * Remove item from transfer
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
        const container = document.getElementById('transferItemsContainer');
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
                        <button class="btn btn-sm btn-danger" onclick="Transfers.removeItem(${index})">
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
        
        document.getElementById('transferTotalItems').textContent = totalItems;
        document.getElementById('transferTotalQuantity').textContent = App.formatNumber(totalQuantity);
        document.getElementById('transferTotalCost').textContent = App.formatCurrency(totalCost);
    },
    
    /**
     * Search product for transfer
     */
    searchProduct: function() {
        const search = document.getElementById('transferProductSearch').value.trim();
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
            <div class="dropdown-item" onclick="Transfers.selectProduct(${p.id}, '${p.name}')">
                <strong>${p.code}</strong> - ${p.name}
                <span class="text-muted">(${App.formatNumber(p.total_quantity || 0)} في المخزن)</span>
            </div>
        `).join('');
        
        const container = document.createElement('div');
        container.className = 'dropdown-menu show';
        container.style.cssText = 'position: absolute; top: 100%; left: 0; right: 0; z-index: 1000;';
        container.innerHTML = suggestions;
        
        const input = document.getElementById('transferProductSearch');
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
        document.getElementById('transferProductId').value = id;
        document.getElementById('transferProductSearch').value = name;
        
        const dropdown = document.querySelector('#transferProductSearch + .dropdown-menu');
        if (dropdown) dropdown.remove();
        
        document.getElementById('transferQuantity').focus();
    },
    
    /**
     * Save transfer
     */
    save: function() {
        const fromWarehouse = document.getElementById('transferFromWarehouse').value;
        const toWarehouse = document.getElementById('transferToWarehouse').value;
        const date = document.getElementById('transferDate').value;
        const time = document.getElementById('transferTime').value;
        const notes = document.getElementById('transferNotes').value;
        
        if (!fromWarehouse) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى اختيار المخزن المصدر' });
            return;
        }
        
        if (!toWarehouse) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى اختيار المخزن الوجهة' });
            return;
        }
        
        if (fromWarehouse === toWarehouse) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'لا يمكن التحويل بين نفس المخزن' });
            return;
        }
        
        if (this.items.length === 0) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يجب إضافة صنف واحد على الأقل' });
            return;
        }
        
        const data = {
            from_warehouse_id: parseInt(fromWarehouse),
            to_warehouse_id: parseInt(toWarehouse),
            transfer_date: date,
            transfer_time: time,
            notes: notes || undefined,
            items: this.items.map(item => ({
                product_id: parseInt(item.product_id),
                quantity: item.quantity,
                unit_cost: item.unit_cost
            }))
        };
        
        const id = document.getElementById('transferId').value;
        const isEdit = id && id !== '';
        
        const saveFn = isEdit ? API.transfers.update(id, data) : API.transfers.create(data);
        
        saveFn
            .then(response => {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'تم الحفظ بنجاح',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    bootstrap.Modal.getInstance(document.getElementById('transferModal')).hide();
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
     * Approve transfer
     */
    approve: function(id) {
        Swal.fire({
            title: 'اعتماد التحويل',
            text: 'هل أنت متأكد من رغبتك في اعتماد هذا التحويل؟',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم، اعتماد',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#28A745'
        }).then(result => {
            if (result.isConfirmed) {
                API.transfers.approve(id)
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
                            text: error.message || 'حدث خطأ في اعتماد التحويل'
                        });
                    });
            }
        });
    },
    
    /**
     * Complete transfer
     */
    complete: function(id) {
        Swal.fire({
            title: 'إكمال التحويل',
            text: 'هل أنت متأكد من رغبتك في إكمال هذا التحويل؟ سيتم تنفيذ الحركات الفعلية.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم، إكمال',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#28A745'
        }).then(result => {
            if (result.isConfirmed) {
                API.transfers.complete(id)
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'تم إكمال التحويل بنجاح',
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
                            text: error.message || 'حدث خطأ في إكمال التحويل'
                        });
                    });
            }
        });
    },
    
    /**
     * Cancel transfer
     */
    cancel: function(id) {
        Swal.fire({
            title: 'إلغاء التحويل',
            text: 'هل أنت متأكد من رغبتك في إلغاء هذا التحويل؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، إلغاء',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#DC3545'
        }).then(result => {
            if (result.isConfirmed) {
                API.transfers.cancel(id)
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
                            text: error.message || 'حدث خطأ في إلغاء التحويل'
                        });
                    });
            }
        });
    }
};

// ================================================================
// Global Functions
// ================================================================

function showCreateTransfer() {
    Transfers.showCreate();
}

function saveTransfer() {
    Transfers.save();
}

function searchTransferProduct() {
    Transfers.searchProduct();
}

function addTransferItem() {
    Transfers.addItem();
}

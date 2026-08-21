// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/issues.js
// الوصف: إدارة إذون الصرف
// الإصدار: 2.0 Production Ready
// التاريخ: 2026-08-20
// ================================================================

const Issues = {
    // DataTable instance
    table: null,
    
    // Cache data
    warehouses: [],
    recipients: [],
    products: [],
    
    // Current issue items
    items: [],
    
    // Current issue ID for editing
    currentId: null,
    
    /**
     * Load issues page
     */
    load: function() {
        this.loadTable();
        this.loadSelectData();
        this.setupEvents();
    },
    
    /**
     * Load issues table
     */
    loadTable: function() {
        const table = document.getElementById('issuesTable');
        if (!table) return;
        
        if (this.table) {
            this.table.destroy();
        }
        
        const status = document.getElementById('issuesStatus')?.value || '';
        const warehouse = document.getElementById('issuesWarehouse')?.value || '';
        const fromDate = document.getElementById('issuesFromDate')?.value || '';
        const toDate = document.getElementById('issuesToDate')?.value || '';
        
        this.table = $(table).DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: API.baseUrl + '/issues',
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
                { data: 'issue_no' },
                { data: 'warehouse_name', defaultContent: '-' },
                { data: 'recipient_name', defaultContent: '-' },
                { 
                    data: 'issue_date',
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
                                <button class="btn btn-primary" onclick="Issues.show(${data.id})">
                                    <i class="fas fa-eye"></i>
                                </button>
                        `;
                        
                        if (data.status === 'draft') {
                            buttons += `
                                <button class="btn btn-warning" onclick="Issues.edit(${data.id})">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-success" onclick="Issues.approve(${data.id})">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-danger" onclick="Issues.cancel(${data.id})">
                                    <i class="fas fa-times"></i>
                                </button>
                            `;
                        } else if (data.status === 'approved') {
                            buttons += `
                                <button class="btn btn-primary" onclick="Issues.deliver(${data.id})">
                                    <i class="fas fa-truck"></i>
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
        
        // Load recipients
        this.loadRecipients();
    },
    
    /**
     * Load recipients
     */
    loadRecipients: function() {
        // This would be an API call to get recipients
        API.get('/recipients')
            .then(response => {
                if (response.success) {
                    this.recipients = response.data || [];
                    this.populateRecipients();
                }
            })
            .catch(() => {
                // Fallback
                this.recipients = [
                    { id: 1, name: 'وزارة التعليم' },
                    { id: 2, name: 'المستشفى العام' },
                    { id: 3, name: 'جامعة الملك سعود' }
                ];
                this.populateRecipients();
            });
    },
    
    /**
     * Populate warehouse selects
     */
    populateWarehouses: function() {
        const selects = document.querySelectorAll('#issueWarehouse, #issuesWarehouse');
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
     * Populate recipient selects
     */
    populateRecipients: function() {
        const selects = document.querySelectorAll('#issueRecipient');
        selects.forEach(select => {
            const currentValue = select.value;
            select.innerHTML = '<option value="">اختر المستلم</option>';
            this.recipients.forEach(r => {
                const option = document.createElement('option');
                option.value = r.id;
                option.textContent = r.name;
                if (r.id == currentValue) {
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
        document.getElementById('issuesStatus')?.addEventListener('change', () => this.loadTable());
        document.getElementById('issuesWarehouse')?.addEventListener('change', () => this.loadTable());
        document.getElementById('issuesFromDate')?.addEventListener('change', () => this.loadTable());
        document.getElementById('issuesToDate')?.addEventListener('change', () => this.loadTable());
        
        document.getElementById('issuesSearch')?.addEventListener('keyup', debounce(() => {
            this.loadTable();
        }, 500));
    },
    
    /**
     * Show issue details
     */
    show: function(id) {
        API.issues.get(id)
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
        const issue = data.issue;
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
                    <td>${App.formatNumber(item.current_balance || 0)}</td>
                </tr>
            `;
        });
        
        Swal.fire({
            title: `إذن صرف #${issue.issue_no}`,
            html: `
                <div class="text-start">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>المخزن:</strong> ${issue.warehouse_name || '-'}</p>
                            <p><strong>المستلم:</strong> ${issue.recipient_name || '-'}</p>
                            <p><strong>التاريخ:</strong> ${App.formatDate(issue.issue_date)}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>الحالة:</strong> ${App.getStatusBadge(issue.status)}</p>
                            <p><strong>الكمية الإجمالية:</strong> ${App.formatNumber(issue.total_quantity)}</p>
                            <p><strong>القيمة الإجمالية:</strong> ${App.formatCurrency(issue.total_cost)}</p>
                        </div>
                    </div>
                    
                    ${issue.notes ? `<p><strong>ملاحظات:</strong> ${issue.notes}</p>` : ''}
                    
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
                                    <th>الرصيد الحالي</th>
                                </tr>
                            </thead>
                            <tbody>${itemsHtml || '<tr><td colspan="7" class="text-center">لا توجد أصناف</td></tr>'}</tbody>
                        </table>
                    </div>
                </div>
            `,
            width: '850px',
            confirmButtonText: 'إغلاق'
        });
    },
    
    /**
     * Show create issue modal
     */
    showCreate: function() {
        this.currentId = null;
        this.items = [];
        this.resetForm();
        this.populateWarehouses();
        this.populateRecipients();
        this.updateItemsTable();
        
        const modal = new bootstrap.Modal(document.getElementById('issueModal'));
        modal.show();
    },
    
    /**
     * Reset form
     */
    resetForm: function() {
        document.getElementById('issueId').value = '';
        document.getElementById('issueWarehouse').value = '';
        document.getElementById('issueRecipient').value = '';
        document.getElementById('issueDate').value = new Date().toISOString().split('T')[0];
        document.getElementById('issueTime').value = new Date().toTimeString().slice(0, 5);
        document.getElementById('issueNotes').value = '';
        document.getElementById('issueProductSearch').value = '';
        document.getElementById('issueProductId').value = '';
        document.getElementById('issueQuantity').value = '';
        document.getElementById('issueUnitCost').value = '';
        document.getElementById('issueItemsContainer').innerHTML = '';
        this.items = [];
        this.updateTotals();
    },
    
    /**
     * Edit issue
     */
    edit: function(id) {
        API.issues.get(id)
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
        const issue = data.issue;
        const items = data.items || [];
        
        this.currentId = issue.id;
        this.items = items.map(item => ({
            product_id: item.product_id,
            product_code: item.product_code,
            product_name: item.product_name,
            quantity: item.quantity,
            unit_cost: item.unit_cost
        }));
        
        document.getElementById('issueId').value = issue.id;
        document.getElementById('issueWarehouse').value = issue.warehouse_id || '';
        document.getElementById('issueRecipient').value = issue.recipient_id || '';
        document.getElementById('issueDate').value = issue.issue_date || new Date().toISOString().split('T')[0];
        document.getElementById('issueTime').value = issue.issue_time || new Date().toTimeString().slice(0, 5);
        document.getElementById('issueNotes').value = issue.notes || '';
        
        this.populateWarehouses();
        this.populateRecipients();
        this.updateItemsTable();
        this.updateTotals();
        
        const modal = new bootstrap.Modal(document.getElementById('issueModal'));
        modal.show();
    },
    
    /**
     * Add item to issue
     */
    addItem: function() {
        const productId = document.getElementById('issueProductId').value;
        const productName = document.getElementById('issueProductSearch').value;
        const quantity = parseFloat(document.getElementById('issueQuantity').value);
        const unitCost = parseFloat(document.getElementById('issueUnitCost').value);
        
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
        document.getElementById('issueProductSearch').value = '';
        document.getElementById('issueProductId').value = '';
        document.getElementById('issueQuantity').value = '';
        document.getElementById('issueUnitCost').value = '';
        
        this.updateItemsTable();
        this.updateTotals();
    },
    
    /**
     * Remove item from issue
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
        const container = document.getElementById('issueItemsContainer');
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
                        <button class="btn btn-sm btn-danger" onclick="Issues.removeItem(${index})">
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
        
        document.getElementById('issueTotalItems').textContent = totalItems;
        document.getElementById('issueTotalQuantity').textContent = App.formatNumber(totalQuantity);
        document.getElementById('issueTotalCost').textContent = App.formatCurrency(totalCost);
    },
    
    /**
     * Search product for issue
     */
    searchProduct: function() {
        const search = document.getElementById('issueProductSearch').value.trim();
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
            <div class="dropdown-item" onclick="Issues.selectProduct(${p.id}, '${p.name}')">
                <strong>${p.code}</strong> - ${p.name}
                <span class="text-muted">(${App.formatNumber(p.total_quantity || 0)} في المخزن)</span>
            </div>
        `).join('');
        
        const container = document.createElement('div');
        container.className = 'dropdown-menu show';
        container.style.cssText = 'position: absolute; top: 100%; left: 0; right: 0; z-index: 1000;';
        container.innerHTML = suggestions;
        
        const input = document.getElementById('issueProductSearch');
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
        document.getElementById('issueProductId').value = id;
        document.getElementById('issueProductSearch').value = name;
        
        const dropdown = document.querySelector('#issueProductSearch + .dropdown-menu');
        if (dropdown) dropdown.remove();
        
        document.getElementById('issueQuantity').focus();
    },
    
    /**
     * Save issue
     */
    save: function() {
        const warehouseId = document.getElementById('issueWarehouse').value;
        const recipientId = document.getElementById('issueRecipient').value;
        const date = document.getElementById('issueDate').value;
        const time = document.getElementById('issueTime').value;
        const notes = document.getElementById('issueNotes').value;
        
        if (!warehouseId) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى اختيار المخزن' });
            return;
        }
        
        if (!recipientId) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى اختيار المستلم' });
            return;
        }
        
        if (this.items.length === 0) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يجب إضافة صنف واحد على الأقل' });
            return;
        }
        
        const data = {
            warehouse_id: parseInt(warehouseId),
            recipient_id: parseInt(recipientId),
            issue_date: date,
            issue_time: time,
            notes: notes || undefined,
            items: this.items.map(item => ({
                product_id: parseInt(item.product_id),
                quantity: item.quantity,
                unit_cost: item.unit_cost
            }))
        };
        
        const id = document.getElementById('issueId').value;
        const isEdit = id && id !== '';
        
        const saveFn = isEdit ? API.issues.update(id, data) : API.issues.create(data);
        
        saveFn
            .then(response => {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'تم الحفظ بنجاح',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    bootstrap.Modal.getInstance(document.getElementById('issueModal')).hide();
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
     * Approve issue
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
                API.issues.approve(id)
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
     * Deliver issue
     */
    deliver: function(id) {
        Swal.fire({
            title: 'تسليم الإذن',
            text: 'هل أنت متأكد من رغبتك في تسليم هذا الإذن؟',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم، تسليم',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#28A745'
        }).then(result => {
            if (result.isConfirmed) {
                API.issues.deliver(id)
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'تم التسليم بنجاح',
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
                            text: error.message || 'حدث خطأ في تسليم الإذن'
                        });
                    });
            }
        });
    },
    
    /**
     * Cancel issue
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
                API.issues.cancel(id)
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

function showCreateIssue() {
    Issues.showCreate();
}

function saveIssue() {
    Issues.save();
}

function searchIssueProduct() {
    Issues.searchProduct();
}

function addIssueItem() {
    Issues.addItem();
}

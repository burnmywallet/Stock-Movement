// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/users.js
// الوصف: إدارة المستخدمين - CRUD كامل مع صلاحيات
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

/**
 * إدارة المستخدمين - جميع العمليات
 */
const Users = {
    // ================================================================
    // الحالة
    // ================================================================
    
    data: [],
    roles: [],
    currentId: null,
    permissionUserId: null,
    
    // ================================================================
    // التحميل
    // ================================================================
    
    /**
     * تحميل صفحة المستخدمين
     */
    load: function() {
        this.loadList();
        this.loadRoles();
        this.setupEvents();
    },
    
    /**
     * تحميل قائمة المستخدمين
     */
    loadList: function() {
        const container = document.getElementById('usersContainer');
        if (!container) return;
        
        container.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">جاري التحميل...</span>
                </div>
                <p class="mt-2 text-muted">جاري تحميل المستخدمين...</p>
            </div>
        `;
        
        const params = {
            search: document.getElementById('searchUser')?.value || '',
            role: document.getElementById('filterRole')?.value || '',
            status: document.getElementById('filterStatus')?.value || '',
            department: document.getElementById('filterDepartment')?.value || ''
        };
        
        API.users.list(params)
            .then(response => {
                if (response.success && response.data) {
                    this.data = response.data;
                    this.renderList(response);
                }
            })
            .catch(error => {
                console.error('Error loading users:', error);
                container.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-exclamation-circle fa-2x mb-2 text-danger"></i>
                        <p>حدث خطأ في تحميل المستخدمين</p>
                        <button class="btn btn-primary btn-sm mt-2" onclick="Users.loadList()">
                            <i class="fas fa-sync me-1"></i> إعادة المحاولة
                        </button>
                    </div>
                `;
            });
    },
    
    /**
     * تحميل الأدوار
     */
    loadRoles: function() {
        API.get('/roles')
            .then(response => {
                if (response.success) {
                    this.roles = response.data || [];
                    this.populateRoleSelects();
                }
            })
            .catch(error => console.error('Error loading roles:', error));
    },
    
    /**
     * تعبئة قوائم الأدوار
     */
    populateRoleSelects: function() {
        const selects = document.querySelectorAll('#uRole, #filterRole');
        selects.forEach(select => {
            if (!select) return;
            const currentValue = select.value;
            select.innerHTML = '<option value="">اختر الدور</option>';
            this.roles.forEach(role => {
                const option = document.createElement('option');
                option.value = role.id;
                option.textContent = role.display_name || role.name;
                if (role.id == currentValue) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        });
    },
    
    /**
     * عرض قائمة المستخدمين
     */
    renderList: function(response) {
        const container = document.getElementById('usersContainer');
        if (!container) return;
        
        const users = response.data || [];
        const stats = response.stats || {};
        
        // تحديث الإحصائيات
        this.updateStats(stats);
        
        if (users.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-5">
                    <i class="fas fa-users fa-3x mb-3" style="opacity:0.3;"></i>
                    <h3 style="color:rgba(255,255,255,0.3);">لا توجد مستخدمين</h3>
                    <p style="color:rgba(255,255,255,0.15);">قم بإضافة مستخدم جديد باستخدام الزر أعلاه</p>
                    <button class="btn btn-primary mt-2" onclick="Users.showCreate()">
                        <i class="fas fa-user-plus me-1"></i> إضافة مستخدم
                    </button>
                </div>
            `;
            return;
        }
        
        let html = '';
        users.forEach(u => {
            const statusClass = u.is_active ? 'active' : (u.is_locked ? 'locked' : 'inactive');
            const statusLabel = u.is_active ? 'نشط' : (u.is_locked ? 'مقفل' : 'غير نشط');
            const roleName = this.roles.find(r => r.id == u.role_id)?.display_name || u.role_name || '---';
            
            html += `
                <div class="col-xl-4 col-lg-6 col-md-6 col-sm-12">
                    <div class="card user-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="avatar-big me-3">
                                    ${(u.full_name || u.username || 'م')[0]}
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-0">${u.full_name}</h5>
                                    <div class="text-muted small">@${u.username}</div>
                                </div>
                                <span class="badge ${statusClass}">${statusLabel}</span>
                            </div>
                            
                            <div class="info-grid">
                                <div class="item">
                                    <span class="label">البريد</span>
                                    <span class="value">${u.email || '---'}</span>
                                </div>
                                <div class="item">
                                    <span class="label">الدور</span>
                                    <span class="value">${roleName}</span>
                                </div>
                                <div class="item">
                                    <span class="label">القسم</span>
                                    <span class="value">${u.department || '---'}</span>
                                </div>
                                <div class="item">
                                    <span class="label">آخر تسجيل</span>
                                    <span class="value">${u.last_login_at ? App.formatDate(u.last_login_at) : '---'}</span>
                                </div>
                                <div class="item">
                                    <span class="label">الجلسات النشطة</span>
                                    <span class="value">${u.active_sessions || 0}</span>
                                </div>
                                <div class="item">
                                    <span class="label">النشاط (7 أيام)</span>
                                    <span class="value">${u.activities_7d || 0}</span>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn btn-sm btn-info" onclick="Users.view(${u.id})">
                                        <i class="fas fa-eye"></i> عرض
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="Users.edit(${u.id})">
                                        <i class="fas fa-edit"></i> تعديل
                                    </button>
                                    <button class="btn btn-sm btn-purple" onclick="Users.managePermissions(${u.id})">
                                        <i class="fas fa-key"></i> صلاحيات
                                    </button>
                                    ${u.is_locked ? `
                                        <button class="btn btn-sm btn-success" onclick="Users.unlockUser(${u.id})">
                                            <i class="fas fa-unlock"></i> فتح
                                        </button>
                                    ` : `
                                        <button class="btn btn-sm btn-warning" onclick="Users.lockUser(${u.id})">
                                            <i class="fas fa-lock"></i> قفل
                                        </button>
                                    `}
                                    <button class="btn btn-sm btn-danger" onclick="Users.deleteUser(${u.id})">
                                        <i class="fas fa-trash"></i> حذف
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
    
    // ================================================================
    // الأحداث
    // ================================================================
    
    /**
     * إعداد الأحداث
     */
    setupEvents: function() {
        // البحث
        document.getElementById('searchUser')?.addEventListener('keyup', debounce(() => {
            this.loadList();
        }, 500));
        
        // فلترة الدور
        document.getElementById('filterRole')?.addEventListener('change', () => {
            this.loadList();
        });
        
        // فلترة الحالة
        document.getElementById('filterStatus')?.addEventListener('change', () => {
            this.loadList();
        });
        
        // فلترة القسم
        document.getElementById('filterDepartment')?.addEventListener('change', () => {
            this.loadList();
        });
    },
    
    // ================================================================
    // عرض وتفاصيل
    // ================================================================
    
    /**
     * عرض تفاصيل المستخدم
     */
    view: function(id) {
        API.users.get(id)
            .then(response => {
                if (response.success && response.data) {
                    this.showDetailsModal(response.data);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات المستخدم'
                });
            });
    },
    
    /**
     * عرض نافذة التفاصيل
     */
    showDetailsModal: function(data) {
        const user = data.user;
        const permissions = data.permissions || [];
        const sessions = data.sessions || [];
        const activities = data.recent_activities || [];
        
        let sessionHtml = '';
        if (sessions.length > 0) {
            sessionHtml = sessions.map(s => `
                <div class="activity-item">
                    <div class="icon ${s.is_active ? 'green' : 'secondary'}">
                        <i class="fas fa-${s.is_active ? 'circle' : 'circle'}"></i>
                    </div>
                    <div class="content">
                        <div class="text">
                            <strong>${s.device_name || 'جهاز غير معروف'}</strong>
                            <span style="font-size:11px;color:rgba(255,255,255,0.3);">
                                ${s.ip_address || ''}
                            </span>
                        </div>
                        <div class="time">${App.formatDate(s.login_at)}</div>
                    </div>
                </div>
            `).join('');
        } else {
            sessionHtml = '<div class="text-center text-muted py-2">لا توجد جلسات نشطة</div>';
        }
        
        let activityHtml = '';
        if (activities.length > 0) {
            activityHtml = activities.slice(0, 10).map(a => `
                <div class="activity-item">
                    <div class="icon ${a.type || 'secondary'}">
                        <i class="fas fa-${a.action === 'LOGIN_SUCCESS' ? 'sign-in-alt' : a.action === 'LOGOUT' ? 'sign-out-alt' : a.action.includes('CREATE') ? 'plus' : a.action.includes('UPDATE') ? 'edit' : a.action.includes('DELETE') ? 'trash' : 'circle'}"></i>
                    </div>
                    <div class="content">
                        <div class="text">${a.description || a.action}</div>
                        <div class="time">${App.formatDate(a.created_at)}</div>
                    </div>
                </div>
            `).join('');
        } else {
            activityHtml = '<div class="text-center text-muted py-2">لا توجد نشاطات</div>';
        }
        
        Swal.fire({
            title: user.full_name,
            width: '900px',
            showCloseButton: true,
            showConfirmButton: false,
            html: `
                <div class="text-start" style="direction:rtl;">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>اسم المستخدم:</strong> ${user.username}</p>
                            <p><strong>البريد الإلكتروني:</strong> ${user.email || '---'}</p>
                            <p><strong>الدور:</strong> ${user.role_display || user.role_name}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>القسم:</strong> ${user.department || '---'}</p>
                            <p><strong>الحالة:</strong> ${user.is_active ? 'نشط' : 'غير نشط'}</p>
                            <p><strong>موثق:</strong> ${user.is_verified ? 'نعم' : 'لا'}</p>
                        </div>
                    </div>
                    
                    <h6 class="mt-3">الصلاحيات</h6>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;padding:8px 0;">
                        ${permissions.length > 0 ? permissions.map(p => `<span class="badge bg-primary">${p}</span>`).join('') : '<span class="text-muted">لا توجد صلاحيات</span>'}
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <h6>الجلسات النشطة</h6>
                            <div style="max-height:150px;overflow-y:auto;">${sessionHtml}</div>
                        </div>
                        <div class="col-md-6">
                            <h6>آخر النشاطات</h6>
                            <div style="max-height:150px;overflow-y:auto;">${activityHtml}</div>
                        </div>
                    </div>
                </div>
            `
        });
    },
    
    // ================================================================
    // إنشاء وتعديل
    // ================================================================
    
    /**
     * عرض نافذة إنشاء مستخدم
     */
    showCreate: function() {
        this.currentId = null;
        this.resetForm();
        this.populateRoleSelects();
        
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> إضافة مستخدم جديد';
        document.getElementById('passRequired').textContent = '*';
        document.getElementById('uPassword').required = true;
        
        const modal = new bootstrap.Modal(document.getElementById('userModal'));
        modal.show();
    },
    
    /**
     * تعديل مستخدم
     */
    edit: function(id) {
        API.users.get(id)
            .then(response => {
                if (response.success && response.data.user) {
                    this.fillForm(response.data.user);
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في جلب بيانات المستخدم'
                });
            });
    },
    
    /**
     * إعادة تعيين النموذج
     */
    resetForm: function() {
        document.getElementById('userForm').reset();
        document.getElementById('userId').value = '';
        document.getElementById('uPassword').value = '';
        document.getElementById('uActive').checked = true;
        document.getElementById('uVerified').checked = false;
    },
    
    /**
     * تعبئة النموذج للتعديل
     */
    fillForm: function(user) {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-edit"></i> تعديل مستخدم';
        document.getElementById('userId').value = user.id;
        document.getElementById('uUsername').value = user.username || '';
        document.getElementById('uFullName').value = user.full_name || '';
        document.getElementById('uEmail').value = user.email || '';
        document.getElementById('uRole').value = user.role_id || '';
        document.getElementById('uDepartment').value = user.department || '';
        document.getElementById('uPhone').value = user.phone || '';
        document.getElementById('uMobile').value = user.mobile || '';
        document.getElementById('uEmployeeId').value = user.employee_id || '';
        document.getElementById('uActive').checked = user.is_active !== 0;
        document.getElementById('uVerified').checked = user.is_verified === 1;
        document.getElementById('passRequired').textContent = '(اختياري)';
        document.getElementById('uPassword').required = false;
        
        this.populateRoleSelects();
        
        const modal = new bootstrap.Modal(document.getElementById('userModal'));
        modal.show();
    },
    
    /**
     * حفظ المستخدم
     */
    save: function() {
        const data = {
            username: document.getElementById('uUsername').value.trim(),
            full_name: document.getElementById('uFullName').value.trim(),
            email: document.getElementById('uEmail').value.trim(),
            password: document.getElementById('uPassword').value,
            role_id: parseInt(document.getElementById('uRole').value),
            department: document.getElementById('uDepartment').value.trim() || null,
            phone: document.getElementById('uPhone').value.trim() || null,
            mobile: document.getElementById('uMobile').value.trim() || null,
            employee_id: document.getElementById('uEmployeeId').value.trim() || null,
            is_active: document.getElementById('uActive').checked ? 1 : 0,
            is_verified: document.getElementById('uVerified').checked ? 1 : 0
        };
        
        if (!data.username || !data.full_name || !data.email) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يرجى ملء الحقول المطلوبة'
            });
            return;
        }
        
        if (!data.role_id) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'يرجى اختيار الدور'
            });
            return;
        }
        
        const id = document.getElementById('userId').value;
        const isEdit = id && id !== '';
        
        if (!isEdit && !data.password) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'كلمة المرور مطلوبة للمستخدم الجديد'
            });
            return;
        }
        
        if (data.password && data.password.length < 6) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'كلمة المرور يجب أن تكون 6 أحرف على الأقل'
            });
            return;
        }
        
        const saveFn = isEdit ? API.users.update(id, data) : API.users.create(data);
        
        saveFn
            .then(response => {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'تم الحفظ بنجاح',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
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
    // الصلاحيات
    // ================================================================
    
    /**
     * إدارة صلاحيات المستخدم
     */
    managePermissions: function(id) {
        this.permissionUserId = id;
        const modal = document.getElementById('permissionModal');
        modal.classList.add('active');
        
        const container = document.getElementById('permissionContent');
        container.innerHTML = `
            <div style="text-align:center;padding:20px;color:rgba(255,255,255,0.2);">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p class="mt-2">جاري تحميل الصلاحيات...</p>
            </div>
        `;
        
        API.users.getPermissions(id)
            .then(response => {
                if (response.success) {
                    this.renderPermissions(response.data);
                }
            })
            .catch(error => {
                container.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-exclamation-circle fa-2x mb-2 text-danger"></i>
                        <p>حدث خطأ في تحميل الصلاحيات</p>
                    </div>
                `;
            });
    },
    
    /**
     * عرض الصلاحيات
     */
    renderPermissions: function(data) {
        const container = document.getElementById('permissionContent');
        const user = data.user || {};
        const permissions = data.permissions || [];
        const allPermissions = data.all_permissions || [];
        
        // تجميع الصلاحيات حسب الوحدة
        const grouped = {};
        allPermissions.forEach(p => {
            if (!grouped[p.module]) {
                grouped[p.module] = [];
            }
            grouped[p.module].push(p);
        });
        
        let html = `
            <div class="mb-3">
                <p><strong>المستخدم:</strong> ${user.full_name || user.username}</p>
                <p><strong>الدور:</strong> ${user.role || '---'}</p>
            </div>
            <div style="max-height:400px;overflow-y:auto;padding:10px;background:rgba(255,255,255,0.02);border-radius:10px;">
        `;
        
        Object.keys(grouped).forEach(module => {
            html += `<div class="mb-2"><strong style="color:#667eea;">${module}</strong></div>`;
            html += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;padding-right:10px;margin-bottom:10px;">`;
            
            grouped[module].forEach(p => {
                const checked = permissions.includes(p.name) ? 'checked' : '';
                html += `
                    <label class="checkbox-group" style="font-size:13px;padding:4px 0;">
                        <input type="checkbox" class="perm-check" value="${p.name}" ${checked}>
                        ${p.display_name}
                    </label>
                `;
            });
            
            html += `</div>`;
        });
        
        html += `</div>`;
        container.innerHTML = html;
    },
    
    /**
     * حفظ الصلاحيات
     */
    savePermissions: function() {
        if (!this.permissionUserId) {
            Swal.fire({
                icon: 'warning',
                title: 'تنبيه',
                text: 'لم يتم تحديد المستخدم'
            });
            return;
        }
        
        const checkboxes = document.querySelectorAll('.perm-check');
        const permissions = [];
        checkboxes.forEach(cb => {
            if (cb.checked) {
                permissions.push(cb.value);
            }
        });
        
        API.users.permissions(this.permissionUserId, { permissions })
            .then(response => {
                if (response.success) {
                    document.getElementById('permissionModal').classList.remove('active');
                    Swal.fire({
                        icon: 'success',
                        title: 'تم حفظ الصلاحيات بنجاح',
                        timer: 1500,
                        showConfirmButton: false
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: error.message || 'حدث خطأ في حفظ الصلاحيات'
                });
            });
    },
    
    // ================================================================
    // قفل وفتح
    // ================================================================
    
    /**
     * قفل مستخدم
     */
    lockUser: function(id) {
        Swal.fire({
            title: 'تأكيد القفل',
            text: 'هل أنت متأكد من رغبتك في قفل هذا المستخدم؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، قفل',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#ffc107'
        }).then(result => {
            if (result.isConfirmed) {
                API.users.lock(id)
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'تم قفل المستخدم بنجاح',
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
                            text: error.message || 'حدث خطأ في قفل المستخدم'
                        });
                    });
            }
        });
    },
    
    /**
     * فتح قفل المستخدم
     */
    unlockUser: function(id) {
        Swal.fire({
            title: 'تأكيد الفتح',
            text: 'هل أنت متأكد من رغبتك في فتح قفل هذا المستخدم؟',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم، فتح',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#28a745'
        }).then(result => {
            if (result.isConfirmed) {
                API.users.unlock(id)
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'تم فتح قفل المستخدم بنجاح',
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
                            text: error.message || 'حدث خطأ في فتح قفل المستخدم'
                        });
                    });
            }
        });
    },
    
    // ================================================================
    // حذف
    // ================================================================
    
    /**
     * حذف مستخدم
     */
    deleteUser: function(id) {
        Swal.fire({
            title: 'تأكيد الحذف',
            text: 'هل أنت متأكد من رغبتك في حذف هذا المستخدم؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، حذف',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#DC3545'
        }).then(result => {
            if (result.isConfirmed) {
                API.users.delete(id)
                    .then(response => {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'تم حذف المستخدم بنجاح',
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
                            text: error.message || 'حدث خطأ في حذف المستخدم'
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
        
        // تحديث شارة المستخدمين في القائمة الجانبية
        const badge = document.querySelector('.sidebar-menu a[href="#users"] .badge');
        if (badge) {
            badge.textContent = stats.total || 0;
        }
        
        // تحديث عدد المستخدمين في رأس الصفحة
        const countEl = document.getElementById('usersCount');
        if (countEl) {
            countEl.textContent = stats.total || 0;
        }
    }
};

// ================================================================
// دوال عامة للاستخدام من HTML
// ================================================================

function showCreateUser() {
    Users.showCreate();
}

function saveUser() {
    Users.save();
}

function savePermissions() {
    Users.savePermissions();
}

// ================================================================
// تصدير Users
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = Users;
}

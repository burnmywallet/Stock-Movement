// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/settings.js
// الوصف: إدارة إعدادات النظام والنسخ الاحتياطي
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

/**
 * إدارة الإعدادات - جميع عمليات التكوين
 */
const Settings = {
    // ================================================================
    // الحالة
    // ================================================================
    
    settings: {},
    backups: [],
    activeTab: 'general',
    
    // ================================================================
    // التحميل
    // ================================================================
    
    /**
     * تحميل صفحة الإعدادات
     */
    load: function() {
        this.loadSettings();
        this.loadBackupList();
        this.setupEvents();
        this.loadActiveTab();
        this.updateVersionInfo();
    },
    
    /**
     * تحميل الإعدادات
     */
    loadSettings: function() {
        API.settings.list()
            .then(response => {
                if (response.success) {
                    this.settings = response.data || {};
                    this.populateSettings();
                }
            })
            .catch(error => {
                console.error('Error loading settings:', error);
                App.showToast('حدث خطأ في تحميل الإعدادات', 'error');
            });
    },
    
    /**
     * تعبئة الإعدادات في النموذج
     */
    populateSettings: function() {
        const settings = this.settings;
        
        // إعدادات عامة
        document.getElementById('companyName').value = settings.company_name || '';
        document.getElementById('companyEmail').value = settings.company_email || '';
        document.getElementById('companyPhone').value = settings.company_phone || '';
        document.getElementById('companyAddress').value = settings.company_address || '';
        document.getElementById('companyTaxNumber').value = settings.company_tax_number || '';
        document.getElementById('currency').value = settings.currency || 'SAR';
        document.getElementById('timezone').value = settings.timezone || 'Asia/Riyadh';
        document.getElementById('dateFormat').value = settings.date_format || 'Y-m-d';
        document.getElementById('timeFormat').value = settings.time_format || 'H:i:s';
        
        // إعدادات المخزون
        document.getElementById('defaultWarehouse').value = settings.default_warehouse || '';
        document.getElementById('lowStockThreshold').value = settings.low_stock_threshold || 10;
        document.getElementById('autoReorder').checked = settings.auto_reorder === 'true';
        document.getElementById('negativeStockAllowed').checked = settings.negative_stock_allowed === 'true';
        
        // إعدادات الأمان
        document.getElementById('singleSession').checked = settings.single_session_enabled === 'true';
        document.getElementById('sessionTimeout').value = settings.session_timeout || 3600;
        document.getElementById('maxLoginAttempts').value = settings.max_login_attempts || 5;
        document.getElementById('lockoutDuration').value = settings.lockout_duration || 30;
        document.getElementById('passwordExpiryDays').value = settings.password_expiry_days || 90;
        document.getElementById('forceSsl').checked = settings.force_ssl === 'true';
        
        // إعدادات النسخ الاحتياطي
        document.getElementById('autoBackupEnabled').checked = settings.auto_backup_enabled === 'true';
        document.getElementById('autoBackupTime').value = settings.auto_backup_time || '23:00';
        document.getElementById('backupRetentionDays').value = settings.backup_retention_days || 30;
        document.getElementById('backupPath').value = settings.backup_path || '/var/backups/inventory/';
        document.getElementById('backupCompress').checked = settings.backup_compress === 'true';
        
        // إعدادات التقارير
        document.getElementById('reportLogo').value = settings.report_logo || '';
        document.getElementById('reportFooter').value = settings.report_footer || '';
        document.getElementById('reportPageSize').value = settings.report_page_size || 'A4';
    },
    
    /**
     * تحميل قائمة النسخ الاحتياطي
     */
    loadBackupList: function() {
        API.backup.list()
            .then(response => {
                if (response.success) {
                    this.backups = response.data || [];
                    this.renderBackupList();
                }
            })
            .catch(error => {
                console.error('Error loading backups:', error);
            });
    },
    
    /**
     * عرض قائمة النسخ الاحتياطي
     */
    renderBackupList: function() {
        const container = document.getElementById('backupList');
        if (!container) return;
        
        if (this.backups.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-database fa-2x mb-2" style="opacity:0.3;"></i>
                    <p>لا توجد نسخ احتياطية</p>
                </div>
            `;
            return;
        }
        
        let html = `
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>الملف</th>
                        <th>الحجم</th>
                        <th>النوع</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        this.backups.forEach(backup => {
            const statusColors = {
                'completed': 'success',
                'running': 'warning',
                'failed': 'danger',
                'pending': 'secondary',
                'restored': 'info'
            };
            
            const statusLabels = {
                'completed': 'مكتمل',
                'running': 'قيد التشغيل',
                'failed': 'فشل',
                'pending': 'معلق',
                'restored': 'تم الاستعادة'
            };
            
            const typeLabels = {
                'manual': 'يدوي',
                'automatic': 'تلقائي',
                'pre_restore': 'قبل الاستعادة'
            };
            
            html += `
                <tr>
                    <td>${App.formatDate(backup.created_at)}</td>
                    <td><span style="font-size:12px;color:rgba(255,255,255,0.5);">${backup.backup_file || '-'}</span></td>
                    <td>${this.formatFileSize(backup.file_size || 0)}</td>
                    <td>${typeLabels[backup.backup_type] || backup.backup_type}</td>
                    <td><span class="badge bg-${statusColors[backup.status] || 'secondary'}">${statusLabels[backup.status] || backup.status}</span></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            ${backup.status === 'completed' ? `
                                <button class="btn btn-sm btn-primary" onclick="Settings.downloadBackup(${backup.id})" title="تحميل">
                                    <i class="fas fa-download"></i>
                                </button>
                                <button class="btn btn-sm btn-warning" onclick="Settings.restoreBackup(${backup.id})" title="استعادة">
                                    <i class="fas fa-undo"></i>
                                </button>
                            ` : ''}
                            <button class="btn btn-sm btn-danger" onclick="Settings.deleteBackup(${backup.id})" title="حذف">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        
        html += '</tbody></table>';
        container.innerHTML = html;
    },
    
    // ================================================================
    // الأحداث
    // ================================================================
    
    /**
     * إعداد الأحداث
     */
    setupEvents: function() {
        // علامات التبويب
        document.querySelectorAll('.settings-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                Settings.switchTab(tabName);
            });
        });
        
        // حفظ الإعدادات العامة
        document.getElementById('saveGeneralSettings')?.addEventListener('click', () => {
            this.saveGeneralSettings();
        });
        
        // حفظ إعدادات المخزون
        document.getElementById('saveInventorySettings')?.addEventListener('click', () => {
            this.saveInventorySettings();
        });
        
        // حفظ إعدادات الأمان
        document.getElementById('saveSecuritySettings')?.addEventListener('click', () => {
            this.saveSecuritySettings();
        });
        
        // حفظ إعدادات النسخ الاحتياطي
        document.getElementById('saveBackupSettings')?.addEventListener('click', () => {
            this.saveBackupSettings();
        });
        
        // حفظ إعدادات التقارير
        document.getElementById('saveReportSettings')?.addEventListener('click', () => {
            this.saveReportSettings();
        });
        
        // إنشاء نسخة احتياطية
        document.getElementById('createBackupBtn')?.addEventListener('click', () => {
            this.createBackup();
        });
        
        // استعادة نسخة
        document.getElementById('restoreBackupBtn')?.addEventListener('click', () => {
            this.restoreBackup();
        });
        
        // تغيير كلمة المرور
        document.getElementById('changePasswordBtn')?.addEventListener('click', () => {
            this.changePassword();
        });
        
        // تنظيف السجلات
        document.getElementById('cleanupLogsBtn')?.addEventListener('click', () => {
            this.cleanupLogs();
        });
    },
    
    /**
     * تحميل علامة التبويب النشطة
     */
    loadActiveTab: function() {
        const saved = localStorage.getItem('settings_active_tab') || 'general';
        this.switchTab(saved);
    },
    
    /**
     * تبديل علامة التبويب
     */
    switchTab: function(tabName) {
        this.activeTab = tabName;
        localStorage.setItem('settings_active_tab', tabName);
        
        // تحديث الأزرار
        document.querySelectorAll('.settings-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === tabName);
        });
        
        // تحديث المحتوى
        document.querySelectorAll('.settings-panel').forEach(panel => {
            panel.style.display = panel.dataset.panel === tabName ? 'block' : 'none';
        });
    },
    
    // ================================================================
    // حفظ الإعدادات
    // ================================================================
    
    /**
     * حفظ الإعدادات العامة
     */
    saveGeneralSettings: function() {
        const data = {
            company_name: document.getElementById('companyName').value,
            company_email: document.getElementById('companyEmail').value,
            company_phone: document.getElementById('companyPhone').value,
            company_address: document.getElementById('companyAddress').value,
            company_tax_number: document.getElementById('companyTaxNumber').value,
            currency: document.getElementById('currency').value,
            timezone: document.getElementById('timezone').value,
            date_format: document.getElementById('dateFormat').value,
            time_format: document.getElementById('timeFormat').value
        };
        
        this.saveSettings(data, 'تم حفظ الإعدادات العامة بنجاح');
    },
    
    /**
     * حفظ إعدادات المخزون
     */
    saveInventorySettings: function() {
        const data = {
            default_warehouse: document.getElementById('defaultWarehouse').value,
            low_stock_threshold: document.getElementById('lowStockThreshold').value,
            auto_reorder: document.getElementById('autoReorder').checked ? 'true' : 'false',
            negative_stock_allowed: document.getElementById('negativeStockAllowed').checked ? 'true' : 'false'
        };
        
        this.saveSettings(data, 'تم حفظ إعدادات المخزون بنجاح');
    },
    
    /**
     * حفظ إعدادات الأمان
     */
    saveSecuritySettings: function() {
        const data = {
            single_session_enabled: document.getElementById('singleSession').checked ? 'true' : 'false',
            session_timeout: document.getElementById('sessionTimeout').value,
            max_login_attempts: document.getElementById('maxLoginAttempts').value,
            lockout_duration: document.getElementById('lockoutDuration').value,
            password_expiry_days: document.getElementById('passwordExpiryDays').value,
            force_ssl: document.getElementById('forceSsl').checked ? 'true' : 'false'
        };
        
        this.saveSettings(data, 'تم حفظ إعدادات الأمان بنجاح');
    },
    
    /**
     * حفظ إعدادات النسخ الاحتياطي
     */
    saveBackupSettings: function() {
        const data = {
            auto_backup_enabled: document.getElementById('autoBackupEnabled').checked ? 'true' : 'false',
            auto_backup_time: document.getElementById('autoBackupTime').value,
            backup_retention_days: document.getElementById('backupRetentionDays').value,
            backup_path: document.getElementById('backupPath').value,
            backup_compress: document.getElementById('backupCompress').checked ? 'true' : 'false'
        };
        
        this.saveSettings(data, 'تم حفظ إعدادات النسخ الاحتياطي بنجاح');
    },
    
    /**
     * حفظ إعدادات التقارير
     */
    saveReportSettings: function() {
        const data = {
            report_logo: document.getElementById('reportLogo').value,
            report_footer: document.getElementById('reportFooter').value,
            report_page_size: document.getElementById('reportPageSize').value
        };
        
        this.saveSettings(data, 'تم حفظ إعدادات التقارير بنجاح');
    },
    
    /**
     * حفظ الإعدادات
     */
    saveSettings: function(data, successMessage) {
        App.showLoading();
        
        API.settings.batchUpdate(data)
            .then(response => {
                if (response.success) {
                    App.showToast(successMessage, 'success');
                    this.loadSettings();
                } else {
                    App.showToast(response.message || 'حدث خطأ في حفظ الإعدادات', 'error');
                }
            })
            .catch(error => {
                App.showToast(error.message || 'حدث خطأ في حفظ الإعدادات', 'error');
            })
            .finally(() => {
                App.hideLoading();
            });
    },
    
    // ================================================================
    // تغيير كلمة المرور
    // ================================================================
    
    /**
     * تغيير كلمة المرور
     */
    changePassword: function() {
        const currentPassword = document.getElementById('currentPassword')?.value;
        const newPassword = document.getElementById('newPassword')?.value;
        const confirmPassword = document.getElementById('confirmPassword')?.value;
        
        if (!currentPassword || !newPassword || !confirmPassword) {
            App.showToast('يرجى ملء جميع الحقول', 'warning');
            return;
        }
        
        if (newPassword !== confirmPassword) {
            App.showToast('كلمة المرور الجديدة وتأكيدها غير متطابقين', 'warning');
            return;
        }
        
        if (newPassword.length < 8) {
            App.showToast('كلمة المرور يجب أن تكون 8 أحرف على الأقل', 'warning');
            return;
        }
        
        App.showLoading();
        
        Auth.changePassword(currentPassword, newPassword, confirmPassword)
            .then(result => {
                if (result.success) {
                    App.showToast('تم تغيير كلمة المرور بنجاح', 'success');
                    document.getElementById('currentPassword').value = '';
                    document.getElementById('newPassword').value = '';
                    document.getElementById('confirmPassword').value = '';
                    
                    // إعادة توجيه بعد 3 ثواني
                    setTimeout(() => {
                        Auth.logout();
                    }, 3000);
                } else {
                    App.showToast(result.message, 'error');
                }
            })
            .catch(error => {
                App.showToast(error.message || 'حدث خطأ في تغيير كلمة المرور', 'error');
            })
            .finally(() => {
                App.hideLoading();
            });
    },
    
    // ================================================================
    // النسخ الاحتياطي
    // ================================================================
    
    /**
     * إنشاء نسخة احتياطية
     */
    createBackup: function() {
        Swal.fire({
            title: 'إنشاء نسخة احتياطية',
            text: 'هل أنت متأكد من رغبتك في إنشاء نسخة احتياطية جديدة؟',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم، إنشاء',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#28a745'
        }).then(result => {
            if (result.isConfirmed) {
                App.showLoading();
                
                API.backup.create()
                    .then(response => {
                        if (response.success) {
                            App.showToast('تم إنشاء النسخة الاحتياطية بنجاح', 'success');
                            this.loadBackupList();
                        } else {
                            App.showToast(response.message || 'فشل إنشاء النسخة الاحتياطية', 'error');
                        }
                    })
                    .catch(error => {
                        App.showToast(error.message || 'حدث خطأ في إنشاء النسخة الاحتياطية', 'error');
                    })
                    .finally(() => {
                        App.hideLoading();
                    });
            }
        });
    },
    
    /**
     * استعادة نسخة احتياطية
     */
    restoreBackup: function(id) {
        const backupId = id || document.getElementById('restoreBackupId')?.value;
        
        if (!backupId) {
            App.showToast('يرجى اختيار نسخة احتياطية للاستعادة', 'warning');
            return;
        }
        
        Swal.fire({
            title: 'استعادة النسخة الاحتياطية',
            text: 'سيتم استبدال جميع البيانات الحالية. هل أنت متأكد؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، استعادة',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#dc3545'
        }).then(result => {
            if (result.isConfirmed) {
                App.showLoading();
                
                API.backup.restore(backupId)
                    .then(response => {
                        if (response.success) {
                            App.showToast('تم استعادة النسخة الاحتياطية بنجاح. سيتم إعادة تشغيل النظام.', 'success');
                            setTimeout(() => {
                                window.location.reload();
                            }, 3000);
                        } else {
                            App.showToast(response.message || 'فشل استعادة النسخة الاحتياطية', 'error');
                        }
                    })
                    .catch(error => {
                        App.showToast(error.message || 'حدث خطأ في استعادة النسخة الاحتياطية', 'error');
                    })
                    .finally(() => {
                        App.hideLoading();
                    });
            }
        });
    },
    
    /**
     * تحميل نسخة احتياطية
     */
    downloadBackup: function(id) {
        API.backup.download(id)
            .then(response => {
                if (response.success) {
                    // تحويل الـ blob إلى رابط تحميل
                    const url = window.URL.createObjectURL(response);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `backup_${new Date().toISOString().slice(0,10)}.sql.gz`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    App.showToast('تم بدء التحميل', 'success');
                }
            })
            .catch(error => {
                App.showToast(error.message || 'حدث خطأ في تحميل النسخة الاحتياطية', 'error');
            });
    },
    
    /**
     * حذف نسخة احتياطية
     */
    deleteBackup: function(id) {
        Swal.fire({
            title: 'حذف النسخة الاحتياطية',
            text: 'هل أنت متأكد من رغبتك في حذف هذه النسخة؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، حذف',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#dc3545'
        }).then(result => {
            if (result.isConfirmed) {
                App.showLoading();
                
                API.backup.delete(id)
                    .then(response => {
                        if (response.success) {
                            App.showToast('تم حذف النسخة الاحتياطية بنجاح', 'success');
                            this.loadBackupList();
                        } else {
                            App.showToast(response.message || 'فشل حذف النسخة الاحتياطية', 'error');
                        }
                    })
                    .catch(error => {
                        App.showToast(error.message || 'حدث خطأ في حذف النسخة الاحتياطية', 'error');
                    })
                    .finally(() => {
                        App.hideLoading();
                    });
            }
        });
    },
    
    // ================================================================
    // أدوات مساعدة
    // ================================================================
    
    /**
     * تنسيق حجم الملف
     */
    formatFileSize: function(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },
    
    /**
     * تنظيف السجلات
     */
    cleanupLogs: function() {
        Swal.fire({
            title: 'تنظيف السجلات',
            text: 'سيتم حذف السجلات القديمة. هل أنت متأكد؟',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'نعم، تنظيف',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#dc3545'
        }).then(result => {
            if (result.isConfirmed) {
                App.showLoading();
                
                API.post('/settings/cleanup-logs')
                    .then(response => {
                        if (response.success) {
                            App.showToast('تم تنظيف السجلات بنجاح', 'success');
                        } else {
                            App.showToast(response.message || 'فشل تنظيف السجلات', 'error');
                        }
                    })
                    .catch(error => {
                        App.showToast(error.message || 'حدث خطأ في تنظيف السجلات', 'error');
                    })
                    .finally(() => {
                        App.hideLoading();
                    });
            }
        });
    },
    
    /**
     * تحديث معلومات الإصدار
     */
    updateVersionInfo: function() {
        const versionEl = document.getElementById('systemVersionInfo');
        if (versionEl) {
            versionEl.textContent = `v${window.VERSION || '5.0.0'}`;
        }
        
        const updateEl = document.getElementById('lastUpdateInfo');
        if (updateEl) {
            updateEl.textContent = new Date().toLocaleDateString('ar-SA', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    }
};

// ================================================================
// دوال عامة للاستخدام من HTML
// ================================================================

function saveGeneralSettings() {
    Settings.saveGeneralSettings();
}

function saveInventorySettings() {
    Settings.saveInventorySettings();
}

function saveSecuritySettings() {
    Settings.saveSecuritySettings();
}

function saveBackupSettings() {
    Settings.saveBackupSettings();
}

function saveReportSettings() {
    Settings.saveReportSettings();
}

function createBackup() {
    Settings.createBackup();
}

function restoreBackup() {
    Settings.restoreBackup();
}

function changePassword() {
    Settings.changePassword();
}

function cleanupLogs() {
    Settings.cleanupLogs();
}

// ================================================================
// تصدير Settings
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = Settings;
}

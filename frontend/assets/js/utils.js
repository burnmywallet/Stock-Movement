// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: frontend/assets/js/utils.js
// الوصف: دوال مساعدة عامة للاستخدام في جميع أنحاء النظام
// الإصدار: 5.0 Ultimate
// التاريخ: 2026-08-21
// ================================================================

/**
 * الأدوات المساعدة - دوال عامة
 */
const Utils = {
    // ================================================================
    // دوال التاريخ والوقت
    // ================================================================
    
    /**
     * تنسيق التاريخ
     */
    formatDate: function(date, format = 'YYYY-MM-DD') {
        if (!date) return '-';
        const d = new Date(date);
        if (isNaN(d.getTime())) return '-';
        
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hours = String(d.getHours()).padStart(2, '0');
        const minutes = String(d.getMinutes()).padStart(2, '0');
        const seconds = String(d.getSeconds()).padStart(2, '0');
        
        return format
            .replace('YYYY', year)
            .replace('MM', month)
            .replace('DD', day)
            .replace('HH', hours)
            .replace('mm', minutes)
            .replace('ss', seconds);
    },
    
    /**
     * تنسيق التاريخ بالعربية
     */
    formatDateArabic: function(date) {
        if (!date) return '-';
        const d = new Date(date);
        if (isNaN(d.getTime())) return '-';
        
        return d.toLocaleDateString('ar-SA', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    },
    
    /**
     * حساب الفرق بين تاريخين
     */
    daysBetween: function(date1, date2) {
        const d1 = new Date(date1);
        const d2 = new Date(date2);
        const diffTime = Math.abs(d2 - d1);
        return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    },
    
    /**
     * التحقق من صحة التاريخ
     */
    isValidDate: function(date) {
        const d = new Date(date);
        return d instanceof Date && !isNaN(d.getTime());
    },
    
    // ================================================================
    // دوال الأرقام والعملات
    // ================================================================
    
    /**
     * تنسيق العملة
     */
    formatCurrency: function(amount, currency = 'SAR') {
        if (amount === null || amount === undefined) return '0.00 ر.س';
        const num = parseFloat(amount);
        if (isNaN(num)) return '0.00 ر.س';
        
        return new Intl.NumberFormat('ar-SA', {
            style: 'currency',
            currency: currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(num);
    },
    
    /**
     * تنسيق رقم
     */
    formatNumber: function(number, decimals = 0) {
        if (number === null || number === undefined) return '0';
        const num = parseFloat(number);
        if (isNaN(num)) return '0';
        
        return new Intl.NumberFormat('ar-SA', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(num);
    },
    
    /**
     * حساب النسبة المئوية
     */
    percentage: function(value, total) {
        if (total === 0) return 0;
        return (value / total) * 100;
    },
    
    /**
     * تقريب إلى أقرب رقم
     */
    round: function(number, decimals = 2) {
        const factor = Math.pow(10, decimals);
        return Math.round(number * factor) / factor;
    },
    
    // ================================================================
    // دوال النصوص
    // ================================================================
    
    /**
     * اختصار النص
     */
    truncate: function(text, length = 50, suffix = '...') {
        if (!text) return '';
        if (text.length <= length) return text;
        return text.substring(0, length) + suffix;
    },
    
    /**
     * تحويل إلى Slug
     */
    slugify: function(text) {
        if (!text) return '';
        return text
            .toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .trim();
    },
    
    /**
     * استخراج الأحرف الأولى
     */
    getInitials: function(name) {
        if (!name) return '?';
        const parts = name.split(' ');
        if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
        return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    },
    
    /**
     * تلوين النص حسب الحالة
     */
    getStatusColor: function(status) {
        const colors = {
            'success': '#28a745',
            'danger': '#dc3545',
            'warning': '#ffc107',
            'info': '#17a2b8',
            'primary': '#667eea',
            'secondary': '#6c757d',
            'dark': '#343a40',
            'light': '#f8f9fa'
        };
        return colors[status] || '#6c757d';
    },
    
    // ================================================================
    // دوال المصفوفات
    // ================================================================
    
    /**
     * تجميع المصفوفة حسب المفتاح
     */
    groupBy: function(array, key) {
        return array.reduce((result, item) => {
            const groupKey = item[key] || 'unknown';
            if (!result[groupKey]) {
                result[groupKey] = [];
            }
            result[groupKey].push(item);
            return result;
        }, {});
    },
    
    /**
     * ترتيب المصفوفة
     */
    sortBy: function(array, key, order = 'asc') {
        return array.sort((a, b) => {
            const va = a[key] || '';
            const vb = b[key] || '';
            if (typeof va === 'number') {
                return order === 'asc' ? va - vb : vb - va;
            }
            return order === 'asc' 
                ? va.toString().localeCompare(vb.toString())
                : vb.toString().localeCompare(va.toString());
        });
    },
    
    /**
     * فلترة المصفوفة بالبحث
     */
    filterBySearch: function(array, search, keys) {
        if (!search) return array;
        const lowerSearch = search.toLowerCase();
        return array.filter(item => {
            return keys.some(key => {
                const value = item[key] || '';
                return value.toString().toLowerCase().includes(lowerSearch);
            });
        });
    },
    
    // ================================================================
    // دوال التحميل
    // ================================================================
    
    /**
     * تحميل ملف
     */
    downloadFile: function(content, filename, type = 'text/plain') {
        const blob = new Blob([content], { type: type });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    },
    
    /**
     * تصدير CSV
     */
    exportCSV: function(data, filename = 'export.csv') {
        if (!data || data.length === 0) {
            App.showToast('لا توجد بيانات للتصدير', 'warning');
            return;
        }
        
        const headers = Object.keys(data[0]);
        let csv = headers.join(',') + '\n';
        
        data.forEach(row => {
            csv += headers.map(h => {
                let val = row[h] || '';
                if (typeof val === 'string' && (val.includes(',') || val.includes('"') || val.includes('\n'))) {
                    val = '"' + val.replace(/"/g, '""') + '"';
                }
                return val;
            }).join(',') + '\n';
        });
        
        this.downloadFile('\uFEFF' + csv, filename, 'text/csv;charset=utf-8');
    },
    
    /**
     * تصدير Excel (HTML Table)
     */
    exportExcel: function(data, filename = 'export.xls') {
        if (!data || data.length === 0) {
            App.showToast('لا توجد بيانات للتصدير', 'warning');
            return;
        }
        
        let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
        html += '<head><meta charset="UTF-8">';
        html += '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
        html += '<x:Name>Sheet1</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>';
        html += '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
        html += '</head><body>';
        html += '<table border="1">';
        html += '<tr>' + Object.keys(data[0]).map(h => `<th style="background:#667eea;color:#fff;font-weight:bold;">${h}</th>`).join('') + '</tr>';
        
        data.forEach(row => {
            html += '<tr>' + Object.values(row).map(v => `<td>${v || ''}</td>`).join('') + '</tr>';
        });
        
        html += '</table></body></html>';
        this.downloadFile(html, filename, 'application/vnd.ms-excel');
    },
    
    // ================================================================
    // دوال التنبيهات
    // ================================================================
    
    /**
     * عرض تنبيه
     */
    showAlert: function(message, type = 'info') {
        Swal.fire({
            icon: type,
            title: message,
            timer: 3000,
            showConfirmButton: false
        });
    },
    
    /**
     * عرض تأكيد
     */
    showConfirm: function(message, title = 'تأكيد') {
        return Swal.fire({
            title: title,
            text: message,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم',
            cancelButtonText: 'إلغاء'
        }).then(result => result.isConfirmed);
    },
    
    /**
     * عرض نموذج إدخال
     */
    showPrompt: function(message, title = 'إدخال', defaultValue = '') {
        return Swal.fire({
            title: title,
            text: message,
            input: 'text',
            inputValue: defaultValue,
            showCancelButton: true,
            confirmButtonText: 'موافق',
            cancelButtonText: 'إلغاء'
        });
    },
    
    // ================================================================
    // دوال التخزين
    // ================================================================
    
    /**
     * حفظ في التخزين المحلي
     */
    setStorage: function(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch {
            // تجاهل
        }
    },
    
    /**
     * استرجاع من التخزين المحلي
     */
    getStorage: function(key, defaultValue = null) {
        try {
            const value = localStorage.getItem(key);
            return value ? JSON.parse(value) : defaultValue;
        } catch {
            return defaultValue;
        }
    },
    
    /**
     * حذف من التخزين المحلي
     */
    removeStorage: function(key) {
        try {
            localStorage.removeItem(key);
        } catch {
            // تجاهل
        }
    },
    
    // ================================================================
    // دوال المتصفح
    // ================================================================
    
    /**
     * الكشف عن نوع المتصفح
     */
    getBrowserInfo: function() {
        const ua = navigator.userAgent;
        let browser = 'unknown';
        let version = 'unknown';
        
        if (ua.indexOf('Chrome') > -1) {
            browser = 'Chrome';
            version = ua.match(/Chrome\/(\d+)/)?.[1] || 'unknown';
        } else if (ua.indexOf('Firefox') > -1) {
            browser = 'Firefox';
            version = ua.match(/Firefox\/(\d+)/)?.[1] || 'unknown';
        } else if (ua.indexOf('Safari') > -1) {
            browser = 'Safari';
            version = ua.match(/Version\/(\d+)/)?.[1] || 'unknown';
        } else if (ua.indexOf('Edge') > -1) {
            browser = 'Edge';
            version = ua.match(/Edg\/(\d+)/)?.[1] || 'unknown';
        }
        
        return { browser, version };
    },
    
    /**
     * الكشف عن نظام التشغيل
     */
    getOSInfo: function() {
        const ua = navigator.userAgent;
        let os = 'unknown';
        
        if (ua.indexOf('Windows') > -1) os = 'Windows';
        else if (ua.indexOf('Mac OS') > -1) os = 'macOS';
        else if (ua.indexOf('Linux') > -1) os = 'Linux';
        else if (ua.indexOf('Android') > -1) os = 'Android';
        else if (ua.indexOf('iPhone') > -1 || ua.indexOf('iPad') > -1) os = 'iOS';
        
        return os;
    },
    
    /**
     * الكشف عن نوع الجهاز
     */
    getDeviceType: function() {
        const ua = navigator.userAgent;
        if (ua.indexOf('Mobile') > -1) return 'mobile';
        if (ua.indexOf('Tablet') > -1) return 'tablet';
        return 'desktop';
    },
    
    // ================================================================
    // دوال الشبكة
    // ================================================================
    
    /**
     * التحقق من الاتصال بالإنترنت
     */
    isOnline: function() {
        return navigator.onLine;
    },
    
    /**
     * الانتظار حتى الاتصال
     */
    waitForConnection: function() {
        return new Promise((resolve) => {
            if (navigator.onLine) {
                resolve(true);
            } else {
                window.addEventListener('online', () => resolve(true));
            }
        });
    },
    
    // ================================================================
    // دوال التصحيح
    // ================================================================
    
    /**
     * تسجيل في Console مع تنسيق
     */
    log: function(message, type = 'info', data = null) {
        const styles = {
            'info': 'color: #667eea; font-weight: bold;',
            'success': 'color: #28a745; font-weight: bold;',
            'warning': 'color: #ffc107; font-weight: bold;',
            'error': 'color: #dc3545; font-weight: bold;',
            'debug': 'color: #6c757d; font-style: italic;'
        };
        
        console.log(`%c[${type.toUpperCase()}] ${message}`, styles[type] || styles.info);
        if (data) {
            console.log(data);
        }
    },
    
    /**
     * قياس وقت التنفيذ
     */
    time: function(label, callback) {
        console.time(label);
        const result = callback();
        console.timeEnd(label);
        return result;
    }
};

// ================================================================
// تصدير Utils
// ================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = Utils;
}

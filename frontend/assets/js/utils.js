/**
 * ================================================================
 * Logistox - دوال مساعدة عامة
 * نظام إدارة المخازن والمخزون v5.0
 * ================================================================
 */

// منع التكرار
if (typeof window.showToast === 'undefined') {

// ================================================================
// دوال التنسيق
// ================================================================

/**
 * تنسيق التاريخ
 */
function formatDate(dateString, options = {}) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const defaultOptions = {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    };
    return date.toLocaleDateString('ar-EG', { ...defaultOptions, ...options });
}

/**
 * تنسيق الوقت
 */
function formatTime(dateString, options = {}) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const defaultOptions = {
        hour: '2-digit',
        minute: '2-digit'
    };
    return date.toLocaleTimeString('ar-EG', { ...defaultOptions, ...options });
}

/**
 * تنسيق التاريخ والوقت
 */
function formatDateTime(dateString, options = {}) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const defaultOptions = {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    return date.toLocaleString('ar-EG', { ...defaultOptions, ...options });
}

/**
 * تنسيق الأرقام
 */
function formatNumber(number, options = {}) {
    if (number === null || number === undefined) return '0';
    const defaultOptions = {
        maximumFractionDigits: 3,
        minimumFractionDigits: 0
    };
    return new Intl.NumberFormat('ar-EG', { ...defaultOptions, ...options }).format(number);
}

/**
 * تنسيق العملة
 */
function formatCurrency(amount, currency = 'EGP') {
    const symbol = window.APP_CONFIG?.LOCALE?.CURRENCY_SYMBOL || 'ج.م';
    return `${formatNumber(amount)} ${symbol}`;
}

/**
 * تنسيق النسبة المئوية
 */
function formatPercent(value, decimals = 1) {
    return `${Number(value).toFixed(decimals)}%`;
}

// ================================================================
// دوال التحقق
// ================================================================

/**
 * التحقق من البريد الإلكتروني
 */
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

/**
 * التحقق من رقم الهاتف المصري
 */
function isValidPhone(phone) {
    const phoneRegex = /^01[0125][0-9]{8}$/;
    return phoneRegex.test(phone);
}

/**
 * التحقق من الباركود EAN13
 */
function isValidEAN13(barcode) {
    if (!/^\d{13}$/.test(barcode)) return false;
    
    let sum = 0;
    for (let i = 0; i < 12; i++) {
        sum += parseInt(barcode[i]) * (i % 2 === 0 ? 1 : 3);
    }
    
    const checkDigit = (10 - (sum % 10)) % 10;
    return parseInt(barcode[12]) === checkDigit;
}

/**
 * التحقق من كود SKU
 */
function isValidSKU(sku) {
    return /^[A-Z0-9-]{3,20}$/.test(sku);
}

/**
 * التحقق من كود المنتج
 */
function isValidProductCode(code) {
    return /^[A-Z0-9-]{3,20}$/.test(code);
}

/**
 * التحقق من اسم المستخدم
 */
function isValidUsername(username) {
    return /^[a-zA-Z0-9_]{3,20}$/.test(username);
}

/**
 * التحقق من قوة كلمة المرور
 */
function getPasswordStrength(password) {
    let strength = 0;
    const checks = {
        length: password.length >= 8,
        uppercase: /[A-Z]/.test(password),
        lowercase: /[a-z]/.test(password),
        numbers: /[0-9]/.test(password),
        special: /[!@#$%^&*(),.?":{}|<>]/.test(password)
    };
    
    strength = Object.values(checks).filter(Boolean).length;
    
    return {
        score: strength,
        label: strength <= 2 ? 'ضعيفة' : strength <= 3 ? 'متوسطة' : strength <= 4 ? 'جيدة' : 'قوية',
        checks: checks
    };
}

/**
 * التحقق من كلمة المرور
 */
function isValidPassword(password) {
    return password.length >= 8;
}

// ================================================================
// دوال النصوص
// ================================================================

/**
 * تقليم النص
 */
function truncate(text, length = 50) {
    if (!text) return '';
    return text.length > length ? text.substring(0, length) + '...' : text;
}

/**
 * تحويل النص إلى حالة العنوان
 */
function titleCase(str) {
    return str.replace(/\w\S*/g, (txt) => txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase());
}

/**
 * إزالة الرموز الخاصة
 */
function sanitizeInput(input) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(input));
    return div.innerHTML;
}

/**
 * ترميز HTML
 */
function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

/**
 * فك ترميز HTML
 */
function unescapeHtml(str) {
    const div = document.createElement('div');
    div.innerHTML = str;
    return div.textContent;
}

// ================================================================
// دوال التخزين
// ================================================================

/**
 * تخزين بيانات
 */
function setStorage(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
        return true;
    } catch (e) {
        console.error('Error storing data:', e);
        return false;
    }
}

/**
 * قراءة بيانات
 */
function getStorage(key, defaultValue = null) {
    try {
        const value = localStorage.getItem(key);
        return value ? JSON.parse(value) : defaultValue;
    } catch (e) {
        return defaultValue;
    }
}

/**
 * حذف بيانات
 */
function removeStorage(key) {
    localStorage.removeItem(key);
}

/**
 * مسح جميع البيانات
 */
function clearStorage() {
    localStorage.clear();
}

// ================================================================
// دوال DOM
// ================================================================

/**
 * إنشاء عنصر
 */
function createElement(tag, attributes = {}, children = []) {
    const element = document.createElement(tag);
    
    for (const [key, value] of Object.entries(attributes)) {
        if (key === 'class') {
            element.className = value;
        } else if (key === 'style') {
            element.style.cssText = value;
        } else if (key.startsWith('data-')) {
            element.setAttribute(key, value);
        } else if (key === 'text') {
            element.textContent = value;
        } else if (key === 'html') {
            element.innerHTML = value;
        } else {
            element.setAttribute(key, value);
        }
    }
    
    if (children.length > 0) {
        children.forEach(child => {
            if (typeof child === 'string') {
                element.appendChild(document.createTextNode(child));
            } else {
                element.appendChild(child);
            }
        });
    }
    
    return element;
}

/**
 * إزالة عنصر
 */
function removeElement(element) {
    if (element && element.parentNode) {
        element.parentNode.removeChild(element);
    }
}

/**
 * تبديل الرؤية
 */
function toggleVisibility(element, show = null) {
    if (!element) return;
    const shouldShow = show !== null ? show : element.style.display === 'none';
    element.style.display = shouldShow ? '' : 'none';
}

/**
 * إظهار عنصر
 */
function showElement(element) {
    if (element) element.style.display = '';
}

/**
 * إخفاء عنصر
 */
function hideElement(element) {
    if (element) element.style.display = 'none';
}

/**
 * تعطيل عنصر
 */
function disableElement(element) {
    if (element) element.disabled = true;
}

/**
 * تفعيل عنصر
 */
function enableElement(element) {
    if (element) element.disabled = false;
}

// ================================================================
// دوال الإشعارات
// ================================================================

/**
 * عرض إشعار Toast
 */
function showToast(message, type = 'info', duration = 3500) {
    const existing = document.querySelector('.toast-custom');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'toast-custom ' + type;
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    toast.innerHTML = `<i class="fas ${icons[type] || icons.info}"></i> ${message}`;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.4s ease';
        setTimeout(() => toast.remove(), 400);
    }, duration);
}

/**
 * عرض إشعار تأكيد
 */
function showConfirm(message, onConfirm, type = 'warning') {
    const confirmModal = document.createElement('div');
    confirmModal.className = 'modal-overlay show';
    confirmModal.innerHTML = `
        <div class="modal-content" style="max-width:400px;">
            <div class="modal-header">
                <h3>تأكيد الإجراء</h3>
                <button class="close-btn" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="text-align:center;padding:20px 0;">
                    <i class="fas ${type === 'danger' ? 'fa-exclamation-triangle' : 'fa-question-circle'}" 
                       style="font-size:48px;color:${type === 'danger' ? 'var(--danger)' : 'var(--warning)'};margin-bottom:15px;"></i>
                    <br>${message}
                </p>
                <div style="display:flex;gap:10px;justify-content:center;">
                    <button class="btn btn-danger" onclick="confirmAction()">
                        <i class="fas fa-check"></i> نعم
                    </button>
                    <button class="btn btn-secondary" onclick="cancelAction()">
                        <i class="fas fa-times"></i> إلغاء
                    </button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(confirmModal);
    
    const confirmAction = () => {
        confirmModal.remove();
        if (typeof onConfirm === 'function') onConfirm();
    };
    
    const cancelAction = () => {
        confirmModal.remove();
    };
    
    confirmModal.querySelector('.btn-danger').addEventListener('click', confirmAction);
    confirmModal.querySelector('.btn-secondary').addEventListener('click', cancelAction);
}

/**
 * عرض إشعار نجاح
 */
function showSuccess(message) {
    showToast('✅ ' + message, 'success');
}

/**
 * عرض إشعار خطأ
 */
function showError(message) {
    showToast('❌ ' + message, 'error');
}

/**
 * عرض إشعار تحذير
 */
function showWarning(message) {
    showToast('⚠️ ' + message, 'warning');
}

// ================================================================
// دوال التنزيل والتصدير
// ================================================================

/**
 * تصدير CSV
 */
function exportToCSV(data, filename = 'export.csv') {
    if (!data || data.length === 0) {
        showWarning('لا توجد بيانات للتصدير');
        return false;
    }
    
    const headers = Object.keys(data[0]);
    const csv = [
        headers.join(','),
        ...data.map(row => headers.map(header => {
            const value = row[header];
            return `"${typeof value === 'object' ? JSON.stringify(value) : (value !== null && value !== undefined ? String(value).replace(/"/g, '""') : '')}"`;
        }).join(','))
    ].join('\n');
    
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
    
    showSuccess('تم تصدير البيانات بنجاح');
    return true;
}

/**
 * تصدير Excel (XLSX)
 */
function exportToExcel(data, filename = 'export.xlsx') {
    if (!data || data.length === 0) {
        showWarning('لا توجد بيانات للتصدير');
        return false;
    }
    
    // استخدام مكتبة SheetJS إذا كانت متاحة
    if (typeof XLSX !== 'undefined') {
        const ws = XLSX.utils.json_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
        XLSX.writeFile(wb, filename);
        showSuccess('تم تصدير البيانات بنجاح');
        return true;
    }
    
    // خطة بديلة: تصدير HTML
    const tableHTML = `
        <html xmlns:o="urn:schemas-microsoft-com:office:office" 
              xmlns:x="urn:schemas-microsoft-com:office:excel" 
              xmlns="http://www.w3.org/TR/REC-html40">
        <head>
            <meta charset="UTF-8">
            <!--[if gte mso 9]>
            <xml>
                <x:ExcelWorkbook>
                    <x:ExcelWorksheets>
                        <x:ExcelWorksheet>
                            <x:Name>Sheet1</x:Name>
                            <x:WorksheetOptions>
                                <x:DisplayGridlines/>
                            </x:WorksheetOptions>
                        </x:ExcelWorksheet>
                    </x:ExcelWorksheets>
                </x:ExcelWorkbook>
            </xml>
            <![endif]-->
        </head>
        <body>
            <table>
                <thead>
                    <tr>${Object.keys(data[0]).map(key => `<th>${key}</th>`).join('')}</tr>
                </thead>
                <tbody>
                    ${data.map(row => `<tr>${Object.values(row).map(cell => `<td>${cell}</td>`).join('')}</tr>`).join('')}
                </tbody>
            </table>
        </body>
        </html>
    `;
    
    const blob = new Blob([tableHTML], { type: 'application/vnd.ms-excel' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
    
    showSuccess('تم تصدير البيانات بنجاح');
    return true;
}

/**
 * تصدير PDF
 */
function exportToPDF(content, filename = 'export.pdf') {
    if (typeof window.jspdf !== 'undefined') {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        
        doc.text(content, 10, 10);
        doc.save(filename);
        
        showSuccess('تم تصدير PDF بنجاح');
        return true;
    }
    
    // خطة بديلة: طباعة
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>${filename}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                pre { white-space: pre-wrap; }
            </style>
        </head>
        <body>
            <pre>${content}</pre>
            <script>window.print();</script>
        </body>
        </html>
    `);
    printWindow.document.close();
    
    showSuccess('تم فتح نافذة الطباعة');
    return true;
}

// ================================================================
// دوال الطباعة
// ================================================================

/**
 * طباعة عنصر
 */
function printElement(elementId, title = 'طباعة') {
    const element = document.getElementById(elementId);
    if (!element) {
        showError('العنصر غير موجود');
        return false;
    }
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>${title}</title>
            <style>
                body { font-family: 'Tajawal', Arial, sans-serif; padding: 20px; direction: rtl; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                th { background: #f2f2f2; }
                @media print {
                    body { margin: 0; }
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            ${element.outerHTML}
            <script>window.print();</script>
        </body>
        </html>
    `);
    printWindow.document.close();
    
    showSuccess('تم فتح نافذة الطباعة');
    return true;
}

/**
 * طباعة الجدول
 */
function printTable(tableId, title = 'تقرير') {
    return printElement(tableId, title);
}

// ================================================================
// دوال البحث والفلترة
// ================================================================

/**
 * فلترة البيانات
 */
function filterData(data, searchTerm, fields) {
    if (!searchTerm) return data;
    
    const term = searchTerm.toLowerCase();
    return data.filter(item => {
        return fields.some(field => {
            const value = item[field];
            return value && String(value).toLowerCase().includes(term);
        });
    });
}

/**
 * فرز البيانات
 */
function sortData(data, field, direction = 'asc') {
    return [...data].sort((a, b) => {
        const aValue = a[field];
        const bValue = b[field];
        
        if (typeof aValue === 'number' && typeof bValue === 'number') {
            return direction === 'asc' ? aValue - bValue : bValue - aValue;
        }
        
        const aStr = String(aValue || '').toLowerCase();
        const bStr = String(bValue || '').toLowerCase();
        
        return direction === 'asc' 
            ? aStr.localeCompare(bStr, 'ar')
            : bStr.localeCompare(aStr, 'ar');
    });
}

/**
 * ترقيم الصفحات
 */
function paginateData(data, page = 1, perPage = 10) {
    const total = data.length;
    const totalPages = Math.ceil(total / perPage);
    const start = (page - 1) * perPage;
    const end = start + perPage;
    
    return {
        data: data.slice(start, end),
        pagination: {
            page: page,
            perPage: perPage,
            total: total,
            totalPages: totalPages,
            start: start + 1,
            end: Math.min(end, total)
        }
    };
}

/**
 * تحديث عناصر الترقيم
 */
function updatePagination(pagination, containerId, onPageChange) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    if (pagination.totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    let html = '<div class="pagination">';
    
    // زر السابق
    html += `<button class="page-btn" onclick="window.${onPageChange}(${pagination.page - 1})" ${pagination.page === 1 ? 'disabled' : ''}>
                <i class="fas fa-chevron-right"></i>
             </button>`;
    
    // الصفحات
    for (let i = 1; i <= pagination.totalPages; i++) {
        if (i === pagination.page) {
            html += `<button class="page-btn active">${i}</button>`;
        } else if (i <= 3 || i > pagination.totalPages - 3 || Math.abs(i - pagination.page) <= 1) {
            html += `<button class="page-btn" onclick="window.${onPageChange}(${i})">${i}</button>`;
        } else if (i === 4 || i === pagination.totalPages - 3) {
            html += '<span class="page-dots">...</span>';
        }
    }
    
    // زر التالي
    html += `<button class="page-btn" onclick="window.${onPageChange}(${pagination.page + 1})" ${pagination.page === pagination.totalPages ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i>
             </button>`;
    
    html += '</div>';
    
    container.innerHTML = html;
}

// ================================================================
// دوال WebSocket
// ================================================================

/**
 * إنشاء اتصال WebSocket
 */
function createWebSocket(onMessage, onConnect, onDisconnect) {
    const wsUrl = window.APP_CONFIG?.WEBSOCKET?.URL || 'ws://localhost:8080';
    const socket = new WebSocket(wsUrl);
    
    socket.onopen = () => {
        console.log('✅ WebSocket متصل');
        if (typeof onConnect === 'function') onConnect();
    };
    
    socket.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data);
            if (typeof onMessage === 'function') onMessage(data);
        } catch (e) {
            console.error('WebSocket message error:', e);
        }
    };
    
    socket.onclose = () => {
        console.log('WebSocket disconnected');
        if (typeof onDisconnect === 'function') onDisconnect();
    };
    
    socket.onerror = (error) => {
        console.error('WebSocket error:', error);
    };
    
    return socket;
}

/**
 * إعادة الاتصال تلقائياً
 */
function reconnectWebSocket(onMessage, onConnect, onDisconnect) {
    let socket = null;
    let reconnectAttempts = 0;
    const maxAttempts = window.APP_CONFIG?.WEBSOCKET?.MAX_RECONNECT_ATTEMPTS || 10;
    const interval = window.APP_CONFIG?.WEBSOCKET?.RECONNECT_INTERVAL || 3000;
    
    function connect() {
        socket = createWebSocket(
            (data) => {
                reconnectAttempts = 0;
                if (typeof onMessage === 'function') onMessage(data);
            },
            () => {
                reconnectAttempts = 0;
                if (typeof onConnect === 'function') onConnect();
            },
            () => {
                if (reconnectAttempts < maxAttempts) {
                    reconnectAttempts++;
                    console.log(`🔁 إعادة المحاولة ${reconnectAttempts}/${maxAttempts}`);
                    setTimeout(connect, interval);
                }
                if (typeof onDisconnect === 'function') onDisconnect();
            }
        );
    }
    
    connect();
    return socket;
}

// ================================================================
// دوال الباركود
// ================================================================

/**
 * توليد الباركود
 */
function generateBarcode(code, type = 'CODE128') {
    if (typeof JsBarcode === 'undefined') {
        console.warn('JsBarcode not loaded');
        return;
    }
    
    const canvas = document.createElement('canvas');
    JsBarcode(canvas, code, {
        format: type,
        lineColor: '#000',
        width: 2,
        height: 100,
        displayValue: true
    });
    
    return canvas.toDataURL();
}

/**
 * قراءة الباركود بالكاميرا
 */
function initBarcodeScanner(callback) {
    if (typeof Quagga === 'undefined') {
        console.warn('Quagga not loaded');
        return;
    }
    
    Quagga.init({
        inputStream: {
            name: 'Live',
            type: 'LiveStream',
            target: document.querySelector('#scanner')
        },
        decoder: {
            readers: ['ean_reader', 'code_128_reader', 'qr_reader']
        }
    }, function(err) {
        if (err) {
            console.error('Scanner error:', err);
            return;
        }
        Quagga.start();
    });
    
    Quagga.onDetected((result) => {
        if (typeof callback === 'function') {
            callback(result.codeResult.code);
        }
    });
}

/**
 * إيقاف الباركود سكانر
 */
function stopBarcodeScanner() {
    if (typeof Quagga !== 'undefined' && Quagga.initialized) {
        Quagga.stop();
    }
}

// ================================================================
// دوال النسخ الاحتياطي
// ================================================================

/**
 * إنشاء نسخة احتياطية
 */
async function createBackup() {
    try {
        const result = await window.Api.createBackup();
        showSuccess('تم إنشاء النسخة الاحتياطية بنجاح');
        return result;
    } catch (error) {
        showError('خطأ في إنشاء النسخة الاحتياطية');
        return null;
    }
}

/**
 * استعادة نسخة احتياطية
 */
async function restoreBackup(filename) {
    if (!confirm('هل أنت متأكد من استعادة النسخة الاحتياطية؟ سيتم استبدال جميع البيانات الحالية.')) {
        return false;
    }
    
    try {
        await window.Api.restoreBackup(filename);
        showSuccess('تم استعادة النسخة الاحتياطية بنجاح');
        location.reload();
        return true;
    } catch (error) {
        showError('خطأ في استعادة النسخة الاحتياطية');
        return false;
    }
}

// ================================================================
// تصدير الدوال
// ================================================================
window.formatDate = formatDate;
window.formatTime = formatTime;
window.formatDateTime = formatDateTime;
window.formatNumber = formatNumber;
window.formatCurrency = formatCurrency;
window.formatPercent = formatPercent;
window.isValidEmail = isValidEmail;
window.isValidPhone = isValidPhone;
window.isValidEAN13 = isValidEAN13;
window.isValidSKU = isValidSKU;
window.isValidProductCode = isValidProductCode;
window.isValidUsername = isValidUsername;
window.isValidPassword = isValidPassword;
window.getPasswordStrength = getPasswordStrength;
window.truncate = truncate;
window.titleCase = titleCase;
window.sanitizeInput = sanitizeInput;
window.escapeHtml = escapeHtml;
window.unescapeHtml = unescapeHtml;
window.setStorage = setStorage;
window.getStorage = getStorage;
window.removeStorage = removeStorage;
window.clearStorage = clearStorage;
window.createElement = createElement;
window.removeElement = removeElement;
window.toggleVisibility = toggleVisibility;
window.showElement = showElement;
window.hideElement = hideElement;
window.disableElement = disableElement;
window.enableElement = enableElement;
window.showToast = showToast;
window.showConfirm = showConfirm;
window.showSuccess = showSuccess;
window.showError = showError;
window.showWarning = showWarning;
window.exportToCSV = exportToCSV;
window.exportToExcel = exportToExcel;
window.exportToPDF = exportToPDF;
window.printElement = printElement;
window.printTable = printTable;
window.filterData = filterData;
window.sortData = sortData;
window.paginateData = paginateData;
window.updatePagination = updatePagination;
window.createWebSocket = createWebSocket;
window.reconnectWebSocket = reconnectWebSocket;
window.generateBarcode = generateBarcode;
window.initBarcodeScanner = initBarcodeScanner;
window.stopBarcodeScanner = stopBarcodeScanner;
window.createBackup = createBackup;
window.restoreBackup = restoreBackup;

} // نهاية منع التكرار

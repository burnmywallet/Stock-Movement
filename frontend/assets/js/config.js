/**
 * ================================================================
 * Logistox - إعدادات التطبيق
 * نظام إدارة المخازن والمخزون v5.0
 * ================================================================
 */

// منع التكرار
if (typeof window.APP_CONFIG === 'undefined') {

const APP_CONFIG = {
    // معلومات التطبيق
    APP: {
        NAME: 'Logistox',
        VERSION: '5.0.0',
        DESCRIPTION: 'نظام إدارة المخازن والمخزون'
    },
    
    // إعدادات API
    API: {
        BASE_URL: '/api',
        TIMEOUT: 30000
    },
    
    // معلومات الشركة
    COMPANY: {
        NAME: 'شركة البركة لتوريد وتصنيع اللحوم',
        LICENSE: 'LOG-BARAKA-2026-001',
        YEAR: 2026
    },
    
    // معلومات المطور
    DEVELOPER: {
        NAME: 'Abdelrahman.KH',
        ALIAS: 'BurnMyWallet',
        PHONE_1: '01286187173',
        PHONE_2: '01027191811'
    },
    
    // إعدادات المصادقة
    AUTH: {
        TOKEN_KEY: 'auth_token',
        USER_KEY: 'user',
        LOGIN_URL: '/inventory-system/frontend/pages/login.html',
        DASHBOARD_URL: '/inventory-system/frontend/pages/dashboard.html'
    },
    
    // إعدادات الثيم
    THEME: {
        DEFAULT: 'dark',
        STORAGE_KEY: 'theme_preference'
    },
    
    // مسارات الصفحات
    PAGES: {
        LOGIN: '/inventory-system/frontend/pages/login.html',
        DASHBOARD: '/inventory-system/frontend/pages/dashboard.html',
        PRODUCTS: '/inventory-system/frontend/pages/products.html',
        WAREHOUSES: '/inventory-system/frontend/pages/warehouses.html',
        USERS: '/inventory-system/frontend/pages/users.html',
        RECEIPTS: '/inventory-system/frontend/pages/receipts.html',
        ISSUES: '/inventory-system/frontend/pages/issues.html',
        TRANSFERS: '/inventory-system/frontend/pages/transfers.html',
        RETURNS: '/inventory-system/frontend/pages/returns.html',
        REPORTS: '/inventory-system/frontend/pages/reports.html',
        SETTINGS: '/inventory-system/frontend/pages/settings.html',
        AUDIT: '/inventory-system/frontend/pages/audit.html',
        NOTIFICATIONS: '/inventory-system/frontend/pages/notifications.html',
        STOCK_BALANCES: '/inventory-system/frontend/pages/stock-balances.html',
        STOCK_MOVEMENTS: '/inventory-system/frontend/pages/stock-movements.html',
        CATEGORIES: '/inventory-system/frontend/pages/categories.html',
        UNITS: '/inventory-system/frontend/pages/units.html',
        BACKUP: '/inventory-system/frontend/pages/backup.html'
    },
    
    // إعدادات اللغة
    LOCALE: {
        DEFAULT: 'ar',
        DIRECTION: 'rtl',
        TIMEZONE: 'Africa/Cairo',
        CURRENCY: 'EGP',
        CURRENCY_SYMBOL: 'ج.م'
    },
    
    // إعدادات WebSocket
    WEBSOCKET: {
        URL: 'ws://localhost:8080',
        ENABLED: false,
        RECONNECT_INTERVAL: 3000,
        MAX_RECONNECT_ATTEMPTS: 10
    },
    
    // إعدادات الإشعارات
    NOTIFICATIONS: {
        SOUND_ENABLED: true,
        BROWSER_ENABLED: true,
        SOUND_URL: '/inventory-system/frontend/assets/sounds/notification.mp3'
    },
    
    // إعدادات الطباعة
    PRINTING: {
        PAPER_SIZE: 'A4',
        HEADER: '',
        FOOTER: ''
    },
    
    // إعدادات النسخ الاحتياطي
    BACKUP: {
        AUTO_BACKUP: true,
        FREQUENCY: 'daily',
        RETENTION_DAYS: 30
    }
};

// النصوص (الترجمة)
const TRANSLATIONS = {
    ar: {
        'dashboard': 'لوحة التحكم',
        'products': 'الأصناف',
        'warehouses': 'المخازن',
        'users': 'المستخدمون',
        'receipts': 'الاستلام',
        'issues': 'الصرف',
        'transfers': 'التحويلات',
        'returns': 'المرتجعات',
        'reports': 'التقارير',
        'settings': 'الإعدادات',
        'audit': 'التدقيق',
        'notifications': 'الإشعارات',
        'stock_balances': 'الأرصدة',
        'stock_movements': 'الحركات',
        'categories': 'التصنيفات',
        'units': 'الوحدات',
        'backup': 'النسخ الاحتياطي',
        'add': 'إضافة',
        'edit': 'تعديل',
        'delete': 'حذف',
        'save': 'حفظ',
        'cancel': 'إلغاء',
        'search': 'بحث',
        'refresh': 'تحديث',
        'export': 'تصدير',
        'print': 'طباعة',
        'view': 'عرض',
        'close': 'إغلاق',
        'confirm': 'تأكيد',
        'yes': 'نعم',
        'no': 'لا',
        'submit': 'إرسال',
        'loading': 'جاري التحميل...',
        'success': 'تم بنجاح',
        'error': 'حدث خطأ',
        'warning': 'تحذير',
        'info': 'معلومة',
        'confirm_delete': 'هل أنت متأكد من الحذف؟',
        'confirm_logout': 'هل أنت متأكد من تسجيل الخروج؟',
        'no_data': 'لا توجد بيانات',
        'no_results': 'لا توجد نتائج',
        'session_expired': 'انتهت الجلسة. يرجى تسجيل الدخول مرة أخرى',
        'permission_denied': 'ليس لديك صلاحية لتنفيذ هذا الإجراء',
        'username': 'اسم المستخدم',
        'password': 'كلمة المرور',
        'full_name': 'الاسم الكامل',
        'email': 'البريد الإلكتروني',
        'phone': 'الهاتف',
        'role': 'الدور',
        'warehouse': 'المخزن',
        'status': 'الحالة',
        'active': 'نشط',
        'inactive': 'غير نشط',
        'created_at': 'تاريخ الإنشاء',
        'updated_at': 'تاريخ التحديث',
        'actions': 'إجراءات',
        'product_code': 'كود الصنف',
        'product_name': 'اسم الصنف',
        'barcode': 'الباركود',
        'sku': 'كود SKU',
        'category': 'التصنيف',
        'unit': 'الوحدة',
        'min_stock': 'الحد الأدنى',
        'max_stock': 'الحد الأقصى',
        'current_stock': 'المخزون الحالي',
        'stock_status': 'حالة المخزون',
        'warehouse_code': 'كود المخزن',
        'warehouse_name': 'اسم المخزن',
        'warehouse_type': 'نوع المخزن',
        'location': 'الموقع',
        'manager': 'المدير',
        'capacity': 'السعة',
        'movement_type': 'نوع الحركة',
        'quantity': 'الكمية',
        'from_warehouse': 'من مخزن',
        'to_warehouse': 'إلى مخزن',
        'notes': 'ملاحظات',
        'reference': 'المرجع',
        'user': 'المستخدم',
        'date': 'التاريخ',
        'RECEIPT': 'استلام',
        'ISSUE': 'صرف',
        'TRANSFER_OUT': 'تحويل خارج',
        'TRANSFER_IN': 'تحويل داخل',
        'RETURN_IN': 'مرتجع إلى المخزن',
        'RETURN_OUT': 'مرتجع من المخزن',
        'ADJUSTMENT': 'تسوية',
        'COUNT_CORRECTION': 'تصحيح جرد',
        'RESERVATION': 'حجز',
        'RELEASE': 'إفراج',
        'products_by_category': 'الأصناف حسب التصنيف',
        'daily_movements': 'الحركات اليومية',
        'low_stock': 'المخزون المنخفض',
        'out_of_stock': 'المخزون النافق',
        'product_movements': 'حركة صنف',
        'warehouse_movements': 'حركة مخزن',
        'returns_report': 'تقرير المرتجعات',
        'users_activity': 'نشاط المستخدمين',
        'warehouses_status': 'حالة المخازن',
        'company_name': 'اسم الشركة',
        'company_logo': 'شعار الشركة',
        'company_currency': 'العملة',
        'company_timezone': 'المنطقة الزمنية',
        'company_language': 'اللغة',
        'company_address': 'العنوان',
        'company_phone': 'الهاتف',
        'company_email': 'البريد الإلكتروني',
        'login': 'تسجيل الدخول',
        'logout': 'تسجيل الخروج',
        'forgot_password': 'نسيت كلمة المرور؟',
        'remember_me': 'تذكرني',
        'change_password': 'تغيير كلمة المرور',
        'current_password': 'كلمة المرور الحالية',
        'new_password': 'كلمة المرور الجديدة',
        'confirm_password': 'تأكيد كلمة المرور'
    },
    
    en: {
        'dashboard': 'Dashboard',
        'products': 'Products',
        'warehouses': 'Warehouses',
        'users': 'Users',
        'receipts': 'Receipts',
        'issues': 'Issues',
        'transfers': 'Transfers',
        'returns': 'Returns',
        'reports': 'Reports',
        'settings': 'Settings',
        'audit': 'Audit',
        'notifications': 'Notifications',
        'stock_balances': 'Stock Balances',
        'stock_movements': 'Stock Movements',
        'categories': 'Categories',
        'units': 'Units',
        'backup': 'Backup',
        'add': 'Add',
        'edit': 'Edit',
        'delete': 'Delete',
        'save': 'Save',
        'cancel': 'Cancel',
        'search': 'Search',
        'refresh': 'Refresh',
        'export': 'Export',
        'print': 'Print',
        'view': 'View',
        'close': 'Close',
        'confirm': 'Confirm',
        'yes': 'Yes',
        'no': 'No',
        'submit': 'Submit',
        'loading': 'Loading...',
        'success': 'Success',
        'error': 'Error',
        'warning': 'Warning',
        'info': 'Info',
        'confirm_delete': 'Are you sure you want to delete?',
        'confirm_logout': 'Are you sure you want to logout?',
        'no_data': 'No data available',
        'no_results': 'No results found',
        'session_expired': 'Session expired. Please login again',
        'permission_denied': 'You do not have permission to perform this action',
        'username': 'Username',
        'password': 'Password',
        'full_name': 'Full Name',
        'email': 'Email',
        'phone': 'Phone',
        'role': 'Role',
        'warehouse': 'Warehouse',
        'status': 'Status',
        'active': 'Active',
        'inactive': 'Inactive',
        'created_at': 'Created At',
        'updated_at': 'Updated At',
        'actions': 'Actions',
        'product_code': 'Product Code',
        'product_name': 'Product Name',
        'barcode': 'Barcode',
        'sku': 'SKU',
        'category': 'Category',
        'unit': 'Unit',
        'min_stock': 'Min Stock',
        'max_stock': 'Max Stock',
        'current_stock': 'Current Stock',
        'stock_status': 'Stock Status',
        'warehouse_code': 'Warehouse Code',
        'warehouse_name': 'Warehouse Name',
        'warehouse_type': 'Warehouse Type',
        'location': 'Location',
        'manager': 'Manager',
        'capacity': 'Capacity',
        'movement_type': 'Movement Type',
        'quantity': 'Quantity',
        'from_warehouse': 'From Warehouse',
        'to_warehouse': 'To Warehouse',
        'notes': 'Notes',
        'reference': 'Reference',
        'user': 'User',
        'date': 'Date',
        'RECEIPT': 'Receipt',
        'ISSUE': 'Issue',
        'TRANSFER_OUT': 'Transfer Out',
        'TRANSFER_IN': 'Transfer In',
        'RETURN_IN': 'Return To Warehouse',
        'RETURN_OUT': 'Return From Warehouse',
        'ADJUSTMENT': 'Adjustment',
        'COUNT_CORRECTION': 'Count Correction',
        'RESERVATION': 'Reservation',
        'RELEASE': 'Release',
        'products_by_category': 'Products by Category',
        'daily_movements': 'Daily Movements',
        'low_stock': 'Low Stock',
        'out_of_stock': 'Out of Stock',
        'product_movements': 'Product Movements',
        'warehouse_movements': 'Warehouse Movements',
        'returns_report': 'Returns Report',
        'users_activity': 'Users Activity',
        'warehouses_status': 'Warehouses Status',
        'company_name': 'Company Name',
        'company_logo': 'Company Logo',
        'company_currency': 'Currency',
        'company_timezone': 'Timezone',
        'company_language': 'Language',
        'company_address': 'Address',
        'company_phone': 'Phone',
        'company_email': 'Email',
        'login': 'Login',
        'logout': 'Logout',
        'forgot_password': 'Forgot Password?',
        'remember_me': 'Remember Me',
        'change_password': 'Change Password',
        'current_password': 'Current Password',
        'new_password': 'New Password',
        'confirm_password': 'Confirm Password'
    }
};

function t(key) {
    const lang = localStorage.getItem('app_language') || 'ar';
    const translations = TRANSLATIONS[lang] || TRANSLATIONS.ar;
    return translations[key] || key;
}

// تصدير
window.APP_CONFIG = APP_CONFIG;
window.TRANSLATIONS = TRANSLATIONS;
window.t = t;

} // نهاية منع التكرار

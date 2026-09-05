/**
 * ================================================================
 * Logistox - إدارة WebSocket والتحديث اللحظي
 * نظام إدارة المخازن والمخزون v5.0
 * ================================================================
 */

// منع التكرار
if (typeof window.WebSocketManager === 'undefined') {

const WebSocketManager = {
    // ================================================================
    // الخصائص
    // ================================================================
    socket: null,
    isConnected: false,
    reconnectAttempts: 0,
    maxReconnectAttempts: 10,
    reconnectInterval: 3000,
    listeners: new Map(),
    messageQueue: [],
    heartbeatInterval: null,

    // ================================================================
    // الاتصال بالخادم
    // ================================================================
    connect() {
        if (typeof window.Auth !== 'undefined' && !window.Auth.isAuthenticated()) {
            console.log('المستخدم غير مسجل، لا يمكن الاتصال بـ WebSocket');
            return;
        }

        try {
            this.socket = new WebSocket('ws://localhost:8080');

            this.socket.onopen = () => this.handleOpen();
            this.socket.onmessage = (event) => this.handleMessage(event);
            this.socket.onerror = (error) => this.handleError(error);
            this.socket.onclose = (event) => this.handleClose(event);

        } catch (error) {
            console.error('WebSocket connection error:', error);
            this.handleReconnect();
        }
    },

    // ================================================================
    // معالجة الاتصال
    // ================================================================
    handleOpen() {
        this.isConnected = true;
        this.reconnectAttempts = 0;
        console.log('✅ WebSocket متصل');

        this.flushMessageQueue();
        this.startHeartbeat();

        const user = typeof window.Auth !== 'undefined' ? window.Auth.getUser() : null;

        this.send({
            type: 'subscribe',
            channel: 'user_' + (user?.id || 'anonymous'),
            data: {
                username: user?.username || '',
                full_name: user?.full_name || ''
            }
        });

        window.dispatchEvent(new CustomEvent('websocket:connected'));
    },

    // ================================================================
    // معالجة الرسائل
    // ================================================================
    handleMessage(event) {
        try {
            const message = JSON.parse(event.data);

            if (message.type) {
                this.emit(message.type, message.data);
                window.dispatchEvent(new CustomEvent('websocket:message', { detail: message }));
                this.handleMessageType(message);
            }
        } catch (error) {
            console.error('WebSocket message error:', error);
        }
    },

    // ================================================================
    // معالجة أنواع الرسائل
    // ================================================================
    handleMessageType(message) {
        switch (message.type) {
            case 'notification':
                this.handleNotification(message.data);
                break;
            case 'stock_alert':
                this.handleStockAlert(message.data);
                break;
            case 'movement':
                this.handleMovement(message.data);
                break;
            case 'update':
                this.handleUpdate(message.data);
                break;
            case 'sync':
                this.handleSync(message.data);
                break;
            case 'auth':
                this.handleAuth(message.data);
                break;
        }
    },

    // ================================================================
    // معالجة الإشعارات
    // ================================================================
    handleNotification(notification) {
        const type = notification.type || 'info';
        const title = notification.title || 'إشعار جديد';
        const message = notification.message || '';

        if (typeof window.showToast === 'function') {
            window.showToast(title + ': ' + message, type);
        }

        if ('Notification' in window && Notification.permission === 'granted') {
            const browserNotification = new Notification(title, {
                body: message,
                icon: '/inventory-system/frontend/assets/images/logo.png'
            });

            browserNotification.onclick = () => {
                window.focus();
                browserNotification.close();
            };
        }

        if (typeof window.Api !== 'undefined') {
            this.updateNotificationBadge();
        }
    },

    // ================================================================
    // معالجة تنبيهات المخزون
    // ================================================================
    handleStockAlert(alert) {
        const type = alert.alert_type || 'warning';
        const productName = alert.product_name || 'منتج';
        const currentStock = alert.current_stock || 0;
        const minStock = alert.min_stock || 0;

        let message = '';

        if (type === 'out_of_stock') {
            message = `⚠️ نفد المخزون: ${productName}`;
        } else if (type === 'low_stock') {
            message = `📉 مخزون منخفض: ${productName} (المخزون: ${currentStock}، الحد الأدنى: ${minStock})`;
        }

        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
        }

        if (window.location.pathname.includes('dashboard') && typeof window.loadDashboard === 'function') {
            window.loadDashboard();
        }

        if (window.location.pathname.includes('products') && typeof window.loadProducts === 'function') {
            window.loadProducts();
        }
    },

    // ================================================================
    // معالجة الحركات
    // ================================================================
    handleMovement(movement) {
        if (window.location.pathname.includes('dashboard') && typeof window.loadDashboard === 'function') {
            window.loadDashboard();
        }

        if (window.location.pathname.includes('products') && typeof window.loadProducts === 'function') {
            window.loadProducts();
        }

        if (window.location.pathname.includes('warehouses') && typeof window.loadWarehouses === 'function') {
            window.loadWarehouses();
        }

        if (window.location.pathname.includes('stock') && typeof window.loadStockMovements === 'function') {
            window.loadStockMovements();
        }
    },

    // ================================================================
    // معالجة التحديثات
    // ================================================================
    handleUpdate(update) {
        const page = window.location.pathname.split('/').pop().replace('.html', '');

        switch (page) {
            case 'dashboard':
                if (typeof window.loadDashboard === 'function') window.loadDashboard();
                break;
            case 'products':
                if (typeof window.loadProducts === 'function') window.loadProducts();
                break;
            case 'warehouses':
                if (typeof window.loadWarehouses === 'function') window.loadWarehouses();
                break;
            case 'users':
                if (typeof window.loadUsers === 'function') window.loadUsers();
                break;
            case 'receipts':
                if (typeof window.loadReceipts === 'function') window.loadReceipts();
                break;
            case 'issues':
                if (typeof window.loadIssues === 'function') window.loadIssues();
                break;
            case 'transfers':
                if (typeof window.loadTransfers === 'function') window.loadTransfers();
                break;
            case 'returns':
                if (typeof window.loadReturns === 'function') window.loadReturns();
                break;
            case 'stock-balances':
                if (typeof window.loadStockBalances === 'function') window.loadStockBalances();
                break;
            case 'stock-movements':
                if (typeof window.loadStockMovements === 'function') window.loadStockMovements();
                break;
            case 'notifications':
                if (typeof window.loadNotifications === 'function') window.loadNotifications();
                break;
        }
    },

    // ================================================================
    // معالجة المزامنة
    // ================================================================
    handleSync(syncData) {
        if (syncData.type === 'settings' && window.location.pathname.includes('settings') && typeof window.loadSettings === 'function') {
            window.loadSettings();
        }

        if (syncData.type === 'users' && window.location.pathname.includes('users') && typeof window.loadUsers === 'function') {
            window.loadUsers();
        }

        if (syncData.type === 'permissions' && typeof window.Auth !== 'undefined') {
            window.Auth.refreshSession();
        }
    },

    // ================================================================
    // معالجة المصادقة
    // ================================================================
    handleAuth(authData) {
        if (authData.action === 'logout') {
            if (typeof window.showToast === 'function') {
                window.showToast('⚠️ تم تسجيل الخروج من جهاز آخر', 'warning');
            }
            setTimeout(() => {
                if (typeof window.Auth !== 'undefined') window.Auth.logout();
            }, 2000);
        }

        if (authData.action === 'password_changed') {
            if (typeof window.showToast === 'function') {
                window.showToast('🔄 تم تغيير كلمة المرور من جهاز آخر', 'warning');
            }
        }
    },

    // ================================================================
    // إرسال رسالة
    // ================================================================
    send(message) {
        if (this.isConnected && this.socket && this.socket.readyState === WebSocket.OPEN) {
            try {
                this.socket.send(JSON.stringify(message));
                return true;
            } catch (error) {
                console.error('WebSocket send error:', error);
                return false;
            }
        } else {
            this.messageQueue.push(message);
            return false;
        }
    },

    // ================================================================
    // تفريغ قائمة الرسائل المعلقة
    // ================================================================
    flushMessageQueue() {
        while (this.messageQueue.length > 0) {
            const message = this.messageQueue.shift();
            this.send(message);
        }
    },

    // ================================================================
    // إضافة مستمع
    // ================================================================
    on(eventType, callback) {
        if (!this.listeners.has(eventType)) {
            this.listeners.set(eventType, []);
        }
        this.listeners.get(eventType).push(callback);
        return () => this.off(eventType, callback);
    },

    // ================================================================
    // إزالة مستمع
    // ================================================================
    off(eventType, callback) {
        const listeners = this.listeners.get(eventType);
        if (listeners) {
            const index = listeners.indexOf(callback);
            if (index > -1) listeners.splice(index, 1);
        }
    },

    // ================================================================
    // إرسال حدث للمستمعين
    // ================================================================
    emit(eventType, data) {
        const listeners = this.listeners.get(eventType);
        if (listeners) {
            listeners.forEach(callback => {
                try {
                    callback(data);
                } catch (error) {}
            });
        }
    },

    // ================================================================
    // معالجة الأخطاء
    // ================================================================
    handleError(error) {
        console.error('WebSocket error:', error);
    },

    // ================================================================
    // معالجة انقطاع الاتصال
    // ================================================================
    handleClose(event) {
        this.isConnected = false;
        console.log('WebSocket disconnected');
        this.stopHeartbeat();
        window.dispatchEvent(new CustomEvent('websocket:disconnected'));
        this.handleReconnect();
    },

    // ================================================================
    // إعادة الاتصال
    // ================================================================
    handleReconnect() {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            console.error('WebSocket: تم الوصول إلى الحد الأقصى لمحاولات إعادة الاتصال');
            return;
        }

        this.reconnectAttempts++;
        console.log(`🔁 إعادة محاولة الاتصال (${this.reconnectAttempts}/${this.maxReconnectAttempts})`);

        setTimeout(() => {
            this.connect();
        }, this.reconnectInterval);
    },

    // ================================================================
    // نبض القلب
    // ================================================================
    startHeartbeat() {
        this.stopHeartbeat();
        this.heartbeatInterval = setInterval(() => {
            this.send({ type: 'ping', data: { timestamp: Date.now() } });
        }, 30000);
    },

    // ================================================================
    // إيقاف نبض القلب
    // ================================================================
    stopHeartbeat() {
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
    },

    // ================================================================
    // تحديث عداد الإشعارات
    // ================================================================
    updateNotificationBadge() {
        const notifCount = document.getElementById('notifCount');
        const notifDot = document.getElementById('notifDot');

        if (notifCount && typeof window.Api !== 'undefined') {
            window.Api.getNotifications().then(notifications => {
                const unreadCount = notifications.filter(n => !n.is_read).length;

                if (unreadCount > 0) {
                    notifCount.textContent = unreadCount;
                    notifCount.classList.add('active');
                    if (notifDot) notifDot.classList.add('active');
                } else {
                    notifCount.classList.remove('active');
                    if (notifDot) notifDot.classList.remove('active');
                }
            }).catch(error => console.error('Error loading notifications:', error));
        }
    },

    // ================================================================
    // فصل الاتصال
    // ================================================================
    disconnect() {
        if (this.socket) {
            this.socket.close();
            this.socket = null;
        }
        this.isConnected = false;
        this.stopHeartbeat();
    },

    // ================================================================
    // إعادة الاتصال
    // ================================================================
    reconnect() {
        this.disconnect();
        this.reconnectAttempts = 0;
        this.connect();
    },

    // ================================================================
    // تهيئة WebSocket
    // ================================================================
    init() {
        console.log('ℹ️ WebSocket معطل حالياً - سيتم تفعيله لاحقاً');
        console.log('✅ WebSocket Manager initialized');
    }
};

// تصدير
window.WebSocketManager = WebSocketManager;

} // نهاية منع التكرار

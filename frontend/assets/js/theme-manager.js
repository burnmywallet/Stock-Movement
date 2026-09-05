/**
 * ================================================================
 * Logistox - مدير الثيمات المتقدم
 * نظام إدارة المخازن والمخزون v5.0
 * ================================================================
 */

// منع التكرار
if (typeof window.ThemeManager === 'undefined') {

const ThemeManager = {
    // ================================================================
    // الإعدادات
    // ================================================================
    currentTheme: 'dark',
    storageKey: 'theme_preference',
    
    themes: [
        { 
            name: 'dark', 
            display_name: 'داكن', 
            icon: 'fa-moon', 
            colors: { 
                primary: '#667eea', 
                bg: '#0a0e1a',
                card: 'rgba(255,255,255,0.03)',
                border: 'rgba(255,255,255,0.05)',
                text: '#ffffff',
                textSecondary: 'rgba(255,255,255,0.6)',
                textMuted: 'rgba(255,255,255,0.3)'
            } 
        },
        { 
            name: 'light', 
            display_name: 'فاتح', 
            icon: 'fa-sun', 
            colors: { 
                primary: '#667eea', 
                bg: '#f0f2f5',
                card: 'rgba(255,255,255,0.9)',
                border: 'rgba(0,0,0,0.08)',
                text: '#1a2332',
                textSecondary: 'rgba(0,0,0,0.6)',
                textMuted: 'rgba(0,0,0,0.3)'
            } 
        },
        { 
            name: 'purple', 
            display_name: 'بنفسجي', 
            icon: 'fa-palette', 
            colors: { 
                primary: '#9b59b6', 
                bg: '#1a0a2e',
                card: 'rgba(155,89,182,0.05)',
                border: 'rgba(155,89,182,0.1)',
                text: '#ffffff',
                textSecondary: 'rgba(255,255,255,0.6)',
                textMuted: 'rgba(255,255,255,0.3)'
            } 
        },
        { 
            name: 'blue', 
            display_name: 'أزرق', 
            icon: 'fa-water', 
            colors: { 
                primary: '#3498db', 
                bg: '#0a1a2e',
                card: 'rgba(52,152,219,0.05)',
                border: 'rgba(52,152,219,0.1)',
                text: '#ffffff',
                textSecondary: 'rgba(255,255,255,0.6)',
                textMuted: 'rgba(255,255,255,0.3)'
            } 
        },
        { 
            name: 'green', 
            display_name: 'أخضر', 
            icon: 'fa-leaf', 
            colors: { 
                primary: '#27ae60', 
                bg: '#0a1a0a',
                card: 'rgba(39,174,96,0.05)',
                border: 'rgba(39,174,96,0.1)',
                text: '#ffffff',
                textSecondary: 'rgba(255,255,255,0.6)',
                textMuted: 'rgba(255,255,255,0.3)'
            } 
        },
        { 
            name: 'red', 
            display_name: 'أحمر', 
            icon: 'fa-fire', 
            colors: { 
                primary: '#e74c3c', 
                bg: '#1a0a0a',
                card: 'rgba(231,76,60,0.05)',
                border: 'rgba(231,76,60,0.1)',
                text: '#ffffff',
                textSecondary: 'rgba(255,255,255,0.6)',
                textMuted: 'rgba(255,255,255,0.3)'
            } 
        },
        { 
            name: 'gold', 
            display_name: 'ذهبي', 
            icon: 'fa-star', 
            colors: { 
                primary: '#f39c12', 
                bg: '#1a140a',
                card: 'rgba(243,156,18,0.05)',
                border: 'rgba(243,156,18,0.1)',
                text: '#ffffff',
                textSecondary: 'rgba(255,255,255,0.6)',
                textMuted: 'rgba(255,255,255,0.3)'
            } 
        },
        { 
            name: 'pink', 
            display_name: 'وردي', 
            icon: 'fa-heart', 
            colors: { 
                primary: '#e91e63', 
                bg: '#1a0a14',
                card: 'rgba(233,30,99,0.05)',
                border: 'rgba(233,30,99,0.1)',
                text: '#ffffff',
                textSecondary: 'rgba(255,255,255,0.6)',
                textMuted: 'rgba(255,255,255,0.3)'
            } 
        }
    ],

    // ================================================================
    // الحصول على الثيم الحالي
    // ================================================================
    getCurrentTheme() {
        return localStorage.getItem(this.storageKey) || 'dark';
    },

    // ================================================================
    // الحصول على جميع الثيمات
    // ================================================================
    getAllThemes() {
        return this.themes;
    },

    // ================================================================
    // تطبيق الثيم
    // ================================================================
    setTheme(themeName) {
        // التحقق من وجود الثيم
        const theme = this.themes.find(t => t.name === themeName);
        if (!theme) {
            themeName = 'dark';
        }

        // حفظ التفضيل
        localStorage.setItem(this.storageKey, themeName);
        this.currentTheme = themeName;

        // تطبيق الألوان
        const root = document.documentElement;
        const colors = theme.colors;
        
        root.style.setProperty('--primary', colors.primary);
        root.style.setProperty('--bg-dark', colors.bg);
        root.style.setProperty('--bg-card', colors.card);
        root.style.setProperty('--border-color', colors.border);
        root.style.setProperty('--text-primary', colors.text);
        root.style.setProperty('--text-secondary', colors.textSecondary);
        root.style.setProperty('--text-muted', colors.textMuted);

        // تحديث أيقونة الثيم
        const themeToggle = document.querySelector('#themeToggle i');
        if (themeToggle) {
            themeToggle.className = 'fas ' + theme.icon;
        }

        // تحديث شريط العنوان
        document.title = 'نظام المخازن - ' + theme.display_name;

        // إرسال حدث تغيير الثيم
        window.dispatchEvent(new CustomEvent('themeChanged', { detail: theme }));

        console.log('🎨 تم تطبيق الثيم: ' + theme.display_name);
        
        return theme;
    },

    // ================================================================
    // تبديل الثيم
    // ================================================================
    toggleTheme() {
        const current = this.getCurrentTheme();
        const themes = this.themes;
        const currentIndex = themes.findIndex(t => t.name === current);
        const nextIndex = (currentIndex + 1) % themes.length;
        return this.setTheme(themes[nextIndex].name);
    },

    // ================================================================
    // الحصول على ثيم عشوائي
    // ================================================================
    getRandomTheme() {
        const randomIndex = Math.floor(Math.random() * this.themes.length);
        return this.themes[randomIndex].name;
    },

    // ================================================================
    // تهيئة الثيم
    // ================================================================
    init() {
        const savedTheme = this.getCurrentTheme();
        const theme = this.setTheme(savedTheme);
        
        // إعداد مستمع التبديل
        const themeToggle = document.querySelector('#themeToggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                const newTheme = this.toggleTheme();
                window.showToast('🎨 تم التبديل إلى ' + newTheme.display_name, 'success');
            });
        }

        // إعداد قائمة الثيمات إذا وجدت
        this.initThemeSelector();

        return theme;
    },

    // ================================================================
    // إعداد منتقي الثيمات
    // ================================================================
    initThemeSelector() {
        const themeSelector = document.getElementById('themeSelector');
        if (!themeSelector) return;

        // إنشاء قائمة الثيمات
        themeSelector.innerHTML = this.themes.map(theme => `
            <div class="theme-option ${theme.name === this.currentTheme ? 'active' : ''}" 
                 data-theme="${theme.name}"
                 onclick="ThemeManager.setTheme('${theme.name}')">
                <i class="fas ${theme.icon}"></i>
                <span>${theme.display_name}</span>
            </div>
        `).join('');

        // إضافة أنماط القائمة
        const style = document.createElement('style');
        style.textContent = `
            .theme-selector {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                padding: 15px;
            }
            .theme-option {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 15px;
                border-radius: 8px;
                cursor: pointer;
                border: 1px solid var(--border-color);
                background: var(--bg-card);
                transition: all 0.3s ease;
            }
            .theme-option:hover {
                border-color: var(--primary);
            }
            .theme-option.active {
                background: var(--primary);
                color: white;
            }
            .theme-option i {
                font-size: 16px;
            }
        `;
        document.head.appendChild(style);
    },

    // ================================================================
    // تحديث الثيم تلقائياً حسب الوقت
    // ================================================================
    initAutoTheme() {
        const savedPreference = localStorage.getItem('auto_theme');
        if (savedPreference === 'false') return;

        const hour = new Date().getHours();
        const isNight = hour >= 19 || hour < 6;

        if (isNight && this.getCurrentTheme() === 'light') {
            this.setTheme('dark');
        } else if (!isNight && this.getCurrentTheme() === 'dark') {
            this.setTheme('light');
        }
    },

    // ================================================================
    // تصدير إعدادات الثيم
    // ================================================================
    exportThemeSettings() {
        return {
            currentTheme: this.currentTheme,
            themes: this.themes
        };
    },

    // ================================================================
    // استيراد إعدادات الثيم
    // ================================================================
    importThemeSettings(settings) {
        if (settings.currentTheme) {
            this.setTheme(settings.currentTheme);
        }
        return true;
    }
};

// تصدير
window.ThemeManager = ThemeManager;

} // نهاية منع التكرار

-- ================================================================
-- نظام إدارة المخازن والمخزون المتقدم
-- الملف: 04_sample_data_comprehensive.sql
-- الإصدار: 2.0 Production Ready
-- التاريخ: 2026-08-20
-- ================================================================
--
-- هذا الملف يحتوي على:
-- 1. بيانات تجريبية شاملة لجميع الجداول
-- 2. تصنيفات متعددة المستويات
-- 3. وحدات قياس متنوعة
-- 4. موردين وجهات متعددة
-- 5. أصناف متنوعة (500+ صنف)
-- 6. مخازن متعددة
-- 7. أرصدة أولية
-- 8. حركات مخزون متنوعة
-- 9. مستندات (استلام، صرف، تحويل، مرتجع)
-- 10. جلسات جرد
-- 11. تنبيهات
-- 12. سجلات تدقيق
-- 13. بيانات لتقارير متقدمة
-- ================================================================

USE inventory_system;

-- ================================================================
-- 1. التصنيفات المتقدمة (متعددة المستويات)
-- ================================================================

INSERT INTO categories (code, name, description, parent_id, icon, color, is_active, sort_order) VALUES
-- المستوى الأول: إلكترونيات
('CAT-ELEC', 'الإلكترونيات', 'جميع المنتجات الإلكترونية والأجهزة', NULL, 'fa-laptop', '#4A90D9', 1, 10),
-- المستوى الثاني: أجهزة كمبيوتر
('CAT-COMP', 'أجهزة كمبيوتر', 'أجهزة كمبيوتر وملحقاتها', 1, 'fa-desktop', '#5B9BD5', 1, 11),
('CAT-LAPTOP', 'لابتوبات', 'أجهزة لابتوب محمولة', 2, 'fa-laptop', '#6AA6E0', 1, 12),
('CAT-DESKTOP', 'أجهزة مكتبية', 'أجهزة كمبيوتر مكتبية', 2, 'fa-desktop', '#7BB1EA', 1, 13),
('CAT-PARTS', 'قطع غيار كمبيوتر', 'قطع غيار ومكونات الكمبيوتر', 2, 'fa-microchip', '#8CBDF4', 1, 14),
('CAT-PRINTERS', 'طابعات', 'طابعات وماسحات ضوئية', 2, 'fa-print', '#9DC8FD', 1, 15),

-- المستوى الثاني: هواتف محمولة
('CAT-PHONES', 'هواتف محمولة', 'هواتف وملحقاتها', 1, 'fa-mobile-alt', '#E74C3C', 1, 20),
('CAT-SMARTPHONES', 'هواتف ذكية', 'هواتف ذكية من جميع الماركات', 7, 'fa-mobile-screen', '#E85C4C', 1, 21),
('CAT-ACCESSORIES', 'إكسسوارات', 'إكسسوارات الهواتف', 7, 'fa-headphones', '#F06C5C', 1, 22),

-- المستوى الثاني: أجهزة منزلية
('CAT-APPLIANCES', 'أجهزة منزلية', 'أجهزة منزلية كهربائية', 1, 'fa-house', '#F39C12', 1, 30),
('CAT-KITCHEN', 'أجهزة مطبخ', 'أجهزة المطبخ الكهربائية', 10, 'fa-blender', '#F4A62A', 1, 31),
('CAT-CLEANING', 'أجهزة تنظيف', 'أجهزة التنظيف المنزلية', 10, 'fa-broom', '#F5B042', 1, 32),

-- المستوى الأول: مواد غذائية
('CAT-FOOD', 'مواد غذائية', 'جميع المنتجات الغذائية', NULL, 'fa-utensils', '#27AE60', 1, 40),
('CAT-GRAINS', 'حبوب', 'حبوب وبقوليات', 13, 'fa-wheat', '#2ECC71', 1, 41),
('CAT-OILS', 'زيوت', 'زيوت ودهون', 13, 'fa-oil-can', '#3DDC84', 1, 42),
('CAT-CANNED', 'مواد معلبة', 'مواد غذائية معلبة', 13, 'fa-can-food', '#4EE897', 1, 43),

-- المستوى الثاني: مشروبات
('CAT-BEVERAGES', 'مشروبات', 'مشروبات غازية وعصائر', 13, 'fa-wine-bottle', '#3498DB', 1, 44),
('CAT-SOFTDRINKS', 'مشروبات غازية', 'مشروبات غازية', 17, 'fa-mug-saucer', '#45A9E0', 1, 45),
('CAT-JUICES', 'عصائر', 'عصائر طبيعية', 17, 'fa-glass-water', '#56BAEC', 1, 46),
('CAT-WATER', 'مياه', 'مياه معدنية ومقطرة', 17, 'fa-droplet', '#67CBF8', 1, 47),

-- المستوى الأول: مواد بناء
('CAT-CONSTRUCTION', 'مواد بناء', 'مواد البناء والتشييد', NULL, 'fa-hard-hat', '#E67E22', 1, 50),
('CAT-CEMENT', 'أسمنت', 'أسمنت بمختلف أنواعه', 21, 'fa-helmet-safety', '#F08C3A', 1, 51),
('CAT-BRICKS', 'طوب', 'طوب بمختلف أنواعه', 21, 'fa-border-all', '#FA9A4A', 1, 52),
('CAT-PIPES', 'مواسير', 'مواسير ولوازم السباكة', 21, 'fa-water', '#FFA85A', 1, 53),

-- المستوى الأول: أدوات مكتبية
('CAT-OFFICE', 'أدوات مكتبية', 'أدوات ومستلزمات مكتبية', NULL, 'fa-pen', '#9B59B6', 1, 60),
('CAT-PAPER', 'ورقيات', 'ورق ودفاتر ومستلزمات الكتابة', 25, 'fa-paper', '#AF6BC8', 1, 61),
('CAT-STATIONARY', 'قرطاسية', 'أدوات قرطاسية', 25, 'fa-pencil', '#C37DD4', 1, 62),
('CAT-FURNITURE', 'أثاث مكتبي', 'أثاث ومعدات مكتبية', 25, 'fa-chair', '#D78FE0', 1, 63),

-- المستوى الأول: ملابس
('CAT-CLOTHING', 'ملابس', 'ملابس رجالية ونسائية', NULL, 'fa-shirt', '#E74C3C', 1, 70),
('CAT-MEN', 'رجالي', 'ملابس رجالية', 29, 'fa-vest', '#E85C4C', 1, 71),
('CAT-WOMEN', 'نسائي', 'ملابس نسائية', 29, 'fa-dress', '#F06C5C', 1, 72),
('CAT-KIDS', 'أطفال', 'ملابس أطفال', 29, 'fa-child', '#F87C6C', 1, 73),

-- المستوى الأول: مستلزمات طبية
('CAT-MEDICAL', 'مستلزمات طبية', 'مستلزمات ومعدات طبية', NULL, 'fa-heart-pulse', '#2ECC71', 1, 80),
('CAT-EQUIPMENT', 'معدات طبية', 'معدات طبية', 33, 'fa-stethoscope', '#3DDC84', 1, 81),
('CAT-SUPPLIES', 'مستهلكات طبية', 'مستهلكات طبية', 33, 'fa-bandage', '#4EE897', 1, 82);

-- ================================================================
-- 2. الوحدات المتقدمة
-- ================================================================

INSERT INTO units (code, name, name_plural, symbol, is_base_unit, conversion_factor, base_unit_id, precision_digits, is_active) VALUES
-- وحدات أساسية
('U-001', 'قطعة', 'قطع', 'ق', 1, 1.000000, NULL, 0, 1),
('U-002', 'كرتونة', 'كراتين', 'كرت', 1, 1.000000, NULL, 0, 1),
('U-003', 'كيلوغرام', 'كيلوغرامات', 'كجم', 1, 1.000000, NULL, 3, 1),
('U-004', 'جرام', 'جرامات', 'جم', 0, 0.001000, 3, 3, 1),
('U-005', 'لتر', 'لترات', 'ل', 1, 1.000000, NULL, 3, 1),
('U-006', 'مليلتر', 'مليلترات', 'مل', 0, 0.001000, 5, 3, 1),
('U-007', 'متر', 'أمتار', 'م', 1, 1.000000, NULL, 2, 1),
('U-008', 'سنتيمتر', 'سنتيمترات', 'سم', 0, 0.010000, 7, 2, 1),
('U-009', 'طرد', 'طرود', 'ط', 1, 1.000000, NULL, 0, 1),
('U-010', 'حقيبة', 'حقائب', 'ح', 1, 1.000000, NULL, 0, 1),
('U-011', 'علبة', 'علب', 'ع', 1, 1.000000, NULL, 0, 1),
('U-012', 'زجاجة', 'زجاجات', 'ز', 1, 1.000000, NULL, 0, 1),
('U-013', 'طن', 'أطنان', 'طن', 1, 1000.000000, 3, 3, 1),
('U-014', 'كيس', 'أكياس', 'ك', 1, 1.000000, NULL, 0, 1),
('U-015', 'صندوق', 'صناديق', 'ص', 1, 1.000000, NULL, 0, 1),
('U-016', 'زوج', 'أزواج', 'زوج', 1, 1.000000, NULL, 0, 1);

-- ================================================================
-- 3. الموردين المتقدمين
-- ================================================================

INSERT INTO suppliers (code, name, contact_person, phone, mobile, email, website, address, city, country, tax_number, commercial_register, payment_terms, credit_limit, is_active, rating) VALUES
('SUP-001', 'شركة التقنية الحديثة', 'أحمد محمد العتيبي', '0112223333', '0501111111', 'info@techco.com', 'www.techco.com', 'الرياض - طريق الملك فهد - مبنى 10', 'الرياض', 'السعودية', '1012345678', 'CR-2024-001', '30 يوم', 500000.00, 1, 5),
('SUP-002', 'مؤسسة الغذاء الصحي', 'سارة عبدالله القحطاني', '0123334444', '0502222222', 'sales@foodco.com', 'www.foodco.com', 'جدة - شارع الأمير سلطان - مجمع 5', 'جدة', 'السعودية', '2012345678', 'CR-2024-002', '45 يوم', 300000.00, 1, 4),
('SUP-003', 'شركة البناء المتين', 'خالد سليمان الدوسري', '0134445555', '0503333333', 'info@buildingco.com', 'www.buildingco.com', 'الدمام - طريق الملك سعود - برج 3', 'الدمام', 'السعودية', '3012345678', 'CR-2024-003', '60 يوم', 1000000.00, 1, 5),
('SUP-004', 'مكتبة العلم والنور', 'نورة محمد السبيعي', '0145556666', '0504444444', 'books@science.com', 'www.science.com', 'مكة المكرمة - شارع الستين - مركز 2', 'مكة', 'السعودية', '4012345678', 'CR-2024-004', '30 يوم', 100000.00, 1, 4),
('SUP-005', 'شركة الألبان الطازجة', 'فهد سعد الحربي', '0156667777', '0505555555', 'dairy@fresh.com', 'www.fresh.com', 'المدينة المنورة - طريق الملك عبدالعزيز', 'المدينة', 'السعودية', '5012345678', 'CR-2024-005', '15 يوم', 200000.00, 1, 5),
('SUP-006', 'مؤسسة الأجهزة الطبية', 'عبدالله صالح الغامدي', '0167778888', '0506666666', 'info@medicalco.com', 'www.medicalco.com', 'الرياض - حي النخيل - شارع 20', 'الرياض', 'السعودية', '6012345678', 'CR-2024-006', '30 يوم', 800000.00, 1, 4),
('SUP-007', 'شركة الملابس العصرية', 'منى إبراهيم العمر', '0178889999', '0507777777', 'sales@fashionco.com', 'www.fashionco.com', 'جدة - حي الروضة - شارع الأمير ماجد', 'جدة', 'السعودية', '7012345678', 'CR-2024-007', '45 يوم', 400000.00, 1, 3),
('SUP-008', 'مؤسسة القرطاسية المتطورة', 'سامي عبدالرحمن الحميد', '0189990000', '0508888888', 'info@stationaryco.com', 'www.stationaryco.com', 'الرياض - حي العليا - شارع التخصصي', 'الرياض', 'السعودية', '8012345678', 'CR-2024-008', '30 يوم', 150000.00, 1, 5);

-- ================================================================
-- 4. الجهات (للصرف)
-- ================================================================

INSERT INTO recipients (code, name, type, contact_person, phone, email, address, is_active) VALUES
('REC-001', 'وزارة التعليم', 'external', 'مدير المشتريات', '0111111111', 'procurement@moe.gov.sa', 'الرياض - وزارة التعليم - مبنى 1', 1),
('REC-002', 'المستشفى العام', 'external', 'مسؤول المخازن', '0122222222', 'warehouse@hospital.com', 'جدة - مستشفى الملك عبدالعزيز', 1),
('REC-003', 'جامعة الملك سعود', 'external', 'مدير المختبرات', '0113333333', 'labs@ksu.edu.sa', 'الرياض - جامعة الملك سعود - مبنى 7', 1),
('REC-004', 'شركة الاتصالات السعودية', 'external', 'مدير الإمداد', '0144444444', 'supply@telecom.com', 'الرياض - طريق الملك فهد - برج الاتصالات', 1),
('REC-005', 'مدرسة الفيصلية', 'internal', 'مسؤول المخازن', '0155555555', 'storage@alfaisal.edu', 'الرياض - حي النخيل - شارع الأمير سلطان', 1),
('REC-006', 'قسم الصيانة', 'internal', 'مدير الصيانة', '0166666666', 'maintenance@company.com', 'الرياض - المنطقة الصناعية - مبنى 5', 1),
('REC-007', 'قسم المشاريع', 'internal', 'مدير المشاريع', '0177777777', 'projects@company.com', 'الرياض - حي العليا - برج 2', 1),
('REC-008', 'فرع جدة', 'internal', 'مدير الفرع', '0188888888', 'jeddah@company.com', 'جدة - شارع الأمير سلطان - مجمع 3', 1),
('REC-009', 'فرع الدمام', 'internal', 'مدير الفرع', '0199999999', 'dammam@company.com', 'الدمام - طريق الملك سعود - برج 5', 1),
('REC-010', 'شركة النقل', 'external', 'مدير المشتريات', '0110000000', 'transport@logistics.com', 'الرياض - طريق الخرج - منطقة صناعية', 1);

-- ================================================================
-- 5. المخازن المتقدمة
-- ================================================================

INSERT INTO warehouses (code, name, type, location, address, manager_id, phone, email, is_active, is_main, is_default, capacity, notes) VALUES
('W-001', 'المخزن الرئيسي - الرياض', 'main', 'الرياض - المنطقة الصناعية', 'الرياض - المنطقة الصناعية الثانية - شارع 10', 2, '0112345678', 'main@company.com', 1, 1, 1, 10000.00, 'المخزن الرئيسي للشركة'),
('W-002', 'مخزن جدة', 'sub', 'جدة - شارع الأمير ماجد', 'جدة - حي الروضة - شارع الأمير ماجد - مبنى 12', 3, '0122345678', 'jeddah@company.com', 1, 0, 0, 5000.00, 'مخزن فرع جدة'),
('W-003', 'مخزن الدمام', 'sub', 'الدمام - طريق الملك فهد', 'الدمام - حي الزهور - طريق الملك فهد - برج 3', 4, '0132345678', 'dammam@company.com', 1, 0, 0, 4000.00, 'مخزن فرع الدمام'),
('W-004', 'مخزن مكة', 'store', 'مكة المكرمة - حي العزيزية', 'مكة - حي العزيزية - شارع الستين - مبنى 5', 4, '0142345678', 'makkah@company.com', 1, 0, 0, 3000.00, 'مخزن فرع مكة'),
('W-005', 'مخزن الطوارئ', 'virtual', 'الرياض - المنطقة الصناعية', 'الرياض - المنطقة الصناعية الثانية - شارع 5', 2, '0112345679', 'emergency@company.com', 1, 0, 0, 2000.00, 'مخزن الطوارئ للمواد الحرجة');

-- ================================================================
-- 6. الأصناف الضخمة (500+ صنف)
-- ================================================================

-- إجراء لتوليد الأصناف تلقائياً
DELIMITER //

CREATE PROCEDURE generate_products()
BEGIN
    DECLARE v_counter INT DEFAULT 1;
    DECLARE v_category_id INT;
    DECLARE v_unit_id INT;
    DECLARE v_category_count INT;
    DECLARE v_categories CURSOR FOR SELECT id FROM categories WHERE is_active = 1;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_category_count = 0;
    
    -- حذف الأصناف القديمة (للتجديد)
    -- TRUNCATE TABLE products; -- سيتم تشغيله يدوياً
    
    -- فتح المؤشر
    OPEN v_categories;
    
    read_loop: LOOP
        FETCH v_categories INTO v_category_id;
        IF v_category_count = 0 THEN
            LEAVE read_loop;
        END IF;
        
        -- توليد 20 صنف لكل تصنيف
        SET v_counter = 1;
        WHILE v_counter <= 20 DO
            -- اختيار وحدة عشوائية
            SELECT id INTO v_unit_id FROM units WHERE is_active = 1 ORDER BY RAND() LIMIT 1;
            
            -- إدراج الصنف
            INSERT INTO products (
                code, barcode, name, description, category_id, unit_id, 
                min_stock, max_stock, reorder_point, reorder_quantity,
                cost_price, selling_price, weight, is_active, 
                is_serialized, is_batch_tracked, is_expirable, shelf_life_days,
                created_at, updated_at
            ) VALUES (
                CONCAT('P-', LPAD(v_category_id, 3, '0'), '-', LPAD(v_counter, 4, '0')),
                CONCAT('62910415', LPAD(FLOOR(RAND() * 10000), 6, '0')),
                CONCAT('صنف ', v_counter, ' من التصنيف ', (SELECT name FROM categories WHERE id = v_category_id)),
                CONCAT('وصف تفصيلي للصنف رقم ', v_counter, ' من التصنيف ', (SELECT name FROM categories WHERE id = v_category_id)),
                v_category_id,
                v_unit_id,
                FLOOR(10 + RAND() * 50),
                FLOOR(100 + RAND() * 400),
                FLOOR(5 + RAND() * 20),
                FLOOR(20 + RAND() * 100),
                ROUND(10 + RAND() * 500, 2),
                ROUND(15 + RAND() * 700, 2),
                ROUND(0.5 + RAND() * 20, 2),
                1,
                IF(RAND() > 0.7, 1, 0),
                IF(RAND() > 0.8, 1, 0),
                IF(RAND() > 0.6, 1, 0),
                FLOOR(30 + RAND() * 365),
                DATE_SUB(NOW(), INTERVAL FLOOR(1 + RAND() * 100) DAY),
                NOW()
            );
            
            SET v_counter = v_counter + 1;
        END WHILE;
    END LOOP;
    
    CLOSE v_categories;
    
    -- تحديث عداد الأصناف
    UPDATE system_settings 
    SET setting_value = (SELECT COUNT(*) FROM products)
    WHERE setting_key = 'total_products';
END//

DELIMITER ;

-- تنفيذ توليد الأصناف (يمكن تشغيله بعد إنشاء الإجراء)
-- CALL generate_products();

-- بدلاً من ذلك، نقوم بإدخال أصناف محددة للاختبار الفوري

INSERT INTO products (code, barcode, name, description, category_id, unit_id, min_stock, max_stock, reorder_point, reorder_quantity, cost_price, selling_price, weight, is_active, is_serialized, is_batch_tracked, is_expirable, shelf_life_days, created_at) VALUES
-- إلكترونيات - أجهزة كمبيوتر
('P-001', '6291041500001', 'لابتوب HP ProBook 450', 'لابتوب HP ProBook 450 معالج Intel Core i7 - 16GB RAM - 512GB SSD', 2, 1, 5, 50, 10, 15, 3500.00, 4500.00, 2.50, 1, 1, 0, 0, NULL, NOW()),
('P-002', '6291041500002', 'لابتوب Dell Latitude 5430', 'لابتوب Dell Latitude 5430 معالج Intel Core i5 - 8GB RAM - 256GB SSD', 2, 1, 5, 40, 8, 12, 2800.00, 3600.00, 2.30, 1, 1, 0, 0, NULL, NOW()),
('P-003', '6291041500003', 'لابتوب MacBook Air M2', 'لابتوب Apple MacBook Air M2 - 8GB RAM - 256GB SSD', 2, 1, 3, 20, 5, 8, 4200.00, 5200.00, 1.50, 1, 1, 0, 0, NULL, NOW()),
('P-004', '6291041500004', 'شاشة Samsung 24" LED', 'شاشة سامسونج 24 بوصة LED - Full HD - 75Hz', 2, 1, 10, 50, 15, 20, 650.00, 850.00, 3.50, 1, 0, 0, 0, NULL, NOW()),
('P-005', '6291041500005', 'شاشة LG 27" 4K', 'شاشة LG 27 بوصة 4K UHD - IPS - HDR10', 2, 1, 5, 30, 8, 12, 1200.00, 1600.00, 4.00, 1, 0, 0, 0, NULL, NOW()),
('P-006', '6291041500006', 'ماوس لوجيتك MX Master 3', 'ماوس لاسلكي لوجيتك MX Master 3 - Bluetooth + USB', 2, 1, 20, 100, 30, 50, 350.00, 450.00, 0.20, 1, 0, 0, 0, NULL, NOW()),
('P-007', '6291041500007', 'كيبورد ميكانيكي Redragon', 'كيبورد ميكانيكي Redragon K530 - براون سويتش - RGB', 2, 1, 15, 80, 20, 30, 180.00, 250.00, 1.00, 1, 0, 0, 0, NULL, NOW()),

-- إلكترونيات - هواتف
('P-008', '6291041500008', 'آيفون 15 برو ماكس', 'هاتف Apple iPhone 15 Pro Max - 256GB - التيتانيوم', 7, 1, 3, 30, 5, 10, 4500.00, 5500.00, 0.25, 1, 1, 0, 0, NULL, NOW()),
('P-009', '6291041500009', 'سامسونج جالاكسي S24 Ultra', 'هاتف Samsung Galaxy S24 Ultra - 256GB - معالج Snapdragon', 7, 1, 3, 25, 5, 8, 4000.00, 4800.00, 0.23, 1, 1, 0, 0, NULL, NOW()),
('P-010', '6291041500010', 'شاحن سريع 65W', 'شاحن سريع USB-C 65W - منفذين', 7, 1, 30, 200, 50, 80, 80.00, 120.00, 0.15, 1, 0, 0, 0, NULL, NOW()),
('P-011', '6291041500011', 'سماعة أبل AirPods Pro 2', 'سماعة لاسلكية Apple AirPods Pro 2 - عزل ضوضاء', 7, 1, 10, 50, 15, 20, 550.00, 750.00, 0.10, 1, 0, 0, 0, NULL, NOW()),

-- مواد غذائية
('P-012', '6291041500012', 'أرز بسمتي باكستاني 10 كجم', 'أرز بسمتي باكستاني فاخر - 10 كجم', 14, 3, 50, 500, 100, 150, 25.00, 35.00, 10.00, 1, 0, 1, 0, NULL, NOW()),
('P-013', '6291041500013', 'أرز مصري 5 كجم', 'أرز مصري بلدي - 5 كجم', 14, 3, 30, 300, 60, 100, 15.00, 22.00, 5.00, 1, 0, 1, 0, NULL, NOW()),
('P-014', '6291041500014', 'زيت زيتون بكر ممتاز 2 لتر', 'زيت زيتون بكر ممتاز - 2 لتر', 15, 5, 20, 150, 40, 60, 55.00, 75.00, 2.00, 1, 0, 1, 1, 730, NOW()),
('P-015', '6291041500015', 'زيت دوار الشمس 5 لتر', 'زيت دوار الشمس - 5 لتر', 15, 5, 30, 200, 50, 80, 30.00, 42.00, 5.00, 1, 0, 1, 1, 365, NOW()),
('P-016', '6291041500016', 'طحين أبيض فاخر 2 كجم', 'طحين أبيض فاخر - 2 كجم', 14, 3, 50, 300, 80, 120, 6.00, 9.00, 2.00, 1, 0, 1, 1, 180, NOW()),
('P-017', '6291041500017', 'سكر أبيض 5 كجم', 'سكر أبيض ناعم - 5 كجم', 14, 3, 30, 200, 50, 80, 12.00, 18.00, 5.00, 1, 0, 0, 0, NULL, NOW()),

-- مشروبات
('P-018', '6291041500018', 'كوكا كولا 330 مل - علبة', 'كوكا كولا - 330 مل - علبة ألمنيوم', 18, 1, 100, 1000, 200, 300, 1.50, 2.50, 0.33, 1, 0, 0, 1, 365, NOW()),
('P-019', '6291041500019', 'بيبسي 330 مل - علبة', 'بيبسي - 330 مل - علبة ألمنيوم', 18, 1, 100, 1000, 200, 300, 1.50, 2.50, 0.33, 1, 0, 0, 1, 365, NOW()),
('P-020', '6291041500020', 'عصير برتقال طازج 1 لتر', 'عصير برتقال طازج 100% - 1 لتر', 19, 5, 30, 200, 50, 80, 5.00, 8.00, 1.00, 1, 0, 1, 1, 30, NOW()),
('P-021', '6291041500021', 'ماء معدني 1.5 لتر', 'ماء معدني نقي - 1.5 لتر', 20, 5, 50, 500, 100, 150, 1.00, 1.50, 1.50, 1, 0, 0, 1, 365, NOW()),
('P-022', '6291041500022', 'ماء معدني 330 مل', 'ماء معدني نقي - 330 مل', 20, 5, 100, 1000, 200, 300, 0.50, 1.00, 0.33, 1, 0, 0, 1, 365, NOW()),

-- مواد بناء
('P-023', '6291041500023', 'اسمنت بورتلاند 50 كجم', 'اسمنت بورتلاند عادي - 50 كجم', 22, 14, 100, 1000, 200, 300, 18.00, 25.00, 50.00, 1, 0, 1, 0, NULL, NOW()),
('P-024', '6291041500024', 'اسمنت مقاوم للملوحة 50 كجم', 'اسمنت مقاوم للملوحة - 50 كجم', 22, 14, 50, 500, 100, 150, 22.00, 30.00, 50.00, 1, 0, 1, 0, NULL, NOW()),
('P-025', '6291041500025', 'طوب أحمر 20x20x40 سم', 'طوب أحمر قياسي - 20x20x40 سم', 23, 1, 500, 5000, 1000, 1500, 3.00, 5.00, 3.00, 1, 0, 0, 0, NULL, NOW()),
('P-026', '6291041500026', 'طوب خفيف 10x20x40 سم', 'طوب خفيف الوزن - 10x20x40 سم', 23, 1, 300, 3000, 600, 1000, 4.50, 7.00, 2.00, 1, 0, 0, 0, NULL, NOW()),
('P-027', '6291041500027', 'مواسير PVC 4 بوصة', 'مواسير PVC 4 بوصة - 3 متر', 24, 7, 50, 500, 100, 150, 25.00, 35.00, 2.00, 1, 0, 0, 0, NULL, NOW()),

-- أدوات مكتبية
('P-028', '6291041500028', 'ورق A4 80 جرام - 500 ورقة', 'ورق A4 أبيض 80 جرام - عبوة 500 ورقة', 26, 1, 50, 500, 80, 120, 8.00, 12.00, 2.50, 1, 0, 0, 0, NULL, NOW()),
('P-029', '6291041500029', 'قلم حبر أزرق - عبوة 12', 'قلم حبر أزرق - عبوة 12 قطعة', 27, 1, 100, 1000, 150, 200, 1.50, 2.50, 0.05, 1, 0, 0, 0, NULL, NOW()),
('P-030', '6291041500030', 'دفتر ملاحظات 50 ورقة', 'دفتر ملاحظات 50 ورقة - مقاس A5', 27, 1, 50, 500, 80, 120, 4.00, 6.00, 0.20, 1, 0, 0, 0, NULL, NOW()),
('P-031', '6291041500031', 'طابعة HP LaserJet MFP', 'طابعة HP LaserJet متعددة الوظائف - طباعة - مسح - نسخ', 4, 1, 2, 20, 4, 6, 1200.00, 1600.00, 10.00, 1, 1, 0, 0, NULL, NOW()),
('P-032', '6291041500032', 'طابعة إبسون EcoTank', 'طابعة إبسون EcoTank - خراطيش ملونة', 4, 1, 2, 15, 4, 5, 900.00, 1200.00, 8.00, 1, 1, 0, 0, NULL, NOW()),

-- ملابس
('P-033', '6291041500033', 'قميص رجالي قطن 100%', 'قميص رجالي قطن 100% - مقاسات مختلفة', 30, 1, 20, 200, 40, 60, 60.00, 90.00, 0.30, 1, 0, 0, 0, NULL, NOW()),
('P-034', '6291041500034', 'بنطلون جينز رجالي', 'بنطلون جينز رجالي - مقاسات مختلفة', 30, 1, 15, 150, 30, 50, 90.00, 140.00, 0.50, 1, 0, 0, 0, NULL, NOW()),
('P-035', '6291041500035', 'حذاء رياضي رجالي', 'حذاء رياضي رجالي - مقاسات 39-45', 30, 16, 10, 100, 20, 30, 140.00, 200.00, 0.80, 1, 0, 0, 0, NULL, NOW()),

-- مستلزمات طبية
('P-036', '6291041500036', 'قفازات طبية معقمة - 100 قطعة', 'قفازات طبية معقمة - 100 قطعة - مقاسات مختلفة', 34, 1, 50, 500, 80, 120, 25.00, 40.00, 1.00, 1, 0, 1, 1, 730, NOW()),
('P-037', '6291041500037', 'كمامات طبية جراحية - 50 قطعة', 'كمامات طبية جراحية - 50 قطعة - 3 طبقات', 34, 1, 100, 1000, 150, 200, 10.00, 15.00, 0.50, 1, 0, 1, 1, 365, NOW()),
('P-038', '6291041500038', 'منظف معقم 5 لتر', 'منظف معقم للمستشفيات - 5 لتر', 34, 5, 20, 200, 40, 60, 45.00, 65.00, 5.00, 1, 0, 0, 1, 365, NOW());

-- ================================================================
-- 7. الأرصدة الأولية في المخازن
-- ================================================================

INSERT INTO stock_balances (product_id, warehouse_id, quantity, reserved_quantity) VALUES
-- المخزن الرئيسي (W-001) - كميات كبيرة
(1, 1, 25.000, 2.000),
(2, 1, 18.000, 1.000),
(3, 1, 8.000, 0.000),
(4, 1, 35.000, 5.000),
(5, 1, 12.000, 2.000),
(6, 1, 75.000, 10.000),
(7, 1, 45.000, 5.000),
(8, 1, 15.000, 2.000),
(9, 1, 12.000, 1.000),
(10, 1, 150.000, 20.000),
(11, 1, 30.000, 5.000),
(12, 1, 200.000, 30.000),
(13, 1, 120.000, 20.000),
(14, 1, 80.000, 10.000),
(15, 1, 150.000, 25.000),
(16, 1, 180.000, 30.000),
(17, 1, 100.000, 15.000),
(18, 1, 500.000, 80.000),
(19, 1, 450.000, 70.000),
(20, 1, 120.000, 20.000),
(21, 1, 300.000, 50.000),
(22, 1, 500.000, 80.000),
(23, 1, 500.000, 50.000),
(24, 1, 300.000, 30.000),
(25, 1, 2000.000, 200.000),
(26, 1, 1500.000, 150.000),
(27, 1, 200.000, 30.000),
(28, 1, 300.000, 50.000),
(29, 1, 500.000, 80.000),
(30, 1, 200.000, 30.000),
(31, 1, 10.000, 2.000),
(32, 1, 8.000, 1.000),
(33, 1, 80.000, 10.000),
(34, 1, 60.000, 8.000),
(35, 1, 40.000, 5.000),
(36, 1, 200.000, 30.000),
(37, 1, 300.000, 50.000),
(38, 1, 80.000, 10.000),

-- مخزن جدة (W-002)
(1, 2, 15.000, 2.000),
(3, 2, 5.000, 0.000),
(4, 2, 20.000, 3.000),
(6, 2, 40.000, 5.000),
(8, 2, 8.000, 1.000),
(11, 2, 20.000, 3.000),
(12, 2, 100.000, 15.000),
(14, 2, 50.000, 8.000),
(18, 2, 200.000, 30.000),
(21, 2, 150.000, 20.000),
(23, 2, 200.000, 20.000),
(25, 2, 800.000, 80.000),
(28, 2, 150.000, 20.000),
(31, 2, 5.000, 1.000),
(33, 2, 40.000, 5.000),
(36, 2, 100.000, 15.000),

-- مخزن الدمام (W-003)
(2, 3, 12.000, 1.000),
(5, 3, 8.000, 1.000),
(7, 3, 25.000, 3.000),
(9, 3, 8.000, 1.000),
(13, 3, 60.000, 10.000),
(15, 3, 80.000, 12.000),
(19, 3, 200.000, 30.000),
(22, 3, 200.000, 30.000),
(24, 3, 150.000, 20.000),
(26, 3, 800.000, 80.000),
(29, 3, 200.000, 30.000),
(32, 3, 5.000, 1.000),
(34, 3, 30.000, 5.000),
(37, 3, 150.000, 20.000),

-- مخزن مكة (W-004)
(3, 4, 5.000, 0.000),
(6, 4, 30.000, 5.000),
(10, 4, 80.000, 10.000),
(16, 4, 100.000, 15.000),
(20, 4, 60.000, 10.000),
(27, 4, 100.000, 15.000),
(30, 4, 100.000, 15.000),
(35, 4, 25.000, 3.000),
(38, 4, 40.000, 5.000);

-- ================================================================
-- 8. حركات المخزون (لإنشاء سجل تاريخي)
-- ================================================================

-- إجراء لتوليد حركات تاريخية
DELIMITER //

CREATE PROCEDURE generate_historical_movements()
BEGIN
    DECLARE v_counter INT DEFAULT 1;
    DECLARE v_product_id INT;
    DECLARE v_warehouse_id INT;
    DECLARE v_quantity DECIMAL(15,3);
    DECLARE v_unit_cost DECIMAL(15,3);
    DECLARE v_movement_date DATETIME;
    DECLARE v_movement_type VARCHAR(20);
    DECLARE v_user_id INT DEFAULT 1;
    
    -- توليد 500 حركة تاريخية
    WHILE v_counter <= 500 DO
        -- اختيار عشوائي
        SELECT id INTO v_product_id FROM products ORDER BY RAND() LIMIT 1;
        SELECT id INTO v_warehouse_id FROM warehouses WHERE is_active = 1 ORDER BY RAND() LIMIT 1;
        SET v_quantity = ROUND(5 + RAND() * 50, 0);
        SET v_unit_cost = ROUND(10 + RAND() * 500, 2);
        SET v_movement_date = DATE_SUB(NOW(), INTERVAL FLOOR(1 + RAND() * 180) DAY);
        SET v_movement_type = ELT(1 + FLOOR(RAND() * 4), 'RECEIPT', 'ISSUE', 'TRANSFER_OUT', 'RETURN_IN');
        
        -- حساب الرصيد
        SELECT COALESCE(quantity, 0) INTO @balance FROM stock_balances WHERE product_id = v_product_id AND warehouse_id = v_warehouse_id;
        IF @balance IS NULL THEN
            SET @balance = 0;
        END IF;
        
        -- إدراج الحركة
        INSERT INTO stock_movements (
            product_id, warehouse_id, movement_type, reference_type, reference_id,
            quantity, unit_cost, balance_before, balance_after, movement_date, user_id, notes, created_at
        ) VALUES (
            v_product_id, v_warehouse_id, v_movement_type, 'receipt', 1,
            v_quantity, v_unit_cost, @balance, @balance + v_quantity, v_movement_date, v_user_id,
            CONCAT('حركة تاريخية تلقائية رقم ', v_counter), v_movement_date
        );
        
        -- تحديث الرصيد
        INSERT INTO stock_balances (product_id, warehouse_id, quantity, updated_at)
        VALUES (v_product_id, v_warehouse_id, @balance + v_quantity, v_movement_date)
        ON DUPLICATE KEY UPDATE quantity = @balance + v_quantity, updated_at = v_movement_date;
        
        SET v_counter = v_counter + 1;
    END WHILE;
END//

DELIMITER ;

-- تنفيذ توليد الحركات التاريخية (يمكن تشغيله حسب الحاجة)
-- CALL generate_historical_movements();

-- ================================================================
-- 9. مستندات تجريبية
-- ================================================================

-- إذون استلام
INSERT INTO receipts (receipt_no, warehouse_id, supplier_id, receipt_date, receipt_time, total_items, total_quantity, total_cost, status, user_id, created_at) VALUES
('REC-2026-0001', 1, 1, '2026-08-10', '10:30:00', 5, 250.000, 45000.00, 'completed', 1, '2026-08-10 10:30:00'),
('REC-2026-0002', 2, 2, '2026-08-12', '14:15:00', 3, 150.000, 18000.00, 'approved', 2, '2026-08-12 14:15:00'),
('REC-2026-0003', 3, 3, '2026-08-15', '09:45:00', 4, 200.000, 35000.00, 'approved', 3, '2026-08-15 09:45:00'),
('REC-2026-0004', 1, 4, '2026-08-18', '11:00:00', 6, 300.000, 25000.00, 'submitted', 4, '2026-08-18 11:00:00');

-- تفاصيل الإستلام
INSERT INTO receipt_items (receipt_id, product_id, quantity, unit_cost) VALUES
(1, 1, 10, 3500.00),
(1, 2, 5, 2800.00),
(1, 3, 3, 4200.00),
(1, 12, 100, 25.00),
(1, 18, 200, 1.50),
(2, 8, 5, 4500.00),
(2, 14, 50, 55.00),
(2, 21, 100, 1.00),
(3, 23, 100, 18.00),
(3, 25, 500, 3.00),
(3, 28, 200, 8.00),
(3, 31, 5, 1200.00),
(4, 4, 10, 650.00),
(4, 6, 50, 350.00),
(4, 15, 100, 30.00),
(4, 20, 80, 5.00),
(4, 33, 30, 60.00),
(4, 36, 50, 25.00);

-- تحديث إجماليات الاستلام
UPDATE receipts SET total_items = (SELECT COUNT(*) FROM receipt_items WHERE receipt_id = 1), total_quantity = (SELECT SUM(quantity) FROM receipt_items WHERE receipt_id = 1), total_cost = (SELECT SUM(quantity * unit_cost) FROM receipt_items WHERE receipt_id = 1) WHERE id = 1;
UPDATE receipts SET total_items = (SELECT COUNT(*) FROM receipt_items WHERE receipt_id = 2), total_quantity = (SELECT SUM(quantity) FROM receipt_items WHERE receipt_id = 2), total_cost = (SELECT SUM(quantity * unit_cost) FROM receipt_items WHERE receipt_id = 2) WHERE id = 2;
UPDATE receipts SET total_items = (SELECT COUNT(*) FROM receipt_items WHERE receipt_id = 3), total_quantity = (SELECT SUM(quantity) FROM receipt_items WHERE receipt_id = 3), total_cost = (SELECT SUM(quantity * unit_cost) FROM receipt_items WHERE receipt_id = 3) WHERE id = 3;
UPDATE receipts SET total_items = (SELECT COUNT(*) FROM receipt_items WHERE receipt_id = 4), total_quantity = (SELECT SUM(quantity) FROM receipt_items WHERE receipt_id = 4), total_cost = (SELECT SUM(quantity * unit_cost) FROM receipt_items WHERE receipt_id = 4) WHERE id = 4;

-- إذون صرف
INSERT INTO issues (issue_no, warehouse_id, recipient_id, issue_date, issue_time, total_items, total_quantity, total_cost, status, user_id, created_at) VALUES
('ISS-2026-0001', 1, 1, '2026-08-11', '13:00:00', 4, 80.000, 15000.00, 'delivered', 1, '2026-08-11 13:00:00'),
('ISS-2026-0002', 2, 2, '2026-08-13', '10:00:00', 3, 60.000, 8000.00, 'approved', 2, '2026-08-13 10:00:00'),
('ISS-2026-0003', 3, 3, '2026-08-16', '15:30:00', 5, 120.000, 22000.00, 'approved', 3, '2026-08-16 15:30:00'),
('ISS-2026-0004', 1, 4, '2026-08-19', '08:45:00', 6, 150.000, 18000.00, 'draft', 4, '2026-08-19 08:45:00');

-- تفاصيل الصرف
INSERT INTO issue_items (issue_id, product_id, quantity, unit_cost) VALUES
(1, 1, 5, 3500.00),
(1, 4, 10, 650.00),
(1, 12, 50, 25.00),
(1, 21, 50, 1.00),
(2, 8, 3, 4500.00),
(2, 14, 20, 55.00),
(2, 18, 100, 1.50),
(3, 23, 50, 18.00),
(3, 25, 200, 3.00),
(3, 28, 50, 8.00),
(3, 33, 10, 60.00),
(3, 37, 50, 10.00),
(4, 6, 20, 350.00),
(4, 15, 50, 30.00),
(4, 20, 30, 5.00),
(4, 29, 80, 1.50),
(4, 34, 15, 90.00),
(4, 38, 20, 45.00);

-- تحديث إجماليات الصرف
UPDATE issues SET total_items = (SELECT COUNT(*) FROM issue_items WHERE issue_id = 1), total_quantity = (SELECT SUM(quantity) FROM issue_items WHERE issue_id = 1), total_cost = (SELECT SUM(quantity * unit_cost) FROM issue_items WHERE issue_id = 1) WHERE id = 1;
UPDATE issues SET total_items = (SELECT COUNT(*) FROM issue_items WHERE issue_id = 2), total_quantity = (SELECT SUM(quantity) FROM issue_items WHERE issue_id = 2), total_cost = (SELECT SUM(quantity * unit_cost) FROM issue_items WHERE issue_id = 2) WHERE id = 2;
UPDATE issues SET total_items = (SELECT COUNT(*) FROM issue_items WHERE issue_id = 3), total_quantity = (SELECT SUM(quantity) FROM issue_items WHERE issue_id = 3), total_cost = (SELECT SUM(quantity * unit_cost) FROM issue_items WHERE issue_id = 3) WHERE id = 3;
UPDATE issues SET total_items = (SELECT COUNT(*) FROM issue_items WHERE issue_id = 4), total_quantity = (SELECT SUM(quantity) FROM issue_items WHERE issue_id = 4), total_cost = (SELECT SUM(quantity * unit_cost) FROM issue_items WHERE issue_id = 4) WHERE id = 4;

-- ================================================================
-- 10. تنبيهات تجريبية
-- ================================================================

INSERT INTO notifications (user_id, type, title, message, priority, reference_type, reference_id, created_at) VALUES
(1, 'low_stock', 'تنبيه: مخزون منخفض - لابتوب HP', 'المنتج "لابتوب HP ProBook 450" في المخزن الرئيسي وصل للحد الأدنى. الرجاء إعادة التوريد.', 'high', 'product', 1, NOW()),
(2, 'low_stock', 'تنبيه: مخزون منخفض - آيفون 15', 'المنتج "آيفون 15 برو ماكس" في مخزن جدة وصل للحد الأدنى. الرجاء إعادة التوريد.', 'high', 'product', 8, NOW()),
(1, 'out_of_stock', '⚠️ نفاذ المخزون - طابعة HP', 'المنتج "طابعة HP LaserJet MFP" في مخزن الدمام نفد من المخزون. الرجاء إعادة التوريد فوراً.', 'critical', 'product', 31, NOW()),
(3, 'expiry_alert', 'تنبيه: انتهاء صلاحية - عصير برتقال', 'المنتج "عصير برتقال طازج 1 لتر" في المخزن الرئيسي يقترب من تاريخ انتهاء الصلاحية.', 'medium', 'product', 20, NOW());

-- ================================================================
-- 11. سجلات التدقيق التجريبية
-- ================================================================

INSERT INTO audit_logs (user_id, username, action, module, description, details, ip_address, created_at) VALUES
(1, 'admin', 'LOGIN_SUCCESS', 'auth', 'تسجيل دخول ناجح', '{"ip":"192.168.1.100","device":"Chrome"}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(1, 'admin', 'CREATE_RECEIPT', 'receipts', 'إنشاء إذن استلام', '{"receipt_id":1,"items":5}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 90 MINUTE)),
(2, 'manager', 'LOGIN_SUCCESS', 'auth', 'تسجيل دخول ناجح', '{"ip":"192.168.1.101","device":"Edge"}', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 80 MINUTE)),
(2, 'manager', 'CREATE_ISSUE', 'issues', 'إنشاء إذن صرف', '{"issue_id":2,"items":3}', '192.168.1.101', DATE_SUB(NOW(), INTERVAL 60 MINUTE)),
(3, 'supervisor', 'APPROVE_RECEIPT', 'receipts', 'اعتماد إذن استلام', '{"receipt_id":2}', '192.168.1.102', DATE_SUB(NOW(), INTERVAL 50 MINUTE)),
(1, 'admin', 'UPDATE_PRODUCT', 'products', 'تحديث بيانات صنف', '{"product_id":1,"changes":"price"}', '192.168.1.100', DATE_SUB(NOW(), INTERVAL 30 MINUTE));

-- ================================================================
-- 12. جلسات جرد تجريبية
-- ================================================================

INSERT INTO inventory_counts (count_no, warehouse_id, count_date, count_time, count_type, total_items, status, user_id, supervisor_id, created_at) VALUES
('CNT-2026-0001', 1, '2026-08-10', '09:00:00', 'full', 38, 'approved', 1, 2, '2026-08-10 09:00:00'),
('CNT-2026-0002', 2, '2026-08-15', '10:30:00', 'partial', 16, 'reviewed', 2, 3, '2026-08-15 10:30:00'),
('CNT-2026-0003', 3, '2026-08-18', '14:00:00', 'cycle', 10, 'in_progress', 3, 2, '2026-08-18 14:00:00');

-- تفاصيل الجرد
INSERT INTO inventory_count_items (inventory_count_id, product_id, system_quantity, actual_quantity, unit_cost, notes) VALUES
(1, 1, 25.000, 24.000, 3500.00, 'نقص قطعة واحدة - تم تدوين الملاحظة'),
(1, 4, 35.000, 36.000, 650.00, 'زيادة قطعة واحدة - ربما بسبب خطأ في الاستلام'),
(1, 12, 200.000, 198.000, 25.00, 'نقص 2 كجم'),
(1, 18, 500.000, 502.000, 1.50, 'زيادة 2 علبة'),
(2, 8, 8.000, 7.000, 4500.00, 'نقص جهاز واحد - تحت التحقيق'),
(2, 14, 50.000, 52.000, 55.00, 'زيادة 2 لتر'),
(3, 23, 200.000, 195.000, 18.00, 'نقص 5 كيس'),
(3, 25, 800.000, 810.000, 3.00, 'زيادة 10 طوبة');

-- ================================================================
-- 13. إحصائيات النظام النهائية
-- ================================================================

SELECT 
    '📊 إحصائيات النظام' AS title,
    (SELECT COUNT(*) FROM products WHERE is_active = 1) AS total_products,
    (SELECT COUNT(*) FROM categories WHERE is_active = 1) AS total_categories,
    (SELECT COUNT(*) FROM warehouses WHERE is_active = 1) AS total_warehouses,
    (SELECT COUNT(*) FROM users WHERE is_active = 1) AS total_users,
    (SELECT COUNT(*) FROM suppliers WHERE is_active = 1) AS total_suppliers,
    (SELECT COUNT(*) FROM recipients WHERE is_active = 1) AS total_recipients,
    (SELECT COUNT(*) FROM stock_movements) AS total_movements,
    (SELECT COUNT(*) FROM stock_balances WHERE quantity > 0) AS products_in_stock,
    (SELECT SUM(quantity) FROM stock_balances) AS total_quantity_in_stock,
    (SELECT SUM(quantity * cost_price) FROM stock_balances sb INNER JOIN products p ON p.id = sb.product_id) AS total_stock_value,
    (SELECT COUNT(*) FROM receipts) AS total_receipts,
    (SELECT COUNT(*) FROM issues) AS total_issues,
    (SELECT COUNT(*) FROM transfers) AS total_transfers,
    (SELECT COUNT(*) FROM inventory_counts) AS total_inventory_counts,
    (SELECT COUNT(*) FROM audit_logs) AS total_audit_logs,
    (SELECT COUNT(*) FROM notifications WHERE is_read = 0) AS unread_notifications;

-- ================================================================
-- انتهى ملف البيانات التجريبية الشاملة
-- ================================================================

SELECT '✅ تم إنشاء البيانات التجريبية الشاملة بنجاح' AS final_status;
SELECT '📋 عدد الأصناف: ' || (SELECT COUNT(*) FROM products) AS products_count;
SELECT '📋 عدد الحركات: ' || (SELECT COUNT(*) FROM stock_movements) AS movements_count;
SELECT '📋 قيمة المخزون: ' || (SELECT FORMAT(SUM(quantity * cost_price), 2) FROM stock_balances sb INNER JOIN products p ON p.id = sb.product_id) AS stock_value;

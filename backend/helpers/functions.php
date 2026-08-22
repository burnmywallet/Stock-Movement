<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/helpers/functions.php
// الوصف: دوال مساعدة عامة - استجابة، تحقق، تنسيق، ملفات، سجلات
// التاريخ: 2026-08-22
// ================================================================

// ================================================================
// 1. دوال الاستجابة
// ================================================================

if (!function_exists('json_response')) {
    /**
     * إرسال استجابة JSON متقدمة
     */
    function json_response(bool $success, string $message, $data = null, int $statusCode = 200, $meta = null, $errors = null): void
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        
        $response = [
            'success' => $success,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => VERSION ?? '5.0.0'
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        if ($meta !== null) {
            $response['meta'] = $meta;
        }
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}

if (!function_exists('json_success')) {
    /**
     * إرسال استجابة نجاح
     */
    function json_success(string $message, $data = null, int $statusCode = 200): void
    {
        json_response(true, $message, $data, $statusCode);
    }
}

if (!function_exists('json_error')) {
    /**
     * إرسال استجابة خطأ
     */
    function json_error(string $message, $data = null, int $statusCode = 400): void
    {
        json_response(false, $message, $data, $statusCode);
    }
}

// ================================================================
// 2. دوال التحقق من البيانات
// ================================================================

if (!function_exists('is_valid_email')) {
    /**
     * التحقق من صحة البريد الإلكتروني
     */
    function is_valid_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('is_valid_phone')) {
    /**
     * التحقق من صحة رقم الهاتف
     */
    function is_valid_phone(string $phone): bool
    {
        return preg_match('/^[0-9+\-\s()]{7,20}$/', $phone) === 1;
    }
}

if (!function_exists('is_valid_saudi_id')) {
    /**
     * التحقق من صحة الهوية السعودية
     */
    function is_valid_saudi_id(string $id): bool
    {
        $id = trim($id);
        if (!preg_match('/^[1-2]\d{9}$/', $id)) {
            return false;
        }
        
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int)$id[$i];
            if ($i % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }
        
        return $sum % 10 === 0;
    }
}

if (!function_exists('is_valid_egyptian_id')) {
    /**
     * التحقق من صحة الرقم القومي المصري
     */
    function is_valid_egyptian_id(string $id): bool
    {
        $id = trim($id);
        if (!preg_match('/^[2-3]\d{13}$/', $id)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('sanitize_input')) {
    /**
     * تنظيف المدخلات
     */
    function sanitize_input($input)
    {
        if (is_array($input)) {
            return array_map('sanitize_input', $input);
        }
        
        if (is_string($input)) {
            return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
        }
        
        return $input;
    }
}

if (!function_exists('validate_date')) {
    /**
     * التحقق من صحة التاريخ
     */
    function validate_date(string $date, string $format = 'Y-m-d'): bool
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}

if (!function_exists('validate_datetime')) {
    /**
     * التحقق من صحة التاريخ والوقت
     */
    function validate_datetime(string $datetime, string $format = 'Y-m-d H:i:s'): bool
    {
        $d = DateTime::createFromFormat($format, $datetime);
        return $d && $d->format($format) === $datetime;
    }
}

// ================================================================
// 3. دوال التوليد
// ================================================================

if (!function_exists('generate_random_string')) {
    /**
     * توليد نص عشوائي آمن
     */
    function generate_random_string(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}

if (!function_exists('generate_code')) {
    /**
     * توليد كود فريد
     */
    function generate_code(string $prefix = '', int $length = 6, string $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'): string
    {
        $code = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        return $prefix ? $prefix . '-' . $code : $code;
    }
}

if (!function_exists('generate_uuid')) {
    /**
     * توليد UUID v4
     */
    function generate_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('generate_slug')) {
    /**
     * تحويل النص إلى Slug
     */
    function generate_slug(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        
        return empty($text) ? 'n-a' : $text;
    }
}

// ================================================================
// 4. دوال التاريخ والوقت
// ================================================================

if (!function_exists('format_date')) {
    /**
     * تنسيق التاريخ
     */
    function format_date($date, string $format = 'Y-m-d'): string
    {
        if (empty($date)) {
            return '';
        }
        
        if (is_string($date)) {
            $date = strtotime($date);
        }
        
        if (is_numeric($date)) {
            return date($format, $date);
        }
        
        return '';
    }
}

if (!function_exists('format_datetime')) {
    /**
     * تنسيق التاريخ والوقت
     */
    function format_datetime($datetime, string $format = 'Y-m-d H:i:s'): string
    {
        return format_date($datetime, $format);
    }
}

if (!function_exists('format_date_arabic')) {
    /**
     * تنسيق التاريخ بالعربية
     */
    function format_date_arabic($date, string $format = 'd F Y'): string
    {
        $months = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس',
            4 => 'أبريل', 5 => 'مايو', 6 => 'يونيو',
            7 => 'يوليو', 8 => 'أغسطس', 9 => 'سبتمبر',
            10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
        ];
        
        $days = [
            'Sat' => 'السبت', 'Sun' => 'الأحد', 'Mon' => 'الإثنين',
            'Tue' => 'الثلاثاء', 'Wed' => 'الأربعاء', 'Thu' => 'الخميس',
            'Fri' => 'الجمعة'
        ];
        
        $date = format_date($date);
        if (empty($date)) {
            return '';
        }
        
        $timestamp = strtotime($date);
        $month = (int)date('n', $timestamp);
        $day = date('D', $timestamp);
        
        $result = date($format, $timestamp);
        $result = str_replace(date('F', $timestamp), $months[$month], $result);
        $result = str_replace($day, $days[$day], $result);
        
        return $result;
    }
}

if (!function_exists('get_days_between')) {
    /**
     * عدد الأيام بين تاريخين
     */
    function get_days_between(string $start, string $end): int
    {
        $startTime = strtotime($start);
        $endTime = strtotime($end);
        return (int)(($endTime - $startTime) / 86400);
    }
}

if (!function_exists('is_date_in_range')) {
    /**
     * التحقق من أن التاريخ في نطاق معين
     */
    function is_date_in_range(string $date, string $start, string $end): bool
    {
        $dateTime = strtotime($date);
        $startTime = strtotime($start);
        $endTime = strtotime($end);
        
        return $dateTime >= $startTime && $dateTime <= $endTime;
    }
}

if (!function_exists('time_ago')) {
    /**
     * حساب الوقت المنقضي
     */
    function time_ago($datetime): string
    {
        if (empty($datetime)) {
            return '';
        }
        
        $timestamp = is_string($datetime) ? strtotime($datetime) : $datetime;
        $diff = time() - $timestamp;
        
        $units = [
            31536000 => 'سنة',
            2592000 => 'شهر',
            604800 => 'أسبوع',
            86400 => 'يوم',
            3600 => 'ساعة',
            60 => 'دقيقة',
            1 => 'ثانية'
        ];
        
        foreach ($units as $seconds => $unit) {
            if ($diff >= $seconds) {
                $count = floor($diff / $seconds);
                $text = $count . ' ' . $unit;
                if ($count > 1) {
                    $text .= $unit === 'سنة' ? 'ات' : ($unit === 'شهر' ? 'ور' : ($unit === 'أسبوع' ? 'وع' : ($unit === 'يوم' ? 'اً' : ($unit === 'ساعة' ? 'ات' : ($unit === 'دقيقة' ? 'ق' : 'ات')))));
                }
                return $text . ' ago';
            }
        }
        
        return 'الآن';
    }
}

// ================================================================
// 5. دوال الأرقام والعملات
// ================================================================

if (!function_exists('format_money')) {
    /**
     * تنسيق العملة
     */
    function format_money($amount, int $decimals = 2): string
    {
        $currency = $_ENV['CURRENCY_SYMBOL'] ?? 'ر.س';
        return number_format((float)$amount, $decimals, '.', ',') . ' ' . $currency;
    }
}

if (!function_exists('format_number')) {
    /**
     * تنسيق رقم
     */
    function format_number($number, int $decimals = 2): string
    {
        return number_format((float)$number, $decimals, '.', ',');
    }
}

if (!function_exists('round_up')) {
    /**
     * تقريب لأعلى
     */
    function round_up(float $number, int $precision = 0): float
    {
        $factor = pow(10, $precision);
        return ceil($number * $factor) / $factor;
    }
}

if (!function_exists('round_down')) {
    /**
     * تقريب لأسفل
     */
    function round_down(float $number, int $precision = 0): float
    {
        $factor = pow(10, $precision);
        return floor($number * $factor) / $factor;
    }
}

if (!function_exists('percentage')) {
    /**
     * حساب النسبة المئوية
     */
    function percentage(float $part, float $total, int $decimals = 2): float
    {
        if ($total == 0) {
            return 0;
        }
        return round(($part / $total) * 100, $decimals);
    }
}

// ================================================================
// 6. دوال النصوص
// ================================================================

if (!function_exists('truncate_text')) {
    /**
     * اختصار النص
     */
    function truncate_text(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        
        return mb_substr($text, 0, $length) . $suffix;
    }
}

if (!function_exists('strip_html')) {
    /**
     * إزالة HTML من النص
     */
    function strip_html(string $text): string
    {
        return strip_tags($text);
    }
}

if (!function_exists('extract_emails')) {
    /**
     * استخراج البريد الإلكتروني من النص
     */
    function extract_emails(string $text): array
    {
        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $matches);
        return $matches[0] ?? [];
    }
}

if (!function_exists('extract_phones')) {
    /**
     * استخراج أرقام الهواتف من النص
     */
    function extract_phones(string $text): array
    {
        preg_match_all('/[0-9+\-\s()]{7,20}/', $text, $matches);
        return array_map('trim', $matches[0] ?? []);
    }
}

// ================================================================
// 7. دوال المصفوفات
// ================================================================

if (!function_exists('array_group_by')) {
    /**
     * تجميع مصفوفة حسب المفتاح
     */
    function array_group_by(array $array, string $key): array
    {
        $result = [];
        foreach ($array as $item) {
            if (isset($item[$key])) {
                $groupKey = $item[$key];
                if (!isset($result[$groupKey])) {
                    $result[$groupKey] = [];
                }
                $result[$groupKey][] = $item;
            }
        }
        return $result;
    }
}

if (!function_exists('array_sort_by')) {
    /**
     * ترتيب مصفوفة حسب المفتاح
     */
    function array_sort_by(array $array, string $key, string $direction = 'asc'): array
    {
        usort($array, function($a, $b) use ($key, $direction) {
            $valA = $a[$key] ?? '';
            $valB = $b[$key] ?? '';
            
            if ($direction === 'asc') {
                return $valA <=> $valB;
            }
            return $valB <=> $valA;
        });
        return $array;
    }
}

if (!function_exists('array_to_csv')) {
    /**
     * تحويل مصفوفة إلى CSV
     */
    function array_to_csv(array $array, array $headers = null): string
    {
        $output = fopen('php://memory', 'r+');
        
        if ($headers) {
            fputcsv($output, $headers);
        }
        
        foreach ($array as $row) {
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
}

if (!function_exists('array_to_xml')) {
    /**
     * تحويل مصفوفة إلى XML
     */
    function array_to_xml(array $data, string $root = 'root', string $item = 'item'): string
    {
        $xml = new SimpleXMLElement('<' . $root . '/>');
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $node = $xml->addChild($item);
                foreach ($value as $k => $v) {
                    $node->addChild($k, $v);
                }
            } else {
                $xml->addChild($key, $value);
            }
        }
        
        return $xml->asXML();
    }
}

// ================================================================
// 8. دوال الملفات
// ================================================================

if (!function_exists('get_file_size')) {
    /**
     * الحصول على حجم الملف بتنسيق مقروء
     */
    function get_file_size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}

if (!function_exists('get_file_extension')) {
    /**
     * الحصول على امتداد الملف
     */
    function get_file_extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}

if (!function_exists('is_allowed_extension')) {
    /**
     * التحقق من امتداد الملف المسموح
     */
    function is_allowed_extension(string $filename, array $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf']): bool
    {
        $ext = get_file_extension($filename);
        return in_array($ext, $allowed);
    }
}

if (!function_exists('upload_file')) {
    /**
     * رفع ملف
     */
    function upload_file(array $file, string $destination, array $allowedTypes = null): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'حدث خطأ في رفع الملف'];
        }
        
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'الملف غير صالح'];
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($allowedTypes && !in_array($extension, $allowedTypes)) {
            return ['success' => false, 'message' => 'نوع الملف غير مسموح'];
        }
        
        $filename = uniqid() . '.' . $extension;
        $filepath = rtrim($destination, '/') . '/' . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            return ['success' => false, 'message' => 'فشل نقل الملف'];
        }
        
        return [
            'success' => true,
            'filename' => $filename,
            'path' => $filepath,
            'size' => $file['size'],
            'extension' => $extension,
            'mime_type' => mime_content_type($filepath)
        ];
    }
}

if (!function_exists('delete_file')) {
    /**
     * حذف ملف
     */
    function delete_file(string $path): bool
    {
        if (file_exists($path) && is_file($path)) {
            return unlink($path);
        }
        return false;
    }
}

// ================================================================
// 9. دوال النظام
// ================================================================

if (!function_exists('get_client_ip')) {
    /**
     * الحصول على IP العميل
     */
    function get_client_ip(): string
    {
        $ip = '0.0.0.0';
        
        if (isset($_SERVER['HTTP_CLIENT_IP']) && !empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}

if (!function_exists('get_user_agent')) {
    /**
     * الحصول على وكيل المستخدم
     */
    function get_user_agent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}

if (!function_exists('is_ajax_request')) {
    /**
     * التحقق من طلب AJAX
     */
    function is_ajax_request(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('is_cli')) {
    /**
     * التحقق من تشغيل CLI
     */
    function is_cli(): bool
    {
        return php_sapi_name() === 'cli';
    }
}

if (!function_exists('memory_usage')) {
    /**
     * استخدام الذاكرة الحالي
     */
    function memory_usage(): string
    {
        return get_file_size(memory_get_usage(true));
    }
}

if (!function_exists('get_server_uptime')) {
    /**
     * الحصول على وقت تشغيل الخادم
     */
    function get_server_uptime(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'غير معروف (Windows)';
        }
        
        try {
            $uptime = shell_exec('uptime -p');
            return trim($uptime) ?: 'غير معروف';
        } catch (Exception $e) {
            return 'غير معروف';
        }
    }
}

// ================================================================
// 10. دوال السجل
// ================================================================

if (!function_exists('log_debug')) {
    /**
     * تسجيل رسالة تصحيح
     */
    function log_debug(string $message, array $context = []): void
    {
        if ($_ENV['APP_DEBUG'] ?? false) {
            error_log("[DEBUG] {$message} " . json_encode($context, JSON_UNESCAPED_UNICODE));
        }
    }
}

if (!function_exists('log_error')) {
    /**
     * تسجيل رسالة خطأ
     */
    function log_error(string $message, array $context = []): void
    {
        error_log("[ERROR] {$message} " . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('log_info')) {
    /**
     * تسجيل رسالة معلومات
     */
    function log_info(string $message, array $context = []): void
    {
        error_log("[INFO] {$message} " . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('log_warning')) {
    /**
     * تسجيل رسالة تحذير
     */
    function log_warning(string $message, array $context = []): void
    {
        error_log("[WARNING] {$message} " . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}

// ================================================================
// 11. دوال إضافية متقدمة
// ================================================================

if (!function_exists('is_serialized')) {
    /**
     * التحقق من أن البيانات مسلسلة
     */
    function is_serialized(string $data): bool
    {
        $data = trim($data);
        if ($data === 'N;') {
            return true;
        }
        if (!preg_match('/^([adObis]):/', $data, $badions)) {
            return false;
        }
        switch ($badions[1]) {
            case 'a':
            case 'O':
            case 's':
                return preg_match("/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data);
            case 'b':
            case 'i':
            case 'd':
                return preg_match("/^{$badions[1]}:[0-9.E-]+;\$/", $data);
        }
        return false;
    }
}

if (!function_exists('safe_json_decode')) {
    /**
     * فك تشفير JSON بأمان
     */
    function safe_json_decode(string $json, bool $assoc = true)
    {
        if (empty($json)) {
            return null;
        }
        $data = json_decode($json, $assoc);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return $data;
    }
}

if (!function_exists('safe_json_encode')) {
    /**
     * تشفير JSON بأمان
     */
    function safe_json_encode($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return json_encode(['error' => 'JSON encoding failed']);
        }
        return $json;
    }
}

if (!function_exists('get_currency_symbol')) {
    /**
     * الحصول على رمز العملة
     */
    function get_currency_symbol(string $currency = null): string
    {
        $symbols = [
            'SAR' => 'ر.س',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'EGP' => 'ج.م',
            'AED' => 'د.إ',
            'KWD' => 'د.ك',
            'BHD' => 'د.ب',
            'QAR' => 'ر.ق'
        ];
        
        $currency = $currency ?? ($_ENV['CURRENCY'] ?? 'SAR');
        return $symbols[$currency] ?? 'ر.س';
    }
}

// ================================================================
// انتهى الملف
// ================================================================

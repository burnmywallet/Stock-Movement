<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/helpers/functions.php
// الوصف: دوال مساعدة عامة
// الإصدار: 2.0 Production Ready
// التاريخ: 2026-08-20
// ================================================================

// ================================================================
// دوال الاستجابة
// ================================================================

if (!function_exists('json_response')) {
    /**
     * إرسال استجابة JSON
     */
    function json_response(bool $success, string $message, $data = null, int $statusCode = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        
        $response = [
            'success' => $success,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
// دوال التحقق من البيانات
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

if (!function_exists('generate_random_string')) {
    /**
     * توليد نص عشوائي
     */
    function generate_random_string(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }
}

// ================================================================
// دوال التاريخ والوقت
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

// ================================================================
// دوال الأرقام والعملات
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

// ================================================================
// دوال النصوص
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

if (!function_exists('slugify')) {
    /**
     * تحويل النص إلى Slug
     */
    function slugify(string $text): string
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

if (!function_exists('generate_code')) {
    /**
     * توليد كود فريد
     */
    function generate_code(string $prefix = '', int $length = 6): string
    {
        $code = strtoupper(substr(generate_random_string($length), 0, $length));
        return $prefix ? $prefix . '-' . $code : $code;
    }
}

// ================================================================
// دوال المصفوفات
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

// ================================================================
// دوال الملفات
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
            'extension' => $extension
        ];
    }
}

// ================================================================
// دوال النظام
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

// ================================================================
// دوال السجل
// ================================================================

if (!function_exists('log_debug')) {
    /**
     * تسجيل رسالة تصحيح
     */
    function log_debug(string $message, array $context = []): void
    {
        if ($_ENV['APP_DEBUG'] ?? false) {
            error_log("[DEBUG] {$message} " . json_encode($context));
        }
    }
}

if (!function_exists('log_error')) {
    /**
     * تسجيل رسالة خطأ
     */
    function log_error(string $message, array $context = []): void
    {
        error_log("[ERROR] {$message} " . json_encode($context));
    }
}

if (!function_exists('log_info')) {
    /**
     * تسجيل رسالة معلومات
     */
    function log_info(string $message, array $context = []): void
    {
        error_log("[INFO] {$message} " . json_encode($context));
    }
}

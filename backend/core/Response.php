<?php
/**
 * ================================================================
 * Logistox - نظام الاستجابات الموحدة
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 * 
 * الملف: backend/core/Response.php
 * الوظيفة: توحيد جميع استجابات النظام
 * ================================================================
 */

namespace Core;

/**
 * Class Response
 * 
 * توحيد استجابات النظام مع دعم:
 * - JSON Responses
 * - HTML Responses
 * - File Downloads
 * - Redirects
 * - Error Handling
 * - Status Codes
 * - Headers Management
 */
class Response
{
    /**
     * @var array $headers رؤوس الاستجابة
     */
    private static $headers = [];

    /**
     * @var int $statusCode كود الحالة
     */
    private static $statusCode = 200;

    /**
     * @var string $charset الترميز
     */
    private static $charset = 'UTF-8';

    /**
     * @var string $contentType نوع المحتوى الافتراضي
     */
    private static $contentType = 'application/json';

    /**
     * إضافة رأس استجابة
     * 
     * @param string $name اسم الرأس
     * @param string $value قيمة الرأس
     * @return self
     */
    public static function header(string $name, string $value): self
    {
        self::$headers[$name] = $value;
        return new self();
    }

    /**
     * تعيين كود الحالة
     * 
     * @param int $code كود الحالة
     * @return self
     */
    public static function status(int $code): self
    {
        self::$statusCode = $code;
        return new self();
    }

    /**
     * تعيين نوع المحتوى
     * 
     * @param string $type نوع المحتوى
     * @param string $charset الترميز
     * @return self
     */
    public static function contentType(string $type, string $charset = 'UTF-8'): self
    {
        self::$contentType = $type;
        self::$charset = $charset;
        return new self();
    }

    /**
     * إرسال الرؤوس
     */
    private static function sendHeaders(): void
    {
        // تعيين كود الحالة
        http_response_code(self::$statusCode);

        // تعيين نوع المحتوى
        $contentType = self::$contentType . '; charset=' . self::$charset;
        header('Content-Type: ' . $contentType);

        // إضافة الرؤوس المخصصة
        foreach (self::$headers as $name => $value) {
            header($name . ': ' . $value);
        }

        // رؤوس إضافية
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    /**
     * استجابة JSON ناجحة
     * 
     * @param string $message رسالة النجاح
     * @param array $data البيانات
     * @param int $status كود الحالة
     * @param array $meta بيانات إضافية
     * @return void
     */
    public static function success(
        string $message = 'تمت العملية بنجاح',
        array $data = [],
        int $status = 200,
        array $meta = []
    ): void {
        self::$statusCode = $status;
        self::$contentType = 'application/json';

        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '5.0.0',
        ];

        // إضافة بيانات إضافية
        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        self::sendHeaders();
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * استجابة JSON فاشلة (خطأ)
     * 
     * @param string $message رسالة الخطأ
     * @param string $code كود الخطأ
     * @param int $status كود الحالة
     * @param array $errors تفاصيل الأخطاء
     * @return void
     */
    public static function error(
        string $message = 'حدث خطأ',
        string $code = 'ERROR',
        int $status = 400,
        array $errors = []
    ): void {
        self::$statusCode = $status;
        self::$contentType = 'application/json';

        $response = [
            'success' => false,
            'message' => $message,
            'code' => $code,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '5.0.0',
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        self::sendHeaders();
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * استجابة 404 - غير موجود
     * 
     * @param string $message رسالة الخطأ
     * @return void
     */
    public static function notFound(string $message = 'المسار غير موجود'): void
    {
        self::error($message, 'ROUTE_NOT_FOUND', 404);
    }

    /**
     * استجابة 401 - غير مصرح
     * 
     * @param string $message رسالة الخطأ
     * @return void
     */
    public static function unauthorized(string $message = 'غير مصرح - يرجى تسجيل الدخول'): void
    {
        self::error($message, 'UNAUTHORIZED', 401);
    }

    /**
     * استجابة 403 - ممنوع
     * 
     * @param string $message رسالة الخطأ
     * @return void
     */
    public static function forbidden(string $message = 'غير مسموح - لا تملك الصلاحية'): void
    {
        self::error($message, 'FORBIDDEN', 403);
    }

    /**
     * استجابة 422 - خطأ في التحقق
     * 
     * @param array $errors أخطاء التحقق
     * @param string $message رسالة الخطأ
     * @return void
     */
    public static function validationError(array $errors, string $message = 'خطأ في التحقق من البيانات'): void
    {
        self::error($message, 'VALIDATION_ERROR', 422, $errors);
    }

    /**
     * استجابة 500 - خطأ داخلي
     * 
     * @param string $message رسالة الخطأ
     * @param string $code كود الخطأ
     * @return void
     */
    public static function internalError(string $message = 'حدث خطأ داخلي في الخادم', string $code = 'INTERNAL_ERROR'): void
    {
        self::error($message, $code, 500);
    }

    /**
     * استجابة HTML
     * 
     * @param string $html محتوى HTML
     * @param int $status كود الحالة
     * @return void
     */
    public static function html(string $html, int $status = 200): void
    {
        self::$statusCode = $status;
        self::$contentType = 'text/html';

        self::sendHeaders();
        echo $html;
        exit;
    }

    /**
     * استجابة HTML مع ملف
     * 
     * @param string $filePath مسار الملف
     * @param array $data بيانات للتمرير
     * @param int $status كود الحالة
     * @return void
     */
    public static function view(string $filePath, array $data = [], int $status = 200): void
    {
        if (!file_exists($filePath)) {
            self::notFound('الملف المطلوب غير موجود');
        }

        // استخراج المتغيرات
        extract($data);

        // بدء التخزين المؤقت
        ob_start();
        include $filePath;
        $content = ob_get_clean();

        self::html($content, $status);
    }

    /**
     * تنزيل ملف
     * 
     * @param string $filePath مسار الملف
     * @param string $filename اسم الملف المحمل
     * @param string $mimeType نوع الملف
     * @return void
     */
    public static function download(
        string $filePath,
        string $filename = '',
        string $mimeType = ''
    ): void {
        if (!file_exists($filePath)) {
            self::notFound('الملف المطلوب غير موجود');
        }

        if (empty($filename)) {
            $filename = basename($filePath);
        }

        if (empty($mimeType)) {
            $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        }

        self::$statusCode = 200;
        self::$headers = [];

        // رؤوس التحميل
        self::header('Content-Type', $mimeType);
        self::header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        self::header('Content-Length', filesize($filePath));
        self::header('Cache-Control', 'no-cache, no-store, must-revalidate');
        self::header('Pragma', 'no-cache');
        self::header('Expires', '0');

        self::sendHeaders();

        // إرسال الملف
        readfile($filePath);
        exit;
    }

    /**
     * عرض ملف صورة
     * 
     * @param string $filePath مسار الملف
     * @param string $mimeType نوع الملف
     * @return void
     */
    public static function image(string $filePath, string $mimeType = ''): void
    {
        if (!file_exists($filePath)) {
            self::notFound('الصورة غير موجودة');
        }

        if (empty($mimeType)) {
            $mimeType = mime_content_type($filePath) ?: 'image/jpeg';
        }

        self::$statusCode = 200;
        self::$contentType = $mimeType;
        self::$headers = [];

        self::header('Cache-Control', 'public, max-age=31536000');
        self::header('Expires', gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

        self::sendHeaders();

        readfile($filePath);
        exit;
    }

    /**
     * إعادة توجيه
     * 
     * @param string $url الرابط
     * @param int $status كود الحالة
     * @return void
     */
    public static function redirect(string $url, int $status = 302): void
    {
        self::$statusCode = $status;
        self::$headers = [];

        self::header('Location', $url);

        self::sendHeaders();
        exit;
    }

    /**
     * إعادة توجيه مع رسالة نجاح
     * 
     * @param string $url الرابط
     * @param string $message رسالة النجاح
     * @param string $type نوع الرسالة
     * @return void
     */
    public static function redirectWithSuccess(string $url, string $message, string $type = 'success'): void
    {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
        self::redirect($url);
    }

    /**
     * إعادة توجيه مع رسالة خطأ
     * 
     * @param string $url الرابط
     * @param string $message رسالة الخطأ
     * @return void
     */
    public static function redirectWithError(string $url, string $message): void
    {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = 'error';
        self::redirect($url);
    }

    /**
     * استجابة JSON مع بيانات مجمعة
     * 
     * @param array $data البيانات
     * @param int $status كود الحالة
     * @return void
     */
    public static function json(array $data, int $status = 200): void
    {
        self::$statusCode = $status;
        self::$contentType = 'application/json';

        self::sendHeaders();
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * استجابة JSON مع بيانات مجمعة (بدون رؤوس إضافية)
     * 
     * @param array $data البيانات
     * @param int $status كود الحالة
     * @return void
     */
    public static function rawJson(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * استجابة نصية
     * 
     * @param string $text النص
     * @param int $status كود الحالة
     * @param string $contentType نوع المحتوى
     * @return void
     */
    public static function text(string $text, int $status = 200, string $contentType = 'text/plain'): void
    {
        self::$statusCode = $status;
        self::$contentType = $contentType;

        self::sendHeaders();
        echo $text;
        exit;
    }

    /**
     * استجابة XML
     * 
     * @param string $xml محتوى XML
     * @param int $status كود الحالة
     * @return void
     */
    public static function xml(string $xml, int $status = 200): void
    {
        self::$statusCode = $status;
        self::$contentType = 'application/xml';

        self::sendHeaders();
        echo $xml;
        exit;
    }

    /**
     * استجابة CSV
     * 
     * @param array $data البيانات
     * @param string $filename اسم الملف
     * @param int $status كود الحالة
     * @return void
     */
    public static function csv(array $data, string $filename = 'export.csv', int $status = 200): void
    {
        self::$statusCode = $status;

        self::header('Content-Type', 'text/csv');
        self::header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        self::header('Cache-Control', 'no-cache, no-store, must-revalidate');

        self::sendHeaders();

        $output = fopen('php://output', 'w');
        
        // إضافة BOM لدعم اللغة العربية
        fputs($output, "\xEF\xBB\xBF");

        // كتابة الرؤوس
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            
            // كتابة البيانات
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }

        fclose($output);
        exit;
    }

    /**
     * تعيين رؤوس CORS
     * 
     * @param string $origin الأصل المسموح
     * @param array $methods الطرق المسموحة
     * @param array $headers الرؤوس المسموحة
     * @return self
     */
    public static function cors(
        string $origin = '*',
        array $methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $headers = ['Content-Type', 'Authorization', 'X-Requested-With']
    ): self {
        self::header('Access-Control-Allow-Origin', $origin);
        self::header('Access-Control-Allow-Methods', implode(', ', $methods));
        self::header('Access-Control-Allow-Headers', implode(', ', $headers));
        self::header('Access-Control-Allow-Credentials', 'true');
        self::header('Access-Control-Max-Age', '86400');

        return new self();
    }

    /**
     * استجابة فارغة (No Content)
     * 
     * @param int $status كود الحالة
     * @return void
     */
    public static function noContent(int $status = 204): void
    {
        self::$statusCode = $status;
        self::$contentType = 'application/json';
        self::$headers = [];

        self::sendHeaders();
        exit;
    }

    /**
     * استجابة Created (201)
     * 
     * @param string $message رسالة النجاح
     * @param array $data البيانات
     * @param string $location رابط المورد الجديد
     * @return void
     */
    public static function created(string $message = 'تم الإنشاء بنجاح', array $data = [], string $location = ''): void
    {
        if (!empty($location)) {
            self::header('Location', $location);
        }
        self::success($message, $data, 201);
    }

    /**
     * استجابة Accepted (202)
     * 
     * @param string $message رسالة النجاح
     * @param array $data البيانات
     * @return void
     */
    public static function accepted(string $message = 'تم القبول', array $data = []): void
    {
        self::success($message, $data, 202);
    }

    /**
     * استجابة No Content (204)
     * 
     * @return void
     */
    public static function deleted(): void
    {
        self::noContent(204);
    }

    /**
     * استجابة Bad Request (400)
     * 
     * @param string $message رسالة الخطأ
     * @param array $errors تفاصيل الأخطاء
     * @return void
     */
    public static function badRequest(string $message = 'طلب غير صحيح', array $errors = []): void
    {
        self::error($message, 'BAD_REQUEST', 400, $errors);
    }

    /**
     * استجابة Conflict (409)
     * 
     * @param string $message رسالة الخطأ
     * @return void
     */
    public static function conflict(string $message = 'تعارض في البيانات'): void
    {
        self::error($message, 'CONFLICT', 409);
    }

    /**
     * استجابة Gone (410)
     * 
     * @param string $message رسالة الخطأ
     * @return void
     */
    public static function gone(string $message = 'المورد غير متوفر'): void
    {
        self::error($message, 'GONE', 410);
    }

    /**
     * استجابة Unavailable (503)
     * 
     * @param string $message رسالة الخطأ
     * @return void
     */
    public static function unavailable(string $message = 'الخدمة غير متاحة حالياً'): void
    {
        self::error($message, 'UNAVAILABLE', 503);
    }

    /**
     * استجابة مع البيانات الوصفية
     * 
     * @param array $data البيانات
     * @param array $meta البيانات الوصفية
     * @param int $status كود الحالة
     * @return void
     */
    public static function withMeta(array $data, array $meta, int $status = 200): void
    {
        self::$statusCode = $status;
        self::$contentType = 'application/json';

        $response = [
            'success' => true,
            'data' => $data,
            'meta' => $meta,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '5.0.0',
        ];

        self::sendHeaders();
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * استجابة مع الترقيم (Pagination)
     * 
     * @param array $data البيانات
     * @param int $total الإجمالي
     * @param int $page الصفحة الحالية
     * @param int $perPage عدد العناصر في الصفحة
     * @param int $status كود الحالة
     * @return void
     */
    public static function paginated(
        array $data,
        int $total,
        int $page = 1,
        int $perPage = 25,
        int $status = 200
    ): void {
        $lastPage = max(1, ceil($total / $perPage));

        $meta = [
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'from' => ($page - 1) * $perPage + 1,
                'to' => min($page * $perPage, $total),
            ]
        ];

        self::withMeta($data, $meta, $status);
    }
}

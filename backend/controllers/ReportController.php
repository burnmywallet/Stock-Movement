<?php

/**
 * ================================================================
 * Logistox - Report Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/ReportController.php
 * الوظيفة: توليد التقارير المهنية مع دعم التصدير المتعدد
 *
 * التقارير المدعومة:
 * 1. inventory   - تقرير المخزون الحالي
 * 2. movements   - تقرير الحركات المخزنية
 * 3. receipts    - تقرير إذونات الاستلام
 * 4. issues      - تقرير إذونات الصرف
 * 5. transfers   - تقرير التحويلات
 * 6. returns     - تقرير المرتجعات
 * 7. low-stock   - تقرير المخزون المنخفض
 * 8. user-activity - تقرير نشاط المستخدمين
 * 9. audit-log   - تقرير سجل التدقيق
 *
 * صيغ التصدير المدعومة:
 * - json  (الافتراضي)
 * - csv   (متوافق مع Excel العربي)
 * - excel (XLS عبر HTML table)
 * - pdf   (HTML قابل للطباعة)
 * - print (HTML مباشر للطباعة)
 *
 * الصلاحيات المطلوبة:
 * - reports.view: عرض التقارير
 * - reports.export: تصدير التقارير
 *
 * ملاحظات هامة:
 * - Header/Footer احترافي موحد لكل التقارير
 * - يدعم Company Settings من قاعدة البيانات
 * - يستخدم ReportService لتجميع البيانات
 * - يدعم الفلاتر المتقدمة (تاريخ، مخزن، منتج، حالة)
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\ReportService;
use App\Services\SettingService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class ReportController
 *
 * Controller للتقارير المهنية
 */
class ReportController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var ReportService خدمة التقارير
     */
    private ReportService $reportService;

    /**
     * @var SettingService خدمة الإعدادات
     */
    private SettingService $settingService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var array معلومات الشركة (cache)
     */
    private ?array $companyInfo = null;

    /**
     * @var array صيغ التصدير المدعومة
     */
    private const SUPPORTED_FORMATS = ['json', 'csv', 'excel', 'pdf', 'print'];

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->reportService = new ReportService($this->db);
            $this->settingService = new SettingService($this->db);
            $this->auditService = new AuditService($this->db);
        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة التقارير');
        }
    }

    // =========================================================================
    // 1. تقرير المخزون الحالي (Inventory Report)
    // =========================================================================

    /**
     * تقرير المخزون الحالي مع التقييم
     *
     * GET /api/reports/inventory
     *
     * Query Parameters:
     * - warehouse_id: تصفية حسب المخزن
     * - category_id: تصفية حسب التصنيف
     * - stock_status: تصفية حسب الحالة (normal, low, critical, out_of_stock)
     * - format: صيغة التصدير (json, csv, excel, pdf, print)
     *
     * @return void
     */
    public function inventory(): void
    {
        try {
            $filters = $this->extractFilters();
            $format = $this->getFormat();

            $report = $this->reportService->inventoryReport($filters);

            // إضافة معلومات التقرير
            $report['report_info'] = $this->buildReportInfo('تقرير المخزون الحالي');
            $report['filters'] = $filters;

            $this->exportReport($report, 'inventory_report', $format, 'inventory');

        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] Inventory failed: ' . $e->getMessage());
            Response::internalError('فشل في توليد تقرير المخزون');
        }
    }

    // =========================================================================
    // 2. تقرير الحركات المخزنية (Movements Report)
    // =========================================================================

    /**
     * تقرير الحركات المخزنية
     *
     * GET /api/reports/movements
     *
     * Query Parameters:
     * - from_date, to_date: نطاق التاريخ
     * - product_id: تصفية حسب المنتج
     * - warehouse_id: تصفية حسب المخزن
     * - movement_type: تصفية حسب نوع الحركة
     * - format: صيغة التصدير
     *
     * @return void
     */
    public function movements(): void
    {
        try {
            $filters = $this->extractFilters();
            $format = $this->getFormat();

            $report = $this->reportService->movementsReport($filters);

            $report['report_info'] = $this->buildReportInfo('تقرير الحركات المخزنية');
            $report['filters'] = $filters;

            $this->exportReport($report, 'movements_report', $format, 'movements');

        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] Movements failed: ' . $e->getMessage());
            Response::internalError('فشل في توليد تقرير الحركات');
        }
    }

    // =========================================================================
    // 3. تقرير إذونات الاستلام (Receipts Report)
    // =========================================================================

    /**
     * تقرير إذونات الاستلام
     *
     * GET /api/reports/receipts
     *
     * @return void
     */
    public function receipts(): void
    {
        try {
            $filters = $this->extractFilters();
            $format = $this->getFormat();

            $report = $this->reportService->receiptsReport($filters);

            $report['report_info'] = $this->buildReportInfo('تقرير إذونات الاستلام');
            $report['filters'] = $filters;

            $this->exportReport($report, 'receipts_report', $format, 'receipts');

        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] Receipts failed: ' . $e->getMessage());
            Response::internalError('فشل في توليد تقرير الاستلام');
        }
    }

    // =========================================================================
    // 4. تقرير إذونات الصرف (Issues Report)
    // =========================================================================

    /**
     * تقرير إذونات الصرف
     *
     * GET /api/reports/issues
     *
     * @return void
     */
    public function issues(): void
    {
        try {
            $filters = $this->extractFilters();
            $format = $this->getFormat();

            $report = $this->reportService->issuesReport($filters);

            $report['report_info'] = $this->buildReportInfo('تقرير إذونات الصرف');
            $report['filters'] = $filters;

            $this->exportReport($report, 'issues_report', $format, 'issues');

        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] Issues failed: ' . $e->getMessage());
            Response::internalError('فشل في توليد تقرير الصرف');
        }
    }

    // =========================================================================
    // 5. تقرير التحويلات (Transfers Report)
    // =========================================================================

    /**
     * تقرير التحويلات المخزنية
     *
     * GET /api/reports/transfers
     *
     * @return void
     */
    public function transfers(): void
    {
        try {
            $filters = $this->extractFilters();
            $format = $this->getFormat();

            $report = $this->reportService->transfersReport($filters);

            $report['report_info'] = $this->buildReportInfo('تقرير التحويلات المخزنية');
            $report['filters'] = $filters;

            $this->exportReport($report, 'transfers_report', $format, 'transfers');

        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] Transfers failed: ' . $e->getMessage());
            Response::internalError('فشل في توليد تقرير التحويلات');
        }
    }

    // =========================================================================
    // 6. تقرير المرتجعات (Returns Report)
    // =========================================================================

    /**
     * تقرير المرتجعات
     *
     * GET /api/reports/returns
     *
     * @return void
     */
    public function returns(): void
    {
        try {
            $filters = $this->extractFilters();
            $format = $this->getFormat();

            $report = $this->reportService->returnsReport($filters);

            $report['report_info'] = $this->buildReportInfo('تقرير المرتجعات');
            $report['filters'] = $filters;

            $this->exportReport($report, 'returns_report', $format, 'returns');

        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] Returns failed: ' . $e->getMessage());
            Response::internalError('فشل في توليد تقرير المرتجعات');
        }
    }

    // =========================================================================
    // 7. تقرير المخزون المنخفض (Low Stock Report)
    // =========================================================================

    /**
     * تقرير المخزون المنخفض
     *
     * GET /api/reports/low-stock
     *
     * @return void
     */
    public function lowStock(): void
    {
        try {
            $filters = $this->extractFilters();
            $format = $this->getFormat();

            $report = $this->reportService->lowStockReport($filters);

            $report['report_info'] = $this->buildReportInfo('تقرير المخزون المنخفض');
            $report['filters'] = $filters;

            $this->exportReport($report, 'low_stock_report', $format, 'low_stock');

        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] LowStock failed: ' . $e->getMessage());
            Response::internalError('فشل في توليد تقرير المخزون المنخفض');
        }
    }

    // =========================================================================
    // 8. تقرير نشاط المستخدمين (User Activity Report)
    // =========================================================================

    /**
     * تقرير نشاط المستخدمين
     *
     * GET /api/reports/user-activity
     *
     * @return void
     */
    public function userActivity(): void
    {
        try {
            $filters = $this->extractFilters();
            $format = $this->getFormat();

            $report = $this->reportService->userActivityReport($filters);

            $report['report_info'] = $this->buildReportInfo('تقرير نشاط المستخدمين');
            $report['filters'] = $filters;

            $this->exportReport($report, 'user_activity_report', $format, 'user_activity');

        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] UserActivity failed: ' . $e->getMessage());
            Response::internalError('فشل في توليد تقرير نشاط المستخدمين');
        }
    }

    // =========================================================================
    // 9. تقرير سجل التدقيق (Audit Log Report)
    // =========================================================================

    /**
     * تقرير سجل التدقيق
     *
     * GET /api/reports/audit-log
     *
     * @return void
     */
    public function auditLog(): void
    {
        try {
            $filters = $this->extractFilters();
            $format = $this->getFormat();

            $report = $this->reportService->auditLogReport($filters);

            $report['report_info'] = $this->buildReportInfo('تقرير سجل التدقيق');
            $report['filters'] = $filters;

            $this->exportReport($report, 'audit_log_report', $format, 'audit_log');

        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] AuditLog failed: ' . $e->getMessage());
            Response::internalError('فشل في توليد تقرير سجل التدقيق');
        }
    }

    // =========================================================================
    // 10. تصدير عام (Export)
    // =========================================================================

    /**
     * تصدير تقرير عام
     *
     * POST /api/reports/export
     *
     * Request Body (JSON):
     * {
     *   "report_type": "inventory",
     *   "format": "excel",
     *   "filters": { ... }
     * }
     *
     * @return void
     */
    public function export(): void
    {
        try {
            $input = $this->getJsonInput();

            $reportType = $input['report_type'] ?? null;
            $format = $input['format'] ?? 'json';
            $filters = $input['filters'] ?? [];

            if (empty($reportType)) {
                Response::badRequest('نوع التقرير (report_type) مطلوب');
            }

            // اختيار الدالة المناسبة
            $methodMap = [
                'inventory'     => 'inventoryReport',
                'movements'     => 'movementsReport',
                'receipts'      => 'receiptsReport',
                'issues'        => 'issuesReport',
                'transfers'     => 'transfersReport',
                'returns'       => 'returnsReport',
                'low_stock'     => 'lowStockReport',
                'user_activity' => 'userActivityReport',
                'audit_log'     => 'auditLogReport',
            ];

            if (!isset($methodMap[$reportType])) {
                Response::badRequest('نوع التقرير غير مدعوم: ' . $reportType);
            }

            $method = $methodMap[$reportType];
            $report = $this->reportService->{$method}($filters);

            $reportTitles = [
                'inventory'     => 'تقرير المخزون الحالي',
                'movements'     => 'تقرير الحركات المخزنية',
                'receipts'      => 'تقرير إذونات الاستلام',
                'issues'        => 'تقرير إذونات الصرف',
                'transfers'     => 'تقرير التحويلات المخزنية',
                'returns'       => 'تقرير المرتجعات',
                'low_stock'     => 'تقرير المخزون المنخفض',
                'user_activity' => 'تقرير نشاط المستخدمين',
                'audit_log'     => 'تقرير سجل التدقيق',
            ];

            $report['report_info'] = $this->buildReportInfo($reportTitles[$reportType]);
            $report['filters'] = $filters;

            // تسجيل عملية التصدير
            $currentUserId = $this->getCurrentUserId();
            $this->auditService->log(
                userId: $currentUserId,
                action: 'REPORT_EXPORT',
                entityType: 'report',
                entityId: null,
                description: "تم تصدير تقرير: {$reportTitles[$reportType]} بصيغة {$format}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            $this->exportReport($report, "{$reportType}_report", $format, $reportType);

        } catch (Throwable $e) {
            error_log('[REPORT_CONTROLLER] Export failed: ' . $e->getMessage());
            Response::internalError('فشل في تصدير التقرير: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Helper Methods - التصدير
    // =========================================================================

    /**
     * تصدير التقرير بالصيغة المطلوبة
     *
     * @param array $report بيانات التقرير
     * @param string $filename اسم الملف (بدون امتداد)
     * @param string $format الصيغة المطلوبة
     * @param string $reportType نوع التقرير
     */
    private function exportReport(array $report, string $filename, string $format, string $reportType): void
    {
        switch ($format) {
            case 'csv':
                $this->exportAsCsv($report, $filename);
                break;

            case 'excel':
                $this->exportAsExcel($report, $filename, $reportType);
                break;

            case 'pdf':
            case 'print':
                $this->exportAsHtml($report, $filename, $reportType, $format === 'pdf');
                break;

            case 'json':
            default:
                Response::success(
                    message: 'تم توليد التقرير بنجاح',
                    data: $report
                );
                break;
        }
    }

    /**
     * تصدير التقرير كـ CSV (متوافق مع Excel العربي)
     */
    private function exportAsCsv(array $report, string $filename): void
    {
        $items = $report['items'] ?? [];

        if (empty($items)) {
            Response::error('لا توجد بيانات للتصدير', 'NO_DATA', 404);
        }

        $csvFilename = $filename . '_' . date('Y-m-d_H-i-s') . '.csv';

        Response::csv($items, $csvFilename, 200);
    }

    /**
     * تصدير التقرير كـ Excel (XLS عبر HTML table)
     */
    private function exportAsExcel(array $report, string $filename, string $reportType): void
    {
        $items = $report['items'] ?? [];
        $company = $this->getCompanyInfo();

        $filename = $filename . '_' . date('Y-m-d_H-i-s') . '.xls';

        // بناء HTML table
        $html = $this->buildExcelHtml($report, $items, $company, $reportType);

        // إرسال كـ Excel
        if (headers_sent()) {
            Response::error('تم إرسال الـ Headers مسبقاً', 'HEADERS_SENT', 500);
        }

        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Cache-Control: max-age=0');

        // BOM لدعم UTF-8 في Excel
        echo "\xEF\xBB\xBF";
        echo $html;
        exit;
    }

    /**
     * تصدير التقرير كـ HTML (PDF/Print)
     */
    private function exportAsHtml(array $report, string $filename, string $reportType, bool $forPdf): void
    {
        $items = $report['items'] ?? [];
        $company = $this->getCompanyInfo();
        $reportInfo = $report['report_info'] ?? [];

        $html = $this->buildPrintableHtml($report, $items, $company, $reportInfo, $reportType, $forPdf);

        if (headers_sent()) {
            Response::error('تم إرسال الـ Headers مسبقاً', 'HEADERS_SENT', 500);
        }

        header('Content-Type: text/html; charset=UTF-8');

        if ($forPdf) {
            header('Content-Disposition: inline; filename="' . rawurlencode($filename . '.html') . '"');
        }

        echo $html;
        exit;
    }

    /**
     * بناء HTML لملف Excel
     */
    private function buildExcelHtml(array $report, array $items, array $company, string $reportType): string
    {
        $reportInfo = $report['report_info'] ?? [];

        $html = '<html dir="rtl"><head><meta charset="UTF-8"></head><body>';

        // Header
        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%; font-family: Arial, Tahoma;">';
        $html .= '<tr><td colspan="10" style="text-align: center; background: #f0f0f0; font-size: 18px; font-weight: bold;">' . htmlspecialchars($company['name'] ?? 'Logistox') . '</td></tr>';
        $html .= '<tr><td colspan="10" style="text-align: center;">' . htmlspecialchars($company['address'] ?? '') . ' - هاتف: ' . htmlspecialchars($company['phone'] ?? '') . '</td></tr>';
        $html .= '<tr><td colspan="10" style="text-align: center; font-size: 16px; font-weight: bold; background: #e0e0e0;">' . htmlspecialchars($reportInfo['title'] ?? 'تقرير') . '</td></tr>';
        $html .= '<tr><td colspan="10" style="text-align: center;">تاريخ التقرير: ' . htmlspecialchars($reportInfo['generated_at'] ?? date('Y-m-d H:i:s')) . ' | بواسطة: ' . htmlspecialchars($reportInfo['generated_by'] ?? 'النظام') . '</td></tr>';
        $html .= '</table><br>';

        // Summary
        if (!empty($report['summary'])) {
            $html .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 50%; margin-bottom: 10px; font-family: Arial, Tahoma;">';
            $html .= '<tr style="background: #d0d0d0;"><th colspan="2">ملخص التقرير</th></tr>';
            foreach ($report['summary'] as $key => $value) {
                $label = $this->translateKey($key);
                $html .= '<tr><td style="font-weight: bold;">' . htmlspecialchars($label) . '</td><td>' . htmlspecialchars((string) $value) . '</td></tr>';
            }
            $html .= '</table><br>';
        }

        // Data table
        if (!empty($items)) {
            $html .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%; font-family: Arial, Tahoma;">';

            // Headers
            $html .= '<tr style="background: #4a90e2; color: white;">';
            $html .= '<th>#</th>';
            foreach (array_keys($items[0]) as $key) {
                $html .= '<th>' . htmlspecialchars($this->translateKey($key)) . '</th>';
            }
            $html .= '</tr>';

            // Rows
            $index = 1;
            foreach ($items as $item) {
                $html .= '<tr>';
                $html .= '<td>' . $index++ . '</td>';
                foreach ($item as $value) {
                    $html .= '<td>' . htmlspecialchars((string) ($value ?? '')) . '</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</table>';
        }

        // Footer
        $html .= '<br><table border="0" width="100%"><tr>';
        $html .= '<td style="text-align: left; font-size: 10px; color: #666;">Logistox v5.0 - نظام إدارة المخازن</td>';
        $html .= '<td style="text-align: right; font-size: 10px; color: #666;">صفحة 1 من 1</td>';
        $html .= '</tr></table>';

        $html .= '</body></html>';

        return $html;
    }

    /**
     * بناء HTML قابل للطباعة (PDF/Print)
     */
    private function buildPrintableHtml(array $report, array $items, array $company, array $reportInfo, string $reportType, bool $forPdf): string
    {
        $title = htmlspecialchars($reportInfo['title'] ?? 'تقرير');
        $companyName = htmlspecialchars($company['name'] ?? 'Logistox');
        $companyAddress = htmlspecialchars($company['address'] ?? '');
        $companyPhone = htmlspecialchars($company['phone'] ?? '');
        $companyEmail = htmlspecialchars($company['email'] ?? '');
        $generatedAt = htmlspecialchars($reportInfo['generated_at'] ?? date('Y-m-d H:i:s'));
        $generatedBy = htmlspecialchars($reportInfo['generated_by'] ?? 'النظام');

        $css = '
            <style>
                @page { size: A4; margin: 15mm; }
                * { box-sizing: border-box; }
                body {
                    font-family: "Tahoma", "Arial", sans-serif;
                    direction: rtl;
                    margin: 0;
                    padding: 20px;
                    color: #333;
                    font-size: 12px;
                    background: white;
                }
                .report-header {
                    border-bottom: 3px double #333;
                    padding-bottom: 15px;
                    margin-bottom: 20px;
                }
                .company-info {
                    text-align: center;
                    margin-bottom: 10px;
                }
                .company-name {
                    font-size: 22px;
                    font-weight: bold;
                    color: #2c3e50;
                    margin: 5px 0;
                }
                .company-details {
                    font-size: 11px;
                    color: #555;
                }
                .report-title {
                    text-align: center;
                    font-size: 18px;
                    font-weight: bold;
                    background: #3498db;
                    color: white;
                    padding: 10px;
                    margin: 15px 0;
                    border-radius: 4px;
                }
                .report-meta {
                    display: flex;
                    justify-content: space-between;
                    font-size: 11px;
                    color: #666;
                    margin-bottom: 15px;
                    padding: 8px;
                    background: #f9f9f9;
                    border-radius: 4px;
                }
                .summary-box {
                    background: #ecf0f1;
                    padding: 12px;
                    margin-bottom: 15px;
                    border-radius: 4px;
                    border-right: 4px solid #3498db;
                }
                .summary-title {
                    font-weight: bold;
                    font-size: 14px;
                    margin-bottom: 8px;
                    color: #2c3e50;
                }
                .summary-grid {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 10px;
                }
                .summary-item {
                    background: white;
                    padding: 8px;
                    border-radius: 3px;
                    text-align: center;
                }
                .summary-label {
                    font-size: 10px;
                    color: #7f8c8d;
                }
                .summary-value {
                    font-size: 16px;
                    font-weight: bold;
                    color: #2c3e50;
                    margin-top: 3px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 15px 0;
                    font-size: 11px;
                }
                thead tr {
                    background: #34495e;
                    color: white;
                }
                th, td {
                    padding: 8px 6px;
                    text-align: right;
                    border: 1px solid #bdc3c7;
                }
                tbody tr:nth-child(even) {
                    background: #f9f9f9;
                }
                tbody tr:hover {
                    background: #eaf2f8;
                }
                .report-footer {
                    margin-top: 30px;
                    padding-top: 15px;
                    border-top: 2px solid #333;
                    display: flex;
                    justify-content: space-between;
                    font-size: 10px;
                    color: #7f8c8d;
                }
                .no-data {
                    text-align: center;
                    padding: 40px;
                    color: #95a5a6;
                    font-size: 14px;
                }
                .print-button {
                    position: fixed;
                    top: 20px;
                    left: 20px;
                    background: #3498db;
                    color: white;
                    padding: 10px 20px;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 14px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                }
                .print-button:hover {
                    background: #2980b9;
                }
                @media print {
                    .print-button { display: none; }
                    body { padding: 0; }
                }
                .status-badge {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 10px;
                    font-size: 10px;
                    font-weight: bold;
                }
                .status-pending { background: #f39c12; color: white; }
                .status-approved { background: #27ae60; color: white; }
                .status-completed { background: #2980b9; color: white; }
                .status-cancelled { background: #e74c3c; color: white; }
            </style>
        ';

        $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>' . $title . '</title>' . $css . '</head><body>';

        // زر الطباعة (يظهر فقط في المتصفح)
        if (!$forPdf) {
            $html .= '<button class="print-button" onclick="window.print()">🖨️ طباعة التقرير</button>';
        }

        // Header
        $html .= '<div class="report-header">';
        $html .= '<div class="company-info">';
        $html .= '<div class="company-name">' . $companyName . '</div>';
        $html .= '<div class="company-details">';
        if (!empty($companyAddress)) {
            $html .= '📍 ' . $companyAddress;
        }
        if (!empty($companyPhone)) {
            $html .= ' | 📞 ' . $companyPhone;
        }
        if (!empty($companyEmail)) {
            $html .= ' | ✉️ ' . $companyEmail;
        }
        $html .= '</div></div>';
        $html .= '<div class="report-title">' . $title . '</div>';
        $html .= '<div class="report-meta">';
        $html .= '<span>📅 تاريخ التقرير: ' . $generatedAt . '</span>';
        $html .= '<span>👤 بواسطة: ' . $generatedBy . '</span>';
        $html .= '<span>🔢 عدد السجلات: ' . count($items) . '</span>';
        $html .= '</div>';
        $html .= '</div>';

        // Summary
        if (!empty($report['summary'])) {
            $html .= '<div class="summary-box">';
            $html .= '<div class="summary-title">📊 ملخص التقرير</div>';
            $html .= '<div class="summary-grid">';
            foreach ($report['summary'] as $key => $value) {
                $label = $this->translateKey($key);
                $formattedValue = is_numeric($value) ? number_format((float) $value, 2) : $value;
                $html .= '<div class="summary-item">';
                $html .= '<div class="summary-label">' . htmlspecialchars($label) . '</div>';
                $html .= '<div class="summary-value">' . htmlspecialchars((string) $formattedValue) . '</div>';
                $html .= '</div>';
            }
            $html .= '</div></div>';
        }

        // Data table
        if (empty($items)) {
            $html .= '<div class="no-data">⚠️ لا توجد بيانات لعرضها في هذا التقرير</div>';
        } else {
            $html .= '<table>';
            $html .= '<thead><tr>';
            $html .= '<th style="width: 40px;">#</th>';
            foreach (array_keys($items[0]) as $key) {
                $html .= '<th>' . htmlspecialchars($this->translateKey($key)) . '</th>';
            }
            $html .= '</tr></thead>';

            $html .= '<tbody>';
            $index = 1;
            foreach ($items as $item) {
                $html .= '<tr>';
                $html .= '<td>' . $index++ . '</td>';
                foreach ($item as $key => $value) {
                    $displayValue = $this->formatCellValue($key, $value);
                    $html .= '<td>' . $displayValue . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        // Footer
        $html .= '<div class="report-footer">';
        $html .= '<span>Logistox v5.0 - نظام إدارة المخازن والمخزون</span>';
        $html .= '<span>تم التوليد في: ' . date('Y-m-d H:i:s') . '</span>';
        $html .= '<span>صفحة 1 من 1</span>';
        $html .= '</div>';

        $html .= '</body></html>';

        return $html;
    }

    // =========================================================================
    // Helper Methods - البيانات
    // =========================================================================

    /**
     * استخراج الفلاتر من Query Parameters
     */
    private function extractFilters(): array
    {
        return [
            'search'        => trim($_GET['search'] ?? ''),
            'warehouse_id'  => !empty($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null,
            'product_id'    => !empty($_GET['product_id']) ? (int) $_GET['product_id'] : null,
            'category_id'   => !empty($_GET['category_id']) ? (int) $_GET['category_id'] : null,
            'supplier_id'   => !empty($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null,
            'recipient_id'  => !empty($_GET['recipient_id']) ? (int) $_GET['recipient_id'] : null,
            'status'        => $_GET['status'] ?? null,
            'movement_type' => $_GET['movement_type'] ?? null,
            'stock_status'  => $_GET['stock_status'] ?? null,
            'user_id'       => !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null,
            'from_date'     => $_GET['from_date'] ?? null,
            'to_date'       => $_GET['to_date'] ?? null,
            'limit'         => !empty($_GET['limit']) ? (int) $_GET['limit'] : 1000,
        ];
    }

    /**
     * الحصول على صيغة التصدير المطلوبة
     */
    private function getFormat(): string
    {
        $format = strtolower($_GET['format'] ?? 'json');

        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            $format = 'json';
        }

        return $format;
    }

    /**
     * بناء معلومات التقرير
     */
    private function buildReportInfo(string $title): array
    {
        $currentUserId = $this->getCurrentUserId(false);
        $generatedBy = 'النظام';

        if ($currentUserId !== null) {
            $user = $this->db->selectOne(
                "SELECT full_name, username FROM users WHERE id = ?",
                [$currentUserId]
            );
            if ($user) {
                $generatedBy = $user['full_name'] . ' (' . $user['username'] . ')';
            }
        }

        return [
            'title'        => $title,
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_by' => $generatedBy,
            'system_name'  => 'Logistox',
            'system_version' => getenv('APP_VERSION') ?: '5.0.0',
        ];
    }

    /**
     * الحصول على معلومات الشركة
     */
    private function getCompanyInfo(): array
    {
        if ($this->companyInfo !== null) {
            return $this->companyInfo;
        }

        $this->companyInfo = $this->settingService->getCompanyInfo();

        return $this->companyInfo;
    }

    /**
     * ترجمة مفاتيح الأعمدة إلى العربية
     */
    private function translateKey(string $key): string
    {
        $translations = [
            'id'                    => 'المعرف',
            'code'                  => 'الكود',
            'name'                  => 'الاسم',
            'product_name'          => 'اسم المنتج',
            'product_code'          => 'كود المنتج',
            'barcode'               => 'الباركود',
            'category_name'         => 'التصنيف',
            'unit_symbol'           => 'الوحدة',
            'warehouse_name'        => 'المخزن',
            'from_warehouse_name'   => 'من المخزن',
            'to_warehouse_name'     => 'إلى المخزن',
            'supplier_name'         => 'المورد',
            'recipient_name'        => 'الجهة المستلمة',
            'quantity'              => 'الكمية',
            'reserved_quantity'     => 'الكمية المحجوزة',
            'available_quantity'    => 'الكمية المتاحة',
            'min_stock'             => 'الحد الأدنى',
            'reorder_point'         => 'نقطة الطلب',
            'max_stock'             => 'الحد الأقصى',
            'cost_price'            => 'سعر التكلفة',
            'unit_cost'             => 'سعر الوحدة',
            'total_cost'            => 'الإجمالي',
            'total_value'           => 'القيمة الإجمالية',
            'total_quantity'        => 'الكمية الإجمالية',
            'total_items'           => 'عدد البنود',
            'status'                => 'الحالة',
            'status_label'          => 'الحالة',
            'return_type'           => 'النوع',
            'return_type_label'     => 'النوع',
            'movement_type'         => 'نوع الحركة',
            'movement_number'       => 'رقم الحركة',
            'receipt_number'        => 'رقم الإذن',
            'issue_number'          => 'رقم الإذن',
            'transfer_number'       => 'رقم التحويل',
            'return_number'         => 'رقم المرتجع',
            'count_number'          => 'رقم عملية الجرد',
            'balance_before'        => 'الرصيد قبل',
            'balance_after'         => 'الرصيد بعد',
            'movement_date'         => 'تاريخ الحركة',
            'created_at'            => 'تاريخ الإنشاء',
            'updated_at'            => 'تاريخ التحديث',
            'approved_at'           => 'تاريخ الاعتماد',
            'user_name'             => 'المستخدم',
            'username'              => 'اسم المستخدم',
            'full_name'             => 'الاسم الكامل',
            'role_name'             => 'الدور',
            'role_display_name'     => 'الدور',
            'ip_address'            => 'عنوان IP',
            'action'                => 'الإجراء',
            'description'           => 'الوصف',
            'entity_type'           => 'نوع الكيان',
            'reason'                => 'السبب',
            'notes'                 => 'ملاحظات',
            'batch_number'          => 'رقم اللوتة',
            'expiry_date'           => 'تاريخ الصلاحية',
            'last_movement_date'    => 'آخر حركة',
            'stock_status'          => 'حالة المخزون',
            'system_quantity'       => 'الكمية النظامية',
            'counted_quantity'      => 'الكمية المعدودة',
            'difference_quantity'   => 'الفرق',
            'title'                 => 'العنوان',
            'message'               => 'الرسالة',
            'type'                  => 'النوع',
            'is_read'               => 'مقروء',
            'last_login_at'         => 'آخر دخول',
            'is_active'             => 'نشط',
        ];

        return $translations[$key] ?? $key;
    }

    /**
     * تنسيق قيمة الخلية للعرض
     */
    private function formatCellValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        // تنسيق التواريخ
        if (str_contains($key, '_at') || str_contains($key, '_date') || $key === 'count_date') {
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                return htmlspecialchars($value);
            }
        }

        // تنسيق الأرقام العشرية
        if (in_array($key, ['quantity', 'unit_cost', 'total_cost', 'total_value', 'cost_price', 'balance_before', 'balance_after', 'reserved_quantity', 'available_quantity', 'min_stock', 'reorder_point', 'max_stock', 'system_quantity', 'counted_quantity', 'difference_quantity'], true)) {
            if (is_numeric($value)) {
                return number_format((float) $value, 3);
            }
        }

        // تنسيق الحالة
        if ($key === 'status' || $key === 'status_label') {
            $statusClasses = [
                'pending'   => 'status-pending',
                'approved'  => 'status-approved',
                'completed' => 'status-completed',
                'cancelled' => 'status-cancelled',
            ];
            $statusValue = is_string($value) ? strtolower($value) : $value;
            if (isset($statusClasses[$statusValue])) {
                return '<span class="status-badge ' . $statusClasses[$statusValue] . '">' . htmlspecialchars((string) $value) . '</span>';
            }
        }

        // تنسيق boolean
        if (is_bool($value)) {
            return $value ? '✅ نعم' : '❌ لا';
        }

        return htmlspecialchars((string) $value);
    }

    // =========================================================================
    // Helper Methods - عامة
    // =========================================================================

    /**
     * قراءة مدخلات JSON
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }

        $decoded = json_decode($input, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * جلب معرف المستخدم الحالي
     *
     * @param bool $required إذا كان true، سيرفض الطلب إذا لم يوجد المستخدم
     */
    private function getCurrentUserId(bool $required = true): ?int
    {
        if (isset($_REQUEST['user']['id'])) {
            return (int) $_REQUEST['user']['id'];
        }

        if (isset($GLOBALS['current_user_id'])) {
            return (int) $GLOBALS['current_user_id'];
        }

        if ($required) {
            error_log('[REPORT_CONTROLLER] Current user ID not found');
            Response::unauthorized('لم يتم العثور على بيانات المستخدم');
        }

        return null;
    }

    /**
     * جلب IP العميل
     */
    private function getClientIp(): string
    {
        if (!empty($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return trim($_SERVER['REMOTE_ADDR']);
        }

        return '0.0.0.0';
    }
}
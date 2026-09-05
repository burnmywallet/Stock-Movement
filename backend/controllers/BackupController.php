<?php

/**
 * ================================================================
 * Logistox - Backup Controller
 * نظام إدارة المخازن والمخزون v5.0
 * مخصص لشركة البركة لتوريد وتصنيع اللحوم - مصر
 * ================================================================
 *
 * الملف: backend/controllers/BackupController.php
 * الوظيفة: إدارة النسخ الاحتياطي لقاعدة البيانات
 *
 * المسؤوليات:
 * 1. عرض حالة النسخ الاحتياطي (status)
 * 2. عرض قائمة النسخ الاحتياطية السابقة (index)
 * 3. إنشاء نسخة احتياطية يدوية (create)
 * 4. تنزيل نسخة احتياطية (download)
 * 5. استيراد نسخة احتياطية (restore) - ⚠️ خطير جداً
 * 6. حذف نسخة احتياطية (destroy)
 * 7. تنظيف النسخ الاحتياطية القديمة (cleanup)
 *
 * الصلاحيات المطلوبة:
 * - backup.view: عرض النسخ الاحتياطية
 * - backup.create: إنشاء نسخة احتياطية
 * - backup.restore: استيراد نسخة احتياطية (Super Admin فقط)
 * - backup.delete: حذف نسخة احتياطية
 *
 * قيود الأمان:
 * - استيراد النسخ الاحتياطية متاح لمدير النظام فقط (role_id = 1)
 * - تأكيد مزدوج قبل الاستيراد
 * - إنشاء نسخة احتياطية تلقائية قبل كل استيراد
 * - منع تنزيل الملفات خارج مجلد backups (Path Traversal)
 * - التحقق من وجود الملف فعلياً قبل التنزيل
 * - تسجيل كل العمليات في audit_logs
 *
 * ملاحظات هامة:
 * - يعتمد على BackupService لتنفيذ العمليات
 * - يعتمد على AuditService لتسجيل العمليات
 * - يستخدم mysqldump لإنشاء النسخ الاحتياطية
 * - النسخ الاحتياطية تُخزن في storage/backups/
 * ================================================================
 */

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Response;
use App\Services\BackupService;
use App\Services\AuditService;
use Throwable;
use Exception;

/**
 * Class BackupController
 *
 * Controller لإدارة النسخ الاحتياطي
 */
class BackupController
{
    /**
     * @var Database اتصال قاعدة البيانات
     */
    private Database $db;

    /**
     * @var BackupService خدمة النسخ الاحتياطي
     */
    private BackupService $backupService;

    /**
     * @var AuditService خدمة التدقيق
     */
    private AuditService $auditService;

    /**
     * @var string مسار مجلد النسخ الاحتياطي
     */
    private string $backupPath;

    /**
     * @var int الحد الأقصى لحجم الملف المسموح بتنزيله (بالبايت) - 500 ميجا
     */
    private const MAX_DOWNLOAD_SIZE = 524288000;

    /**
     * Constructor
     */
    public function __construct()
    {
        try {
            $this->db = Database::getInstance();
            $this->backupService = new BackupService($this->db);
            $this->auditService = new AuditService($this->db);
            $this->backupPath = dirname(__DIR__, 2) . '/storage/backups';
        } catch (Throwable $e) {
            error_log('[BACKUP_CONTROLLER] Initialization failed: ' . $e->getMessage());
            Response::internalError('فشل في تهيئة وحدة النسخ الاحتياطي');
        }
    }

    // =========================================================================
    // 1. حالة النسخ الاحتياطي (Status)
    // =========================================================================

    /**
     * عرض حالة النسخ الاحتياطي
     *
     * GET /api/backup/status
     *
     * @return void يرسل استجابة JSON
     */
    public function status(): void
    {
        try {
            $status = $this->backupService->getStatus();

            // إضافة معلومات إضافية
            $status['backup_path'] = $this->maskPath($status['backup_path'] ?? $this->backupPath);
            $status['disk_free_space'] = $this->getDiskFreeSpace();
            $status['disk_total_space'] = $this->getDiskTotalSpace();
            $status['mysqldump_available'] = $this->checkMysqldumpAvailable();
            $status['auto_backup_enabled'] = filter_var(
                getenv('BACKUP_AUTO_ENABLED') ?: 'false',
                FILTER_VALIDATE_BOOLEAN
            );
            $status['retention_days'] = (int) (getenv('BACKUP_RETENTION_DAYS') ?: 30);

            Response::success(
                message: 'تم جلب حالة النسخ الاحتياطي بنجاح',
                data: $status
            );

        } catch (Throwable $e) {
            error_log('[BACKUP_CONTROLLER] Status failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب حالة النسخ الاحتياطي');
        }
    }

    // =========================================================================
    // 2. عرض قائمة النسخ الاحتياطية (Index)
    // =========================================================================

    /**
     * عرض قائمة النسخ الاحتياطية السابقة
     *
     * GET /api/backup
     *
     * @return void يرسل استجابة JSON
     */
    public function index(): void
    {
        try {
            $backups = $this->backupService->listBackups();

            // إضافة معلومات إضافية لكل نسخة
            foreach ($backups as &$backup) {
                $backup['file_size_formatted'] = $this->formatFileSize((int) ($backup['file_size'] ?? 0));
                $backup['status_label'] = $this->translateStatus($backup['status'] ?? 'unknown');
                $backup['type_label'] = ($backup['type'] ?? 'manual') === 'auto' ? 'تلقائي' : 'يدوي';

                // التحقق من وجود الملف فعلياً
                $filepath = $this->backupPath . '/' . ($backup['filename'] ?? '');
                $backup['file_exists'] = file_exists($filepath);
            }

            Response::success(
                message: 'تم جلب قائمة النسخ الاحتياطية بنجاح',
                data: [
                    'count'   => count($backups),
                    'backups' => $backups,
                ]
            );

        } catch (Throwable $e) {
            error_log('[BACKUP_CONTROLLER] Index failed: ' . $e->getMessage());
            Response::internalError('فشل في جلب قائمة النسخ الاحتياطية');
        }
    }

    // =========================================================================
    // 3. إنشاء نسخة احتياطية يدوية (Create)
    // =========================================================================

    /**
     * إنشاء نسخة احتياطية يدوية
     *
     * POST /api/backup/create
     *
     * @return void يرسل استجابة JSON
     */
    public function create(): void
    {
        try {
            $currentUserId = $this->getCurrentUserId();

            // التحقق من توفر mysqldump
            if (!$this->checkMysqldumpAvailable()) {
                Response::internalError(
                    'أداة mysqldump غير متوفرة على الخادم. ' .
                    'يرجى تثبيت MySQL/MariaDB client tools.'
                );
            }

            // التحقق من مساحة القرص
            $freeSpace = disk_free_space($this->backupPath);
            if ($freeSpace !== false && $freeSpace < 10485760) { // أقل من 10 ميجا
                Response::internalError(
                    'مساحة القرص غير كافية لإنشاء نسخة احتياطية. ' .
                    'المتاح: ' . $this->formatFileSize((int) $freeSpace)
                );
            }

            // إنشاء النسخة الاحتياطية
            $result = $this->backupService->createBackup($currentUserId, 'manual');

            // تسجيل العملية
            $this->auditService->log(
                userId: $currentUserId,
                action: 'BACKUP_CREATE',
                entityType: 'backup',
                entityId: $result['backup_id'] ?? null,
                newValues: [
                    'filename'  => $result['filename'] ?? null,
                    'file_size' => $result['file_size'] ?? null,
                    'type'      => 'manual',
                ],
                description: "تم إنشاء نسخة احتياطية يدوية: {$result['filename']} (" .
                             $this->formatFileSize((int) ($result['file_size'] ?? 0)) . ")",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::created(
                message: 'تم إنشاء النسخة الاحتياطية بنجاح',
                data: $result
            );

        } catch (Throwable $e) {
            error_log('[BACKUP_CONTROLLER] Create failed: ' . $e->getMessage());
            Response::internalError('فشل في إنشاء النسخة الاحتياطية: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 4. تنزيل نسخة احتياطية (Download)
    // =========================================================================

    /**
     * تنزيل ملف نسخة احتياطية
     *
     * GET /api/backup/{id}/download
     *
     * @param array $params المعاملات من Router
     * @return void
     */
    public function download(array $params): void
    {
        try {
            $backupId = $this->validateBackupId($params);

            // جلب معلومات النسخة الاحتياطية
            $backup = $this->db->selectOne(
                "SELECT id, filename, file_size, status FROM backups WHERE id = ? AND status = 'completed'",
                [$backupId]
            );

            if (!$backup) {
                Response::notFound('النسخة الاحتياطية غير موجودة أو لم تكتمل');
            }

            $filename = $backup['filename'];

            // حماية من Path Traversal
            if ($this->isPathTraversal($filename)) {
                error_log("[BACKUP_CONTROLLER] Path traversal attempt: {$filename}");
                Response::forbidden('اسم الملف غير صالح');
            }

            $filepath = $this->backupPath . '/' . $filename;

            // التحقق من وجود الملف
            if (!is_file($filepath) || !is_readable($filepath)) {
                Response::notFound('ملف النسخة الاحتياطية غير موجود على الخادم');
            }

            // التحقق من حجم الملف
            $fileSize = filesize($filepath);
            if ($fileSize > self::MAX_DOWNLOAD_SIZE) {
                Response::badRequest(
                    'حجم الملف كبير جداً للتنزيل المباشر: ' . $this->formatFileSize($fileSize) .
                    '. يرجى تنزيله عبر FTP أو SSH.'
                );
            }

            // تسجيل العملية
            $currentUserId = $this->getCurrentUserId();
            $this->auditService->log(
                userId: $currentUserId,
                action: 'BACKUP_DOWNLOAD',
                entityType: 'backup',
                entityId: $backupId,
                description: "تم تنزيل نسخة احتياطية: {$filename}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            // إرسال الملف
            Response::download($filepath, $filename, 'application/sql');

        } catch (Throwable $e) {
            error_log('[BACKUP_CONTROLLER] Download failed: ' . $e->getMessage());
            Response::internalError('فشل في تنزيل النسخة الاحتياطية');
        }
    }

    // =========================================================================
    // 5. استيراد نسخة احتياطية (Restore) - ⚠️ خطير جداً
    // =========================================================================

    /**
     * استيراد نسخة احتياطية
     *
     * POST /api/backup/{id}/restore
     *
     * Request Body (JSON):
     * {
     *   "confirm": true,
     *   "confirm_text": "أؤكد استيراد النسخة الاحتياطية",
     *   "create_backup_before": true
     * }
     *
     * ⚠️ تحذير: هذه العملية ستستبدل كل بيانات قاعدة البيانات الحالية!
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function restore(array $params): void
    {
        try {
            $currentUserId = $this->getCurrentUserId();

            // 1. التحقق من أن المستخدم هو Super Admin
            $this->requireSuperAdmin($currentUserId);

            // 2. التحقق من معرف النسخة الاحتياطية
            $backupId = $this->validateBackupId($params);

            // 3. جلب معلومات النسخة الاحتياطية
            $backup = $this->db->selectOne(
                "SELECT id, filename, file_size, status FROM backups WHERE id = ? AND status = 'completed'",
                [$backupId]
            );

            if (!$backup) {
                Response::notFound('النسخة الاحتياطية غير موجودة أو لم تكتمل');
            }

            $filename = $backup['filename'];

            // 4. حماية من Path Traversal
            if ($this->isPathTraversal($filename)) {
                Response::forbidden('اسم الملف غير صالح');
            }

            $filepath = $this->backupPath . '/' . $filename;

            // 5. التحقق من وجود الملف
            if (!is_file($filepath) || !is_readable($filepath)) {
                Response::notFound('ملف النسخة الاحتياطية غير موجود على الخادم');
            }

            // 6. التحقق من التأكيد المزدوج
            $input = $this->getJsonInput();

            if (empty($input['confirm']) || $input['confirm'] !== true) {
                Response::badRequest(
                    'يجب تأكيد العملية بإرسال confirm: true. ' .
                    'تحذير: هذه العملية ستستبدل كل بيانات قاعدة البيانات الحالية!'
                );
            }

            $confirmText = $input['confirm_text'] ?? '';
            if ($confirmText !== 'أؤكد استيراد النسخة الاحتياطية') {
                Response::badRequest(
                    'يجب كتابة نص التأكيد بالضبط: "أؤكد استيراد النسخة الاحتياطية"'
                );
            }

            // 7. إنشاء نسخة احتياطية تلقائية قبل الاستيراد (حماية)
            $createBackupBefore = $input['create_backup_before'] ?? true;
            $preBackupResult = null;

            if ($createBackupBefore) {
                try {
                    $preBackupResult = $this->backupService->createBackup($currentUserId, 'auto');

                    $this->auditService->log(
                        userId: $currentUserId,
                        action: 'BACKUP_CREATE',
                        entityType: 'backup',
                        entityId: $preBackupResult['backup_id'] ?? null,
                        description: "نسخة احتياطية تلقائية قبل الاستيراد: {$preBackupResult['filename']}",
                        ipAddress: $this->getClientIp(),
                        userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    );
                } catch (Throwable $e) {
                    error_log('[BACKUP_CONTROLLER] Pre-restore backup failed: ' . $e->getMessage());
                    Response::internalError(
                        'فشل في إنشاء نسخة احتياطية قبل الاستيراد. ' .
                        'تم إلغاء العملية لحماية البيانات. الخطأ: ' . $e->getMessage()
                    );
                }
            }

            // 8. تنفيذ الاستيراد
            $restoreResult = $this->backupService->restoreBackup($filepath, $currentUserId);

            // 9. تسجيل العملية
            $this->auditService->log(
                userId: $currentUserId,
                action: 'BACKUP_RESTORE',
                entityType: 'backup',
                entityId: $backupId,
                newValues: [
                    'filename'             => $filename,
                    'pre_backup'           => $preBackupResult['filename'] ?? null,
                    'create_backup_before' => $createBackupBefore,
                ],
                description: "تم استيراد نسخة احتياطية: {$filename} - " .
                             ($createBackupBefore ? "تم إنشاء نسخة حماية: " . ($preBackupResult['filename'] ?? 'غير معروف') : "بدون نسخة حماية"),
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم استيراد النسخة الاحتياطية بنجاح',
                data: [
                    'restored_backup' => $filename,
                    'pre_backup'      => $preBackupResult['filename'] ?? null,
                    'message'         => 'تم استيراد البيانات بنجاح. يرجى تسجيل الدخول مرة أخرى.',
                ]
            );

        } catch (Throwable $e) {
            error_log('[BACKUP_CONTROLLER] Restore failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'مدير النظام فقط')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في استيراد النسخة الاحتياطية: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // 6. حذف نسخة احتياطية (Destroy)
    // =========================================================================

    /**
     * حذف نسخة احتياطية
     *
     * DELETE /api/backup/{id}
     *
     * @param array $params المعاملات من Router
     * @return void يرسل استجابة JSON
     */
    public function destroy(array $params): void
    {
        try {
            $backupId = $this->validateBackupId($params);
            $currentUserId = $this->getCurrentUserId();

            // جلب معلومات النسخة قبل الحذف
            $backup = $this->db->selectOne(
                "SELECT id, filename, file_size, type FROM backups WHERE id = ?",
                [$backupId]
            );

            if (!$backup) {
                Response::notFound('النسخة الاحتياطية غير موجودة');
            }

            // حذف النسخة
            $this->backupService->deleteBackup($backupId);

            // تسجيل العملية
            $this->auditService->log(
                userId: $currentUserId,
                action: 'BACKUP_DELETE',
                entityType: 'backup',
                entityId: $backupId,
                oldValues: [
                    'filename'  => $backup['filename'],
                    'file_size' => $backup['file_size'],
                    'type'      => $backup['type'],
                ],
                description: "تم حذف نسخة احتياطية: {$backup['filename']}",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: 'تم حذف النسخة الاحتياطية بنجاح',
                data: null,
                status: 200
            );

        } catch (Throwable $e) {
            error_log('[BACKUP_CONTROLLER] Destroy failed: ' . $e->getMessage());
            Response::internalError('فشل في حذف النسخة الاحتياطية');
        }
    }

    // =========================================================================
    // 7. تنظيف النسخ الاحتياطية القديمة (Cleanup)
    // =========================================================================

    /**
     * تنظيف النسخ الاحتياطية التلقائية القديمة
     *
     * POST /api/backup/cleanup
     *
     * Request Body (JSON):
     * {
     *   "retention_days": 30
     * }
     *
     * @return void يرسل استجابة JSON
     */
    public function cleanup(): void
    {
        try {
            $currentUserId = $this->getCurrentUserId();

            // التحقق من أن المستخدم هو Super Admin
            $this->requireSuperAdmin($currentUserId);

            $input = $this->getJsonInput();
            $retentionDays = (int) ($input['retention_days'] ?? (getenv('BACKUP_RETENTION_DAYS') ?: 30));

            if ($retentionDays < 7) {
                Response::badRequest('يجب الاحتفاظ بالنسخ الاحتياطية لمدة 7 أيام على الأقل');
            }

            $deletedCount = $this->backupService->cleanupOldBackups($retentionDays);

            // تسجيل العملية
            $this->auditService->log(
                userId: $currentUserId,
                action: 'BACKUP_CLEANUP',
                entityType: 'backup',
                entityId: null,
                description: "تم تنظيف {$deletedCount} نسخة احتياطية أقدم من {$retentionDays} يوم",
                ipAddress: $this->getClientIp(),
                userAgent: $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            );

            Response::success(
                message: "تم حذف {$deletedCount} نسخة احتياطية قديمة بنجاح",
                data: [
                    'deleted_count'   => $deletedCount,
                    'retention_days'  => $retentionDays,
                ]
            );

        } catch (Throwable $e) {
            error_log('[BACKUP_CONTROLLER] Cleanup failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'مدير النظام فقط')) {
                Response::forbidden($e->getMessage());
            }

            Response::internalError('فشل في تنظيف النسخ الاحتياطية');
        }
    }

    // =========================================================================
    // Helper Methods - الأمان
    // =========================================================================

    /**
     * التحقق من أن المستخدم هو Super Admin
     *
     * @param int $userId معرف المستخدم
     * @throws Exception إذا لم يكن المستخدم مديراً
     */
    private function requireSuperAdmin(int $userId): void
    {
        $user = $this->db->selectOne(
            "SELECT id, role_id, username FROM users WHERE id = ? AND deleted_at IS NULL",
            [$userId]
        );

        if (!$user || (int) $user['role_id'] !== 1) {
            throw new Exception('هذه العملية متاحة لمدير النظام فقط (Super Admin)');
        }
    }

    /**
     * التحقق من Path Traversal
     *
     * يمنع الوصول لملفات خارج مجلد backups
     *
     * @param string $filename اسم الملف
     * @return bool true إذا كان هناك محاولة Path Traversal
     */
    private function isPathTraversal(string $filename): bool
    {
        // منع ../ و ..\ و المسارات المطلقة
        if (str_contains($filename, '..') || str_contains($filename, '\\')) {
            return true;
        }

        if (str_starts_with($filename, '/') || str_starts_with($filename, '\\')) {
            return true;
        }

        // منع الأسماء التي تحتوي على مجلدات
        if (str_contains($filename, '/')) {
            return true;
        }

        // التحقق من أن المسار النهائي داخل مجلد backups
        $realBackupPath = realpath($this->backupPath);
        $realFilePath = realpath($this->backupPath . '/' . $filename);

        if ($realBackupPath !== false && $realFilePath !== false) {
            if (!str_starts_with($realFilePath, $realBackupPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * التحقق من توفر mysqldump
     *
     * @return bool true إذا كان mysqldump متاحاً
     */
    private function checkMysqldumpAvailable(): bool
    {
        $output = [];
        $returnCode = 0;

        @exec('which mysqldump 2>/dev/null || where mysqldump 2>nul', $output, $returnCode);

        return $returnCode === 0;
    }

    // =========================================================================
    // Helper Methods - التنسيق
    // =========================================================================

    /**
     * تنسيق حجم الملف
     *
     * @param int $bytes الحجم بالبايت
     * @return string الحجم المنسق
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 بايت';
        }

        $units = ['بايت', 'كيلوبايت', 'ميجابايت', 'جيجابايت', 'تيرابايت'];
        $factor = floor(log($bytes, 1024));
        $factor = min($factor, count($units) - 1);

        return round($bytes / pow(1024, $factor), 2) . ' ' . $units[$factor];
    }

    /**
     * إخفاء المسار الكامل (للأمان)
     *
     * @param string $path المسار الكامل
     * @return string المسار المخفي
     */
    private function maskPath(string $path): string
    {
        // عرض فقط آخر جزأين من المسار
        $parts = explode('/', str_replace('\\', '/', $path));
        $count = count($parts);

        if ($count > 2) {
            return '.../' . $parts[$count - 2] . '/' . $parts[$count - 1];
        }

        return $path;
    }

    /**
     * ترجمة حالة النسخة الاحتياطية
     */
    private function translateStatus(string $status): string
    {
        $translations = [
            'pending'   => 'قيد التنفيذ',
            'completed' => 'مكتمل',
            'failed'    => 'فشل',
            'unknown'   => 'غير معروف',
        ];

        return $translations[$status] ?? $status;
    }

    /**
     * الحصول على المساحة الحرة في القرص
     */
    private function getDiskFreeSpace(): string
    {
        $free = @disk_free_space($this->backupPath);
        return $free !== false ? $this->formatFileSize((int) $free) : 'غير معروف';
    }

    /**
     * الحصول على المساحة الإجمالية للقرص
     */
    private function getDiskTotalSpace(): string
    {
        $total = @disk_total_space($this->backupPath);
        return $total !== false ? $this->formatFileSize((int) $total) : 'غير معروف';
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
     * التحقق من صحة معرف النسخة الاحتياطية
     */
    private function validateBackupId(array $params): int
    {
        $id = $params['id'] ?? null;

        if ($id === null || !is_numeric($id) || (int) $id <= 0) {
            Response::badRequest('معرف النسخة الاحتياطية غير صالح. يجب أن يكون رقماً موجباً.');
        }

        return (int) $id;
    }

    /**
     * جلب معرف المستخدم الحالي
     */
    private function getCurrentUserId(): int
    {
        if (isset($_REQUEST['user']['id'])) {
            return (int) $_REQUEST['user']['id'];
        }

        if (isset($GLOBALS['current_user_id'])) {
            return (int) $GLOBALS['current_user_id'];
        }

        error_log('[BACKUP_CONTROLLER] Current user ID not found');
        Response::unauthorized('لم يتم العثور على بيانات المستخدم');
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
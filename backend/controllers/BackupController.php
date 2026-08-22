<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/controllers/BackupController.php
// الوصف: متحكم إدارة النسخ الاحتياطي - إنشاء، استعادة، تحميل، جدولة
// التاريخ: 2026-08-22
// ================================================================

namespace Controllers;

use Core\Database;
use Core\Auth;
use Core\Audit;
use Exception;

class BackupController
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var Auth $auth - نظام المصادقة
     */
    private $auth;
    
    /**
     * @var Audit $audit - سجل التدقيق
     */
    private $audit;
    
    /**
     * @var string $backupPath - مسار حفظ النسخ الاحتياطية
     */
    private $backupPath;
    
    /**
     * @var int $retentionDays - عدد أيام الاحتفاظ بالنسخ
     */
    private $retentionDays = 30;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
        $this->audit = new Audit();
        $this->backupPath = $_ENV['BACKUP_PATH'] ?? __DIR__ . '/../../backups/';
        $this->retentionDays = (int)($_ENV['BACKUP_RETENTION_DAYS'] ?? 30);
        
        // إنشاء مجلد النسخ الاحتياطي إذا لم يكن موجوداً
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    /**
     * GET /api/backup
     * جلب قائمة النسخ الاحتياطية
     */
    public function index(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'backup.view')) {
                errorResponse('ليس لديك صلاحية لعرض النسخ الاحتياطية', 403);
                return;
            }

            $backups = $this->getBackupFiles();
            $stats = $this->getBackupStats();

            successResponse('تم جلب قائمة النسخ الاحتياطية', [
                'data' => $backups,
                'stats' => $stats
            ]);

        } catch (Exception $e) {
            error_log('Backup list error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/backup/create
     * إنشاء نسخة احتياطية جديدة
     */
    public function create(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'backup.create')) {
                errorResponse('ليس لديك صلاحية لإنشاء نسخ احتياطية', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $type = $input['type'] ?? 'manual';
            $compress = $input['compress'] ?? true;
            $notes = $input['notes'] ?? null;

            // بدء عملية النسخ الاحتياطي
            $startTime = microtime(true);
            $backupFile = $this->createBackup($compress);
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            $fileSize = filesize($backupFile);

            // حساب الـ Hash
            $fileHash = hash_file('sha256', $backupFile);

            // تسجيل في قاعدة البيانات
            $backupId = $this->db->insert('backup_logs', [
                'backup_type' => $type,
                'backup_file' => basename($backupFile),
                'file_size' => $fileSize,
                'file_hash' => $fileHash,
                'status' => 'completed',
                'started_at' => date('Y-m-d H:i:s', $startTime),
                'completed_at' => date('Y-m-d H:i:s'),
                'notes' => $notes,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'BACKUP_CREATED',
                'backup',
                "إنشاء نسخة احتياطية: " . basename($backupFile),
                [
                    'filename' => basename($backupFile),
                    'size' => $fileSize,
                    'duration' => $duration,
                    'type' => $type,
                    'hash' => $fileHash
                ]
            );

            // تنظيف النسخ القديمة
            $this->cleanupOldBackups();

            successResponse('تم إنشاء النسخة الاحتياطية بنجاح', [
                'backup_id' => $backupId,
                'filename' => basename($backupFile),
                'size' => $this->formatSize($fileSize),
                'duration' => $duration . ' seconds',
                'hash' => $fileHash
            ]);

        } catch (Exception $e) {
            error_log('Backup create error: ' . $e->getMessage());
            errorResponse('حدث خطأ في إنشاء النسخة الاحتياطية: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/backup/restore/{id}
     * استعادة نسخة احتياطية
     */
    public function restore(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'backup.restore')) {
                errorResponse('ليس لديك صلاحية لاستعادة النسخ الاحتياطية', 403);
                return;
            }

            // جلب معلومات النسخة
            $backup = $this->db->queryOne("
                SELECT * FROM backup_logs WHERE id = :id AND status = 'completed'
            ", ['id' => $id]);

            if (!$backup) {
                errorResponse('النسخة الاحتياطية غير موجودة أو غير مكتملة');
                return;
            }

            $filePath = $this->backupPath . $backup['backup_file'];
            
            if (!file_exists($filePath)) {
                errorResponse('ملف النسخة الاحتياطية غير موجود');
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $confirm = $input['confirm'] ?? false;

            if (!$confirm) {
                errorResponse('يجب تأكيد عملية الاستعادة', 400, null, [
                    'confirmation_required' => true,
                    'warning' => 'سيتم استبدال جميع البيانات الحالية'
                ]);
                return;
            }

            // بدء عملية الاستعادة
            $startTime = microtime(true);
            
            // التحقق من صحة الملف
            $this->validateBackupFile($filePath, $backup['file_hash']);

            // استعادة قاعدة البيانات
            $this->restoreDatabase($filePath);
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            // تحديث سجل الاستعادة
            $this->db->update('backup_logs', [
                'restored_at' => date('Y-m-d H:i:s'),
                'restored_by' => $userId,
                'restore_details' => json_encode([
                    'duration' => $duration,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ])
            ], ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'BACKUP_RESTORED',
                'backup',
                "استعادة نسخة احتياطية: " . $backup['backup_file'],
                [
                    'filename' => $backup['backup_file'],
                    'duration' => $duration,
                    'backup_id' => $id
                ]
            );

            successResponse('تم استعادة النسخة الاحتياطية بنجاح', [
                'duration' => $duration . ' seconds',
                'filename' => $backup['backup_file']
            ]);

        } catch (Exception $e) {
            error_log('Backup restore error: ' . $e->getMessage());
            errorResponse('حدث خطأ في استعادة النسخة الاحتياطية: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/backup/download/{id}
     * تحميل نسخة احتياطية
     */
    public function download(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'backup.view')) {
                errorResponse('ليس لديك صلاحية لتحميل النسخ الاحتياطية', 403);
                return;
            }

            // جلب معلومات النسخة
            $backup = $this->db->queryOne("
                SELECT * FROM backup_logs WHERE id = :id
            ", ['id' => $id]);

            if (!$backup) {
                errorResponse('النسخة الاحتياطية غير موجودة');
                return;
            }

            $filePath = $this->backupPath . $backup['backup_file'];
            
            if (!file_exists($filePath)) {
                errorResponse('ملف النسخة الاحتياطية غير موجود');
                return;
            }

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'BACKUP_DOWNLOADED',
                'backup',
                "تحميل نسخة احتياطية: " . $backup['backup_file'],
                [
                    'filename' => $backup['backup_file'],
                    'size' => filesize($filePath)
                ]
            );

            // إرسال الملف للتحميل
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            
            readfile($filePath);
            exit;

        } catch (Exception $e) {
            error_log('Backup download error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/backup/{id}
     * حذف نسخة احتياطية
     */
    public function delete(int $id): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'backup.delete')) {
                errorResponse('ليس لديك صلاحية لحذف النسخ الاحتياطية', 403);
                return;
            }

            // جلب معلومات النسخة
            $backup = $this->db->queryOne("
                SELECT * FROM backup_logs WHERE id = :id
            ", ['id' => $id]);

            if (!$backup) {
                errorResponse('النسخة الاحتياطية غير موجودة');
                return;
            }

            $filePath = $this->backupPath . $backup['backup_file'];
            
            // حذف الملف
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // حذف السجل
            $this->db->delete('backup_logs', ['id' => $id]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'BACKUP_DELETED',
                'backup',
                "حذف نسخة احتياطية: " . $backup['backup_file'],
                ['filename' => $backup['backup_file']]
            );

            successResponse('تم حذف النسخة الاحتياطية بنجاح');

        } catch (Exception $e) {
            error_log('Backup delete error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/backup/schedule
     * جدولة النسخ الاحتياطي التلقائي
     */
    public function schedule(): void
    {
        try {
            $userId = $_REQUEST['user_id'] ?? null;
            
            if (!$userId) {
                errorResponse('غير مصرح', 401);
                return;
            }

            if (!$this->auth->hasPermission($userId, 'backup.schedule')) {
                errorResponse('ليس لديك صلاحية لإدارة جدولة النسخ الاحتياطي', 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            $enabled = $input['enabled'] ?? true;
            $time = $input['time'] ?? '23:00';
            $frequency = $input['frequency'] ?? 'daily'; // daily, weekly, monthly

            // تحديث الإعدادات في قاعدة البيانات
            $this->db->execute("
                UPDATE system_settings 
                SET setting_value = :value, updated_at = NOW()
                WHERE setting_key = :key
            ", [
                'key' => 'auto_backup_enabled',
                'value' => $enabled ? 'true' : 'false'
            ]);

            $this->db->execute("
                UPDATE system_settings 
                SET setting_value = :value, updated_at = NOW()
                WHERE setting_key = :key
            ", [
                'key' => 'auto_backup_time',
                'value' => $time
            ]);

            $this->db->execute("
                UPDATE system_settings 
                SET setting_value = :value, updated_at = NOW()
                WHERE setting_key = :key
            ", [
                'key' => 'auto_backup_frequency',
                'value' => $frequency
            ]);

            // تسجيل في سجل التدقيق
            $this->audit->log(
                $userId,
                'BACKUP_SCHEDULED',
                'backup',
                "تحديث جدولة النسخ الاحتياطي",
                [
                    'enabled' => $enabled,
                    'time' => $time,
                    'frequency' => $frequency
                ]
            );

            successResponse('تم تحديث جدولة النسخ الاحتياطي بنجاح', [
                'enabled' => $enabled,
                'time' => $time,
                'frequency' => $frequency
            ]);

        } catch (Exception $e) {
            error_log('Backup schedule error: ' . $e->getMessage());
            errorResponse('حدث خطأ: ' . $e->getMessage(), 500);
        }
    }

    // ================================================================
    // دوال مساعدة خاصة
    // ================================================================

    /**
     * إنشاء نسخة احتياطية
     */
    private function createBackup(bool $compress = true): string
    {
        $config = $this->db->getConnection();
        $dbname = $_ENV['DB_NAME'] ?? 'inventory_system';
        $dbuser = $_ENV['DB_USER'] ?? 'angel';
        $dbpass = $_ENV['DB_PASS'] ?? 'Lecico10@';
        $dbhost = $_ENV['DB_HOST'] ?? 'localhost';

        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $this->backupPath . $filename;

        // بناء أمر التصدير
        $command = sprintf(
            'mysqldump --host=%s --user=%s --password=%s --single-transaction --routines --triggers --events --add-drop-database --databases %s > %s 2>&1',
            escapeshellarg($dbhost),
            escapeshellarg($dbuser),
            escapeshellarg($dbpass),
            escapeshellarg($dbname),
            escapeshellarg($filepath)
        );

        // تنفيذ الأمر
        $output = shell_exec($command);
        
        if (!file_exists($filepath) || filesize($filepath) === 0) {
            throw new Exception('فشل إنشاء النسخة الاحتياطية: ' . ($output ?? 'unknown error'));
        }

        // إذا كان الضغط مطلوباً
        if ($compress) {
            $compressedFile = $filepath . '.gz';
            $gz = gzopen($compressedFile, 'wb9');
            $data = file_get_contents($filepath);
            gzwrite($gz, $data);
            gzclose($gz);
            
            // حذف الملف غير المضغوط
            unlink($filepath);
            $filepath = $compressedFile;
        }

        return $filepath;
    }

    /**
     * استعادة قاعدة البيانات من ملف
     */
    private function restoreDatabase(string $filePath): void
    {
        $config = $this->db->getConnection();
        $dbname = $_ENV['DB_NAME'] ?? 'inventory_system';
        $dbuser = $_ENV['DB_USER'] ?? 'angel';
        $dbpass = $_ENV['DB_PASS'] ?? 'Lecico10@';
        $dbhost = $_ENV['DB_HOST'] ?? 'localhost';

        // فك الضغط إذا كان الملف مضغوطاً
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        if ($ext === 'gz') {
            $tempFile = str_replace('.gz', '', $filePath);
            $gz = gzopen($filePath, 'rb');
            $data = gzread($gz, filesize($filePath) * 10);
            gzclose($gz);
            file_put_contents($tempFile, $data);
            $filePath = $tempFile;
        }

        // بناء أمر الاستعادة
        $command = sprintf(
            'mysql --host=%s --user=%s --password=%s %s < %s 2>&1',
            escapeshellarg($dbhost),
            escapeshellarg($dbuser),
            escapeshellarg($dbpass),
            escapeshellarg($dbname),
            escapeshellarg($filePath)
        );

        // تنفيذ الأمر
        $output = shell_exec($command);
        
        if ($output) {
            throw new Exception('فشل استعادة قاعدة البيانات: ' . $output);
        }

        // حذف الملف المؤقت إذا كان مضغوطاً
        if ($ext === 'gz' && file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * التحقق من صحة ملف النسخ الاحتياطي
     */
    private function validateBackupFile(string $filePath, string $expectedHash): void
    {
        if (!file_exists($filePath)) {
            throw new Exception('الملف غير موجود');
        }

        $actualHash = hash_file('sha256', $filePath);
        
        if ($actualHash !== $expectedHash) {
            throw new Exception('الملف تالف أو تم التلاعب به');
        }

        // التحقق من أن الملف يحتوي على بيانات SQL صالحة
        $content = file_get_contents($filePath);
        if (strpos($content, 'CREATE TABLE') === false && strpos($content, 'INSERT INTO') === false) {
            throw new Exception('الملف لا يحتوي على بيانات قاعدة بيانات صالحة');
        }
    }

    /**
     * الحصول على قائمة ملفات النسخ الاحتياطي
     */
    private function getBackupFiles(): array
    {
        $files = [];
        $pattern = $this->backupPath . 'backup_*.sql*';
        
        foreach (glob($pattern) as $file) {
            $stat = stat($file);
            $files[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'size_formatted' => $this->formatSize(filesize($file)),
                'created_at' => date('Y-m-d H:i:s', $stat['mtime']),
                'modified_at' => date('Y-m-d H:i:s', $stat['mtime']),
                'hash' => hash_file('sha256', $file),
                'extension' => pathinfo($file, PATHINFO_EXTENSION)
            ];
        }

        // ترتيب حسب التاريخ (الأحدث أولاً)
        usort($files, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        return $files;
    }

    /**
     * إحصائيات النسخ الاحتياطي
     */
    private function getBackupStats(): array
    {
        $files = $this->getBackupFiles();
        $totalSize = array_sum(array_column($files, 'size'));

        $stats = [
            'total_backups' => count($files),
            'total_size' => $totalSize,
            'total_size_formatted' => $this->formatSize($totalSize),
            'oldest_backup' => !empty($files) ? $files[count($files)-1]['created_at'] : null,
            'newest_backup' => !empty($files) ? $files[0]['created_at'] : null,
            'storage_path' => $this->backupPath,
            'retention_days' => $this->retentionDays,
            'auto_backup_enabled' => $_ENV['BACKUP_ENABLED'] ?? false
        ];

        // إحصائيات من قاعدة البيانات
        $dbStats = $this->db->queryOne("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(CASE WHEN status = 'restored' THEN 1 END) as restored,
                MAX(created_at) as last_backup,
                MIN(created_at) as first_backup
            FROM backup_logs
        ");

        if ($dbStats) {
            $stats['database'] = [
                'total_logs' => (int)($dbStats['total'] ?? 0),
                'completed' => (int)($dbStats['completed'] ?? 0),
                'failed' => (int)($dbStats['failed'] ?? 0),
                'restored' => (int)($dbStats['restored'] ?? 0),
                'last_backup' => $dbStats['last_backup'] ?? null,
                'first_backup' => $dbStats['first_backup'] ?? null
            ];
        }

        return $stats;
    }

    /**
     * تنظيف النسخ القديمة
     */
    private function cleanupOldBackups(): void
    {
        $files = $this->getBackupFiles();
        $deleteCount = count($files) - $this->retentionDays;
        
        if ($deleteCount > 0) {
            // ترتيب من الأقدم إلى الأحدث
            usort($files, function($a, $b) {
                return strtotime($a['created_at']) - strtotime($b['created_at']);
            });

            // حذف الأقدم
            for ($i = 0; $i < $deleteCount; $i++) {
                $filePath = $this->backupPath . $files[$i]['filename'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
        }
    }

    /**
     * تنسيق حجم الملف
     */
    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}

// ================================================================
// انتهى الملف
// ================================================================

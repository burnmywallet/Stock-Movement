<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Exception;

/**
 * ============================================================================
 * Backup Service
 * مسؤول عن: إنشاء واستعادة النسخ الاحتياطية
 * ============================================================================
 */
class BackupService
{
    private Database $db;
    private string $backupPath;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->backupPath = __DIR__ . '/../../storage/backups';

        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    /**
     * إنشاء نسخة احتياطية
     */
    public function createBackup(int $userId, string $type = 'manual'): array
    {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup_{$timestamp}.sql";
        $filepath = $this->backupPath . '/' . $filename;

        // تسجيل بدء العملية
        $this->db->execute("
            INSERT INTO backups (filename, type, status, created_by)
            VALUES (?, ?, 'pending', ?)
        ", [$filename, $type, $userId]);

        $backupId = (int)$this->db->lastInsertId();

        try {
            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $db = $_ENV['DB_DATABASE'] ?? 'inventory_system';
            $user = $_ENV['DB_USERNAME'] ?? 'root';
            $pass = $_ENV['DB_PASSWORD'] ?? '';

            // استخدام mysqldump
            $passwordArg = $pass !== '' ? "-p'" . escapeshellarg($pass) . "'" : '';
            $command = sprintf(
                'mysqldump -h %s -P %s -u %s %s %s --single-transaction --routines --triggers %s > %s 2>&1',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($user),
                $passwordArg,
                '',
                escapeshellarg($db),
                escapeshellarg($filepath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new Exception('فشل تنفيذ mysqldump: ' . implode("\n", $output));
            }

            if (!file_exists($filepath)) {
                throw new Exception('لم يتم إنشاء ملف النسخة الاحتياطية.');
            }

            $fileSize = filesize($filepath);

            // تحديث الحالة
            $this->db->execute("
                UPDATE backups
                SET file_size = ?, status = 'completed', completed_at = NOW()
                WHERE id = ?
            ", [$fileSize, $backupId]);

            return [
                'success' => true,
                'backup_id' => $backupId,
                'filename' => $filename,
                'file_size' => $fileSize,
                'message' => 'تم إنشاء النسخة الاحتياطية بنجاح.'
            ];

        } catch (Exception $e) {
            $this->db->execute("
                UPDATE backups SET status = 'failed' WHERE id = ?
            ", [$backupId]);

            if (file_exists($filepath)) {
                @unlink($filepath);
            }

            throw new Exception('فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage());
        }
    }

    /**
     * جلب قائمة النسخ الاحتياطية
     */
    public function listBackups(): array
    {
        $backups = $this->db->fetchAll("
            SELECT b.*, u.full_name as created_by_name
            FROM backups b
            LEFT JOIN users u ON b.created_by = u.id
            ORDER BY b.created_at DESC
        ");

        // التحقق من وجود الملفات فعلياً
        foreach ($backups as &$backup) {
            $filepath = $this->backupPath . '/' . $backup['filename'];
            $backup['file_exists'] = file_exists($filepath);
        }

        return $backups;
    }

    /**
     * حذف نسخة احتياطية
     */
    public function deleteBackup(int $backupId): void
    {
        $backup = $this->db->fetch("SELECT filename FROM backups WHERE id = ?", [$backupId]);

        if (!$backup) {
            throw new Exception('النسخة الاحتياطية غير موجودة.');
        }

        $filepath = $this->backupPath . '/' . $backup['filename'];
        if (file_exists($filepath)) {
            @unlink($filepath);
        }

        $this->db->execute("DELETE FROM backups WHERE id = ?", [$backupId]);
    }

    /**
     * تنظيف النسخ الاحتياطية القديمة
     */
    public function cleanupOldBackups(int $retentionDays = 30): int
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));

        $oldBackups = $this->db->fetchAll("
            SELECT id, filename FROM backups
            WHERE created_at < ? AND type = 'auto'
        ", [$cutoffDate]);

        $deleted = 0;
        foreach ($oldBackups as $backup) {
            $filepath = $this->backupPath . '/' . $backup['filename'];
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
            $this->db->execute("DELETE FROM backups WHERE id = ?", [$backup['id']]);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * معلومات الحالة
     */
    public function getStatus(): array
    {
        $totalBackups = $this->db->fetch("SELECT COUNT(*) as count FROM backups")['count'] ?? 0;
        $totalSize = $this->db->fetch("SELECT COALESCE(SUM(file_size), 0) as total FROM backups WHERE status = 'completed'")['total'] ?? 0;
        $lastBackup = $this->db->fetch("SELECT created_at FROM backups WHERE status = 'completed' ORDER BY created_at DESC LIMIT 1");

        return [
            'total_backups' => (int)$totalBackups,
            'total_size_bytes' => (int)$totalSize,
            'total_size_mb' => round((int)$totalSize / 1024 / 1024, 2),
            'last_backup_at' => $lastBackup['created_at'] ?? null,
            'backup_path' => $this->backupPath,
        ];
    }
}
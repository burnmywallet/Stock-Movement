<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * ============================================================================
 * Audit Service
 * مسؤول عن: تسجيل كل العمليات الحساسة في النظام
 * ============================================================================
 */
class AuditService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * تسجيل عملية
     */
    public function log(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        try {
            $this->db->execute("
                INSERT INTO audit_logs (
                    user_id, action, entity_type, entity_id, old_values, new_values,
                    ip_address, user_agent, description
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $userId,
                $action,
                $entityType,
                $entityId,
                $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
                $description,
            ]);
        } catch (\Exception $e) {
            // فشل التسجيل لا يجب أن يكسر العملية
            error_log('[AUDIT] Failed to log: ' . $e->getMessage());
        }
    }

    /**
     * جلب السجل مع الفلاتر
     */
    public function getLogs(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $sql = "
            SELECT al.*, u.full_name as user_name, u.username, r.display_name as role_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND al.action = ?";
            $params[] = $filters['action'];
        }

        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = ?";
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (al.description LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * عد السجلات
     */
    public function countLogs(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM audit_logs al WHERE 1=1";
        $params = [];

        // نفس الفلاتر أعلاه...
        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }

        $result = $this->db->fetch($sql, $params);
        return (int)($result['count'] ?? 0);
    }
}
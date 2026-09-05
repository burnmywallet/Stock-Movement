<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * ============================================================================
 * Setting Service
 * ============================================================================
 */
class SettingService
{
    private Database $db;
    private ?array $cache = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * جلب كل الإعدادات
     */
    public function getAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $settings = $this->db->fetchAll("
            SELECT `key`, `value`, type, description
            FROM settings
            WHERE is_active = 1
        ");

        $result = [];
        foreach ($settings as $s) {
            $result[$s['key']] = $this->castValue($s['value'], $s['type']);
        }

        $this->cache = $result;
        return $result;
    }

    /**
     * جلب قيمة واحدة
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->getAll();
        return $all[$key] ?? $default;
    }

    /**
     * تحديث مجموعة إعدادات
     */
    public function updateMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->db->execute("
                UPDATE settings SET `value` = ?, updated_at = NOW() WHERE `key` = ?
            ", [is_array($value) ? json_encode($value) : (string)$value, $key]);
        }
        $this->cache = null;
    }

    /**
     * معلومات الشركة
     */
    public function getCompanyInfo(): array
    {
        return [
            'name' => $this->get('company.name', 'Logistox'),
            'logo' => $this->get('company.logo', '/assets/images/logo.png'),
            'address' => $this->get('company.address', ''),
            'phone' => $this->get('company.phone', ''),
            'email' => $this->get('company.email', ''),
            'currency' => $this->get('company.currency', 'EGP'),
            'currency_symbol' => $this->get('company.currency_symbol', 'ج.م'),
        ];
    }

    private function castValue(?string $value, string $type): mixed
    {
        if ($value === null) return null;

        return match($type) {
            'number' => (float)$value,
            'boolean' => (bool)$value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
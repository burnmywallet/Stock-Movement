<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/core/Model.php
// الوصف: النموذج الأساسي مع دعم ORM خفيف
// الإصدار: 2.0 Production Ready
// التاريخ: 2026-08-20
// ================================================================

namespace Core;

use Exception;

abstract class Model
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    protected $db;
    
    /**
     * @var string $table - اسم الجدول
     */
    protected $table;
    
    /**
     * @var string $primaryKey - المفتاح الأساسي
     */
    protected $primaryKey = 'id';
    
    /**
     * @var array $fillable - الحقول القابلة للتعبئة
     */
    protected $fillable = [];
    
    /**
     * @var array $guarded - الحقول المحمية
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];
    
    /**
     * @var array $casts - تحويل أنواع البيانات
     */
    protected $casts = [];
    
    /**
     * @var array $dates - حقول التاريخ
     */
    protected $dates = ['created_at', 'updated_at'];
    
    /**
     * @var array $softDelete - الحذف الناعم
     */
    protected $softDelete = true;
    
    /**
     * @var string $deletedAtColumn - عمود الحذف الناعم
     */
    protected $deletedAtColumn = 'deleted_at';
    
    /**
     * @var array $attributes - سمات النموذج
     */
    protected $attributes = [];
    
    /**
     * @var array $original - السمات الأصلية
     */
    protected $original = [];
    
    /**
     * @var array $relations - العلاقات المحملة
     */
    protected $relations = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * الحصول على جميع السجلات
     */
    public function all(array $columns = ['*']): array
    {
        $sql = $this->buildSelectQuery($columns);
        return $this->db->query($sql);
    }

    /**
     * البحث عن سجل بالمعرف
     */
    public function find(int $id, array $columns = ['*']): ?array
    {
        $sql = $this->buildSelectQuery($columns);
        $sql .= " WHERE {$this->primaryKey} = :id";
        
        if ($this->softDelete) {
            $sql .= " AND {$this->deletedAtColumn} IS NULL";
        }
        
        return $this->db->queryOne($sql, ['id' => $id]);
    }

    /**
     * البحث عن سجل حسب الشرط
     */
    public function where(array $conditions, array $columns = ['*']): array
    {
        $sql = $this->buildSelectQuery($columns);
        $sql .= " WHERE " . $this->buildWhereClause($conditions);
        
        if ($this->softDelete) {
            $sql .= " AND {$this->deletedAtColumn} IS NULL";
        }
        
        return $this->db->query($sql, $this->extractParams($conditions));
    }

    /**
     * البحث عن سجل واحد حسب الشرط
     */
    public function whereOne(array $conditions, array $columns = ['*']): ?array
    {
        $results = $this->where($conditions, $columns);
        return $results[0] ?? null;
    }

    /**
     * إنشاء سجل جديد
     */
    public function create(array $data): int
    {
        // تصفية الحقول
        $data = $this->filterFillable($data);
        
        // إضافة الطوابع الزمنية
        if (in_array('created_at', $this->dates)) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $this->dates)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        
        return $this->db->insert($this->table, $data);
    }

    /**
     * تحديث سجل
     */
    public function update(int $id, array $data): int
    {
        // تصفية الحقول
        $data = $this->filterFillable($data);
        
        // تحديث الطابع الزمني
        if (in_array('updated_at', $this->dates)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        
        return $this->db->update($this->table, $data, [$this->primaryKey => $id]);
    }

    /**
     * حذف سجل (حذف ناعم)
     */
    public function delete(int $id): int
    {
        if ($this->softDelete) {
            return $this->db->softDelete($this->table, [$this->primaryKey => $id]);
        }
        
        return $this->db->delete($this->table, [$this->primaryKey => $id]);
    }

    /**
     * استعادة سجل محذوف
     */
    public function restore(int $id): int
    {
        if (!$this->softDelete) {
            throw new Exception('Soft delete is not enabled for this model');
        }
        
        return $this->db->update(
            $this->table,
            [$this->deletedAtColumn => null],
            [$this->primaryKey => $id]
        );
    }

    /**
     * حذف دائم
     */
    public function forceDelete(int $id): int
    {
        return $this->db->delete($this->table, [$this->primaryKey => $id]);
    }

    /**
     * الحصول على السجلات المحذوفة
     */
    public function trashed(): array
    {
        if (!$this->softDelete) {
            throw new Exception('Soft delete is not enabled for this model');
        }
        
        $sql = "SELECT * FROM {$this->table} WHERE {$this->deletedAtColumn} IS NOT NULL";
        return $this->db->query($sql);
    }

    /**
     * بدء المعاملة
     */
    public function beginTransaction(): bool
    {
        return $this->db->beginTransaction();
    }

    /**
     * تأكيد المعاملة
     */
    public function commit(): bool
    {
        return $this->db->commit();
    }

    /**
     * التراجع عن المعاملة
     */
    public function rollback(): bool
    {
        return $this->db->rollback();
    }

    /**
     * بناء استعلام SELECT
     */
    private function buildSelectQuery(array $columns): string
    {
        $columnsStr = implode(', ', $columns);
        return "SELECT {$columnsStr} FROM {$this->table}";
    }

    /**
     * بناء جملة WHERE
     */
    private function buildWhereClause(array $conditions): string
    {
        $parts = [];
        foreach ($conditions as $key => $value) {
            if (is_array($value) && count($value) === 2) {
                $operator = $value[0];
                $field = $key;
                $val = $value[1];
                $parts[] = "{$field} {$operator} :{$field}";
            } else {
                $parts[] = "{$key} = :{$key}";
            }
        }
        return implode(' AND ', $parts);
    }

    /**
     * استخراج المعلمات من الشروط
     */
    private function extractParams(array $conditions): array
    {
        $params = [];
        foreach ($conditions as $key => $value) {
            if (is_array($value) && count($value) === 2) {
                $params[$key] = $value[1];
            } else {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    /**
     * تصفية الحقول القابلة للتعبئة
     */
    private function filterFillable(array $data): array
    {
        if (!empty($this->fillable)) {
            return array_intersect_key($data, array_flip($this->fillable));
        }
        
        if (!empty($this->guarded)) {
            return array_diff_key($data, array_flip($this->guarded));
        }
        
        return $data;
    }

    /**
     * تعيين سمات النموذج
     */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;
        $this->original = $attributes;
        return $this;
    }

    /**
     * الحصول على سمة
     */
    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * تعيين سمة
     */
    public function setAttribute(string $key, $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * حفظ التغييرات
     */
    public function save(): int
    {
        if (isset($this->attributes[$this->primaryKey])) {
            $id = $this->attributes[$this->primaryKey];
            return $this->update($id, $this->attributes);
        }
        
        return $this->create($this->attributes);
    }

    /**
     * تحويل البيانات
     */
    protected function cast(array $data): array
    {
        foreach ($this->casts as $field => $type) {
            if (isset($data[$field])) {
                switch ($type) {
                    case 'int':
                    case 'integer':
                        $data[$field] = (int)$data[$field];
                        break;
                    case 'float':
                    case 'double':
                        $data[$field] = (float)$data[$field];
                        break;
                    case 'bool':
                    case 'boolean':
                        $data[$field] = (bool)$data[$field];
                        break;
                    case 'array':
                    case 'json':
                        $data[$field] = json_decode($data[$field], true);
                        break;
                    case 'date':
                        $data[$field] = date('Y-m-d', strtotime($data[$field]));
                        break;
                    case 'datetime':
                        $data[$field] = date('Y-m-d H:i:s', strtotime($data[$field]));
                        break;
                }
            }
        }
        
        return $data;
    }

    /**
     * الحصول على إحصائيات الجدول
     */
    public function stats(): array
    {
        $stats = [];
        
        // إجمالي السجلات
        $result = $this->db->queryOne("SELECT COUNT(*) as total FROM {$this->table}");
        $stats['total'] = (int)($result['total'] ?? 0);
        
        // السجلات النشطة
        if ($this->softDelete) {
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as active FROM {$this->table} 
                 WHERE {$this->deletedAtColumn} IS NULL"
            );
            $stats['active'] = (int)($result['active'] ?? 0);
            
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as trashed FROM {$this->table} 
                 WHERE {$this->deletedAtColumn} IS NOT NULL"
            );
            $stats['trashed'] = (int)($result['trashed'] ?? 0);
        }
        
        return $stats;
    }
}

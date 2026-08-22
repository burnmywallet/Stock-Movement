<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم v5.0
// الملف: backend/core/Model.php
// الوصف: النموذج الأساسي - ORM خفيف مع دعم الحذف الناعم والعلاقات
// التاريخ: 2026-08-22
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
    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];
    
    /**
     * @var array $casts - تحويل أنواع البيانات
     */
    protected $casts = [];
    
    /**
     * @var array $dates - حقول التاريخ
     */
    protected $dates = ['created_at', 'updated_at'];
    
    /**
     * @var bool $softDelete - تفعيل الحذف الناعم
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
    
    /**
     * @var bool $exists - هل النموذج موجود في قاعدة البيانات
     */
    protected $exists = false;

    /**
     * Constructor
     */
    public function __construct(array $attributes = [])
    {
        $this->db = Database::getInstance();
        $this->fill($attributes);
    }

    /**
     * تعبئة النموذج بالبيانات
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    /**
     * تعيين سمة
     */
    public function setAttribute(string $key, $value): self
    {
        // التحقق من الحقول المحمية
        if (in_array($key, $this->guarded)) {
            return $this;
        }
        
        // التحقق من الحقول القابلة للتعبئة
        if (!empty($this->fillable) && !in_array($key, $this->fillable)) {
            return $this;
        }
        
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * الحصول على سمة
     */
    public function getAttribute(string $key)
    {
        // التحقق من السمات
        if (isset($this->attributes[$key])) {
            return $this->castAttribute($key, $this->attributes[$key]);
        }
        
        // التحقق من العلاقات
        if (isset($this->relations[$key])) {
            return $this->relations[$key];
        }
        
        return null;
    }

    /**
     * تحويل السمة حسب النوع
     */
    protected function castAttribute(string $key, $value)
    {
        if (!isset($this->casts[$key])) {
            return $value;
        }
        
        switch ($this->casts[$key]) {
            case 'int':
            case 'integer':
                return (int)$value;
            case 'float':
            case 'double':
                return (float)$value;
            case 'bool':
            case 'boolean':
                return (bool)$value;
            case 'string':
                return (string)$value;
            case 'array':
            case 'json':
                return json_decode($value, true) ?? [];
            case 'date':
                return date('Y-m-d', strtotime($value));
            case 'datetime':
                return date('Y-m-d H:i:s', strtotime($value));
            case 'timestamp':
                return strtotime($value);
            default:
                return $value;
        }
    }

    /**
     * الحصول على جميع السجلات
     */
    public function all(array $columns = ['*']): array
    {
        $sql = $this->buildSelectQuery($columns);
        $result = $this->db->query($sql);
        return $this->hydrate($result);
    }

    /**
     * البحث عن سجل بالمعرف
     */
    public function find(int $id, array $columns = ['*']): ?self
    {
        $sql = $this->buildSelectQuery($columns);
        $sql .= " WHERE {$this->primaryKey} = :id";
        
        if ($this->softDelete) {
            $sql .= " AND {$this->deletedAtColumn} IS NULL";
        }
        
        $result = $this->db->queryOne($sql, ['id' => $id]);
        
        if ($result) {
            return $this->newInstance($result)->setExists(true);
        }
        
        return null;
    }

    /**
     * البحث عن سجلات حسب الشرط
     */
    public function where(array $conditions, array $columns = ['*']): array
    {
        $sql = $this->buildSelectQuery($columns);
        $sql .= " WHERE " . $this->buildWhereClause($conditions);
        
        if ($this->softDelete) {
            $sql .= " AND {$this->deletedAtColumn} IS NULL";
        }
        
        $result = $this->db->query($sql, $this->extractParams($conditions));
        return $this->hydrate($result);
    }

    /**
     * البحث عن سجل واحد حسب الشرط
     */
    public function whereOne(array $conditions, array $columns = ['*']): ?self
    {
        $results = $this->where($conditions, $columns);
        return $results[0] ?? null;
    }

    /**
     * البحث مع ترتيب
     */
    public function orderBy(string $column, string $direction = 'ASC', array $columns = ['*']): array
    {
        $sql = $this->buildSelectQuery($columns);
        $sql .= " ORDER BY {$column} {$direction}";
        
        if ($this->softDelete) {
            $sql .= " AND {$this->deletedAtColumn} IS NULL";
        }
        
        $result = $this->db->query($sql);
        return $this->hydrate($result);
    }

    /**
     * البحث مع تحديد عدد
     */
    public function limit(int $limit, int $offset = 0, array $columns = ['*']): array
    {
        $sql = $this->buildSelectQuery($columns);
        $sql .= " LIMIT :limit OFFSET :offset";
        
        if ($this->softDelete) {
            $sql .= " AND {$this->deletedAtColumn} IS NULL";
        }
        
        $result = $this->db->query($sql, ['limit' => $limit, 'offset' => $offset]);
        return $this->hydrate($result);
    }

    /**
     * البحث مع فلترة وبحث متقدم
     */
    public function paginate(int $page = 1, int $perPage = 20, array $conditions = [], array $columns = ['*']): array
    {
        $offset = ($page - 1) * $perPage;
        
        $sql = $this->buildSelectQuery($columns);
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . $this->buildWhereClause($conditions);
        }
        
        if ($this->softDelete) {
            $sql .= " AND {$this->deletedAtColumn} IS NULL";
        }
        
        $sql .= " LIMIT :limit OFFSET :offset";
        
        $params = $this->extractParams($conditions);
        $params['limit'] = $perPage;
        $params['offset'] = $offset;
        
        $result = $this->db->query($sql, $params);
        $data = $this->hydrate($result);
        
        // إجمالي السجلات
        $total = $this->count($conditions);
        
        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $total)
            ]
        ];
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
     * إنشاء سجل جديد وإرجاع النموذج
     */
    public function createAndGet(array $data): ?self
    {
        $id = $this->create($data);
        return $this->find($id);
    }

    /**
     * تحديث السجل الحالي
     */
    public function update(array $data): int
    {
        if (!$this->exists) {
            throw new Exception('Cannot update non-existing record');
        }
        
        $id = $this->attributes[$this->primaryKey] ?? null;
        if (!$id) {
            throw new Exception('Primary key not found');
        }
        
        // تصفية الحقول
        $data = $this->filterFillable($data);
        
        // تحديث الطابع الزمني
        if (in_array('updated_at', $this->dates)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        
        return $this->db->update($this->table, $data, [$this->primaryKey => $id]);
    }

    /**
     * تحديث سجل بالمعرف
     */
    public function updateById(int $id, array $data): int
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
     * حفظ التغييرات
     */
    public function save(): int
    {
        if ($this->exists) {
            $id = $this->attributes[$this->primaryKey] ?? null;
            if ($id) {
                return $this->update($this->attributes);
            }
        }
        
        return $this->create($this->attributes);
    }

    /**
     * حذف السجل الحالي (حذف ناعم)
     */
    public function delete(): int
    {
        if (!$this->exists) {
            throw new Exception('Cannot delete non-existing record');
        }
        
        $id = $this->attributes[$this->primaryKey] ?? null;
        if (!$id) {
            throw new Exception('Primary key not found');
        }
        
        return $this->deleteById($id);
    }

    /**
     * حذف سجل بالمعرف (حذف ناعم)
     */
    public function deleteById(int $id): int
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
        $result = $this->db->query($sql);
        return $this->hydrate($result);
    }

    /**
     * عدد السجلات
     */
    public function count(array $conditions = []): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->table}";
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . $this->buildWhereClause($conditions);
        }
        
        if ($this->softDelete) {
            $sql .= " AND {$this->deletedAtColumn} IS NULL";
        }
        
        return (int)$this->db->queryValue($sql, $this->extractParams($conditions));
    }

    /**
     * التحقق من وجود سجل
     */
    public function exists(array $conditions): bool
    {
        return $this->count($conditions) > 0;
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
    protected function buildSelectQuery(array $columns): string
    {
        $columnsStr = implode(', ', $columns);
        return "SELECT {$columnsStr} FROM {$this->table}";
    }

    /**
     * بناء جملة WHERE
     */
    protected function buildWhereClause(array $conditions): string
    {
        $parts = [];
        foreach ($conditions as $key => $value) {
            if (is_array($value) && count($value) === 3) {
                // [column, operator, value]
                $operator = $value[1];
                $field = $value[0];
                $val = $value[2];
                $parts[] = "{$field} {$operator} :{$field}";
            } elseif (is_array($value) && count($value) === 2) {
                // [operator, value]
                $operator = $value[0];
                $field = $key;
                $val = $value[1];
                $parts[] = "{$field} {$operator} :{$field}";
            } else {
                // key = value
                $parts[] = "{$key} = :{$key}";
            }
        }
        return implode(' AND ', $parts);
    }

    /**
     * استخراج المعلمات من الشروط
     */
    protected function extractParams(array $conditions): array
    {
        $params = [];
        foreach ($conditions as $key => $value) {
            if (is_array($value)) {
                // [column, operator, value] أو [operator, value]
                if (count($value) === 3) {
                    $params[$value[0]] = $value[2];
                } elseif (count($value) === 2) {
                    $params[$key] = $value[1];
                } else {
                    $params[$key] = $value;
                }
            } else {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    /**
     * تصفية الحقول القابلة للتعبئة
     */
    protected function filterFillable(array $data): array
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
     * إنشاء مثيل جديد من النموذج
     */
    protected function newInstance(array $attributes = []): self
    {
        $model = new static($attributes);
        $model->setExists(true);
        return $model;
    }

    /**
     * تحويل النتائج إلى كائنات نموذج
     */
    protected function hydrate(array $results): array
    {
        $models = [];
        foreach ($results as $result) {
            $models[] = $this->newInstance($result);
        }
        return $models;
    }

    /**
     * تعيين حالة الوجود
     */
    public function setExists(bool $exists): self
    {
        $this->exists = $exists;
        return $this;
    }

    /**
     * التحقق من وجود النموذج
     */
    public function isExists(): bool
    {
        return $this->exists;
    }

    /**
     * الحصول على السمات كمصفوفة
     */
    public function toArray(): array
    {
        return array_merge($this->attributes, $this->relations);
    }

    /**
     * الحصول على السمات كـ JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * تحميل العلاقة
     */
    public function load(string $relation, callable $callback = null): self
    {
        if ($callback) {
            $callback($this);
        }
        return $this;
    }

    /**
     * تعريف علاقة belongsTo
     */
    public function belongsTo(string $related, string $foreignKey = null, string $localKey = 'id'): ?self
    {
        $model = new $related();
        $foreignKey = $foreignKey ?? strtolower($model->getTable()) . '_id';
        $localValue = $this->getAttribute($foreignKey);
        
        if ($localValue) {
            return $model->whereOne([$localKey => $localValue]);
        }
        
        return null;
    }

    /**
     * تعريف علاقة hasMany
     */
    public function hasMany(string $related, string $foreignKey = null, string $localKey = 'id'): array
    {
        $model = new $related();
        $foreignKey = $foreignKey ?? strtolower($this->getTable()) . '_id';
        $localValue = $this->getAttribute($localKey);
        
        if ($localValue) {
            return $model->where([$foreignKey => $localValue]);
        }
        
        return [];
    }

    /**
     * تعريف علاقة hasOne
     */
    public function hasOne(string $related, string $foreignKey = null, string $localKey = 'id'): ?self
    {
        $model = new $related();
        $foreignKey = $foreignKey ?? strtolower($this->getTable()) . '_id';
        $localValue = $this->getAttribute($localKey);
        
        if ($localValue) {
            return $model->whereOne([$foreignKey => $localValue]);
        }
        
        return null;
    }

    /**
     * الحصول على اسم الجدول
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * الحصول على المفتاح الأساسي
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * الحصول على الحقول القابلة للتعبئة
     */
    public function getFillable(): array
    {
        return $this->fillable;
    }

    /**
     * الحصول على الحقول المحمية
     */
    public function getGuarded(): array
    {
        return $this->guarded;
    }

    /**
     * إحصائيات الجدول
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
                "SELECT COUNT(*) as active FROM {$this->table} WHERE {$this->deletedAtColumn} IS NULL"
            );
            $stats['active'] = (int)($result['active'] ?? 0);
            
            $result = $this->db->queryOne(
                "SELECT COUNT(*) as trashed FROM {$this->table} WHERE {$this->deletedAtColumn} IS NOT NULL"
            );
            $stats['trashed'] = (int)($result['trashed'] ?? 0);
        }
        
        return $stats;
    }

    /**
     * تنفيذ استعلام مخصص
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->db->query($sql, $params);
    }

    /**
     * تنفيذ استعلام مخصص وإرجاع سجل واحد
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->db->queryOne($sql, $params);
    }

    /**
     * تنفيذ استعلام مخصص وإرجاع قيمة واحدة
     */
    public function queryValue(string $sql, array $params = [])
    {
        return $this->db->queryValue($sql, $params);
    }

    /**
     * تنفيذ استعلام (INSERT, UPDATE, DELETE)
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->db->execute($sql, $params);
    }

    /**
     * الحصول على قيمة سمة (Magic Method)
     */
    public function __get(string $key)
    {
        return $this->getAttribute($key);
    }

    /**
     * تعيين قيمة سمة (Magic Method)
     */
    public function __set(string $key, $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * التحقق من وجود سمة (Magic Method)
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) || isset($this->relations[$key]);
    }

    /**
     * حذف سمة (Magic Method)
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
        unset($this->relations[$key]);
    }

    /**
     * تحويل النموذج إلى نص (Magic Method)
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}

// ================================================================
// انتهى الملف
// ================================================================

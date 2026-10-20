<?php
/**
 * النموذج الأساسي المحسن للنظام
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.0.0
 * @author نظام إدارة المتدربين
 * @copyright 2024 جميع الحقوق محفوظة
 */


class BaseModel {
    protected $conn;
    protected $table;
    protected $primaryKey = 'id';
    protected $allowedFields = [];
    protected $timestamps = true;
    protected $softDelete = false;

    public function __construct($db, $table, $primaryKey = 'id', array $allowedFields = []) {
        $this->conn = $db;
        $this->table = $table;
        $this->primaryKey = $primaryKey;
        $this->allowedFields = $allowedFields;
    }

    public function findById($id) {
        try {
            // تسجيل بيانات البحث للتصحيح
            error_log("Searching in table {$this->table} for ID: " . print_r($id, true));
            
            $sql = "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id";

            // Add soft-delete guard only if enabled and column exists
            $addSoftDelete = false;
            if ($this->softDelete) {
                if (is_object($this->conn) && method_exists($this->conn, 'hasColumn')) {
                    $addSoftDelete = $this->conn->hasColumn($this->table, 'deleted_at');
                } else {
                    // fallback: try to assume column exists (legacy behavior)
                    $addSoftDelete = true;
                }
            }

            if ($addSoftDelete) {
                $sql .= " AND deleted_at IS NULL";
            }

            $sql .= " LIMIT 1";
            
            // تسجيل الاستعلام للتصحيح
            error_log("SQL Query: " . $sql);
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // تسجيل النتيجة للتصحيح
            error_log("Search result for ID {$id}: " . print_r($result, true));
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error in findById: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Backwards compatible alias for findById used by older code (getById)
     *
     * @param mixed $id
     * @return array|false
     */
    public function getById($id) {
        return $this->findById($id);
    }

    public function list(array $filters = [], $orderBy = null, $limit = null, $offset = null) {
        try {
            $where = [];
            $params = [];
            
            foreach ($filters as $column => $value) {
                if (in_array($column, $this->allowedFields, true) || $column === $this->primaryKey) {
                    $where[] = "`$column` = :$column";
                    $params[":$column"] = $value;
                }
            }
            
            // إضافة شرط الحذف الناعم فقط إذا كان مفعلاً والعمود موجود
            if ($this->softDelete) {
                $addSoft = false;
                if (is_object($this->conn) && method_exists($this->conn, 'hasColumn')) {
                    $addSoft = $this->conn->hasColumn($this->table, 'deleted_at');
                } else {
                    $addSoft = true;
                }
                if ($addSoft) {
                    $where[] = "deleted_at IS NULL";
                }
            }
            
            $sql = "SELECT * FROM `{$this->table}`" . (count($where) ? " WHERE " . implode(' AND ', $where) : '');
            
            if ($orderBy) {
                $sql .= " ORDER BY $orderBy";
            }
            
            if ($limit !== null) {
                $sql .= " LIMIT " . (int)$limit;
                if ($offset !== null) {
                    $sql .= " OFFSET " . (int)$offset;
                }
            }
            
            $stmt = $this->conn->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error in list: " . $e->getMessage());
            return [];
        }
    }

    public function create(array $data) {
        $filtered = $this->filterAllowed($data);
        if (empty($filtered)) {
            throw new InvalidArgumentException('No valid fields to insert');
        }
        $columns = array_keys($filtered);
        $placeholders = array_map(function ($c) { return ":$c"; }, $columns);
        $sql = "INSERT INTO `{$this->table}` (" . implode(',', array_map(function ($c) { return "`$c`"; }, $columns)) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $this->conn->prepare($sql);
        foreach ($filtered as $col => $val) {
            $stmt->bindValue(":" . $col, $val);
        }
        try {
            $stmt->execute();
            return $this->conn->lastInsertId();
        } catch (PDOException $e) {
            // Log SQL and params to help diagnose parameter mismatches
            $msg = "Error in create: " . $e->getMessage() . "\n";
            $msg .= "SQL: " . $sql . "\n";
            $msg .= "Params: " . print_r($filtered, true) . "\n";
            error_log($msg);
            throw $e;
        }
    }

    public function update($id, array $data) {
        $filtered = $this->filterAllowed($data);
        if (empty($filtered)) {
            throw new InvalidArgumentException('No valid fields to update');
        }
        $sets = [];
        foreach (array_keys($filtered) as $col) {
            $sets[] = "`$col` = :$col";
        }
        $sql = "UPDATE `{$this->table}` SET " . implode(',', $sets) . " WHERE `{$this->primaryKey}` = :id";
        $stmt = $this->conn->prepare($sql);
        foreach ($filtered as $col => $val) {
            $stmt->bindValue(":" . $col, $val);
        }
        $stmt->bindValue(':id', $id);
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            $msg = "Error in update: " . $e->getMessage() . "\n";
            $msg .= "SQL: " . $sql . "\n";
            $msg .= "Params: " . print_r(array_merge($filtered, ['id' => $id]), true) . "\n";
            error_log($msg);
            throw $e;
        }
    }

    public function delete($id) {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    protected function filterAllowed(array $data) {
        if (empty($this->allowedFields)) {
            return $data;
        }
        $filtered = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $this->allowedFields, true)) {
                $filtered[$k] = $v;
            }
        }
        return $filtered;
    }

    public function count(array $filters = []) {
        try {
            $where = [];
            $params = [];
            
            foreach ($filters as $column => $value) {
                if (in_array($column, $this->allowedFields, true) || $column === $this->primaryKey) {
                    $where[] = "`$column` = :$column";
                    $params[":$column"] = $value;
                }
            }
            
            // إضافة شرط الحذف الناعم فقط إذا كان مفعلاً والعمود موجود
            if ($this->softDelete) {
                $addSoft = false;
                if (is_object($this->conn) && method_exists($this->conn, 'hasColumn')) {
                    $addSoft = $this->conn->hasColumn($this->table, 'deleted_at');
                } else {
                    $addSoft = true;
                }
                if ($addSoft) {
                    $where[] = "deleted_at IS NULL";
                }
            }
            
            $sql = "SELECT COUNT(*) as total FROM `{$this->table}`" . (count($where) ? " WHERE " . implode(' AND ', $where) : '');
            
            $stmt = $this->conn->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['total'];
        } catch (PDOException $e) {
            error_log("Error in count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Retrieve ENUM values for a given column on this model's table.
     * Returns an array of allowed string values or an empty array on error.
     *
     * @param string $column
     * @return array
     */
    public function getEnumValues(string $column): array {
        try {
            // Use SHOW COLUMNS ... LIKE to get the column type which contains the ENUM definition
            $sql = "SHOW COLUMNS FROM `{$this->table}` LIKE :column";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute(['column' => $column]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['Type'])) {
                return [];
            }
            $type = $row['Type']; // e.g. "enum('paid','delayed','partial')"
            if (preg_match_all("/\\'([^\\']+)\\'/", $type, $matches)) {
                return $matches[1];
            }
            return [];
        } catch (PDOException $e) {
            error_log("Error getting enum values for {$this->table}.{$column}: " . $e->getMessage());
            return [];
        }
    }
}
?>






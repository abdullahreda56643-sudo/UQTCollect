<?php

// NormalizedPDOStatement: ensure parameter arrays passed to execute() work whether keys
// include a leading ':' or not. Declared in global scope and guarded with class_exists
// to avoid redeclaration when the file is included multiple times.
if (!class_exists('NormalizedPDOStatement')) {
    class NormalizedPDOStatement extends PDOStatement {
        protected function __construct() {
            // required for PDO to be able to construct this class
        }
    #[\ReturnTypeWillChange]
    public function execute($input_parameters = null) {
            $normalized = $input_parameters;
            if (is_array($input_parameters)) {
                $normalized = [];
                foreach ($input_parameters as $k => $v) {
                    // If key is integer (positional), keep as-is
                    if (is_int($k)) {
                        $normalized[$k] = $v;
                        continue;
                    }
                    // strip leading colon(s) if present
                    $nk = ltrim((string)$k, ':');
                    $normalized[$nk] = $v;
                }
            }

            try {
                return parent::execute($normalized);
            } catch (PDOException $e) {
                // Log SQL and normalized params to help diagnose HY093 and other issues
                $message = "PDOStatement execute failed: " . $e->getMessage() . "\n";
                $message .= "SQL: " . ($this->queryString ?? '[unknown]') . "\n";
                $message .= "Params: " . print_r($normalized, true) . "\n";
                // Use error_log; file path uses LOG_PATH if defined, fallback to default
                if (defined('LOG_PATH')) {
                    error_log($message, 3, LOG_PATH . "sql_errors.log");
                } else {
                    error_log($message);
                }
                throw $e; // rethrow after logging
            }
        }
    }
}

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $charset = DB_CHARSET;
    public $conn;
    // simple cache for schema checks
    private $columnExistsCache = [];
    
    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            // Tell PDO to use our global NormalizedPDOStatement class for prepared statements
            $this->conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('NormalizedPDOStatement', array()));
            
            // إعدادات إضافية للأداء
            $this->conn->exec("SET time_zone = '+03:00'");
            $this->conn->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage(), 3, LOG_PATH . "error.log");
            throw new Exception("فشل الاتصال بقاعدة البيانات. يرجى المحاولة لاحقاً.");
        }
        return $this->conn;
    }

    /**
     * Check if a column exists on a given table. Results are cached per-request.
     * Returns boolean.
     */
    public function hasColumn(string $table, string $column): bool {
        $key = "$table.$column";
        if (array_key_exists($key, $this->columnExistsCache)) {
            return $this->columnExistsCache[$key];
        }

        try {
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
            $stmt->execute([':col' => $column]);
            $exists = $stmt->rowCount() > 0;
            $this->columnExistsCache[$key] = $exists;
            return $exists;
        } catch (PDOException $e) {
            // If the table doesn't exist or permission issue, assume column missing
            error_log("hasColumn check failed for $table.$column: " . $e->getMessage());
            $this->columnExistsCache[$key] = false;
            return false;
        }
    }

    /**
     * Quote an identifier (very small helper) to avoid syntax issues in dynamic SQL.
     */
    public function quoteIdentifier(string $identifier): string {
        return "`" . str_replace("`", "``", $identifier) . "`";
    }
    
    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }
    
    public function commit() {
        return $this->conn->commit();
    }
    
    public function rollBack() {
        return $this->conn->rollBack();
    }

    /**
     * Wrapper to check whether a transaction is currently active.
     * Some callers use this to avoid calling rollBack when no transaction exists.
     */
    public function inTransaction() {
        try {
            return $this->conn->inTransaction();
        } catch (Exception $e) {
            // If underlying driver doesn't support it for any reason, assume false
            return false;
        }
    }
    
    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }
}
?>
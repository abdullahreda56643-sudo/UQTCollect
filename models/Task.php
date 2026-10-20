<?php
class Task extends BaseModel {
    public function __construct($db) {
        parent::__construct($db, 'tasks', 'id', [
            'title','description','due_date','priority','status','assigned_to','related_trainee','created_by','completed_at'
        ]);
    }


public function getUserTasksByDate($user_id, $date) {
    $sql = "SELECT * FROM tasks 
            WHERE assigned_to = ? 
            AND DATE(due_date) = ? 
            AND status != 'completed' 
            AND status != 'cancelled'
            ORDER BY due_date ASC";
    
    $stmt = $this->conn->prepare($sql);
    $stmt->execute([$user_id, $date]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


    public function create($data) {
        try {
            // التحقق من وجود المتدرب إذا تم تحديده
            if (!empty($data['related_trainee'])) {
                $stmt = $this->conn->prepare("SELECT id FROM trainees WHERE id = ?");
                $stmt->execute([$data['related_trainee']]);
                if (!$stmt->fetch()) {
                    throw new Exception('المتدرب المحدد غير موجود في النظام');
                }
            } else {
                // إذا لم يتم تحديد متدرب، نجعل القيمة NULL
                $data['related_trainee'] = null;
            }

            // تنظيف البيانات
            $fields = [];
            $values = [];
            $params = [];

            foreach ($this->allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = $field;
                    $values[] = ":$field";
                    $params[$field] = $data[$field];
                }
            }

            $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $values) . ")";

            $stmt = $this->conn->prepare($sql);
            foreach ($params as $field => $value) {
                $stmt->bindValue(":$field", $value, 
                    $field === 'related_trainee' && $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR
                );
            }

            $stmt->execute();
            return $this->conn->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error creating task: " . $e->getMessage());
            throw new Exception('حدث خطأ أثناء إنشاء المهمة');
        }
    }
}
?>






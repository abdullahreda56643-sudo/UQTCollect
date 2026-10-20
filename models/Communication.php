<?php
require_once 'BaseModel.php'; // تأكد من وجود هذا الملف

class Communication extends BaseModel {
    protected $db;
    
    public function __construct($db) {
        parent::__construct($db, 'communications', 'id', [
            'trainee_id', 'type', 'contact_date', 'result', 'notes', 
            'next_action_date', 'next_action_notes', 'created_by'
        ]);
        $this->db = $db;
        $this->timestamps = true;
        $this->softDelete = false;
    }

    /**
     * إضافة تواصل جديد مع تحديث آخر اتصال للمتدرب
     */
    public function create($data) {
        try {
            $this->db->beginTransaction();

            // إضافة سجل التواصل
            $sql = "INSERT INTO communications (trainee_id, type, contact_date, result, notes, next_action_date, next_action_notes, created_by)
                    VALUES (:trainee_id, :type, :contact_date, :result, :notes, :next_action_date, :next_action_notes, :created_by)";
            
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                ':trainee_id' => $data['trainee_id'],
                ':type' => $data['type'],
                ':contact_date' => $data['contact_date'],
                ':result' => $data['result'],
                ':notes' => $data['notes'] ?? null,
                ':next_action_date' => $data['next_action_date'] ?? null,
                ':next_action_notes' => $data['next_action_notes'] ?? null,
                ':created_by' => $data['created_by']
            ]);

            if ($success) {
                // تحديث آخر اتصال في جدول المتدربين
                $updateSql = "UPDATE trainees 
                             SET last_contact = :contact_date,
                                 next_action = :next_action_date,
                                 next_action_notes = :next_action_notes
                             WHERE id = :trainee_id";
                
                $updateStmt = $this->db->prepare($updateSql);
                $updateStmt->execute([
                    ':contact_date' => $data['contact_date'],
                    ':next_action_date' => $data['next_action_date'] ?? null,
                    ':next_action_notes' => $data['next_action_notes'] ?? null,
                    ':trainee_id' => $data['trainee_id']
                ]);
            }

            $this->db->commit();
            return $success;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error in Communication::create: " . $e->getMessage());
            return false;
        }
    }

    /**
     * التحقق من وجود الجداول المطلوبة
     */
    private function checkTablesExist() {
        // Only require communications table as mandatory. Optional tables (reminders, tasks) are handled gracefully.
        $table = 'communications';
        try {
            $this->db->query("SELECT 1 FROM $table LIMIT 1");
        } catch (PDOException $e) {
            throw new Exception("الجدول $table غير موجود في قاعدة البيانات");
        }
    }

    /**
     * Check whether a table exists in the current database
     */
    private function tableExists($tableName) {
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE '" . $tableName . "'");
            return ($stmt && $stmt->rowCount() > 0);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check whether a column exists on a given table
     */
    private function columnExists($tableName, $columnName) {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM `" . $tableName . "` LIKE '" . $columnName . "'");
            return ($stmt && $stmt->rowCount() > 0);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * الحصول على سجلات التواصل لمتدرب معين مع معلومات إضافية
     */
    public function getTraineeCommunications($traineeId) {
        try {
            $this->checkTablesExist();
            $includeReminders = $this->tableExists('reminders');

            $sql = "SELECT c.*, 
                           u.name as created_by_name,
                           t.name as trainee_name";

            if ($includeReminders) {
                $sql .= ", r.id as reminder_id, r.reminder_date, r.status as reminder_status";
            } else {
                $sql .= ", NULL as reminder_id, NULL as reminder_date, NULL as reminder_status";
            }

            $sql .= " FROM communications c
                    LEFT JOIN users u ON c.created_by = u.id
                    LEFT JOIN trainees t ON c.trainee_id = t.id";

            if ($includeReminders) {
                $sql .= " LEFT JOIN reminders r ON r.communication_id = c.id";
            }

            $sql .= " WHERE c.trainee_id = :trainee_id
                    ORDER BY c.contact_date DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['trainee_id' => $traineeId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error in getTraineeCommunications: " . $e->getMessage());
            return [];
        }
    }

    /**
     * إضافة تواصل جديد مع تذكير (إذا تم تحديد موعد الإجراء التالي)
     */
    public function addCommunication($data) {
        try {
            $this->checkTablesExist();
            $this->db->beginTransaction();

            // التحقق من البيانات المطلوبة
            $required = ['trainee_id', 'type', 'contact_date', 'result'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    throw new Exception("الحقل {$field} مطلوب");
                }
            }

            // Normalize type/result to canonical keys so view labels work reliably
            $normalizedType = $this->normalizeEnumValue('type', $data['type'] ?? null, $data['type'] ?? null);
            // sanitize common variants (Arabic phrases, alternate keys) before enum normalization
            $sanitizedResult = $this->sanitizeResultValue($data['result'] ?? null);
            $normalizedResult = $this->normalizeEnumValue('result', $sanitizedResult, $sanitizedResult);

            // إضافة سجل التواصل
            $sql = "INSERT INTO communications (
                trainee_id, type, contact_date, result, notes, 
                next_action_date, next_action_notes, created_by
            ) VALUES (
                :trainee_id, :type, :contact_date, :result, :notes,
                :next_action_date, :next_action_notes, :created_by
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'trainee_id' => $data['trainee_id'],
                'type' => $normalizedType,
                'contact_date' => $data['contact_date'],
                'result' => $normalizedResult,
                'notes' => $data['notes'] ?? null,
                'next_action_date' => $data['next_action_date'] ?? null,
                'next_action_notes' => $data['next_action_notes'] ?? null,
                'created_by' => $data['created_by']
            ]);

            $communicationId = $this->db->lastInsertId();

            // إذا تم تحديد موعد الإجراء التالي، أضف تذكيراً فقط إذا كان جدول tasks موجودًا
            if (!empty($data['next_action_date'])) {
                if ($this->tableExists('tasks')) {
                    $taskSql = "INSERT INTO tasks (
                        title, description, due_date, assigned_to, related_trainee,
                        priority, status, created_by
                    ) VALUES (
                        :title, :description, :due_date, :assigned_to, :related_trainee,
                        'medium', 'pending', :created_by
                    )";

                    $stmt = $this->db->prepare($taskSql);
                    $stmt->execute([
                        'title' => "متابعة المتدرب - " . $data['result'],
                        'description' => $data['next_action_notes'] ?? 'متابعة نتيجة التواصل السابق',
                        'due_date' => $data['next_action_date'],
                        'assigned_to' => $data['created_by'],
                        'related_trainee' => $data['trainee_id'],
                        'created_by' => $data['created_by']
                    ]);

                    $taskId = $this->db->lastInsertId();

                    // تحديث سجل التواصل مع معرف التذكير
                    $updateSql = "UPDATE communications SET reminder_id = :task_id WHERE id = :comm_id";
                    $stmt = $this->db->prepare($updateSql);
                    $stmt->execute([
                        'task_id' => $taskId,
                        'comm_id' => $communicationId
                    ]);
                } else {
                    // If tasks table doesn't exist, log and continue without creating a reminder
                    error_log('Communication::addCommunication - tasks table not found; skipping reminder creation');
                }
            }

            $this->db->commit();
            return $communicationId;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error in addCommunication: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * إضافة تذكير
     */
    private function addReminder($communicationId, $reminderDate) {
        $sql = "INSERT INTO reminders (communication_id, reminder_date, status) 
                VALUES (:communication_id, :reminder_date, 'pending')";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'communication_id' => $communicationId,
            'reminder_date' => $reminderDate
        ]);
    }

    /**
     * الحصول على التذكيرات القادمة
     */
    public function getUpcomingReminders($userId = null, $limit = 10, $daysAhead = 7) {
        try {
            $this->checkTablesExist();
            
            $sql = "SELECT r.*, 
                           c.trainee_id,
                           c.type as communication_type,
                           c.notes,
                           c.next_action_date,
                           t.name as trainee_name,
                           t.phone as trainee_phone,
                           u.name as created_by_name
                    FROM reminders r
                    JOIN communications c ON r.communication_id = c.id
                    JOIN trainees t ON c.trainee_id = t.id
                    LEFT JOIN users u ON c.created_by = u.id
                    WHERE r.status = 'pending'
                    AND r.reminder_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :days_ahead DAY)";

            $params = ['days_ahead' => $daysAhead];

            if ($userId) {
                $sql .= " AND c.created_by = :user_id";
                $params['user_id'] = $userId;
            }

            $sql .= " ORDER BY r.reminder_date ASC LIMIT :limit";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':days_ahead', $daysAhead, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            if ($userId) {
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            }
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error in getUpcomingReminders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * الحصول على التذكيرات المتأخرة
     */
    public function getOverdueReminders($userId = null) {
        try {
            $this->checkTablesExist();
            
            $sql = "SELECT r.*, 
                           c.trainee_id,
                           c.type as communication_type,
                           c.notes,
                           t.name as trainee_name,
                           t.phone as trainee_phone
                    FROM reminders r
                    JOIN communications c ON r.communication_id = c.id
                    JOIN trainees t ON c.trainee_id = t.id
                    WHERE r.status = 'pending'
                    AND r.reminder_date < NOW()";

            $params = [];
            if ($userId) {
                $sql .= " AND c.created_by = :user_id";
                $params['user_id'] = $userId;
            }

            $sql .= " ORDER BY r.reminder_date ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error in getOverdueReminders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Update with detailed info (returns success, errorInfo and affected rows)
     * This overrides the BaseModel behaviour to allow callers to inspect warnings
     */
    public function updateWithInfo($id, array $data) {
        $filtered = $this->filterAllowed($data);
        if (empty($filtered)) {
            throw new InvalidArgumentException('No valid fields to update');
        }
        $sets = [];
        foreach (array_keys($filtered) as $col) {
            $sets[] = "`$col` = :$col";
        }
        $sql = "UPDATE `{$this->table}` SET " . implode(',', $sets) . " WHERE `{$this->primaryKey}` = :id";
        $stmt = $this->db->prepare($sql);
        foreach ($filtered as $col => $val) {
            $stmt->bindValue(":" . $col, $val);
        }
        $stmt->bindValue(':id', $id);
        $success = $stmt->execute();
        $info = $stmt->errorInfo();
        $affected = $stmt->rowCount();
        return [
            'success' => $success,
            'errorInfo' => $info,
            'affected' => $affected,
            'sql' => $sql,
            'params' => $filtered
        ];
    }

    /**
     * Return allowed ENUM values for a column (empty array if not an enum or on error)
     *
     * @param string $column
     * @return array
     */
    public function getEnumValues(string $column): array {
        try {
            $stmt = $this->db->prepare("SHOW COLUMNS FROM `{$this->table}` LIKE :col");
            $stmt->execute(['col' => $column]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['Type'])) return [];
            $type = $row['Type']; // e.g. enum('call','whatsapp')
            if (stripos($type, 'enum(') !== 0) return [];
            $inside = substr($type, 5, -1);
            $vals = str_getcsv($inside, ',', "'");
            // trim quotes
            $vals = array_map(function($v){ return trim($v, "'"); }, $vals);
            return $vals;
        } catch (Exception $e) {
            error_log('getEnumValues error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Normalize a candidate value into one of the allowed enum values if possible
     */
    public function normalizeEnumValue($column, $value, $fallback = null) {
        $allowed = $this->getEnumValues($column);
        // If column is not an ENUM (e.g. `result` is varchar in some schemas),
        // accept the provided value after basic sanitization instead of
        // returning fallback and silently dropping the filter.
        if (!$allowed) {
            $v = trim((string)$value);
            return ($v === '') ? $fallback : $v;
        }
        // direct match
        if (in_array($value, $allowed, true)) return $value;
        // simple mappings for common UI values
        $map = [
            'type' => [
                'phone' => 'call',
                'call' => 'call',
                'whatsapp' => 'whatsapp',
                'email' => 'call',
                'meeting' => 'call'
            ],
            'result' => [
                'responded' => 'promise',
                'promised_payment' => 'promise',
                'promise' => 'promise',
                'refused' => 'refused',
                'paid' => 'paid',
                'not_responding' => 'no_answer',
                'no_answer' => 'no_answer',
                'other' => 'other'
            ]
        ];
        if (isset($map[$column]) && isset($map[$column][$value])) {
            $mapped = $map[$column][$value];
            if (in_array($mapped, $allowed, true)) return $mapped;
        }
        // try lowercase comparison
        foreach ($allowed as $a) {
            if (strtolower($a) === strtolower($value)) return $a;
        }
        return $fallback;
    }

    /**
     * Sanitize various freeform result strings (including Arabic literals)
     * into canonical keys used by the application (e.g. 'follow_up', 'promise').
     */
    private function sanitizeResultValue($value) {
        if ($value === null) return null;
        $v = trim((string)$value);
        if ($v === '') return null;

        $map = [
            // Arabic phrases
            'طلب الاتصال لاحقا' => 'follow_up',
            'طلب الاتصال لاحقاً' => 'follow_up',
            'اتصل لاحقاً' => 'follow_up',
            'اتصل لاحقا' => 'follow_up',
            'مهتم' => 'promise',
            'غير مهتم' => 'not_interested',
            'وعد' => 'promise',
            'رفض' => 'refused',
            'تم السداد' => 'paid',
            'لم يرد' => 'no_answer',
            'متابعة' => 'follow_up',
            'أخرى' => 'other',        // ← تمت الإضافة
            'اخرى' => 'other',        // ← تمت الإضافة (للهمزة المختلفة)
            'أخرى' => 'other',        // ← تمت الإضافة (للهمزة المختلفة)

            // English/variants
            'call_later' => 'follow_up',
            'contact_later' => 'follow_up',
            'call_later_request' => 'follow_up',
            'interested' => 'promise',
            'not_interested' => 'not_interested',
            'promise' => 'promise',
            'refused' => 'refused',
            'paid' => 'paid',
            'no_answer' => 'no_answer',
            'follow_up' => 'follow_up',
            'other' => 'other'
        ];

        // direct map
        if (isset($map[$v])) return $map[$v];

        // try case-insensitive match
        $lower = mb_strtolower($v);
        foreach ($map as $k => $m) {
            if (mb_strtolower($k) === $lower) return $m;
        }

        // fallback to returning trimmed original (caller may further normalize)
        return $v;
    }

    /**
     * تحديث حالة التذكير
     */
    public function updateReminderStatus($reminderId, $status) {
        try {
            $this->checkTablesExist();
            
            $validStatuses = ['pending', 'completed', 'cancelled'];
            if (!in_array($status, $validStatuses)) {
                throw new Exception("حالة التذكير غير صالحة");
            }

            $sql = "UPDATE reminders SET status = :status, updated_at = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute(['status' => $status, 'id' => $reminderId]);
        } catch (Exception $e) {
            error_log("Error in updateReminderStatus: " . $e->getMessage());
            return false;
        }
    }

    /**
     * البحث في سجلات التواصل
     */
    public function searchCommunications($searchTerm, $filters = []) {
        try {
            $this->checkTablesExist();
            
            // Determine whether communications.reminder_id column and tasks table exist before joining
            $hasReminderId = $this->columnExists('communications', 'reminder_id');
            $hasTasksTable = $this->tableExists('tasks');

        $sql = "SELECT DISTINCT c.*, 
            COALESCE(t.full_name, '') as trainee_name,
            COALESCE(t.national_id, '') as national_id,
            COALESCE(t.assigned_to, 0) as assigned_to,
            COALESCE(t.phone, '') as phone,
            COALESCE(t.email, '') as email,
            COALESCE(t.graduation_date, '') as graduation_date,
            COALESCE(t.graduation_date_hijri, '') as graduation_date_hijri,
            COALESCE(u.full_name, '') as created_by_name,
            COALESCE(u2.full_name, '') as assigned_to_name";

            if ($hasReminderId && $hasTasksTable) {
                $sql .= ", r.id as reminder_id, r.status as reminder_status";
            } else {
                $sql .= ", NULL as reminder_id, NULL as reminder_status";
            }

            $sql .= " FROM communications c
              LEFT JOIN trainees t ON c.trainee_id = t.id
              LEFT JOIN users u ON c.created_by = u.id
              LEFT JOIN users u2 ON t.assigned_to = u2.id";

            if ($hasReminderId && $hasTasksTable) {
                $sql .= "\n              LEFT JOIN tasks r ON c.reminder_id = r.id";
            }

            $sql .= "\n              WHERE 1=1";

            $params = [];

            if (!empty($searchTerm)) {
                // broaden search to include national id, phone, email, creator name and notes
                $sql .= " AND (COALESCE(t.full_name,'') LIKE :search ";
                $sql .= " OR COALESCE(c.notes, '') LIKE :search ";
                $sql .= " OR COALESCE(t.national_id, '') LIKE :search ";
                $sql .= " OR COALESCE(t.phone, '') LIKE :search ";
                $sql .= " OR COALESCE(t.email, '') LIKE :search ";
                $sql .= " OR COALESCE(u.full_name, '') LIKE :search ";
                // if the term is numeric, also allow exact match on communication id
                if (is_numeric($searchTerm)) {
                    $sql .= " OR c.id = :search_exact";
                }
                $sql .= ")";
                $params['search'] = "%{$searchTerm}%";
                if (is_numeric($searchTerm)) {
                    $params['search_exact'] = (int)$searchTerm;
                }
            }

            // فلتر المتدربين المسندين للموظف
            if (!empty($filters['assigned_to'])) {
                if (!empty($filters['trainee_ids']) && is_array($filters['trainee_ids'])) {
                    // إذا تم تحديد متدربين محددين - استخدام معلمات مسماة لتجنّب خلط المعاملات المرقمة والمسمّاة
                    $placeholders = [];
                    foreach ($filters['trainee_ids'] as $i => $id) {
                        $key = 'trainee_' . $i;
                        $placeholders[] = ':' . $key;
                        $params[$key] = (int)$id;
                    }
                    if (!empty($placeholders)) {
                        $sql .= " AND c.trainee_id IN (" . implode(',', $placeholders) . ")";
                    }
                    // تأكد أيضاً من فلترة المالك في حال طلب ذلك (من جدول trainees)
                    $sql .= " AND COALESCE(t.assigned_to, 0) = :assigned_to";
                    $params['assigned_to'] = (int)$filters['assigned_to'];
                } else {
                    // عرض جميع المتدربين المسندين للموظف
                    $sql .= " AND COALESCE(t.assigned_to, 0) = :assigned_to";
                    $params['assigned_to'] = (int)$filters['assigned_to'];
                }
            }

            // تطبيق الفلاتر الإضافية
            if (!empty($filters['trainee_id'])) {
                $sql .= " AND c.trainee_id = :trainee_id";
                $params['trainee_id'] = $filters['trainee_id'];
            }

            if (!empty($filters['type'])) {
                $sql .= " AND c.type = :type";
                $params['type'] = $filters['type'];
            }

            if (!empty($filters['result'])) {
                $sql .= " AND c.result = :result";
                $params['result'] = $filters['result'];
            }

            if (!empty($filters['start_date'])) {
                $sql .= " AND DATE(c.contact_date) >= :start_date";
                $params['start_date'] = $filters['start_date'];
            }

            if (!empty($filters['end_date'])) {
                $sql .= " AND DATE(c.contact_date) <= :end_date";
                $params['end_date'] = $filters['end_date'];
            }

            if (!empty($filters['contact_date'])) {
                $sql .= " AND DATE(c.contact_date) = :contact_date";
                $params['contact_date'] = $filters['contact_date'];
            }

            if (!empty($filters['graduation_date'])) {
                $sql .= " AND DATE(t.graduation_date) = :graduation_date";
                $params['graduation_date'] = $filters['graduation_date'];
            }

            if (!empty($filters['created_by'])) {
                $sql .= " AND c.created_by = :created_by";
                $params['created_by'] = $filters['created_by'];
            }

            $sql .= " ORDER BY c.contact_date DESC";

            $stmt = $this->db->prepare($sql);
            
            // ربط المعلمات (جميعها مسماة الآن)
            foreach ($params as $key => $value) {
                $paramName = ':' . $key;
                if (is_int($value)) {
                    $stmt->bindValue($paramName, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($paramName, $value, PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error in searchCommunications: " . $e->getMessage());
            return [];
        }
    }
}
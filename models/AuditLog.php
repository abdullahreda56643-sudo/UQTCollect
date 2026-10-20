<?php
class AuditLog extends BaseModel {
    protected $db;

    public function __construct($db) {
        parent::__construct($db, 'audit_logs', 'id', [
            'user_id', 'action', 'entity_type', 'entity_id', 'description', 'old_values', 'new_values',
            'ip_address', 'user_agent', 'created_at'
        ]);
        $this->db = $db;
    }

    public function getByTrainee($traineeId) {
        $query = "SELECT a.*, u.full_name as created_by_name 
                 FROM audit_logs a 
                 LEFT JOIN users u ON a.user_id = u.id 
                 WHERE a.entity_type = 'trainee' 
                 AND a.entity_id = ?
                 ORDER BY a.created_at DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$traineeId]);
        
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($activities as &$activity) {
            $activity['type'] = 'note';
            $activity['notes'] = $activity['description'];
        }
        
        return $activities;
    }

    /**
     * تسجيل حدث في سجل النظام
     * 
     * @param string $action نوع العملية
     * @param string $entityType نوع الكيان (مثل trainees, users, etc)
     * @param int $entityId معرف الكيان
     * @param string $description وصف العملية
     * @param array $changes التغييرات (القيم القديمة والجديدة)
     * @return bool
     */
    public function log($action, $entityType, $entityId, $description, $changes = null) {
        try {
            if (!isset($_SESSION['user_id'])) {
                throw new Exception('User not logged in');
            }

            $data = [
                'user_id' => $_SESSION['user_id'],
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];

            // إذا تم توفير التغييرات، قم بتخزينها كـ JSON
            if ($changes) {
                if (isset($changes['old'])) {
                    $data['old_values'] = is_scalar($changes['old']) ? $changes['old'] : json_encode($changes['old'], JSON_UNESCAPED_UNICODE);
                }
                if (isset($changes['new'])) {
                    $data['new_values'] = is_scalar($changes['new']) ? $changes['new'] : json_encode($changes['new'], JSON_UNESCAPED_UNICODE);
                }
                // إذا تم تمرير مصفوفة بسيطة
                if (!isset($changes['old']) && !isset($changes['new'])) {
                    $data['new_values'] = is_scalar($changes) ? $changes : json_encode($changes, JSON_UNESCAPED_UNICODE);
                }
            }

            // ضمان أن الحقول old_values/new_values نصية أو NULL
            if (isset($data['old_values']) && !is_string($data['old_values'])) {
                $data['old_values'] = json_encode($data['old_values'], JSON_UNESCAPED_UNICODE);
            }
            if (isset($data['new_values']) && !is_string($data['new_values'])) {
                $data['new_values'] = json_encode($data['new_values'], JSON_UNESCAPED_UNICODE);
            }

            return $this->create($data);
        } catch (Exception $e) {
            error_log("Error in AuditLog::log - " . $e->getMessage());
            return false;
        }
    }
}
?>






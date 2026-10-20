<?php
class Reminder extends BaseModel {
    protected $db;

    public function __construct($db) {
        parent::__construct($db, 'reminders', 'id', ['trainee_id', 'type', 'due_date', 'status', 'assigned_to', 'notes']);
        $this->db = $db;
    }

    public function getMyTasks($userId, $date = null) {
        $sql = "SELECT r.*, t.name as trainee_name, t.phone as trainee_phone 
                FROM reminders r 
                JOIN trainees t ON r.trainee_id = t.id 
                WHERE r.assigned_to = ? AND r.status = 'pending'";
        
        if ($date) {
            $sql .= " AND DATE(r.due_date) = ?";
        }
        
        $sql .= " ORDER BY r.due_date ASC";
        
        $stmt = $this->db->prepare($sql);
        
        if ($date) {
            $stmt->execute([$userId, $date]);
        } else {
            $stmt->execute([$userId]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $sql = "INSERT INTO reminders (trainee_id, type, due_date, status, assigned_to, notes) 
                VALUES (?, ?, ?, 'pending', ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['trainee_id'],
            $data['type'],
            $data['due_date'],
            $data['assigned_to'],
            $data['notes'] ?? null
        ]);
    }

    public function updateStatus($id, $status) {
        $sql = "UPDATE reminders SET status = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$status, $id]);
    }
}






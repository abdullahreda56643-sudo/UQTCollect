<?php
class Contact extends BaseModel {
    protected $db;

    public function __construct($db) {
        parent::__construct($db, 'contacts', 'id', [
            'trainee_id','contact_type','contact_date','result','next_action','notes','created_by'
        ]);
        $this->db = $db;
    }

    public function getAllForTrainee($traineeId) {
        $query = "SELECT c.*, u.full_name as created_by_name 
                 FROM contacts c 
                 LEFT JOIN users u ON c.created_by = u.id 
                 WHERE c.trainee_id = ? 
                 ORDER BY c.contact_date DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$traineeId]);
        
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($contacts as &$contact) {
            $contact['type'] = 'contact';
            $contact['created_at'] = $contact['contact_date'];
        }
        
        return $contacts;
    }
}






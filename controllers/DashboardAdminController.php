<?php
/**
 * متحكم لوحة التحكم الإدارية
 * يدير جميع منطق لوحة التحكم للمستخدمين الإداريين والموظفين
 * 
 * @version 2.0.0
 */
class DashboardAdminController {
    private $db;
    private $currentUserId;
    
    public function __construct($db) {
        $this->db = $db;
        $this->currentUserId = $_SESSION['user_id'] ?? null;
    }
    
    /**
     * الحصول على بيانات لوحة التحكم حسب نوع المستخدم
     * @param string $dashboardType نوع لوحة التحكم (admin/employee)
     * @return array
     */
    public function getDashboardData($dashboardType = 'admin') {
        $data = [
            'stats' => $this->getGeneralStats(),
            'employeePerformance' => $this->getEmployeePerformance(),
            'recentActivities' => $this->getRecentActivities(),
            'branchPerformance' => $this->getBranchPerformance(),
            'statusCounts' => $this->getTraineeStatusCounts()
        ];
        
        if ($dashboardType == 'employee') {
            $data = array_merge($data, [
                'employeeStats' => $this->getEmployeeStats($this->currentUserId),
                'traineesNeedingFollowup' => $this->getTraineesNeedingFollowup($this->currentUserId),
                'upcomingTasks' => $this->getUpcomingTasks($this->currentUserId)
            ]);
        }
        
        return $data;
    }
    
    /**
     * الحصول على القيم الافتراضية للإحصائيات
     * @return array
     */
    public function getDefaultStats() {
        return [
            'total_trainees' => 0,
            'today_payments' => 0,
            'today_contacts' => 0,
            'total_debt' => 0,
            'payments_total' => 0,
            'monthly_amount' => 0,
            'monthly_payments' => 0,
            'monthly_contacts' => 0,
            'new_trainees' => 0
        ];
    }
    
    /**
     * الحصول على القيم الافتراضية لإحصائيات الموظف
     * @return array
     */
    public function getDefaultEmployeeStats() {
        return [
            'assigned_trainees' => 0,
            'today_collected' => 0,
            'today_contacts' => 0,
            'pending_tasks' => 0
        ];
    }

    /**
     * الحصول على إحصائيات الموظف
     * @param int $userId معرف الموظف
     * @return array
     */
    private function getEmployeeStats($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM trainees WHERE assigned_to = :user_id) as assigned_trainees,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p 
                     JOIN trainees t ON p.trainee_id = t.id 
                     WHERE t.assigned_to = :user_id AND DATE(p.payment_date) = CURDATE()) as today_collected,
                    (SELECT COUNT(*) FROM contacts c 
                     JOIN trainees t ON c.trainee_id = t.id 
                     WHERE t.assigned_to = :user_id AND DATE(c.contact_date) = CURDATE()) as today_contacts,
                    (SELECT COUNT(*) FROM tasks 
                     WHERE assigned_to = :user_id AND status IN ('pending', 'in_progress') 
                     AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) as pending_tasks
            ");
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: $this->getDefaultEmployeeStats();
        } catch (PDOException $e) {
            error_log("Error in getEmployeeStats: " . $e->getMessage());
            return $this->getDefaultEmployeeStats();
        }
    }




    /**
     * الحصول على المتدربين الذين يحتاجون متابعة
     * @param int $userId معرف الموظف
     * @return array
     */
    private function getTraineesNeedingFollowup($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    t.*, d.name as diploma_name,
                    DATEDIFF(CURDATE(), COALESCE(MAX(c.contact_date), t.created_at)) as days_since_contact,
                    c.result as last_contact_result,
                    c.notes as last_contact_notes,
                    c.contact_date as last_contact
                FROM trainees t
                LEFT JOIN contacts c ON t.id = c.trainee_id
                LEFT JOIN diplomas d ON t.diploma_id = d.id
                WHERE t.assigned_to = :user_id AND t.remaining_amount > 0
                GROUP BY t.id
                HAVING days_since_contact > 3 OR days_since_contact IS NULL
                ORDER BY days_since_contact DESC
                LIMIT 10
            ");
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error in getTraineesNeedingFollowup: " . $e->getMessage());
            return [];
        }
    }

    /**
     * الحصول على المهام القادمة
     * @param int $userId معرف الموظف
     * @return array
     */
    private function getUpcomingTasks($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT t.*, tr.full_name as trainee_name, 
                       d.name as diploma_name,
                       tr.phone as trainee_phone,
                       tr.remaining_amount,
                       u.full_name as created_by_name,
                       DATEDIFF(due_date, CURDATE()) as days_remaining
                FROM tasks t
                LEFT JOIN trainees tr ON t.related_trainee = tr.id
                LEFT JOIN diplomas d ON t.related_diploma = d.id
                LEFT JOIN users u ON t.created_by = u.id
                WHERE t.assigned_to = :user_id 
                AND t.status IN ('pending', 'in_progress')
                AND t.due_date >= CURDATE()
                ORDER BY t.priority DESC, t.due_date ASC
                LIMIT 10
            ");
            $stmt->bindParam(':user_id', $userId);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error in getUpcomingTasks: " . $e->getMessage());
            return [];
        }
    }
    
    private function getGeneralStats() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM trainees) as total_trainees,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(payment_date) = CURDATE()) as today_payments,
                    (SELECT COUNT(*) FROM contacts WHERE DATE(contact_date) = CURDATE()) as today_contacts,
                    (SELECT COALESCE(SUM(t.total_amount - COALESCE(paid_sum.paid,0)), 0)
                     FROM trainees t
                     LEFT JOIN (
                         SELECT trainee_id, SUM(amount) as paid
                         FROM payments
                         GROUP BY trainee_id
                     ) paid_sum ON paid_sum.trainee_id = t.id
                     WHERE (t.total_amount - COALESCE(paid_sum.paid,0)) > 0) as total_debt,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments) as payments_total,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE())) as monthly_amount,
                    (SELECT COUNT(*) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE())) as monthly_payments,
                    (SELECT COUNT(*) FROM contacts WHERE MONTH(contact_date) = MONTH(CURDATE())) as monthly_contacts,
                    (SELECT COUNT(*) FROM trainees WHERE MONTH(created_at) = MONTH(CURDATE())) as new_trainees
            ");
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: $this->getDefaultStats();
        } catch (PDOException $e) {
            error_log("Error in getGeneralStats: " . $e->getMessage());
            return $this->getDefaultStats();
        }
    }
    
    private function getEmployeePerformance() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    u.full_name as employee_name, 
                    COALESCE(SUM(p.amount), 0) as collected_amount,
                    COUNT(DISTINCT c.id) as contact_count
                FROM users u
                LEFT JOIN trainees t ON u.id = t.assigned_to
                LEFT JOIN payments p ON t.id = p.trainee_id AND DATE(p.payment_date) = CURDATE()
                LEFT JOIN contacts c ON t.id = c.trainee_id AND DATE(c.contact_date) = CURDATE()
                WHERE u.role = 'employee' AND u.is_active = 1
                GROUP BY u.id
                ORDER BY collected_amount DESC
                LIMIT 5
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error in getEmployeePerformance: " . $e->getMessage());
            return [];
        }
    }
    
    private function getRecentActivities($limit = 10) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    u.full_name as user_name, 
                    al.action, 
                    al.created_at
                FROM audit_logs al
                JOIN users u ON al.user_id = u.id
                ORDER BY al.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($activities)) {
                return $this->getDefaultActivities();
            }
            
            return $activities;
        } catch (PDOException $e) {
            error_log("Error in getRecentActivities: " . $e->getMessage());
            return $this->getDefaultActivities();
        }
    }
    
    private function getBranchPerformance() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    b.name as branch_name,
                    COUNT(t.id) as trainee_count,
                    COALESCE(SUM(CASE WHEN DATE(p.payment_date) = CURDATE() THEN p.amount ELSE 0 END), 0) as collected_today,
                    COALESCE(SUM(t.total_amount - COALESCE(paid_sum.total_paid, 0)), 0) as total_debt
                FROM branches b
                LEFT JOIN trainees t ON b.id = t.branch_id
                LEFT JOIN (
                    SELECT trainee_id, SUM(amount) as total_paid FROM payments GROUP BY trainee_id
                ) paid_sum ON paid_sum.trainee_id = t.id
                LEFT JOIN payments p ON t.id = p.trainee_id
                GROUP BY b.id
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error in getBranchPerformance: " . $e->getMessage());
            return [];
        }
    }
    
    private function getTraineeStatusCounts() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN status = 'delayed_payment' THEN 1 ELSE 0 END) as delayed,
                    SUM(CASE WHEN status = 'promised_payment' THEN 1 ELSE 0 END) as promised_payment,
                    SUM(CASE WHEN status = 'under_communication' THEN 1 ELSE 0 END) as under_communication
                FROM trainees
            ");
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                'paid' => 0,
                'delayed' => 0,
                'promised_payment' => 0,
                'under_communication' => 0
            ];
        } catch (PDOException $e) {
            error_log("Error in getTraineeStatusCounts: " . $e->getMessage());
            return [
                'paid' => 0,
                'delayed' => 0,
                'promised_payment' => 0,
                'under_communication' => 0
            ];
        }
    }
    
    private function getDefaultActivities() {
        return [
            [
                'user_name' => 'النظام',
                'action' => 'تم تسجيل الدخول',
                'created_at' => date('Y-m-d H:i:s')
            ]
        ];
    }
}

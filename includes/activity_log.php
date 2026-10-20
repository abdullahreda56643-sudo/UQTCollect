<?php
/**
 * نظام تسجيل النشاطات في النظام
 */

// التحقق مما إذا كانت الدالة معرفة مسبقاً
if (!function_exists('logActivity')) {
    function logActivity($db, $userId, $actionType, $module, $description = null) {
        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            $stmt = $db->prepare("
                INSERT INTO system_activities (user_id, action_type, module, description, ip_address)
                VALUES (:user_id, :action_type, :module, :description, :ip_address)
            ");
            
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':action_type', $actionType);
            $stmt->bindParam(':module', $module);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':ip_address', $ipAddress);
            
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Error logging activity: " . $e->getMessage());
            return false;
        }
    }
}

// التحقق مما إذا كانت الدالة معرفة مسبقاً
if (!function_exists('getRecentActivities')) {
    function getRecentActivities($db, $limit = 10) {
        try {
            $stmt = $db->prepare("
                SELECT sa.*, u.full_name as user_name
                FROM system_activities sa
                LEFT JOIN users u ON sa.user_id = u.id
                ORDER BY sa.created_at DESC
                LIMIT :limit
            ");
            
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching activities: " . $e->getMessage());
            return [];
        }
    }
}

// التحقق مما إذا كانت الدالة معرفة مسبقاً
if (!function_exists('getTopEmployees')) {
    function getTopEmployees($db, $limit = 5) {
        try {
            $stmt = $db->prepare("
                SELECT 
                    u.id,
                    u.full_name as employee_name,
                    u.avatar,
                    COUNT(DISTINCT t.id) as trainee_count,
                    COALESCE(SUM(p.amount), 0) as total_collected,
                    COUNT(DISTINCT c.id) as contact_count,
                    COUNT(DISTINCT ts.id) as completed_tasks
                FROM users u
                LEFT JOIN trainees t ON u.id = t.assigned_to
                LEFT JOIN payments p ON t.id = p.trainee_id AND p.payment_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                LEFT JOIN contacts c ON t.id = c.trainee_id AND c.contact_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                LEFT JOIN tasks ts ON u.id = ts.assigned_to AND ts.status = 'completed' AND ts.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                WHERE u.role = 'employee' AND u.is_active = 1
                GROUP BY u.id
                ORDER BY total_collected DESC, contact_count DESC
                LIMIT :limit
            ");
            
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching top employees: " . $e->getMessage());
            return [];
        }
    }
}

// التحقق مما إذا كانت الدالة معرفة مسبقاً
if (!function_exists('getAssignedTasks')) {
    function getAssignedTasks($db, $user_id, $limit = 5) {
        try {
            $stmt = $db->prepare("
                SELECT t.*, u1.full_name as assigned_by_name, u2.full_name as assigned_to_name
                FROM tasks t
                LEFT JOIN users u1 ON t.assigned_by = u1.id
                LEFT JOIN users u2 ON t.assigned_to = u2.id
                WHERE t.assigned_to = :user_id
                ORDER BY t.created_at DESC
                LIMIT :limit
            ");
            
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching assigned tasks: " . $e->getMessage());
            return [];
        }
    }
}
?>
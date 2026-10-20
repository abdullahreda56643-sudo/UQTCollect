<?php
require_once '../config.php';
require_once '../db/connection.php';
require_once '../includes/auth.php';
require_once '../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$userId = (int)$_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // جلب جميع الإشعارات (ليس فقط غير المقروءة)
    try {
        $stmt = $db->prepare("
            SELECT * FROM notifications 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $unreadCount = getUnreadNotificationsCount($db, $userId);
        
        echo json_encode([
            'success' => true, 
            'data' => $notifications,
            'unreadCount' => $unreadCount
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error fetching notifications']);
    }
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    try {
        if ($action === 'mark_read') {
            // تعليم إشعار واحد كمقروء
            $notificationId = isset($input['notification_id']) ? (int)$input['notification_id'] : 0;
            if ($notificationId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid notification id']);
                exit;
            }
            
            $success = markAlertAsRead($db, $notificationId, $userId);
            echo json_encode(['success' => $success]);
            
        } elseif ($action === 'mark_all_read') {
            // تعليم جميع الإشعارات كمقروءة
            $stmt = $db->prepare("
                UPDATE notifications 
                SET is_read = 1 
                WHERE user_id = :user_id AND is_read = 0
            ");
            $success = $stmt->execute([':user_id' => $userId]);
            echo json_encode(['success' => $success]);
            
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>
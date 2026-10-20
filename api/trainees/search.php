<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/Trainee.php';

header('Content-Type: application/json; charset=utf-8');

// التحقق من تسجيل الدخول
checkPermission('employee');

// التأكد من وجود مصطلح البحث
$query = trim($_GET['q'] ?? '');
if (empty($query)) {
    echo json_encode([]);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $traineeModel = new Trainee($db);
    
    // البحث في المتدربين
    $sql = "SELECT id, full_name, national_id, phone, status 
            FROM trainees 
            WHERE full_name LIKE :query 
               OR national_id LIKE :query 
               OR phone LIKE :query
            LIMIT 10";
    
    $stmt = $db->prepare($sql);
    $stmt->execute(['query' => "%$query%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
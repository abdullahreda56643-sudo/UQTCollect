<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db/connection.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // require login
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'غير مسموح. الرجاء تسجيل الدخول']);
        exit;
    }

    $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
    if ($employeeId <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرف الموظف غير صالح']);
        exit;
    }

    $db = (new Database())->getConnection();

    $stmt = $db->prepare("SELECT id, full_name, national_id FROM trainees WHERE assigned_to = ? ORDER BY full_name LIMIT 1000");
    $stmt->execute([$employeeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'trainees' => $rows]);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    error_log('API get_assigned_trainees error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات']);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    error_log('API get_assigned_trainees general error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ غير متوقع']);
    exit;
}

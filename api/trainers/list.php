<?php
// التأكد من عدم الوصول المباشر للملف
if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(dirname(__FILE__) . '/../../../'));
}

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/db/connection.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/models/User.php';

header('Content-Type: application/json');

try {
    // التحقق من الصلاحيات
    checkPermission('employee');

    // إنشاء اتصال قاعدة البيانات
    $db = (new Database())->getConnection();
    $userModel = new User($db);

    // جلب قائمة المدربين
    $trainers = $userModel->list(['role' => 'trainer'], 'name ASC');

    // تحويل البيانات إلى التنسيق المطلوب
    $result = array_map(function($trainer) {
        return [
            'id' => (int)$trainer['id'],
            'name' => $trainer['name'],
            'email' => $trainer['email'],
            'phone' => $trainer['phone'],
        ];
    }, $trainers);

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'حدث خطأ أثناء جلب قائمة المدربين'
    ]);
}
?>
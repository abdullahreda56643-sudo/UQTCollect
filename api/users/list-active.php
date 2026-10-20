<?php
define('BASE_PATH', realpath(dirname(__FILE__) . '/../../'));

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/db/connection.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/models/User.php';

header('Content-Type: application/json');

try {
    // التحقق من تسجيل الدخول
    session_start();
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('يجب تسجيل الدخول أولاً');
    }

    // التحقق من الصلاحيات
    if (!hasAccess('trainees', 'assign')) {
        throw new Exception('ليس لديك صلاحية لإسناد المتدربين');
    }

    // إنشاء اتصال بقاعدة البيانات
    $db = (new Database())->getConnection();
    
    // إنشاء نموذج المستخدم
    $userModel = new User($db);
    
    // جلب جميع المستخدمين النشطين
    $users = $userModel->list([], 'full_name ASC');
    
    // تنسيق البيانات للرد
    $response = [
        'status' => 'success',
        'users' => array_map(function($user) {
            return [
                'id' => $user['id'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'role_name' => $user['role_name']
            ];
        }, $users)
    ];
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
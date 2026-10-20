<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

// only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/notifications/all.php', 'طريقة الطلب غير مدعومة', 'error');
}

if (!isset($_SESSION['user_id'])) {
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/auth/login.php', 'يجب تسجيل الدخول', 'error');
}

try {
    // التصحيح هنا - يجب استدعاء الدالة getConnection() بشكل صحيح
    $database = new Database();
    $db = $database->getConnection();
    $userId = (int) $_SESSION['user_id'];

    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0");
    $stmt->execute([':user_id' => $userId]);

    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/notifications/all.php', 'تم تعليم جميع الإشعارات كمقروءة', 'success');
} catch (Exception $e) {
    error_log('mark_all_read failed: ' . $e->getMessage());
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/notifications/all.php', 'فشل في تعليم الإشعارات كمقروءة', 'error');
}

?>
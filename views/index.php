<?php
// مسارات الاستدعاء الصحيحة
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

// بدء الجلسة إذا لم تكن بدأت
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/modules/auth/login.php');
    exit;
}

$userRole = $_SESSION['role'] ?? 'employee';

// توجيه المستخدم حسب دوره
$redirectPath = '';
switch ($userRole) {
    case 'admin':
        $redirectPath = '/views/dashboard/admin.php';
        break;
    case 'supervisor':
        $redirectPath = '/views/dashboard/supervisor.php';
        break;
    case 'employee':
        $redirectPath = '/views/dashboard/employee.php';
        break;
    default:
        $redirectPath = '/views/index.php';
}

header('Location: ' . rtrim(SITE_URL, '/') . $redirectPath);
exit;
?>
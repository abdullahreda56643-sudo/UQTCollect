<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Trainee.php';

$db = (new Database())->getConnection();
// Allow admin or users with per-user 'delete' permission for trainees
requireAccessOrAdmin('trainees', 'delete');
$traineeModel = new Trainee($db);

$id = (int)($_GET['id'] ?? 0);
// تم إزالة التحقق من رمز CSRF للأمان
if ($id <= 0) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/trainees/list.php', 'طلب غير صالح', 'error');
}

// حماية: لا تسمح للموظف بحذف متدرب ليس مسندًا إليه
try {
    if (function_exists('canAccessTrainee') && !canAccessTrainee($id, $db)) {
        redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/trainees/list.php', 'غير مصرح لك بحذف هذا المتدرب', 'error');
    }
} catch (Throwable $e) {
    error_log('canAccessTrainee check failed (delete): ' . $e->getMessage());
}

try {
    // جلب القيم القديمة قبل الحذف
    $oldRow = $traineeModel->findById($id);
    $traineeModel->delete($id);
    if (function_exists('createUserAlert')) {
        $title = 'حذف متدرب';
        $msg = 'تم حذف المتدرب #' . $id;
        createUserAlert($db, (int)$_SESSION['user_id'], $title, $msg, 'warning', rtrim(SITE_URL,'/') . '/modules/trainees/list.php');
    }
    // سجل النشاط: حذف
    try {
        require_once '../../includes/auth.php';
        logActivity($db, 'delete', 'trainees', $id, $oldRow, null);
    } catch (Exception $e) {
        error_log('Failed to log trainee deletion: ' . $e->getMessage());
    }
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/trainees/list.php', 'تم حذف السجل');
} catch (Exception $e) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/trainees/list.php', 'فشل الحذف: ' . $e->getMessage(), 'error');
}




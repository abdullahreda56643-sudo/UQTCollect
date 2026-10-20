<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Task.php';

$db = (new Database())->getConnection();
// require admin or explicit tasks delete permission
requireAccessOrAdmin('tasks', 'delete');
$taskModel = new Task($db);

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/tasks/list.php', 'طلب غير صالح', 'error');
}

try {
    $taskModel->delete($id);
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/tasks/list.php', 'تم حذف المهمة');
} catch (Exception $e) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/tasks/list.php', 'فشل الحذف: ' . $e->getMessage(), 'error');
}
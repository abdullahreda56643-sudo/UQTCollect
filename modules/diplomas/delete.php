<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Diploma.php';

checkPermission('admin');
$db = (new Database())->getConnection();
$diplomaModel = new Diploma($db);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/diplomas/list.php', 'طلب غير صالح', 'error');
}

try {
    $diplomaModel->delete($id);
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/diplomas/list.php', 'تم حذف الدبلوم');
} catch (Exception $e) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/diplomas/list.php', 'فشل الحذف: ' . $e->getMessage(), 'error');
}





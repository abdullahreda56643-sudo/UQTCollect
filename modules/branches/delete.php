<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Branch.php';

checkPermission('admin');
$db = (new Database())->getConnection();
$branchModel = new Branch($db);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/branches/list.php', 'طلب غير صالح', 'error');
}

try {
    $branchModel->delete($id);
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/branches/list.php', 'تم حذف الفرع');
} catch (Exception $e) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/branches/list.php', 'فشل الحذف: ' . $e->getMessage(), 'error');
}





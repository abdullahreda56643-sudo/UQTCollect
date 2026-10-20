<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Contact.php';

// require admin or explicit communication delete permission
requireAccessOrAdmin('communication', 'delete');
$db = (new Database())->getConnection();
$contactModel = new Contact($db);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/communications/list.php', 'طلب غير صالح', 'error');
}

try {
    $oldRow = $contactModel->findById($id);
    $contactModel->delete($id);
    // سجل الحذف
    try {
        require_once '../../includes/auth.php';
        logActivity($db, 'delete', 'communication', $id, $oldRow, null);
    } catch (Exception $e) {
        error_log('Failed to log communication deletion: ' . $e->getMessage());
    }
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/communications/list.php', 'تم حذف السجل');
} catch (Exception $e) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/communications/list.php', 'فشل الحذف: ' . $e->getMessage(), 'error');
}





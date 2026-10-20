<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Payment.php';
require_once '../../models/Trainee.php';

$db = (new Database())->getConnection();
// require admin or explicit payments delete permission
requireAccessOrAdmin('payments', 'delete');
$paymentModel = new Payment($db);
$traineeModel = new Trainee($db);

$id = (int)($_GET['id'] ?? 0);
// تم إزالة التحقق من رمز CSRF للأمان
if ($id <= 0) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/payments/list.php', 'طلب غير صالح', 'error');
}

try {
    $row = $paymentModel->findById($id);
    $paymentModel->delete($id);
    if ($row && isset($row['trainee_id'])) {
        $traineeModel->updateBalanceProcedure((int)$row['trainee_id']);
    }
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/payments/list.php', 'تم حذف الدفعة');
} catch (Exception $e) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/payments/list.php', 'فشل الحذف: ' . $e->getMessage(), 'error');
}






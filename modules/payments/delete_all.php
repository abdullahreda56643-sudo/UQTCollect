<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

// تأكد من أن المستخدم مصرح - يتطلب مستوى أعلى من الحماية
checkPermission('admin');

try {
    $db = (new Database())->getConnection();
} catch (Exception $e) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'خطأ', 'message' => 'فشل الاتصال بقاعدة البيانات'];
    header('Location: list.php');
    exit;
}

// فقط استقبال POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list.php');
    exit;
}

// التحقق من كلمة المرور المؤكدة للحماية من الحذف الخاطئ
$confirmPassword = $_POST['confirm_password'] ?? '';
$adminId = $_SESSION['user_id'] ?? 0;

// التحقق من كلمة المرور
if (empty($confirmPassword)) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'خطأ', 'message' => 'يجب إدخال كلمة المرور للتأكيد'];
    header('Location: list.php');
    exit;
}

// تحقق من كلمة المرور للمستخدم الحالي
try {
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin || !password_verify($confirmPassword, $admin['password'])) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'خطأ', 'message' => 'كلمة المرور غير صحيحة'];
        header('Location: list.php');
        exit;
    }
} catch (Exception $e) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'خطأ', 'message' => 'فشل التحقق من كلمة المرور'];
    header('Location: list.php');
    exit;
}

// التحقق من عدد المدفوعات الحالية
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM payments");
    $paymentCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($paymentCount == 0) {
        $_SESSION['alert'] = ['type' => 'warning', 'title' => 'تنبيه', 'message' => 'لا توجد مدفوعات لحذفها'];
        header('Location: list.php');
        exit;
    }
    
    // إضافة تحذير إذا كان هناك أكثر من 10 مدفوعات
    if ($paymentCount > 10) {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'خطأ', 'message' => "لا يمكن حذف $paymentCount دفعة دفعة واحدة. يرجى حذف المدفوعات individually أو الاتصال بالدعم الفني"];
        header('Location: list.php');
        exit;
    }
    
} catch (Exception $e) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'خطأ', 'message' => 'فشل التحقق من عدد المدفوعات'];
    header('Location: list.php');
    exit;
}

try {
    // create a backup table with timestamp
    $ts = date('Ymd_His');
    $backupTable = 'payments_backup_' . $ts;

    $db->beginTransaction();

    // Create backup
    $db->exec("CREATE TABLE `{$backupTable}` LIKE payments");
    $db->exec("INSERT INTO `{$backupTable}` SELECT * FROM payments");

    // Delete all payments
    $db->exec("DELETE FROM payments");

    $db->commit();

    // Log activity if function available
    if (function_exists('logActivity')) {
        logActivity($db, 'delete_all', 'payments', null, null, "backup={$backupTable}");
    }

    $_SESSION['alert'] = ['type' => 'success', 'title' => 'تم', 'message' => 'تم حذف جميع المدفوعات بنجاح (نسخة احتياطية: ' . $backupTable . ')'];
} catch (Exception $e) {
    try { $db->rollBack(); } catch (Exception $ee) {}
    error_log('Failed to delete all payments: ' . $e->getMessage());
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'خطأ', 'message' => 'حدث خطأ أثناء حذف المدفوعات'];
}

header('Location: list.php');
exit;

?>

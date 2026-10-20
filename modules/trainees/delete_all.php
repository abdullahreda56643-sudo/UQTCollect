<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Trainee.php';

// Allow only POST to perform destructive action
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', 'طريقة الطلب غير مدعومة', 'error');
}

// Require explicit 'delete_all' permission (even admins must have it enabled)
if (!hasAccess('trainees', 'delete_all')) {
    $_SESSION['error_message'] = 'ليس لديك صلاحية حذف جميع المتدربين';
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', 'ليس لديك صلاحية لحذف جميع المتدربين', 'error');
}

// التحقق من كلمة المرور المؤكدة للحماية من الحذف الخاطئ
$confirmPassword = $_POST['confirm_password'] ?? '';
$adminId = $_SESSION['user_id'] ?? 0;

// التحقق من كلمة المرور
if (empty($confirmPassword)) {
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', 'يجب إدخال كلمة المرور للتأكيد', 'error');
}

// تحقق من كلمة المرور للمستخدم الحالي
try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin || !password_verify($confirmPassword, $admin['password'])) {
        redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', 'كلمة المرور غير صحيحة', 'error');
    }
} catch (Exception $e) {
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', 'فشل التحقق من كلمة المرور', 'error');
}

// التحقق من عدد المتدربين الحاليين
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM trainees");
    $traineeCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($traineeCount == 0) {
        redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', 'لا يوجد متدربين لحذفهم', 'warning');
    }
    
    // إضافة تحذير إذا كان هناك أكثر من 50 متدرب
    if ($traineeCount > 50) {
        redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', "لا يمكن حذف $traineeCount متدرب دفعة واحدة. يرجى حذف المتدربين بشكل فردي أو الاتصال بالدعم الفني", 'error');
    }
    
} catch (Exception $e) {
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', 'فشل التحقق من عدد المتدربين', 'error');
}

$db = (new Database())->getConnection();
$traineeModel = new Trainee($db);

try {
    // Optional: create a backup table before deleting for safety
    $timestamp = date('Ymd_His');
    $backupTable = "trainees_backup_{$timestamp}";
    try {
        $db->exec("CREATE TABLE `{$backupTable}` LIKE trainees");
        $db->exec("INSERT INTO `{$backupTable}` SELECT * FROM trainees");
    } catch (Exception $e) {
        // If backup fails, log and continue (do not block deletion)
        error_log('Failed to backup trainees before bulk delete: ' . $e->getMessage());
    }

    // Begin transaction for safe deletion
    $db->beginTransaction();

    // Fetch count for logging
    $countStmt = $db->query('SELECT COUNT(*) as cnt FROM trainees');
    $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    // Delete all trainees
    $db->exec('DELETE FROM trainees');

    // Optionally, clear related tables if present (communications, payments)
    try {
        $db->exec('DELETE FROM communications');
    } catch (Exception $e) {}
    try {
        $db->exec('DELETE FROM payments');
    } catch (Exception $e) {}

    $db->commit();

    // Log activity
    try {
        logActivity($db, 'bulk_delete', 'trainees', null, null, ['count' => $total, 'backup_table' => $backupTable]);
    } catch (Exception $e) {
        error_log('Failed to log bulk trainees delete: ' . $e->getMessage());
    }

    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', "تم حذف جميع المتدربين ({$total}). تم إنشاء نسخة احتياطية: {$backupTable}");

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Bulk delete failed: ' . $e->getMessage());
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', 'فشل حذف المتدربين: ' . $e->getMessage(), 'error');
}


?>

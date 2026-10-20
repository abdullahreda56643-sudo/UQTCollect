<?php
/**
 * CLI: Run backup, create notifications for admins, and send email summary.
 * Run with PHP CLI (Task Scheduler/cron every 24h).
 */
require_once __DIR__ . '/../config.php';

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n";
    exit(1);
}

// ensure writable dirs
if (!is_dir(BACKUP_PATH)) mkdir(BACKUP_PATH, 0755, true);
if (!is_dir(TEMP_PATH)) mkdir(TEMP_PATH, 0755, true);

// Run the existing backup runner which emulates a session and includes backup logic
echo "Starting backup...\n";
ob_start();
include __DIR__ . '/backup_run.php';
$out = ob_get_clean();
file_put_contents(LOG_PATH . 'backup_and_notify.log', date('Y-m-d H:i:s') . " - backup_run output: " . trim($out) . "\n", FILE_APPEND);

// find latest backup file created
$files = glob(BACKUP_PATH . 'backup_*.zip');
usort($files, function($a, $b){ return filemtime($b) - filemtime($a); });
$latest = $files[0] ?? null;

// Connect to DB and create notifications for admin users
try {
    require_once __DIR__ . '/../db/connection.php';
    $db = (new Database())->getConnection();

    // get admin users
    $stmt = $db->prepare("SELECT id, email, full_name FROM users WHERE role = 'admin' AND is_active = 1");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $title = 'تذكير: النسخ الاحتياطي اليومي';
    if ($latest) {
        $sizeKb = number_format(filesize($latest) / 1024, 1);
        $message = "تم إنشاء نسخة احتياطية جديدة: " . basename($latest) . " (" . $sizeKb . " KB). يمكنك تحميلها من مجلد backups/ على الخادم.";
    } else {
        $message = "فشل إنشاء النسخة الاحتياطية اليوم. تحقق من سجلات النظام.";
    }

    foreach ($admins as $a) {
        try {
            $ins = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (:uid, 'info', :title, :message, :link, NOW())");
            $link = $latest ? ('/backups/' . basename($latest)) : null;
            $ins->execute([':uid' => $a['id'], ':title' => $title, ':message' => $message, ':link' => $link]);
        } catch (Exception $e) {
            error_log('Failed to create notification for admin ' . ($a['id'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    // send an email to ADMIN_EMAIL as well
    if (defined('ENABLE_EMAIL_NOTIFICATIONS') && ENABLE_EMAIL_NOTIFICATIONS) {
        $subject = 'Backup reminder - ' . date('Y-m-d');
        $body = "Hello,\n\n" . strip_tags($message) . "\n\nRegards,\nSystem\n";
        $headers = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>\r\n' .
                   'MIME-Version: 1.0\r\n' .
                   'Content-Type: text/plain; charset=utf-8\r\n';
        // Try PHP mail(); recommend configuring proper SMTP or PHPMailer for reliability
        @mail(ADMIN_EMAIL, $subject, $body, $headers);
    }

    echo "Notifications created for " . count($admins) . " admin(s).\n";
    echo "Backup_and_notify finished.\n";

} catch (Exception $e) {
    file_put_contents(LOG_PATH . 'backup_and_notify.log', date('Y-m-d H:i:s') . " - DB error: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "DB error: " . $e->getMessage() . "\n";
    exit(1);
}

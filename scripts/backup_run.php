<?php
/**
 * CLI runner for scheduled backups. Run with PHP CLI.
 * Example (Windows Task Scheduler):
 * php C:\xampp\htdocs\my-works\Trainee-Management-System\scripts\backup_run.php
 */
require_once __DIR__ . '/../config.php';

// Allow running from CLI only
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n";
    exit(1);
}

// Try to include the backup logic directly to avoid relying on HTTP
$backupFile = __DIR__ . '/../modules/admin/backup.php';
if (!file_exists($backupFile)) {
    echo "Backup endpoint missing: $backupFile\n";
    exit(1);
}

// Set minimal $_SERVER/$_POST values expected by the endpoint
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'create';

// Emulate an admin session minimally so isAdmin() returns true
if (session_status() == PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION['user_id'] = $_SESSION['user_id'] ?? 1;
$_SESSION['role'] = 'admin';

// Disable output buffering and include endpoint
ob_start();
include $backupFile;
$output = ob_get_clean();

// Write output to log
file_put_contents(LOG_PATH . 'backup_cli.log', date('Y-m-d H:i:s') . " - " . trim($output) . "\n", FILE_APPEND);
echo "Backup run completed. Check backups/ and logs/backup_cli.log for details.\n";

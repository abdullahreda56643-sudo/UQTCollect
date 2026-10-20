<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/connection.php';

try {
    $db = (new Database())->getConnection();
    $ts = date('Ymd_His');
    $bk = 'trainees_backup_' . $ts;
    $db->exec("CREATE TABLE IF NOT EXISTS `$bk` LIKE trainees");
    $db->exec("INSERT INTO `$bk` SELECT * FROM trainees");
    echo "Backup table created: $bk\n";
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}

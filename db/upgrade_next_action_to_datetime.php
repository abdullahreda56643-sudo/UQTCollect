<?php
/**
 * Upgrade script: convert next_action_date and trainees.next_action columns to DATETIME
 * Run locally once: php upgrade_next_action_to_datetime.php
 * Backup your database before running.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/connection.php';

$db = (new Database())->getConnection();

try {
    echo "Starting migration: converting next_action_date and trainees.next_action to DATETIME...\n";

    // communications.next_action_date
    $sql1 = "ALTER TABLE `communications` MODIFY `next_action_date` DATETIME NULL COMMENT 'موعد الإجراء التالي'";
    $db->exec($sql1);
    echo "Updated communications.next_action_date -> DATETIME\n";

    // Ensure communications.contact_date is DATETIME as well if it's currently DATE
    $colComContact = $db->query("SHOW COLUMNS FROM `communications` LIKE 'contact_date'")->fetch(PDO::FETCH_ASSOC);
    if ($colComContact && stripos($colComContact['Type'], 'date') === 0 && stripos($colComContact['Type'], 'datetime') === false) {
        $db->exec("ALTER TABLE `communications` MODIFY `contact_date` DATETIME NULL COMMENT 'تاريخ/وقت التواصل'");
        echo "Updated communications.contact_date -> DATETIME\n";
    } else {
        echo "communications.contact_date already DATETIME or not DATE; skipping.\n";
    }

    // trainees.next_action (if exists)
    // Some installations use trainees.next_action as DATE (legacy); convert it to DATETIME if present
    $colStmt = $db->query("SHOW COLUMNS FROM `trainees` LIKE 'next_action'");
    $colInfo = $colStmt->fetch(PDO::FETCH_ASSOC);
    if ($colInfo) {
        $sql2 = "ALTER TABLE `trainees` MODIFY `next_action` DATETIME NULL COMMENT 'موعد الإجراء التالي (من سجلات التواصل)';";
        $db->exec($sql2);
        echo "Updated trainees.next_action -> DATETIME\n";
    } else {
        echo "Column trainees.next_action not found; skipping trainees table change.\n";
    }

    // Ensure trainees.last_contact is DATETIME if currently DATE
    $colLast = $db->query("SHOW COLUMNS FROM `trainees` LIKE 'last_contact'")->fetch(PDO::FETCH_ASSOC);
    if ($colLast && stripos($colLast['Type'], 'date') === 0 && stripos($colLast['Type'], 'datetime') === false) {
        $db->exec("ALTER TABLE `trainees` MODIFY `last_contact` DATETIME NULL COMMENT 'آخر تواصل مع المتدرب'");
        echo "Updated trainees.last_contact -> DATETIME\n";
    } else {
        echo "trainees.last_contact already DATETIME or not DATE; skipping.\n";
    }

    // If there's a trigger that copied NEW.next_action_date to trainees.next_action (legacy), we keep it; it will now copy datetime.

    echo "Migration completed successfully. Please verify a backup was made and test the UI.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

return 0;

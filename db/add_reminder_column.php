<?php
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/../config.php';

function tableExists($db, $name) {
    try {
        $stmt = $db->query("SHOW TABLES LIKE '" . $name . "'");
        return ($stmt && $stmt->rowCount() > 0);
    } catch (Exception $e) {
        return false;
    }
}

function columnExists($db, $table, $column) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $column . "'");
        return ($stmt && $stmt->rowCount() > 0);
    } catch (Exception $e) {
        return false;
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!tableExists($db, 'communications')) {
        echo "Table `communications` does not exist. You can run db/run_communication_migration.php to create it.\n";
        exit(1);
    }

    if (columnExists($db, 'communications', 'reminder_id')) {
        echo "Column `reminder_id` already exists on `communications`. Nothing to do.\n";
        exit(0);
    }

    // Add the column first
    $sqlAdd = "ALTER TABLE `communications` ADD COLUMN `reminder_id` INT NULL COMMENT 'معرف التذكير المرتبط'";
    $db->exec($sqlAdd);
    echo "Added column `reminder_id` to `communications`.\n";

    // If tasks table exists, add foreign key
    if (tableExists($db, 'tasks')) {
        // check if a FK with same name exists
        try {
            $db->exec("ALTER TABLE `communications` ADD CONSTRAINT `fk_communications_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `tasks`(`id`) ON DELETE SET NULL");
            echo "Added foreign key fk_communications_reminder -> tasks(id).\n";
        } catch (Exception $e) {
            echo "Could not add foreign key (maybe one exists or lack of privileges): " . $e->getMessage() . "\n";
        }
    } else {
        echo "Table `tasks` not found; created `reminder_id` without foreign key.\n";
    }

    echo "Done.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

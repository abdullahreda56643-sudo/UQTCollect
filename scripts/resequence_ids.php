<?php
// CLI script to resequence primary key ids for a table and optionally update foreign keys.
// Usage: php resequence_ids.php table_name [--start=1] [--fix-fks]

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/connection.php';

if (PHP_SAPI !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

$options = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', $arg, 2);
        $key = ltrim($parts[0], '-');
        $val = $parts[1] ?? true;
        $options[$key] = $val;
    }
}

$allMode = isset($options['all']);
$dryRun = isset($options['dry-run']);
$autoYes = isset($options['yes']) || isset($options['y']);

if (!$allMode && (!isset($argv[1]) || strpos($argv[1], '--') === 0)) {
    echo "Usage: php resequence_ids.php <table> [--start=N] [--fix-fks] [--all] [--dry-run] [--yes]\n";
    echo "Example: php scripts/resequence_ids.php trainees --start=1 --fix-fks\n";
    echo "Example: php scripts/resequence_ids.php --all --dry-run\n";
    exit(1);
}

$table = $allMode ? null : $argv[1];
$start = isset($options['start']) ? (int)$options['start'] : 1;
$fixFks = isset($options['fix-fks']);

$db = (new Database())->getConnection();

try {
    // helper to process a single table (with its own transaction)
    $processTable = function(string $tbl) use ($db, $start, $fixFks, $dryRun) {
        // Verify table exists using INFORMATION_SCHEMA (supports prepared params)
        $s = $db->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t");
        $s->execute([':db' => DB_NAME, ':t' => $tbl]);
        if ($s->rowCount() === 0) {
            echo "Table '$tbl' not found, skipping.\n";
            return;
        }

        // Determine PK column name (default to 'id') using KEY_COLUMN_USAGE
        $pk = 'id';
        $ksql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND CONSTRAINT_NAME = 'PRIMARY' LIMIT 1";
        $ks = $db->prepare($ksql);
        $ks->execute([':db' => DB_NAME, ':t' => $tbl]);
        $pkRow = $ks->fetch(PDO::FETCH_ASSOC);
        if ($pkRow && !empty($pkRow['COLUMN_NAME'])) $pk = $pkRow['COLUMN_NAME'];

        echo "Preparing to resequence $tbl (primary key: $pk) starting at $start" . ($dryRun ? " [dry-run]" : "") . "\n";

        // Build mapping
        $rows = $db->query("SELECT `$pk` FROM `$tbl` ORDER BY `$pk` ASC")->fetchAll(PDO::FETCH_COLUMN);
        $mapping = [];
        $next = $start;
        foreach ($rows as $old) $mapping[$old] = $next++;

        if (empty($mapping)) { echo "No rows to process for $tbl.\n"; return; }

        // Find referencing FKs if requested
        $fkUpdates = [];
        if ($fixFks) {
            $sql = "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE REFERENCED_TABLE_NAME = :table AND REFERENCED_COLUMN_NAME = :col AND TABLE_SCHEMA = :db";
            $stmt = $db->prepare($sql);
            $stmt->execute([':db' => DB_NAME, ':table' => $tbl, ':col' => $pk]);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($candidates as $c) if ($c['TABLE_NAME'] !== $tbl) $fkUpdates[] = [$c['TABLE_NAME'], $c['COLUMN_NAME']];
        }

        // Show preview if dry-run
        if ($dryRun) {
            echo "Preview map for $tbl: first 10 entries:\n";
            $i = 0; foreach ($mapping as $o => $n) { echo "  $o -> $n\n"; if (++$i >= 10) break; }
            return;
        }

        // Proceed with changes for this table
        $db->beginTransaction();
        $tmp = 'tmp_id_map_' . time() . '_' . rand(1000,9999);
        $db->exec("CREATE TABLE `$tmp` (`old_id` BIGINT PRIMARY KEY, `new_id` BIGINT NOT NULL)");
        $ins = $db->prepare("INSERT INTO `$tmp` (old_id, new_id) VALUES (?, ?)");
        foreach ($mapping as $old => $new) $ins->execute([$old, $new]);

        try {
            echo "Disabling FOREIGN_KEY_CHECKS...\n";
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");

            echo "Updating primary keys in $tbl...\n";
            $db->exec("UPDATE `$tbl` t JOIN `$tmp` m ON t.`$pk` = m.old_id SET t.`$pk` = m.new_id");

            foreach ($fkUpdates as $u) {
                list($fkTable, $fkCol) = $u;
                echo "Updating foreign keys in $fkTable.$fkCol...\n";
                $db->exec("UPDATE `$fkTable` f JOIN `$tmp` m ON f.`$fkCol` = m.old_id SET f.`$fkCol` = m.new_id");
            }

            echo "Re-enabling FOREIGN_KEY_CHECKS...\n";
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");

            $max = max($mapping);
            $nextAi = $max + 1;
            $db->exec("ALTER TABLE `$tbl` AUTO_INCREMENT = $nextAi");

            $db->exec("DROP TABLE `$tmp`");
            $db->commit();
            echo "Resequence complete for $tbl. Next AUTO_INCREMENT set to $nextAi\n";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo "Error while processing $tbl: " . $e->getMessage() . "\n";
            // attempt to drop tmp if exists
            try { $db->exec("DROP TABLE IF EXISTS `$tmp`"); } catch (Exception $_){}
        }
    };

    // Build list of tables to process
    $tablesToProcess = [];
    if ($allMode) {
        $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND COLUMN_NAME = 'id' GROUP BY TABLE_NAME";
        $stmt = $db->prepare($sql);
        $stmt->execute([':db' => DB_NAME]);
        $tablesToProcess = array_map(fn($r)=>$r['TABLE_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        $tablesToProcess = [$table];
    }

    if (empty($tablesToProcess)) { echo "No tables found to process.\n"; exit(0); }

    echo "Will process " . count($tablesToProcess) . " table(s): " . implode(', ', $tablesToProcess) . "\n";
    if (!$autoYes) {
        echo "Proceed? Type 'yes' to continue: ";
        $confirm = trim(fgets(STDIN));
        if (strtolower($confirm) !== 'yes') { echo "Aborted by user.\n"; exit(0); }
    }

    foreach ($tablesToProcess as $t) {
        $processTable($t);
    }

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);

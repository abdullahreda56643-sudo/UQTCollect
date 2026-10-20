<?php
// scripts/sync_all_trainees.php
// Synchronize balances for all trainees by calling Payment::updateTraineeAmounts
// Usage: php sync_all_trainees.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../models/Payment.php';

try {
    $db = (new Database())->getConnection();
    $payment = new Payment($db);

    echo "Fetching trainee ids...\n";
    $stmt = $db->query('SELECT id FROM trainees');
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "Found " . count($ids) . " trainees.\n";
    $i = 0;
    foreach ($ids as $id) {
        $i++;
        echo "[$i/" . count($ids) . "] Syncing trainee id: $id ... ";
        $res = $payment->updateTraineeAmounts((int)$id);
        echo ($res ? "OK" : "FAIL") . "\n";
    }

    echo "Done.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

?>
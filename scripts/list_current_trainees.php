<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../models/Trainee.php';

$db = (new Database())->getConnection();
$traineeModel = new Trainee($db);

try {
    $sql = "SELECT id, full_name, total_amount, paid_amount, remaining_amount FROM trainees ORDER BY id DESC LIMIT 20";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
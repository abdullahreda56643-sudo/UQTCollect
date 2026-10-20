<?php
// Simple import script to parse a semicolon-separated trainee record and insert it using existing models.
// Usage (run locally in project root):
// php scripts/import_trainee_from_string.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../models/BaseModel.php';
require_once __DIR__ . '/../models/Trainee.php';
require_once __DIR__ . '/../models/AuditLog.php';

// The input line to parse (you can edit this variable or read from argv)
$input = "national_id: 2345678978; full_name: ahmed reda; phone: 0237483920; graduation_date: 2025-10-02; total_amount: 8000; paid_amount: 0; remaining_amount: 8000; diploma_id: 5; branch_id: 1; assigned_to:     5; full_name: ahmed reda; national_id: 2345678978; phone: 0237483920; phone2: ; graduation_date: 2025-10-02; graduation_date_hijri: ; total_amount: 8000.00; paid_amount: 0.00; remaining_amount: 8000.00; diploma_id: 5; branch_id: 1; status: under_communication; last_contact: ; next_action: ; next_action_notes: ; notes: ; assigned_to: 2; assignment_date: ; assignment_notes: ; created_at: 2025-10-02 20:55:08; updated_at: 2025-10-02 20:55:08; updated_by:";

// Optional: accept input from argv
if (isset($argv[1])) {
    $input = $argv[1];
}

function parseRecord($line) {
    $parts = preg_split('/;\s*/', $line);
    $data = [];
    foreach ($parts as $part) {
        if (trim($part) === '') continue;
        $kv = explode(':', $part, 2);
        if (count($kv) === 2) {
            $key = trim($kv[0]);
            $value = trim($kv[1]);
            $data[$key] = $value;
        }
    }
    return $data;
}

$data = parseRecord($input);

// Map to Trainee fields
$map = [
    'national_id' => 'national_id',
    'full_name' => 'full_name',
    'phone' => 'phone',
    'phone2' => 'phone2',
    'graduation_date' => 'graduation_date',
    'graduation_date_hijri' => 'graduation_date_hijri',
    'total_amount' => 'total_amount',
    'paid_amount' => 'paid_amount',
    'remaining_amount' => 'remaining_amount',
    'diploma_id' => 'diploma_id',
    'branch_id' => 'branch_id',
    'status' => 'status',
    'last_contact' => 'last_contact',
    'next_action' => 'next_action',
    'next_action_notes' => 'next_action_notes',
    'notes' => 'notes',
    'assigned_to' => 'assigned_to'
];

$insert = [];
foreach ($map as $k => $v) {
    if (isset($data[$k]) && $data[$k] !== '') {
        $insert[$v] = $data[$k];
    }
}

// Normalize numeric fields
foreach (['total_amount','paid_amount','remaining_amount','diploma_id','branch_id','assigned_to'] as $n) {
    if (isset($insert[$n])) {
        $insert[$n] = is_numeric($insert[$n]) ? (strpos($insert[$n], '.') !== false ? (float)$insert[$n] : (int)$insert[$n]) : $insert[$n];
    }
}

// DB setup
try {
    $dbObj = new Database();
    $db = $dbObj->getConnection();
} catch (Exception $e) {
    echo "DB connection failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$traineeModel = new Trainee($db);
$auditModel = new AuditLog($db);

try {
    // create trainee
    $newId = $traineeModel->create($insert);
    echo "Inserted trainee ID: " . $newId . PHP_EOL;

    // create audit log (simulate system user id 1 if not in session)
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) $_SESSION['user_id'] = 1;

    $auditModel->log('create', 'trainee', $newId, 'إنشاء متدرب عن طريق الاستيراد', ['new' => $insert]);

    // show latest audit entries for this trainee
    $stmt = $db->prepare("SELECT * FROM audit_logs WHERE entity_type = 'trainee' AND entity_id = :id ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([':id' => $newId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Recent audit rows:\n";
    foreach ($rows as $r) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}




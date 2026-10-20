<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/Communication.php';

checkPermission('employee');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['reminder_id']) || !isset($_POST['status'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$reminderId = (int)$_POST['reminder_id'];
$status = $_POST['status'];

if (!in_array($status, ['completed', 'cancelled'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

$db = (new Database())->getConnection();
$communicationModel = new Communication($db);

try {
    $success = $communicationModel->updateReminderStatus($reminderId, $status);
    echo json_encode(['success' => $success]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db/connection.php';
require_once __DIR__ . '/../../includes/auth.php';

// For API endpoints return JSON on auth failure instead of redirecting
if (!isLoggedIn() || !hasAccess('diplomas', 'edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo = (new Database())->getConnection();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid id']);
    exit;
}

try {
    // get current status
    $sql = "SELECT is_active FROM diplomas WHERE id = :id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Diploma not found']);
        exit;
    }

    $newStatus = $row['is_active'] == 1 ? 0 : 1;
    $upd = $pdo->prepare("UPDATE diplomas SET is_active = :s WHERE id = :id");
    $upd->bindValue(':s', $newStatus, PDO::PARAM_INT);
    $upd->bindValue(':id', $id, PDO::PARAM_INT);
    $upd->execute();

    echo json_encode(['success' => true, 'new_status' => $newStatus]);
} catch (PDOException $e) {
    error_log('Error in api/diplomas/toggle_status.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

?>
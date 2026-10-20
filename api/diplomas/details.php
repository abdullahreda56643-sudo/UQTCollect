<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db/connection.php';
require_once __DIR__ . '/../../includes/auth.php';

// require admin or explicit diplomas view permission
requireAccessOrAdmin('diplomas', 'view');

$pdo = (new Database())->getConnection();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid id']);
    exit;
}

try {
    $sql = "SELECT d.*, COUNT(t.id) AS current_enrollments FROM diplomas d LEFT JOIN trainees t ON d.id = t.diploma_id WHERE d.id = :id GROUP BY d.id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $diploma = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($diploma) {
        echo json_encode(['success' => true, 'diploma' => $diploma]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Diploma not found']);
    }
} catch (PDOException $e) {
    error_log('Error in api/diplomas/details.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

?>
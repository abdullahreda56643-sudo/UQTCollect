<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isAdmin()) {
    safeRedirect(rtrim(SITE_URL, '/') . '/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$file = $_POST['file'] ?? '';
if (empty($file)) {
    echo json_encode(['success' => false, 'message' => 'Missing file']);
    exit;
}

// Sanitise filename (no path traversal)
$basename = basename($file);
$full = BACKUP_PATH . $basename;
if (!file_exists($full) || !is_file($full)) {
    echo json_encode(['success' => false, 'message' => 'File not found']);
    exit;
}

$ok = @unlink($full);
if ($ok) {
    echo json_encode(['success' => true, 'message' => 'Deleted']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete']);
}
exit;

<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db/connection.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['trainee_id']) || !is_numeric($_GET['trainee_id'])) {
    echo json_encode(['success' => false, 'message' => 'trainee_id required']);
    exit;
}
$traineeId = (int)$_GET['trainee_id'];

try {
    $db = (new Database())->getConnection();
    // API-friendly authentication: return JSON error if not logged in
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: login required']);
        exit;
    }

    // Ensure the current user has permission to view trainees or can access this trainee
    // If hasAccess exists, prefer role-based check; otherwise fallback to canAccessTrainee
    $allowed = false;
    if (function_exists('hasAccess') && hasAccess('trainees', 'view')) {
        $allowed = true;
    } else {
        // Check per-trainee access if function available
        if (function_exists('canAccessTrainee')) {
            try {
                if (canAccessTrainee($traineeId, $db)) {
                    $allowed = true;
                }
            } catch (Exception $e) {
                // ignore and treat as not allowed
                $allowed = false;
            }
        }
    }

    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden: insufficient permissions']);
        exit;
    }

    // fetch communications ordered by contact_date desc
    // Note: some installations use `type` instead of `contact_method` and may have different columns
    $selectCols = 'id, trainee_id, contact_date, notes, created_at';
    // prefer `type` column if present
    try {
        // try to detect which column exists
        $colStmt = $db->query("SHOW COLUMNS FROM communications LIKE 'contact_method'");
        if ($colStmt && $colStmt->rowCount() > 0) {
            $selectCols = 'id, trainee_id, contact_date, contact_method as type, notes, created_at';
        } else {
            // fall back to `type` if present
            $colStmt2 = $db->query("SHOW COLUMNS FROM communications LIKE 'type'");
            if ($colStmt2 && $colStmt2->rowCount() > 0) {
                $selectCols = 'id, trainee_id, contact_date, type, notes, created_at';
            }
        }
    } catch (Exception $e) {
        // ignore; we'll attempt the query and catch errors below
    }

    $sql = "SELECT " . $selectCols . " FROM communications WHERE trainee_id = :tid ORDER BY contact_date DESC, created_at DESC";
    $stmt = $db->prepare($sql);
    try {
        $stmt->execute([':tid' => $traineeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("api/trainees/communications.php - DB query failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error while fetching communications']);
        exit;
    }

    // count
    $count = count($rows);

    echo json_encode(['success' => true, 'count' => $count, 'communications' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

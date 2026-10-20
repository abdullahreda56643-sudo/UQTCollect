<?php
// This script simulates a logged-in session and calls the trainees communications API
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/connection.php';

// start a session context similar to web
session_start();
// Set a user who exists in your DB; adjust ID and role if necessary
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['last_activity'] = time();

$traineeId = $argv[1] ?? 2; // sample trainee id existing in DB (from earlier script we saw trainee_id 2)

$apiUrl = rtrim(SITE_URL, '/') . '/api/trainees/communications.php?trainee_id=' . urlencode($traineeId);

// We'll include the API file directly to reuse the same process environment
// Set GET param
$_GET['trainee_id'] = $traineeId;

// Capture output
ob_start();
include __DIR__ . '/../api/trainees/communications.php';
$output = ob_get_clean();

// Print API output
echo "API_OUTPUT:\n" . $output . "\n";

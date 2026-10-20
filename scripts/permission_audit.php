<?php
// CLI script: Audit effective permissions for all users
// Usage: php scripts/permission_audit.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/auth.php';

// Start a session to let hasAccess/isLoggedIn read $_SESSION
if (php_sapi_name() === 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
}

$db = (new Database())->getConnection();

$modules = [
    'users', 'trainees', 'payments', 'reports', 'communications', 'tasks', 'branches'
];

$actions = ['view','create','edit','delete','export','import','toggle','manage_permissions'];

// Role default map copied from includes/auth.php to compute expected defaults
$roleDefaults = [
    'admin' => [
        'users' => ['view','create','edit','delete','export','import','toggle','manage_permissions'],
        'trainees' => ['view','create','edit','delete','export','import','toggle','manage_permissions'],
        'payments' => ['view','create','edit','delete','export','import','toggle','manage_permissions'],
        'reports' => ['view','create','edit','delete','export','import','toggle','manage_permissions'],
        'communication' => ['view','create','edit','delete','export','import','toggle','manage_permissions'],
        'tasks' => ['view','create','edit','delete','export','import','toggle','manage_permissions'],
        'branches' => ['view','create','edit','delete','export','import','toggle','manage_permissions'],
    ],
    'supervisor' => [
        'users' => ['view','edit'],
        'trainees' => ['view','create','edit','export','toggle'],
        'payments' => ['view','create','export'],
        'reports' => ['view','export'],
        'communication' => ['view','create','edit'],
        'tasks' => ['view','create','edit','toggle'],
        'branches' => ['view'],
    ],
    'employee' => [
        'users' => ['view'],
        'trainees' => ['view','create'],
        'payments' => ['view','create'],
        'reports' => ['view'],
        'communication' => ['view','create'],
        'tasks' => ['view','create'],
        'branches' => ['view'],
    ],
];

// Fetch users
$stmt = $db->query('SELECT id, username, full_name, role FROM users ORDER BY id');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$report = [];

foreach ($users as $user) {
    // prepare session context
    $_SESSION = [];
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['last_activity'] = time();

    $userResult = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'effective_permissions' => [],
        'role_defaults' => [],
        'overrides' => []
    ];

    foreach ($modules as $mod) {
        $modKey = $mod;
        $userResult['effective_permissions'][$modKey] = [];
        $userResult['role_defaults'][$modKey] = [];
        foreach ($actions as $act) {
            // map action names to hasAccess action parameter
            $actionMap = $act;
            if ($act === 'create') $actionMap = 'create';
            if ($act === 'manage_permissions') $actionMap = 'manage_permissions';

            $allowed = false;
            try {
                $allowed = hasAccess($modKey, $actionMap);
            } catch (Exception $e) {
                // if hasAccess fails, mark as false and record error
                $allowed = false;
                error_log("permission_audit: hasAccess failed for user {$user['id']} module {$modKey} action {$actionMap}: " . $e->getMessage());
            }

            $userResult['effective_permissions'][$modKey][$act] = (bool)$allowed;

            // compute role default
            $role = $user['role'];
            $default = false;
            if (isset($roleDefaults[$role])) {
                // note: roleDefaults keys use 'communication' singular in some places in original; normalize
                $lookupModule = $modKey;
                if ($lookupModule === 'communications') $lookupModule = 'communication';
                if (isset($roleDefaults[$role][$lookupModule]) && in_array($act, $roleDefaults[$role][$lookupModule])) {
                    $default = true;
                }
            }

            $userResult['role_defaults'][$modKey][$act] = (bool)$default;

            // detect override (effective != role default)
            if ($userResult['effective_permissions'][$modKey][$act] !== $userResult['role_defaults'][$modKey][$act]) {
                $userResult['overrides'][] = ['module' => $modKey, 'action' => $act, 'effective' => $userResult['effective_permissions'][$modKey][$act], 'role_default' => $userResult['role_defaults'][$modKey][$act]];
            }
        }
    }

    $report[] = $userResult;
}

$outPath = __DIR__ . '/permission_audit_output.json';
file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

// Print a compact CLI summary
foreach ($report as $u) {
    $overridesCount = count($u['overrides']);
    echo "User: {$u['id']} {$u['username']} ({$u['role']}) - overrides: {$overridesCount}\n";
    if ($overridesCount > 0) {
        foreach ($u['overrides'] as $ov) {
            $eff = $ov['effective'] ? 'YES' : 'NO ';
            $def = $ov['role_default'] ? 'YES' : 'NO ';
            echo "  - {$ov['module']}: {$ov['action']} effective={$eff} role_default={$def}\n";
        }
    }
}

echo "\nDetailed JSON written to: {$outPath}\n";

return 0;

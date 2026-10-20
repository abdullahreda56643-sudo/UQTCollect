<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// read query params for pagination/search/sort
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 10;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$sort = isset($_GET['sort']) ? strtolower(trim((string)$_GET['sort'])) : 'time_desc';

$all = [];
if (is_dir(BACKUP_PATH)) {
    $files = scandir(BACKUP_PATH);
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = BACKUP_PATH . $f;
        if (is_file($full)) {
            $all[] = [
                'name' => $f,
                'time' => filemtime($full),
                'size' => filesize($full)
            ];
        }
    }
}

// apply search filter
if ($search !== '') {
    $all = array_values(array_filter($all, function ($it) use ($search) {
        return stripos($it['name'], $search) !== false;
    }));
}

// sort
usort($all, function ($a, $b) use ($sort) {
    switch ($sort) {
        case 'name_asc': return strcasecmp($a['name'], $b['name']);
        case 'name_desc': return strcasecmp($b['name'], $a['name']);
        case 'time_asc': return $a['time'] <=> $b['time'];
        case 'time_desc':
        default:
            return $b['time'] <=> $a['time'];
    }
});

$total = count($all);
$paged = array_slice($all, $offset, $limit);

// format response
$backups = array_map(function($it){
    return [
        'name' => $it['name'],
        'time' => date('Y-m-d H:i', $it['time']),
        'size' => $it['size']
    ];
}, $paged);

echo json_encode(['success' => true, 'total' => $total, 'backups' => $backups, 'limit' => $limit, 'offset' => $offset]);
exit;

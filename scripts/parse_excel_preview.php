<?php
require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

if ($argc < 2) {
    echo "Usage: php parse_excel_preview.php <path-to-file>\n";
    exit(1);
}

$path = $argv[1];
if (!file_exists($path)) {
    echo "File not found: $path\n";
    exit(1);
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (!in_array($ext, ['xls','xlsx','csv','txt'])) {
    echo "Unsupported extension: $ext\n";
    exit(1);
}

try {
    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    echo "Total rows: " . count($rows) . "\n";
    $max = min(30, count($rows));
    for ($i = 0; $i < $max; $i++) {
        $row = $rows[$i];
        $cols = array_values($row);
        $nonEmpty = array_filter($cols, function($c){ return trim((string)$c) !== ''; });
        echo "Row " . ($i+1) . " - columns: " . count($cols) . ", non-empty: " . count($nonEmpty) . "\n";
        echo json_encode($cols, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    echo "\nPreview finished.\n";
    echo "Import mapping expects: [0]=graduation_date_hijri, [1]=diploma_name, [2]=full_name, [3]=national_id, [4]=phone, [5]=status_text, [6]=total_amount, [7]=remaining_amount, [8]=notes (optional).\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

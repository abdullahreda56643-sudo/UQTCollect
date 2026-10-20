<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Task.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$db = (new Database())->getConnection();
// ensure session exists (auth may rely on it) and start output buffering to prevent accidental prior output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// start an output buffer so we can clear any accidental output from included files
ob_start();
checkPermission('employee');
$taskModel = new Task($db);

// Gather filters from GET
$filters = [];
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $filters['status'] = $_GET['status'];
}
if (isset($_GET['assigned_to']) && $_GET['assigned_to'] !== '') {
    $filters['assigned_to'] = (int)$_GET['assigned_to'];
}

$tasks = $taskModel->list($filters, 'due_date ASC', 1000);

// Prepare spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('قائمة المهام');

$headers = ['#', 'العنوان', 'الوصف', 'تاريخ الاستحقاق', 'الأولوية', 'الحالة', 'المعيّن له', 'متعلق بالمتدرب'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col.'1', $h);
    $col++;
}

$row = 2;
foreach ($tasks as $t) {
    $sheet->setCellValue('A'.$row, (int)$t['id']);
    $sheet->setCellValue('B'.$row, $t['title']);
    $sheet->setCellValue('C'.$row, $t['description']);
    $sheet->setCellValue('D'.$row, date('Y-m-d H:i', strtotime($t['due_date'])));
    $sheet->setCellValue('E'.$row, $t['priority']);
    $sheet->setCellValue('F'.$row, $t['status']);
    $sheet->setCellValue('G'.$row, (int)$t['assigned_to']);
    $sheet->setCellValue('H'.$row, $t['related_trainee']);
    $row++;
}

// Output to browser
$filename = 'tasks_export_' . date('Ymd_His') . '.xlsx';

// Clear any output buffers created by included files to avoid corrupting binary output
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Turn off PHP output compression if enabled (can corrupt binary downloads)
if (ini_get('zlib.output_compression')) {
    ini_set('zlib.output_compression', '0');
}

// Content headers (include UTF-8 filename fallback)
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
// Modern way to support non-ASCII filenames
header("Content-Disposition: attachment; filename=\"{$filename}\"; filename*=UTF-8''" . rawurlencode($filename));
header('Pragma: public');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

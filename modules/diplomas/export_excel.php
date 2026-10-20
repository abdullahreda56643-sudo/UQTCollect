<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Diploma.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Ensure session started (auth/permissions rely on it)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// require admin or explicit diplomas export permission
requireAccessOrAdmin('diplomas', 'export');
$db = (new Database())->getConnection();
$diplomaModel = new Diploma($db);

// start buffering to clear any accidental output
ob_start();

// Gather filters from GET (same names used in list.php)
$filters = [];
if (isset($_GET['search']) && $_GET['search'] !== '') {
    $filters['search'] = $_GET['search'];
}
if (isset($_GET['cost_min']) && $_GET['cost_min'] !== '') {
    $filters['cost_min'] = (float)$_GET['cost_min'];
}
if (isset($_GET['cost_max']) && $_GET['cost_max'] !== '') {
    $filters['cost_max'] = (float)$_GET['cost_max'];
}
if (isset($_GET['duration_min']) && $_GET['duration_min'] !== '') {
    $filters['duration_min'] = (int)$_GET['duration_min'];
}
if (isset($_GET['duration_max']) && $_GET['duration_max'] !== '') {
    $filters['duration_max'] = (int)$_GET['duration_max'];
}
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $filters['status'] = $_GET['status'];
}

$diplomas = $diplomaModel->list($filters, 'id DESC', 10000);

// Prepare spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('قائمة الدبلومات');

$headers = ['#', 'اسم الدبلوم', 'الوصف', 'المدة (شهر)', 'التكلفة', 'المسجلين', 'الحالة'];
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col.'1', $h);
    $col++;
}

$row = 2;
foreach ($diplomas as $d) {
    $sheet->setCellValue('A'.$row, (int)$d['id']);
    $sheet->setCellValue('B'.$row, $d['name']);
    $sheet->setCellValue('C'.$row, $d['description'] ?? '');
    $sheet->setCellValue('D'.$row, (int)$d['duration']);
    $sheet->setCellValue('E'.$row, number_format((float)$d['cost'], 2));
    $sheet->setCellValue('F'.$row, (int)($d['current_enrollments'] ?? 0));
    $sheet->setCellValue('G'.$row, (($d['is_active'] ?? 0) == 1) ? 'نشط' : 'غير نشط');
    $row++;
}

$filename = 'diplomas_export_' . date('Ymd_His') . '.xlsx';

// Clear output buffers to avoid corrupting binary output
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Disable zlib compression if enabled
if (ini_get('zlib.output_compression')) {
    ini_set('zlib.output_compression', '0');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"; filename*=UTF-8''" . rawurlencode($filename));
header('Pragma: public');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

?>

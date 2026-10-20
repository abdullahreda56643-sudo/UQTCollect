<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Branch.php';
require_once '../../models/Diploma.php';

require_once '../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

$db = (new Database())->getConnection();
// require admin or explicit branches export permission
requireAccessOrAdmin('branches', 'export');
$branchModel = new Branch($db);
$diplomaModel = new Diploma($db);

$start = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
$end = $_GET['end'] ?? date('Y-m-d');

$branches = $branchModel->list([], 'id ASC', 1000);
$diplomas = $diplomaModel->list([], 'name ASC', 1000);

// build matrix counts
$distStmt = $db->prepare(
    "SELECT b.id as branch_id, d.id as diploma_id, COUNT(t.id) as cnt
     FROM trainees t
     JOIN branches b ON t.branch_id = b.id
     JOIN diplomas d ON t.diploma_id = d.id
     WHERE DATE(t.created_at) BETWEEN :start AND :end
     GROUP BY b.id, d.id"
);
$distStmt->execute([':start'=>$start, ':end'=>$end]);
$distRows = $distStmt->fetchAll(PDO::FETCH_ASSOC);
$matrix = [];
foreach ($distRows as $r) {
    $matrix[$r['branch_id']][$r['diploma_id']] = (int)$r['cnt'];
}

// create spreadsheet
$spreadsheet = new Spreadsheet();

// colors
$palette = ['primary'=>'2F5597','accent'=>'5B9BD5','muted'=>'F2F2F2','dark'=>'44546A'];

// 1) Summary sheet
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('ملخص الفروع');
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->setCellValue('A1', 'تقرير الفروع');
$sheet->mergeCells('A1:F1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($palette['primary']);
$sheet->getRowDimension(1)->setRowHeight(26);

$sheet->setCellValue('A2', 'نطاق التاريخ: ' . $start . ' — ' . $end);
$sheet->mergeCells('A2:F2');

$sheet->setCellValue('A4', 'إجمالي الفروع');
$sheet->setCellValue('B4', count($branches));

$sheet->setCellValue('A5', 'إجمالي السجلات (نطاق التاريخ)');
$totalTraineesStmt = $db->prepare("SELECT COUNT(*) as cnt FROM trainees WHERE DATE(created_at) BETWEEN :start AND :end");
$totalTraineesStmt->execute([':start'=>$start, ':end'=>$end]);
$totalTrainees = (int)$totalTraineesStmt->fetchColumn();
$sheet->setCellValue('B5', $totalTrainees);

// Simple styling
$sheet->getStyle('A4:B5')->getFont()->setBold(true);

// 2) Matrix sheet
$matrixSheet = $spreadsheet->createSheet();
$matrixSheet->setTitle('توزيع الدبلومات');
$matrixSheet->setCellValue('A1', 'الفرع \ الدبلوم');
$col = 2;
foreach ($diplomas as $d) {
    $matrixSheet->setCellValueByColumnAndRow($col, 1, $d['name']);
    $col++;
}
$matrixSheet->setCellValueByColumnAndRow($col, 1, 'المجموع');

$row = 2;
foreach ($branches as $b) {
    $matrixSheet->setCellValueByColumnAndRow(1, $row, $b['name']);
    $col = 2;
    $rowTotal = 0;
    foreach ($diplomas as $d) {
        $val = $matrix[$b['id']][$d['id']] ?? 0;
        $matrixSheet->setCellValueByColumnAndRow($col, $row, $val);
        $rowTotal += $val;
        $col++;
    }
    $matrixSheet->setCellValueByColumnAndRow($col, $row, $rowTotal);
    $row++;
}

// 3) Branches list sheet
$listSheet = $spreadsheet->createSheet();
$listSheet->setTitle('قائمة الفروع');
$headers = ['#','الفرع','الهاتف','الحالة'];
$listSheet->fromArray($headers, null, 'A1');
$r = 2;
foreach ($branches as $b) {
    $listSheet->setCellValue('A' . $r, $b['id']);
    $listSheet->setCellValue('B' . $r, $b['name']);
    $listSheet->setCellValue('C' . $r, $b['phone'] ?? '');
    $listSheet->setCellValue('D' . $r, ((int)$b['is_active']===1)?'نشط':'موقوف');
    $r++;
}

// Styling touches: header row bold + fill
foreach ([$matrixSheet, $listSheet] as $sht) {
    $sht->getStyle('A1:' . $sht->getHighestColumn() . '1')->getFont()->setBold(true);
    $sht->getStyle('A1:' . $sht->getHighestColumn() . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($palette['accent']);
    $sht->getStyle('A1:' . $sht->getHighestColumn() . '1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sht->freezePane('A2');
}

// Auto-size simple columns for list sheet
foreach (range('A','D') as $col) {
    $listSheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = 'تقرير_الفروع_' . date('Y-m-d_His') . '.xlsx';
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

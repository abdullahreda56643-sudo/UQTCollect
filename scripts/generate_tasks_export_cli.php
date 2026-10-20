<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../models/BaseModel.php';
require_once __DIR__ . '/../models/Task.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$db = (new Database())->getConnection();
$taskModel = new Task($db);
$tasks = call_user_func([$taskModel, 'list'], [], 'due_date ASC', 1000);

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

$filename = __DIR__ . '/tasks_export_cli_' . date('Ymd_His') . '.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($filename);

echo "Wrote $filename\n";

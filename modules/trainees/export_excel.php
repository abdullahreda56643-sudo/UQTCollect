<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Trainee.php';

require_once '../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooter;

checkPermission('employee');

$db = (new Database())->getConnection();
$traineeModel = new Trainee($db);

// الفلاتر
$filters = [];
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $filters['status'] = $_GET['status'];
}

// جلب المتدربين - استعلام آمن باستخدام الأعمدة الأساسية فقط
$query = "SELECT t.*, 
                 d.name as diploma_name,
                 (t.total_amount - t.paid_amount) as remaining_amount
          FROM trainees t
          LEFT JOIN diplomas d ON t.diploma_id = d.id
          WHERE 1=1";

$params = [];

if (isset($_GET['status']) && $_GET['status'] !== '') {
    $query .= " AND t.status = :status";
    $params[':status'] = $_GET['status'];
}

$query .= " ORDER BY t.id DESC LIMIT 2000";

$stmt = $db->prepare($query);
$stmt->execute($params);
$trainees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// استخدام تاريخ الإنشاء كتاريخ آخر تواصل (بديل عن last_contact)
foreach ($trainees as &$trainee) {
    $trainee['last_contact'] = $trainee['created_at'] ?? $trainee['updated_at'] ?? 'لم يتم التواصل';
}
unset($trainee);

// حساب الإحصائيات
$totalCount = count($trainees);
$totalRemaining = array_sum(array_column($trainees, 'remaining_amount'));

// استخدام الحالات الفعلية من قاعدة البيانات
$statusCounts = [
    'paid' => 0,
    'delayed' => 0,
    'promised_payment' => 0,
    'under_communication' => 0,
    'new' => 0
];

foreach ($trainees as $t) {
    $status = $t['status'] ?? 'under_communication';
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    } else {
        $statusCounts['under_communication']++;
    }
}

// إنشاء ملف إكسل
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("تقرير المتدربين");

// إعداد الصفحة - تنسيق احترافي
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);
$sheet->getPageSetup()->setHorizontalCentered(true);

// هوامش الصفحة
$sheet->getPageMargins()->setTop(0.5);
$sheet->getPageMargins()->setRight(0.3);
$sheet->getPageMargins()->setLeft(0.3);
$sheet->getPageMargins()->setBottom(0.5);

// رأس وتذييل الصفحة
$sheet->getHeaderFooter()
    ->setOddHeader('&C&16&"Arial,Bold"تقرير المتدربين - نظام الإدارة');
$sheet->getHeaderFooter()
    ->setOddFooter('&Rصفحة &P من &N &L' . date('Y-m-d H:i'));

// لوحة الألوان المهنية
$colorPalette = [
    'primary' => '2F5597',
    'secondary' => '5B9BD5',
    'success' => '70AD47',
    'warning' => 'FFC000',
    'danger' => 'FF0000',
    'light' => 'F2F2F2',
    'dark' => '44546A'
];

// العنوان الرئيسي للنظام
$sheet->setCellValue('A1', 'نظام إدارة المتدربين والتحصيل المالي');
$sheet->mergeCells('A1:K1');
$titleStyle = $sheet->getStyle('A1');
$titleStyle->getFont()->setBold(true)->setSize(16)->setColor(new Color(Color::COLOR_WHITE));
$titleStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPalette['primary']);
$titleStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(30);

// عنوان التقرير
$sheet->setCellValue('A2', 'تقرير المتدربين الشامل');
$sheet->mergeCells('A2:K2');
$subtitleStyle = $sheet->getStyle('A2');
$subtitleStyle->getFont()->setBold(true)->setSize(14);
$subtitleStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(2)->setRowHeight(25);

// تاريخ التقرير
$currentDate = new DateTime();
$formattedDate = $currentDate->format('Y-m-d H:i');
$sheet->setCellValue('A3', 'تاريخ إنشاء التقرير: ' . $formattedDate);
$sheet->mergeCells('A3:K3');
$dateStyle = $sheet->getStyle('A3');
$dateStyle->getFont()->setItalic(true);
$dateStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(3)->setRowHeight(20);

// اسم المؤسسة
$sheet->setCellValue('A4', 'مؤسسة التدريب والتطوير');
$sheet->mergeCells('A4:K4');
$orgStyle = $sheet->getStyle('A4');
$orgStyle->getFont()->setBold(true)->setSize(12)->setColor(new Color($colorPalette['dark']));
$orgStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(4)->setRowHeight(22);

// ملخص الإحصائيات في جدول منظم
$sheet->setCellValue('A6', 'ملخص التقرير الإحصائي');
$sheet->mergeCells('A6:K6');
$summaryHeaderStyle = $sheet->getStyle('A6');
$summaryHeaderStyle->getFont()->setBold(true)->setSize(12)->setColor(new Color(Color::COLOR_WHITE));
$summaryHeaderStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPalette['secondary']);
$summaryHeaderStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(6)->setRowHeight(25);

// بيانات الملخص
$summaryData = [
    ['إجمالي عدد المتدربين', $totalCount, $colorPalette['primary']],
    ['إجمالي المبالغ المتبقية', number_format($totalRemaining, 2) . ' ر.س', $colorPalette['dark']],
    ['الحالة: مدفوع', $statusCounts['paid'], $colorPalette['success']],
    ['الحالة: متأخر', $statusCounts['delayed'], $colorPalette['danger']],
    ['الحالة: وعد بالدفع', $statusCounts['promised_payment'], $colorPalette['warning']],
    ['الحالة: تحت التواصل', $statusCounts['under_communication'], '5B9BD5']
];

// إضافة حالة "جديد" فقط إذا كانت موجودة
if ($statusCounts['new'] > 0) {
    $summaryData[] = ['الحالة: جديد', $statusCounts['new'], 'A5A5A5'];
}

$summaryRow = 7;
foreach ($summaryData as $index => $data) {
    $col = $index * 2 + 1;
    if ($col <= 11) {
        $sheet->setCellValueByColumnAndRow($col, $summaryRow, $data[0]);
        $sheet->setCellValueByColumnAndRow($col + 1, $summaryRow, $data[1]);
        
        $sheet->getStyleByColumnAndRow($col, $summaryRow)->getFont()->setBold(true);
        $sheet->getStyleByColumnAndRow($col + 1, $summaryRow)->getFont()->setBold(true)->getColor()->setRGB($data[2]);
    }
}

// تطبيق حدود على خلايا الملخص
$sheet->getStyle('A7:K7')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A7:K7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// مسافة قبل الجدول الرئيسي
$sheet->setCellValue('A8', '');
$sheet->mergeCells('A8:K8');
$sheet->getRowDimension(8)->setRowHeight(10);

// العناوين الرئيسية للجدول
$headers = ['#', 'الاسم الكامل', 'رقم الهوية', 'الجوال', 'الدبلوم', 'الحالة', 'المبلغ المتبقي', 'المبلغ الإجمالي', 'المبلغ المدفوع', 'ملاحظات', 'تاريخ التسجيل'];
$sheet->fromArray($headers, null, 'A9');

// تنسيق عناوين الجدول
$headerStyle = $sheet->getStyle('A9:K9');
$headerStyle->getFont()->setBold(true)->setSize(11)->setColor(new Color(Color::COLOR_WHITE));
$headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPalette['secondary']);
$headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM);
$sheet->getRowDimension(9)->setRowHeight(25);

// دالة مساعدة للحصول على نص الحالة
function getStatusText($status) {
    $statuses = [
        'paid' => 'مدفوع',
        'delayed' => 'متأخر',
        'promised_payment' => 'وعد بالدفع',
        'under_communication' => 'تحت التواصل',
        'new' => 'جديد'
    ];
    
    return $statuses[$status] ?? $status;
}

// تعبئة البيانات
$row = 10;
foreach ($trainees as $t) {
    $status = $t['status'] ?? 'under_communication';
    
    $sheet->setCellValue('A' . $row, $t['id']);
    $sheet->setCellValue('B' . $row, $t['full_name']);
    $sheet->setCellValue('C' . $row, $t['national_id']);
    $sheet->setCellValue('D' . $row, $t['phone']);
    $sheet->setCellValue('E' . $row, $t['diploma_name']);
    $sheet->setCellValue('F' . $row, getStatusText($status));
    $sheet->setCellValue('G' . $row, $t['remaining_amount']);
    $sheet->setCellValue('H' . $row, $t['total_amount']);
    $sheet->setCellValue('I' . $row, $t['paid_amount']);
    $sheet->setCellValue('J' . $row, $t['notes'] ?? '');
    
    // تنسيق تاريخ التسجيل
    if (!empty($t['last_contact']) && $t['last_contact'] !== 'لم يتم التواصل') {
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $t['last_contact']);
        if ($date) {
            $sheet->setCellValue('K' . $row, $date->format('Y-m-d H:i'));
        } else {
            $sheet->setCellValue('K' . $row, $t['last_contact']);
        }
    } else {
        $sheet->setCellValue('K' . $row, 'غير محدد');
    }

    // ألوان الحالة
    $statusCell = 'F' . $row;
    switch ($status) {
        case 'paid':
            $sheet->getStyle($statusCell)
                  ->getFont()->setBold(true)->getColor()->setRGB('008000');
            $sheet->getStyle($statusCell)
                  ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('CCFFCC');
            break;
        case 'delayed':
            $sheet->getStyle($statusCell)
                  ->getFont()->setBold(true)->getColor()->setRGB('FF0000');
            $sheet->getStyle($statusCell)
                  ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFCCCC');
            break;
        case 'promised_payment':
            $sheet->getStyle($statusCell)
                  ->getFont()->setBold(true)->getColor()->setRGB('FFA500');
            $sheet->getStyle($statusCell)
                  ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFE6CC');
            break;
        case 'under_communication':
            $sheet->getStyle($statusCell)
                  ->getFont()->setBold(true)->getColor()->setRGB('5B9BD5');
            $sheet->getStyle($statusCell)
                  ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
            break;
        case 'new':
            $sheet->getStyle($statusCell)
                  ->getFont()->setBold(true)->getColor()->setRGB('A5A5A5');
            $sheet->getStyle($statusCell)
                  ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
            break;
        default:
            break;
    }

    // صفوف بألوان متناوبة
    $rowStyle = $sheet->getStyle("A{$row}:K{$row}");
    if ($row % 2 == 0) {
        $rowStyle->getFill()
                 ->setFillType(Fill::FILL_SOLID)
                 ->getStartColor()
                 ->setRGB($colorPalette['light']);
    }
    
    // حدود للخلايا
    $rowStyle->getBorders()
             ->getAllBorders()
             ->setBorderStyle(Border::BORDER_THIN);

    $row++;
}

// محاذاة الخلايا
$lastDataRow = $row - 1;
$alignments = [
    'A' => Alignment::HORIZONTAL_CENTER,
    'B' => Alignment::HORIZONTAL_RIGHT,
    'C' => Alignment::HORIZONTAL_CENTER,
    'D' => Alignment::HORIZONTAL_LEFT,
    'E' => Alignment::HORIZONTAL_RIGHT,
    'F' => Alignment::HORIZONTAL_CENTER,
    'G' => Alignment::HORIZONTAL_RIGHT,
    'H' => Alignment::HORIZONTAL_RIGHT,
    'I' => Alignment::HORIZONTAL_RIGHT,
    'J' => Alignment::HORIZONTAL_RIGHT,
    'K' => Alignment::HORIZONTAL_CENTER
];

foreach ($alignments as $col => $alignment) {
    $sheet->getStyle($col . '10:' . $col . $lastDataRow)->getAlignment()->setHorizontal($alignment);
}

// تنسيق المبالغ المالية
$sheet->getStyle('G10:I' . $lastDataRow)->getNumberFormat()->setFormatCode('#,##0.00" ر.س"');

// تنسيق التواريخ
$sheet->getStyle('K10:K' . $lastDataRow)->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');

// تمكين التصفية التلقائية
$sheet->setAutoFilter('A9:K9');

// تثبيت العناوين
$sheet->freezePane('A10');

// توسيع الأعمدة
$columnWidths = [
    'A' => 6,   'B' => 25,  'C' => 15,  'D' => 15,
    'E' => 25,  'F' => 15,  'G' => 15,  'H' => 15,
    'I' => 15,  'J' => 30,  'K' => 18
];

foreach ($columnWidths as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

// إضافة توقيع
$sheet->setCellValue('A' . ($row + 2), 'اسم المسؤول: ...............................');
$sheet->setCellValue('A' . ($row + 3), 'التوقيع: ...................................');
$sheet->setCellValue('A' . ($row + 4), 'تاريخ الطباعة: ' . $formattedDate);

$sheet->mergeCells('A' . ($row + 2) . ':C' . ($row + 2));
$sheet->mergeCells('A' . ($row + 3) . ':C' . ($row + 3));
$sheet->mergeCells('A' . ($row + 4) . ':C' . ($row + 4));

// تعيين نطاق الطباعة
$sheet->getPageSetup()->setPrintArea('A1:K' . ($row + 4));

// إخراج الملف
$filename = "تقرير_المتدربين_" . date('Y-m-d_His') . ".xlsx";

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
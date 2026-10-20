<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/Payment.php';
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Set headers for file download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . date('Y-m-d') . '_payments_report.xlsx"');
header('Cache-Control: max-age=0');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooter;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

checkPermission('employee');

// تنظيف أي مخرجات سابقة
if (ob_get_length()) {
    ob_end_clean();
}

$db = (new Database())->getConnection();
$paymentModel = new Payment($db);

// تجهيز الفلاتر
$filters = [];
if (isset($_GET['trainee_id']) && $_GET['trainee_id'] !== '') {
    $filters['trainee_id'] = (int)$_GET['trainee_id'];
}

// تجهيز نطاق التاريخ
$startDate = !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$period = $_GET['period'] ?? 'daily';

// الحصول على تقرير التحصيل
$report = $paymentModel->getCollectionReport($startDate, $endDate, $period, $filters);

// التحقق من وجود بيانات
$hasData = !empty($report);

// إنشاء ملف Excel جديد
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('تقرير المدفوعات');

// تعيين اتجاه الصفحة من اليمين إلى اليسار
$sheet->setRightToLeft(true);

// لوحة الألوان الاحترافية
$colorPalette = [
    'primary' => '2F5597',
    'secondary' => '5B9BD5',
    'accent' => 'FF6B6B',
    'success' => '70AD47',
    'warning' => 'FFC000',
    'light' => 'F2F2F2',
    'dark' => '44546A',
    'header' => '2F5597',
    'subheader' => '5B9BD5'
];

// إعداد الصفحة - تنسيق احترافي
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);
$sheet->getPageSetup()->setHorizontalCentered(true);

// هوامش الصفحة
$sheet->getPageMargins()->setTop(0.8);
$sheet->getPageMargins()->setRight(0.5);
$sheet->getPageMargins()->setLeft(0.5);
$sheet->getPageMargins()->setBottom(0.8);

// رأس وتذييل الصفحة
$sheet->getHeaderFooter()
    ->setOddHeader('&C&16&"Arial,Bold"تقرير المدفوعات - نظام إدارة المتدربين');
$sheet->getHeaderFooter()
    ->setOddFooter('&Rصفحة &P من &N &L' . date('Y-m-d H:i'));

// العنوان الرئيسي للنظام
$sheet->setCellValue('A1', 'نظام إدارة المتدربين والتحصيل المالي');
$sheet->mergeCells('A1:E1');
$titleStyle = $sheet->getStyle('A1');
$titleStyle->getFont()->setBold(true)->setSize(18)->setColor(new Color(Color::COLOR_WHITE));
$titleStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPalette['primary']);
$titleStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(35);

// عنوان التقرير
$sheet->setCellValue('A2', 'تقرير المدفوعات والتحصيل المالي');
$sheet->mergeCells('A2:E2');
$subtitleStyle = $sheet->getStyle('A2');
$subtitleStyle->getFont()->setBold(true)->setSize(14);
$subtitleStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(2)->setRowHeight(28);

// معلومات الفترة
$sheet->setCellValue('A3', 'الفترة: من ' . $startDate . ' إلى ' . $endDate);
$sheet->mergeCells('A3:E3');
$periodStyle = $sheet->getStyle('A3');
$periodStyle->getFont()->setItalic(true)->setSize(11);
$periodStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(3)->setRowHeight(22);

// اسم المؤسسة
$sheet->setCellValue('A4', 'مؤسسة التدريب والتطوير');
$sheet->mergeCells('A4:E4');
$orgStyle = $sheet->getStyle('A4');
$orgStyle->getFont()->setBold(true)->setSize(12)->setColor(new Color($colorPalette['dark']));
$orgStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(4)->setRowHeight(24);

// مسافة قبل الجدول
$sheet->getRowDimension(5)->setRowHeight(10);

if ($hasData) {
    // العناوين الرئيسية للجدول
    $headers = ['الفترة', 'عدد المدفوعات', 'إجمالي المبلغ (ر.س)', 'متوسط المدفوعة (ر.س)', 'النسبة المئوية'];
    $sheet->fromArray($headers, null, 'A6');

    // تنسيق عناوين الجدول
    $headerStyle = $sheet->getStyle('A6:E6');
    $headerStyle->getFont()->setBold(true)->setSize(12)->setColor(new Color(Color::COLOR_WHITE));
    $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPalette['secondary']);
    $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM);
    $sheet->getRowDimension(6)->setRowHeight(28);

    // حساب الإجماليات للمساعدة في النسب المئوية
    $totalPayments = 0;
    $totalAmount = 0;
    foreach ($report as $data) {
        $totalPayments += isset($data['payment_count']) ? $data['payment_count'] : 0;
        $totalAmount += isset($data['total_amount']) ? $data['total_amount'] : 0;
    }

    // إضافة البيانات مع تنسيق احترافي
    $row = 7;
    foreach ($report as $index => $data) {
        // حساب النسبة المئوية
        $total_amount = isset($data['total_amount']) ? $data['total_amount'] : 0;
        $payment_count = isset($data['payment_count']) ? $data['payment_count'] : 0;
        $period = isset($data['period']) ? $data['period'] : '';
        
        $percentage = $totalAmount > 0 ? ($total_amount / $totalAmount) * 100 : 0;
        $average = ($payment_count > 0) ? $total_amount / $payment_count : 0;
        
        $sheet->setCellValue('A' . $row, $period);
        $sheet->setCellValue('B' . $row, $payment_count);
        $sheet->setCellValue('C' . $row, $total_amount);
        $sheet->setCellValue('D' . $row, $average);
        $sheet->setCellValue('E' . $row, $percentage);
        
        // تنسيق الصفوف المتناوبة
        $rowStyle = $sheet->getStyle("A{$row}:E{$row}");
        if ($index % 2 == 0) {
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

    // صف الإجمالي
    $sheet->setCellValue('A' . $row, 'الإجمالي');
    $sheet->setCellValue('B' . $row, $totalPayments);
    $sheet->setCellValue('C' . $row, $totalAmount);
    $sheet->setCellValue('D' . $row, ($totalPayments > 0) ? $totalAmount / $totalPayments : 0);
    $sheet->setCellValue('E' . $row, 100);

    // تنسيق صف الإجمالي
    $totalStyle = $sheet->getStyle("A{$row}:E{$row}");
    $totalStyle->getFont()->setBold(true)->setSize(12);
    $totalStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E6E6E6');
    $totalStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM);

    // الإحصائيات الإضافية
    $statsRow = $row + 2;
    $sheet->setCellValue('A' . $statsRow, 'ملخص الإحصائيات');
    $sheet->mergeCells("A{$statsRow}:E{$statsRow}");
    $sheet->getStyle("A{$statsRow}")->getFont()->setBold(true)->setSize(12)->setColor(new Color(Color::COLOR_WHITE));
    $sheet->getStyle("A{$statsRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorPalette['primary']);
    $sheet->getStyle("A{$statsRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension($statsRow)->setRowHeight(25);

    // إحصائيات مفصلة - مع معالجة المصفوفات الفارغة
    $totalPeriods = count($report);
    $avgDailyPayments = $totalPeriods > 0 ? $totalPayments / $totalPeriods : 0;
    
    // الحصول على أعلى وأقل مبلغ مع معالجة المصفوفات الفارغة
    $amounts = array_column($report, 'total_amount');
    $maxAmount = !empty($amounts) ? max($amounts) : 0;
    $minAmount = !empty($amounts) ? min($amounts) : 0;

    $statsData = [
        ['إجمالي عدد الفترات', $totalPeriods],
        ['إجمالي عدد المدفوعات', $totalPayments],
        ['متوسط المدفوعات اليومية', number_format($avgDailyPayments, 2)],
        ['أعلى مبلغ في فترة', number_format($maxAmount, 2) . ' ر.س'],
        ['أقل مبلغ في فترة', number_format($minAmount, 2) . ' ر.س']
    ];

    $statsRow++;
    foreach ($statsData as $index => $stat) {
        $sheet->setCellValue('A' . ($statsRow + $index), $stat[0]);
        $sheet->setCellValue('B' . ($statsRow + $index), $stat[1]);
        
        // تنسيق متناوب للإحصائيات
        $statStyle = $sheet->getStyle("A" . ($statsRow + $index) . ":B" . ($statsRow + $index));
        if ($index % 2 == 0) {
            $statStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8F9FA');
        }
        $statStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    // محاذاة الخلايا
    $alignments = [
        'A' => Alignment::HORIZONTAL_RIGHT,   // الفترة
        'B' => Alignment::HORIZONTAL_CENTER,  // العدد
        'C' => Alignment::HORIZONTAL_RIGHT,   // الإجمالي
        'D' => Alignment::HORIZONTAL_RIGHT,   // المتوسط
        'E' => Alignment::HORIZONTAL_CENTER   // النسبة
    ];

    foreach ($alignments as $col => $alignment) {
        $sheet->getStyle($col . '7:' . $col . $row)->getAlignment()->setHorizontal($alignment);
    }

    // تنسيق الأرقام
    $sheet->getStyle('C7:D' . $row)->getNumberFormat()->setFormatCode('#,##0.00" ر.س"');
    $sheet->getStyle('E7:E' . $row)->getNumberFormat()->setFormatCode('0.00"%"');

    // تمكين التصفية التلقائية
    $sheet->setAutoFilter('A6:E6');

    // تثبيت العناوين لتبقى ظاهرة عند التمرير
    $sheet->freezePane('A7');

    $lastDataRow = $statsRow + count($statsData);
} else {
    // إذا لم توجد بيانات
    $sheet->setCellValue('A6', 'لا توجد بيانات للعرض في الفترة المحددة');
    $sheet->mergeCells('A6:E6');
    $sheet->getStyle('A6')->getFont()->setBold(true)->setSize(14)->setColor(new Color('FF0000'));
    $sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getRowDimension(6)->setRowHeight(40);
    
    $lastDataRow = 6;
}

// توسيع الأعمدة تلقائياً مع تحديد عرض مناسب
$columnWidths = [
    'A' => 25,  // الفترة
    'B' => 18,  // عدد المدفوعات
    'C' => 20,  // الإجمالي
    'D' => 20,  // المتوسط
    'E' => 15   // النسبة
];

foreach ($columnWidths as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

// إضافة توقيع في نهاية التقرير
$signatureRow = $lastDataRow + 2;
$sheet->setCellValue('A' . $signatureRow, 'اسم المسؤول: _________________________');
$sheet->setCellValue('A' . ($signatureRow + 1), 'التوقيع: _________________________');
$sheet->setCellValue('A' . ($signatureRow + 2), 'تاريخ الطباعة: ' . date('Y-m-d H:i'));

// دمج خلايا التوقيع
$sheet->mergeCells("A{$signatureRow}:C{$signatureRow}");
$sheet->mergeCells("A" . ($signatureRow + 1) . ":C" . ($signatureRow + 1));
$sheet->mergeCells("A" . ($signatureRow + 2) . ":C" . ($signatureRow + 2));

// تعيين نطاق الطباعة
$lastRow = $signatureRow + 2;
$sheet->getPageSetup()->setPrintArea("A1:E{$lastRow}");

// حماية الورقة (يمكن للمستخدمين عرض البيانات فقط)
$sheet->getProtection()->setSheet(true);
$sheet->getProtection()->setSort(true);
$sheet->getProtection()->setInsertRows(true);

// إخراج الملف
$filename = "تقرير_المدفوعات_" . date('Y-m-d_His') . ".xlsx";

try {
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
} catch (Exception $e) {
    echo "حدث خطأ في إنشاء التقرير: " . $e->getMessage();
    exit;
}
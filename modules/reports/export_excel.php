<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/Report.php';

// استخدام مكتبة PhpSpreadsheet لإنشاء ملفات Excel
require_once '../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

$db = (new Database())->getConnection();
// require admin or explicit reports export permission
requireAccessOrAdmin('reports', 'export');
$report = new Report($db);

// المستخدم الحالي ودوره
$stmt = $db->prepare('SELECT branch_id, role FROM users WHERE id = :id');
$stmt->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_INT);
$stmt->execute();
$me = $stmt->fetch();
$userRole = $me['role'] ?? 'employee';
$defaultBranchId = (int)($me['branch_id'] ?? 0);

// معلمات التقرير
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end = $_GET['end'] ?? date('Y-m-d');
$branchId = ($userRole === 'admin') ? (int)($_GET['branch_id'] ?? $defaultBranchId) : $defaultBranchId;

// بيانات التقرير
$collections = $report->getCollectionsByDay($start, $end, $branchId);
$summary = $report->getFinancialSummary($start, $end, $branchId);
$statusDist = $report->getTraineeStatusDistribution($branchId);

// إنشاء ملف Excel جديد
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// إعدادات اللغة العربية
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial');
$sheet->setRightToLeft(true); // تعيين اتجاه RTL للغة العربية

// تعيين عنوان الورقة
$sheet->setTitle('تقرير التحصيل المالي');

// تنسيق العنوان الرئيسي
$sheet->mergeCells('A1:F1');
$sheet->setCellValue('A1', 'تقرير التحصيل المالي');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A2:F2');
$sheet->setCellValue('A2', 'من: ' . $start . ' إلى: ' . $end);
$sheet->getStyle('A2')->getFont()->setSize(12);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A3:F3');
$sheet->setCellValue('A3', 'تاريخ التقرير: ' . date('Y-m-d H:i:s'));
$sheet->getStyle('A3')->getFont()->setSize(12);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// إضافة مسافة
$sheet->setCellValue('A4', '');
$sheet->getRowDimension(4)->setRowHeight(10);

// ملخص التقرير
$sheet->mergeCells('A5:F5');
$sheet->setCellValue('A5', 'ملخص التقرير');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAD3');
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A6', 'إجمالي المحصل في الفترة:');
$sheet->setCellValue('B6', formatCurrency($summary['total_collected'] ?? 0));
$sheet->setCellValue('A7', 'إجمالي المديونية الحالية:');
$sheet->setCellValue('B7', formatCurrency($summary['total_debt'] ?? 0));
$sheet->setCellValue('A8', 'متدربون تم التواصل معهم:');
$sheet->setCellValue('B8', $summary['contacted_trainees'] ?? 0);

$sheet->getStyle('A6:A8')->getFont()->setBold(true);
$sheet->getStyle('B6:B8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

// إضافة مسافة
$sheet->setCellValue('A9', '');
$sheet->getRowDimension(9)->setRowHeight(10);

// التحصيل اليومي
$sheet->mergeCells('A10:F10');
$sheet->setCellValue('A10', 'التحصيل اليومي');
$sheet->getStyle('A10')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A10')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAD3');
$sheet->getStyle('A10')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// عناوين الجدول
$sheet->setCellValue('A11', 'اليوم');
$sheet->setCellValue('B11', 'المبلغ');
$sheet->setCellValue('C11', 'العمليات');

$sheet->getStyle('A11:C11')->getFont()->setBold(true);
$sheet->getStyle('A11:C11')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3D85C6');
$sheet->getStyle('A11:C11')->getFont()->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A11:C11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// بيانات الجدول
$row = 12;

if (empty($collections)) {
    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->setCellValue('A' . $row, 'لا توجد بيانات للعرض');
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A' . $row)->getFont()->setItalic(true);
    $row++;
} else {
    foreach ($collections as $collection) {
        // التحقق من وجود pay_day وتعيين قيمة افتراضية إذا لم يكن موجودًا
        $payDay = isset($collection['pay_day']) ? $collection['pay_day'] : null;
        $payDayFormatted = $payDay ? date('d/m/Y', strtotime($payDay)) : 'غير محدد';

        $sheet->setCellValue('A' . $row, $payDayFormatted);
        $sheet->setCellValue('B' . $row, formatCurrency($collection['total_amount'] ?? 0));
        $sheet->setCellValue('C' . $row, $collection['tx_count'] ?? 0);
        
        // تنسيق متناوب للصفوف
        if ($row % 2 == 0) {
            $sheet->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
        }
        
        // محاذاة الأعمدة
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $row++;
    }
}

// إضافة مسافة
$sheet->setCellValue('A' . $row, '');
$sheet->getRowDimension($row)->setRowHeight(10);
$row++;

// توزيع حالات المتدربين
$sheet->mergeCells('A' . $row . ':F' . $row);
$sheet->setCellValue('A' . $row, 'توزيع حالات المتدربين');
$sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9EAD3');
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// عناوين الجدول
$sheet->setCellValue('A' . $row, 'الحالة');
$sheet->setCellValue('B' . $row, 'العدد');
$sheet->setCellValue('C' . $row, 'النسبة');

$sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
$sheet->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3D85C6');
$sheet->getStyle('A' . $row . ':C' . $row)->getFont()->getColor()->setRGB('FFFFFF');
$sheet->getStyle('A' . $row . ':C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$row++;

// بيانات الجدول
$totalTrainees = array_sum($statusDist);

if (empty($statusDist)) {
    $sheet->mergeCells('A' . $row . ':C' . $row);
    $sheet->setCellValue('A' . $row, 'لا توجد بيانات للعرض');
    $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A' . $row)->getFont()->setItalic(true);
    $row++;
} else {
    foreach ($statusDist as $status => $count) {
        $percentage = ($totalTrainees > 0) ? round(($count / $totalTrainees) * 100, 1) : 0;
        
        // تحويل أسماء الحالات إلى العربية
        $statusArabic = match($status) {
            'active' => 'نشط',
            'graduated' => 'متخرج',
            'delayed' => 'متأخر',
            'withdrawn' => 'منسحب',
            'follow_up' => 'متابعة',
            default => $status
        };
        
        $sheet->setCellValue('A' . $row, $statusArabic);
        $sheet->setCellValue('B' . $row, $count);
        $sheet->setCellValue('C' . $row, $percentage . '%');
        
        // تنسيق متناوب للصفوف
        if ($row % 2 == 0) {
            $sheet->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
        }
        
        // محاذاة الأعمدة
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $row++;
    }
}

// تنسيق عام للجداول
if (!empty($collections) || !empty($statusDist)) {
    $sheet->getStyle('A11:C' . ($row-1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}

// ضبط عرض الأعمدة تلقائيًا
$sheet->getColumnDimension('A')->setWidth(20);
$sheet->getColumnDimension('B')->setWidth(15);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(10);
$sheet->getColumnDimension('E')->setWidth(10);
$sheet->getColumnDimension('F')->setWidth(10);

// إضافة تذييل للتقرير
$row += 2;
$sheet->mergeCells('A' . $row . ':F' . $row);
$sheet->setCellValue('A' . $row, 'تم إنشاء هذا التقرير بواسطة نظام إدارة المتدربين بتاريخ ' . date('d/m/Y H:i'));
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setItalic(true);
$row++;

$sheet->mergeCells('A' . $row . ':F' . $row);
$sheet->setCellValue('A' . $row, '© ' . date('Y') . ' جميع الحقوق محفوظة');
$sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A' . $row)->getFont()->setItalic(true);

// إعداد headers للتحميل
$filename = 'تقرير_التحصيل_المالي_' . date('Y-m-d') . '.xlsx';

// إنشاء كائن Writer لحفظ الملف
$writer = new Xlsx($spreadsheet);

// تعيين headers للتحميل
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Content-Transfer-Encoding: binary');

// إضافة headers للغة العربية
header('Pragma: public');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

// حفظ الملف إلى php://output
$writer->save('php://output');
exit;
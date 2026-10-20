<?php
// تفعيل عرض الأخطاء للتحقق من المشاكل
error_reporting(E_ALL);
ini_set('display_errors', 1);

// زيادة حد الذاكرة
ini_set('memory_limit', '512M');

// فحص وإعداد الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../vendor/autoload.php';
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/Report.php';

// require admin or explicit reports export permission
requireAccessOrAdmin('reports', 'export');

// الاتصال بقاعدة البيانات
try {
    $db = (new Database())->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    die('خطأ في الاتصال بقاعدة البيانات: ' . $e->getMessage());
}

$report = new Report($db);

// المستخدم الحالي ودوره
try {
    $stmt = $db->prepare('SELECT branch_id, role FROM users WHERE id = :id');
    $stmt->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $me = $stmt->fetch();
    
    if (!$me) {
        throw new Exception('المستخدم غير موجود');
    }
    
    $userRole = $me['role'] ?? 'employee';
    $defaultBranchId = (int)($me['branch_id'] ?? 0);
} catch (Exception $e) {
    http_response_code(500);
    die('خطأ في تحميل بيانات المستخدم: ' . $e->getMessage());
}

// معلمات التقرير مع التحقق من الصحة
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end = $_GET['end'] ?? date('Y-m-d');
$branchId = ($userRole === 'admin') ? (int)($_GET['branch_id'] ?? $defaultBranchId) : $defaultBranchId;

// التحقق من صحة التواريخ
if (!strtotime($start) || !strtotime($end)) {
    die('التواريخ غير صالحة');
}

if (strtotime($start) > strtotime($end)) {
    die('تاريخ البداية يجب أن يكون قبل تاريخ النهاية');
}

// تعريف متغير لتخزين نوع التقرير
$reportType = $_GET['type'] ?? 'financial';

// التأكد من أن نوع التقرير صالح
if (!in_array($reportType, ['financial', 'collections', 'contacts'])) {
    $reportType = 'financial';
}

// جلب بيانات التقرير مع معالجة الأخطاء
try {
    $collections = $report->getCollectionsByDay($start, $end, $branchId);
    $summary = $report->getFinancialSummary($start, $end, $branchId);
    $statusDist = $report->getTraineeStatusDistribution($branchId);
} catch (Exception $e) {
    http_response_code(500);
    die('خطأ في جلب بيانات التقرير: ' . $e->getMessage());
}

// إنشاء وتجهيز المجلد المؤقت
$tempDir = __DIR__ . '/../../temp/mpdf';
if (!file_exists($tempDir)) {
    if (!mkdir($tempDir, 0777, true)) {
        die('تعذر إنشاء المجلد المؤقت');
    }
}

// تنظيف المجلد المؤقت من الملفات القديمة (أقدم من ساعة)
$files = glob("$tempDir/*.*");
$now = time();
foreach ($files as $file) {
    if (is_file($file) && ($now - filemtime($file)) > 3600) {
        unlink($file);
    }
}

// تنظيف أي مخرجات سابقة
if (ob_get_length()) ob_clean();

// إنشاء كائن mPDF مع معالجة الأخطاء
try {
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L',
        'default_font' => 'dejavusans', // دعم أفضل للعربية
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 20,
        'margin_bottom' => 20,
        'tempDir' => $tempDir,
        'directionality' => 'rtl' // تحديد اتجاه النص من اليمين لليسار
    ]);

    // تعيين الرأس والتذييل
    $header = '<div style="text-align: center; font-weight: bold; font-size: 10pt; border-bottom: 1px solid #ccc; padding-bottom: 5px;">نظام إدارة المتدربين - تقرير مالي</div>';
    $footer = '<div style="text-align: center; font-size: 8pt; border-top: 1px solid #ccc; padding-top: 5px;">صفحة {PAGENO} من {nb}</div>';
    
    $mpdf->SetHTMLHeader($header);
    $mpdf->SetHTMLFooter($footer);
    
} catch (Exception $e) {
    error_log('خطأ في إنشاء PDF: ' . $e->getMessage());
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="text-align: center; font-family: Arial, sans-serif; direction: rtl; margin-top: 20px;">';
    echo '<h2>عذراً، حدث خطأ أثناء إنشاء ملف PDF</h2>';
    echo '<p>يرجى المحاولة مرة أخرى لاحقاً أو الاتصال بمسؤول النظام.</p>';
    echo '</div>';
    exit;
}

// تعيين معلومات المستند وخصائصه
$mpdf->SetTitle('تقرير التحصيل المالي');
$mpdf->SetAuthor('نظام إدارة المتدربين');
$mpdf->SetCreator('نظام إدارة المتدربين');

// إضافة CSS للتنسيق
$cssFile = __DIR__ . '/../../assets/css/pdf-style.css';
if (file_exists($cssFile)) {
    $stylesheet = file_get_contents($cssFile);
} else {
    // CSS افتراضي
    $stylesheet = '
        body { 
            font-family: "DejaVu Sans", Arial, sans-serif; 
            direction: rtl; 
            line-height: 1.6;
            font-size: 12pt;
        }
        h1 { 
            text-align: center; 
            color: #2c3e50;
            margin-bottom: 10px;
        }
        h2 { 
            color: #34495e;
            border-bottom: 2px solid #3498db;
            padding-bottom: 5px;
            margin-top: 20px;
        }
        h3, h4 { 
            text-align: center; 
            color: #7f8c8d;
            margin: 5px 0;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 15px 0;
            font-size: 10pt;
        }
        th { 
            background-color: #2980B9; 
            color: white; 
            font-weight: bold; 
            padding: 10px;
            text-align: center;
        }
        td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            text-align: center;
        }
        tr:nth-child(even) { 
            background-color: #f8f9fa; 
        }
        .summary-box { 
            border: 1px solid #ddd; 
            padding: 15px; 
            margin: 15px 0; 
            background-color: #f8f9fa;
            border-radius: 5px;
        }
        .footer { 
            text-align: center; 
            font-size: 9pt; 
            margin-top: 30px;
            color: #7f8c8d;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }
        .section-break {
            margin: 20px 0;
            border-top: 2px dashed #ddd;
        }
        .currency {
            font-family: "Courier New", monospace;
            font-weight: bold;
        }
    ';
}

// بدء محتوى HTML
$html = '<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقرير التحصيل المالي</title>
</head>
<body>';

$html .= '<h1>تقرير التحصيل المالي</h1>';
$html .= '<h3>الفترة من: ' . htmlspecialchars($start) . ' إلى: ' . htmlspecialchars($end) . '</h3>';
$html .= '<h4>تاريخ إنشاء التقرير: ' . date('Y-m-d H:i:s') . '</h4>';

// ملخص التقرير
$html .= '<h2>ملخص التقرير</h2>';
$html .= '<div class="summary-box">';
$html .= '<table>';
$html .= '<tr><td style="width: 50%; font-weight: bold;">إجمالي المحصل في الفترة:</td><td class="currency">' . formatCurrency($summary['total_collected'] ?? 0) . '</td></tr>';
$html .= '<tr><td style="font-weight: bold;">إجمالي المديونية الحالية:</td><td class="currency">' . formatCurrency($summary['total_debt'] ?? 0) . '</td></tr>';
$html .= '<tr><td style="font-weight: bold;">متدربون تم التواصل معهم:</td><td>' . ($summary['contacted_trainees'] ?? 0) . '</td></tr>';
$html .= '</table>';
$html .= '</div>';

// التحصيل اليومي
if (!empty($collections)) {
    $html .= '<h2>التحصيل اليومي</h2>';
    $html .= '<table>';
    $html .= '<tr><th>اليوم</th><th>المبلغ</th><th>عدد العمليات</th></tr>';
    
    $totalAmount = 0;
    $totalTransactions = 0;
    
    foreach ($collections as $collection) {
        $totalAmount += $collection['total_amount'] ?? 0;
        $totalTransactions += $collection['tx_count'] ?? 0;
        
        $html .= '<tr>';
        $html .= '<td>' . date('d/m/Y (l)', strtotime($collection['pay_day'])) . '</td>';
        $html .= '<td class="currency">' . formatCurrency($collection['total_amount'] ?? 0) . '</td>';
        $html .= '<td>' . ($collection['tx_count'] ?? 0) . '</td>';
        $html .= '</tr>';
    }
    
    // إجمالي التحصيل اليومي
    $html .= '<tr style="background-color: #e8f4fd; font-weight: bold;">';
    $html .= '<td>الإجمالي</td>';
    $html .= '<td class="currency">' . formatCurrency($totalAmount) . '</td>';
    $html .= '<td>' . $totalTransactions . '</td>';
    $html .= '</tr>';
    
    $html .= '</table>';
} else {
    $html .= '<p style="text-align: center; color: #7f8c8d; font-style: italic;">لا توجد بيانات للتحصيل اليومي في الفترة المحددة</p>';
}

// إضافة ملخص التحصيل المالي
$html .= '<div class="section-break"></div>';
$html .= '<h2>ملخص التحصيل المالي</h2>';
$html .= '<div class="summary-box">';
$html .= '<p>إجمالي المبالغ المحصلة: <strong class="currency">' . formatCurrency($summary['total_collected'] ?? 0) . '</strong></p>';
$html .= '<p>متوسط التحصيل اليومي: <strong class="currency">' . formatCurrency($summary['daily_average'] ?? 0) . '</strong></p>';
$html .= '<p>عدد المعاملات: <strong>' . number_format($summary['transaction_count'] ?? 0) . '</strong></p>';
$html .= '</div>';

// توزيع حالات المتدربين
if (!empty($statusDist)) {
    $html .= '<div class="section-break"></div>';
    $html .= '<h2>توزيع حالات المتدربين</h2>';
    $html .= '<table class="status-table">';
    $html .= '<tr><th>الحالة</th><th>العدد</th><th>النسبة</th></tr>';
    
    $totalTrainees = array_sum($statusDist);
    
    foreach ($statusDist as $status => $count) {
        $percentage = ($totalTrainees > 0) ? round(($count / $totalTrainees) * 100, 1) : 0;
        
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($status) . '</td>';
        $html .= '<td>' . $count . '</td>';
        $html .= '<td>' . $percentage . '%</td>';
        $html .= '</tr>';
    }
    
    $html .= '<tr style="background-color: #e8f4fd; font-weight: bold;">';
    $html .= '<td>الإجمالي</td>';
    $html .= '<td>' . $totalTrainees . '</td>';
    $html .= '<td>100%</td>';
    $html .= '</tr>';
    
    $html .= '</table>';
}

// إضافة تذييل للتقرير
$html .= '<div class="footer">';
$html .= '<p>تم إنشاء هذا التقرير بواسطة نظام إدارة المتدربين بتاريخ ' . date('d/m/Y H:i') . '</p>';
$html .= '<p>© ' . date('Y') . ' جميع الحقوق محفوظة</p>';
$html .= '</div>';

$html .= '</body></html>';

try {
    // إضافة الأنماط وكتابة المحتوى
    $mpdf->WriteHTML($stylesheet, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
    
    // تعيين headers التحميل
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="financial_report_' . date('Y-m-d_H-i') . '.pdf"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // إخراج PDF مباشرة للمتصفح
    $mpdf->Output('financial_report_' . date('Y-m-d') . '.pdf', 'I');
    
} catch (Exception $e) {
    error_log('خطأ في إنشاء ملف PDF: ' . $e->getMessage());
    
    // تنظيف المخرجات قبل إظهار الخطأ
    if (ob_get_length()) ob_clean();
    
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="text-align: center; font-family: Arial, sans-serif; direction: rtl; margin-top: 20px;">';
    echo '<h2>عذراً، حدث خطأ أثناء إنشاء ملف PDF</h2>';
    echo '<p>يرجى المحاولة مرة أخرى لاحقاً أو الاتصال بمسؤول النظام.</p>';
    echo '<p style="color: red; font-size: 12px;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}

exit;
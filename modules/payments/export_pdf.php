<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/Payment.php';
require_once '../../vendor/autoload.php';

checkPermission('employee');
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

// حساب الإجماليات
$totalAmount = array_sum(array_column($report, 'total_amount'));
$totalCount = array_sum(array_column($report, 'payment_count'));

// إنشاء مستند PDF جديد
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'ar-SA',
    'format' => 'A4',
    'default_font' => 'XB Riyaz',
    'default_font_size' => 12,
    'margin_left' => 15,
    'margin_right' => 15,
    'margin_top' => 15,
    'margin_bottom' => 15,
    'margin_header' => 10,
    'margin_footer' => 10,
    'orientation' => 'P',
    'tempDir' => __DIR__ . '/../../temp/mpdf'
]);

// تعيين خصائص المستند
$mpdf->SetDirectionality('rtl');
$mpdf->SetTitle('تقرير المدفوعات');
$mpdf->SetAuthor('نظام إدارة المتدربين');

// إضافة محتوى HTML
$html = '
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<style>
    * { font-family: "XB Riyaz", Arial, sans-serif; }
    body { direction: rtl; }
    table { width: 100%; border-collapse: collapse; margin: 1em 0; }
    th, td { border: 1px solid #000; padding: 8px; text-align: right; }
    th { background-color: #f4f4f4; }
    .total { font-size: 1.1em; margin: 1em 0; line-height: 1.5; }
    h1 { text-align: center; margin-bottom: 1em; }
</style>
<h1 style="text-align: center;">تقرير المدفوعات</h1>
<div class="total">
    الفترة: ' . date('Y/m/d', strtotime($startDate)) . ' إلى ' . date('Y/m/d', strtotime($endDate)) . '<br>
    إجمالي المدفوعات: ' . number_format($totalAmount, 2) . ' ريال<br>
    عدد المدفوعات: ' . number_format($totalCount) . '<br>
    متوسط المدفوعات: ' . ($totalCount > 0 ? number_format($totalAmount / $totalCount, 2) : 0) . ' ريال
</div>
<table dir="rtl">
    <thead>
        <tr>
            <th>الفترة</th>
            <th>عدد المدفوعات</th>
            <th>إجمالي المبلغ</th>
            <th>متوسط المدفوعة</th>
        </tr>
    </thead>
    <tbody>';

foreach ($report as $row) {
    $html .= '<tr>
        <td>' . date('Y/m/d', strtotime($row['period'])) . '</td>
        <td>' . number_format($row['payment_count']) . '</td>
        <td>' . number_format($row['total_amount'], 2) . ' ريال</td>
        <td>' . (isset($row['payment_count']) && $row['payment_count'] > 0 ? number_format($row['total_amount'] / $row['payment_count'], 2) : '0.00') . ' ريال</td>
    </tr>';
}

$html .= '
    </tbody>
</table>
</body>
</html>';

try {
    // التأكد من عدم وجود أي مخرجات سابقة
    if (ob_get_length()) ob_clean();
    
    // إضافة المحتوى إلى PDF
    $mpdf->WriteHTML($html);

    // تعيين اسم الملف وإرساله للتحميل
    $filename = 'تقرير_المدفوعات_' . date('Y-m-d_H-i-s') . '.pdf';
    
    // التأكد من أن الملف يحتوي على محتوى
    if ($mpdf->pages[1]) {
        $mpdf->Output($filename, 'D');
    } else {
        throw new Exception('لم يتم إنشاء محتوى PDF');
    }
} catch (Exception $e) {
    // سجل الخطأ وأظهر رسالة مناسبة للمستخدم
    error_log('خطأ في إنشاء PDF: ' . $e->getMessage());
    header('Content-Type: text/html; charset=utf-8');
    echo 'عذراً، حدث خطأ أثناء إنشاء ملف PDF. يرجى المحاولة مرة أخرى.';
}
exit;
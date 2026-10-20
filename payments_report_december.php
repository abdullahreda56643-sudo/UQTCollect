<?php
/**
 * سكريبت عرض جميع المدفوعات من أول شهر ديسمبر
 * يقوم بعرض تقرير مفصل بجميع المدفوعات التي تمت في شهر ديسمبر
 */

require_once "config.php";
require_once "db/connection.php";

header("Content-Type: text/html; charset=utf-8");
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; direction: rtl; background: #f5f5f5; }
.header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
.summary-cards { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
.card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); flex: 1; min-width: 200px; text-align: center; }
.card h3 { margin: 0 0 10px 0; color: #333; }
.card .amount { font-size: 24px; font-weight: bold; color: #2c3e50; }
.card.total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
.card.total .amount { color: white; }
.table-container { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px; }
.search-box { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
.search-box input, .search-box select { padding: 10px; margin: 5px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
.search-box button { padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer; }
.search-box button:hover { background: #2980b9; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 12px; text-align: right; border-bottom: 1px solid #eee; }
th { background: #f8f9fa; font-weight: bold; color: #333; }
tr:hover { background: #f8f9fa; }
.amount-cell { font-weight: bold; color: #27ae60; }
.method-cash { color: #27ae60; }
.method-bank { color: #3498db; }
.method-card { color: #9b59b6; }
.method-check { color: #e67e22; }
.export-btn { padding: 10px 20px; background: #27ae60; color: white; text-decoration: none; border-radius: 5px; margin: 5px; display: inline-block; }
.export-btn:hover { background: #229954; }
.filters { margin-bottom: 20px; }
.date-range { display: flex; gap: 10px; align-items: center; margin-bottom: 15px; }
.pagination { text-align: center; margin: 20px 0; }
.pagination a { padding: 8px 16px; margin: 0 5px; background: #3498db; color: white; text-decoration: none; border-radius: 3px; }
.pagination a:hover { background: #2980b9; }
.pagination .current { background: #2c3e50; }
</style>";

try {
    $db = (new Database())->getConnection();
    
    echo "<div class=\"header\">
        <h1>تقرير المدفوعات - شهر ديسمبر</h1>
        <p>عرض جميع المدفوعات التي تمت من أول ديسمبر 2025</p>
    </div>";
    
    // الحصول على معايير البحث
    $search_name = isset($_GET['search_name']) ? $_GET['search_name'] : '';
    $payment_method = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '2025-12-01';
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
    
    // الاستعلام الأساسي
    $base_query = "
        SELECT 
            p.id,
            p.amount,
            p.payment_date,
            p.payment_method,
            p.receipt_number,
            p.notes,
            p.created_at,
            t.id as trainee_id,
            t.full_name,
            t.phone,
            d.name as diploma_name,
            b.name as branch_name,
            u.full_name as created_by_name
        FROM payments p
        LEFT JOIN trainees t ON p.trainee_id = t.id
        LEFT JOIN diplomas d ON t.diploma_id = d.id
        LEFT JOIN branches b ON t.branch_id = b.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.payment_date BETWEEN ? AND ?
    ";
    
    $params = [$date_from, $date_to];
    
    // إضافة معايير البحث
    if (!empty($search_name)) {
        $base_query .= " AND t.full_name LIKE ?";
        $params[] = "%$search_name%";
    }
    
    if (!empty($payment_method)) {
        $base_query .= " AND p.payment_method = ?";
        $params[] = $payment_method;
    }
    
    $base_query .= " ORDER BY p.payment_date DESC, p.created_at DESC";
    
    $stmt = $db->prepare($base_query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // حساب الإحصائيات
    $total_amount = array_sum(array_column($payments, 'amount'));
    $total_payments = count($payments);
    $payment_methods = array_count_values(array_column($payments, 'payment_method'));
    
    echo "<div class=\"summary-cards\">";
    echo "<div class=\"card total\">";
    echo "<h3>إجمالي المدفوعات</h3>";
    echo "<div class=\"amount\">" . number_format($total_amount, 2) . " ر.س</div>";
    echo "<small>" . $total_payments . " دفعة</small>";
    echo "</div>";
    
    echo "<div class=\"card\">";
    echo "<h3>نقداً</h3>";
    echo "<div class=\"amount method-cash\">" . number_format($payment_methods['cash'] ?? 0) . "</div>";
    echo "<small>دفعة</small>";
    echo "</div>";
    
    echo "<div class=\"card\">";
    echo "<h3>تحويل بنكي</h3>";
    echo "<div class=\"amount method-bank\">" . number_format($payment_methods['bank_transfer'] ?? 0) . "</div>";
    echo "<small>دفعة</small>";
    echo "</div>";
    
    echo "<div class=\"card\">";
    echo "<h3>بطاقة ائتمان</h3>";
    echo "<div class=\"amount method-card\">" . number_format($payment_methods['credit_card'] ?? 0) . "</div>";
    echo "<small>دفعة</small>";
    echo "</div>";
    
    echo "<div class=\"card\">";
    echo "<h3>شيك</h3>";
    echo "<div class=\"amount method-check\">" . number_format($payment_methods['check'] ?? 0) . "</div>";
    echo "<small>دفعة</small>";
    echo "</div>";
    echo "</div>";
    
    // صندوق البحث
    echo "<div class=\"search-box\">";
    echo "<h3>البحث والتصفية</h3>";
    echo "<form method=\"GET\" style=\"display: flex; flex-wrap: wrap; gap: 10px; align-items: end;\">";
    
    echo "<div>";
    echo "<label>اسم المتدرب:</label><br>";
    echo "<input type=\"text\" name=\"search_name\" value=\"" . htmlspecialchars($search_name) . "\" placeholder=\"ابحث باسم المتدرب\">";
    echo "</div>";
    
    echo "<div>";
    echo "<label>طريقة الدفع:</label><br>";
    echo "<select name=\"payment_method\">";
    echo "<option value=\"\">الكل</option>";
    echo "<option value=\"cash\" " . ($payment_method == 'cash' ? 'selected' : '') . ">نقداً</option>";
    echo "<option value=\"bank_transfer\" " . ($payment_method == 'bank_transfer' ? 'selected' : '') . ">تحويل بنكي</option>";
    echo "<option value=\"credit_card\" " . ($payment_method == 'credit_card' ? 'selected' : '') . ">بطاقة ائتمان</option>";
    echo "<option value=\"check\" " . ($payment_method == 'check' ? 'selected' : '') . ">شيك</option>";
    echo "</select>";
    echo "</div>";
    
    echo "<div>";
    echo "<label>من تاريخ:</label><br>";
    echo "<input type=\"date\" name=\"date_from\" value=\"" . htmlspecialchars($date_from) . "\">";
    echo "</div>";
    
    echo "<div>";
    echo "<label>إلى تاريخ:</label><br>";
    echo "<input type=\"date\" name=\"date_to\" value=\"" . htmlspecialchars($date_to) . "\">";
    echo "</div>";
    
    echo "<div>";
    echo "<button type=\"submit\">بحث</button>";
    echo "</div>";
    
    echo "</form>";
    echo "</div>";
    
    // أزرار التصدير
    echo "<div style=\"text-align: center; margin-bottom: 20px;\">";
    echo "<a href=\"?export=excel&date_from=" . urlencode($date_from) . "&date_to=" . urlencode($date_to) . "\" class=\"export-btn\">تصدير إلى Excel</a>";
    echo "<a href=\"?export=pdf&date_from=" . urlencode($date_from) . "&date_to=" . urlencode($date_to) . "\" class=\"export-btn\">تصدير إلى PDF</a>";
    echo "</div>";
    
    // جدول المدفوعات
    echo "<div class=\"table-container\">";
    echo "<table>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>التاريخ</th>";
    echo "<th>اسم المتدرب</th>";
    echo "<th>رقم الهوية</th>";
    echo "<th>الهاتف</th>";
    echo "<th>المبلغ</th>";
    echo "<th>طريقة الدفع</th>";
    echo "<th>رقم الإيصال</th>";
    echo "<th>الدبلوم</th>";
    echo "<th>الفرع</th>";
    echo "<th>مسجل بواسطة</th>";
    echo "<th>ملاحظات</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($payments as $payment) {
        $method_class = "method-" . str_replace('_', '-', $payment['payment_method']);
        $method_text = '';
        
        switch($payment['payment_method']) {
            case 'cash': $method_text = 'نقداً'; break;
            case 'bank_transfer': $method_text = 'تحويل بنكي'; break;
            case 'credit_card': $method_text = 'بطاقة ائتمان'; break;
            case 'check': $method_text = 'شيك'; break;
            default: $method_text = $payment['payment_method'];
        }
        
        echo "<tr>";
        echo "<td>" . date('Y-m-d', strtotime($payment['payment_date'])) . "</td>";
        echo "<td><strong>" . htmlspecialchars($payment['full_name']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($payment['national_id'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($payment['phone'] ?? '-') . "</td>";
        echo "<td class=\"amount-cell\">" . number_format($payment['amount'], 2) . " ر.س</td>";
        echo "<td class=\"$method_class\">" . $method_text . "</td>";
        echo "<td>" . htmlspecialchars($payment['receipt_number'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($payment['diploma_name'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($payment['branch_name'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($payment['created_by_name'] ?? '-') . "</td>";
        echo "<td>" . htmlspecialchars($payment['notes'] ?? '-') . "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    echo "</div>";
    
    // ملخص إضافي
    echo "<div class=\"table-container\">";
    echo "<h3>ملخص حسب طريقة الدفع</h3>";
    echo "<table>";
    echo "<thead>";
    echo "<tr><th>طريقة الدفع</th><th>عدد الدفعات</th><th>المبلغ الإجمالي</th><th>النسبة المئوية</th></tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($payment_methods as $method => $count) {
        $method_total = 0;
        foreach ($payments as $payment) {
            if ($payment['payment_method'] == $method) {
                $method_total += $payment['amount'];
            }
        }
        
        $percentage = $total_amount > 0 ? ($method_total / $total_amount) * 100 : 0;
        
        $method_text = '';
        switch($method) {
            case 'cash': $method_text = 'نقداً'; break;
            case 'bank_transfer': $method_text = 'تحويل بنكي'; break;
            case 'credit_card': $method_text = 'بطاقة ائتمان'; break;
            case 'check': $method_text = 'شيك'; break;
            default: $method_text = $method;
        }
        
        echo "<tr>";
        echo "<td class=\"$method_class\">" . $method_text . "</td>";
        echo "<td>" . $count . "</td>";
        echo "<td class=\"amount-cell\">" . number_format($method_total, 2) . " ر.س</td>";
        echo "<td>" . number_format($percentage, 1) . "%</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style=\"color: red; font-weight: bold; text-align: center; padding: 50px;\">";
    echo "<h2>خطأ في تنفيذ التقرير</h2>";
    echo "<p>الخطأ: " . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<div style=\"text-align: center; margin: 50px 0; color: #666;\">";
echo "<p>تقرير المدفوعات - شهر ديسمبر 2025</p>";
echo "<p><small>نظام إدارة المتدربين والتحصيل المالي - الإصدار 2.0.0</small></p>";
echo "</div>";
?>

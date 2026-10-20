<?php
/**
 * سكريبت كشف المبالغ الفعلية التي حصلها الموظفون اليوم
 * يقوم بمقارنة المبالغ المحصلة الفعلية مع المبالغ المسجلة في حسابات الموظفين
 * ويشخص أسباب التناقضات
 */

require_once "config.php";
require_once "db/connection.php";

header("Content-Type: text/html; charset=utf-8");
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; direction: rtl; }
.header { background: #2c3e50; color: white; padding: 20px; text-align: center; }
.summary { background: #ecf0f1; padding: 15px; margin: 20px 0; border-radius: 5px; }
.employee-card { border: 1px solid #ddd; margin: 15px 0; padding: 15px; border-radius: 5px; }
.correct { border-left: 5px solid #27ae60; background: #f8fff8; }
.incorrect { border-left: 5px solid #e74c3c; background: #fff8f8; }
.warning { border-left: 5px solid #f39c12; background: #fffaf0; }
.amount { font-weight: bold; font-size: 18px; }
.status-correct { color: #27ae60; }
.status-incorrect { color: #e74c3c; }
.status-warning { color: #f39c12; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: right; border: 1px solid #ddd; }
th { background: #f2f2f2; }
.problem-diagnosis { background: #f8f9fa; padding: 10px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #17a2b8; }
.problem-title { font-weight: bold; color: #17a2b8; margin-bottom: 5px; }
.problem-detail { margin: 3px 0; font-size: 14px; }
.btn { padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 3px; margin: 5px; }
.btn-danger { background: #e74c3c; }
.btn-success { background: #27ae60; }
.alert { padding: 15px; margin: 20px 0; border-radius: 5px; }
.alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
.alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
</style>";

try {
    $db = (new Database())->getConnection();
    
    echo "<div class=\"header\">
        <h1>سكريبت كشف المبالغ الفعلية للموظفين اليوم</h1>
        <p>تحليل ومقارنة المبالغ المحصلة الفعلية اليوم مع المبالغ المسجلة في حسابات الموظفين</p>
        <p>تاريخ اليوم: " . date('Y-m-d') . "</p>
    </div>";
    
    // التحقق من وجود جدول سجلات التحصيل الموظف
    $check_table = $db->query("SHOW TABLES LIKE 'employee_collections'");
    if ($check_table->rowCount() == 0) {
        echo "<div class=\"alert alert-danger\">
            <h2>جدول سجلات التحصيل غير موجود</h2>
            <p>لا يوجد جدول 'employee_collections' لتسجيل المبالغ المحصلة من الموظفين.</p>
            <p>سيتم إنشاء تقرير بناءً على جدول الدفعات فقط.</p>
        </div>";
        $use_collections_table = false;
    } else {
        $use_collections_table = true;
    }
    
    // الاستعلام الرئيسي للحصول على بيانات الموظفين والمبالغ المحصلة اليوم
    if ($use_collections_table) {
        $query = "
            SELECT 
                u.id,
                u.username,
                u.full_name,
                u.role,
                COALESCE(ec.recorded_amount, 0) as recorded_collections,
                COALESCE(p.total_payments, 0) as actual_payments,
                COALESCE(p.payment_count, 0) as payment_count,
                ec.collection_date,
                ec.notes as collection_notes
            FROM users u
            LEFT JOIN employee_collections ec ON u.id = ec.employee_id AND ec.collection_date = CURDATE()
            LEFT JOIN (
                SELECT 
                    created_by,
                    SUM(amount) as total_payments,
                    COUNT(id) as payment_count
                FROM payments 
                WHERE DATE(payment_date) = CURDATE()
                GROUP BY created_by
            ) p ON u.id = p.created_by
            WHERE u.is_active = 1 AND u.role IN ('employee', 'supervisor')
            ORDER BY u.full_name
        ";
    } else {
        $query = "
            SELECT 
                u.id,
                u.username,
                u.full_name,
                u.role,
                0 as recorded_collections,
                COALESCE(p.total_payments, 0) as actual_payments,
                COALESCE(p.payment_count, 0) as payment_count,
                NULL as collection_date,
                NULL as collection_notes
            FROM users u
            LEFT JOIN (
                SELECT 
                    created_by,
                    SUM(amount) as total_payments,
                    COUNT(id) as payment_count
                FROM payments 
                WHERE DATE(payment_date) = CURDATE()
                GROUP BY created_by
            ) p ON u.id = p.created_by
            WHERE u.is_active = 1 AND u.role IN ('employee', 'supervisor')
            ORDER BY u.full_name
        ";
    }
    
    $stmt = $db->query($query);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $correct_count = 0;
    $incorrect_count = 0;
    $warning_count = 0;
    $total_discrepancy = 0;
    $total_actual_collections = 0;
    $total_recorded_collections = 0;
    
    echo "<h2>تحليل تفصيلي للموظفين</h2>";
    
    foreach ($employees as $employee) {
        $discrepancy = $employee["recorded_collections"] - $employee["actual_payments"];
        
        // تحليل مصدر المشكلة
        $problem_source = "";
        $problem_details = [];
        
        if ($employee["payment_count"] == 0 && $employee["recorded_collections"] > 0) {
            $problem_source = "لا توجد دفعات مسجلة اليوم";
            $problem_details[] = "المبلغ المسجل في سجلات التحصيل: " . number_format($employee["recorded_collections"], 2) . " ر.س";
            $problem_details[] = "عدد الدفعات الفعلية اليوم: 0";
            $problem_details[] = "السبب المحتمل: تم إدخال المبلغ يدوياً في سجلات التحصيل بدون إضافة دفعات فعلية";
            $problem_details[] = "الحل: إضافة دفعات فعلية في جدول payments أو تصفير سجل التحصيل";
        } elseif (abs($discrepancy) > 0.01) {
            $problem_source = "تناقض في المبالغ";
            $problem_details[] = "المبلغ المسجل في سجلات التحصيل: " . number_format($employee["recorded_collections"], 2) . " ر.س";
            $problem_details[] = "المبلغ الفعلي من الدفعات: " . number_format($employee["actual_payments"], 2) . " ر.س";
            $problem_details[] = "الفرق: " . number_format($discrepancy, 2) . " ر.س";
            $problem_details[] = "عدد الدفعات الفعلية: " . $employee["payment_count"];
            
            if ($employee["actual_payments"] > 0) {
                $problem_details[] = "السبب المحتمل: خطأ في إدخال المبلغ في سجلات التحصيل";
            } else {
                $problem_details[] = "السبب المحتمل: تم إدخال المبلغ في سجلات التحصيل فقط بدون إضافة دفعات";
            }
        }
        
        // تحديد حالة الموظف
        $status_class = "";
        $status_text = "";
        
        if (abs($discrepancy) < 0.01 && $employee["actual_payments"] > 0) {
            $status_class = "correct";
            $status_text = "بيانات صحيحة";
            $correct_count++;
        } elseif ($employee["payment_count"] == 0 && $employee["recorded_collections"] > 0) {
            $status_class = "warning";
            $status_text = "لا توجد دفعات فعلية مسجلة اليوم";
            $warning_count++;
        } elseif ($employee["actual_payments"] == 0 && $employee["recorded_collections"] == 0) {
            $status_class = "correct";
            $status_text = "لا توجد تحصيلات اليوم";
            $correct_count++;
        } else {
            $status_class = "incorrect";
            $status_text = "تناقض في البيانات";
            $incorrect_count++;
            $total_discrepancy += abs($discrepancy);
        }
        
        $total_actual_collections += $employee["actual_payments"];
        $total_recorded_collections += $employee["recorded_collections"];
        
        echo "<div class=\"employee-card {$status_class}\">";
        echo "<h3>" . htmlspecialchars($employee["full_name"]) . " (" . htmlspecialchars($employee["username"]) . ")</h3>";
        echo "<p><strong>الوظيفة:</strong> " . htmlspecialchars($employee["role"]) . "</p>";
        echo "<p><strong>الحالة:</strong> <span class=\"status-{$status_class}\">" . $status_text . "</span></p>";
        
        echo "<table>";
        echo "<tr><th>المبلغ الفعلي المحصل اليوم</th><td class=\"amount\">" . number_format($employee["actual_payments"], 2) . " ر.س</td></tr>";
        echo "<tr><th>عدد الدفعات الفعلية</th><td>" . $employee["payment_count"] . " دفعة</td></tr>";
        
        if ($use_collections_table) {
            echo "<tr><th>المبلغ المسجل في سجلات التحصيل</th><td class=\"amount\">" . number_format($employee["recorded_collections"], 2) . " ر.س</td></tr>";
            echo "<tr><th>الفرق</th><td class=\"amount " . ($discrepancy >= 0 ? "status-incorrect" : "status-correct") . "\">" . number_format($discrepancy, 2) . " ر.س</td></tr>";
            if ($employee["collection_notes"]) {
                echo "<tr><th>ملاحظات السجل</th><td>" . htmlspecialchars($employee["collection_notes"]) . "</td></tr>";
            }
        } else {
            echo "<tr><th>ملاحظة</th><td>لا يوجد جدول سجلات تحصيل - البيانات من جدول الدفعات فقط</td></tr>";
        }
        
        echo "</table>";
        
        // عرض تفاصيل الدفعات الفعلية
        if ($employee["payment_count"] > 0) {
            $payments_query = "
                SELECT 
                    p.id,
                    p.amount,
                    p.payment_date,
                    p.payment_method,
                    p.receipt_number,
                    t.full_name as trainee_name,
                    t.id as trainee_id
                FROM payments p
                JOIN trainees t ON p.trainee_id = t.id
                WHERE p.created_by = ? AND DATE(p.payment_date) = CURDATE()
                ORDER BY p.payment_date DESC
            ";
            
            $payments_stmt = $db->prepare($payments_query);
            $payments_stmt->execute([$employee["id"]]);
            $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<h4>تفاصيل الدفعات الفعلية اليوم:</h4>";
            echo "<table>";
            echo "<tr><th>رقم الدفعة</th><th>المتدرب</th><th>المبلغ</th><th>طريقة الدفع</th><th>رقم الإيصال</th></tr>";
            
            foreach ($payments as $payment) {
                echo "<tr>";
                echo "<td>" . $payment["id"] . "</td>";
                echo "<td>" . htmlspecialchars($payment["trainee_name"]) . " (" . $payment["trainee_id"] . ")</td>";
                echo "<td class=\"amount\">" . number_format($payment["amount"], 2) . " ر.س</td>";
                echo "<td>" . htmlspecialchars($payment["payment_method"]) . "</td>";
                echo "<td>" . htmlspecialchars($payment["receipt_number"] ?: 'غير محدد') . "</td>";
                echo "</tr>";
            }
            
            echo "</table>";
        }
        
        // عرض تشخيص المشكلة
        if (!empty($problem_source)) {
            echo "<div class=\"problem-diagnosis\">";
            echo "<div class=\"problem-title\">تشخيص المشكلة: " . htmlspecialchars($problem_source) . "</div>";
            foreach ($problem_details as $detail) {
                echo "<div class=\"problem-detail\">• " . $detail . "</div>";
            }
            echo "</div>";
        }
        
        echo "</div>";
    }
    
    // ملخص النتائج
    echo "<div class=\"summary\">
        <h2>ملخص نتائج اليوم</h2>
        <table>
            <tr><th>إجمالي الموظفين</th><td>" . count($employees) . " موظف</td></tr>
            <tr><th>البيانات الصحيحة</th><td class=\"status-correct\">" . $correct_count . " موظف</td></tr>
            <tr><th>البيانات الخاطئة</th><td class=\"status-incorrect\">" . $incorrect_count . " موظف</td></tr>
            <tr><th>تحذيرات</th><td class=\"status-warning\">" . $warning_count . " موظف</td></tr>";
    
    echo "<tr><th>إجمالي المبالغ الفعلية المحصلة اليوم</th><td class=\"amount\">" . number_format($total_actual_collections, 2) . " ر.س</td></tr>";
    
    if ($use_collections_table) {
        echo "<tr><th>إجمالي المبالغ المسجلة في سجلات التحصيل</th><td class=\"amount\">" . number_format($total_recorded_collections, 2) . " ر.س</td></tr>";
        $overall_discrepancy = $total_recorded_collections - $total_actual_collections;
        echo "<tr><th>الفرق الإجمالي</th><td class=\"amount " . ($overall_discrepancy >= 0 ? "status-incorrect" : "status-correct") . "\">" . number_format($overall_discrepancy, 2) . " ر.س</td></tr>";
    }
    
    if ($total_discrepancy > 0) {
        echo "<tr><th>إجمالي الفرق في البيانات الخاطئة</th><td class=\"amount status-incorrect\">" . number_format($total_discrepancy, 2) . " ر.س</td></tr>";
    }
    
    echo "</table>
    </div>";
    
    // تحليل المشاكل وتوصيات الحل
    echo "<div class=\"summary\">
        <h2>تحليل المشاكل وتوصيات الحل</h2>";
    
    if ($incorrect_count > 0 || $warning_count > 0) {
        echo "<div class=\"alert alert-danger\">";
        echo "<h3>مشاكل تم اكتشافها:</h3>";
        echo "<ul>";
        if ($warning_count > 0) {
            echo "<li>يوجد " . $warning_count . " موظف لديهم سجلات تحصيل但没有 دفعات فعلية</li>";
        }
        if ($incorrect_count > 0) {
            echo "<li>يوجد " . $incorrect_count . " موظف لديهم تناقض في المبالغ</li>";
            echo "<li>إجمالي الفرق: " . number_format($total_discrepancy, 2) . " ر.س</li>";
        }
        echo "</ul>";
        echo "</div>";
        
        echo "<h3>أسباب المشاكل المحتملة:</h3>";
        echo "<ul>";
        echo "<li><strong>إدخال يدوي خاطئ:</strong> تم إدخال المبالغ في سجلات التحصيل يدوياً بدون إضافة دفعات فعلية</li>";
        echo "<li><strong>خطأ في الإدخال:</strong> تم إدخال مبلغ خاطئ في سجلات التحصيل</li>";
        echo "<li><strong>نقص في السجلات:</strong> تمت إضافة دفعات فعلية但没有 تسجيلها في سجلات التحصيل</li>";
        echo "<li><strong>مشكلة في النظام:</strong> قد يكون هناك خطأ في آلية تسجيل البيانات</li>";
        echo "</ul>";
        
        echo "<h3>توصيات الحل:</h3>";
        echo "<ul>";
        echo "<li>مراجعة جميع الدفعات الفعلية اليوم ومقارنتها بسجلات التحصيل</li>";
        echo "<li>تصحيح سجلات التحصيل لتطابق الدفعات الفعلية</li>";
        echo "<li>تطبيق آلية تحقق تلقائية عند إدخال البيانات</li>";
        echo "<li>تدريب الموظفين على الإدخال الصحيح للبيانات</li>";
        echo "</ul>";
        
    } else {
        echo "<div class=\"alert alert-success\">";
        echo "<h3>جميع البيانات صحيحة!</h3>";
        echo "<p>لا توجد تناقضات في المبالغ المحصلة اليوم.</p>";
        echo "</div>";
    }
    
    echo "</div>";
    
    // زر لتشغيل السكريبت مرة أخرى
    echo "<div style=\"text-align: center; margin: 20px 0;\">";
    echo "<a href=\"employee_daily_collections.php\" class=\"btn btn-success\">تحديث البيانات</a>";
    echo "<a href=\"index.php\" class=\"btn\">العودة للرئيسية</a>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class=\"alert alert-danger\">";
    echo "<h2>خطأ في التنفيذ</h2>";
    echo "<p>الخطأ: " . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<div style=\"text-align: center; margin: 50px 0; color: #666;\">";
echo "<p>تم إنشاء هذا السكريبت لكشف المبالغ الفعلية المحصلة من الموظفين اليوم</p>";
echo "<p><small>نظام إدارة المتدربين والتحصيل المالي - الإصدار 2.0.0</small></p>";
echo "</div>";
?>

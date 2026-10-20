<?php
// =============================================================================
// سكريبت تحديث المبالغ المتبقية للمتدربين - نسخة cPanel
// للاستخدام في بيئة الإنتاج
// =============================================================================

// إعدادات إضافية
define('DISPLAY_ERRORS', true);          // عرض الأخطاء (false في الإنتاج)
define('LOG_ERRORS', true);               // تسجيل الأخطاء

// =============================================================================
// لا تقم بتعديل أي شيء تحت هذا السطر
// =============================================================================

// إعداد عرض الأخطاء
if (DISPLAY_ERRORS) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// دالة الاتصال بقاعدة البيانات
function connectDB() {
    try {
        // استخدام ملفات الكونفيج الموجودة في النظام
        require_once 'config.php';
        require_once 'db/connection.php';
        
        $database = new Database();
        return $database->getConnection();
    } catch (PDOException $e) {
        die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
    }
}

// دالة تسجيل الأخطاء
function logError($message) {
    if (LOG_ERRORS) {
        $log_file = 'update_log_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    }
}

// البيانات المحدثة - 54 متدرب
$updates = [
    // بشرى
    '1082599869' => ['current' => 8400, 'new' => 4000, 'employee' => 'بشرى'],
    '1084040078' => ['current' => 8400, 'new' => 4700, 'employee' => 'بشرى'],
    '1106458803' => ['current' => 9200, 'new' => 8700, 'employee' => 'بشرى'],
    '1093454591' => ['current' => 8400, 'new' => 6400, 'employee' => 'بشرى'],
    '1076904141' => ['current' => 9200, 'new' => 8200, 'employee' => 'بشرى'],
    '1097422529' => ['current' => 9200, 'new' => 6700, 'employee' => 'بشرى'],
    '1074624089' => ['current' => 8449, 'new' => 3049, 'employee' => 'بشرى'],
    '1056489568' => ['current' => 8400, 'new' => 6800, 'employee' => 'بشرى'],
    '1112600430' => ['current' => 8400, 'new' => 4400, 'employee' => 'بشرى'],
    '1119878963' => ['current' => 7100, 'new' => 6600, 'employee' => 'بشرى'],
    '1071879546' => ['current' => 8000, 'new' => 7600, 'employee' => 'بشرى'],
    '1106508839' => ['current' => 3200, 'new' => 1600, 'employee' => 'بشرى'],
    '1128606439' => ['current' => 2400, 'new' => 2000, 'employee' => 'بشرى'],
    '1055609067' => ['current' => 1200, 'new' => 400, 'employee' => 'بشرى'], // لديه خصم 400 من ا.علي
    '1075244432' => ['current' => 6400, 'new' => 3400, 'employee' => 'بشرى'],
    '1061904957' => ['current' => 1590, 'new' => 590, 'employee' => 'بشرى'],
    '1132257047' => ['current' => 740, 'new' => 0, 'employee' => 'بشرى'], // المتعثر قبل السداد كان 7400 وتم سداده بالكامل
    '1001268869' => ['current' => 2000, 'new' => 1000, 'employee' => 'بشرى'],
    '1105349383' => ['current' => 1950, 'new' => 1560, 'employee' => 'بشرى'],
    
    // شهد
    '1071304669' => ['current' => 8449, 'new' => 5019, 'employee' => 'شهد'],
    '1061118459' => ['current' => 8400, 'new' => 6400, 'employee' => 'شهد'],
    '1109053478' => ['current' => 8174, 'new' => 5474, 'employee' => 'شهد'],
    '1086644216' => ['current' => 9200, 'new' => 6700, 'employee' => 'شهد'],
    '1069283214' => ['current' => 9600, 'new' => 9200, 'employee' => 'شهد'],
    '1105207185' => ['current' => 7600, 'new' => 7100, 'employee' => 'شهد'],
    '1096511777' => ['current' => 8400, 'new' => 7900, 'employee' => 'شهد'],
    '1058274018' => ['current' => 8000, 'new' => 2200, 'employee' => 'شهد'],
    '1114828757' => ['current' => 8400, 'new' => 8000, 'employee' => 'شهد'],
    '1047685613' => ['current' => 8400, 'new' => 7600, 'employee' => 'شهد'],
    
    // ريم
    '1094063383' => ['current' => 5680, 'new' => 3120, 'employee' => 'ريم'],
    '1070637556' => ['current' => 6647, 'new' => 0, 'employee' => 'ريم'], // فيه خطأ بالحساب
    '1091165199' => ['current' => 8400, 'new' => 8000, 'employee' => 'ريم'],
    '1087541643' => ['current' => 8400, 'new' => 5200, 'employee' => 'ريم'],
    '2150496517' => ['current' => 8490, 'new' => 7500, 'employee' => 'ريم'],
    '1081617860' => ['current' => 8400, 'new' => 5200, 'employee' => 'ريم'],
    '1050213519' => ['current' => 7900, 'new' => 4900, 'employee' => 'ريم'],
    '2470313673' => ['current' => 8400, 'new' => 8000, 'employee' => 'ريم'],
    '1057866301' => ['current' => 8400, 'new' => 5200, 'employee' => 'ريم'],
    
    // رهف
    '1073985200' => ['current' => 8400, 'new' => 1600, 'employee' => 'رهف'],
    '1109739506' => ['current' => 7600, 'new' => 7100, 'employee' => 'رهف'],
    '1086137401' => ['current' => 8490, 'new' => 2560, 'employee' => 'رهف'],
    '1098811191' => ['current' => 8400, 'new' => 6000, 'employee' => 'رهف'],
    '1105926883' => ['current' => 7820, 'new' => 1955, 'employee' => 'رهف'],
    '1083157055' => ['current' => 8400, 'new' => 8000, 'employee' => 'رهف'],
    '1081982629' => ['current' => 8000, 'new' => 6800, 'employee' => 'رهف'],
    '1102244462' => ['current' => 7820, 'new' => 4756, 'employee' => 'رهف'],
    '1071966426' => ['current' => 9200, 'new' => 8700, 'employee' => 'رهف'],
    '1089937104' => ['current' => 9000, 'new' => 6500, 'employee' => 'رهف'],
    '1077146213' => ['current' => 8400, 'new' => 5600, 'employee' => 'رهف'],
    '1070763501' => ['current' => 8490, 'new' => 8000, 'employee' => 'رهف'],
    '1042780724' => ['current' => 8400, 'new' => 7200, 'employee' => 'رهف'],
    '1065599779' => ['current' => 8400, 'new' => 1800, 'employee' => 'رهف'],
    '1107688523' => ['current' => 7820, 'new' => 5866, 'employee' => 'رهف'],
];

// =============================================================================
// تنفيذ التحديث
// =============================================================================

echo "<!DOCTYPE html>\n";
echo "<html dir='rtl' lang='ar'>\n";
echo "<head>\n";
echo "<meta charset='UTF-8'>\n";
echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
echo "<title>تحديث المبالغ المتبقية للمتدربين</title>\n";
echo "<style>\n";
echo "body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background: #f5f5f5; }\n";
echo ".container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }\n";
echo ".header { text-align: center; margin-bottom: 30px; color: #2c3e50; }\n";
echo ".success { color: #27ae60; font-weight: bold; }\n";
echo ".error { color: #e74c3c; font-weight: bold; }\n";
echo ".warning { color: #f39c12; font-weight: bold; }\n";
echo ".info { color: #3498db; }\n";
echo "table { width: 100%; border-collapse: collapse; margin: 20px 0; }\n";
echo "th, td { padding: 12px; text-align: right; border: 1px solid #ddd; }\n";
echo "th { background: #3498db; color: white; }\n";
echo ".summary { background: #ecf0f1; padding: 20px; border-radius: 5px; margin: 20px 0; }\n";
echo ".progress { width: 100%; height: 20px; background: #ecf0f1; border-radius: 10px; overflow: hidden; margin: 10px 0; }\n";
echo ".progress-bar { height: 100%; background: #3498db; transition: width 0.3s; }\n";
echo "</style>\n";
echo "</head>\n";
echo "<body>\n";
echo "<div class='container'>\n";
echo "<div class='header'>\n";
echo "<h1>🔄 تحديث المبالغ المتبقية للمتدربين</h1>\n";
echo "<p>نسخة محسنة للاستخدام في cPanel</p>\n";
echo "</div>\n";

try {
    $db = connectDB();
    echo "<div class='success'>✅ تم الاتصال بقاعدة البيانات بنجاح</div>\n";
    
    $success_count = 0;
    $error_count = 0;
    $not_found_count = 0;
    $total_updates = count($updates);
    
    echo "<div class='info'>📊 معالجة $total_updates متدرب...</div>\n";
    echo "<div class='progress'><div class='progress-bar' id='progress' style='width: 0%'></div></div>\n";
    
    echo "<table>\n";
    echo "<tr><th>رقم الهوية</th><th>اسم المتدرب</th><th>المبلغ الحالي</th><th>المبلغ الجديد</th><th>الموظف</th><th>الحالة</th></tr>\n";
    
    foreach ($updates as $national_id => $data) {
        echo "<tr>\n";
        echo "<td>$national_id</td>\n";
        
        // البحث عن المتدرب
        $stmt = $db->prepare('SELECT id, full_name, remaining_amount, assigned_to FROM trainees WHERE national_id = ?');
        $stmt->execute([$national_id]);
        $trainee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($trainee) {
            echo "<td>{$trainee['full_name']}</td>\n";
            echo "<td>" . number_format($trainee['remaining_amount'], 2) . "</td>\n";
            echo "<td>" . number_format($data['new'], 2) . "</td>\n";
            
            // التحقق من الموظف
            $stmt = $db->prepare('SELECT full_name FROM users WHERE id = ?');
            $stmt->execute([$trainee['assigned_to']]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            $employee_name = $employee ? $employee['full_name'] : 'غير معين';
            echo "<td>$employee_name</td>\n";
            
            // تحديث المبلغ
            $stmt = $db->prepare('UPDATE trainees SET remaining_amount = ?, updated_at = NOW() WHERE id = ?');
            $result = $stmt->execute([$data['new'], $trainee['id']]);
            
            if ($result) {
                echo "<td class='success'>✅ تم التحديث</td>\n";
                $success_count++;
                logError("تم تحديث المتدرب {$trainee['full_name']} (ID: {$trainee['id']}) من {$trainee['remaining_amount']} إلى {$data['new']}");
            } else {
                echo "<td class='error'>❌ فشل التحديث</td>\n";
                $error_count++;
                logError("فشل تحديث المتدرب {$trainee['full_name']} (ID: {$trainee['id']})");
            }
        } else {
            echo "<td colspan='3' class='warning'>⚠️ المتدرب غير موجود</td>\n";
            echo "<td>-</td>\n";
            echo "<td class='warning'>❌ غير موجود</td>\n";
            $not_found_count++;
            logError("المتدرب برقم الهوية $national_id غير موجود");
        }
        
        echo "</tr>\n";
        
        // تحديث شريط التقدم
        $progress = round((($success_count + $error_count + $not_found_count) / $total_updates) * 100);
        echo "<script>document.getElementById('progress').style.width = '$progress%';</script>\n";
        flush();
    }
    
    echo "</table>\n";
    
    // ملخص النتائج
    echo "<div class='summary'>\n";
    echo "<h2>📊 ملخص النتائج</h2>\n";
    echo "<p class='success'>✅ تم التحديث بنجاح: $success_count متدرب</p>\n";
    echo "<p class='error'>❌ فشل التحديث: $error_count متدرب</p>\n";
    echo "<p class='warning'>⚠️ غير موجودين: $not_found_count متدرب</p>\n";
    echo "</div>\n";
    
    // إجماليات حسب الموظف
    echo "<h3>📈 الإجماليات حسب الموظف</h3>\n";
    $stmt = $db->prepare('
        SELECT u.full_name as employee_name, 
               COUNT(t.id) as trainee_count,
               COALESCE(SUM(t.remaining_amount), 0) as total_remaining
        FROM trainees t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.assigned_to IS NOT NULL
        GROUP BY t.assigned_to, u.full_name
        ORDER BY total_remaining DESC
    ');
    $stmt->execute();
    $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table>\n";
    echo "<tr><th>الموظف</th><th>عدد المتدربين</th><th>إجمالي المتبقي</th></tr>\n";
    foreach ($summary as $row) {
        echo "<tr>\n";
        echo "<td>{$row['employee_name']}</td>\n";
        echo "<td>" . number_format($row['trainee_count']) . "</td>\n";
        echo "<td>" . number_format($row['total_remaining'], 2) . " ريال</td>\n";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    echo "<div class='success'><h3>🎉 اكتملت العملية بنجاح!</h3></div>\n";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ حدث خطأ: " . $e->getMessage() . "</div>\n";
    logError("خطأ عام: " . $e->getMessage());
}

echo "</div>\n";
echo "</body>\n";
echo "</html>\n";

?>

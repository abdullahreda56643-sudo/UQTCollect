<?php
/**
 * سكريبت إنشاء جدول سجلات التحصيل اليومي للموظفين
 * يساعد في تتبع المبالغ التي يجب أن يحصلها الموظفون ومقارنتها بالفعل
 */

require_once "config.php";
require_once "db/connection.php";

header("Content-Type: text/html; charset=utf-8");
echo "<style>
body { font-family: Arial, sans-serif; margin: 20px; direction: rtl; }
.header { background: #2c3e50; color: white; padding: 20px; text-align: center; }
.alert { padding: 15px; margin: 20px 0; border-radius: 5px; }
.alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
.alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
.alert-info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
.btn { padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 3px; margin: 5px; }
.btn-success { background: #27ae60; }
.btn-danger { background: #e74c3c; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: right; border: 1px solid #ddd; }
th { background: #f2f2f2; }
</style>";

echo "<div class=\"header\">
    <h1>إنشاء جدول سجلات التحصيل اليومي للموظفين</h1>
    <p>هذا السكريبت يقوم بإنشاء جدول لتسجيل المبالغ اليومية التي يحصلها الموظفون</p>
</div>";

try {
    $db = (new Database())->getConnection();
    
    // التحقق من وجود الجدول
    $check_table = $db->query("SHOW TABLES LIKE 'employee_collections'");
    if ($check_table->rowCount() > 0) {
        echo "<div class=\"alert alert-info\">";
        echo "<h3>جدول سجلات التحصيل موجود بالفعل</h3>";
        echo "<p>الجدول 'employee_collections' موجود بالفعل في قاعدة البيانات.</p>";
        echo "</div>";
        
        // عرض معلومات الجدول
        $describe = $db->query("DESCRIBE employee_collections");
        $columns = $describe->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>هيكل الجدول الحالي:</h3>";
        echo "<table>";
        echo "<tr><th>اسم الحقل</th><th>نوع البيانات</th><th>يسمح بالقيم الفارغة</th><th>المفتاح</th><th>الافتراضي</th></tr>";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column["Field"]) . "</td>";
            echo "<td>" . htmlspecialchars($column["Type"]) . "</td>";
            echo "<td>" . ($column["Null"] == "YES" ? "نعم" : "لا") . "</td>";
            echo "<td>" . htmlspecialchars($column["Key"]) . "</td>";
            echo "<td>" . htmlspecialchars($column["Default"] ?: 'لا يوجد') . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        echo "<div style=\"text-align: center; margin: 20px 0;\">";
        echo "<a href=\"employee_daily_collections.php\" class=\"btn btn-success\">عرض تقرير التحصيل اليومي</a>";
        echo "<a href=\"index.php\" class=\"btn\">العودة للرئيسية</a>";
        echo "</div>";
        
    } else {
        // إنشاء الجدول بدون قيود المفتاح الخارجي أولاً
        $create_table_query = "
            CREATE TABLE `employee_collections` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `employee_id` int(11) NOT NULL COMMENT 'معرف الموظف',
                `collection_date` date NOT NULL COMMENT 'تاريخ التحصيل',
                `expected_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'المبلغ المتوقع تحصيله',
                `actual_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'المبلغ الفعلي المحصل',
                `recorded_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'المبلغ المسجل في النظام',
                `payment_count` int(11) DEFAULT 0 COMMENT 'عدد الدفعات',
                `status` enum('pending','completed','discrepancy') DEFAULT 'pending' COMMENT 'حالة التحصيل',
                `notes` text DEFAULT NULL COMMENT 'ملاحظات',
                `created_by` int(11) NOT NULL COMMENT 'من قام بالإدخال',
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_employee_date` (`employee_id`, `collection_date`),
                KEY `idx_employee_id` (`employee_id`),
                KEY `idx_collection_date` (`collection_date`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجلات التحصيل اليومي للموظفين'
        ";
        
        $db->exec($create_table_query);
        
        echo "<div class=\"alert alert-success\">";
        echo "<h3>تم إنشاء الجدول بنجاح</h3>";
        echo "<p>تم إنشاء جدول 'employee_collections' بنجاح في قاعدة البيانات.</p>";
        echo "</div>";
        
        // إنشاء الجدول لتتبع تعديلات الموظفين على المبالغ
        $create_audit_table_query = "
            CREATE TABLE `employee_collection_audit` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `collection_id` int(11) NOT NULL COMMENT 'معرف سجل التحصيل',
                `employee_id` int(11) NOT NULL COMMENT 'معرف الموظف',
                `old_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'المبلغ القديم',
                `new_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'المبلغ الجديد',
                `change_type` enum('create','update','delete') NOT NULL COMMENT 'نوع التغيير',
                `reason` varchar(255) DEFAULT NULL COMMENT 'سبب التغيير',
                `changed_by` int(11) NOT NULL COMMENT 'من قام بالتغيير',
                `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_collection_id` (`collection_id`),
                KEY `idx_employee_id` (`employee_id`),
                KEY `idx_changed_at` (`changed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول تدقيق تعديلات سجلات التحصيل'
        ";
        
        $db->exec($create_audit_table_query);
        
        echo "<div class=\"alert alert-success\">";
        echo "<h3>تم إنشاء جدول التدقيق</h3>";
        echo "<p>تم إنشاء جدول 'employee_collection_audit' لتتبع جميع التعديلات على سجلات التحصيل.</p>";
        echo "</div>";
        
        // إنشاء إجراء مخزن لتحديث سجلات التحصيل تلقائياً
        $create_procedure_query = "
            DELIMITER $$
            CREATE PROCEDURE `UpdateEmployeeDailyCollection`(IN p_employee_id INT, IN p_collection_date DATE)
            BEGIN
                DECLARE v_actual_amount DECIMAL(10,2) DEFAULT 0.00;
                DECLARE v_payment_count INT DEFAULT 0;
                DECLARE v_recorded_amount DECIMAL(10,2) DEFAULT 0.00;
                DECLARE v_status VARCHAR(20) DEFAULT 'pending';
                
                -- حساب المبالغ الفعلية من جدول الدفعات
                SELECT 
                    COALESCE(SUM(amount), 0.00),
                    COUNT(id)
                INTO v_actual_amount, v_payment_count
                FROM payments 
                WHERE created_by = p_employee_id 
                AND DATE(payment_date) = p_collection_date;
                
                -- الحصول على المبلغ المسجل في سجلات التحصيل
                SELECT COALESCE(recorded_amount, 0.00)
                INTO v_recorded_amount
                FROM employee_collections 
                WHERE employee_id = p_employee_id 
                AND collection_date = p_collection_date;
                
                -- تحديد الحالة
                IF v_actual_amount = 0.00 AND v_recorded_amount = 0.00 THEN
                    SET v_status = 'pending';
                ELSEIF ABS(v_actual_amount - v_recorded_amount) <= 0.01 THEN
                    SET v_status = 'completed';
                ELSE
                    SET v_status = 'discrepancy';
                END IF;
                
                -- تحديث أو إدخال السجل
                INSERT INTO employee_collections 
                    (employee_id, collection_date, actual_amount, recorded_amount, payment_count, status, created_by)
                VALUES 
                    (p_employee_id, p_collection_date, v_actual_amount, v_recorded_amount, v_payment_count, v_status, 1)
                ON DUPLICATE KEY UPDATE
                    actual_amount = VALUES(actual_amount),
                    payment_count = VALUES(payment_count),
                    status = VALUES(status),
                    updated_at = CURRENT_TIMESTAMP;
                    
            END$$
            DELIMITER ;
        ";
        
        try {
            $db->exec($create_procedure_query);
            echo "<div class=\"alert alert-success\">";
            echo "<h3>تم إنشاء الإجراء المخزن</h3>";
            echo "<p>تم إنشاء الإجراء المخزن 'UpdateEmployeeDailyCollection' لتحديث السجلات تلقائياً.</p>";
            echo "</div>";
        } catch (Exception $e) {
            echo "<div class=\"alert alert-danger\">";
            echo "<h3>فشل إنشاء الإجراء المخزن</h3>";
            echo "<p>الخطأ: " . $e->getMessage() . "</p>";
            echo "</div>";
        }
        
        echo "<h3>الخطوات التالية:</h3>";
        echo "<ol>";
        echo "<li>استخدام سكريبت التحصيل اليومي لمراقبة المبالغ</li>";
        echo "<li>إعداد مهام مجدولة لتحديث السجلات تلقائياً</li>";
        echo "<li>مراجعة السياسات الحالية لإدخال البيانات</li>";
        echo "<li>تدريب الموظفين على استخدام النظام بشكل صحيح</li>";
        echo "</ol>";
        
        echo "<div style=\"text-align: center; margin: 20px 0;\">";
        echo "<a href=\"employee_daily_collections.php\" class=\"btn btn-success\">عرض تقرير التحصيل اليومي</a>";
        echo "<a href=\"index.php\" class=\"btn\">العودة للرئيسية</a>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class=\"alert alert-danger\">";
    echo "<h2>خطأ في التنفيذ</h2>";
    echo "<p>الخطأ: " . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<div style=\"text-align: center; margin: 50px 0; color: #666;\">";
echo "<p>تم إنشاء هذا السكريبت لإعداد نظام تتبع التحصيل اليومي للموظفين</p>";
echo "<p><small>نظام إدارة المتدربين والتحصيل المالي - الإصدار 2.0.0</small></p>";
echo "</div>";
?>

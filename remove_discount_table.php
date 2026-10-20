<?php
require_once 'config.php';
require_once 'db/connection.php';

try {
    $db = (new Database())->getConnection();
    
    // Drop the discounts table
    $sql = "DROP TABLE IF EXISTS discounts";
    $stmt = $db->prepare($sql);
    $result = $stmt->execute();
    
    if ($result) {
        echo "✓ تم حذف جدول الخصومات بنجاح\n";
        
        // Also remove discount columns from payments table if they exist
        $discountColumns = ['discount_id', 'discount_amount', 'original_amount'];
        
        foreach ($discountColumns as $column) {
            $checkColumnSql = "SHOW COLUMNS FROM payments LIKE '$column'";
            $checkStmt = $db->prepare($checkColumnSql);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                $dropColumnSql = "ALTER TABLE payments DROP COLUMN $column";
                $dropStmt = $db->prepare($dropColumnSql);
                $dropResult = $dropStmt->execute();
                
                if ($dropResult) {
                    echo "✓ تم حذف العمود $column من جدول المدفوعات\n";
                } else {
                    echo "✗ فشل حذف العمود $column\n";
                }
            }
        }
        
        echo "\n✓ تم إزالة نظام الخصومات بالكامل من النظام\n";
        
    } else {
        echo "✗ فشل حذف جدول الخصومات\n";
    }
    
} catch (Exception $e) {
    echo "✗ خطأ: " . $e->getMessage() . "\n";
}
?>

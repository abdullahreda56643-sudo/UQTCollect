<?php
require_once 'config.php';
require_once 'db/connection.php';

try {
    $db = (new Database())->getConnection();
    
    // تحديث رصيد المتدرب 1743 يدوياً
    $traineeId = 1743;
    
    echo "<h2>تحديث رصيد المتدرب ID: $traineeId</h2>";
    
    // استدعاء الإجراء المخزن المُصلّح
    $stmt = $db->prepare("CALL UpdateTraineeBalance(?)");
    $stmt->bindParam(1, $traineeId, PDO::PARAM_INT);
    $stmt->execute();
    
    // التحقق من النتائج بعد التحديث
    $stmt = $db->prepare("SELECT * FROM trainees WHERE id = ?");
    $stmt->bindParam(1, $traineeId, PDO::PARAM_INT);
    $stmt->execute();
    $trainee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<div style='border: 1px solid #green; padding: 10px; margin: 10px 0; background: #f0fff0;'>";
    echo "<h3>النتيجة بعد التحديث:</h3>";
    echo "<p><strong>المتدرب:</strong> " . htmlspecialchars($trainee['full_name']) . "</p>";
    echo "<p><strong>الرسوم الإجمالية:</strong> " . number_format($trainee['total_amount'], 2) . " ر.س</p>";
    echo "<p><strong>المبلغ المدفوع:</strong> " . number_format($trainee['paid_amount'], 2) . " ر.س</p>";
    echo "<p><strong>المبلغ المتبقي:</strong> " . number_format($trainee['remaining_amount'], 2) . " ر.س</p>";
    echo "<p><strong>الحالة:</strong> " . htmlspecialchars($trainee['status']) . "</p>";
    echo "</div>";
    
    // التحقق من تفاصيل الدفعات
    $stmt = $db->prepare("SELECT * FROM payments WHERE trainee_id = ? ORDER BY created_at DESC");
    $stmt->bindParam(1, $traineeId, PDO::PARAM_INT);
    $stmt->execute();
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>تفاصيل الدفعات:</h3>";
    foreach ($payments as $payment) {
        echo "<div style='border: 1px solid #blue; padding: 10px; margin: 5px 0; background: #f0f8ff;'>";
        echo "<p><strong>الدفعة ID:</strong> " . $payment['id'] . "</p>";
        echo "<p><strong>المبلغ بعد الخصم:</strong> " . number_format($payment['amount'], 2) . " ر.س</p>";
        echo "<p><strong>المبلغ الأصلي:</strong> " . number_format($payment['original_amount'], 2) . " ر.س</p>";
        echo "<p><strong>قيمة الخصم:</strong> " . number_format($payment['discount_amount'], 2) . " ر.س</p>";
        echo "</div>";
    }
    
    echo "<p style='color: green; font-weight: bold;'>تم تحديث رصيد المتدرب بنجاح!</p>";
    
} catch (Exception $e) {
    echo '<p style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>

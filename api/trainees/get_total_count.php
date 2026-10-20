<?php
/**
 * API لجلب إجمالي عدد المتدربين في النظام
 * نسخة محسنة تعيد العدد الحقيقي
 */

// تعطيب جميع الأخطاء
error_reporting(0);
ini_set('display_errors', 0);

// تعيين رأس JSON مباشرة
header('Content-Type: application/json; charset=utf-8');

// دالة للرد بالخطأ
function sendError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'total_count' => 0,
        'message' => $message
    ]);
    exit;
}

// دالة للرد بالنجاح
function sendSuccess($count, $message = 'تم جلب العدد بنجاح') {
    echo json_encode([
        'success' => true,
        'total_count' => (int)$count,
        'message' => $message
    ]);
    exit;
}

try {
    // المسارات النسبية
    $base_dir = __DIR__ . '/../../..';
    
    // تحميل الإعدادات الأساسية فقط
    $config_file = $base_dir . '/config.php';
    if (!file_exists($config_file)) {
        sendError('ملف الإعدادات غير موجود');
    }
    
    // تحميل الإعدادات
    require_once $config_file;
    
    // بدء الجلسة إذا لزم الأمر
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // التحقق من المصادقة
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        sendError('غير مصرح - يرجى تسجيل الدخول', 401);
    }
    
    // تحميل اتصال قاعدة البيانات
    $db_file = $base_dir . '/db/connection.php';
    if (!file_exists($db_file)) {
        sendError('ملف الاتصال بقاعدة البيانات غير موجود');
    }
    
    require_once $db_file;
    
    // إنشاء اتصال بقاعدة البيانات
    $db = (new Database())->getConnection();
    
    // استعلام بسيط وآمن للحصول على إجمالي عدد المتدربين
    // بدون أي فلاتر أو قيود للحصول على العدد الحقيقي
    $query = "SELECT COUNT(*) as total_count FROM trainees";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalCount = $result['total_count'] ?? 0;
    
    sendSuccess($totalCount, 'إجمالي عدد المتدربين في النظام: ' . $totalCount);
    
} catch (Exception $e) {
    error_log("Error in get_total_count.php: " . $e->getMessage());
    sendError('حدث خطأ في جلب البيانات: ' . $e->getMessage());
}
?>
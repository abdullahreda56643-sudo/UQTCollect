<?php
/**
 * API لجلب جميع معرفات المتدربين في النظام
 * نسخة محسنة تعيد جميع المعرفات بدون قيود
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
        'trainee_ids' => [],
        'message' => $message
    ]);
    exit;
}

// دالة للرد بالنجاح
function sendSuccess($ids, $message = 'تم جلب المعرفات بنجاح') {
    echo json_encode([
        'success' => true,
        'trainee_ids' => $ids,
        'total_count' => count($ids),
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
    
    // استعلام بسيط وآمن للحصول على جميع معرفات المتدربين
    // بدون أي فلاتر أو قيود للحصول على جميع المتدربين
    $query = "SELECT id FROM trainees ORDER BY id";
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    sendSuccess($results ?: [], 'تم جلب جميع معرفات المتدربين في النظام: ' . count($results));
    
} catch (Exception $e) {
    error_log("Error in get_all_ids.php: " . $e->getMessage());
    sendError('حدث خطأ في جلب البيانات: ' . $e->getMessage());
}
?>
<?php
// /api/communications/get-history.php
header('Content-Type: application/json; charset=utf-8');
// When credentials are allowed, Access-Control-Allow-Origin must not be '*'.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    // fallback to the configured SITE_URL if available, else allow same-origin
    $siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    if ($siteUrl) {
        header('Access-Control-Allow-Origin: ' . $siteUrl);
    }
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// التعامل مع طلبات OPTIONS للـ CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// تضمين الملفات المطلوبة
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db/connection.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

// بدء الجلسة بنفس الإعدادات
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Temporary debug logging to help diagnose 401 issues (only in development)
if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
    $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $cookieDump = [];
    try { $cookieDump = $_COOKIE; } catch (Throwable $e) { $cookieDump = ['error' => $e->getMessage()]; }
    error_log("[DEBUG get-history] Origin=" . $reqOrigin . " | SessionID=" . session_id() . " | Cookies=" . json_encode($cookieDump) . " | _SESSION=" . json_encode($_SESSION));
}

// دالة مصادقة مبسطة ومباشرة
function checkAPIAuth() {
    // التحقق الأساسي من الجلسة
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log("API Auth Failed: No user_id in session. Session: " . json_encode($_SESSION));
        return false;
    }
    
    // التحقق من أن المستخدم مسجل دخول
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        error_log("API Auth Failed: User not logged in. User ID: " . $_SESSION['user_id']);
        return false;
    }
    
    // تحديث وقت النشاط الأخير
    $_SESSION['last_activity'] = time();
    
    return true;
}

// التحقق من المصادقة
if (!checkAPIAuth()) {
    error_log("API Access Denied - Session data: " . json_encode($_SESSION));
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'غير مصرح بالوصول. يرجى تسجيل الدخول أولاً.',
        'code' => 'UNAUTHORIZED',
        'session_status' => session_status(),
        'has_user_id' => isset($_SESSION['user_id']),
        'logged_in' => $_SESSION['logged_in'] ?? false
    ]);
    exit;
}

// التحقق من وجود trainee_id
if (!isset($_GET['trainee_id']) || empty($_GET['trainee_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'معرف المتدرب مطلوب',
        'code' => 'MISSING_TRAINEE_ID'
    ]);
    exit;
}

$trainee_id = intval($_GET['trainee_id']);

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('تعذر الاتصال بقاعدة البيانات');
    }

    // الحصول على معلومات المتدرب
    $stmt = $db->prepare("
        SELECT id, full_name, national_id, phone, email 
        FROM trainees 
        WHERE id = ?
    ");
    
    $stmt->execute([$trainee_id]);
    $trainee_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trainee_info) {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'المتدرب غير موجود',
            'code' => 'TRAINEE_NOT_FOUND'
        ]);
        exit;
    }

    // الحصول على سجل التواصل
    $stmt = $db->prepare("
        SELECT 
            c.id,
            c.trainee_id,
            c.type,
            c.result,
            c.notes,
            c.contact_date,
            c.created_at,
            c.created_by,
            u.full_name as created_by_name,
            c.next_action_date as next_follow_up
        FROM communications c
        LEFT JOIN users u ON c.created_by = u.id
        WHERE c.trainee_id = ?
        ORDER BY c.contact_date DESC, c.created_at DESC
    ");
    
    $stmt->execute([$trainee_id]);
    $communications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // تسجيل نجاح العملية
    error_log("API Success: Communications history loaded for trainee " . $trainee_id . " by user " . $_SESSION['user_id']);

    echo json_encode([
        'success' => true,
        'trainee_info' => $trainee_info,
        'communications' => $communications,
        'count' => count($communications)
    ]);

} catch (PDOException $e) {
    error_log("Database error in get-history: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'حدث خطأ في قاعدة البيانات',
        'code' => 'DATABASE_ERROR'
    ]);
} catch (Exception $e) {
    error_log("General error in get-history: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'حدث خطأ: ' . $e->getMessage(),
        'code' => 'GENERAL_ERROR'
    ]);
}
?>
<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// التعامل مع طلبات OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config.php';
require_once '../db/connection.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_once '../models/Report.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'غير مصرح بالوصول']);
    exit;
}

// إنشاء اتصال بقاعدة البيانات
$db = (new Database())->getConnection();
$report = new Report($db);

// المستخدم الحالي ودوره
$stmt = $db->prepare('SELECT branch_id, role FROM users WHERE id = :id');
$stmt->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_INT);
$stmt->execute();
$me = $stmt->fetch();
$userRole = $me['role'] ?? 'employee';
$defaultBranchId = (int)($me['branch_id'] ?? 0);

// الحصول على المعلمات المشتركة
$start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$end = $_GET['end_date'] ?? date('Y-m-d');
$branchId = ($userRole === 'admin') ? (int)($_GET['branch_id'] ?? $defaultBranchId) : $defaultBranchId;

// تحديد نوع التقرير المطلوب
$reportType = $_GET['type'] ?? 'financial_summary';

// التحقق من الصلاحيات للتقارير: require admin or explicit reports view permission
if ($reportType !== 'employee_performance') {
    if (!isAdmin() && !hasAccess('reports','view')) {
        http_response_code(403);
        echo json_encode(['error' => 'غير مصرح بالوصول'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $data = [];
    
    switch ($reportType) {
        case 'financial_summary':
            $data = $report->getFinancialSummary($start, $end, $branchId);
            break;
            
        case 'collections_by_day':
            $data = $report->getCollectionsByDay($start, $end, $branchId);
            break;
            
        case 'trainee_status':
            $data = $report->getTraineeStatusDistribution($branchId);
            break;
            
        case 'branch_contacts_by_day':
            $data = $report->getBranchContactsByDay($branchId, $start, $end);
            break;
            
        case 'branch_contacts_by_employee':
            $data = $report->getBranchContactsByEmployee($branchId, $start, $end);
            break;
            
        case 'employee_performance':
            $employeeId = (int)($_GET['employee_id'] ?? $_SESSION['user_id']);
            
            // التحقق من الصلاحيات: يمكن للموظف رؤية أدائه فقط، بينما المشرف والمدير يمكنهم رؤية أداء أي موظف
            if ($userRole === 'employee' && $employeeId !== (int)$_SESSION['user_id']) {
                throw new Exception('غير مصرح بالوصول إلى بيانات هذا الموظف');
            }
            
            $data = $report->getEmployeePerformance($employeeId, $start, $end);
            break;
            
        default:
            throw new Exception('نوع التقرير غير معروف');
    }
    
    // إضافة معلومات إضافية للاستجابة
    $response = [
        'success' => true,
        'data' => $data,
        'meta' => [
            'report_type' => $reportType,
            'start_date' => $start,
            'end_date' => $end,
            'branch_id' => $branchId,
            'generated_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
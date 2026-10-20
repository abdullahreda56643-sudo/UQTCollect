<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/Report.php';

$db = (new Database())->getConnection();
// Allow admin or users with explicit reports view permission
requireAccessOrAdmin('reports', 'view');

$report = new Report($db);

// المستخدم الحالي ودوره
$stmt = $db->prepare('SELECT branch_id, role FROM users WHERE id = :id');
$stmt->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_INT);
$stmt->execute();
$me = $stmt->fetch();
$userRole = $me['role'] ?? 'employee';
$defaultBranchId = (int)($me['branch_id'] ?? 0);

// معلمات التقرير
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end = $_GET['end'] ?? date('Y-m-d');
$branchId = (in_array($userRole, ['admin', 'manager'], true)) ? (int)($_GET['branch_id'] ?? $defaultBranchId) : $defaultBranchId;
$period = $_GET['period'] ?? 'daily'; // يومي/أسبوعي/شهري
$employeeId = (int)($_GET['employee_id'] ?? 0);
$diplomaId = (int)($_GET['diploma_id'] ?? 0);

// فلاتر قسم أداء الموظفين
$employeeStartDate = $_GET['employee_start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$employeeEndDate = $_GET['employee_end_date'] ?? date('Y-m-d');
$employeeSortBy = $_GET['employee_sort_by'] ?? 'collections';
$employeeCollectionRange = $_GET['employee_collection_range'] ?? 'all';
$employeeSuccessRate = $_GET['employee_success_rate'] ?? 'all';

// معلمات التقسيم للمتعثرين
$delinquentsPage = isset($_GET['delinquents_page']) ? max(1, (int)$_GET['delinquents_page']) : 1;
$delinquentsPerPage = 10; // عدد المتعثرين في كل صفحة
$delinquentsOffset = ($delinquentsPage - 1) * $delinquentsPerPage;

// بيانات التقرير
// استخدم دوال التقارير المناسبة حسب نوع الفترة
if ($period === 'daily') {
    $collections = $report->getCollectionsByDay($start, $end, $branchId);
} else {
    // weekly or monthly
    $collections = $report->getCollectionsByPeriod($start, $end, $period, $branchId);
}

// Ensure $collections is iterable. Some callers or DB errors may return a string or null.
if (is_string($collections)) {
    $decoded = json_decode($collections, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $collections = $decoded;
    } else {
        $collections = [];
    }
} elseif (!is_array($collections) && !($collections instanceof Traversable)) {
    $collections = [];
}

// Fallback grouping directly from payments table for daily period when report returns empty
$collections_fallback = [];
$collections_fallback_unrestricted = [];
$fallback_used = null; // 'branch', 'unrestricted' or null
if (empty($collections) && $period === 'daily') {
    try {
        // 1) Branch-restricted fallback (matches trainees.branch_id when available)
        $branchCondition = '';
        if (!empty($branchId)) {
            $branchCondition = ' AND t.branch_id = :branch_id';
        }
        $sql = "SELECT DATE(p.payment_date) as pay_day, COUNT(*) as tx_count, COALESCE(SUM(p.amount),0) as total_amount, COALESCE(AVG(p.amount),0) as avg_amount
                FROM payments p
                LEFT JOIN trainees t ON p.trainee_id = t.id
                WHERE DATE(p.payment_date) BETWEEN :start AND :end {$branchCondition}
                GROUP BY DATE(p.payment_date)
                ORDER BY DATE(p.payment_date) DESC";
        $fbStmt = $db->prepare($sql);
        $fbStmt->bindValue(':start', $start);
        $fbStmt->bindValue(':end', $end);
        if (!empty($branchId)) {
            $fbStmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        }
        $fbStmt->execute();
        $collections_fallback = $fbStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2) Unrestricted fallback (ignore branch filter) — useful to detect if data exists outside selected branch
        $sql2 = "SELECT DATE(p.payment_date) as pay_day, COUNT(*) as tx_count, COALESCE(SUM(p.amount),0) as total_amount, COALESCE(AVG(p.amount),0) as avg_amount
                 FROM payments p
                 WHERE DATE(p.payment_date) BETWEEN :start2 AND :end2
                 GROUP BY DATE(p.payment_date)
                 ORDER BY DATE(p.payment_date) DESC";
        $fbStmt2 = $db->prepare($sql2);
        $fbStmt2->bindValue(':start2', $start);
        $fbStmt2->bindValue(':end2', $end);
        $fbStmt2->execute();
        $collections_fallback_unrestricted = $fbStmt2->fetchAll(PDO::FETCH_ASSOC);

        // determine which fallback to use: prefer branch-restricted; else use unrestricted
        if (!empty($collections_fallback)) {
            $fallback_used = 'branch';
        } elseif (!empty($collections_fallback_unrestricted)) {
            $fallback_used = 'unrestricted';
        }

    } catch (Exception $e) {
        $collections_fallback = [];
        $collections_fallback_unrestricted = [];
        $fallback_used = null;
    }
}

$summary = $report->getFinancialSummary($start, $end, $branchId);
$statusDist = $report->getTraineeStatusDistribution($branchId);

// Fallback: إذا كانت بيانات الكروت فارغة، استخدم نفس طريقة index.php
if ((empty($summary['total_collected']) && empty($summary['total_debt'])) || (empty($summary['total_contacts']) && empty($summary['contacted_trainees']))) {
    try {
        $branchCondition = "";
        $params = [];
        if (in_array($userRole, ['supervisor', 'manager'], true) && !empty($defaultBranchId)) {
            $branchCondition = "AND t.branch_id = :branch_id";
            $params[':branch_id'] = $defaultBranchId;
        }
        $stmt = $db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM trainees t WHERE 1=1 {$branchCondition}) as total_trainees,
                (SELECT COALESCE(SUM(p.amount), 0) 
                 FROM payments p 
                 JOIN trainees t ON p.trainee_id = t.id 
                 WHERE DATE(p.payment_date) = CURDATE() {$branchCondition}) as today_payments,
                (SELECT COUNT(*) 
                 FROM communications c 
                 JOIN trainees t ON c.trainee_id = t.id 
                 WHERE DATE(c.created_at) = CURDATE() {$branchCondition}) as today_contacts,
                (SELECT COALESCE(SUM(GREATEST(t.remaining_amount, 0)), 0)
                 FROM trainees t
                 WHERE 1=1 {$branchCondition}) as total_debt
        ");
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->execute();
        $today = $stmt->fetch(PDO::FETCH_ASSOC);
        $summary['total_collected'] = $today['today_payments'] ?? 0;
        $summary['total_debt'] = $today['total_debt'] ?? 0;
        $summary['total_contacts'] = $today['today_contacts'] ?? 0;
        $summary['contacted_trainees'] = $today['total_trainees'] ?? 0;
        $summary['contact_success_rate'] = '0%';
    } catch (Exception $e) {
        // إذا فشل الاستعلام، استخدم بيانات افتراضية
        $summary = [
            'total_collected' => 0,
            'total_debt' => 0,
            'total_contacts' => 0,
            'contacted_trainees' => 0,
            'contact_success_rate' => '0%'
        ];
    }
}

// البيانات الإضافية - استخدام الدالة المحسنة مع فلاتر التاريخ
$employeeStats = $report->getBranchContactsByEmployee($branchId, $employeeStartDate, $employeeEndDate);

// Detect duplicate rows for the same employee (help debugging duplicated cards)
if (is_array($employeeStats)) {
    $ids = array_map(function($e){ return $e['employee_id'] ?? null; }, $employeeStats);
    $ids = array_filter($ids, function($v){ return $v !== null && $v !== ''; });
    $counts = array_count_values($ids);
    $dups = array_filter($counts, function($c){ return $c > 1; });
    if (!empty($dups)) {
        // write a debug dump to the project's logs directory (non-blocking)
        $debugPath = __DIR__ . '/../../logs/employee_stats_duplicates_' . date('Ymd_His') . '.json';
        @file_put_contents($debugPath, json_encode($employeeStats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        error_log("[reports/financial.php] Duplicate employee rows detected; dump written to: {$debugPath}");
    }
}

// الحصول على موظف الأسبوع
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));
$weeklyEmployeeStats = $report->getBranchContactsByEmployee($branchId, $weekStart, $weekEnd);

// الحصول على المتعثرين مع نظام التقسيم
    try {
        // Build base query and append optional filters for trainee_status and contact_result
        $delinquentsQuery = "
            SELECT 
                t.id,
                t.full_name,
                t.phone,
                t.remaining_amount as debt_amount,
                d.name as diploma_name,
                c.created_at as last_contact,
                u.full_name as last_contacted_by,
                c.result as contact_result,
                c.notes
            FROM trainees t
            LEFT JOIN diplomas d ON t.diploma_id = d.id
            LEFT JOIN (
                SELECT c1.* 
                FROM communications c1
                INNER JOIN (
                    SELECT trainee_id, MAX(created_at) as max_date
                    FROM communications 
                    GROUP BY trainee_id
                ) c2 ON c1.trainee_id = c2.trainee_id AND c1.created_at = c2.max_date
            ) c ON t.id = c.trainee_id
            LEFT JOIN users u ON c.user_id = u.id
            WHERE t.remaining_amount > 0 
            
            /* apply branch filter only when a specific branch is selected (branchId > 0) */
            " . (!empty($branchId) ? " AND t.branch_id = :branch_id" : "") . "
        ";

        // optional filters
        if (!empty($_GET['trainee_status'])) {
            $delinquentsQuery .= "\n AND t.status = :trainee_status";
        }
        if (!empty($_GET['contact_result'])) {
            // filter by last contact result (uses the joined derived table c)
            $delinquentsQuery .= "\n AND c.result = :contact_result";
        }

        $delinquentsQuery .= "\n ORDER BY t.remaining_amount DESC\n LIMIT :limit OFFSET :offset\n";

        $delinquentsStmt = $db->prepare($delinquentsQuery);
        if (!empty($branchId)) {
            $delinquentsStmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        }
        if (!empty($_GET['trainee_status'])) {
            $delinquentsStmt->bindValue(':trainee_status', $_GET['trainee_status']);
        }
        if (!empty($_GET['contact_result'])) {
            $delinquentsStmt->bindValue(':contact_result', $_GET['contact_result']);
        }
        $delinquentsStmt->bindValue(':limit', $delinquentsPerPage, PDO::PARAM_INT);
        $delinquentsStmt->bindValue(':offset', $delinquentsOffset, PDO::PARAM_INT);
        $delinquentsStmt->execute();
        $delinquents = $delinquentsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $delinquents = [];
    }

// الحصول على إحصائيات حالة التواصل مع معالجة الخطأ
$contactStatusStats = [];
try {
    $contactStats = $report->getContactStatusStats($branchId, $start, $end);
    // التأكد من أن $contactStats هي مصفوفة
    if (is_array($contactStats)) {
        $contactStatusStats = $contactStats;
    }
} catch (Exception $e) {
    $contactStatusStats = [];
    error_log("Error getting contact status stats: " . $e->getMessage());
}

// الحصول على إجمالي عدد المتعثرين للتقسيم
$totalDelinquentsStmt = $db->prepare("
    SELECT COUNT(*) as total 
    FROM trainees 
    WHERE remaining_amount > 0 
    AND branch_id = :branch_id
");
$totalDelinquentsStmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
$totalDelinquentsStmt->execute();
$totalDelinquentsResult = $totalDelinquentsStmt->fetch(PDO::FETCH_ASSOC);
$totalDelinquents = $totalDelinquentsResult['total'] ?? 0;
$totalDelinquentsPages = ceil($totalDelinquents / $delinquentsPerPage);

// الحصول على بيانات إضافية للموظفين لاستخدامها في الرسوم البيانية
$employeePerformanceData = [];
if (!empty($employeeStats) && is_array($employeeStats)) {
    foreach ($employeeStats as $employee) {
        $employeeId = $employee['employee_id'] ?? 0;
        if ($employeeId) {
            $employeePerformanceData[$employeeId] = [
                'name' => $employee['full_name'] ?? 'غير معروف',
                'contacts' => $employee['total_contacts'] ?? 0,
                'collections' => $employee['total_collections'] ?? 0,
                'success_rate' => $employee['success_rate'] ?? 0,
                'paying_trainees' => $employee['paying_trainees'] ?? 0
            ];
        }
    }
}

// قائمة الدبلومات للتصفية (من جدول الدبلومات)
$diplomasStmt = $db->prepare("SELECT id, name FROM diplomas ORDER BY name");
$diplomasStmt->execute();
$diplomas = $diplomasStmt->fetchAll();

// قائمة الموظفين للتصفية
$employeesStmt = $db->prepare("SELECT id, full_name FROM users WHERE role IN ('employee', 'supervisor') ORDER BY full_name");
$employeesStmt->execute();
$employees = $employeesStmt->fetchAll();

// قائمة الفروع للتصفية
$branchesStmt = $db->prepare("SELECT id, name FROM branches ORDER BY name");
$branchesStmt->execute();
$branches = $branchesStmt->fetchAll();

// دالة لتعريب قيم حالة الاتصال
function translateContactResult($result) {
    return match($result) {
        'paid' => 'تم الدفع',
        'under_communication' => 'قيد التواصل',
        'interested' => 'مهتم',
        'promise' => 'وعد بالدفع',
        'responded' => 'تم الرد',
        'refused' => 'رفض',
        'not_responding' => 'لم يرد',
        'promised_payment' => 'وعد بالدفع',
        'refused_payment' => 'رفض الدفع',
        'not_available' => 'غير متاح',
        'other' => 'أخرى',
        'no_answer' => 'لم يرد',
        'call_later' => 'الاتصال لاحقاً',
        'wrong_number' => 'رقم خاطئ',
        'disconnected' => 'مفصول',
        default => $result ?? 'غير محدد'
    };
}

// دالة لتعريب حالات المتدربين
function translateTraineeStatus($status) {
    return match($status) {
        'active' => 'نشط',
        'graduated' => 'متخرج',
        'delayed' => 'متأخر',
        'withdrawn' => 'منسحب',
        'promised_payment' => 'وعد بالدفع',
        'refused_payment' => 'رفض الدفع',
        'not_available' => 'غير متاح',
        'other' => 'أخرى',
        'no_answer' => 'لم يرد',
        'call_later' => 'الاتصال لاحقاً',
        'wrong_number' => 'رقم خاطئ',
        'follow_up' => 'متابعة',
        'disconnected' => 'مفصول',
        'under_communication' => 'قيد التواصل',
        'not_responding' => 'لم يرد',
        'paid' => 'مدفوع',
        'unpaid' => 'غير مدفوع',
        'partially_paid' => 'مدفوع جزئياً',
        'completed' => 'مكتمل',
        'in_progress' => 'قيد التنفيذ',
        'pending' => 'معلق',
        'cancelled' => 'ملغى',
        'suspended' => 'موقوف',
        'transferred' => 'منقول',
        'new' => 'جديد',
        'registered' => 'مسجل',
        'approved' => 'معتمد',
        'rejected' => 'مرفوض',
        'on_hold' => 'في الانتظار',
        'expired' => 'منتهي',
        'inactive' => 'غير نشط',
        'dropped' => 'منقطع',
        'deferred' => 'مؤجل',
        default => $status
    };
}

?>
<?php $page_title = 'تقرير التحصيل المالي المتقدم'; include '../../includes/tw_layout_start.php'; ?>

<!-- Page-specific stylesheet to align with site layout -->
<link href="<?php echo rtrim(SITE_URL,'/'); ?>/public/assets/css/reports.css" rel="stylesheet">

<!-- استدعاء مكتبة الأيقونات Boxicons -->
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<!-- استدعاء مكتبة Chart.js للرسوم البيانية -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    :root {
        --card-bg-light: #ffffff;
        --card-bg-dark: #1f2937;
        --text-light: #374151;
        --text-dark: #f9fafb;
        --border-light: #e5e7eb;
        --border-dark: #4b5563;
    }

    .dark .card-hover {
        background: var(--card-bg-dark);
        border-color: var(--border-dark);
        color: var(--text-dark);
    }

    .dark .filter-control {
        background: #374151;
        border-color: #4b5563;
        color: #f9fafb;
    }

    .dark .filter-control:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
    }

    .dark .performance-card {
        background: var(--card-bg-dark);
        border-color: var(--border-dark);
    }

    .dark .card-header {
        background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
        border-color: var(--border-dark);
    }

    .dark .metric-card {
        background: #374151;
        color: var(--text-dark);
    }

    .dark .employee-of-week {
        background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
        border-color: #3b82f6;
    }

    .card-hover {
        transition: all 0.3s ease;
        background: var(--card-bg-light);
        color: var(--text-light);
    }
    .card-hover:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
    .performance-chart {
        height: 200px;
        width: 100%;
    }
    .overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        display: none;
        z-index: 1000;
    }
    .overlay-content {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        padding: 20px;
        border-radius: 10px;
        width: 90%;
        max-width: 800px;
        max-height: 90vh;
        overflow-y: auto;
    }
    .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .badge-success {
        background-color: #d1fae5;
        color: #065f46;
    }
    .dark .badge-success {
        background-color: #065f46;
        color: #d1fae5;
    }
    .badge-warning {
        background-color: #fef3c7;
        color: #92400e;
    }
    .dark .badge-warning {
        background-color: #92400e;
        color: #fef3c7;
    }
    .badge-danger {
        background-color: #fee2e2;
        color: #991b1b;
    }
    .dark .badge-danger {
        background-color: #991b1b;
        color: #fee2e2;
    }
    .badge-info {
        background-color: #dbeafe;
        color: #1e40af;
    }
    .dark .badge-info {
        background-color: #1e40af;
        color: #dbeafe;
    }
    .employee-card {
        border-radius: 12px;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    .employee-card:hover {
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        transform: translateY(-5px);
    }
    .progress-bar {
        height: 8px;
        border-radius: 4px;
        background-color: #e5e7eb;
        overflow: hidden;
    }
    .dark .progress-bar {
        background-color: #4b5563;
    }
    .progress-fill {
        height: 100%;
        border-radius: 4px;
    }
    .filter-section {
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 24px;
    }
    .dark .filter-section {
        background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
    }
    .filter-toggle {
        display: none;
    }
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
        margin-top: 16px;
    }
    .pagination button {
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: white;
        color: #374151;
        cursor: pointer;
        transition: all 0.2s;
    }
    .dark .pagination button {
        background: #374151;
        border-color: #4b5563;
        color: #f9fafb;
    }
    .pagination button:hover:not(:disabled) {
        background: #3b82f6;
        color: white;
        border-color: #3b82f6;
    }
    .pagination button:disabled {
        background: #f3f4f6;
        color: #9ca3af;
        cursor: not-allowed;
    }
    .dark .pagination button:disabled {
        background: #4b5563;
        color: #6b7280;
    }
    .pagination button.active {
        background: #3b82f6;
        color: white;
        border-color: #3b82f6;
    }
    .pagination-info {
        text-align: center;
        margin-top: 8px;
        color: #6b7280;
        font-size: 0.875rem;
    }
    .dark .pagination-info {
        color: #9ca3af;
    }
    
    /* التصميم المحسن للفلاتر وأزرار التصدير */
    .advanced-filters {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    .dark .advanced-filters {
        background: linear-gradient(135deg, #1f2937 0%, #202731 100%);
        border-color: #4b5563;
    }
    
    .filter-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .dark .filter-header {
        border-bottom-color: #4b5563;
    }
    
    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
    }
    
    .filter-label {
        font-size: 0.875rem;
        font-weight: 600;
        margin-bottom: 6px;
        color: #374151;
    }
    
    .dark .filter-label {
        color: #f9fafb;
    }
    
    .filter-control {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        background: white;
        font-size: 0.875rem;
        transition: all 0.2s;
    }
    
    .filter-control:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .export-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 20px;
    }
    
    .export-btn {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.875rem;
        transition: all 0.2s;
    }
    
    .export-excel {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
    }
    
    .export-excel:hover {
        background: linear-gradient(135deg, #059669 0%, #047857 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }
    
    .export-pdf {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
    }
    
    .export-pdf:hover {
        background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }
    
    .back-btn {
        background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
        color: white;
    }
    
    .back-btn:hover {
        background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
    }
    
    /* التصميم المحسن لقسم أداء الموظفين - مطابقة ألوان وبنية بقية الأقسام */
    .employee-performance-section {
        background: white; /* matches other page sections */
        border-radius: 12px; /* consistent with rounded-xl in markup */
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
        overflow: hidden;
        margin-bottom: 24px;
    }

    /* dark mode: match other sections' dark background */
    .dark .employee-performance-section {
        background: var(--card-bg-dark, #1f2937);
        border-color: var(--border-dark, #4b5563);
    }
    
    .performance-header {
        padding: 20px 24px;
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        border-bottom: 1px solid #e2e8f0;
    }
    
    .dark .performance-header {
        /* match the card-header dark gradient for consistent appearance */
        background: linear-gradient(135deg, #1b2027ff 0%, #1c2127ff 100%);
        border-bottom-color: var(--border-dark, #4b5563);
        color: var(--text-dark, #f9fafb);
    }

    /* Unified section title and icon styles for professional look */
    .section-title {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        font-size: 1.125rem; /* 18px */
        font-weight: 700;
        color: inherit;
    }

    .section-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        color: white;
        box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    }

    .section-icon.yellow { background: linear-gradient(135deg,#d97706,#f59e0b); }
    .section-icon.blue   { background: linear-gradient(135deg,#0369a1,#0891b2); }
    .section-icon.purple { background: linear-gradient(135deg,#6d28d9,#7c3aed); }
    .section-icon.red    { background: linear-gradient(135deg,#dc2626,#ef4444); }
    .section-icon.green  { background: linear-gradient(135deg,#059669,#10b981); }

    .section-title .section-text { display: inline-block; }

    /* ensure icons in existing headings inherit consistent spacing in dark mode */
    .dark .section-icon { filter: brightness(0.95); }
    
    .performance-filters {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        margin-top: 16px;
    }
    
    .performance-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 20px;
        padding: 24px;
    }
    
    .performance-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        border: 1px solid #e2e8f0;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .dark .performance-card {
        background: #1f2937;
        border-color: #374151;
    }
    
    .performance-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.12);
    }
    
    .card-header {
        padding: 16px;
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        border-bottom: 1px solid #e2e8f0;
    }
    
    .dark .card-header {
        background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
        border-bottom-color: #4b5563;
    }
    
    .employee-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .employee-name {
        font-weight: 700;
        font-size: 1.125rem;
        color: #1f2937;
    }
    
    .dark .employee-name {
        color: #f9fafb;
    }
    
    .employee-id {
        font-size: 0.75rem;
        color: #6b7280;
    }
    
    .dark .employee-id {
        color: #9ca3af;
    }
    
    .employee-role {
        font-size: 0.875rem;
        color: #6b7280;
        margin-top: 4px;
    }
    
    .dark .employee-role {
        color: #9ca3af;
    }
    
    .rating-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .card-body {
        padding: 16px;
    }
    
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-bottom: 16px;
    }
    
    .metric-card {
        background: #f8fafc;
        border-radius: 8px;
        padding: 12px;
        text-align: center;
    }
    
    .dark .metric-card {
        background: #374151;
    }
    
    .metric-value {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1f2937;
    }
    
    .dark .metric-value {
        color: #f9fafb;
    }
    
    .metric-label {
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 4px;
    }
    
    .dark .metric-label {
        color: #9ca3af;
    }
    
    .progress-section {
        margin-top: 16px;
    }
    
    .progress-info {
        display: flex;
        justify-content: space-between;
        margin-bottom: 6px;
    }
    
    .progress-label {
        font-size: 0.875rem;
        color: #374151;
    }
    
    .dark .progress-label {
        color: #f9fafb;
    }
    
    .progress-value {
        font-size: 0.875rem;
        font-weight: 600;
        color: #1f2937;
    }
    
    .dark .progress-value {
        color: #f9fafb;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        margin-top: 16px;
    }
    
    .stat-item {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.75rem;
        color: #6b7280;
    }
    
    .dark .stat-item {
        color: #9ca3af;
    }

    /* قسم موظف الأسبوع */
    .employee-of-week-section {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
        border: 2px solid #0ea5e9;
    }

    .dark .employee-of-week-section {
        background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
        border-color: #3b82f6;
    }

    .employee-of-week {
        background: linear-gradient(135deg, #1f2937 0%, #1f2937 100%);
        color: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .dark .employee-of-week {
        background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
    }

    .employee-of-week-badge {
        background: rgba(255, 255, 255, 0.2);
        color: white;
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 16px;
    }

    .weekly-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
        margin-top: 20px;
    }

    .weekly-stat-card {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        padding: 16px;
        text-align: center;
        backdrop-filter: blur(10px);
    }

    .weekly-stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .weekly-stat-label {
        font-size: 0.875rem;
        opacity: 0.9;
    }

    .employee-filters-form {
        background: rgba(255, 255, 255, 0.5);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .dark .employee-filters-form {
        background: rgba(55, 65, 81, 0.5);
    }

    .apply-filters-btn {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .apply-filters-btn:hover {
        background: linear-gradient(135deg, #059669 0%, #047857 100%);
        transform: translateY(-2px);
    }

    .reset-filters-btn {
        background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .reset-filters-btn:hover {
        background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
        transform: translateY(-2px);
    }

    .quick-filters {
        margin-top: 16px;
    }

    .quick-filter-btn {
        padding: 8px 16px;
        border: none;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        margin: 4px;
    }

    .quick-filter-btn:hover {
        transform: translateY(-1px);
    }

    /* تحسينات إضافية للوضع المظلم */
    .dark .bg-white {
        background-color: #1f2937 !important;
    }

    .dark .bg-gray-50 {
        background-color: #374151 !important;
    }

    .dark .text-gray-900 {
        color: #f9fafb !important;
    }

    .dark .text-gray-800 {
        color: #e5e7eb !important;
    }

    .dark .text-gray-700 {
        color: #d1d5db !important;
    }

    .dark .text-gray-500 {
        color: #9ca3af !important;
    }

    .dark .text-gray-400 {
        color: #6b7280 !important;
    }

    .dark .divide-gray-200 > :not([hidden]) ~ :not([hidden]) {
        border-color: #4b5563;
    }

    .dark .border-gray-200 {
        border-color: #4b5563;
    }

    .dark .border-gray-300 {
        border-color: #4b5563;
    }

    .dark .shadow {
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2) !important;
    }

    @media (max-width: 1024px) {
        .filter-toggle {
            display: block;
        }
        .filter-form {
            display: none;
        }
        .filter-form.active {
            display: block;
        }
        .performance-grid {
            grid-template-columns: 1fr;
        }
        .performance-filters {
            flex-direction: column;
        }
        .performance-filters .filter-control {
            width: 100%;
        }
        .weekly-stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .weekly-stats-grid {
            grid-template-columns: 1fr;
        }
        .export-actions {
            flex-direction: column;
        }
        .export-btn {
            justify-content: center;
        }
    }
</style>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">التقرير المالي المتقدم</h1>
</div>

<!-- الفلاتر المتقدمة وأزرار التصدير -->
<div class="advanced-filters">
    <div class="filter-header">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
            <span class="section-icon blue" title="فلاتر التقرير"><i class="bx bx-filter-alt"></i></span>
            <span class="section-text">فلاتر التقرير</span>
        </h2>
        <button id="filterToggle" class="filter-toggle px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg flex items-center gap-2">
            <i class="bx bx-filter-alt"></i>
            <span>الفلاتر</span>
        </button>
    </div>
    
    <form method="get" class="filter-form">
        <div class="filter-grid">
            <?php if (in_array($userRole, ['admin', 'manager'], true)): ?>
            <div class="filter-group">
                <label class="filter-label">الفرع</label>
                <select name="branch_id" class="filter-control">
                    <option value="">جميع الفروع</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)$branchId === (int)$b['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="filter-group">
                <label class="filter-label">الفترة</label>
                <select name="period" class="filter-control">
                    <option value="daily" <?php echo $period === 'daily' ? 'selected' : ''; ?>>يومي</option>
                    <option value="weekly" <?php echo $period === 'weekly' ? 'selected' : ''; ?>>أسبوعي</option>
                    <option value="monthly" <?php echo $period === 'monthly' ? 'selected' : ''; ?>>شهري</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">الدبلوم</label>
                <select name="diploma_id" class="filter-control">
                    <option value="">جميع الدبلومات</option>
                    <?php foreach ($diplomas as $diploma): ?>
                    <option value="<?php echo $diploma['id']; ?>" <?php echo $diplomaId == $diploma['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($diploma['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">حالة التواصل</label>
                <select name="contact_result" class="filter-control">
                    <option value="">جميع الحالات</option>
                    <?php
                    // قائمة خيارات نتائج الاتصال (مطابقة للدالة translateContactResult)
                    $contactOptions = [
                        'paid','under_communication','interested','promise','responded','refused','not_responding',
                        'promised_payment','refused_payment','not_available','other','no_answer','call_later','wrong_number','disconnected'
                    ];
                    foreach ($contactOptions as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php echo (isset($_GET['contact_result']) && $_GET['contact_result'] === $opt) ? 'selected' : ''; ?>>
                            <?php echo translateContactResult($opt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">حالة المتدرب</label>
                <select name="trainee_status" class="filter-control">
                    <option value="">جميع الحالات</option>
                    <?php
                    $statusOptions = [
                        'active','graduated','delayed','withdrawn','promised_payment','refused_payment','not_available','other',
                        'no_answer','call_later','wrong_number','follow_up','disconnected','under_communication','not_responding',
                        'paid','unpaid','partially_paid','completed','in_progress','pending','cancelled','suspended','transferred',
                        'new','registered','approved','rejected','on_hold','expired','inactive','dropped','deferred'
                    ];
                    foreach ($statusOptions as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo (isset($_GET['trainee_status']) && $_GET['trainee_status'] === $s) ? 'selected' : ''; ?>>
                            <?php echo translateTraineeStatus($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">من تاريخ</label>
                <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="filter-control">
            </div>
            
            <div class="filter-group">
                <label class="filter-label">إلى تاريخ</label>
                <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="filter-control">
            </div>
        </div>
        
        <!-- إضافة حقل الصفحة الحالية للمتعثرين -->
        <input type="hidden" name="delinquents_page" value="<?php echo $delinquentsPage; ?>">
        
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-3">
                <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg flex items-center gap-2 font-medium">
                    <i class="bx bx-refresh"></i>
                    <span>تحديث التقرير</span>
                </button>
                <button type="button" id="resetAllFilters" class="px-6 py-3 bg-gray-400 hover:bg-gray-400 text-gray-800 rounded-lg flex items-center gap-2 font-medium">
                    <i class="bx bx-reset"></i>
                    <span>إعادة تعيين</span>
                </button>
            </div>
            
            <div class="export-actions">
                <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/reports/index.php"
                   class="export-btn back-btn">
                    <i class="bx bx-home-alt"></i>
                    <span>مركز التقارير</span>
                </a>
                
                     <a href="export_excel.php?<?php echo http_build_query($_GET); ?>" 
                         class="export-btn export-excel">
                    <i class="bx bxs-file-export"></i>
                    <span>تحميل Excel</span>
                </a>
                
                <a href="export_pdf.php?<?php echo http_build_query($_GET); ?>"
                   class="export-btn export-pdf">
                    <i class="bx bxs-file-pdf"></i>
                    <span>تحميل PDF</span>
                </a>
            </div>
        </div>
    </form>
</div>

<!-- الكروت الإحصائية المحسنة -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-gradient-to-r from-green-50 to-emerald-100 dark:from-green-900/20 dark:to-emerald-900/20 rounded-xl shadow p-6 border-l-4 border-green-500 card-hover">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">إجمالي المحصل</div>
                <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-white">
                    <?php echo htmlspecialchars(formatCurrency($summary['total_collected'] ?? 0)); ?>
                </div>
            </div>
            <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-full">
                <i class="bx bx-credit-card text-2xl text-green-600"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gradient-to-r from-orange-50 to-amber-100 dark:from-orange-900/20 dark:to-amber-900/20 rounded-xl shadow p-6 border-l-4 border-orange-500 card-hover">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">إجمالي المديونية</div>
                <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-white">
                    <?php echo htmlspecialchars(formatCurrency($summary['total_debt'] ?? 0)); ?>
                </div>
            </div>
            <div class="p-3 bg-orange-100 dark:bg-orange-900/30 rounded-full">
                <i class="bx bx-money text-2xl text-orange-600"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gradient-to-r from-blue-50 to-cyan-100 dark:from-blue-900/20 dark:to-cyan-900/20 rounded-xl shadow p-6 border-l-4 border-blue-500 card-hover">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">إحصائيات الاتصالات</div>
                <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-white">
                    <?php echo number_format($summary['total_contacts'] ?? 0); ?>
                </div>
                <div class="flex items-center gap-2 mt-1 text-xs">
                    <span class="text-blue-600 dark:text-blue-400">
                        <?php echo number_format($summary['contacted_trainees'] ?? 0); ?> متدرب
                    </span>
                    <span class="text-gray-400">|</span>
                    <span class="text-green-600 dark:text-green-400">
                        <?php echo $summary['contact_success_rate'] ?? '0%'; ?> نجاح
                    </span>
                </div>
            </div>
            <div class="p-3 bg-blue-100 dark:bg-blue-900/30 rounded-full">
                <i class="bx bx-phone-call text-2xl text-blue-600"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-gradient-to-r from-purple-50 to-violet-100 dark:from-purple-900/20 dark:to-violet-900/20 rounded-xl shadow p-6 border-l-4 border-purple-500 card-hover">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">نسبة التحصيل</div>
                <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-white">
                    <?php 
                    $total = ($summary['total_collected'] ?? 0) + ($summary['total_debt'] ?? 0);
                    $collectionRate = $total > 0 ? (($summary['total_collected'] ?? 0) / $total) * 100 : 0;
                    echo number_format($collectionRate, 1) . '%';
                    ?>
                </div>
                <div class="progress-bar mt-2">
                    <div class="progress-fill bg-purple-500" style="width: <?php echo number_format($collectionRate, 1); ?>%"></div>
                </div>
            </div>
            <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-full">
                <i class="bx bx-trending-up text-2xl text-purple-600"></i>
            </div>
        </div>
    </div>
</div>

<!-- قسم موظف الأسبوع -->
<div class="employee-of-week-section">
    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-6">
        <span class="section-icon yellow" title="موظف الأسبوع"><i class="bx bx-crown"></i></span>
        <span class="section-text">موظف الأسبوع</span>
    </h3>
    
    <?php
    // تحديد موظف الأسبوع
    $employeeOfTheWeek = null;
    $maxScore = 0;
    
    if (!empty($weeklyEmployeeStats) && is_array($weeklyEmployeeStats)) {
        foreach ($weeklyEmployeeStats as $employee) {
            $score = 0;
            $score += (($employee['success_rate'] ?? 0) * 0.4);
            $collectionScore = min(100, (($employee['total_collections'] ?? 0) / 10000) * 100);
            $score += ($collectionScore * 0.3);
            $traineeScore = min(100, (($employee['paying_trainees'] ?? 0) / 10) * 100);
            $score += ($traineeScore * 0.3);
            
            if ($score > $maxScore) {
                $maxScore = $score;
                $employeeOfTheWeek = $employee;
            }
        }
    }
    ?>
    
    <?php if ($employeeOfTheWeek): ?>
    <div class="employee-of-week">
        <div class="employee-of-week-badge">
            <i class="bx bx-crown"></i>
            موظف الأسبوع
        </div>
        
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="text-center md:text-right mb-4 md:mb-0">
                <h4 class="text-2xl font-bold"><?php echo htmlspecialchars($employeeOfTheWeek['full_name'] ?? 'غير معروف'); ?></h4>
                <p class="text-blue-100">#<?php echo $employeeOfTheWeek['employee_id'] ?? ''; ?></p>
                <p class="text-blue-200 text-sm mt-2">
                    <?php echo htmlspecialchars(match($employeeOfTheWeek['role'] ?? '') {
                        'supervisor' => 'مشرف',
                        'employee' => 'موظف',
                        default => $employeeOfTheWeek['role'] ?? 'غير محدد'
                    }); ?>
                </p>
            </div>
            
            <div class="text-center">
                <div class="text-3xl font-bold text-yellow-300"><?php echo number_format($maxScore, 1); ?></div>
                <div class="text-yellow-200 text-sm">نقطة التقييم</div>
            </div>
        </div>
        
        <div class="weekly-stats-grid mt-6">
            <div class="weekly-stat-card">
                <div class="weekly-stat-value"><?php echo htmlspecialchars(formatCurrency($employeeOfTheWeek['total_collections'] ?? 0)); ?></div>
                <div class="weekly-stat-label">إجمالي المحصل</div>
            </div>
            <div class="weekly-stat-card">
                <div class="weekly-stat-value"><?php echo number_format($employeeOfTheWeek['total_contacts'] ?? 0); ?></div>
                <div class="weekly-stat-label">عدد الاتصالات</div>
            </div>
            <div class="weekly-stat-card">
                <div class="weekly-stat-value"><?php echo number_format($employeeOfTheWeek['success_rate'] ?? 0, 1); ?>%</div>
                <div class="weekly-stat-label">معدل النجاح</div>
            </div>
            <div class="weekly-stat-card">
                <div class="weekly-stat-value"><?php echo number_format($employeeOfTheWeek['paying_trainees'] ?? 0); ?></div>
                <div class="weekly-stat-label">المتدربين الناجحين</div>
            </div>
        </div>
        
        <div class="mt-6 text-center text-blue-200 text-sm">
            <i class="bx bx-calendar ml-1"></i>
            الفترة: <?php echo date('d/m/Y', strtotime($weekStart)); ?> - <?php echo date('d/m/Y', strtotime($weekEnd)); ?>
        </div>
    </div>
    <?php else: ?>
    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
        <i class="bx bx-user-x text-4xl mb-4"></i>
        <p>لا توجد بيانات كافية لتحديد موظف الأسبوع</p>
    </div>
    <?php endif; ?>
</div>

<!-- قسم أداء الموظفين المحسن -->
<div class="employee-performance-section">
    <div class="performance-header">
        <div class="flex justify-between items-center">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                <span class="section-icon yellow" title="أداء الموظفين"><i class="bx bx-trophy"></i></span>
                <span class="section-text">أداء الموظفين</span>
            </h3>
        </div>
        
        <!-- فلاتر قسم الموظفين -->
        <form method="get" action="" class="employee-filters-form" id="employeeFiltersForm">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                <div class="filter-group">
                    <label class="filter-label">من تاريخ</label>
                    <input type="date" name="employee_start_date" value="<?php echo htmlspecialchars($employeeStartDate); ?>" class="filter-control">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">إلى تاريخ</label>
                    <input type="date" name="employee_end_date" value="<?php echo htmlspecialchars($employeeEndDate); ?>" class="filter-control">
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">ترتيب حسب</label>
                    <select name="employee_sort_by" class="filter-control">
                        <option value="collections" <?php echo $employeeSortBy === 'collections' ? 'selected' : ''; ?>>التحصيل المالي</option>
                        <option value="contacts" <?php echo $employeeSortBy === 'contacts' ? 'selected' : ''; ?>>عدد الاتصالات</option>
                        <option value="success" <?php echo $employeeSortBy === 'success' ? 'selected' : ''; ?>>معدل النجاح</option>
                        <option value="rating" <?php echo $employeeSortBy === 'rating' ? 'selected' : ''; ?>>التقييم العام</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">نطاق التحصيل</label>
                    <select name="employee_collection_range" class="filter-control">
                        <option value="all" <?php echo $employeeCollectionRange === 'all' ? 'selected' : ''; ?>>جميع التحصيلات</option>
                        <option value="0-5000" <?php echo $employeeCollectionRange === '0-5000' ? 'selected' : ''; ?>>حتى 5,000 ريال</option>
                        <option value="5000-15000" <?php echo $employeeCollectionRange === '5000-15000' ? 'selected' : ''; ?>>5,000 - 15,000 ريال</option>
                        <option value="15000-30000" <?php echo $employeeCollectionRange === '15000-30000' ? 'selected' : ''; ?>>15,000 - 30,000 ريال</option>
                        <option value="30000+" <?php echo $employeeCollectionRange === '30000+' ? 'selected' : ''; ?>>أكثر من 30,000 ريال</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">معدل النجاح</label>
                    <select name="employee_success_rate" class="filter-control">
                        <option value="all" <?php echo $employeeSuccessRate === 'all' ? 'selected' : ''; ?>>جميع المعدلات</option>
                        <option value="0-30" <?php echo $employeeSuccessRate === '0-30' ? 'selected' : ''; ?>>0% - 30%</option>
                        <option value="30-60" <?php echo $employeeSuccessRate === '30-60' ? 'selected' : ''; ?>>30% - 60%</option>
                        <option value="60-80" <?php echo $employeeSuccessRate === '60-80' ? 'selected' : ''; ?>>60% - 80%</option>
                        <option value="80-100" <?php echo $employeeSuccessRate === '80-100' ? 'selected' : ''; ?>>80% - 100%</option>
                    </select>
                </div>
            </div>
            
            <div class="flex gap-4">
                <button type="button" onclick="submitEmployeeFilters()" class="apply-filters-btn">
                    <i class="bx bx-check"></i>
                    تطبيق الفلاتر
                </button>
                
                <a href="?" class="reset-filters-btn">
                    <i class="bx bx-reset"></i>
                    إعادة تعيين
                </a>
            </div>
            
            <!-- إضافة الحقول المخفية للحفاظ على بقية الفلاتر -->
            <input type="hidden" name="start" value="<?php echo htmlspecialchars($start); ?>">
            <input type="hidden" name="end" value="<?php echo htmlspecialchars($end); ?>">
            <input type="hidden" name="branch_id" value="<?php echo (int)$branchId; ?>">
            <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
            <input type="hidden" name="diploma_id" value="<?php echo (int)$diplomaId; ?>">
            <input type="hidden" name="delinquents_page" value="<?php echo $delinquentsPage; ?>">
        </form>

        <!-- الفلاتر السريعة -->
        <div class="quick-filters">
            <button class="quick-filter-btn bg-blue-100 hover:bg-blue-200 text-blue-700 dark:bg-blue-900 dark:hover:bg-blue-800 dark:text-blue-300" data-range="all" data-success="all">
                الكل
            </button>
            <button class="quick-filter-btn bg-green-100 hover:bg-green-200 text-green-700 dark:bg-green-900 dark:hover:bg-green-800 dark:text-green-300" data-range="30000+" data-success="80-100">
                المتميزون
            </button>
            <button class="quick-filter-btn bg-yellow-100 hover:bg-yellow-200 text-yellow-700 dark:bg-yellow-900 dark:hover:bg-yellow-800 dark:text-yellow-300" data-range="5000-15000" data-success="60-80">
                المتوسطون
            </button>
            <button class="quick-filter-btn bg-red-100 hover:bg-red-200 text-red-700 dark:bg-red-900 dark:hover:bg-red-800 dark:text-red-300" data-range="0-5000" data-success="0-30">
                بحاجة تحسين
            </button>
        </div>
    </div>
    
    <div class="performance-grid">
        <?php 
        // تطبيق الفلاتر على بيانات الموظفين
        $filteredEmployeeStats = [];
        
        if (!empty($employeeStats) && is_array($employeeStats)) {
            foreach ($employeeStats as $employee) {
                // use a distinct variable name to avoid clobbering the global $collections used for the report
                $empCollections = $employee['total_collections'] ?? 0;
                $successRate = $employee['success_rate'] ?? 0;
                
                // فلترة نطاق التحصيل
                $collectionMatch = true;
                switch($employeeCollectionRange) {
                    case '0-5000':
                        $collectionMatch = $empCollections <= 5000;
                        break;
                    case '5000-15000':
                        $collectionMatch = $empCollections > 5000 && $empCollections <= 15000;
                        break;
                    case '15000-30000':
                        $collectionMatch = $empCollections > 15000 && $empCollections <= 30000;
                        break;
                    case '30000+':
                        $collectionMatch = $empCollections > 30000;
                        break;
                }
                
                // فلترة معدل النجاح
                $successMatch = true;
                switch($employeeSuccessRate) {
                    case '0-30':
                        $successMatch = $successRate <= 30;
                        break;
                    case '30-60':
                        $successMatch = $successRate > 30 && $successRate <= 60;
                        break;
                    case '60-80':
                        $successMatch = $successRate > 60 && $successRate <= 80;
                        break;
                    case '80-100':
                        $successMatch = $successRate > 80;
                        break;
                }
                
                if ($collectionMatch && $successMatch) {
                    $filteredEmployeeStats[] = $employee;
                }
            }
        }
        
        // دمج الصفوف المكررة لنفس الموظف (في حال أعاد الاستعلام أكثر من سطر لنفس الموظف)
        $mergedByEmployee = [];
        foreach ($filteredEmployeeStats as $employee) {
            $employeeId = $employee['employee_id'] ?? 0;
            if (!$employeeId) continue;

            // تهيئة قيم رقمية
            $contacts = (int)($employee['total_contacts'] ?? 0);
            $collectionsAmount = (float)($employee['total_collections'] ?? 0);
            $paymentsCount = (int)($employee['total_payments_count'] ?? 0);
            $payingTrainees = (int)($employee['paying_trainees'] ?? 0);

            if (!isset($mergedByEmployee[$employeeId])) {
                $mergedByEmployee[$employeeId] = $employee;
                $mergedByEmployee[$employeeId]['total_contacts'] = $contacts;
                $mergedByEmployee[$employeeId]['total_collections'] = $collectionsAmount;
                $mergedByEmployee[$employeeId]['total_payments_count'] = $paymentsCount;
                $mergedByEmployee[$employeeId]['paying_trainees'] = $payingTrainees;
            } else {
                // اجمع القيم العددية
                $mergedByEmployee[$employeeId]['total_contacts'] += $contacts;
                $mergedByEmployee[$employeeId]['total_collections'] += $collectionsAmount;
                $mergedByEmployee[$employeeId]['total_payments_count'] += $paymentsCount;
                $mergedByEmployee[$employeeId]['paying_trainees'] += $payingTrainees;
            }
        }

        // بناء المصفوفة النهائية وإعادة حساب المتوسطات والنسب
        $finalEmployeeStats = [];
        foreach ($mergedByEmployee as $eid => $emp) {
            $totalPayments = (int)($emp['total_payments_count'] ?? 0);
            $totalCollections = (float)($emp['total_collections'] ?? 0);
            $totalContacts = (int)($emp['total_contacts'] ?? 0);

            $emp['avg_collection_per_payment'] = $totalPayments > 0 ? ($totalCollections / $totalPayments) : 0;
            $emp['success_rate'] = $totalContacts > 0 ? (($totalPayments * 100.0) / $totalContacts) : 0;

            $finalEmployeeStats[] = $emp;
        }

    // حساب التقييم لكل موظف
    foreach ($finalEmployeeStats as &$employee) {
            $score = 0;
            $score += (($employee['success_rate'] ?? 0) * 0.4);
            $collectionScore = min(100, (($employee['total_collections'] ?? 0) / 10000) * 100);
            $score += ($collectionScore * 0.3);
            $traineeScore = min(100, (($employee['paying_trainees'] ?? 0) / 10) * 100);
            $score += ($traineeScore * 0.3);
            
            $employee['score'] = $score;
            $employee['rating'] = match(true) {
                $score >= 80 => ['text' => 'ممتاز', 'color' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300', 'icon' => 'bx bx-medal'],
                $score >= 60 => ['text' => 'جيد جداً', 'color' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300', 'icon' => 'bx bx-star'],
                $score >= 40 => ['text' => 'جيد', 'color' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300', 'icon' => 'bx bx-like'],
                default => ['text' => 'يحتاج تحسين', 'color' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300', 'icon' => 'bx bx-dislike']
            };
        }

        // Final safeguard: ensure uniqueness by employee_id or full_name (merge if duplicates remain)
        $uniqueMap = [];
        foreach ($finalEmployeeStats as $emp) {
            $key = $emp['employee_id'] ?? null;
            if (empty($key)) {
                $key = strtolower(trim($emp['full_name'] ?? '')) ?: uniqid('emp_');
            }

            if (!isset($uniqueMap[$key])) {
                $uniqueMap[$key] = $emp;
            } else {
                // merge numeric fields conservatively
                $uniqueMap[$key]['total_contacts'] = (int)$uniqueMap[$key]['total_contacts'] + (int)($emp['total_contacts'] ?? 0);
                $uniqueMap[$key]['total_collections'] = (float)$uniqueMap[$key]['total_collections'] + (float)($emp['total_collections'] ?? 0);
                $uniqueMap[$key]['paying_trainees'] = (int)$uniqueMap[$key]['paying_trainees'] + (int)($emp['paying_trainees'] ?? 0);
                $uniqueMap[$key]['total_payments_count'] = (int)$uniqueMap[$key]['total_payments_count'] + (int)($emp['total_payments_count'] ?? 0);
                // recompute averages
                $totalPayments = (int)$uniqueMap[$key]['total_payments_count'];
                $totalCollections = (float)$uniqueMap[$key]['total_collections'];
                $totalContacts = (int)$uniqueMap[$key]['total_contacts'];
                $uniqueMap[$key]['avg_collection_per_payment'] = $totalPayments > 0 ? ($totalCollections / $totalPayments) : 0;
                $uniqueMap[$key]['success_rate'] = $totalContacts > 0 ? (($totalPayments * 100.0) / $totalContacts) : 0;
            }
        }

        $finalEmployeeStats = array_values($uniqueMap);
        
        // ترتيب الموظفين حسب المعيار المختار
        switch($employeeSortBy) {
            case 'contacts':
                usort($finalEmployeeStats, function($a, $b) {
                    return ($b['total_contacts'] ?? 0) <=> ($a['total_contacts'] ?? 0);
                });
                break;
            case 'success':
                usort($finalEmployeeStats, function($a, $b) {
                    return ($b['success_rate'] ?? 0) <=> ($a['success_rate'] ?? 0);
                });
                break;
            case 'rating':
                usort($finalEmployeeStats, function($a, $b) {
                    return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
                });
                break;
            default: // collections
                usort($finalEmployeeStats, function($a, $b) {
                    return ($b['total_collections'] ?? 0) <=> ($a['total_collections'] ?? 0);
                });
        }
        
        // Build time-series per employee (payments by date) for small charts
        $employeeTimeSeries = [];
        if (!empty($finalEmployeeStats) && is_array($finalEmployeeStats)) {
            $tsSql = "SELECT DATE(p.payment_date) as day, COALESCE(SUM(p.amount),0) as total_amount, COUNT(*) as tx_count
                      FROM payments p
                      WHERE p.created_by = :eid AND DATE(p.payment_date) BETWEEN :s AND :e
                      GROUP BY DATE(p.payment_date)
                      ORDER BY DATE(p.payment_date) ASC";
            $tsStmt = $db->prepare($tsSql);

            // normalize date range for series
            $startDT = new DateTime($employeeStartDate);
            $endDT = new DateTime($employeeEndDate);
            $periodInterval = new DateInterval('P1D');
            $period = new DatePeriod($startDT, $periodInterval, (clone $endDT)->modify('+1 day'));
            $labels = [];
            foreach ($period as $dt) { $labels[] = $dt->format('Y-m-d'); }

            foreach ($finalEmployeeStats as $emp) {
                $eid = $emp['employee_id'] ?? null;
                if (empty($eid)) continue;
                $tsStmt->bindValue(':eid', $eid, PDO::PARAM_INT);
                $tsStmt->bindValue(':s', $employeeStartDate);
                $tsStmt->bindValue(':e', $employeeEndDate);
                $tsStmt->execute();
                $rows = $tsStmt->fetchAll(PDO::FETCH_ASSOC);
                $map = [];
                foreach ($rows as $r) { $map[$r['day']] = (float)$r['total_amount']; }
                $data = [];
                foreach ($labels as $d) { $data[] = isset($map[$d]) ? $map[$d] : 0; }
                $employeeTimeSeries[$eid] = ['labels' => $labels, 'amounts' => $data];
            }
        }

        // Debug dump of finalEmployeeStats to help diagnose duplicate rendering issues (kept briefly)
        try {
            $dumpPath = __DIR__ . '/../../logs/final_employee_stats_' . date('Ymd_His') . '.json';
            @file_put_contents($dumpPath, json_encode($finalEmployeeStats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            // ignore
        }

        if (empty($finalEmployeeStats)): 
        ?>
        <div class="col-span-3 text-center py-8 text-gray-500 dark:text-gray-400">
            <i class="bx bx-user-x text-4xl mb-4"></i>
            <p>لا توجد بيانات تطابق الفلاتر المحددة</p>
        </div>
        <?php else: ?>
        <?php foreach ($finalEmployeeStats as $employee): 
            $employeeId = $employee['employee_id'] ?? 0;
        ?>
        <div class="performance-card" 
             data-collections="<?php echo $employee['total_collections'] ?? 0; ?>" 
             data-success="<?php echo $employee['success_rate'] ?? 0; ?>"
             data-contacts="<?php echo $employee['total_contacts'] ?? 0; ?>"
             data-score="<?php echo $employee['score'] ?? 0; ?>">
            <div class="card-header">
                <div class="employee-info">
                    <div>
                        <div class="employee-name"><?php echo htmlspecialchars($employee['full_name'] ?? 'غير معروف'); ?></div>
                        <div class="employee-id">#<?php echo $employeeId; ?></div>
                        <div class="employee-role">
                            <?php echo htmlspecialchars(match($employee['role'] ?? '') {
                                'supervisor' => 'مشرف',
                                'employee' => 'موظف',
                                default => $employee['role'] ?? 'غير محدد'
                            }); ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="rating-badge <?php echo $employee['rating']['color']; ?>">
                            <i class="<?php echo $employee['rating']['icon']; ?> mr-1"></i>
                            <?php echo $employee['rating']['text']; ?>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-300 mt-1"><?php echo number_format($employee['score'], 1); ?> نقطة</div>
                    </div>
                </div>
            </div>
            
            <div class="card-body">
                <div class="metrics-grid">
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($employee['total_contacts'] ?? 0); ?></div>
                        <div class="metric-label">الاتصالات</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo htmlspecialchars(formatCurrency($employee['total_collections'] ?? 0)); ?></div>
                        <div class="metric-label">المحصل</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($employee['paying_trainees'] ?? 0); ?></div>
                        <div class="metric-label">المتدربين</div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-value"><?php echo number_format($employee['promised_payments'] ?? 0); ?></div>
                        <div class="metric-label">الوعود</div>
                    </div>
                </div>
                <!-- small sparkline for recent collections -->
                <div class="mt-3">
                    <canvas id="spark-emp-<?php echo $employeeId; ?>" class="emp-sparkline" width="300" height="60"></canvas>
                </div>
                
                <div class="progress-section">
                    <div class="progress-info">
                        <span class="progress-label">معدل النجاح</span>
                        <span class="progress-value"><?php echo number_format($employee['success_rate'] ?? 0, 1); ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill <?php echo ($employee['success_rate'] ?? 0) >= 70 ? 'bg-green-500' : 
                                                 (($employee['success_rate'] ?? 0) >= 50 ? 'bg-yellow-500' : 
                                                 'bg-red-500'); ?>" 
                             style="width: <?php echo number_format($employee['success_rate'] ?? 0, 1); ?>%"></div>
                    </div>
                </div>
                
                <div class="stats-grid">
                    <div class="stat-item" title="المتدربين الذين قاموا بالسداد">
                        <i class="bx bx-user-check"></i>
                        <span><?php echo number_format($employee['paying_trainees'] ?? 0); ?></span>
                    </div>
                    <div class="stat-item" title="متوسط التحصيل لكل عملية">
                        <i class="bx bx-money"></i>
                        <span><?php echo htmlspecialchars(formatCurrency($employee['avg_collection_per_payment'] ?? 0)); ?></span>
                    </div>
                    <div class="stat-item" title="الوعود بالسداد">
                        <i class="bx bx-calendar-check"></i>
                        <span><?php echo number_format($employee['promised_payments'] ?? 0); ?></span>
                    </div>
                </div>
                
                <div class="mt-4 text-xs text-gray-500 dark:text-gray-400 text-center">
                    <i class="bx bx-calendar ml-1"></i>
                    الفترة: <?php echo date('d/m/Y', strtotime($employeeStartDate)); ?> - <?php echo date('d/m/Y', strtotime($employeeEndDate)); ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- الشبكة المحسنة -->
<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
    <!-- التحصيل حسب الفترة -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 card-hover">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
            <span class="section-icon blue" title="التحصيل"><i class="bx bx-calendar-event"></i></span>
            <span class="section-text">التحصيل <?php echo $period === 'daily' ? 'اليومي' : ($period === 'weekly' ? 'الأسبوعي' : 'الشهري'); ?></span>
        </h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                            <?php echo $period === 'daily' ? 'اليوم' : ($period === 'weekly' ? 'الأسبوع' : 'الشهر'); ?>
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">المبلغ</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">العمليات</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">متوسط القيمة</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (!empty($collections)): ?>
                        <?php foreach ($collections as $r): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200">
                                <?php 
                                // Determine period label depending on returned keys
                                if (isset($r['period_label'])) {
                                    $periodLabel = $r['period_label'];
                                } elseif (isset($r['pay_day'])) {
                                    $periodLabel = date('d/m/Y', strtotime($r['pay_day']));
                                } elseif (isset($r['period'])) {
                                    // period may be YEARWEEK (e.g., 202540) or YYYY-MM for monthly, or other
                                    $p = $r['period'];
                                    // weekly as numeric YYYYWW
                                    if (preg_match('/^(\d{6})$/', $p)) {
                                        $year = intval(substr($p,0,4));
                                        $week = intval(substr($p,4,2));
                                        // get monday of ISO week
                                        $dto = new DateTime();
                                        $dto->setISODate($year, $week);
                                        $startWeek = $dto->format('d/m/Y');
                                        $dto->modify('+6 days');
                                        $endWeek = $dto->format('d/m/Y');
                                        $periodLabel = $startWeek . ' - ' . $endWeek;
                                    } elseif (preg_match('/^\d{4}-\d{2}$/', $p)) {
                                        // monthly YYYY-MM
                                        $periodLabel = date('F Y', strtotime($p . '-01'));
                                    } else {
                                        $periodLabel = $p;
                                    }
                                } else {
                                    $periodLabel = 'غير محدد';
                                }
                                echo htmlspecialchars($periodLabel);
                                ?>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200 font-medium">
                                <?php echo htmlspecialchars(formatCurrency($r['total_amount'] ?? 0)); ?>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo (int)($r['tx_count'] ?? 0); ?></td>
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200">
                                <?php echo htmlspecialchars(formatCurrency($r['avg_amount'] ?? 0)); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php elseif (!empty($collections_fallback) || !empty($collections_fallback_unrestricted)): ?>
                        <?php
                            $useRows = !empty($collections_fallback) ? $collections_fallback : $collections_fallback_unrestricted;
                        ?>
                        <?php foreach ($useRows as $r): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200">
                                <?php echo htmlspecialchars(date('d/m/Y', strtotime($r['pay_day']))); ?>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200 font-medium">
                                <?php echo htmlspecialchars(formatCurrency($r['total_amount'] ?? 0)); ?>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo (int)($r['tx_count'] ?? 0); ?></td>
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200">
                                <?php echo htmlspecialchars(formatCurrency($r['avg_amount'] ?? 0)); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">لا توجد بيانات للعرض</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- توزيع حالات المتدربين -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 card-hover">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
            <span class="section-icon purple" title="توزيع حالات المتدربين"><i class="bx bx-pie-chart-alt"></i></span>
            <span class="section-text">توزيع حالات المتدربين</span>
        </h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الحالة</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">العدد</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">النسبة</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php 
                    // التأكد من أن $statusDist هو مصفوفة
                    $statusDist = is_array($statusDist) ? $statusDist : [];
                    if (empty($statusDist)): ?>
                    <tr>
                        <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">لا توجد بيانات للعرض</td>
                    </tr>
                    <?php else: ?>
                    <?php 
                    $totalTrainees = array_sum(array_map('intval', $statusDist));
                    foreach ($statusDist as $status => $count): 
                        $percentage = $totalTrainees > 0 ? ($count / $totalTrainees) * 100 : 0;
                        $statusLabel = translateTraineeStatus($status);
                    ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($statusLabel); ?></td>
                        <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo (int)$count; ?></td>
                        <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200">
                            <div class="flex items-center gap-2">
                                <span><?php echo number_format($percentage, 1); ?>%</span>
                                <div class="progress-bar w-16">
                                    <div class="progress-fill bg-purple-500" style="width: <?php echo number_format($percentage, 1); ?>%"></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- المتعثرون مع نظام التقسيم -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 mb-6 card-hover">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
            <span class="section-icon red" title="المتعثرون"><i class="bx bx-alarm-exclamation"></i></span>
            <span class="section-text">قائمة المتعثرين في السداد</span>
        </h3>
        <button id="showDelinquentsOverlay" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg flex items-center gap-2">
            <i class="bx bx-expand-alt"></i>
            <span>عرض مفصل</span>
        </button>
    </div>
    
    <!-- معلومات التقسيم -->
    <div class="pagination-info mb-4">
        عرض <?php echo min($delinquentsPerPage, count($delinquents)); ?> من أصل <?php echo $totalDelinquents; ?> متعثر
        - الصفحة <?php echo $delinquentsPage; ?> من <?php echo $totalDelinquentsPages; ?>
    </div>
    
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">المتدرب</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الدبلوم</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">المديونية</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">آخر تواصل</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">نتيجة التواصل</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($delinquents)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">لا توجد بيانات للعرض</td>
                </tr>
                <?php else: ?>
                <?php foreach ($delinquents as $delinquent): ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                    <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200">
                        <div class="font-medium"><?php echo htmlspecialchars($delinquent['full_name'] ?? 'غير معروف'); ?></div>
                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($delinquent['phone'] ?? ''); ?></div>
                    </td>
                    <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($delinquent['diploma_name'] ?? 'غير محدد'); ?></td>
                    <td class="px-6 py-3 text-sm font-medium text-red-600 dark:text-red-400">
                        <?php echo htmlspecialchars(formatCurrency($delinquent['debt_amount'] ?? 0)); ?>
                    </td>
                    <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200">
                        <?php if (!empty($delinquent['last_contact'])): ?>
                            <?php echo date('Y-m-d', strtotime($delinquent['last_contact'])); ?>
                            <div class="text-xs text-gray-500">بواسطة: <?php echo htmlspecialchars($delinquent['last_contacted_by'] ?? 'غير معروف'); ?></div>
                        <?php else: ?>
                            <span class="text-red-500">لم يتم التواصل بعد</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-3 text-sm">
                        <span class="px-2 py-1 rounded-full text-xs 
                            <?php echo match($delinquent['contact_result'] ?? '') {
                                'promised_payment' => 'badge-success',
                                'refused_payment' => 'badge-danger',
                                'not_available' => 'badge-warning',
                                default => 'badge-info'
                            }; ?>">
                            <?php echo translateContactResult($delinquent['contact_result'] ?? ''); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- نظام التقسيم -->
    <?php if ($totalDelinquentsPages > 1): ?>
    <div class="pagination mt-4">
        <!-- زر الصفحة الأولى -->
        <button onclick="changeDelinquentsPage(1)" <?php echo $delinquentsPage <= 1 ? 'disabled' : ''; ?>>
            <i class="bx bx-chevrons-right"></i>
        </button>
        
        <!-- زر الصفحة السابقة -->
        <button onclick="changeDelinquentsPage(<?php echo $delinquentsPage - 1; ?>)" <?php echo $delinquentsPage <= 1 ? 'disabled' : ''; ?>>
            <i class="bx bx-chevron-right"></i>
        </button>
        
        <!-- أرقام الصفحات -->
        <?php
        $startPage = max(1, $delinquentsPage - 2);
        $endPage = min($totalDelinquentsPages, $delinquentsPage + 2);
        
        for ($i = $startPage; $i <= $endPage; $i++):
        ?>
            <button 
                onclick="changeDelinquentsPage(<?php echo $i; ?>)" 
                class="<?php echo $i == $delinquentsPage ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </button>
        <?php endfor; ?>
        
        <!-- زر الصفحة التالية -->
        <button onclick="changeDelinquentsPage(<?php echo $delinquentsPage + 1; ?>)" <?php echo $delinquentsPage >= $totalDelinquentsPages ? 'disabled' : ''; ?>>
            <i class="bx bx-chevron-left"></i>
        </button>
        
        <!-- زر الصفحة الأخيرة -->
        <button onclick="changeDelinquentsPage(<?php echo $totalDelinquentsPages; ?>)" <?php echo $delinquentsPage >= $totalDelinquentsPages ? 'disabled' : ''; ?>>
            <i class="bx bx-chevrons-left"></i>
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- إحصائيات حالة التواصل -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 card-hover">
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
        <span class="section-icon purple" title="إحصائيات حالة التواصل"><i class="bx bx-stats"></i></span>
        <span class="section-text">إحصائيات حالة التواصل</span>
    </h3>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php 
        // التأكد من أن $contactStatusStats هو مصفوفة وليس فارغاً
        if (!empty($contactStatusStats) && is_array($contactStatusStats)): 
            foreach ($contactStatusStats as $stat): 
                if (is_array($stat)): // تأكد أن كل عنصر مصفوفة
        ?>
        <div class="bg-gradient-to-r from-purple-50 to-indigo-50 dark:from-purple-900/20 dark:to-indigo-900/20 rounded-lg p-4 border border-purple-100 dark:border-purple-800">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm text-purple-600 dark:text-purple-400 font-medium">
                        <?php echo translateContactResult($stat['contact_result'] ?? ''); ?>
                    </div>
                    <div class="text-2xl font-bold text-purple-700 dark:text-purple-300 mt-1">
                        <?php echo number_format($stat['count'] ?? 0); ?>
                    </div>
                    <div class="text-xs text-purple-500 dark:text-purple-400 mt-1">
                        <?php echo number_format($stat['percentage'] ?? 0, 1); ?>%
                    </div>
                </div>
                <i class="bx bx-message-rounded text-2xl text-purple-500"></i>
            </div>
        </div>
        <?php 
                endif;
            endforeach; 
        else: 
        ?>
        <div class="col-span-4 text-center py-8 text-gray-500 dark:text-gray-400">
            <i class="bx bx-stats text-4xl mb-4"></i>
            <p>لا توجد بيانات إحصائيات حالة التواصل</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- أوفر فلو لقائمة المتعثرين -->
<div id="delinquentsOverlay" class="overlay">
    <div class="overlay-content bg-white dark:bg-gray-800">
        <div class="flex items-center justify-between mb-4 p-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">قائمة المتعثرين في السداد</h3>
            <button id="closeDelinquentsOverlay" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                <i class="bx bx-x text-2xl"></i>
            </button>
        </div>
        <div class="p-4 max-h-96 overflow-y-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50 sticky top-0">
                    <tr>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">المتدرب</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الدبلوم</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">المديونية</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">آخر تواصل</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">نتيجة التواصل</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">ملاحظات</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($delinquents)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">لا توجد بيانات للعرض</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($delinquents as $delinquent): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200">
                            <div class="font-medium"><?php echo htmlspecialchars($delinquent['full_name'] ?? 'غير معروف'); ?></div>
                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($delinquent['phone'] ?? ''); ?></div>
                        </td>
                        <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($delinquent['diploma_name'] ?? 'غير محدد'); ?></td>
                        <td class="px-6 py-3 text-sm font-medium text-red-600 dark:text-red-400">
                            <?php echo htmlspecialchars(formatCurrency($delinquent['debt_amount'] ?? 0)); ?>
                        </td>
                        <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200">
                            <?php if (!empty($delinquent['last_contact'])): ?>
                                <?php echo date('Y-m-d', strtotime($delinquent['last_contact'])); ?>
                                <div class="text-xs text-gray-500">بواسطة: <?php echo htmlspecialchars($delinquent['last_contacted_by'] ?? 'غير معروف'); ?></div>
                            <?php else: ?>
                                <span class="text-red-500">لم يتم التواصل بعد</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-3 text-sm">
                            <span class="px-2 py-1 rounded-full text-xs 
                                <?php echo match($delinquent['contact_result'] ?? '') {
                                    'promised_payment' => 'badge-success',
                                    'refused_payment' => 'badge-danger',
                                    'not_available' => 'badge-warning',
                                    default => 'badge-info'
                                }; ?>">
                                <?php echo translateContactResult($delinquent['contact_result'] ?? ''); ?>
                            </span>
                        </td>
                        <td class="px-6 py-3 text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate">
                            <?php echo htmlspecialchars($delinquent['notes'] ?? '-'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-gray-200 dark:border-gray-700 flex justify-end">
            <button id="closeDelinquentsOverlay2" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg">إغلاق</button>
        </div>
    </div>
</div>

<script>
    // إظهار/إخفاء الفلاتر على الأجهزة المحمولة (محمي - يتأكد من وجود العناصر)
    (function(){
        const filterToggle = document.getElementById('filterToggle');
        if (filterToggle) {
            filterToggle.addEventListener('click', function() {
                const form = document.querySelector('.filter-form');
                if (form) form.classList.toggle('active');
            });
        }

        // التحكم في أوفر فلو المتعثرين
        const showDelinquents = document.getElementById('showDelinquentsOverlay');
        const delinquentsOverlay = document.getElementById('delinquentsOverlay');
        const closeDelinquents = document.getElementById('closeDelinquentsOverlay');
        const closeDelinquents2 = document.getElementById('closeDelinquentsOverlay2');

        if (showDelinquents && delinquentsOverlay) {
            showDelinquents.addEventListener('click', function() { delinquentsOverlay.style.display = 'block'; });
        }
        if (closeDelinquents && delinquentsOverlay) {
            closeDelinquents.addEventListener('click', function() { delinquentsOverlay.style.display = 'none'; });
        }
        if (closeDelinquents2 && delinquentsOverlay) {
            closeDelinquents2.addEventListener('click', function() { delinquentsOverlay.style.display = 'none'; });
        }
        if (delinquentsOverlay) {
            delinquentsOverlay.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
        }
    })();

    // دالة تغيير صفحة المتعثرين
    function changeDelinquentsPage(page) {
        const url = new URL(window.location.href);
        url.searchParams.set('delinquents_page', page);
        window.location.href = url.toString();
    }

    // فلترة الموظفين باستخدام JavaScript (للتحديث الفوري)
    function filterEmployees() {
        const collectionRange = document.querySelector('select[name="employee_collection_range"]')?.value || 'all';
        const successRate = document.querySelector('select[name="employee_success_rate"]')?.value || 'all';
        const employeeCards = document.querySelectorAll('.performance-card');
        
        employeeCards.forEach(card => {
            const collections = parseFloat(card.dataset.collections || 0);
            const success = parseFloat(card.dataset.success || 0);
            
            let collectionMatch = true;
            switch(collectionRange) {
                case '0-5000':
                    collectionMatch = collections <= 5000;
                    break;
                case '5000-15000':
                    collectionMatch = collections > 5000 && collections <= 15000;
                    break;
                case '15000-30000':
                    collectionMatch = collections > 15000 && collections <= 30000;
                    break;
                case '30000+':
                    collectionMatch = collections > 30000;
                    break;
            }
            
            let successMatch = true;
            switch(successRate) {
                case '0-30':
                    successMatch = success <= 30;
                    break;
                case '30-60':
                    successMatch = success > 30 && success <= 60;
                    break;
                case '60-80':
                    successMatch = success > 60 && success <= 80;
                    break;
                case '80-100':
                    successMatch = success > 80;
                    break;
            }
            
            card.style.display = (collectionMatch && successMatch) ? 'block' : 'none';
        });
    }

    // إضافة أزرار الفلترة السريعة
    function addQuickFilters() {
        const quickFilters = document.querySelector('.quick-filters');
        if (quickFilters) {
            // إضافة حدث النقر للفلاتر السريعة
            document.querySelectorAll('.quick-filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const range = this.dataset.range;
                    const success = this.dataset.success;
                    
                    const colSelect = document.querySelector('select[name="employee_collection_range"]');
                    const successSelect = document.querySelector('select[name="employee_success_rate"]');
                    if (colSelect) colSelect.value = range;
                    if (successSelect) successSelect.value = success;
                    
                    // إزالة التفعيل من جميع الأزرار
                    document.querySelectorAll('.quick-filter-btn').forEach(b => {
                        b.classList.remove('bg-blue-500', 'text-white', 'dark:bg-blue-600');
                        b.classList.add('bg-gray-100', 'text-gray-700', 'dark:bg-gray-700', 'dark:text-gray-300');
                    });
                    
                    // تفعيل الزر الحالي
                    this.classList.remove('bg-gray-100', 'text-gray-700', 'dark:bg-gray-700', 'dark:text-gray-300');
                    this.classList.add('bg-blue-500', 'text-white', 'dark:bg-blue-600');
                    
                    filterEmployees();
                    // Use the new submit function
                    submitEmployeeFilters();
                });
            });
        }
    }

    // فرز الموظفين حسب المعيار المختار
    function sortEmployees() {
        const sortBy = document.querySelector('select[name="employee_sort_by"]')?.value || 'collections';
        const employeeCards = Array.from(document.querySelectorAll('.performance-card'));
        
        employeeCards.sort((a, b) => {
            let aValue, bValue;
            
            switch(sortBy) {
                case 'collections':
                    aValue = parseFloat(a.dataset.collections || 0);
                    bValue = parseFloat(b.dataset.collections || 0);
                    return bValue - aValue;
                case 'contacts':
                    aValue = parseFloat(a.dataset.contacts || 0);
                    bValue = parseFloat(b.dataset.contacts || 0);
                    return bValue - aValue;
                case 'success':
                    aValue = parseFloat(a.dataset.success || 0);
                    bValue = parseFloat(b.dataset.success || 0);
                    return bValue - aValue;
                case 'rating':
                    aValue = parseFloat(a.dataset.score || 0);
                    bValue = parseFloat(b.dataset.score || 0);
                    return bValue - aValue;
                default:
                    return 0;
            }
        });
        
        const container = document.querySelector('.performance-grid');
        if (container) {
            container.innerHTML = '';
            employeeCards.forEach(card => container.appendChild(card));
        }
    }

    // تهيئة الفلاتر عند تحميل الصفحة
    document.addEventListener('DOMContentLoaded', function() {
        addQuickFilters();
        filterEmployees();
        sortEmployees();
        
        // تفعيل الزر الحالي بناءً على الفلاتر المحددة
        const currentRange = '<?php echo $employeeCollectionRange; ?>';
        const currentSuccess = '<?php echo $employeeSuccessRate; ?>';
        
        document.querySelectorAll('.quick-filter-btn').forEach(btn => {
            if (btn.dataset.range === currentRange && btn.dataset.success === currentSuccess) {
                btn.classList.remove('bg-gray-100', 'text-gray-700', 'dark:bg-gray-700', 'dark:text-gray-300');
                btn.classList.add('bg-blue-500', 'text-white', 'dark:bg-blue-600');
            }
        });

        // إضافة أحداث التغيير للفلاتر - تحديث الصفحة عند التغيير
        const form = document.getElementById('employeeFiltersForm');
        if (form) {
            // Prevent default form submission
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                submitEmployeeFilters();
            });
            
            // Sort by change - only sort without page reload
            document.querySelector('select[name="employee_sort_by"]')?.addEventListener('change', function() {
                sortEmployees();
            });
            
            // Other filters - submit form
            document.querySelector('select[name="employee_collection_range"]')?.addEventListener('change', function() {
                submitEmployeeFilters();
            });
            
            document.querySelector('select[name="employee_success_rate"]')?.addEventListener('change', function() {
                submitEmployeeFilters();
            });
            
            // Date inputs - submit form
            document.querySelector('input[name="employee_start_date"]')?.addEventListener('change', function() {
                submitEmployeeFilters();
            });
            
            document.querySelector('input[name="employee_end_date"]')?.addEventListener('change', function() {
                submitEmployeeFilters();
            });
        }
    });

    // دالة لإرسال فلاتر الموظفين
    function submitEmployeeFilters() {
        const form = document.getElementById('employeeFiltersForm');
        if (form) {
            const params = new URLSearchParams(new FormData(form));
            const url = window.location.pathname + '?' + params.toString();
            window.location.href = url;
        }
    }

    // Render sparklines using Chart.js for each employee
    (function(){
        try {
            const series = <?php echo json_encode($employeeTimeSeries ?? []); ?>;
            Object.keys(series).forEach(eid => {
                const ctx = document.getElementById('spark-emp-' + eid);
                if (!ctx) return;
                const data = series[eid].amounts || [];
                new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: series[eid].labels || [],
                        datasets: [{ data: data, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.15)', fill: true, tension: 0.4, pointRadius: 0 }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { x: { display: false }, y: { display: false } },
                        elements: { line: { borderWidth: 2 } }
                    }
                });
            });
        } catch(e) {
            // ignore chart errors
            console.error('Chart init error', e);
        }
    })();

    // تحديث الفلاتر عند تغيير التواريخ - تنقل في نفس الصفحة بدلاً من فتح تبويب جديد
    document.querySelector('input[name="employee_start_date"]')?.addEventListener('change', function() {
        const form = this.form;
        if (form) {
            const params = new URLSearchParams(new FormData(form));
            window.location.href = window.location.pathname + '?' + params.toString();
        }
    });
    
    document.querySelector('input[name="employee_end_date"]')?.addEventListener('change', function() {
        const form = this.form;
        if (form) {
            const params = new URLSearchParams(new FormData(form));
            window.location.href = window.location.pathname + '?' + params.toString();
        }
    });

    // تفعيل فلتر الفترة: عند تغييره يتم إعادة تحميل الصفحة مع معامل period
    // استخدام تحديث مباشر لبارامترات الـ URL حتى نحافظ على بقية المعاملات بغض النظر عن بنية النماذج
    document.querySelector('select[name="period"]')?.addEventListener('change', function() {
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('period', this.value);
            // reset delinquents page to 1 when changing major filters
            if (url.searchParams.has('delinquents_page')) url.searchParams.set('delinquents_page', '1');
            window.location.href = url.toString();
        } catch (e) {
            // fallback: build from any existing filter-form if URL API not supported
            const form = this.form || document.querySelector('.filter-form');
            if (form) {
                const params = new URLSearchParams(new FormData(form));
                params.set('period', this.value);
                params.set('delinquents_page', '1');
                window.location.href = window.location.pathname + '?' + params.toString();
            }
        }
    });

    // تفعيل فلتر حالة التواصل: عند تغييره يتم إعادة تحميل الصفحة مع معامل contact_result
    document.querySelector('select[name="contact_result"]')?.addEventListener('change', function() {
        try {
            const url = new URL(window.location.href);
            if (this.value === '' || this.value === null) {
                url.searchParams.delete('contact_result');
            } else {
                url.searchParams.set('contact_result', this.value);
            }
            if (url.searchParams.has('delinquents_page')) url.searchParams.set('delinquents_page', '1');
            window.location.href = url.toString();
        } catch (e) {
            const form = this.form || document.querySelector('.filter-form');
            if (form) {
                const params = new URLSearchParams(new FormData(form));
                if (this.value === '' || this.value === null) params.delete('contact_result');
                else params.set('contact_result', this.value);
                params.set('delinquents_page', '1');
                window.location.href = window.location.pathname + '?' + params.toString();
            }
        }
    });

    // تفعيل فلتر حالة المتدرب
    document.querySelector('select[name="trainee_status"]')?.addEventListener('change', function() {
        try {
            const url = new URL(window.location.href);
            if (this.value === '' || this.value === null) {
                url.searchParams.delete('trainee_status');
            } else {
                url.searchParams.set('trainee_status', this.value);
            }
            if (url.searchParams.has('delinquents_page')) url.searchParams.set('delinquents_page', '1');
            window.location.href = url.toString();
        } catch (e) {
            const form = this.form || document.querySelector('.filter-form');
            if (form) {
                const params = new URLSearchParams(new FormData(form));
                if (this.value === '' || this.value === null) params.delete('trainee_status');
                else params.set('trainee_status', this.value);
                params.set('delinquents_page', '1');
                window.location.href = window.location.pathname + '?' + params.toString();
            }
        }
    });

    // زر إعادة التعيين - يحذف كل معاملات GET ويعيد تحميل الصفحة
    document.getElementById('resetAllFilters')?.addEventListener('click', function() {
        window.location.href = window.location.pathname;
    });
</script>

<?php include '../../includes/tw_layout_end.php'; ?>
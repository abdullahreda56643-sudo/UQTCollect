<?php
/**
 * لوحة تحكم المشرف
 * 
 * هذا الملف يعرض لوحة التحكم الرئيسية للمشرف مع الإحصائيات والبيانات المسموح له برؤيتها
 * 
 * @package    TraineeManagementSystem
 * @author     Developer
 * @version    1.0.0
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../db/connection.php';
require_once '../../includes/communication_helpers.php';

$page_title = 'لوحة تحكم المشرف';

// تنظيف رسالة الخطأ القديمة إذا كانت موجودة من تحميل سابق للصفحة
if (isset($_SESSION['error_message']) && !isset($_SESSION['fresh_error'])) {
    unset($_SESSION['error_message']);
}
unset($_SESSION['fresh_error']);

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/modules/auth/login.php');
    exit;
}

$userRole = $_SESSION['role'] ?? 'supervisor';
$userId = $_SESSION['user_id'] ?? 0;

if ($userRole === 'admin') {
    header('Location: ' . rtrim(SITE_URL, '/') . '/admin/dashboard.php');
    exit;
}

if ($userRole === 'employee') {
    header('Location: ' . rtrim(SITE_URL, '/') . '/dashboard/employee.php');
    exit;
}

// إنشاء اتصال قاعدة البيانات
$database = new Database();
$pdo = $database->getConnection();

// التحقق من وجود موظفين تحت إشراف المشرف
try {
    // جلب معلومات المشرف
    $supervisor_sql = "SELECT full_name FROM users WHERE id = ? AND role = 'supervisor'";
    $supervisor_stmt = $pdo->prepare($supervisor_sql);
    $supervisor_stmt->execute([$userId]);
    $supervisor = $supervisor_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$supervisor) {
        $_SESSION['error_message'] = 'عذراً، لا يمكنك الوصول إلى لوحة تحكم المشرف.';
        header('Location: ' . rtrim(SITE_URL, '/') . '/dashboard/employee.php');
        exit;
    }

    // جلب الموظفين التابعين للمشرف
    $emp_sql = "SELECT id, full_name FROM users WHERE supervisor_id = ? AND is_active = 1";
    $emp_stmt = $pdo->prepare($emp_sql);
    $emp_stmt->execute([$userId]);
    $employees = $emp_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // تجهيز معرفات الموظفين
    $employee_ids = array_column($employees, 'id');
    
    // إضافة معرف المشرف نفسه للحصول على المتدربين المسندين له مباشرة
    $employee_ids[] = $userId;
    // تأكد من أن مصفوفة المعرفات عبارة عن أرقام متسلسلة (مفتاح 0..n-1)
    $employee_ids = array_values(array_map('intval', array_filter($employee_ids, function($v){ return $v !== null && $v !== ''; })));
    
    // حفظ أسماء الموظفين للعرض في الصفحة
    $employee_names = array_column($employees, 'full_name');

} catch (PDOException $e) {
    error_log("Error fetching supervisor and employees: " . $e->getMessage());
    
    // تعيين القيم الأساسية للمتغيرات
    $employee_ids = [$userId];  // تضمين معرف المشرف على الأقل
    $employee_names = [];
    
    // عرض رسالة الخطأ فقط في حالة وجود مشكلة فعلية في قاعدة البيانات
    $errorCode = $e->getCode();
    if ($errorCode !== '00000' && $errorCode !== '42S02') {
        if (!empty($employee_ids)) {
            error_log("Database error in supervisor dashboard: " . $e->getMessage());
        }
    }
}

// بناء شرط للموظفين (فقط إذا كان لدينا معرفات موظفين)
if (!empty($employee_ids)) {
    $emp_placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
} else {
    $emp_placeholders = '?';
    $employee_ids = [$userId];
}

// تعريف متغير $stats لاستخدامه في الرسوم البيانية
$stats = [
    'status_paid' => 0,
    'status_delayed' => 0,
    'status_promised' => 0,
    'status_communicating' => 0
];

// جلب آخر التواصلات
$recentCommunications = [];
try {
    if (!empty($employee_ids)) {
        $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
        $comm_sql = "
            SELECT 
                c.id,
                c.contact_date,
                c.type as communication_type,
                c.result,
                c.notes,
                t.full_name as trainee_name,
                u.full_name as user_name,
                t.status as trainee_status
            FROM communications c
            JOIN trainees t ON c.trainee_id = t.id
            JOIN users u ON c.created_by = u.id
            WHERE t.assigned_to IN ($placeholders)
                  OR c.created_by IN ($placeholders)
            ORDER BY c.contact_date DESC
            LIMIT 5
        ";
        $comm_stmt = $pdo->prepare($comm_sql);
        // نضاعف مصفوفة المعرفات لأننا نستخدمها مرتين في الاستعلام
        $params = array_merge($employee_ids, $employee_ids);
        $comm_stmt->execute($params);
        $recentCommunications = $comm_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error fetching recent communications: " . $e->getMessage());
}

// استعلام لجلب إحصائيات حالات المتدربين (مشابه لـ admin.php)
try {
    if (empty($employee_ids)) {
        $stats = [
            'status_paid' => 0,
            'status_delayed' => 0,
            'status_promised' => 0,
            'status_communicating' => 0
        ];
    } else {
        $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
        $status_sql = "SELECT 
                        SUM(CASE WHEN t.status = 'paid' OR (COALESCE(p.total_paid, 0) >= d.cost) THEN 1 ELSE 0 END) as status_paid,
                        SUM(CASE WHEN t.status = 'delayed' OR 
                            (COALESCE(p.total_paid, 0) < d.cost AND t.last_contact < DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY))
                            THEN 1 ELSE 0 END) as status_delayed,
                        SUM(CASE WHEN t.status = 'promised_payment' AND t.next_action IS NOT NULL THEN 1 ELSE 0 END) as status_promised,
                        SUM(CASE WHEN t.last_contact >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY) THEN 1 ELSE 0 END) as status_communicating
                    FROM trainees t
                    LEFT JOIN diplomas d ON t.diploma_id = d.id
                    LEFT JOIN (
                        SELECT trainee_id, SUM(amount) as total_paid 
                        FROM payments 
                        GROUP BY trainee_id
                    ) p ON p.trainee_id = t.id
                    WHERE t.assigned_to IN ($placeholders)";
        
        $status_stmt = $pdo->prepare($status_sql);
        $status_stmt->execute($employee_ids);
        $stats_result = $status_stmt->fetch(PDO::FETCH_ASSOC);
        if ($stats_result) {
            $stats = $stats_result;
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching trainee status statistics: " . $e->getMessage());
}/**
 * استعلامات الإحصائيات العامة (مشابهة لـ admin.php مع إضافة فلتر الفروع)
 */
try {
    // إجمالي المتدربين
    if (empty($employee_ids)) {
        $trainees_count = 0;
    } else {
        $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
        $trainees_count_sql = "SELECT COUNT(*) as count FROM trainees WHERE assigned_to IN ($placeholders)";
        $trainees_count_stmt = $pdo->prepare($trainees_count_sql);
        $trainees_count_stmt->execute($employee_ids);
        $trainees_count = $trainees_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    // إجمالي المدفوعات
    if (empty($employee_ids)) {
        $payments_total = 0;
    } else {
        $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
        $payments_total_sql = "SELECT SUM(amount) as total 
                              FROM payments p
                              JOIN trainees t ON p.trainee_id = t.id
                              WHERE t.assigned_to IN ($placeholders)";
        $payments_total_stmt = $pdo->prepare($payments_total_sql);
        $payments_total_stmt->execute($employee_ids);
        $payments_total = $payments_total_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    }

    // عدد المدفوعات هذا الشهر
    if (empty($employee_ids)) {
        $monthly_payments = 0;
    } else {
        $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
        $monthly_payments_sql = "SELECT COUNT(*) as count 
                                FROM payments p
                                JOIN trainees t ON p.trainee_id = t.id
                                WHERE t.assigned_to IN ($placeholders)
                                AND MONTH(p.payment_date) = MONTH(CURRENT_DATE()) 
                                AND YEAR(p.payment_date) = YEAR(CURRENT_DATE())";
        $monthly_payments_stmt = $pdo->prepare($monthly_payments_sql);
        $monthly_payments_stmt->execute($employee_ids);
        $monthly_payments = $monthly_payments_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    // إجمالي المدفوعات هذا الشهر
    if (empty($employee_ids)) {
        $monthly_amount = 0;
    } else {
        $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
        $monthly_amount_sql = "SELECT SUM(p.amount) as total 
                              FROM payments p
                              JOIN trainees t ON p.trainee_id = t.id
                              WHERE t.assigned_to IN ($placeholders)
                              AND MONTH(p.payment_date) = MONTH(CURRENT_DATE()) 
                              AND YEAR(p.payment_date) = YEAR(CURRENT_DATE())";
        $monthly_amount_stmt = $pdo->prepare($monthly_amount_sql);
        $monthly_amount_stmt->execute($employee_ids);
        $monthly_amount = $monthly_amount_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    }

    // عدد المتدربين الجدد هذا الشهر
    if (empty($employee_ids)) {
        $new_trainees = 0;
    } else {
        $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
        $new_trainees_sql = "SELECT COUNT(*) as count 
                            FROM trainees 
                            WHERE assigned_to IN ($placeholders)
                            AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
                            AND YEAR(created_at) = YEAR(CURRENT_DATE())";
        $new_trainees_stmt = $pdo->prepare($new_trainees_sql);
        $new_trainees_stmt->execute($employee_ids);
        $new_trainees = $new_trainees_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    // عدد سجلات التواصل هذا الشهر
    if (empty($employee_ids)) {
        $monthly_contacts = 0;
    } else {
        $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
        $monthly_contacts_sql = "SELECT COUNT(*) as count 
                                FROM communications c
                                JOIN trainees t ON c.trainee_id = t.id
                                WHERE t.assigned_to IN ($placeholders)
                                AND MONTH(c.created_at) = MONTH(CURRENT_DATE()) 
                                AND YEAR(c.created_at) = YEAR(CURRENT_DATE())";
        $monthly_contacts_stmt = $pdo->prepare($monthly_contacts_sql);
        $monthly_contacts_stmt->execute($employee_ids);
        $monthly_contacts = $monthly_contacts_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

} catch (PDOException $e) {
    error_log('حدث خطأ في جلب الإحصائيات: ' . $e->getMessage());
    $trainees_count = $payments_total = $monthly_payments = $monthly_amount = $new_trainees = $monthly_contacts = 0;
}

/**
 * استعلامات البيانات الحديثة (مشابهة لـ admin.php مع إضافة فلتر الفروع)
 */

// أحدث المدفوعات
if (empty($employee_ids)) {
    $recent_payments = [];
} else {
    $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
    $recent_payments_sql = "SELECT p.*, t.full_name as trainee_name, u.full_name as user_name
                            FROM payments p
                            LEFT JOIN trainees t ON p.trainee_id = t.id
                            LEFT JOIN users u ON p.created_by = u.id
                            WHERE t.assigned_to IN ($placeholders)
                            ORDER BY p.created_at DESC
                            LIMIT 5";

    $recent_payments_stmt = $pdo->prepare($recent_payments_sql);
    $recent_payments_stmt->execute($employee_ids);
    $recent_payments = $recent_payments_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// أحدث المتدربين
if (empty($employee_ids)) {
    $recent_trainees = [];
} else {
    $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
    $recent_trainees_sql = "SELECT 
                            t.*,
                            b.name as branch_name,
                            d.name as diploma_name,
                            d.cost as diploma_cost,
                            COALESCE((
                                SELECT SUM(amount)
                                FROM payments p
                                WHERE p.trainee_id = t.id
                            ), 0) as total_paid
                            FROM trainees t
                            LEFT JOIN branches b ON t.branch_id = b.id
                            LEFT JOIN diplomas d ON t.diploma_id = d.id
                            WHERE t.assigned_to IN ($placeholders)
                            ORDER BY t.created_at DESC
                            LIMIT 5";

    $recent_trainees_stmt = $pdo->prepare($recent_trainees_sql);
    $recent_trainees_stmt->execute($employee_ids);
    $recent_trainees = $recent_trainees_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// أحدث سجلات التواصل
if (empty($employee_ids)) {
    $recent_contacts = [];
} else {
    $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
    $recent_contacts_sql = "SELECT c.*, t.full_name as trainee_name, u.full_name as user_name
                            FROM contacts c
                            LEFT JOIN trainees t ON c.trainee_id = t.id
                            LEFT JOIN users u ON c.created_by = u.id
                            WHERE t.assigned_to IN ($placeholders)
                            ORDER BY c.created_at DESC
                            LIMIT 5";

    $recent_contacts_stmt = $pdo->prepare($recent_contacts_sql);
    $recent_contacts_stmt->execute($employee_ids);
    $recent_contacts = $recent_contacts_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// إحصائيات المدفوعات اليومية (آخر 7 أيام)
if (empty($employee_ids)) {
    $daily_payments = [];
} else {
    $placeholders = str_repeat('?,', count($employee_ids) - 1) . '?';
    $daily_payments_sql = "SELECT 
                            DATE(payment_date) as payment_date,
                            COUNT(*) as payments_count,
                            SUM(amount) as daily_amount
                            FROM payments p
                            JOIN trainees t ON p.trainee_id = t.id
                            WHERE t.assigned_to IN ($placeholders)
                            AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                            GROUP BY DATE(payment_date)
                            ORDER BY payment_date DESC";

    $daily_payments_stmt = $pdo->prepare($daily_payments_sql);
    $daily_payments_stmt->execute($employee_ids);
    $daily_payments = $daily_payments_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// الحصول على قائمة الفروع التي يشرف عليها المستخدم لعرضها في لوحة التحكم
try {
    // Some installations don't have a separate `supervisors` table.
    // Prefer to use it when present; otherwise fall back to branches inferred
    // from trainees assigned to the supervisor/team.
    $tblStmt = $pdo->prepare("SELECT COUNT(*) as c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisors'");
    $tblStmt->execute();
    $hasSupervisorsTable = (int)($tblStmt->fetchColumn() ?? 0) > 0;

    if ($hasSupervisorsTable) {
        $branches_list_sql = "SELECT b.id, b.name 
                             FROM branches b
                             JOIN supervisors s ON b.id = s.branch_id
                             WHERE s.user_id = :user_id
                             ORDER BY b.name";
        $branches_list_stmt = $pdo->prepare($branches_list_sql);
        $branches_list_stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $branches_list_stmt->execute();
        $branches_list = $branches_list_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fallback: infer branches from trainees assigned to the supervisor/team
        if (!empty($employee_ids)) {
            $place = str_repeat('?,', count($employee_ids) - 1) . '?';
            $branches_list_sql = "SELECT DISTINCT b.id, b.name FROM branches b JOIN trainees t ON b.id = t.branch_id WHERE t.assigned_to IN ($place) ORDER BY b.name";
            $branches_list_stmt = $pdo->prepare($branches_list_sql);
            $branches_list_stmt->execute($employee_ids);
            $branches_list = $branches_list_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $branches_list = [];
        }
    }
} catch (PDOException $e) {
    $branches_list = [];
    error_log("Error fetching branches: " . $e->getMessage());
}

include '../../includes/tw_layout_start.php';
?>

<style>
/* Dark-mode and hover improvements for supervisor dashboard */
.content-card, .bg-gradient-to-br, .glass-card {
    transition: transform .18s ease, box-shadow .18s ease, filter .18s ease, background-color .18s ease;
}
.content-card:hover, .bg-gradient-to-br:hover, .glass-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 18px 40px rgba(2,6,23,0.55);
}
.bg-gradient-to-br:hover { filter: brightness(1.05) saturate(1.02); }
.dark .notification-item:hover, .dark .content-card .hover-row:hover, .dark table tr:hover td {
    background-color: rgba(255,255,255,0.02) !important;
}
.dark .panel-action:hover { background: rgba(255,255,255,0.02); transform: translateY(-2px); }
</style>

<div class="container-fluid px-6 mx-auto">
    <!-- Welcome Section -->
    <div class="grid gap-6 mb-8">
        <div class="w-full">
            <div class="bg-gradient-to-r from-blue-500 to-indigo-600 rounded-lg shadow-xl overflow-hidden transition-all duration-300">
                <div class="px-6 py-5">
                    <div class="flex items-center justify-between">
                        <div class="flex-1">
                            <h2 class="text-2xl font-bold text-white mb-2">مرحباً، <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'مستخدم'); ?>!</h2>
                            <p class="text-white/90">
                                <?php
                                $hour = date('H');
                                if ($hour < 12) {
                                    echo 'صباح الخير';
                                } elseif ($hour < 17) {
                                    echo 'مساء الخير';
                                } else {
                                    echo 'مساء الخير';
                                }
                                ?>
                                - مرحباً بك في لوحة تحكم المشرف
                            </p>
                            <?php if (!empty($branches_list)): ?>
                            <div class="mt-3">
                                <p class="text-white/80 text-sm">الفروع المشرف عليها:</p>
                                <div class="flex flex-wrap gap-2 mt-1">
                                    <?php foreach ($branches_list as $branch): ?>
                                        <span class="bg-white/20 text-white text-xs px-2 py-1 rounded"><?php echo htmlspecialchars($branch['name']); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-shrink-0 text-left">
                            <i class="fas fa-user-shield text-5xl text-white/75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- إحصائيات عامة -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="w-full">
            <div class="bg-gradient-to-br from-cyan-500 to-blue-500 rounded-lg shadow-md overflow-hidden h-full transition-all duration-300 hover:shadow-lg">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h6 class="text-sm font-medium text-white/90 mb-1">إجمالي المتدربين</h6>
                            <h3 class="text-2xl font-bold text-white mb-1"><?php echo number_format($trainees_count); ?></h3>
                            <p class="text-xs text-white/75">في الفروع المشرف عليها</p>
                        </div>
                        <div class="bg-white/20 p-3 rounded-full">
                            <i class="fas fa-users text-xl text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="w-full">
            <div class="bg-gradient-to-br from-emerald-500 to-green-500 rounded-lg shadow-md overflow-hidden h-full transition-all duration-300 hover:shadow-lg">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h6 class="text-sm font-medium text-white/90 mb-1">إجمالي المدفوعات</h6>
                            <h3 class="text-2xl font-bold text-white mb-1"><?php echo number_format($payments_total, 2); ?> ريال</h3>
                            <p class="text-xs text-white/75">في الفروع المشرف عليها</p>
                        </div>
                        <div class="bg-white/20 p-3 rounded-full">
                            <i class="fas fa-money-bill-wave text-xl text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="w-full">
            <div class="bg-gradient-to-br from-amber-500 to-yellow-500 rounded-lg shadow-md overflow-hidden h-full transition-all duration-300 hover:shadow-lg">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h6 class="text-sm font-medium text-white/90 mb-1">المدفوعات هذا الشهر</h6>
                            <h3 class="text-2xl font-bold text-white mb-1"><?php echo number_format($monthly_amount, 2); ?> ريال</h3>
                            <p class="text-xs text-white/75"><?php echo number_format($monthly_payments); ?> دفعة</p>
                        </div>
                        <div class="bg-white/20 p-3 rounded-full">
                            <i class="fas fa-calendar-alt text-xl text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="w-full">
            <div class="bg-gradient-to-br from-purple-500 to-violet-600 rounded-lg shadow-md overflow-hidden h-full transition-all duration-300 hover:shadow-lg">
                <div class="p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h6 class="text-sm font-medium text-white/90 mb-1">المتدربين الجدد</h6>
                            <h3 class="text-2xl font-bold text-white mb-1"><?php echo number_format($new_trainees); ?></h3>
                            <p class="text-xs text-white/75">هذا الشهر في الفروع المشرف عليها</p>
                        </div>
                        <div class="bg-white/20 p-3 rounded-full">
                            <i class="fas fa-user-plus text-xl text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- أحدث التواصلات -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow-md h-full overflow-hidden border border-gray-200">
                <div class="bg-gradient-to-r from-blue-600 to-blue-500 text-white px-4 py-3 flex justify-between items-center">
                    <h5 class="font-medium text-lg">
                        <i class="fas fa-comments mr-2"></i>
                        أحدث التواصلات
                    </h5>
                    <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/communications/list.php" class="bg-white/20 hover:bg-white/30 text-white text-xs px-3 py-1 rounded transition-colors duration-200">
                        عرض الكل
                    </a>
                </div>
                <div class="p-4">
                    <?php if (empty($recentCommunications)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-comments text-gray-400 text-4xl mb-3"></i>
                            <p class="text-gray-500">لا توجد تواصلات حديثة</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($recentCommunications as $comm): ?>
                                <div class="p-3 border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 ml-3">
                                            <?php
                                            $icon = 'phone';
                                            $color = 'blue';
                                            switch ($comm['communication_type']) {
                                                case 'whatsapp':
                                                    $icon = 'whatsapp';
                                                    $color = 'green';
                                                    break;
                                                case 'email':
                                                    $icon = 'envelope';
                                                    $color = 'purple';
                                                    break;
                                                case 'sms':
                                                    $icon = 'comment-sms';
                                                    $color = 'yellow';
                                                    break;
                                            }
                                            ?>
                                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-600">
                                                <i class="fas fa-<?php echo $icon; ?> text-sm"></i>
                                            </span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex justify-between items-center text-sm">
                                                <span class="font-medium text-gray-900 truncate">
                                                    <?php echo htmlspecialchars($comm['trainee_name']); ?>
                                                </span>
                                                <span class="text-xs text-gray-500">
                                                    <?php echo date('Y-m-d H:i', strtotime($comm['contact_date'])); ?>
                                                </span>
                                            </div>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <span class="font-medium"><?php echo htmlspecialchars(getResultLabel($comm['result'] ?? '')); ?></span>
                                            </p>
                                            <p class="text-sm text-gray-600 mt-1 line-clamp-2">
                                                <?php echo htmlspecialchars($comm['notes']); ?>
                                            </p>
                                            <p class="text-xs text-gray-500 mt-1">
                                                بواسطة: <?php echo htmlspecialchars($comm['user_name']); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- أحدث المدفوعات -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow-md h-full overflow-hidden border border-gray-200">
                <div class="bg-gradient-to-r from-green-600 to-green-500 text-white px-4 py-3 flex justify-between items-center">
                    <h5 class="font-medium text-lg">
                        <i class="fas fa-money-bill-wave mr-2"></i>
                        أحدث المدفوعات
                    </h5>
                    <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/payments/list.php" class="bg-white/20 hover:bg-white/30 text-white text-xs px-3 py-1 rounded transition-colors duration-200">
                        عرض الكل
                    </a>
                </div>
                <div class="p-4">
                    <?php if (empty($recent_payments)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-receipt text-gray-400 text-4xl mb-3"></i>
                            <p class="text-gray-500">لا توجد مدفوعات حديثة</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($recent_payments as $payment): ?>
                                <div class="p-3 border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h6 class="font-medium text-gray-800 dark:text-gray-200">
                                                <?php echo htmlspecialchars($payment['trainee_name'] ?? 'غير محدد'); ?>
                                            </h6>
                                            <p class="text-gray-500 text-xs mt-1">
                                                <i class="fas fa-building opacity-50 ml-1"></i>
                                                <?php echo htmlspecialchars($payment['branch_name'] ?? 'غير محدد'); ?>
                                            </p>
                                            <p class="text-gray-500 text-xs mt-1">
                                                <i class="fas fa-calendar-alt opacity-50 ml-1"></i>
                                                <?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?>
                                            </p>
                                            <p class="text-gray-500 text-xs mt-1">
                                                <i class="fas fa-user opacity-50 ml-1"></i>
                                                <?php echo htmlspecialchars($payment['user_name']); ?>
                                            </p>
                                        </div>
                                        <div class="text-left">
                                            <h6 class="font-medium text-green-600 text-lg">
                                                <?php echo number_format($payment['amount'], 2); ?> ريال
                                            </h6>
                                            <span class="inline-block px-2 py-1 mt-2 text-xs text-white bg-blue-600 rounded">
                                                <?php echo htmlspecialchars($payment['payment_method']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- أحدث المتدربين -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow-md h-full overflow-hidden border border-gray-200">
                <div class="bg-gradient-to-r from-blue-600 to-blue-500 text-white px-4 py-3 flex justify-between items-center">
                    <h5 class="font-medium text-lg">
                        <i class="fas fa-users mr-2"></i>
                        أحدث المتدربين
                    </h5>
                    <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/list.php" class="bg-white/20 hover:bg-white/30 text-white text-xs px-3 py-1 rounded transition-colors duration-200">
                        عرض الكل
                    </a>
                </div>
                <div class="p-4">
                    <?php if (empty($recent_trainees)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-user-plus text-gray-400 text-4xl mb-3"></i>
                            <p class="text-gray-500">لا توجد متدربين جدد</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($recent_trainees as $trainee): ?>
                                <div class="p-3 border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                                    <div class="flex items-start">
                                        <div class="flex-1">
                                            <h6 class="font-medium text-gray-800 dark:text-gray-200">
                                                <?php echo htmlspecialchars($trainee['full_name']); ?>
                                            </h6>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <i class="fas fa-graduation-cap opacity-50 ml-1"></i>
                                                <?php echo htmlspecialchars($trainee['diploma_name'] ?? 'غير محدد'); ?>
                                            </p>
                                            <p class="text-gray-500 text-xs mt-1">
                                                <i class="fas fa-building opacity-50 ml-1"></i>
                                                <?php echo htmlspecialchars($trainee['branch_name'] ?? 'غير محدد'); ?>
                                            </p>
                                            <p class="text-gray-500 text-xs mt-1">
                                                <i class="fas fa-calendar-alt opacity-50 ml-1"></i>
                                                <?php echo date('Y-m-d', strtotime($trainee['created_at'])); ?>
                                            </p>
                                        </div>
                                        <div class="text-left">
                                            <?php 
                                            $progress = 0;
                                            $diploma_cost = floatval($trainee['diploma_cost'] ?? 0);
                                            $total_paid = floatval($trainee['total_paid'] ?? 0);
                                            if ($diploma_cost > 0) {
                                                $progress = min(($total_paid / $diploma_cost) * 100, 100);
                                            }
                                            $statusColor = match($trainee['status'] ?? 'pending') {
                                                'paid' => 'bg-green-600',
                                                'delayed' => 'bg-red-600',
                                                'promised_payment' => 'bg-yellow-600',
                                                default => 'bg-blue-600'
                                            };
                                            ?>
                                            <div class="text-xs text-gray-500">السداد: <?php echo number_format($progress, 1); ?>%</div>
                                            <div class="w-24 h-2 bg-gray-200 rounded-full mt-1">
                                                <div class="h-full <?php echo $statusColor; ?> rounded-full" style="width: <?php echo $progress; ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- الرسم البياني للمدفوعات اليومية -->
    <div class="mt-8">
        <div class="w-full">
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="bg-gradient-to-r from-blue-600 to-indigo-700 text-white px-4 py-3">
                    <h5 class="font-medium text-lg">
                        <i class="fas fa-chart-line mr-2"></i>
                        المدفوعات اليومية (آخر 7 أيام)
                    </h5>
                </div>
                <div class="p-4">
                    <!-- رسم بياني للمدفوعات اليومية -->
                    <canvas id="dailyPaymentsChart" class="w-full" height="100"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- إجراءات سريعة - روابط سريعة للوظائف الأكثر استخداماً -->
    <div class="mt-8 mb-8">
        <div class="w-full">
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="bg-gradient-to-r from-blue-600 to-indigo-700 text-white px-4 py-3">
                    <h5 class="font-medium text-lg">
                        <i class="fas fa-bolt mr-2"></i>
                        إجراءات سريعة
                    </h5>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- إضافة متدرب جديد -->
                        <div>
                            <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/add.php" class="flex flex-col items-center justify-center p-6 border border-blue-200 rounded-lg hover:bg-blue-50 hover:shadow-md transition-all duration-300 h-full">
                                <i class="fas fa-user-plus text-3xl text-blue-600 mb-3"></i>
                                <span class="text-gray-700 font-medium">إضافة متدرب جديد</span>
                            </a>
                        </div>
                        <!-- إضافة مدفوعات جديدة -->
                        <div>
                            <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/payments/add.php" class="flex flex-col items-center justify-center p-6 border border-green-200 rounded-lg hover:bg-green-50 hover:shadow-md transition-all duration-300 h-full">
                                <i class="fas fa-money-bill-wave text-3xl text-green-600 mb-3"></i>
                                <span class="text-gray-700 font-medium">تسجيل دفعة</span>
                            </a>
                        </div>
                        <!-- إضافة سجل تواصل جديد -->
                        <div>
                            <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/contacts/add.php" class="flex flex-col items-center justify-center p-6 border border-cyan-200 rounded-lg hover:bg-cyan-50 hover:shadow-md transition-all duration-300 h-full">
                                <i class="fas fa-phone text-3xl text-cyan-600 mb-3"></i>
                                <span class="text-gray-700 font-medium">إضافة تواصل</span>
                            </a>
                        </div>
                        <!-- عرض التقارير -->
                        <div>
                            <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/reports/branch_reports.php" class="flex flex-col items-center justify-center p-6 border border-amber-200 rounded-lg hover:bg-amber-50 hover:shadow-md transition-all duration-300 h-full">
                                <i class="fas fa-chart-bar text-3xl text-amber-600 mb-3"></i>
                                <span class="text-gray-700 font-medium">تقارير الفروع</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- استدعاء مكتبة Chart.js للرسوم البيانية -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
/**
 * إعداد الرسم البياني للمدفوعات اليومية
 * يعرض المدفوعات اليومية خلال آخر 7 أيام
 */
const dailyPaymentsData = <?php echo json_encode($daily_payments); ?>;
const labels = dailyPaymentsData.map(item => item.payment_date).reverse();
const amounts = dailyPaymentsData.map(item => parseFloat(item.daily_amount)).reverse();

// إنشاء سياق الرسم البياني
const ctx = document.getElementById('dailyPaymentsChart').getContext('2d');

// تكوين الرسم البياني
const dailyPaymentsChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [{
            label: '(المدفوعات اليومية ريال)',
            data: amounts,
            backgroundColor: 'rgba(59, 130, 246, 0.8)',
            borderColor: '#3b82f6',
            borderWidth: 2,
            borderRadius: 8,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    color: document.documentElement.classList.contains('dark') ? '#f9fafb' : '#374151'
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280'
                },
                grid: {
                    color: document.documentElement.classList.contains('dark') ? '#374151' : '#e5e7eb'
                }
            },
            y: {
                ticks: {
                    color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280',
                    callback: function(value) {
                        return value.toLocaleString() + ' ريال';
                    }
                },
                grid: {
                    color: document.documentElement.classList.contains('dark') ? '#374151' : '#e5e7eb'
                }
            }
        },
        interaction: {
            intersect: false,
            mode: 'index'
        }
    }
});

// رسم بياني لتوزيع المتدربين
var ctx2 = document.getElementById('traineeDistributionChart');
if (ctx2) {
    var distributionChart = new Chart(ctx2, {
        type: 'doughnut',
        data: {
            labels: ['مدفوع', 'متعثر', 'وعد بالسداد', 'جار التواصل'],
            datasets: [{
                data: <?php echo json_encode([
                    $stats['status_paid'],
                    $stats['status_delayed'],
                    $stats['status_promised'],
                    $stats['status_communicating']
                ]); ?>,
                backgroundColor: ['#4e73df', '#e74a3b', '#f6c23e', '#36b9cc'],
                hoverBackgroundColor: ['#2e59d9', '#e02d1b', '#f4b619', '#2c9faf'],
                hoverBorderColor: "rgba(234, 236, 244, 1)",
            }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: document.documentElement.classList.contains('dark') ? '#f9fafb' : '#374151'
                    }
                }
            },
            cutout: '80%',
        },
    });
}
</script>

<?php
include '../../includes/tw_layout_end.php';
?>

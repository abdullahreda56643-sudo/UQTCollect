<?php
/**
 * لوحة تحكم الموظف
 * 
 * هذا الملف يعرض لوحة التحكم الرئيسية للموظف مع الإحصائيات والبيانات المسموح له برؤيتها
 * 
 * @package    TraineeManagementSystem
 * @author     Developer
 * @version    1.0.0
 */

require_once '../../config.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../db/connection.php';

$page_title = 'لوحة تحكم الموظف';

// التحقق من الجلسة
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/modules/auth/login.php');
    exit;
}

$userRole = $_SESSION['role'] ?? 'employee';
$userId = $_SESSION['user_id'] ?? 0;

// إذا كان المستخدم مديراً، نقوم بتوجيهه إلى لوحة التحكم الخاصة بالمدير
if ($userRole === 'admin') {
    header('Location: ' . rtrim(SITE_URL, '/') . '/admin/dashboard.php');
    exit;
}

// إنشاء اتصال قاعدة البيانات
$database = new Database();
$pdo = $database->getConnection();

// تعريف متغير $stats لاستخدامه في الرسوم البيانية
$stats = [
    'status_paid' => 0,
    'status_delayed' => 0,
    'status_promised' => 0,
    'status_communicating' => 0
];

// استعلام لجلب إحصائيات حالات المتدربين (فقط المتدربين الذين يتعامل معهم الموظف)
try {
    $status_sql = "SELECT 
                    SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as status_paid,
                    SUM(CASE WHEN payment_status = 'delayed' THEN 1 ELSE 0 END) as status_delayed,
                    SUM(CASE WHEN payment_status = 'promised' THEN 1 ELSE 0 END) as status_promised,
                    SUM(CASE WHEN payment_status = 'communicating' THEN 1 ELSE 0 END) as status_communicating
                FROM trainees
                WHERE assigned_to = :user_id";
    $status_stmt = $pdo->prepare($status_sql);
    $status_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $status_stmt->execute();
    $stats_result = $status_stmt->fetch(PDO::FETCH_ASSOC);
    if ($stats_result) {
        $stats = $stats_result;
    }
} catch (PDOException $e) {
    // تسجيل الخطأ دون إظهاره للمستخدم
    error_log("Error fetching trainee status statistics: " . $e->getMessage());
}

/**
 * استعلامات الإحصائيات العامة
 * جلب البيانات الإحصائية لعرضها في لوحة التحكم (فقط البيانات الخاصة بالموظف)
 */
try {
    // إجمالي المتدربين المسندين للموظف
    $trainees_count_sql = "SELECT COUNT(*) as count FROM trainees WHERE assigned_to = :user_id";
    $trainees_count_stmt = $pdo->prepare($trainees_count_sql);
    $trainees_count_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $trainees_count_stmt->execute();
    $trainees_count = $trainees_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // إجمالي المدفوعات التي سجلها الموظف
    $payments_total_sql = "SELECT SUM(amount) as total FROM payments WHERE created_by = :user_id";
    $payments_total_stmt = $pdo->prepare($payments_total_sql);
    $payments_total_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $payments_total_stmt->execute();
    $payments_total = $payments_total_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // عدد المدفوعات التي سجلها الموظف هذا الشهر
    $monthly_payments_sql = "SELECT COUNT(*) as count FROM payments 
                            WHERE created_by = :user_id 
                            AND MONTH(payment_date) = MONTH(CURRENT_DATE()) 
                            AND YEAR(payment_date) = YEAR(CURRENT_DATE())";
    $monthly_payments_stmt = $pdo->prepare($monthly_payments_sql);
    $monthly_payments_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $monthly_payments_stmt->execute();
    $monthly_payments = $monthly_payments_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // إجمالي المدفوعات التي سجلها الموظف هذا الشهر
    $monthly_amount_sql = "SELECT SUM(amount) as total FROM payments 
                          WHERE created_by = :user_id 
                          AND MONTH(payment_date) = MONTH(CURRENT_DATE()) 
                          AND YEAR(payment_date) = YEAR(CURRENT_DATE())";
    $monthly_amount_stmt = $pdo->prepare($monthly_amount_sql);
    $monthly_amount_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $monthly_amount_stmt->execute();
    $monthly_amount = $monthly_amount_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // عدد المتدربين الجدد المسندين للموظف هذا الشهر
    $new_trainees_sql = "SELECT COUNT(*) as count FROM trainees 
                        WHERE assigned_to = :user_id 
                        AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
                        AND YEAR(created_at) = YEAR(CURRENT_DATE())";
    $new_trainees_stmt = $pdo->prepare($new_trainees_sql);
    $new_trainees_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $new_trainees_stmt->execute();
    $new_trainees = $new_trainees_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // عدد سجلات التواصل التي سجلها الموظف هذا الشهر
    $monthly_contacts_sql = "SELECT COUNT(*) as count FROM contacts 
                            WHERE created_by = :user_id 
                            AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
                            AND YEAR(created_at) = YEAR(CURRENT_DATE())";
    $monthly_contacts_stmt = $pdo->prepare($monthly_contacts_sql);
    $monthly_contacts_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $monthly_contacts_stmt->execute();
    $monthly_contacts = $monthly_contacts_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'حدث خطأ في جلب الإحصائيات: ' . $e->getMessage();
    $trainees_count = $payments_total = $monthly_payments = $monthly_amount = $new_trainees = $monthly_contacts = 0;
}

/**
 * استعلامات البيانات الحديثة
 * جلب أحدث المدفوعات والمتدربين وسجلات التواصل التي سجلها الموظف
 */

// أحدث المدفوعات التي سجلها الموظف
$recent_payments_sql = "SELECT p.*, t.full_name as trainee_name, u.full_name as user_name
                        FROM payments p
                        LEFT JOIN trainees t ON p.trainee_id = t.id
                        LEFT JOIN users u ON p.created_by = u.id
                        WHERE p.created_by = :user_id
                        ORDER BY p.created_at DESC
                        LIMIT 5";

$recent_payments_stmt = $pdo->prepare($recent_payments_sql);
$recent_payments_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
$recent_payments_stmt->execute();
$recent_payments = $recent_payments_stmt->fetchAll(PDO::FETCH_ASSOC);

// أحدث المتدربين المسندين للموظف
$recent_trainees_sql = "SELECT t.*, b.name as branch_name, d.name as diploma_name
                        FROM trainees t
                        LEFT JOIN branches b ON t.branch_id = b.id
                        LEFT JOIN diplomas d ON t.diploma_id = d.id
                        WHERE t.assigned_to = :user_id
                        ORDER BY t.created_at DESC
                        LIMIT 5";

$recent_trainees_stmt = $pdo->prepare($recent_trainees_sql);
$recent_trainees_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
$recent_trainees_stmt->execute();
$recent_trainees = $recent_trainees_stmt->fetchAll(PDO::FETCH_ASSOC);

// أحدث سجلات التواصل التي سجلها الموظف
$recent_contacts_sql = "SELECT c.*, t.full_name as trainee_name, u.full_name as user_name
                        FROM contacts c
                        LEFT JOIN trainees t ON c.trainee_id = t.id
                        LEFT JOIN users u ON c.created_by = u.id
                        WHERE c.created_by = :user_id
                        ORDER BY c.created_at DESC
                        LIMIT 5";

$recent_contacts_stmt = $pdo->prepare($recent_contacts_sql);
$recent_contacts_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
$recent_contacts_stmt->execute();
$recent_contacts = $recent_contacts_stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات المدفوعات اليومية (آخر 7 أيام) التي سجلها الموظف
$daily_payments_sql = "SELECT 
                        DATE(payment_date) as payment_date,
                        COUNT(*) as payments_count,
                        SUM(amount) as daily_amount
                        FROM payments 
                        WHERE created_by = :user_id
                        AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                        GROUP BY DATE(payment_date)
                        ORDER BY payment_date DESC";

$daily_payments_stmt = $pdo->prepare($daily_payments_sql);
$daily_payments_stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
$daily_payments_stmt->execute();
$daily_payments = $daily_payments_stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/tw_layout_start.php';
?>

<style>
/* Dark-mode and hover improvements for employee dashboard */
.content-card, .bg-gradient-to-br, .glass-card {
    transition: transform .18s ease, box-shadow .18s ease, filter .18s ease, background-color .18s ease;
}
.content-card:hover, .bg-gradient-to-br:hover, .glass-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 14px 30px rgba(2,6,23,0.5);
}
.bg-gradient-to-br:hover { filter: brightness(1.04) saturate(1.02); }
.dark .notification-item:hover, .dark .content-card .hover-row:hover, .dark table tr:hover td {
    background-color: rgba(255,255,255,0.02) !important;
}
.dark .action-btn:hover { transform: translateY(-2px); opacity: 0.98; }
</style>

<?php
// إشعارات السطح: لا نحتاج تضمين مكون الإشعارات هنا لأنّه مضمّن في التخطيط العام (tw_layout_start.php)

// جلب إشعارات إسناد المتدربين غير المقروءة فقط (لإبرازها بلون مختلف أو تضمينها أعلى القائمة)
try {
    // Only fetch assignment notifications from the last 24 hours so they auto-hide after one day
    $assignmentsStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :user_id AND type = 'success' AND title LIKE 'تم إسناد %' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY created_at DESC LIMIT 5");
    $assignmentsStmt->execute([':user_id' => $userId]);
    $assignmentNotifications = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $assignmentNotifications = [];
}
?>

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
                                - مرحباً بك في لوحة تحكم الموظف
                            </p>
                        </div>
                        <div class="flex-shrink-0 text-left">
                            <i class="fas fa-user-tie text-5xl text-white/75"></i>
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
                            <h6 class="text-sm font-medium text-white/90 mb-1">المتدربين المسندين إليك</h6>
                            <h3 class="text-2xl font-bold text-white mb-1"><?php echo number_format($trainees_count); ?></h3>
                            <p class="text-xs text-white/75">المتدربين الذين تتعامل معهم</p>
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
                            <h6 class="text-sm font-medium text-white/90 mb-1">إجمالي مدفوعاتك</h6>
                            <h3 class="text-2xl font-bold text-white mb-1"><?php echo number_format($payments_total, 2); ?> ريال</h3>
                            <p class="text-xs text-white/75">المدفوعات التي سجلتها</p>
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
                            <h6 class="text-sm font-medium text-white/90 mb-1">مدفوعاتك هذا الشهر</h6>
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
                            <p class="text-xs text-white/75">المسندين إليك هذا الشهر</p>
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
        <!-- أحدث المدفوعات -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow-md h-full overflow-hidden border border-gray-200">
                   <div class="bg-gradient-to-r from-green-600 to-green-500 text-white px-4 py-3 flex justify-between items-center">
                    <h5 class="font-medium text-lg">
                        <i class="fas fa-money-bill-wave mr-2"></i>
                        أحدث مدفوعاتك
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
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <h6 class="font-medium text-gray-800 dark:text-gray-200">
                                                <?php echo htmlspecialchars($payment['trainee_name'] ?? 'غير محدد'); ?>
                                            </h6>
                                            <p class="text-gray-500 text-xs">
                                                <?php echo date('Y-m-d H:i', strtotime($payment['created_at'])); ?>
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <h6 class="font-medium text-green-600">
                                                <?php echo number_format($payment['amount'], 2); ?> ريال
                                            </h6>
                                            <span class="inline-block px-2 py-1 text-xs text-white bg-blue-600 rounded"><?php echo htmlspecialchars($payment['payment_method']); ?></span>
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
                        أحدث متدربيك
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
                                    <div class="flex items-center">
                                     
                                        <div class="flex-1">
                                            <h6 class="font-medium text-gray-800 dark:text-gray-200">
                                                <?php echo htmlspecialchars($trainee['full_name']); ?>
                                            </h6>
                                            <p class="text-gray-500 text-xs">
                                                <?php echo htmlspecialchars($trainee['branch_name'] ?? 'غير محدد'); ?>
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-xs text-gray-500">
                                                <?php echo date('Y-m-d', strtotime($trainee['created_at'])); ?>
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

        <!-- أحدث سجلات التواصل -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow-md h-full overflow-hidden border border-gray-200">
               <div class="bg-gradient-to-r from-indigo-900 to-indigo-700 text-white px-4 py-3 flex justify-between items-center">
                    <h5 class="font-medium text-lg">
                        <i class="fas fa-phone mr-2"></i>
                        أحدث تواصلاتك
                    </h5>
                    <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/communications/list.php" class="bg-white/20 ">
                        عرض الكل
                    </a>
                </div>
                <div class="p-4">
                    <?php if (empty($recent_contacts)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-comments text-gray-400 text-4xl mb-3"></i>
                            <p class="text-gray-500">لا توجد سجلات تواصل حديثة</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($recent_contacts as $contact): ?>
                                <div class="p-3 border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h6 class="font-medium text-gray-800 dark:text-gray-200">
                                                <?php echo htmlspecialchars($contact['trainee_name'] ?? 'غير محدد'); ?>
                                            </h6>
                                            <p class="text-gray-500 text-xs">
                                                <?php echo htmlspecialchars($contact['contact_type'] ?? 'غير محدد'); ?>
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <span class="block text-xs text-gray-500 mt-1">
                                                <?php echo date('Y-m-d H:i', strtotime($contact['created_at'])); ?>
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
    </div>

    <!-- الرسم البياني للمدفوعات اليومية -->
    <div class="mt-8">
        <div class="w-full">
            <div class="bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                <div class="bg-gradient-to-r from-blue-600 to-indigo-700 text-white px-4 py-3">
                    <h5 class="font-medium text-lg">
                        <i class="fas fa-chart-line mr-2"></i>
                        مدفوعاتك اليومية (آخر 7 أيام)
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
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        <!-- إضافة متدرب جديد -->
                        <div>
                            <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/tasks/add.php" class="flex flex-col items-center justify-center p-6 border border-blue-200 rounded-lg hover:bg-blue-50 hover:shadow-md transition-all duration-300 h-full">
                                <i class="fas fa-user-plus text-3xl text-blue-600 mb-3"></i>
                                <span class="text-gray-700 font-medium">إضافة مهمة</span>
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
                            <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/communications/add.php" class="flex flex-col items-center justify-center p-6 border border-cyan-200 rounded-lg hover:bg-cyan-50 hover:shadow-md transition-all duration-300 h-full">
                                <i class="fas fa-phone text-3xl text-cyan-600 mb-3"></i>
                                <span class="text-gray-700 font-medium">إضافة تواصل</span>
                            </a>
                        </div>
                        <!-- عرض تقاريري -->
                     
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- أنماط CSS مخصصة للوحة التحكم -->
<style>
/* تدرجات الألوان للبطاقات */
.bg-gradient-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
}

.bg-gradient-info {
    background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
}

.bg-gradient-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.bg-gradient-warning {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}

.bg-gradient-purple {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
}

/* تنسيق عناصر القوائم (المدفوعات، المتدربين، التواصل) */
.payment-item, .trainee-item, .contact-item {
    padding: 1rem 0;
    border-bottom: 1px solid #e5e7eb;
    transition: all 0.2s ease-in-out;
}

.dark .payment-item, .dark .trainee-item, .dark .contact-item {
    border-bottom-color: #374151;
}

.payment-item:last-child, .trainee-item:last-child, .contact-item:last-child {
    border-bottom: none;
}



/* تأثيرات البطاقات */
.card {
    transition: all 0.3s ease;
    border-radius: 0.75rem;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
}

.dark .card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.3) !important;
}

/* تأثيرات الأزرار */
.btn-outline-primary, .btn-outline-success, .btn-outline-info, .btn-outline-warning {
    transition: all 0.3s ease;
    border-radius: 0.5rem;
}/* تأثير التحويم على عناصر القوائم */
.payment-item:hover, .trainee-item:hover, .contact-item:hover {
    background-color: #f9fafb;
    margin: 0 -1rem;
    padding: 1rem;
    border-radius: 0.5rem;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.dark .payment-item:hover, .dark .trainee-item:hover, .dark .contact-item:hover {
    background-color: #374151;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}
.btn-outline-primary:hover, .btn-outline-success:hover, .btn-outline-info:hover, .btn-outline-warning:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

/* تأثيرات إضافية للتحويم */
.hover-shadow {
    transition: all 0.3s ease;
}

.hover-shadow:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.transition-all {
    transition: all 0.3s ease;
}
</style>

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
    type: 'bar',  // نوع الرسم البياني: أعمدة
    data: {
        labels: labels,  // تواريخ المدفوعات
        datasets: [{
            label: '(المدفوعات اليومية ريال)',
            data: amounts,  // قيم المدفوعات
            backgroundColor: 'rgba(59, 130, 246, 0.8)',  // لون الأعمدة
            borderColor: '#3b82f6',  // لون حدود الأعمدة
            borderWidth: 2,
            borderRadius: 8,  // تدوير زوايا الأعمدة
            borderSkipped: false,
        }]
    },
    // خيارات الرسم البياني
    options: {
        responsive: true,  // جعل الرسم البياني متجاوب مع حجم الشاشة
        maintainAspectRatio: false,  // السماح بتغيير نسبة العرض إلى الارتفاع
        
        // إعدادات العناصر المرئية
        plugins: {
            legend: {
                labels: {
                    // تغيير لون النص حسب وضع الإضاءة (مظلم/فاتح)
                    color: document.documentElement.classList.contains('dark') ? '#f9fafb' : '#374151'
                }
            }
        },
        
        // إعدادات المحاور
        scales: {
            // محور السينات (التواريخ)
            x: {
                ticks: {
                    color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280'
                },
                grid: {
                    color: document.documentElement.classList.contains('dark') ? '#374151' : '#e5e7eb'
                }
            },
            // محور الصادات (المبالغ)
            y: {
                ticks: {
                    color: document.documentElement.classList.contains('dark') ? '#9ca3af' : '#6b7280',
                    // تنسيق عرض المبالغ المالية
                    callback: function(value) {
                        return value.toLocaleString() + ' ريال';
                    }
                },
                grid: {
                    color: document.documentElement.classList.contains('dark') ? '#374151' : '#e5e7eb'
                }
            }
        },
        
        // إعدادات التفاعل
        interaction: {
            intersect: false,  // عدم اشتراط التقاطع المباشر للتفاعل
            mode: 'index'  // عرض جميع القيم عند نفس النقطة على المحور السيني
        }
    }
});

// تحديث الرسم البياني عند تغيير الوضع المظلم
document.addEventListener('DOMContentLoaded', function() {
    const darkModeToggle = document.querySelector('[x-data]');
    if (darkModeToggle) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    // إعادة إنشاء الرسم البياني مع الألوان الجديدة
                    dailyPaymentsChart.destroy();
                    dailyPaymentsChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'المدفوعات اليومية (ريال)',
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
                }
            });
        });
        observer.observe(document.documentElement, { attributes: true });
    }
});

// إضافة تأثيرات تفاعلية
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.card');
    
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.transition = 'all 0.3s ease';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // تأثيرات للعناصر عند التمرير
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, {
        threshold: 0.1
    });
    
    const animatedElements = document.querySelectorAll('.card, .payment-item, .trainee-item, .contact-item');
    animatedElements.forEach(element => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';
        element.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(element);
    });
});

  var chartCanvas = document.getElementById('traineeDistributionChart');
if (chartCanvas) {
    var ctx2 = chartCanvas.getContext('2d');
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
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}
</script>

<?php include '../../includes/tw_layout_end.php'; ?>


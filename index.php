<?php
session_start();
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/helpers.php';
require_once 'db/connection.php';
require_once 'models/BaseModel.php';
require_once 'models/Communication.php';
require_once 'models/AuditLog.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/modules/auth/login.php');
    exit;
}

// الاتصال بقاعدة البيانات
$database = null;
$db = null;
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    die('خطأ في الاتصال بقاعدة البيانات');
}

// التحقق من الصلاحية
try {
    checkPermission('user');
} catch (Exception $e) {
    error_log("Permission check error: " . $e->getMessage());
    header('Location: ' . rtrim(SITE_URL, '/') . '/modules/auth/login.php');
    exit;
}

// تحديد نوع لوحة التحكم حسب دور المستخدم
$userRole = $_SESSION['role'] ?? 'employee';
// Support a distinct supervisor dashboard type while reusing many admin queries
// Supervisor will see a scoped admin-like dashboard limited to their branch
if ($userRole === 'supervisor') {
    $dashboardType = 'supervisor';
} elseif ($userRole === 'admin') {
    $dashboardType = 'admin';
} else {
    $dashboardType = 'employee';
}

// أيقونات الوحدات (قابل للتوسيع)
$moduleIcons = [
    'trainees' => 'fa-users',
    'payments' => 'fa-money-bill-wave',
    'communications' => 'fa-phone',
    'tasks' => 'fa-tasks',
    'reports' => 'fa-chart-bar',
    'branches' => 'fa-code-branch',
    'users' => 'fa-user-friends',
    'diplomas' => 'fa-certificate',
    'settings' => 'fa-cogs',
    'notifications' => 'fa-bell',
    'backups' => 'fa-database'
];

// دالة حساب الترند
function calculateTrend($today, $yesterday)
{
    if ($yesterday == 0 && $today > 0) {
        return '+100%';
    } elseif ($yesterday == 0 && $today == 0) {
        return '0%';
    }
    $diff = (($today - $yesterday) / $yesterday) * 100;
    return ($diff >= 0 ? '+' : '') . number_format($diff, 1) . '%';
}

// تحضير بيانات لوحة تحكم الموظف
function prepareEmployeeDashboard($db, $user_id)
{
    $stats = [];

    try {
        // المتدربين المسندين
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM trainees WHERE assigned_to = :user_id");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $assigned_trainees = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // المتحصل اليوم
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(p.amount), 0) as total 
            FROM payments p
            JOIN trainees t ON p.trainee_id = t.id
                            WHERE t.assigned_to = :user_id 
                                AND p.payment_date >= CURDATE() 
                                AND p.payment_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $today_collected = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // المتحصل أمس للمقارنة
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(p.amount), 0) as total 
            FROM payments p
            JOIN trainees t ON p.trainee_id = t.id
                            WHERE t.assigned_to = :user_id 
                                AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) 
                                AND p.payment_date < CURDATE()
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $yesterday_collected = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // التواصل اليوم
        $stmt = $db->prepare("
            SELECT COUNT(*) as total
            FROM communications c
            JOIN trainees t ON c.trainee_id = t.id
                            WHERE t.assigned_to = :user_id 
                                AND c.contact_date >= CURDATE() 
                                AND c.contact_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $today_contacts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // التواصل أمس للمقارنة
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM communications c
            JOIN trainees t ON c.trainee_id = t.id
                            WHERE t.assigned_to = :user_id 
                                AND c.contact_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) 
                                AND c.contact_date < CURDATE()
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $yesterday_contacts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // المتحصل آخر 7 أيام
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(p.amount), 0) as total 
            FROM payments p
            JOIN trainees t ON p.trainee_id = t.id
            WHERE t.assigned_to = :user_id 
            AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $weekly_collected = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // المتحصل الأسبوع الماضي للمقارنة
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(p.amount), 0) as total 
            FROM payments p
            JOIN trainees t ON p.trainee_id = t.id
            WHERE t.assigned_to = :user_id 
            AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND p.payment_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $last_week_collected = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // التواصل آخر 7 أيام
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM communications c
            JOIN trainees t ON c.trainee_id = t.id
            WHERE t.assigned_to = :user_id 
            AND c.contact_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $weekly_contacts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // التواصل الأسبوع الماضي للمقارنة
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM communications c
            JOIN trainees t ON c.trainee_id = t.id
            WHERE t.assigned_to = :user_id 
            AND c.contact_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND c.contact_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $last_week_contacts = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // المهام المعلقة
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM tasks 
            WHERE assigned_to = :user_id AND status != 'completed'
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $pending_tasks = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // المديونيات
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(remaining_amount), 0) as total 
            FROM trainees 
            WHERE assigned_to = :user_id AND remaining_amount > 0
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $total_debt = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stats = [
            [
                'title' => 'المتدربين المسندين',
                'value' => number_format($assigned_trainees),
                'icon' => 'fas fa-users',
                'color' => 'bg-gradient-to-r from-blue-500 to-indigo-600',
                'icon_color' => 'text-indigo-500',
                'trend' => '—'
            ],
            [
                'title' => 'المتحصل اليوم',
                'value' => number_format($today_collected, 2) . ' ر.س',
                'icon' => 'fas fa-money-bill-wave',
                'color' => 'bg-gradient-to-r from-emerald-500 to-green-600',
                'icon_color' => 'text-emerald-600',
                'trend' => calculateTrend($today_collected, $yesterday_collected)
            ],
            [
                'title' => 'التواصل اليوم',
                'value' => number_format($today_contacts),
                'icon' => 'fas fa-phone',
                'color' => 'bg-gradient-to-r from-purple-500 to-fuchsia-600',
                'icon_color' => 'text-purple-600',
                'trend' => calculateTrend($today_contacts, $yesterday_contacts)
            ],
            [
                'title' => 'المتحصل أسبوعي',
                'value' => number_format($weekly_collected, 2) . ' ر.س',
                'icon' => 'fas fa-calendar-week',
                'color' => 'bg-gradient-to-r from-teal-500 to-cyan-600',
                'trend' => calculateTrend($weekly_collected, $last_week_collected)
            ],
            [
                'title' => 'التواصل أسبوعي',
                'value' => number_format($weekly_contacts),
                'icon' => 'fas fa-comments',
                'color' => 'bg-gradient-to-r from-violet-500 to-purple-600',
                'icon_color' => 'text-violet-600',
                'trend' => calculateTrend($weekly_contacts, $last_week_contacts)
            ],
            [
                'title' => 'المديونيات',
                'value' => number_format($total_debt, 2) . ' ر.س',
                'icon' => 'fas fa-exclamation-triangle',
                'color' => 'bg-gradient-to-r from-rose-500 to-red-600',
                'icon_color' => 'text-rose-600',
                'trend' => '—'
            ]
        ];

    } catch (Exception $e) {
        error_log("Error preparing employee stats: " . $e->getMessage());
        $stats = getDefaultStats();
    }

    return $stats;
}


// تحضير بيانات لوحة تحكم المدير
function prepareAdminDashboard($db)
{
    $stats = [];

    try {
        // تحديد شرط الفرع للمشرف
        $branchCondition = "";
        $params = [];

        if (isset($_SESSION['role']) && $_SESSION['role'] === 'supervisor' && isset($_SESSION['branch_id'])) {
            $branchCondition = "AND t.branch_id = :branch_id";
            $params[':branch_id'] = $_SESSION['branch_id'];
        }

        // بيانات اليوم
        $stmt = $db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM trainees t WHERE 1=1 {$branchCondition}) as total_trainees,
                (SELECT COUNT(*) FROM users u WHERE u.role = 'employee' AND u.is_active = 1) as total_employees,
                (SELECT COALESCE(SUM(p.amount), 0) 
                 FROM payments p 
                 JOIN trainees t ON p.trainee_id = t.id 
                 WHERE p.payment_date >= CURDATE() AND p.payment_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY) {$branchCondition}) as today_payments,
                (SELECT COUNT(*) 
                 FROM communications c 
                 JOIN trainees t ON c.trainee_id = t.id 
                 WHERE c.contact_date >= CURDATE() AND c.contact_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY) {$branchCondition}) as today_contacts,
                (SELECT COALESCE(SUM(GREATEST(t.remaining_amount, 0)), 0)
                 FROM trainees t
                 WHERE 1=1 {$branchCondition}) as total_debt,
                (SELECT COALESCE(SUM(p.amount), 0)
                 FROM payments p 
                 JOIN trainees t ON p.trainee_id = t.id 
                 WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) {$branchCondition}) as weekly_payments
        ");

        // إضافة معلمات الفرع إذا كان المستخدم مشرف
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
        }

        $stmt->execute();
        $today = $stmt->fetch(PDO::FETCH_ASSOC);

        // بيانات أمس
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(p.amount), 0) 
                 FROM payments p 
                 JOIN trainees t ON p.trainee_id = t.id 
                 WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND p.payment_date < CURDATE() {$branchCondition}) as yesterday_payments,
                (SELECT COUNT(*) 
                 FROM communications c 
                 JOIN trainees t ON c.trainee_id = t.id 
                 WHERE c.contact_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND c.contact_date < CURDATE() {$branchCondition}) as yesterday_contacts
        ");

        // إضافة معلمات الفرع إذا كان المستخدم مشرف
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
        }

        $stmt->execute();
        $yesterday = $stmt->fetch(PDO::FETCH_ASSOC);

        // بيانات الأسبوع الماضي
        $stmt = $db->prepare("
            SELECT 
                (SELECT COALESCE(SUM(p.amount), 0)
                 FROM payments p 
                 JOIN trainees t ON p.trainee_id = t.id 
                 WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND p.payment_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY) {$branchCondition}) as last_week_payments
        ");

        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
        }

        $stmt->execute();
        $last_week = $stmt->fetch(PDO::FETCH_ASSOC);

        $stats = [
            [
                'title' => 'إجمالي المتدربين',
                'value' => number_format($today['total_trainees']),
                'icon' => 'fas fa-users',
                'color' => 'bg-gradient-to-r from-blue-500 to-indigo-600',
                'trend' => '—'
            ],
            [
                'title' => 'عدد الموظفين',
                'value' => number_format($today['total_employees']),
                'icon' => 'fas fa-user-friends',
                'color' => 'bg-gradient-to-r from-cyan-500 to-blue-600',
                'trend' => '—'
            ],
            [
                'title' => 'المدفوعات اليوم',
                'value' => number_format($today['today_payments'], 2) . ' ر.س',
                'icon' => 'fas fa-money-bill-wave',
                'color' => 'bg-gradient-to-r from-emerald-500 to-green-600',
                'trend' => calculateTrend($today['today_payments'], $yesterday['yesterday_payments'])
            ],
            [
                'title' => 'التواصل اليوم',
                'value' => number_format($today['today_contacts']),
                'icon' => 'fas fa-phone',
                'color' => 'bg-gradient-to-r from-purple-500 to-fuchsia-600',
                'trend' => calculateTrend($today['today_contacts'], $yesterday['yesterday_contacts'])
            ],
            [
                'title' => 'المتحصل أسبوعي',
                'value' => number_format($today['weekly_payments'], 2) . ' ر.س',
                'icon' => 'fas fa-calendar-week',
                'color' => 'bg-gradient-to-r from-teal-500 to-cyan-600',
                'trend' => calculateTrend($today['weekly_payments'], $last_week['last_week_payments'])
            ],
            [
                'title' => 'إجمالي المديونيات',
                'value' => number_format($today['total_debt'], 2) . ' ر.س',
                'icon' => 'fas fa-exclamation-triangle',
                'color' => 'bg-gradient-to-r from-rose-500 to-red-600',
                'trend' => '—'
            ]
        ];

    } catch (Exception $e) {
        error_log("Error preparing admin stats: " . $e->getMessage());
        $stats = getDefaultStats();
    }

    return $stats;
}

// تحضير بيانات لوحة تحكم المشرف (نعرض بياناته الخاصة مثل لوحة الموظف)
function prepareSupervisorDashboard($db, $user_id)
{
    // حالياً نعرض نفس كروت الموظف للمشرف (بيانات المتدربين المسندين له والمتحصل والتواصل والمهام)
    return prepareEmployeeDashboard($db, $user_id);
}

function getDefaultStats()
{
    return [
        [
            'title' => 'الإجمالي',
            'value' => '0',
            'icon' => 'fas fa-chart-line',
            'color' => 'bg-gradient-to-r from-gray-500 to-gray-600',
            'trend' => '0%'
        ]
    ];
}

// تحضير البيانات للعرض
$currentUserId = $_SESSION['user_id'] ?? null;
if ($dashboardType === 'admin') {
    $stats = prepareAdminDashboard($db);
} elseif ($dashboardType === 'supervisor') {
    $stats = prepareSupervisorDashboard($db, $currentUserId);
} else {
    $stats = prepareEmployeeDashboard($db, $currentUserId);
}

// ملخص سريع لحساب الموظف (يستخدم لعرض كروت مختصرة خاصة بالموظف)
$employeeSummary = [];
// Build per-user summary for employees and supervisors (show their personal stats)
if (($dashboardType === 'employee' || $dashboardType === 'supervisor') && $currentUserId) {
    try {
        // المتدربين المسندين
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM trainees WHERE assigned_to = :user_id");
        $stmt->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $assigned = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // المتدربين المسددين (لمعدل الإنجاز)
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM trainees WHERE assigned_to = :user_id AND status = 'paid'");
        $stmt->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $paid = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // المهام المعلقة
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM tasks WHERE assigned_to = :user_id AND status != 'completed'");
        $stmt->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $pending = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // المتحصل اليوم
        $stmt = $db->prepare("SELECT COALESCE(SUM(p.amount),0) as total FROM payments p JOIN trainees t ON p.trainee_id = t.id WHERE t.assigned_to = :user_id AND p.payment_date >= CURDATE() AND p.payment_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)");
        $stmt->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $todayCollected = (float) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0.0);

        // المتحصل آخر 7 أيام
        $stmt = $db->prepare("SELECT COALESCE(SUM(p.amount),0) as total FROM payments p JOIN trainees t ON p.trainee_id = t.id WHERE t.assigned_to = :user_id AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
        $stmt->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $weeklyCollected = (float) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0.0);

        // المديونيات
        $stmt = $db->prepare("SELECT COALESCE(SUM(t.remaining_amount),0) as total FROM trainees t WHERE t.assigned_to = :user_id AND t.remaining_amount > 0");
        $stmt->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $totalDebt = (float) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0.0);

        $progressPercent = $assigned > 0 ? round(($paid / $assigned) * 100, 1) : 0.0;

        $employeeSummary = [
            'assigned' => $assigned,
            'paid' => $paid,
            'pending_tasks' => $pending,
            'today_collected' => $todayCollected,
            'weekly_collected' => $weeklyCollected,
            'total_debt' => $totalDebt,
            'progress_percent' => $progressPercent
        ];
    } catch (Exception $e) {
        error_log('Error fetching employee summary: ' . $e->getMessage());
        $employeeSummary = [
            'assigned' => 0,
            'paid' => 0,
            'pending_tasks' => 0,
            'today_collected' => 0.0,
            'weekly_collected' => 0.0,
            'total_debt' => 0.0,
            'progress_percent' => 0.0
        ];
    }
}

// جلب بيانات إضافية للمدير
$employeePerformance = [];
$dailyStats = [];
$debtDistribution = [];

if ($dashboardType === 'admin' || $dashboardType === 'supervisor') {
    // أداء الموظفين (مؤشرات مُجمعة لتفادي تكرار الصفوف)
    try {
        // Build a scoped WHERE clause so supervisors only see their employees
        $employeeWhere = "WHERE u.role = 'employee' AND u.is_active = 1";
        $binds = [];

        if ($dashboardType === 'supervisor' && $currentUserId) {
            // Prefer to use users.supervisor_id if the column exists, otherwise fall back to scoping by branch
            try {
                $colStmt = $db->prepare("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'supervisor_id'");
                $colStmt->execute();
                $hasSupervisorCol = (int) ($colStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) > 0;
            } catch (Exception $e) {
                $hasSupervisorCol = false;
            }

            if ($hasSupervisorCol) {
                $employeeWhere .= " AND u.supervisor_id = :supervisor_id";
                $binds[':supervisor_id'] = $currentUserId;
            } elseif (!empty($_SESSION['branch_id'])) {
                // Limit to employees who have trainees in the supervisor's branch
                $employeeWhere .= " AND EXISTS (SELECT 1 FROM trainees t2 WHERE t2.assigned_to = u.id AND t2.branch_id = :branch_id)";
                $binds[':branch_id'] = $_SESSION['branch_id'];
            }
        }

        $sql = "SELECT u.id, u.full_name,
                       COALESCE(t.trainee_count,0) AS trainee_count,
                       COALESCE(t.paid_trainees,0) AS paid_trainees,
                       COALESCE(t.delayed_trainees,0) AS delayed_trainees,
                       COALESCE(p.total_collected,0) AS total_collected,
                       COALESCE(t.total_debt,0) AS total_debt,
                       COALESCE(c.recent_contacts,0) AS recent_contacts
                FROM users u
                LEFT JOIN (
                    SELECT assigned_to AS uid,
                           COUNT(*) AS trainee_count,
                           SUM(status = 'paid') AS paid_trainees,
                           SUM(status = 'delayed') AS delayed_trainees,
                           COALESCE(SUM(remaining_amount),0) AS total_debt
                    FROM trainees
                    GROUP BY assigned_to
                ) t ON t.uid = u.id
                LEFT JOIN (
                    SELECT t.assigned_to AS uid, COALESCE(SUM(p.amount),0) AS total_collected
                    FROM payments p
                    JOIN trainees t ON p.trainee_id = t.id
                    WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    GROUP BY t.assigned_to
                ) p ON p.uid = u.id
                LEFT JOIN (
                    SELECT t.assigned_to AS uid, COUNT(DISTINCT c.id) AS recent_contacts
                    FROM communications c
                    JOIN trainees t ON c.trainee_id = t.id
                    WHERE c.contact_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    GROUP BY t.assigned_to
                ) c ON c.uid = u.id
                " . $employeeWhere . "
                ORDER BY total_collected DESC
                LIMIT 10";

        $stmt = $db->prepare($sql);
        foreach ($binds as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        $employeePerformance = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: list active employees with zero stats if query returned nothing
        if (empty($employeePerformance)) {
            $stmt = $db->prepare("SELECT id, full_name FROM users WHERE role = 'employee' AND is_active = 1 LIMIT 10");
            $stmt->execute();
            $emps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($emps as $e) {
                $employeePerformance[] = [
                    'id' => $e['id'],
                    'full_name' => $e['full_name'],
                    'trainee_count' => 0,
                    'paid_trainees' => 0,
                    'delayed_trainees' => 0,
                    'total_collected' => 0,
                    'total_debt' => 0,
                    'recent_contacts' => 0
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching employee performance: " . $e->getMessage());
        $employeePerformance = [];
    }

    // التواصل والمتحصل اليومي (robust aggregated queries to avoid multiplicative joins)
    try {
        $sql = "SELECT u.id, u.full_name,
                   COALESCE(c.tcnt, 0) AS today_contacts,
                   COALESCE(p.tsum, 0) AS today_collected
                FROM users u
                LEFT JOIN (
                    SELECT t.assigned_to AS uid, COUNT(DISTINCT c.trainee_id) AS tcnt
                    FROM communications c
                    JOIN trainees t ON c.trainee_id = t.id
                    WHERE c.contact_date >= CURDATE() AND c.contact_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                    GROUP BY t.assigned_to
                ) c ON c.uid = u.id
                LEFT JOIN (
                    SELECT t.assigned_to AS uid, COALESCE(SUM(p.amount),0) AS tsum
                    FROM payments p
                    JOIN trainees t ON p.trainee_id = t.id
                    WHERE p.payment_date >= CURDATE() AND p.payment_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                    GROUP BY t.assigned_to
                ) p ON p.uid = u.id
                WHERE u.role = 'employee' AND u.is_active = 1
                ORDER BY today_collected DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute();
        $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: ensure we have active employees listed (shouldn't be needed but kept as safeguard)
        if (empty($dailyStats)) {
            $stmt = $db->prepare("SELECT id, full_name FROM users WHERE role = 'employee' AND is_active = 1");
            $stmt->execute();
            $emps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($emps as $e) {
                $dailyStats[] = [
                    'id' => $e['id'],
                    'full_name' => $e['full_name'],
                    'today_contacts' => 0,
                    'today_collected' => 0
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching daily stats (aggregated): " . $e->getMessage());
        $dailyStats = [];
    }

    // توزيع المديونيات
    try {
        $stmt = $db->prepare("
            SELECT 
                d.name as diploma_name,
                b.name as branch_name,
                u.full_name as employee_name,
                COUNT(t.id) as trainee_count,
                COALESCE(SUM(t.remaining_amount), 0) as total_debt
            FROM trainees t
            LEFT JOIN diplomas d ON t.diploma_id = d.id
            LEFT JOIN branches b ON t.branch_id = b.id
            LEFT JOIN users u ON t.assigned_to = u.id
            WHERE t.remaining_amount > 0
            GROUP BY d.name, b.name, u.full_name
            ORDER BY total_debt DESC
            LIMIT 10
        ");
        $stmt->execute();
        $debtDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching debt distribution: " . $e->getMessage());
    }
}

// بيانات الموظف الإضافية
$employeeTraineesStatus = [];
$weeklyIndicators = [];

// جهز مؤشرات الأسبوعية إما لموظف/مشرف (مقيدة بالمستخدم) أو لمدير النظام (مجمعة)
if ((($dashboardType === 'employee') || ($dashboardType === 'supervisor')) && $currentUserId) {
    // توزيع المتدربين حسب الحالة
    try {
        $stmt = $db->prepare("
            SELECT 
                status,
                COUNT(*) as count
            FROM trainees
            WHERE assigned_to = :user_id
            GROUP BY status
        ");
        $stmt->bindParam(':user_id', $currentUserId);
        $stmt->execute();
        $employeeTraineesStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching employee trainees status: " . $e->getMessage());
    }

    // مؤشرات أسبوعية
    try {
        $stmt = $db->prepare("
        SELECT 
            DAYNAME(c.contact_date) as day_name,
            COUNT(DISTINCT c.trainee_id) as contacts_count
        FROM communications c
        JOIN trainees t ON c.trainee_id = t.id
        WHERE t.assigned_to = :user_id 
        AND c.contact_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DAYNAME(c.contact_date), DAYOFWEEK(c.contact_date)
        ORDER BY DAYOFWEEK(c.contact_date)
    ");
        $stmt->bindParam(':user_id', $currentUserId);
        $stmt->execute();
        $weeklyContacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // تحويل البيانات للتخطيط
        $dayNamesMap = [
            'Saturday' => 'السبت',
            'Sunday' => 'الأحد',
            'Monday' => 'الإثنين',
            'Tuesday' => 'الثلاثاء',
            'Wednesday' => 'الأربعاء',
            'Thursday' => 'الخميس',
            'Friday' => 'الجمعة'
        ];

        $weeklyIndicators['contacts'] = [
            'labels' => [],
            'data' => array_fill(0, 7, 0)
        ];

        foreach ($weeklyContacts as $contact) {
            $dayIndex = array_search(
                $dayNamesMap[$contact['day_name']],
                ['السبت', 'الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة']
            );
            if ($dayIndex !== false) {
                $weeklyIndicators['contacts']['data'][$dayIndex] = $contact['contacts_count'];
            }
        }
        $weeklyIndicators['contacts']['labels'] = ['السبت', 'الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];

    } catch (Exception $e) {
        error_log("Error fetching weekly indicators: " . $e->getMessage());
    }

    // نهاية تجهيز مؤشرات موظف/مشرف
} elseif ($dashboardType === 'admin') {
    // لمشرف النظام: نجمع بيانات التواصل لآخر 7 أيام عبر النظام كله (بدون تصفية حسب الموظف)
    try {
        $stmt = $db->prepare("\n            SELECT \n                DAYNAME(c.contact_date) as day_name,\n                COUNT(DISTINCT c.trainee_id) as contacts_count\n            FROM communications c\n            JOIN trainees t ON c.trainee_id = t.id\n            WHERE c.contact_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)\n            GROUP BY DAYNAME(c.contact_date), DAYOFWEEK(c.contact_date)\n            ORDER BY DAYOFWEEK(c.contact_date)\n        ");
        $stmt->execute();
        $weeklyContacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // تحويل البيانات للتخطيط (نفس الخريطة المستخدمة للموظف)
        $dayNamesMap = [
            'Saturday' => 'السبت',
            'Sunday' => 'الأحد',
            'Monday' => 'الإثنين',
            'Tuesday' => 'الثلاثاء',
            'Wednesday' => 'الأربعاء',
            'Thursday' => 'الخميس',
            'Friday' => 'الجمعة'
        ];

        $weeklyIndicators['contacts'] = [
            'labels' => [],
            'data' => array_fill(0, 7, 0)
        ];

        foreach ($weeklyContacts as $contact) {
            $mapped = $dayNamesMap[$contact['day_name']] ?? null;
            $dayIndex = $mapped !== null ? array_search(
                $mapped,
                ['السبت', 'الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة']
            ) : false;
            if ($dayIndex !== false) {
                $weeklyIndicators['contacts']['data'][$dayIndex] = $contact['contacts_count'];
            }
        }
        $weeklyIndicators['contacts']['labels'] = ['السبت', 'الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];

    } catch (Exception $e) {
        error_log("Error fetching weekly indicators (admin): " . $e->getMessage());
    }
}

// بيانات توزيع المتدربين للرسوم البيانية
$traineesDistribution = [];
try {
    // إذا كان المستخدم موظفًا أو مشرفًا نظهر بياناته الخاصة فقط
    if (($dashboardType === 'employee' || $dashboardType === 'supervisor') && $currentUserId) {
        $stmt = $db->prepare("
            SELECT 
                status,
                COUNT(*) as count
            FROM trainees
            WHERE assigned_to = :user_id
            GROUP BY status
        ");
        $stmt->bindParam(':user_id', $currentUserId);
        $stmt->execute();
        $distributionData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // عرض نظامي كامل فقط لمديري النظام (admin)
        $stmt = $db->prepare("
            SELECT 
                status,
                COUNT(*) as count
            FROM trainees
            GROUP BY status
        ");
        $stmt->execute();
        $distributionData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // تحويل البيانات
    $labels = [];
    $series = [];
    $colors = ['#10b981', '#ef4444', '#f59e0b', '#dc2626', '#6b7280', '#0ea5e9'];

    foreach ($distributionData as $data) {
        switch ($data['status']) {
            case 'paid':
                $statusName = 'مسدد';
                break;
            case 'delayed':
                $statusName = 'متأخر';
                break;
            case 'promised_payment':
                $statusName = 'وعد بالسداد';
                break;
            case 'refused_payment':
                $statusName = 'رافض السداد';
                break;
            case 'not_responding':
                $statusName = 'لا يرد';
                break;
            case 'under_communication':
                $statusName = 'قيد التواصل';
                break;
            default:
                $statusName = 'غير محدد';
        }

        $labels[] = $statusName;
        $series[] = (int) $data['count']; // 👈 تأكد إن القيم رقمية صحيحة
    }

    // 🔥 تصحيح تنسيق البيانات النهائي
    // نضمن أن السلاسل والملصقات مصفوفات رقمية عادية وليست كائنات
    $traineesDistribution = [
        'labels' => array_values($labels),
        'series' => array_values($series),
        'colors' => array_slice($colors, 0, count($labels)) // لتطابق عدد الألوان مع البيانات
    ];

    // في حالة لم يرجع أي صف من قاعدة البيانات
    if (empty($traineesDistribution['series'])) {
        $traineesDistribution = [
            'labels' => ['لا توجد بيانات'],
            'series' => [0],
            'colors' => ['#d1d5db']
        ];
    }

} catch (Exception $e) {
    error_log("Error fetching trainees distribution: " . $e->getMessage());
    $traineesDistribution = [
        'labels' => ['مسدد', 'متأخر', 'وعد بالسداد', 'رافض السداد', 'لا يرد', 'قيد التواصل'],
        'series' => [40, 25, 15, 10, 5, 20],
        'colors' => ['#10b981', '#ef4444', '#f59e0b', '#dc2626', '#6b7280', '#0ea5e9']
    ];
}

// بيانات المدفوعات الأسبوعية
// بيانات المدفوعات الأسبوعية
$paymentsData = [];
if (($dashboardType === 'employee' || $dashboardType === 'supervisor') && $currentUserId) {
    $stmt = $db->prepare("
        SELECT 
            DAYNAME(p.payment_date) as day_name,
            COALESCE(SUM(p.amount), 0) as total_amount
        FROM payments p
        JOIN trainees t ON p.trainee_id = t.id
        WHERE YEARWEEK(p.payment_date, 1) = YEARWEEK(CURDATE(), 1) AND t.assigned_to = :user_id
        GROUP BY DAYNAME(p.payment_date), DAYOFWEEK(p.payment_date)
        ORDER BY DAYOFWEEK(p.payment_date)
    ");
    $stmt->bindParam(':user_id', $currentUserId);
    $stmt->execute();
    $paymentsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->prepare("
        SELECT 
            DAYNAME(payment_date) as day_name,
            COALESCE(SUM(amount), 0) as total_amount
        FROM payments 
        WHERE YEARWEEK(payment_date, 1) = YEARWEEK(CURDATE(), 1)
        GROUP BY DAYNAME(payment_date), DAYOFWEEK(payment_date)
        ORDER BY DAYOFWEEK(payment_date)
    ");
    $stmt->execute();
    $paymentsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// تحويل أسماء الأيام إلى العربية
$dayNamesMap = [
    'Saturday' => 'السبت',
    'Sunday' => 'الأحد',
    'Monday' => 'الإثنين',
    'Tuesday' => 'الثلاثاء',
    'Wednesday' => 'الأربعاء',
    'Thursday' => 'الخميس',
    'Friday' => 'الجمعة'
];

$paymentsChartLabels = [];
$paymentsChartData = [];

foreach ($paymentsData as $payment) {
    $dayName = $dayNamesMap[$payment['day_name']] ?? $payment['day_name'];
    $paymentsChartLabels[] = $dayName;
    $paymentsChartData[] = (float) $payment['total_amount'];
}

if (empty($paymentsChartData)) {
    $paymentsChartLabels = ['السبت', 'الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
    $paymentsChartData = [2100, 3200, 1800, 2400, 2900, 3500, 4200];
}

// بيانات الأداء الشهري (يومياً داخل الشهر الحالي)
$monthlyPerformanceLabels = [];
$monthlyPerformanceData = [];
try {
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-d', strtotime('+1 month', strtotime($monthStart)));
    $daysInMonth = (int) date('t', strtotime($monthStart));

    // initialize labels/data
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $monthlyPerformanceLabels[] = (string) $d;
        $monthlyPerformanceData[] = 0.0;
    }

    if (($dashboardType === 'employee' || $dashboardType === 'supervisor') && $currentUserId) {
        $stmt = $db->prepare("SELECT DAY(p.payment_date) as day, COALESCE(SUM(p.amount),0) as total FROM payments p JOIN trainees t ON p.trainee_id = t.id WHERE p.payment_date >= :month_start AND p.payment_date < :month_end AND t.assigned_to = :user_id GROUP BY DAY(p.payment_date) ORDER BY DAY(p.payment_date)");
        $stmt->bindValue(':month_start', $monthStart);
        $stmt->bindValue(':month_end', $monthEnd);
        $stmt->bindValue(':user_id', $currentUserId);
    } else {
        $stmt = $db->prepare("SELECT DAY(payment_date) as day, COALESCE(SUM(amount),0) as total FROM payments WHERE payment_date >= :month_start AND payment_date < :month_end GROUP BY DAY(payment_date) ORDER BY DAY(payment_date)");
        $stmt->bindValue(':month_start', $monthStart);
        $stmt->bindValue(':month_end', $monthEnd);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $dayIndex = intval($r['day']) - 1;
        if ($dayIndex >= 0 && $dayIndex < $daysInMonth) {
            $monthlyPerformanceData[$dayIndex] = (float) $r['total'];
        }
    }
} catch (Exception $e) {
    error_log('Error fetching monthly performance: ' . $e->getMessage());
    // leave defaults (zeros)
}


// معلومات المستخدم
$avatarSrc = isset($_SESSION['avatar']) && $_SESSION['avatar'] ? $_SESSION['avatar'] :
    'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['full_name'] ?? 'User') . '&background=0ea5e9&color=fff&size=64';
$userName = h($_SESSION['full_name'] ?? 'مستخدم');
$userRoleText = 'موظف';
if ($dashboardType === 'admin') {
    $userRoleText = 'مدير النظام';
} elseif ($dashboardType === 'supervisor') {
    $userRoleText = 'مشرف الفرع';
}

// دالة لتحويل الوقت من 24 ساعة إلى 12 ساعة
function formatTime12Hour($timeString)
{
    return date('h:i A', strtotime($timeString));
}

// دالة لتحويل الوقت الحالي إلى 12 ساعة
function getCurrentTime12Hour()
{
    return date('h:i A');
}
// دالة لعرض كروت الإحصائيات
function displayStatCards($stats)
{
    $html = '';
    foreach ($stats as $index => $stat) {
        $html .= '
        <div class="stat-card p-6 animate-in delay-' . ($index % 4) . '">
            <div class="flex items-start justify-between mb-4">
                <div class="stat-icon ' . $stat['color'] . ' text-white">
                    <i class="' . $stat['icon'] . '"></i>
                </div>
                <span class="text-xs font-medium px-2 py-1 rounded-full ' .
            (strpos($stat['trend'], '+') !== false ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' :
                (strpos($stat['trend'], '-') !== false ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400' :
                    'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300')) . '">
                    ' . $stat['trend'] . '
                </span>
            </div>
            <div>
                <p class="text-sm font-medium text-secondary-600 dark:text-secondary-400">
                    <i class="fas fa-circle ' . (isset($stat['icon_color']) ? $stat['icon_color'] : 'text-red-500') . ' ml-2"></i>
                    ' . $stat['title'] . '
                </p>
                <p class="text-2xl font-bold text-secondary-900 dark:text-white mt-1">
                    ' . $stat['value'] . '
                </p>
            </div>
        </div>';
    }
    return $html;
}

// دالة لعرض بطاقة حساب الموظف (تظهر فقط للموظف)
function displayEmployeeAccountCard($avatarSrc, $userName, $userRoleText, $db, $userId)
{
    // جمع بعض البيانات السريعة الخاصة بالمستخدم
    $assignedCount = 0;
    $pendingTasks = 0;
    $todayCollected = 0.0;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM trainees WHERE assigned_to = :user_id");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $assignedCount = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $stmt = $db->prepare("SELECT COUNT(*) as total FROM tasks WHERE assigned_to = :user_id AND status != 'completed'");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $pendingTasks = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $stmt = $db->prepare("SELECT COALESCE(SUM(p.amount),0) as total FROM payments p JOIN trainees t ON p.trainee_id = t.id WHERE t.assigned_to = :user_id AND p.payment_date >= CURDATE() AND p.payment_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $todayCollected = (float) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0.0);
    } catch (Exception $e) {
        error_log('Error fetching employee account card data: ' . $e->getMessage());
    }

    $html = "<div class=\"content-card p-6 mb-6 flex items-center justify-between\">"
        . "<div class=\"flex items-center gap-4\">"
        . "<img src=\"" . h($avatarSrc) . "\" alt=\"avatar\" class=\"w-16 h-16 rounded-full object-cover border-2 border-white shadow-sm\">"
        . "<div>"
        . "<p class=\"text-lg font-semibold text-secondary-900 dark:text-white\">" . h($userName) . "</p>"
        . "<p class=\"text-sm text-secondary-500 dark:text-secondary-400\">" . h($userRoleText) . "</p>"
        . "<div class=\"mt-2 flex items-center gap-3 text-sm\">"
        . "<span class=\"px-2 py-1 bg-gray-100 dark:bg-gray-800 rounded-md\">المتدربين: " . number_format($assignedCount) . "</span>"
        . "<span class=\"px-2 py-1 bg-gray-100 dark:bg-gray-800 rounded-md\">المهام المعلقة: " . number_format($pendingTasks) . "</span>"
        . "</div></div></div>"
        . "<div class=\"text-right\">"
        . "<p class=\"text-lg font-bold text-emerald-600\">" . number_format($todayCollected, 2) . " ر.س</p>"
        . "<p class=\"text-sm text-secondary-500 dark:text-secondary-400\">المتحصل اليوم</p>"
        . "<div class=\"mt-4 flex justify-end gap-2\">"
        . "<a href=\"modules/trainees/list.php\" class=\"px-3 py-2 bg-primary-500 text-white rounded-md text-sm\">متدربي</a>"
        . "<a href=\"modules/payments/list.php\" class=\"px-3 py-2 bg-emerald-500 text-white rounded-md text-sm\">المدفوعات</a>"
        . "</div></div></div>";

    return $html;
}

// دالة لعرض أداء الموظفين (للمدير)
function displayEmployeePerformance($employees)
{
    $html = '';
    if (empty($employees)) {
        $html .= "<tr class=\"border-t border-secondary-100 dark:border-secondary-700\">\n"
            . "            <td colspan=\"7\" class=\"py-6 px-4 text-center text-secondary-500\">لا توجد بيانات أداء للموظفين حالياً.</td>\n"
            . "        </tr>";
        return $html;
    }

    foreach ($employees as $index => $employee) {
        $full_name = isset($employee['full_name']) ? h($employee['full_name']) : '—';
        $trainee_count = isset($employee['trainee_count']) ? (int) $employee['trainee_count'] : 0;
        $paid_trainees = isset($employee['paid_trainees']) ? (int) $employee['paid_trainees'] : 0;
        $delayed_trainees = isset($employee['delayed_trainees']) ? (int) $employee['delayed_trainees'] : 0;
        $total_collected = isset($employee['total_collected']) ? (float) $employee['total_collected'] : 0.0;
        $total_debt = isset($employee['total_debt']) ? (float) $employee['total_debt'] : 0.0;
        $recent_contacts = isset($employee['recent_contacts']) ? (int) $employee['recent_contacts'] : 0;

        $html .= "<tr class=\"border-t border-secondary-100 dark:border-secondary-700 hover:bg-gray-50 dark:hover:bg-gray-800\">\n"
            . "            <td class=\"py-3 px-4 font-medium\">" . $full_name . "</td>\n"
            . "            <td class=\"py-3 px-4 text-center\">" . number_format($trainee_count) . "</td>\n"
            . "            <td class=\"py-3 px-4 text-center\">\n"
            . "                <span class=\"text-green-600 font-medium\">" . number_format($paid_trainees) . "</span>\n"
            . "            </td>\n"
            . "            <td class=\"py-3 px-4 text-center\">\n"
            . "                <span class=\"text-red-600 font-medium\">" . number_format($delayed_trainees) . "</span>\n"
            . "            </td>\n"
            . "            <td class=\"py-3 px-4 text-center\">" . number_format($total_collected) . " ر.س</td>\n"
            . "            <td class=\"py-3 px-4 text-center\">" . number_format($total_debt) . " ر.س</td>\n"
            . "            <td class=\"py-3 px-4 text-center\">" . number_format($recent_contacts) . "</td>\n"
            . "        </tr>";
    }
    return $html;
}

// دالة لعرض التواصل اليومي (للمدير)
function displayDailyStats($dailyStats)
{
    $html = '';
    if (empty($dailyStats)) {
        // Friendly placeholder when no data is available
        $html .= "<div class=\"bg-gray-50 dark:bg-gray-800 rounded-lg p-6 text-center\">\n"
            . "            <p class=\"text-sm text-secondary-500 dark:text-secondary-400\">لا توجد بيانات للتواصل أو المتحصل اليوم.</p>\n"
            . "            <p class=\"mt-2 text-xs text-secondary-400\">تحقق من سجلات التواصل أو المدفوعات لليوم أو عدّل نطاق التاريخ.</p>\n"
            . "        </div>";
        return $html;
    }

    foreach ($dailyStats as $stat) {
        $full_name = isset($stat['full_name']) ? h($stat['full_name']) : '—';
        $today_contacts = isset($stat['today_contacts']) ? (int) $stat['today_contacts'] : 0;
        $today_collected = isset($stat['today_collected']) ? (float) $stat['today_collected'] : 0.0;

        $html .= "<div class=\"bg-gray-50 dark:bg-gray-800 rounded-lg p-4 flex items-center justify-between\">\n"
            . "            <div class=\"flex items-center space-x-3\">\n"
            . "                <div class=\"w-10 h-10 bg-primary-100 dark:bg-primary-900 rounded-full flex items-center justify-center\">\n"
            . "                    <i class=\"fas fa-user text-primary-600 dark:text-primary-400\"></i>\n"
            . "                </div>\n"
            . "                <div>\n"
            . "                    <p class=\"font-medium text-secondary-900 dark:text-white\">" . $full_name . "</p>\n"
            . "                    <p class=\"text-sm text-secondary-500 dark:text-secondary-400\">" . number_format($today_contacts) . " تواصل</p>\n"
            . "                </div>\n"
            . "            </div>\n"
            . "            <div class=\"text-right\">\n"
            . "                <p class=\"text-lg font-bold text-emerald-600\">" . number_format($today_collected) . " ر.س</p>\n"
            . "                <p class=\"text-sm text-secondary-500 dark:text-secondary-400\">المتحصل اليوم</p>\n"
            . "            </div>\n"
            . "        </div>";
    }
    return $html;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نظام إدارة المتدربين - لوحة التحكم</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts - Tajawal -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <!-- ApexCharts -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: { 50: '#f0f9ff', 100: '#e0f2fe', 200: '#bae6fd', 300: '#7dd3fc', 400: '#38bdf8', 500: '#0ea5e9', 600: '#0284c7', 700: '#0369a1', 800: '#075985', 900: '#0c4a6e' },
                        secondary: { 50: '#f8fafc', 100: '#f1f5f9', 200: '#e2e8f0', 300: '#cbd5e1', 400: '#94a3b8', 500: '#64748b', 600: '#475569', 700: '#334155', 800: '#1e293b', 900: '#0f172a' }
                    },
                    fontFamily: {
                        'sans': ['Tajawal', 'ui-sans-serif', 'system-ui']
                    }
                }
            }
        };

        // Backup panel handlers (admin only) - attach after DOM ready
        document.addEventListener('DOMContentLoaded', function () {
            var createBtn = document.getElementById('createBackupBtn');
            var backupSpinner = document.getElementById('backupSpinner');
            var loadMoreSpinner = document.getElementById('loadMoreSpinner');
            var loadMoreBtn = document.getElementById('loadMoreBtn');
            var backupSearch = document.getElementById('backupSearch');
            var backupSort = document.getElementById('backupSort');
            var backupSummary = document.getElementById('backupSummary');

            var state = { limit: 6, offset: 0, total: 0, loading: false, query: '', sort: 'time_desc' };

            function escapeHtml(str) {
                return String(str).replace(/[&<>"']+/g, function (s) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s]); });
            }
            function escapeHtmlAttr(str) {
                return String(str).replace(/['\"]/g, function (s) { return s === "'" ? "&#39;" : '&quot;'; });
            }

            function setStatus(msg, isError) {
                var el = document.getElementById('backupStatus');
                if (!el) return;
                el.textContent = msg || '';
                el.style.color = isError ? '#b91c1c' : '';
            }

            function renderBackupList(items, append) {
                var list = document.getElementById('backupList');
                if (!list) return;
                if (!append) list.innerHTML = '';
                if (!items || items.length === 0) {
                    if (!append) list.innerHTML = '<li class="text-secondary-500 p-2">لا توجد نسخ محفوظة</li>';
                    return;
                }
                items.forEach(function (b) {
                    var download = '<?php echo rtrim(SITE_URL, '/'); ?>' + '/backups/' + encodeURIComponent(b.name);
                    var li = document.createElement('li');
                    li.className = 'flex items-center justify-between p-2 border rounded-md bg-secondary-50 dark:bg-secondary-900';
                    li.innerHTML = '<div>' + escapeHtml(b.name) + '<div class="text-xs text-secondary-500">' + escapeHtml(b.time) + ' • ' + Math.round(b.size / 1024) + ' KB</div></div>' +
                        '<div class="flex items-center gap-2"><a href="' + download + '" class="backup-download-btn px-2 py-1 rounded">تحميل</a><button data-file="' + escapeHtmlAttr(b.name) + '" class="deleteBackupBtn px-2 py-1 bg-rose-500 text-white rounded text-xs">حذف</button></div>';
                    list.appendChild(li);
                });

                // rebind delete buttons inside list
                list.querySelectorAll('.deleteBackupBtn').forEach(function (btn) {
                    btn.removeEventListener('click', onDeleteClick);
                    btn.addEventListener('click', onDeleteClick);
                });
            }

            function onDeleteClick(e) {
                var file = this.getAttribute('data-file');
                if (!confirm('هل أنت متأكد من حذف ' + file + ' ?')) return;
                fetch('<?php echo rtrim(SITE_URL, '/'); ?>/modules/admin/backup_delete.php', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: new URLSearchParams({ file: file })
                }).then(function (res) { return res.json(); }).then(function (data) {
                    if (data && data.success) {
                        // refresh from start
                        state.offset = 0;
                        fetchBackups({ reset: true });
                    } else {
                        alert('فشل الحذف: ' + (data.message || 'خطأ'));
                    }
                }).catch(function () { alert('خطأ في الاتصال بالخادم'); });
            }

            function fetchBackups(opts) {
                opts = opts || {};
                if (state.loading) return;
                state.loading = true;
                if (opts.reset) {
                    state.offset = 0;
                    if (loadMoreBtn) loadMoreBtn.disabled = false;
                }
                if (opts.showSpinner) {
                    if (loadMoreBtn && !opts.reset) loadMoreSpinner.style.display = 'inline-block';
                    else if (backupSpinner) backupSpinner.style.display = 'inline-block';
                }

                var params = new URLSearchParams();
                params.set('limit', state.limit);
                params.set('offset', state.offset);
                if (state.query) params.set('search', state.query);
                if (state.sort) params.set('sort', state.sort);

                fetch('<?php echo rtrim(SITE_URL, '/'); ?>/modules/admin/backups_list.php?' + params.toString())
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            state.total = data.total || 0;
                            var append = !opts.reset && state.offset > 0;
                            renderBackupList(data.backups || [], append);
                            // update summary
                            if (backupSummary) {
                                var shown = Math.min(state.offset + (data.backups ? data.backups.length : 0), state.total || 0);
                                backupSummary.textContent = 'عرض ' + shown + ' من ' + (state.total || 0) + ' ملف';
                            }
                            // adjust offset
                            if (!opts.reset) state.offset += (data.backups ? data.backups.length : 0);
                            else state.offset = (data.backups ? data.backups.length : 0);

                            // disable load more if all loaded
                            if (state.offset >= state.total) {
                                if (loadMoreBtn) loadMoreBtn.disabled = true;
                            } else {
                                if (loadMoreBtn) loadMoreBtn.disabled = false;
                            }
                        }
                    }).catch(function () { /* ignore network errors */ })
                    .finally(function () {
                        state.loading = false;
                        if (backupSpinner) backupSpinner.style.display = 'none';
                        if (loadMoreSpinner) loadMoreSpinner.style.display = 'none';
                    });
            }

            // debounce helper
            function debounce(fn, delay) {
                var t;
                return function () { var args = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, args); }, delay); };
            }

            if (backupSearch) {
                backupSearch.addEventListener('input', debounce(function (e) {
                    state.query = e.target.value.trim();
                    fetchBackups({ reset: true, showSpinner: true });
                }, 450));
            }

            if (backupSort) {
                backupSort.addEventListener('change', function (e) {
                    state.sort = e.target.value || 'time_desc';
                    fetchBackups({ reset: true, showSpinner: true });
                });
            }

            if (loadMoreBtn) {
                loadMoreBtn.addEventListener('click', function () {
                    fetchBackups({ showSpinner: true });
                });
            }

            if (createBtn) {
                createBtn.addEventListener('click', function (e) {
                    var btn = this;
                    btn.disabled = true;
                    if (backupSpinner) backupSpinner.style.display = 'inline-block';
                    setStatus('جارٍ إنشاء النسخة...', false);
                    fetch('<?php echo rtrim(SITE_URL, '/'); ?>/modules/admin/backup.php', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: new URLSearchParams({ action: 'create' })
                    }).then(function (res) { return res.json(); }).then(function (data) {
                        if (data && data.success) {
                            setStatus('تم إنشاء النسخة: ' + (data.file || ''), false);
                            // refresh list from start so new file appears
                            fetchBackups({ reset: true, showSpinner: true });
                        } else {
                            setStatus('فشل إنشاء النسخة: ' + (data.message || 'خطأ'), true);
                        }
                    }).catch(function (err) {
                        setStatus('خطأ في الاتصال بالخادم', true);
                    }).finally(function () { btn.disabled = false; if (backupSpinner) backupSpinner.style.display = 'none'; });
                });
            }

            // toggle expanded
            var toggleBtn = document.getElementById('backupToggleBtn');
            var wrapper = document.querySelector('.backup-list-wrapper');
            if (toggleBtn && wrapper) {
                toggleBtn.addEventListener('click', function () {
                    var expanded = wrapper.classList.toggle('expanded');
                    toggleBtn.textContent = expanded ? 'عرض أقل' : 'عرض المزيد';
                    // if expanding and no items loaded yet, fetch more
                    if (expanded && state.offset === 0) fetchBackups({ reset: true });
                });
            }

            // initial load (only a few items)
            fetchBackups({ reset: true });
        });
    </script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background-color: #f8fafc;
        }

        .dark body {
            background-color: #0f172a;
        }

        /* Backup panel visual polish */
        #backupPanel {
            border: 1px solid rgba(14, 165, 233, 0.08);
            background: linear-gradient(180deg, rgba(14, 165, 233, 0.03) 0%, rgba(255, 255, 255, 0.6) 100%);
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(2, 132, 199, 0.06);
        }

        .dark #backupPanel {
            background: linear-gradient(180deg, rgba(2, 132, 199, 0.06) 0%, rgba(15, 23, 42, 0.6) 100%);
            border: 1px solid rgba(14, 165, 233, 0.12);
            box-shadow: 0 8px 20px rgba(2, 132, 199, 0.12);
        }

        /* Primary create button */
        #createBackupBtn {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.55rem 0.9rem;
            font-weight: 600;
            border-radius: 10px;
            background: linear-gradient(90deg, #06b6d4 0%, #0ea5e9 50%, #0284c7 100%);
            color: #fff;
            border: none;
            box-shadow: 0 6px 18px rgba(14, 165, 233, 0.18);
            transition: transform 0.12s ease, box-shadow 0.12s ease, opacity 0.12s ease;
            cursor: pointer;
        }

        #createBackupBtn:active {
            transform: translateY(1px) scale(0.998);
        }

        #createBackupBtn:hover {
            box-shadow: 0 10px 26px rgba(14, 165, 233, 0.24);
        }

        #createBackupBtn[disabled] {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.06);
        }

        /* Secondary action buttons (download/delete) */
        .deleteBackupBtn {
            padding: 0.35rem 0.6rem;
            border-radius: 8px;
            font-weight: 600;
            color: #fff;
            background: linear-gradient(90deg, #ef4444 0%, #f43f5e 100%);
            border: none;
            transition: transform 0.08s ease, opacity 0.12s ease;
            cursor: pointer;
        }

        .deleteBackupBtn:hover {
            transform: translateY(-1px);
        }

        .backup-download-btn {
            padding: 0.35rem 0.6rem;
            border-radius: 8px;
            font-weight: 600;
            color: #fff;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            text-decoration: none;
            display: inline-block;
        }

        /* Backup list items */
        #backupList li {
            padding: 0.6rem 0.8rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.8rem;
            transition: background 0.12s ease, transform 0.08s ease;
        }

        #backupList li:hover {
            transform: translateY(-3px);
            background: rgba(14, 165, 233, 0.02);
        }

        .backup-meta {
            font-size: 0.88rem;
            color: #475569;
        }

        .dark .backup-meta {
            color: #cbd5e1;
        }

        /* Status text */
        #backupStatus {
            font-weight: 600;
            color: #0f172a;
            padding-left: 0.4rem;
        }

        .dark #backupStatus {
            color: #e6f7ff;
        }

        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            height: 70px;
        }

        .dark .navbar {
            background: rgba(15, 23, 42, 0.95);
        }

        /* sidebar sizing and colors (centralized here) */
        :root {
            --sidebar-width: 17rem;
            /* 288px for desktop */
            --sidebar-width-sm: 14rem;
            /* 224px for mobile panel */
        }

        .sidebar {
            background: white;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
            border-right: 1px solid rgba(226, 232, 240, 0.8);
            width: var(--sidebar-width);
            min-width: 14rem;
            max-width: 20rem;
            overflow-y: auto;
        }

        .dark .sidebar {
            background: #0f172a;
            border-right: 1px solid rgba(51, 65, 85, 0.5);
        }


        .sidebar::-webkit-scrollbar {
            width: 3px;
            height: 3px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: #bfdbfe;
            /* very light blue */
            border-radius: 999px;
            transition: background 0.15s ease;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: #93c5fd;
            /* slightly darker on hover */
        }

        /* Firefox */
        .sidebar {
            scrollbar-width: thin;
            scrollbar-color: #bfdbfe transparent;
        }

        .dark .sidebar::-webkit-scrollbar-thumb {
            background: rgba(191, 219, 254, 0.5);
            /* semi-transparent very light blue */
        }

        .sidebar-item {
            transition: all 0.2s ease;
            border-radius: 10px;
            margin: 6px 12px;
            padding: 12px 16px;
            color: #64748b;
        }

        .sidebar-item:hover {
            background-color: #f1f5f9;
            color: #0ea5e9;
        }

        .dark .sidebar-item:hover {
            background-color: #1e293b;
            color: #38bdf8;
        }

        .sidebar-item.active {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
        }

        .stat-card {
            transition: all 0.3s ease;
            border-radius: 16px;
            background: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .dark .stat-card {
            background: #1e293b;
            border-color: #334155;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .content-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .dark .content-card {
            background: #1e293b;
            border-color: #334155;
        }

        .animate-in {
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>
</head>

<body
    class="bg-gray-50 text-secondary-800 dark:bg-gray-950 dark:text-gray-200 min-h-screen transition-colors duration-300">
    <!-- شريط التنقل العلوي -->
    <nav class="navbar fixed top-0 right-0 left-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-full">
            <div class="flex justify-between h-full items-center">
                <div class="flex items-center">
                    <button id="sidebarToggle"
                        class="lg:hidden p-2 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 mr-3">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div class="flex-shrink-0 flex items-center mr-4">
                        <img src="<?php echo rtrim(SITE_URL, '/'); ?>/public/assets/images/logo.png" alt="Logo"
                            class="h-16 w-auto">
                        <span class="mr-2 text-xl font-bold">المعهد التقني للتدريب</span>
                    </div>
                </div>

                <div class="flex items-center space-x-4 gap-4">
                    <!-- زر الوضع المظلم -->
                    <button id="darkModeToggle"
                        class="p-2.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700">
                        <i class="fas fa-moon"></i>
                    </button>

                    <!-- الملف الشخصي -->
                    <div class="relative">
                        <button
                            class="flex items-center space-x-3 p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">
                            <img class="w-9 h-9 rounded-full object-cover border-2 border-white dark:border-gray-700 shadow-sm"
                                src="<?php echo $avatarSrc; ?>" alt="Profile">
                            <span class="text-sm font-medium hidden md:block"><?php echo $userName; ?></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <?php if (!function_exists('link_is_active')) {
        function link_is_active($needle)
        {
            $current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            return strpos($current_path, $needle) !== false;
        }
    }
    ?>

    <!-- Desktop/mobile-compatible permission-aware sidebar (copied from includes/tw_layout_start.php) -->
    <aside id="sidebar" class="sidebar thin-scroll fixed top-0 right-0 bottom-0 transform -translate-x-full lg:translate-x-0 
        transition-transform duration-300 z-40 flex flex-col h-screen hidden md:flex
        bg-white dark:bg-gray-900 shadow-lg" style="width: var(--sidebar-width);">

        <!-- رأس الشريط الجانبي -->
        <div class="p-5 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-lg font-bold text-gray-800 dark:text-white">لوحة التحكم</h2>
        </div>

        <!-- المحتوى مع Scroll داخلي -->
        <div class="flex-1 p-4 space-y-6 sidebar-scroll thin-scroll"
            style="overflow-y:auto; -webkit-overflow-scrolling: touch; touch-action: pan-y; max-height: calc(100vh - 5.5rem);">
            <!-- القائمة الرئيسية -->
            <div class="space-y-2">
                <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">القائمة
                    الرئيسية</h3>
                <ul class="space-y-1">
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/index.php"
                            class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/index.php') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-chart-pie text-base ml-3"></i>
                            <span class="font-medium">لوحة التحكم</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- إدارة المتدربين -->
            <?php if (hasAccess('trainees', 'view') || hasAccess('payments', 'view')): ?>
                <div class="space-y-2">
                    <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">إدارة
                        المتدربين</h3>
                    <ul class="space-y-1">
                        <?php if (hasAccess('trainees', 'view')): ?>
                            <li>
                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/list.php"
                                    class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/trainees/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                    <i class="fas <?php echo $moduleIcons['trainees'] ?? 'fa-chart-pie'; ?> text-base ml-3"></i>
                                    <span class="font-medium">المتدربين</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (hasAccess('payments', 'view')): ?>
                            <li>
                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/payments/list.php"
                                    class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/payments/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                    <i
                                        class="fas <?php echo $moduleIcons['payments'] ?? 'fa-money-bill-wave'; ?> text-base ml-3"></i>
                                    <span class="font-medium">المدفوعات</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- العمليات اليومية -->
            <?php if (hasAccess('contacts', 'view') || hasAccess('tasks', 'view')): ?>
                <div class="space-y-2">
                    <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">
                        العمليات اليومية</h3>
                    <ul class="space-y-1">
                        <?php if (hasAccess('contacts', 'view')): ?>
                            <li>
                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/communications/list.php"
                                    class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/communications/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                    <i
                                        class="fas <?php echo $moduleIcons['communications'] ?? 'fa-phone'; ?> text-base ml-3"></i>
                                    <span class="font-medium">التواصل</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (hasAccess('tasks', 'view')): ?>
                            <li>
                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/tasks/list.php"
                                    class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/tasks/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                    <i class="fas <?php echo $moduleIcons['tasks'] ?? 'fa-tasks'; ?> text-base ml-3"></i>
                                    <span class="font-medium">المهام</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- الإدارة -->
            <?php
            $adminSectionVisible = ($dashboardType === 'admin' || $dashboardType === 'supervisor') || hasAccess('users', 'view') || hasAccess('reports', 'view') || hasAccess('branches', 'view') || hasAccess('diplomas', 'view');
            if ($adminSectionVisible): ?>
                <div class="space-y-2">
                    <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">الإدارة
                    </h3>
                    <ul class="space-y-1">
                        <?php if (hasAccess('reports', 'view')): ?>
                            <li>
                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/reports/financial.php"
                                    class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/reports/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                    <i class="fas <?php echo $moduleIcons['reports'] ?? 'fa-chart-bar'; ?> text-base ml-3"></i>
                                    <span class="font-medium">التقارير</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (hasAccess('branches', 'view')): ?>
                            <li>
                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/branches/list.php"
                                    class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/branches/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                    <i
                                        class="fas <?php echo $moduleIcons['branches'] ?? 'fa-code-branch'; ?> text-base ml-3"></i>
                                    <span class="font-medium">الفروع</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (hasAccess('users', 'view')): ?>
                            <li>
                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/users/list.php"
                                    class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/users/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                    <i class="fas <?php echo $moduleIcons['users'] ?? 'fa-user-friends'; ?> text-base ml-3"></i>
                                    <span class="font-medium">المستخدمين</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (hasAccess('diplomas', 'view')): ?>
                            <li>
                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/diplomas/list.php"
                                    class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/diplomas/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                    <i
                                        class="fas <?php echo $moduleIcons['diplomas'] ?? 'fa-certificate'; ?> text-base ml-3"></i>
                                    <span class="font-medium">الدبلومات</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if (isAdmin()): ?>
                            <li>
                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/settings/index.php"
                                    class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/settings/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                                    <i class="fas <?php echo $moduleIcons['settings'] ?? 'fa-cogs'; ?> text-base ml-3"></i>
                                    <span class="font-medium">الإعدادات</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- تسجيل الخروج -->
            <div class="border-t border-gray-200 dark:border-gray-600 pt-4">
                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/auth/logout.php"
                    class="sidebar-item flex items-center bg-red-500 px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 text-gray-50 dark:text-gray-50 hover:bg-red-600 dark:hover:bg-red-600/20">
                    <i class="fas fa-sign-out-alt text-base ml-3"></i>
                    <span class="font-medium">تسجيل الخروج</span>
                </a>
            </div>
        </div>
        </div>
    </aside>

    <!-- المحتوى الرئيسي -->
    <main class="pt-16 min-h-screen transition-all duration-300 lg:pr-64">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <!-- رأس الصفحة -->
            <div class="content-card p-6 mb-6 animate-in">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex-1">
                        <div class="flex items-center justify-between">
                            <?php
                            // Compact advanced header per role
                            if ($dashboardType === 'admin') {
                                $hdr_icon = 'fa-user-shield';
                                $hdr_title = 'مرحباً، مدير النظام';
                                $hdr_sub = 'نظرة سريعة متقدمة على إدارة المتدربين والتحصيل';
                            } elseif ($dashboardType === 'supervisor') {
                                $hdr_icon = 'fa-user-tie';
                                $hdr_title = ' مرحباً، مشرف النظام';
                                $hdr_sub = 'لوحة إشراف سريعة ومختصرة';
                            } else {
                                $hdr_icon = 'fa-user';
                                $hdr_title = 'أهلاً بك، موظفنا';
                                $hdr_sub = 'مهامك اليوميّة ونظرة مختصرة';
                            }
                            ?>
                            <div class="flex items-center gap-4">
                                <div
                                    class="w-12 h-12 rounded-lg bg-gradient-to-br from-indigo-600 to-blue-500 text-white flex items-center justify-center shadow">
                                    <i class="fas <?php echo $hdr_icon; ?> text-lg"></i>
                                </div>
                                <div>
                                    <div class="text-lg lg:text-xl font-semibold text-secondary-900 dark:text-white">
                                        <?php echo htmlspecialchars($hdr_title); ?>
                                    </div>
                                    <div class="text-sm text-gray-400 mt-1"><?php echo htmlspecialchars($hdr_sub); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/reports/financial.php"
                                    aria-label="تقرير التحصيل"
                                    class="inline-flex items-center gap-3 px-3 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 text-sm shadow-sm">
                                    <span
                                        class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-white/10 text-white">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                    </span>
                                    <span class="font-medium">تقرير التحصيل</span>
                                </a>
                                <button id="refreshDashboard" aria-label="تحديث لوحة التحكم"
                                    class="inline-flex items-center gap-3 px-3 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm shadow-sm">
                                    <span
                                        class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-white/10 text-white">
                                        <i class="fas fa-sync-alt"></i>
                                    </span>
                                    <span class="font-medium">تحديث</span>
                                </button>
                            </div>
                        </div>



                        <p class="mt-2 text-secondary-600 dark:text-secondary-400 flex flex-wrap items-center gap-4">
                            <span class="flex items-center space-x-1"><i
                                    class="fas fa-calendar-day text-primary-500"></i><span><?php echo date('Y/m/d'); ?></span></span>
                            <span class="flex items-center space-x-1"><i
                                    class="fas fa-clock text-primary-500"></i><span><?php echo getCurrentTime12Hour(); ?></span></span>
                            <span class="flex items-center space-x-1"><i
                                    class="fas fa-user text-primary-500"></i><span><?php echo $userName; ?></span></span>
                        </p>
                    </div>
                </div>
            </div>
            <!-- إذا كان الموظف، عرض بطاقة الحساب الشخصية ثم الإحصائيات -->
            <?php if ($dashboardType === 'employee' || $dashboardType === 'supervisor'): ?>
                <?php echo displayEmployeeAccountCard($avatarSrc, $userName, $userRoleText, $db, $currentUserId); ?>

                <?php
                // Fetch recent "assignment" notifications for the logged-in employee
                $assignmentNotifications = [];
                $assignUnreadCount = 0;
                if ($currentUserId) {
                    try {
                        // Only show assignment notifications from the last 24 hours so they disappear after a day
                        $stmt = $db->prepare("SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = :user_id AND title LIKE 'تم إسناد %' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY created_at DESC LIMIT 6");
                        $stmt->bindValue(':user_id', $currentUserId, PDO::PARAM_INT);
                        $stmt->execute();
                        $assignmentNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        // Count unread notifications
                        $assignUnreadCount = 0;
                        foreach ($assignmentNotifications as $n) {
                            if (empty($n['is_read'])) {
                                $assignUnreadCount++;
                            }
                        }
                    } catch (Exception $e) {
                        error_log('Error fetching assignment notifications: ' . $e->getMessage());
                        $assignmentNotifications = [];
                        $assignUnreadCount = 0;
                    }
                }
                ?>

                <!-- إشعارات الإسناد المتقدمة -->
                <div class="content-card p-6 mb-6 animate-in">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-primary-50 dark:bg-primary-900/20 rounded-lg">
                                <svg xmlns="http://www.w3.org/2000/svg"
                                    class="h-6 w-6 text-primary-600 dark:text-primary-400" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-xl font-bold text-secondary-900 dark:text-white">إشعارات الإسناد</h2>
                                <p class="text-sm text-secondary-500 dark:text-secondary-400">إشعارات المتدربين المسندين
                                    حديثاً</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <?php if ($assignUnreadCount > 0): ?>
                                <div class="relative">
                                    <span
                                        class="px-3 py-1.5 bg-rose-500 text-white rounded-full text-sm font-medium flex items-center gap-1 shadow-sm">
                                        <span class="w-2 h-2 bg-white rounded-full animate-pulse"></span>
                                        <?php echo number_format($assignUnreadCount); ?> جديد
                                    </span>
                                </div>
                            <?php else: ?>
                                <span
                                    class="px-3 py-1.5 bg-secondary-100 dark:bg-secondary-800 text-secondary-700 dark:text-secondary-300 rounded-full text-sm flex items-center gap-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M5 13l4 4L19 7" />
                                    </svg>
                                    لا توجد إشعارات جديدة
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($assignmentNotifications)): ?>
                                <form method="post"
                                    action="<?php echo rtrim(SITE_URL, '/'); ?>/modules/notifications/mark_all_read.php">
                                    <input type="hidden" name="redirect_url" value="<?php echo $_SERVER['REQUEST_URI']; ?>">
                                    <button type="submit"
                                        class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-medium transition-all duration-200 flex items-center gap-2 shadow-sm hover:shadow">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M5 13l4 4L19 7" />
                                        </svg>
                                        تعليم الكل كمقروء
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-2 space-y-4">
                        <?php if (empty($assignmentNotifications)): ?>
                            <div class="text-center py-10">
                                <div
                                    class="mx-auto w-16 h-16 bg-secondary-100 dark:bg-secondary-800 rounded-full flex items-center justify-center mb-4">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-secondary-400" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                                <h3 class="text-lg font-medium text-secondary-900 dark:text-white mb-1">لا توجد إشعارات إسناد
                                </h3>
                                <p class="text-secondary-500 dark:text-secondary-400 text-sm">سيظهر هنا إشعارات إسناد المتدربين
                                    الجدد</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($assignmentNotifications as $n): ?>
                                <?php
                                $link = $n['link'] ? (strpos($n['link'], 'http') === 0 ? $n['link'] : rtrim(SITE_URL, '/') . '/' . ltrim($n['link'], '/')) : rtrim(SITE_URL, '/') . '/modules/notifications/all.php';
                                $isRead = !empty($n['is_read']);

                                // Try to extract trainee names from messages like: "تم إسناد المتدربين: Name1, Name2 إليك"
                                $namesListHtml = '';
                                $msg = trim((string) $n['message']);

                                // Improved regex to handle different message formats
                                if (preg_match('/تم\s+إسناد[^:]*:\s*(.*?)\s*(?:إليك|\.|$)/ui', $msg, $m)) {
                                    $namesStr = trim($m[1]);
                                    // split by comma and also support Arabic comma variants
                                    $parts = preg_split('/\s*[,،]\s*/u', $namesStr);
                                    $parts = array_filter(array_map('trim', $parts));
                                    if (!empty($parts)) {
                                        $namesListHtml .= '<div class="mt-2">';
                                        $namesListHtml .= '<p class="text-xs text-secondary-500 dark:text-secondary-400 mb-2">المتدربين المسندين:</p>';
                                        $namesListHtml .= '<div class="flex flex-wrap gap-2">';
                                        foreach ($parts as $p) {
                                            $namesListHtml .= '<span class="px-2.5 py-1 bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300 rounded-full text-xs font-medium flex items-center gap-1.5">';
                                            $namesListHtml .= '<svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">';
                                            $namesListHtml .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />';
                                            $namesListHtml .= '</svg>';
                                            $namesListHtml .= htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
                                            $namesListHtml .= '</span>';
                                        }
                                        $namesListHtml .= '</div>';
                                        $namesListHtml .= '</div>';
                                    }
                                }

                                // Fallback: short trimmed message
                                if ($namesListHtml === '') {
                                    $shortMsg = mb_strimwidth(strip_tags($msg), 0, 140, '...', 'utf-8');
                                    $namesListHtml = '<p class="text-sm text-secondary-600 dark:text-secondary-400 mt-2">' . htmlspecialchars($shortMsg, ENT_QUOTES, 'UTF-8') . '</p>';
                                }
                                ?>

                                <a href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>"
                                    class="block p-4 rounded-xl transition-all duration-200 hover:shadow-md border <?php echo $isRead ? 'bg-secondary-50/50 dark:bg-secondary-900/20 border-secondary-200 dark:border-secondary-700 hover:bg-secondary-50 dark:hover:bg-secondary-900/30' : 'bg-white dark:bg-secondary-800 border-l-4 border-l-primary-500 border border-secondary-100 dark:border-secondary-700 shadow-sm hover:shadow'; ?>">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-start gap-3">
                                                <?php if (!$isRead): ?>
                                                    <div class="mt-1.5 w-2.5 h-2.5 bg-primary-500 rounded-full flex-shrink-0"></div>
                                                <?php endif; ?>
                                                <div class="flex-1">
                                                    <div class="flex items-center gap-2 mb-1">
                                                        <p class="text-base font-semibold text-secondary-900 dark:text-white">
                                                            <?php echo htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8'); ?>
                                                        </p>
                                                        <?php if (!$isRead): ?>
                                                            <span
                                                                class="px-2 py-0.5 bg-rose-100 text-rose-700 rounded-full text-xs font-medium">جديد</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php echo $namesListHtml; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-right flex flex-col items-end">
                                            <div class="flex items-center gap-1.5 text-secondary-400 text-xs mb-2">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none"
                                                    viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                <span><?php echo date('Y/m/d', strtotime($n['created_at'])); ?></span>
                                            </div>
                                            <div class="text-xs text-secondary-400">
                                                <?php echo formatTime12Hour($n['created_at']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($assignmentNotifications)): ?>
                        <div
                            class="mt-6 pt-4 border-t border-secondary-200 dark:border-secondary-700 flex justify-between items-center">
                            <div class="text-sm text-secondary-500 dark:text-secondary-400">
                                إجمالي الإشعارات: <span
                                    class="font-medium text-secondary-700 dark:text-secondary-300"><?php echo count($assignmentNotifications); ?></span>
                            </div>
                            <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/notifications/all.php"
                                class="text-sm font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 flex items-center gap-1.5 transition-colors duration-200">
                                <span>عرض جميع الإشعارات</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 rtl:rotate-180" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <!-- الإحصائيات -->
            <?php if (($dashboardType === 'employee' || $dashboardType === 'supervisor') && !empty($employeeSummary)): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
                    <!-- تقدم الموظف -->
                    <div class="stat-card p-5 bg-white dark:bg-gray-800 hover:shadow-lg transition-shadow rounded-lg border"
                        style="border-left:5px solid rgba(71, 140, 231, 1);">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <p class="text-xs font-medium text-secondary-600 dark:text-secondary-400"><i
                                        class="fas fa-circle text-red-500 ml-2"></i> تقدّم حسابي</p>
                                <p class="text-2xl font-bold text-secondary-900 dark:text-white mt-1">
                                    <?php echo number_format($employeeSummary['progress_percent'], 1); ?>%
                                </p>
                            </div>
                            <div
                                class="w-14 h-14 rounded-full bg-gradient-to-br from-primary-600 to-primary-400 flex items-center justify-center text-white shadow-sm">
                                <i class="fas fa-chart-line fa-lg"></i>
                            </div>
                        </div>
                        <div class="mt-2 mb-3">
                            <div class="progress w-full bg-gray-100 dark:bg-gray-700 rounded-full">
                                <div style="width: <?php echo $employeeSummary['progress_percent']; ?>%"
                                    class="bg-emerald-500"></div>
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-xs text-secondary-500 dark:text-secondary-400">
                            <span>المتدربين المسددين: <span
                                    class="text-secondary-700 dark:text-white font-medium"><?php echo number_format($employeeSummary['paid']); ?></span></span>
                            <span>الإجمالي: <span
                                    class="text-secondary-700 dark:text-white font-medium"><?php echo number_format($employeeSummary['assigned']); ?></span></span>
                        </div>
                    </div>

                    <!-- المتحصل اليومي والأسبوعي -->
                    <div class="stat-card p-5 bg-white dark:bg-gray-800 hover:shadow-lg transition-shadow rounded-lg border"
                        style="border-left:5px solid rgba(50, 179, 104, 1);">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <p class="text-xs font-medium text-secondary-600 dark:text-secondary-400"><i
                                        class="fas fa-circle text-red-500 ml-2"></i> المتحصّل</p>
                                <p class="text-2xl font-bold text-secondary-900 dark:text-white mt-1">
                                    <?php echo number_format($employeeSummary['today_collected'], 2); ?> ر.س
                                </p>
                                <p class="text-sm text-secondary-500">الأسبوعي: <span
                                        class="font-medium text-secondary-700 dark:text-white"><?php echo number_format($employeeSummary['weekly_collected'], 2); ?>
                                        ر.س</span></p>
                            </div>
                            <div
                                class="w-12 h-12 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center text-emerald-600 shadow-sm">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                        </div>


                    </div>

                    <!-- المهام المعلقة والديون -->
                    <div class="stat-card p-5 bg-white dark:bg-gray-800 hover:shadow-lg transition-shadow rounded-lg border"
                        style="border-left:5px solid rgba(255, 0, 0, 1);">
                        <div class="flex items-start justify-between mb-2">
                            <div>
                                <p class="text-xs font-medium text-secondary-600 dark:text-secondary-400"><i
                                        class="fas fa-circle text-red-500 ml-2"></i> المهام المعلقة</p>
                                <p class="text-2xl font-bold text-secondary-900 dark:text-white mt-1">
                                    <?php echo number_format($employeeSummary['pending_tasks']); ?>
                                </p>
                                <p class="text-sm text-secondary-500 mt-1">المديونيات: <span
                                        class="font-medium text-secondary-700 dark:text-white"><?php echo number_format($employeeSummary['total_debt'], 2); ?>
                                        ر.س</span></p>
                            </div>
                            <div
                                class="w-12 h-12 rounded-lg bg-rose-50 dark:bg-rose-900/20 flex items-center justify-center text-rose-600 shadow-sm">
                                <i class="fas fa-tasks"></i>
                            </div>
                        </div>
                        <div class="mt-3 text-xs text-secondary-500 dark:text-secondary-400">رابط سريع للمهام: <a
                                href="modules/tasks/list.php" class="text-primary-600 hover:underline">عرض المهام</a></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
                    <?php echo displayStatCards($stats); ?>
                </div>
            <?php endif; ?>

            <style>
                /* تحسين مظهر البطاقات */
                .stat-card {
                    background: white;
                    border-radius: 10px;
                    padding: 16px;
                    box-shadow: 0 1px 3px rgba(2, 6, 23, 0.06);
                    border: 1px solid rgba(226, 232, 240, 0.6);
                    transition: transform 0.18s ease, box-shadow 0.18s ease;
                }

                .stat-card:hover {
                    transform: translateY(-6px);
                    box-shadow: 0 8px 30px rgba(2, 6, 23, 0.12);
                }

                .dark .stat-card {
                    background: #0f172a;
                    border-color: rgba(55, 65, 81, 0.6);
                }

                .stat-card h3 {
                    font-size: 13px;
                    color: #64748b;
                    margin-bottom: 6px;
                }

                .stat-card .value {
                    font-size: 20px;
                    font-weight: 700;
                    color: #0f172a;
                }

                /* progress bar animation */
                .stat-card .progress {
                    height: 10px;
                    border-radius: 999px;
                    overflow: hidden;
                    background: rgba(243, 244, 246, 1);
                }

                .stat-card .progress>div {
                    height: 100%;
                    transition: width 0.8s cubic-bezier(.2, .9, .2, 1);
                }

                /* very thin scrollbar for employee performance table */
                .thin-scroll {
                    /* Firefox */
                    scrollbar-width: thin;
                    scrollbar-color: #3b83f677;
                    /* blue */
                }

                /* WebKit browsers */
                .thin-scroll::-webkit-scrollbar {
                    height: 4px;
                    /* horizontal scrollbar */
                    width: 4px;
                    /* vertical scrollbar */
                }

                .thin-scroll::-webkit-scrollbar-track {
                    background: transparent;
                }

                .thin-scroll::-webkit-scrollbar-thumb {
                    background-color: #bfdbfe;
                    /* very light blue */
                    border-radius: 999px;
                    border: 1px solid transparent;
                    transition: background 0.12s ease;
                }

                .thin-scroll::-webkit-scrollbar-thumb:hover {
                    background-color: #93c5fd;
                    /* slightly darker on hover */
                }

                /* Backup panel visual polish (added) */
                #backupPanel {
                    border: 1px solid rgba(14, 165, 233, 0.08);
                    background: linear-gradient(180deg, rgba(14, 165, 233, 0.03) 0%, rgba(255, 255, 255, 0.6) 100%);
                    padding: 1rem;
                    border-radius: 12px;
                    box-shadow: 0 8px 20px rgba(2, 132, 199, 0.06);
                }

                .dark #backupPanel {
                    background: linear-gradient(180deg, rgba(2, 132, 199, 0.06) 0%, rgba(15, 23, 42, 0.6) 100%);
                    border: 1px solid rgba(14, 165, 233, 0.12);
                    box-shadow: 0 8px 20px rgba(2, 132, 199, 0.12);
                }

                /* Primary create button */
                #createBackupBtn {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.6rem;
                    padding: 0.55rem 0.9rem;
                    font-weight: 600;
                    border-radius: 10px;
                    background: linear-gradient(90deg, #06b6d4 0%, #0ea5e9 50%, #0284c7 100%);
                    color: #fff;
                    border: none;
                    box-shadow: 0 6px 18px rgba(14, 165, 233, 0.18);
                    transition: transform 0.12s ease, box-shadow 0.12s ease, opacity 0.12s ease;
                    cursor: pointer;
                }

                #createBackupBtn:active {
                    transform: translateY(1px) scale(0.998);
                }

                #createBackupBtn:hover {
                    box-shadow: 0 10px 26px rgba(14, 165, 233, 0.24);
                }

                #createBackupBtn[disabled] {
                    opacity: 0.6;
                    cursor: not-allowed;
                    transform: none;
                    box-shadow: 0 4px 12px rgba(2, 132, 199, 0.06);
                }

                /* Secondary action buttons (download/delete) */
                .deleteBackupBtn {
                    padding: 0.35rem 0.6rem;
                    border-radius: 8px;
                    font-weight: 600;
                    color: #fff;
                    background: linear-gradient(90deg, #ef4444 0%, #f43f5e 100%);
                    border: none;
                    transition: transform 0.08s ease, opacity 0.12s ease;
                    cursor: pointer;
                }

                .deleteBackupBtn:hover {
                    transform: translateY(-1px);
                }

                .backup-download-btn {
                    padding: 0.35rem 0.6rem;
                    border-radius: 8px;
                    font-weight: 600;
                    color: #fff;
                    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
                    text-decoration: none;
                    display: inline-block;
                }

                /* Backup list items */
                #backupList li {
                    padding: 0.6rem 0.8rem;
                    border-radius: 10px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 0.8rem;
                    transition: background 0.12s ease, transform 0.08s ease;
                }

                #backupList li:hover {
                    transform: translateY(-3px);
                    background: rgba(14, 165, 233, 0.02);
                }

                .backup-meta {
                    font-size: 0.88rem;
                    color: #475569;
                }

                .dark .backup-meta {
                    color: #cbd5e1;
                }

                /* Status text */
                #backupStatus {
                    font-weight: 600;
                    color: #0f172a;
                    padding-left: 0.4rem;
                }

                .dark #backupStatus {
                    color: #e6f7ff;
                }

                /* small icon for heading */
                .backup-icon i {
                    font-size: 0.9rem;
                }

                /* dotted note border */
                .backup-note {
                    border: 1px dashed rgba(99, 102, 241, 0.08);
                    padding: 0.6rem 0.75rem;
                    border-radius: 8px;
                    display: inline-block;
                    margin-top: 0.4rem;
                }

                .dark .backup-note {
                    border-color: rgba(59, 130, 246, 0.12);
                    background: rgba(2, 6, 23, 0.03);
                    color: #c7e9ff;
                }

                /* increase gap below backup panel */
                #backupPanel {
                    margin-bottom: 2.25rem;
                }

                @media (min-width: 1024px) {

                    /* slightly larger spacing on wide screens */
                    #backupPanel {
                        margin-bottom: 3rem;
                    }
                }

                /* backup list overflow wrapper */
                .backup-list-wrapper {
                    max-height: 10rem;
                    /* collapsed height */
                    overflow: hidden;
                    position: relative;
                    transition: max-height 0.28s ease;
                }

                .backup-list-wrapper::after {
                    content: '';
                    position: absolute;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    height: 2.6rem;
                    background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, rgba(248, 250, 252, 1) 100%);
                    pointer-events: none;
                }

                .dark .backup-list-wrapper::after {
                    background: linear-gradient(180deg, rgba(15, 23, 42, 0) 0%, rgba(15, 23, 42, 0.9) 100%);
                }

                .backup-list-wrapper.expanded {
                    max-height: 40rem;
                    /* expanded */
                }
            </style>

            <!-- محتوى إضافي حسب نوع المستخدم -->
            <?php if ($dashboardType === 'admin'): ?>
                <!-- لوحة تحكم المدير -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- أداء الموظفين -->
                    <div class="content-card p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-semibold text-secondary-900 dark:text-white"><i
                                    class="fas fa-tachometer-alt text-indigo-500 ml-2"></i> أداء الموظفين</h2>

                        </div>
                        <style>
                            /* Very thin, subtle blue scrollbar for the employee performance table (1px) */
                            .thin-scroll {
                                scrollbar-width: thin;
                                /* Firefox */
                                scrollbar-color: rgba(14, 165, 233, 0.18) transparent;
                            }

                            .thin-scroll::-webkit-scrollbar {
                                height: 1px;
                                width: 1px;
                            }

                            .thin-scroll::-webkit-scrollbar-track {
                                background: transparent;
                            }

                            .thin-scroll::-webkit-scrollbar-thumb {
                                background: rgba(14, 165, 233, 0.18);
                                border-radius: 10px;
                            }

                            /* Make sure the thumb is barely visible on dark backgrounds */
                            @media (prefers-color-scheme: dark) {
                                .thin-scroll::-webkit-scrollbar-thumb {
                                    background: rgba(56, 178, 255, 0.12);
                                }
                            }
                        </style>
                        <div class="overflow-x-auto thin-scroll background-white dark:background-blue-800 ">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr
                                        class="text-right text-secondary-500 dark:text-secondary-400 border-b border-secondary-100 dark:border-secondary-700">
                                        <th class="pb-3 px-4">الموظف</th>
                                        <th class="pb-3 px-4 text-center">المتدربين</th>
                                        <th class="pb-3 px-4 text-center">مسدد</th>
                                        <th class="pb-3 px-4 text-center">متأخر</th>
                                        <th class="pb-3 px-4 text-center">المتحصل أسبوعي</th>
                                        <th class="pb-3 px-4 text-center">الديون</th>
                                        <th class="pb-3 px-4 text-center">التواصل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php echo displayEmployeePerformance($employeePerformance); ?>
                                </tbody>
                            </table>
                        </div>
                    </div>


                    <div class="content-card p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-semibold text-secondary-900 dark:text-white"><i
                                    class="fas fa-calendar-day text-emerald-600 ml-2"></i> التواصل والمتحصّل اليومي
                            </h2>
                        </div>
                        <div class="space-y-4">
                            <?php echo displayDailyStats($dailyStats); ?>
                        </div>
                    </div>

                    <!-- (تم إزالة مؤشرات التواصل الأسبوعية من لوحة المدير حسب الطلب) -->
                </div>

                <!-- لوحة النسخ الاحتياطي (للمدير فقط) -->
                <?php if (isAdmin()): ?>
                    <div class="content-card p-6 mt-4">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h2 class="text-lg font-semibold text-secondary-900 dark:text-white">
                                    <span
                                        class="backup-icon inline-flex items-center justify-center mr-2 bg-gradient-to-r from-primary-100 to-primary-200 text-primary-700 rounded-full w-8 h-8">
                                        <i class="fas fa-database"></i>
                                    </span>
                                    إدارة النسخ الاحتياطي
                                </h2>
                                <p class="text-sm text-secondary-500 dark:text-secondary-400 backup-note">يمكن للمدير إنشاء نسخة
                                    احتياطية للنظام يدوياً. النسخ التلقائية تُنشأ كل 24 ساعة عبر جدول المهام ويُحذف أي ملف أقدم
                                    من 7 أيام تلقائياً.</p>
                            </div>
                        </div>

                        <div id="backupPanel" class="space-y-4">
                            <div class="flex items-center gap-3">
                                <button id="createBackupBtn"
                                    class="px-4 py-2 bg-primary-600 text-white rounded-md shadow-sm">إنشاء نسخة احتياطية
                                    الآن</button>
                                <span id="backupStatus" class="text-sm text-secondary-600"></span>
                            </div>

                            <div>
                                <div class="flex items-center justify-between">
                                    <h3 class="text-sm font-medium text-secondary-700 dark:text-secondary-300 mb-2">النسخ
                                        المتوفرة (آخر 7 أيام)</h3>
                                    <button id="backupToggleBtn"
                                        class="text-xs px-2 py-1 rounded-md bg-secondary-100 dark:bg-secondary-800 text-secondary-700 dark:text-secondary-300">عرض
                                        المزيد</button>
                                </div>
                                <div class="backup-list-wrapper">
                                    <ul id="backupList" class="space-y-2 text-sm text-secondary-600">
                                        <?php
                                        $backups = [];
                                        if (is_dir(BACKUP_PATH)) {
                                            $files = scandir(BACKUP_PATH, SCANDIR_SORT_DESCENDING);
                                            foreach ($files as $f) {
                                                if ($f === '.' || $f === '..')
                                                    continue;
                                                $full = BACKUP_PATH . $f;
                                                if (is_file($full)) {
                                                    $backups[] = [
                                                        'name' => $f,
                                                        'time' => date('Y-m-d H:i', filemtime($full)),
                                                        'size' => number_format(filesize($full) / 1024, 1) . ' KB'
                                                    ];
                                                }
                                            }
                                        }
                                        if (empty($backups)) {
                                            echo '<li class="text-secondary-500">لا توجد نسخ محفوظة</li>';
                                        } else {
                                            foreach ($backups as $b) {
                                                $download = rtrim(SITE_URL, '/') . '/backups/' . rawurlencode($b['name']);
                                                echo '<li class="flex items-center justify-between p-2 border rounded-md bg-secondary-50 dark:bg-secondary-900">';
                                                echo '<div>' . htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') . '<div class="text-xs text-secondary-500">' . htmlspecialchars($b['time']) . ' • ' . htmlspecialchars($b['size']) . '</div></div>';
                                                echo '<div class="flex items-center gap-2"><a href="' . $download . '" class="px-2 py-1 bg-emerald-500 text-white rounded text-xs">تحميل</a><button data-file="' . htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') . '" class="deleteBackupBtn px-2 py-1 bg-rose-500 text-white rounded text-xs">حذف</button></div>';
                                                echo '</li>';
                                            }
                                        }
                                        ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <br><br>
                <!-- توزيع المديونيات -->
                <div class="content-card p-6 mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-lg font-semibold text-secondary-900 dark:text-white"><i
                                class="fas fa-chart-pie text-rose-600 ml-2"></i> توزيع المديونيات</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr
                                    class="text-right text-secondary-500 dark:text-secondary-400 border-b border-secondary-100 dark:border-secondary-700">
                                    <th class="pb-3 px-4">الدبلوم</th>
                                    <th class="pb-3 px-4">الفرع</th>
                                    <th class="pb-3 px-4">الموظف</th>
                                    <th class="pb-3 px-4 text-center">عدد المتدربين</th>
                                    <th class="pb-3 px-4 text-center">إجمالي الديون</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($debtDistribution as $debt): ?>
                                    <tr
                                        class="border-t border-secondary-100 dark:border-secondary-700 hover:bg-gray-50 dark:hover:bg-gray-800">
                                        <td class="py-3 px-4"><?php echo h($debt['diploma_name'] ?? '—'); ?></td>
                                        <td class="py-3 px-4"><?php echo h($debt['branch_name'] ?? '—'); ?></td>
                                        <td class="py-3 px-4"><?php echo h($debt['employee_name'] ?? '—'); ?></td>
                                        <td class="py-3 px-4 text-center"><?php echo number_format($debt['trainee_count']); ?>
                                        </td>
                                        <td class="py-3 px-4 text-center text-red-600 font-medium">
                                            <?php echo number_format($debt['total_debt']); ?> ر.س
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php else: ?>
                <!-- لوحة تحكم الموظف -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- توزيع المتدربين حسب الحالة -->
                    <div class="content-card p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-semibold text-secondary-900 dark:text-white">توزيع المتدربين</h2>
                        </div>
                        <div class="chart-container" id="traineesChart" style="height: 300px;"></div>
                    </div>

                    <!-- مؤشرات أسبوعية -->
                    <div class="content-card p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-semibold text-secondary-900 dark:text-white">مؤشرات التواصل الأسبوعية
                            </h2>
                        </div>
                        <div class="chart-container" id="weeklyContactsChart" style="height: 300px;"></div>
                    </div>
                </div>

                <!-- تفاصيل المتدربين -->
                <div class="content-card p-6 mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-lg font-semibold text-secondary-900 dark:text-white">تفاصيل المتدربين</h2>
                        <a href="modules/trainees/list.php"
                            class="text-sm text-primary-600 hover:text-primary-700 flex items-center space-x-1">
                            <span>عرض الكل</span>
                            <i class="fas fa-arrow-left"></i>
                        </a>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <?php foreach ($employeeTraineesStatus as $status): ?>
                            <?php
                            // default classes
                            $bgClass = 'bg-gray-100 dark:bg-gray-800';
                            $labelClass = 'text-sm text-secondary-600 dark:text-secondary-400';
                            $countClass = 'text-2xl font-bold text-secondary-900 dark:text-white mt-2';

                            switch ($status['status']) {
                                case 'paid':
                                    $bgClass = 'bg-emerald-50 dark:bg-emerald-900';
                                    $labelClass = 'text-sm text-emerald-700 dark:text-emerald-300';
                                    $countClass = 'text-2xl font-bold text-emerald-900 dark:text-emerald-200 mt-2';
                                    $statusName = 'مسدد';
                                    break;
                                case 'delayed':
                                    $bgClass = 'bg-red-50 dark:bg-red-900';
                                    $labelClass = 'text-sm text-red-600 dark:text-red-300';
                                    $countClass = 'text-2xl font-bold text-red-700 dark:text-red-200 mt-2';
                                    $statusName = 'متأخر';
                                    break;
                                case 'promised_payment':
                                    $bgClass = 'bg-amber-50 dark:bg-amber-900';
                                    $labelClass = 'text-sm text-amber-700 dark:text-amber-300';
                                    $countClass = 'text-2xl font-bold text-amber-900 dark:text-amber-200 mt-2';
                                    $statusName = 'وعد بالسداد';
                                    break;
                                case 'refused_payment':
                                    $bgClass = 'bg-gray-50 dark:bg-gray-800';
                                    $labelClass = 'text-sm text-gray-600 dark:text-gray-300';
                                    $countClass = 'text-2xl font-bold text-gray-900 dark:text-gray-200 mt-2';
                                    $statusName = 'رافض السداد';
                                    break;
                                case 'not_responding':
                                    $bgClass = 'bg-indigo-50 dark:bg-indigo-900';
                                    $labelClass = 'text-sm text-indigo-700 dark:text-indigo-300';
                                    $countClass = 'text-2xl font-bold text-indigo-900 dark:text-indigo-200 mt-2';
                                    $statusName = 'لا يرد';
                                    break;
                                case 'under_communication':
                                    $bgClass = 'bg-blue-50 dark:bg-blue-900';
                                    $labelClass = 'text-sm text-blue-700 dark:text-blue-300';
                                    $countClass = 'text-2xl font-bold text-blue-900 dark:text-blue-200 mt-2';
                                    $statusName = 'قيد التواصل';
                                    break;
                                default:
                                    $statusName = isset($status['status']) ? $status['status'] : '';
                                    break;
                            }
                            ?>
                            <div class="<?php echo $bgClass; ?> rounded-lg p-4 text-center">
                                <p class="<?php echo $labelClass; ?>"><?php echo $statusName; ?></p>
                                <p class="<?php echo $countClass; ?>"><?php echo number_format($status['count']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- مخططات مشتركة -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- مخطط المدفوعات -->
                <div class="content-card p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-lg font-semibold text-secondary-900 dark:text-white">إحصائيات المدفوعات
                            الأسبوعية</h2>
                    </div>
                    <div class="chart-container" id="paymentsChart" style="height: 300px;"></div>
                </div>

                <!-- مخطط الأداء الشهري -->
                <div class="content-card p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-lg font-semibold text-secondary-900 dark:text-white">الأداء الشهري</h2>
                    </div>
                    <div class="chart-container" id="monthlyPerformanceChart" style="height: 300px;"></div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // إدارة الوضع المظلم
        const darkModeToggle = document.getElementById('darkModeToggle');
        const htmlElement = document.documentElement;

        if (localStorage.getItem('darkMode') === 'true') {
            htmlElement.classList.add('dark');
            darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }

        darkModeToggle.addEventListener('click', () => {
            htmlElement.classList.toggle('dark');
            if (htmlElement.classList.contains('dark')) {
                localStorage.setItem('darkMode', 'true');
                darkModeToggle.innerHTML = '<i class="fas fa-sun"></i>';
            } else {
                localStorage.setItem('darkMode', 'false');
                darkModeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            }
        });

        // إدارة الشريط الجانبي
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('-translate-x-full');
        });

        // تهيئة الرسوم البيانية
        document.addEventListener("DOMContentLoaded", function () {
            // مخطط المدفوعات الأسبوعية
            if (document.querySelector("#paymentsChart")) {
                var paymentsOptions = {
                    series: [{ name: "المدفوعات", data: <?php echo json_encode($paymentsChartData); ?> }],
                    chart: { type: 'area', height: 300, fontFamily: 'Tajawal, sans-serif' },
                    colors: ['#0ea5e9'],
                    xaxis: { categories: <?php echo json_encode($paymentsChartLabels); ?> },
                    yaxis: { labels: { formatter: function (val) { return val.toFixed(0) + " ر.س"; } } }
                };
                new ApexCharts(document.querySelector("#paymentsChart"), paymentsOptions).render();
            }

            if (document.querySelector("#traineesChart")) {
                var chartSeries = <?php echo json_encode($traineesDistribution['series'] ?? []); ?>;
                var chartLabels = <?php echo json_encode($traineesDistribution['labels'] ?? []); ?>;
                var chartColors = <?php echo json_encode($traineesDistribution['colors'] ?? []); ?>;

                if (Array.isArray(chartSeries) && chartSeries.length > 0 && chartSeries.some(x => x > 0)) {
                    var traineesOptions = {
                        series: chartSeries,
                        chart: { type: 'donut', height: 300 },
                        colors: chartColors,
                        labels: chartLabels,
                        legend: { position: 'bottom' }
                    };
                    new ApexCharts(document.querySelector("#traineesChart"), traineesOptions).render();
                } else {
                    console.warn("⚠️ لا توجد بيانات متاحة للرسم البياني.");
                    document.querySelector("#traineesChart").innerHTML = "<p class='text-center text-gray-400 mt-10'>لا توجد بيانات متاحة</p>";
                }
            }



            // مخطط التواصل الأسبوعي (للموظف)
            <?php if ((($dashboardType === 'employee') || ($dashboardType === 'admin') || ($dashboardType === 'supervisor')) && isset($weeklyIndicators['contacts'])): ?>
                if (document.querySelector("#weeklyContactsChart")) {
                    var weeklyOptions = {
                        series: [{ name: "التواصل", data: <?php echo json_encode($weeklyIndicators['contacts']['data']); ?> }],
                        chart: { type: 'bar', height: 300, fontFamily: 'Tajawal, sans-serif' },
                        colors: ['#10b981'],
                        xaxis: { categories: <?php echo json_encode($weeklyIndicators['contacts']['labels']); ?> }
                    };
                    new ApexCharts(document.querySelector("#weeklyContactsChart"), weeklyOptions).render();
                }
            <?php endif; ?>

            // مخطط الأداء الشهري
            if (document.querySelector("#monthlyPerformanceChart")) {
                var monthlyOptions = {
                    series: [{ name: "المتحصل", data: <?php echo json_encode($monthlyPerformanceData); ?> }],
                    chart: { type: 'area', height: 300, fontFamily: 'Tajawal, sans-serif' },
                    colors: ['#0b8cf5ff'],
                    xaxis: { categories: <?php echo json_encode($monthlyPerformanceLabels); ?> },
                    yaxis: { labels: { formatter: function (val) { return val.toFixed(0) + " ر.س"; } } }
                };
                new ApexCharts(document.querySelector("#monthlyPerformanceChart"), monthlyOptions).render();
            }

            // زر تحديث لوحة التحكم: عرض سبينر ثم إعادة تحميل الصفحة
            var refreshBtn = document.getElementById('refreshDashboard');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function (e) {
                    try {
                        this.disabled = true;
                        var original = this.innerHTML;
                        // عرض أيقونة دوران مع نص
                        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span> جارٍ التحديث</span>';
                        // حافظ على مظهر قصير قبل إعادة التحميل حتى يشعر المستخدم بالاستجابة
                        setTimeout(function () { location.reload(); }, 200);
                        // احتياطي: إذا لم يتم إعادة التحميل خلال 6 ثوانٍ، أعد الزر إلى الحالة الأصلية
                        setTimeout(function (btn, html) {
                            if (btn) {
                                btn.disabled = false;
                                btn.innerHTML = html;
                            }
                        }, 6000, this, original);
                    } catch (err) {
                        console.error('Refresh failed', err);
                    }
                });
            }
        });
    </script>
</body>

</html>
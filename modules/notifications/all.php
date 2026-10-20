<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/modules/auth/login.php');
    exit;
}

// دوال مساعدة للإشعارات
function formatTime($timestamp) {
    $date = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $date->getTimestamp();

    if ($diff < 60) {
        return 'الآن';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return "منذ $minutes دقيقة";
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return "منذ $hours ساعة";
    } else {
        return $date->format('Y/m/d H:i');
    }
}

function getNotificationIcon($type) {
    $icons = [
        'info' => 'fa-info-circle',
        'success' => 'fa-check-circle',
        'warning' => 'fa-exclamation-triangle',
        'error' => 'fa-exclamation-circle',
        'task' => 'fa-tasks',
        'login' => 'fa-sign-in-alt'
    ];
    return $icons[$type] ?? 'fa-bell';
}

function getNotificationIconClass($type) {
    $classes = [
        'info' => 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-300',
        'success' => 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300',
        'warning' => 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900 dark:text-yellow-300',
        'error' => 'bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-300',
        'task' => 'bg-purple-100 text-purple-600 dark:bg-purple-900 dark:text-purple-300',
        'login' => 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900 dark:text-indigo-300'
    ];
    return $classes[$type] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
}

function getTypeLabel($type) {
    $labels = [
        'info' => 'معلومات',
        'success' => 'نجاح',
        'warning' => 'تحذير',
        'error' => 'خطأ',
        'task' => 'مهمة',
        'login' => 'تسجيل دخول'
    ];
    return $labels[$type] ?? 'إشعار';
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';
$userName = $_SESSION['full_name'] ?? 'مستخدم';
$userAvatar = isset($_SESSION['avatar']) && $_SESSION['avatar'] ? $_SESSION['avatar'] : 
            'https://ui-avatars.com/api/?name=' . urlencode($userName) . '&background=0ea5e9&color=fff&size=128';

// جلب الإشعارات مع تجميع زمني - للمدير تشمل إشعارات الموظفين
if ($user_role === 'manager' || $user_role === 'admin') {
    // أولاً: تحقق من وجود الأعمدة في الجدول
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM users LIKE 'manager_id'");
        $hasManagerId = $checkStmt->rowCount() > 0;
        
        if ($hasManagerId) {
            $stmt = $db->prepare("
                SELECT 
                    n.*,
                    u.full_name as user_name,
                    u.email as user_email,
                    CASE 
                        WHEN n.created_at > NOW() - INTERVAL 1 HOUR THEN 'recent'
                        WHEN n.created_at > NOW() - INTERVAL 24 HOUR THEN 'today'
                        WHEN n.created_at > NOW() - INTERVAL 7 DAY THEN 'week'
                        WHEN n.created_at > NOW() - INTERVAL 30 DAY THEN 'month'
                        ELSE 'older'
                    END as time_group
                FROM notifications n
                LEFT JOIN users u ON n.user_id = u.id
                WHERE n.user_id = :user_id 
                   OR u.manager_id = :user_id
                   OR (u.role != 'manager' AND u.role != 'admin' AND :user_role IN ('manager', 'admin'))
                ORDER BY n.created_at DESC
            ");
        } else {
            // إذا لم يكن حقل manager_id موجوداً، استخدم المنطق البديل
            $stmt = $db->prepare("
                SELECT 
                    n.*,
                    u.full_name as user_name,
                    u.email as user_email,
                    CASE 
                        WHEN n.created_at > NOW() - INTERVAL 1 HOUR THEN 'recent'
                        WHEN n.created_at > NOW() - INTERVAL 24 HOUR THEN 'today'
                        WHEN n.created_at > NOW() - INTERVAL 7 DAY THEN 'week'
                        WHEN n.created_at > NOW() - INTERVAL 30 DAY THEN 'month'
                        ELSE 'older'
                    END as time_group
                FROM notifications n
                LEFT JOIN users u ON n.user_id = u.id
                WHERE n.user_id = :user_id 
                   OR (u.role = 'employee' AND :user_role IN ('manager', 'admin'))
                ORDER BY n.created_at DESC
            ");
        }
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':user_role' => $user_role
        ]);
    } catch (Exception $e) {
        // إذا فشل الاستعلام، استخدم الاستعلام الأساسي
        $stmt = $db->prepare("
            SELECT 
                *,
                CASE 
                    WHEN created_at > NOW() - INTERVAL 1 HOUR THEN 'recent'
                    WHEN created_at > NOW() - INTERVAL 24 HOUR THEN 'today'
                    WHEN created_at > NOW() - INTERVAL 7 DAY THEN 'week'
                    WHEN created_at > NOW() - INTERVAL 30 DAY THEN 'month'
                    ELSE 'older'
                END as time_group
            FROM notifications 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
    }
} else {
    $stmt = $db->prepare("
        SELECT 
            *,
            CASE 
                WHEN created_at > NOW() - INTERVAL 1 HOUR THEN 'recent'
                WHEN created_at > NOW() - INTERVAL 24 HOUR THEN 'today'
                WHEN created_at > NOW() - INTERVAL 7 DAY THEN 'week'
                WHEN created_at > NOW() - INTERVAL 30 DAY THEN 'month'
                ELSE 'older'
            END as time_group
        FROM notifications 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC
    ");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
}
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// تجميع الإشعارات
$grouped_notifications = [];
foreach ($notifications as $notification) {
    $grouped_notifications[$notification['time_group']][] = $notification;
}

// إحصائيات الإشعارات
$unread_count = 0;
$total_count = count($notifications);
$user_notifications_count = 0;
$team_notifications_count = 0;

foreach ($notifications as $notification) {
    if (!$notification['is_read']) {
        $unread_count++;
    }
    if ($notification['user_id'] == $user_id) {
        $user_notifications_count++;
    } else {
        $team_notifications_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإشعارات - <?php echo SITE_NAME; ?></title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff', 500: '#0ea5e9', 600: '#0284c7', 700: '#0369a1'
                        }
                    },
                    fontFamily: {
                        'cairo': ['Cairo', 'sans-serif']
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'slide-in': 'slideIn 0.3s ease-out',
                        'pulse-soft': 'pulseSoft 2s infinite'
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' }
                        },
                        slideIn: {
                            '0%': { transform: 'translateX(20px)', opacity: '0' },
                            '100%': { transform: 'translateX(0)', opacity: '1' }
                        },
                        pulseSoft: {
                            '0%, 100%': { opacity: '1' },
                            '50%': { opacity: '0.8' }
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .dark .glass-card {
            background: rgba(17, 24, 39, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .gradient-border {
            position: relative;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 1px;
            border-radius: 16px;
        }
        
        .gradient-border > div {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
        }
        
        .dark .gradient-border > div {
            background: rgba(17, 24, 39, 0.95);
        }
        
        .notification-item {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .notification-item::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 3px;
            height: 100%;
            background: transparent;
            transition: all 0.3s ease;
        }
        
        .notification-item.unread::before {
            background: linear-gradient(180deg, #3b82f6, #8b5cf6);
        }
        
        .notification-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            animation: pulseSoft 2s infinite;
        }
        
        .scrollbar-design::-webkit-scrollbar {
            width: 6px;
        }
        
        .scrollbar-design::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 10px;
        }
        
        .scrollbar-design::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 10px;
        }
        
        .type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .type-info { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .type-success { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .type-warning { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .type-error { background: rgba(239, 68, 68, 0.1); color: #ef4444; }
        
        .action-btn {
            transition: all 0.2s ease;
        }
        
        .action-btn:hover {
            transform: scale(1.05);
        }
        
        .team-notification {
            border-right: 4px solid #f59e0b;
        }
        
        .user-notification {
            border-right: 4px solid #3b82f6;
        }
        /* Compact view: reduce padding and hide long text for denser lists */
        .notification-item.compact {
            padding: 0.65rem !important;
        }
        .notification-item.compact .font-medium { font-size: 0.95rem; }
        .notification-item.compact .text-sm { display: none; }
    </style>
</head>
<body class="font-cairo bg-gray-50 dark:bg-gray-900 min-h-screen transition-colors duration-300" x-data="notificationApp()">

<!-- Header -->
<nav class="bg-white dark:bg-gray-800 shadow-lg border-b border-gray-200 dark:border-gray-700 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex items-center">
                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/index.php" class="flex items-center text-xl font-bold text-gray-800 dark:text-white hover:text-blue-600 dark:hover:text-blue-400 transition-colors gap-3">
                    <div class="bg-blue-100 dark:bg-blue-900 p-2 rounded-lg mr-2">
                        <i class="fas fa-graduation-cap text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <?php echo SITE_NAME; ?>
                </a>
            </div>
            
            <div class="flex items-center space-x-4">
                <!-- Profile -->
                <div class="relative group">
                    <button class="flex items-center space-x-2 p-2 rounded-lg bg-white/50 dark:bg-gray-700/50 hover:bg-white dark:hover:bg-gray-700 transition-all duration-300 shadow-md hover:shadow-lg">
                        <img class="w-8 h-8 rounded-full object-cover border-2 border-white dark:border-gray-600 shadow-sm" 
                            src="<?php echo htmlspecialchars($userAvatar); ?>" alt="صورة الملف الشخصي">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($userName); ?></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Main Content -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header Section -->
    <div class="gradient-border mb-8">
        <div class="p-8">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center mb-6 lg:mb-0">
                    <div class="bg-gradient-to-br from-blue-500 to-purple-600 p-4 rounded-2xl mr-4 shadow-lg gap-3">
                        <i class="fas fa-bell text-white text-2xl"></i>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800 dark:text-white mb-2">مركز الإشعارات</h1>
                        <p class="text-gray-600 dark:text-gray-400">إدارة وتتبع جميع إشعارات النظام في مكان واحد</p>
                        <?php if ($user_role === 'manager' || $user_role === 'admin'): ?>
                        <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                            <i class="fas fa-eye ml-1"></i>
                            تشاهد إشعاراتك وإشعارات فريقك
                        </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button @click="markAllAsRead()" class="bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 text-white px-4 py-2 rounded-xl flex items-center space-x-2 shadow-lg hover:shadow-xl transition-all duration-300 action-btn" title="تعليم جميع الإشعارات كمقروء">
                        <i class="fas fa-check-double"></i>
                        <span>تعليم الكل كمقروء</span>
                    </button>
                    <button onclick="collapseAll()" class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl px-4 py-2 hover:shadow-md transition duration-200" title="طي كل المجموعات">طي الكل</button>
                    <button onclick="expandAll()" class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl px-4 py-2 hover:shadow-md transition duration-200" title="توسيع كل المجموعات">توسيع الكل</button>
                    <button id="compact-toggle-btn" onclick="toggleCompactView()" class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl px-4 py-2 hover:shadow-md transition duration-200" title="تبديل العرض المدمج">عرض مدمج</button>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
        <!-- Sidebar -->
        <div class="lg:col-span-1">
            <!-- Stats Cards -->
            <div class="space-y-4 mb-6">
                <div class="glass-card rounded-2xl p-6 text-center">
                    <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
                        <i class="fas fa-bell text-white text-xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-1"><?php echo $total_count; ?></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">إجمالي الإشعارات</p>
                </div>
                
                <div class="glass-card rounded-2xl p-6 text-center">
                    <div class="w-16 h-16 bg-gradient-to-br from-red-500 to-red-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
                        <i class="fas fa-envelope text-white text-xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-1"><?php echo $unread_count; ?></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">غير مقروء</p>
                </div>

                <?php if ($user_role === 'manager' || $user_role === 'admin'): ?>
                <div class="glass-card rounded-2xl p-6 text-center">
                    <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-white mb-1"><?php echo $team_notifications_count; ?></h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">إشعارات الفريق</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Filters -->
            <div class="glass-card rounded-2xl p-6">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">التصفية</h3>
                
                <div class="space-y-3">
                    <button @click="applyFilter('all')" class="w-full text-right py-3 px-4 rounded-xl bg-blue-500 text-white flex items-center justify-between transition-all duration-200 action-btn">
                        <span class="flex items-center space-x-2">
                            <i class="fas fa-layer-group"></i>
                            <span>جميع الإشعارات</span>
                        </span>
                        <span class="bg-white/20 px-2 py-1 rounded-full text-xs"><?php echo $total_count; ?></span>
                    </button>
                    
                    <button @click="applyFilter('unread')" class="w-full text-right py-3 px-4 rounded-xl bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 flex items-center justify-between transition-all duration-200 action-btn hover:bg-gray-50 dark:hover:bg-gray-600">
                        <span class="flex items-center space-x-2">
                            <i class="fas fa-envelope text-red-400"></i>
                            <span>غير المقروء</span>
                        </span>
                        <?php if ($unread_count > 0): ?>
                        <span class="bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-400 px-2 py-1 rounded-full text-xs"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </button>

                    <?php if ($user_role === 'manager' || $user_role === 'admin'): ?>
                    <button @click="applyFilter('team')" class="w-full text-right py-3 px-4 rounded-xl bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 flex items-center justify-between transition-all duration-200 action-btn hover:bg-gray-50 dark:hover:bg-gray-600">
                        <span class="flex items-center space-x-2">
                            <i class="fas fa-users text-orange-400"></i>
                            <span>إشعارات الفريق</span>
                        </span>
                        <?php if ($team_notifications_count > 0): ?>
                        <span class="bg-orange-100 dark:bg-orange-900 text-orange-600 dark:text-orange-400 px-2 py-1 rounded-full text-xs"><?php echo $team_notifications_count; ?></span>
                        <?php endif; ?>
                    </button>

                    <button @click="applyFilter('my')" class="w-full text-right py-3 px-4 rounded-xl bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 flex items-center justify-between transition-all duration-200 action-btn hover:bg-gray-50 dark:hover:bg-gray-600">
                        <span class="flex items-center space-x-2">
                            <i class="fas fa-user text-blue-400"></i>
                            <span>إشعاراتي فقط</span>
                        </span>
                        <span class="bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 px-2 py-1 rounded-full text-xs"><?php echo $user_notifications_count; ?></span>
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Notification Types -->
                <div class="mt-6">
                    <h4 class="text-sm font-medium text-gray-600 dark:text-gray-400 mb-3">أنواع الإشعارات</h4>
                    <div class="space-y-2">
                        <?php
                        $types = [
                            'info' => ['count' => count(array_filter($notifications, fn($n) => $n['type'] === 'info')), 'color' => 'blue'],
                            'success' => ['count' => count(array_filter($notifications, fn($n) => $n['type'] === 'success')), 'color' => 'green'],
                            'warning' => ['count' => count(array_filter($notifications, fn($n) => $n['type'] === 'warning')), 'color' => 'yellow'],
                            'error' => ['count' => count(array_filter($notifications, fn($n) => $n['type'] === 'error')), 'color' => 'red']
                        ];
                        
                        foreach ($types as $type => $data):
                            if ($data['count'] > 0):
                        ?>
                        <div class="flex items-center justify-between py-2 px-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                            <span class="flex items-center space-x-2">
                                <div class="w-2 h-2 bg-<?php echo $data['color']; ?>-500 rounded-full"></div>
                                <span class="text-sm text-gray-600 dark:text-gray-400"><?php echo getTypeLabel($type); ?></span>
                            </span>
                            <span class="bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-400 px-2 rounded-full text-xs"><?php echo $data['count']; ?></span>
                        </div>
                        <?php
                            endif;
                        endforeach;
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notifications Content -->
        <div class="lg:col-span-3">
            <!-- Search and Controls -->
            <div class="glass-card rounded-2xl p-6 mb-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex-1">
                        <div class="relative">
                            <input id="notification-search" type="text" placeholder="ابحث في الإشعارات..." oninput="filterNotificationsByText(this.value)" class="w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl pl-12 pr-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200" aria-label="بحث في الإشعارات">
                            <i class="fas fa-search absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <button class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl p-3 hover:shadow-lg transition-all duration-300 action-btn">
                            <i class="fas fa-sort text-gray-600 dark:text-gray-400"></i>
                        </button>
                        <button class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl p-3 hover:shadow-lg transition-all duration-300 action-btn">
                            <i class="fas fa-filter text-gray-600 dark:text-gray-400"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Notifications List: New compact grid layout with detail drawer -->
            <div class="space-y-4">
                <?php
                $time_groups = [
                    'recent' => ['label' => 'الأخيرة', 'icon' => 'fa-clock', 'color' => 'text-green-500'],
                    'today' => ['label' => 'اليوم', 'icon' => 'fa-sun', 'color' => 'text-blue-500'],
                    'week' => ['label' => 'هذا الأسبوع', 'icon' => 'fa-calendar-week', 'color' => 'text-purple-500'],
                    'month' => ['label' => 'هذا الشهر', 'icon' => 'fa-calendar-alt', 'color' => 'text-orange-500'],
                    'older' => ['label' => 'الأقدم', 'icon' => 'fa-archive', 'color' => 'text-gray-500']
                ];

                foreach ($time_groups as $group_key => $group_info):
                    if (!empty($grouped_notifications[$group_key])):
                ?>
                <section class="mb-6">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center space-x-3">
                            <i class="fas <?php echo $group_info['icon']; ?> <?php echo $group_info['color']; ?> text-lg"></i>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white"><?php echo $group_info['label']; ?></h3>
                            <span class="bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 px-3 py-1 rounded-full text-sm font-medium">
                                <?php echo count($grouped_notifications[$group_key]); ?>
                            </span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button onclick="toggleGroup('<?php echo $group_key; ?>')" id="toggle-<?php echo $group_key; ?>" aria-expanded="true" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors px-3 py-2 rounded-md" title="إظهار/إخفاء المجموعة">
                                <i id="toggle-icon-<?php echo $group_key; ?>" class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                    </div>

                    <div id="group-<?php echo $group_key; ?>" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <?php foreach ($grouped_notifications[$group_key] as $notification): 
                            $is_team_notification = ($user_role === 'manager' || $user_role === 'admin') && $notification['user_id'] != $user_id;
                            $notification_class = $is_team_notification ? 'team-notification' : 'user-notification';
                            $isUnread = !$notification['is_read'];
                        ?>
                        <article onclick="openDetail(<?php echo $notification['id']; ?>)" role="button" tabindex="0" class="notification-item p-4 rounded-lg cursor-pointer <?php echo $notification_class; ?> <?php echo $isUnread ? 'unread bg-blue-50/50 dark:bg-blue-900/20' : 'bg-white dark:bg-gray-800'; ?> shadow-sm" data-id="<?php echo $notification['id']; ?>" data-user-id="<?php echo $notification['user_id']; ?>" data-title="<?php echo htmlspecialchars(addslashes($notification['title'] ?? '')); ?>" data-message="<?php echo htmlspecialchars(addslashes($notification['message'] ?? '')); ?>" data-created_at="<?php echo $notification['created_at']; ?>" data-type="<?php echo $notification['type']; ?>" data-user_name="<?php echo htmlspecialchars(addslashes($notification['user_name'] ?? '')); ?>">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 rounded-xl flex items-center justify-center <?php echo getNotificationIconClass($notification['type']); ?> shadow">
                                        <i class="fas <?php echo getNotificationIcon($notification['type']); ?> text-lg"></i>
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <h4 class="font-medium text-gray-800 dark:text-white truncate"><?php echo htmlspecialchars($notification['title'] ?? $notification['message']); ?></h4>
                                        <?php if ($isUnread): ?>
                                        <div class="status-dot bg-red-500 rounded-full w-2 h-2"></div>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 truncate mt-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <div class="mt-2 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                        <span class="flex items-center"><i class="far fa-clock ml-2"></i> <?php echo formatTime($notification['created_at']); ?></span>
                                        <span class="type-badge type-<?php echo $notification['type']; ?>"><?php echo getTypeLabel($notification['type']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php
                    endif;
                endforeach;

                if (empty($notifications)):
                ?>
                <!-- Empty State -->
                <div class="glass-card rounded-2xl p-12 text-center">
                    <div class="w-24 h-24 bg-gradient-to-br from-gray-200 to-gray-300 dark:from-gray-600 dark:to-gray-700 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg">
                        <i class="far fa-bell-slash text-gray-400 dark:text-gray-500 text-3xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold text-gray-600 dark:text-gray-400 mb-3">لا توجد إشعارات</h3>
                    <p class="text-gray-500 dark:text-gray-500 mb-6 max-w-md mx-auto">لم تتلق أي إشعارات حتى الآن. سيظهر هنا أي إشعارات جديدة تتلقاها من النظام</p>
                    <button onclick="location.reload()" class="bg-gradient-to-r from-blue-500 to-purple-600 text-white px-8 py-3 rounded-xl hover:shadow-lg transition-all duration-300 action-btn" aria-label="تحديث الصفحة">
                        <i class="fas fa-sync-alt ml-2"></i>
                        تحديث الصفحة
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Detail Drawer (hidden, opens on click) -->
            <aside id="notification-drawer" class="fixed inset-y-0 right-0 w-full sm:w-96 bg-white dark:bg-gray-900 shadow-2xl transform translate-x-full transition-transform duration-300 z-50" aria-hidden="true">
                <div class="p-6 h-full flex flex-col">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 id="drawer-title" class="text-xl font-semibold text-gray-800 dark:text-white"></h3>
                            <p id="drawer-user" class="text-sm text-gray-500 dark:text-gray-400 mt-1"></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button onclick="closeDetail()" class="text-gray-500 hover:text-gray-700 dark:text-gray-300 dark:hover:text-white p-2 rounded-md" aria-label="إغلاق">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <div id="drawer-body" class="prose prose-invert text-gray-700 dark:text-gray-300 overflow-auto mb-4"></div>

                    <div class="mt-auto flex items-center justify-between">
                        <div id="drawer-meta" class="text-sm text-gray-500 dark:text-gray-400"></div>
                        <div class="flex items-center gap-2">
                            <button id="drawer-mark-read" onclick="drawerMarkAsRead()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">تعليم كمقروء</button>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toast-container" class="fixed top-4 right-4 z-50 space-y-3"></div>

<script>
function notificationApp() {
    return {
        darkMode: localStorage.getItem('darkMode') === 'true',
        currentUserId: <?php echo $user_id; ?>,
        userRole: '<?php echo $user_role; ?>',
        
        init() {
            if (this.darkMode) {
                document.documentElement.classList.add('dark');
            }
        },
        
        toggleDarkMode() {
            this.darkMode = !this.darkMode;
            localStorage.setItem('darkMode', this.darkMode);
            document.documentElement.classList.toggle('dark');
        },
        
        async markAsRead(id) {
            try {
                const notification = document.querySelector(`[data-id="${id}"]`);
                if (notification) {
                    notification.classList.add('opacity-50');
                }
                
                const response = await fetch('<?php echo rtrim(SITE_URL, '/'); ?>/api/notifications.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'mark_read',
                        notification_id: id
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    if (notification) {
                        notification.classList.remove('unread', 'bg-blue-50/50', 'dark:bg-blue-900/20', 'opacity-50');
                        const statusDot = notification.querySelector('.status-dot');
                        if (statusDot) statusDot.remove();
                        const markBtn = notification.querySelector('.bg-green-500');
                        if (markBtn) markBtn.remove();
                    }
                    this.showToast('تم تعليم الإشعار كمقروء', 'success');
                } else {
                    if (notification) {
                        notification.classList.remove('opacity-50');
                    }
                    this.showToast('فشل في تحديث الإشعار', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                this.showToast('حدث خطأ أثناء تحديث الإشعار', 'error');
            }
        },
        
        async markAllAsRead() {
            try {
                const response = await fetch('<?php echo rtrim(SITE_URL, '/'); ?>/api/notifications.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'mark_all_read'
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    document.querySelectorAll('.notification-item').forEach(notification => {
                        notification.classList.remove('unread', 'bg-blue-50/50', 'dark:bg-blue-900/20');
                        const statusDot = notification.querySelector('.status-dot');
                        if (statusDot) statusDot.remove();
                        const markBtn = notification.querySelector('.bg-green-500');
                        if (markBtn) markBtn.remove();
                    });
                    this.showToast('تم تعليم جميع الإشعارات كمقروءة', 'success');
                } else {
                    this.showToast('فشل في تحديث الإشعارات', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                this.showToast('حدث خطأ أثناء تحديث الإشعارات', 'error');
            }
        },
        
        applyFilter(type) {
            document.querySelectorAll('.notification-item').forEach(notification => {
                const userId = notification.getAttribute('data-user-id');
                const isUnread = notification.classList.contains('unread');
                const isTeamNotification = userId != this.currentUserId;
                
                switch (type) {
                    case 'all':
                        notification.style.display = 'block';
                        break;
                    case 'unread':
                        notification.style.display = isUnread ? 'block' : 'none';
                        break;
                    case 'team':
                        if (this.userRole === 'manager' || this.userRole === 'admin') {
                            notification.style.display = isTeamNotification ? 'block' : 'none';
                        }
                        break;
                    case 'my':
                        if (this.userRole === 'manager' || this.userRole === 'admin') {
                            notification.style.display = !isTeamNotification ? 'block' : 'none';
                        }
                        break;
                }
            });
        },
        
        showToast(message, type = 'info') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            
            const config = {
                success: { icon: 'fa-check-circle', bg: 'bg-gradient-to-r from-green-500 to-emerald-600' },
                error: { icon: 'fa-exclamation-circle', bg: 'bg-gradient-to-r from-red-500 to-rose-600' },
                info: { icon: 'fa-info-circle', bg: 'bg-gradient-to-r from-blue-500 to-purple-600' }
            }[type];
            
            toast.className = `${config.bg} text-white px-6 py-4 rounded-2xl shadow-2xl transform transition-all duration-300 translate-x-0 opacity-100 flex items-center max-w-md`;
            toast.innerHTML = `
                <i class="fas ${config.icon} text-xl ml-3"></i>
                <span class="flex-1">${message}</span>
                <button class="text-white/80 hover:text-white ml-4 transition-colors" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.transform = 'translateX(100%)';
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 300);
                }
            }, 4000);
        }
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    if (localStorage.getItem('darkMode') === 'true') {
        document.documentElement.classList.add('dark');
    }
});
</script>
<script>
// Toggle visibility of a notification group
function toggleGroup(groupKey) {
    var container = document.getElementById('group-' + groupKey);
    var icon = document.getElementById('toggle-icon-' + groupKey);
    var btn = document.getElementById('toggle-' + groupKey);
    if (!container || !icon) return;
    if (container.classList.contains('hidden')) {
        container.classList.remove('hidden');
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
        if (btn) btn.setAttribute('aria-expanded', 'true');
    } else {
        container.classList.add('hidden');
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }
}

// Simple client-side search that filters notifications by title/message
function filterNotificationsByText(query) {
    var q = (query || '').trim().toLowerCase();
    document.querySelectorAll('.notification-item').forEach(function(item) {
        var text = (item.textContent || '').toLowerCase();
        if (!q) {
            item.style.display = '';
            return;
        }
        if (text.indexOf(q) !== -1) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}
</script>
<script>
// Collapse all groups
function collapseAll() {
    document.querySelectorAll('[id^="group-"]').forEach(function(g){
        g.classList.add('hidden');
    });
    document.querySelectorAll('[id^="toggle-icon-"]').forEach(function(icon){
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
    });
}

// Expand all groups
function expandAll() {
    document.querySelectorAll('[id^="group-"]').forEach(function(g){
        g.classList.remove('hidden');
    });
    document.querySelectorAll('[id^="toggle-icon-"]').forEach(function(icon){
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
    });
}

// Toggle compact view for notifications
function toggleCompactView() {
    var anyCompact = document.querySelector('.notification-item.compact');
    var items = document.querySelectorAll('.notification-item');
    if (anyCompact) {
        items.forEach(function(it){ it.classList.remove('compact'); });
        var btn = document.getElementById('compact-toggle-btn'); if (btn) btn.textContent = 'عرض مدمج';
    } else {
        items.forEach(function(it){ it.classList.add('compact'); });
        var btn = document.getElementById('compact-toggle-btn'); if (btn) btn.textContent = 'عرض تفصيلي';
    }
}
</script>
<script>
var currentDrawerId = null;
function openDetail(id) {
    var el = document.querySelector('[data-id="' + id + '"]');
    if (!el) return;
    currentDrawerId = id;
    var title = el.getAttribute('data-title') || '';
    var message = el.getAttribute('data-message') || '';
    var created = el.getAttribute('data-created_at') || '';
    var type = el.getAttribute('data-type') || '';
    var userName = el.getAttribute('data-user_name') || '';

    document.getElementById('drawer-title').innerText = title || (message.substring(0,60) + (message.length>60? '...':''));
    document.getElementById('drawer-user').innerText = userName ? ('من: ' + userName) : '';
    document.getElementById('drawer-body').innerText = message;
    document.getElementById('drawer-meta').innerText = (created ? created : '');

    var drawer = document.getElementById('notification-drawer');
    if (drawer) {
        drawer.classList.remove('translate-x-full');
        drawer.setAttribute('aria-hidden', 'false');
    }
}

function closeDetail() {
    currentDrawerId = null;
    var drawer = document.getElementById('notification-drawer');
    if (drawer) {
        drawer.classList.add('translate-x-full');
        drawer.setAttribute('aria-hidden', 'true');
    }
}

function drawerMarkAsRead() {
    if (!currentDrawerId) return;
    var id = currentDrawerId;
    fetch('<?php echo rtrim(SITE_URL, '/'); ?>/api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_read', notification_id: id })
    }).then(function(res){ return res.json().catch(function(){ return {} }); })
    .then(function(data){
        if (data && data.success) {
            // update UI
            var el = document.querySelector('[data-id="' + id + '"]');
            if (el) {
                el.classList.remove('unread');
                el.classList.remove('bg-blue-50/50');
                el.classList.remove('dark:bg-blue-900/20');
                var dot = el.querySelector('.status-dot'); if (dot) dot.remove();
            }
            // also update buttons if present
            var markBtn = document.getElementById('drawer-mark-read');
            if (markBtn) markBtn.style.display = 'none';
        }
    }).catch(function(){ /* ignore */ });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
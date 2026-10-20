<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../db/connection.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    redirectWithMessage('/modules/auth/login.php', 'يرجى تسجيل الدخول أولاً');
}

$page_title = 'سجل الأنشطة';

try {
    $database = new Database();
    $db = $database->getConnection();

    // جلب جميع الأنشطة مع معلومات المستخدمين
    $stmt = $db->prepare("
        SELECT u.full_name as user_name, 
               al.action, 
               al.table_name,
               al.record_id,
               al.created_at,
               al.ip_address
        FROM audit_logs al
        JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $activities = [];
    $error = 'حدث خطأ في جلب البيانات: ' . $e->getMessage();
}

include __DIR__ . '/../../includes/tw_layout_start.php';
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white">سجل الأنشطة</h1>
                <p class="mt-2 text-gray-600 dark:text-gray-400">جميع الأنشطة والعمليات في النظام</p>
            </div>
            <div class="flex items-center space-x-4 space-x-reverse">
                <a href="<?php echo rtrim(SITE_URL,'/'); ?>/index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors duration-200">
                    <i class="fas fa-arrow-right ml-2"></i>
                    العودة للرئيسية
                </a>
            </div>
        </div>
    </div>

    <!-- Activities List -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">الأنشطة الحديثة</h2>
        </div>
        
        <?php if (empty($activities)): ?>
        <div class="p-8 text-center">
            <div class="w-16 h-16 mx-auto mb-4 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center">
                <i class="fas fa-history text-gray-400 text-xl"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">لا توجد أنشطة</h3>
            <p class="text-gray-600 dark:text-gray-400">لم يتم تسجيل أي أنشطة في النظام بعد</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            <?php foreach ($activities as $activity): ?>
            <div class="p-6 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-150">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-full flex items-center justify-center">
                            <i class="fas fa-history text-blue-600 dark:text-blue-400"></i>
                        </div>
                    </div>
                    <div class="mr-4 flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-medium text-gray-900 dark:text-white">
                                <?php echo htmlspecialchars($activity['user_name']); ?>
                            </h3>
                            <time class="text-xs text-gray-500 dark:text-gray-400">
                                <?php echo date('Y-m-d H:i:s', strtotime($activity['created_at'])); ?>
                            </time>
                        </div>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            <?php echo htmlspecialchars($activity['action']); ?>
                        </p>
                        <?php if ($activity['table_name']): ?>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
                            الجدول: <span class="font-mono"><?php echo htmlspecialchars($activity['table_name']); ?></span>
                            <?php if ($activity['record_id']): ?>
                            - السجل: <span class="font-mono">#<?php echo htmlspecialchars($activity['record_id']); ?></span>
                            <?php endif; ?>
                        </p>
                        <?php endif; ?>
                        <?php if ($activity['ip_address']): ?>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
                            IP: <span class="font-mono"><?php echo htmlspecialchars($activity['ip_address']); ?></span>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/tw_layout_end.php'; ?>



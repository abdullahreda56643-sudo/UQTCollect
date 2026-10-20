<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Task.php';

checkPermission('employee');
$db = (new Database())->getConnection();
$taskModel = new Task($db);

$current_user_id = (int)$_SESSION['user_id'];

// فلتر المهام العامة
$filters = [];
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $filters['status'] = $_GET['status'];
}
if (isset($_GET['assigned_to']) && $_GET['assigned_to'] !== '') {
    $filters['assigned_to'] = (int)$_GET['assigned_to'];
}

// قيد: للمستخدمين غير الإداريين، عرض المهام المعيّنة لهم فقط
if (!isAdmin()) {
    // تجاهل أي فلتر assigned_to خارجي وفرض عرض مهام المستخدم الحالي فقط
    $filters['assigned_to'] = $current_user_id;
}

// الحصول على المهام
$tasks = $taskModel->list($filters, 'due_date ASC', 100);

// Fetch user names for assigned_to IDs present in the tasks to display Arabic names instead of numeric IDs
$assignedUserIds = [];
foreach ($tasks as $tt) {
    if (!empty($tt['assigned_to'])) $assignedUserIds[] = (int)$tt['assigned_to'];
}
$assignedUserIds = array_values(array_unique(array_filter($assignedUserIds)));
$usersById = [];
if (!empty($assignedUserIds)) {
    // prepare placeholders
    $placeholders = implode(',', array_fill(0, count($assignedUserIds), '?'));
    $ustmt = $db->prepare("SELECT id, full_name FROM users WHERE id IN ($placeholders)");
    foreach ($assignedUserIds as $i => $uid) $ustmt->bindValue($i+1, $uid, PDO::PARAM_INT);
    $ustmt->execute();
    $urows = $ustmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($urows as $ur) {
        $usersById[(int)$ur['id']] = $ur['full_name'];
    }
}


function formatTimeArabic($time) {
    $timestamp = strtotime($time);
    $hour = date('h', $timestamp);
    $minute = date('i', $timestamp);
    $ampm = date('A', $timestamp) == 'AM' ? 'ص' : 'م';
    return $hour . ':' . $minute . ' ' . $ampm;
}
// Fetch related trainee names for tasks that reference a trainee (related_trainee)
$relatedTraineeIds = [];
foreach ($tasks as $tt) {
    if (!empty($tt['related_trainee'])) $relatedTraineeIds[] = (int)$tt['related_trainee'];
}
$relatedTraineeIds = array_values(array_unique(array_filter($relatedTraineeIds)));
$traineesById = [];
if (!empty($relatedTraineeIds)) {
    $placeholders2 = implode(',', array_fill(0, count($relatedTraineeIds), '?'));
    $tstmt = $db->prepare("SELECT id, full_name FROM trainees WHERE id IN ($placeholders2)");
    foreach ($relatedTraineeIds as $i => $rid) $tstmt->bindValue($i+1, $rid, PDO::PARAM_INT);
    $tstmt->execute();
    $trows = $tstmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($trows as $tr) {
        $traineesById[(int)$tr['id']] = $tr['full_name'];
    }
}

// تعاريف الترجمة للقيم التي تخزن بالإنجليزية في قاعدة البيانات
$priorityLabels = [
    'high' => 'عالية',
    'medium' => 'متوسطة',
    'low' => 'منخفضة'
];

$statusLabels = [
    'pending' => 'معلقة',
    'in_progress' => 'قيد التنفيذ',
    'completed' => 'منجزة',
    'cancelled' => 'ملغاة'
];

// الحصول على مهام اليوم والغد للموظف الحالي
$today_tasks = $taskModel->getUserTasksByDate($current_user_id, date('Y-m-d'));
$tomorrow_tasks = $taskModel->getUserTasksByDate($current_user_id, date('Y-m-d', strtotime('+1 day')));

$page_title = 'قائمة المهام';
include '../../includes/tw_layout_start.php';
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">قائمة المهام</h1>
    <?php if (hasAccess('tasks','create')): ?>
    <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/tasks/add.php" class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition-all duration-200">
        <i class="fas fa-plus ml-2"></i> إضافة مهمة
    </a>
    <?php endif; ?>
</div>

<!-- قسم مهامي اليوم والغد -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- مهام اليوم -->
    <div class="bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-800/20 rounded-xl shadow-lg border border-blue-200 dark:border-blue-700">
        <div class="bg-blue-600 text-white px-6 py-4 rounded-t-xl">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold flex items-center">
                    <i class="fas fa-calendar-day ml-2"></i>
                    مهامي اليوم
                </h2>
                <span class="bg-blue-700 px-3 py-1 rounded-full text-sm font-medium">
                    <?php echo count($today_tasks); ?>
                </span>
            </div>
            <p class="text-blue-100 text-sm mt-1"><?php echo date('Y-m-d'); ?></p>
        </div>
        <div class="p-4 max-h-80 overflow-y-auto">
            <?php if (empty($today_tasks)): ?>
                <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                    <i class="fas fa-check-circle text-3xl mb-3 text-green-400"></i>
                    <p class="font-medium">لا توجد مهام لهذا اليوم</p>
                    <p class="text-sm mt-1">استمتع بيوم خالٍ من المهام!</p>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($today_tasks as $task): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 shadow-sm border border-blue-100 dark:border-blue-800 hover:shadow-md transition-shadow duration-200">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <h3 class="font-medium text-gray-900 dark:text-white text-sm mb-1">
                                    <?php
                                    if (!empty($task['related_trainee']) && isset($traineesById[(int)$task['related_trainee']])) {
                                        echo htmlspecialchars('متابعة المتدرب(' . $traineesById[(int)$task['related_trainee']] . ')');
                                    } else {
                                        echo htmlspecialchars($task['title']);
                                    }
                                    ?>
                                </h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                                    <?php echo formatTimeArabic($task['due_date']); ?>
                                </p>
                                <div class="flex items-center space-x-2 space-x-reverse">
                                    <span class="px-2 py-1 rounded-full text-xs <?php echo $task['priority'] === 'high' ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-400' : ($task['priority'] === 'medium' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-400' : 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400'); ?>">
                                        <?php echo htmlspecialchars($priorityLabels[$task['priority']] ?? $task['priority']); ?>
                                    </span>
                                    <span class="px-2 py-1 rounded-full text-xs <?php echo $task['status'] === 'completed' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400' : ($task['status'] === 'in_progress' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-400'); ?>">
                                        <?php echo htmlspecialchars($statusLabels[$task['status']] ?? $task['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/tasks/edit.php?id=<?php echo (int)$task['id']; ?>" 
                               class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 transition-colors duration-200">
                                <i class="fas fa-edit"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- مهام الغد -->
    <div class="bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900/20 dark:to-green-800/20 rounded-xl shadow-lg border border-green-200 dark:border-green-700">
        <div class="bg-green-600 text-white px-6 py-4 rounded-t-xl">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold flex items-center">
                    <i class="fas fa-calendar-plus ml-2"></i>
                    مهامي غداً
                </h2>
                <span class="bg-green-700 px-3 py-1 rounded-full text-sm font-medium">
                    <?php echo count($tomorrow_tasks); ?>
                </span>
            </div>
            <p class="text-green-100 text-sm mt-1"><?php echo date('Y-m-d', strtotime('+1 day')); ?></p>
        </div>
        <div class="p-4 max-h-80 overflow-y-auto">
            <?php if (empty($tomorrow_tasks)): ?>
                <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                    <i class="fas fa-calendar-check text-3xl mb-3 text-green-400"></i>
                    <p class="font-medium">لا توجد مهام للغد</p>
                    <p class="text-sm mt-1">يمكنك التخطيط لمهام جديدة</p>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($tomorrow_tasks as $task): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 shadow-sm border border-green-100 dark:border-green-800 hover:shadow-md transition-shadow duration-200">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <h3 class="font-medium text-gray-900 dark:text-white text-sm mb-1">
                                    <?php
                                    if (!empty($task['related_trainee']) && isset($traineesById[(int)$task['related_trainee']])) {
                                        echo htmlspecialchars('متابعة المتدرب(' . $traineesById[(int)$task['related_trainee']] . ')');
                                    } else {
                                        echo htmlspecialchars($task['title']);
                                    }
                                    ?>
                                </h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                                    <?php echo formatTimeArabic($task['due_date']); ?>
                                </p>
                                <div class="flex items-center space-x-2 space-x-reverse">
                                    <span class="px-2 py-1 rounded-full text-xs <?php echo $task['priority'] === 'high' ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-400' : ($task['priority'] === 'medium' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-400' : 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400'); ?>">
                                        <?php echo htmlspecialchars($priorityLabels[$task['priority']] ?? $task['priority']); ?>
                                    </span>
                                    <span class="px-2 py-1 rounded-full text-xs <?php echo $task['status'] === 'completed' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400' : ($task['status'] === 'in_progress' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-400'); ?>">
                                        <?php echo htmlspecialchars($statusLabels[$task['status']] ?? $task['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/tasks/edit.php?id=<?php echo (int)$task['id']; ?>" 
                               class="text-orange-600 dark:text-orange-400 hover:text-orange-800 dark:hover:text-orange-300 transition-colors duration-200">
                                <i class="fas fa-edit"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- قسم جميع المهام -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
    <div class="border-b border-gray-200 dark:border-gray-700 px-6 py-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white">جميع المهام</h2>
            <span class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-3 py-1 rounded-full text-sm font-medium">
                <?php echo count($tasks); ?> مهمة
            </span>
        </div>
        
        <!-- نموذج التصفية -->
        <form class="flex items-center space-x-2 space-x-reverse mt-4" method="get">
            <select name="status" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                <option value="">كل الحالات</option>
                <?php 
                $statuses = ['pending'=>'معلقة','in_progress'=>'قيد التنفيذ','completed'=>'منجزة','cancelled'=>'ملغاة']; 
                foreach($statuses as $k=>$l): 
                ?>
                    <option value="<?php echo $k; ?>" <?php echo (($_GET['status'] ?? '')===$k?'selected':''); ?>>
                        <?php echo $l; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input name="assigned_to" placeholder="ID المعيّن له" 
                   class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" 
                   value="<?php echo htmlspecialchars($_GET['assigned_to'] ?? ''); ?>">
            <button class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm transition-colors duration-200" type="submit">
                <i class="fas fa-filter ml-2"></i>تصفية
            </button>
            <?php if (isset($_GET['status']) || isset($_GET['assigned_to'])): ?>
            <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/tasks/list.php" 
               class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-500 text-sm transition-colors duration-200">
                إعادة تعيين
            </a>
            <?php endif; ?>
            <div class="flex items-center gap-2">
                <a href="export_excel.php?<?php echo http_build_query($_GET); ?>" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">
                    <i class="fas fa-file-excel ml-1"></i> تصدير Excel
                </a>
            </div>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr>
            
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">العنوان</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">تاريخ الاستحقاق</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الأولوية</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الحالة</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">المعيّن له</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($tasks)): ?>
                <tr>
                    <td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                        <i class="fas fa-tasks text-4xl mb-3 text-gray-300"></i>
                        <p class="font-medium">لا توجد مهام</p>
                        <p class="text-sm mt-1">ابدأ بإضافة مهمة جديدة</p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($tasks as $t): ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors duration-150">
                   
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                        <?php
                        // If task references a trainee, show the trainee's name as the title for the table row
                        if (!empty($t['related_trainee']) && isset($traineesById[(int)$t['related_trainee']])) {
                            echo htmlspecialchars('متابعة المتدرب(' . $traineesById[(int)$t['related_trainee']] . ')');
                        } else {
                            echo htmlspecialchars($t['title']);
                        }
                        ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-200">
                        <?php echo date('Y-m-d', strtotime($t['due_date'])) . ' ' . formatTimeArabic($t['due_date']); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-xs">
                        <?php
                        $priorityClasses = [
                            'high' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-400',
                            'medium' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-400',
                            'low' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400'
                        ];
                        $class = $priorityClasses[$t['priority']] ?? $priorityClasses['medium'];
                        ?>
                        <span class="px-2 py-1 rounded-full <?php echo $class; ?>">
                            <?php echo htmlspecialchars($priorityLabels[$t['priority']] ?? $t['priority']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-xs">
                        <?php
                        $statusClasses = [
                            'completed' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400',
                            'in_progress' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400',
                            'cancelled' => 'bg-gray-100 text-gray-800 dark:bg-gray-900/40 dark:text-gray-400',
                            'pending' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-400'
                        ];
                        $class = $statusClasses[$t['status']] ?? $statusClasses['pending'];
                        ?>
                        <span class="px-2 py-1 rounded-full <?php echo $class; ?>">
                            <?php echo htmlspecialchars($statusLabels[$t['status']] ?? $t['status']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-200">
                        <?php
                        $assignedName = '';
                        if (!empty($t['assigned_to']) && isset($usersById[(int)$t['assigned_to']])) {
                            $assignedName = $usersById[(int)$t['assigned_to']];
                        }
                        // fallback to numeric id if name not available
                        echo htmlspecialchars($assignedName !== '' ? $assignedName : (int)$t['assigned_to']);
                        if ($t['assigned_to'] == $current_user_id): ?>
                        <span class="bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400 text-xs px-2 py-1 rounded-full mr-2">أنت</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-left text-sm">
                        <a class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 hover:underline ml-3 transition-colors duration-200" 
                           href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/tasks/edit.php?id=<?php echo (int)$t['id']; ?>">
                           <i class="fas fa-edit ml-1"></i>تعديل
                        </a>
                        <?php if (hasAccess('tasks','delete')): ?>
                        <a class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 hover:underline transition-colors duration-200" 
                           href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/tasks/delete.php?id=<?php echo (int)$t['id']; ?>" 
                           onclick="return confirm('هل أنت متأكد من حذف هذه المهمة؟');">
                           <i class="fas fa-trash ml-1"></i>حذف
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../../includes/tw_layout_end.php'; ?>
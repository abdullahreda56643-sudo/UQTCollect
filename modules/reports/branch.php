<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

// require admin or explicit reports view permission (branch report)
requireAccessOrAdmin('reports', 'view');

$db = (new Database())->getConnection();

// جلب فرع المستخدم الحالي (للمشرف/الموظف)، المدير يمكنه اختيار الفرع
$currentUserId = (int)$_SESSION['user_id'];
$stmt = $db->prepare('SELECT branch_id, role FROM users WHERE id = :id');
$stmt->bindParam(':id', $currentUserId);
$stmt->execute();
$userRow = $stmt->fetch();
$userBranchId = (int)($userRow['branch_id'] ?? 0);
$userRole = $userRow['role'] ?? 'employee';

// معلمات التصفية
$start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end = $_GET['end'] ?? date('Y-m-d');
$branchId = ($userRole === 'admin') ? (int)($_GET['branch_id'] ?? $userBranchId) : $userBranchId;

// إحصائيات التواصل حسب الأيام
$contactsByDaySql = "
    SELECT DATE(c.contact_date) as contact_day,
           COUNT(*) as total_contacts,
           COUNT(DISTINCT c.trainee_id) as unique_trainees
    FROM contacts c
    INNER JOIN trainees t ON t.id = c.trainee_id
    WHERE t.branch_id = :branch_id
      AND DATE(c.contact_date) BETWEEN :start AND :end
    GROUP BY DATE(c.contact_date)
    ORDER BY contact_day ASC";
$stmt = $db->prepare($contactsByDaySql);
$stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
$stmt->bindValue(':start', $start);
$stmt->bindValue(':end', $end);
$stmt->execute();
$contactsByDay = $stmt->fetchAll();

// إحصائيات حسب الموظفين
$contactsByEmployeeSql = "
    SELECT u.full_name as employee_name,
           COUNT(*) as total_contacts,
           COUNT(DISTINCT c.trainee_id) as unique_trainees
    FROM contacts c
    INNER JOIN trainees t ON t.id = c.trainee_id
    INNER JOIN users u ON u.id = c.created_by
    WHERE t.branch_id = :branch_id
      AND DATE(c.contact_date) BETWEEN :start AND :end
    GROUP BY u.id
    ORDER BY total_contacts DESC";
$stmt = $db->prepare($contactsByEmployeeSql);
$stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
$stmt->bindValue(':start', $start);
$stmt->bindValue(':end', $end);
$stmt->execute();
$contactsByEmployee = $stmt->fetchAll();

// إجماليات سريعة
$totals = [
    'total_contacts' => array_sum(array_map(function($r){ return (int)$r['total_contacts']; }, $contactsByDay)),
    'unique_trainees' => array_sum(array_map(function($r){ return (int)$r['unique_trainees']; }, $contactsByDay))
];
?>
<?php $page_title = 'تقرير تواصل الفرع'; include '../../includes/tw_layout_start.php'; ?>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">تقرير تواصل الفرع</h1>
        <form method="get" class="flex items-center space-x-2 space-x-reverse">
            <?php if ($userRole === 'admin'): ?>
            <input name="branch_id" type="number" value="<?php echo (int)$branchId; ?>" placeholder="ID الفرع" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            <?php endif; ?>
            <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">تحديث</button>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 border-blue-500">
            <div class="text-sm text-gray-500 dark:text-gray-400">إجمالي الاتصالات</div>
            <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-white"><?php echo number_format($totals['total_contacts']); ?></div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 border-green-500">
            <div class="text-sm text-gray-500 dark:text-gray-400">متدربون تم التواصل معهم</div>
            <div class="text-2xl font-bold mt-1 text-gray-900 dark:text-white"><?php echo number_format($totals['unique_trainees']); ?></div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">الاتصالات حسب الأيام</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">التاريخ</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">عدد الاتصالات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">متدربين فريدين</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($contactsByDay as $r): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($r['contact_day']); ?></td>
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo (int)$r['total_contacts']; ?></td>
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo (int)$r['unique_trainees']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">الاتصالات حسب الموظفين</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الموظف</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">عدد الاتصالات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">متدربين فريدين</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($contactsByEmployee as $r): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($r['employee_name']); ?></td>
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo (int)$r['total_contacts']; ?></td>
                            <td class="px-6 py-3 text-sm text-gray-800 dark:text-gray-200"><?php echo (int)$r['unique_trainees']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php include '../../includes/tw_layout_end.php'; ?>





<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

// Require view access to users list (admin or explicit user view permission)
requireAccessOrAdmin('users', 'view');
$db = (new Database())->getConnection();

// معالجة طلب تغيير حالة المستخدم
if (isset($_POST['toggle_status']) && (isAdmin() || hasAccess('users','edit'))) {
    $user_id = (int)$_POST['user_id'];
    $current_status = (int)$_POST['current_status'];
    $new_status = $current_status ? 0 : 1;
    
    // التحقق من أن المستخدم لا يمكنه تعديل حالته الخاصة
    if ($user_id != $_SESSION['user_id']) {
        $stmt = $db->prepare("UPDATE users SET is_active = :status WHERE id = :id");
        $stmt->bindValue(':status', $new_status, PDO::PARAM_INT);
        $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // إعادة تحميل الصفحة لتحديث البيانات
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $error = "لا يمكنك تعديل حالة حسابك الخاص";
    }
}

$role = $_SESSION['role'] ?? 'employee';
if ($role === 'admin') {
    $stmt = $db->query("SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.branch_id, u.is_active FROM users u ORDER BY u.id DESC LIMIT 200");
} else {
    $stmt = $db->prepare("SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.branch_id, u.is_active FROM users u WHERE u.branch_id = (SELECT branch_id FROM users WHERE id = :id) ORDER BY u.id DESC LIMIT 200");
    $stmt->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
}
$users = $stmt->fetchAll();
?>
<?php $page_title = 'المستخدمون'; include '../../includes/tw_layout_start.php'; ?>

<?php if (isset($error)): ?>
    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
        <span class="block sm:inline"><?php echo $error; ?></span>
    </div>
<?php endif; ?>

<div class="flex items-center justify-between mb-4">
    <div class="flex items-center">
        <div class="w-12 h-12 rounded-xl bg-blue-600 bg-opacity-10 flex items-center justify-center ml-4">
            <i class="fas fa-users text-blue-600 text-xl"></i>
        </div>
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white">إدارة المستخدمين</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">قائمة بجميع المستخدمين في النظام</p>
        </div>
    </div>
    <?php if (isAdmin()): ?>
    <a href="add.php" class="inline-flex items-center px-5 py-2.5 bg-blue-600 text-white rounded-lg shadow-md hover:bg-blue-700 transition-all duration-200 transform hover:-translate-y-0.5 focus:ring-4 focus:ring-blue-200 dark:focus:ring-blue-900">
        <i class="fas fa-user-plus text-lg ml-2"></i>
        <span>إضافة موظف جديد</span>
    </a>
    <?php endif; ?>
</div>
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr>
                   
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الاسم</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">المعرف</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الدور</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الفرع</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">الحالة</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php foreach ($users as $u): ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                
                    <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($u['full_name']); ?></td>
                    <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200"><?php echo htmlspecialchars($u['username']); ?></td>
                    <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200"><?php echo htmlspecialchars($u['role']); ?></td>
                    <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200"><?php echo (int)$u['branch_id']; ?></td>
                    <td class="px-6 py-4 text-xs">
                        <span class="px-2 py-1 rounded-full <?php echo ((int)$u['is_active']===1)?'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400':'bg-gray-100 text-gray-800 dark:bg-gray-900/40 dark:text-gray-400'; ?>">
                            <?php echo ((int)$u['is_active']===1)?'نشط':'موقوف'; ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-left text-sm">
                        <!-- زر التعديل - يظهر للمدير والمشرف والموظف لحسابه الشخصي فقط -->
                        <?php if (isAdmin() || hasAccess('users','edit') || (int)$u['id'] == $_SESSION['user_id']): ?>
                        <a class="text-blue-600 dark:text-blue-400 hover:underline ml-3" 
                           href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/users/edit.php?id=<?php echo (int)$u['id']; ?>">
                           تعديل
                        </a>
                        <?php endif; ?>

                        <!-- زر إدارة الصلاحيات - للمدير فقط -->
                        <?php if (isAdmin()): ?>
                        <a class="text-purple-600 dark:text-purple-400 hover:underline ml-3" 
                           href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/users/manage_permissions.php?user_id=<?php echo (int)$u['id']; ?>">
                           إدارة الصلاحيات
                        </a>
                        <?php endif; ?>

                        <!-- زر تغيير حالة المستخدم - للمدير والمشرف فقط ولا يظهر للموظفين -->
                        <?php if ((isAdmin() || isSupervisor()) && $u['id'] != $_SESSION['user_id']): ?>
                        <form method="post" class="inline-block ml-3">
                            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                            <input type="hidden" name="current_status" value="<?php echo (int)$u['is_active']; ?>">
                            <button type="submit" name="toggle_status" 
                                    class="<?php echo ((int)$u['is_active']===1) ? 'text-yellow-600 dark:text-yellow-400' : 'text-green-600 dark:text-green-400'; ?> hover:underline">
                                <?php echo ((int)$u['is_active']===1) ? 'تعطيل' : 'تفعيل'; ?>
                            </button>
                        </form>
                        <?php endif; ?>

                        <!-- زر الحذف - للمدير والمشرف فقط ولا يظهر للموظفين -->
                        <?php if ((isAdmin() || isSupervisor()) && $u['id'] != $_SESSION['user_id']): ?>
                        <a class="text-red-600 dark:text-red-400 hover:underline ml-3" 
                           href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/users/delete.php?id=<?php echo (int)$u['id']; ?>"
                           onclick="return confirm('هل أنت متأكد من حذف هذا المستخدم؟')">
                           حذف
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include '../../includes/tw_layout_end.php'; ?>
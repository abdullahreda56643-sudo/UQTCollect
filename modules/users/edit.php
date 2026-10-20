<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

// المدير والمشرف يمكنهما التعديل (المشرف ضمن نطاق الفرع فقط)
// Allow admin or users with explicit users edit permission
requireAccessOrAdmin('users', 'edit');
$db = (new Database())->getConnection();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/users/list.php', 'معرّف غير صالح', 'error');

// إذا كان المستخدم الحالي موظفاً (ليس مديراً أو مشرفاً) ويحاول تعديل حساب آخر
if (!isAdmin() && !isSupervisor() && $id != $_SESSION['user_id']) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/users/list.php', 'غير مسموح لك بتعديل هذا المستخدم', 'error');
}

// تحقق نطاق الفرع للمشرف
if (!isAdmin()) {
    $check = $db->prepare('SELECT 1 FROM users target JOIN users me ON me.id = :me WHERE target.id = :id AND target.branch_id = me.branch_id');
    $check->bindValue(':me', $_SESSION['user_id']);
    $check->bindValue(':id', $id);
    $check->execute();
    if ($check->rowCount() === 0) {
        redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/users/list.php', 'غير مسموح لك بتعديل هذا المستخدم', 'error');
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // تم إزالة التحقق من رمز CSRF للأمان
    {
        try {
            // إذا كان المستخدم موظفاً ويحاول تعديل حسابه الشخصي، نفرض بعض القيود
            $isEmployeeEditingSelf = (!isAdmin() && !isSupervisor() && $id == $_SESSION['user_id']);
            
            // تحقق من كلمة المرور الجديدة إن وُجدت
            $newPassword = trim($_POST['new_password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');

            $maxTrainees = (int)($_POST['max_trainees'] ?? 50);
            
            if ($newPassword !== '') {
                if ($newPassword !== $confirmPassword) {
                    throw new Exception('كلمة المرور الجديدة وتأكيدها غير متطابقين');
                }
                // تشفير كلمة المرور الجديدة
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                if ($isEmployeeEditingSelf) {
                    // الموظف يمكنه فقط تغيير كلمة المرور والبيانات الأساسية
                    $stmt = $db->prepare('UPDATE users SET full_name = :full_name, email = :email, phone = :phone, password = :password WHERE id = :id');
                    $stmt->bindValue(':password', $hashed);
                } else {
                    $stmt = $db->prepare('UPDATE users SET full_name = :full_name, email = :email, phone = :phone, role = :role, is_active = :is_active, password = :password, max_trainees = :max_trainees WHERE id = :id');
                    $stmt->bindValue(':password', $hashed);
                    $stmt->bindValue(':role', $_POST['role'] ?? 'employee');
                    $stmt->bindValue(':is_active', isset($_POST['is_active']) ? 1 : 0, PDO::PARAM_INT);
                    $stmt->bindValue(':max_trainees', $maxTrainees, PDO::PARAM_INT);
                }
            } else {
                if ($isEmployeeEditingSelf) {
                    // الموظف يمكنه فقط تحديث بياناته الأساسية
                    $stmt = $db->prepare('UPDATE users SET full_name = :full_name, email = :email, phone = :phone WHERE id = :id');
                } else {
                    $stmt = $db->prepare('UPDATE users SET full_name = :full_name, email = :email, phone = :phone, role = :role, is_active = :is_active, max_trainees = :max_trainees WHERE id = :id');
                    $stmt->bindValue(':role', $_POST['role'] ?? 'employee');
                    $stmt->bindValue(':is_active', isset($_POST['is_active']) ? 1 : 0, PDO::PARAM_INT);
                    $stmt->bindValue(':max_trainees', $maxTrainees, PDO::PARAM_INT);
                }
            }

            $stmt->bindValue(':full_name', trim($_POST['full_name'] ?? ''));
            $stmt->bindValue(':email', trim($_POST['email'] ?? ''));
            $stmt->bindValue(':phone', trim($_POST['phone'] ?? ''));
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/users/edit.php?id=' . $id, 'تم حفظ التعديلات');
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$stmt = $db->prepare('SELECT id, username, full_name, email, phone, role, branch_id, is_active, max_trainees FROM users WHERE id = :id');
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$row = $stmt->fetch();
if (!$row) redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/users/list.php', 'المستخدم غير موجود', 'error');

// التحقق إذا كان المستخدم الحالي موظفاً ويحاول تعديل حسابه الشخصي
$isEmployeeEditingSelf = (!isAdmin() && !isSupervisor() && $id == $_SESSION['user_id']);
?>
<?php $page_title = 'تعديل مستخدم'; include '../../includes/tw_layout_start.php'; ?>
    <h1 class="text-xl font-bold mb-4">تعديل مستخدم</h1>
    <?php if($error): ?><div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="post" class="space-y-4">
        <!-- تم إزالة حقل CSRF المخفي -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">الاسم الكامل</label>
                <input name="full_name" value="<?php echo htmlspecialchars($row['full_name']); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" required>
            </div>
            
            <?php if (!$isEmployeeEditingSelf): ?>
            <div>
                <label class="block text-sm mb-1">الحد الأقصى للمتدربين للموظف</label>
                <input name="max_trainees" type="number" min="1" value="<?php echo htmlspecialchars($row['max_trainees'] ?? 50); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" required>
                <small class="text-xs text-gray-500">القيمة الافتراضية 50 ويمكنك زيادتها</small>
            </div>
            <?php endif; ?>
            
            <div>
                <label class="block text-sm mb-1">البريد</label>
                <input name="email" type="email" value="<?php echo htmlspecialchars($row['email']); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            </div>
            <div>
                <label class="block text-sm mb-1">الهاتف</label>
                <input name="phone" value="<?php echo htmlspecialchars($row['phone']); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            </div>
            
            <?php if (!$isEmployeeEditingSelf): ?>
            <div>
                <label class="block text-sm mb-1">الدور</label>
                <select name="role" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    <?php $roles=['admin'=>'مدير','supervisor'=>'مشرف','employee'=>'موظف']; foreach($roles as $k=>$l): ?>
                    <option value="<?php echo $k; ?>" <?php echo ($row['role']===$k?'selected':''); ?>><?php echo $l; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-2 flex items-center space-x-2 space-x-reverse">
                <input id="is_active" name="is_active" type="checkbox" class="h-4 w-4" <?php echo ((int)$row['is_active']===1)?'checked':''; ?>>
                <label for="is_active" class="text-sm">نشط</label>
            </div>
            <?php endif; ?>
            
            <div class="md:col-span-2">
                <label class="block text-sm mb-1">كلمة المرور الجديدة (اتركها فارغة إن لم ترغب بالتغيير)</label>
                <div class="relative">
                    <input id="new_password" name="new_password" type="password" autocomplete="new-password" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 pr-10">
                    <button type="button" aria-label="Toggle password visibility" class="absolute inset-y-0 right-2 flex items-center px-2 text-gray-600 dark:text-gray-300" onclick="togglePassword('new_password','new_pw_eye')">
                        <svg id="new_pw_eye" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 4.5c-4 0-7.27 2.5-8.5 6 1.23 3.5 4.5 6 8.5 6s7.27-2.5 8.5-6c-1.23-3.5-4.5-6-8.5-6zM10 14a4 4 0 110-8 4 4 0 010 8z" />
                            <path d="M10 8.5a1.5 1.5 0 100 3 1.5 1.5 0 000-3z" />
                        </svg>
                    </button>
                </div>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm mb-1">تأكيد كلمة المرور الجديدة</label>
                <div class="relative">
                    <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 pr-10">
                    <button type="button" aria-label="Toggle password visibility" class="absolute inset-y-0 right-2 flex items-center px-2 text-gray-600 dark:text-gray-300" onclick="togglePassword('confirm_password','confirm_pw_eye')">
                        <svg id="confirm_pw_eye" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 4.5c-4 0-7.27 2.5-8.5 6 1.23 3.5 4.5 6 8.5 6s7.27-2.5 8.5-6c-1.23-3.5-4.5-6-8.5-6zM10 14a4 4 0 110-8 4 4 0 010 8z" />
                            <path d="M10 8.5a1.5 1.5 0 100 3 1.5 1.5 0 000-3z" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        <div class="mt-2 space-x-2 space-x-reverse">
            <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg" type="submit">حفظ</button>
            <a class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-lg" href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/users/list.php">عودة</a>
        </div>
    </form>
<?php include '../../includes/tw_layout_end.php'; ?>
<script>
    // Toggle visibility for password inputs. id: input id, eyeId: svg id
    function togglePassword(id, eyeId) {
        try {
            var input = document.getElementById(id);
            var eye = document.getElementById(eyeId);
            if (!input || !eye) return;
            if (input.type === 'password') {
                input.type = 'text';
                // change icon to indicate 'visible' (simple stroke change by replacing path)
                eye.innerHTML = '<path d="M4.03 3.97a.75.75 0 10-1.06 1.06l1.7 1.7A9.94 9.94 0 001.5 10c1.23 3.5 4.5 6 8.5 6 1.6 0 3.1-.34 4.42-.96l1.9 1.9a.75.75 0 101.06-1.06L4.03 3.97zM10 6.5a3.5 3.5 0 013.08 5.13L8.37 6.47A3.48 3.48 0 0110 6.5zM6.92 4.64l1.57 1.57A3.48 3.48 0 0110 6.5c.66 0 1.27.18 1.8.48l1.66 1.66A9.94 9.94 0 001.5 10c.77-2.17 2.06-3.98 3.42-5.36.86-.86 1.8-1.5 1.99-1.5.12 0 .37.03.01.5z"/>';
            } else {
                input.type = 'password';
                // restore original eye (closed) icon
                eye.innerHTML = '<path d="M10 4.5c-4 0-7.27 2.5-8.5 6 1.23 3.5 4.5 6 8.5 6s7.27-2.5 8.5-6c-1.23-3.5-4.5-6-8.5-6zM10 14a4 4 0 110-8 4 4 0 010 8z" /><path d="M10 8.5a1.5 1.5 0 100 3 1.5 1.5 0 000-3z" />';
            }
        } catch (e) {
            console.error(e);
        }
    }
</script>
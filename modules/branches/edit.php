<?php
require_once '../../config.php';
require_once '../../db/connection.php';
$require_once_line = "require_once '../../includes/auth.php';";
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Branch.php';

// require admin or explicit branches edit permission
requireAccessOrAdmin('branches', 'edit');
$db = (new Database())->getConnection();
$branchModel = new Branch($db);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/branches/list.php', 'معرّف غير صالح', 'error');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    { // تم حذف التحقق من رمز الأمان CSRF
        try {
            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'address' => trim($_POST['address'] ?? ''),
                'phone' => trim($_POST['phone'] ?? ''),
                'manager_id' => $_POST['manager_id'] !== '' ? (int)$_POST['manager_id'] : null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ];
            $branchModel->update($id, $data);
            redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/branches/edit.php?id=' . $id, 'تم حفظ التعديلات');
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$row = $branchModel->findById($id);
if (!$row) redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/branches/list.php', 'السجل غير موجود', 'error');
?>
<?php $page_title = 'تعديل فرع'; include '../../includes/tw_layout_start.php'; ?>
    <h1 class="text-xl font-bold mb-4">تعديل فرع</h1>
    <?php if($error): ?><div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="post" class="space-y-4">
        <!-- تم حذف حقل CSRF -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">اسم الفرع</label>
                <input name="name" value="<?php echo htmlspecialchars($row['name']); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" required>
            </div>
            <div>
                <label class="block text-sm mb-1">الهاتف</label>
                <input name="phone" value="<?php echo htmlspecialchars($row['phone']); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm mb-1">العنوان</label>
                <input name="address" value="<?php echo htmlspecialchars($row['address']); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            </div>
            <div>
                <label class="block text-sm mb-1">ID المدير (اختياري)</label>
                <input name="manager_id" type="number" value="<?php echo htmlspecialchars($row['manager_id']); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            </div>
            <div class="flex items-center space-x-2 space-x-reverse">
                <input id="is_active" name="is_active" type="checkbox" class="h-4 w-4" <?php echo ((int)$row['is_active']===1)?'checked':''; ?>>
                <label for="is_active" class="text-sm">نشط</label>
            </div>
        </div>
        <div class="mt-2 space-x-2 space-x-reverse">
            <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg" type="submit">حفظ</button>
            <a class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-lg" href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/branches/list.php">عودة</a>
        </div>
    </form>
<?php include '../../includes/tw_layout_end.php'; ?>





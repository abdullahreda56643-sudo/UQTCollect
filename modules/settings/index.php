<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Setting.php';

// require admin or explicit settings view permission
requireAccessOrAdmin('settings', 'view');
// require admin or explicit settings view permission
requireAccessOrAdmin('settings', 'view');
$db = (new Database())->getConnection();
$settingModel = new Setting($db);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    { // تم حذف التحقق من رمز الأمان CSRF
        try {
            // أمثلة مفاتيح قابلة للتعديل
            $settingModel->set('company_name', trim($_POST['company_name'] ?? ''));
            $settingModel->set('default_currency', strtoupper(trim($_POST['default_currency'] ?? 'SAR')));
            $settingModel->set('default_currency_symbol', trim($_POST['default_currency_symbol'] ?? ''));
            // صور الأدوار
            $settingModel->set('avatar_admin', trim($_POST['avatar_admin'] ?? ''));
            $settingModel->set('avatar_supervisor', trim($_POST['avatar_supervisor'] ?? ''));
            $settingModel->set('avatar_employee', trim($_POST['avatar_employee'] ?? ''));
            $settingModel->set('max_trainees_per_employee', (int)($_POST['max_trainees_per_employee'] ?? 50));
            redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/settings/index.php', 'تم حفظ الإعدادات');
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$company = $settingModel->getByKey('company_name')['value'] ?? '';
$currency = $settingModel->getByKey('default_currency')['value'] ?? 'SAR';
$currencySymbol = $settingModel->getByKey('default_currency_symbol')['value'] ?? '';
$avatarAdmin = $settingModel->getByKey('avatar_admin')['value'] ?? '';
$avatarSupervisor = $settingModel->getByKey('avatar_supervisor')['value'] ?? '';
$avatarEmployee = $settingModel->getByKey('avatar_employee')['value'] ?? '';
$maxTrainees = $settingModel->getByKey('max_trainees_per_employee')['value'] ?? '50';
?>
<?php $page_title = 'الإعدادات'; include '../../includes/tw_layout_start.php'; ?>
    <h1 class="text-xl font-bold mb-4">إعدادات النظام</h1>
    <?php if($error): ?><div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="post" class="space-y-4">
        <!-- تم حذف حقل CSRF -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">اسم الشركة</label>
                <input name="company_name" value="<?php echo htmlspecialchars($company); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            </div>
            <div>
                <label class="block text-sm mb-1">العملة الافتراضية (مثال: SAR, USD)</label>
                <input name="default_currency" value="<?php echo htmlspecialchars($currency); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            </div>
            <div>
                <label class="block text-sm mb-1">رمز العملة (اختياري)</label>
                <input name="default_currency_symbol" value="<?php echo htmlspecialchars($currencySymbol); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" placeholder="مثال: ر.س أو $">
            </div>
           
            <div>
                <label class="block text-sm mb-1">الحد الأقصى للمتدربين لكل موظف</label>
                <input name="max_trainees_per_employee" type="number" value="<?php echo htmlspecialchars($maxTrainees); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
            </div>
        </div>
        <div class="mt-2">
            <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg" type="submit">حفظ</button>
        </div>
    </form>
<?php include '../../includes/tw_layout_end.php'; ?>

















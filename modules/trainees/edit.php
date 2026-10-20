<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Trainee.php';
require_once '../../models/Diploma.php';
require_once '../../models/Branch.php';

checkPermission('employee');
$db = (new Database())->getConnection();
$traineeModel = new Trainee($db);
$diplomaModel = new Diploma($db);
$branchModel  = new Branch($db);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $url = rtrim(SITE_URL,'/') . '/modules/trainees/list.php';
    header("Location: $url");
    exit;
}

// حماية: تأكد أن المستخدم يملك حق الوصول لهذا المتدرب
try {
    if (function_exists('canAccessTrainee') && !canAccessTrainee($id, $db)) {
        redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/trainees/list.php', 'غير مصرح لك بالوصول إلى بيانات هذا المتدرب', 'error');
    }
} catch (Throwable $e) {
    error_log('canAccessTrainee check failed: ' . $e->getMessage());
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // جلب القيم القديمة قبل التحديث
        $oldRow = $traineeModel->findById($id);

        $data = [
            'full_name'       => trim($_POST['full_name'] ?? ''),
            'phone'           => trim($_POST['phone'] ?? ''),
            'status'          => $_POST['status'] ?? 'under_communication',
            'notes'           => trim($_POST['notes'] ?? ''),
            'diploma_id'      => (int)($_POST['diploma_id'] ?? 0),
            'branch_id'       => (int)($_POST['branch_id'] ?? 0),
            'graduation_date' => !empty($_POST['graduation_date']) ? $_POST['graduation_date'] : null,
            'total_amount'    => (float)($_POST['total_amount'] ?? 0),
            'paid_amount'     => (float)($_POST['paid_amount'] ?? 0),
            'remaining_amount' => (float)($_POST['remaining_amount'] ?? 0),
        ];

        // توليد وحفظ التاريخ الهجري إذا وُجد تاريخ ميلادي
        if (!empty($data['graduation_date'])) {
            $hijri = '';
            try {
                if (class_exists('IntlDateFormatter')) {
                    $fmt = new IntlDateFormatter('ar_SA@calendar=islamic', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL);
                    $ts = strtotime($data['graduation_date']);
                    if ($ts !== false && $ts > 0) {
                        $hijri = $fmt->format($ts);
                    } else {
                        $dt = new DateTime($data['graduation_date']);
                        $hijri = $fmt->format($dt);
                    }
                }
            } catch (\Throwable $e) {
                $hijri = '';
            }
            if ($hijri !== '') {
                $data['graduation_date_hijri'] = $hijri;
            } else {
                // إذا لم نتمكن من توليد الهجري، تأكد من إزالة أي قيمة سابقة إذا المستخدم حذف التاريخ
                if (empty($_POST['graduation_date'])) {
                    $data['graduation_date_hijri'] = null;
                }
            }
        } else {
            // المستخدم أزال التاريخ؛ تأكد من مسح الحقل الهجري كذلك
            $data['graduation_date_hijri'] = null;
        }

        $traineeModel->update($id, $data);

        // سجل النشاط: التحديث
        try {
            require_once '../../includes/auth.php';
            $newValues = $traineeModel->findById($id);
            logActivity($db, 'update', 'trainees', $id, $oldRow, $newValues);
        } catch (Exception $e) {
            error_log('Failed to log trainee update: ' . $e->getMessage());
        }

        $target = rtrim(SITE_URL,'/') . '/modules/trainees/edit.php?id=' . $id;
        header("Location: $target");
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$row = $traineeModel->findById($id);
if (!$row) {
    $url = rtrim(SITE_URL,'/') . '/modules/trainees/list.php';
    header("Location: $url");
    exit;
}

$diplomas = $diplomaModel->list([], 'name ASC');
$branches = $branchModel->list([], 'name ASC');
?>
<?php $page_title = 'تعديل متدرب'; include '../../includes/tw_layout_start.php'; ?>
    <h1 class="text-xl font-bold mb-4">تعديل متدرب</h1>
    <?php if($error): ?>
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="post" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm mb-1">الاسم الكامل</label>
                <input name="full_name" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" required value="<?php echo htmlspecialchars($row['full_name'] ?? ''); ?>">
            </div>
            <div>
                <label class="block text-sm mb-1">الهاتف</label>
                <input name="phone" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" value="<?php echo htmlspecialchars($row['phone'] ?? ''); ?>">
            </div>
            <div>
                <label class="block text-sm mb-1">الفرع</label>
                <select name="branch_id" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>" <?php echo (($row['branch_id'] ?? '') == $branch['id'] ? 'selected' : ''); ?>>
                            <?php echo htmlspecialchars($branch['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm mb-1">الدبلوم</label>
                <select name="diploma_id" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    <?php foreach ($diplomas as $diploma): ?>
                        <option value="<?php echo $diploma['id']; ?>" <?php echo (($row['diploma_id'] ?? '') == $diploma['id'] ? 'selected' : ''); ?>>
                            <?php echo htmlspecialchars($diploma['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm mb-1">تاريخ التخرج</label>
                <input name="graduation_date" type="date" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" value="<?php echo htmlspecialchars($row['graduation_date'] ?? ''); ?>">
            </div>
            <div>
                <label class="block text-sm mb-1">الحالة</label>
                <select name="status" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    <?php
                    $statuses = [
                        'under_communication' => 'قيد التواصل',
                        'promised_payment'    => 'وعد بالسداد',
                        'delayed'             => 'متأخر',
                        'paid'                => 'مسدد'
                    ];
                    foreach ($statuses as $k=>$label): ?>
                        <option value="<?php echo $k; ?>" <?php echo (($row['status'] ?? '')===$k?'selected':''); ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm mb-1">المبلغ الإجمالي (ريال)</label>
                <input name="total_amount" type="number" step="0.01" min="0" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" value="<?php echo htmlspecialchars($row['total_amount'] ?? ''); ?>">
            </div>
            <div>
                <label class="block text-sm mb-1">المبلغ المدفوع (ريال)</label>
                <input name="paid_amount" type="number" step="0.01" min="0" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" value="<?php echo htmlspecialchars($row['paid_amount'] ?? ''); ?>">
            </div>
            <div>
                <label class="block text-sm mb-1">المبلغ المتبقي (ريال)</label>
                <input name="remaining_amount" type="number" step="0.01" min="0" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700" value="<?php echo htmlspecialchars($row['remaining_amount'] ?? ''); ?>">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm mb-1">ملاحظات</label>
                <textarea name="notes" rows="3" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700"><?php echo htmlspecialchars($row['notes'] ?? ''); ?></textarea>
            </div>
        </div>
        <div class="mt-2 space-x-2 space-x-reverse">
            <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg" type="submit">حفظ</button>
            <a class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-lg" href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/trainees/list.php">عودة</a>
        </div>
    </form>
<?php include '../../includes/tw_layout_end.php'; ?>


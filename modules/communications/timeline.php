<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../includes/communication_helpers.php';
require_once '../../models/Communication.php';
require_once '../../models/Trainee.php';

checkPermission('employee');
$db = (new Database())->getConnection();
$communicationModel = new Communication($db);
$traineeModel = new Trainee($db);

// التحقق من وجود معرف المتدرب
if (!isset($_GET['trainee_id']) || empty($_GET['trainee_id'])) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/modules/trainees/list.php');
    exit;
}

$traineeId = (int)$_GET['trainee_id'];
// استرجاع بيانات المتدرب
$trainee = $traineeModel->findById($traineeId);
if (!$trainee) {
    setFlashMessage('error', 'المتدرب غير موجود');
    header('Location: ' . rtrim(SITE_URL, '/') . '/modules/trainees/list.php');
    exit;
}

// الحصول على سجلات التواصل
$communications = $communicationModel->getTraineeCommunications($traineeId);

$page_title = 'الخط الزمني للتواصل - ' . $trainee['name'];
include '../../includes/tw_layout_start.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <!-- معلومات المتدرب -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-8">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-2xl font-bold"><?php echo htmlspecialchars($trainee['name']); ?></h1>
                <a href="add.php?trainee_id=<?php echo $traineeId; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-plus ml-1"></i> تواصل جديد
                </a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-gray-600 dark:text-gray-400">رقم الهاتف: <?php echo htmlspecialchars($trainee['phone']); ?></p>
                </div>
                <div>
                    <p class="text-gray-600 dark:text-gray-400">البريد الإلكتروني: <?php echo htmlspecialchars($trainee['email']); ?></p>
                </div>
            </div>
        </div>

        <!-- الخط الزمني -->
        <div class="relative">
            <?php foreach ($communications as $comm): ?>
            <div class="mb-8 flex">
                <!-- خط العمود -->
                <div class="flex flex-col items-center">
                    <div class="w-px h-full bg-gray-300 dark:bg-gray-600"></div>
                    <div class="<?php echo getResultColor($comm['result']); ?> w-4 h-4 rounded-full border-4 border-white dark:border-gray-800"></div>
                </div>

                <!-- محتوى التواصل -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mr-4 flex-grow">
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <span class="font-semibold"><?php echo getTypeLabel($comm['type']); ?></span>
                            <span class="text-gray-500 dark:text-gray-400 text-sm mr-2"><?php echo formatDateTime($comm['contact_date']); ?></span>
                        </div>
                        <span class="<?php echo getResultBadgeClass($comm['result']); ?>"><?php echo getResultLabel($comm['result']); ?></span>
                    </div>

                    <?php if (!empty($comm['notes'])): ?>
                    <p class="text-gray-700 dark:text-gray-300 mt-2"><?php echo nl2br(htmlspecialchars($comm['notes'])); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($comm['next_action_date'])): ?>
                    <div class="mt-3 text-sm">
                        <span class="text-gray-500 dark:text-gray-400">الإجراء التالي: </span>
                        <span class="font-medium"><?php echo formatDateTime($comm['next_action_date']); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        بواسطة: <?php echo htmlspecialchars($comm['created_by_name']); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
// دوال مساعدة
function getTypeLabel($type) {
    return [
        'call' => '<i class="fas fa-phone-alt ml-1"></i> اتصال',
        'whatsapp' => '<i class="fab fa-whatsapp ml-1"></i> واتساب'
    ][$type] ?? $type;
}

function getResultLabel($result) {
    return [
        'promise' => 'وعد',
        'refused' => 'رفض',
        'paid' => 'تم السداد',
        'no_answer' => 'لم يرد',
        'other' => 'أخرى'
    ][$result] ?? $result;
}

function getResultColor($result) {
    return [
        'promise' => 'bg-yellow-500',
        'refused' => 'bg-red-500',
        'paid' => 'bg-green-500',
        'no_answer' => 'bg-gray-500',
        'other' => 'bg-blue-500'
    ][$result] ?? 'bg-gray-500';
}

function getResultBadgeClass($result) {
    return [
        'promise' => 'px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800',
        'refused' => 'px-2 py-1 text-xs rounded-full bg-red-100 text-red-800',
        'paid' => 'px-2 py-1 text-xs rounded-full bg-green-100 text-green-800',
        'no_answer' => 'px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800',
        'other' => 'px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800'
    ][$result] ?? 'px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800';
}
?>

<?php include '../../includes/tw_layout_end.php'; ?>
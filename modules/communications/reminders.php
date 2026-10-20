<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/Communication.php';

// دوال مساعدة
function formatDateTime($datetime) {
    $date = new DateTime($datetime);
    return $date->format('Y/m/d H:i');
}

// الحصول على معرف المستخدم الحالي
function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

checkPermission('employee');
$db = (new Database())->getConnection();
$communicationModel = new Communication($db);

// الحصول على التذكيرات القادمة للمستخدم الحالي
$reminders = $communicationModel->getUpcomingReminders(getCurrentUserId(), 20);

$page_title = 'التذكيرات القادمة';
include '../../includes/tw_layout_start.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">التذكيرات القادمة</h1>

        <?php if (empty($reminders)): ?>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 text-center">
            <p class="text-gray-600 dark:text-gray-400">لا توجد تذكيرات قادمة</p>
        </div>
        <?php else: ?>
        <div class="grid gap-4">
            <?php foreach ($reminders as $reminder): ?>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
                <div class="flex justify-between items-start">
                    <div>
                        <h3 class="font-semibold text-lg">
                            <a href="../trainees/view.php?id=<?php echo $reminder['trainee_id']; ?>" class="text-blue-600 dark:text-blue-400 hover:underline">
                                <?php echo htmlspecialchars($reminder['trainee_name']); ?>
                            </a>
                        </h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">
                            <i class="<?php echo $reminder['communication_type'] === 'call' ? 'fas fa-phone-alt' : 'fab fa-whatsapp'; ?> ml-1"></i>
                            <?php echo $reminder['communication_type'] === 'call' ? 'اتصال' : 'واتساب'; ?>:
                            <?php echo htmlspecialchars($reminder['notes']); ?>
                        </p>
                    </div>
                    <div class="text-left">
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            <?php echo formatDateTime($reminder['reminder_date']); ?>
                        </span>
                        <div class="mt-2">
                            <button onclick="completeReminder(<?php echo $reminder['id']; ?>)" class="text-green-600 dark:text-green-400 hover:underline ml-2">تم</button>
                            <button onclick="cancelReminder(<?php echo $reminder['id']; ?>)" class="text-red-600 dark:text-red-400 hover:underline">إلغاء</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function completeReminder(reminderId) {
    if (confirm('هل تريد تأكيد إتمام هذا التذكير؟')) {
        updateReminderStatus(reminderId, 'completed');
    }
}

function cancelReminder(reminderId) {
    if (confirm('هل تريد إلغاء هذا التذكير؟')) {
        updateReminderStatus(reminderId, 'cancelled');
    }
}

function updateReminderStatus(reminderId, status) {
    fetch('update_reminder.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `reminder_id=${reminderId}&status=${status}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('حدث خطأ أثناء تحديث التذكير');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ أثناء تحديث التذكير');
    });
}
</script>

<?php include '../../includes/tw_layout_end.php'; ?>
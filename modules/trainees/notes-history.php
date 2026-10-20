<?php
define('BASE_PATH', realpath(dirname(__FILE__) . '/../../'));
require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/db/connection.php';
require_once BASE_PATH . '/models/Trainee.php';
require_once BASE_PATH . '/models/Contact.php';
require_once BASE_PATH . '/models/AuditLog.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/modules/auth/login.php');
    exit();
}

// التحقق من وجود معرف المتدرب
if (!isset($_GET['trainee_id'])) {
    header('Location: ' . SITE_URL . '/modules/trainees/list.php');
    exit();
}

try {
    $traineeId = (int)$_GET['trainee_id'];

    // إنشاء اتصال قاعدة البيانات
    $db = (new Database())->getConnection();

// تهيئة النماذج
$contactModel = new Contact($db);
$auditModel = new AuditLog($db);

// جلب بيانات المتدرب
$query = "SELECT t.*, d.name as diploma_name, b.name as branch_name,
                 COALESCE(t.updated_at, t.created_at) as last_update
          FROM trainees t 
          LEFT JOIN diplomas d ON t.diploma_id = d.id 
          LEFT JOIN branches b ON t.branch_id = b.id
          WHERE t.id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$traineeId]);
$trainee = $stmt->fetch(PDO::FETCH_ASSOC);if (!$trainee) {
    header('Location: ' . SITE_URL . '/modules/trainees/list.php');
    exit();
}

// جلب الاتصالات
$query = "SELECT c.*, u.full_name as created_by_name 
          FROM contacts c 
          LEFT JOIN users u ON c.created_by = u.id 
          WHERE c.trainee_id = ? 
          ORDER BY c.contact_date DESC";
$stmt = $db->prepare($query);
$stmt->execute([$traineeId]);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// إضافة نوع الحدث للاتصالات
foreach ($contacts as &$contact) {
    $contact['type'] = 'contact';
    $contact['created_at'] = $contact['contact_date'];
}

// جلب سجل النشاطات
$query = "SELECT a.*, u.full_name as created_by_name 
          FROM system_activities a 
          LEFT JOIN users u ON a.user_id = u.id 
          WHERE a.module = 'trainees' 
          AND (a.related_id = ? OR a.description LIKE ?)
          ORDER BY a.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute([$traineeId, "%trainee_id: $traineeId%"]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// إضافة نوع الحدث للملاحظات
foreach ($notes as &$note) {
    $note['type'] = 'note';
    $note['notes'] = $note['description'];
}
if (!is_array($contacts)) $contacts = [];

$notes = $auditModel->getByTrainee($traineeId);
if (!is_array($notes)) $notes = [];

// دمج وترتيب جميع الأحداث
$allEvents = array_merge($contacts, $notes);
usort($allEvents, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// تجميع الأحداث حسب الشهر
$groupedEvents = [];
foreach ($allEvents as $event) {
    $monthYear = date('Y-m', strtotime($event['created_at']));
    if (!isset($groupedEvents[$monthYear])) {
        $groupedEvents[$monthYear] = [];
    }
    $groupedEvents[$monthYear][] = $event;
}

include '../../includes/tw_layout_start.php';
?>

<!-- رأس الصفحة -->
<div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg mb-6 overflow-hidden">
    <div class="px-6 py-4 bg-gradient-to-l from-blue-600 to-blue-800">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4 space-x-reverse">
                <div class="bg-white p-2 rounded-full">
                    <i class="fas fa-history text-blue-600 text-xl"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">سجل ملاحظات المتدرب</h1>
                    <p class="text-blue-100 text-sm mt-1"><?php echo htmlspecialchars($trainee['full_name']); ?></p>
                </div>
            </div>
            <a href="list.php" class="inline-flex items-center px-4 py-2 bg-white/10 text-white rounded-lg hover:bg-white/20 transition-colors">
                <i class="fas fa-arrow-right ml-2"></i>
                عودة للقائمة
            </a>
        </div>
    </div>

    <!-- معلومات المتدرب المختصرة -->
    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-750 border-b dark:border-gray-700">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="flex items-center">
                <div class="w-3 h-3 rounded-full <?php echo $trainee['status'] === 'active' ? 'bg-green-500' : 'bg-gray-400'; ?> ml-2"></div>
                <span class="text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($trainee['status']); ?></span>
            </div>
            <div class="flex items-center">
                <i class="fas fa-phone-alt text-gray-400 ml-2"></i>
                <span class="text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($trainee['phone']); ?></span>
            </div>
            <div class="flex items-center">
                <i class="fas fa-graduation-cap text-gray-400 ml-2"></i>
                <span class="text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($trainee['diploma_name']); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- الجدول الزمني للأحداث -->
<div class="space-y-8">
    <?php foreach ($groupedEvents as $monthYear => $events): ?>
        <div class="relative">
            <!-- عنوان الشهر -->
            <div class="sticky top-0 z-10 mb-4 flex items-center">
                <h3 class="bg-white dark:bg-gray-800 text-lg font-bold text-gray-900 dark:text-white px-4 py-2 rounded-lg shadow-sm">
                    <?php echo date('F Y', strtotime($monthYear . '-01')); ?>
                </h3>
                <div class="flex-1 border-b dark:border-gray-700 mx-4"></div>
            </div>

            <!-- قائمة الأحداث -->
            <div class="space-y-4">
                <?php foreach ($events as $event): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700 p-4 hover:shadow-md transition-shadow">
                        <div class="flex items-start">
                            <!-- أيقونة نوع الحدث -->
                            <div class="flex-shrink-0">
                                <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $event['type'] === 'contact' ? 'bg-indigo-100 text-indigo-600' : 'bg-blue-100 text-blue-600'; ?> dark:bg-opacity-10">
                                    <i class="fas <?php echo $event['type'] === 'contact' ? 'fa-phone-alt' : 'fa-clipboard-list'; ?>"></i>
                                </div>
                            </div>

                            <!-- تفاصيل الحدث -->
                            <div class="mr-4 flex-1">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <h4 class="text-base font-semibold text-gray-900 dark:text-white">
                                            <?php echo $event['type'] === 'contact' ? 'اتصال هاتفي' : 'ملاحظة'; ?>
                                        </h4>
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                            <?php echo htmlspecialchars($event['description'] ?? $event['notes']); ?>
                                        </p>
                                    </div>
                                    <time class="text-xs text-gray-500">
                                        <?php 
                                        $date = new DateTime($event['created_at']);
                                        echo $date->format('g:i a');
                                        ?>
                                    </time>
                                </div>

                                <!-- تفاصيل إضافية -->
                                <?php if ($event['type'] === 'contact' && isset($event['contact_result'])): ?>
                                    <div class="mt-2 flex items-center gap-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php echo match($event['contact_result']) {
                                                'answered' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
                                                'no_answer' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
                                                'promised_payment' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
                                                default => 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400'
                                            }; ?>">
                                            <i class="fas fa-circle text-[8px] ml-1"></i>
                                            <?php echo htmlspecialchars($event['contact_result']); ?>
                                        </span>
                                        <?php if (isset($event['promised_date'])): ?>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                <i class="far fa-calendar ml-1"></i>
                                                موعد الدفع: <?php echo date('Y-m-d', strtotime($event['promised_date'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- بيانات المستخدم -->
                                <div class="mt-2 flex items-center text-sm text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-user-circle ml-1"></i>
                                    <?php echo htmlspecialchars($event['created_by_name'] ?? 'غير معروف'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- زر إضافة ملاحظة جديدة -->
<div class="fixed bottom-6 left-6">
    <button onclick="showAddNoteModal()" class="flex items-center justify-center w-14 h-14 rounded-full bg-blue-600 text-white shadow-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
        <i class="fas fa-plus"></i>
    </button>
</div>

<!-- نافذة إضافة ملاحظة جديدة -->
<div id="addNoteModal" class="fixed inset-0 bg-gray-900 bg-opacity-60 backdrop-blur-sm hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white dark:bg-gray-800">
        <div class="text-center mb-4">
            <i class="fas fa-clipboard-list text-3xl text-blue-600 mb-2"></i>
            <h3 class="text-xl font-bold">إضافة ملاحظة جديدة</h3>
        </div>
        <form id="noteForm" onsubmit="submitNote(event)">
            <input type="hidden" name="trainee_id" value="<?php echo $traineeId; ?>">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">الملاحظة</label>
                    <textarea name="note" rows="4" required
                            class="block w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600"></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3 space-x-reverse">
                <button type="button" onclick="closeAddNoteModal()"
                        class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-400">
                    إلغاء
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    حفظ الملاحظة
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddNoteModal() {
    document.getElementById('addNoteModal').classList.remove('hidden');
}

function closeAddNoteModal() {
    document.getElementById('addNoteModal').classList.add('hidden');
}

function submitNote(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    
    fetch('/api/trainees/add-note.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('success', 'تم إضافة الملاحظة بنجاح');
            closeAddNoteModal();
            // تحديث الصفحة لعرض الملاحظة الجديدة
            location.reload();
        } else {
            showNotification('error', data.error || 'حدث خطأ أثناء إضافة الملاحظة');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'حدث خطأ في الاتصال بالخادم');
    });
}

function showNotification(type, message) {
    // يمكنك استخدام مكتبة مثل Toastify أو SweetAlert2
    alert(message);
}

// إغلاق النافذة المنبثقة عند الضغط على ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddNoteModal();
    }
});

// إغلاق النافذة المنبثقة عند النقر خارجها
document.getElementById('addNoteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAddNoteModal();
    }
});
</script>

<?php 
} catch (Exception $e) {
    error_log("Error in notes-history.php: " . $e->getMessage());
    echo '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">';
    echo '<strong class="font-bold">خطأ!</strong>';
    echo '<span class="block sm:inline"> ' . htmlspecialchars($e->getMessage()) . '</span>';
    echo '</div>';
}

include BASE_PATH . '/includes/tw_layout_end.php';
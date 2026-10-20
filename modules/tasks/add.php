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

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'due_date' => $_POST['due_date'] ? date('Y-m-d H:i:s', strtotime($_POST['due_date'])) : date('Y-m-d H:i:s'),
            'priority' => $_POST['priority'] ?? 'medium',
            'status' => $_POST['status'] ?? 'pending',
            'assigned_to' => (int)($_POST['assigned_to'] ?? $_SESSION['user_id']),
            'created_by' => (int)$_SESSION['user_id']
        ];
        
        // معالجة المتدرب المرتبط
        if (isset($_POST['related_trainee']) && !empty($_POST['related_trainee']) && $_POST['related_trainee'] !== '0') {
            $data['related_trainee'] = (int)$_POST['related_trainee'];
        }
        
        $id = $taskModel->create($data);
        
        // إنشاء إشعار للمستخدم المسند إليه المهمة
        require_once '../../includes/notifications.php';
        $title = 'مهمة جديدة';
        $msg = 'تم إنشاء مهمة جديدة بعنوان: ' . mb_substr($data['title'], 0, 50);
        createNotification($db, $data['assigned_to'], $title, $msg, 'task', rtrim(SITE_URL,'/') . '/modules/tasks/edit.php?id=' . $id);
        
        redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/tasks/edit.php?id=' . $id, 'تم إنشاء المهمة');
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// بيانات افتراضية للنموذج
$row = [
    'title' => '',
    'description' => '',
    'due_date' => date('Y-m-d H:i:s'),
    'priority' => 'medium',
    'status' => 'pending',
    'assigned_to' => (int)$_SESSION['user_id'],
    'related_trainee' => ''
];

$page_title = 'إضافة مهمة';
include '../../includes/tw_layout_start.php';
?>

<h1 class="text-xl font-bold mb-4">إضافة مهمة</h1>

<?php if($error): ?>
<div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg">
    <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_form.php'; ?>

<?php include '../../includes/tw_layout_end.php'; ?>
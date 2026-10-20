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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/tasks/list.php', 'معرّف غير صالح', 'error');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'title' => trim($_POST['title'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'due_date' => $_POST['due_date'] ?? date('Y-m-d H:i:s'),
            'priority' => $_POST['priority'] ?? 'medium',
            'status' => $_POST['status'] ?? 'pending',
            'assigned_to' => (int)($_POST['assigned_to'] ?? $_SESSION['user_id']),
            'related_trainee' => $_POST['related_trainee'] !== '' ? (int)$_POST['related_trainee'] : null,
        ];
        
        $taskModel->update($id, $data);
        
        if ($data['status'] === 'completed') {
            $taskModel->update($id, ['completed_at' => date('Y-m-d H:i:s')]);
        }
        
        if (function_exists('createUserAlert')) {
            $title = 'تعديل مهمة';
            $msg = 'تم تعديل المهمة #' . $id . ' - الحالة: ' . $data['status'];
            createUserAlert($db, (int)$_SESSION['user_id'], $title, $msg, 'info', rtrim(SITE_URL,'/') . '/modules/tasks/edit.php?id=' . $id);
        }
        
        redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/tasks/edit.php?id=' . $id, 'تم حفظ التعديلات');
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$row = $taskModel->findById($id);
if (!$row) {
    redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/tasks/list.php', 'السجل غير موجود', 'error');
}

$page_title = 'تعديل مهمة';
include '../../includes/tw_layout_start.php';
?>

<h1 class="text-xl font-bold mb-4">تعديل مهمة</h1>

<?php if($error): ?>
<div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg">
    <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_form.php'; ?>

<?php include '../../includes/tw_layout_end.php'; ?>
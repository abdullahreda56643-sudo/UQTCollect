<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

// require admin or explicit users delete permission
requireAccessOrAdmin('users', 'delete');
$db = (new Database())->getConnection();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/users/list.php', 'معرّف غير صالح', 'error');
}

// جلب بيانات المستخدم
$stmt = $db->prepare('SELECT id, full_name, role, branch_id FROM users WHERE id = :id');
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$user = $stmt->fetch();

if (!$user) {
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/users/list.php', 'المستخدم غير موجود', 'error');
}

// تحقق من الصلاحيات (المشرف = فقط ضمن الفرع)
if (!isAdmin()) {
    $check = $db->prepare('SELECT 1 FROM users target JOIN users me ON me.id = :me WHERE target.id = :id AND target.branch_id = me.branch_id');
    $check->bindValue(':me', $_SESSION['user_id']);
    $check->bindValue(':id', $id);
    $check->execute();
    if ($check->rowCount() === 0) {
        redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/users/list.php', 'غير مسموح لك بحذف هذا المستخدم', 'error');
    }
}

$error = '';

// Count potential foreign-key dependent rows to show in the UI
$dependencyCounts = [];
try {
    $q = $db->prepare('SELECT COUNT(*) FROM tasks WHERE assigned_to = :id'); $q->bindValue(':id', $id, PDO::PARAM_INT); $q->execute(); $dependencyCounts['tasks'] = (int)$q->fetchColumn();
    $q = $db->prepare('SELECT COUNT(*) FROM trainees WHERE assigned_to = :id'); $q->bindValue(':id', $id, PDO::PARAM_INT); $q->execute(); $dependencyCounts['trainees'] = (int)$q->fetchColumn();
    $q = $db->prepare('SELECT COUNT(*) FROM payments WHERE created_by = :id'); $q->bindValue(':id', $id, PDO::PARAM_INT); $q->execute(); $dependencyCounts['payments'] = (int)$q->fetchColumn();
    $q = $db->prepare('SELECT COUNT(*) FROM communications WHERE created_by = :id'); $q->bindValue(':id', $id, PDO::PARAM_INT); $q->execute(); $dependencyCounts['communications'] = (int)$q->fetchColumn();
    // reminders table may not exist in some deployments; check before querying
    $tblStmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
    $tblStmt->bindValue(':t', 'reminders');
    $tblStmt->execute();
    if ($tblStmt->fetchColumn()) {
        $q = $db->prepare('SELECT COUNT(*) FROM reminders WHERE assigned_to = :id'); $q->bindValue(':id', $id, PDO::PARAM_INT); $q->execute(); $dependencyCounts['reminders'] = (int)$q->fetchColumn();
    } else {
        $dependencyCounts['reminders'] = 0;
    }
} catch (Exception $e) {
    // ignore counting errors
}

// fetch list of potential reassignment targets (other active users)
$usersList = [];
try {
    $uStmt = $db->prepare('SELECT id, full_name FROM users WHERE id != :id AND role IN ("employee", "supervisor") ORDER BY full_name');
    $uStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $uStmt->execute();
    $usersList = $uStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $usersList = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['force_action'] ?? 'prevent'; // 'prevent' | 'reassign' | 'delete_deps'
    $reassign_to = (int)($_POST['reassign_to'] ?? 0);

    try {
        $db->beginTransaction();

        if ($action === 'reassign') {
            if ($reassign_to <= 0) throw new Exception('يرجى اختيار مستخدم لإعادة التعيين');
            // reassign tasks
            $stmt = $db->prepare('UPDATE tasks SET assigned_to = :to WHERE assigned_to = :from');
            $stmt->execute([':to' => $reassign_to, ':from' => $id]);
            // reassign trainees
            $stmt = $db->prepare('UPDATE trainees SET assigned_to = :to WHERE assigned_to = :from');
            $stmt->execute([':to' => $reassign_to, ':from' => $id]);
            // reassign reminders (only if table exists)
            $tblStmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
            $tblStmt->bindValue(':t', 'reminders'); $tblStmt->execute();
            if ($tblStmt->fetchColumn()) {
                $stmt = $db->prepare('UPDATE reminders SET assigned_to = :to WHERE assigned_to = :from');
                $stmt->execute([':to' => $reassign_to, ':from' => $id]);
            }
            // For payments and communications we keep created_by as-is to maintain audit trail (optional to reassign)

        } elseif ($action === 'delete_deps') {
            // delete in safe order (communications -> payments -> reminders -> tasks -> trainees)
            // communications
            try {
                $stmt = $db->prepare('DELETE FROM communications WHERE created_by = :from'); $stmt->execute([':from' => $id]);
            } catch (Exception $_e) { /* table may not exist or other issue, continue */ }
            try {
                $stmt = $db->prepare('DELETE FROM payments WHERE created_by = :from'); $stmt->execute([':from' => $id]);
            } catch (Exception $_e) { }
            // reminders (only if exists)
            $tblStmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
            $tblStmt->bindValue(':t', 'reminders'); $tblStmt->execute();
            if ($tblStmt->fetchColumn()) {
                try { $stmt = $db->prepare('DELETE FROM reminders WHERE assigned_to = :from'); $stmt->execute([':from' => $id]); } catch (Exception $_e) { }
            }
            try { $stmt = $db->prepare('DELETE FROM tasks WHERE assigned_to = :from'); $stmt->execute([':from' => $id]); } catch (Exception $_e) { }
            // trainees: set assigned_to = NULL to avoid deleting trainees
            try { $stmt = $db->prepare('UPDATE trainees SET assigned_to = NULL WHERE assigned_to = :from'); $stmt->execute([':from' => $id]); } catch (Exception $_e) { }

        } else {
            // prevent: if there are dependencies, stop and show message
            $totalDeps = array_sum($dependencyCounts);
            if ($totalDeps > 0) {
                $parts = [];
                if (!empty($dependencyCounts['tasks'])) $parts[] = 'مهام: ' . $dependencyCounts['tasks'];
                if (!empty($dependencyCounts['trainees'])) $parts[] = 'متعلمين مسندين: ' . $dependencyCounts['trainees'];
                if (!empty($dependencyCounts['payments'])) $parts[] = 'دفعات مسجلة: ' . $dependencyCounts['payments'];
                if (!empty($dependencyCounts['communications'])) $parts[] = 'سجلات تواصل: ' . $dependencyCounts['communications'];
                if (!empty($dependencyCounts['reminders'])) $parts[] = 'تذكيرات: ' . $dependencyCounts['reminders'];
                throw new Exception('تعذر حذف المستخدم لوجود علاقات مرجعية في النظام: ' . implode('، ', $parts) . '.');
            }
        }

        // Finally delete user
        $del = $db->prepare('DELETE FROM users WHERE id = :id');
        $del->bindValue(':id', $id, PDO::PARAM_INT);
        $del->execute();

        $db->commit();
        redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/users/list.php', 'تم حذف المستخدم بنجاح');

    } catch (Exception $e) {
        try { $db->rollBack(); } catch (Exception $_) {}
        $error = $e->getMessage();
    }
}
?>
<?php $page_title = 'حذف مستخدم'; include '../../includes/tw_layout_start.php'; ?>

<div class="max-w-xl mx-auto space-y-6">
    <!-- العنوان -->
    <div class="bg-white dark:bg-gray-800 shadow-md rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
        <h1 class="text-2xl font-bold text-red-600 dark:text-red-400 mb-2 flex items-center">
            <i class="fas fa-user-times ml-2"></i> حذف مستخدم
        </h1>
        <p class="text-sm text-gray-600 dark:text-gray-400">هل أنت متأكد أنك تريد حذف المستخدم التالي؟</p>
    </div>

    <!-- بيانات المستخدم -->
    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-5 border border-gray-200 dark:border-gray-600">
        <p><span class="font-semibold">الاسم:</span> <?php echo htmlspecialchars($user['full_name']); ?></p>
        <p><span class="font-semibold">الدور:</span> <?php echo htmlspecialchars($user['role']); ?></p>
        <p><span class="font-semibold">المعرف:</span> <?php echo $user['id']; ?></p>
    </div>

    <!-- رسائل -->
    <?php if($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl p-4 shadow-sm">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- أزرار التأكيد وخيارات الحذف -->
    <form method="post" class="space-y-4">
        <div class="bg-white/50 dark:bg-gray-800/50 p-4 rounded-lg border">
            <div class="text-sm text-gray-700 dark:text-gray-300 mb-2">يوجد عدد من السجلات المرتبطة بهذا المستخدم:</div>
            <ul class="text-sm text-gray-600 dark:text-gray-400 list-disc list-inside">
                <li>مهام: <?php echo $dependencyCounts['tasks'] ?? 0; ?></li>
                <li>متعلمين مسندين: <?php echo $dependencyCounts['trainees'] ?? 0; ?></li>
                <li>دفعات مسجلة: <?php echo $dependencyCounts['payments'] ?? 0; ?></li>
                <li>سجلات تواصل: <?php echo $dependencyCounts['communications'] ?? 0; ?></li>
                <li>تذكيرات: <?php echo $dependencyCounts['reminders'] ?? 0; ?></li>
            </ul>
        </div>

        <div class="bg-white/50 dark:bg-gray-800/50 p-4 rounded-lg border">
            <div class="text-sm font-medium mb-2">اختر استراتيجية التعامل مع السجلات المرتبطة قبل الحذف</div>
            <label class="flex items-center gap-2"><input type="radio" name="force_action" value="prevent" checked> منع الحذف إذا وُجدت تبعيات (الافتراضي)</label>
            <label class="flex items-center gap-2 mt-2"><input type="radio" name="force_action" value="reassign"> إعادة التعيين إلى مستخدم آخر ثم الحذف</label>
            <div id="reassign_box" class="mt-2 pl-6" style="display:none;">
                <label class="block text-sm mb-1">اختر المستخدم البديل</label>
                <select name="reassign_to" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                    <option value="0">-- اختر مستخدماً --</option>
                    <?php foreach ($usersList as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label class="flex items-center gap-2 mt-2"><input type="radio" name="force_action" value="delete_deps"> حذف السجلات المرتبطة تلقائياً (غير مسترجع)</label>
            <div class="text-xs text-gray-500 mt-2">ملحوظة: سيتم تعيين المتدربين إلى بدون مالك (NULL) بدلاً من حذفهم لتفادي فقدان بيانات المتدربين.</div>
        </div>

        <div class="flex gap-3 justify-end">
            <button type="submit" 
                    class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl shadow-md hover:shadow-lg transition-all duration-200">
                <i class="fas fa-trash ml-2"></i> نعم، تنفيذ الحذف
            </button>
            <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/users/list.php"
               class="px-5 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-xl shadow-md hover:shadow-lg transition-all duration-200">
                إلغاء
            </a>
        </div>
    </form>

    <script>
        (function(){
            const radios = document.querySelectorAll('input[name="force_action"]');
            const reassignBox = document.getElementById('reassign_box');
            radios.forEach(r => r.addEventListener('change', function(){
                if (this.value === 'reassign') reassignBox.style.display = '';
                else reassignBox.style.display = 'none';
            }));
        })();
    </script>
</div>

<?php include '../../includes/tw_layout_end.php'; ?>

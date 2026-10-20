<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

// فقط المدير يمكنه إدارة صلاحيات المستخدمين
checkPermission('admin');

$db = (new Database())->getConnection();

$user_id = (int) ($_GET['user_id'] ?? ($_POST['user_id'] ?? 0));
if ($user_id <= 0) {
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/users/list.php', 'معرّف المستخدم غير صالح', 'error');
}

// جلب بيانات المستخدم المستهدف
$stmt = $db->prepare('SELECT id, username, full_name, role FROM users WHERE id = :id');
$stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/users/list.php', 'المستخدم غير موجود', 'error');
}

// Ensure user_permissions table has the extended permission columns (backwards compatibility)
try {
    $neededCols = ['can_export', 'can_import', 'can_toggle', 'can_manage_permissions', 'can_assign', 'can_delete_all'];
    $placeholders = implode("','", $neededCols);
    $checkSql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_permissions' AND COLUMN_NAME IN ('" . $placeholders . "')";
    $existing = [];
    $stmt = $db->query($checkSql);
    while ($c = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[] = $c['COLUMN_NAME'];
    }
    $missing = array_diff($neededCols, $existing);
    foreach ($missing as $col) {
        $db->exec("ALTER TABLE user_permissions ADD COLUMN `" . $col . "` TINYINT(1) DEFAULT 0");
    }
} catch (Exception $e) {
    // لو فشل التحقق/التعديل، نواصل لكن نحفظ الخطأ في اللوج
    error_log('manage_permissions schema check failed: ' . $e->getMessage());
}

// UI labels for modules. Note: some internal code (role defaults / hasAccess) uses singular 'communication'
$modules = [
    'users' => 'إدارة المستخدمين',
    'trainees' => 'المتدربين',
    'payments' => 'المدفوعات',
    'reports' => 'التقارير',
    'communications' => 'الاتصالات', // maps to 'communication' internally
    'diplomas' => 'الدبلومات',
    'tasks' => 'المهام',
    'branches' => 'الفروع'
];

$actions = [
    'can_view' => 'عرض',
    'can_add' => 'إضافة',
    'can_assign' => 'إسناد',
    'can_edit' => 'تعديل',
    'can_delete' => 'حذف',
    'can_delete_all' => 'حذف الكل',
    'can_export' => 'تصدير',
    'can_import' => 'استيراد',
    'can_toggle' => 'تبديل حالة',
    'can_manage_permissions' => 'إدارة صلاحيات'
];

$error = '';
$success = '';

// If the target is admin, they effectively have all permissions. We'll disable editing in UI.
$isTargetAdmin = isset($target['role']) && $target['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // حفظ التعديلات
    try {
        // Restore defaults action: remove custom rows for this user
        $skipSave = false;
        if (isset($_POST['restore_defaults']) && $_POST['restore_defaults'] == '1') {
            // حذف كل صفوف الصلاحيات للمستخدم لإعادة الافتراضيات حسب الدور
            $del = $db->prepare('DELETE FROM user_permissions WHERE user_id = :user_id');
            $del->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $del->execute();
            if (function_exists('logActivity')) {
                logActivity($db, 'restore_permissions_defaults', 'user_permissions', $user_id, null, ['by' => $_SESSION['user_id']]);
            }
            $success = 'تم استرجاع الصلاحيات الافتراضية لهذا المستخدم.';
            // Debug: log resulting rows for this user
            try {
                $dbg = $db->prepare('SELECT * FROM user_permissions WHERE user_id = :user_id');
                $dbg->bindValue(':user_id', $user_id, PDO::PARAM_INT);
                $dbg->execute();
                $rows = $dbg->fetchAll(PDO::FETCH_ASSOC);
                error_log('manage_permissions: after restore for user ' . $user_id . ' rows: ' . json_encode($rows, JSON_UNESCAPED_UNICODE));
            } catch (Exception $e) {
                error_log('manage_permissions: debug fetch after restore failed: ' . $e->getMessage());
            }
            // refresh currentPermissions after delete
            $currentPermissions = [];
            $stmt = $db->prepare('SELECT * FROM user_permissions WHERE user_id = :user_id');
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // map both internal key and UI key for lookups
                $currentPermissions[$r['module']] = $r;
                if ($r['module'] === 'communication') {
                    $currentPermissions['communications'] = $r;
                }
            }
            // skip further save
            $skipSave = true;
        }
        // إذا كان المستخدم يحاول تعديل صلاحيات حسابه الخاص، نسمح فقط بتغيير حقل 'can_delete_all' لوحدة 'trainees'
        $isEditingSelf = ($user_id == ($_SESSION['user_id'] ?? 0));
        if ($isEditingSelf) {
            // لا نسمح باستعادة الافتراضيات عند تعديل الحساب الذاتي
            if (isset($_POST['restore_defaults']) && $_POST['restore_defaults'] == '1') {
                throw new Exception('غير مسموح باستعادة الافتراضيات لحسابك؛ استخدم واجهة المستخدم الخاصة بك.');
            }
            // سنقصر الحفظ بحيث يُطبَّق فقط قيمة can_delete_all للـ module 'trainees'
        }

        if (!$skipSave) {
            $db->beginTransaction();

            foreach ($modules as $moduleKey => $moduleLabel) {
                // Map UI module key to internal module key used by hasAccess/role defaults
                $internalModuleKey = $moduleKey === 'communications' ? 'communication' : $moduleKey;
                // بناء قيم الإجراء لكل module
                $values = [];
                // جمع القيم من POST — مع مراعاة أنه إذا كان هذا حفظًا لحساب المستخدم نفسه،
                // فنحن نسمح فقط بتحديث can_delete_all لِـ 'trainees' ونتجاهل الباقي
                foreach ($actions as $actionKey => $actionLabel) {
                    if ($isEditingSelf) {
                        if ($moduleKey === 'trainees' && $actionKey === 'can_delete_all') {
                            $values[$actionKey] = isset($_POST[$moduleKey][$actionKey]) ? 1 : 0;
                        } else {
                            // force no change for other actions when editing own account
                            $values[$actionKey] = null; // null means skip applying
                        }
                    } else {
                        $values[$actionKey] = isset($_POST[$moduleKey][$actionKey]) ? 1 : 0;
                    }
                }

                // Enforce: if 'view' is not allowed, then no other actions should be allowed for this module
                if (isset($values['can_view']) && empty($values['can_view'])) {
                    foreach ($values as $k => $v) {
                        if ($k !== 'can_view')
                            $values[$k] = 0;
                    }
                }

                // التحقق مما إذا كانت هناك صف موجود بالفعل
                $stmt = $db->prepare('SELECT id FROM user_permissions WHERE user_id = :user_id AND module = :module LIMIT 1');
                $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
                $stmt->bindValue(':module', $internalModuleKey, PDO::PARAM_STR);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    // تحديث — بناء SQL ديناميكيًا وربط المتغيرات المستخدمة فقط
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $setParts = [];
                    $params = [];
                    foreach ($actions as $actionKey => $actionLabel) {
                        if (isset($values[$actionKey]) && $values[$actionKey] !== null) {
                            $setParts[] = "$actionKey = :$actionKey";
                            $params[$actionKey] = $values[$actionKey];
                        }
                    }

                    if (!empty($setParts)) {
                        $updateSql = 'UPDATE user_permissions SET ' . implode(', ', $setParts) . ' WHERE id = :id';
                        $u = $db->prepare($updateSql);
                        foreach ($params as $pname => $pval) {
                            $u->bindValue(':' . $pname, $pval, PDO::PARAM_INT);
                        }
                        $u->bindValue(':id', $row['id'], PDO::PARAM_INT);
                        $u->execute();
                    }
                    // إذا كانت مجموعة setParts فارغة، فلا حاجة للتحديث لهذه الوحدة
                } else {
                    // إدراج
                    $ins = $db->prepare('INSERT INTO user_permissions (user_id, module, can_view, can_add, can_assign, can_edit, can_delete, can_delete_all, can_export, can_import, can_toggle, can_manage_permissions) VALUES (:user_id, :module, :can_view, :can_add, :can_assign, :can_edit, :can_delete, :can_delete_all, :can_export, :can_import, :can_toggle, :can_manage_permissions)');
                    $ins->bindValue(':user_id', $user_id, PDO::PARAM_INT);
                    $ins->bindValue(':module', $internalModuleKey, PDO::PARAM_STR);
                    // For insert, if editing self we only insert can_delete_all for trainees; other values default to 0
                    $ins->bindValue(':can_view', $values['can_view'] !== null ? $values['can_view'] : 0, PDO::PARAM_INT);
                    $ins->bindValue(':can_add', $values['can_add'] !== null ? $values['can_add'] : 0, PDO::PARAM_INT);
                    $ins->bindValue(':can_assign', $values['can_assign'] !== null ? $values['can_assign'] : 0, PDO::PARAM_INT);
                    $ins->bindValue(':can_edit', $values['can_edit'] !== null ? $values['can_edit'] : 0, PDO::PARAM_INT);
                    $ins->bindValue(':can_delete', $values['can_delete'] !== null ? $values['can_delete'] : 0, PDO::PARAM_INT);
                    $ins->bindValue(':can_delete_all', $values['can_delete_all'] !== null ? $values['can_delete_all'] : 0, PDO::PARAM_INT);
                    $ins->bindValue(':can_export', $values['can_export'] !== null ? $values['can_export'] : 0, PDO::PARAM_INT);
                    $ins->bindValue(':can_import', $values['can_import'] !== null ? $values['can_import'] : 0, PDO::PARAM_INT);
                    $ins->bindValue(':can_toggle', $values['can_toggle'] !== null ? $values['can_toggle'] : 0, PDO::PARAM_INT);
                    $ins->bindValue(':can_manage_permissions', $values['can_manage_permissions'] !== null ? $values['can_manage_permissions'] : 0, PDO::PARAM_INT);
                    $ins->execute();
                }
            }

            // تسجيل النشاط
            if (function_exists('logActivity')) {
                logActivity($db, 'update_permissions', 'user_permissions', $user_id, null, ['by' => $_SESSION['user_id']]);
            }
            $db->commit();
            $success = 'تم حفظ صلاحيات المستخدم بنجاح';
            // If the current user edited their own permissions, redirect to trainees list
            // so the new permission (can_delete_all) takes effect immediately for this session.
            if (isset($isEditingSelf) && $isEditingSelf) {
                redirectWithMessage(rtrim(SITE_URL, '/') . '/modules/trainees/list.php', $success, 'success');
            }
            // Debug: log resulting rows for this user after save
            try {
                $dbg = $db->prepare('SELECT * FROM user_permissions WHERE user_id = :user_id');
                $dbg->bindValue(':user_id', $user_id, PDO::PARAM_INT);
                $dbg->execute();
                $rows = $dbg->fetchAll(PDO::FETCH_ASSOC);
                error_log('manage_permissions: after save for user ' . $user_id . ' rows: ' . json_encode($rows, JSON_UNESCAPED_UNICODE));
            } catch (Exception $e) {
                error_log('manage_permissions: debug fetch after save failed: ' . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        // Only roll back if a transaction was started
        try {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Exception $rollbackEx) {
            error_log('manage_permissions rollback failed: ' . $rollbackEx->getMessage());
        }

        // Log original exception and show a user-friendly message
        error_log('manage_permissions save error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

// جلب الصلاحيات الحالية للمستخدم
$currentPermissions = [];
$stmt = $db->prepare('SELECT * FROM user_permissions WHERE user_id = :user_id');
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->execute();
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // store under the DB module name
    $currentPermissions[$r['module']] = $r;
    // also map 'communication' (DB) to 'communications' (UI) so the UI row shows existing permissions
    if ($r['module'] === 'communication') {
        $currentPermissions['communications'] = $r;
    }
}

// Compute effective permissions (fallback to role defaults) for display when no DB row exists
$effectivePermissions = [];
$backupSession = $_SESSION ?? [];
// set temporary session context to target user so hasAccess() uses role defaults when no row
$_SESSION['user_id'] = $target['id'];
$_SESSION['role'] = $target['role'];
$_SESSION['last_activity'] = time();

$actionMap = [
    'can_view' => 'view',
    'can_add' => 'create',
    'can_assign' => 'assign',
    'can_edit' => 'edit',
    'can_delete' => 'delete',
    'can_delete_all' => 'delete_all',
    'can_export' => 'export',
    'can_import' => 'import',
    'can_toggle' => 'toggle',
    'can_manage_permissions' => 'manage_permissions'
];

foreach ($modules as $modKey => $modLabel) {
    $internalModuleKey = $modKey === 'communications' ? 'communication' : $modKey;
    $effectivePermissions[$modKey] = [];
    foreach ($actionMap as $actKey => $actName) {
        try {
            $allowed = hasAccess($internalModuleKey, $actName);
        } catch (Exception $e) {
            $allowed = false;
        }
        $effectivePermissions[$modKey][$actKey] = (bool) $allowed;
    }
}

// restore original session
$_SESSION = $backupSession;

// Fetch recent audit logs related to user_permissions changes for this user
$auditByModule = [];
try {
    $aStmt = $db->prepare("SELECT a.*, u.full_name as changed_by_name FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id WHERE a.table_name = 'user_permissions' AND a.record_id = :user_id ORDER BY a.created_at DESC LIMIT 50");
    $aStmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $aStmt->execute();
    while ($ar = $aStmt->fetch(PDO::FETCH_ASSOC)) {
        // old_values/new_values are JSON strings; decode safely
        $old = null;
        $new = null;
        if (!empty($ar['old_values'])) {
            $old = json_decode($ar['old_values'], true);
        }
        if (!empty($ar['new_values'])) {
            $new = json_decode($ar['new_values'], true);
        }

        // Entry may contain multiple modules or a single module; normalize
        // If new/old contain 'module' key, use that; otherwise attempt to inspect keys
        $modulesInEntry = [];
        if (is_array($new) && isset($new['module'])) {
            $modulesInEntry[] = $new['module'];
        } elseif (is_array($old) && isset($old['module'])) {
            $modulesInEntry[] = $old['module'];
        } else {
            // try keys present like can_view etc. assume caller applied to specific module stored elsewhere
            // fallback: put under 'general'
            $modulesInEntry[] = 'general';
        }

        foreach ($modulesInEntry as $m) {
            // normalize communication -> communications for UI
            $uiKey = $m === 'communication' ? 'communications' : $m;
            if (!isset($auditByModule[$uiKey]))
                $auditByModule[$uiKey] = [];
            $auditByModule[$uiKey][] = [
                'created_at' => $ar['created_at'],
                'action' => $ar['action'],
                'by' => $ar['changed_by_name'] ?? ($ar['user_id'] ?? 'system'),
                'old' => $old,
                'new' => $new
            ];
        }
    }
} catch (Exception $e) {
    error_log('manage_permissions audit fetch failed: ' . $e->getMessage());
}

// عرض الصفحة
$page_title = 'إدارة صلاحيات المستخدم';
include '../../includes/tw_layout_start.php';
?>
<div class="max-w-4xl mx-auto mb-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 text-sm">
        <div class="font-medium mb-2">الوحدات المرئية للمستخدم:</div>
        <?php
        $visibleModules = [];
        foreach ($modules as $mKey => $mLabel) {
            $perm = $currentPermissions[$mKey] ?? null;
            $fallback = $effectivePermissions[$mKey] ?? [];
            $canView = $perm !== null ? !empty($perm['can_view']) : (!empty($fallback['can_view']));
            if ($canView)
                $visibleModules[] = $mLabel;
        }
        if (empty($visibleModules)) {
            echo '<div class="text-gray-500">لا توجد وحدات مرئية لهذا المستخدم.</div>';
        } else {
            foreach ($visibleModules as $vm) {
                echo '<span class="inline-block bg-green-100 text-green-800 px-3 py-1 rounded-full mr-2 mb-2">' . htmlspecialchars($vm) . '</span>';
            }
        }
        ?>
    </div>
</div>
<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold">إدارة صلاحيات المستخدم</h1>
            <p class="text-sm text-gray-500">التحكم بصلاحيات المستخدم:
                <?php echo htmlspecialchars($target['full_name'] . ' (' . $target['username'] . ')'); ?>
            </p>
        </div>
        <a href="list.php"
            class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-lg">العودة</a>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg">
            <?php echo htmlspecialchars($error); ?>
        </div><?php endif; ?>
    <?php if ($success): ?>
        <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg">
            <?php echo htmlspecialchars($success); ?>
        </div><?php endif; ?>

    <?php if ($isTargetAdmin): ?>
        <div class="mb-4 bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 rounded-lg">هذا المستخدم مدير نظام ويملك
            جميع الصلاحيات افتراضياً. تحرير الصلاحيات معطّل باستثناء حقل "حذف الكل" حيث يمكنك تفعيل/تعطيلها.</div>
    <?php endif; ?>

    <form method="post" class="space-y-6">
        <input type="hidden" name="user_id" value="<?php echo (int) $user_id; ?>">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden p-4">
            <table class="w-full text-sm">
                <thead class="text-right text-xs text-gray-500 border-b pb-2">
                    <tr>
                        <th class="py-2">الوحدة</th>
                        <?php foreach ($actions as $a): ?>
                            <th class="py-2 text-center"><?php echo $a; ?></th><?php endforeach; ?>
                        <!-- <th class="py-2 text-center">سجل</th> -->
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modules as $modKey => $modLabel):
                        $perm = $currentPermissions[$modKey] ?? null; // null means no explicit row
                        $fallback = $effectivePermissions[$modKey] ?? [];
                        ?>
                        <tr class="border-b last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="py-3 pr-4 font-medium text-gray-800 dark:text-gray-100"><?php echo $modLabel; ?></td>
                            <?php foreach ($actions as $actKey => $actLabel):
                                if ($perm !== null) {
                                    $isChecked = !empty($perm[$actKey]);
                                } else {
                                    $isChecked = (!empty($fallback[$actKey]));
                                }
                                // For admin target: default all fields checked and non-editable, except can_delete_all
                                if ($isTargetAdmin) {
                                    if ($actKey === 'can_delete_all') {
                                        // keep stored or fallback value for can_delete_all
                                        $checked = $isChecked ? 'checked' : '';
                                        $disabledAttr = '';
                                    } else {
                                        $checked = 'checked';
                                        $disabledAttr = 'disabled';
                                    }
                                } else {
                                    $checked = $isChecked ? 'checked' : '';
                                    $disabledAttr = '';
                                }
                                ?>
                                <td class="py-3 text-center">
                                    <input type="checkbox" name="<?php echo $modKey; ?>[<?php echo $actKey; ?>]" <?php echo $checked; ?>         <?php echo $disabledAttr; ?> />
                                </td>
                            <?php endforeach; ?>
                            <td class="py-3 text-center">
                                <?php $aud = $auditByModule[$modKey] ?? []; ?>
                                <div x-data="{open:false}" class="relative inline-block">

                                    <div x-show="open" @click.away="open=false" x-cloak
                                        class="absolute left-0 mt-2 w-96 bg-white dark:bg-gray-800 border rounded shadow-lg z-50 p-3 text-xs">
                                        <?php if (empty($aud)): ?>
                                            <div class="text-gray-500">لا توجد تغييرات مسجلة لهذه الوحدة.</div>
                                        <?php else: ?>
                                            <?php foreach ($aud as $entry): ?>
                                                <div class="mb-2 border-b pb-2">
                                                    <div class="flex items-center justify-between">
                                                        <div class="text-gray-700 dark:text-gray-200 font-medium">
                                                            <?php echo htmlspecialchars($entry['by']); ?>
                                                        </div>
                                                        <div class="text-gray-400 text-xs"><?php echo $entry['created_at']; ?></div>
                                                    </div>
                                                    <div class="text-gray-600 dark:text-gray-300 text-xs mt-1">
                                                        <?php echo htmlspecialchars($entry['action']); ?>
                                                    </div>
                                                    <?php if (!empty($entry['old']) || !empty($entry['new'])): ?>
                                                        <details class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                                            <summary class="cursor-pointer">عرض الفرق</summary>
                                                            <pre
                                                                class="whitespace-pre-wrap text-xs mt-1 bg-gray-50 dark:bg-gray-900 p-2 rounded overflow-auto">
                                                                                                <?php echo htmlspecialchars(json_encode(['old' => $entry['old'], 'new' => $entry['new']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?>
                                                                                                                                                        </pre>
                                                        </details>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="flex justify-end space-x-2 space-x-reverse">
            <a href="list.php"
                class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-lg">إلغاء</a>
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">حفظ
                الصلاحيات</button>
            <?php if (!$isTargetAdmin): ?>
                <button type="submit" name="restore_defaults" value="1"
                    onclick="return confirm('هل أنت متأكد من استعادة الصلاحيات الافتراضية لهذا المستخدم؟ سيتم حذف أي تخصيصات سابقة.');"
                    class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg">استعادة الافتراضيات</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php include '../../includes/tw_layout_end.php'; ?>
<script>
    // Disable other checkboxes in a row when the 'view' checkbox is unchecked
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table tbody tr').forEach(function (row) {
            var inputs = Array.from(row.querySelectorAll('input[type="checkbox"]'));
            if (inputs.length === 0) return;
            // assume first checkbox corresponds to can_view
            var viewCb = inputs[0];
            function sync() {
                var disabled = !viewCb.checked;
                // keep view checkbox enabled (but may be disabled for admin target)
                inputs.forEach(function (cb, idx) {
                    if (idx === 0) return;
                    cb.disabled = disabled || cb.hasAttribute('disabled');
                });
            }
            viewCb.addEventListener('change', sync);
            sync();
        });
    });
</script>
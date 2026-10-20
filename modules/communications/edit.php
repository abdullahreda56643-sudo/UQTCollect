<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Communication.php';

checkPermission('employee');
$db = (new Database())->getConnection();
$communicationModel = new Communication($db);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/communications/list.php', 'معرّف غير صالح', 'error');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // تنسيق تاريخ ووقت التواصل من input datetime-local
        $contactDateRaw = $_POST['contact_date'] ?? '';
        $contactDate = $contactDateRaw !== '' ? date('Y-m-d H:i:s', strtotime($contactDateRaw)) : date('Y-m-d H:i:s');

        // تنسيق الإجراء التالي وجعله NULL إذا كان فارغاً
        $nextActionRaw = $_POST['next_action'] ?? '';
        $nextAction = null;
        if ($nextActionRaw !== '') {
            $ts = strtotime($nextActionRaw);
            if ($ts === false) { 
                throw new Exception('صيغة تاريخ الإجراء التالي غير صحيحة'); 
            }
            $nextAction = date('Y-m-d H:i:s', $ts);
        }

            // Map form fields to communications table columns
            // Translate UI values into DB enum values to avoid MySQL truncation warnings
            $typeMap = [
                'phone' => 'call',
                'call' => 'call',
                'whatsapp' => 'whatsapp',
                'email' => 'call',
                'meeting' => 'call'
            ];
            $resultMap = [
                'responded' => 'promise',
                'promised_payment' => 'promise',
                'promise' => 'promise',
                'refused' => 'refused',
                'paid' => 'paid',
                'not_responding' => 'no_answer',
                'no_answer' => 'no_answer',
                'other' => 'other'
            ];

            $submittedType = $_POST['contact_type'] ?? 'phone';
            $submittedResult = $_POST['result'] ?? 'other';

            // Prefer normalizeEnumValue to ensure we send allowed values
            $dbType = $communicationModel->normalizeEnumValue('type', $submittedType, 'call');
            $dbResult = $communicationModel->normalizeEnumValue('result', $submittedResult, 'other');

            $data = [
                'type' => $dbType,
                'contact_date' => $contactDate,
                'result' => $dbResult,
                'next_action_date' => $nextAction,
                'next_action_notes' => $_POST['next_action_notes'] ?? null,
                'notes' => trim($_POST['notes'] ?? ''),
            ];
        
        // سجل القيم القديمة
        $oldRow = $communicationModel->findById($id);
        // Use updateWithInfo to capture warnings (ENUM truncation etc.)
        if (method_exists($communicationModel, 'updateWithInfo')) {
            $updateRes = $communicationModel->updateWithInfo($id, $data);
            // Log details for debugging
            error_log('Communication update result: ' . print_r($updateRes, true));
            $pdoInfo = $updateRes['errorInfo'] ?? null;
            if (!$updateRes['success'] || (!empty($pdoInfo) && isset($pdoInfo[1]) && (int)$pdoInfo[1] !== 0)) {
                // MySQL warning/error occurred
                $dbMsg = isset($pdoInfo[2]) ? $pdoInfo[2] : 'Unknown DB error/warning';
                error_log('Communication update DB message: ' . $dbMsg);
                // Surface a friendly message to the user while directing to logs for details
                $error = 'حدث تحذير/خطأ أثناء الحفظ. الرجاء مراجعة سجلات الخادم للمزيد من التفاصيل. (' . htmlspecialchars($dbMsg) . ')';
            }
        } else {
            $communicationModel->update($id, $data);
        }
        
        if (function_exists('createUserAlert')) {
            $title = 'تعديل تواصل';
            $msg = 'تم تعديل تواصل #' . $id . ' - النتيجة: ' . ($data['result']);
            createUserAlert($db, (int)$_SESSION['user_id'], $title, $msg, 'info', rtrim(SITE_URL,'/') . '/modules/communications/edit.php?id=' . $id);
        }

        // سجل النشاط: تحديث التواصل
        try {
            require_once '../../includes/auth.php';
            $newRow = $communicationModel->findById($id);
            logActivity($db, 'update', 'communication', $id, $oldRow, $newRow);
        } catch (Exception $e) {
            error_log('Failed to log communication update: ' . $e->getMessage());
        }

        redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/communications/edit.php?id=' . $id, 'تم حفظ التعديلات');
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$row = $communicationModel->findById($id);
if (!$row) redirectWithMessage(rtrim(SITE_URL,'/') . '/modules/communications/list.php', 'السجل غير موجود', 'error');
?>
<?php $page_title = 'تعديل تواصل'; include '../../includes/tw_layout_start.php'; ?>
    <div class="form-container bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6 max-w-4xl mx-auto">
        <?php if($error): ?>
        <div class="mb-6 bg-red-50 dark:bg-red-900/50 border-r-4 border-red-500 p-4 rounded-lg flex items-center">
            <i class="fas fa-exclamation-circle text-red-500 text-xl ml-3"></i>
            <p class="text-red-700 dark:text-red-300"><?php echo htmlspecialchars($error); ?></p>
        </div>
        <?php endif; ?>

        <form method="post" class="space-y-6">
            <!-- معلومات المتدرب -->
            <div class="form-section bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 mb-6 animate-entry">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center">
                    <i class="fas fa-user-graduate text-blue-500 ml-2"></i>
                    معلومات المتدرب
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">رقم المتدرب</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                <i class="fas fa-id-card text-gray-400"></i>
                            </div>
                            <input value="<?php echo (int)$row['trainee_id']; ?>" 
                                   disabled 
                                   class="w-full pr-10 px-4 py-2 rounded-lg border border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                        </div>
                    </div>
                </div>
            </div>

            <!-- تفاصيل التواصل -->
            <div class="form-section bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 mb-6 animate-entry">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center">
                    <i class="fas fa-phone-alt text-green-500 ml-2"></i>
                    تفاصيل التواصل
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">نوع التواصل</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                <i class="fas fa-comment-alt text-gray-400"></i>
                            </div>
                            <select name="contact_type" class="w-full pr-10 px-4 py-2 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-700 appearance-none">
                                <?php 
                                $types = [
                                    'phone' => ['label' => 'هاتف', 'icon' => 'phone'],
                                    'whatsapp' => ['label' => 'واتساب', 'icon' => 'whatsapp'],
                                    'email' => ['label' => 'بريد', 'icon' => 'envelope'],
                                    'meeting' => ['label' => 'اجتماع', 'icon' => 'users']
                                ]; 
                                foreach ($types as $k => $v): ?>
                                <option value="<?php echo $k; ?>" <?php echo ($row['type']===$k?'selected':''); ?>><?php echo $v['label']; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-chevron-down text-gray-400"></i>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">النتيجة</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                <i class="fas fa-check-circle text-gray-400"></i>
                            </div>
                            <select name="result" class="w-full pr-10 px-4 py-2 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-700 appearance-none">
                                <?php 
                                $results = [
                                    'responded' => 'استجاب',
                                    'refused' => 'رفض',
                                    'paid' => 'سدد',
                                    'not_responding' => 'لا يرد',
                                    'promised_payment' => 'وعد بالسداد',
                                    'other' => 'أخرى'
                                ]; 
                                foreach ($results as $k => $l): ?>
                                <option value="<?php echo $k; ?>" <?php echo ($row['result']===$k?'selected':''); ?>><?php echo $l; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-chevron-down text-gray-400"></i>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">تاريخ ووقت التواصل</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                <i class="fas fa-calendar-alt text-gray-400"></i>
                            </div>
                            <input name="contact_date"
                                type="datetime-local"
                                value="<?php echo (isset($row['contact_date']) && $row['contact_date']) ? htmlspecialchars(date('Y-m-d\\TH:i', strtotime($row['contact_date'])), ENT_QUOTES) : ''; ?>"
                                class="w-full pr-10 px-4 py-2 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-700">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">الإجراء التالي (اختياري)</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                <i class="fas fa-clock text-gray-400"></i>
                            </div>
                            <input name="next_action"
                                type="datetime-local"
                                value="<?php echo (isset($row['next_action_date']) && $row['next_action_date']) ? htmlspecialchars(date('Y-m-d\\TH:i', strtotime($row['next_action_date'])), ENT_QUOTES) : ''; ?>"
                                class="w-full pr-10 px-4 py-2 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-700">
                        </div>
                    </div>
                </div>
            </div>

            <!-- الملاحظات -->
            <div class="form-section bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 mb-6 animate-entry">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center">
                    <i class="fas fa-comment text-yellow-500 ml-2"></i>
                    الملاحظات
                </h2>
                <div class="form-group">
                    <div class="relative">
                        <textarea name="notes" 
                                  rows="4" 
                                  class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-700"
                                  placeholder="أدخل الملاحظات هنا..."><?php echo htmlspecialchars($row['notes']); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- أزرار التحكم -->
            <div class="flex items-center justify-end gap-4 mt-6">
                <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/communications/list.php" 
                   class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 transition-colors duration-200 flex items-center">
                    <i class="fas fa-times ml-2"></i>
                    إلغاء
                </a>
                <button type="submit" 
                        class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200 flex items-center">
                    <i class="fas fa-save ml-2"></i>
                    حفظ التغييرات
                </button>
            </div>
        </form>
    </div>
<?php include '../../includes/tw_layout_end.php'; ?>
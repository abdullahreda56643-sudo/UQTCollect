<?php
// =============================================================================
// إعدادات النظام والتحقق من الصلاحيات
// =============================================================================

// تحميل ملفات التهيئة والاعتماديات
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../includes/communication_helpers.php';
require_once '../../models/Communication.php';
require_once '../../models/Trainee.php';

// التحقق من صلاحية المستخدم
checkPermission('employee');

// =============================================================================
// تعريف الدوال المساعدة
// =============================================================================

/**
 * تحويل حالة المتدرب إلى نص مقروء
 */
function getTraineeStatusLabel($status) {
    $labels = [
        'under_communication' => 'تحت التواصل',
        'promised_payment'    => 'وعد بالسداد',
        'paid'               => 'تم السداد',
        'refused_payment'    => 'رفض السداد',
        'not_responding'     => 'لا يرد',
        'pending'            => 'معلق',
        'active'             => 'نشط',
        'graduated'          => 'متخرج',
        'withdrawn'          => 'منسحب'
    ];
    
    return $labels[$status] ?? $status;
}

/**
 * تحديث حالة المتدرب بناءً على نتيجة التواصل
 */
function updateTraineeStatus($traineeModel, $traineeId, $result) {
    $statusMap = [
        'paid'      => 'paid',
        'promise'   => 'promised_payment',
        'refused'   => 'refused_payment',
        'no_answer' => 'not_responding'
    ];
    
    if (isset($statusMap[$result])) {
        return $traineeModel->update($traineeId, ['status' => $statusMap[$result]]);
    }
    
    return true;
}

// =============================================================================
// تهيئة النماذج والمتغيرات
// =============================================================================

$db = (new Database())->getConnection();
$communicationModel = new Communication($db);
$traineeModel = new Trainee($db);

$error = '';
$success = '';

// =============================================================================
// معالجة نموذج الإضافة
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // التحقق من الحقول المطلوبة (استخدم تسميات قابلة للقراءة للمستخدم)
        $requiredFields = ['trainee_id', 'type', 'result', 'contact_date'];
        $fieldLabels = [
            'trainee_id' => 'المتدرب',
            'type' => 'نوع التواصل',
            'result' => 'نتيجة التواصل',
            'contact_date' => 'تاريخ ووقت التواصل'
        ];
        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                $label = $fieldLabels[$field] ?? $field;
                throw new Exception("الحقل {$label} مطلوب");
            }
        }

        // التحقق من وجود المتدرب
        $traineeId = (int)$_POST['trainee_id'];
        $trainee = $traineeModel->findById($traineeId);
        if (!$trainee) {
            throw new Exception("المتدرب المحدد غير موجود");
        }

        // If user is supervisor, ensure the trainee belongs to the supervisor (or their subordinates)
        if (!empty($_SESSION['role']) && $_SESSION['role'] === 'supervisor') {
            try {
                $allowed = getEmployeesUnderSupervisor($db, (int)$_SESSION['user_id']);
            } catch (Exception $e) {
                $allowed = [];
            }
            $allowed[] = (int)$_SESSION['user_id'];
            $assignedTo = isset($trainee['assigned_to']) ? (int)$trainee['assigned_to'] : null;
            if ($assignedTo === null || !in_array($assignedTo, $allowed, true)) {
                throw new Exception('لا تملك صلاحية على هذا المتدرب');
            }
        }

        // Ensure 'type' value is compatible with the DB column (enum or varchar)
        $postedType = trim($_POST['type']);
        $dbType = '';
        try {
            $colStmt = $db->query("SHOW COLUMNS FROM communications LIKE 'type'");
            $colInfo = $colStmt->fetch(PDO::FETCH_ASSOC);
            $dbType = $colInfo['Type'] ?? '';
        } catch (Exception $e) {
            // ignore - fall back to safe mapping
            $dbType = '';
        }

        $finalType = $postedType;
        // if DB uses ENUM, parse allowed values and map accordingly
        if (stripos($dbType, 'enum(') === 0) {
            // extract values
            preg_match("/enum\((.*)\)/i", $dbType, $m);
            $allowed = [];
            if (!empty($m[1])) {
                $vals = explode(',', $m[1]);
                foreach ($vals as $v) {
                    $allowed[] = trim(trim($v), "'\"");
                }
            }

            if (!in_array($postedType, $allowed, true)) {
                // map common values to 'communication' or to the first allowed value
                $commGroup = ['call','whatsapp','visit','email','sms','phone','communication'];
                $mapped = null;
                foreach ($commGroup as $c) {
                    if (strpos(strtolower($postedType), $c) !== false || $postedType === $c) {
                        if (in_array('communication', $allowed, true)) {
                            $mapped = 'communication';
                            break;
                        }
                    }
                }

                if ($mapped === null) {
                    if (in_array('payment', $allowed, true) && strpos(strtolower($postedType), 'pay') !== false) {
                        $mapped = 'payment';
                    } else {
                        // fallback to first allowed value
                        $mapped = $allowed[0] ?? $postedType;
                    }
                }

                $finalType = $mapped;
            }
        }

        // Normalize contact_date based on DB column type (DATE vs DATETIME)
        $contactDateInput = trim($_POST['contact_date']);
        $contactDateFinal = $contactDateInput;
        try {
            $colStmt2 = $db->query("SHOW COLUMNS FROM communications LIKE 'contact_date'");
            $colInfo2 = $colStmt2->fetch(PDO::FETCH_ASSOC);
            $contactColType = $colInfo2['Type'] ?? '';
            if (stripos($contactColType, 'date') === 0 && stripos($contactColType, 'datetime') === false) {
                // DB expects DATE only; convert
                $ts = strtotime($contactDateInput);
                if ($ts !== false) {
                    $contactDateFinal = date('Y-m-d', $ts);
                } else {
                    // fallback: try to extract date portion
                    $contactDateFinal = substr($contactDateInput, 0, 10);
                }
            } else {
                // DB expects DATETIME or similar; try to format to MySQL DATETIME
                $ts = strtotime($contactDateInput);
                if ($ts !== false) {
                    $contactDateFinal = date('Y-m-d H:i:s', $ts);
                }
            }
        } catch (Exception $e) {
            // ignore and pass original input
        }

        // Normalize next_action_date based on DB column type (DATE vs DATETIME)
        if (!empty($_POST['next_action_date'])) {
            $nextActionInput = trim($_POST['next_action_date']);
            $nextActionFinal = $nextActionInput;
            try {
                $colStmt3 = $db->query("SHOW COLUMNS FROM communications LIKE 'next_action_date'");
                $colInfo3 = $colStmt3->fetch(PDO::FETCH_ASSOC);
                $nextColType = $colInfo3['Type'] ?? '';
                if (stripos($nextColType, 'date') === 0 && stripos($nextColType, 'datetime') === false) {
                    // DB expects DATE only; convert
                    $ts2 = strtotime($nextActionInput);
                    if ($ts2 !== false) {
                        $nextActionFinal = date('Y-m-d', $ts2);
                    } else {
                        $nextActionFinal = substr($nextActionInput, 0, 10);
                    }
                } else {
                    // DB expects DATETIME or similar; format to MySQL DATETIME
                    $ts2 = strtotime($nextActionInput);
                    if ($ts2 !== false) {
                        $nextActionFinal = date('Y-m-d H:i:s', $ts2);
                    }
                }
            } catch (Exception $e) {
                // ignore and pass original input
                $nextActionFinal = $nextActionInput;
            }
            $data['next_action_date'] = $nextActionFinal;
        }

        // تجهيز البيانات للإدخال
        $data = [
            'trainee_id'        => $traineeId,
            'type'              => $finalType,
            'result'            => $_POST['result'],
            'notes'             => $_POST['notes'] ?? '',
            'contact_date'      => $contactDateFinal,
            'created_by'        => $_SESSION['user_id']
        ];

        // إضافة الحقول الاختيارية
        if (!empty($_POST['next_action_date'])) {
            $data['next_action_date'] = $_POST['next_action_date'];
        }

        // دمج ملاحظات الإجراء التالي مع الملاحظات العامة
        if (!empty($_POST['next_action_notes'])) {
            $currentNotes = $data['notes'];
            $nextActionNotes = $_POST['next_action_notes'];
            
            if (!empty($currentNotes)) {
                $data['notes'] = $currentNotes . "\n\nملاحظات الإجراء التالي: " . $nextActionNotes;
            } else {
                $data['notes'] = "ملاحظات الإجراء التالي: " . $nextActionNotes;
            }
        }

        // إضافة سجل التواصل
        $communicationId = $communicationModel->addCommunication($data);

        if ($communicationId) {
            // تحديث حالة المتدرب إذا لزم الأمر
            updateTraineeStatus($traineeModel, $data['trainee_id'], $data['result']);
            
            // استخدم جلسة لإظهار رسالة النجاح بعد إعادة التوجيه
            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'تم',
                'message' => 'تم إضافة سجل التواصل بنجاح',
                'icon' => 'success'
            ];

            // أعد التوجيه إلى قائمة التواصل مع معرّف السجل المضاف للمساعدة في التشخيص/تمييزه
            $redirectUrl = 'list.php?new_comm=' . intval($communicationId) . '&trainee_id=' . intval($data['trainee_id']);

            // سجل النشاط
            try {
                require_once '../../includes/auth.php';
                logActivity($db, 'create', 'communication', $communicationId, null, $data);
            } catch (Exception $e) {
                error_log('Failed to log communication creation: ' . $e->getMessage());
            }

            header('Location: ' . $redirectUrl);
            exit;
        } else {
            throw new Exception("حدث خطأ أثناء إضافة سجل التواصل");
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// =============================================================================
// تحضير البيانات للعرض
// =============================================================================

// الحصول على قائمة المتدربين للقائمة المنسدلة
// إذا كان المستخدم مشرفًا، نعرض فقط المتدربين التابعين له أو لموظفيه
if (!empty($_SESSION['role']) && $_SESSION['role'] === 'supervisor') {
    try {
        $employee_ids = getEmployeesUnderSupervisor($db, (int)$_SESSION['user_id']);
    } catch (Exception $e) {
        $employee_ids = [];
    }
    // تأكد من إضافة المشرف نفسه للقائمة ليشمل المتدربين المعينين له مباشرة
    $employee_ids[] = (int)$_SESSION['user_id'];
    // استخدم مرشح assigned_to لعرض المتدربين فقط لتلك المعرفات
    $trainees = $traineeModel->getTraineesWithDetails(['assigned_to' => $employee_ids]);
} else {
    $trainees = $traineeModel->getTraineesWithDetails();
}

// تحضير بيانات المتدربين للاستخدام في JavaScript
$traineesData = [];
foreach ($trainees as $trainee) {
    $traineesData[] = [
        'id' => $trainee['id'],
        'full_name' => $trainee['full_name'],
        'national_id' => $trainee['national_id'] ?? '',
        'phone' => $trainee['phone'] ?? '',
        'status' => $trainee['status'] ?? 'pending'
    ];
}

// =============================================================================
// عرض الواجهة
// =============================================================================

$page_title = 'إضافة تواصل جديد';
include '../../includes/tw_layout_start.php';
?>
<style>
/* تحسينات الوضع المظلم للنموذج */
html.dark .bg-blue-50 {
    background-color: rgba(59, 130, 246, 0.1) !important;
    border-color: rgba(59, 130, 246, 0.2) !important;
}

html.dark .border-blue-100 {
    border-color: rgba(59, 130, 246, 0.2) !important;
}

html.dark .text-blue-800 {
    color: #93c5fd !important;
}

html.dark .text-blue-600 {
    color: #60a5fa !important;
}

/* تحسين Tom Select في الوضع المظلم */
html.dark .ts-control {
    background-color: #000000ff !important;
    border-color: #4b5563 !important;
    color: #e5e7eb !important;
}

html.dark .ts-dropdown {
    background-color: #0c0c0cff !important;
    border-color: #4b5563 !important;
    color: #e5e7eb !important;
}

html.dark .ts-dropdown .option {
    background-color: #000000ff !important;
    color: #e5e7eb !important;
    border-color: #4b5563 !important;
}

html.dark .ts-dropdown .option:hover {
    background-color: #050505ff !important;
}

html.dark .ts-dropdown .active {
    background-color: #1b1b1bff !important;
}

html.dark .ts-dropdown .selected {
    background-color: #1e1e1fff !important;
}

/* تحسين الحقول في الوضع المظلم */
html.dark input[type="datetime-local"],
html.dark select,
html.dark textarea {
    background-color: #374151 !important;
    border-color: #4b5563 !important;
    color: #e5e7eb !important;
}

html.dark input[type="datetime-local"]:focus,
html.dark select:focus,
html.dark textarea:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2) !important;
}

/* تحسين ألوان البادجات في معلومات المتدرب */
html.dark .bg-blue-100 {
    background-color: rgba(59, 130, 246, 0.2) !important;
    color: #93c5fd !important;
}

html.dark .bg-green-100 {
    background-color: rgba(34, 197, 94, 0.2) !important;
    color: #86efac !important;
}

html.dark .bg-yellow-100 {
    background-color: rgba(234, 179, 8, 0.2) !important;
    color: #fde047 !important;
}

html.dark .bg-red-100 {
    background-color: rgba(239, 68, 68, 0.2) !important;
    color: #fca5a5 !important;
}

html.dark .bg-orange-100 {
    background-color: rgba(249, 115, 22, 0.2) !important;
    color: #fdba74 !important;
}

html.dark .bg-purple-100 {
    background-color: rgba(168, 85, 247, 0.2) !important;
    color: #d8b4fe !important;
}

/* تحسين حدود النموذج في الوضع المظلم */
html.dark .border-gray-200 {
    border-color: #374151 !important;
}

html.dark .border-t {
    border-color: #374151 !important;
}

/* تحسين النصوص في الوضع المظلم */
html.dark .text-gray-700 {
    color: #e5e7eb !important;
}

html.dark .text-gray-500 {
    color: #9ca3af !important;
}

/* تحسين الأزرار في الوضع المظلم */
html.dark .bg-gray-500 {
    background-color: #6b7280 !important;
}

html.dark .bg-gray-500:hover {
    background-color: #4b5563 !important;
}

html.dark .bg-purple-500 {
    background-color: #8b5cf6 !important;
}

html.dark .bg-purple-500:hover {
    background-color: #7c3aed !important;
}

/* تحسين Tom Select dropdown content */
html.dark .ts-dropdown .create {
    color: #e5e7eb !important;
    background-color: #374151 !important;
}

html.dark .ts-dropdown .create:hover {
    background-color: #4b5563 !important;
}

/* إصلاح مشكلة النص الأبيض على الخلفية البيضاء */
html.dark .ts-wrapper.multi .ts-control > div {
    background-color: #4b5563 !important;
    color: #e5e7eb !important;
    border: 1px solid #6b7280 !important;
}

html.dark .ts-wrapper.multi .ts-control > div.active {
    background-color: #3b82f6 !important;
    color: white !important;
}

/* تحسين placeholders في الوضع المظلم */
html.dark ::placeholder {
    color: #9ca3af !important;
}

html.dark :-ms-input-placeholder {
    color: #9ca3af !important;
}

html.dark ::-ms-input-placeholder {
    color: #9ca3af !important;
}

</style>
<div class="container mx-auto px-4 py-8">
    <!-- رأس الصفحة -->
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white">إضافة تواصل جديد</h1>
        <a href="list.php" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
            <i class="fas fa-arrow-right ml-2"></i> عودة للقائمة
        </a>
    </div>

    <!-- رسائل التنبيه -->
    <?php if ($error): ?>
    <div class="mb-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <span class="font-medium">خطأ:</span>
        </div>
        <p class="mt-1"><?php echo htmlspecialchars($error); ?></p>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="mb-6 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg">
        <div class="flex items-center">
            <i class="fas fa-check-circle ml-2"></i>
            <span class="font-medium">نجاح:</span>
        </div>
        <p class="mt-1"><?php echo htmlspecialchars($success); ?></p>
        <p class="text-sm mt-2">سيتم توجيهك إلى قائمة التواصل خلال ثانيتين...</p>
    </div>
    <?php endif; ?>

    <!-- بطاقة النموذج -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        <!-- رأس البطاقة -->
        <div class="bg-blue-50 dark:bg-blue-900 px-6 py-4 border-b border-blue-100 dark:border-blue-800">
            <h2 class="text-lg font-semibold text-blue-800 dark:text-blue-200">
                <i class="fas fa-plus-circle ml-2"></i>
                معلومات التواصل الجديد
            </h2>
            <p class="text-sm text-blue-600 dark:text-blue-300 mt-1">املأ الحقول المطلوبة لإضافة سجل تواصل جديد</p>
        </div>

        <!-- نموذج الإضافة -->
        <form method="POST" class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- حقل المتدرب -->
                <div class="md:col-span-2">
                    <label for="trainee_select" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <span class="text-red-500">*</span> اختر المتدرب
                    </label>
                    <select name="trainee_id" id="trainee_select" required 
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">-- اختر المتدرب --</option>
                        <?php if (isset($_GET['trainee_id'])): 
                            $selectedTrainee = $traineeModel->findById($_GET['trainee_id']);
                            if ($selectedTrainee): ?>
                            <option value="<?php echo $selectedTrainee['id']; ?>" selected>
                                معرف: <?php echo $selectedTrainee['id']; ?> - <?php echo htmlspecialchars($selectedTrainee['full_name']); ?>
                            </option>
                        <?php endif; endif; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">ابحث باستخدام ID المتدرب أو الاسم أو رقم الهوية</p>
                </div>

                <!-- نوع التواصل -->
                <div>
                    <label for="communication_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <span class="text-red-500">*</span> نوع التواصل
                    </label>
                    <select name="type" id="communication_type" required 
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">-- اختر نوع التواصل --</option>
                        <option value="call">اتصال هاتفي</option>
                        <option value="whatsapp">رسالة واتساب</option>
                        <option value="visit">زيارة شخصية</option>
                        <option value="email">بريد إلكتروني</option>
                        <option value="sms">رسالة نصية</option>
                    </select>
                </div>

                <!-- نتيجة التواصل -->
                <div>
                    <label for="communication_result" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <span class="text-red-500">*</span> نتيجة التواصل
                    </label>
                    <select name="result" id="communication_result" required 
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">-- اختر النتيجة --</option>
                        <option value="promise">وعد بالسداد</option>
                        <option value="paid">تم السداد</option>
                        <option value="refused">رفض السداد</option>
                        <option value="no_answer">لم يرد</option>
                        <option value="interested">مهتم</option>
                        <option value="not_interested">غير مهتم</option>
                        <option value="other">أخرى</option>
                    </select>
                </div>

                <!-- تاريخ ووقت التواصل -->
                <div>
                    <label for="contact_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        <span class="text-red-500">*</span> تاريخ ووقت التواصل
                    </label>
                    <input type="datetime-local" 
                           id="contact_date"
                           name="contact_date" 
                           required
                           value="<?php echo date('Y-m-d\TH:i'); ?>"
                           class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                </div>

                <!-- موعد الإجراء التالي -->
                <div>
                    <label for="next_action_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        موعد الإجراء التالي
                    </label>
                    <input type="datetime-local" 
                           id="next_action_date"
                           name="next_action_date"
                           class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <p class="text-xs text-gray-500 mt-1">اختياري - سيتم إنشاء تذكير تلقائي</p>
                </div>

                <!-- ملاحظات التواصل -->
                <div class="md:col-span-2">
                    <label for="communication_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        ملاحظات التواصل
                    </label>
                    <textarea name="notes" id="communication_notes" rows="4" 
                              class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                              placeholder="ادخل تفاصيل التواصل، النقاط التي تم مناقشتها، الردود المهمة..."></textarea>
                </div>

                <!-- ملاحظات الإجراء التالي -->
                <div class="md:col-span-2">
                    <label for="next_action_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        ملاحظات الإجراء التالي
                    </label>
                    <textarea name="next_action_notes" id="next_action_notes" rows="3" 
                              class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                              placeholder="اذكر الإجراء المطلوب في المرة القادمة، النقاط التي تحتاج متابعة..."></textarea>
                    <p class="text-xs text-gray-500 mt-1">سيتم دمج هذه الملاحظات تلقائياً مع ملاحظات التواصل</p>
                </div>
            </div>

            <!-- أزرار التحكم -->
            <div class="mt-8 pt-6 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row justify-between items-center space-y-4 sm:space-y-0">
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    <i class="fas fa-info-circle ml-2"></i>
                    الحقول marked with <span class="text-red-500">*</span> إلزامية
                </div>
                
                <div class="flex space-x-3 rtl:space-x-reverse">
                    <a href="list.php" class="px-6 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors flex items-center">
                        <i class="fas fa-times ml-2"></i> إلغاء
                    </a>
                    <button type="reset" class="px-6 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-colors flex items-center">
                        <i class="fas fa-redo ml-2"></i> إعادة تعيين
                    </button>
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center">
                        <i class="fas fa-save ml-2"></i> حفظ التواصل
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- معلومات المتدرب المحدد -->
    <div id="trainee_info" class="mt-6 bg-blue-50 dark:bg-blue-900 rounded-lg p-4 hidden">
        <h3 class="font-semibold text-blue-800 dark:text-blue-200 mb-2">
            <i class="fas fa-user ml-2"></i> معلومات المتدرب المحدد
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <span class="font-medium text-gray-600 dark:text-gray-300">الاسم:</span>
                <span id="info_name" class="text-gray-800 dark:text-white"></span>
            </div>
            <div>
                <span class="font-medium text-gray-600 dark:text-gray-300">رقم الهوية:</span>
                <span id="info_national_id" class="text-gray-800 dark:text-white"></span>
            </div>
            <div>
                <span class="font-medium text-gray-600 dark:text-gray-300">الجوال:</span>
                <span id="info_phone" class="text-gray-800 dark:text-white"></span>
            </div>
            <div>
                <span class="font-medium text-gray-600 dark:text-gray-300">الحالة:</span>
                <span id="info_status" class="text-gray-800 dark:text-white"></span>
            </div>
        </div>
    </div>
</div>

<!-- إضافة مكتبة Tom Select -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<script>
// تمرير بيانات المتدربين من PHP إلى JavaScript
const traineesData = <?php echo json_encode($traineesData); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // تهيئة Tom Select لقائمة المتدربين
    const traineeSelect = document.getElementById('trainee_select');
    
    if (traineeSelect) {
            const tomSelect = new TomSelect(traineeSelect, {
            valueField: 'id',
            labelField: 'label',
            searchField: ['id', 'name', 'national_id', 'phone'],
            placeholder: 'ابحث بمعرّف المتدرب أو الاسم أو رقم الهوية أو الجوال...',
            maxOptions: 50,
            create: false,
            allowEmptyOption: false,
            
            // تحضير الخيارات
                options: traineesData.map(trainee => ({
                id: trainee.id,
                label: `معرّف: ${trainee.id} - ${trainee.full_name} - ${trainee.national_id || 'لا يوجد'} - ${trainee.phone || 'لا يوجد'} (${getTraineeStatusLabel(trainee.status)})`,
                name: trainee.full_name,
                national_id: trainee.national_id || 'لا يوجد',
                phone: trainee.phone || 'لا يوجد',
                status: trainee.status
            })),
            
            // وظيفة البحث
            load: function(query, callback) {
                if (!query.length) {
                    // عرض أول 10 متدربين عند فتح القائمة
                    const limitedOptions = this.options.slice(0, 10);
                    return callback(limitedOptions);
                }
                
                // البحث في البيانات المحلية
                const searchText = query.toLowerCase();
                const results = this.options.filter(option => {
                    return (
                        option.id.toString().includes(searchText) ||
                        option.name.toLowerCase().includes(searchText) ||
                        (option.national_id && option.national_id.toString().includes(searchText)) ||
                        (option.phone && option.phone.includes(searchText))
                    );
                });
                
                callback(results.slice(0, 20));
            },
            
            // تخصيص العرض
            render: {
                option: function(item, escape) {
                    const statusLabel = getTraineeStatusLabel(item.status);
                    const statusColor = getStatusColor(item.status);
                    
                    return `
                        <div class="py-3 px-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="font-semibold text-blue-600">${escape(item.name)}</div>
                                    <div class="text-sm text-gray-600 mt-1">
                                        <span class="font-medium">معرّف:</span> ${escape(item.id)} | 
                                        <span class="font-medium">هوية:</span> ${escape(item.national_id)} | 
                                        <span class="font-medium">جوال:</span> ${escape(item.phone)}
                                    </div>
                                </div>
                                <span class="px-2 py-1 text-xs rounded-full ${statusColor}">${escape(statusLabel)}</span>
                            </div>
                        </div>
                    `;
                },
                
                item: function(item, escape) {
                    return `<div class="flex items-center">
                        <span class="font-medium">${escape(item.name)}</span>
                        <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded mr-2">معرّف: ${escape(item.id)}</span>
                    </div>`;
                },
                
                no_results: function() {
                    return '<div class="py-3 px-4 text-center text-gray-500">لم يتم العثور على متدربين مطابقين للبحث</div>';
                },
                
                loading: function() {
                    return '<div class="py-3 px-4 text-center text-gray-500">جاري البحث...</div>';
                }
            },
            
            // عند اختيار متدرب
            onInitialize: function() {
                this.focus();
            }
        });
        
        // عند تغيير الاختيار، عرض معلومات المتدرب
        traineeSelect.addEventListener('change', function() {
            const selectedId = this.value;
            const traineeInfo = document.getElementById('trainee_info');
            const selectedTrainee = traineesData.find(t => t.id == selectedId);
            
            if (selectedTrainee) {
                document.getElementById('info_name').textContent = selectedTrainee.full_name;
                document.getElementById('info_national_id').textContent = selectedTrainee.national_id || 'غير محدد';
                document.getElementById('info_phone').textContent = selectedTrainee.phone || 'غير محدد';
                document.getElementById('info_status').textContent = getTraineeStatusLabel(selectedTrainee.status);
                traineeInfo.classList.remove('hidden');
            } else {
                traineeInfo.classList.add('hidden');
            }
        });
    }
    
    // دالة مساعدة لعرض حالة المتدرب
    function getTraineeStatusLabel(status) {
        const labels = {
            'under_communication': 'تحت التواصل',
            'promised_payment': 'وعد بالسداد',
            'paid': 'تم السداد',
            'refused_payment': 'رفض السداد',
            'not_responding': 'لا يرد',
            'pending': 'معلق',
            'delayed': 'مؤجل',
            'active': 'نشط',
            'graduated': 'متخرج',
            'withdrawn': 'منسحب'
        };
        return labels[status] || 'غير محدد';
    }
    
    // دالة للحصول على لون الحالة
    function getStatusColor(status) {
        const colors = {
            'under_communication': 'bg-blue-100 text-blue-800',
            'promised_payment': 'bg-yellow-100 text-yellow-800',
            'paid': 'bg-green-100 text-green-800',
            'refused_payment': 'bg-red-100 text-red-800',
            'not_responding': 'bg-gray-100 text-gray-800',
            'pending': 'bg-orange-100 text-orange-800',
            'delayed': 'bg-yellow-50 text-yellow-800',
            'active': 'bg-green-100 text-green-800',
            'graduated': 'bg-purple-100 text-purple-800',
            'withdrawn': 'bg-red-100 text-red-800'
        };
        return colors[status] || 'bg-gray-100 text-gray-800';
    }
    
    // إضافة تأثيرات للتحقق من الصحة
    const form = document.querySelector('form');
    const requiredFields = form.querySelectorAll('[required]');
    
    requiredFields.forEach(field => {
        field.addEventListener('blur', function() {
            if (this.value.trim() === '') {
                this.classList.add('border-red-500');
                this.classList.remove('border-green-500');
            } else {
                this.classList.remove('border-red-500');
                this.classList.add('border-green-500');
            }
        });
    });
    
    // التحقق من النموذج قبل الإرسال
    form.addEventListener('submit', function(e) {
        let valid = true;
        requiredFields.forEach(field => {
            if (field.value.trim() === '') {
                field.classList.add('border-red-500');
                valid = false;
            }
        });
        
        if (!valid) {
            e.preventDefault();
            alert('يرجى ملء جميع الحقول الإلزامية');
        }
    });
    
    // تسجيل للتصحيح
    console.log('تم تحميل بيانات المتدربين:', traineesData.length, 'متدرب');
});
</script>

<?php 
include '../../includes/tw_layout_end.php'; 
?>



<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../includes/communication_helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Communication.php';

checkPermission('employee');

$db = (new Database())->getConnection();
$communicationModel = new Communication($db);

$rawId = isset($_GET['id']) ? trim($_GET['id']) : '';
$isModal = isset($_GET['modal']) && $_GET['modal'] === 'true';

// Support placeholder ids from the UI like "new_<traineeId>" so the modal can show a helpful placeholder
$isPlaceholder = false;
$placeholderTraineeId = 0;
$id = 0;
if ($rawId !== '') {
    if (preg_match('/^new_(\d+)$/', $rawId, $m)) {
        $isPlaceholder = true;
        $placeholderTraineeId = (int)$m[1];
    } elseif (is_numeric($rawId)) {
        $id = (int)$rawId;
    }
}

if ($id === 0 && !$isPlaceholder) {
    if ($isModal) {
        echo 'معرف التواصل غير صالح';
        exit;
    }
    header('Location: list.php');
    exit;
}

// استخدام الدوال المجمعة في includes/communication_helpers.php

// Get communication details with joined trainee information
if ($isPlaceholder) {
    // Build a placeholder communication using trainee data so the modal can show useful info
    $tStmt = $db->prepare('SELECT id, full_name, national_id, phone FROM trainees WHERE id = ?');
    $tStmt->execute([$placeholderTraineeId]);
    $trainee = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$trainee) {
        if ($isModal) {
            echo 'سجل المتدرب غير موجود';
            exit;
        }
        header('Location: list.php');
        exit;
    }

    // minimal placeholder communication structure
    $communication = [
        'id' => null,
        'trainee_id' => $trainee['id'],
        'trainee_name' => $trainee['full_name'],
        'trainee_national_id' => $trainee['national_id'] ?? '',
        'trainee_phone' => $trainee['phone'] ?? '',
        'created_by_name' => '',
        'contact_date' => date('Y-m-d H:i:s'),
        // Use the same labels used when the list creates virtual/placeholder rows so the modal matches the table
        'type' => 'تم الإسناد',
        'result' => 'تم إسناد المتدرب',
        'notes' => 'سجل تواصل افتراضي (لم يتم حفظه بعد). يمكنك إنشاء سجل فعلي عبر الزر أدناه.',
        'next_action_date' => null,
        'next_action_notes' => null,
        'is_placeholder' => true
    ];
} else {
    $sql = "SELECT c.*, 
               t.full_name as trainee_name, 
               t.national_id as trainee_national_id, 
               t.phone as trainee_phone, 
               u.full_name as created_by_name
        FROM communications c
        LEFT JOIN trainees t ON t.id = c.trainee_id
        LEFT JOIN users u ON u.id = c.created_by
        WHERE c.id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $communication = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$communication) {
        if ($isModal) {
            echo 'سجل التواصل غير موجود';
            exit;
        }
        header('Location: list.php');
        exit;
    }
}

if (!$isModal) {
    $page_title = 'عرض تفاصيل التواصل';
    include '../../includes/tw_layout_start.php';
}

// Local helper: display datetime in 12-hour format with Arabic AM/PM (ص/م)
if (!function_exists('displayDateTime12')) {
    function displayDateTime12($datetime, $showTime = true) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return 'غير محدد';
        $ts = strtotime($datetime);
        if ($ts === false) return 'تاريخ غير صالح';
        if ($showTime) {
            $s = date('Y-m-d h:i A', $ts);
            // Replace English AM/PM with Arabic short markers
            $s = str_replace(['AM', 'PM'], ['ص', 'م'], $s);
            return $s;
        }
        return date('Y-m-d', $ts);
    }
}
?>

<div class="<?php echo $isModal ? '' : 'max-w-4xl mx-auto py-6'; ?>">
    <div class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden">
        <!-- Hero header -->
    <?php $contactHijri = formatHijri($communication['contact_date'] ?? null); ?>
        <div class="px-6 py-6 bg-gradient-to-r from-indigo-600 to-blue-600 text-white">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold"><?php echo htmlspecialchars($communication['trainee_name'] ?: 'متدرب'); ?></h2>
                    <div class="text-sm opacity-80 mt-1">رقم الهوية: <span class="dir-ltr"><?php echo htmlspecialchars($communication['trainee_national_id'] ?? '-'); ?></span></div>
                </div>
                <div class="text-right">
                    <div class="inline-flex items-center gap-2">
                                        <span class="px-3 py-1 rounded-full bg-white/20 text-white text-sm js-hijri-date" data-datetime="<?php echo htmlspecialchars($communication['contact_date'] ?? ''); ?>"><?php echo htmlspecialchars($contactHijri ?: displayDateTime12($communication['contact_date'] ?? '')); ?></span>
                        <span class="px-3 py-1 rounded-full bg-white/20 text-white text-sm"><?php echo getTypeLabel($communication['type']); ?></span>
                    </div>
                            <div class="mt-2">
                        <?php if (!empty($communication['is_placeholder']) && $communication['is_placeholder']): ?>
                            <?php
                                // Options shown to the user when creating a real communication from placeholder
                                $typeOptions = [
                                    'call' => 'اتصال',
                                    'whatsapp' => 'واتساب',
                                    'email' => 'بريد إلكتروني',
                                    'sms' => 'رسالة نصية',
                                    'phone' => 'هاتف',
                                    'visit' => 'زيارة',
                                    'communication' => 'تواصل'
                                ];
                                $resultOptions = [
                                    'paid' => 'تم السداد',
                                    'promise' => 'وعد بالسداد',
                                    'refused' => 'رفض الدفع',
                                    'no_answer' => 'لم يرد',
                                    'follow_up' => 'متابعة',
                                    'not_interested' => 'غير مهتم'
                                ];
                            ?>
                            <form action="add.php" method="post" class="space-y-3">
                                <input type="hidden" name="trainee_id" value="<?php echo intval($communication['trainee_id']); ?>">
                                <input type="hidden" name="created_by" value="<?php echo intval($_SESSION['user_id'] ?? 0); ?>">
                                <input type="hidden" name="contact_date" value="<?php echo date('Y-m-d H:i:s'); ?>">

                                <div class="flex items-center gap-3">
                                    <div class="flex-1">
                                        <label class="text-xs text-gray-500 block">نوع التواصل</label>
                                        <select name="type" class="w-full mt-1 px-2 py-1 rounded border">
                                            <?php foreach ($typeOptions as $k => $label): ?>
                                                <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="w-44">
                                        <label class="text-xs text-gray-500 block">النتيجة</label>
                                        <select name="result" class="w-full mt-1 px-2 py-1 rounded border">
                                            <?php foreach ($resultOptions as $k => $label): ?>
                                                <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label class="text-xs text-gray-500 block">ملاحظات (اختياري)</label>
                                    <textarea name="notes" rows="3" class="w-full mt-1 px-2 py-1 rounded border" placeholder="أدخل ملاحظات تواصل..."><?php echo htmlspecialchars($communication['notes'] ?? ''); ?></textarea>
                                </div>

                                <div class="text-left">
                                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg shadow">حفظ وإنشاء</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <span class="inline-block <?php echo getResultBadgeClass($communication['result']); ?> text-xs">
                                <?php 
                                // Debug: Show the raw result value
                                $debugResult = $communication['result'];
                                error_log("DEBUG: Raw result value = '" . $debugResult . "'");
                                echo getResultLabel($communication['result']); 
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Left column: details -->
                <div class="md:col-span-2 space-y-4">
                    <div class="rounded-xl p-6 bg-white/60 dark:bg-gray-900/50 border border-white/30 dark:border-gray-800 shadow-md">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <div class="text-sm text-gray-500">الجوال</div>
                                <div class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($communication['trainee_phone'] ?? '-'); ?></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">سجل بواسطة</div>
                                <div class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($communication['created_by_name'] ?? '-'); ?></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">نوع التواصل</div>
                                <div class="mt-1"><span class="<?php echo getTypeBadgeClass($communication['type']); ?>"><?php echo getTypeLabel($communication['type']); ?></span></div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">النتيجة</div>
                                <div class="mt-1"><span class="<?php echo getResultBadgeClass($communication['result']); ?>">
                                <?php 
                                // Debug: Show the raw result value in details section
                                $debugResult2 = $communication['result'];
                                error_log("DEBUG: Raw result value (details) = '" . $debugResult2 . "'");
                                echo getResultLabel($communication['result']); 
                                ?>
                            </span></div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($communication['notes'])): ?>
                    <div class="rounded-xl p-6 bg-white/60 dark:bg-gray-900/50 border border-white/30 dark:border-gray-800 shadow-md">
                        <h3 class="text-lg font-semibold mb-2 text-gray-800 dark:text-white">الملاحظات</h3>
                        <div class="prose max-h-56 overflow-auto text-gray-700 dark:text-gray-300 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($communication['notes'])); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($communication['next_action_date'])): ?>
                    <div class="rounded-xl p-6 bg-white/50 dark:bg-gray-900/50 border border-white/30 dark:border-gray-800 shadow-md">
                        <h3 class="text-lg font-semibold mb-2 text-gray-800 dark:text-white">الإجراء التالي</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <div class="text-sm text-gray-500">تاريخ الإجراء التالي</div>
                                <div class="font-medium text-gray-900 dark:text-white"><?php echo displayDateTime12($communication['next_action_date']); ?></div>
                            </div>
                            <?php if (!empty($communication['next_action_notes'])): ?>
                            <div>
                                <div class="text-sm text-gray-500">ملاحظات الإجراء التالي</div>
                                <div class="text-gray-700 dark:text-gray-300 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($communication['next_action_notes'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right column: compact info & actions -->
                <div class="space-y-4">
                    <div class="rounded-xl p-5 bg-gradient-to-b from-white/80 to-white/60 dark:from-gray-800/60 dark:to-gray-800/40 border border-white/30 dark:border-gray-700 shadow-lg text-center">
                        <div class="text-sm text-gray-600">تاريخ التواصل</div>
                        <div class="mt-2 text-lg font-extrabold text-gray-900 dark:text-white js-hijri-date" data-datetime="<?php echo htmlspecialchars($communication['contact_date'] ?? ''); ?>"><?php echo htmlspecialchars($contactHijri ?: displayDateTime12($communication['contact_date'] ?? '')); ?></div>
                        <?php if (!empty($contactHijri)): ?>
                        <div class="text-xs text-gray-500 mt-1 js-greg-date">ميلادي: <?php echo htmlspecialchars(displayDateTime12($communication['contact_date'] ?? '')); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="rounded-xl p-4 bg-white/60 dark:bg-gray-900/40 border border-white/30 dark:border-gray-800 shadow-md text-center">
                        <?php if (!empty($communication['is_placeholder']) && $communication['is_placeholder']): ?>
                            <a href="add.php?trainee_id=<?php echo intval($communication['trainee_id']); ?>" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg shadow">إنشاء تواصل جديد</a>
                        <?php else: ?>
                            <?php if (hasAccess('communications','edit')): ?>
                                <a href="edit.php?id=<?php echo intval($communication['id']); ?>" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg shadow">تعديل</a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="mt-3 text-sm text-gray-500">معرف التواصل: <?php echo $communication['id'] ? intval($communication['id']) : 'غير محفوظ'; ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php if (!$isModal): ?>
            <div class="px-6 py-4 bg-gray-50 dark:bg-gray-700/50 flex justify-end gap-4">
            <a href="list.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-200 dark:hover:bg-gray-500">عودة للقائمة</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isModal) include '../../includes/tw_layout_end.php'; ?>

<script>
// Convert dates to Hijri client-side where possible for better UX
(function() {
    function toJulian(date) {
        // returns Julian day number
        return Math.floor((date / 86400000) + 2440587.5);
    }

    function julianToHijri(jd) {
        // Approximate conversion (arithmetical) - Kuwaiti algorithm variant
        var jd = jd - 1948440 + 10632;
        var n = Math.floor((jd - 1) / 10631);
        jd = jd - 10631 * n + 354;
        var j = (Math.floor((10985 - jd) / 5316)) * (Math.floor((50 * jd) / 17719)) + (Math.floor(jd / 5670)) * (Math.floor((43 * jd) / 15238));
        jd = jd - (Math.floor((30 - j) / 15)) * (Math.floor((17719 * j) / 50)) - (Math.floor(j / 16)) * (Math.floor((15238 * j) / 43)) + 29;
        var m = Math.floor((24 * jd) / 709);
        var d = jd - Math.floor((709 * m) / 24);
        var y = 30 * n + j - 30;
        return { y: y, m: m, d: d };
    }

    function formatHijriApprox(date) {
        var jd = toJulian(date.getTime());
        var hij = julianToHijri(jd);
        var months = ['محرم','صفر','ربيع الأول','ربيع الآخر','جمادى الأولى','جمادى الآخرة','رجب','شعبان','رمضان','شوال','ذو القعدة','ذو الحجة'];
        return hij.d + ' ' + months[(hij.m - 1 + 12) % 12] + ' ' + hij.y;
    }

    try {
        var supportsIntlHijri = false;
        try {
            supportsIntlHijri = !!new Intl.DateTimeFormat('ar-SA-u-ca-islamic');
        } catch (e) {
            supportsIntlHijri = false;
        }

        if (supportsIntlHijri) {
            const hijriFormatter = new Intl.DateTimeFormat('ar-SA-u-ca-islamic', { day: '2-digit', month: 'long', year: 'numeric' });
            document.querySelectorAll('.js-hijri-date').forEach(el => {
                const dt = el.getAttribute('data-datetime');
                if (!dt) return;
                const d = new Date(dt);
                if (isNaN(d)) return;
                try {
                    el.textContent = hijriFormatter.format(d);
                } catch (e) {
                    // ignore
                }
            });
        } else {
            // Fallback approximate conversion
            document.querySelectorAll('.js-hijri-date').forEach(el => {
                const dt = el.getAttribute('data-datetime');
                if (!dt) return;
                const d = new Date(dt);
                if (isNaN(d)) return;
                try {
                    el.textContent = formatHijriApprox(d);
                } catch (e) {
                    // ignore
                }
            });
        }
    } catch (e) {
        // noop
    }
})();
</script>
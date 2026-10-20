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

// ===== الدوال الجديدة لتحويل الوقت إلى نظام 12 ساعة =====
// دالة لتحويل الوقت من 24 ساعة إلى 12 ساعة
function formatTime12Hour($timeString)
{
    if (empty($timeString))
        return '';
    $timestamp = strtotime($timeString);
    if ($timestamp === false)
        return '';
    return date('h:i A', $timestamp);
}

// دالة محسنة لعرض التاريخ والوقت بنظام 12 ساعة
function formatDateTime12Hour($datetimeStr, $showTime = true)
{
    if (empty($datetimeStr))
        return '-';

    try {
        $timestamp = strtotime($datetimeStr);
        if ($timestamp === false)
            return '-';

        $date = date('Y/m/d', $timestamp);
        $time = date('h:i A', $timestamp);

        if ($showTime) {
            return $date . ' ' . $time;
        }
        return $date;
    } catch (Exception $e) {
        return '-';
    }
}

// دالة بديلة إذا كانت هناك مشكلة في الدالة الرئيسية
function safeFormatDateTime12Hour($datetimeStr)
{
    return formatDateTime12Hour($datetimeStr, true);
}

// ===== نهاية الدوال الجديدة =====

// Render graduation date HTML matching trainees list logic
function renderGraduationHtml($graduationDate, $graduationHijri)
{
    // helper: format Gregorian date as Y/m/d
    $formatGregorian = function ($dateStr) {
        try {
            if (empty($dateStr) || $dateStr === '0000-00-00')
                return false;
            $ts = strtotime($dateStr);
            if ($ts === false)
                return false;
            return date('Y/m/d', $ts);
        } catch (Throwable $e) {
            return false;
        }
    };

    // helper: format hijri from gregorian using Intl if available, otherwise fallback to helper convertGregorianToHijri
    $formatHijriFromGregorian = function ($dateStr) {
        if (empty($dateStr) || $dateStr === '0000-00-00')
            return '';
        try {
            if (class_exists('IntlDateFormatter')) {
                $fmt = new IntlDateFormatter('ar_SA@calendar=islamic', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL);
                $ts = strtotime($dateStr);
                if ($ts !== false && $ts > 0)
                    return $fmt->format($ts);
                $dt = new DateTime($dateStr);
                return $fmt->format($dt);
            }
        } catch (Throwable $e) {
            // ignore and fallback
        }
        // fallback to helper function if present
        if (function_exists('convertGregorianToHijri')) {
            $conv = convertGregorianToHijri($dateStr);
            if ($conv !== false)
                return $conv;
        }
        return '';
    };

    $gradHtml = '';
    $intervalHtml = '';

    if (!empty($graduationDate) && $graduationDate !== '0000-00-00') {
        $g = $formatGregorian($graduationDate);
        $h = !empty($graduationHijri) ? $graduationHijri : $formatHijriFromGregorian($graduationDate);

        if ($g) {
            $gradHtml .= '<div class="flex items-center gap-2"><i class="fas fa-graduation-cap text-green-600"></i><div><div class="font-medium">ميلادي: ' . htmlspecialchars($g) . '</div>' . ($h ? '<div class="text-xs text-gray-400">هجري: ' . htmlspecialchars($h) . '</div>' : '<div class="text-xs text-gray-400">هجري: غير متوفر</div>') . '</div></div>';
        }

        // compute interval
        try {
            $graduationDateObj = new DateTime($graduationDate);
            $today = new DateTime();
            $diff = $today->diff($graduationDateObj);
            $days = (int) $diff->format('%a');
            if ($graduationDateObj < $today) {
                $intervalHtml = '<div class="text-xs text-green-600 dark:text-green-400 mt-1">تم التخرج منذ ' . $days . ' يوم</div>';
            } else {
                $intervalHtml = '<div class="text-xs text-blue-600 dark:text-blue-400 mt-1">متبقي ' . $days . ' يوم للتخرج</div>';
            }
        } catch (Throwable $e) {
            $intervalHtml = '';
        }

    } elseif (!empty($graduationHijri)) {
        // try to convert hijri raw text to gregorian (best-effort)
        $raw = trim((string) $graduationHijri);
        // attempt to reuse convertHijriToGregorian if available
        if (function_exists('convertHijriToGregorian')) {
            $converted = convertHijriToGregorian($raw);
            if ($converted !== false) {
                $g = $formatGregorian($converted);
                $h = $raw;
                if ($g) {
                    $gradHtml .= '<div class="flex items-center gap-2"><i class="fas fa-graduation-cap text-green-600"></i><div><div class="font-medium">ميلادي: ' . htmlspecialchars($g) . '</div>' . ($h ? '<div class="text-xs text-gray-400">هجري: ' . htmlspecialchars($h) . '</div>' : '') . '</div></div>';
                }
            } else {
                // cannot convert; just show the raw hijri text
                $gradHtml .= '<div class="flex items-center gap-2"><i class="fas fa-graduation-cap text-green-600"></i><div><div class="font-medium">هجري: ' . htmlspecialchars($raw) . '</div></div></div>';
            }
        } else {
            $gradHtml .= '<div class="flex items-center gap-2"><i class="fas fa-graduation-cap text-green-600"></i><div><div class="font-medium">هجري: ' . htmlspecialchars($raw) . '</div></div></div>';
        }
    } else {
        $gradHtml = '<span class="text-xs text-gray-500">غير متوفر</span>';
    }

    return $gradHtml . $intervalHtml;
}

// Render contact date showing Gregorian (date/time) and Hijri if available - with 12-hour format
function renderContactDate($datetimeStr)
{
    if (empty($datetimeStr))
        return '-';
    // Try to format Gregorian datetime
    try {
        // Use the new 12-hour format function
        $greg = safeFormatDateTime12Hour($datetimeStr);
        if (empty($greg) || $greg === 'تاريخ غير صالح' || $greg === 'غير محدد')
            return 'غير محدد';
        $hijri = '';
        // try Intl
        $ts = false;
        if (class_exists('IntlDateFormatter')) {
            try {
                $fmt = new IntlDateFormatter('ar_SA@calendar=islamic', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL);
                $ts = strtotime($datetimeStr);
                if ($ts !== false)
                    $hijri = $fmt->format($ts);
            } catch (Throwable $e) {
                $hijri = '';
            }
        }
        // fallback to convertGregorianToHijri helper if available
        if (empty($hijri) && function_exists('convertGregorianToHijri')) {
            if ($ts === false) {
                $ts = strtotime($datetimeStr);
            }
            if ($ts !== false) {
                $conv = convertGregorianToHijri(date('Y-m-d', $ts));
                if ($conv !== false)
                    $hijri = $conv;
            }
        }

        if (!empty($hijri)) {
            return '<span class="block">' . htmlspecialchars($greg) . '</span><span class="text-xs text-gray-500 block mt-1">' . htmlspecialchars($hijri) . ' هـ</span>';
        }
        return htmlspecialchars($greg);
    } catch (Throwable $e) {
        return 'غير محدد';
    }
}

// Render next action date: prefer communication.next_action_date (datetime) then trainee-level next action - with 12-hour format
function renderNextActionDate(array $contact)
{
    // contact may contain 'next_action_date' from communications row or 'trainee_next_action' from joined trainee
    $next = $contact['next_action_date'] ?? null;
    if (empty($next)) {
        $next = $contact['trainee_next_action'] ?? null;
    }
    if (empty($next))
        return '';

    // استخدام الدالة الجديدة مع معالجة الأخطاء
    try {
        return safeFormatDateTime12Hour($next);
    } catch (Exception $e) {
        // fallback إلى تنسيق بسيط إذا فشلت الدالة الرئيسية
        $timestamp = strtotime($next);
        if ($timestamp === false)
            return '';
        return date('Y/m/d h:i A', $timestamp);
    }
}

// تهيئة المتغيرات بشكل آمن
$communications = []; // تأكد من أنها مصفوفة فارغة دائمًا
// Read the search term from GET so the search input actually works
$searchTerm = trim($_GET['search'] ?? '');
// خطأ افتراضي - منع تحذيرات المتغير غير المعرف
$error = '';

// تهيئة الفلاتر الافتراضية اعتمادًا على GET (آمنة)
$filters = [
    'type' => $_GET['type'] ?? '',
    'result' => $_GET['result'] ?? '',
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? '',
    'created_by' => $_GET['created_by'] ?? '',
    'trainee_id' => $_GET['trainee_id'] ?? ''
];

// حفظ الفلاتر المهمة في الجلسة عندما يتم تمريرها عبر GET
$savedKey = 'communications_filters';
$keysToPersist = ['assigned_to', 'trainee_id', 'type', 'result', 'start_date', 'end_date', 'created_by'];
// إذا كانت هناك قيم GET لواحد من المفاتيح الأساسية، خزّنها
$hasRelevantGet = false;
foreach ($keysToPersist as $k) {
    if (array_key_exists($k, $_GET)) {
        $hasRelevantGet = true;
        break;
    }
}
if ($hasRelevantGet) {
    $sess = $_SESSION[$savedKey] ?? [];
    foreach ($keysToPersist as $k) {
        if (array_key_exists($k, $_GET)) {
            $sess[$k] = $_GET[$k];
        }
    }
    $_SESSION[$savedKey] = $sess;
} else {
    // لا توجد GET لفلتر، استعد القيم المحفوظة إذا وجدت (لا تتجاوز GET الحالية)
    if (!empty($_SESSION[$savedKey])) {
        $sess = $_SESSION[$savedKey];
        foreach ($keysToPersist as $k) {
            if (!array_key_exists($k, $_GET) && isset($sess[$k])) {
                // ضعها في $_GET بحيث تتبع باقي منطق الصفحة نفس سلوك المستخدم
                $_GET[$k] = $sess[$k];
                $filters[$k] = $sess[$k];
            }
        }
    }
}


if (!isAdmin()) {
    if (isSupervisor()) {
        // Supervisor: scope to team (supervisor + employees)
        try {
            $team = getEmployeesUnderSupervisor($db, (int) ($_SESSION['user_id'] ?? 0));
            $team[] = (int) ($_SESSION['user_id'] ?? 0);
            // Do NOT inject a comma-separated string into $_GET (that breaks later logic)
            // Instead keep the team as an array in filters; we'll convert to trainee_ids later
            $filters['assigned_to'] = array_values(array_unique($team));
            error_log('Communications: applied supervisor assigned_to team scope [' . implode(',', $filters['assigned_to']) . ']');
        } catch (Exception $e) {
            error_log('Failed to apply supervisor communications scope: ' . $e->getMessage());
            // fallback to supervisor only
            $filters['assigned_to'] = [(int) ($_SESSION['user_id'] ?? 0)];
        }
    } else {
        // Regular employee: limit to themselves
        $_GET['assigned_to'] = (string) ($_SESSION['user_id'] ?? '');
        $filters['assigned_to'] = (int) ($_SESSION['user_id'] ?? 0);
    }
}

// Always accept exact contact_date and graduation_date if provided
if (isset($_GET['contact_date']) && $_GET['contact_date'] !== '') {
    $filters['contact_date'] = $_GET['contact_date'];
}
if (isset($_GET['graduation_date']) && $_GET['graduation_date'] !== '') {
    $filters['graduation_date'] = $_GET['graduation_date'];
}

// الحصول على البيانات مع معالجة الأخطاء المحسنة
try {
    // التحقق من دور المستخدم وإضافة فلتر المتدربين المسندين
    // Important: if an explicit assigned_to is provided via GET (for example after a redirect), respect it.
    // If the assigned_to parameter exists in GET, the user explicitly chose a value in the filter form.
    // If it's an empty string, treat it as 'All' and do not restrict by assigned_to.
    if (array_key_exists('assigned_to', $_GET)) {
        if ($_GET['assigned_to'] !== '' && $_GET['assigned_to'] !== 'all') {
            // explicit employee selected via UI
            $filters['assigned_to'] = (int) $_GET['assigned_to'];

            // إذا تم اختيار موظف محدد، جلب المتدربين المسندين له
            if (!isset($filters['trainee_ids'])) {
                $stmt = $db->prepare("SELECT id FROM trainees WHERE assigned_to = ?");
                $stmt->execute([(int) $_GET['assigned_to']]);
                $traineeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($traineeIds)) {
                    $filters['trainee_ids'] = $traineeIds;
                }
            }
        } else {
            // explicit 'All' selected: ensure no assigned_to filter is applied
            if (isset($filters['assigned_to']))
                unset($filters['assigned_to']);
            if (isset($filters['trainee_ids']))
                unset($filters['trainee_ids']);
        }
    } else {
        // assigned_to was not provided at all (e.g., first load).
        // If supervisor scope provided an array of user ids, convert team -> trainee_ids
        if (!empty($filters['assigned_to']) && is_array($filters['assigned_to'])) {
            try {
                $place = implode(',', array_fill(0, count($filters['assigned_to']), '?'));
                $stmt = $db->prepare("SELECT id FROM trainees WHERE assigned_to IN ($place)");
                $stmt->execute($filters['assigned_to']);
                $traineeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($traineeIds)) {
                    $filters['trainee_ids'] = $traineeIds;
                } else {
                    // if team has no trainees, fall back to supervisor id
                    $filters['assigned_to'] = [(int) ($_SESSION['user_id'] ?? 0)];
                }
            } catch (Exception $e) {
                error_log('Failed to resolve trainee ids for supervisor team: ' . $e->getMessage());
                $filters['assigned_to'] = [(int) ($_SESSION['user_id'] ?? 0)];
            }
        } else {
            // For non-admin users default to their own id
            if (!isAdmin()) {
                $filters['assigned_to'] = (int) ($_SESSION['user_id'] ?? 0);

                // جلب المتدربين المسندين للمستخدم الحالي
                $stmt = $db->prepare("SELECT id FROM trainees WHERE assigned_to = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $traineeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($traineeIds)) {
                    $filters['trainee_ids'] = $traineeIds;
                }
            }
        }
    }
    // جلب قائمة الموظفين للفلتر (آمن مع فحص الاعمدة الاختيارية)
    try {
        $checkUsers = $db->query("SHOW TABLES LIKE 'users'");
        if ($checkUsers->rowCount() > 0) {
            $hasDeletedAt = ($db->query("SHOW COLUMNS FROM users LIKE 'deleted_at'")->rowCount() > 0);
            $hasStatus = ($db->query("SHOW COLUMNS FROM users LIKE 'status'")->rowCount() > 0);
            $hasIsActive = ($db->query("SHOW COLUMNS FROM users LIKE 'is_active'")->rowCount() > 0);

            $where = [];
            if ($hasDeletedAt)
                $where[] = 'deleted_at IS NULL';
            // Do not filter by status/is_active here - include users irrespective of status

            // By default do not restrict by role here — include all users (legacy imports, contractors, etc.).
            // However, when the current user is a supervisor we should not show admins in the "choose employee" filter
            // because supervisors should not select managers. Keep deleted/status/is_active checks above to avoid showing removed users.
            if (isSupervisor()) {
                $where[] = "COALESCE(u.role, '') != 'admin'";
            }

            $whereSql = '';
            if (!empty($where))
                $whereSql = 'WHERE ' . implode(' AND ', $where);

            // Use subqueries to provide counts if desired; keeping it simple here to ensure all users returned.
            $q = "SELECT u.id, u.full_name, COALESCE(u.role, '') as role,
                                                COALESCE((SELECT COUNT(*) FROM trainees t WHERE t.assigned_to = u.id),0) as assigned_trainees_count
                                            FROM users u $whereSql ORDER BY u.full_name";
            $stmt = $db->prepare($q);
            $stmt->execute();
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log('Error fetching users for communications filter: ' . $e->getMessage());
        $employees = [];
    }

    // إذا تم تمرير assigned_to، جلب قائمة المتدربين للمشاهدة (سواء تم تمرير trainee_ids أم لا)
    $assignedTrainees = [];
    if (!empty($filters['assigned_to'])) {
        try {
            if (!empty($filters['trainee_ids']) && is_array($filters['trainee_ids'])) {
                // جلب أسماء المتدربين المحددين
                $placeholders = implode(',', array_fill(0, count($filters['trainee_ids']), '?'));
                $stmt = $db->prepare("SELECT id, full_name FROM trainees WHERE id IN ($placeholders) ORDER BY full_name");
                $stmt->execute(array_values(array_map('intval', (array) $filters['trainee_ids'])));
                $assignedTrainees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // جلب جميع المتدربين المسندين للموظف
                $stmt = $db->prepare("SELECT id, full_name FROM trainees WHERE assigned_to = ? ORDER BY full_name");
                $stmt->execute([(int) $filters['assigned_to']]);
                $assignedTrainees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            // لا نفشل الصفحة بسبب فشل جلب المتدربين؛ سجل الخطأ واستمر
            error_log('Failed to fetch assigned trainees for communications view: ' . $e->getMessage());
            $assignedTrainees = [];
        }

        // Diagnostic: if we expected trainees but found none, log a helpful message
        if (!empty($filters['assigned_to']) && empty($assignedTrainees)) {
            try {
                $countStmt = $db->prepare("SELECT COUNT(*) as c FROM trainees WHERE assigned_to = ?");
                $countStmt->execute([(int) $filters['assigned_to']]);
                $row = $countStmt->fetch(PDO::FETCH_ASSOC);
                $count = $row['c'] ?? 0;

                // fetch up to 5 sample trainee ids to help debugging
                $sample = [];
                if ($count > 0) {
                    $sampleStmt = $db->prepare("SELECT id FROM trainees WHERE assigned_to = ? LIMIT 5");
                    $sampleStmt->execute([(int) $filters['assigned_to']]);
                    $sample = $sampleStmt->fetchAll(PDO::FETCH_COLUMN);
                }

                error_log(sprintf('communications: assigned_to=%s expected trainees but fetched 0 rows; DB count=%d; sample_ids=%s', (int) $filters['assigned_to'], (int) $count, json_encode($sample)));
            } catch (Exception $e) {
                error_log('communications diagnostic failed: ' . $e->getMessage());
            }
        }
    }
























    // قبل البحث: قم بتطبيع قيم ENUM للنوع والنتيجة حتى تتطابق مع قيم قاعدة البيانات
    if (!empty($filters['type'])) {
        $normType = $communicationModel->normalizeEnumValue('type', $filters['type'], null);
        if ($normType === null) {
            // إذا لم نستطع تطبيع القيمة، احذف الفلتر حتى لا يمنع النتائج بطريق الخطأ
            unset($filters['type']);
        } else {
            $filters['type'] = $normType;
        }
    }
    if (!empty($filters['result'])) {
        $normResult = $communicationModel->normalizeEnumValue('result', $filters['result'], null);
        if ($normResult === null) {
            unset($filters['result']);
        } else {
            $filters['result'] = $normResult;
        }
    }

    // استخدام searchCommunications في كل الحالات مع الفلاتر المناسبة
    $communications = $communicationModel->searchCommunications($searchTerm, $filters);

    // إذا كان هناك متدربين مسندين، قم بإنشاء سجلات افتراضية فقط عندما لا تكون هناك فلاتر تُقيّد العرض
    // (فالصفوف الافتراضية تعبر عن متدربين "مسندين" وليس عن سجلات تواصل فعلية، لذلك يجب ألا تتجاوز فلاتر مثل result/type/date)
    $restrictingFilterKeys = ['result', 'type', 'start_date', 'end_date', 'contact_date', 'graduation_date', 'created_by'];
    $hasRestrictingFilters = false;
    foreach ($restrictingFilterKeys as $fk) {
        if (!empty($filters[$fk])) {
            $hasRestrictingFilters = true;
            break;
        }
    }

    // Only add default (placeholder) communications for assigned trainees when the user
    // did not explicitly select an `assigned_to` value via GET. If the user selected
    // a specific employee we must NOT create placeholders because that can surface
    // "غير محدد"/placeholder states which don't reflect actual communications.
    if (!empty($filters['trainee_ids']) && is_array($filters['trainee_ids']) && !$hasRestrictingFilters && !array_key_exists('assigned_to', $_GET)) {
        $defaultCommunications = [];

        // جلب معلومات المتدربين المسندين
        $placeholders = implode(',', array_fill(0, count($filters['trainee_ids']), '?'));
        // include trainee-level next_action fields so defaults can show saved notes
        $stmt = $db->prepare("SELECT t.id, t.full_name, t.national_id, t.assigned_to, COALESCE(t.graduation_date, '') as graduation_date, COALESCE(t.graduation_date_hijri, '') as graduation_date_hijri, COALESCE(t.next_action, '') as trainee_next_action, COALESCE(t.next_action_notes, '') as trainee_next_action_notes, COALESCE(t.last_contact, '') as trainee_last_contact, u.full_name as assigned_to_name 
                      FROM trainees t 
                                      LEFT JOIN users u ON t.assigned_to = u.id 
                                      WHERE t.id IN ($placeholders)");
        $stmt->execute(array_values(array_map('intval', (array) $filters['trainee_ids'])));
        $assignedTrainees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // إنشاء سجل تواصل افتراضي لكل متدرب مسند فقط إذا لم توجد سجلات تواصل حقيقية له
        // بناء مجموعة من trainee_id الموجودين بالفعل في نتائج البحث
        $existingTraineeIds = [];
        if (!empty($communications) && is_array($communications)) {
            foreach ($communications as $c) {
                if (!empty($c['trainee_id']))
                    $existingTraineeIds[] = (int) $c['trainee_id'];
            }
            $existingTraineeIds = array_unique($existingTraineeIds);
        }

        foreach ($assignedTrainees as $trainee) {
            // إذا كان لدى هذا المتدرب سجلات تواصل حقيقية، تجاهل إنشاء العنصر الافتراضي
            if (in_array((int) $trainee['id'], $existingTraineeIds, true))
                continue;

            // prefer trainee-level next action/notes if present
            $preferredAction = !empty($trainee['trainee_next_action']) ? $trainee['trainee_next_action'] : null;
            $preferredNotes = !empty($trainee['trainee_next_action_notes']) ? $trainee['trainee_next_action_notes'] : null;

            $defaultCommunications[] = [
                'id' => 'new_' . $trainee['id'],
                'trainee_id' => $trainee['id'],
                'trainee_name' => $trainee['full_name'],
                'national_id' => $trainee['national_id'],
                'graduation_date' => $trainee['graduation_date'] ?? '',
                'graduation_date_hijri' => $trainee['graduation_date_hijri'] ?? '',
                'type' => 'تم الإسناد',
                'result' => 'تم إسناد المتدرب',
                // Prefer showing trainee's last_contact (if available) instead of using current time for all placeholders
                'contact_date' => !empty($trainee['trainee_last_contact']) ? $trainee['trainee_last_contact'] : '',
                // If trainee-level next action exists, expose it as next_action_date so the table can show its datetime
                'next_action_date' => !empty($trainee['trainee_next_action']) ? $trainee['trainee_next_action'] : null,
                'next_action' => $preferredAction ?? 'متابعة المتدرب',
                'next_action_notes' => $preferredNotes ?? '',
                'notes' => 'تم إسناد المتدرب حديثاً',
                'created_by_name' => $_SESSION['user_name'] ?? 'النظام',
                'assigned_to' => $trainee['assigned_to'],
                'assigned_to_name' => $trainee['assigned_to_name']
            ];
        }

        // دمج السجلات الافتراضية مع السجلات الموجودة: ضع السجلات الحقيقية أولاً ثم الإضافات الافتراضية
        if (!empty($defaultCommunications)) {
            // إذا توجد بالفعل سجلات حقيقية فاضف الافتراضية بعدهم
            if (!empty($communications) && is_array($communications)) {
                $communications = array_merge($communications, $defaultCommunications);
            } else {
                $communications = $defaultCommunications;
            }
        }
    }

    // Safe fallback: if search returned nothing but filters indicate a specific trainee(s) or assigned_to,
    // fetch communications directly to ensure the user sees records that may have been excluded by complex search logic.
    if (empty($communications)) {
        $shouldAttemptFallback = false;
        if (!empty($filters['trainee_id']))
            $shouldAttemptFallback = true;
        if (!empty($filters['trainee_ids']) && is_array($filters['trainee_ids']))
            $shouldAttemptFallback = true;
        if (!empty($filters['assigned_to']))
            $shouldAttemptFallback = true;

        if ($shouldAttemptFallback) {
            try {
                $fallbackSql = "SELECT c.*, 
                                COALESCE(t.full_name, '') as trainee_name,
                                COALESCE(t.national_id, '') as national_id,
                                COALESCE(t.assigned_to, 0) as assigned_to,
                                COALESCE(t.graduation_date, '') as graduation_date,
                                COALESCE(t.graduation_date_hijri, '') as graduation_date_hijri,
                                COALESCE(u.full_name, '') as created_by_name,
                                COALESCE(u2.full_name, '') as assigned_to_name,
                                r.id as reminder_id,
                                r.status as reminder_status
                            FROM communications c
                            LEFT JOIN trainees t ON c.trainee_id = t.id
                            LEFT JOIN users u ON c.created_by = u.id
                            LEFT JOIN users u2 ON t.assigned_to = u2.id
                            LEFT JOIN tasks r ON c.reminder_id = r.id
                            WHERE 1=1";

                $fbParams = [];

                if (!empty($filters['trainee_id'])) {
                    $fallbackSql .= " AND c.trainee_id = :trainee_id";
                    $fbParams['trainee_id'] = (int) $filters['trainee_id'];
                } elseif (!empty($filters['trainee_ids']) && is_array($filters['trainee_ids'])) {
                    $placeholders = implode(',', array_map(function ($i) {
                        return ':tid' . $i;
                    }, array_keys($filters['trainee_ids'])));
                    $fallbackSql .= " AND c.trainee_id IN ($placeholders)";
                    foreach ($filters['trainee_ids'] as $i => $val) {
                        $fbParams['tid' . $i] = (int) $val;
                    }
                }

                if (!empty($filters['assigned_to'])) {
                    // match via trainees.assigned_to
                    $fallbackSql .= " AND COALESCE(t.assigned_to, 0) = :assigned_to";
                    $fbParams['assigned_to'] = (int) $filters['assigned_to'];
                }

                // preserve date/type/result filters if present
                if (!empty($filters['type'])) {
                    $fallbackSql .= " AND c.type = :type";
                    $fbParams['type'] = $filters['type'];
                }
                if (!empty($filters['result'])) {
                    $fallbackSql .= " AND c.result = :result";
                    $fbParams['result'] = $filters['result'];
                }
                if (!empty($filters['start_date'])) {
                    $fallbackSql .= " AND DATE(c.contact_date) >= :start_date";
                    $fbParams['start_date'] = $filters['start_date'];
                }
                if (!empty($filters['end_date'])) {
                    $fallbackSql .= " AND DATE(c.contact_date) <= :end_date";
                    $fbParams['end_date'] = $filters['end_date'];
                }

                $fallbackSql .= " ORDER BY c.contact_date DESC";

                $fs = $db->prepare($fallbackSql);
                // bind parameters
                foreach ($fbParams as $k => $v) {
                    $param = ':' . $k;
                    if (is_int($v)) {
                        $fs->bindValue($param, $v, PDO::PARAM_INT);
                    } else {
                        $fs->bindValue($param, $v, PDO::PARAM_STR);
                    }
                }
                $fs->execute();
                $fbRows = $fs->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($fbRows)) {
                    error_log('communications:list - fallback fetch returned ' . count($fbRows) . ' rows for filters: ' . json_encode(array_intersect_key($filters, array_flip(['trainee_id', 'assigned_to', 'trainee_ids']))));
                    $communications = $fbRows;
                    $_SESSION['alert'] = [
                        'type' => 'info',
                        'title' => 'عرض بديل لسجلات التواصل',
                        'message' => 'تم عرض سجلات التواصل مباشرة من السجل لأن نتائج البحث لم تُرجع سجلات متوقعة.',
                        'icon' => 'info'
                    ];
                }
            } catch (Exception $e) {
                error_log('communications:list fallback failed: ' . $e->getMessage());
            }
        }
    }

    // إذا اختار المستخدم "الكل" للموظف المسند إليه، نعرض جميع سجلات التواصل في النظام
    // (مع الحفاظ على فلاتر النوع/النتيجة/التواريخ إن وُجِدت)
    if (empty($communications) && (array_key_exists('assigned_to', $_GET) && ($_GET['assigned_to'] === '' || $_GET['assigned_to'] === 'all'))) {
        try {
            $simpleSql = "SELECT c.*, COALESCE(t.full_name,'') as trainee_name, COALESCE(t.national_id,'') as national_id, 
                                  COALESCE(t.graduation_date, '') as graduation_date, COALESCE(t.graduation_date_hijri, '') as graduation_date_hijri,
                                  COALESCE(u.full_name,'') as created_by_name, COALESCE(t.assigned_to, 0) as assigned_to,
                                  COALESCE(u2.full_name, '') as assigned_to_name
                                  FROM communications c
                                  LEFT JOIN trainees t ON c.trainee_id = t.id
                                  LEFT JOIN users u ON c.created_by = u.id
                                  LEFT JOIN users u2 ON t.assigned_to = u2.id
                                  WHERE 1=1";

            $params = [];
            if (!empty($filters['type'])) {
                $simpleSql .= " AND c.type = :type";
                $params[':type'] = $filters['type'];
            }
            if (!empty($filters['result'])) {
                $simpleSql .= " AND c.result = :result";
                $params[':result'] = $filters['result'];
            }
            if (!empty($filters['start_date'])) {
                $simpleSql .= " AND DATE(c.contact_date) >= :start_date";
                $params[':start_date'] = $filters['start_date'];
            }
            if (!empty($filters['end_date'])) {
                $simpleSql .= " AND DATE(c.contact_date) <= :end_date";
                $params[':end_date'] = $filters['end_date'];
            }

            $simpleSql .= " ORDER BY c.contact_date DESC";

            $sstmt = $db->prepare($simpleSql);
            foreach ($params as $k => $v) {
                $sstmt->bindValue($k, $v, PDO::PARAM_STR);
            }
            $sstmt->execute();
            $rows = $sstmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $communications = $rows;
                $_SESSION['alert'] = [
                    'type' => 'info',
                    'title' => 'عرض كافة سجلات التواصل',
                    'message' => 'تم عرض جميع سجلات التواصل في النظام.',
                    'icon' => 'info'
                ];
            } else {
                // Diagnostic: no rows returned when selecting 'All' — log filters for debugging and show brief message
                error_log('communications:list all-branch returned 0 rows; filters: ' . json_encode(array_intersect_key($filters, array_flip(['type', 'result', 'start_date', 'end_date']))));
                $_SESSION['alert'] = [
                    'type' => 'warning',
                    'title' => 'لا توجد سجلات',
                    'message' => 'لم يتم العثور على سجلات تواصل تتطابق مع الفلاتر الحالية (راجع سجلات الخادم للتشخيص).',
                    'icon' => 'warning'
                ];
            }
        } catch (Exception $e) {
            error_log('communications:list final all-communications fallback failed: ' . $e->getMessage());
        }
    }

    // Additional fallback: if still empty, try to show assigned trainees for the current user
    // This addresses the case where the user navigates away and returns without GET params
    if (empty($communications)) {
        try {
            $targetAssignedTo = null;
            if (!empty($filters['assigned_to'])) {
                $targetAssignedTo = (int) $filters['assigned_to'];
            } elseif (!array_key_exists('assigned_to', $_GET) && !isAdmin()) {
                // not explicitly filtering by assigned_to in GET, but non-admin users should see their assigned trainees
                $targetAssignedTo = (int) ($_SESSION['user_id'] ?? 0);
            }

            if (!empty($targetAssignedTo)) {
                $stmt = $db->prepare("SELECT t.id, t.full_name, t.national_id, COALESCE(t.graduation_date,'') as graduation_date, COALESCE(t.graduation_date_hijri,'') as graduation_date_hijri, COALESCE(t.next_action,'') as trainee_next_action, COALESCE(t.next_action_notes,'') as trainee_next_action_notes, COALESCE(u.full_name,'') as assigned_to_name FROM trainees t LEFT JOIN users u ON t.assigned_to = u.id WHERE t.assigned_to = ? ORDER BY t.full_name");
                $stmt->execute([$targetAssignedTo]);
                $myAssigned = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($myAssigned)) {
                    // build list of existing trainee ids already in $communications
                    $existingTraineeIds = [];
                    if (!empty($communications) && is_array($communications)) {
                        foreach ($communications as $c) {
                            if (!empty($c['trainee_id']))
                                $existingTraineeIds[] = (int) $c['trainee_id'];
                        }
                        $existingTraineeIds = array_unique($existingTraineeIds);
                    }

                    // Only add placeholder communications when the user did NOT explicitly
                    // select an `assigned_to` via GET. If the user explicitly chose an
                    // employee we should not invent placeholder rows.
                    if (!array_key_exists('assigned_to', $_GET)) {
                        $defaultCommunications = [];
                        foreach ($myAssigned as $trainee) {
                            if (in_array((int) $trainee['id'], $existingTraineeIds, true))
                                continue;
                            $preferredAction = !empty($trainee['trainee_next_action']) ? $trainee['trainee_next_action'] : null;
                            $preferredNotes = !empty($trainee['trainee_next_action_notes']) ? $trainee['trainee_next_action_notes'] : null;

                            $defaultCommunications[] = [
                                'id' => 'new_' . $trainee['id'],
                                'trainee_id' => $trainee['id'],
                                'trainee_name' => $trainee['full_name'],
                                'national_id' => $trainee['national_id'],
                                'graduation_date' => $trainee['graduation_date'] ?? '',
                                'graduation_date_hijri' => $trainee['graduation_date_hijri'] ?? '',
                                'type' => 'تم الإسناد',
                                'result' => 'تم إسناد المتدرب',
                                // prefer the trainee's last saved contact if present; do NOT use current time on page load/refresh
                                'contact_date' => !empty($trainee['trainee_last_contact']) ? $trainee['trainee_last_contact'] : '',
                                'next_action' => $preferredAction ?? 'متابعة المتدرب',
                                'next_action_notes' => $preferredNotes ?? '',
                                'notes' => 'تم إسناد المتدرب حديثاً',
                                'created_by_name' => $_SESSION['user_name'] ?? 'النظام',
                                'assigned_to' => $targetAssignedTo,
                                'assigned_to_name' => $trainee['assigned_to_name'] ?? ''
                            ];
                        }

                        if (!empty($defaultCommunications)) {
                            $communications = array_merge($communications ?: [], $defaultCommunications);
                            $_SESSION['alert'] = [
                                'type' => 'info',
                                'title' => 'عرض المتدربين المسندين',
                                'message' => 'تم عرض المتدربين المسندين لأن نتائج البحث فارغة.',
                                'icon' => 'info'
                            ];
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('communications:list assigned fallback failed: ' . $e->getMessage());
        }
    }

    // Diagnostic: if redirected after add with new_comm, verify the record exists and whether it is included
    if (!empty($_GET['new_comm'])) {
        $newCommId = (int) $_GET['new_comm'];
        try {
            $checkStmt = $db->prepare('SELECT * FROM communications WHERE id = ?');
            $checkStmt->execute([$newCommId]);
            $newCommRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($newCommRow) {
                // check whether the returned $communications includes this id
                $found = false;
                foreach ($communications as $c) {
                    if ((int) $c['id'] === $newCommId) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    error_log('communications:list - new_comm exists in DB but not in search results. new_comm=' . $newCommId . ' trainee_id=' . $newCommRow['trainee_id']);
                    $_SESSION['alert'] = [
                        'type' => 'warning',
                        'title' => 'تشخيص التواصل الجديد',
                        'message' => 'سجل التواصل تم إضافته لكن لم يظهر في القائمة. تم التحقق من وجوده في قاعدة البيانات.',
                        'icon' => 'warning'
                    ];
                } else {
                    // mark success for UX
                    $_SESSION['alert'] = [
                        'type' => 'success',
                        'title' => 'نجاح',
                        'message' => 'سجل التواصل الجديد ظاهر في القائمة.',
                        'icon' => 'success'
                    ];
                }
            } else {
                error_log('communications:list - new_comm provided but not found in DB: ' . $newCommId);
                $_SESSION['alert'] = [
                    'type' => 'error',
                    'title' => 'خطأ تشخيصي',
                    'message' => 'لم يتم العثور على سجل التواصل الذي تم إضافته في قاعدة البيانات.',
                    'icon' => 'error'
                ];
            }
        } catch (Exception $e) {
            error_log('communications:list diagnostic failed: ' . $e->getMessage());
        }
        // Previously we reloaded here which cleared GET params and hid diagnostic alerts/fallback rows.
        // Continue rendering so alerts and any fallback rows are visible to the user.
        // no redirect; c  ontinue
    }
} catch (Exception $e) {
    $error = $e->getMessage();
    $communications = []; // تأكد من أنها مصفوفة فارغة في حالة الخطأ
}


// تأكد مرة أخرى أن $communications هي مصفوفة
if (!is_array($communications)) {
    $communications = [];
}

// Collapse multiple communication rows for the same trainee into a single row
// keeping the most recent contact_date. This prevents the UI from showing
// the same trainee multiple times when they have several communications.
$groupedCommunications = [];
foreach ($communications as $c) {
    // prefer numeric trainee_id when available
    $tid = isset($c['trainee_id']) && $c['trainee_id'] !== '' ? (int)$c['trainee_id'] : null;

    if ($tid && $tid > 0) {
        $currentTs = 0;
        if (!empty($c['contact_date'])) {
            $ts = strtotime($c['contact_date']);
            $currentTs = $ts === false ? 0 : $ts;
        }

        // If we don't have this trainee yet, or this row is newer, keep it
        if (!isset($groupedCommunications[$tid]) || ($currentTs > ($groupedCommunications[$tid]['_contact_ts'] ?? 0))) {
            $c['_contact_ts'] = $currentTs;
            $groupedCommunications[$tid] = $c;
        }
    } else {
        // keep rows without a valid trainee_id (e.g. special placeholders)
        $key = $c['id'] ?? uniqid('row_');
        $groupedCommunications[$key] = $c;
    }
}

// Remove internal timestamps and reindex
$communications = array_values(array_map(function($r){ unset($r['_contact_ts']); return $r; }, $groupedCommunications));

$page_title = 'سجلات التواصل';
include '../../includes/tw_layout_start.php';
?>

<!-- Page-specific dark-mode hover neutralization -->
<style>
    html.dark .comm-list-no-hover tbody tr:hover,
    html.dark .comm-list-no-hover tr:hover,
    html.dark .comm-list-no-hover .hover\:bg-blue-50:hover,
    html.dark .comm-list-no-hover .hover\:bg-green-200:hover,
    html.dark .comm-list-no-hover .hover\:bg-red-200:hover,
    html.dark .comm-list-no-hover .hover\:bg-gray-200:hover,
    html.dark .comm-list-no-hover .hover\:bg-gray-100:hover,
    html.dark .comm-list-no-hover button:hover,
    html.dark .comm-list-no-hover a:hover {
        background-color: transparent !important;
        transform: none !important;
        box-shadow: none !important;
        color: inherit !important;
    }

    /* Remove transition jitter in dark mode for this page */
    html.dark .comm-list-no-hover .transition-all,
    html.dark .comm-list-no-hover .transition-colors {
        transition: none !important;
    }

    /* Improve last-contact box readability in dark mode */
    html.dark .comm-list-no-hover td .last-contact-box {
        background-color: rgba(255, 255, 255, 0.03) !important;
        /* subtle elevated surface */
        border: 1px solid rgba(255, 255, 255, 0.04) !important;
        color: #e6eef6 !important;
    }

    html.dark .comm-list-no-hover td .last-contact-box .inline-flex {
        background-color: rgba(255, 255, 255, 0.02) !important;
    }

    /* Ensure type/result badges remain visible in dark mode */
    html.dark .comm-list-no-hover td .last-contact-box .inline-flex .px-1\.5 {
        background-color: rgba(255, 255, 255, 0.02) !important;
        color: #e6eef6 !important;
    }

    /* تحسينات إضافية للوضع المظلم في الجدول */
    html.dark .comm-list-no-hover table {
        border-color: rgba(255, 255, 255, 0.1) !important;
    }

    html.dark .comm-list-no-hover thead tr {
        background: linear-gradient(to right, #1e40af, #1e3a8a) !important;
    }

    html.dark .comm-list-no-hover tbody tr {
        border-bottom-color: rgba(255, 255, 255, 0.05) !important;
    }

    html.dark .comm-list-no-hover tbody tr:nth-child(even) {
        background-color: rgba(255, 255, 255, 0.01) !important;
    }

    html.dark .comm-list-no-hover tbody tr:hover {
        background-color: rgba(59, 130, 246, 0.05) !important;
    }

    /* تحسين الألوان في الخلايا للوضع المظلم */
    html.dark .comm-list-no-hover .bg-blue-50,
    html.dark .comm-list-no-hover .bg-green-50,
    html.dark .comm-list-no-hover .bg-yellow-50,
    html.dark .comm-list-no-hover .bg-gray-50,
    html.dark .comm-list-no-hover .bg-red-100,
    html.dark .comm-list-no-hover .bg-green-100,
    html.dark .comm-list-no-hover .bg-yellow-100,
    html.dark .comm-list-no-hover .bg-blue-100,
    html.dark .comm-list-no-hover .bg-purple-100,
    html.dark .comm-list-no-hover .bg-orange-100,
    html.dark .comm-list-no-hover .bg-indigo-100 {
        background-color: rgba(255, 255, 255, 0.05) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }

    /* تحسين النصوص في الوضع المظلم */
    html.dark .comm-list-no-hover .text-gray-800,
    html.dark .comm-list-no-hover .text-gray-700,
    html.dark .comm-list-no-hover .text-gray-900 {
        color: #e5e7eb !important;
    }

    html.dark .comm-list-no-hover .text-gray-500,
    html.dark .comm-list-no-hover .text-gray-400 {
        color: #9ca3af !important;
    }

    /* تحسين الأزرار في الوضع المظلم */
    html.dark .comm-list-no-hover .bg-gray-100 {
        background-color: rgba(255, 255, 255, 0.1) !important;
    }

    html.dark .comm-list-no-hover .bg-gray-700 {
        background-color: rgba(255, 255, 255, 0.15) !important;
    }

    /* تحسين الأيقونات في الوضع المظلم */
    html.dark .comm-list-no-hover .text-gray-400 {
        color: #6b7280 !important;
    }

    /* تحسين التدرجات في الوضع المظلم */
    html.dark .comm-list-no-hover .bg-gradient-to-br {
        opacity: 0.9;
    }

    /* إصلاح مشكلة الحدود في الوضع المظلم */
    html.dark .comm-list-no-hover .border-gray-200,
    html.dark .comm-list-no-hover .border-gray-300 {
        border-color: rgba(255, 255, 255, 0.1) !important;
    }

    html.dark .comm-list-no-hover .divide-gray-200>*+* {
        border-color: rgba(255, 255, 255, 0.1) !important;
    }

    /* تحسين ظلال البادجات في الوضع المظلم */
    html.dark .comm-list-no-hover .shadow-sm {
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.3) !important;
    }
</style>

<!-- Modal Components -->
<div id="viewModal"
    class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50 transition-opacity duration-300"
    style="backdrop-filter: blur(8px);">
    <div class="relative min-h-screen flex items-center justify-center p-4">
        <div class="absolute inset-0" onclick="closeModal('viewModal')"></div>
        <div class="relative w-11/12 md:w-3/4 lg:w-1/2 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl transform transition-all duration-300 scale-95 opacity-0"
            id="viewModalBox">
            <!-- Header -->
            <div class="flex items-center justify-between p-6 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white flex items-center">
                    <i class="fas fa-info-circle text-blue-500 ml-2"></i>
                    تفاصيل التواصل
                </h3>
                <button onclick="closeModal('viewModal')"
                    class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <i class="fas fa-times text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"></i>
                </button>
            </div>

            <!-- Content -->
            <div id="viewModalContent" class="overflow-y-auto max-h-[calc(100vh-16rem)]">
                <div class="flex justify-center items-center p-8">
                    <div class="relative">
                        <div class="animate-spin rounded-full h-16 w-16 border-4 border-gray-200 dark:border-gray-700">
                        </div>
                        <div class="absolute top-0 left-0 right-0 bottom-0 flex items-center justify-center">
                            <div class="animate-pulse">
                                <i class="fas fa-sync-alt text-blue-500 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="editModal"
    class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50 transition-opacity duration-300"
    style="backdrop-filter: blur(8px);">
    <div class="relative min-h-screen flex items-center justify-center p-4">
        <div class="absolute inset-0" onclick="closeModal('editModal')"></div>
        <div class="relative w-11/12 md:w-3/4 lg:w-2/3 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl transform transition-all duration-300 scale-95 opacity-0"
            id="editModalBox">
            <!-- Header -->
            <div class="flex items-center justify-between p-6 border-b border-gray-100 dark:border-gray-700">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white flex items-center">
                    <i class="fas fa-edit text-green-500 ml-2"></i>
                    تعديل التواصل
                </h3>
                <button onclick="closeModal('editModal')"
                    class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-green-500">
                    <i class="fas fa-times text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"></i>
                </button>
            </div>

            <!-- Content -->
            <div id="editModalContent" class="overflow-y-auto max-h-[calc(100vh-16rem)]">
                <div class="flex justify-center items-center p-8">
                    <div class="relative">
                        <div class="animate-spin rounded-full h-16 w-16 border-4 border-gray-200 dark:border-gray-700">
                        </div>
                        <div class="absolute top-0 left-0 right-0 bottom-0 flex items-center justify-center">
                            <div class="animate-pulse">
                                <i class="fas fa-sync-alt text-green-500 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Loading Indicator -->
<div id="loadingOverlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 hidden z-50 transition-opacity duration-300"
    style="backdrop-filter: blur(12px);">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 transform transition-all">
            <div class="relative">
                <div class="animate-spin rounded-full h-16 w-16 border-4 border-blue-500 border-t-transparent"></div>
                <div class="absolute top-0 left-0 right-0 bottom-0 flex items-center justify-center">
                    <div class="animate-pulse">
                        <i class="fas fa-circle-notch text-blue-500 text-2xl"></i>
                    </div>
                </div>
            </div>
            <p class="text-gray-600 dark:text-gray-300 mt-4 text-center animate-pulse">جاري التحميل...</p>
        </div>
    </div>
</div>

<div class="container mx-auto px-4 py-8 comm-list-no-hover">
    <div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white">سجلات التواصل</h1>
        <?php if (function_exists('hasAccess') && hasAccess('communication', 'create')): ?>
            <a href="add.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <i class="fas fa-plus ml-2"></i> إضافة تواصل جديد
            </a>
        <?php endif; ?>
    </div>


    <?php
    // Build a small panel of trainees who have communications (count + last contact)
    try {
        $ctSql = "SELECT t.id, t.full_name, COUNT(c.id) as comm_count, MAX(c.contact_date) as last_contact
                  FROM trainees t
                  JOIN communications c ON c.trainee_id = t.id
                  GROUP BY t.id, t.full_name
                  ORDER BY last_contact DESC
                  LIMIT 100";
        $ctStmt = $db->prepare($ctSql);
        $ctStmt->execute();
        $communicatedTrainees = $ctStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch up to 3 recent communications for each trainee in the list
        if (!empty($communicatedTrainees)) {
            $ids = array_map(function ($r) {
                return (int) $r['id'];
            }, $communicatedTrainees);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $commSql = "SELECT c.id, c.trainee_id, c.contact_date, c.type, c.result, c.notes, COALESCE(u.full_name,'') as created_by_name
                        FROM communications c
                        LEFT JOIN users u ON c.created_by = u.id
                        WHERE c.trainee_id IN ($placeholders)
                        ORDER BY c.trainee_id, c.contact_date DESC";
            $commStmt = $db->prepare($commSql);
            foreach ($ids as $i => $val)
                $commStmt->bindValue($i + 1, $val, PDO::PARAM_INT);
            $commStmt->execute();
            $allComms = $commStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allComms as $c) {
                $tid = (int) $c['trainee_id'];
                if (!isset($recentCommsByTrainee[$tid]))
                    $recentCommsByTrainee[$tid] = [];
                if (count($recentCommsByTrainee[$tid]) < 3)
                    $recentCommsByTrainee[$tid][] = $c;
            }
        }
    } catch (Exception $e) {
        error_log('Error building communicated trainees panel: ' . $e->getMessage());
        $communicatedTrainees = [];
        $recentCommsByTrainee = [];
    }
    ?>

    <?php if ($error): ?>
        <div class="mb-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg">
            <i class="fas fa-exclamation-circle ml-2"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- بطاقة البحث والفلاتر (مبسطة وموثوقة) -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
        <form method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- بحث نصي عام -->
                <div class="md:col-span-1">
                    <label class="block text-sm font-medium mb-2">بحث</label>
                    <div class="relative">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>"
                            placeholder="ابحث بالاسم، الملاحظات أو رقم الهوية"
                            class="w-full px-3 py-2 pl-10 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    </div>
                </div>



                <!-- النوع -->
                <div>
                    <label class="block text-sm font-medium mb-2">نوع التواصل</label>
                    <select name="type"
                        class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                        <option value="">الكل</option>
                        <option value="call" <?php echo ($filters['type'] ?? '') === 'call' ? 'selected' : ''; ?>>
                            مكالمة</option>
                        <option value="whatsapp" <?php echo ($filters['type'] ?? '') === 'whatsapp' ? 'selected' : ''; ?>>
                            واتساب</option>
                        <option value="email" <?php echo ($filters['type'] ?? '') === 'email' ? 'selected' : ''; ?>>
                            بريد إلكتروني</option>
                        <option value="meeting" <?php echo ($filters['type'] ?? '') === 'meeting' ? 'selected' : ''; ?>>
                            اجتماع</option>
                    </select>
                </div>

                <!-- النتيجة -->
                <div>
                    <label class="block text-sm font-medium mb-2">النتيجة</label>
                    <select name="result"
                        class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                        <option value="">الكل</option>
                        <option value="promise" <?php echo ($filters['result'] ?? '') === 'promise' ? 'selected' : ''; ?>>
                            وعد بالسداد</option>
                        <option value="refused" <?php echo ($filters['result'] ?? '') === 'refused' ? 'selected' : ''; ?>>
                            رفض السداد</option>
                        <option value="paid" <?php echo ($filters['result'] ?? '') === 'paid' ? 'selected' : ''; ?>>
                            تم السداد</option>
                        <option value="no_answer" <?php echo ($filters['result'] ?? '') === 'no_answer' ? 'selected' : ''; ?>>
                            لم يرد</option>
                        <option value="follow_up" <?php echo ($filters['result'] ?? '') === 'follow_up' ? 'selected' : ''; ?>>
                            متابعة</option>
                        <option value="not_interested" <?php echo ($filters['result'] ?? '') === 'not_interested' ? 'selected' : ''; ?>>
                            غير مهتم</option>
                        <option value="other" <?php echo ($filters['result'] ?? '') === 'other' ? 'selected' : ''; ?>>
                            أخرى</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-2">من تاريخ</label>
                    <input type="date" name="start_date"
                        value="<?php echo htmlspecialchars($filters['start_date'] ?? ''); ?>"
                        class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">إلى تاريخ</label>
                    <input type="date" name="end_date"
                        value="<?php echo htmlspecialchars($filters['end_date'] ?? ''); ?>"
                        class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                </div>

                <!-- الموظف المسند إليه -->
                <?php if (isAdmin() || isSupervisor()): ?>
                    <div>
                        <label class="block text-sm font-medium mb-2">الموظف</label>
                        <select name="assigned_to"
                            class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                            <option value="">الكل</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" <?php echo (isset($filters['assigned_to']) && (int) $filters['assigned_to'] === (int) $emp['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['full_name']); ?>
                                    (<?php echo htmlspecialchars($emp['role']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="flex items-end gap-2">
                    <button type="submit"
                        class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-filter ml-2"></i> تطبيق
                    </button>
                    <a href="list.php"
                        class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                        <i class="fas fa-redo ml-2"></i> مسح
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                <div>
                    <label class="block text-sm font-medium mb-2">تاريخ التواصل (محدد)</label>
                    <input type="date" name="contact_date"
                        value="<?php echo htmlspecialchars($filters['contact_date'] ?? ''); ?>"
                        class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">تاريخ التخرج (محدد)</label>
                    <input type="date" name="graduation_date"
                        value="<?php echo htmlspecialchars($filters['graduation_date'] ?? ''); ?>"
                        class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                </div>
                <div></div>
            </div>
        </form>
    </div>



    <!-- بطاقة النتائج -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        <!-- رأس الجدول -->
        <div
            class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex flex-col md:flex-row md:items-center justify-between">
            <div class="text-sm text-gray-600 dark:text-gray-400">
                عرض <span class="font-medium"><?php echo count($communications); ?></span> سجل
            </div>
            <div class="flex gap-2 mt-2 md:mt-0">
                <button onclick="exportToExcel()"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm">
                    <?php if (hasAccess('communication', 'export')): ?><i class="fas fa-file-excel ml-2"></i> تصدير
                        إكسل<?php endif; ?>
                </button>
                <button onclick="exportToPDF()"
                    class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm">
                    <i class="fas fa-file-pdf ml-2"></i> تصدير بي دي إف
                </button>
            </div>
        </div>

        <!-- الجدول -->
        <div
            class="overflow-x-auto max-w-full bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-gradient-to-r from-blue-600 to-blue-800 text-white">
                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider border-b border-blue-700"
                            style="min-width: 60px;">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-hashtag text-blue-200 mr-1"></i>
                                <span>رقم</span>
                            </div>
                        </th>
                        <th
                            class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider border-b border-blue-700 w-48">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-user-graduate text-blue-200 mr-1"></i>
                                <span>المتدرب</span>
                            </div>
                        </th>
                        <th
                            class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider border-b border-blue-700 w-36">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-id-card text-blue-200 mr-1"></i>
                                <span>رقم الهوية</span>
                            </div>
                        </th>
                        <!-- removed columns: نوع التواصل و النتيجة -->
                        <th
                            class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider border-b border-blue-700 w-36">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-calendar-check text-blue-200 mr-1"></i>
                                <span>تاريخ التواصل</span>
                            </div>
                        </th>
                        <th
                            class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider border-b border-blue-700 w-36">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-graduation-cap text-blue-200 mr-1"></i>
                                <span>تاريخ التخرج</span>
                            </div>
                        </th>
                        <th
                            class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider border-b border-blue-700 w-48">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-calendar-plus text-blue-200 mr-1"></i>
                                <span>الإجراء التالي</span>
                            </div>
                        </th>
                        <th
                            class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider border-b border-blue-700 w-32">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-user-tie text-blue-200 mr-1"></i>
                                <span>مسند لـ</span>
                            </div>
                        </th>
                        <th
                            class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider border-b border-blue-700 w-40">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-history text-blue-200 mr-1"></i>
                                <span>آخر تواصل</span>
                            </div>
                        </th>
                        <th
                            class="px-3 py-3 text-center text-xs font-semibold uppercase tracking-wider border-b border-blue-700 w-28">
                            <div class="flex items-center justify-center">
                                <i class="fas fa-cog text-blue-200 mr-1"></i>
                                <span>الإجراءات</span>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($communications) || !is_array($communications)): ?>
                        <tr>
                            <td colspan="9" class="px-3 py-4 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="rounded-full bg-blue-100 dark:bg-blue-900 p-2 mb-2">
                                        <i class="fas fa-inbox text-xl text-blue-500 dark:text-blue-300"></i>
                                    </div>
                                    <p class="text-gray-500 dark:text-gray-400 text-sm font-medium">
                                        <?php echo empty($searchTerm) ? 'لا توجد سجلات تواصل' : 'لم يتم العثور على نتائج'; ?>
                                    </p>
                                    <p class="text-gray-400 dark:text-gray-500 text-xs mt-1">
                                        <?php echo empty($searchTerm) ? 'يمكنك إضافة سجل تواصل جديد من خلال زر الإضافة' : 'حاول تغيير معايير البحث'; ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($communications as $index => $contact): ?>
                            <tr
                                class="hover:bg-blue-50 dark:hover:bg-blue-900/10 transition-all duration-200 <?php echo (isset($contact['id']) && strpos($contact['id'], 'new_') === 0) ? 'bg-green-50 dark:bg-green-900/10' : ''; ?>">
                                <!-- رقم -->
                                <td class="px-4 py-3 text-center">
                                    <span
                                        class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-200 font-semibold text-xs">
                                        <?php echo $index + 1; ?>
                                    </span>
                                </td>

                                <!-- المتدرب -->
                                <td class="px-4 py-3">
                                    <div class="flex items-center">
                                        <div
                                            class="flex-shrink-0 h-8 w-8 bg-gradient-to-br from-blue-500 to-blue-700 rounded-md flex items-center justify-center text-white font-bold shadow-sm">
                                            <?php echo mb_substr($contact['trainee_name'] ?? 'م', 0, 1, 'UTF-8'); ?>
                                        </div>
                                        <div class="mr-2">
                                            <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($contact['trainee_name'] ?? 'متدرب #' . ($contact['trainee_id'] ?? 'غير معروف')); ?>
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                <span class="inline-flex items-center">
                                                    <span class="text-blue-500 ml-1"><i class="fas fa-fingerprint"></i></span>
                                                    <?php echo $contact['trainee_id'] ?? 'غير معروف'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- رقم الهوية -->
                                <td class="px-4 py-3 text-center">
                                    <div
                                        class="inline-flex items-center px-2 py-1 rounded-md bg-gray-100 dark:bg-gray-700 text-xs">
                                        <i class="fas fa-id-card text-gray-500 dark:text-gray-400 ml-1"></i>
                                        <span class="text-gray-700 dark:text-gray-300">
                                            <?php echo htmlspecialchars($contact['national_id'] ?? '-'); ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- removed: نوع التواصل column (collapsed for compactness) -->

                                <!-- removed: النتيجة column -->

                                <!-- تاريخ التواصل -->
                                <td class="px-4 py-3 text-center">
                                    <div class="inline-flex flex-col items-center">
                                        <div class="bg-blue-50 dark:bg-blue-900/20 px-2 py-1 rounded-md text-xs">
                                            <div class="text-gray-500 dark:text-gray-400">
                                                <i class="fas fa-clock ml-1"></i> التاريخ
                                            </div>
                                            <div class="font-medium text-gray-800 dark:text-gray-200">
                                                <?php echo renderContactDate($contact['contact_date'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- تاريخ التخرج -->
                                <td class="px-4 py-3 text-center">
                                    <div class="inline-flex flex-col items-center">
                                        <div class="bg-green-50 dark:bg-green-900/10 px-2 py-1 rounded-md text-xs">
                                            <div class="text-gray-500 dark:text-gray-400">
                                                <i class="fas fa-calendar-alt ml-1"></i> التخرج
                                            </div>
                                            <div class="font-medium text-gray-800 dark:text-gray-200">
                                                <?php echo renderGraduationHtml($contact['graduation_date'] ?? '', $contact['graduation_date_hijri'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- الإجراء التالي -->
                                <td class="px-2 py-2">
                                    <?php if (!empty($contact['next_action_date']) || !empty($contact['next_action']) || !empty($contact['next_action_notes'])): ?>
                                        <div class="flex flex-col space-y-1">
                                            <?php if (!empty($contact['next_action_date']) || !empty($contact['trainee_next_action'])): ?>
                                                <div
                                                    class="inline-flex items-center bg-yellow-50 dark:bg-yellow-900/20 px-2 py-1 rounded-md text-xs flex-col items-start">
                                                    <div class="inline-flex items-center">
                                                        <i class="fas fa-calendar-plus text-yellow-500 dark:text-yellow-400 ml-1"></i>
                                                        <span class="font-medium text-gray-800 dark:text-gray-200">
                                                            <?php echo renderNextActionDate($contact); ?>
                                                        </span>
                                                    </div>
                                                    <?php if (!empty($contact['next_action_notes'])): ?>
                                                        <div class="text-gray-700 dark:text-gray-300 text-xs mt-1">
                                                            <?php echo nl2br(htmlspecialchars(mb_substr($contact['next_action_notes'], 0, 500))); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($contact['next_action'])): ?>
                                                <div class="bg-gray-50 dark:bg-gray-700 p-1 rounded-md text-xs">
                                                    <div class="text-gray-500 dark:text-gray-400">
                                                        <i class="fas fa-tasks ml-1"></i> الإجراء
                                                    </div>
                                                    <div class="text-gray-800 dark:text-gray-200">
                                                        <?php echo htmlspecialchars($contact['next_action']); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>


                                            <?php if (!empty($contact['reminder_id'])): ?>
                                                <div class="inline-flex items-center justify-center">
                                                    <span
                                                        class="inline-flex items-center px-1.5 py-0.5 rounded-md text-xs <?php echo (($contact['reminder_status'] ?? '') === 'completed') ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400'; ?>">
                                                        <i class="fas fa-bell ml-1"></i>
                                                        <?php echo (($contact['reminder_status'] ?? '') === 'completed') ? 'تم التذكير' : 'تذكير نشط'; ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center">
                                            <span
                                                class="inline-flex items-center justify-center h-6 w-6 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500">
                                                <i class="fas fa-minus"></i>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- مسند لـ -->
                                <td class="px-2 py-2 text-center">
                                    <?php if (!empty($contact['assigned_to_name'])): ?>
                                        <div class="flex flex-col items-center">
                                            <div
                                                class="flex-shrink-0 h-7 w-7 bg-gradient-to-br from-purple-500 to-purple-700 rounded-md flex items-center justify-center text-white font-bold shadow-sm mb-1">
                                                <?php echo mb_substr($contact['assigned_to_name'] ?? 'م', 0, 1, 'UTF-8'); ?>
                                            </div>
                                            <div class="text-xs font-medium text-gray-800 dark:text-gray-200">
                                                <?php echo htmlspecialchars($contact['assigned_to_name']); ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div
                                            class="inline-flex items-center justify-center px-2 py-1 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-xs">
                                            <i class="fas fa-user-slash ml-1"></i> غير مسند
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- آخر تواصل للمسند -->
                                <td class="px-2 py-2">
                                    <?php if (!empty($contact['assigned_to_name'])): ?>
                                                <?php
                                                    // استخدم بيانات السطر الحالي لعرض "آخر تواصل" حتى تطابق الفلاتر المعمول بها
                                                    $lastComm = [];
                                                    if (!empty($contact['type']) || !empty($contact['result']) || !empty($contact['contact_date'])) {
                                                        $lastComm['type'] = $contact['type'] ?? '';
                                                        $lastComm['result'] = $contact['result'] ?? '';
                                                        $lastComm['contact_date'] = $contact['contact_date'] ?? '';
                                                    } else {
                                                        // كحل احتياطي، لا نقوم باستدعاء قاعدة البيانات هنا لتجنّب نتائج غير متوقعة خارج نطاق الفلاتر
                                                        $lastComm = null;
                                                    }

                                                    if ($lastComm): ?>
                                                        <div class="bg-gray-50 dark:bg-gray-100 p-1.5 rounded-md space-y-1 text-xs">
                                                            <div class="flex items-center justify-center">
                                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-md <?php echo getTypeBadgeClass($lastComm['type']); ?>">
                                                                    <i class="fas fa-phone-alt ml-1"></i>
                                                                    <?php echo getTypeLabel($lastComm['type']); ?>
                                                                </span>
                                                            </div>
                                                            <div class="flex items-center justify-center">
                                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-md <?php echo getResultBadgeClass($lastComm['result']); ?>">
                                                                    <i class="fas fa-check-circle ml-1"></i>
                                                                    <?php echo getResultLabel($lastComm['result']); ?>
                                                                </span>
                                                            </div>
                                                            <div class="text-gray-500 dark:text-gray-50 text-center">
                                                                <i class="fas fa-calendar ml-1"></i>
                                                                <?php echo safeFormatDateTime12Hour($lastComm['contact_date']); ?>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="inline-flex items-center justify-center px-2 py-1 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-xs">
                                                            <i class="fas fa-minus-circle ml-1"></i> لا يوجد تواصل
                                                        </div>
                                                    <?php endif; ?>
                                    <?php else: ?>
                                        <div
                                            class="inline-flex items-center justify-center px-2 py-1 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-xs">
                                            <i class="fas fa-user-slash ml-1"></i> غير مسند
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- الإجراءات -->
                                <td class="px-2 py-2 text-center">
                                    <div class="flex items-center justify-center space-x-1 space-x-reverse">
                                        <?php // عرض التفاصيل محذوفة - لا نعرض أيقونة العين بعد الآن ?>

                                        <button type="button"
                                            class="view-trainee-btn text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 transition-colors p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20"
                                            data-trainee-id="<?php echo htmlspecialchars($contact['trainee_id'] ?? ''); ?>"
                                            title="عرض التفاصيل">
                                            <i class="fas fa-eye"></i>
                                        </button>


                                        <?php if (isset($contact['trainee_id'])): ?>
                                            <button type="button"
                                                onclick="contactTrainee('<?php echo addslashes($contact['trainee_id'] ?? ''); ?>')"
                                                class="inline-flex items-center justify-center p-1.5 rounded-md bg-green-100 hover:bg-green-200 dark:bg-green-900/30 dark:hover:bg-green-800/50 text-green-600 dark:text-green-400 transition-colors"
                                                title="تواصل مع المتدرب">
                                                <i class="fas fa-phone-alt"></i>
                                            </button>
                                        <?php endif; ?>


                                        <button type="button"
                                            class="communications-btn text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 transition-colors p-2 rounded-lg hover:bg-indigo-50 dark:hover:bg-indigo-900/20"
                                            data-trainee-id="<?php echo htmlspecialchars($contact['trainee_id'] ?? ''); ?>"
                                            data-trainee-name="<?php echo htmlspecialchars($contact['trainee_name'] ?? ''); ?>"
                                            title="سجل التواصل">
                                            <i class="fas fa-history"></i>
                                        </button>

                                        <?php if (isset($contact['id']) && is_numeric($contact['id']) && function_exists('hasAccess') && (hasAccess('communication', 'delete') || isAdmin())): ?>
                                            <a href="delete.php?id=<?php echo urlencode($contact['id']); ?>"
                                                onclick="return confirm('هل أنت متأكد من حذف هذا السجل؟');"
                                                class="inline-flex items-center justify-center p-1.5 rounded-md bg-red-100 hover:bg-red-200 dark:bg-red-900/30 dark:hover:bg-red-800/50 text-red-600 dark:text-red-400 transition-colors"
                                                title="حذف">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>



<div id="communicationsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div
        class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-hidden flex flex-col">
        <div class="flex justify-between items-center p-6 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                <i class="fas fa-history ml-2"></i> سجل التواصل
            </h3>
            <button id="closeCommunicationsModal"
                class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto custom-scrollbar flex-1 min-h-0" id="communicationsContent">
            <!-- محتوى سجل التواصل سيتم تحميله هنا -->
        </div>
    </div>
</div>


<!-- Trainee Details Modal (lightweight reuse) -->
<div id="traineeModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div
        class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-hidden flex flex-col">
        <div class="flex justify-between items-center p-6 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                <i class="fas fa-user-graduate ml-2"></i> تفاصيل المتدرب
            </h3>
            <button id="closeTraineeModal"
                class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto custom-scrollbar flex-1 min-h-0" id="traineeModalContent">
            <!-- يتم تحميل التفاصيل هنا -->
        </div>
    </div>
</div>


<script>
    // تهيئة المتغيرات العامة
    let currentModal = null;
    let isLoading = false;
    let modalAnimationFrame = null;

    // دالة التواصل مع المتدرب
    function contactTrainee(traineeId) {
        // فتح نافذة إضافة تواصل جديد مع المتدرب
        window.location.href = 'add.php?trainee_id=' + encodeURIComponent(traineeId);
    }

    // دالة فتح النافذة المنبثقة مع تأثيرات حركية
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        const modalBox = document.getElementById(modalId + 'Box');

        if (modal && modalBox) {
            // إيقاف أي تأثيرات حركية سابقة
            if (modalAnimationFrame) {
                cancelAnimationFrame(modalAnimationFrame);
            }

            // إظهار الخلفية أولاً
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            currentModal = modalId;

            // تأخير قصير قبل بدء التأثيرات الحركية
            requestAnimationFrame(() => {
                modalBox.classList.remove('scale-95', 'opacity-0');
                modalBox.classList.add('scale-100', 'opacity-100');
            });

            // تحريك التركيز وتفعيل لوحة المفاتيح
            const firstInput = modalBox.querySelector('input, button, select, textarea');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 300);
            }

            // إضافة تأثير دخول متموج للمحتوى
            const elements = modalBox.querySelectorAll('.animate-entry');
            const isDarkMode = document.documentElement.classList.contains('dark');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                // only apply translate animation when not in dark mode (user prefers no hover/motion in dark)
                if (!isDarkMode) el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = isDarkMode ? 'opacity 0.25s ease-out' : 'all 0.5s ease-out';
                    el.style.opacity = '1';
                    if (!isDarkMode) el.style.transform = 'translateY(0)';
                }, 100 + (index * 100));
            });
        }
    }




    // دالة إغلاق النافذة المنبثقة مع تأثيرات حركية
    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        const modalBox = document.getElementById(modalId + 'Box');

        if (modal && modalBox) {
            // إضافة تأثيرات الخروج
            modalBox.classList.add('scale-95', 'opacity-0');
            modalBox.classList.remove('scale-100', 'opacity-100');

            // إخفاء النافذة بعد انتهاء التأثير
            setTimeout(() => {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
                currentModal = null;

                // إعادة تعيين حالة المحتوى
                const elements = modalBox.querySelectorAll('.animate-entry');
                elements.forEach(el => {
                    el.style.opacity = '';
                    el.style.transform = '';
                    el.style.transition = '';
                });
            }, 300);
        }
    }

    // دالة إظهار مؤشر التحميل
    function showLoading() {
        if (!isLoading) {
            isLoading = true;
            document.getElementById('loadingOverlay').classList.remove('hidden');
        }
    }

    // دالة إخفاء مؤشر التحميل
    function hideLoading() {
        if (isLoading) {
            isLoading = false;
            document.getElementById('loadingOverlay').classList.add('hidden');
        }
    }

    // دالة عرض رسالة خطأ في النافذة المنبثقة
    function showModalError(modalId, message) {
        const contentDiv = document.getElementById(`${modalId}Content`);
        if (contentDiv) {
            contentDiv.innerHTML = `
            <div class="p-4 text-center">
                <div class="text-red-500 mb-2">
                    <i class="fas fa-exclamation-circle text-3xl"></i>
                </div>
                <div class="text-red-600 font-semibold">${message}</div>
                <button onclick="closeModal('${modalId}')" class="mt-4 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                    إغلاق
                </button>
            </div>
        `;
        }
    }

    // Safe wrapper for openViewModal: validate id is numeric and show friendly error if not
    function openViewModalSafe(id) {
        // allow numeric ids or placeholder ids like new_<number>
        const numericRe = /^\d+$/;
        const placeholderRe = /^new_\d+$/;
        if (!id || !(numericRe.test(id) || placeholderRe.test(id))) {
            openModal('viewModal');
            const contentDiv = document.getElementById('viewModalContent');
            if (contentDiv) {
                contentDiv.innerHTML = '<div class="p-6 text-center"><div class="text-red-600 font-semibold">معرف التواصل غير صالح</div><div class="mt-4"><button onclick="closeModal(\'viewModal\')" class="px-4 py-2 bg-gray-200 rounded">إغلاق</button></div></div>';
            }
            return;
        }
        // otherwise proceed to server which handles both numeric and placeholder ids
        openViewModal(id);
    }

    // دالة فتح نافذة عرض التفاصيل مع تأثيرات حركية
    async function openViewModal(id) {
        try {
            openModal('viewModal');
            const contentDiv = document.getElementById('viewModalContent');
            contentDiv.innerHTML = `
            <div class="flex justify-center items-center p-8">
                <div class="relative">
                    <div class="animate-spin rounded-full h-16 w-16 border-4 border-blue-200 border-t-blue-500"></div>
                    <div class="absolute top-0 left-0 right-0 bottom-0 flex items-center justify-center">
                        <div class="animate-pulse">
                            <i class="fas fa-file-alt text-blue-500 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        `;

            const response = await fetch(`view.php?id=${id}&modal=true`);
            if (!response.ok) throw new Error('فشل تحميل البيانات');

            const html = await response.text();

            // إضافة تأثير التلاشي عند تحميل المحتوى
            contentDiv.style.opacity = '0';
            contentDiv.innerHTML = html;

            // تطبيق classes للتأثيرات الحركية على العناصر
            const sections = contentDiv.querySelectorAll('.modal-section');
            sections.forEach((section, index) => {
                section.classList.add('animate-entry');
                section.style.transitionDelay = `${index * 100}ms`;
            });

            requestAnimationFrame(() => {
                contentDiv.style.transition = 'opacity 0.3s ease-in-out';
                contentDiv.style.opacity = '1';
            });

        } catch (error) {
            console.error('Error:', error);
            showModalError('viewModal', 'حدث خطأ أثناء تحميل البيانات');
        }
    }

    // دالة فتح نافذة التعديل مع تأثيرات حركية
    async function openEditModal(id) {
        try {
            openModal('editModal');
            const contentDiv = document.getElementById('editModalContent');
            contentDiv.innerHTML = `
            <div class="flex justify-center items-center p-8">
                <div class="relative">
                    <div class="animate-spin rounded-full h-16 w-16 border-4 border-green-200 border-t-green-500"></div>
                    <div class="absolute top-0 left-0 right-0 bottom-0 flex items-center justify-center">
                        <div class="animate-pulse">
                            <i class="fas fa-edit text-green-500 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        `;

            const response = await fetch(`edit.php?id=${id}&modal=true`);
            if (!response.ok) throw new Error('فشل تحميل البيانات');

            const html = await response.text();

            // إضافة تأثير التلاشي عند تحميل المحتوى
            contentDiv.style.opacity = '0';
            contentDiv.innerHTML = html;

            // تطبيق classes للتأثيرات الحركية على العناصر
            const formElements = contentDiv.querySelectorAll('.form-group');
            formElements.forEach((element, index) => {
                element.classList.add('animate-entry');
                element.style.transitionDelay = `${index * 100}ms`;
            });

            requestAnimationFrame(() => {
                contentDiv.style.transition = 'opacity 0.3s ease-in-out';
                contentDiv.style.opacity = '1';
            });

            initializeModalForm();
        } catch (error) {
            console.error('Error:', error);
            showModalError('editModal', 'حدث خطأ أثناء تحميل البيانات');
        }
    }

    // دالة تهيئة نموذج التعديل
    function initializeModalForm() {
        const form = document.querySelector('#editModalContent form');
        if (form) {
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                showLoading();

                try {
                    const formData = new FormData(form);
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();
                    if (data.success) {
                        closeModal('editModal');
                        window.location.reload();
                    } else {
                        throw new Error(data.message || 'حدث خطأ أثناء حفظ البيانات');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert(error.message || 'حدث خطأ أثناء حفظ البيانات');
                } finally {
                    hideLoading();
                }
            });
        }
    }

    // إضافة مستمعي الأحداث عند تحميل الصفحة
    document.addEventListener('DOMContentLoaded', function () {
        // إغلاق النافذة عند النقر خارجها
        document.addEventListener('click', function (e) {
            if (currentModal) {
                const modal = document.getElementById(currentModal);
                const modalContent = modal.querySelector('.relative');
                if (e.target === modal && !modalContent.contains(e.target)) {
                    closeModal(currentModal);
                }
            }
        });

        // إغلاق النافذة بمفتاح Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && currentModal) {
                closeModal(currentModal);
            }
        });
    });

    // Submit the filters form when assigned_to changes so assigned trainees are shown quickly
    document.addEventListener('DOMContentLoaded', function () {
        const assignedSelect = document.querySelector('select[name="assigned_to"]');
        if (assignedSelect) {
            assignedSelect.addEventListener('change', function () {
                // submit the nearest form
                const form = assignedSelect.closest('form');
                if (form) form.submit();
            });
        }
    });

    // دوال التصدير
    function exportToExcel() {
        const params = new URLSearchParams(window.location.search);
        params.set('format', 'excel');
        window.location.href = 'export.php?' + params.toString();
    }

    function exportToPDF() {
        const params = new URLSearchParams(window.location.search);
        params.set('format', 'pdf');
        window.location.href = 'export.php?' + params.toString();
    }

    // --------- Trainee details & communications handlers ---------
    // Compute APP_BASE similar to other modules
    const APP_BASE = (function () {
        try {
            const p = window.location.pathname || '';
            const idx = p.indexOf('/modules/');
            if (idx !== -1) return p.substring(0, idx);
            return '';
        } catch (e) {
            return '';
        }
    })();
    // CommunicationsModal class (copied from trainees list) so this page can open communications in-page
    class CommunicationsModal {
        constructor() {
            this.modal = document.getElementById('communicationsModal');
            this.modalContent = document.getElementById('communicationsContent');
            this.closeBtn = document.getElementById('closeCommunicationsModal');
            this.currentTraineeId = null;

            this.init();
        }

        init() {
            if (!this.modal) return;
            // Add event listeners
            if (this.closeBtn) this.closeBtn.addEventListener('click', () => this.hide());

            // Close when clicking outside content
            this.modal.addEventListener('click', (e) => {
                if (e.target === this.modal) {
                    this.hide();
                }
            });

            // Close on ESC
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !this.modal.classList.contains('hidden')) {
                    this.hide();
                }
            });
        }

        show(traineeId, communicationId = null) {
            if (!this.modal) return;
            if (communicationId) {
                this.loadCommunicationDetails(communicationId);
            } else {
                this.loadCommunicationsHistory(traineeId);
            }
            this.modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        hide() {
            if (!this.modal) return;
            this.modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        async loadCommunicationDetails(communicationId) {
            if (!this.modalContent) return;
            this.modalContent.innerHTML = `
            <div class="flex justify-center items-center py-12">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
                <span class="mr-3 text-gray-600 dark:text-gray-400">جاري تحميل تفاصيل التواصل...</span>
            </div>
        `;

            try {
                const response = await fetch(`${APP_BASE}/modules/communications/view.php?id=${communicationId}&modal=true`, { credentials: 'include' });

                if (!response.ok) {
                    if (response.status === 401) {
                        this.showError('غير مصرح بالوصول. ربما انتهت صلاحية الجلسة. الرجاء إعادة تسجيل الدخول.');
                        console.error('API returned 401 for communication details');
                        return;
                    }
                    throw new Error(`خطأ في الخادم: ${response.status}`);
                }

                const html = await response.text();
                this.modalContent.innerHTML = html;

                // execute any scripts inside loaded content
                this.executeScripts(this.modalContent);

            } catch (error) {
                console.error('Error loading communication details:', error);
                this.showError('حدث خطأ في تحميل التفاصيل: ' + error.message);
            }
        }

        async loadCommunicationsHistory(traineeId) {
            this.currentTraineeId = traineeId;
            this.showLoading();

            try {
                const response = await fetch(`${APP_BASE}/modules/communications/history.php?trainee_id=${traineeId}&modal=true`, {
                    credentials: 'include'
                });

                if (!response.ok) {
                    if (response.status === 401) {
                        this.showError('غير مصرح بالوصول. قد تكون جلستك قد انتهت. الرجاء تسجيل الدخول وإعادة المحاولة.');
                        console.error('Page returned 401 when loading communications history');
                        return;
                    }
                    throw new Error(`خطأ في الخادم: ${response.status}`);
                }

                const html = await response.text();
                this.modalContent.innerHTML = html;

            } catch (error) {
                console.error('Error loading communications history:', error);
                this.showError('حدث خطأ في الاتصال بالخادم: ' + error.message);
            }
        }

        showLoading() {
            if (!this.modalContent) return;
            this.modalContent.innerHTML = `
                <div class="flex justify-center items-center py-12">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
                    <span class="mr-3 text-gray-600 dark:text-gray-400">جاري تحميل البيانات...</span>
                </div>
            `;
        }

        showError(message, detail = '') {
            if (!this.modalContent) return;
            this.modalContent.innerHTML = `
            <div class="text-center py-12">
                <i class="fas fa-exclamation-triangle text-4xl text-red-500 mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">خطأ في تحميل البيانات</h3>
                <p class="text-gray-600 dark:text-gray-400 mb-4">${message}</p>
                ${detail ? `<p class="text-sm text-gray-500 dark:text-gray-400 mb-4">${detail}</p>` : ''}
                <button type="button" onclick="communicationsModal.hide()" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    <i class="fas fa-times ml-2"></i> إغلاق
                </button>
                <button type="button" onclick="communicationsModal.loadCommunicationsHistory(${this.currentTraineeId})" class="px-6 py-2 mr-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                    <i class="fas fa-sync-alt ml-2"></i> إعادة المحاولة
                </button>
            </div>
        `;
        }

        // execute inline scripts inside loaded HTML
        executeScripts(container) {
            const scripts = container.querySelectorAll('script');
            scripts.forEach(s => {
                try {
                    const newScript = document.createElement('script');
                    if (s.src) {
                        newScript.src = s.src;
                    } else {
                        newScript.textContent = s.textContent;
                    }
                    document.body.appendChild(newScript);
                    // remove immediately after execution
                    document.body.removeChild(newScript);
                } catch (e) {
                    console.error('Failed to execute script from loaded content', e);
                }
            });
        }
    }

    // Simple trainee modal controls
    document.addEventListener('DOMContentLoaded', function () {
        // Instantiate communications modal so the page always opens history in a modal
        try {
            window.communicationsModal = new CommunicationsModal();
        } catch (e) {
            console.error('Failed to initialize CommunicationsModal:', e);
        }
        const traineeModal = document.getElementById('traineeModal');
        const traineeModalContent = document.getElementById('traineeModalContent');
        const closeTraineeBtn = document.getElementById('closeTraineeModal');

        function showTraineeModal() {
            traineeModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function hideTraineeModal() {
            traineeModal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        if (closeTraineeBtn) closeTraineeBtn.addEventListener('click', hideTraineeModal);
        if (traineeModal) {
            traineeModal.addEventListener('click', function (e) {
                if (e.target === traineeModal) hideTraineeModal();
            });
        }

        // View trainee button
        document.querySelectorAll('.view-trainee-btn').forEach(btn => {
            btn.addEventListener('click', async function () {
                const traineeId = this.getAttribute('data-trainee-id');
                if (!traineeId) return;

                traineeModalContent.innerHTML = `
                    <div class="flex justify-center items-center py-12">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
                        <span class="mr-3 text-gray-600 dark:text-gray-400">جاري تحميل البيانات...</span>
                    </div>
                `;
                showTraineeModal();

                try {
                    const resp = await fetch(`${APP_BASE}/api/trainees/get-details.php?id=${encodeURIComponent(traineeId)}`, { credentials: 'include' });
                    if (!resp.ok) throw new Error('فشل تحميل تفاصيل المتدرب');
                    const data = await resp.json();
                    if (!data.success || !data.trainee) {
                        traineeModalContent.innerHTML = `<div class="p-6 text-center text-red-600">${data.message || 'تعذر جلب بيانات المتدرب'}</div>`;
                        return;
                    }

                    // Render full trainee details using fields returned by API
                    const t = data.trainee;
                    // helper to render a small list or fallback
                    const renderList = (items, emptyText) => {
                        if (!items || items.length === 0) return `<div class="text-sm text-gray-500">${escapeHtml(emptyText)}</div>`;
                        return `<div class="space-y-3">${items.map(i => `<div class="p-3 bg-white/50 dark:bg-gray-700 rounded-lg border border-gray-100 dark:border-gray-700 text-sm"><div class="flex justify-between"><div class="font-medium">${escapeHtml(i.title || i.payment_method || i.type || i.created_by_name || '')}</div><div class="text-xs text-gray-500">${escapeHtml(i.payment_date || i.contact_date || i.assigned_at || '')}</div></div>${i.amount ? `<div class="text-sm text-gray-700">المبلغ: ${escapeHtml(String(i.amount))} ر.س</div>` : ''}${i.notes ? `<div class="mt-2 text-sm text-gray-600">${escapeHtml(i.notes)}</div>` : ''}</div>`).join('')}</div>`;
                    };

                    traineeModalContent.innerHTML = `
                        <div class="space-y-6">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <div class="w-20 h-20 rounded-full bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center text-white text-3xl font-bold">${escapeHtml((t.full_name || '?').charAt(0))}</div>
                                    <div>
                                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">${escapeHtml(t.full_name || 'غير محدد')}</h2>
                                        <div class="text-sm text-gray-600 dark:text-gray-400">الهوية: ${escapeHtml(t.national_id || '-')}</div>
                                        <div class="text-sm text-gray-600 dark:text-gray-400">الدبلوم: ${escapeHtml(t.diploma_name || '-')}</div>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                   
                                    <button id="addCommBtn" class="px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700">إضافة تواصل</button>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                                <div class="lg:col-span-2 space-y-4">
                                    <div class="bg-white dark:bg-gray-700 rounded-lg p-4 border border-gray-100 dark:border-gray-700">
                                        <h4 class="font-semibold text-gray-800 dark:text-white">معلومات التواصل</h4>
                                        <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                                            <div>الهاتف: ${escapeHtml(t.phone || '-')}</div>
                                            <div>الجوال: ${escapeHtml(t.mobile || '-')}</div>
                                            <div>البريد: ${escapeHtml(t.email || '-')}</div>
                                            <div>مسند لـ: ${escapeHtml(t.assigned_to_name || 'غير مسند')}</div>
                                            <div class="mt-2">آخر تواصل: ${escapeHtml(t.last_contact || 'لم يتم التواصل')}</div>
                                        </div>
                                    </div>

                                  
                                   
                                <div class="space-y-4">
                                    <div class="bg-white dark:bg-gray-700 rounded-lg p-4 border border-gray-100 dark:border-gray-700">
                                        <h4 class="font-semibold text-gray-800 dark:text-white">ملخص مالي</h4>
                                        <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">المبلغ الإجمالي: ${escapeHtml((t.total_amount || 0).toString())} ر.س<br>المدفوع: ${escapeHtml((t.paid_amount || 0).toString())} ر.س<br>المتبقي: ${escapeHtml((t.remaining_amount || t.remaining_fees || 0).toString())} ر.س</div>
                                    </div>

                                    <div class="bg-white dark:bg-gray-700 rounded-lg p-4 border border-gray-100 dark:border-gray-700">
                                        <h4 class="font-semibold text-gray-800 dark:text-white">سجل الإسناد</h4>
                                        <div class="mt-3">
                                            ${renderList(t.assignment_history, 'لا توجد سجلات إسناد')}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white dark:bg-gray-700 rounded-lg p-4 border border-gray-100 dark:border-gray-700">
                                <h4 class="font-semibold text-gray-800 dark:text-white">ملاحظات</h4>
                                <div class="mt-2 text-sm text-gray-700 dark:text-gray-300">${escapeHtml(t.notes || 'لا توجد ملاحظات')}</div>
                            </div>
                        </div>
                    `;

                    // wire buttons inside modal
                    const openFullCommsBtn = document.getElementById('openFullCommsBtn');
                    const addCommBtn = document.getElementById('addCommBtn');
                    if (openFullCommsBtn) {
                        openFullCommsBtn.addEventListener('click', function () {
                            try {
                                if (typeof communicationsModal !== 'undefined' && communicationsModal && typeof communicationsModal.show === 'function') {
                                    communicationsModal.show(t.id);
                                } else {
                                    window.open(`${APP_BASE}/modules/communications/history.php?trainee_id=${encodeURIComponent(t.id)}`, '_blank', 'width=900,height=700');
                                }
                            } catch (e) { console.error(e); }
                        });
                    }
                    if (addCommBtn) {
                        addCommBtn.addEventListener('click', function () {
                            window.location.href = `${APP_BASE}/modules/communications/add.php?trainee_id=${encodeURIComponent(t.id)}`;
                        });
                    }

                    // delegate communication detail buttons inside modal
                    traineeModalContent.querySelectorAll('.open-comm-details-btn').forEach(b => {
                        b.addEventListener('click', function () {
                            const commId = this.getAttribute('data-comm-id');
                            if (!commId) return;
                            try {
                                if (typeof communicationsModal !== 'undefined' && communicationsModal && typeof communicationsModal.show === 'function') {
                                    communicationsModal.show(null, commId);
                                } else {
                                    window.open(`${APP_BASE}/modules/communications/view.php?id=${encodeURIComponent(commId)}`, '_blank', 'width=800,height=600');
                                }
                            } catch (e) { console.error(e); }
                        });
                    });

                } catch (err) {
                    console.error('Error loading trainee details:', err);
                    traineeModalContent.innerHTML = `<div class="p-6 text-center text-red-600">حدث خطأ أثناء تحميل البيانات</div>`;
                }
            });
        });

        // Communications history button (reuse existing communicationsModal)
        document.querySelectorAll('.communications-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const traineeId = this.getAttribute('data-trainee-id');
                if (!traineeId) return;
                // Try to use the communications modal logic present on the page
                try {
                    if (typeof communicationsModal !== 'undefined' && communicationsModal && typeof communicationsModal.show === 'function') {
                        communicationsModal.show(traineeId);
                    } else {
                        // fallback: open history.php in a centered window
                        const url = `${APP_BASE}/modules/communications/history.php?trainee_id=${encodeURIComponent(traineeId)}`;
                        window.open(url, '_blank', 'toolbar=0,location=0,menubar=0,width=900,height=700');
                    }
                } catch (e) {
                    console.error('Error opening communications history:', e);
                }
            });
        });

        // simple HTML escape
        function escapeHtml(s) {
            if (!s && s !== 0) return '';
            return String(s).replace(/[&<>\"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
    });
</script>

<?php

function getTypeLabel($type)
{
    $types = [
        'call' => 'اتصال',
        'whatsapp' => 'واتساب',
        'email' => 'بريد إلكتروني',
        'meeting' => 'اجتماع',
        'payment' => 'دفعة مالية',
        'visit' => 'زيارة',
        'sms' => 'رسالة نصية',
        'phone' => 'هاتف'
    ];
    return $types[$type] ?? 'تواصل';
}

function getTypeBadgeClass($type)
{
    $classes = [
        'call' => 'px-1.5 py-0.5 rounded-md bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        'whatsapp' => 'px-1.5 py-0.5 rounded-md bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        'email' => 'px-1.5 py-0.5 rounded-md bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
        'meeting' => 'px-1.5 py-0.5 rounded-md bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
        'payment' => 'px-1.5 py-0.5 rounded-md bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400',
        'visit' => 'px-1.5 py-0.5 rounded-md bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
        'sms' => 'px-1.5 py-0.5 rounded-md bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        'phone' => 'px-1.5 py-0.5 rounded-md bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
    ];
    return $classes[$type] ?? 'px-1.5 py-0.5 rounded-md bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
}

// ترجمة نتائج التواصل إلى العربية
function getResultLabel($result)
{
    $results = [
        'promise' => 'وعد',
        'refused' => 'رفض',
        'paid' => 'تم السداد',
        'no_answer' => 'لم يرد',
        'follow_up' => 'متابعة',
        'not_interested' => 'غير مهتم',
        'other' => 'أخرى'
    ];
    // support common alternative keys and Arabic literal values
    $aliases = [
        'call_later' => 'follow_up',
        'contact_later' => 'follow_up',
        'call_later_request' => 'follow_up',
        'interested' => 'promise',
        'مهتم' => 'promise',
        'طلب الاتصال لاحقا' => 'follow_up',
        'طلب الاتصال لاحقاً' => 'follow_up',
        'اتصل لاحقاً' => 'follow_up',
        'اتصل لاحقا' => 'follow_up',
        'غير مهتم' => 'not_interested'
    ];

    if ($result === null || $result === '') return 'غير محدد';

    // direct match
    if (isset($results[$result])) return $results[$result];

    // alias mapping
    if (isset($aliases[$result])) {
        $key = $aliases[$result];
        if (isset($results[$key])) return $results[$key];
    }

    // try case-insensitive check and trim
    $r = mb_strtolower(trim((string)$result));
    foreach ($results as $k => $v) {
        if (mb_strtolower($k) === $r) return $v;
    }
    foreach ($aliases as $k => $mapped) {
        if (mb_strtolower($k) === $r && isset($results[$mapped])) return $results[$mapped];
    }

    return 'غير محدد';
}

function getResultBadgeClass($result)
{
    $classes = [
        'promise' => 'px-1.5 py-0.5 rounded-md bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        'refused' => 'px-1.5 py-0.5 rounded-md bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        'paid' => 'px-1.5 py-0.5 rounded-md bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        'no_answer' => 'px-1.5 py-0.5 rounded-md bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
        'follow_up' => 'px-1.5 py-0.5 rounded-md bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400',
        'not_interested' => 'px-1.5 py-0.5 rounded-md bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        'other' => 'px-1.5 py-0.5 rounded-md bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400'
    ];
    // allow aliases similar to getResultLabel
    $aliases = [
        'call_later' => 'follow_up',
        'contact_later' => 'follow_up',
        'interested' => 'promise',
        'مهتم' => 'promise',
        'طلب الاتصال لاحقا' => 'follow_up',
        'طلب الاتصال لاحقاً' => 'follow_up',
        'اتصل لاحقاً' => 'follow_up',
        'اتصل لاحقا' => 'follow_up',
        'غير مهتم' => 'not_interested'
    ];

    if (isset($classes[$result])) return $classes[$result];
    if (isset($aliases[$result]) && isset($classes[$aliases[$result]])) return $classes[$aliases[$result]];

    $r = mb_strtolower(trim((string)$result));
    foreach ($classes as $k => $v) {
        if (mb_strtolower($k) === $r) return $v;
    }
    foreach ($aliases as $k => $mapped) {
        if (mb_strtolower($k) === $r && isset($classes[$mapped])) return $classes[$mapped];
    }

    return 'px-1.5 py-0.5 rounded-md bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
}
?>

<?php include '../../includes/tw_layout_end.php'; ?>
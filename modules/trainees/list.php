<?php
/**
 * صفحة قائمة المتدربين - Trainees List Page
 * إصدار محسن مع إصلاح مشكلة عرض الدبلومات
 */

require_once '../../config.php';
if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/BaseModel.php';
require_once '../../models/Trainee.php';

// التحقق من صلاحيات الوصول
if (!hasAccess('trainees', 'view')) {
    header('HTTP/1.0 403 Forbidden');
    die('ليس لديك صلاحية الوصول إلى هذه الصفحة');
}

// تهيئة المتغيرات
$trainees = [];
$error = null;
$success = null;
$totalTrainees = 0;
$totalPages = 1;
$page = 1;
$diplomas = [];
$branches = [];
$uniqueDates = [];
$lastCommunications = [];
$employees = [];

// التحقق من وجود رابط إعادة توجيه في الجلسة
$redirectUrl = '';
if (isset($_SESSION['redirect_after_load'])) {
    $redirectUrl = $_SESSION['redirect_after_load'];
    unset($_SESSION['redirect_after_load']);
}

// التحقق من وجود رسالة نجاح في الجلسة
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

// معالجة إسناد المتدربين
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_trainees'])) {
    if (!hasAccess('trainees', 'assign')) {
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'خطأ في الصلاحية',
            'message' => 'ليس لديك صلاحية إسناد المتدربين',
            'icon' => 'error'
        ];
    } else {
        try {
            $db = (new Database())->getConnection();

            // Normalize selected trainees: accept array or comma-separated string
            $selectedTraineesRaw = $_POST['selected_trainees'] ?? [];
            if (!is_array($selectedTraineesRaw)) {
                if (is_string($selectedTraineesRaw) && strpos($selectedTraineesRaw, ',') !== false) {
                    $selectedTrainees = array_filter(array_map('trim', explode(',', $selectedTraineesRaw)), function ($v) {
                        return $v !== '';
                    });
                } elseif ($selectedTraineesRaw === '') {
                    $selectedTrainees = [];
                } else {
                    $selectedTrainees = [$selectedTraineesRaw];
                }
            } else {
                $selectedTrainees = $selectedTraineesRaw;
            }

            // sanitize ids
            $selectedTrainees = array_values(array_filter(array_map('intval', $selectedTrainees), function ($v) {
                return $v > 0;
            }));

            $assignedTo = intval($_POST['assigned_to'] ?? 0);

            if (empty($selectedTrainees)) {
                // Debug log to help investigate client-side submission issues
                try {
                    error_log('Assign trainees called but no selected trainees received. Raw POST keys: ' . json_encode(array_keys($_POST)) . ' | selected_trainees raw: ' . json_encode($selectedTraineesRaw));
                } catch (Exception $e) {
                    // noop - don't break flow if logging fails
                }
                $_SESSION['alert'] = [
                    'type' => 'warning',
                    'title' => 'تحذير',
                    'message' => 'لم يتم اختيار أي متدربين للإسناد',
                    'icon' => 'warning'
                ];
            } elseif (empty($assignedTo)) {
                $_SESSION['alert'] = [
                    'type' => 'warning',
                    'title' => 'تحذير',
                    'message' => 'لم يتم اختيار موظف للإسناد',
                    'icon' => 'warning'
                ];
            } else {
                // Use transaction to ensure consistency
                $db->beginTransaction();
                $successCount = 0;
                $errorCount = 0;
                $traineeNames = [];
                try {
                    $nameStmt = $db->prepare("SELECT full_name FROM trainees WHERE id = ?");
                    $checkStmt = $db->prepare("SELECT assigned_to FROM trainees WHERE id = ?");
                    $updateStmt = $db->prepare("UPDATE trainees SET assigned_to = ?, updated_at = NOW() WHERE id = ?");

                    // Prepare assignment_logs statement only if the table exists in this schema.
                    $hasAssignmentLogs = false;
                    try {
                        $hasAssignmentLogs = $db->query("SHOW TABLES LIKE 'assignment_logs'")->rowCount() > 0;
                    } catch (Exception $e) {
                        $hasAssignmentLogs = false;
                    }

                    if ($hasAssignmentLogs) {
                        try {
                            $logStmt = $db->prepare("INSERT INTO assignment_logs (trainee_id, assigned_to, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
                        } catch (Exception $e) {
                            // If prepare fails for any reason, disable logging to avoid breaking assignment flow
                            error_log('Failed to prepare assignment_logs statement: ' . $e->getMessage());
                            $logStmt = null;
                            $hasAssignmentLogs = false;
                        }
                    } else {
                        $logStmt = null;
                    }

                    foreach ($selectedTrainees as $traineeId) {
                        try {
                            // get trainee name (optional)
                            $nameStmt->execute([$traineeId]);
                            $traineeName = $nameStmt->fetch(PDO::FETCH_COLUMN);
                            $traineeNames[] = $traineeName ?: "#{$traineeId}";

                            $checkStmt->execute([$traineeId]);
                            $currentAssignment = $checkStmt->fetch(PDO::FETCH_ASSOC);

                            if (!isset($currentAssignment['assigned_to']) || $currentAssignment['assigned_to'] != $assignedTo) {
                                $updateStmt->execute([$assignedTo, $traineeId]);
                            }

                            if ($hasAssignmentLogs && $logStmt) {
                                try {
                                    $logStmt->execute([$traineeId, $assignedTo, $_SESSION['user_id']]);
                                } catch (Exception $e) {
                                    // don't fail the whole loop if logging fails; just record and continue
                                    error_log("assignment_logs insert failed for trainee $traineeId: " . $e->getMessage());
                                }
                            }
                            $successCount++;
                        } catch (Exception $e) {
                            $errorCount++;
                            error_log("Error assigning trainee $traineeId: " . $e->getMessage());
                            // continue to next trainee
                        }
                    }

                    // commit transaction
                    $db->commit();

                    if ($successCount > 0) {
                        $employeeStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                        $employeeStmt->execute([$assignedTo]);
                        $employeeName = $employeeStmt->fetch(PDO::FETCH_COLUMN) ?: "#{$assignedTo}";

                        $_SESSION['alert'] = [
                            'type' => 'success',
                            'title' => 'تم الإسناد بنجاح',
                            'message' => "تم إسناد $successCount متدرب بنجاح إلى $employeeName",
                            'icon' => 'success',
                            'details' => $traineeNames
                        ];

                        // إنشاء إشعار للموظف الذي تم الإسناد إليه (حتى يرى ذلك في لوحة التحكم)
                        try {
                            require_once __DIR__ . '/../../includes/notifications.php';
                            // Use count-based notification message (do not include individual names)
                            $title = 'تم إسناد ' . $successCount . ' متدربًا لك';
                            $message = 'تم إسناد ' . $successCount . ' متدربًا إليك';
                            $link = rtrim(SITE_URL, '/') . '/modules/trainees/list.php?assigned_to=' . urlencode($assignedTo);
                            // createNotification expects ($db, $userId, $title, $message, $type = 'info', $link = null)
                            createNotification($db, $assignedTo, $title, $message, 'success', $link);
                        } catch (Throwable $e) {
                            error_log('Failed to create assignment notification: ' . $e->getMessage());
                        }

                        // Redirect directly to communications list so user sees assigned trainees immediately
                        $traineesParam = urlencode(implode(',', $selectedTrainees));
                        $commUrl = rtrim(SITE_URL, '/') . '/modules/communications/list.php?assigned_to=' . urlencode($assignedTo) . '&trainees=' . $traineesParam;
                        header('Location: ' . $commUrl);
                        exit;
                    } else {
                        $_SESSION['alert'] = [
                            'type' => 'error',
                            'title' => 'فشل الإسناد',
                            'message' => 'فشل إسناد المتدربين المحددين. الرجاء التحقق من السجلات.',
                            'icon' => 'error'
                        ];
                    }

                } catch (Exception $e) {
                    // rollback on major failure
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log("Assignment transaction failed: " . $e->getMessage());
                    $_SESSION['alert'] = [
                        'type' => 'error',
                        'title' => 'خطأ في النظام',
                        'message' => 'حدث خطأ أثناء عملية الإسناد. تم إلغاء العملية.',
                        'icon' => 'error'
                    ];
                }

            }
        } catch (Exception $e) {
            $_SESSION['alert'] = [
                'type' => 'error',
                'title' => 'خطأ في النظام',
                'message' => 'حدث خطأ أثناء عملية الإسناد: ' . $e->getMessage(),
                'icon' => 'error'
            ];
        }

        // إعادة التوجيه لتجنب إعادة إرسال النموذج
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}


// التحقق من وجود رسالة تنبيه في الجلسة
if (isset($_SESSION['alert'])) {
    $alert = $_SESSION['alert'];
    unset($_SESSION['alert']);
}

try {
    // التحقق من الصلاحيات
    checkPermission('employee');

    // إنشاء اتصال بقاعدة البيانات
    $db = (new Database())->getConnection();
    $traineeModel = new Trainee($db);

    // جلب الموظفين - استعلام محسن ومضمون
    try {
        // أولاً: التحقق من هيكل جدول users
        $checkTable = $db->query("SHOW TABLES LIKE 'users'");
        if ($checkTable->rowCount() > 0) {
            // التحقق من الأعمدة المطلوبة في الجدول users
            $checkColumnsUsers = $db->query("SHOW COLUMNS FROM users LIKE 'full_name'");
            if ($checkColumnsUsers->rowCount() === 0) {
                throw new Exception("العمود 'full_name' غير موجود في الجدول users.");
            }

            // Build a safer, more inclusive employees selection query.
            $hasDeletedAtUsers = ($db->query("SHOW COLUMNS FROM users LIKE 'deleted_at'")->rowCount() > 0) ? true : false;

            $whereFragments = [];

            // exclude deleted rows only when column exists
            if ($hasDeletedAtUsers) {
                $whereFragments[] = "u.deleted_at IS NULL";
            }

            $whereSql = '';
            if (!empty($whereFragments)) {
                $whereSql = 'WHERE ' . implode(' AND ', $whereFragments);
            }

            $employeesQuery = "
                SELECT
                    u.id,
                    u.full_name,
                    COALESCE(u.role, '') as role,
                    CASE
                        WHEN u.role = 'admin' THEN 'مدير'
                        WHEN u.role = 'supervisor' THEN 'مشرف'
                        WHEN u.role = 'employee' THEN 'موظف'
                        ELSE 'موظف'
                    END as role_name,
                    COALESCE((SELECT COUNT(*) FROM trainees t WHERE t.assigned_to = u.id), 0) as assigned_trainees_count,
                    COALESCE((SELECT COUNT(*) FROM trainees t WHERE t.assigned_to = u.id AND t.status = 'under_communication'), 0) as communicating_trainees
                FROM users u
                $whereSql
                ORDER BY u.full_name
            ";

            $employeesStmt = $db->prepare($employeesQuery);
            $employeesStmt->execute();
            $employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // إذا لم يتم العثور على موظفين، جرب استعلام أبسط
        if (empty($employees)) {
            $simpleWhereFragments = [];
            $hasDeletedAtUsers = ($db->query("SHOW COLUMNS FROM users LIKE 'deleted_at'")->rowCount() > 0) ? true : false;

            if ($hasDeletedAtUsers) {
                $simpleWhereFragments[] = 'deleted_at IS NULL';
            }

            $simpleWhere = '';
            if (!empty($simpleWhereFragments)) {
                $simpleWhere = 'WHERE ' . implode(' AND ', $simpleWhereFragments);
            }

            $simpleQuery = "SELECT id, full_name, COALESCE(role, '') as role, 'موظف' as role_name,
                COALESCE((SELECT COUNT(*) FROM trainees t WHERE t.assigned_to = users.id),0) as assigned_trainees_count,
                COALESCE((SELECT COUNT(*) FROM trainees t WHERE t.assigned_to = users.id AND t.status='under_communication'),0) as communicating_trainees
                FROM users $simpleWhere ORDER BY full_name";
            $simpleStmt = $db->prepare($simpleQuery);
            $simpleStmt->execute();
            $employees = $simpleStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // إذا لم ينجح أي استعلام، استخدم بيانات تجريبية
        if (empty($employees)) {
            $employees = [
                [
                    'id' => 1,
                    'full_name' => 'مدير النظام',
                    'role' => 'admin',
                    'role_name' => 'مدير',
                    'assigned_trainees_count' => 0,
                    'communicating_trainees' => 0
                ]
            ];
        }

    } catch (Exception $e) {
        error_log("Error fetching employees: " . $e->getMessage());
        $employees = [
            [
                'id' => 1,
                'full_name' => 'مدير النظام',
                'role' => 'admin',
                'role_name' => 'مدير',
                'assigned_trainees_count' => 0,
                'communicating_trainees' => 0
            ]
        ];
    }

    // جلب قائمة الدبلومات للفلتر - استعلام محسن ومضمون
    try {
        $checkDiplomas = $db->query("SHOW TABLES LIKE 'diplomas'");
        if ($checkDiplomas->rowCount() > 0) {
            // استعلام مباشر ومضمون للدبلومات
            $diplomaQuery = "
                SELECT 
                    d.id, 
                    d.name,
                    COUNT(t.id) as trainees_count
                FROM diplomas d 
                LEFT JOIN trainees t ON d.id = t.diploma_id 
                WHERE d.id IS NOT NULL
                GROUP BY d.id, d.name
                ORDER BY d.name
            ";

            $diplomasStmt = $db->prepare($diplomaQuery);
            $diplomasStmt->execute();
            $diplomas = $diplomasStmt->fetchAll(PDO::FETCH_ASSOC);

            // تسجيل الدبلومات المتاحة للتصحيح
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                error_log("Available diplomas for filter: " . count($diplomas));
                foreach ($diplomas as $diploma) {
                    error_log("Diploma ID: " . $diploma['id'] . ", Name: " . $diploma['name'] . ", Trainees: " . $diploma['trainees_count']);
                }
            }
        } else {
            error_log("Diplomas table not found");
            $diplomas = [];
        }
    } catch (Exception $e) {
        error_log("Error fetching diplomas: " . $e->getMessage());
        $diplomas = [];
    }

    // جلب قائمة الفروع للفلتر - استعلام محسن
    try {
        $checkBranches = $db->query("SHOW TABLES LIKE 'branches'");
        if ($checkBranches->rowCount() > 0) {
            // build branch WHERE based on available columns
            $hasDeletedAtBranches = ($db->query("SHOW COLUMNS FROM branches LIKE 'deleted_at'")->rowCount() > 0) ? true : false;
            $hasStatusBranches = ($db->query("SHOW COLUMNS FROM branches LIKE 'status'")->rowCount() > 0) ? true : false;

            $branchWhere = [];
            if ($hasStatusBranches) {
                $branchWhere[] = "(b.status = 'active' OR b.status = 1 OR b.status IS NULL)";
            }
            if ($hasDeletedAtBranches) {
                $branchWhere[] = "b.deleted_at IS NULL";
            }

            $branchWhereSql = '';
            if (!empty($branchWhere)) {
                $branchWhereSql = 'WHERE ' . implode(' AND ', $branchWhere);
            }

            $branchQuery = "
                SELECT 
                    b.id, 
                    b.name,
                    COUNT(t.id) as trainees_count
                FROM branches b 
                LEFT JOIN trainees t ON b.id = t.branch_id 
                $branchWhereSql
                GROUP BY b.id, b.name
                ORDER BY b.name
            ";
            $branchesStmt = $db->prepare($branchQuery);
            $branchesStmt->execute();
            $branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Error fetching branches: " . $e->getMessage());
        $branches = [];
    }

    // تحضير الفلاتر
    $filters = [];

    // دالة مساعدة لتنظيف المدخلات
    function sanitizeFilter($value, $type = 'string')
    {
        if ($value === null || $value === '')
            return null;

        $value = trim($value);
        switch ($type) {
            case 'int':
                return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
            case 'float':
                return (float) filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            case 'string':
            default:
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }

    // معالجة الفلاتر المختلفة
    $filterFields = [
        'status' => ['type' => 'string', 'empty_check' => false],
        'national_id' => ['type' => 'string', 'empty_check' => true],
        'diploma_id' => ['type' => 'int', 'empty_check' => true],
        'branch_id' => ['type' => 'int', 'empty_check' => true],
        'min_remaining' => ['type' => 'float', 'empty_check' => true],
        'max_remaining' => ['type' => 'float', 'empty_check' => true],
        'contact_filter' => ['type' => 'string', 'empty_check' => false],
        'communication_result' => ['type' => 'string', 'empty_check' => true],
        'date_from' => ['type' => 'string', 'empty_check' => true],
        'graduation_date' => ['type' => 'string', 'empty_check' => true],
        'day' => ['type' => 'string', 'empty_check' => true],
        'last_contact_date' => ['type' => 'string', 'empty_check' => true],
        'assigned_to' => ['type' => 'int', 'empty_check' => false]
    ];

    foreach ($filterFields as $field => $config) {
        if (isset($_GET[$field])) {
            $value = $_GET[$field];
            $isEmpty = $config['empty_check'] ? empty($value) : ($value === '');

            if (!$isEmpty) {
                $filters[$field] = sanitizeFilter($value, $config['type']);
            }
        }
    }

    // Special handling: if user provided graduation_date, accept Hijri or Gregorian
    if (!empty($_GET['graduation_date'])) {
        $rawDateInput = trim($_GET['graduation_date']);
        // normalize Arabic-Indic digits
        $arabicNums = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ','];
        $normalized = strtr($rawDateInput, $arabicNums);
        // common separators to '/'
        $normalized = preg_replace('/[\.\-\s\\_]+/', '/', $normalized);
        $normalized = preg_replace('/[^0-9\/]/', '/', $normalized);
        $normalized = preg_replace('/\/{2,}/', '/', $normalized);
        $normalized = trim($normalized, '/');

        $convertedGraduation = null;
        // If it looks like Gregorian yyyy/mm/dd or yyyy-mm-dd or yyyy/mm, accept directly
        if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $normalized) || preg_match('/^\d{4}\-\d{1,2}\-\d{1,2}$/', $rawDateInput)) {
            // try to normalize to Y-m-d
            $ts = strtotime(str_replace('/', '-', $normalized));
            if ($ts !== false) {
                $convertedGraduation = date('Y-m-d', $ts);
            }
        } else {
            // attempt to parse as Hijri (yyyy/mm/dd or dd/mm/yyyy)
            $cands = [];
            if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $normalized))
                $cands[] = $normalized;
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $normalized)) {
                $p = explode('/', $normalized);
                $cands[] = $p[2] . '/' . $p[1] . '/' . $p[0];
            }
            if (empty($cands) && $normalized !== '') {
                $parts = preg_split('/\//', $normalized);
                if (count($parts) === 3) {
                    if (strlen($parts[0]) === 4)
                        $cands[] = $normalized;
                    else
                        $cands[] = $parts[2] . '/' . $parts[1] . '/' . $parts[0];
                }
            }

            foreach ($cands as $cand) {
                if (empty($cand))
                    continue;
                // try Intl first
                if (class_exists('IntlDateFormatter')) {
                    try {
                        $fmt = new IntlDateFormatter('en_US@calendar=islamic', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
                        $parsedTs = @$fmt->parse($cand);
                        if ($parsedTs !== false && $parsedTs !== null) {
                            if ($parsedTs > 1000000000000)
                                $parsedTs = (int) ($parsedTs / 1000);
                            $convertedGraduation = gmdate('Y-m-d', (int) $parsedTs);
                            break;
                        }
                    } catch (Throwable $e) {
                        // ignore
                    }
                }

                $try = convertHijriToGregorian($cand);
                if ($try !== false) {
                    $convertedGraduation = $try;
                    break;
                }
            }
        }

        if ($convertedGraduation !== null) {
            $filters['graduation_date'] = $convertedGraduation;
        }

        // If conversion didn't yield a Gregorian date, allow matching raw Hijri text
        if ($convertedGraduation === null && $rawDateInput !== '') {
            // keep the raw input for LIKE matching in the model (graduation_date_raw)
            $filters['graduation_date_raw'] = $rawDateInput;
            error_log('Graduation filter: using raw hijri input for search: ' . $rawDateInput);
        }
    }

    // قيد خاص: إذا كان المستخدم موظف (isEmployee())، عرض المتدربين المسندين إليه فقط
    try {
        // Allow an explicit override via ?__show_all=1 to let an employee view global results
        $allowShowAll = isset($_GET['__show_all']) && $_GET['__show_all'] == '1';
        if (function_exists('isEmployee') && isEmployee() && !empty($_SESSION['user_id']) && !$allowShowAll) {
            // override any assigned_to filter and force current user id
            $filters['assigned_to'] = (int) $_SESSION['user_id'];
            error_log('Applied assigned_to filter for employee user_id=' . (int) $_SESSION['user_id']);
        }

        // If current user is supervisor, scope to supervisor + their employees
        if (function_exists('isSupervisor') && isSupervisor() && !empty($_SESSION['user_id']) && !$allowShowAll) {
            // fetch employees under this supervisor
            try {
                $employeeIds = getEmployeesUnderSupervisor($db, (int) $_SESSION['user_id']);
                // include supervisor himself so direct assignments to the supervisor are shown
                $employeeIds[] = (int) $_SESSION['user_id'];
                // apply as array filter
                $filters['assigned_to'] = array_values(array_unique($employeeIds));
                error_log('Applied supervisor scope assigned_to=[' . implode(',', $filters['assigned_to']) . '] for supervisor_id=' . (int) $_SESSION['user_id']);
            } catch (Exception $e) {
                error_log('Failed to apply supervisor scope: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('Error applying employee-assigned filter: ' . $e->getMessage());
    }

    // تحديد ترتيب العرض
    $orderBy = 't.id DESC';
    if (isset($filters['contact_filter'])) {
        switch ($filters['contact_filter']) {
            case 'contacted':
                $filters['has_contact'] = true;
                break;
            case 'not_contacted':
                $filters['no_contact'] = true;
                break;
            case 'recent_contact':
                $orderBy = 't.last_contact DESC, t.id DESC';
                break;
            case 'old_contact':
                $orderBy = 't.last_contact ASC, t.id ASC';
                break;
        }
        // remove the raw contact_filter to avoid accidental processing later
        unset($filters['contact_filter']);
        // If user explicitly chose contacted / not_contacted, ignore any specific last_contact_date
        if (!empty($filters['has_contact']) || !empty($filters['no_contact'])) {
            if (isset($filters['last_contact_date'])) {
                unset($filters['last_contact_date']);
            }
        }
    }

// Pagination
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

// allow client to control page size via ?per_page=10|20|40|50|80|100|all (default 50)
$allowedPerPage = [10, 20, 40, 50, 80, 100];
$defaultPerPage = 50;
$perPage = $defaultPerPage;
if (isset($_GET['per_page'])) {
    if ($_GET['per_page'] === 'all') {
        $perPage = 'all';
    } else {
        $pp = (int) $_GET['per_page'];
        if (in_array($pp, $allowedPerPage, true)) {
            $perPage = $pp;
        }
    }
}

// إذا كان "الكل" محدد، لا نستخدم pagination
if ($perPage === 'all') {
    $limit = 1000000; // رقم كبير جداً لضمان عرض جميع السجلات
    $offset = 0;
    $totalPages = 1; // صفحة واحدة فقط
    $page = 1; // العودة للصفحة الأولى
} else {
    $limit = $perPage;
    $offset = ($page - 1) * $limit;
    $totalPages = ceil($totalTrainees / $limit);
}

// Validate last_contact_date if provided (must be YYYY-MM-DD) else ignore it
if (isset($filters['last_contact_date'])) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['last_contact_date'])) {
        unset($filters['last_contact_date']);
    }
}

    // جلب المتدربين مع التفاصيل
    // Debug: log filters only when explicitly requested in development
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development' && isset($_GET['debug_filters']) && $_GET['debug_filters'] == '1') {
        try {
            error_log('DEBUG: Trainees list - applied filters: ' . json_encode($filters, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            // ignore logging failure
        }
    }

    $trainees = $traineeModel->getTraineesWithDetails($filters, $orderBy, $limit, $offset);

    // === فحص موسع لبيانات الدبلومات ===
    if (!empty($trainees)) {
        error_log("=== DIPLOMA DATA ANALYSIS ===");
        error_log("Total trainees fetched: " . count($trainees));

        // فحص أول 10 متدربين بالتفصيل
        foreach (array_slice($trainees, 0, 10) as $index => $trainee) {
            error_log("Trainee #" . ($index + 1) .
                " - ID: " . $trainee['id'] .
                ", Name: " . $trainee['full_name'] .
                ", Diploma ID: " . ($trainee['diploma_id'] ?? 'NULL') .
                ", Diploma Name: " . ($trainee['diploma_name'] ?? 'NULL'));
        }

        // تحليل فريد للدبلومات
        $diplomaAnalysis = [];
        foreach ($trainees as $trainee) {
            $diplomaId = $trainee['diploma_id'] ?? 'NULL';
            $diplomaName = $trainee['diploma_name'] ?? 'NULL';

            if (!isset($diplomaAnalysis[$diplomaId])) {
                $diplomaAnalysis[$diplomaId] = [
                    'name' => $diplomaName,
                    'count' => 0,
                    'trainee_ids' => []
                ];
            }

            $diplomaAnalysis[$diplomaId]['count']++;
            $diplomaAnalysis[$diplomaId]['trainee_ids'][] = $trainee['id'];
        }

        error_log("Diploma distribution analysis:");
        foreach ($diplomaAnalysis as $diplomaId => $data) {
            error_log("Diploma ID: " . $diplomaId .
                ", Name: " . $data['name'] .
                ", Count: " . $data['count'] .
                ", Trainee IDs: " . implode(', ', array_slice($data['trainee_ids'], 0, 5)));
        }

        // فحص مباشر من قاعدة البيانات للمقارنة
        $sampleTraineeIds = array_slice(array_column($trainees, 'id'), 0, 5);
        if (!empty($sampleTraineeIds)) {
            $directCheckQuery = "
                SELECT 
                    t.id as trainee_id,
                    t.full_name,
                    t.diploma_id as trainee_diploma_id,
                    d.id as diploma_table_id,
                    d.name as diploma_table_name
                FROM trainees t
                LEFT JOIN diplomas d ON t.diploma_id = d.id
                WHERE t.id IN (" . implode(',', $sampleTraineeIds) . ")
            ";

            try {
                $directStmt = $db->prepare($directCheckQuery);
                $directStmt->execute();
                $directResults = $directStmt->fetchAll(PDO::FETCH_ASSOC);

                error_log("Direct database verification:");
                foreach ($directResults as $result) {
                    error_log("DB Check - Trainee ID: " . $result['trainee_id'] .
                        ", Trainee Diploma ID: " . $result['trainee_diploma_id'] .
                        ", Diploma Table ID: " . $result['diploma_table_id'] .
                        ", Diploma Table Name: " . $result['diploma_table_name']);
                }
            } catch (Exception $e) {
                error_log("Error in direct database check: " . $e->getMessage());
            }
        }
        error_log("=== END DIPLOMA ANALYSIS ===");
    }

    // حساب إجمالي عدد المتدربين
    $totalTrainees = $traineeModel->getTraineesCount($filters);
    $totalPages = ceil($totalTrainees / $limit);

    // If employee-scoped and no results, check if global results exist and show a hint
    $showScopeHint = false;
    if (!empty($filters['assigned_to']) && $totalTrainees == 0) {
        $globalFilters = $filters;
        unset($globalFilters['assigned_to']);
        $globalCount = $traineeModel->getTraineesCount($globalFilters);
        if ($globalCount > 0 && !isSupervisor()) {
            // only show the "scope hint" to employees (not to supervisors)
            $showScopeHint = true;
        }
    }

    // جلب التواريخ الفريدة للتسجيل
    try {
        $dateQuery = "
            SELECT DISTINCT 
                DATE_FORMAT(created_at, '%Y-%m') as month_year,
                COUNT(id) as trainees_count
            FROM trainees 
            WHERE created_at IS NOT NULL 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 2 YEAR)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY created_at DESC
            LIMIT 18
        ";
        $dateStmt = $db->prepare($dateQuery);
        $dateStmt->execute();
        $dateResults = $dateStmt->fetchAll(PDO::FETCH_ASSOC);

        $uniqueDates = [];
        foreach ($dateResults as $date) {
            $gy = $date['month_year'];
            $count = $date['trainees_count'];
            $gregFirst = $gy . '-01';
            $gregLabel = date('Y-m', strtotime($gregFirst));
            $hijriFull = convertGregorianToHijri($gregFirst);
            $hijriLabel = '';
            if ($hijriFull !== false) {
                $parts = explode('/', $hijriFull);
                if (count($parts) >= 2)
                    $hijriLabel = $parts[0] . '-' . $parts[1];
            }
            $label = $gregLabel;
            if ($hijriLabel && $hijriLabel !== str_replace('/', '-', $gregLabel)) {
                $label .= ' | ' . $hijriLabel;
            }
            $uniqueDates[$gy] = ['label' => $label, 'count' => $count];
        }
    } catch (Exception $e) {
        error_log("Error fetching unique dates: " . $e->getMessage());
        $uniqueDates = [];
    }

    // جلب قائمة آخر التواصلات
    try {
        $communicationsQuery = "
            SELECT DISTINCT 
                DATE(t.last_contact) as contact_date,
                COUNT(t.id) as trainee_count
            FROM trainees t
            WHERE t.last_contact IS NOT NULL
            AND t.last_contact != '0000-00-00 00:00:00'
            AND t.last_contact >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE(t.last_contact)
            ORDER BY contact_date DESC
            LIMIT 30
        ";
        $communicationsStmt = $db->prepare($communicationsQuery);
        $communicationsStmt->execute();
        $lastCommunications = $communicationsStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching communications: " . $e->getMessage());
        $lastCommunications = [];
    }

    // جلب تواريخ التخرج المتاحة للفلتر
    try {
        $gradWhere = "WHERE graduation_date IS NOT NULL AND graduation_date != '0000-00-00' AND graduation_date >= DATE_SUB(NOW(), INTERVAL 5 YEAR)";
        $gradParams = [];

        try {
            if (function_exists('isEmployee') && isEmployee() && !empty($_SESSION['user_id'])) {
                $gradWhere .= " AND assigned_to = :__scope_assigned_to";
                $gradParams[':__scope_assigned_to'] = (int) $_SESSION['user_id'];
            } elseif (!empty($filters['assigned_to'])) {
                $gradWhere .= " AND assigned_to = :__scope_assigned_to";
                $gradParams[':__scope_assigned_to'] = (int) $filters['assigned_to'];
            }
        } catch (Throwable $e) {
            error_log('Error applying assigned scope for graduation dates: ' . $e->getMessage());
        }

        $graduationQuery = "
            SELECT DATE(graduation_date) as graduation_date, COUNT(id) as trainee_count
            FROM trainees
            {$gradWhere}
            GROUP BY DATE(graduation_date)
            ORDER BY graduation_date DESC
            LIMIT 100
        ";
        $graduationStmt = $db->prepare($graduationQuery);
        foreach ($gradParams as $k => $v) {
            $graduationStmt->bindValue($k, $v);
        }
        $graduationStmt->execute();
        $graduationDates = $graduationStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching graduation dates: " . $e->getMessage());
        $graduationDates = [];
    }

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $error = "حدث خطأ في الاتصال بقاعدة البيانات. الرجاء المحاولة مرة أخرى.";
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    $error = "حدث خطأ غير متوقع. الرجاء المحاولة مرة أخرى.";
}

$page_title = 'قائمة المتدربين - تصميم متقدم';
include '../../includes/tw_layout_start.php';
?>

<?php
// If developer requests, show computed filters on the page for easier debugging
if (isset($_GET['debug_filters']) && (!empty($filters) || isset($_GET['graduation_date']))) {
    echo '<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">';
    echo '<div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded mb-4 text-sm text-yellow-800">';
    echo '<strong>DEBUG filters:</strong><pre style="white-space:pre-wrap;">' . htmlspecialchars(print_r($filters, true)) . '</pre>';
    echo '</div></div>';
}

?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Alert Container -->
    <div id="alertContainer" class="fixed top-4 right-4 z-50 max-w-sm w-full"></div>

    <!-- Modal Container for Trainee Details -->
    <div id="traineeModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div
            class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-hidden flex flex-col">
            <div
                class="flex justify-between items-center p-6 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                    <i class="fas fa-user-graduate ml-2"></i> تفاصيل المتدرب
                </h3>
                <button id="closeModal"
                    class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6 overflow-y-auto custom-scrollbar flex-1 min-h-0" id="modalContent">
                <!-- محتوى تفاصيل المتدرب سيتم تحميله هنا -->
            </div>
        </div>
    </div>

    <!-- Header Section -->

    <div class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-center space-x-4 rtl:space-x-reverse">
                    <div class="p-2 bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl shadow-lg">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">قائمة المتدربين</h1>
                        <p class="text-gray-600 dark:text-gray-400">إدارة وعرض جميع المتدربين في النظام</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <?php if (hasAccess('trainees', 'create')): ?>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/add.php"
                            class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-300 dark:from-blue-600 dark:to-blue-700 dark:text-white dark:shadow-lg dark:focus:ring-blue-500">
                            <i class="fas fa-user-plus ml-2"></i> إضافة متدرب
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($trainees) && hasAccess('trainees', 'export')): ?>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/export_excel.php<?php echo !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''; ?>"
                            class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-green-300 dark:from-green-600 dark:to-green-700 dark:text-white dark:shadow-lg dark:focus:ring-green-500">
                            <i class="fas fa-file-excel ml-2"></i> تصدير Excel
                        </a>
                    <?php endif; ?>

                    <?php if (!empty($trainees) && (function_exists('hasAccess') && (hasAccess('trainees', 'assign') || hasAccess('trainee', 'assign')))): ?>
                        <button id="toggleAssignment"
                            class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-purple-500 to-purple-600 text-white rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition-all duration-200">
                            <i class="fas fa-user-check ml-2"></i> إسناد متدربين
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border-l-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">إجمالي المتدربين</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                            <?php echo number_format($totalTrainees); ?>
                        </p>
                    </div>
                    <div class="p-3 bg-blue-100 dark:bg-blue-900/30 rounded-lg">
                        <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border-l-4 border-green-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">المسددين</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                            <?php
                            $paidCount = array_reduce($trainees, function ($carry, $trainee) {
                                return $carry + ($trainee['status'] === 'paid' ? 1 : 0);
                            }, 0);
                            echo number_format($paidCount);
                            ?>
                        </p>
                    </div>
                    <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-lg">
                        <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border-l-4 border-orange-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">المتأخرين</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                            <?php
                            $delayedCount = array_reduce($trainees, function ($carry, $trainee) {
                                return $carry + ($trainee['status'] === 'delayed' ? 1 : 0);
                            }, 0);
                            echo number_format($delayedCount);
                            ?>
                        </p>
                    </div>
                    <div class="p-3 bg-orange-100 dark:bg-orange-900/30 rounded-lg">
                        <i class="fas fa-clock text-orange-600 dark:text-orange-400 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border-l-4 border-purple-500">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">قيد التواصل</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                            <?php
                            $communicatingCount = array_reduce($trainees, function ($carry, $trainee) {
                                return $carry + ($trainee['status'] === 'under_communication' ? 1 : 0);
                            }, 0);
                            echo number_format($communicatingCount);
                            ?>
                        </p>
                    </div>
                    <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-lg">
                        <i class="fas fa-comments text-purple-600 dark:text-purple-400 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md mb-6">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">🔍 فلاتر البحث المتقدمة</h2>
            </div>

            <div class="p-6">
                <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <!-- الهوية الوطنية -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-id-card ml-2"></i> الهوية الوطنية
                        </label>
                        <input type="text" name="national_id"
                            value="<?php echo htmlspecialchars($_GET['national_id'] ?? ''); ?>"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="ابحث بالهوية الوطنية">
                    </div>

                    <!-- الحالة -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-chart-bar ml-2"></i> الحالة
                        </label>
                        <select name="status"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">جميع الحالات</option>
                            <?php
                            $statuses = [
                                'paid' => 'مسدد',
                                'delayed' => 'متأخر',
                                'promised_payment' => 'وعد بالسداد',
                                'refused_payment' => 'رافض',
                                'not_responding' => 'لا يرد',
                                'under_communication' => 'قيد التواصل'
                            ];
                            $currentStatus = $_GET['status'] ?? '';
                            foreach ($statuses as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($currentStatus === $key) ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- الدبلوم -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-graduation-cap ml-2"></i> الدبلوم
                        </label>
                        <select name="diploma_id"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">جميع الدبلومات</option>
                            <?php if (!empty($diplomas)): ?>
                                <?php foreach ($diplomas as $diploma): ?>
                                    <option value="<?php echo $diploma['id']; ?>" <?php echo (isset($_GET['diploma_id']) && (int) $_GET['diploma_id'] === (int) $diploma['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($diploma['name']); ?>
                                        (<?php echo $diploma['trainees_count'] ?? 0; ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>لا توجد دبلومات</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- الفرع -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-building ml-2"></i> الفرع
                        </label>
                        <select name="branch_id"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">جميع الفروع</option>
                            <?php if (!empty($branches)): ?>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>" <?php echo (isset($_GET['branch_id']) && (int) $_GET['branch_id'] === (int) $branch['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($branch['name']); ?>
                                        (<?php echo $branch['trainees_count'] ?? 0; ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>لا توجد فروع</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- المبلغ المتبقي من -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-money-bill-wave ml-2"></i> الرسوم المتبقية من
                        </label>
                        <input type="number" name="min_remaining"
                            value="<?php echo htmlspecialchars($_GET['min_remaining'] ?? ''); ?>"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            min="0" step="0.01" placeholder="0.00">
                    </div>

                    <!-- المبلغ المتبقي إلى -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-money-bill-wave ml-2"></i> الرسوم المتبقية إلى
                        </label>
                        <input type="number" name="max_remaining"
                            value="<?php echo htmlspecialchars($_GET['max_remaining'] ?? ''); ?>"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            min="0" step="0.01" placeholder="0.00">
                    </div>

                    <!-- فلتر التواصل -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-comments ml-2"></i> حالة التواصل
                        </label>
                        <select name="contact_filter"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">جميع المتدربين</option>
                            <option value="contacted" <?php echo (isset($_GET['contact_filter']) && $_GET['contact_filter'] === 'contacted') ? 'selected' : ''; ?>>تم التواصل معهم</option>
                            <option value="not_contacted" <?php echo (isset($_GET['contact_filter']) && $_GET['contact_filter'] === 'not_contacted') ? 'selected' : ''; ?>>لم يتم التواصل معهم
                            </option>
                            <option value="recent_contact" <?php echo (isset($_GET['contact_filter']) && $_GET['contact_filter'] === 'recent_contact') ? 'selected' : ''; ?>>آخر تواصل (الأحدث)
                            </option>
                            <option value="old_contact" <?php echo (isset($_GET['contact_filter']) && $_GET['contact_filter'] === 'old_contact') ? 'selected' : ''; ?>>آخر تواصل (الأقدم)
                            </option>
                        </select>
                    </div>

                    <!-- فلتر نتيجة التواصل -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-phone-alt ml-2"></i> نتيجة آخر تواصل
                        </label>
                        <select name="communication_result"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">جميع النتائج</option>
                            <option value="promise" <?php echo (isset($_GET['communication_result']) && $_GET['communication_result'] === 'promise') ? 'selected' : ''; ?>>وعد بالسداد</option>
                            <option value="refused" <?php echo (isset($_GET['communication_result']) && $_GET['communication_result'] === 'refused') ? 'selected' : ''; ?>>رفض السداد</option>
                            <option value="paid" <?php echo (isset($_GET['communication_result']) && $_GET['communication_result'] === 'paid') ? 'selected' : ''; ?>>تم السداد</option>
                            <option value="no_answer" <?php echo (isset($_GET['communication_result']) && $_GET['communication_result'] === 'no_answer') ? 'selected' : ''; ?>>لم يرد</option>
                            <option value="follow_up" <?php echo (isset($_GET['communication_result']) && $_GET['communication_result'] === 'follow_up') ? 'selected' : ''; ?>>متابعة</option>
                            <option value="not_interested" <?php echo (isset($_GET['communication_result']) && $_GET['communication_result'] === 'not_interested') ? 'selected' : ''; ?>>غير مهتم</option>
                            <option value="other" <?php echo (isset($_GET['communication_result']) && $_GET['communication_result'] === 'other') ? 'selected' : ''; ?>>أخرى</option>
                        </select>
                    </div>

                    <!-- فلتر الموظف المسند إليه -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-user-tie ml-2"></i> الموظف المسند إليه
                        </label>
                        <select name="assigned_to"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">جميع الموظفين</option>
                            <?php if (!empty($employees)): ?>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo $employee['id']; ?>" <?php echo (isset($_GET['assigned_to']) && (int) $_GET['assigned_to'] === (int) $employee['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($employee['full_name']); ?>
                                        (<?php echo $employee['role_name']; ?>)
                                        - <?php echo $employee['assigned_trainees_count']; ?> متدرب مسند
                                        <?php if ($employee['communicating_trainees'] > 0): ?>
                                            - <?php echo $employee['communicating_trainees']; ?> قيد التواصل
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>لا يوجد موظفين</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <?php if (!empty($showScopeHint)): ?>
                        <div class="col-span-full">
                            <div class="p-3 rounded border border-yellow-300 bg-yellow-50 text-yellow-800">
                                لا توجد نتائج ضمن المتدربين المسندين إليك. هل تريد مشاهدة نتائج النظام كاملة؟
                                <a href="?<?php
                                $qs = $_GET;
                                $qs['__show_all'] = '1';
                                echo htmlspecialchars(http_build_query($qs));
                                ?>" class="font-semibold underline">عرض جميع المتدربين</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- فلتر التاريخ -->
                    <!-- فلتر التاريخ -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-comments ml-2"></i> تاريخ التواصل
                        </label>
                        <select name="last_contact_date"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">جميع التواريخ</option>
                            <?php if (!empty($lastCommunications)): ?>
                                <?php foreach ($lastCommunications as $communication):
                                    $contactDate = $communication['contact_date'];
                                    $traineeCount = $communication['trainee_count'];
                                    $formattedDate = date('Y-m-d', strtotime($contactDate));
                                    $displayLabel = $formattedDate;
                                    // only call helper if available
                                    if (function_exists('convertGregorianToHijri')) {
                                        $hijriDate = convertGregorianToHijri($contactDate);
                                        if ($hijriDate !== false && $hijriDate !== null) {
                                            $displayLabel .= ' | ' . $hijriDate;
                                        }
                                    }
                                    $selected = (isset($_GET['last_contact_date']) && $_GET['last_contact_date'] == $formattedDate) ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo htmlspecialchars($formattedDate); ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($displayLabel); ?> (<?php echo $traineeCount; ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>


                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-graduation-cap ml-2"></i> تاريخ التخرج
                        </label>
                        <select name="graduation_date"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="" <?php echo (!isset($_GET['graduation_date']) || $_GET['graduation_date'] === '') ? 'selected' : ''; ?>>
                                جميع التواريخ
                            </option>
                            <?php if (!empty($graduationDates)):
                                foreach ($graduationDates as $graduation):
                                    if (empty($graduation['graduation_date']))
                                        continue;
                                    $gradDate = $graduation['graduation_date'];
                                    $traineeCount = isset($graduation['trainee_count']) ? (int) $graduation['trainee_count'] : 0;
                                    $formattedDate = date('Y-m-d', strtotime($gradDate));
                                    $displayLabel = $formattedDate;
                                    if (function_exists('convertGregorianToHijri')) {
                                        $hijriDate = convertGregorianToHijri($gradDate);
                                        if ($hijriDate !== false && $hijriDate !== null)
                                            $displayLabel .= ' | ' . $hijriDate;
                                    }
                                    $selected = (isset($_GET['graduation_date']) && $_GET['graduation_date'] === $formattedDate) ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo htmlspecialchars($formattedDate); ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($displayLabel); ?> (<?php echo $traineeCount; ?> متدرب)
                                    </option>
                                    <?php
                                endforeach;
                            else:
                                ?>
                                <option value="" disabled>لا توجد تواريخ متاحة</option>
                            <?php endif; ?>
                        </select>
                    </div>



                    <!-- Action Buttons -->
                    <div
                        class="md:col-span-2 lg:col-span-3 xl:col-span-4 flex flex-col sm:flex-row gap-4 justify-between items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex items-center gap-4">
                            <button type="submit"
                                class="inline-flex items-center px-6 py-2 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition-all duration-200">
                                <i class="fas fa-filter ml-2"></i> تطبيق الفلاتر
                            </button>

                            <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/list.php"
                                class="inline-flex items-center px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                                <i class="fas fa-redo ml-2"></i> إعادة تعيين
                            </a>
                        </div>

                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            <i class="fas fa-info-circle ml-2"></i>
                            تم العثور على <?php echo number_format($totalTrainees); ?> متدرب
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assignment Panel -->
        <div id="assignmentPanel" class="bg-white dark:bg-gray-800 rounded-xl shadow-md mb-6 hidden">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                            <i class="fas fa-user-check ml-2"></i> إسناد المتدربين المحددين
                        </h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">اختر الموظف الذي تريد إسناد المتدربين إليه</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <span id="selectedCount"
                            class="inline-flex items-center px-3 py-1 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 text-sm font-medium">
                            <i class="fas fa-user-check ml-2"></i> <span>0</span> متدرب محدد
                        </span>
                        <button id="selectAllTrainees"
                            class="inline-flex items-center px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-sm">
                            <i class="fas fa-check-double ml-1"></i> تحديد الكل
                        </button>
                        <button id="deselectAllTrainees"
                            class="inline-flex items-center px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-sm">
                            <i class="fas fa-times ml-1"></i> إلغاء الكل
                        </button>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <form method="post" id="assignmentForm">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-user-tie ml-2"></i> اختر الموظف للإسناد:
                            </label>
                            <div class="flex items-center gap-4">
                                <select id="assignedToSelect" name="assigned_to"
                                    class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    required>
                                    <option value="">-- اختر الموظف --</option>
                                    <?php if (!empty($employees)): ?>
                                        <?php foreach ($employees as $employee): ?>
                                            <option value="<?php echo $employee['id']; ?>">
                                                <?php echo htmlspecialchars($employee['full_name']); ?>
                                                (<?php echo $employee['role_name']; ?>)
                                                - <?php echo $employee['assigned_trainees_count']; ?> متدرب مسند
                                                <?php if ($employee['communicating_trainees'] > 0): ?>
                                                    - <?php echo $employee['communicating_trainees']; ?> قيد التواصل
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <button type="button" id="viewAssignedBtn"
                                    class="inline-flex w-full md:w-auto justify-center items-center px-3 py-2 mb-3 md:mb-0 bg-blue-500 text-white rounded-lg shadow-sm hover:bg-blue-600 focus:outline-none"
                                    title="عرض المتدربين المسندين لهذا الموظف">
                                    <i class="fas fa-eye ml-1"></i> عرض
                                </button>
                            </div>
                        </div>
                        <br><br>
                        <!-- إضافة حقل الفلترة الجديد -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 gap-4">
                                <i class="fas fa-filter ml-2"></i> تصفية المتدربين حسب الموظف المسند:
                            </label>
                            <select id="filterAssignedTrainees"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">جميع المتدربين</option>
                                <?php if (!empty($employees)): ?>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?php echo $employee['id']; ?>">
                                            <?php echo htmlspecialchars($employee['full_name']); ?>
                                            (<?php echo $employee['assigned_trainees_count']; ?> متدرب مسند)
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-end gap-6 lg:justify-end">
                        <button type="submit" name="assign_trainees" id="confirmAssignment"
                            class="inline-flex items-center px-6 py-2 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled>
                            <i class="fas fa-check ml-2"></i> تأكيد الإسناد
                        </button>

                        <button type="button" id="cancelAssignment"
                            class="inline-flex items-center px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                            <i class="fas fa-times ml-2"></i> إلغاء
                        </button>
                    </div>

                    <?php if (!empty($employees)): ?>
                        <div class="mb-6">
                            <h4 class="text-md font-medium text-gray-700 dark:text-gray-300 mb-4">
                                <i class="fas fa-users ml-2"></i> الموظفون المتاحون:
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($employees as $employee):
                                    // note: previously the card was wrapped in an anchor linking to communications list.
                                    // change: make the card selectable only (no navigation) so it can be used to choose the assignee.
                                    ?>
                                    <div class="employee-card block border border-gray-200 dark:border-gray-600 rounded-lg p-4 cursor-pointer transition-all duration-200 hover:border-blue-500 hover:shadow-md"
                                        data-employee-id="<?php echo $employee['id']; ?>"
                                        title="اختر الموظف للإسناد: <?php echo htmlspecialchars($employee['full_name']); ?>"
                                        role="button" tabindex="0">
                                        <div class="flex items-center space-x-3 rtl:space-x-reverse">
                                            <div
                                                class="w-12 h-12 bg-gradient-to-r from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-lg">
                                                <?php echo mb_substr($employee['full_name'], 0, 1, 'UTF-8'); ?>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                                    <?php echo htmlspecialchars($employee['full_name']); ?>
                                                </p>
                                                <p class="text-sm text-gray-500 dark:text-gray-400 truncate">
                                                    <i class="fas fa-user-tie ml-1"></i> <?php echo $employee['role_name']; ?>
                                                </p>
                                                <div class="flex items-center gap-2 mt-1">
                                                    <span
                                                        class="text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 px-2 py-1 rounded">
                                                        <i class="fas fa-users ml-1"></i>
                                                        <?php echo $employee['assigned_trainees_count']; ?>
                                                    </span>
                                                    <?php if ($employee['communicating_trainees'] > 0): ?>
                                                        <span
                                                            class="text-xs bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300 px-2 py-1 rounded">
                                                            <i class="fas fa-comments ml-1"></i>
                                                            <?php echo $employee['communicating_trainees']; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                            <i class="fas fa-users-slash text-4xl mb-3"></i>
                            <p>لا يوجد موظفين متاحين للإسناد</p>
                        </div>
                    <?php endif; ?>

                    <input type="hidden" name="selected_trainees_display" id="selectedTraineesInput">
                </form>
            </div>
        </div>

        <!-- Trainees Table -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    <i class="fas fa-list-alt ml-2"></i> قائمة المتدربين
                </h3>
                <div id="tableActions" class="hidden">
                    <span id="tableSelectedCount"
                        class="inline-flex items-center px-3 py-1 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 text-sm font-medium mr-3">
                        <i class="fas fa-user-check ml-2"></i> <span>0</span> محدد
                    </span>
                </div>
                <?php if (hasAccess('trainees', 'delete_all')): ?>
                    <div class="mr-4">
                        <button onclick="openDeleteAllTraineesModal()" 
                            class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg shadow-md"
                            title="حذف جميع المتدربين">
                            <i class="fas fa-trash-alt ml-2"></i> حذف جميع المتدربين
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($trainees)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-users-slash text-4xl text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">لا يوجد متدربين</h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-4">لم يتم العثور على أي متدربين بناءً على الفلاتر المحددة
                    </p>
                    <?php if (hasAccess('trainees', 'create')): ?>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/add.php"
                            class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg shadow-md hover:shadow-lg">
                            <i class="fas fa-user-plus ml-2"></i> إضافة متدرب جديد
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th
                                    class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider checkbox-column hidden">
                                    <input type="checkbox" id="selectAll"
                                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                </th>
                                <th
                                    class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    <i class="fas fa-user ml-2"></i> المتدرب
                                </th>
                                <th
                                    class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    <i class="fas fa-info-circle ml-2"></i> المعلومات
                                </th>
                                <th
                                    class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    <i class="fas fa-chart-bar ml-2"></i> الحالة
                                </th>
                                <th
                                    class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    <i class="fas fa-money-bill-wave ml-2"></i> الرسوم
                                </th>
                                <th
                                    class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    <i class="fas fa-money-bill-wave ml-2"></i> الرسوم المتبقيه
                                </th>
                                <!-- إضافة عمود تاريخ التخرج -->
                                <th
                                    class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    <i class="fas fa-graduation-cap ml-2"></i> تاريخ التخرج
                                </th>
                                <th
                                    class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    <i class="fas fa-clock ml-2"></i> آخر تواصل
                                </th>
                                <th
                                    class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    <i class="fas fa-user-tie ml-2"></i> مسند لـ
                                </th>
                                <th
                                    class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    <i class="fas fa-cog ml-2"></i> الإجراءات
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($trainees as $trainee): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors trainee-row"
                                    data-trainee-id="<?php echo $trainee['id']; ?>"
                                    data-assigned-to="<?php echo $trainee['assigned_to'] ?? ''; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap checkbox-column hidden">
                                        <input type="checkbox"
                                            class="trainee-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                            value="<?php echo $trainee['id']; ?>">
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div
                                                class="flex-shrink-0 h-10 w-10 bg-gradient-to-r from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold">
                                                <?php echo mb_substr($trainee['full_name'], 0, 1, 'UTF-8'); ?>
                                            </div>
                                            <div class="mr-4">
                                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                    <?php echo htmlspecialchars($trainee['full_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                                    <i class="fas fa-id-card ml-1"></i>
                                                    <?php echo htmlspecialchars($trainee['national_id']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white">
                                            <div class="font-medium">
                                                <i class="fas fa-graduation-cap ml-1"></i>
                                                <?php echo htmlspecialchars($trainee['diploma_name'] ?? 'غير محدد'); ?>
                                            </div>
                                            <div class="text-gray-500 dark:text-gray-400">
                                                <i class="fas fa-building ml-1"></i>
                                                <?php echo htmlspecialchars($trainee['branch_name'] ?? 'غير محدد'); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $statusConfig = [
                                            'paid' => ['label' => 'مسدد', 'color' => 'green', 'icon' => 'check-circle'],
                                            'delayed' => ['label' => 'متأخر', 'color' => 'red', 'icon' => 'exclamation-circle'],
                                            'promised_payment' => ['label' => 'وعد بالسداد', 'color' => 'yellow', 'icon' => 'clock'],
                                            'refused_payment' => ['label' => 'رافض', 'color' => 'gray', 'icon' => 'times-circle'],
                                            'not_responding' => ['label' => 'لا يرد', 'color' => 'orange', 'icon' => 'phone-slash'],
                                            'under_communication' => ['label' => 'قيد التواصل', 'color' => 'blue', 'icon' => 'comments']
                                        ];
                                        $status = $trainee['status'] ?? 'unknown';
                                        $config = $statusConfig[$status] ?? ['label' => 'غير محدد', 'color' => 'gray', 'icon' => 'question-circle'];
                                        ?>
                                        <div class="flex items-center gap-2">
                                            <i
                                                class="fas fa-<?php echo $config['icon']; ?> text-<?php echo $config['color']; ?>-500"></i>
                                            <span
                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-<?php echo $config['color']; ?>-100 text-<?php echo $config['color']; ?>-800 dark:bg-<?php echo $config['color']; ?>-900/30 dark:text-<?php echo $config['color']; ?>-400">
                                                <?php echo $config['label']; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-700 dark:text-white font-semibold"
                                        title="إجمالي الرسوم">
                                        <i class="fas fa-money-bill-wave ml-1"></i>
                                        <?php
                                        $totalFees = $trainee['total_fees'] ?? $trainee['total_amount'] ?? $trainee['fees'] ?? 0;
                                        echo number_format((float) $totalFees, 2);
                                        ?>
                                        ر.س
                                    </td>
                                    <td
                                        class="px-6 py-4 whitespace-nowrap text-sm text-green-700 dark:text-white font-semibold">
                                        <i class="fas fa-money-bill-wave ml-1"></i>
                                        <?php
                                        // مبدأ الرسوم المتبقية: استخدام الحقل الصحيح مع fallback آمن
                                        $remainingFees = $trainee['remaining_amount'] ?? $trainee['remaining_fees'] ?? 0;
                                        echo number_format((float) $remainingFees, 2);
                                        ?>
                                        ر.س
                                    </td>
                                    <!-- إضافة خلية تاريخ التخرج (مُعاد كتابتها لعرض التاريخ بشكل موثوق) -->
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php
                                        // Helper closures for normalization and Intl conversion (local scope)
                                        $normalizeHijriString = function ($s) {
                                            $s = trim((string) $s);
                                            if ($s === '')
                                                return '';
                                            // replace Arabic-Indic digits with latin
                                            $arabicNums = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ','];
                                            $s = strtr($s, $arabicNums);
                                            // replace any non-digit with '/'
                                            $s = preg_replace('/[^0-9\/\\-\s]/u', '/', $s);
                                            $s = preg_replace('/[\.\-\s\\_]+/', '/', $s);
                                            $s = preg_replace('/\/{2,}/', '/', $s);
                                            return trim($s, '/');
                                        };

                                        $tryIntlHijriToGregorian = function ($cand) {
                                            if (empty($cand))
                                                return false;
                                            if (!class_exists('IntlDateFormatter'))
                                                return false;
                                            try {
                                                $fmt = new IntlDateFormatter('en_US@calendar=islamic', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
                                                $parsed = @$fmt->parse($cand);
                                                if ($parsed === false || $parsed === null)
                                                    return false;
                                                // normalize ms => s if necessary
                                                if ($parsed > 1000000000000)
                                                    $parsed = (int) ($parsed / 1000);
                                                return gmdate('Y-m-d', (int) $parsed);
                                            } catch (\Throwable $e) {
                                                return false;
                                            }
                                        };

                                        $formatGregorian = function ($dateStr) {
                                            try {
                                                if (empty($dateStr) || $dateStr === '0000-00-00')
                                                    return false;
                                                $ts = strtotime($dateStr);
                                                if ($ts === false)
                                                    return false;
                                                return date('Y/m/d', $ts);
                                            } catch (\Throwable $e) {
                                                return false;
                                            }
                                        };

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
                                            } catch (\Throwable $e) {
                                                // ignore
                                            }
                                            return '';
                                        };

                                        $gradHtml = '';
                                        $intervalHtml = '';

                                        // 1) If we have a Gregorian date, show it and its Hijri (stored or converted)
                                        if (!empty($trainee['graduation_date']) && $trainee['graduation_date'] !== '0000-00-00') {
                                            $g = $formatGregorian($trainee['graduation_date']);
                                            // safe check: use graduation_date_hijri if present in the row, otherwise try to convert
                                            $h = !empty($trainee['graduation_date_hijri'] ?? '') ? $trainee['graduation_date_hijri'] : $formatHijriFromGregorian($trainee['graduation_date']);

                                            if ($g) {
                                                $gradHtml .= '<div class="flex items-center gap-2"><i class="fas fa-graduation-cap text-blue-500"></i><div><div class="font-medium">ميلادي: ' . htmlspecialchars($g) . '</div>' . ($h ? '<div class="text-xs text-gray-400">هجري: ' . htmlspecialchars($h) . '</div>' : '<div class="text-xs text-gray-400">هجري: غير متوفر</div>') . '</div></div>';
                                            }

                                            // compute interval
                                            try {
                                                $graduationDate = new DateTime($trainee['graduation_date']);
                                                $today = new DateTime();
                                                $diff = $today->diff($graduationDate);
                                                $days = (int) $diff->format('%a');
                                                if ($graduationDate < $today) {
                                                    $intervalHtml = '<div class="text-xs text-green-600 dark:text-green-400 mt-1">تم التخرج منذ ' . $days . ' يوم</div>';
                                                } else {
                                                    $intervalHtml = '<div class="text-xs text-blue-600 dark:text-blue-400 mt-1">متبقي ' . $days . ' يوم للتخرج</div>';
                                                }
                                            } catch (\Throwable $e) {
                                                $intervalHtml = '';
                                            }

                                        } elseif (!empty($trainee['graduation_date_hijri'] ?? '')) {
                                            // 2) No Gregorian, but have a Hijri string from import: normalize and try to convert to Gregorian
                                            $raw = trim((string) ($trainee['graduation_date_hijri'] ?? ''));
                                            $norm = $normalizeHijriString($raw);
                                            $convertedG = false;

                                            // try candidate orderings
                                            $candidates = [];
                                            if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $norm)) {
                                                $candidates[] = $norm;
                                            }
                                            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $norm)) {
                                                $p = explode('/', $norm);
                                                $candidates[] = $p[2] . '/' . $p[1] . '/' . $p[0];
                                            }
                                            if (empty($candidates) && $norm !== '') {
                                                $parts = preg_split('/\//', $norm);
                                                if (count($parts) === 3) {
                                                    if (strlen($parts[0]) === 4)
                                                        $candidates[] = $norm;
                                                    else
                                                        $candidates[] = $parts[2] . '/' . $parts[1] . '/' . $parts[0];
                                                }
                                            }

                                            foreach ($candidates as $cand) {
                                                $try = $tryIntlHijriToGregorian($cand);
                                                if ($try) {
                                                    $convertedG = $try;
                                                    break;
                                                }
                                            }

                                            // Build display: prefer showing a readable Gregorian if we converted, else the raw hijri string
                                            if ($convertedG) {
                                                $gconv = htmlspecialchars(date('Y/m/d', strtotime($convertedG)));
                                                $gradHtml .= '<div class="flex items-center gap-2"><i class="fas fa-graduation-cap text-blue-500"></i><div><div class="font-medium">ميلادي: ' . $gconv . '</div><div class="text-xs text-gray-400">هجري: ' . htmlspecialchars($raw) . '</div></div></div>';
                                                // interval using converted date
                                                try {
                                                    $graduationDate = new DateTime($convertedG);
                                                    $today = new DateTime();
                                                    $days = (int) $today->diff($graduationDate)->format('%a');
                                                    if ($graduationDate < $today) {
                                                        $intervalHtml = '<div class="text-xs text-green-600 dark:text-green-400 mt-1">تم التخرج منذ ' . $days . ' يوم</div>';
                                                    } else {
                                                        $intervalHtml = '<div class="text-xs text-blue-600 dark:text-blue-400 mt-1">متبقي ' . $days . ' يوم للتخرج</div>';
                                                    }
                                                } catch (\Throwable $e) {
                                                    $intervalHtml = '';
                                                }
                                            } else {
                                                $gradHtml .= '<div class="flex items-center gap-2"><i class="fas fa-graduation-cap text-blue-500"></i><div><div class="font-medium">ميلادي: غير محدد</div><div class="text-xs text-gray-400">هجري: ' . htmlspecialchars($raw) . '</div></div></div>';
                                            }
                                        }

                                        // Output
                                        if ($gradHtml) {
                                            echo $gradHtml;
                                            if ($intervalHtml)
                                                echo $intervalHtml;
                                        } else {
                                            echo '<span class="text-gray-400"><i class="fas fa-graduation-cap ml-1"></i> غير محدد</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php if (!empty($trainee['last_contact']) && $trainee['last_contact'] !== '0000-00-00 00:00:00'): ?>
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-clock text-gray-400"></i>
                                                <span><?php echo date('Y/m/d', strtotime($trainee['last_contact'])); ?></span>
                                            </div>
                                            <div class="text-xs text-gray-400 mt-1">
                                                <?php echo date('g:i A', strtotime($trainee['last_contact'])); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400">
                                                <i class="fas fa-clock ml-1"></i> لم يتم التواصل
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php if (!empty($trainee['assigned_to_name'])): ?>
                                            <div class="flex items-center">
                                                <div
                                                    class="w-6 h-6 bg-gray-200 dark:bg-gray-600 rounded-full flex items-center justify-center text-xs mr-2">
                                                    <?php echo mb_substr($trainee['assigned_to_name'], 0, 1, 'UTF-8'); ?>
                                                </div>
                                                <i class="fas fa-user-tie ml-1"></i>
                                                <?php echo htmlspecialchars($trainee['assigned_to_name']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400">
                                                <i class="fas fa-user-times ml-1"></i> غير مسند
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex items-center space-x-2 rtl:space-x-reverse">
                                            <!-- تعديل رابط العين لفتح النافذة المنبثقة -->
                                            <button type="button"
                                                class="view-trainee-btn text-blue-600 hover:text-blue-900 dark:text-white dark:hover:bg-blue-800/30 transition-colors p-2 rounded-lg hover:bg-blue-50"
                                                data-trainee-id="<?php echo $trainee['id']; ?>" title="عرض التفاصيل">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if (hasAccess('trainees', 'edit')): ?>
                                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/edit.php?id=<?php echo $trainee['id']; ?>"
                                                    class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 transition-colors p-2 rounded-lg hover:bg-green-50 dark:hover:bg-green-900/20"
                                                    title="تعديل">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (hasAccess('trainees', 'delete')): ?>
                                                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/delete.php?id=<?php echo $trainee['id']; ?>"
                                                    class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300 transition-colors p-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20"
                                                    title="حذف" onclick="return confirm('هل أنت متأكد من حذف هذا المتدرب؟');">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button"
                                                class="communications-btn text-indigo-600 hover:text-indigo-900 dark:text-white dark:hover:bg-indigo-800/30 transition-colors p-2 rounded-lg hover:bg-indigo-50"
                                                data-trainee-id="<?php echo $trainee['id']; ?>"
                                                data-trainee-name="<?php echo htmlspecialchars($trainee['full_name']); ?>"
                                                title="سجل التواصل">
                                                <i class="fas fa-history"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Pagination -->
        <!-- Pagination -->
<?php if ($totalPages > 1 || $perPage === 'all'): ?>
    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-3">
                <div>
                    <i class="fas fa-info-circle ml-2"></i> عرض <?php echo count($trainees); ?> من أصل
                    <?php echo number_format($totalTrainees); ?> متدرب
                    <?php if ($perPage === 'all'): ?>
                        <span class="text-blue-600 dark:text-blue-400 font-semibold">(عرض الكل)</span>
                    <?php endif; ?>
                </div>

                <!-- Per-page selector - يظل مرئياً حتى عند عرض الكل -->
                <div class="flex items-center gap-2">
                    <label for="perPageSelect" class="text-xs text-gray-400">عرض:</label>
                    <select id="perPageSelect" class="text-sm border rounded px-2 py-1 bg-white dark:bg-gray-700" aria-label="عدد الصفوف">
                        <option value="10" <?php echo ($perPage == 10) ? 'selected' : ''; ?>>10</option>
                        <option value="20" <?php echo ($perPage == 20) ? 'selected' : ''; ?>>20</option>
                        <option value="40" <?php echo ($perPage == 40) ? 'selected' : ''; ?>>40</option>
                        <option value="50" <?php echo ($perPage == 50) ? 'selected' : ''; ?>>50</option>
                        <option value="80" <?php echo ($perPage == 80) ? 'selected' : ''; ?>>80</option>
                        <option value="100" <?php echo ($perPage == 100) ? 'selected' : ''; ?>>100</option>
                        <!-- إضافة خيار "الكل" -->
                        <option value="all" <?php echo ($perPage === 'all') ? 'selected' : ''; ?>>الكل</option>
                    </select>
                </div>
            </div>

            <?php if ($totalPages > 1 && $perPage !== 'all'): ?>
                <div class="flex items-center space-x-1 rtl:space-x-reverse">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                            class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                            <i class="fas fa-chevron-right ml-2"></i> السابق
                        </a>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                            class="inline-flex items-center px-3 py-2 border text-sm font-medium rounded-md <?php echo $i === $page ? 'border-blue-500 text-blue-600 bg-blue-50 dark:bg-blue-900/30' : 'border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
                            class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                            التالي <i class="fas fa-chevron-left ml-2"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>



    <!-- Modal Container for Communications History -->
    <div id="communicationsModal"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div
            class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-hidden flex flex-col">
            <div
                class="flex justify-between items-center p-6 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
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

    <!-- Delete All Trainees Modal -->
    <div id="deleteAllTraineesModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50" style="backdrop-filter: blur(4px);">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white dark:bg-gray-800">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">حذف جميع المتدربين</h3>
                <button onclick="closeDeleteAllTraineesModal()" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="post" action="delete_all.php" onsubmit="return validateDeleteAllTraineesForm();">
                <div class="mb-4">
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                        <div class="flex items-center mb-2">
                            <i class="fas fa-exclamation-triangle text-red-500 ml-2"></i>
                            <h4 class="text-red-800 font-semibold">تحذير</h4>
                        </div>
                        <p class="text-red-700 text-sm">
                            سيؤدي هذا الإجراء إلى حذف جميع سجلات المتدربين وبياناتهم المرتبطة نهائياً. لا يمكن التراجع عن هذا الإجراء.
                        </p>
                    </div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        كلمة المرور للتأكيد
                    </label>
                    <input type="password" name="confirm_password" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 dark:bg-gray-700 dark:border-gray-600"
                           placeholder="أدخل كلمة المرور للتأكيد">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        يجب إدخال كلمة المرور للمسؤول لتأكيد عملية الحذف
                    </p>
                </div>
                <div class="flex justify-end space-x-reverse space-x-2">
                    <button type="button" onclick="closeDeleteAllTraineesModal()" 
                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                        إلغاء
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                        <i class="fas fa-trash-alt ml-2"></i> حذف الكل
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Delete All Trainees Modal Functions
        function openDeleteAllTraineesModal() {
            document.getElementById('deleteAllTraineesModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteAllTraineesModal() {
            document.getElementById('deleteAllTraineesModal').classList.add('hidden');
            document.body.style.overflow = '';
        }

        function validateDeleteAllTraineesForm() {
            const password = document.querySelector('input[name="confirm_password"]').value;
            if (!password) {
                alert('يجب إدخال كلمة المرور للتأكيد');
                return false;
            }
            return true;
        }

        // نظام التنبيهات الاحترافية




        // نظام النافذة المنبثقة لعرض سجل التواصل
        class CommunicationsModal {
            constructor() {
                this.modal = document.getElementById('communicationsModal');
                this.modalContent = document.getElementById('communicationsContent');
                this.closeBtn = document.getElementById('closeCommunicationsModal');
                this.currentTraineeId = null;

                this.init();
            }

            init() {
                // إضافة مستمعي الأحداث
                this.closeBtn.addEventListener('click', () => this.hide());

                // إغلاق النافذة عند النقر خارج المحتوى
                this.modal.addEventListener('click', (e) => {
                    if (e.target === this.modal) {
                        this.hide();
                    }
                });

                // إغلاق النافذة بمفتاح ESC
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && !this.modal.classList.contains('hidden')) {
                        this.hide();
                    }
                });
            }

            show(traineeId, communicationId = null) {
                if (communicationId) {
                    // إذا كان هناك communicationId محدد، فتح عرض التفاصيل
                    this.loadCommunicationDetails(communicationId);
                } else {
                    // إذا كان هناك traineeId فقط، فتح سجل التواصل
                    this.loadCommunicationsHistory(traineeId);
                }
                this.modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }

            hide() {
                this.modal.classList.add('hidden');
                document.body.style.overflow = '';
            }

            async loadCommunicationDetails(communicationId) {
                // عرض مؤشر التحميل
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
                            // unauthorized - likely session expired
                            this.showError('غير مصرح بالوصول. ربما انتهت صلاحية الجلسة. الرجاء إعادة تسجيل الدخول.');
                            console.error('API returned 401 for communication details');
                            return;
                        }
                        throw new Error(`خطأ في الخادم: ${response.status}`);
                    }

                    const html = await response.text();
                    this.modalContent.innerHTML = html;

                    // تشغيل السكريبتات الموجودة في المحتوى
                    this.executeScripts(this.modalContent);

                } catch (error) {
                    console.error('Error loading communication details:', error);
                    this.showError('حدث خطأ في تحميل التفاصيل: ' + error.message);
                }
            }

            async loadCommunicationsHistory(traineeId) {
                // Store trainee ID for retries
                this.currentTraineeId = traineeId;

                // Show loading indicator
                this.showLoading();

                try {
                    // Use the direct HTML endpoint instead of the API
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
                this.modalContent.innerHTML = `
                <div class="flex justify-center items-center py-12">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
                    <span class="mr-3 text-gray-600 dark:text-gray-400">جاري تحميل البيانات...</span>
                </div>
            `;
            }

            showError(message, detail = '') {
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
        }

        // تهيئة النافذة المنبثقة لسجل التواصل
        const communicationsModal = new CommunicationsModal();
        // Compute application base based on current location to avoid incorrect SITE_URL values
        // Example: if current path is /my-works/Trainee-Management-System/modules/trainees/list.php
        // APP_BASE becomes /my-works/Trainee-Management-System
        const APP_BASE = (function () {
            try {
                const p = window.location.pathname || '';
                const idx = p.indexOf('/modules/');
                if (idx !== -1) return p.substring(0, idx);
                // fallback to root
                return '';
            } catch (e) {
                return '';
            }
        })();

        // Robust API fetch helper: try multiple candidate URLs until one succeeds
        async function apiFetch(path, opts = {}) {
            // path should start with '/api/...'
            const candidates = [];
            // 1) APP_BASE + path (same app folder)
            candidates.push(`${APP_BASE}${path}`);
            // 2) relative path from modules/trainees => ../../api/...
            if (path.startsWith('/')) {
                candidates.push(`..${path}`); // ../api/...
                candidates.push(`../../${path.replace(/^\//, '')}`); // ../../api/...
            }
            // 3) absolute root path as last resort
            candidates.push(path);

            let lastError = null;
            for (const url of candidates) {
                try {
                    // ensure credentials included so session cookie is sent
                    const response = await fetch(url, Object.assign({ credentials: 'include' }, opts));
                    if (response.ok) return response;
                    // if 404 try next candidate
                    lastError = response;
                } catch (e) {
                    lastError = e;
                    // continue to next candidate
                }
            }

            // throw the last error/response so caller can handle
            throw lastError;
        }

        // إضافة مستمعي الأحداث لأزرار سجل التواصل
        document.addEventListener('DOMContentLoaded', function () {
            // للأزرار التي تفتح سجل التواصل (قائمة)
            document.querySelectorAll('.communications-btn').forEach(button => {
                button.addEventListener('click', function () {
                    const traineeId = this.getAttribute('data-trainee-id');
                    communicationsModal.show(traineeId);
                });
            });

            // للأزرار التي تفتح تفاصيل تواصل محدد
            document.querySelectorAll('.communication-details-btn').forEach(button => {
                button.addEventListener('click', function () {
                    const communicationId = this.getAttribute('data-communication-id');
                    communicationsModal.show(null, communicationId);
                });
            });
        });








        // نظام التنبيهات الاحترافية
        class AlertSystem {
            constructor(containerId) {
                this.container = document.getElementById(containerId);
            }

            showAlert(alert) {
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert-item p-4 mb-4 rounded-lg shadow-lg border-l-4 ${
                    alert.type === 'success' ? 'bg-green-50 border-green-500 text-green-800' :
                    alert.type === 'error' ? 'bg-red-50 border-red-500 text-red-800' :
                    alert.type === 'warning' ? 'bg-yellow-50 border-yellow-500 text-yellow-800' :
                    alert.type === 'info' ? 'bg-blue-50 border-blue-500 text-blue-800' :
                    'bg-gray-50 border-gray-500 text-gray-800'
                }`;
                
                alertDiv.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas fa-${alert.icon || 'info-circle'} ml-2 text-lg"></i>
                        <div class="flex-1">
                            <h4 class="font-semibold">${alert.title || 'تنبيه'}</h4>
                            <p class="text-sm mt-1">${alert.message}</p>
                        </div>
                        <button onclick="this.parentElement.parentElement.remove()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;

                this.container.appendChild(alertDiv);

                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (alertDiv.parentElement) {
                        alertDiv.remove();
                    }
                }, 5000);
            }
        }

        // تهيئة نظام التنبيهات
        const alertSystem = new AlertSystem('alertContainer');

        // عرض التنبيهات من الجلسة إذا وجدت
        <?php if (isset($alert)): ?>
            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(() => {
                    alertSystem.showAlert(<?php echo json_encode($alert); ?>);
                }, 500);
            });
        <?php endif; ?>

        // نظام النافذة المنبثقة لعرض تفاصيل المتدرب
        class TraineeModal {
            constructor() {
                this.modal = document.getElementById('traineeModal');
                this.modalContent = document.getElementById('modalContent');
                this.closeBtn = document.getElementById('closeModal');

                this.init();
            }

            init() {
                // إضافة مستمعي الأحداث
                this.closeBtn.addEventListener('click', () => this.hide());

                // إغلاق النافذة عند النقر خارج المحتوى
                this.modal.addEventListener('click', (e) => {
                    if (e.target === this.modal) {
                        this.hide();
                    }
                });

                // إغلاق النافذة بمفتاح ESC
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && !this.modal.classList.contains('hidden')) {
                        this.hide();
                    }
                });
            }

            formatHijri(dateStr) {
                try {
                    if (!dateStr) return '';
                    const d = new Date(dateStr);
                    if (isNaN(d)) return '';
                    if (typeof Intl !== 'undefined' && Intl.DateTimeFormat) {
                        const opts = { day: 'numeric', month: 'long', year: 'numeric' };
                        try {
                            return new Intl.DateTimeFormat('ar-SA-u-ca-islamic', opts).format(d);
                        } catch (e) {
                            try {
                                return new Intl.DateTimeFormat('en-u-ca-islamic', opts).format(d);
                            } catch (e2) {
                                return '';
                            }
                        }
                    }
                } catch (e) {
                    // ignore and return empty
                }
                return '';
            }

            show(traineeId) {
                this.loadTraineeDetails(traineeId);
                this.modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden'; // منع التمرير في الخلفية
            }

            hide() {
                this.modal.classList.add('hidden');
                document.body.style.overflow = ''; // إعادة التمرير
            }

            async loadTraineeDetails(traineeId) {
                // عرض مؤشر التحميل
                this.modalContent.innerHTML = `
            <div class="flex justify-center items-center py-12">
                <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
                <span class="mr-3 text-gray-600 dark:text-gray-400">جاري تحميل البيانات...</span>
            </div>
        `;

                try {
                    // استخدام المسار الصحيح لواجهة برمجة التطبيقات التي تُرجع تفاصيل المتدرب
                    const response = await fetch(`${APP_BASE}/api/trainees/get-details.php?id=${traineeId}`, { credentials: 'include' });

                    if (!response.ok) {
                        throw new Error(`خطأ في الخادم: ${response.status}`);
                    }

                    const data = await response.json();

                    console.log('Response data:', data); // للتصحيح

                    if (data.success && data.trainee) {
                        this.renderTraineeDetails(data.trainee);
                    } else {
                        this.showError(data.message || 'حدث خطأ أثناء تحميل البيانات');
                    }
                } catch (error) {
                    console.error('Error loading trainee details:', error);
                    this.showError('حدث خطأ في الاتصال بالخادم: ' + error.message);
                }
            }

            renderTraineeDetails(trainee) {
                const statusConfig = {
                    'paid': { label: 'مسدد', color: 'green', icon: 'check-circle' },
                    'delayed': { label: 'متأخر', color: 'red', icon: 'exclamation-circle' },
                    'promised_payment': { label: 'وعد بالسداد', color: 'yellow', icon: 'clock' },
                    'refused_payment': { label: 'رافض', color: 'gray', icon: 'times-circle' },
                    'not_responding': { label: 'لا يرد', color: 'orange', icon: 'phone-slash' },
                    'under_communication': { label: 'قيد التواصل', color: 'blue', icon: 'comments' }
                };

                const status = trainee.status || 'unknown';
                const config = statusConfig[status] || { label: 'غير محدد', color: 'gray', icon: 'question-circle' };

                // حساب الفترة المتبقية/المنقضية منذ التخرج
                let graduationInfo = '';
                if (trainee.graduation_date && trainee.graduation_date !== '0000-00-00') {
                    const graduationDate = new Date(trainee.graduation_date);
                    const today = new Date();
                    const diffTime = Math.abs(today - graduationDate);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

                    if (graduationDate < today) {
                        graduationInfo = `<span class="text-green-600 dark:text-green-400">تم التخرج منذ ${diffDays} يوم</span>`;
                    } else {
                        graduationInfo = `<span class="text-blue-600 dark:text-blue-400">متبقي ${diffDays} يوم للتخرج</span>`;
                    }
                }

                // تحضير تنسيقات هجري لتواريخ مهمة
                const regGregorian = trainee.registration_date ? new Date(trainee.registration_date).toLocaleDateString('ar-SA') : null;
                const regHijri = this.formatHijri(trainee.registration_date);
                const gradGregorian = (trainee.graduation_date && trainee.graduation_date !== '0000-00-00') ? new Date(trainee.graduation_date).toLocaleDateString('ar-SA') : null;
                const gradHijri = this.formatHijri(trainee.graduation_date);
                const lastContactGregorian = (trainee.last_contact && trainee.last_contact !== '0000-00-00 00:00:00') ? new Date(trainee.last_contact).toLocaleString('ar-SA') : null;
                const lastContactHijri = this.formatHijri(trainee.last_contact);
                const assignedAtGregorian = trainee.assigned_at ? new Date(trainee.assigned_at).toLocaleDateString('ar-SA') : null;
                const assignedAtHijri = this.formatHijri(trainee.assigned_at);
                const updatedAtGregorian = trainee.updated_at ? new Date(trainee.updated_at).toLocaleString('ar-SA') : null;
                const updatedAtHijri = this.formatHijri(trainee.updated_at);

                this.modalContent.innerHTML = `
            <div class="space-y-6">
                <!-- Hero header (distinctive) -->
                <div class="relative rounded-xl overflow-hidden shadow-2xl">
                    <div class="absolute inset-0 bg-gradient-to-r from-pink-500 via-indigo-600 to-blue-600 opacity-95"></div>
                    <div class="relative z-10 px-6 py-6 md:py-8 flex items-center gap-6">
                        <div class="flex-shrink-0">
                            <div class="w-24 h-24 rounded-full bg-white/10 flex items-center justify-center text-3xl font-extrabold text-white shadow-lg">
                                ${trainee.full_name ? trainee.full_name.charAt(0) : '?'}
                            </div>
                        </div>
                        <div class="flex-1 text-white">
                            <h2 class="text-3xl md:text-4xl font-extrabold leading-tight">${trainee.full_name || 'غير محدد'}</h2>
                            <div class="mt-1 text-sm opacity-90 flex items-center gap-3">
                                <span class="inline-flex items-center gap-2 bg-white/10 px-3 py-1 rounded-full text-sm">
                                    <i class="fas fa-id-card"></i>
                                    <span>${trainee.national_id || 'غير محدد'}</span>
                                </span>
                                <span class="inline-flex items-center gap-2 bg-white/10 px-3 py-1 rounded-full text-sm">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span>${gradGregorian || 'تاريخ التخرج غير معروف'}</span>
                                </span>
                            </div>
                        </div>

                        <!-- Vertical metrics panel -->
                        <div class="hidden md:flex flex-col items-end gap-3">
                            <div class="text-white text-sm opacity-90">الرسوم المتبقية</div>
                            <div class="bg-white/20 px-3 py-2 rounded-lg text-white font-bold text-lg">${(trainee.remaining_amount ?? trainee.remaining_fees ?? 0).toLocaleString('ar-SA')} ر.س</div>
                            <div class="text-white text-sm opacity-90">المدفوع</div>
                            <div class="bg-white/20 px-3 py-2 rounded-lg text-white font-bold text-lg">${(trainee.paid_amount || 0).toLocaleString('ar-SA')} ر.س</div>
                        </div>
                    </div>

                    <!-- Hijri ribbon (prominent) -->
                    ${gradHijri ? `
                        <div class="absolute top-3 left-3 z-20">
                            <span class="inline-block transform rotate-[-8deg] bg-white text-indigo-700 font-semibold px-4 py-2 rounded shadow-lg">هجري: ${gradHijri}</span>
                        </div>
                    ` : ''}
                </div>

                <!-- Main content: glass cards with strong visual separation -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left: big summary column (spans 2 on large screens) -->
                    <div class="lg:col-span-2 space-y-4">
                        <div class="backdrop-blur-sm bg-white/60 dark:bg-gray-900/50 border border-white/30 dark:border-gray-800 rounded-xl p-6 shadow-md">
                            <h3 class="text-xl font-semibold text-gray-800 dark:text-white mb-4">معلومات سريعة</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-3">
                                    <div class="text-sm text-gray-600">الدبلوم</div>
                                    <div class="font-semibold text-gray-900 dark:text-white">${trainee.diploma_name || 'غير محدد'}</div>
                                </div>
                                <div class="space-y-3">
                                    <div class="text-sm text-gray-600">الفرع</div>
                                    <div class="font-semibold text-gray-900 dark:text-white">${trainee.branch_name || 'غير محدد'}</div>
                                </div>
                                <div class="space-y-3">
                                    <div class="text-sm text-gray-600">تاريخ التسجيل</div>
                                    <div class="font-semibold">${regGregorian ? regGregorian + (regHijri ? `<br><small class="text-xs text-gray-500">هجري: ${regHijri}</small>` : '') : 'غير محدد'}</div>
                                </div>
                                <div class="space-y-3">
                                    <div class="text-sm text-gray-600">تاريخ التخرج</div>
                                    <div class="font-semibold">${gradGregorian ? gradGregorian + (gradHijri ? `<br><small class="text-xs text-gray-500">هجري: ${gradHijri}</small>` : '') : 'غير محدد'}</div>
                                </div>
                            </div>
                        </div>

                        <div class="backdrop-blur-sm bg-white/50 dark:bg-gray-900/50 border border-white/30 dark:border-gray-800 rounded-xl p-6 shadow-md">
                            <h3 class="text-lg font-semibold mb-3 text-gray-800 dark:text-white">التواصل والإسناد</h3>
                            <div class="grid grid-cols-1 gap-3 text-sm text-gray-700 dark:text-gray-300">
                                <div class="flex justify-between"><div class="text-gray-600">الهاتف</div><div class="font-medium">${trainee.phone || 'غير محدد'}</div></div>
                                <div class="flex justify-between"><div class="text-gray-600">الجوال</div><div class="font-medium">${trainee.mobile || 'غير محدد'}</div></div>
                                <div class="flex justify-between"><div class="text-gray-600">البريد</div><div class="font-medium">${trainee.email || 'غير محدد'}</div></div>
                                <div class="flex justify-between"><div class="text-gray-600">مسند لـ</div><div class="font-medium">${trainee.assigned_to_name || 'غير مسند'}</div></div>
                                <div class="flex justify-between"><div class="text-gray-600">آخر تواصل</div><div class="font-medium">${lastContactGregorian ? lastContactGregorian + (lastContactHijri ? `<br><small class="text-xs text-gray-500">هجري: ${lastContactHijri}</small>` : '') : 'لم يتم التواصل'}</div></div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: metrics & actions column -->
                    <div class="space-y-4">
                        <div class="rounded-xl p-5 bg-gradient-to-b from-white/80 to-white/60 dark:from-gray-800/60 dark:to-gray-800/40 border border-white/30 dark:border-gray-700 shadow-lg text-center">
                            <div class="text-sm text-gray-600">المبلغ الإجمالي</div>
                            <div class="mt-2 text-2xl font-extrabold text-gray-900 dark:text-white">${(trainee.total_amount || 0).toLocaleString('ar-SA')} ر.س</div>
                            <div class="mt-4 grid grid-cols-3 gap-2">
                                <div class="p-2 bg-white/80 dark:bg-gray-900/60 rounded-lg">
                                    <div class="text-xs text-gray-500">مدفوع</div>
                                    <div class="font-semibold text-green-600">${(trainee.paid_amount || 0).toLocaleString('ar-SA')}</div>
                                </div>
                                <div class="p-2 bg-white/80 dark:bg-gray-900/60 rounded-lg">
                                    <div class="text-xs text-gray-500">متبقي</div>
                                    <div class="font-semibold text-blue-600">${(trainee.remaining_amount ?? trainee.remaining_fees ?? 0).toLocaleString('ar-SA')}</div>
                                </div>
                                <div class="p-2 bg-white/80 dark:bg-gray-900/60 rounded-lg">
                                    <div class="text-xs text-gray-500">سداد</div>
                                    <div class="font-semibold">${trainee.total_amount > 0 ? Math.round((trainee.paid_amount / trainee.total_amount) * 100) : 0}%</div>
                                </div>
                            </div>
                        </div>

             
                    </div>
                </div>

                <!-- Notes (large) -->
                <div class="rounded-xl p-5 bg-white/60 dark:bg-gray-900/40 border border-white/30 dark:border-gray-800 shadow-md">
                    <h4 class="text-lg font-semibold mb-2 text-gray-800 dark:text-white">ملاحظات</h4>
                    <div class="prose max-h-40 overflow-auto text-gray-700 dark:text-gray-300">${trainee.notes || 'لا توجد ملاحظات'}</div>
                </div>

                <!-- sticky footer actions inside modal (keeps visible when scrolling) -->
                <div class="sticky bottom-0 bg-gradient-to-t from-white/50 dark:from-gray-900/60 backdrop-blur-md py-4 -mx-6 px-6 rounded-b-xl border-t border-gray-200 dark:border-gray-700">
                    <div class="max-w-4xl mx-auto flex justify-end gap-3">
       
                        <button type="button" onclick="traineeModal.hide()" class="inline-flex items-center px-4 py-2 bg-transparent border border-gray-300 dark:border-gray-700 rounded-lg">إغلاق</button>
                    </div>
                </div>
            </div>
        `;
            }

            showError(message) {
                this.modalContent.innerHTML = `
            <div class="text-center py-12">
                <i class="fas fa-exclamation-triangle text-4xl text-red-500 mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">خطأ في تحميل البيانات</h3>
                <p class="text-gray-600 dark:text-gray-400 mb-4">${message}</p>
                <button type="button" onclick="traineeModal.hide()" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    <i class="fas fa-times ml-2"></i> إغلاق
                </button>
            </div>
        `;
            }
        }

        // تهيئة النافذة المنبثقة
        const traineeModal = new TraineeModal();

        document.addEventListener('DOMContentLoaded', function () {
            // إضافة مستمعي الأحداث لأزرار عرض التفاصيل
            document.querySelectorAll('.view-trainee-btn').forEach(button => {
                button.addEventListener('click', function () {
                    const traineeId = this.getAttribute('data-trainee-id');
                    traineeModal.show(traineeId);
                });
            });

            // Assignment System
            const toggleAssignment = document.getElementById('toggleAssignment');
            const assignmentPanel = document.getElementById('assignmentPanel');
            const assignmentForm = document.getElementById('assignmentForm');
            const selectAll = document.getElementById('selectAll');
            const selectAllTrainees = document.getElementById('selectAllTrainees');
            const deselectAllTrainees = document.getElementById('deselectAllTrainees');
            const selectedCount = document.getElementById('selectedCount');
            const selectedCountSpan = selectedCount ? selectedCount.querySelector('span') : null;
            const cancelAssignment = document.getElementById('cancelAssignment');
            const confirmAssignment = document.getElementById('confirmAssignment');
            const employeeCards = document.querySelectorAll('.employee-card');
            const assignedToSelect = document.getElementById('assignedToSelect');
            const selectedTraineesInput = document.getElementById('selectedTraineesInput');
            const tableActions = document.getElementById('tableActions');
            const tableSelectedCount = document.getElementById('tableSelectedCount');
            const tableSelectedCountSpan = tableSelectedCount ? tableSelectedCount.querySelector('span') : null;
            const checkboxColumns = document.querySelectorAll('.checkbox-column');
            const traineeRows = document.querySelectorAll('.trainee-row');

            // Debug: Check if elements exist
            console.log('Assignment Elements Debug:');
            console.log('toggleAssignment:', toggleAssignment);
            console.log('assignmentPanel:', assignmentPanel);
            console.log('assignedToSelect:', assignedToSelect);
            console.log('selectedCount:', selectedCount);
            console.log('selectedCountSpan:', selectedCountSpan);

            // الحقل الجديد لفلترة المتدربين المسندين
            const filterAssignedTrainees = document.getElementById('filterAssignedTrainees');

            // Function to show/hide checkboxes
            function toggleCheckboxes(show) {
                if (checkboxColumns.length > 0) {
                    checkboxColumns.forEach(column => {
                        if (show) {
                            column.classList.remove('hidden');
                        } else {
                            column.classList.add('hidden');
                        }
                    });
                }

                if (tableActions) {
                    if (show) {
                        tableActions.classList.remove('hidden');
                    } else {
                        tableActions.classList.add('hidden');
                    }
                }
            }

            // Toggle assignment panel
            if (toggleAssignment) {
                toggleAssignment.addEventListener('click', function () {
                    const isHidden = assignmentPanel.classList.contains('hidden');

                    if (isHidden) {
                        // Show assignment panel and checkboxes
                        assignmentPanel.classList.remove('hidden');
                        toggleCheckboxes(true);
                        this.innerHTML = '<i class="fas fa-times ml-2"></i> إغلاق الإسناد';
                        this.classList.remove('bg-gradient-to-r', 'from-purple-500', 'to-purple-600');
                        this.classList.add('bg-gradient-to-r', 'from-red-500', 'to-red-600');

                        // Scroll to assignment panel
                        assignmentPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    } else {
                        // Hide assignment panel and checkboxes
                        assignmentPanel.classList.add('hidden');
                        toggleCheckboxes(false);
                        this.innerHTML = '<i class="fas fa-user-check ml-2"></i> إسناد متدربين';
                        this.classList.add('bg-gradient-to-r', 'from-purple-500', 'to-purple-600');
                        this.classList.remove('bg-gradient-to-r', 'from-red-500', 'to-red-600');
                        resetAssignment();
                    }
                });
            }

            // Select all trainees
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    const checkboxes = document.querySelectorAll('.trainee-checkbox');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateSelectedCount();
                });
            }

            // Select all button
            if (selectAllTrainees) {
                selectAllTrainees.addEventListener('click', function () {
                    const checkboxes = document.querySelectorAll('.trainee-checkbox');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = true;
                    });
                    if (selectAll) selectAll.checked = true;
                    updateSelectedCount();
                });
            }

            // Deselect all button
            if (deselectAllTrainees) {
                deselectAllTrainees.addEventListener('click', function () {
                    const checkboxes = document.querySelectorAll('.trainee-checkbox');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = false;
                    });
                    if (selectAll) selectAll.checked = false;
                    updateSelectedCount();
                });
            }

            // Update selected count
            function updateSelectedCount() {
                const selected = document.querySelectorAll('.trainee-checkbox:checked');
                if (selectedCountSpan) {
                    selectedCountSpan.textContent = selected.length;
                }
                if (tableSelectedCountSpan) {
                    tableSelectedCountSpan.textContent = selected.length;
                }

                // Update hidden inputs with selected trainee IDs (create inputs named selected_trainees[] inside assignmentForm)
                if (assignmentForm) {
                    // Remove any existing selected_trainees[] inputs
                    assignmentForm.querySelectorAll('input[name="selected_trainees[]"]').forEach(el => el.remove());
                    // Add new inputs for each selected id
                    const selectedIds = Array.from(selected).map(cb => cb.value);
                    selectedIds.forEach(id => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'selected_trainees[]';
                        input.value = id;
                        assignmentForm.appendChild(input);
                    });
                    // Keep a lightweight display of selected ids for UX (not used for submission)
                    selectedTraineesInput.value = selectedIds.join(',');
                }

                // Enable/disable confirm button
                if (confirmAssignment) {
                    confirmAssignment.disabled = selected.length === 0 || !assignedToSelect.value;
                }

                // Highlight selected rows
                traineeRows.forEach(row => {
                    const checkbox = row.querySelector('.trainee-checkbox');
                    if (checkbox && checkbox.checked) {
                        row.classList.add('bg-blue-50', 'dark:bg-blue-900/20');
                    } else {
                        row.classList.remove('bg-blue-50', 'dark:bg-blue-900/20');
                    }
                });
            }

            // Employee card selection
            employeeCards.forEach(card => {
                card.addEventListener('click', function () {
                    const employeeId = this.dataset.employeeId;
                    assignedToSelect.value = employeeId;

                    // Remove selection from all cards
                    employeeCards.forEach(c => c.classList.remove('border-blue-500', 'bg-blue-50', 'dark:bg-blue-900/20'));

                    // Add selection to clicked card
                    this.classList.add('border-blue-500', 'bg-blue-50', 'dark:bg-blue-900/20');

                    updateSelectedCount();
                });
                // keyboard accessibility: Enter or Space should select the employee card
                card.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                        e.preventDefault();
                        this.click();
                    }
                });
            });

            // Cancel assignment
            if (cancelAssignment) {
                cancelAssignment.addEventListener('click', function () {
                    if (toggleAssignment) {
                        toggleAssignment.click();
                    }
                });
            }

            // Reset assignment state
            function resetAssignment() {
                const checkboxes = document.querySelectorAll('.trainee-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = false;
                });

                if (selectAll) {
                    selectAll.checked = false;
                }

                assignedToSelect.value = '';
                employeeCards.forEach(card => {
                    card.classList.remove('border-blue-500', 'bg-blue-50', 'dark:bg-blue-900/20');
                });

                // Reset filter
                if (filterAssignedTrainees) {
                    filterAssignedTrainees.value = '';
                }

                updateSelectedCount();
            }

            // Update count when any checkbox changes
            document.addEventListener('change', function (e) {
                if (e.target.classList.contains('trainee-checkbox')) {
                    updateSelectedCount();

                    // Update select all checkbox state
                    if (selectAll) {
                        const checkboxes = document.querySelectorAll('.trainee-checkbox');
                        const allChecked = checkboxes.length > 0 && Array.from(checkboxes).every(cb => cb.checked);
                        const someChecked = Array.from(checkboxes).some(cb => cb.checked);

                        selectAll.checked = allChecked;
                        selectAll.indeterminate = someChecked && !allChecked;
                    }
                }
            });

            // Update when assigned employee changes
            if (assignedToSelect) {
                assignedToSelect.addEventListener('change', function () {
                    updateSelectedCount();

                    // Update employee card selection
                    employeeCards.forEach(card => {
                        card.classList.remove('border-blue-500', 'bg-blue-50', 'dark:bg-blue-900/20');
                        if (card.dataset.employeeId === this.value) {
                            card.classList.add('border-blue-500', 'bg-blue-50', 'dark:bg-blue-900/20');
                        }
                    });
                });
            }

            // فلترة المتدربين حسب الموظف المسند
            if (filterAssignedTrainees) {
                filterAssignedTrainees.addEventListener('change', function () {
                    const employeeId = this.value;

                    traineeRows.forEach(row => {
                        const rowEmployeeId = row.getAttribute('data-assigned-to');

                        if (employeeId === '' || rowEmployeeId === employeeId) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });

                    // Update select all state after filtering
                    updateSelectAllState();
                });
            }

            // Update select all state based on visible rows
            function updateSelectAllState() {
                const visibleCheckboxes = Array.from(document.querySelectorAll('.trainee-checkbox')).filter(checkbox => {
                    return checkbox.closest('tr').style.display !== 'none';
                });

                if (visibleCheckboxes.length === 0) {
                    if (selectAll) {
                        selectAll.checked = false;
                        selectAll.indeterminate = false;
                    }
                    return;
                }

                const allChecked = visibleCheckboxes.every(cb => cb.checked);
                const someChecked = visibleCheckboxes.some(cb => cb.checked);

                if (selectAll) {
                    selectAll.checked = allChecked;
                    selectAll.indeterminate = someChecked && !allChecked;
                }
            }

            // Form validation
            if (assignmentForm) {
                assignmentForm.addEventListener('submit', function (e) {
                    const selectedTrainees = document.querySelectorAll('.trainee-checkbox:checked');
                    if (selectedTrainees.length === 0) {
                        e.preventDefault();
                        alertSystem.showAlert({
                            type: 'warning',
                            title: 'تحذير',
                            message: 'يرجى اختيار متدربين للإسناد',
                            icon: 'warning'
                        });
                        return false;
                    }

                    if (!assignedToSelect.value) {
                        e.preventDefault();
                        alertSystem.showAlert({
                            type: 'warning',
                            title: 'تحذير',
                            message: 'يرجى اختيار موظف للإسناد',
                            icon: 'warning'
                        });
                        return false;
                    }

                    const employeeName = assignedToSelect.options[assignedToSelect.selectedIndex].text.split('(')[0].trim();
                    const confirmMessage = `هل أنت متأكد من إسناد ${selectedTrainees.length} متدرب إلى ${employeeName}؟`;

                    if (!confirm(confirmMessage)) {
                        e.preventDefault();
                        return false;
                    }

                    // إظهار رسالة تحميل
                    alertSystem.showAlert({
                        type: 'info',
                        title: 'جاري المعالجة',
                        message: 'يتم الآن إسناد المتدربين...',
                        icon: 'info'
                    });

                    return true;
                });
            }

            // Initialize
            toggleCheckboxes(false);
            updateSelectedCount();

            // Add smooth animations
            traineeRows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(20px)';

                setTimeout(() => {
                    row.style.transition = 'all 0.3s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 50);
            });

            // Enhanced hover effects
            const statCards = document.querySelectorAll('.grid > div');
            statCards.forEach(card => {
                card.addEventListener('mouseenter', function () {
                    this.style.transform = 'translateY(-5px)';
                    this.style.transition = 'all 0.3s ease';
                });

                card.addEventListener('mouseleave', function () {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Auto-focus on search inputs when filters are shown
            const filterInputs = document.querySelectorAll('input[name="national_id"], input[name="min_remaining"], input[name="max_remaining"]');
            filterInputs.forEach(input => {
                input.addEventListener('focus', function () {
                    this.select();
                });
            });

            // View assigned trainees button handler - open communications list filtered by selected employee and trainees
            const viewAssignedBtn = document.getElementById('viewAssignedBtn');
            if (viewAssignedBtn) {
                viewAssignedBtn.addEventListener('click', async function () {
                    const selectedEmployeeEl = document.getElementById('assignedToSelect');
                    const selectedEmployee = selectedEmployeeEl ? selectedEmployeeEl.value : '';
                    if (!selectedEmployee) {
                        alertSystem.showAlert({ type: 'warning', title: 'تحذير', message: 'الرجاء اختيار موظف أولاً لعرض المتدربين المسندين', icon: 'warning' });
                        return;
                    }

                    const url = '<?php echo rtrim(SITE_URL, "/"); ?>/api/trainees/get_assigned_trainees.php?employee_id=' + encodeURIComponent(selectedEmployee);
                    try {
                        const response = await fetch(url, { credentials: 'same-origin' });

                        // If response is not JSON or not ok, try to surface message
                        const contentType = response.headers.get('content-type') || '';
                        if (!response.ok) {
                            let serverMsg = await response.text().catch(() => 'خطأ في الخادم');
                            // try to parse JSON message
                            try {
                                const parsed = JSON.parse(serverMsg);
                                serverMsg = parsed.message || JSON.stringify(parsed);
                            } catch (e) {
                                // keep raw serverMsg
                            }
                            console.error('Server error when fetching assigned trainees:', response.status, serverMsg);
                            alertSystem.showAlert({ type: 'error', title: 'خطأ في الخادم', message: serverMsg || 'فشل الاتصال بالخادم', icon: 'error' });
                            return;
                        }

                        // Expect JSON
                        let data;
                        if (contentType.indexOf('application/json') !== -1) {
                            data = await response.json();
                        } else {
                            // handle non-json response
                            const text = await response.text();
                            try { data = JSON.parse(text); } catch (e) { data = { success: false, message: text }; }
                        }

                        if (data && data.success && Array.isArray(data.trainees) && data.trainees.length > 0) {
                            const traineeIds = data.trainees.map(t => t.id);
                            let dest = '<?php echo rtrim(SITE_URL, '/'); ?>/modules/communications/list.php?assigned_to=' + encodeURIComponent(selectedEmployee);
                            dest += '&trainees=' + encodeURIComponent(traineeIds.join(','));
                            window.location.href = dest;
                        } else {
                            const msg = (data && data.message) ? data.message : 'لا يوجد متدربين مسندين لهذا الموظف';
                            alertSystem.showAlert({ type: 'info', title: 'معلومات', message: msg, icon: 'info' });
                        }
                    } catch (err) {
                        console.error('Error fetching assigned trainees:', err);
                        alertSystem.showAlert({ type: 'error', title: 'خطأ', message: 'حدث خطأ أثناء جلب المتدربين المسندين. افتح وحدة التحكم لمزيد من التفاصيل', icon: 'error' });
                    }
                });
            }
        });
    </script>

    <script>
        // Attach per-page select handler after DOM ready
        document.addEventListener('DOMContentLoaded', function () {
            var perPageEl = document.getElementById('perPageSelect');
            if (!perPageEl) return;
            perPageEl.addEventListener('change', function () {
                try {
                    var params = new URLSearchParams(window.location.search);
                    params.set('per_page', this.value);
                    params.set('page', '1');
                    var qs = params.toString();
                    var newUrl = window.location.pathname + (qs ? ('?' + qs) : '');
                    window.location.href = newUrl;
                } catch (e) {
                    window.location.search = '?per_page=' + encodeURIComponent(this.value) + '&page=1';
                }
            });
        });
    </script>


    <style>
        /* Custom scrollbar */
        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: rgba(156, 163, 175, 0.5) transparent;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background-color: rgba(156, 163, 175, 0.5);
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background-color: rgba(156, 163, 175, 0.7);
        }

        /* Smooth transitions */
        .employee-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .employee-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        /* Alert animations */
        .alert-item {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .progress-bar {
            animation: progressBar 5s linear forwards;
        }

        @keyframes progressBar {
            from {
                width: 100%;
            }

            to {
                width: 0%;
            }
        }

        /* Loading animation */
        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        .loading {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        /* Dark mode enhancements */
        .dark .employee-card:hover {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }

        /* Responsive table */
        @media (max-width: 768px) {
            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .checkbox-column {
                display: none !important;
            }
        }

        /* Focus states */
        button:focus,
        select:focus,
        input:focus {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
        }

        /* Print styles */
        @media print {
            .no-print {
                display: none !important;
            }

            .checkbox-column {
                display: none !important;
            }
        }

        /* Selection styles */
        .trainee-row.selected {
            background-color: rgba(59, 130, 246, 0.1) !important;
        }

        /* Icon styles */
        .icon-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            margin-left: 8px;
        }

        .icon-box.success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .icon-box.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .icon-box.warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .icon-box.info {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        /* Enhanced status badges */
        .status-badge {
            transition: all 0.3s ease;
        }

        .status-badge:hover {
            transform: scale(1.05);
        }

        /* Fix: prevent repeated validation/check icons appearing in dark mode selects/inputs.
       Some embedded CSS (bootstrap/vendor) adds background-image SVGs for validity states
       which can repeat or clash with the dark theme. We only disable those decorative
       background-images in dark mode while keeping the native dropdown arrow usable. */
        .dark select,
        .dark input[type="text"],
        .dark input[type="number"],
        .dark input[type="search"],
        .dark .form-control.is-valid,
        .dark .form-control.is-invalid,
        .dark .is-valid,
        .dark .is-invalid {
            background-image: none !important;
            background-repeat: no-repeat !important;
        }

        /* On some browsers the select arrow may be hidden after removing background-image,
       keep a simple, single arrow using appearance and hide MS expand control. */
        select {
            -webkit-appearance: auto;
            -moz-appearance: auto;
            appearance: auto;
        }

        select::-ms-expand {
            display: inline-block;
        }
    </style>


    <?php
    include '../../includes/tw_layout_end.php';
    ?>
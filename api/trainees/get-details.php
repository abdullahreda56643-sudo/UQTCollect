<?php

require_once '../../config.php';

require_once '../../db/connection.php';

require_once '../../includes/auth.php';

require_once '../../models/Trainee.php';



// تأكد من تسجيل الدخول والصلاحيات

if (!isLoggedIn() || !hasAccess('trainees', 'view')) {

    http_response_code(403);

    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية الوصول إلى هذه البيانات']);

    exit;

}



// تحقق من وجود معرف المتدرب

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {

    http_response_code(400);

    echo json_encode(['success' => false, 'message' => 'معرف المتدرب غير صحيح']);

    exit;

}



// تعيين headers للتعامل مع JSON و CORS
header('Content-Type: application/json; charset=utf-8');
// When credentials may be sent, avoid wildcard origin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    $siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    if ($siteUrl) header('Access-Control-Allow-Origin: ' . $siteUrl);
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET');

// Ensure session is started with same params as config
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
    $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    error_log("[DEBUG get-details] Origin=" . $reqOrigin . " | SessionID=" . session_id() . " | Cookies=" . json_encode($_COOKIE) . " | _SESSION=" . json_encode(
        array_intersect_key($_SESSION, array_flip(['user_id','logged_in','last_activity','role']))
    ));
}



try {

    $db = (new Database())->getConnection();

    $traineeModel = new Trainee($db);



    // Build a safe SELECT that only references optional columns/tables if they exist

    $selectParts = ["t.*"];

    $joins = [];



    // diplomas

    try {

        $hasDiplomas = $db->query("SHOW TABLES LIKE 'diplomas'")->rowCount() > 0;

    } catch (Exception $e) {

        $hasDiplomas = false;

    }

    if ($hasDiplomas) {

        $selectParts[] = "d.name as diploma_name";

        $joins[] = "LEFT JOIN diplomas d ON t.diploma_id = d.id";

    }



    // branches

    try {

        $hasBranches = $db->query("SHOW TABLES LIKE 'branches'")->rowCount() > 0;

    } catch (Exception $e) {

        $hasBranches = false;

    }

    if ($hasBranches) {

        $selectParts[] = "b.name as branch_name";

        $joins[] = "LEFT JOIN branches b ON t.branch_id = b.id";

    }



    // users (assigned_to)

    try {

        $hasUsers = $db->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;

    } catch (Exception $e) {

        $hasUsers = false;

    }

    if ($hasUsers) {

        // check for full_name column

        $hasFullName = $db->query("SHOW COLUMNS FROM users LIKE 'full_name'")->rowCount() > 0;

        if ($hasFullName) {

            $selectParts[] = "u.full_name as assigned_to_name";

        }

        $joins[] = "LEFT JOIN users u ON t.assigned_to = u.id";

    }



    // communication count

    try {

        $hasCommunications = $db->query("SHOW TABLES LIKE 'communications'")->rowCount() > 0;

    } catch (Exception $e) {

        $hasCommunications = false;

    }

    if ($hasCommunications) {

        $selectParts[] = "(SELECT COUNT(*) FROM communications c WHERE c.trainee_id = t.id) as communication_count";

    }



    $query = "SELECT " . implode(", ", $selectParts) . " FROM trainees t " . implode(' ', $joins) . " WHERE t.id = :id LIMIT 1";



    $stmt = $db->prepare($query);

    $stmt->execute(['id' => $_GET['id']]);

    $trainee = $stmt->fetch(PDO::FETCH_ASSOC);

    

    if (!$trainee) {

        echo json_encode(['success' => false, 'message' => 'المتدرب غير موجود']);

        exit;

    }

    

    // تنظيف وتنسيق البيانات

    $cleanedTrainee = [

        'id' => (int)$trainee['id'],

        'full_name' => $trainee['full_name'] ?? '',

        'national_id' => $trainee['national_id'] ?? '',

        'diploma_name' => $trainee['diploma_name'] ?? 'غير محدد',

        'branch_name' => $trainee['branch_name'] ?? 'غير محدد',

        'status' => $trainee['status'] ?? 'unknown',

        'remaining_amount' => (float)($trainee['remaining_amount'] ?? 0),

        'total_amount' => (float)($trainee['total_amount'] ?? 0),

        'paid_amount' => (float)($trainee['paid_amount'] ?? 0),

        'registration_date' => $trainee['registration_date'] ?? null,

        'graduation_date' => $trainee['graduation_date'] ?? null,

        'phone' => $trainee['phone'] ?? 'غير محدد',

        'mobile' => $trainee['mobile'] ?? 'غير محدد',

        'email' => $trainee['email'] ?? 'غير محدد',

        'last_contact' => $trainee['last_contact'] ?? null,

        'assigned_to_name' => $trainee['assigned_to_name'] ?? 'غير مسند',

        'assigned_at' => $trainee['assigned_at'] ?? null,

        'communication_count' => (int)($trainee['communication_count'] ?? 0),

        'updated_at' => $trainee['updated_at'] ?? null,

        'notes' => $trainee['notes'] ?? 'لا توجد ملاحظات'

    ];

    

    // معالجة التواريخ الفارغة

    if ($cleanedTrainee['graduation_date'] === '0000-00-00') {

        $cleanedTrainee['graduation_date'] = null;

    }

    

    if ($cleanedTrainee['last_contact'] === '0000-00-00 00:00:00') {

        $cleanedTrainee['last_contact'] = null;

    }

    

    // إرسال البيانات بالصيغة الصحيحة

    // إضافة بيانات متعلقة عند توفر الجداول والأعمدة

    // recent payments (limit 5)

    try {

        $hasPayments = $db->query("SHOW TABLES LIKE 'payments'")->rowCount() > 0;

    } catch (Exception $e) {

        $hasPayments = false;

    }

    $recentPayments = [];

    if ($hasPayments) {

        try {

            $paymentsSql = "SELECT p.id, p.amount, p.payment_date, p.payment_method, p.created_by FROM payments p WHERE p.trainee_id = :id ORDER BY p.payment_date DESC LIMIT 5";

            $ps = $db->prepare($paymentsSql);

            $ps->execute(['id' => $cleanedTrainee['id']]);

            $recentPayments = $ps->fetchAll(PDO::FETCH_ASSOC);

            // try to attach created_by_name when users table exists

            $hasUsers = $db->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;

            if ($hasUsers && !empty($recentPayments)) {

                $userStmt = $db->prepare("SELECT id, COALESCE(full_name, name, '') as full_name FROM users WHERE id IN (" . implode(',', array_map(function($p){ return (int)$p['created_by']; }, $recentPayments)) . ")");

                // build map

                $userStmt->execute();

                $usersMap = [];

                foreach ($userStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {

                    $usersMap[$u['id']] = $u['full_name'];

                }

                foreach ($recentPayments as &$p) {

                    $p['created_by_name'] = $usersMap[$p['created_by']] ?? null;

                }

                unset($p);

            }

        } catch (Exception $e) {

            error_log('Error fetching recent payments: ' . $e->getMessage());

            $recentPayments = [];

        }

    }



    // recent communications (limit 5)

    try {

        $hasCommunications = $db->query("SHOW TABLES LIKE 'communications'")->rowCount() > 0;

    } catch (Exception $e) {

        $hasCommunications = false;

    }

    $recentCommunications = [];

    if ($hasCommunications) {

        try {

            $commSql = "SELECT c.id, c.type, c.contact_date, c.result, c.notes, c.created_by FROM communications c WHERE c.trainee_id = :id ORDER BY c.contact_date DESC LIMIT 5";

            $cs = $db->prepare($commSql);

            $cs->execute(['id' => $cleanedTrainee['id']]);

            $recentCommunications = $cs->fetchAll(PDO::FETCH_ASSOC);

            // attach created_by_name when possible

            if (!empty($recentCommunications) && $db->query("SHOW TABLES LIKE 'users'")->rowCount() > 0) {

                $ids = array_unique(array_filter(array_map(function($c){ return isset($c['created_by']) ? (int)$c['created_by'] : 0; }, $recentCommunications)));

                if (!empty($ids)) {

                    $in = implode(',', $ids);

                    $usr = $db->query("SELECT id, COALESCE(full_name, name, '') as full_name FROM users WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);

                    $map = [];

                    foreach ($usr as $u) $map[$u['id']] = $u['full_name'];

                    foreach ($recentCommunications as &$c) {

                        $c['created_by_name'] = $map[$c['created_by']] ?? null;

                    }

                    unset($c);

                }

            }

        } catch (Exception $e) {

            error_log('Error fetching recent communications: ' . $e->getMessage());

            $recentCommunications = [];

        }

    }



    // assignment history (limit 5)

    try {

        $hasAssignmentLogs = $db->query("SHOW TABLES LIKE 'assignment_logs'")->rowCount() > 0;

    } catch (Exception $e) {

        $hasAssignmentLogs = false;

    }

    $assignmentHistory = [];

    if ($hasAssignmentLogs) {

        try {

            $alSql = "SELECT al.assigned_to, al.assigned_by, al.assigned_at FROM assignment_logs al WHERE al.trainee_id = :id ORDER BY al.assigned_at DESC LIMIT 5";

            $als = $db->prepare($alSql);

            $als->execute(['id' => $cleanedTrainee['id']]);

            $assignmentHistory = $als->fetchAll(PDO::FETCH_ASSOC);

            // attach user names if users table exists

            if (!empty($assignmentHistory) && $db->query("SHOW TABLES LIKE 'users'")->rowCount() > 0) {

                $uids = [];

                foreach ($assignmentHistory as $row) {

                    if (!empty($row['assigned_to'])) $uids[] = (int)$row['assigned_to'];

                    if (!empty($row['assigned_by'])) $uids[] = (int)$row['assigned_by'];

                }

                $uids = array_unique(array_filter($uids));

                if (!empty($uids)) {

                    $in = implode(',', $uids);

                    $usr = $db->query("SELECT id, COALESCE(full_name, name, '') as full_name FROM users WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);

                    $map = [];

                    foreach ($usr as $u) $map[$u['id']] = $u['full_name'];

                    foreach ($assignmentHistory as &$a) {

                        $a['assigned_to_name'] = $map[$a['assigned_to']] ?? null;

                        $a['assigned_by_name'] = $map[$a['assigned_by']] ?? null;

                    }

                    unset($a);

                }

            }

        } catch (Exception $e) {

            error_log('Error fetching assignment history: ' . $e->getMessage());

            $assignmentHistory = [];

        }

    }



    // created_by name (if available)

    $createdByName = null;

    try {

        $hasCreatedBy = $db->query("SHOW COLUMNS FROM trainees LIKE 'created_by'")->rowCount() > 0;

    } catch (Exception $e) {

        $hasCreatedBy = false;

    }

    if ($hasCreatedBy && $db->query("SHOW TABLES LIKE 'users'")->rowCount() > 0) {

        try {

            if (!empty($trainee['created_by'])) {

                $cbStmt = $db->prepare("SELECT COALESCE(full_name, name, '') as full_name FROM users WHERE id = :id LIMIT 1");

                $cbStmt->execute(['id' => $trainee['created_by']]);

                $createdByName = $cbStmt->fetchColumn() ?: null;

            }

        } catch (Exception $e) {

            error_log('Error fetching created_by name: ' . $e->getMessage());

            $createdByName = null;

        }

    }



    $cleanedTrainee['recent_payments'] = $recentPayments;

    $cleanedTrainee['recent_communications'] = $recentCommunications;

    $cleanedTrainee['assignment_history'] = $assignmentHistory;

    $cleanedTrainee['created_by_name'] = $createdByName;



    // available discounts for this trainee (if table exists)
    try {
        $hasDiscounts = $db->query("SHOW TABLES LIKE 'discounts'")->rowCount() > 0;
    } catch (Exception $e) {
        $hasDiscounts = false;
    }
    $discounts = [];
    if ($hasDiscounts) {
        try {
            $ds = $db->prepare("SELECT id, type, amount, percent, reason, applied_at, reversed FROM discounts WHERE trainee_id = :id AND (reversed = 0 OR reversed IS NULL) ORDER BY applied_at DESC");
            $ds->execute(['id' => $cleanedTrainee['id']]);
            $discounts = $ds->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error fetching discounts: ' . $e->getMessage());
            $discounts = [];
        }
    }
    $cleanedTrainee['discounts'] = $discounts;

    echo json_encode([
        'success' => true,
        'trainee' => $cleanedTrainee
    ]);

    

} catch (Exception $e) {

    error_log("Error fetching trainee details: " . $e->getMessage());

    http_response_code(500);

    echo json_encode([

        'success' => false, 

        'message' => 'حدث خطأ أثناء جلب بيانات المتدرب: ' . $e->getMessage()

    ]);

}
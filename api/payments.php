<?php
/**
 * API المدفوعات
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.1.0
 * @author نظام إدارة المتدربين
 * @copyright 2024 جميع الحقوق محفوظة
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// التعامل مع طلبات OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config.php';
require_once '../db/connection.php';
require_once '../includes/auth.php';
require_once '../includes/helpers.php';
require_once '../models/BaseModel.php';
require_once '../models/Payment.php';
require_once '../models/Trainee.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'يجب تسجيل الدخول أولاً'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من تفعيل API
if (!ENABLE_API_ACCESS) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'الوصول إلى API غير مفعل'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $paymentModel = new Payment($db);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في الاتصال بقاعدة البيانات'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تحديد العملية المطلوبة
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : null;

switch ($method) {
    case 'GET':
        // الحصول على المدفوعات
        if (isset($_GET['id'])) {
            // الحصول على دفعة محددة
            $id = (int)$_GET['id'];
            $payment = $paymentModel->getById($id);
            
            if ($payment) {
                echo json_encode([
                    'success' => true,
                    'data' => $payment
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'الدفعة غير موجودة'
                ], JSON_UNESCAPED_UNICODE);
            }
        } elseif ($action === 'daily' && isset($_GET['date'])) {
            // الحصول على المدفوعات اليومية
            $date = $_GET['date'];
            $payments = $paymentModel->getDailyPayments($date);
            
            echo json_encode([
                'success' => true,
                'data' => $payments,
                'total' => count($payments)
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($action === 'statistics') {
            // الحصول على إحصائيات المدفوعات
            $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
            $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;
            
            $stats = $paymentModel->getStatistics($startDate, $endDate);
            
            if ($stats) {
                echo json_encode([
                    'success' => true,
                    'data' => $stats
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'خطأ في الحصول على الإحصائيات'
                ], JSON_UNESCAPED_UNICODE);
            }
        } elseif ($action === 'collection_report') {
            // الحصول على تقرير التحصيل
            $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
            $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
            $period = isset($_GET['period']) ? $_GET['period'] : 'daily';
            
            $filters = [];
            $allowedFilters = ['branch_id', 'diploma_id', 'employee_id', 'graduation_date'];
            foreach ($allowedFilters as $filter) {
                if (isset($_GET[$filter])) {
                    $filters[$filter] = $_GET[$filter];
                }
            }
            
            $report = $paymentModel->getCollectionReport($startDate, $endDate, $period, $filters);
            
            echo json_encode([
                'success' => true,
                'data' => $report,
                'period' => $period,
                'start_date' => $startDate,
                'end_date' => $endDate
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // الحصول على قائمة المدفوعات مع التفاصيل
            $filters = [];
            $orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : 'payment_date DESC';
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            
            // تطبيق الفلاتر
            $allowedFilters = ['trainee_id', 'payment_method', 'created_by'];
            foreach ($allowedFilters as $filter) {
                if (isset($_GET[$filter])) {
                    $filters[$filter] = $_GET[$filter];
                }
            }

            // If current user is a supervisor, restrict results to their branch only
            if (isSupervisor() && isset($_SESSION['branch_id'])) {
                $filters['branch_id'] = $_SESSION['branch_id'];
            }
            
            $payments = $paymentModel->getPaymentsWithDetails($filters, $orderBy, $limit, $offset);
            $total = count($paymentModel->getPaymentsWithDetails($filters));
            
            echo json_encode([
                'success' => true,
                'data' => $payments,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'POST':
        // التحقق من الصلاحيات
        if (!hasAccess('payments', 'create')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لإضافة دفعة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // دعم إضافة دفعة واحدة أو دفعات متعددة (bulk)
        $data = json_decode(file_get_contents('php://input'), true);
        $action = isset($_GET['action']) ? $_GET['action'] : null;

        if ($action === 'bulk') {
            // Expect payload: { "payments": [ {trainee_id, amount, payment_date, payment_method, ...}, ... ] }
            if (!isset($data['payments']) || !is_array($data['payments']) || count($data['payments']) === 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'لا توجد دفعات في الطلب أو الصيغة خاطئة'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Collect unique trainee ids and group new payments per trainee
            $grouped = [];
            $traineeIds = [];
            foreach ($data['payments'] as $p) {
                if (!isset($p['trainee_id'], $p['amount'], $p['payment_date'], $p['payment_method'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'كل دفعة يجب أن تحتوي على trainee_id, amount, payment_date, payment_method'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $tid = (int)$p['trainee_id'];
                $amt = (float)$p['amount'];
                if ($amt <= 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'المبلغ يجب أن يكون أكبر من الصفر لكل دفعة'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $grouped[$tid][] = $p;
                $traineeIds[$tid] = true;
            }

            $traineeIds = array_keys($traineeIds);

            try {
                $db->beginTransaction();

                // Fetch trainees and payments_sum for existing payments
                $in = implode(',', array_map('intval', $traineeIds));
                $stmt = $db->prepare("SELECT id, total_amount, paid_amount, initial_paid_amount FROM trainees WHERE id IN ($in) FOR UPDATE");
                $stmt->execute();
                $trainees = [];
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $trainees[$r['id']] = $r;
                }

                if (count($trainees) !== count($traineeIds)) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'أحد المتدربين غير موجود في القائمة المرسلة'], JSON_UNESCAPED_UNICODE);
                    $db->rollBack();
                    exit;
                }

                // compute existing payments sums
                $stmt = $db->prepare('SELECT trainee_id, COALESCE(SUM(amount),0) as payments_sum FROM payments WHERE trainee_id IN (' . $in . ') GROUP BY trainee_id');
                $stmt->execute();
                $paymentsSums = [];
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $paymentsSums[(int)$r['trainee_id']] = (float)$r['payments_sum'];
                }

                // Validate totals per trainee (existing payments + new payments must not exceed remaining)
                foreach ($grouped as $tid => $paymentsForT) {
                    $existingSum = $paymentsSums[$tid] ?? 0.0;
                    // استخدام طريقة حساب موحدة لجميع المتدربين
                    $initialPaid = 0.0;
                    if (isset($trainees[$tid]['initial_paid_amount']) && $trainees[$tid]['initial_paid_amount'] != null) {
                        $initialPaid = (float)$trainees[$tid]['initial_paid_amount'];
                    }
                    $currentPaid = $initialPaid + $existingSum;
                    $currentRemaining = max(0.0, (float)$trainees[$tid]['total_amount'] - $currentPaid);

                    $sumNew = 0.0;
                    foreach ($paymentsForT as $pp) $sumNew += (float)$pp['amount'];

                    if ($sumNew > $currentRemaining + 0.00001) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => "مجموع الدفعات الجديدة للمتدرب $tid يتجاوز المتبقي ({$currentRemaining})"], JSON_UNESCAPED_UNICODE);
                        $db->rollBack();
                        exit;
                    }
                }

                // Insert payments and track affected trainees
                $insertStmt = $db->prepare('INSERT INTO payments (trainee_id, amount, payment_date, payment_method, receipt_number, notes, created_by, created_at, updated_at) VALUES (:trainee_id, :amount, :payment_date, :payment_method, :receipt_number, :notes, :created_by, NOW(), NOW())');
                $createdBy = $_SESSION['user_id'] ?? 1;
                $affectedTrainees = [];
                $insertedIds = [];
                foreach ($grouped as $tid => $paymentsForT) {
                    foreach ($paymentsForT as $pp) {
                        $insertStmt->execute([
                            ':trainee_id' => $tid,
                            ':amount' => (float)$pp['amount'],
                            ':payment_date' => $pp['payment_date'],
                            ':payment_method' => $pp['payment_method'],
                            ':receipt_number' => $pp['receipt_number'] ?? null,
                            ':notes' => $pp['notes'] ?? null,
                            ':created_by' => $createdBy
                        ]);
                        $insertedIds[] = $db->lastInsertId();
                    }
                    $affectedTrainees[] = $tid;
                }

                // Recalculate balances for affected trainees (once each)
                $traineeModel = new Trainee($db);
                foreach (array_unique($affectedTrainees) as $tid) {
                    $traineeModel->updateBalanceProcedure($tid);
                }

                $db->commit();

                // Fetch inserted payments and updated trainees for response
                $respPayments = [];
                if (count($insertedIds)) {
                    $inIds = implode(',', array_map('intval', $insertedIds));
                    $ps = $db->query('SELECT * FROM payments WHERE id IN (' . $inIds . ')')->fetchAll(PDO::FETCH_ASSOC);
                    $respPayments = $ps;
                }

                $respTrainees = [];
                $ts = $db->query('SELECT id, paid_amount, remaining_amount FROM trainees WHERE id IN (' . implode(',', array_map('intval', $affectedTrainees)) . ')')->fetchAll(PDO::FETCH_ASSOC);
                $respTrainees = $ts;

                echo json_encode(['success' => true, 'message' => 'تم إضافة الدفعات دفعة واحدة', 'payments' => $respPayments, 'trainees' => $respTrainees], JSON_UNESCAPED_UNICODE);
                exit;
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'خطأ أثناء إدخال دفعات جماعية: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        if (!$data) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'البيانات المرسلة غير صحيحة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من البيانات المطلوبة
        $requiredFields = ['trainee_id', 'amount', 'payment_date', 'payment_method'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "حقل {$field} مطلوب"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        // التحقق من أن المبلغ صحيح
        $amount = floatval($data['amount']);
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'المبلغ يجب أن يكون أكبر من الصفر'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
    // التحقق من وجود المتدرب
        $traineeModel = new Trainee($db);
        $trainee = $traineeModel->getById($data['trainee_id']);
        if (!$trainee) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'المتدرب غير موجود'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // تأكد أن المستخدم الحالي يملك صلاحية على هذا المتدرب (المشرف يقتصر على فرعه)
        if (!canAccessTrainee($data['trainee_id'], $db)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لإضافة دفعات لهذا المتدرب'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من أن المبلغ لا يتجاوز المتبقي — حساب موحد لجميع المتدربين
        $stmt = $db->prepare('SELECT COALESCE(SUM(amount),0) AS payments_sum FROM payments WHERE trainee_id = :tid');
        $stmt->bindValue(':tid', $data['trainee_id'], PDO::PARAM_INT);
        $stmt->execute();
        $paymentsSum = (float)$stmt->fetchColumn();

        // استخدام طريقة حساب موحدة لجميع المتدربين
        $initialPaid = 0.0;
        if (isset($trainee['initial_paid_amount']) && $trainee['initial_paid_amount'] != null) {
            $initialPaid = (float)$trainee['initial_paid_amount'];
        }

        $currentPaid = $initialPaid + $paymentsSum;
        $currentRemaining = max(0.0, (float)$trainee['total_amount'] - $currentPaid);

        if ($amount > $currentRemaining) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'المبلغ المدفوع يتجاوز المبلغ المتبقي. المتبقي: ' . number_format($currentRemaining, 2) . ' ريال'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // إضافة بيانات إضافية
        $data['created_by'] = $_SESSION['user_id'];
        $data['amount'] = $amount;
        
        // إضافة الدفعة (الـ Trigger سيتولى تحديث رصيد المتدرب تلقائياً)
        $id = $paymentModel->addPayment($data);
        
        if ($id) {
            // الحصول على بيانات الدفعة المضافة
            $payment = $paymentModel->getById($id);
            
            // الحصول على بيانات المتدرب المحدثة
            $updatedTrainee = $traineeModel->getById($data['trainee_id']);
            
            echo json_encode([
                'success' => true,
                'message' => 'تم إضافة الدفعة بنجاح',
                'data' => $payment,
                'trainee' => [
                    'id' => $updatedTrainee['id'],
                    'paid_amount' => $updatedTrainee['paid_amount'],
                    'remaining_amount' => $updatedTrainee['remaining_amount']
                ]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء إضافة الدفعة'
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'PUT':
        // التحقق من الصلاحيات
        if (!hasAccess('payments', 'update')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لتعديل دفعة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود المعرف
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'معرف الدفعة مطلوب'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $id = (int)$_GET['id'];
        $payment = $paymentModel->getById($id);
        
        if (!$payment) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'الدفعة غير موجودة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // الحصول على البيانات المرسلة
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'البيانات المرسلة غير صحيحة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من المبلغ إذا تم تحديثه
        if (isset($data['amount'])) {
            $amount = floatval($data['amount']);
            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'المبلغ يجب أن يكون أكبر من الصفر'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $data['amount'] = $amount;
        }
        
    // حفظ معرف المتدرب قبل التحديث
        $oldTraineeId = $payment['trainee_id'];
        $newTraineeId = isset($data['trainee_id']) ? $data['trainee_id'] : $oldTraineeId;
        
        // التحقق من المتدرب الجديد إذا تم التغيير
        if ($newTraineeId != $oldTraineeId) {
            $traineeModel = new Trainee($db);
            $newTrainee = $traineeModel->getById($newTraineeId);
            if (!$newTrainee) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'المتدرب الجديد غير موجود'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // تأكد أن المستخدم يملك صلاحية على كلا المتدربين (القديم والجديد)
        if (!canAccessTrainee($oldTraineeId, $db) || !canAccessTrainee($newTraineeId, $db)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لتعديل هذه الدفعة بالنسبة للمتدرب المحدد'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // تحديث الدفعة
        $result = $paymentModel->update($id, $data);
        
        if ($result) {
            // تحديث رصيد المتدرب (الـ Trigger سيتولى ذلك تلقائياً)
            // لكن نستدعي الإجراء المخزن للتأكد من التحديث
            $traineeModel = new Trainee($db);
            
            // تحديث رصيد المتدرب القديم إذا تغير
            if ($newTraineeId != $oldTraineeId) {
                $traineeModel->updateBalanceProcedure($oldTraineeId);
            }
            
            // تحديث رصيد المتدرب الجديد
            $traineeModel->updateBalanceProcedure($newTraineeId);
            
            $payment = $paymentModel->getById($id);
            echo json_encode([
                'success' => true,
                'message' => 'تم تحديث الدفعة بنجاح',
                'data' => $payment
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء تحديث الدفعة'
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'DELETE':
        // التحقق من الصلاحيات
        if (!hasAccess('payments', 'delete')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لحذف دفعة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود المعرف
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'معرف الدفعة مطلوب'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $id = (int)$_GET['id'];
        $payment = $paymentModel->getById($id);
        
        if (!$payment) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'الدفعة غير موجودة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // حفظ معرف المتدرب قبل الحذف
        $traineeId = $payment['trainee_id'];
        // تأكد أن المستخدم يملك صلاحية على المتدرب قبل الحذف
        if (!canAccessTrainee($traineeId, $db)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لحذف دفعات هذا المتدرب'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // حذف الدفعة (الـ Trigger سيتولى تحديث رصيد المتدرب تلقائياً)
        $result = $paymentModel->deletePayment($id);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'تم حذف الدفعة بنجاح',
                'trainee_id' => $traineeId
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء حذف الدفعة'
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'طريقة الطلب غير مدعومة'
        ], JSON_UNESCAPED_UNICODE);
        break;
}
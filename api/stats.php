<?php
/**
 * API الإحصائيات
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.0.1
 * @author نظام إدارة المتدربين
 * @copyright 2024 جميع الحقوق محفوظة
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
require_once '../models/Trainee.php';
require_once '../models/Payment.php';
require_once '../models/Contact.php';
require_once '../models/User.php';

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

// التحقق من الصلاحيات للوصول إلى الإحصائيات
if (!hasAccess('reports', 'view')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'ليس لديك صلاحية للوصول إلى الإحصائيات'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $traineeModel = new Trainee($db);
    $paymentModel = new Payment($db);
    $contactModel = new Contact($db);
    $userModel = new User($db);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في الاتصال بقاعدة البيانات: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تحديد العملية المطلوبة
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : null;

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير مدعومة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تحديد نطاق التاريخ إذا تم تحديده
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;

// التحقق من صحة التواريخ إذا تم توفيرها
if ($startDate && !validateDate($startDate)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'تاريخ البداية غير صالح'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($endDate && !validateDate($endDate)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'تاريخ النهاية غير صالح'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($startDate && $endDate && $startDate > $endDate) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'تاريخ البداية يجب أن يكون قبل تاريخ النهاية'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تحديد نوع الإحصائيات المطلوبة
switch ($action) {
    case 'trainees':
        // إحصائيات المتدربين
        try {
            // استخدام طريقة بديلة إذا كانت getTraineeStatistics غير موجودة
            if (method_exists($traineeModel, 'getTraineeStatistics')) {
                $stats = $traineeModel->getTraineeStatistics();
            } else {
                $stats = getTraineeStatisticsAlternative($db);
            }
            
            if ($stats !== false) {
                echo json_encode([
                    'success' => true,
                    'data' => $stats
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'حدث خطأ أثناء جلب إحصائيات المتدربين'
                ], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب إحصائيات المتدربين: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'payments':
        // إحصائيات المدفوعات
        try {
            // استخدام طريقة بديلة إذا كانت getStatistics غير موجودة
            if (method_exists($paymentModel, 'getStatistics')) {
                $stats = $paymentModel->getStatistics($startDate, $endDate);
            } else {
                $stats = getPaymentStatisticsAlternative($db, $startDate, $endDate);
            }
            
            if ($stats !== false) {
                echo json_encode([
                    'success' => true,
                    'data' => $stats
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'حدث خطأ أثناء جلب إحصائيات المدفوعات'
                ], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب إحصائيات المدفوعات: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'daily_payments':
        // المدفوعات اليومية
        try {
            $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
            
            // التحقق من صحة التاريخ
            if (!validateDate($date)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'التاريخ غير صالح'
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            // استخدام طريقة بديلة إذا كانت getDailyPayments غير موجودة
            if (method_exists($paymentModel, 'getDailyPayments')) {
                $payments = $paymentModel->getDailyPayments($date);
            } else {
                $payments = getDailyPaymentsAlternative($db, $date);
            }
            
            if ($payments === false) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'حدث خطأ أثناء جلب المدفوعات اليومية'
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            // حساب المجموع
            $total = 0;
            foreach ($payments as $payment) {
                $total += floatval($payment['amount']);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $payments,
                'total' => $total,
                'count' => count($payments),
                'date' => $date
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب المدفوعات اليومية: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'contacts':
        // إحصائيات التواصل
        try {
            $sql = "SELECT 
                        COUNT(*) as total_contacts,
                        SUM(CASE WHEN contact_type = 'phone' THEN 1 ELSE 0 END) as phone_count,
                        SUM(CASE WHEN contact_type = 'sms' THEN 1 ELSE 0 END) as sms_count,
                        SUM(CASE WHEN contact_type = 'email' THEN 1 ELSE 0 END) as email_count,
                        SUM(CASE WHEN contact_type = 'whatsapp' THEN 1 ELSE 0 END) as whatsapp_count,
                        SUM(CASE WHEN result = 'successful' THEN 1 ELSE 0 END) as successful_count,
                        SUM(CASE WHEN result = 'no_answer' THEN 1 ELSE 0 END) as no_answer_count,
                        SUM(CASE WHEN result = 'wrong_number' THEN 1 ELSE 0 END) as wrong_number_count,
                        SUM(CASE WHEN result = 'not_interested' THEN 1 ELSE 0 END) as not_interested_count,
                        SUM(CASE WHEN result IS NULL OR result = '' THEN 1 ELSE 0 END) as no_result_count
                    FROM contacts WHERE 1=1";
            
            $params = [];
            
            // إضافة فلتر التاريخ إذا تم تحديده
            if ($startDate && $endDate) {
                $sql .= " AND contact_date BETWEEN :start_date AND :end_date";
                $params[':start_date'] = $startDate;
                $params[':end_date'] = $endDate;
            } elseif ($startDate) {
                $sql .= " AND contact_date >= :start_date";
                $params[':start_date'] = $startDate;
            } elseif ($endDate) {
                $sql .= " AND contact_date <= :end_date";
                $params[':end_date'] = $endDate;
            }
            
            $stmt = $db->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // تحويل القيم NULL إلى 0
            if ($stats) {
                foreach ($stats as $key => $value) {
                    if ($value === null) {
                        $stats[$key] = 0;
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $stats
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب إحصائيات التواصل: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'users':
        // إحصائيات المستخدمين
        try {
            $sql = "SELECT 
                        COUNT(*) as total_users,
                        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_count,
                        SUM(CASE WHEN role = 'supervisor' THEN 1 ELSE 0 END) as supervisor_count,
                        SUM(CASE WHEN role = 'employee' THEN 1 ELSE 0 END) as employee_count,
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count,
                        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_count
                    FROM users";
            
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // تحويل القيم NULL إلى 0
            if ($stats) {
                foreach ($stats as $key => $value) {
                    if ($value === null) {
                        $stats[$key] = 0;
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $stats
            ], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب إحصائيات المستخدمين: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'dashboard':
        // إحصائيات لوحة التحكم (مجموعة من الإحصائيات المختلفة)
        try {
            // استخدام الطرق البديلة إذا كانت الطرق الأصلية غير موجودة
            if (method_exists($traineeModel, 'getTraineeStatistics')) {
                $traineeStats = $traineeModel->getTraineeStatistics();
            } else {
                $traineeStats = getTraineeStatisticsAlternative($db);
            }
            
            if (method_exists($paymentModel, 'getStatistics')) {
                $paymentStats = $paymentModel->getStatistics();
            } else {
                $paymentStats = getPaymentStatisticsAlternative($db);
            }
            
            // إحصائيات التواصل اليوم
            $today = date('Y-m-d');
            $sql = "SELECT COUNT(*) as today_contacts FROM contacts WHERE DATE(contact_date) = :today";
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':today', $today);
            $stmt->execute();
            $todayContacts = $stmt->fetch(PDO::FETCH_ASSOC);
            $todayContactsCount = $todayContacts ? intval($todayContacts['today_contacts']) : 0;
            
            // إحصائيات المدفوعات اليوم
            $sql = "SELECT COUNT(*) as today_payments, COALESCE(SUM(amount), 0) as today_amount FROM payments WHERE DATE(payment_date) = :today AND status = 'completed'";
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':today', $today);
            $stmt->execute();
            $todayPayments = $stmt->fetch(PDO::FETCH_ASSOC);
            $todayPaymentsCount = $todayPayments ? intval($todayPayments['today_payments']) : 0;
            $todayPaymentsAmount = $todayPayments ? floatval($todayPayments['today_amount']) : 0;
            
            // إحصائيات المتدربين الجدد هذا الشهر
            $firstDayOfMonth = date('Y-m-01');
            $sql = "SELECT COUNT(*) as new_trainees FROM trainees WHERE DATE(created_at) >= :first_day";
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':first_day', $firstDayOfMonth);
            $stmt->execute();
            $newTrainees = $stmt->fetch(PDO::FETCH_ASSOC);
            $newTraineesCount = $newTrainees ? intval($newTrainees['new_trainees']) : 0;
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'trainees' => $traineeStats !== false ? $traineeStats : [],
                    'payments' => $paymentStats !== false ? $paymentStats : [],
                    'today' => [
                        'contacts' => $todayContactsCount,
                        'payments_count' => $todayPaymentsCount,
                        'payments_amount' => $todayPaymentsAmount
                    ],
                    'month' => [
                        'new_trainees' => $newTraineesCount
                    ]
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء جلب إحصائيات لوحة التحكم: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'يجب تحديد نوع الإحصائيات المطلوبة',
            'available_actions' => [
                'trainees',
                'payments',
                'daily_payments',
                'contacts',
                'users',
                'dashboard'
            ]
        ], JSON_UNESCAPED_UNICODE);
        break;
}

/**
 * التحقق من صحة تاريخ
 * 
 * @param string $date التاريخ للتحقق
 * @param string $format صيغة التاريخ (افتراضي: Y-m-d)
 * @return bool
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * طريقة بديلة لجلب إحصائيات المتدربين
 */
function getTraineeStatisticsAlternative($db) {
    try {
        $sql = "SELECT 
                    COUNT(*) as total_trainees,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END) as male_count,
                    SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END) as female_count
                FROM trainees";
        
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // تحويل القيم NULL إلى 0
        if ($stats) {
            foreach ($stats as $key => $value) {
                if ($value === null) {
                    $stats[$key] = 0;
                }
            }
        }
        
        return $stats;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * طريقة بديلة لجلب إحصائيات المدفوعات
 */
function getPaymentStatisticsAlternative($db, $startDate = null, $endDate = null) {
    try {
        $sql = "SELECT 
                    COUNT(*) as total_payments,
                    COALESCE(SUM(amount), 0) as total_amount,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as completed_amount
                FROM payments WHERE 1=1";
        
        $params = [];
        
        if ($startDate && $endDate) {
            $sql .= " AND payment_date BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        } elseif ($startDate) {
            $sql .= " AND payment_date >= :start_date";
            $params[':start_date'] = $startDate;
        } elseif ($endDate) {
            $sql .= " AND payment_date <= :end_date";
            $params[':end_date'] = $endDate;
        }
        
        $stmt = $db->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $stats;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * طريقة بديلة لجلب المدفوعات اليومية
 */
function getDailyPaymentsAlternative($db, $date) {
    try {
        $sql = "SELECT p.*, t.name as trainee_name 
                FROM payments p 
                LEFT JOIN trainees t ON p.trainee_id = t.id 
                WHERE DATE(p.payment_date) = :date 
                ORDER BY p.payment_date DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}
<?php
/**
 * API التواصل
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.0.0
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
require_once '../models/Contact.php';
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
    $contactModel = new Contact($db);
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
        // الحصول على سجلات التواصل
        if (isset($_GET['id'])) {
            // الحصول على سجل تواصل محدد
            $id = (int)$_GET['id'];
            $contact = $contactModel->getById($id);
            
            if ($contact) {
                // إضافة معلومات المتدرب
                if (isset($contact['trainee_id'])) {
                    $traineeModel = new Trainee($db);
                    $trainee = $traineeModel->getById($contact['trainee_id']);
                    if ($trainee) {
                        $contact['trainee_name'] = $trainee['full_name'];
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $contact
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'سجل التواصل غير موجود'
                ], JSON_UNESCAPED_UNICODE);
            }
        } elseif (isset($_GET['trainee_id'])) {
            // الحصول على سجلات التواصل لمتدرب محدد
            $traineeId = (int)$_GET['trainee_id'];
            $contacts = $contactModel->findAll(['trainee_id' => $traineeId], 'contact_date DESC');
            
            echo json_encode([
                'success' => true,
                'data' => $contacts,
                'total' => count($contacts)
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // الحصول على جميع سجلات التواصل
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : 'contact_date DESC';
            
            // تطبيق الفلاتر
            $filters = [];
            $allowedFilters = ['trainee_id', 'contact_type', 'created_by'];
            foreach ($allowedFilters as $filter) {
                if (isset($_GET[$filter])) {
                    $filters[$filter] = $_GET[$filter];
                }
            }
            
            // إضافة فلتر التاريخ إذا تم تحديده
            if (isset($_GET['start_date']) && isset($_GET['end_date'])) {
                $filters[] = "contact_date BETWEEN '{$_GET['start_date']}' AND '{$_GET['end_date']}'";
            }
            
            $contacts = $contactModel->findAll($filters, $orderBy, $limit, $offset);
            $total = count($contactModel->findAll($filters));
            
            // إضافة معلومات المتدربين
            $traineeModel = new Trainee($db);
            foreach ($contacts as &$contact) {
                if (isset($contact['trainee_id'])) {
                    $trainee = $traineeModel->getById($contact['trainee_id']);
                    if ($trainee) {
                        $contact['trainee_name'] = $trainee['full_name'];
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $contacts,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'POST':
        // التحقق من الصلاحيات
        if (!hasAccess('contacts', 'create')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لإضافة سجل تواصل'
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
        
        // التحقق من البيانات المطلوبة
        $requiredFields = ['trainee_id', 'contact_type', 'contact_date', 'result'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "حقل $field مطلوب"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        // إضافة بيانات إضافية
        $data['created_by'] = $_SESSION['user_id'];
        
        // إضافة سجل التواصل
        $id = $contactModel->create($data);
        
        if ($id) {
            $contact = $contactModel->getById($id);
            echo json_encode([
                'success' => true,
                'message' => 'تم إضافة سجل التواصل بنجاح',
                'data' => $contact
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء إضافة سجل التواصل'
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'PUT':
        // التحقق من الصلاحيات
        if (!hasAccess('contacts', 'update')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لتعديل سجل تواصل'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود المعرف
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'معرف سجل التواصل مطلوب'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $id = (int)$_GET['id'];
        $contact = $contactModel->getById($id);
        
        if (!$contact) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'سجل التواصل غير موجود'
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
        
        // تحديث سجل التواصل
        $result = $contactModel->update($id, $data);
        
        if ($result) {
            $contact = $contactModel->getById($id);
            echo json_encode([
                'success' => true,
                'message' => 'تم تحديث سجل التواصل بنجاح',
                'data' => $contact
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء تحديث سجل التواصل'
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'DELETE':
        // التحقق من الصلاحيات
        if (!hasAccess('contacts', 'delete')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لحذف سجل تواصل'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود المعرف
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'معرف سجل التواصل مطلوب'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $id = (int)$_GET['id'];
        $contact = $contactModel->getById($id);
        
        if (!$contact) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'سجل التواصل غير موجود'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // حذف سجل التواصل
        $result = $contactModel->delete($id);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'تم حذف سجل التواصل بنجاح'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء حذف سجل التواصل'
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
<?php
/**
 * API المستخدمين
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
require_once '../models/User.php';
require_once '../models/Branch.php';

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
    $userModel = new User($db);
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
        // التحقق من الصلاحيات للوصول إلى بيانات المستخدمين
        if (!hasAccess('users', 'view')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية للوصول إلى بيانات المستخدمين'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (isset($_GET['id'])) {
            // الحصول على مستخدم محدد
            $id = (int)$_GET['id'];
            $conditions = ['id' => $id];
            $users = $userModel->list($conditions);
            
            if (!empty($users)) {
                $user = $users[0];
                // حذف كلمة المرور من البيانات المرسلة
                unset($user['password']);
                
                // إضافة معلومات الفرع إذا كان المستخدم مرتبط بفرع
                if (!empty($user['branch_id'])) {
                    $branchModel = new Branch($db);
                    $branch = $branchModel->getById($user['branch_id']);
                    if ($branch) {
                        $user['branch_name'] = $branch['name'];
                    }
                }
                
                // إضافة عدد المتدربين المسندين
                $userModel->id = $id;
                $user['assigned_trainees_count'] = $userModel->getAssignedTraineesCount();
                
                echo json_encode([
                    'success' => true,
                    'data' => $user
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'المستخدم غير موجود'
                ], JSON_UNESCAPED_UNICODE);
            }
        } else {
            // الحصول على قائمة المستخدمين
            $conditions = [];
            
            // تطبيق الفلاتر
            $allowedFilters = ['role', 'branch_id', 'is_active'];
            foreach ($allowedFilters as $filter) {
                if (isset($_GET[$filter])) {
                    $conditions[$filter] = $_GET[$filter];
                }
            }
            
            // ترتيب النتائج
            $orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : 'id ASC';
            
            $users = $userModel->list($conditions, $orderBy);
            
            // حذف كلمات المرور من البيانات المرسلة
            foreach ($users as &$user) {
                unset($user['password']);
            }
            
            // إضافة معلومات الفروع
            $branchModel = new Branch($db);
            foreach ($users as &$user) {
                if (!empty($user['branch_id'])) {
                    $branch = $branchModel->getById($user['branch_id']);
                    if ($branch) {
                        $user['branch_name'] = $branch['name'];
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $users,
                'total' => count($users)
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'POST':
        // التحقق من الصلاحيات لإضافة مستخدم جديد
        if (!hasAccess('users', 'create')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لإضافة مستخدم جديد'
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
        $requiredFields = ['username', 'password', 'email', 'full_name', 'role'];
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
        
        // التحقق من عدم تكرار اسم المستخدم أو البريد الإلكتروني
        $existingUsers = $userModel->list(['username' => $data['username']]);
        if (!empty($existingUsers)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'اسم المستخدم موجود بالفعل'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $existingUsers = $userModel->list(['email' => $data['email']]);
        if (!empty($existingUsers)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'البريد الإلكتروني موجود بالفعل'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // إعداد بيانات المستخدم
        $userModel->username = $data['username'];
        $userModel->password = $data['password'];
        $userModel->email = $data['email'];
        $userModel->full_name = $data['full_name'];
        $userModel->phone = isset($data['phone']) ? $data['phone'] : null;
        $userModel->branch_id = isset($data['branch_id']) ? $data['branch_id'] : null;
        $userModel->role = $data['role'];
        $userModel->max_trainees = isset($data['max_trainees']) ? $data['max_trainees'] : 0;
        
        // إضافة المستخدم
        if ($userModel->create()) {
            // الحصول على بيانات المستخدم المضاف
            $newUser = $userModel->list(['username' => $data['username']])[0];
            unset($newUser['password']);
            
            echo json_encode([
                'success' => true,
                'message' => 'تم إضافة المستخدم بنجاح',
                'data' => $newUser
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء إضافة المستخدم'
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'PUT':
        // التحقق من الصلاحيات لتعديل مستخدم
        if (!hasAccess('users', 'update')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لتعديل بيانات المستخدمين'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود المعرف
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'معرف المستخدم مطلوب'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $id = (int)$_GET['id'];
        $users = $userModel->list(['id' => $id]);
        
        if (empty($users)) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'المستخدم غير موجود'
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
        
        // تحديث بيانات المستخدم
        $updateQuery = "UPDATE users SET ";
        $updateParts = [];
        $params = [];
        
        // تحديد الحقول المسموح بتحديثها
        $allowedFields = ['email', 'full_name', 'phone', 'branch_id', 'role', 'max_trainees', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateParts[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        // تحديث كلمة المرور إذا تم تقديمها
        if (isset($data['password']) && !empty($data['password'])) {
            $updateParts[] = "password = :password";
            $params[":password"] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (empty($updateParts)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'لم يتم تقديم أي بيانات للتحديث'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $updateQuery .= implode(", ", $updateParts);
        $updateQuery .= " WHERE id = :id";
        $params[":id"] = $id;
        
        $stmt = $db->prepare($updateQuery);
        
        if ($stmt->execute($params)) {
            // الحصول على بيانات المستخدم المحدثة
            $updatedUser = $userModel->list(['id' => $id])[0];
            unset($updatedUser['password']);
            
            echo json_encode([
                'success' => true,
                'message' => 'تم تحديث بيانات المستخدم بنجاح',
                'data' => $updatedUser
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء تحديث بيانات المستخدم'
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'DELETE':
        // التحقق من الصلاحيات لحذف مستخدم
        if (!hasAccess('users', 'delete')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'ليس لديك صلاحية لحذف المستخدمين'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود المعرف
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'معرف المستخدم مطلوب'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $id = (int)$_GET['id'];
        
        // التحقق من عدم حذف المستخدم الحالي
        if ($id == $_SESSION['user_id']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'لا يمكن حذف المستخدم الحالي'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // التحقق من وجود المستخدم
        $users = $userModel->list(['id' => $id]);
        
        if (empty($users)) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'المستخدم غير موجود'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // حذف المستخدم
        $query = "DELETE FROM users WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'تم حذف المستخدم بنجاح'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء حذف المستخدم'
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
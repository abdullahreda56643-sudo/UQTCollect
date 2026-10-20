<?php

/**

 * API المتدربين

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

    $traineeModel = new Trainee($db);

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

        // الحصول على المتدربين

        if (isset($_GET['id'])) {

            // الحصول على متدرب محدد

            $id = (int)$_GET['id'];

            $trainee = $traineeModel->findById($id);

            

            if ($trainee) {

                echo json_encode([

                    'success' => true,

                    'data' => $trainee

                ], JSON_UNESCAPED_UNICODE);

            } else {

                http_response_code(404);

                echo json_encode([

                    'success' => false,

                    'message' => 'المتدرب غير موجود'

                ], JSON_UNESCAPED_UNICODE);

            }

        } elseif ($action === 'search' && isset($_GET['q'])) {

            // البحث في المتدربين

            $query = trim($_GET['q']);

            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

            

            $results = $traineeModel->search($query, $limit);

            

            echo json_encode([

                'success' => true,

                'data' => $results,

                'total' => count($results)

            ], JSON_UNESCAPED_UNICODE);

        } elseif ($action === 'statistics') {

            // الحصول على إحصائيات المتدربين

            $stats = $traineeModel->getStatistics();

            

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

        } else {

            // الحصول على قائمة المتدربين مع التفاصيل

            $filters = [];

            $orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : 'created_at DESC';

            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

            

            // تطبيق الفلاتر

            $allowedFilters = ['branch_id', 'diploma_id', 'status', 'assigned_to'];

            foreach ($allowedFilters as $filter) {
                if (isset($_GET[$filter])) {
                    $filters[$filter] = $_GET[$filter];
                }
            }

            // إذا كان المستخدم موظفًا، فرض أن يعرض فقط المسندين إليه
            try {
                if (function_exists('isEmployee') && isEmployee() && !isset($filters['assigned_to'])) {
                    $filters['assigned_to'] = (int)$_SESSION['user_id'];
                }
            } catch (Throwable $e) {
                // لا نفشل الطلب بسبب خطأ بسيط في الفحص
                error_log('API trainees: failed to apply assigned_to filter: ' . $e->getMessage());
            }

            

            $trainees = $traineeModel->getTraineesWithDetails($filters, $orderBy, $limit, $offset);

            $total = count($traineeModel->getTraineesWithDetails($filters));

            

            echo json_encode([

                'success' => true,

                'data' => $trainees,

                'total' => $total,

                'limit' => $limit,

                'offset' => $offset

            ], JSON_UNESCAPED_UNICODE);

        }

        break;

        

    case 'POST':

        // التحقق من الصلاحيات

        if (!hasAccess('trainees', 'create')) {

            http_response_code(403);

            echo json_encode([

                'success' => false,

                'message' => 'ليس لديك صلاحية لإضافة متدرب'

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

        $requiredFields = ['national_id', 'full_name', 'phone', 'branch_id', 'diploma_id', 'total_amount'];

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

        $data['assigned_to'] = $data['assigned_to'] ?? $_SESSION['user_id'];

        $data['status'] = $data['status'] ?? 'under_communication';

        $data['paid_amount'] = $data['paid_amount'] ?? 0;

        $data['remaining_amount'] = $data['total_amount'] - $data['paid_amount'];

        

        // إضافة المتدرب

        $id = $traineeModel->create($data);

        

        if ($id) {

            $trainee = $traineeModel->findById($id);

            echo json_encode([

                'success' => true,

                'message' => 'تم إضافة المتدرب بنجاح',

                'data' => $trainee

            ], JSON_UNESCAPED_UNICODE);

        } else {

            http_response_code(500);

            echo json_encode([

                'success' => false,

                'message' => 'حدث خطأ أثناء إضافة المتدرب'

            ], JSON_UNESCAPED_UNICODE);

        }

        break;

        

    case 'PUT':

        // التحقق من الصلاحيات

        if (!hasAccess('trainees', 'update')) {

            http_response_code(403);

            echo json_encode([

                'success' => false,

                'message' => 'ليس لديك صلاحية لتعديل متدرب'

            ], JSON_UNESCAPED_UNICODE);

            exit;

        }

        

        // التحقق من وجود المعرف

        if (!isset($_GET['id'])) {

            http_response_code(400);

            echo json_encode([

                'success' => false,

                'message' => 'معرف المتدرب مطلوب'

            ], JSON_UNESCAPED_UNICODE);

            exit;

        }

        

        $id = (int)$_GET['id'];

        $trainee = $traineeModel->findById($id);

        

        if (!$trainee) {

            http_response_code(404);

            echo json_encode([

                'success' => false,

                'message' => 'المتدرب غير موجود'

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

        

        // تحديث المتدرب

        $result = $traineeModel->update($id, $data);

        

        if ($result) {

            // تحديث الرصيد إذا تم تغيير المبالغ

            if (isset($data['total_amount']) || isset($data['paid_amount'])) {

                $traineeModel->updateBalanceProcedure($id);

            }

            

            $trainee = $traineeModel->findById($id);

            echo json_encode([

                'success' => true,

                'message' => 'تم تحديث المتدرب بنجاح',

                'data' => $trainee

            ], JSON_UNESCAPED_UNICODE);

        } else {

            http_response_code(500);

            echo json_encode([

                'success' => false,

                'message' => 'حدث خطأ أثناء تحديث المتدرب'

            ], JSON_UNESCAPED_UNICODE);

        }

        break;

        

    case 'DELETE':

        // التحقق من الصلاحيات

        if (!hasAccess('trainees', 'delete')) {

            http_response_code(403);

            echo json_encode([

                'success' => false,

                'message' => 'ليس لديك صلاحية لحذف متدرب'

            ], JSON_UNESCAPED_UNICODE);

            exit;

        }

        

        // التحقق من وجود المعرف

        if (!isset($_GET['id'])) {

            http_response_code(400);

            echo json_encode([

                'success' => false,

                'message' => 'معرف المتدرب مطلوب'

            ], JSON_UNESCAPED_UNICODE);

            exit;

        }

        

        $id = (int)$_GET['id'];

        $trainee = $traineeModel->findById($id);

        

        if (!$trainee) {

            http_response_code(404);

            echo json_encode([

                'success' => false,

                'message' => 'المتدرب غير موجود'

            ], JSON_UNESCAPED_UNICODE);

            exit;

        }

        

        // حذف المتدرب

        $result = $traineeModel->delete($id);

        

        if ($result) {

            echo json_encode([

                'success' => true,

                'message' => 'تم حذف المتدرب بنجاح'

            ], JSON_UNESCAPED_UNICODE);

        } else {

            http_response_code(500);

            echo json_encode([

                'success' => false,

                'message' => 'حدث خطأ أثناء حذف المتدرب'

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
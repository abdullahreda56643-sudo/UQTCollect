<?php
define('BASE_PATH', realpath(dirname(__FILE__) . '/../../'));

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/db/connection.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/models/Trainee.php';
require_once BASE_PATH . '/models/User.php';

header('Content-Type: application/json');

// تمكين عرض الأخطاء للتشخيص (إزالة هذا في الإنتاج)
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // التحقق من تسجيل الدخول
    session_start();
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('يجب تسجيل الدخول أولاً');
    }

    // التحقق من صلاحيات المدير
        // Use centralized permission helper when available (allows supervisors to assign as well)
        if (!function_exists('hasAccess') || !hasAccess('trainees', 'assign')) {
            throw new Exception('عذراً، ليس لديك صلاحية لإسناد متدربين');
        }

    // التحقق من طريقة الطلب
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('طريقة الطلب غير صحيحة. يجب استخدام POST.');
    }

    // قراءة البيانات المرسلة
    $input = file_get_contents('php://input');
    if (empty($input)) {
        throw new Exception('لم يتم إرسال أي بيانات');
    }
    
        // read JSON body when available, otherwise accept form-encoded POST for backward compatibility
        $data = [];
        if (!empty($input)) {
            $decoded = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $data = $decoded;
            }
        }

        // fallback to standard $_POST if JSON not provided or failed to decode
        if (empty($data) && !empty($_POST)) {
            $data = $_POST;
        }

        if (empty($data)) {
            throw new Exception('لم يتم إرسال أي بيانات');
        }
    
    // Accept either trainee_ids (array) or trainee_id (single) or comma-separated string
    $rawTraineeIds = [];
    if (isset($data['trainee_ids'])) {
        $rawTraineeIds = $data['trainee_ids'];
    } elseif (isset($data['trainee_id'])) {
        $rawTraineeIds = $data['trainee_id'];
    }

    if (empty($rawTraineeIds) || !isset($data['trainer_id'])) {
        throw new Exception('البيانات المطلوبة غير مكتملة. يلزم معرف المتدربين والموظف.');
    }

    // normalize trainee ids into integer array
    if (is_array($rawTraineeIds)) {
        $traineeIds = array_values(array_filter(array_map('intval', $rawTraineeIds), function($v){ return $v > 0; }));
    } elseif (is_string($rawTraineeIds) && strpos($rawTraineeIds, ',') !== false) {
        $parts = array_filter(array_map('trim', explode(',', $rawTraineeIds)), function($v){ return $v !== ''; });
        $traineeIds = array_values(array_filter(array_map('intval', $parts), function($v){ return $v > 0; }));
    } else {
        $traineeIds = [(int)$rawTraineeIds];
    }
    $trainerId = (int)$data['trainer_id'];
    $notes = isset($data['notes']) ? trim($data['notes']) : '';

    if ($trainerId <= 0) {
        throw new Exception('معرف الموظف غير صحيح: ' . $trainerId);
    }

    // إنشاء اتصال قاعدة البيانات
    $db = (new Database())->getConnection();
    $db->beginTransaction();

    try {
        // التحقق من وجود الموظف
        $stmt = $db->prepare("SELECT id, full_name, role, active FROM users WHERE id = ? AND active = 1");
        $stmt->execute([$trainerId]);
        $assignedUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$assignedUser) {
            throw new Exception('الموظف غير موجود أو غير نشط بالمعرف: ' . $trainerId);
        }

        // جمع معلومات المتدربين
        $placeholders = str_repeat('?,', count($traineeIds) - 1) . '?';
        $stmt = $db->prepare("SELECT id, full_name, assigned_to FROM trainees WHERE id IN ($placeholders)");
        $stmt->execute($traineeIds);
        $trainees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($trainees) !== count($traineeIds)) {
            throw new Exception('بعض المتدربين المحددين غير موجودين');
        }

        // تحديث المتدربين
        $updateStmt = $db->prepare("UPDATE trainees SET 
                                 assigned_to = ?,
                                 assignment_date = ?,
                                 assignment_notes = ?,
                                 updated_at = ?,
                                 updated_by = ?
                                 WHERE id = ?");

        $currentTime = date('Y-m-d H:i:s');
        $assignedTrainees = [];

        foreach ($trainees as $trainee) {
            // تخطي المتدربين المسندين بالفعل لنفس الموظف
            if ($trainee['assigned_to'] == $trainerId) {
                continue;
            }

            $result = $updateStmt->execute([
                $trainerId,
                $currentTime,
                $notes,
                $currentTime,
                $_SESSION['user_id'],
                $trainee['id']
            ]);

            if (!$result) {
                throw new Exception('فشل تحديث بيانات المتدرب: ' . implode(', ', $updateStmt->errorInfo()));
            }

            $assignedTrainees[] = $trainee;
        }

        // تسجيل العملية في سجل النشاطات
            // سجل النشاطات: be tolerant if system_activities table is missing in some installs
            if (!empty($assignedTrainees)) {
                try {
                    $hasSystemActivities = $db->query("SHOW TABLES LIKE 'system_activities'")->rowCount() > 0;
                } catch (Exception $e) {
                    $hasSystemActivities = false;
                }

                if ($hasSystemActivities) {
                    try {
                        $stmt = $db->prepare("INSERT INTO system_activities (user_id, action_type, module, description, ip_address, created_at) 
                                            VALUES (?, ?, ?, ?, ?, NOW())");

                        $traineeNames = array_map(function($t) { return $t['full_name']; }, $assignedTrainees);
                        $description = sprintf(
                            'تم إسناد الموظف %s للمتدربين: %s', 
                            $assignedUser['full_name'], 
                            implode('، ', $traineeNames)
                        );

                        $stmt->execute([
                            $_SESSION['user_id'],
                            'bulk_assign_trainees',
                            'trainees',
                            $description,
                            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                        ]);
                    } catch (Exception $e) {
                        // do not fail assignment on logging errors
                        error_log('system_activities insert failed: ' . $e->getMessage());
                    }
                }
            }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'تم إسناد المتدربين بنجاح',
            'data' => [
                'assigned_count' => count($assignedTrainees),
                'assigned_to' => $trainerId,
                'assigned_name' => $assignedUser['full_name'],
                'assignment_date' => $currentTime
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Database Error in assign-trainer.php: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'حدث خطأ في قاعدة البيانات: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error in assign-trainer.php: " . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    error_log("General Error in assign-trainer.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
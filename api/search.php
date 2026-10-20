<?php
/**
 * API البحث المحسن
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.1.0
 * @author نظام إدارة المتدربين
 * @copyright 2024 جميع الحقوق محفوظة
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
require_once '../models/Communication.php';

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'يجب تسجيل الدخول أولاً'
    ]);
    exit;
}

try {
    $db = (new Database())->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في الاتصال بقاعدة البيانات'
    ]);
    exit;
}

// التحقق من وجود كلمة البحث
if (!isset($_GET['q']) || empty(trim($_GET['q']))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'كلمة البحث مطلوبة'
    ]);
    exit;
}

$query = trim($_GET['q']);
$type = $_GET['type'] ?? 'all'; // all, trainees, payments, communications
$limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 20;
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? 'employee';

// use global safeExecute() from includes/helpers.php

try {
    $results = [];
    $searchTerm = "%$query%";
    
    // تحديد الفروع المسموح بها للمستخدم
    $allowedTrainees = [];
    if ($userRole === 'admin') {
        $accessCondition = "1=1"; // المدير يرى كل شيء
        $accessParams = [];
    } else if ($userRole === 'supervisor') {
        // جلب المستخدمين تحت إشراف المشرف
        $stmt = $db->prepare("SELECT id FROM users WHERE supervisor_id = ?");
        $stmt->execute([$userId]);
        $subordinates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $subordinates[] = $userId; // إضافة المشرف نفسه
        
        if (!empty($subordinates)) {
            $placeholders = str_repeat('?,', count($subordinates) - 1) . '?';
            $accessCondition = "t.assigned_to IN ($placeholders)";
            $accessParams = $subordinates;
        } else {
            $accessCondition = "t.assigned_to = ?";
            $accessParams = [$userId];
        }
    } else {
        // الموظف العادي
        $accessCondition = "t.assigned_to = ?";
        $accessParams = [$userId];
    }
    
    switch ($type) {
        case 'trainees':
            // البحث في المتدربين
            $sql = "SELECT t.*, 
                           d.name as diploma_name,
                           b.name as branch_name,
                           COALESCE((SELECT SUM(amount) FROM payments WHERE trainee_id = t.id), 0) as total_paid
                    FROM trainees t
                    LEFT JOIN diplomas d ON t.diploma_id = d.id
                    LEFT JOIN branches b ON t.branch_id = b.id
                    WHERE ($accessCondition)
                    AND (
                        t.full_name LIKE ? 
                        OR t.national_id LIKE ?
                        OR t.phone LIKE ?
                        OR t.phone2 LIKE ?
                        OR t.notes LIKE ?
                    )
                    ORDER BY t.created_at DESC
                    LIMIT ?";
            
            $stmt = $db->prepare($sql);
            
            // دمج الباراميترات بشكل صحيح
            $params = array_merge(
                $accessParams,
                [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]
            );
            
            safeExecute($stmt, $params);
            $results['trainees'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'payments':
            // البحث في المدفوعات
            $sql = "SELECT p.*, 
                           t.full_name as trainee_name,
                           t.national_id as trainee_national_id,
                           d.name as diploma_name,
                           u.full_name as created_by_name
                    FROM payments p
                    JOIN trainees t ON p.trainee_id = t.id
                    LEFT JOIN diplomas d ON t.diploma_id = d.id
                    LEFT JOIN users u ON p.created_by = u.id
                    WHERE ($accessCondition)
                    AND (
                        p.receipt_number LIKE ? 
                        OR t.full_name LIKE ?
                        OR t.national_id LIKE ?
                        OR p.notes LIKE ?
                    )
                    ORDER BY p.payment_date DESC
                    LIMIT ?";
            
            $stmt = $db->prepare($sql);
            $params = array_merge(
                $accessParams,
                [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]
            );
            
            safeExecute($stmt, $params);
            $results['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'contacts':
            // البحث في التواصل
            $sql = "SELECT c.*, 
                           t.full_name as trainee_name,
                           t.national_id as trainee_national_id
                    FROM communications c
                    LEFT JOIN trainees t ON c.trainee_id = t.id
                    WHERE ($accessCondition)
                    AND (
                        c.notes LIKE ? 
                        OR t.full_name LIKE ?
                        OR t.national_id LIKE ?
                    )
                    ORDER BY c.contact_date DESC
                    LIMIT ?";
            
            $stmt = $db->prepare($sql);
            $params = array_merge(
                $accessParams,
                [$searchTerm, $searchTerm, $searchTerm, $limit]
            );
            
            safeExecute($stmt, $params);
            $results['contacts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'all':
        default:
            // البحث في المتدربين
            $sql = "SELECT t.*, 
                           d.name as diploma_name,
                           b.name as branch_name,
                           COALESCE((SELECT SUM(amount) FROM payments WHERE trainee_id = t.id), 0) as total_paid
                    FROM trainees t
                    LEFT JOIN diplomas d ON t.diploma_id = d.id
                    LEFT JOIN branches b ON t.branch_id = b.id
                    WHERE ($accessCondition)
                    AND (
                        t.full_name LIKE ? 
                        OR t.national_id LIKE ?
                        OR t.phone LIKE ?
                        OR t.phone2 LIKE ?
                        OR t.notes LIKE ?
                    )
                    ORDER BY t.created_at DESC
                    LIMIT ?";
            
            $stmt = $db->prepare($sql);
            $params = array_merge(
                $accessParams,
                [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]
            );
            
            safeExecute($stmt, $params);
            $results['trainees'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // البحث في المدفوعات
            $sql = "SELECT p.*, 
                           t.full_name as trainee_name,
                           t.national_id as trainee_national_id,
                           d.name as diploma_name,
                           u.full_name as created_by_name
                    FROM payments p
                    JOIN trainees t ON p.trainee_id = t.id
                    LEFT JOIN diplomas d ON t.diploma_id = d.id
                    LEFT JOIN users u ON p.created_by = u.id
                    WHERE ($accessCondition)
                    AND (
                        p.receipt_number LIKE ? 
                        OR t.full_name LIKE ?
                        OR t.national_id LIKE ?
                        OR p.notes LIKE ?
                    )
                    ORDER BY p.payment_date DESC
                    LIMIT ?";
            
            $stmt = $db->prepare($sql);
            $params = array_merge(
                $accessParams,
                [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]
            );
            
            safeExecute($stmt, $params);
            $results['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // البحث في التواصل
            $sql = "SELECT c.*, 
                           t.full_name as trainee_name,
                           t.national_id as trainee_national_id
                    FROM communications c
                    LEFT JOIN trainees t ON c.trainee_id = t.id
                    WHERE ($accessCondition)
                    AND (
                        c.notes LIKE ? 
                        OR t.full_name LIKE ?
                        OR t.national_id LIKE ?
                    )
                    ORDER BY c.contact_date DESC
                    LIMIT ?";
            
            $stmt = $db->prepare($sql);
            $params = array_merge(
                $accessParams,
                [$searchTerm, $searchTerm, $searchTerm, $limit]
            );
            
            safeExecute($stmt, $params);
            $results['contacts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    // إضافة إحصائيات
    $totalResults = 0;
    foreach ($results as $typeResults) {
        $totalResults += count($typeResults);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $results,
        'query' => $query,
        'type' => $type,
        'total_results' => $totalResults,
        'limit' => $limit
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Search API Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ في البحث',
        'error' => ENVIRONMENT === 'development' ? $e->getMessage() : null
    ], JSON_UNESCAPED_UNICODE);
}
?>
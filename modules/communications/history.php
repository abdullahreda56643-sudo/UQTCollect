<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../models/Communication.php';
require_once './communications_view.php';

// التحقق من تسجيل الدخول وصلاحية الوصول
checkPermission('employee');

$db = (new Database())->getConnection();
$commModel = new Communication($db);

$trainee_id = isset($_GET['trainee_id']) ? intval($_GET['trainee_id']) : 0;
$search = trim($_GET['q'] ?? '');

$communications = [];
$traineeInfo = null;

try {
    if ($trainee_id > 0) {
        // Get trainee info first
        $stmt = $db->prepare("SELECT id, full_name FROM trainees WHERE id = ?");
        $stmt->execute([$trainee_id]);
        $traineeInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get communications
        $stmt = $db->prepare("SELECT c.*, u.full_name as employee_name 
                             FROM communications c 
                             LEFT JOIN users u ON c.created_by = u.id 
                             WHERE c.trainee_id = ? 
                             ORDER BY c.contact_date DESC, c.created_at DESC");
        $stmt->execute([$trainee_id]);
        $communications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // بحث بسيط في الملاحظات ومعرف المتدرب
        if ($search !== '') {
            $stmt = $db->prepare("SELECT c.*, u.full_name as employee_name,
                                        t.full_name as trainee_name
                                 FROM communications c 
                                 LEFT JOIN users u ON c.created_by = u.id
                                 LEFT JOIN trainees t ON c.trainee_id = t.id
                                 WHERE c.notes LIKE ? OR c.trainee_id = ? OR t.full_name LIKE ?
                                 ORDER BY c.contact_date DESC, c.created_at DESC 
                                 LIMIT 100");
            $searchParam = '%' . $search . '%';
            $stmt->execute([$searchParam, (int)$search, $searchParam]);
            $communications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) {
    error_log('Failed to load communications history: ' . $e->getMessage());
    $communications = [];
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سجل التواصل <?php echo $traineeInfo ? '- ' . htmlspecialchars($traineeInfo['full_name']) : ''; ?></title>
    <link rel="stylesheet" href="../../public/assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200">
    <div class="container mx-auto px-4 py-8">
        <?php echo render_communications_list($communications, $traineeInfo, true); ?>
    </div>
</body>
</html>

</body>
</html>

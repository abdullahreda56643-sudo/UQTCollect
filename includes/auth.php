<?php
/**
 * وظائف المصادقة والصلاحيات المحسنة
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.0.0
 * @author نظام إدارة المتدربين
 * @copyright 2024 جميع الحقوق محفوظة
 */


// التحقق من صحة الجلسة المحسن
function isLoggedIn() {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return false;
    }
    
    // التحقق من انتهاء صلاحية الجلسة
    $session_timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800; // 30 دقيقة افتراضياً
    if (!isset($_SESSION['last_activity']) || (time() - $_SESSION['last_activity'] > $session_timeout)) {
        session_unset();
        session_destroy();
        return false;
    }
    
    // تحديث وقت آخر نشاط
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Safe redirect helper: use header() when headers not yet sent, otherwise output JS/meta fallback.
 */
function safeRedirect($url, $statusCode = 302) {
    // Ensure absolute URL
    $url = (string)$url;
    if (!headers_sent()) {
        header('Location: ' . $url, true, $statusCode);
        exit;
    }
    // Fallback: output small HTML page that redirects via JS and meta-refresh
    $escaped = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><meta http-equiv=\"refresh\" content=\"0;url={$escaped}\">";
    echo "<script>window.location.href=\"{$escaped}\";</script></head><body>";
    echo "<a href=\"{$escaped}\">تابع إلى الصفحة</a>";
    echo "</body></html>";
    exit;
}

// التحقق من صلاحية المدير المحسن
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'admin' && isLoggedIn();
}

// التحقق من صلاحية المشرف المحسن
function isSupervisor() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'supervisor' && isLoggedIn();
}

// التحقق من صلاحية الموظف المحسن
function isEmployee() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'employee' && isLoggedIn();
}

// التحقق من الصلاحيات المحسن
function checkPermission($requiredRole = 'user') {
    if (!isLoggedIn()) {
        $_SESSION['error_message'] = 'يجب تسجيل الدخول أولاً';
        $loginUrl = rtrim(SITE_URL, '/') . '/modules/auth/login.php';
        safeRedirect($loginUrl);
    }
    
    $hasPermission = false;
    
    switch ($requiredRole) {
        case 'admin':
            $hasPermission = isAdmin();
            break;
        case 'supervisor':
            $hasPermission = isAdmin() || isSupervisor();
            break;
        case 'employee':
            $hasPermission = isAdmin() || isSupervisor() || isEmployee();
            break;
        case 'user':
            $hasPermission = true;
            break;
        default:
            $hasPermission = false;
            break;
    }
    
    if (!$hasPermission) {
        $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
        safeRedirect(rtrim(SITE_URL, '/') . '/index.php');
    }
    
    return true;
}

// التحقق من صلاحية الوصول إلى المورد
/**
 * التحقق من صلاحية الوصول إلى المورد - النسخة المحسنة
 */
function hasAccess($resource, $action = 'view') {
    if (!isLoggedIn()) {
        return false;
    }
    
    // إذا كان المستخدم مديراً، يعطى جميع الصلاحيات *باستثناء* إجراء خاص مثل 'delete_all'
    // (نريد أن يكون 'delete_all' خاضعًا لصلاحية قابلة للتبديل بدلًا من تجاوزها تلقائيًا للمدير)
    if ($action !== 'delete_all' && isAdmin()) {
        return true;
    }
    
    // Normalize action names (allow 'create' and 'add' synonyms)
    $action = strtolower($action);
    if ($action === 'add') $action = 'create';
    if ($action === 'manage') $action = 'manage_permissions';

    // Normalize resource names that vary between modules and DB/role defaults
    // Accept common synonyms and map them to the canonical module name used in DB/role defaults
    // e.g. 'communications' or 'contacts' -> 'communication'
    // also tolerate singular/plural mismatches like 'trainee' -> 'trainees'
    $resource = strtolower($resource);
    $resourceMap = [
        'communications' => 'communication',
        'contacts' => 'communication',
        'communication' => 'communication',
        'trainee' => 'trainees',
        'trainees' => 'trainees',
        'diploma' => 'diplomas',
        'diplomas' => 'diplomas'
    ];
    if (isset($resourceMap[$resource])) {
        $resource = $resourceMap[$resource];
    }

    // First, try to read explicit per-user permissions from DB (if a row exists for this user & resource)
    static $userPermCache = [];
    $userId = $_SESSION['user_id'];
    $cacheKey = $userId . '|' . $resource;
    
    if (!isset($userPermCache[$cacheKey])) {
        // Lazy-load DB and query user_permissions table
        try {
            require_once __DIR__ . '/../db/connection.php';
            $db = (new Database())->getConnection();
            
            // select extended permissions if available
         $stmt = $db->prepare("SELECT can_view, can_add, can_edit, can_delete, 
                          IFNULL(can_export,0) as can_export, 
                          IFNULL(can_import,0) as can_import, 
                          IFNULL(can_toggle,0) as can_toggle, 
                          IFNULL(can_manage_permissions,0) as can_manage_permissions, 
                          IFNULL(can_delete_all,0) as can_delete_all, 
                          IFNULL(can_assign,0) as can_assign 
                      FROM user_permissions 
                      WHERE user_id = ? AND module = ? LIMIT 1");
            $stmt->execute([$userId, $resource]);
            
            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $userPermCache[$cacheKey] = $row;
            } else {
                $userPermCache[$cacheKey] = null;
            }
        } catch (Exception $e) {
            // DB unavailable or other error - just fall back to role defaults
            error_log('hasAccess DB check failed: ' . $e->getMessage());
            $userPermCache[$cacheKey] = null;
        }
    }

    $row = $userPermCache[$cacheKey];

    // إذا كانت هناك صلاحيات محددة للمستخدم في قاعدة البيانات، نستخدمها
    if (!empty($row) && is_array($row)) {
        switch ($action) {
            case 'view':
                return !empty($row['can_view']);
            case 'create':
            case 'add':
                return !empty($row['can_add']);
            case 'edit':
                return !empty($row['can_edit']);
            case 'delete':
                return !empty($row['can_delete']);
            case 'export':
                return !empty($row['can_export']);
            case 'import':
                return !empty($row['can_import']);
            case 'toggle':
                return !empty($row['can_toggle']);
            case 'assign':
                return !empty($row['can_assign']);
            case 'manage_permissions':
            case 'manage':
                return !empty($row['can_manage_permissions']);
            case 'delete_all':
                return !empty($row['can_delete_all']);
            default:
                // إذا لم يكن الإجراء معروفاً، نستخدم الصلاحية الافتراضية للعرض
                return !empty($row['can_view']);
        }
    }

    // Fallback to role-based default permissions (only if no specific permissions found)
    $role = $_SESSION['role'];
    $permissions = [
        'admin' => [
            'users' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'toggle', 'manage_permissions'],
            'trainees' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'toggle', 'manage_permissions', 'assign'],
            'payments' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'toggle', 'manage_permissions'],
            'reports' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'toggle', 'manage_permissions'],
            'communication' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'toggle', 'manage_permissions'],
            'tasks' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'toggle', 'manage_permissions'],
            'branches' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'toggle', 'manage_permissions'],
            'diplomas' => ['view', 'create', 'edit', 'delete', 'export', 'import', 'toggle', 'manage_permissions'],
        ],
        'supervisor' => [
            'users' => ['view', 'edit'],
            'trainees' => ['view', 'create', 'edit', 'export', 'toggle', 'assign'],
            'payments' => ['view', 'create', 'export'],
            'reports' => ['view', 'export'],
            'communication' => ['view', 'create', 'edit'],
            'tasks' => ['view', 'create', 'edit', 'toggle'],
            'branches' => ['view'],
            'diplomas' => ['view', 'export'],
        ],
        'employee' => [
            'users' => ['view'],
            'trainees' => ['view', 'create'],
            'payments' => ['view', 'create'],
            'reports' => ['view'],
            'communication' => ['view', 'create'],
            'tasks' => ['view', 'create'],
            'branches' => ['view'],
            'diplomas' => ['view'],
        ]
    ];

    return isset($permissions[$role][$resource]) && in_array($action, $permissions[$role][$resource]);
}

/**
 * Require that the current user is either admin or has the given resource/action permission.
 * If not allowed, redirect with an error.
 */
function requireAccessOrAdmin($resource, $action = 'view') {
    if (!isLoggedIn()) {
        $_SESSION['error_message'] = 'يجب تسجيل الدخول أولاً';
        safeRedirect(rtrim(SITE_URL, '/') . '/modules/auth/login.php');
    }

    if (isAdmin() || hasAccess($resource, $action)) {
        return true;
    }

    $_SESSION['error_message'] = 'ليس لديك صلاحية للوصول إلى هذه الصفحة';
    safeRedirect(rtrim(SITE_URL, '/') . '/index.php');
}


/**
 * التحقق من صلاحية المستخدم على متدرب معين - النسخة المحسنة
 */
function canAccessTrainee($traineeId, $db) {
    // إذا كان المدير، له صلاحية على الجميع
    if (isAdmin()) {
        return true;
    }
    
    // التحقق من أن المتدرب موجود
    $query = "SELECT assigned_to, branch_id FROM trainees WHERE id = :trainee_id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':trainee_id', $traineeId, PDO::PARAM_INT);
    $stmt->execute();
    $trainee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$trainee) {
        return false;
    }
    
    // إذا كان المستخدم موظفاً، يجب أن يكون المسند له
    if (isEmployee()) {
        return $trainee['assigned_to'] == $_SESSION['user_id'];
    }
    
    // إذا كان المستخدم مشرفاً، يجب أن يكون المتدرب ضمن فرعه
    if (isSupervisor()) {
        // Allow if branch matches (fast path)
        if (isset($_SESSION['branch_id']) && $trainee['branch_id'] == $_SESSION['branch_id']) {
            return true;
        }

        // Also allow if the trainee is assigned to one of the supervisor's employees (team members)
        try {
            $employeeIds = getEmployeesUnderSupervisor($db, (int)($_SESSION['user_id'] ?? 0));
            // supervisor may also be directly assigned
            $employeeIds[] = (int)($_SESSION['user_id'] ?? 0);
            if (!empty($employeeIds) && in_array((int)$trainee['assigned_to'], $employeeIds, true)) {
                return true;
            }
        } catch (Exception $e) {
            error_log('canAccessTrainee getEmployeesUnderSupervisor failed: ' . $e->getMessage());
        }

        // otherwise deny
        return false;
    }
    
    return false;
}

// تسجيل نشاط المستخدم
function logActivity($db, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
    if (!ENABLE_AUDIT_LOGGING) {
        // حتى لو كان تسجيل النشاط معطلاً، نريد إنشاء إشعارات
        createActivityNotifications($db, $action, $tableName, $recordId);
        return;
    }
    
    $query = "INSERT INTO audit_logs 
              (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
              VALUES 
              (:user_id, :action, :table_name, :record_id, :old_values, :new_values, :ip_address, :user_agent)";
    
    $stmt = $db->prepare($query);
    
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    // تحويل القيم إلى JSON إذا كانت مصفوفات
    if (is_array($oldValues) || is_object($oldValues)) {
        $oldValues = json_encode($oldValues, JSON_UNESCAPED_UNICODE);
    }
    if (is_array($newValues) || is_object($newValues)) {
        $newValues = json_encode($newValues, JSON_UNESCAPED_UNICODE);
    }

    // استخدم bindValue حتى نتجنب مشاكل تحويل الأنواع
    $stmt->bindValue(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':action', $action, PDO::PARAM_STR);
    $stmt->bindValue(':table_name', $tableName, PDO::PARAM_STR);
    $stmt->bindValue(':record_id', $recordId !== null ? $recordId : null, PDO::PARAM_INT);
    $stmt->bindValue(':old_values', $oldValues, PDO::PARAM_STR);
    $stmt->bindValue(':new_values', $newValues, PDO::PARAM_STR);
    $stmt->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);
    $stmt->bindValue(':user_agent', $userAgent, PDO::PARAM_STR);
    
    try {
        $stmt->execute();
        
        // إنشاء إشعارات للمستخدمين عند تسجيل النشاط
        createActivityNotifications($db, $action, $tableName, $recordId);
        
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}



/**
 * التحقق من أن المستخدم له دور محدد
 */
function checkRole($requiredRole) {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    // إذا كان المستخدم مديراً، يمكنه كل شيء
    if ($_SESSION['user_role'] === 'admin') {
        return true;
    }
    
    // التحقق من الأدوار الأخرى
    return $_SESSION['user_role'] === $requiredRole;
}

/**
 * الحصول على دور المستخدم الحالي
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}


// إنشاء إشعارات للمستخدمين عند تسجيل نشاط
function createActivityNotifications($db, $action, $tableName = null, $recordId = null) {
    // التأكد من تضمين ملف الإشعارات
    if (!function_exists('createActivityAlert')) {
        require_once __DIR__ . '/notifications.php';
    }
    
    // تحديد نوع الإشعار بناءً على الإجراء والجدول
    $notificationType = null;
    $notificationMessage = null;
    $targetUsers = [];
    
    // تحديد نوع الإشعار والرسالة والمستخدمين المستهدفين
    switch ($tableName) {
        case 'trainees':
            if ($action == 'insert') {
                $notificationType = 'trainee_added';
                $notificationMessage = 'تم إضافة متدرب جديد';
                // إشعار للمشرفين والمدراء
                $targetUsers = getUsersByRoles($db, ['admin', 'supervisor']);
            } elseif ($action == 'update') {
                $notificationType = 'trainee_updated';
                $notificationMessage = 'تم تحديث بيانات متدرب';
                // إشعار للمشرف المسؤول والمدراء
                $targetUsers = getTraineeResponsibles($db, $recordId);
            }
            break;
            
        case 'payments':
            if ($action == 'insert') {
                $notificationType = 'payment_added';
                $notificationMessage = 'تم تسجيل دفعة جديدة';
                // إشعار للمشرفين والمدراء
                $targetUsers = getUsersByRoles($db, ['admin', 'supervisor']);
            }
            break;
            
        case 'tasks':
            if ($action == 'insert') {
                $notificationType = 'task_added';
                $notificationMessage = 'تم إضافة مهمة جديدة';
                // إشعار للمستخدم المسند إليه المهمة
                $targetUsers = getTaskAssignees($db, $recordId);
            } elseif ($action == 'update') {
                $notificationType = 'task_updated';
                $notificationMessage = 'تم تحديث مهمة';
                // إشعار للمستخدم المسند إليه المهمة
                $targetUsers = getTaskAssignees($db, $recordId);
            }
            break;
    }
    
   
}



// الحصول على المستخدمين حسب الأدوار
function getUsersByRoles($db, $roles) {
    $placeholders = str_repeat('?,', count($roles) - 1) . '?';
    $query = "SELECT id FROM users WHERE role IN ($placeholders)";
    
    $stmt = $db->prepare($query);
    $stmt->execute($roles);
    
    $userIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $userIds[] = $row['id'];
    }
    
    return $userIds;
}

// الحصول على المسؤولين عن متدرب (المشرف والموظف المسند إليه)
function getTraineeResponsibles($db, $traineeId) {
    $query = "SELECT u.id 
             FROM trainees t 
             INNER JOIN users u ON u.branch_id = t.branch_id OR u.id = t.assigned_to 
             WHERE t.id = ? AND u.role IN ('admin', 'supervisor', 'employee')";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$traineeId]);
    
    $userIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $userIds[] = $row['id'];
    }
    
    return $userIds;
}

// الحصول على المستخدمين المسند إليهم مهمة
function getTaskAssignees($db, $taskId) {
    $query = "SELECT assigned_to FROM tasks WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$taskId]);
    
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? [$row['assigned_to']] : [];
}

// تسجيل الدخول
function loginUser($db, $username, $password) {
    $query = "SELECT * FROM users WHERE username = :username AND status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        return ['success' => false, 'message' => 'اسم المستخدم غير موجود أو الحساب معطل'];
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // التحقق من كلمة المرور
    if (!password_verify($password, $user['password'])) {
        // تسجيل محاولة فاشلة
        incrementFailedLogins($db, $user['id']);
        return ['success' => false, 'message' => 'كلمة المرور غير صحيحة'];
    }
    
    // إعادة تعيين عدد محاولات تسجيل الدخول الفاشلة
    resetFailedLogins($db, $user['id']);
    
    // تحديث آخر تسجيل دخول
    updateLastLogin($db, $user['id']);
    
    // إنشاء جلسة المستخدم
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['branch_id'] = $user['branch_id'];
    $_SESSION['last_activity'] = time();
    
    // تسجيل نشاط تسجيل الدخول
    logActivity($db, 'login', 'users', $user['id']);
    // تسجيل النشاط في system_activities لظهوره في لوحة التحكم
    if (function_exists('logActivity')) {
        logActivity($db, $user['id'], 'login', 'users');
    }
    
    return ['success' => true, 'user' => $user];
}

// زيادة عدد محاولات تسجيل الدخول الفاشلة
function incrementFailedLogins($db, $userId) {
    $query = "UPDATE users SET failed_logins = failed_logins + 1 WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();
    
    // التحقق مما إذا كان يجب قفل الحساب
    $query = "SELECT failed_logins FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user['failed_logins'] >= MAX_LOGIN_ATTEMPTS) {
        // قفل الحساب
        $query = "UPDATE users SET status = 'locked', locked_at = NOW() WHERE id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        
        // تسجيل نشاط قفل الحساب
        logActivity($db, 'account_locked', 'users', $userId);
        // تسجيل النشاط في system_activities لظهوره في لوحة التحكم
        if (function_exists('logActivity')) {
            logActivity($db, $userId, 'account_locked', 'users');
        }
    }
}

// إعادة تعيين عدد محاولات تسجيل الدخول الفاشلة
function resetFailedLogins($db, $userId) {
    $query = "UPDATE users SET failed_logins = 0 WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();
}

// تحديث آخر تسجيل دخول
function updateLastLogin($db, $userId) {
    $query = "UPDATE users SET last_login = NOW() WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();
}

// تسجيل الخروج
function logoutUser($db) {
    // تسجيل نشاط تسجيل الخروج
    if (isset($_SESSION['user_id'])) {
        logActivity($db, 'logout', 'users', $_SESSION['user_id']);
        // تسجيل النشاط في system_activities لظهوره في لوحة التحكم
        if (function_exists('logActivity')) {
            logActivity($db, $_SESSION['user_id'], 'logout', 'users');
        }
    }
    
    // تدمير الجلسة
    session_unset();
    session_destroy();
    
    return true;
}

// إنشاء رمز إعادة تعيين كلمة المرور
function createPasswordResetToken($db, $email) {
    $query = "SELECT * FROM users WHERE email = :email AND status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        return ['success' => false, 'message' => 'البريد الإلكتروني غير موجود أو الحساب معطل'];
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // إنشاء رمز فريد
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    // حفظ الرمز في قاعدة البيانات
    $query = "INSERT INTO password_resets (user_id, token, expires_at) 
              VALUES (:user_id, :token, :expires_at)";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user['id']);
    $stmt->bindParam(':token', $token);
    $stmt->bindParam(':expires_at', $expires);
    $stmt->execute();
    
    // تسجيل نشاط طلب إعادة تعيين كلمة المرور
    logActivity($db, 'password_reset_request', 'users', $user['id']);
    
    return [
        'success' => true, 
        'token' => $token, 
        'user' => $user,
        'expires' => $expires
    ];
}

// التحقق من صحة رمز إعادة تعيين كلمة المرور
function validatePasswordResetToken($db, $token) {
    $query = "SELECT pr.*, u.* 
              FROM password_resets pr 
              INNER JOIN users u ON u.id = pr.user_id 
              WHERE pr.token = :token AND pr.expires_at > NOW() AND pr.used = 0";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        return ['success' => false, 'message' => 'الرمز غير صالح أو منتهي الصلاحية'];
    }
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return ['success' => true, 'user' => $result];
}

// إعادة تعيين كلمة المرور
function resetPassword($db, $token, $newPassword) {
    // التحقق من صحة الرمز
    $validation = validatePasswordResetToken($db, $token);
    
    if (!$validation['success']) {
        return $validation;
    }
    
    $user = $validation['user'];
    
    // تحديث كلمة المرور
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $query = "UPDATE users SET password = :password WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':password', $hashedPassword);
    $stmt->bindParam(':user_id', $user['user_id']);
    $stmt->execute();
    
    // تحديث حالة الرمز
    $query = "UPDATE password_resets SET used = 1, used_at = NOW() WHERE token = :token";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    
    // تسجيل نشاط إعادة تعيين كلمة المرور
    logActivity($db, 'password_reset', 'users', $user['user_id']);
    
    return ['success' => true, 'message' => 'تم إعادة تعيين كلمة المرور بنجاح'];
}

// الحصول على الصورة الرمزية الافتراضية حسب الدور
function getRoleDefaultAvatar($role) {
    $avatars = [
        'admin' => 'admin_avatar.png',
        'supervisor' => 'supervisor_avatar.png',
        'employee' => 'employee_avatar.png',
        'user' => 'user_avatar.png'
    ];
    
    return isset($avatars[$role]) ? $avatars[$role] : $avatars['user'];
}

// الحصول على قيمة إعداد
function getSettingValue($key, $default = null) {
    global $db;
    
    $query = "SELECT value FROM settings WHERE `key` = :key";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':key', $key);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['value'];
    }
    
    return $default;
}



// تحديث قيمة إعداد
function updateSettingValue($key, $value) {
    global $db;
    
    $query = "INSERT INTO settings (`key`, value) 
              VALUES (:key, :value) 
              ON DUPLICATE KEY UPDATE value = :value";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':key', $key);
    $stmt->bindParam(':value', $value);
    
    return $stmt->execute();
}

// الحصول على عنوان URL للملف المرفق
function getAttachmentUrl($filename) {
    if (empty($filename)) {
        return null;
    }
    
    $key = 'attachment_base_url';
    $url = getSettingValue($key, '');
    return $url ?: null;
}

// التحقق من صحة رمز CSRF


// الحصول على عنوان URL بعد تسجيل الدخول
function getPostLoginRedirectUrl() {
    // التحقق من وجود URL مخزن في الجلسة للتوجيه بعد تسجيل الدخول
    if (isset($_SESSION['redirect_after_login']) && !empty($_SESSION['redirect_after_login'])) {
        $url = $_SESSION['redirect_after_login'];
        unset($_SESSION['redirect_after_login']); // حذف المتغير من الجلسة بعد استخدامه
        return $url;
    }
    
    // التوجيه حسب دور المستخدم
    if (isset($_SESSION['role'])) {
        switch ($_SESSION['role']) {
            case 'admin':
                return rtrim(SITE_URL, '/') . '/views/dashboard/admin.php';
            case 'supervisor':
                return rtrim(SITE_URL, '/') . '/views/dashboard/index.php';
            case 'employee':
                return rtrim(SITE_URL, '/') . '/views/dashboard/employee.php';
            default:
                return rtrim(SITE_URL, '/') . '/index.php';
        }
    }
    
    // التوجيه الافتراضي
    return rtrim(SITE_URL, '/') . '/index.php';
}


<?php
/**
 * ملف الدوال المساعدة المحسن للنظام
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.1.1
 * @author نظام إدارة المتدربين
 * @copyright 2024 جميع الحقوق محفوظة
 */

/**
 * توليد توكن CSRF ثابت للجلسة
 * يتم إنشاء التوكن مرة واحدة للجلسة ويبقى ثابتاً
 * @return string التوكن الثابت
 */

/**
 



 * دالة للحصول على مسار الصورة الشخصية للمستخدم
 * @param int $userId معرف المستخدم
 * @return string مسار الصورة الشخصية
 */
function getAvatarUrl($userId) {
    $avatarPath = "uploads/avatars/{$userId}.jpg";
    if (file_exists($avatarPath)) {
        return $avatarPath;
    }
    return "public/assets/images/default-avatar.png";
}







/**
 * دالة للحصول على رمز العملة المستخدمة في النظام
 * 
 * @return string رمز العملة
 */
function getCurrencySymbol() {
    return 'ر.س'; // يمكن تخصيصها من الإعدادات لاحقاً
}

/**
 * تنسيق المبلغ المالي بالعملة المحددة
 *
 * @param float $amount المبلغ المراد تنسيقه
 * @param bool $withSymbol إضافة رمز العملة (اختياري)
 * @return string المبلغ المنسق
 */
function formatCurrency($amount, $withSymbol = true) {
    $formattedAmount = number_format((float)$amount, 2, '.', ',');
    return $withSymbol ? $formattedAmount . ' ' . getCurrencySymbol() : $formattedAmount;
}

/**
 * إنشاء إشعار للمستخدم
 *
 * @param PDO $db قاعدة البيانات
 * @param int $userId معرف المستخدم
 * @param string $title عنوان الإشعار
 * @param string $message نص الإشعار
 * @param string $type نوع الإشعار (success, info, warning, error)
 * @param string|null $link رابط إضافي (اختياري)
 * @return bool
 */
function createUserAlert($db, $userId, $title, $message, $type = 'info', $link = null) {
    // Ensure session is active when checking actor info (some CLI/scripts may not have started session)
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $sql = "INSERT INTO notifications (user_id, type, title, message, link, created_at) 
            VALUES (:user_id, :type, :title, :message, :link, CURRENT_TIMESTAMP)";

    $stmt = $db->prepare($sql);
    $ok = $stmt->execute([
        ':user_id' => $userId,
        ':type' => $type,
        ':title' => $title,
        ':message' => $message,
        ':link' => $link
    ]);

    // إذا كان الفاعل في الجلسة موظفاً (ليس مدير) فنبث نسخة للمدراء
    try {
        if ($ok && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            $actorId = (int)$_SESSION['user_id'];
            // جلب دور الممثل
            $roleStmt = $db->prepare('SELECT role, CONCAT(first_name, " ", last_name) AS full_name FROM users WHERE id = :id LIMIT 1');
            $roleStmt->execute([':id' => $actorId]);
            $actor = $roleStmt->fetch(PDO::FETCH_ASSOC);
            $actorRole = $actor['role'] ?? null;
            $actorName = $actor['full_name'] ?? null;

            if ($actorRole && strtolower($actorRole) !== 'admin') {
                // أرسل نسخة لكل مدراء/مشرفين نشطين
                $adminsStmt = $db->prepare("SELECT id FROM users WHERE role IN ('admin','supervisor') AND is_active = 1");
                $adminsStmt->execute();
                $admins = $adminsStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $adminId) {
                    // لا نرسل نسخة إلى نفس المستخدم إذا كان هو مدير
                    if ((int)$adminId === $actorId) continue;
                    $adminTitle = $title;
                    $adminMsg = ($actorName ? ($actorName . ': ') : '') . $message;
                    $ins = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (:uid, :type, :title, :message, :link, CURRENT_TIMESTAMP)");
                    $ins->execute([':uid' => $adminId, ':type' => $type, ':title' => $adminTitle, ':message' => $adminMsg, ':link' => $link]);
                }
            }
        }
    } catch (Exception $e) {
        error_log('Error broadcasting user alert to admins: ' . $e->getMessage());
    }

    return $ok;
}

/**
 * الحصول على قائمة المشرفين
 *
 * @param PDO $db قاعدة البيانات
 * @return array قائمة بمعرفات المشرفين
 */
function getSupervisors($db) {
    $stmt = $db->prepare("SELECT id FROM users WHERE role IN ('supervisor', 'admin') AND is_active = 1");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * الحصول على معرفات الموظفين التابعين لمشرف معين
 * @param PDO $db
 * @param int $supervisorId
 * @return array
 */
function getEmployeesUnderSupervisor($db, $supervisorId) {
    try {
        // Check whether the users table has a supervisor_id column (some installs don't)
        $colStmt = $db->prepare("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'supervisor_id'");
        $colStmt->execute();
        $hasSupervisorCol = (int)($colStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) > 0;

        if ($hasSupervisorCol) {
            $stmt = $db->prepare("SELECT id FROM users WHERE supervisor_id = ? AND is_active = 1");
            $stmt->execute([(int)$supervisorId]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return $rows ?: [];
        }

        // Fallback: if there's no supervisor_id column, try to scope by the supervisor's branch.
        // Many parts of the code also use "employees who have trainees in the supervisor's branch"
        $bstmt = $db->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
        $bstmt->execute([(int)$supervisorId]);
        $branchId = $bstmt->fetchColumn();
        if (empty($branchId)) {
            return [];
        }

        // Find active employees who have trainees in that branch
        $stmt = $db->prepare("SELECT DISTINCT u.id FROM users u INNER JOIN trainees t ON t.assigned_to = u.id WHERE t.branch_id = ? AND u.role = 'employee' AND u.is_active = 1");
        $stmt->execute([(int)$branchId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: [];
    } catch (Exception $e) {
        error_log('getEmployeesUnderSupervisor error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Safely execute a prepared statement with automatic normalization between
 * positional (?) and named (:name) placeholders.
 *
 * This helper tries to avoid SQLSTATE[HY093] by:
 * - re-indexing numeric-keyed arrays to sequential values for positional placeholders
 * - mapping numeric arrays to named placeholders by order when the SQL uses named placeholders
 * - converting associative arrays to positional arrays when SQL uses ? placeholders
 *
 * It logs a helpful message when counts mismatch so we can diagnose problems.
 *
 * @param PDOStatement $stmt
 * @param array|null $params
 * @return bool
 */
function safeExecute($stmt, $params = null) {
    try {
        if (empty($params)) {
            return $stmt->execute();
        }

        $hasStringKeys = false;
        $hasNumericKeys = false;
        foreach ($params as $k => $v) {
            if (is_int($k)) $hasNumericKeys = true; else $hasStringKeys = true;
        }

        $sql = '';
        if (isset($stmt->queryString)) $sql = $stmt->queryString;
        $hasNamed = preg_match_all('/:([a-zA-Z0-9_]+)/', $sql, $namedMatches);
        $hasPositional = strpos($sql, '?') !== false;

        // If SQL has named placeholders but params is a numeric array, map by placeholder order
        if ($hasNamed && $hasNumericKeys && !$hasStringKeys) {
            $placeholders = $namedMatches[1] ?? [];
            $vals = array_values($params);
            if (count($placeholders) !== count($vals)) {
                error_log("safeExecute: placeholder count (" . count($placeholders) . ") does not match params count (" . count($vals) . ") for SQL: " . $sql);
                // try best-effort mapping of what's available
            }
            $assoc = [];
            for ($i = 0; $i < count($placeholders); $i++) {
                $assoc[':' . $placeholders[$i]] = $vals[$i] ?? null;
            }
            return $stmt->execute($assoc);
        }

        // If SQL has positional placeholders but params is associative, convert to positional
        if ($hasPositional && $hasStringKeys && !$hasNumericKeys) {
            $vals = array_values($params);
            return $stmt->execute($vals);
        }

        // If numeric keys exist but are not sequential, reindex to avoid PDO confusion
        if ($hasNumericKeys) {
            $params = array_values($params);
        }

        return $stmt->execute($params);
    } catch (Exception $e) {
        error_log('safeExecute exception: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * دالة لتنسيق التاريخ بالصيغة المناسبة للعرض
 * 
 * @param string $date التاريخ بصيغة Y-m-d H:i:s
 * @return string التاريخ المنسق
 */
function format_date($date) {
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return 'غير محدد';
    }
    
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return 'تاريخ غير صالح';
    }
    
    return date('Y-m-d', $timestamp);
}

/**
 * تنسيق التاريخ والوقت بالشكل المناسب للعرض
 * @param string $datetime التاريخ والوقت المراد تنسيقه
 * @param bool $showTime عرض الوقت مع التاريخ (اختياري)
 * @return string التاريخ والوقت المنسق
 */
if (!function_exists('formatDateTime')) {
    function formatDateTime($datetime, $showTime = true) {
        if (empty($datetime)) {
            return 'غير محدد';
        }
        $s = trim((string)$datetime);

        // Handle cases like "11 00:00" (day + time) by assuming current month/year
        if (preg_match('/^(\d{1,2})\s+(\d{1,2}:\d{2})$/', $s, $m)) {
            $day = (int)$m[1];
            $time = $m[2];
            $year = (int)date('Y');
            $month = (int)date('m');
            $dt = DateTime::createFromFormat('Y-m-d H:i', sprintf('%04d-%02d-%02d %s', $year, $month, $day, $time));
            if ($dt) {
                return $showTime ? $dt->format('Y-m-d H:i') : $dt->format('Y-m-d');
            }
        }

        // Try common date/time formats explicitly (including ISO T format)
        $candidateFormats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d\TH:i',
            'Y/m/d H:i',
            'd/m/Y H:i',
            'Y-m-d',
            'd/m/Y',
            'H:i',
        ];

        foreach ($candidateFormats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $s);
            $errors = DateTime::getLastErrors();
            // defensive: ensure $errors is an array before accessing offsets
            if (!is_array($errors)) {
                $errors = ['warning_count' => 0, 'error_count' => 0];
            }
            if ($dt !== false && (($errors['warning_count'] ?? 0) === 0) && (($errors['error_count'] ?? 0) === 0)) {
                // If the parsed value contains only time, attach today's date
                if ($fmt === 'H:i') {
                    $today = new DateTime();
                    $parts = explode(':', $s);
                    $dt = DateTime::createFromFormat('Y-m-d H:i', $today->format('Y-m-d') . ' ' . $s);
                }
                return $showTime ? $dt->format('Y-m-d H:i') : $dt->format('Y-m-d');
            }
        }

        // Fallback to strtotime
        $timestamp = strtotime($s);
        if ($timestamp === false) {
            return 'تاريخ غير صالح';
        }
        if ($showTime) {
            return date('Y-m-d H:i', $timestamp);
        }
        return date('Y-m-d', $timestamp);
    }
}


/**
 * Convert a Hijri (Islamic) date string to Gregorian Y-m-d using a tabular algorithm fallback.
 * Accepts formats like YYYY/MM/DD or DD/MM/YYYY and Arabic-Indic digits. Returns 'Y-m-d' or false.
 */
function convertHijriToGregorian($hijriStr) {
    if (empty($hijriStr)) return false;
    // normalize digits and separators
    $arabicNums = ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.','٬'=>','];
    $s = trim((string)$hijriStr);
    $s = strtr($s, $arabicNums);
    $s = preg_replace('/[^0-9\/\-\s]/u', '/', $s);
    $s = preg_replace('/[\.\-\s\\_]+/', '/', $s);
    $s = preg_replace('/\/{2,}/', '/', $s);
    $s = trim($s, '/');
    if ($s === '') return false;

    // generate candidate ordering(s)
    $candidates = [];
    if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $s)) {
        $candidates[] = $s; // yyyy/mm/dd
    }
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $s)) {
        $p = explode('/', $s);
        $candidates[] = $p[2] . '/' . $p[1] . '/' . $p[0]; // convert dd/mm/yyyy -> yyyy/mm/dd
    }
    if (empty($candidates)) {
        $parts = preg_split('/\//', $s);
        if (count($parts) === 3) {
            if (strlen($parts[0]) === 4) $candidates[] = $s; else $candidates[] = $parts[2].'/'.$parts[1].'/'.$parts[0];
        }
    }

    // helper: convert hijri y,m,d -> julian day (tabular Islamic)
    $hijriToJd = function($y, $m, $d) {
        return (int)floor((11*$y + 3)/30) + 354*$y + 30*$m - (int)floor(($m-1)/2) + $d + 1948440 - 385;
    };

    // helper: julian day to Gregorian date
    $jdToGregorian = function($jd) {
        $j = (int)$jd;
        $l = $j + 68569;
        $n = (int)(4 * $l / 146097);
        $l = $l - (int)((146097 * $n + 3) / 4);
        $i = (int)(4000 * ($l + 1) / 1461001);
        $l = $l - (int)(1461 * $i / 4) + 31;
        $j2 = (int)(80 * $l / 2447);
        $d = $l - (int)(2447 * $j2 / 80);
        $l = (int)($j2 / 11);
        $m = $j2 + 2 - 12 * $l;
        $y = 100 * ($n - 49) + $i + $l;
        if ($y <= 0) return false;
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    };

    foreach ($candidates as $cand) {
        $parts = explode('/', $cand);
        if (count($parts) !== 3) continue;
        $yy = (int)$parts[0]; $mm = (int)$parts[1]; $dd = (int)$parts[2];
        if ($yy <= 0 || $mm <= 0 || $dd <= 0) continue;
        // basic range checks
        if ($mm > 12 || $dd > 31) continue;
        $jd = $hijriToJd($yy, $mm, $dd);
        $greg = $jdToGregorian($jd);
        if ($greg !== false) return $greg;
    }
    return false;
}

/**
 * Convert Gregorian date (Y-m-d) to Hijri date string YYYY/MM/DD using a tabular algorithm fallback.
 * Returns false on failure or 'YYYY/MM/DD' on success.
 */
function convertGregorianToHijri($gregorianDate) {
    if (empty($gregorianDate)) return false;
    // Accept 'Y-m-d' or other strtotime-parsable strings
    $ts = strtotime($gregorianDate);
    if ($ts === false) return false;
    $d = (int)date('j', $ts);
    $m = (int)date('n', $ts);
    $y = (int)date('Y', $ts);

    // Convert Gregorian to Julian Day Number (JDN)
    $a = (int)floor((14 - $m) / 12);
    $y2 = $y + 4800 - $a;
    $m2 = $m + 12 * $a - 3;
    $jd = $d + (int)floor((153 * $m2 + 2) / 5) + 365 * $y2 + (int)floor($y2 / 4) - (int)floor($y2 / 100) + (int)floor($y2 / 400) - 32045;

    // Algorithm to convert JDN to Islamic date (tabular Islamic calendar)
    $days = $jd - 1948439 + 10632;
    $n = (int)floor(($days - 1) / 10631);
    $days = $days - 10631 * $n + 354;
    $j = ((int)floor((10985 - $days) / 5316)) * ((int)floor((50 * $days) / 17719)) + ((int)floor($days / 5670)) * ((int)floor((43 * $days) / 15238));
    $days = $days - ((int)floor((30 - $j) / 15)) * ((int)floor((17719 * $j) / 50)) - ((int)floor($j / 16)) * ((int)floor((15238 * $j) / 43)) + 29;
    $m_i = (int)floor((24 * $days) / 709);
    $d_i = $days - (int)floor((709 * $m_i) / 24);
    $y_i = 30 * $n + $j - 30;

    if ($y_i <= 0) return false;
    return sprintf('%04d/%02d/%02d', $y_i, $m_i, $d_i);
}

/**
 * دالة لتسجيل إشعارات التعديل والحذف والإضافة
 * مصممة خصيصًا لنظام إدارة المتدربين والتحصيل المالي
 * 
 * @param string $action نوع الإجراء (add, edit, delete, login, logout)
 * @param string $entity الكيان الذي تم التعديل عليه
 * @param mixed $entityId معرف الكيان (اختياري)
 * @param array $details تفاصيل إضافية عن الإجراء (اختياري)
 * @return bool true إذا تم التسجيل بنجاح، false إذا فشل
 */
function logNotification($action, $entity, $entityId = null, $details = []) {
    global $pdo;
    
    // التحقق من اتصال قاعدة البيانات
    if (!$pdo) {
        error_log("[" . date('Y-m-d H:i:s') . "] فشل في تسجيل الإشعار: لا يوجد اتصال بقاعدة البيانات");
        return false;
    }
    
    // الحصول على معرف المستخدم الحالي
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    $userName = isset($_SESSION['username']) ? $_SESSION['username'] : 'مستخدم غير معروف';
    
    // إنشاء رسالة الإشعار بناءً على نوع الحدث والكيان
    $messages = [
        'add' => 'تم إضافة %s جديد',
        'edit' => 'تم تعديل %s',
        'delete' => 'تم حذف %s',
        'login' => 'تم تسجيل الدخول إلى النظام',
        'logout' => 'تم تسجيل الخروج من النظام',
        'view' => 'تم عرض %s',
        'export' => 'تم تصدير %s',
        'import' => 'تم استيراد %s'
    ];
    
    // تحويل أسماء الكيانات إلى العربية مع تفاصيل خاصة بنظام المتدربين
    $entities = [
        'trainee' => 'متدرب',
        'payment' => 'دفعة مالية',
        'diploma' => 'دبلوم',
        'task' => 'مهمة',
        'user' => 'مستخدم',
        'course' => 'دورة',
        'expense' => 'مصروف',
        'invoice' => 'فاتورة',
        'contract' => 'عقد',
        'certificate' => 'شهادة',
        'product' => 'منتج',
        'order' => 'طلب',
        'category' => 'تصنيف',
        'page' => 'صفحة',
        'post' => 'مقال',
        'item' => 'عنصر',
        'file' => 'ملف',
        'setting' => 'إعداد',
        'notification' => 'إشعار'
    ];
    
    // إنشاء الرسالة الأساسية
    $entityName = $entities[$entity] ?? $entity;
    
    if (isset($messages[$action])) {
        $message = sprintf($messages[$action], $entityName);
    } else {
        $message = "تم تنفيذ إجراء على $entityName";
    }
    
    // إضافة المعرف إذا كان متوفراً
    if ($entityId) {
        $message .= " (رقم: $entityId)";
    }
    
    // إضافة اسم المستخدم الذي قام بالإجراء
    $message .= " - بواسطة: $userName";
    
    // إضافة التفاصيل الإضافية إذا كانت موجودة
    if (!empty($details) && is_array($details)) {
        $detailsStr = implode('، ', array_map(
            function ($v, $k) { 
                // تحويل بعض التفاصيل الشائعة للعربية
                $keyMap = [
                    'name' => 'الاسم',
                    'title' => 'العنوان',
                    'amount' => 'المبلغ',
                    'status' => 'الحالة',
                    'date' => 'التاريخ',
                    'description' => 'الوصف',
                    'phone' => 'الهاتف',
                    'email' => 'البريد الإلكتروني',
                    'balance' => 'الرصيد',
                    'duration' => 'المدة',
                    'price' => 'السعر',
                    'quantity' => 'الكمية',
                    'trainee_name' => 'اسم المتدرب',
                    'diploma_name' => 'اسم الدبلوم',
                    'course_name' => 'اسم الدورة',
                    'category' => 'التصنيف',
                    'changes' => 'التغييرات',
                    'reason' => 'السبب'
                ];
                
                $key = $keyMap[$k] ?? $k;
                return "$key: $v"; 
            },
            $details,
            array_keys($details)
        ));
        $message .= " - $detailsStr";
    }
    
    try {
        // التحقق من وجود الجدول قبل الإدخال
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, message, action, entity, entity_id, details, created_at)
            VALUES (:user_id, :message, :action, :entity, :entity_id, :details, NOW())
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':message' => $message,
            ':action' => $action,
            ':entity' => $entity,
            ':entity_id' => $entityId,
            ':details' => json_encode($details, JSON_UNESCAPED_UNICODE)
        ]);
        
        return true;
    } catch (PDOException $e) {
        // في حالة الخطأ، يمكنك تسجيل الخطأ دون التأثير على تدفق التطبيق
        error_log("[" . date('Y-m-d H:i:s') . "] فشل في تسجيل الإشعار: " . $e->getMessage());
        return false;
    }
}

/**
 * دالة مساعدة للحصول على الإشعارات غير المقروءة
 * @param int $userId معرف المستخدم
 * @param int $limit الحد الأقصى لعدد الإشعارات
 * @return array مصفوفة بالإشعارات غير المقروءة
 */
function getUnreadNotifications($userId, $limit = 5) {
    global $pdo;
    
    if (!$pdo) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, message, created_at 
            FROM notifications 
            WHERE user_id = :user_id AND is_read = 0 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("فشل في جلب الإشعارات: " . $e->getMessage());
        return [];
    }
}

/**
 * دالة مساعدة لوضع علامة مقروء على الإشعارات
 * @param array $notificationIds مصفوفة بمعرفات الإشعارات
 * @return bool true إذا تم التحديث بنجاح
 */
function markNotificationsAsRead($notificationIds) {
    global $pdo;
    
    if (empty($notificationIds) || !$pdo) {
        return false;
    }
    
    try {
        $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
        
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id IN ($placeholders)
        ");
        
        $stmt->execute($notificationIds);
        return true;
    } catch (PDOException $e) {
        error_log("فشل في تحديث الإشعارات: " . $e->getMessage());
        return false;
    }
}

/**
 * دالة لعرض الوقت المنقضي منذ تاريخ معين بصيغة مناسبة للقراءة
 * مثال: منذ 5 دقائق، منذ ساعتين، منذ 3 أيام
 *
 * @param string $datetime التاريخ والوقت بصيغة Y-m-d H:i:s
 * @return string النص الذي يوضح الوقت المنقضي
 */
function timeAgo($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return 'غير محدد';
    }
    
    $time = strtotime($datetime);
    $now = time();
    
    if ($time === false) {
        return 'تاريخ غير صالح';
    }
    
    $diff = $now - $time;
    
    if ($diff < 60) {
        return "منذ لحظات";
    }
    
    $minutes = round($diff / 60);
    $hours = round($diff / 3600);
    $days = round($diff / 86400);
    $weeks = round($diff / 604800);
    $months = round($diff / 2592000); // 30 يوم
    $years = round($diff / 31536000); // 365 يوم
    
    if ($minutes < 60) {
        if ($minutes == 1) return "منذ دقيقة واحدة";
        if ($minutes == 2) return "منذ دقيقتين";
        if ($minutes <= 10) return "منذ {$minutes} دقائق";
        return "منذ {$minutes} دقيقة";
    }
    
    if ($hours < 24) {
        if ($hours == 1) return "منذ ساعة واحدة";
        if ($hours == 2) return "منذ ساعتين";
        if ($hours <= 10) return "منذ {$hours} ساعات";
        return "منذ {$hours} ساعة";
    }
    
    if ($days < 7) {
        if ($days == 1) return "منذ يوم واحد";
        if ($days == 2) return "منذ يومين";
        if ($days <= 10) return "منذ {$days} أيام";
        return "منذ {$days} يوم";
    }
    
    if ($weeks < 4) {
        if ($weeks == 1) return "منذ أسبوع واحد";
        if ($weeks == 2) return "منذ أسبوعين";
        return "منذ {$weeks} أسابيع";
    }
    
    if ($months < 12) {
        if ($months == 1) return "منذ شهر واحد";
        if ($months == 2) return "منذ شهرين";
        if ($months <= 10) return "منذ {$months} أشهر";
        return "منذ {$months} شهر";
    }
    
    if ($years == 1) return "منذ سنة واحدة";
    if ($years == 2) return "منذ سنتين";
    if ($years <= 10) return "منذ {$years} سنوات";
    return "منذ {$years} سنة";
}





/**
 * دالة لإنشاء رابط إعادة توجيه ذكي بناءً على العملية والمورد
 * 
 * @param array $params معلمات التوجيه (العملية، المورد، المعرف)
 * @return string رابط إعادة التوجيه
 */
function smartRedirect($params = []) {
    $action = $params['action'] ?? '';
    $resource = $params['resource'] ?? '';
    $id = $params['id'] ?? null;
    
    // تعريف SITE_URL إذا لم يكن معرّفاً
    if (!defined('SITE_URL')) {
        define('SITE_URL', 'http://localhost'); // قيمة افتراضية
    }
    
    // الصفحة الافتراضية
    $defaultUrl = rtrim(SITE_URL, '/') . '/index.php';
    
    // تحديد الصفحة المناسبة بناءً على المورد والعملية
    switch ($resource) {
        case 'trainee':
            if (($action == 'create' || $action == 'update') && $id) {
                return rtrim(SITE_URL, '/') . '/modules/trainees/edit.php?id=' . $id;
            } else {
                return rtrim(SITE_URL, '/') . '/modules/trainees/index.php';
            }
            
        case 'payment':
            if (($action == 'create' || $action == 'update') && $id) {
                return rtrim(SITE_URL, '/') . '/modules/payments/view.php?id=' . $id;
            } else {
                return rtrim(SITE_URL, '/') . '/modules/payments/index.php';
            }
            
        case 'task':
            if (($action == 'create' || $action == 'update') && $id) {
                return rtrim(SITE_URL, '/') . '/modules/tasks/view.php?id=' . $id;
            } else {
                return rtrim(SITE_URL, '/') . '/modules/tasks/index.php';
            }
            
        case 'diploma':
            if (($action == 'create' || $action == 'update') && $id) {
                return rtrim(SITE_URL, '/') . '/modules/diplomas/edit.php?id=' . $id;
            } else {
                return rtrim(SITE_URL, '/') . '/modules/diplomas/index.php';
            }
            
        default:
            return $defaultUrl;
    }
}

/**
 * دالة لإعادة التوجيه مع رسالة
 * 
 * @param string $url عنوان URL للتوجيه إليه
 * @param string $message الرسالة المراد عرضها
 * @param string $type نوع الرسالة (success, error, warning, info)
 */
function redirectWithMessage($url, $message, $type = 'success') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // تخزين الرسالة في الجلسة
    // New standardized flash payload (used by includes/alerts.php)
    $titleMap = [
        'success' => 'تم بنجاح',
        'error' => 'حدث خطأ',
        'info' => 'معلومة',
        'warning' => 'تنبيه'
    ];
    $title = $titleMap[$type] ?? ucfirst($type);
    $_SESSION['flash'] = [
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'autoHide' => ($type !== 'error')
    ];

    // Keep legacy keys for backward compatibility
    $_SESSION['flash_message'] = [
        'text' => $message,
        'type' => $type
    ];
    if ($type === 'success') {
        $_SESSION['success_message'] = $message;
    } elseif ($type === 'error') {
        $_SESSION['error_message'] = $message;
    } else {
        $_SESSION['info_message'] = $message;
    }
    
    // إعادة التوجيه إلى العنوان المحدد
    header('Location: ' . $url);
    exit;
}

/**
 * دالة لتنظيف الإشعارات القديمة
 * @param int $days عدد الأيام (الإشعارات الأقدم من هذا العدد سيتم حذفها)
 * @return bool true إذا تم الحذف بنجاح
 */
function cleanupOldNotifications($days = 30) {
    global $pdo;
    
    if (!$pdo) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            DELETE FROM notifications 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        
        $stmt->execute([':days' => $days]);
        return true;
    } catch (PDOException $e) {
        error_log("فشل في حذف الإشعارات القديمة: " . $e->getMessage());
        return false;
    }
}

/**
 * دالة للحصول على سجل الإشعارات مع إمكانية التصفية
 * @param int $userId معرف المستخدم (اختياري)
 * @param string $action نوع الإجراء (اختياري)
 * @param string $entity الكيان (اختياري)
 * @param int $limit الحد الأقصى لعدد النتائج
 * @return array مصفوفة بالإشعارات
 */
function getNotificationsLog($userId = null, $action = null, $entity = null, $limit = 100) {
    global $pdo;
    
    if (!$pdo) {
        return [];
    }
    
    try {
        $whereClauses = [];
        $params = [];
        
        if ($userId) {
            $whereClauses[] = "n.user_id = :user_id";
            $params[':user_id'] = $userId;
        }
        
        if ($action) {
            $whereClauses[] = "n.action = :action";
            $params[':action'] = $action;
        }
        
        if ($entity) {
            $whereClauses[] = "n.entity = :entity";
            $params[':entity'] = $entity;
        }
        
        $where = empty($whereClauses) ? "" : "WHERE " . implode(" AND ", $whereClauses);
        
        $stmt = $pdo->prepare("
            SELECT n.*, u.username 
            FROM notifications n
            LEFT JOIN users u ON n.user_id = u.id
            $where
            ORDER BY n.created_at DESC 
            LIMIT :limit
        ");
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("فشل في جلب سجل الإشعارات: " . $e->getMessage());
        return [];
    }
}

/**
 * دالة للحصول على اسم الحالة باللغة العربية
 * @param string $status الحالة بالإنجليزية
 * @return string الحالة بالعربية
 */
function getStatusName($status) {
    $statusMap = [
        'under_communication' => 'قيد التواصل',
        'promised_payment' => 'وعد بالسداد',
        'delayed' => 'متأخر',
        'paid' => 'مسدد',
        'refused_payment' => 'رافض',
        'not_responding' => 'لا يرد',
        'active' => 'نشط',
        'inactive' => 'غير نشط',
        'pending' => 'معلق',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغي'
    ];
    
    return $statusMap[$status] ?? $status;
}

/**
 * دالة لتنظيف بيانات الإدخال
 * @param mixed $data البيانات المراد تنظيفها
 * @return mixed البيانات النظيفة
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

/**
 * HTML escape helper (view-safe)
 * Ensures a string is returned and avoids passing null to htmlspecialchars (PHP 8.1+ deprecation)
 * @param mixed $value
 * @return string
 */
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * دالة للتحقق من صحة البريد الإلكتروني
 * @param string $email البريد الإلكتروني
 * @return bool true إذا كان البريد صالحاً
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * دالة للتحقق من صحة رقم الهاتف السعودي
 * @param string $phone رقم الهاتف
 * @retud/rn bool true إذا كان الرقم صالحاً
 */
function isValidSaudiPhone($phone) {
    // نمط رقم الهاتف السعودي (05xxxxxxxx)
    $pattern = '/^05[0-9]{8}$/';
    return preg_match($pattern, $phone) === 1;
}

?>
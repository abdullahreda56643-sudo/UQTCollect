<?php
/**
 * نظام الإشعارات
 * ملف موحد بدون تكرار
 */

/**
 * إنشاء إشعار جديد
 * 
 * @param PDO $db اتصال قاعدة البيانات
 * @param int $userId معرف المستخدم
 * @param string $title عنوان الإشعار
 * @param string $message نص الإشعار
 * @param string $type نوع الإشعار (info, success, warning, error, task, login)
 * @param string|null $link رابط اختياري للصفحة المتعلقة
 * @return bool نجاح العملية
 */
function hasIconColumn($db) {
    try {
        $schema = $db->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'icon'");
        $stmt->execute([':schema' => $schema]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        // If information_schema is not accessible, assume no column
        return false;
    }
}

/**
 * إنشاء إشعار جديد
 * يدعم حقل اختياري `icon` في حال توافره في جدول notifications
 */
function createNotification($db, $userId, $title, $message, $type = 'info', $link = null, $icon = null) {
    // Ensure session is active when checking actor info (some scripts may call this without session)
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    try {
        if (hasIconColumn($db) && $icon !== null) {
            $sql = "INSERT INTO notifications (user_id, type, title, message, link, icon, created_at) 
                    VALUES (:user_id, :type, :title, :message, :link, :icon, NOW())";
            $stmt = $db->prepare($sql);
            $ok = $stmt->execute([
                ':user_id' => $userId,
                ':type'    => $type,
                ':title'   => $title,
                ':message' => $message,
                ':link'    => $link,
                ':icon'    => $icon
            ]);
        } else {
            $sql = "INSERT INTO notifications (user_id, type, title, message, link, created_at) 
                    VALUES (:user_id, :type, :title, :message, :link, NOW())";
            $stmt = $db->prepare($sql);
            $ok = $stmt->execute([
                ':user_id' => $userId,
                ':type'    => $type,
                ':title'   => $title,
                ':message' => $message,
                ':link'    => $link
            ]);
        }

        // بعد إنشاء الإشعار، إذا كان الفاعل (المستخدم في الجلسة) موظفًا (ليس مديرًا)
        // فقم بإرسال نسخة إلى جميع المدراء ليظهر لديهم رمز (icon) إن وُجد
        try {
            if ($ok && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
                $actorId = (int)$_SESSION['user_id'];
                // إذا كان المستلم نفسه هو الممثل فلا ننسخ
                if ($actorId !== (int)$userId) {
                    // تحقق من دور الفاعل
                    $r = $db->prepare('SELECT role, CONCAT(first_name, " ", last_name) as full_name FROM users WHERE id = :id LIMIT 1');
                    $r->execute([':id' => $actorId]);
                    $actor = $r->fetch(PDO::FETCH_ASSOC);
                    $actorRole = $actor['role'] ?? null;
                    $actorName = $actor['full_name'] ?? null;

                    if ($actorRole && strtolower($actorRole) !== 'admin') {
                        // أرسل إشعارًا لكل المدراء
                        $adminsStmt = $db->prepare("SELECT id FROM users WHERE role IN ('admin','supervisor') AND is_active = 1");
                        $adminsStmt->execute();
                        $admins = $adminsStmt->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($admins as $adminId) {
                            // لا نرسل نسخة إلى نفس المستخدم المستلم مسبقاً
                            if ((int)$adminId === (int)$userId) continue;
                            // عنوان مُعدّل ليُظهر المرسل
                            $adminTitle = $title;
                            $adminMessage = ($actorName ? ($actorName . ': ') : '') . $message;
                            // استخدم نفس الـ icon إن وُجد، وإلا 'user' كافتراضي
                            $adminIcon = $icon ?? 'user';
                            // ندعم وجود عمود icon أو لا
                            if (hasIconColumn($db)) {
                                $ins = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link, icon, created_at) VALUES (:uid, :type, :title, :message, :link, :icon, NOW())");
                                $ins->execute([':uid' => $adminId, ':type' => $type, ':title' => $adminTitle, ':message' => $adminMessage, ':link' => $link, ':icon' => $adminIcon]);
                            } else {
                                $ins = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (:uid, :type, :title, :message, :link, NOW())");
                                $ins->execute([':uid' => $adminId, ':type' => $type, ':title' => $adminTitle, ':message' => $adminMessage, ':link' => $link]);
                            }
                        }
                    }
                }
            }
        } catch (Exception $ex) {
            // لا نوقف العملية الأساسية إذا فشل إشعار المدراء
            error_log('Error broadcasting to admins: ' . $ex->getMessage());
        }

        return $ok;
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * جلب إشعارات المستخدم
 * 
 * @param PDO $db
 * @param int $userId
 * @param bool $unreadOnly
 * @return array
 */
function getUserAlerts($db, $userId, $unreadOnly = false) {
    try {
        $sql = "SELECT * FROM notifications WHERE user_id = :user_id";
        if ($unreadOnly) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY created_at DESC LIMIT 10";
        $stmt = $db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching user alerts: " . $e->getMessage());
        return [];
    }
}

/**
 * وضع علامة مقروء على الإشعار
 */
function markAlertAsRead($db, $alertId, $userId) {
    try {
        $sql = "UPDATE notifications SET is_read = 1 
                WHERE id = :alert_id AND user_id = :user_id";
        $stmt = $db->prepare($sql);
        return $stmt->execute([
            ':alert_id' => $alertId,
            ':user_id'  => $userId
        ]);
    } catch (Exception $e) {
        error_log("Error marking alert as read: " . $e->getMessage());
        return false;
    }
}

/**
 * جلب عدد الإشعارات غير المقروءة
 */
function getUnreadNotificationsCount($db, $userId) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications 
                              WHERE user_id = :user_id AND is_read = 0");
        $stmt->execute([':user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error counting unread notifications: " . $e->getMessage());
        return 0;
    }
}

/* === دوال مساعدة للإشعارات الجاهزة === */

/**
 * إشعار تسجيل دخول جديد
 */
function notifyNewLogin($db, $userId, $deviceInfo) {
    // تحديد نوع الجهاز بشكل بسيط
    $deviceType = 'جهاز غير معروف';
    if (strpos($deviceInfo, 'Mobile') !== false) {
        $deviceType = 'هاتف محمول';
    } elseif (strpos($deviceInfo, 'Tablet') !== false) {
        $deviceType = 'جهاز لوحي';
    } elseif (strpos($deviceInfo, 'Windows') !== false) {
        $deviceType = 'كمبيوتر (Windows)';
    } elseif (strpos($deviceInfo, 'Mac') !== false) {
        $deviceType = 'جهاز Mac';
    }

    return createNotification(
        $db,
        $userId,
        'تسجيل دخول جديد',
        "تم تسجيل الدخول بنجاح من " . $deviceType,
        'login'
    );
}

/**
 * إشعار عند إضافة متدرب جديد
 */
function notifyNewTrainee($db, $adminId, $traineeName) {
    return createNotification(
        $db,
        $adminId,
        'متدرب جديد',
        "تم إضافة متدرب جديد: " . $traineeName,
        'success',
        'modules/trainees/list.php'
    );
}

/**
 * إشعار عند تحديث بيانات متدرب
 */
function notifyTraineeUpdate($db, $adminId, $traineeName) {
    return createNotification(
        $db,
        $adminId,
        'تحديث بيانات متدرب',
        "تم تحديث بيانات المتدرب: " . $traineeName,
        'info',
        'modules/trainees/list.php'
    );
}

/**
 * إشعار عند إضافة دفعة مالية
 */
function notifyNewPayment($db, $adminId, $traineeName, $amount) {
    return createNotification(
        $db,
        $adminId,
        'دفعة مالية جديدة',
        "تم استلام دفعة جديدة من " . $traineeName . " بقيمة " . $amount . " ريال",
        'success',
        'modules/payments/list.php'
    );
}

/**
 * إشعار عند إضافة مهمة جديدة
 */
function notifyNewTask($db, $userId, $taskTitle) {
    return createNotification(
        $db,
        $userId,
        'مهمة جديدة',
        "تم إسناد مهمة جديدة لك: " . $taskTitle,
        'task',
        'modules/tasks/list.php'
    );
}

/**
 * إشعار عند اقتراب موعد استحقاق دفعة
 */
function notifyPaymentDue($db, $userId, $traineeName, $amount, $dueDate) {
    return createNotification(
        $db,
        $userId,
        'موعد استحقاق دفعة',
        "موعد استحقاق دفعة للمتدرب $traineeName بقيمة $amount ريال في $dueDate",
        'warning',
        'modules/payments/list.php'
    );
}

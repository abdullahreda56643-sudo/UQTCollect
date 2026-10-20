<?php
/**
 * المتحكم الرئيسي للمصادقة وإدارة جلسات المستخدم
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.0.0
 * @author 
 * @copyright 2024
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db/connection.php';
require_once __DIR__ . '/../../includes/auth.php';

class AuthController {
    private $db;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    /**
     * تسجيل الدخول
     */
    public function login($username, $password) {
        $result = loginUser($this->db, $username, $password);

        if ($result['success']) {
            $redirectUrl = getPostLoginRedirectUrl();
            header("Location: " . $redirectUrl);
            exit;
        } else {
            $_SESSION['error_message'] = $result['message'];
            header("Location: " . rtrim(SITE_URL, '/') . "/modules/auth/login.php");
            exit;
        }
    }

    /**
     * تسجيل الخروج
     */
    public function logout() {
        logoutUser($this->db);
        header("Location: " . rtrim(SITE_URL, '/') . "/modules/auth/login.php");
        exit;
    }

    /**
     * طلب إعادة تعيين كلمة المرور
     */
    public function requestPasswordReset($email) {
        $result = createPasswordResetToken($this->db, $email);

        if ($result['success']) {
            // TODO: إرسال البريد الإلكتروني
            $_SESSION['success_message'] = "تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني.";
        } else {
            $_SESSION['error_message'] = $result['message'];
        }

        header("Location: " . rtrim(SITE_URL, '/') . "/modules/auth/forgot_password.php");
        exit;
    }

    /**
     * إعادة تعيين كلمة المرور
     */
    public function resetPassword($token, $newPassword) {
        $result = resetPassword($this->db, $token, $newPassword);

        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'];
            header("Location: " . rtrim(SITE_URL, '/') . "/modules/auth/login.php");
        } else {
            $_SESSION['error_message'] = $result['message'];
            header("Location: " . rtrim(SITE_URL, '/') . "/modules/auth/reset_password.php?token=" . urlencode($token));
        }
        exit;
    }

    /**
     * التحقق من الجلسة والصلاحيات
     */
    public function authorize($requiredRole = 'user') {
        return checkPermission($requiredRole);
    }
}

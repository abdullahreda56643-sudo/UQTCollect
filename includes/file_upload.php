<?php
/**
 * وظائف تحميل الملفات
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.0.0
 * @author نظام إدارة المتدربين
 * @copyright 2024 جميع الحقوق محفوظة
 */

/**
 * دالة تحميل الصور
 * 
 * @param array $file معلومات الملف من $_FILES
 * @param string $destination مجلد الوجهة
 * @param array $allowed_types أنواع الملفات المسموح بها
 * @param int $max_size الحجم الأقصى للملف بالبايت
 * @return array مصفوفة تحتوي على حالة التحميل واسم الملف ورسالة الخطأ
 */
function uploadImage($file, $destination = 'avatars', $allowed_types = ['image/jpeg', 'image/png', 'image/gif'], $max_size = 2097152) {
    // التحقق من وجود الملف
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return [
            'status' => false,
            'message' => 'فشل في تحميل الملف: ' . getUploadErrorMessage($file['error']),
            'filename' => null
        ];
    }
    
    // التحقق من نوع الملف
    if (!in_array($file['type'], $allowed_types)) {
        return [
            'status' => false,
            'message' => 'نوع الملف غير مسموح به. الأنواع المسموح بها هي: ' . implode(', ', $allowed_types),
            'filename' => null
        ];
    }
    
    // التحقق من حجم الملف
    if ($file['size'] > $max_size) {
        return [
            'status' => false,
            'message' => 'حجم الملف كبير جداً. الحد الأقصى هو ' . formatFileSize($max_size),
            'filename' => null
        ];
    }
    
    // إنشاء مجلد الوجهة إذا لم يكن موجوداً
    $upload_dir = UPLOAD_PATH . $destination . '/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // إنشاء اسم فريد للملف
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('img_') . '.' . $file_extension;
    $filepath = $upload_dir . $filename;
    
    // نقل الملف إلى المجلد المحدد
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return [
            'status' => true,
            'message' => 'تم تحميل الملف بنجاح',
            'filename' => $destination . '/' . $filename
        ];
    } else {
        return [
            'status' => false,
            'message' => 'فشل في حفظ الملف',
            'filename' => null
        ];
    }
}

/**
 * دالة الحصول على رسالة خطأ التحميل
 * 
 * @param int $error_code رمز الخطأ
 * @return string رسالة الخطأ
 */
function getUploadErrorMessage($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'حجم الملف يتجاوز الحد المسموح به في إعدادات PHP';
        case UPLOAD_ERR_FORM_SIZE:
            return 'حجم الملف يتجاوز الحد المسموح به في النموذج';
        case UPLOAD_ERR_PARTIAL:
            return 'تم تحميل جزء من الملف فقط';
        case UPLOAD_ERR_NO_FILE:
            return 'لم يتم تحميل أي ملف';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'مجلد الملفات المؤقتة مفقود';
        case UPLOAD_ERR_CANT_WRITE:
            return 'فشل في كتابة الملف على القرص';
        case UPLOAD_ERR_EXTENSION:
            return 'توقف التحميل بسبب امتداد PHP';
        default:
            return 'خطأ غير معروف في التحميل';
    }
}

/**
 * دالة تنسيق حجم الملف
 * 
 * @param int $size حجم الملف بالبايت
 * @return string الحجم المنسق
 */
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, 2) . ' ' . $units[$i];
}
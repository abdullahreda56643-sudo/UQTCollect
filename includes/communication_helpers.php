<?php
/**
 * دوال مساعدة للتعامل مع سجلات التواصل
 * تتضمن ترجمات لأنواع التواصل ونتائجها، وفئات للـ badges، ودالة تنسيق هجري بسيطة
 */

if (!function_exists('formatDateTime')) {
    function formatDateTime($datetime) {
        if (empty($datetime)) return '';
        try {
            $ts = strtotime($datetime);
            if ($ts === false) return htmlspecialchars($datetime);
            return date('Y-m-d H:i', $ts);
        } catch (Exception $e) {
            return htmlspecialchars($datetime);
        }
    }
}

if (!function_exists('formatHijri')) {
    /**
     * حاول تنسيق التاريخ هجريًا باستخدام IntlDateFormatter إذا كان متاحًا.
     * إرجاع نص فارغ عند عدم الإمكان.
     */
    function formatHijri($datetime) {
        if (empty($datetime)) return '';
        try {
            $ts = strtotime($datetime);
            if ($ts === false) return '';
            if (class_exists('IntlDateFormatter')) {
                $fmt = new IntlDateFormatter('ar_SA@calendar=islamic', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL);
                return $fmt->format($ts);
            }
        } catch (Throwable $e) {
            // ignore and return empty
        }
        return '';
    }
}

if (!function_exists('getTypeLabel')) {
    function getTypeLabel($type) {
        $map = [
            'communication' => 'تواصل',
            'call' => '<i class="fas fa-phone-alt ml-1"></i> اتصال',
            'whatsapp' => '<i class="fab fa-whatsapp ml-1"></i> واتساب',
            'payment' => '<i class="fas fa-money-bill-wave ml-1"></i> دفعة',
            'visit' => '<i class="fas fa-door-open ml-1"></i> زيارة',
            'email' => '<i class="fas fa-envelope ml-1"></i> بريد إلكتروني',
            'sms' => '<i class="fas fa-sms ml-1"></i> رسالة نصية',
            'phone' => '<i class="fas fa-phone ml-1"></i> هاتف'
        ];
        return $map[$type] ?? htmlspecialchars($type ?: 'تواصل');
    }
}

if (!function_exists('getTypeBadgeClass')) {
    function getTypeBadgeClass($type) {
        $map = [
            'communication' => 'px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800',
            'call' => 'px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800',
            'whatsapp' => 'px-2 py-1 text-xs rounded-full bg-green-100 text-green-800',
            'payment' => 'px-2 py-1 text-xs rounded-full bg-indigo-100 text-indigo-800',
            'visit' => 'px-2 py-1 text-xs rounded-full bg-purple-100 text-purple-800',
            'email' => 'px-2 py-1 text-xs rounded-full bg-pink-100 text-pink-800',
            'sms' => 'px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800',
            'phone' => 'px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800'
        ];
        return $map[$type] ?? 'px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800';
    }
}

if (!function_exists('getResultLabel')) {
    function getResultLabel($result) {
        // Debug: Log the input value
        error_log("DEBUG getResultLabel: Input = '" . $result . "'");
        
        // شاملة للقيم الممكنة حسب الـ schema والاختصارات الشائعة
        $map = [
            // القيم الحالية في قاعدة البيانات (schema)
            'responded' => 'تم الرد',
            'refused' => 'رفض',
            'paid' => 'تم السداد',
            'not_responding' => 'لم يرد',
            'promised_payment' => 'وعد بالدفع',
            'other' => 'أخرى',

            // بدائل واختصارات مستخدمة عبر الواجهة
            'promise' => 'وعد',
            'no_answer' => 'لم يرد',
            'follow_up' => 'متابعة',
            'not_interested' => 'غير مهتم',
            'interested' => 'مهتم',
            'callback' => 'طلب الاتصال لاحقاً',
            'paid_partial' => 'مدفوع جزئياً',
            'delayed' => 'متأخر'
        ];

        // قم بإرجاع الترجمة العربية أو 'غير محدد' كقيمة افتراضية
        if (is_null($result) || $result === '') {
            error_log("DEBUG getResultLabel: Returning 'غير محدد' for empty input");
            return 'غير محدد';
        }
        
        $translated = $map[$result] ?? 'غير محدد';
        error_log("DEBUG getResultLabel: Translated = '" . $translated . "'");
        return $translated;
    }
}

if (!function_exists('getResultBadgeClass')) {
    function getResultBadgeClass($result) {
        $map = [
            'paid' => 'px-2 py-1 text-xs rounded-full bg-green-100 text-green-800',
            'promised_payment' => 'px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800',
            'promise' => 'px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800',
            'refused' => 'px-2 py-1 text-xs rounded-full bg-red-100 text-red-800',
            'no_answer' => 'px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800',
            'not_responding' => 'px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800',
            'follow_up' => 'px-2 py-1 text-xs rounded-full bg-indigo-100 text-indigo-800',
            'not_interested' => 'px-2 py-1 text-xs rounded-full bg-red-100 text-red-800',
            'other' => 'px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800',
            'interested' => 'px-2 py-1 text-xs rounded-full bg-green-100 text-green-800',
            'callback' => 'px-2 py-1 text-xs rounded-full bg-indigo-100 text-indigo-800',
            'delayed' => 'px-2 py-1 text-xs rounded-full bg-orange-100 text-orange-800'
        ];
        return $map[$result] ?? 'px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800';
    }
}

if (!function_exists('setFlashMessage')) {
    function setFlashMessage($type, $message) {
        if (!isset($_SESSION['flash_messages'])) $_SESSION['flash_messages'] = [];
        $_SESSION['flash_messages'][] = [
            'type' => $type,
            'message' => $message
        ];
    }
}
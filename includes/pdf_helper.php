<?php
/**
 * مساعد إنشاء ملفات PDF
 * يحتوي على الدوال المساعدة لإنشاء وتنسيق ملفات PDF
 */

/**
 * تهيئة كائن mPDF مع الإعدادات المناسبة للغة العربية
 *
 * @param array $config إعدادات إضافية اختيارية
 * @return \Mpdf\Mpdf
 * @throws \Exception
 */
function initializeMPDF($config = []) {
    // التأكد من وجود المجلد المؤقت
    $tempDir = __DIR__ . '/../temp/mpdf';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }

    // التحقق من صلاحيات المجلد
    if (!is_writable($tempDir)) {
        throw new \Exception('المجلد المؤقت غير قابل للكتابة: ' . $tempDir);
    }

    // الإعدادات الافتراضية
    $defaultConfig = [
        'mode' => 'utf-8',
        'format' => [210, 297], // A4 in mm
        'default_font' => 'arial',
        'default_font_size' => 12,
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 16,
        'margin_bottom' => 16,
        'margin_header' => 9,
        'margin_footer' => 9,
        'orientation' => 'P',
        'tempDir' => $tempDir,
        'autoScriptToLang' => true,
        'autoLangToFont' => true,
        'direction' => 'rtl',
        'debug' => true
    ];

    // دمج الإعدادات المخصصة مع الإعدادات الافتراضية
    $finalConfig = array_merge($defaultConfig, $config);

    try {
        // تهيئة كائن mPDF
        $mpdf = new \Mpdf\Mpdf($finalConfig);
        
        // إعداد خصائص المستند
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetDisplayMode('fullpage');
        $mpdf->SetFont('XB Riyaz');
        
        // تعيين معلومات PDF
        $mpdf->SetCreator('نظام إدارة المتدربين');
        $mpdf->SetTitle('تقرير نظام إدارة المتدربين');
        
        return $mpdf;
    } catch (\Exception $e) {
        error_log('خطأ في تهيئة mPDF: ' . $e->getMessage());
        throw new \Exception('فشل في تهيئة مكتبة PDF: ' . $e->getMessage());
    }
}

/**
 * حفظ ملف PDF وإرساله للمتصفح
 *
 * @param \Mpdf\Mpdf $mpdf كائن mPDF
 * @param string $filename اسم الملف
 * @return void
 * @throws \Exception
 */
function outputPDF($mpdf, $filename) {
    try {
        // تنظيف المخرجات السابقة
        if (ob_get_length()) ob_clean();
        
        // تعيين headers التحميل
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        // حفظ الملف في الذاكرة وإرساله
        $mpdf->Output($filename, 'I');
    } catch (\Exception $e) {
        error_log('خطأ في إنشاء PDF: ' . $e->getMessage());
        throw new \Exception('فشل في إنشاء ملف PDF: ' . $e->getMessage());
    }
}

/**
 * إضافة CSS افتراضي للمستند
 *
 * @param \Mpdf\Mpdf $mpdf كائن mPDF
 * @return void
 */
function addDefaultStyles($mpdf) {
    $css = '
        body { 
            font-family: "XB Riyaz", Arial, sans-serif; 
            direction: rtl; 
            font-size: 12pt;
        }
        h1, h2, h3 { 
            text-align: center; 
            color: #2c3e50;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 10px 0; 
        }
        th { 
            background-color: #2980b9; 
            color: white; 
            font-weight: bold; 
            padding: 8px;
        }
        td { 
            padding: 8px; 
            border: 1px solid #bdc3c7;
        }
        tr:nth-child(even) { 
            background-color: #f9f9f9; 
        }
        .summary-box { 
            border: 1px solid #ddd; 
            padding: 15px; 
            margin: 10px 0; 
            background-color: #f8f9fa;
        }
        .footer { 
            text-align: center; 
            font-size: 9pt; 
            margin-top: 20px; 
            border-top: 1px solid #eee;
            padding-top: 10px;
        }
    ';
    
    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
}
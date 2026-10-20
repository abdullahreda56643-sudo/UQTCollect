<?php
/**
 * Autoloader لتحميل الكلاسات والمكتبات
 * + وظيفة استيراد ملف Excel إلى جدول trainees
 */

// ✅ Autoload للكلاسات
spl_autoload_register(function ($class_name) {
    $directories = [
        __DIR__ . '/controllers/',
        __DIR__ . '/models/',
        __DIR__ . '/lib/',
        __DIR__ . '/db/'
    ];
    
    foreach ($directories as $directory) {
        $file = $directory . $class_name . '.php';
        if (is_readable($file)) {
            require_once $file;
            return;
        }
    }
});

// ✅ تحميل مكتبات Composer (PhpSpreadsheet)
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * تنسيق رقم الجوال وتحويله إلى الصيغة المطلوبة
 */
function formatPhoneNumber($phone) {
    // إذا كان الرقم فارغاً
    if (empty($phone)) {
        return null;
    }

    // إزالة المسافات فقط
    $phone = trim($phone);
    
    // إرجاع الرقم كما هو بدون أي تعديلات
    return $phone;
    
    // إذا كان الرقم يبدأ بـ 05، نحوله إلى صيغة دولية
    if (strpos($phone, '05') === 0) {
        $phone = '+966' . substr($phone, 1);
    }
    
    // إذا كان الرقم يبدأ بـ 5 فقط، نضيف 966+
    if (strlen($phone) === 9 && $phone[0] === '5') {
        $phone = '+966' . $phone;
    }
    
    // إذا كان الرقم يبدأ بـ 966 بدون +، نضيف +
    if (strpos($phone, '966') === 0 && $phone[0] !== '+') {
        $phone = '+' . $phone;
    }
    
    return $phone;
}

/**
 * التحقق من صحة رقم الجوال
 */
function validatePhoneNumber($phone) {
    // إذا كان الرقم فارغاً، اعتبره صحيحاً
    if (empty($phone)) {
        return true;
    }

    $phone = formatPhoneNumber($phone);
    
    // قبول الأرقام التي تبدأ بـ 05 وتتكون من 10 أرقام
    // أو الأرقام التي تبدأ بـ 966 أو +966 وتتكون من 12 أو 13 رقم
    return preg_match('/^(05[0-9]{8}|966[0-9]{9}|\+966[0-9]{9})$/', $phone);
}

/**
 * استيراد بيانات المتدربين من ملف Excel إلى جدول trainees
 */
function importTraineesFromExcel($inputFileName, PDO $pdo)
{
    $errors = [];
    $spreadsheet = IOFactory::load($inputFileName);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, true, true);

    // خريطة الأعمدة
    $mapping = [
        "اسم المتدرب"     => "full_name",
        "رقم الهوية"      => "national_id",
        "رقم الجوال"      => "phone",
        "تاريخ آخر ترم"   => "graduation_date",
        "الرسوم"          => "total_amount",
        "المبلغ المتبقي"  => "remaining_amount",
        "حالة الرسوم"     => "status",
        "نتيجة التواصل"   => "notes"
    ];

    $sql = "INSERT INTO trainees 
    (full_name, national_id, phone, phone2, email, address, graduation_date, total_amount, paid_amount, remaining_amount, status, notes, branch_id, diploma_id, created_by) 
    VALUES (:full_name, :national_id, :phone, :phone2, :email, :address, :graduation_date, :total_amount, :paid_amount, :remaining_amount, :status, :notes, :branch_id, :diploma_id, :created_by)";
    
    $stmt = $pdo->prepare($sql);
    $successCount = 0;

    foreach ($data as $index => $row) {
        if ($index == 1) continue; // تخطي العناوين
        if (empty($row["C"])) continue; // تخطي الصفوف الفارغة

        // تنسيق رقم الجوال - قبول أي رقم كما هو
        $phone = !empty($row["E"]) ? formatPhoneNumber($row["E"]) : null;
        
        // التحقق من صحة رقم الجوال
        if (!empty($row["E"]) && !validatePhoneNumber($phone)) {
            $errors[] = "رقم الجوال غير صحيح للمتدرب {$row['C']} ({$row['E']})";
            continue;
        }

        $record = [
            "full_name"       => $row["C"] ?? null,
            "national_id"     => $row["D"] ?? null,
            "phone"           => $phone,
            "phone2"          => null,
            "email"           => null,
            "address"         => null,
            "graduation_date" => !empty($row["A"]) ? date("Y-m-d", strtotime($row["A"])) : null,
            "total_amount"    => $row["G"] ?? 0,
            "paid_amount"     => 0,
            "remaining_amount"=> $row["H"] ?? 0,
            "status"          => $row["F"] ?? "under_communication",
            "notes"           => $row["I"] ?? null,
            "branch_id"       => 1,
            "diploma_id"      => 1,
            "created_by"      => 1
        ];

        try {
            $stmt->execute($record);
            $successCount++;
        } catch (PDOException $e) {
            $errors[] = "خطأ في إدخال بيانات المتدرب {$row['C']}: " . $e->getMessage();
        }
    }

    // إعداد رسالة النتيجة
    $result = [];
    if ($successCount > 0) {
        $result[] = "✅ تم استيراد $successCount متدرب بنجاح.";
    }
    if (!empty($errors)) {
        $result[] = "\n⚠️ لم يتم استيراد " . count($errors) . " متدرب للأسباب التالية:";
        foreach ($errors as $error) {
            $result[] = "- " . $error;
        }
    }

    return implode("\n", $result);
}

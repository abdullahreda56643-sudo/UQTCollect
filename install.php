<?php
require_once 'config.php';
require_once 'db/connection.php';
require_once 'includes/auth.php';

// التحقق من صلاحيات المستخدم
checkPermission('admin');

// تعريف المتغيرات
$messages = [];
$errors = [];
$success = false;

// التحقق من وجود Composer
if (file_exists('composer.phar') || commandExists('composer')) {
    $messages[] = 'تم العثور على Composer.'; 
} else {
    $errors[] = 'لم يتم العثور على Composer. يرجى تثبيت Composer أولاً.'; 
}

// التحقق من وجود ملف composer.json
if (file_exists('composer.json')) {
    $messages[] = 'تم العثور على ملف composer.json.'; 
} else {
    $errors[] = 'لم يتم العثور على ملف composer.json.'; 
}

// تنفيذ العمليات إذا تم إرسال النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['install_dependencies'])) {
        // تثبيت المكتبات باستخدام Composer
        $output = [];
        $return_var = 0;
        
        if (file_exists('composer.phar')) {
            exec('php composer.phar install 2>&1', $output, $return_var);
        } else {
            exec('composer install 2>&1', $output, $return_var);
        }
        
        if ($return_var === 0) {
            $messages[] = 'تم تثبيت المكتبات بنجاح.'; 
        } else {
            $errors[] = 'فشل في تثبيت المكتبات: ' . implode("\n", $output); 
        }
    }
    
    if (isset($_POST['apply_indexes'])) {
        // تطبيق فهارس قاعدة البيانات
        try {
            $db = (new Database())->getConnection();
            
            // قراءة ملف SQL
            $sqlFile = file_get_contents('db/add_indexes.sql');
            
            // تقسيم الأوامر SQL
            $sqlCommands = explode(';', $sqlFile);
            
            // تنفيذ كل أمر SQL
            foreach ($sqlCommands as $sql) {
                $sql = trim($sql);
                if (!empty($sql)) {
                    $db->exec($sql);
                }
            }
            
            $messages[] = 'تم تطبيق فهارس قاعدة البيانات بنجاح.'; 
        } catch (PDOException $e) {
            $errors[] = 'فشل في تطبيق فهارس قاعدة البيانات: ' . $e->getMessage(); 
        }
    }
    
    if (isset($_POST['create_notifications_table'])) {
        // إنشاء جدول الإشعارات
        try {
            $db = (new Database())->getConnection();
            
            // قراءة ملف SQL
            $sqlFile = file_get_contents('db/create_notifications_table.sql');
            
            // تنفيذ أمر SQL
            $db->exec($sqlFile);
            
            $messages[] = 'تم إنشاء جدول الإشعارات بنجاح.'; 
        } catch (PDOException $e) {
            $errors[] = 'فشل في إنشاء جدول الإشعارات: ' . $e->getMessage(); 
        }
    }
    
    // التحقق من نجاح جميع العمليات
    if (count($errors) === 0) {
        $success = true;
    }
}

// دالة للتحقق من وجود أمر في النظام
function commandExists($command) {
    $whereIsCommand = (PHP_OS == 'WINNT') ? 'where' : 'which';
    $process = proc_open(
        "$whereIsCommand $command",
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ],
        $pipes
    );
    if (is_resource($process)) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);
        return $returnCode === 0;
    }
    return false;
}
?>

<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تثبيت وتحديث نظام إدارة المتدربين</title>
    <link rel="stylesheet" href="public/assets/css/unified-style.css">
    <style>
        .install-container {
            max-width: 800px;
            margin: 50px auto;
            padding: 30px;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }
        .step {
            margin-bottom: 30px;
            padding: 20px;
            border-radius: 5px;
            background-color: #f8f9fa;
            border-right: 4px solid #007bff;
        }
        .step h3 {
            margin-top: 0;
            color: #007bff;
        }
        .messages {
            margin: 20px 0;
            padding: 15px;
            border-radius: 5px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .btn-container {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <h1>تثبيت وتحديث نظام إدارة المتدربين</h1>
        
        <?php if ($success): ?>
            <div class="messages success">
                <h3>تم التثبيت بنجاح!</h3>
                <p>تم تثبيت وتحديث النظام بنجاح. يمكنك الآن استخدام النظام بكامل وظائفه.</p>
                <a href="index.php" class="btn btn-primary">العودة إلى الصفحة الرئيسية</a>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($messages)): ?>
            <div class="messages success">
                <h3>رسائل النظام</h3>
                <ul>
                    <?php foreach ($messages as $message): ?>
                        <li><?php echo $message; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="messages error">
                <h3>أخطاء</h3>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if (!$success): ?>
            <form method="post" action="">
                <div class="step">
                    <h3>الخطوة 1: تثبيت المكتبات المطلوبة</h3>
                    <p>سيقوم هذا الإجراء بتثبيت المكتبات المطلوبة باستخدام Composer:</p>
                    <ul>
                        <li>tecnickcom/tcpdf - لإنشاء ملفات PDF</li>
                        <li>phpoffice/phpspreadsheet - لإنشاء ملفات Excel</li>
                        <li>mpdf/mpdf - لإنشاء ملفات PDF بدعم اللغة العربية</li>
                    </ul>
                    <div class="btn-container">
                        <button type="submit" name="install_dependencies" class="btn btn-primary">تثبيت المكتبات</button>
                    </div>
                </div>
                
                <div class="step">
                    <h3>الخطوة 2: تطبيق فهارس قاعدة البيانات</h3>
                    <p>سيقوم هذا الإجراء بتطبيق فهارس قاعدة البيانات لتحسين أداء الاستعلامات:</p>
                    <div class="btn-container">
                        <button type="submit" name="apply_indexes" class="btn btn-primary">تطبيق الفهارس</button>
                    </div>
                </div>
                
                <div class="step">
                    <h3>الخطوة 3: إنشاء جدول الإشعارات</h3>
                    <p>سيقوم هذا الإجراء بإنشاء جدول الإشعارات في قاعدة البيانات:</p>
                    <div class="btn-container">
                        <button type="submit" name="create_notifications_table" class="btn btn-primary">إنشاء جدول الإشعارات</button>
                    </div>
                </div>
                
                <div class="btn-container">
                    <button type="submit" name="install_all" class="btn btn-success">تنفيذ جميع الخطوات</button>
                    <a href="index.php" class="btn btn-secondary">إلغاء</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
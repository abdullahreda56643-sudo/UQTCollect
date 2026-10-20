<?php
// صفحة تنفيذ النسخ الاحتياطي - متاحة للمدير فقط
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

// تأكد من أن المستخدم مدير
if (!isAdmin()) {
    safeRedirect(rtrim(SITE_URL, '/') . '/index.php');
}

// فقط قبول طلبات POST لإنشاء نسخة احتياطية
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? 'create';

// تنظيف النسخ القديمة (أقدم من 7 أيام)
function cleanup_old_backups($days = 7) {
    $files = glob(BACKUP_PATH . '*');
    $now = time();
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        if ($now - filemtime($file) > ($days * 86400)) {
            @unlink($file);
        }
    }
}

// إنشاء تفريغ لقاعدة البيانات باستخدام mysqldump (يتطلب توفر mysqldump)
function create_db_dump($dumpPath) {
    $host = DB_HOST;
    $port = defined('DB_PORT') ? DB_PORT : 3306;
    $user = DB_USER;
    $pass = DB_PASS;
    $db = DB_NAME;

    // حاول استخدام mysqldump الموجود في PATH
    $cmd = sprintf('mysqldump --host=%s --port=%d --user=%s --password=%s %s > "%s" 2>&1',
        escapeshellarg($host), (int)$port, escapeshellarg($user), escapeshellarg($pass), escapeshellarg($db), $dumpPath
    );

    // على ويندوز قد لا يكون mysqldump في PATH - جرب مسار XAMPP الافتراضي
    exec($cmd, $output, $returnVar);
    if ($returnVar !== 0 || !file_exists($dumpPath)) {
        // попытка с явным путем XAMPP (Windows)
        $possible = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe'
        ];
        foreach ($possible as $p) {
            if (is_executable($p)) {
                $cmd2 = sprintf('"%s" --host=%s --port=%d --user=%s --password=%s %s > "%s" 2>&1',
                    $p, escapeshellarg($host), (int)$port, escapeshellarg($user), escapeshellarg($pass), escapeshellarg($db), $dumpPath
                );
                exec($cmd2, $output, $returnVar2);
                if ($returnVar2 === 0 && file_exists($dumpPath)) {
                    return true;
                }
            }
        }
        return false;
    }
    return true;
}

// وظيفة لإضافة مجلدات/ملفات إلى ZIP بشكل متكرر مع دعم استبعاد مسارات
function addFolderToZip($zip, $folder, $basePathLength, $excludes = []) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $filePath = $file->getRealPath();
        // Normalize slashes for comparison
        $norm = str_replace('\\', '/', $filePath);
        $skip = false;
        foreach ($excludes as $ex) {
            // ex may be a directory or pattern
            $exNorm = str_replace('\\', '/', $ex);
            if ($exNorm === '') continue;
            if (stripos($norm, $exNorm) === 0 || stripos($norm, '/' . trim($exNorm, '/') ) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;
        $localPath = substr($filePath, $basePathLength + 1);
        $zip->addFile($filePath, $localPath);
    }
}

if ($action === 'create') {
    // تأكد من وجود مجلد النسخ
    if (!is_dir(BACKUP_PATH) && !mkdir(BACKUP_PATH, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create backup folder']);
        exit;
    }

    $timestamp = date('Ymd_His');
    $dumpFile = TEMP_PATH . 'db_dump_' . $timestamp . '.sql';
    $zipFileName = 'backup_' . $timestamp . '.zip';
    $zipFilePath = BACKUP_PATH . $zipFileName;

    // cleanup old backups first
    cleanup_old_backups(7);

    $dbDumpOk = create_db_dump($dumpFile);
    if (!$dbDumpOk) {
        // حاول العودة مع رسالة مفيدة
        echo json_encode(['success' => false, 'message' => 'Failed to create database dump. Ensure mysqldump is available and DB credentials in config.php are correct.']);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFilePath, ZipArchive::CREATE) !== true) {
        @unlink($dumpFile);
        echo json_encode(['success' => false, 'message' => 'Failed to create zip archive']);
        exit;
    }

    // نضيف تفريغ قاعدة البيانات
    $zip->addFile($dumpFile, 'database/db_dump_' . $timestamp . '.sql');

    // نضيف مجلد التحميلات (uploads)
    $basePath = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/../../')), '/');
    $basePathLength = strlen($basePath);

    // Include the whole project root, but exclude backup folder itself and other large/temporary dirs
    $projectRoot = realpath(__DIR__ . '/../../');
    $excludes = [
        str_replace('\\','/', rtrim(BACKUP_PATH, '/')),
        str_replace('\\','/', $projectRoot . '/vendor'),
        str_replace('\\','/', $projectRoot . '/node_modules'),
        str_replace('\\','/', $projectRoot . '/temp'),
        str_replace('\\','/', $projectRoot . '/cache'),
        str_replace('\\','/', $projectRoot . '/logs'),
        str_replace('\\','/', $projectRoot . '/backups'),
        $projectRoot . '/.git'
    ];

    // Add everything under project root except excluded paths
    if ($projectRoot !== false && is_dir($projectRoot)) {
        addFolderToZip($zip, $projectRoot, $basePathLength, $excludes);
    }

    $zip->close();

    // حذف ملف التفريغ المؤقت
    @unlink($dumpFile);

    if (!file_exists($zipFilePath)) {
        echo json_encode(['success' => false, 'message' => 'Backup failed (zip not created)']);
        exit;
    }

    // تسجيل حدث في سجل الأنشطة إن أمكن
    if (file_exists(__DIR__ . '/../../db/connection.php')) {
        try {
            require_once __DIR__ . '/../../db/connection.php';
            $dbObj = new Database();
            $pdo = $dbObj->getConnection();
            if (function_exists('logActivity')) {
                // لا نريد استدعاء شامل هنا لأن logActivity يتطلب $db ويُعرّف ضمن includes
                // بدلاً من ذلك، نضيف صف في ملف السجل العام
                error_log("Backup created: " . $zipFilePath);
            }
        } catch (Exception $e) {
            // تجاهل أخطاء التسجيل
        }
    }

    // استجابة بالنجاح ورابط التحميل
    $downloadUrl = rtrim(SITE_URL, '/') . '/backups/' . $zipFileName;
    echo json_encode(['success' => true, 'message' => 'Backup created', 'file' => $zipFileName, 'download' => $downloadUrl]);
    exit;
}

// غير مدعوم
echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;

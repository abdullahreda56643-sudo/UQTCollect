<?php
require_once '../config.php';
require_once 'connection.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // تحديد الملف المطلوب تنفيذه
    $migrationFile = isset($argv[1]) ? $argv[1] : null;
    
    if (!$migrationFile) {
        die("يرجى تحديد اسم ملف الترحيل\n");
    }
    
    $filePath = __DIR__ . '/migrations/' . $migrationFile;
    if (!file_exists($filePath)) {
        die("ملف الترحيل غير موجود: " . $filePath . "\n");
    }
    
    // قراءة وتنفيذ ملف الترحيل
    $sql = file_get_contents($filePath);
    $db->exec($sql);
    
    echo "تم تنفيذ الترحيل بنجاح: " . $migrationFile . "\n";
    
} catch (PDOException $e) {
    die("خطأ في تنفيذ الترحيل: " . $e->getMessage() . "\n");
}

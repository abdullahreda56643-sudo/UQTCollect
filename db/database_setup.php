<?php
require_once '../config.php';
require_once 'connection.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // قراءة وتنفيذ ملف ترحيل جدول الإشعارات
    $notificationsTableSql = file_get_contents(__DIR__ . '/migrations/create_notifications_table.sql');
    $db->exec($notificationsTableSql);
    
    // قراءة وتنفيذ ملف ترحيل جدول التواصل
    $communicationsTableSql = file_get_contents(__DIR__ . '/migrations/create_communications_table.sql');
    $db->exec($communicationsTableSql);
    
    echo "تم إنشاء جداول النظام بنجاح\n";
    
} catch (PDOException $e) {
    die("خطأ في إعداد قاعدة البيانات: " . $e->getMessage());
}

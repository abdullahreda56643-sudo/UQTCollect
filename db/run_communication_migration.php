<?php
require_once '../config.php';
require_once 'connection.php';

function executeMigration($db, $sql) {
    try {
        $db->exec($sql);
        echo "تم تنفيذ الترحيل بنجاح\n";
        return true;
    } catch (PDOException $e) {
        echo "خطأ في تنفيذ الترحيل: " . $e->getMessage() . "\n";
        return false;
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // SQL لإنشاء جدول التواصل
    $sql = "CREATE TABLE IF NOT EXISTS `communications` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `trainee_id` INT NOT NULL COMMENT 'المتدرب',
        `type` ENUM('call', 'whatsapp') NOT NULL COMMENT 'نوع التواصل',
        `result` ENUM('promise', 'refused', 'paid', 'no_answer', 'other') NOT NULL COMMENT 'نتيجة التواصل',
        `notes` TEXT COMMENT 'ملاحظات',
        `contact_date` DATETIME NOT NULL COMMENT 'تاريخ التواصل',
        `next_action_date` DATETIME COMMENT 'موعد الإجراء التالي',
        `next_action_notes` TEXT COMMENT 'ملاحظات الإجراء التالي',
        `reminder_id` INT COMMENT 'معرف التذكير المرتبط',
        `created_by` INT NOT NULL COMMENT 'الموظف',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`trainee_id`) REFERENCES `trainees`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE NO ACTION,
        FOREIGN KEY (`reminder_id`) REFERENCES `tasks`(`id`) ON DELETE SET NULL,
        INDEX `idx_trainee_date` (`trainee_id`, `contact_date`),
        INDEX `idx_type_result` (`type`, `result`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجلات التواصل مع المتدربين'";
    
    if (executeMigration($db, $sql)) {
        echo "<div style='color: green; font-family: Arial;'>✓ تم إنشاء جدول التواصل بنجاح</div>";
    } else {
        echo "<div style='color: red; font-family: Arial;'>✗ فشل في إنشاء جدول التواصل</div>";
    }
    
} catch (PDOException $e) {
    die("<div style='color: red; font-family: Arial;'>خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage() . "</div>");
}
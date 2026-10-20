-- جدول سجل النشاطات
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(50) NOT NULL COMMENT 'نوع النشاط',
    `module` VARCHAR(50) NOT NULL COMMENT 'الوحدة المتأثرة',
    `record_id` INT NOT NULL COMMENT 'معرف السجل المتأثر',
    `description` TEXT NOT NULL COMMENT 'وصف النشاط',
    `user_id` INT NULL COMMENT 'المستخدم الذي قام بالنشاط',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_module` (`module`),
    INDEX `idx_action` (`action`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_created` (`created_at`)
) COMMENT='سجل نشاطات النظام';
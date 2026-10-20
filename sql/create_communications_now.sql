USE `training_management`;

-- جدول التواصل مع المتدربين
CREATE TABLE IF NOT EXISTS `communications` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول سجلات التواصل مع المتدربين';
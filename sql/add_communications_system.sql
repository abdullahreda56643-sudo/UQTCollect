-- إضافة جدول الاتصالات
CREATE TABLE IF NOT EXISTS `communications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `trainee_id` INT NOT NULL,
    `type` ENUM('communication', 'payment') NOT NULL COMMENT 'نوع التواصل',
    `contact_date` DATE NOT NULL COMMENT 'تاريخ التواصل',
    `result` VARCHAR(100) COMMENT 'نتيجة التواصل',
    `notes` TEXT COMMENT 'ملاحظات',
    `next_action_date` DATE NULL COMMENT 'تاريخ الإجراء التالي',
    `next_action_notes` TEXT COMMENT 'ملاحظات الإجراء التالي',
    `created_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`trainee_id`) REFERENCES `trainees`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    INDEX `idx_trainee_date` (`trainee_id`, `contact_date`),
    INDEX `idx_contact_date` (`contact_date`),
    INDEX `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إضافة الأعمدة الجديدة إلى جدول المتدربين
ALTER TABLE `trainees`
ADD COLUMN `last_contact` DATE NULL COMMENT 'تاريخ آخر تواصل' AFTER `status`,
ADD COLUMN `next_action` DATE NULL COMMENT 'تاريخ الإجراء التالي' AFTER `last_contact`,
ADD COLUMN `next_action_notes` TEXT COMMENT 'ملاحظات الإجراء التالي' AFTER `next_action`,
ADD INDEX `idx_last_contact` (`last_contact`),
ADD INDEX `idx_next_action` (`next_action`);

-- Trigger لتحديث حقل last_contact في جدول المتدربين عند إضافة اتصال جديد
DELIMITER //
CREATE TRIGGER `update_trainee_last_contact` AFTER INSERT ON `communications`
FOR EACH ROW
BEGIN
    UPDATE `trainees` 
    SET `last_contact` = NEW.contact_date,
        `next_action` = NEW.next_action_date,
        `next_action_notes` = NEW.next_action_notes
    WHERE `id` = NEW.trainee_id;
END;//
DELIMITER ;
USE `training_management`;

-- إضافة الأعمدة الإضافية لجدول المتدربين
ALTER TABLE `trainees`
ADD COLUMN `assignment_date` TIMESTAMP NULL COMMENT 'تاريخ الإسناد' AFTER `assigned_to`,
ADD COLUMN `assignment_notes` TEXT NULL COMMENT 'ملاحظات الإسناد' AFTER `assignment_date`,
ADD COLUMN `updated_by` INT NULL COMMENT 'آخر من قام بالتحديث' AFTER `updated_at`,
ADD FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;
-- جدول المتدربين - إضافة عمود assigned_to
ALTER TABLE `trainees`
ADD COLUMN `assigned_to` INT NULL COMMENT 'معرف الموظف المسؤول' AFTER `branch_id`,
ADD FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
ADD INDEX `idx_assigned_to` (`assigned_to`);
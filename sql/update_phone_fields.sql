-- تحديث حقول رقم الهاتف لتكون أكثر مرونة
ALTER TABLE `trainees` MODIFY COLUMN `phone` VARCHAR(50) NULL COMMENT 'رقم الجوال';
ALTER TABLE `trainees` MODIFY COLUMN `phone2` VARCHAR(50) NULL COMMENT 'رقم الجوال 2';
ALTER TABLE `users` MODIFY COLUMN `phone` VARCHAR(50);
ALTER TABLE `branches` MODIFY COLUMN `phone` VARCHAR(50);
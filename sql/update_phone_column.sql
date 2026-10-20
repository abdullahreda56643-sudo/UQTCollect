USE `training_management`;

-- تعديل حقل رقم الجوال في جدول المتدربين ليقبل الصيغة الدولية
ALTER TABLE `trainees` 
    MODIFY COLUMN `phone` VARCHAR(15) NULL COMMENT 'رقم الجوال (بالصيغة الدولية مثل +966512345678)',
    MODIFY COLUMN `phone2` VARCHAR(15) NULL COMMENT 'رقم الجوال 2 (بالصيغة الدولية مثل +966512345678)';

























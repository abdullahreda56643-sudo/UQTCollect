-- إنشاء جدول الخصومات
CREATE TABLE IF NOT EXISTS `discounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trainee_id` int(11) NOT NULL,
  `type` enum('fixed','percent') NOT NULL COMMENT 'fixed: مبلغ ثابت, percent: نسبة مئوية',
  `amount` decimal(10,2) DEFAULT NULL COMMENT 'قيمة الخصم الثابتة',
  `percent` decimal(5,2) DEFAULT NULL COMMENT 'نسبة الخصم المئوية',
  `applied_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي قام بتطبيق الخصم',
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'تاريخ تطبيق الخصم',
  `reason` text DEFAULT NULL COMMENT 'سبب الخصم',
  `invoice_id` int(11) DEFAULT NULL COMMENT 'معرف الدفعة المرتبطة بالخصم',
  `reversed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'هل تم إلغاء الخصم',
  `reversed_by` int(11) DEFAULT NULL COMMENT 'معرف المستخدم الذي ألغى الخصم',
  `reversed_at` timestamp NULL DEFAULT NULL COMMENT 'تاريخ إلغاء الخصم',
  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `trainee_id` (`trainee_id`),
  KEY `applied_by` (`applied_by`),
  KEY `invoice_id` (`invoice_id`),
  KEY `reversed` (`reversed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول الخصومات للمتدربين';

-- إضافة أعمدة الخصم إلى جدول المدفوعات
ALTER TABLE `payments` 
ADD COLUMN `discount_id` int(11) DEFAULT NULL COMMENT 'معرف الخصم المرتبط بالدفعة',
ADD COLUMN `discount_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'قيمة الخصم المطبقة على هذه الدفعة';

-- إضافة فهارس للأعمدة الجديدة
ALTER TABLE `payments` 
ADD INDEX `discount_id` (`discount_id`);

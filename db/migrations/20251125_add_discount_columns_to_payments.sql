-- Migration: add discount columns to payments table
-- Run in your database (make backup first).

ALTER TABLE `payments`
  ADD COLUMN `discount_id` INT NULL AFTER `notes`,
  ADD COLUMN `discount_amount` DECIMAL(12,2) NULL AFTER `discount_id`,
  ADD INDEX (`discount_id`);

-- Optionally add a foreign key to discounts if desired (uncomment to enable)
-- ALTER TABLE `payments` ADD CONSTRAINT `fk_payments_discount` FOREIGN KEY (`discount_id`) REFERENCES `discounts`(`id`) ON DELETE SET NULL;

-- Rollback:
-- ALTER TABLE `payments` DROP COLUMN `discount_amount`, DROP COLUMN `discount_id`;

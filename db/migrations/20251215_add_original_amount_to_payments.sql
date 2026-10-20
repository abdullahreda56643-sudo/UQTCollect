-- Migration: add original_amount column to payments table
-- Run in your database (make backup first).

ALTER TABLE `payments`
  ADD COLUMN `original_amount` DECIMAL(12,2) NULL AFTER `discount_amount`,
  ADD INDEX (`original_amount`);

-- Update existing records to populate original_amount
UPDATE `payments` 
SET `original_amount` = `amount` + COALESCE(`discount_amount`, 0) 
WHERE `original_amount` IS NULL;

-- Make original_amount NOT NULL after populating existing records
ALTER TABLE `payments` 
  MODIFY COLUMN `original_amount` DECIMAL(12,2) NOT NULL;

-- Rollback:
-- ALTER TABLE `payments` DROP COLUMN `original_amount`;

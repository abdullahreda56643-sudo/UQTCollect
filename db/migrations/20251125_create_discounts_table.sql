-- Migration: create discounts table
-- Run in your database (make backup first).

CREATE TABLE IF NOT EXISTS `discounts` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `trainee_id` INT NOT NULL,
  `type` ENUM('fixed','percent') NOT NULL,
  `amount` DECIMAL(12,2) DEFAULT NULL,
  `percent` DECIMAL(5,2) DEFAULT NULL,
  `applied_by` INT DEFAULT NULL,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` TEXT DEFAULT NULL,
  `invoice_id` INT DEFAULT NULL,
  `reversed` TINYINT(1) NOT NULL DEFAULT 0,
  `reversed_by` INT DEFAULT NULL,
  `reversed_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  INDEX (`trainee_id`),
  CONSTRAINT `fk_discounts_trainee` FOREIGN KEY (`trainee_id`) REFERENCES `trainees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback:
-- DROP TABLE IF EXISTS `discounts`;

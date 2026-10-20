-- Migration: create_system_activities_and_assignment_logs.sql
-- Creates two helper tables that some installs expect: system_activities and assignment_logs
-- Run in your MySQL database (adjust DB name if necessary)

-- NOTE: Back up your database before running any migration.

CREATE TABLE IF NOT EXISTS `system_activities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `action_type` VARCHAR(100) NOT NULL,
  `module` VARCHAR(100) NULL,
  `description` TEXT NULL,
  `meta` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_system_activities_user_id` (`user_id`),
  KEY `idx_system_activities_action` (`action_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `assignment_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trainee_id` INT UNSIGNED NOT NULL,
  `assigned_to` INT UNSIGNED NOT NULL,
  `assigned_by` INT UNSIGNED NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assignment_logs_trainee` (`trainee_id`),
  KEY `idx_assignment_logs_assigned_to` (`assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: add foreign keys if you keep strict referential integrity
-- ALTER TABLE assignment_logs
--   ADD CONSTRAINT fk_assignment_logs_trainee FOREIGN KEY (trainee_id) REFERENCES trainees(id) ON DELETE CASCADE,
--   ADD CONSTRAINT fk_assignment_logs_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL;

-- End of migration

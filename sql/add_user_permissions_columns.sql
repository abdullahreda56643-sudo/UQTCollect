-- Migration: add missing columns to user_permissions
-- Run this file once on production DB with appropriate privileges

ALTER TABLE `user_permissions`
    ADD COLUMN IF NOT EXISTS `can_export` TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_import` TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_toggle` TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `can_manage_permissions` TINYINT(1) DEFAULT 0;

-- Note: MySQL versions before 8.0 do not support IF NOT EXISTS for ADD COLUMN.
-- If your MySQL doesn't support it, run the following variant after checking columns manually:
-- ALTER TABLE `user_permissions` ADD `can_export` TINYINT(1) DEFAULT 0;
-- ALTER TABLE `user_permissions` ADD `can_import` TINYINT(1) DEFAULT 0;
-- ALTER TABLE `user_permissions` ADD `can_toggle` TINYINT(1) DEFAULT 0;
-- ALTER TABLE `user_permissions` ADD `can_manage_permissions` TINYINT(1) DEFAULT 0;

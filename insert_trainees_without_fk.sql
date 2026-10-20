-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Now you can insert your trainee data
-- Paste your INSERT statements here

INSERT INTO `trainees` VALUES
(2761,'شايق راجح متعب البقمي','1107688523','599993699',NULL,'2023-03-19','27/08/1444',7820.00,1954.00,5866.00,10,10008,'promised_payment','2025-10-28 12:28:43',NULL,NULL,'بيسدد نهايه الشهر ممكن شهر 9 2025 - شهد',17,NULL,NULL,'2025-10-26 11:48:46','2025-10-28 09:28:43',NULL,1954.00),
-- ... rest of your trainee data ...

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

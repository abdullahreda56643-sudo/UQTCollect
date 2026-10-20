-- Add 'partial' to trainees.status enum to match application values
ALTER TABLE trainees MODIFY COLUMN status ENUM('paid','delayed','promised_payment','partial','refused_payment','not_responding','under_communication') NOT NULL DEFAULT 'under_communication';

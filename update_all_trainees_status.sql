-- Update all trainees to 'under_communication' status
UPDATE trainees SET status = 'under_communication';

-- Verify the update
SELECT status, COUNT(*) as count FROM trainees GROUP BY status;

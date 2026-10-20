ALTER TABLE trainees 
ADD COLUMN last_contact DATE NULL AFTER status,
ADD COLUMN next_action DATE NULL AFTER last_contact,
ADD COLUMN next_action_notes TEXT NULL AFTER next_action;

-- تحديث last_contact من جدول الاتصالات
UPDATE trainees t 
SET last_contact = (
    SELECT MAX(contact_date) 
    FROM communications c 
    WHERE c.trainee_id = t.id
);
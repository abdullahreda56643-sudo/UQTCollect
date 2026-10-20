-- Update trainee branch IDs to match existing branches
-- Map problematic branch IDs to existing ones

-- Update branch_id 10 to 1 (الفرع الرئيسي)
UPDATE trainees SET branch_id = 1 WHERE branch_id = 10;

-- Update branch_id 10007 to 10001 (الخضراء)
UPDATE trainees SET branch_id = 10001 WHERE branch_id = 10007;

-- Update branch_id 10008 to 10002 (النزهة)
UPDATE trainees SET branch_id = 10002 WHERE branch_id = 10008;

-- Update branch_id 10009 to 10003 (الشرائع)
UPDATE trainees SET branch_id = 10003 WHERE branch_id = 10009;

-- Update branch_id 10010 to 10004 (جدة رجال)
UPDATE trainees SET branch_id = 10004 WHERE branch_id = 10010;

-- Update any other high branch IDs to 1 (الفرع الرئيسي)
UPDATE trainees SET branch_id = 1 WHERE branch_id NOT IN (1, 2, 10000, 10001, 10002, 10003, 10004);

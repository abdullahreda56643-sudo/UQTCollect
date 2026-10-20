-- إضافة عمود next_action_notes إلى جدول الاتصالات
ALTER TABLE communications
ADD COLUMN next_action_notes TEXT AFTER next_action_date;
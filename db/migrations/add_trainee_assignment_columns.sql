-- إضافة أعمدة الإسناد إلى جدول المتدربين
ALTER TABLE trainees
ADD COLUMN assigned_to INT NULL,
ADD COLUMN assignment_notes TEXT NULL,
ADD COLUMN assignment_date DATETIME NULL,
ADD FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL;

-- إضافة عمود الحد الأقصى للمتدربين في جدول المستخدمين إذا لم يكن موجوداً
ALTER TABLE users
ADD COLUMN max_trainees INT NULL DEFAULT 5;
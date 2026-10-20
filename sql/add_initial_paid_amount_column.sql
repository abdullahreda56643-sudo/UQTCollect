-- إضافة عمود initial_paid_amount لحفظ الرصيد الأولي للمتدرب
-- هذا الحقل يخزن المبلغ المدفوع قبل استخدام النظام (من البيانات المستوردة)

-- التحقق من وجود العمود وإضافته إن لم يكن موجوداً
SET @dbname = DATABASE();
SET @tablename = 'trainees';
SET @columnname = 'initial_paid_amount';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' DECIMAL(12,2) DEFAULT 0.00 AFTER paid_amount')
));

PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- تحديث القيم الحالية: إذا كان هناك paid_amount ولكن لا توجد دفعات في جدول payments
-- نحفظ paid_amount الحالي في initial_paid_amount
UPDATE trainees t
SET t.initial_paid_amount = t.paid_amount
WHERE t.paid_amount > 0
  AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.trainee_id = t.id)
  AND (t.initial_paid_amount IS NULL OR t.initial_paid_amount = 0);

-- عرض النتائج
SELECT 
    COUNT(*) as total_trainees,
    SUM(CASE WHEN initial_paid_amount > 0 THEN 1 ELSE 0 END) as trainees_with_initial_paid
FROM trainees;

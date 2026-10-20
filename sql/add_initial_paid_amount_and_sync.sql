-- Migration: add initial_paid_amount, backup existing balances, populate from imported paid_amounts, and recompute balances
-- Run on a backup copy or after taking a DB dump. Designed for MySQL/MariaDB.

-- 1) Create a backup of current balances
CREATE TABLE IF NOT EXISTS trainees_balance_backup (
  trainee_id INT PRIMARY KEY,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  remaining_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  initial_paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

TRUNCATE TABLE trainees_balance_backup;

INSERT INTO trainees_balance_backup (trainee_id, paid_amount, remaining_amount)
SELECT id, paid_amount, remaining_amount FROM trainees;

-- 2) Add the column (safe: only if it doesn't already exist)
ALTER TABLE trainees
  ADD COLUMN IF NOT EXISTS initial_paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER paid_amount;

-- 3) Populate initial_paid_amount for trainees that appear to be "imported" (paid_amount > 0 but no payments rows)
--    For trainees where there are no payments, but paid_amount > 0, set initial_paid_amount = paid_amount
UPDATE trainees t
LEFT JOIN (
  SELECT trainee_id, COALESCE(SUM(amount),0) as sum_payments
  FROM payments
  GROUP BY trainee_id
) p ON p.trainee_id = t.id
SET t.initial_paid_amount = CASE
  WHEN COALESCE(p.sum_payments,0) = 0 AND t.paid_amount > 0 THEN t.paid_amount
  ELSE COALESCE(t.initial_paid_amount,0)
END
WHERE COALESCE(p.sum_payments,0) = 0 AND t.paid_amount > 0;

-- 4) Recompute authoritative paid_amount and remaining_amount for all trainees
--    paid_amount := initial_paid_amount + SUM(payments)
--    remaining_amount := total_amount - paid_amount
UPDATE trainees t
LEFT JOIN (
  SELECT trainee_id, COALESCE(SUM(amount),0) as sum_payments
  FROM payments
  GROUP BY trainee_id
) p ON p.trainee_id = t.id
SET t.paid_amount = COALESCE(t.initial_paid_amount,0) + COALESCE(p.sum_payments,0),
    t.remaining_amount = GREATEST(0, t.total_amount - (COALESCE(t.initial_paid_amount,0) + COALESCE(p.sum_payments,0))),
    t.updated_at = NOW();

-- 5) Optional: verify counts (manual step)
-- SELECT COUNT(*) as trainees_with_initial FROM trainees WHERE initial_paid_amount > 0;
-- SELECT id, total_amount, initial_paid_amount, paid_amount, remaining_amount FROM trainees WHERE initial_paid_amount > 0 LIMIT 50;

-- Rollback instructions:
-- 1) If you need to rollback the migration, you can restore values from trainees_balance_backup:
--    UPDATE trainees t
--    JOIN trainees_balance_backup b ON t.id = b.trainee_id
--    SET t.paid_amount = b.paid_amount,
--        t.remaining_amount = b.remaining_amount,
--        t.updated_at = NOW();
-- 2) If desired, drop the column:
--    ALTER TABLE trainees DROP COLUMN initial_paid_amount;

-- IMPORTANT: Take a full database dump (mysqldump) before running this migration. Test on staging first.

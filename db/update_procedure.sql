-- Robust UpdateTraineeBalance procedure
-- This procedure computes paid_amount and remaining_amount using payments table
-- and preserves any initial_paid_amount (used for imported trainees) when present.
DROP PROCEDURE IF EXISTS UpdateTraineeBalance;

DELIMITER //

CREATE PROCEDURE UpdateTraineeBalance(IN p_trainee_id INT)
BEGIN
    DECLARE v_total_paid_from_payments DECIMAL(12,2) DEFAULT 0.00;
    DECLARE v_initial_paid_raw DECIMAL(12,2) DEFAULT 0.00;
    DECLARE v_initial_paid DECIMAL(12,2) DEFAULT 0.00;
    DECLARE v_total_amount DECIMAL(12,2) DEFAULT 0.00;
    DECLARE v_stored_paid DECIMAL(12,2) DEFAULT 0.00;
    DECLARE v_payment_count INT DEFAULT 0;

    -- حساب عدد الدفعات المسجلة
    SELECT COUNT(*) INTO v_payment_count
    FROM payments
    WHERE trainee_id = p_trainee_id;

    -- حساب اجمالي الدفعات من جدول payments
    SELECT COALESCE(SUM(amount), 0) INTO v_total_paid_from_payments
    FROM payments
    WHERE trainee_id = p_trainee_id;

    -- قراءة القيمة المخزنة للـ total_amount و paid_amount
    SELECT COALESCE(total_amount,0), COALESCE(paid_amount,0) INTO v_total_amount, v_stored_paid
    FROM trainees
    WHERE id = p_trainee_id;

    -- الحصول على initial_paid_amount لو كانت موجودة
    SELECT initial_paid_amount INTO v_initial_paid_raw
    FROM trainees
    WHERE id = p_trainee_id;

    IF v_initial_paid_raw IS NULL OR v_initial_paid_raw = 0 THEN
        -- إذا لم توجد قيمة أولية، نحاول استنتاجها: المخزن (paid_amount) قد يحتوي على قيمة أولية
        SET v_initial_paid = GREATEST(0, v_stored_paid - v_total_paid_from_payments);
    ELSE
        SET v_initial_paid = v_initial_paid_raw;
    END IF;

    -- التحديث النهائي للمتدرب
    UPDATE trainees
    SET
        initial_paid_amount = v_initial_paid,
        paid_amount = v_initial_paid + v_total_paid_from_payments,
        remaining_amount = GREATEST(0, v_total_amount - (v_initial_paid + v_total_paid_from_payments)),
        status = CASE WHEN (v_initial_paid + v_total_paid_from_payments) >= v_total_amount THEN 'paid' ELSE status END,
        updated_at = NOW()
    WHERE id = p_trainee_id;
END //

DELIMITER ;
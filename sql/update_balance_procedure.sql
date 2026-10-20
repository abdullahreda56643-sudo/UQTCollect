DELIMITER $$
CREATE PROCEDURE `UpdateTraineeBalance` (IN `p_trainee_id` INT)  
BEGIN
    DECLARE v_total_paid_from_payments DECIMAL(12,2);
    DECLARE v_total_amount DECIMAL(12,2);
    
    -- حساب إجمالي الدفعات من جدول payments
    SELECT COALESCE(SUM(amount), 0) INTO v_total_paid_from_payments
    FROM payments 
    WHERE trainee_id = p_trainee_id;
    
    -- الحصول على إجمالي الرسوم
    SELECT total_amount INTO v_total_amount
    FROM trainees
    WHERE id = p_trainee_id;
    
    -- التحديث الصحيح بناءً على الدفعات الفعلية فقط
    UPDATE trainees 
    SET paid_amount = v_total_paid_from_payments,
        remaining_amount = v_total_amount - v_total_paid_from_payments,
        status = CASE 
            WHEN (v_total_amount - v_total_paid_from_payments) <= 0 THEN 'paid'
            ELSE status
        END,
        updated_at = NOW()
    WHERE id = p_trainee_id;
END$$
DELIMITER ;
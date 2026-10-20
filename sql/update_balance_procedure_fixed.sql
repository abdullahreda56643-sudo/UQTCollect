-- الإجراء المخزن المُصلّح لحساب رصيد المتدرب مع مراعاة الخصومات
DROP PROCEDURE IF EXISTS UpdateTraineeBalance;

DELIMITER $$

CREATE PROCEDURE `UpdateTraineeBalance` (IN `p_trainee_id` INT)  
BEGIN
    DECLARE v_total_paid_from_payments DECIMAL(12,2);
    DECLARE v_total_amount DECIMAL(12,2);
    
    -- حساب إجمالي الدفعات من جدول payments باستخدام original_amount (المبلغ الأصلي قبل الخصم)
    -- هذا يضمن أن الخصومات تُحسب بشكل صحيح في الرصيد المتبقي
    SELECT COALESCE(SUM(CASE 
            WHEN original_amount IS NOT NULL AND original_amount > 0 
            THEN original_amount 
            ELSE amount 
        END), 0) INTO v_total_paid_from_payments
    FROM payments 
    WHERE trainee_id = p_trainee_id;
    
    -- الحصول على إجمالي الرسوم
    SELECT total_amount INTO v_total_amount
    FROM trainees
    WHERE id = p_trainee_id;
    
    -- التحديث الصحيح بناءً على المبالغ الأصلية (قبل الخصم)
    UPDATE trainees 
    SET paid_amount = v_total_paid_from_payments,
        remaining_amount = v_total_amount - v_total_paid_from_payments,
        status = CASE 
            WHEN (v_total_amount - v_total_paid_from_payments) <= 0 THEN 'paid'
            WHEN (v_total_amount - v_total_paid_from_payments) < v_total_amount THEN 'partial'
            ELSE 'pending'
        END,
        updated_at = NOW()
    WHERE id = p_trainee_id;
END$$

DELIMITER ;

<?php
/**
 * نموذج الدفع المحسن
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.1.3
 * @author نظام إدارة المتدربين
 * @copyright 2024 جميع الحقوق محفوظة
 */

class Payment extends BaseModel {
    public function __construct($db) {
        parent::__construct($db, 'payments', 'id', [
            'trainee_id','amount','payment_date','payment_method','receipt_number','notes','created_by'
        ]);
        $this->timestamps = true;
        $this->softDelete = false;
    }

    /**
     * إضافة دفعة جديدة مع التحقق من المبلغ المتبقي (نسخة محسنة)
     */
    public function addPayment($data) {
        $transactionStarted = false;
        
        try {
            // التحقق مما إذا كان هناك transaction نشط بالفعل
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $transactionStarted = true;
            }
            
            // التحقق من وجود المتدرب
            $traineeModel = new Trainee($this->conn);
            $trainee = $traineeModel->getById($data['trainee_id']);
            
            if (!$trainee) {
                throw new Exception("المتدرب غير موجود");
            }

            // استخدام المبلغ المتبقي مباشرة من جدول trainees لأنه يحتوي على القيم الصحيحة
            $stmt = $this->conn->prepare("SELECT remaining_amount FROM trainees WHERE id = ?");
            $stmt->execute([$data['trainee_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                throw new Exception("تعذر حساب المبلغ المتبقي للمتدرب");
            }
            
            $remainingAmount = (float)$result['remaining_amount'];

            // المبلغ الذي سيتم حفظه
            $finalAmount = (float)$data['amount'];
            
            // تخزين المبلغ بشكل صحيح
            $data['amount'] = $finalAmount;

            // التحقق من صحة المبلغ
            if ($data['amount'] < 0) {
                throw new Exception('المبلغ لا يمكن أن يكون سالباً');
            }
            
            // التحقق من أن المبلغ لا يتجاوز المبلغ المتبقي
            if ($finalAmount > $remainingAmount) {
                throw new Exception('المبلغ يتجاوز المبلغ المتبقي للمتدرب');
            }
            
            // تحديث رصيد المتدرب
            $newRemainingAmount = $remainingAmount - $finalAmount;
            
            // التحقق من عدم تكرار رقم الإيصال
            if (!empty($data['receipt_number']) && $this->isReceiptNumberDuplicate($data['receipt_number'])) {
                throw new Exception("رقم الإيصال مسجل مسبقاً: " . $data['receipt_number']);
            }
            
            // إضافة الدفعة باستخدام parent::create مباشرة
            $paymentId = parent::create($data);
            
            if ($paymentId) {
                // تحديث رصيد المتدرب - استخدام طريقة بسيطة ومباشرة
            $newRemainingAmount = $remainingAmount - $finalAmount;
            $stmt = $this->conn->prepare("UPDATE trainees SET remaining_amount = ? WHERE id = ?");
            $stmt->execute([$newRemainingAmount, $data['trainee_id']]);

                // commit فقط إذا كنا بدأنا الـ transaction
                if ($transactionStarted) {
                    $this->conn->commit();
                }
                return $paymentId;
            } else {
                if ($transactionStarted) {
                    $this->conn->rollBack();
                }
                throw new Exception('فشل إضافة الدفعة. لم يتم إنشاء سجل في قاعدة البيانات.');
            }
        } catch (Exception $e) {
            // rollback فقط إذا كنا بدأنا الـ transaction
            if ($transactionStarted && $this->conn->inTransaction()) {
                try {
                    $this->conn->rollBack();
                } catch (Exception $rollbackEx) {
                    // تجاهل أخطاء الـ rollback
                }
            }
            
            error_log("Error adding payment: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * حساب المبلغ المتبقي مع مراعاة المبلغ المدفوع الأولي
     */
    private function calculateRemainingAmountWithInitial($traineeId) {
        $sql = "SELECT 
                    t.total_amount,
                    t.initial_paid_amount,
                    COALESCE(SUM(p.amount), 0) as payments_total,
                    t.total_amount - COALESCE(t.initial_paid_amount, 0) - COALESCE(SUM(p.amount), 0) as remaining_amount
                FROM trainees t
                LEFT JOIN payments p ON t.id = p.trainee_id
                WHERE t.id = :trainee_id
                GROUP BY t.id";
        
    $stmt = $this->conn->prepare($sql);
    // Use parameter keys without leading colon for execute() to avoid driver-specific mismatches
    $stmt->execute(['trainee_id' => $traineeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception("تعذر حساب المبلغ المتبقي للمتدرب");
        }
        
        return max(0, (float)$result['remaining_amount']);
    }

    /**
     * تحديث رصيد المتدرب (نسخة محسنة)
     */
    private function updateTraineeBalance($traineeId) {
        // Determine a safe value for "partial" according to the DB ENUM
        $enum = $this->getEnumValues('status');
        $params = ['trainee_id_in' => $traineeId, 'trainee_id_where' => $traineeId];

        if (in_array('partial', $enum, true)) {
            $statusWhenPartial = ":partial_value";
            $params['partial_value'] = 'partial';
        } elseif (in_array('promised_payment', $enum, true)) {
            // fallback to a close semantic value if 'partial' doesn't exist
            $statusWhenPartial = ":partial_value";
            $params['partial_value'] = 'promised_payment';
        } else {
            // If no suitable enum value exists, keep existing status when paid_amount > 0
            $statusWhenPartial = "status";
        }

        // تحديث المبلغ المتبقي بشكل بسيط وصحيح
        $sql = "UPDATE trainees 
                SET remaining_amount = remaining_amount - (
                    SELECT amount 
                    FROM payments 
                    WHERE trainee_id = :trainee_id_in 
                    ORDER BY id DESC 
                    LIMIT 1
                ),
                status = CASE 
                    WHEN remaining_amount <= 0 THEN 'paid' ";

        if ($statusWhenPartial === "status") {
            $sql .= "\n                    WHEN remaining_amount > 0 AND remaining_amount < total_amount THEN status ";
        } else {
            $sql .= "\n                    WHEN remaining_amount > 0 AND remaining_amount < total_amount THEN {$statusWhenPartial} ";
        }

        $sql .= "\n                    ELSE status 
                END,
                updated_at = NOW()
                WHERE id = :trainee_id_where";

        $stmt = $this->conn->prepare($sql);
        // Provide both parameters separately (same value)
        return $stmt->execute($params);
    }

    /**
     * الحصول على تقرير التحصيل اليومي/الأسبوعي/الشهري
     */
    public function getCollectionReport($startDate, $endDate, $period = 'daily', $filters = []) {
        $groupBy = match($period) {
            'weekly' => 'YEARWEEK(p.payment_date)',
            'monthly' => 'DATE_FORMAT(p.payment_date, "%Y-%m")',
            default => 'DATE(p.payment_date)' // daily
        };

        $dateFormat = match($period) {
            'weekly' => 'DATE_FORMAT(MIN(p.payment_date), "%Y-%m-%d") as start_date, 
                        DATE_FORMAT(MAX(p.payment_date), "%Y-%m-%d") as end_date',
            'monthly' => 'DATE_FORMAT(p.payment_date, "%Y-%m") as period',
            default => 'DATE(p.payment_date) as date'
        };

        $where = ["p.payment_date BETWEEN :start_date AND :end_date"];
        $params = [':start_date' => $startDate, ':end_date' => $endDate];

        // إضافة الفلاتر فقط إذا كانت موجودة
        if (!empty($filters['branch_id'])) {
            $where[] = "t.branch_id = :branch_id";
            $params[':branch_id'] = $filters['branch_id'];
        }
        if (!empty($filters['diploma_id'])) {
            $where[] = "t.diploma_id = :diploma_id";
            $params[':diploma_id'] = $filters['diploma_id'];
        }
        if (!empty($filters['employee_id'])) {
            $where[] = "t.assigned_to = :employee_id";
            $params[':employee_id'] = $filters['employee_id'];
        }

        $sql = "SELECT 
                    {$dateFormat},
                    COUNT(DISTINCT p.id) as payments_count,
                    COUNT(DISTINCT p.trainee_id) as trainees_count,
                    SUM(p.amount) as total_amount,
                    GROUP_CONCAT(DISTINCT b.name) as branches,
                    GROUP_CONCAT(DISTINCT d.name) as diplomas
                FROM payments p
                INNER JOIN trainees t ON t.id = p.trainee_id
                LEFT JOIN branches b ON t.branch_id = b.id
                LEFT JOIN diplomas d ON t.diploma_id = d.id
                WHERE " . implode(" AND ", $where) . "
                GROUP BY {$groupBy}
                ORDER BY " . ($period === 'daily' ? 'date' : 'period') . " DESC";

        try {
            $stmt = $this->conn->prepare($sql);
            // Debug: log SQL and params to diagnose empty results for supervisor scope
            try {
                error_log('[Payment::getPaymentsWithDetails] SQL: ' . $sql);
                error_log('[Payment::getPaymentsWithDetails] Params: ' . print_r($params, true));
            } catch (Exception $e) {
                // ignore logging errors
            }
            // Use safeExecute helper to normalize positional vs named params and avoid HY093
            if (function_exists('safeExecute')) {
                safeExecute($stmt, $params);
            } else {
                $stmt->execute($params);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error in getCollectionReport: " . $e->getMessage());
            error_log("SQL: " . $sql);
            error_log("Params: " . print_r($params, true));
            return [];
        }
    }

    /**
     * الحصول على المدفوعات مع تفاصيل المتدرب
     */
    public function getPaymentsWithDetails($filters = [], $orderBy = 'p.payment_date DESC', $limit = null, $offset = null) {
        try {
            $where = ['1 = 1'];
            $params = [];

            // 支持 trainee_id كـ مصفوفة (IN) أو قيمة واحدة
            if (!empty($filters['trainee_id'])) {
                if (is_array($filters['trainee_id'])) {
                    // Use named placeholders for IN() to avoid mixing positional and named parameters
                    $inNames = [];
                    foreach (array_values($filters['trainee_id']) as $i => $tid) {
                        $key = ':trainee_id_' . $i;
                        $inNames[] = $key;
                        // store without leading colon for execute mapping consistency
                        $params['trainee_id_' . $i] = $tid;
                    }
                    $where[] = "p.trainee_id IN (" . implode(',', $inNames) . ")";
                } else {
                    $where[] = "p.trainee_id = :trainee_id";
                    $params['trainee_id'] = $filters['trainee_id'];
                }
            }

            // فلترة حسب اسم المتدرب
            if (!empty($filters['trainee_name'])) {
                $where[] = "t.full_name LIKE :trainee_name";
                $params['trainee_name'] = '%' . $filters['trainee_name'] . '%';
            }

            // فلترة حسب الرقم القومي
            if (!empty($filters['trainee_national_id'])) {
                $where[] = "t.national_id LIKE :trainee_national_id";
                $params['trainee_national_id'] = '%' . $filters['trainee_national_id'] . '%';
            }

            // دعم نطاق تاريخ (start_date و end_date)
            if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
                $where[] = "p.payment_date BETWEEN :start_date AND :end_date";
                $params['start_date'] = $filters['start_date'];
                $params['end_date'] = $filters['end_date'];
            } elseif (!empty($filters['payment_date'])) {
                // fallback: exact date filter
                $where[] = "DATE(p.payment_date) = :payment_date";
                $params['payment_date'] = $filters['payment_date'];
            }

            if (!empty($filters['branch_id'])) {
                $where[] = "t.branch_id = :branch_id";
                $params['branch_id'] = $filters['branch_id'];
            }
            
            // إضافة فلاتر إضافية
            if (!empty($filters['payment_method'])) {
                $where[] = "p.payment_method = :payment_method";
                $params[':payment_method'] = $filters['payment_method'];
            }
            
            if (!empty($filters['created_by'])) {
                $where[] = "p.created_by = :created_by";
                $params[':created_by'] = $filters['created_by'];
            }

         $sql = "SELECT p.*, 
                  t.full_name as trainee_name,
                  t.national_id as trainee_national_id,
                  t.phone as trainee_phone,
                  t.total_amount as trainee_total_amount,
                  t.remaining_amount as trainee_remaining_amount,
                  b.name as branch_name,
                  COALESCE(uc.full_name, uc.username) as created_by_name
              FROM trainees t
                    LEFT JOIN payments p ON t.id = p.trainee_id
                    LEFT JOIN branches b ON t.branch_id = b.id
                    LEFT JOIN users uc ON p.created_by = uc.id
                    WHERE " . implode(' AND ', $where);
            
            if ($orderBy) {
                $sql .= " ORDER BY $orderBy";
            }
            
            if ($limit !== null) {
                $sql .= " LIMIT " . (int)$limit;
                if ($offset !== null) {
                    $sql .= " OFFSET " . (int)$offset;
                }
            }
            
            $stmt = $this->conn->prepare($sql);
            // Debug: log SQL and params to help diagnose supervisor empty-list issues
            try {
                error_log('[Payment::getPaymentsWithDetails] SQL: ' . $sql);
                error_log('[Payment::getPaymentsWithDetails] Params: ' . print_r($params, true));
            } catch (Exception $e) {
                // ignore logging errors
            }
            // Use safeExecute helper when available to normalize positional vs named params
            if (function_exists('safeExecute')) {
                safeExecute($stmt, $params);
            } else {
                $stmt->execute($params);
            }
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting payments with details: " . $e->getMessage());
            error_log("SQL: " . $sql);
            error_log("Params: " . print_r($params, true));
            return [];
        }
    }

    /**
     * الحصول على إحصائيات المدفوعات
     */
    public function getStatistics($startDate = null, $endDate = null) {
        try {
            $whereClause = "";
            $params = [];
            
            if ($startDate && $endDate) {
                $whereClause = "WHERE payment_date BETWEEN :start_date AND :end_date";
                $params[':start_date'] = $startDate;
                $params[':end_date'] = $endDate;
            }
            
            $sql = "SELECT 
                        COUNT(*) as total_payments,
                        COALESCE(SUM(amount), 0) as total_amount,
                        COALESCE(AVG(amount), 0) as average_amount,
                        COALESCE(MIN(amount), 0) as min_amount,
                        COALESCE(MAX(amount), 0) as max_amount,
                        SUM(CASE WHEN payment_method = 'cash' THEN 1 ELSE 0 END) as cash_count,
                        SUM(CASE WHEN payment_method = 'bank_transfer' THEN 1 ELSE 0 END) as bank_count,
                        SUM(CASE WHEN payment_method = 'check' THEN 1 ELSE 0 END) as check_count
                    FROM payments $whereClause";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting payment statistics: " . $e->getMessage());
            return false;
        }
    }

    /**
     * الحصول على المدفوعات اليومية
     */
    public function getDailyPayments($date = null) {
        try {
            $targetDate = $date ?: date('Y-m-d');
            
            $sql = "SELECT p.*, 
                           t.full_name as trainee_name,
                           t.national_id as trainee_national_id,
                           b.name as branch_name
                    FROM payments p
                    LEFT JOIN trainees t ON p.trainee_id = t.id
                    LEFT JOIN branches b ON t.branch_id = b.id
                    WHERE DATE(p.payment_date) = :date
                    ORDER BY p.payment_date DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':date', $targetDate);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting daily payments: " . $e->getMessage());
            return [];
        }
    }

    /**
     * حذف دفعة مع تحديث رصيد المتدرب
     */
    public function deletePayment($paymentId) {
        $transactionStarted = false;
        
        try {
            // الحصول على بيانات الدفعة قبل الحذف
            $payment = $this->findById($paymentId);
            if (!$payment) {
                throw new Exception("الدفعة غير موجودة");
            }
            
            // التحقق مما إذا كان هناك transaction نشط بالفعل
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $transactionStarted = true;
            }
            
            // حذف الدفعة
            $result = $this->delete($paymentId);
            
            if ($result) {
                // تحديث رصيد المتدرب
                $this->updateTraineeBalance($payment['trainee_id']);
                
                // commit فقط إذا كنا بدأنا الـ transaction
                if ($transactionStarted) {
                    $this->conn->commit();
                }
                return true;
            } else {
                if ($transactionStarted) {
                    $this->conn->rollBack();
                }
                return false;
            }
        } catch (PDOException $e) {
            // rollback فقط إذا كنا بدأنا الـ transaction
            if ($transactionStarted && $this->conn->inTransaction()) {
                try {
                    $this->conn->rollBack();
                } catch (Exception $rollbackEx) {
                    // تجاهل أخطاء الـ rollback
                }
            }
            error_log("Error deleting payment: " . $e->getMessage());
            throw new Exception("فشل حذف الدفعة: " . $e->getMessage());
        }
    }

    /**
     * التحقق من توافق بيانات المدفوعات مع بيانات المتدرب
     */
    public function verifyPaymentSync($traineeId) {
        try {
            $sql = "SELECT 
                        t.paid_amount as trainee_paid,
                        t.remaining_amount as trainee_remaining,
                        COALESCE(SUM(p.amount), 0) as payments_total,
                        t.total_amount - COALESCE(SUM(p.amount), 0) as computed_remaining
                    FROM trainees t
                    LEFT JOIN payments p ON t.id = p.trainee_id
                    WHERE t.id = :trainee_id
                    GROUP BY t.id";
            
            $stmt = $this->conn->prepare($sql);
            // Normalize execute parameter keys (no leading colon)
            $stmt->execute(['trainee_id' => $traineeId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error verifying payment sync: " . $e->getMessage());
            return false;
        }
    }

    /**
     * الحصول على مدفوعات متدرب معين
     */
    public function getTraineePayments($traineeId, $limit = null) {
        try {
            $sql = "SELECT p.*, 
                           u.name as created_by_name
                    FROM payments p
                    LEFT JOIN users u ON p.created_by = u.id
                    WHERE p.trainee_id = :trainee_id
                    ORDER BY p.payment_date DESC, p.created_at DESC";
            
            if ($limit !== null) {
                $sql .= " LIMIT " . (int)$limit;
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute(['trainee_id' => $traineeId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting trainee payments: " . $e->getMessage());
            return [];
        }
    }

    /**
     * الحصول على إجمالي المدفوعات لمتدرب معين
     */
    public function getTotalPaidByTrainee($traineeId) {
        try {
            $sql = "SELECT COALESCE(SUM(amount), 0) as total_paid 
                    FROM payments 
                    WHERE trainee_id = :trainee_id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':trainee_id' => $traineeId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (float)$result['total_paid'];
        } catch (PDOException $e) {
            error_log("Error getting total paid by trainee: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * الحصول على المدفوعات الأخيرة
     */
    public function getRecentPayments($limit = 10) {
        try {
            $sql = "SELECT p.*, 
                           t.full_name as trainee_name,
                           t.national_id as trainee_national_id,
                           b.name as branch_name
                    FROM payments p
                    LEFT JOIN trainees t ON p.trainee_id = t.id
                    LEFT JOIN branches b ON t.branch_id = b.id
                    ORDER BY p.created_at DESC
                    LIMIT :limit";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting recent payments: " . $e->getMessage());
            return [];
        }
    }

    /**
     * تصحيح عدم تطابق بيانات المدفوعات
     */
    public function fixPaymentSync($traineeId) {
        $transactionStarted = false;
        
        try {
            // التحقق مما إذا كان هناك transaction نشط بالفعل
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $transactionStarted = true;
            }
            
            // حساب المبلغ المدفوع الفعلي
            $actualPaid = $this->getTotalPaidByTrainee($traineeId);
            
            // تحديث بيانات المتدرب
            // Use distinct placeholder names for repeated :paid_amount to avoid parameter count issues
            $sql = "UPDATE trainees 
                    SET paid_amount = :paid_amount_1,
                        remaining_amount = total_amount - :paid_amount_2,
                        status = CASE 
                            WHEN :paid_amount_3 >= total_amount THEN 'paid' 
                            WHEN :paid_amount_4 > 0 THEN 'partial' 
                            ELSE 'pending'
                        END,
                        updated_at = NOW()
                    WHERE id = :trainee_id";

            // Choose safe enum values for 'partial' and 'pending' based on DB
            $enum = $this->getEnumValues('status');
            $partialValue = in_array('partial', $enum, true) ? 'partial' : (in_array('promised_payment', $enum, true) ? 'promised_payment' : null);
            $pendingValue = in_array('pending', $enum, true) ? 'pending' : (in_array('not_responding', $enum, true) ? 'not_responding' : null);

            // If DB doesn't have the target statuses, we fall back to leaving status unchanged by adjusting SQL
            if ($partialValue === null && $pendingValue === null) {
                // Replace the THEN clauses to reuse existing status where appropriate
                $sql = "UPDATE trainees 
                    SET paid_amount = :paid_amount_1,
                        remaining_amount = total_amount - :paid_amount_2,
                        status = CASE 
                            WHEN :paid_amount_3 >= total_amount THEN 'paid' 
                            WHEN :paid_amount_4 > 0 THEN status
                            ELSE status
                        END,
                        updated_at = NOW()
                    WHERE id = :trainee_id";

                $stmt = $this->conn->prepare($sql);
                $stmt->execute([
                    'paid_amount_1' => $actualPaid,
                    'paid_amount_2' => $actualPaid,
                    'paid_amount_3' => $actualPaid,
                    'paid_amount_4' => $actualPaid,
                    'trainee_id' => $traineeId
                ]);
            } else {
                // Bind chosen values (if null, we won't include them)
                $params = [
                    'paid_amount_1' => $actualPaid,
                    'paid_amount_2' => $actualPaid,
                    'paid_amount_3' => $actualPaid,
                    'paid_amount_4' => $actualPaid,
                    'trainee_id' => $traineeId
                ];
                if ($partialValue !== null) {
                    // Replace literal 'partial' with a bound parameter
                    $sql = str_replace("'partial'", ":partial_value", $sql);
                    $params['partial_value'] = $partialValue;
                }
                if ($pendingValue !== null) {
                    $sql = str_replace("'pending'", ":pending_value", $sql);
                    $params['pending_value'] = $pendingValue;
                }

                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
            }
            
            // commit فقط إذا كنا بدأنا الـ transaction
            if ($transactionStarted) {
                $this->conn->commit();
            }
            return true;
        } catch (PDOException $e) {
            // rollback فقط إذا كنا بدأنا الـ transaction
            if ($transactionStarted && $this->conn->inTransaction()) {
                try {
                    $this->conn->rollBack();
                } catch (Exception $rollbackEx) {
                    // تجاهل أخطاء الـ rollback
                }
            }
            error_log("Error fixing payment sync: " . $e->getMessage());
            return false;
        }
    }

    /**
     * التحقق من وجود رقم إيصال مكرر
     */
    public function isReceiptNumberDuplicate($receiptNumber, $excludePaymentId = null) {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM payments 
                    WHERE receipt_number = :receipt_number";
            
            $params = [':receipt_number' => $receiptNumber];
            
            if ($excludePaymentId !== null) {
                $sql .= " AND id != :exclude_id";
                $params[':exclude_id'] = $excludePaymentId;
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] > 0;
        } catch (PDOException $e) {
            error_log("Error checking receipt number duplicate: " . $e->getMessage());
            return false;
        }
    }

    /**
     * تحديث دفعة موجودة مع التحقق من البيانات
     */
    public function updatePayment($paymentId, $data) {
        $transactionStarted = false;
        
        try {
            // التحقق مما إذا كان هناك transaction نشط بالفعل
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $transactionStarted = true;
            }
            
            // الحصول على بيانات الدفعة الحالية
            $currentPayment = $this->findById($paymentId);
            if (!$currentPayment) {
                throw new Exception("الدفعة غير موجودة");
            }
            
            // التحقق من عدم تكرار رقم الإيصال (استثناء الدفعة الحالية)
            if (!empty($data['receipt_number']) && 
                $this->isReceiptNumberDuplicate($data['receipt_number'], $paymentId)) {
                throw new Exception("رقم الإيصال مسجل مسبقاً: " . $data['receipt_number']);
            }
            
            // تحديث الدفعة
            $result = parent::update($paymentId, $data);
            
            if ($result) {
                // تحديث رصيد المتدرب
                $this->updateTraineeBalance($currentPayment['trainee_id']);
                
                // commit فقط إذا كنا بدأنا الـ transaction
                if ($transactionStarted) {
                    $this->conn->commit();
                }
                return true;
            } else {
                if ($transactionStarted) {
                    $this->conn->rollBack();
                }
                throw new Exception('فشل تحديث الدفعة');
            }
        } catch (Exception $e) {
            // rollback فقط إذا كنا بدأنا الـ transaction
            if ($transactionStarted && $this->conn->inTransaction()) {
                try {
                    $this->conn->rollBack();
                } catch (Exception $rollbackEx) {
                    // تجاهل أخطاء الـ rollback
                }
            }
            error_log("Error updating payment: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * الحصول على إجمالي المبالغ المحصلة حسب الوسيلة
     */
    public function getTotalByPaymentMethod($startDate = null, $endDate = null) {
        try {
            $whereClause = "";
            $params = [];
            
            if ($startDate && $endDate) {
                $whereClause = "WHERE payment_date BETWEEN :start_date AND :end_date";
                $params[':start_date'] = $startDate;
                $params[':end_date'] = $endDate;
            }
            
            $sql = "SELECT 
                        payment_method,
                        COUNT(*) as count,
                        SUM(amount) as total_amount
                    FROM payments 
                    $whereClause
                    GROUP BY payment_method
                    ORDER BY total_amount DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting total by payment method: " . $e->getMessage());
            return [];
        }
    }
}
?>
<?php
/**
 * نموذج المتدرب المحسن
 * نظام إدارة المتدربين والتحصيل المالي
 * 
 * @version 2.1.3
 */

class Trainee extends BaseModel {
    protected $db;

    public function __construct($db) {
        parent::__construct($db, 'trainees', 'id', [
            'national_id', 'full_name', 'phone', 'phone2', 'email', 'address', 
            'branch_id', 'diploma_id', 'graduation_date', 'graduation_date_hijri', 'total_amount', 
            'paid_amount', 'initial_paid_amount', 'remaining_amount', 'status', 'assigned_to',
            'last_contact', 'next_action', 'next_action_notes', 'notes', 'created_by'
        ]);
        $this->db = $db;
        $this->timestamps = true;
        $this->softDelete = false;
    }

    /**
     * البحث عن متدرب باستخدام الرقم الوطني
     */
    public function findByNationalId($nationalId) {
        try {
            // تنظيف وتجهيز الرقم الوطني
            $nationalId = trim($nationalId);
            
            // تسجيل محاولة البحث للتصحيح
            error_log("Searching for national_id: " . $nationalId);
            
            $sql = "SELECT * FROM {$this->table} WHERE national_id = :national_id LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':national_id', $nationalId, PDO::PARAM_STR);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // تسجيل نتيجة البحث للتصحيح
            error_log("Search result for national_id: " . print_r($result, true));
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error in findByNationalId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * تحديث رصيد المتدرب باستخدام الإجراء المخزن
     */
    public function updateBalanceProcedure($traineeId) {
        try {
            $stmt = $this->db->prepare('CALL UpdateTraineeBalance(:id)');
            $stmt->bindParam(':id', $traineeId, PDO::PARAM_INT);
            $stmt->execute();
            
            // استهلاك جميع النتائج للتأكد من اكتمال الإجراء
            while ($stmt->nextRowset()) {}
            return true;
        } catch (PDOException $e) {
            error_log("Error updating trainee balance: " . $e->getMessage());
            return false;
        }
    }

    /**
     * حساب عدد المتدربين حسب الفلاتر المطبقة
     */
    public function getTraineesCount($filters = []) {
        try {
            // Enforce employee scope: if current user is employee and no assigned_to filter provided,
            // force assigned_to to current session user so employees only see their own trainees.
            try {
                if (function_exists('isEmployee') && isEmployee() && empty($filters['assigned_to']) && !empty($_SESSION['user_id'])) {
                    $filters['assigned_to'] = (int) $_SESSION['user_id'];
                }
            } catch (Throwable $e) {
                error_log('Error enforcing employee scope in getTraineesCount: ' . $e->getMessage());
            }

            $sql = "SELECT COUNT(*) as total 
                    FROM {$this->table} t 
                    WHERE 1=1";

            $params = [];

            // معالجة الفلاتر
            foreach ($filters as $field => $value) {
                if ($value === '' || $value === null) continue;
                
                switch ($field) {
                    case 'national_id':
                    case 'full_name':
                    case 'phone':
                        $sql .= " AND t.$field LIKE :$field";
                        $params[":$field"] = '%' . $value . '%';
                        break;
                    
                    case 'status':
                    case 'diploma_id':
                    case 'branch_id':
                        $sql .= " AND t.$field = :$field";
                        $params[":$field"] = $value;
                        break;
                    
                    case 'date_from':
                        $sql .= " AND DATE(t.created_at) >= :date_from";
                        $params[":date_from"] = $value;
                        break;
                    
                    case 'date_to':
                        $sql .= " AND DATE(t.created_at) <= :date_to";
                        $params[":date_to"] = $value;
                        break;
                    case 'last_contact_date':
                        // match either trainee.last_contact or any communication contact_date for that trainee
                        $sql .= " AND (DATE(t.last_contact) = :last_contact_date OR EXISTS (SELECT 1 FROM communications c2 WHERE c2.trainee_id = t.id AND DATE(c2.contact_date) = :last_contact_date))";
                        $params[":last_contact_date"] = $value;
                        break;
                    case 'has_contact':
                        // any recorded last_contact or any communication row
                        $sql .= " AND ( (t.last_contact IS NOT NULL AND t.last_contact != '0000-00-00 00:00:00') OR EXISTS (SELECT 1 FROM communications c2 WHERE c2.trainee_id = t.id) )";
                        break;
                    case 'no_contact':
                        // neither last_contact nor any communication exists
                        $sql .= " AND ( (t.last_contact IS NULL OR t.last_contact = '0000-00-00 00:00:00') AND NOT EXISTS (SELECT 1 FROM communications c2 WHERE c2.trainee_id = t.id) )";
                        break;
                    case 'graduation_date':
                        // exact match on stored gregorian date
                        $sql .= " AND (t.graduation_date = :graduation_date)";
                        $params[":graduation_date"] = $value;
                        break;
                    case 'graduation_date_raw':
                        // allow matching raw hijri text (partial match)
                        $sql .= " AND (t.graduation_date_hijri LIKE :graduation_date_hijri)";
                        $params[":graduation_date_hijri"] = '%' . $value . '%';
                        break;
                    case 'assigned_to':
                        // allow assigned_to to be int or array of ints
                        if (is_array($value)) {
                            // use named placeholders to avoid mixing positional and named params
                            $ph = [];
                            foreach ($value as $i => $v) {
                                $key = ':assigned_to_' . $i;
                                $ph[] = $key;
                                $params[$key] = (int)$v;
                            }
                            $placeholders = implode(',', $ph);
                            $sql .= " AND t.assigned_to IN ($placeholders)";
                        } else {
                            $sql .= " AND t.assigned_to = :assigned_to";
                            $params[":assigned_to"] = (int)$value;
                        }
                        break;
                }
            }

            $stmt = $this->db->prepare($sql);
            
            // Bind params: support both named (':name'=>value) and positional (numeric indexed) params
            if (!empty($params)) {
                $allKeysAreNumeric = true;
                foreach (array_keys($params) as $k) {
                    if (!is_int($k)) { $allKeysAreNumeric = false; break; }
                }
                if ($allKeysAreNumeric) {
                    // positional params: bind 1-based positions
                    $pos = 1;
                    foreach ($params as $value) {
                        $stmt->bindValue($pos, $value);
                        $pos++;
                    }
                } else {
                    foreach ($params as $key => $value) {
                        $stmt->bindValue($key, $value);
                    }
                }
            }

            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($result['total'] ?? 0);

        } catch (PDOException $e) {
            error_log("Error counting trainees: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * الحصول على المتدربين مع معلومات إضافية (نسخة مصححة ومحسنة)
     */
    public function getTraineesWithDetails($filters = [], $orderBy = 't.id ASC', $limit = null, $offset = null) {
        try {
            // Enforce employee scope: if current user is employee and no assigned_to filter provided,
            // force assigned_to to current session user so employees only see their own trainees.
            try {
                if (function_exists('isEmployee') && isEmployee() && empty($filters['assigned_to']) && !empty($_SESSION['user_id'])) {
                    $filters['assigned_to'] = (int) $_SESSION['user_id'];
                }
            } catch (Throwable $e) {
                error_log('Error enforcing employee scope in getTraineesWithDetails: ' . $e->getMessage());
            }

            // استعلام محسّن مع JOIN صحيح للدبلومات
            $sql = "SELECT t.*,
                           COALESCE(SUM(p.amount), 0) as computed_paid,
                           (t.total_amount - COALESCE(SUM(p.amount), 0)) as computed_remaining_amount,
                           b.name as branch_name,
                           d.name as diploma_name,
                           u.full_name as assigned_to_name,
                           COALESCE(c.result, 'لم يتم التواصل') as last_communication_result,
                           COALESCE(DATE_FORMAT(c.contact_date, '%Y-%m-%d'), 'لم يتم التواصل') as contact_status
                    FROM {$this->table} t
                    LEFT JOIN payments p ON p.trainee_id = t.id
                    LEFT JOIN branches b ON t.branch_id = b.id
                    LEFT JOIN diplomas d ON t.diploma_id = d.id  -- هذا هو ال JOIN المهم
                    LEFT JOIN users u ON t.assigned_to = u.id
                    LEFT JOIN (
                        -- Get the most recent communication for each trainee with its result
                        SELECT c1.trainee_id, c1.contact_date, c1.result
                        FROM communications c1
                        INNER JOIN (
                            SELECT trainee_id, MAX(contact_date) as max_date
                            FROM communications
                            GROUP BY trainee_id
                        ) c2 ON c1.trainee_id = c2.trainee_id AND c1.contact_date = c2.max_date
                    ) c ON t.id = c.trainee_id
                    WHERE 1=1";

            $params = [];

            // معالجة الفلاتر
            foreach ($filters as $field => $value) {
                if ($value === '' || $value === null) continue;
                
                switch ($field) {
                    case 'national_id':
                    case 'full_name':
                    case 'phone':
                        $sql .= " AND t.$field LIKE :$field";
                        $params[":$field"] = '%' . $value . '%';
                        break;
                    
                    case 'status':
                    case 'diploma_id':
                    case 'branch_id':
                        $sql .= " AND t.$field = :$field";
                        $params[":$field"] = $value;
                        break;
                    case 'assigned_to':
                        if (is_array($value)) {
                            $ph = [];
                            foreach ($value as $i => $v) {
                                $key = ':assigned_to_' . $i;
                                $ph[] = $key;
                                $params[$key] = (int)$v;
                            }
                            $placeholders = implode(',', $ph);
                            $sql .= " AND t.assigned_to IN ($placeholders)";
                        } else {
                            $sql .= " AND t.assigned_to = :assigned_to";
                            $params[":assigned_to"] = (int)$value;
                        }
                        break;
                    
                    case 'date_from':
                        $sql .= " AND DATE(t.created_at) >= :date_from";
                        $params[":date_from"] = $value;
                        break;
                    
                    case 'date_to':
                        $sql .= " AND DATE(t.created_at) <= :date_to";
                        $params[":date_to"] = $value;
                        break;
                    case 'last_contact_date':
                        $sql .= " AND (DATE(t.last_contact) = :last_contact_date OR EXISTS (SELECT 1 FROM communications c2 WHERE c2.trainee_id = t.id AND DATE(c2.contact_date) = :last_contact_date))";
                        $params[":last_contact_date"] = $value;
                        break;
                    case 'has_contact':
                        // any recorded last_contact or any communication row
                        $sql .= " AND ( (t.last_contact IS NOT NULL AND t.last_contact != '0000-00-00 00:00:00') OR EXISTS (SELECT 1 FROM communications c2 WHERE c2.trainee_id = t.id) )";
                        break;
                    case 'no_contact':
                        // neither last_contact nor any communication exists
                        $sql .= " AND ( (t.last_contact IS NULL OR t.last_contact = '0000-00-00 00:00:00') AND NOT EXISTS (SELECT 1 FROM communications c2 WHERE c2.trainee_id = t.id) )";
                        break;
                    case 'graduation_date':
                        // exact match on stored gregorian date
                        $sql .= " AND (t.graduation_date = :graduation_date)";
                        $params[":graduation_date"] = $value;
                        break;
                    case 'graduation_date_raw':
                        // allow matching raw hijri text (partial match)
                        $sql .= " AND (t.graduation_date_hijri LIKE :graduation_date_hijri)";
                        $params[":graduation_date_hijri"] = '%' . $value . '%';
                        break;
                    case 'communication_result':
                        // Filter by most recent communication result
                        $sql .= " AND c.result = :communication_result";
                        $params[":communication_result"] = $value;
                        break;
                }
            }

            $sql .= " GROUP BY t.id, d.id";  // إضافة d.id للتجميع لمنع مشاكل الدبلومات
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                error_log('DEBUG: Final SQL Query: ' . $sql);
                error_log('DEBUG: SQL Params: ' . print_r($params, true));
            }

            if ($orderBy) {
                $sql .= " ORDER BY " . $this->sanitizeOrderBy($orderBy);
            }

            if ($limit !== null) {
                $sql .= " LIMIT :limit";
                if ($offset !== null) {
                    $sql .= " OFFSET :offset";
                }
            }

            $stmt = $this->db->prepare($sql);

            // Bind params: support both named (':name'=>value) and positional (numeric indexed) params
            if (!empty($params)) {
                $allKeysAreNumeric = true;
                foreach (array_keys($params) as $k) {
                    if (!is_int($k)) { $allKeysAreNumeric = false; break; }
                }
                if ($allKeysAreNumeric) {
                    $pos = 1;
                    foreach ($params as $value) {
                        $stmt->bindValue($pos, $value);
                        $pos++;
                    }
                } else {
                    foreach ($params as $key => $value) {
                        $stmt->bindValue($key, $value);
                    }
                }
            }

            if ($limit !== null) {
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
                if ($offset !== null) {
                    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
                }
            }

            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // تسجيل النتائج للتصحيح
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                error_log("DEBUG: Trainees with diplomas - " . count($results) . " records found");
                foreach ($results as $result) {
                    error_log("DEBUG: Trainee ID: " . $result['id'] . 
                             ", Name: " . $result['full_name'] . 
                             ", Diploma ID: " . $result['diploma_id'] . 
                             ", Diploma Name: " . $result['diploma_name']);
                }
            }

            return $results;

        } catch (PDOException $e) {
            error_log("Error getting trainees with details: " . $e->getMessage());
            return [];
        }
    }

    /**
     * التحقق من صحة بيانات الدبلومات للمتدربين
     */
    public function debugDiplomaData($traineeIds = []) {
        try {
            if (empty($traineeIds)) {
                return [];
            }
            
            $placeholders = implode(',', array_fill(0, count($traineeIds), '?'));
            
            $sql = "SELECT 
                        t.id as trainee_id,
                        t.full_name,
                        t.diploma_id,
                        d.id as diploma_db_id,
                        d.name as diploma_name
                    FROM trainees t
                    LEFT JOIN diplomas d ON t.diploma_id = d.id
                    WHERE t.id IN ($placeholders)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($traineeIds);
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // تسجيل النتائج للتصحيح
            error_log("Diploma Debug Results: " . print_r($results, true));
            
            return $results;
            
        } catch (PDOException $e) {
            error_log("Error debugging diploma data: " . $e->getMessage());
            return [];
        }
    }

    /**
     * الحصول على إحصائيات مفصلة للدبلومات
     */
    public function getDiplomaStatistics() {
        try {
            $sql = "SELECT 
                        d.id,
                        d.name as diploma_name,
                        COUNT(t.id) as trainee_count,
                        COALESCE(SUM(t.total_amount), 0) as total_revenue,
                        COALESCE(SUM(t.paid_amount), 0) as total_paid,
                        COALESCE(SUM(t.remaining_amount), 0) as total_remaining
                    FROM diplomas d
                    LEFT JOIN trainees t ON d.id = t.diploma_id
                    GROUP BY d.id, d.name
                    ORDER BY trainee_count DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting diploma statistics: " . $e->getMessage());
            return [];
        }
    }

    /**
     * إحصائيات المتدربين مع إمكانية التصفية حسب الفرع
     */
    public function getStatistics($branchId = null) {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_trainees,
                        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                        SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) as delayed_count,
                        SUM(CASE WHEN status = 'promised_payment' THEN 1 ELSE 0 END) as promised_count,
                        SUM(CASE WHEN status = 'under_communication' THEN 1 ELSE 0 END) as communication_count,
                        COALESCE(SUM(total_amount), 0) as total_amount,
                        COALESCE(SUM(paid_amount), 0) as total_paid,
                        COALESCE(SUM(remaining_amount), 0) as total_remaining
                    FROM trainees 
                    WHERE 1=1";
            
            $params = [];
            
            if ($branchId !== null) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branchId;
            }
            
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting trainee statistics: " . $e->getMessage());
            return false;
        }
    }

    /**
     * البحث المتقدم في المتدربين
     */
    public function search($query, $filters = [], $limit = 50) {
        try {
            $searchTerm = "%$query%";
            $where = ["(t.full_name LIKE :query OR t.national_id LIKE :query OR t.phone LIKE :query OR t.email LIKE :query)"];
            $params = [':query' => $searchTerm];

            // إضافة فلاتر إضافية إذا وجدت
            foreach ($filters as $field => $value) {
                if ($value === '' || $value === null) continue;
                
                if (in_array($field, ['branch_id', 'diploma_id', 'status', 'assigned_to'])) {
                    $where[] = "t.$field = :$field";
                    $params[":$field"] = $value;
                }
            }

            $sql = "SELECT t.*, 
                           b.name as branch_name,
                           d.name as diploma_name,
                           u.full_name as assigned_employee_name
                    FROM trainees t
                    LEFT JOIN branches b ON t.branch_id = b.id
                    LEFT JOIN diplomas d ON t.diploma_id = d.id
                    LEFT JOIN users u ON t.assigned_to = u.id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY t.full_name
                    LIMIT :limit";
            
            $stmt = $this->db->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            // Detailed logging to help diagnose parameter/placeholder mismatches (HY093)
            error_log("Error searching trainees: " . $e->getMessage());
            // Log the final SQL and params (if available) for debugging in production
            try {
                if (!empty($sql)) {
                    error_log("Error searching trainees - SQL: " . $sql);
                }
                if (!empty($params) && is_array($params)) {
                    error_log("Error searching trainees - Params: " . print_r($params, true));
                }
            } catch (Throwable $inner) {
                // swallow any logging errors to avoid masking the original exception
                error_log('Error while logging search context: ' . $inner->getMessage());
            }

            return [];
        }
    }

    /**
     * الحصول على متدرب مع جميع التفاصيل المرتبطة
     */
    public function getTraineeWithFullDetails($id) {
        try {
            $sql = "SELECT t.*,
                           b.name as branch_name,
                           d.name as diploma_name,
                           d.price as diploma_price,
                           u.full_name as assigned_employee_name,
                           u.phone as employee_phone,
                           uc.full_name as created_by_name,
                           COALESCE(SUM(p.amount), 0) as computed_paid_amount,
                           (t.total_amount - COALESCE(SUM(p.amount), 0)) as computed_remaining_amount
                    FROM trainees t
                    LEFT JOIN branches b ON t.branch_id = b.id
                    LEFT JOIN diplomas d ON t.diploma_id = d.id
                    LEFT JOIN users u ON t.assigned_to = u.id
                    LEFT JOIN users uc ON t.created_by = uc.id
                    LEFT JOIN payments p ON p.trainee_id = t.id
                    WHERE t.id = :id
                    GROUP BY t.id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting trainee with full details: " . $e->getMessage());
            return false;
        }
    }

    /**
     * دالة مساعدة لتنظيف ORDER BY
     */
    private function sanitizeOrderBy($orderBy) {
        // إزالة الأحرف غير الآمنة
        return preg_replace('/[^a-zA-Z0-9_,\.\s]/', '', $orderBy);
    }

    /**
     * تحديث حالة المتدرب
     */
    public function updateStatus($traineeId, $status) {
        try {
            $allowedStatuses = ['paid', 'delayed', 'promised_payment', 'under_communication', 'new'];
            
            if (!in_array($status, $allowedStatuses)) {
                throw new InvalidArgumentException("الحالة غير صالحة");
            }
            
            $sql = "UPDATE trainees SET status = :status, updated_at = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $traineeId, PDO::PARAM_INT);
            
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Error updating trainee status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * تحديث مبالغ المتدرب يدوياً
     */
    public function updateAmounts($traineeId, $totalAmount, $paidAmount, $remainingAmount) {
        try {
            $sql = "UPDATE trainees SET 
                    total_amount = :total_amount, 
                    paid_amount = :paid_amount, 
                    remaining_amount = :remaining_amount,
                    updated_at = NOW() 
                    WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':total_amount', $totalAmount, PDO::PARAM_STR);
            $stmt->bindParam(':paid_amount', $paidAmount, PDO::PARAM_STR);
            $stmt->bindParam(':remaining_amount', $remainingAmount, PDO::PARAM_STR);
            $stmt->bindParam(':id', $traineeId, PDO::PARAM_INT);
            
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Error updating trainee amounts: " . $e->getMessage());
            return false;
        }
    }

    /**
     * الحصول على المدفوعات للمتدرب
     */
    public function getTraineePayments($traineeId) {
        try {
            $sql = "SELECT p.*, u.full_name as created_by_name 
                    FROM payments p 
                    LEFT JOIN users u ON p.created_by = u.id 
                    WHERE p.trainee_id = :trainee_id 
                    ORDER BY p.payment_date DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':trainee_id', $traineeId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting trainee payments: " . $e->getMessage());
            return [];
        }
    }

    /**
     * الحصول على سجل التواصل مع المتدرب
     */
    public function getTraineeCommunications($traineeId) {
        try {
            $sql = "SELECT c.*, u.full_name as created_by_name 
                    FROM communications c 
                    LEFT JOIN users u ON c.created_by = u.id 
                    WHERE c.trainee_id = :trainee_id 
                    ORDER BY c.contact_date DESC, c.created_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':trainee_id', $traineeId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting trainee communications: " . $e->getMessage());
            return [];
        }
    }

    /**
     * التحقق من وجود متدرب بالرقم الوطني
     */
    public function nationalIdExists($nationalId, $excludeId = null) {
        try {
            $sql = "SELECT COUNT(*) as count FROM trainees WHERE national_id = :national_id";
            $params = [':national_id' => $nationalId];
            
            if ($excludeId !== null) {
                $sql .= " AND id != :exclude_id";
                $params[':exclude_id'] = $excludeId;
            }
            
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return ($result['count'] > 0);
            
        } catch (PDOException $e) {
            error_log("Error checking national ID existence: " . $e->getMessage());
            return false;
        }
    }

    /**
     * الحصول على المتدربين حسب الدبلوم
     */
    public function getTraineesByDiploma($diplomaId, $limit = null) {
        try {
            $sql = "SELECT t.*, 
                           b.name as branch_name,
                           u.full_name as assigned_to_name
                    FROM trainees t
                    LEFT JOIN branches b ON t.branch_id = b.id
                    LEFT JOIN users u ON t.assigned_to = u.id
                    WHERE t.diploma_id = :diploma_id
                    ORDER BY t.full_name";
            
            if ($limit !== null) {
                $sql .= " LIMIT :limit";
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':diploma_id', $diplomaId, PDO::PARAM_INT);
            
            if ($limit !== null) {
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting trainees by diploma: " . $e->getMessage());
            return [];
        }
    }
}
?>
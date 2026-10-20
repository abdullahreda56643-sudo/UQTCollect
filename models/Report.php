<?php
class Report {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getBranchContactsByDay(int $branchId, string $startDate, string $endDate): array {
        $sql = "
            SELECT DATE(c.contact_date) as contact_day,
                   COUNT(*) as total_contacts,
                   COUNT(DISTINCT c.trainee_id) as unique_trainees
            FROM communications c
            INNER JOIN trainees t ON t.id = c.trainee_id
            WHERE t.branch_id = :branch_id
              AND DATE(c.contact_date) BETWEEN :start AND :end
            GROUP BY DATE(c.contact_date)
            ORDER BY contact_day ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        $stmt->bindValue(':start', $startDate);
        $stmt->bindValue(':end', $endDate);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getBranchContactsByEmployee(int $branchId, string $startDate, string $endDate): array {
        $sql = "
            SELECT 
                u.id as employee_id,
                u.full_name,
                u.role,
                
                -- إحصائيات الاتصالات
                (SELECT COUNT(*) FROM communications c 
                 WHERE c.created_by = u.id 
                 AND DATE(c.created_at) BETWEEN :start1 AND :end1) as total_contacts,
                
                (SELECT COUNT(*) FROM communications c 
                 WHERE c.created_by = u.id 
                 AND c.result = 'promised_payment'
                 AND DATE(c.created_at) BETWEEN :start2 AND :end2) as promised_payments,
                
                (SELECT COUNT(DISTINCT trainee_id) FROM communications c 
                 WHERE c.created_by = u.id 
                 AND DATE(c.created_at) BETWEEN :start3 AND :end3) as unique_trainees,
                
            -- إجمالي المبالغ المحصلة (عن طريق المتدربين الموكّلين للموظف)
            (SELECT COALESCE(SUM(p.amount), 0) FROM payments p
             JOIN trainees t ON t.id = p.trainee_id
             WHERE t.assigned_to = u.id
             AND DATE(p.payment_date) BETWEEN :start4 AND :end4) as total_collections,

            -- إجمالي المبالغ المحصلة التراكمي (بدون فلترة زمنية)
            (SELECT COALESCE(SUM(p.amount), 0) FROM payments p
             JOIN trainees t ON t.id = p.trainee_id
             WHERE t.assigned_to = u.id) as cumulative_collections,

            -- عدد عمليات التحصيل
            (SELECT COUNT(*) FROM payments p 
             JOIN trainees t ON t.id = p.trainee_id
             WHERE t.assigned_to = u.id
             AND DATE(p.payment_date) BETWEEN :start5 AND :end5) as total_payments_count,

            -- عدد المتدربين الذين قاموا بالسداد
            (SELECT COUNT(DISTINCT p3.trainee_id) FROM payments p3 
             JOIN trainees t ON t.id = p3.trainee_id
             WHERE t.assigned_to = u.id
             AND p3.amount > 0
             AND DATE(p3.payment_date) BETWEEN :start6 AND :end6) as paying_trainees,

            -- متوسط التحصيل لكل عملية (بحساب عمليات المتدربين الموكّلين)
            CASE 
              WHEN (SELECT COUNT(*) FROM payments p4 
                  JOIN trainees t ON t.id = p4.trainee_id
                  WHERE t.assigned_to = u.id
                  AND DATE(p4.payment_date) BETWEEN :start7 AND :end7) > 0 
              THEN (SELECT COALESCE(SUM(p5.amount), 0) FROM payments p5 
                  JOIN trainees t ON t.id = p5.trainee_id
                  WHERE t.assigned_to = u.id
                  AND DATE(p5.payment_date) BETWEEN :start8 AND :end8) / 
                 (SELECT COUNT(*) FROM payments p6 
                  JOIN trainees t ON t.id = p6.trainee_id
                  WHERE t.assigned_to = u.id
                  AND DATE(p6.payment_date) BETWEEN :start9 AND :end9)
              ELSE 0 
            END as avg_collection_per_payment,
                
                -- معدل النجاح
                CASE 
                    WHEN (SELECT COUNT(*) FROM communications c 
                          WHERE c.created_by = u.id 
                          AND DATE(c.created_at) BETWEEN :start10 AND :end10) > 0 
              THEN (SELECT COUNT(*) FROM payments p7 
                  JOIN trainees t ON t.id = p7.trainee_id
                  WHERE t.assigned_to = u.id
                  AND DATE(p7.payment_date) BETWEEN :start11 AND :end11) * 100.0 / 
                 (SELECT COUNT(*) FROM communications c 
                  WHERE c.created_by = u.id 
                  AND DATE(c.created_at) BETWEEN :start12 AND :end12)
                    ELSE 0 
                END as success_rate
                
            FROM users u
            WHERE u.role IN ('employee', 'supervisor')
            AND (u.branch_id = :branch_id OR :use_branch_id = 0)
            GROUP BY u.id, u.full_name, u.role
            ORDER BY total_collections DESC, total_contacts DESC";
            
        $stmt = $this->conn->prepare($sql);
        
        // ربط معلمات الفرع
        $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        $stmt->bindValue(':use_branch_id', $branchId > 0 ? 1 : 0, PDO::PARAM_INT);
        
        // ربط جميع معلمات التاريخ
        for ($i = 1; $i <= 12; $i++) {
            $stmt->bindValue(":start{$i}", $startDate);
            $stmt->bindValue(":end{$i}", $endDate);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCollectionsByPeriod(string $startDate, string $endDate, string $period = 'daily', ?int $branchId = null): array {
        $groupBy = match($period) {
            'weekly' => 'YEARWEEK(p.payment_date)',
            'monthly' => 'DATE_FORMAT(p.payment_date, "%Y-%m")',
            default => 'DATE(p.payment_date)'
        };
        
        $sql = "SELECT 
                    {$groupBy} as period,
                    SUM(p.amount) as total_amount,
                    COUNT(*) as tx_count
                FROM payments p
                INNER JOIN trainees t ON t.id = p.trainee_id
                WHERE p.payment_date BETWEEN :start AND :end";
                
        if ($branchId) {
            $sql .= " AND t.branch_id = :branch_id";
        }
        
        $sql .= " GROUP BY {$groupBy} ORDER BY period ASC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':start', $startDate);
        $stmt->bindValue(':end', $endDate);
        if ($branchId) {
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getCollectionsByDay(string $startDate, string $endDate, ?int $branchId = null): array {
        $whereBranch = $branchId ? 'AND t.branch_id = :branch_id' : '';
        $sql = "
            SELECT 
                   DATE(p.payment_date) as period_label,
                   SUM(p.amount) as total_amount,
                   COUNT(*) as tx_count,
                   CASE 
                       WHEN COUNT(*) > 0 THEN SUM(p.amount) / COUNT(*)
                       ELSE 0 
                   END as avg_amount
            FROM payments p
            INNER JOIN trainees t ON t.id = p.trainee_id
            WHERE DATE(p.payment_date) BETWEEN :start AND :end
            $whereBranch
            GROUP BY DATE(p.payment_date)
            ORDER BY period_label ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':start', $startDate);
        $stmt->bindValue(':end', $endDate);
        if ($branchId) {
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getFinancialSummary(string $startDate, string $endDate, ?int $branchId = null): array {
        $whereBranch = $branchId ? 'AND t.branch_id = :branch_id' : '';
        
        // إجمالي المدفوعات في الفترة (بدون initial_paid_amount)
        $sql = "SELECT COALESCE(SUM(p.amount), 0) as total_collected
                FROM payments p 
                INNER JOIN trainees t ON t.id = p.trainee_id
                WHERE DATE(p.payment_date) BETWEEN :start AND :end $whereBranch";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':start', $startDate);
        $stmt->bindValue(':end', $endDate);
        if ($branchId) { 
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT); 
        }
        $stmt->execute();
        $collected = (float)($stmt->fetch()['total_collected'] ?? 0);

        // المديونيات الحالية (حساب صحيح)
        $sql2 = "SELECT COALESCE(SUM(
                    t.total_amount - 
                    (COALESCE(t.initial_paid_amount, 0) + COALESCE(paid.total_paid, 0))
                ), 0) as total_debt 
                 FROM trainees t
                 LEFT JOIN (
                     SELECT trainee_id, SUM(amount) as total_paid
                     FROM payments
                     GROUP BY trainee_id
                 ) paid ON paid.trainee_id = t.id";
        
        if ($branchId) {
            $sql2 .= " WHERE t.branch_id = :branch_id";
        }

        $stmt2 = $this->conn->prepare($sql2);
        if ($branchId) { 
            $stmt2->bindValue(':branch_id', $branchId, PDO::PARAM_INT); 
        }
        $stmt2->execute();
        $debt = (float)($stmt2->fetch()['total_debt'] ?? 0);

        // إحصائيات الاتصالات الموسعة
        $successResults = "'paid','promise','refused','responded','interested','under_communication'";
        $sql3 = "SELECT 
                    COUNT(DISTINCT c.trainee_id) as contacted_trainees,
                    COUNT(*) as total_contacts,
                    ROUND(
                        COUNT(CASE WHEN c.result IN ($successResults) THEN 1 END) * 100.0 / 
                        NULLIF(COUNT(*), 0)
                    , 1) as success_rate
                FROM communications c 
                INNER JOIN trainees t ON t.id = c.trainee_id
                WHERE DATE(c.contact_date) BETWEEN :start AND :end";
                
        if ($branchId) {
            $sql3 .= " AND t.branch_id = :branch_id";
        }

        $stmt3 = $this->conn->prepare($sql3);
        $stmt3->bindValue(':start', $startDate);
        $stmt3->bindValue(':end', $endDate);
        if ($branchId) { 
            $stmt3->bindValue(':branch_id', $branchId, PDO::PARAM_INT); 
        }
        $stmt3->execute();
        $contactStats = $stmt3->fetch();

        return [
            'total_collected' => $collected,
            'total_debt' => $debt,
            'contacted_trainees' => (int)($contactStats['contacted_trainees'] ?? 0),
            'total_contacts' => (int)($contactStats['total_contacts'] ?? 0),
            'contact_success_rate' => ($contactStats['success_rate'] ?? 0) . '%'
        ];
    }

    public function getTraineeStatusDistribution(?int $branchId = null): array {
        $whereBranch = $branchId ? 'WHERE branch_id = :branch_id' : '';
        $sql = "SELECT status, COUNT(*) as cnt FROM trainees $whereBranch GROUP BY status";
        $stmt = $this->conn->prepare($sql);
        if ($branchId) { 
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT); 
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $dist = [];
        foreach ($rows as $r) { 
            $dist[$r['status']] = (int)$r['cnt']; 
        }
        return $dist;
    }

    public function getDelinquentTrainees(?int $branchId = null): array {
        $sql = "SELECT 
                    t.id,
                    t.full_name,
                    t.phone,
                    d.name AS diploma_name,
                    t.total_amount AS total_fees,
                    (COALESCE(t.initial_paid_amount, 0) + COALESCE(paid.total_paid, 0)) AS paid_amount,
                    (t.total_amount - (COALESCE(t.initial_paid_amount, 0) + COALESCE(paid.total_paid, 0))) AS debt_amount,
                    lc.last_contact AS last_contact,
                    c.result AS contact_result,
                    c.notes AS notes,
                    u.full_name AS last_contacted_by
                FROM trainees t
                LEFT JOIN (
                    SELECT trainee_id, SUM(amount) AS total_paid
                    FROM payments
                    GROUP BY trainee_id
                ) paid ON paid.trainee_id = t.id
                LEFT JOIN diplomas d ON d.id = t.diploma_id
                LEFT JOIN (
                    SELECT trainee_id, MAX(contact_date) AS last_contact
                    FROM communications
                    GROUP BY trainee_id
                ) lc ON lc.trainee_id = t.id
                LEFT JOIN communications c ON c.trainee_id = t.id AND c.contact_date = lc.last_contact
                LEFT JOIN users u ON u.id = c.created_by
                WHERE (t.total_amount - (COALESCE(t.initial_paid_amount, 0) + COALESCE(paid.total_paid, 0))) > 0";

        if ($branchId) {
            $sql .= " AND t.branch_id = :branch_id";
        }

        $sql .= " ORDER BY debt_amount DESC";

        $stmt = $this->conn->prepare($sql);
        if ($branchId) {
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getContactStatusStats(int $branchId, string $startDate, string $endDate): array {
        // If branchId is falsy (0), return stats across all branches (no branch filter)
        if (empty($branchId)) {
            $sql = "
                SELECT 
                    c.result as contact_result,
                    COUNT(*) as count,
                    (COUNT(*) * 100.0 / (
                        SELECT COUNT(*) 
                        FROM communications c2 
                        WHERE DATE(c2.created_at) BETWEEN :subquery_start AND :subquery_end
                    )) as percentage
                FROM communications c
                INNER JOIN trainees t ON t.id = c.trainee_id
                WHERE DATE(c.created_at) BETWEEN :start AND :end
                GROUP BY c.result
                ORDER BY count DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':start', $startDate);
            $stmt->bindValue(':end', $endDate);
            $stmt->bindValue(':subquery_start', $startDate);
            $stmt->bindValue(':subquery_end', $endDate);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $sql = "
            SELECT 
                c.result as contact_result,
                COUNT(*) as count,
                (COUNT(*) * 100.0 / (
                    SELECT COUNT(*) 
                    FROM communications c2 
                    INNER JOIN trainees t2 ON t2.id = c2.trainee_id 
                    WHERE t2.branch_id = :subquery_branch_id 
                    AND DATE(c2.created_at) BETWEEN :subquery_start AND :subquery_end
                )) as percentage
            FROM communications c
            INNER JOIN trainees t ON t.id = c.trainee_id
            WHERE t.branch_id = :branch_id
            AND DATE(c.created_at) BETWEEN :start AND :end
            GROUP BY c.result
            ORDER BY count DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        $stmt->bindValue(':subquery_branch_id', $branchId, PDO::PARAM_INT);
        $stmt->bindValue(':start', $startDate);
        $stmt->bindValue(':end', $endDate);
        $stmt->bindValue(':subquery_start', $startDate);
        $stmt->bindValue(':subquery_end', $endDate);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getEmployeePerformance(int $employeeId, string $startDate, string $endDate): array {
        $sql = "
            SELECT 
                u.full_name,
                COUNT(DISTINCT t.id) as total_trainees,
                (
                    SELECT COUNT(*)
                    FROM communications c2
                    JOIN trainees t2 ON c2.trainee_id = t2.id
                    WHERE c2.created_by = :eid2
                    AND DATE(c2.created_at) BETWEEN :s2 AND :e2
                ) as total_communications,
                (
                    SELECT COALESCE(SUM(p2.amount), 0)
                    FROM payments p2
                    JOIN trainees t3 ON p2.trainee_id = t3.id
                    WHERE t3.assigned_to = :eid3
                    AND DATE(p2.payment_date) BETWEEN :s3 AND :e3
                ) as total_collected
            FROM users u
            LEFT JOIN trainees t ON u.id = t.assigned_to
            WHERE u.id = :eid
            GROUP BY u.id, u.full_name";
            
        $stmt = $this->conn->prepare($sql);
        
        $stmt->bindValue(':eid', $employeeId, PDO::PARAM_INT);
        $stmt->bindValue(':eid2', $employeeId, PDO::PARAM_INT);
        $stmt->bindValue(':eid3', $employeeId, PDO::PARAM_INT);
        $stmt->bindValue(':s', $startDate);
        $stmt->bindValue(':s2', $startDate);
        $stmt->bindValue(':s3', $startDate);
        $stmt->bindValue(':e', $endDate);
        $stmt->bindValue(':e2', $endDate);
        $stmt->bindValue(':e3', $endDate);
        
        $stmt->execute();
        return $stmt->fetch() ?: [];
    }

    public function getTotalCollectedCorrect(string $startDate, string $endDate, ?int $branchId = null): float {
        $whereBranch = $branchId ? 'AND t.branch_id = :branch_id' : '';
        $sql = "SELECT COALESCE(SUM(p.amount), 0) as total_collected
                FROM payments p 
                INNER JOIN trainees t ON t.id = p.trainee_id
                WHERE DATE(p.payment_date) BETWEEN :start AND :end $whereBranch";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':start', $startDate);
        $stmt->bindValue(':end', $endDate);
        if ($branchId) { 
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT); 
        }
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total_collected'] ?? 0);
    }

    public function getTotalDebtCorrect(?int $branchId = null): float {
        $whereBranch = $branchId ? 'WHERE t.branch_id = :branch_id' : '';
        $sql = "SELECT COALESCE(SUM(
                    t.total_amount - 
                    (COALESCE(t.initial_paid_amount, 0) + COALESCE(paid.total_paid, 0))
                ), 0) as total_debt 
                 FROM trainees t
                 LEFT JOIN (
                     SELECT trainee_id, SUM(amount) as total_paid
                     FROM payments
                     GROUP BY trainee_id
                 ) paid ON paid.trainee_id = t.id
                 $whereBranch";
        
        $stmt = $this->conn->prepare($sql);
        if ($branchId) { 
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT); 
        }
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($result['total_debt'] ?? 0);
    }
}
?>
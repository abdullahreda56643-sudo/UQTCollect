/**
     * الحصول على إجمالي المدفوعات حسب الشهر
     * @return array مصفوفة تحتوي على الشهر والمجموع
     */
    public function getTotalByMonth() {
        $sql = "SELECT 
                    DATE_FORMAT(payment_date, '%Y-%m-01') as month,
                    SUM(amount) as total
                FROM payments 
                WHERE payment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
                GROUP BY month
                ORDER BY month DESC";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return [];
        }
    }
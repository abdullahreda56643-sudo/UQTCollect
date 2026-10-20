<?php
class Diploma extends BaseModel {
    public function __construct($db) {
        parent::__construct($db, 'diplomas', 'id', ['name','code','duration','cost','description','is_active']);
    }

    /**
     * Override list to include current_enrollments (count of trainees per diploma)
     * Supports filters used by the UI: search, cost_min, cost_max, duration_min, duration_max, status
     */
    public function list(array $filters = [], $orderBy = null, $limit = null, $offset = null) {
        try {
            $where = [];
            $params = [];

            // UI-level filters
            if (!empty($filters['search'])) {
                $where[] = "(d.name LIKE :search OR d.code LIKE :search)";
                $params[':search'] = '%' . $filters['search'] . '%';
            }
            if (isset($filters['cost_min']) && $filters['cost_min'] !== '') {
                $where[] = "d.cost >= :cost_min";
                $params[':cost_min'] = (float)$filters['cost_min'];
            }
            if (isset($filters['cost_max']) && $filters['cost_max'] !== '') {
                $where[] = "d.cost <= :cost_max";
                $params[':cost_max'] = (float)$filters['cost_max'];
            }
            if (isset($filters['duration_min']) && $filters['duration_min'] !== '') {
                $where[] = "d.duration >= :duration_min";
                $params[':duration_min'] = (int)$filters['duration_min'];
            }
            if (isset($filters['duration_max']) && $filters['duration_max'] !== '') {
                $where[] = "d.duration <= :duration_max";
                $params[':duration_max'] = (int)$filters['duration_max'];
            }
            if (!empty($filters['status'])) {
                if ($filters['status'] === 'active') {
                    $where[] = "d.is_active = 1";
                } elseif ($filters['status'] === 'inactive') {
                    $where[] = "d.is_active = 0";
                }
            }

            $sql = "SELECT d.*, COUNT(t.id) AS current_enrollments
                    FROM `{$this->table}` d
                    LEFT JOIN trainees t ON d.id = t.diploma_id";

            if (count($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }

            $sql .= " GROUP BY d.id";

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
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error in Diploma::list: " . $e->getMessage());
            return [];
        }
    }
}

?>
?>






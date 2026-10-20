<?php
class Setting extends BaseModel {
    public function __construct($db) {
        parent::__construct($db, 'settings', 'id', ['key','value','description']);
    }

    public function getByKey($key) {
        $stmt = $this->conn->prepare("SELECT * FROM `settings` WHERE `key` = :key LIMIT 1");
        $stmt->bindParam(':key', $key);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function set($key, $value, $description = null) {
        $existing = $this->getByKey($key);
        if ($existing) {
            return $this->update($existing['id'], ['value' => $value, 'description' => $description]);
        }
        return $this->create(['key' => $key, 'value' => $value, 'description' => $description]);
    }
}
?>






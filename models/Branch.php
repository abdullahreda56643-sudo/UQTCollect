<?php
class Branch extends BaseModel {
    public function __construct($db) {
        parent::__construct($db, 'branches', 'id', ['name','address','phone','manager_id','is_active']);
    }
}
?>






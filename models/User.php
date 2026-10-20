<?php
class User {
    private $conn;
    private $table_name = "users";
    
    public $id;
    public $username;
    public $password;
    public $email;
    public $full_name;
    public $phone;
    public $branch_id;
    public $role;
    public $max_trainees;
    public $is_active;
    public $last_login;
    public $login_attempts;
    public $lockout_until;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function list($conditions = [], $order_by = 'id ASC') {
        $query = "SELECT id, username, full_name, email, phone, branch_id, role, 
                        CASE role 
                            WHEN 'admin' THEN 'مدير'
                            WHEN 'supervisor' THEN 'مشرف'
                            WHEN 'employee' THEN 'موظف'
                            ELSE role 
                        END as role_name,
                        is_active 
                 FROM " . $this->table_name . " WHERE is_active = 1";
        
        // إضافة شروط البحث إضافية إذا وجدت
        if (!empty($conditions)) {
            foreach ($conditions as $key => $value) {
                if ($key !== 'is_active') { // تجنب إضافة شرط is_active مرتين
                    $query .= " AND $key = :$key";
                }
            }
        }
        
        // إضافة ترتيب النتائج
        if (!empty($order_by)) {
            $query .= " ORDER BY " . $order_by;
        }
        
        $stmt = $this->conn->prepare($query);
        
        // ربط قيم الشروط إذا وجدت
        if (!empty($conditions)) {
            foreach ($conditions as $key => $value) {
                if ($key !== 'is_active') {
                    $stmt->bindValue(":$key", $value);
                }
            }
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function create() {
        // التحقق من صلاحية الدور
        $allowedRoles = ['admin', 'supervisor', 'employee'];
        if (!in_array($this->role, $allowedRoles)) {
            throw new Exception('الدور غير صالح. يجب أن يكون واحداً من: admin, supervisor, employee');
        }

        $query = "INSERT INTO " . $this->table_name . "
                SET username=:username, password=:password, email=:email, 
                full_name=:full_name, phone=:phone, branch_id=:branch_id, 
                role=:role, max_trainees=:max_trainees";
        
        $stmt = $this->conn->prepare($query);
        
        // تنظيف البيانات
        $this->username = htmlspecialchars(strip_tags($this->username));
        $this->email = htmlspecialchars(strip_tags($this->email));
        $this->full_name = htmlspecialchars(strip_tags($this->full_name));
        $this->phone = htmlspecialchars(strip_tags($this->phone));
        
        // تشفير كلمة المرور
        $this->password = password_hash($this->password, PASSWORD_DEFAULT);
        
        // ربط القيم
        $stmt->bindParam(":username", $this->username);
        $stmt->bindParam(":password", $this->password);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":full_name", $this->full_name);
        $stmt->bindParam(":phone", $this->phone);
        $stmt->bindParam(":branch_id", $this->branch_id);
        $stmt->bindParam(":role", $this->role);
        $stmt->bindParam(":max_trainees", $this->max_trainees);
        
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    public function login($username, $password) {
        // التحقق من حالة القفل
        if ($this->isLockedOut($username)) {
            throw new Exception("تم قفل الحساب مؤقتاً لمدة 15 دقيقة بسبب تجاوز عدد محاولات تسجيل الدخول المسموح بها (5 محاولات). يرجى المحاولة لاحقاً.");
        }
        
        $query = "SELECT * FROM " . $this->table_name . " 
                 WHERE username = :username AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        $stmt->execute();
        
        if ($stmt->rowCount() == 1) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($password, $row['password'])) {
                // نجح تسجيل الدخول، إعادة تعيين العداد
                $this->resetLoginAttempts($username);
                
                // تحديث آخر تسجيل دخول
                $this->updateLastLogin($row['id']);
                
                return $row;
            } else {
                // فشل تسجيل الدخول، زيادة العداد
                $this->incrementLoginAttempts($username);
                throw new Exception("كلمة المرور غير صحيحة.");
            }
        } else {
            throw new Exception("اسم المستخدم غير موجود.");
        }
    }
    
    private function isLockedOut($username) {
        $query = "SELECT lockout_until FROM " . $this->table_name . " 
                 WHERE username = :username AND lockout_until > NOW()";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }
    
    private function incrementLoginAttempts($username) {
        $query = "UPDATE " . $this->table_name . " 
                 SET login_attempts = login_attempts + 1,
                 lockout_until = CASE 
                    WHEN login_attempts + 1 >= " . MAX_LOGIN_ATTEMPTS . " 
                    THEN DATE_ADD(NOW(), INTERVAL " . LOCKOUT_TIME . " SECOND) 
                    ELSE lockout_until 
                 END
                 WHERE username = :username";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        $stmt->execute();
    }
    
    private function resetLoginAttempts($username) {
        $query = "UPDATE " . $this->table_name . " 
                 SET login_attempts = 0, lockout_until = NULL 
                 WHERE username = :username";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        $stmt->execute();
    }
    
    private function updateLastLogin($user_id) {
        $query = "UPDATE " . $this->table_name . " 
                 SET last_login = NOW() 
                 WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $user_id);
        $stmt->execute();
    }
    
    public function hasPermission($required_permission) {
        // تنفيذ منطق الصلاحيات حسب الدور
        $permissions = [
            'admin' => ['view_all', 'edit_all', 'delete_all', 'import_data', 'manage_users'],
            'supervisor' => ['view_branch', 'edit_branch', 'assign_trainees', 'view_reports'],
            'employee' => ['view_assigned', 'add_contacts', 'add_payments']
        ];
        
        return in_array($required_permission, $permissions[$this->role]);
    }
    
    public function getAssignedTraineesCount() {
        $query = "SELECT COUNT(*) as count FROM trainees WHERE assigned_to = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $this->id);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['count'];
    }
}
?>
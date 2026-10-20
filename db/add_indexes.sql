-- إضافة فهارس لقاعدة البيانات للحقول المستخدمة بكثرة في عمليات البحث
-- تاريخ الإنشاء: 2024

-- فهارس لجدول المتدربين
CREATE INDEX idx_trainees_national_id ON trainees(national_id);
CREATE INDEX idx_trainees_full_name ON trainees(full_name);
CREATE INDEX idx_trainees_phone ON trainees(phone);
CREATE INDEX idx_trainees_email ON trainees(email);
CREATE INDEX idx_trainees_branch_id ON trainees(branch_id);
CREATE INDEX idx_trainees_diploma_id ON trainees(diploma_id);
CREATE INDEX idx_trainees_status ON trainees(status);
CREATE INDEX idx_trainees_assigned_to ON trainees(assigned_to);
CREATE INDEX idx_trainees_created_at ON trainees(created_at);

-- فهارس لجدول المدفوعات
CREATE INDEX idx_payments_trainee_id ON payments(trainee_id);
CREATE INDEX idx_payments_payment_date ON payments(payment_date);
CREATE INDEX idx_payments_payment_method ON payments(payment_method);
CREATE INDEX idx_payments_created_by ON payments(created_by);
CREATE INDEX idx_payments_created_at ON payments(created_at);

-- فهارس لجدول المستخدمين
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_branch_id ON users(branch_id);
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_is_active ON users(is_active);

-- فهارس مركبة للبحث المتكرر
CREATE INDEX idx_trainees_branch_status ON trainees(branch_id, status);
CREATE INDEX idx_trainees_assigned_status ON trainees(assigned_to, status);
CREATE INDEX idx_payments_date_method ON payments(payment_date, payment_method);
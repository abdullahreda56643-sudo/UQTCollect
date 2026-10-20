<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/User.php';

checkPermission('admin'); // فقط المدير يمكنه إضافة موظف جديد

$error = '';
$success = '';

// جلب قائمة الفروع
$branches = [];
try {
    $db = (new Database())->getConnection();
    $stmt = $db->query("SELECT id, name FROM branches ORDER BY id");
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'خطأ في جلب قائمة الفروع';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = (new Database())->getConnection();
        $user = new User($db);
        $user->username = trim($_POST['username'] ?? '');
        $user->password = trim($_POST['password'] ?? '');
        $user->email = trim($_POST['email'] ?? '');
        $user->full_name = trim($_POST['full_name'] ?? '');
        $user->phone = trim($_POST['phone'] ?? '');
        $user->branch_id = (int)($_POST['branch_id'] ?? 1);
        $user->role = $_POST['role'] ?? 'employee';
        $user->max_trainees = 50;

        // التحقق من البيانات
        if (strlen($user->password) < 8) {
            throw new Exception('يجب أن تكون كلمة المرور 8 أحرف على الأقل');
        }

        if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('البريد الإلكتروني غير صالح');
        }

        if ($user->create()) {
            $success = 'تم إضافة الموظف بنجاح';
            // تفريغ البيانات بعد الإضافة بنجاح
            $_POST = [];
        } else {
            $error = 'فشل في إضافة الموظف';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$page_title = 'إضافة موظف جديد';
include '../../includes/tw_layout_start.php';
?>

<div class="max-w-2xl mx-auto">
    <!-- بطاقة العودة -->
    <div class="mb-6">
        <a href="list.php" class="inline-flex items-center text-sm text-gray-600 hover:text-gray-800 transition-colors">
            <i class="fas fa-arrow-right ml-2"></i>
            العودة إلى قائمة المستخدمين
        </a>
    </div>

    <!-- بطاقة النموذج -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg overflow-hidden">
        <!-- رأس البطاقة -->
        <div class="p-6 bg-gradient-to-l from-blue-50 to-indigo-50 dark:from-gray-700 dark:to-gray-800 border-b dark:border-gray-700">
            <div class="flex items-center space-x-4 space-x-reverse">
                <div class="w-12 h-12 rounded-xl bg-blue-600 bg-opacity-10 flex items-center justify-center">
                    <i class="fas fa-user-plus text-blue-600 text-xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-1">إضافة موظف جديد</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">قم بإدخال بيانات الموظف الجديد</p>
                </div>
            </div>
        </div>

        <!-- محتوى البطاقة -->
        <div class="p-6">
            <?php if (!empty($error)): ?>
                <div class="mb-6 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800/50 rounded-lg p-4 flex items-center">
                    <i class="fas fa-exclamation-circle ml-3 text-lg"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php elseif (!empty($success)): ?>
                <div class="mb-6 bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-400 border border-green-200 dark:border-green-800/50 rounded-lg p-4 flex items-center">
                    <i class="fas fa-check-circle ml-3 text-lg"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- البيانات الشخصية -->
                <div class="md:col-span-2">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                        <i class="fas fa-user-circle ml-2 text-gray-400"></i>
                        البيانات الشخصية
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">الاسم الكامل</label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
                                    <i class="fas fa-user"></i>
                                </div>
                                <input name="full_name"
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                                       class="w-full pl-4 pr-10 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:focus:ring-blue-800 transition-colors"
                                       placeholder="محمد أحمد"
                                       required>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">اسم المستخدم</label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
                                    <i class="fas fa-at"></i>
                                </div>
                                <input name="username"
                                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                       class="w-full pl-4 pr-10 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:focus:ring-blue-800 transition-colors"
                                       placeholder="mohammed.ahmed"
                                       required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- معلومات الاتصال -->
                <div class="md:col-span-2">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                        <i class="fas fa-address-card ml-2 text-gray-400"></i>
                        معلومات الاتصال
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">البريد الإلكتروني</label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <input name="email"
                                       type="email"
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       class="w-full pl-4 pr-10 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:focus:ring-blue-800 transition-colors"
                                       placeholder="example@company.com"
                                       required>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">سيتم استخدام هذا البريد للتواصل وإعادة تعيين كلمة المرور</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">رقم الهاتف</label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
                                    <i class="fas fa-phone"></i>
                                </div>
                                <input name="phone"
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                       class="w-full pl-4 pr-10 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:focus:ring-blue-800 transition-colors"
                                       placeholder="05xxxxxxxx"
                                       pattern="[0-9]{10}">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- معلومات الحساب -->
                <div class="md:col-span-2">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                        <i class="fas fa-shield-alt ml-2 text-gray-400"></i>
                        معلومات الحساب
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">كلمة المرور</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <input name="password"
                                       type="password"
                                       id="password"
                                       class="w-full pl-4 pr-10 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:focus:ring-blue-800 transition-colors"
                                       placeholder="••••••••"
                                       required
                                       minlength="8">
                                <button type="button"
                                        class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                        id="toggle-password">
                                    <i class="fas fa-eye" id="password-icon"></i>
                                </button>
                            </div>
                            <div class="mt-1 flex items-center justify-between">
                                <p class="text-xs text-gray-500">يجب أن تكون كلمة المرور 8 أحرف على الأقل</p>
                                <div class="flex items-center space-x-2 text-xs text-gray-500" id="password-strength">
                                    <div class="h-1 w-8 bg-gray-200 rounded overflow-hidden">
                                        <div class="h-full bg-gray-300 transition-all duration-300" id="strength-bar" style="width: 0%"></div>
                                    </div>
                                    <span id="strength-text">ضعيفة</span>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">الدور الوظيفي</label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
                                    <i class="fas fa-user-tag"></i>
                                </div>
                                <select name="role"
                                        class="w-full pl-4 pr-10 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:focus:ring-blue-800 transition-colors">
                                    <option value="employee" <?php echo (isset($_POST['role']) && $_POST['role'] === 'employee') ? 'selected' : ''; ?>>موظف</option>
                                    <option value="supervisor" <?php echo (isset($_POST['role']) && $_POST['role'] === 'supervisor') ? 'selected' : ''; ?>>مشرف</option>
                                    <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'selected' : ''; ?>>مدير</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">الفرع</label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
                                    <i class="fas fa-building"></i>
                                </div>
                                <select name="branch_id"
                                        class="w-full pl-4 pr-10 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:border-blue-500 dark:focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:focus:ring-blue-800 transition-colors"
                                        required>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['id']; ?>"
                                                <?php echo (isset($_POST['branch_id']) && (int)$_POST['branch_id'] === (int)$branch['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($branch['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- أزرار التحكم -->
                <div class="md:col-span-2 flex items-center justify-end space-x-4 space-x-reverse mt-6 pt-6 border-t dark:border-gray-700">
                    <a href="list.php"
                       class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors inline-flex items-center">
                        <i class="fas fa-times ml-2"></i>
                        إلغاء
                    </a>
                    <button type="submit"
                            class="px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-200 dark:focus:ring-blue-900 transition-colors inline-flex items-center">
                        <i class="fas fa-user-plus ml-2"></i>
                        إضافة الموظف
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Toggle password visibility
    document.addEventListener('DOMContentLoaded', function() {
        const togglePassword = document.getElementById('toggle-password');
        const password = document.getElementById('password');
        const passwordIcon = document.getElementById('password-icon');

        togglePassword.addEventListener('click', function() {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);

            // Toggle eye icon
            passwordIcon.classList.toggle('fa-eye');
            passwordIcon.classList.toggle('fa-eye-slash');
        });

        // Password strength checker
        password.addEventListener('input', function() {
            const strength = checkPasswordStrength(this.value);
            const strengthBar = document.getElementById('strength-bar');
            const strengthText = document.getElementById('strength-text');

            strengthBar.style.width = strength.width + '%';
            strengthBar.className = 'h-full transition-all duration-300 ' + strength.color;
            strengthText.textContent = strength.text;
            strengthText.className = 'text-xs ' + strength.textColor;
        });

        function checkPasswordStrength(password) {
            let strength = 0;

            // Check length
            if (password.length >= 8) strength += 25;
            if (password.length >= 12) strength += 25;

            // Check for numbers
            if (/[0-9]/.test(password)) strength += 25;

            // Check for special characters
            if (/[^A-Za-z0-9]/.test(password)) strength += 25;

            if (strength <= 25) {
                return { width: '25%', color: 'bg-red-500', text: 'ضعيفة', textColor: 'text-red-500' };
            } else if (strength <= 50) {
                return { width: '50%', color: 'bg-yellow-500', text: 'متوسطة', textColor: 'text-yellow-500' };
            } else if (strength <= 75) {
                return { width: '75%', color: 'bg-blue-500', text: 'جيدة', textColor: 'text-blue-500' };
            } else {
                return { width: '100%', color: 'bg-green-500', text: 'قوية', textColor: 'text-green-500' };
            }
        }
    });
</script>

<?php include '../../includes/tw_layout_end.php'; ?>

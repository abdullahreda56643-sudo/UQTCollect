<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/User.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}



if (isLoggedIn()) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/index.php');
    exit;
}

$error = '';
$success = '';
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
        $user->role = 'employee';
        $user->max_trainees = 50;
        if ($user->create()) {
            $success = 'تم إنشاء الحساب بنجاح، يمكنك تسجيل الدخول الآن';
            } else {
                $error = 'فشل إنشاء الحساب';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }


?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء حساب - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { 
            darkMode: 'class',
            theme: { 
                extend: { 
                    fontFamily: { 
                        'arabic': ['Cairo', 'Tahoma', 'sans-serif'] 
                    } 
                } 
            } 
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e7ec 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        
        .register-card {
            background: white;
            border-radius: 14px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            width: 100%;
            max-width: 420px;
        }
        
        .form-input {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .form-input:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }
        
        .btn-primary {
            background: linear-gradient(to right, #4f46e5, #6366f1);
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(79, 70, 229, 0.2);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 10px rgba(79, 70, 229, 0.25);
        }
    </style>
</head>
<body>
    <div class="register-card p-6">
        <!-- الشعار -->
        <div class="flex items-center justify-center mb-4">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-r from-blue-50 to-indigo-50 flex items-center justify-center text-blue-600 text-xl">
                <i class="fas fa-user-plus"></i>
            </div>
        </div>
        
        <!-- العنوان والترحيب -->
        <div class="text-center mb-4">
            <h1 class="text-xl font-bold text-gray-800 mb-1">إنشاء حساب جديد</h1>
            <p class="text-xs text-gray-600">املأ البيانات التالية لإنشاء حسابك</p>
        </div>

        <!-- رسائل التنبيه -->
        <?php if (!empty($error)): ?>
        <div class="mb-3 bg-red-50 text-red-700 border border-red-200 rounded-lg p-2 text-sm flex items-center">
            <i class="fas fa-exclamation-circle ml-1"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php elseif (!empty($success)): ?>
        <div class="mb-3 bg-green-50 text-green-700 border border-green-200 rounded-lg p-2 text-sm flex items-center">
            <i class="fas fa-check-circle ml-1"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
        <?php endif; ?>

      
        <form method="post" autocomplete="off" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">الاسم الكامل</label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none text-gray-400">
                        <i class="fas fa-user text-xs"></i>
                    </div>
                    <input name="full_name" class="w-full pl-3 pr-8 py-2.5 form-input outline-none" placeholder="الاسم الكامل" required>
                </div>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">اسم المستخدم</label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none text-gray-400">
                        <i class="fas fa-at text-xs"></i>
                    </div>
                    <input name="username" class="w-full pl-3 pr-8 py-2.5 form-input outline-none" placeholder="اسم المستخدم" required>
                </div>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">البريد الإلكتروني</label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none text-gray-400">
                        <i class="fas fa-envelope text-xs"></i>
                    </div>
                    <input name="email" type="email" class="w-full pl-3 pr-8 py-2.5 form-input outline-none" placeholder="example@email.com" required>
                </div>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">كلمة المرور</label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none text-gray-400">
                        <i class="fas fa-lock text-xs"></i>
                    </div>
                    <input name="password" type="password" class="w-full pl-3 pr-8 py-2.5 form-input outline-none" placeholder="كلمة المرور" required>
                </div>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">الهاتف</label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none text-gray-400">
                        <i class="fas fa-phone text-xs"></i>
                    </div>
                    <input name="phone" class="w-full pl-3 pr-8 py-2.5 form-input outline-none" placeholder="رقم الهاتف">
                </div>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">ID الفرع</label>
                <div class="relative">
                    <div class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none text-gray-400">
                        <i class="fas fa-code-branch text-xs"></i>
                    </div>
                    <input name="branch_id" type="number" value="1" class="w-full pl-3 pr-8 py-2.5 form-input outline-none">
                </div>
            </div>
            
            <div class="md:col-span-2 mt-1">
                <button class="w-full py-2.5 btn-primary text-white rounded-lg font-medium text-sm" type="submit">
                    إنشاء الحساب
                </button>
            </div>
            
            <div class="md:col-span-2 text-center text-xs text-gray-600 pt-3 border-t border-gray-100">
                لديك حساب بالفعل؟ 
                <a href="<?php echo rtrim(SITE_URL,'/'); ?>/modules/auth/login.php" class="text-blue-600 hover:text-blue-800 font-medium mr-1">
                    تسجيل الدخول
                </a>
            </div>
        </form>
    </div>
</body>
</html>
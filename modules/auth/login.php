<?php
require_once '../../config.php';
require_once '../../db/connection.php';
require_once '../../includes/auth.php';
require_once '../../models/User.php';

// بدء الجلسة إذا لم تكن بدأت
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// تحقق من إعدادات Google OAuth
$googleReady = true;
if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET) || empty(GOOGLE_REDIRECT_URI)) {
    $googleReady = false;
}

// استقبال رسالة الخطأ من الجلسة (من google_callback.php)
$error = '';
if (!empty($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

if (isLoggedIn()) {
    header('Location: ' . rtrim(SITE_URL, '/') . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    try {
        $db = (new Database())->getConnection();
        $userModel = new User($db);
        $userRow = $userModel->login($username, $password);
        $_SESSION['user_id'] = $userRow['id'];
        $_SESSION['username'] = $userRow['username'];
        $_SESSION['full_name'] = $userRow['full_name'];
        $_SESSION['role'] = $userRow['role'];
        $_SESSION['last_activity'] = time(); // تعيين وقت آخر نشاط
        
        // إضافة إشعار تسجيل الدخول
        require_once '../../includes/notifications.php';
        $deviceInfo = $_SERVER['HTTP_USER_AGENT'];
        try {
            notifyNewLogin($db, $userRow['id'], $deviceInfo);
        } catch (Exception $e) {
            error_log("Error creating login notification: " . $e->getMessage());
        }

        // تسجيل الدخول في سجل النشاط
        try {
            require_once '../../includes/auth.php';
            logActivity($db, 'login', 'user', $userRow['id'], null, ['ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null]);
        } catch (Exception $e) {
            error_log('Failed to log user login: ' . $e->getMessage());
        }

        // توجيه المستخدم حسب دوره
        $redirectPath = '';
        switch ($_SESSION['role']) {
            case 'admin':
                $redirectPath = '/views/dashboard/admin.php';
                break;
            case 'supervisor':
                $redirectPath = '/views/dashboard/supervisor.php';
                break;
            case 'employee':
                $redirectPath = '/views/dashboard/employee.php';
                break;
            default:
                $redirectPath = '/views/index.php';
        }
        
        header('Location: ' . rtrim(SITE_URL, '/') . $redirectPath);
        exit;
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
    <title>تسجيل الدخول - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
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
    <!-- dark-mode removed for this page -->
    <style>
        :root{--glass: rgba(255,255,255,0.75);--muted:#6b7280;--accent-from:#4f46e5;--accent-to:#6366f1}
        body{font-family:'Cairo',sans-serif;background:linear-gradient(135deg,#f5f7fa 0%,#e9eef6 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}

        /* layout grid: form on left, visuals on right */
        .auth-layout{width:100%;max-width:1100px;display:grid;grid-template-columns:1fr 520px;border-radius:16px;overflow:hidden;align-items:stretch}

        /* Left: plain form (no card) */
        .auth-form-side{padding:48px 42px;display:flex;flex-direction:column;justify-content:center;background:transparent}
        .form-panel{max-width:420px}
    .brand{display:flex;align-items:center;gap:12px;margin-bottom:18px}
    .brand .logo-wrap{width:52px;height:52px;border-radius:12px;background:linear-gradient(90deg,rgba(79,70,229,0.1),rgba(99,102,241,0.08));display:flex;align-items:center;justify-content:center;color:var(--accent-from);font-size:20px}

    /* adjust logo-wrap icon size */
    .brand .logo-wrap i{font-size:20px}

    .glass-input{background:var(--glass);border:1px solid rgba(15,23,42,0.04);padding:12px 14px;border-radius:10px;width:100%;outline:none;transition:box-shadow .18s,transform .18s}
    .glass-input:focus{box-shadow:0 6px 18px rgba(99,102,241,0.12);transform:translateY(-2px)}

    /* accessibility & input helpers */
    .input-with-icon{position:relative}
    .input-with-icon .action-btn{position:absolute;left:10px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:var(--muted);cursor:pointer;padding:6px;border-radius:6px}
    .input-with-icon .action-btn:focus{outline:2px solid rgba(79,70,229,0.18)}
    .focus-ring:focus{outline:3px solid rgba(79,70,229,0.18);outline-offset:2px}

    /* spinner for submit */
    .spinner{width:18px;height:18px;border:2px solid rgba(255,255,255,0.25);border-top-color:#ffffff;border-radius:999px;animation:spin .9s linear infinite;display:inline-block;vertical-align:middle}
    @keyframes spin{to{transform:rotate(360deg)}}

    .form-error{background:#fff3f2;color:#b91c1c;border-radius:8px;padding:8px 10px;margin-bottom:10px;border:1px solid rgba(185,28,28,0.08);display:none}

    .social-row{display:flex;gap:8px;margin-top:12px}
    .social-btn{flex:1;padding:8px;border-radius:10px;border:1px solid rgba(15,23,42,0.06);background:#fff;display:inline-flex;align-items:center;justify-content:center;gap:8px;cursor:pointer}
        .muted {color:var(--muted)}

        .btn-primary {background:linear-gradient(90deg,var(--accent-from),var(--accent-to));color:#fff;padding:10px 14px;border-radius:10px;box-shadow:0 8px 20px rgba(79,70,229,0.12);width:100%}

        /* Right: advanced visual area with layered background, texture and sheen */
        .auth-visual-side{position:relative;display:flex;align-items:center;justify-content:center;padding:28px 20px;overflow:hidden;background:linear-gradient(180deg,#f6f9ff 0%, #eef4ff 45%, #f7fbff 100%)}

        /* layered decorative background: large radial glows and subtle diagonal grain */
        .auth-visual-side::before{content:'';position:absolute;inset:0;z-index:0;pointer-events:none;
            background-image:
                radial-gradient(600px 300px at 10% 20%, rgba(79,70,229,0.06), transparent 20%),
                radial-gradient(420px 220px at 86% 78%, rgba(99,102,241,0.04), transparent 22%),
                repeating-linear-gradient(135deg, rgba(255,255,255,0.02) 0 2px, rgba(2,6,23,0.00) 2px 6px);
            background-blend-mode:screen, screen, overlay;opacity:0.98;filter:blur(0.4px);transform:translateZ(0)}

        /* animated sheen: slow subtle movement to add life */
        .auth-visual-side::after{content:'';position:absolute;inset:-20% -30%;z-index:1;pointer-events:none;mix-blend-mode:overlay;opacity:0.9;
            background:linear-gradient(90deg, rgba(255,255,255,0.0) 0%, rgba(255,255,255,0.18) 30%, rgba(255,255,255,0.06) 45%, rgba(255,255,255,0.0) 100%);
            transform:translateX(-6%);animation:visual-sheen 14s linear infinite}

        @keyframes visual-sheen{0%{transform:translateX(-6%)}50%{transform:translateX(6%)}100%{transform:translateX(-6%)}}

        /* ensure card/grid content sits above background layers */
        .auth-visual-side > .visual-grid{position:relative;z-index:3}

        /* soft vignette to focus attention on cards */
        .auth-visual-side .vignette{position:absolute;inset:0;z-index:2;pointer-events:none;background:radial-gradient(60% 60% at 50% 40%, rgba(0,0,0,0.00), rgba(2,6,23,0.03) 70%)}

        .clip-one{position:absolute;right:18px;top:28px;width:66%;max-width:420px;height:auto;box-shadow:0 18px 50px rgba(15,23,42,0.12);transform:rotate(-6deg);overflow:hidden}
        .clip-one img{width:100%;height:auto;display:block;object-fit:cover;clip-path:polygon(5% 0,100% 0,92% 100%,0 88%)}
        
   
    .clip-three{position:absolute;right:6%;top:8%;width:48%;max-width:380px;height:auto;z-index:5;transform:rotate(-2deg);overflow:hidden;border-radius:14px}
    .clip-three img{width:100%;height:auto;display:block;object-fit:cover;clip-path:polygon(8% 4%,96% 2%,92% 94%,6% 86%)}

        
        .clip-two{position:absolute;right:-10px;bottom:18px;width:52%;max-width:360px;height:auto;filter:brightness(.98) saturate(1.05);transform:rotate(6deg);box-shadow:0 12px 34px rgba(15,23,42,0.08);overflow:hidden}
        .clip-two img{width:100%;height:auto;display:block;object-fit:cover;clip-path:ellipse(65% 50% at 80% 40%)}
        
    /* advanced borders / separators between images */
    .clip-one::before, .clip-two::before, .clip-three::before{content:'';position:absolute;inset:0;border-radius:inherit;pointer-events:none;z-index:8}
    /* subtle inner frame */
    .clip-one::before{box-shadow:inset 0 1px 0 rgba(15, 22, 107, 0.5), inset 0 -10px 30px rgba(15, 38, 141, 0.53);border-radius:8px}
    .clip-two::before{box-shadow:inset 0 1px 0 rgba(255,255,255,0.45), inset -6px -12px 40px rgba(10, 25, 92, 0.58);border-radius:10px}
    .clip-three::before{box-shadow:inset 0 1px 0 rgba(255,255,255,0.48), inset 0 -8px 28px rgba(2,6,23,0.05);border-radius:10px}
        
    /* decorative beveled borders between layers */
    .clip-one::after, .clip-two::after, .clip-three::after{content:'';position:absolute;inset:auto auto 4px 4px;width:calc(100% - 8px);height:calc(100% - 8px);border-radius:6px;pointer-events:none;background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(2,6,23,0.03));transform:translate(6px,6px);z-index:2;filter:blur(.6px)}

    /* thin separators (hairlines) between layers */
    .clip-two::marker, .clip-three::marker { display:none }
    .clip-one .separator, .clip-two .separator, .clip-three .separator { position:absolute;pointer-events:none }
    .clip-three::after{content:'';position:absolute;right:-8px;top:8px;height:calc(100% - 16px);width:2px;background:linear-gradient(180deg, rgba(255,255,255,0.6), rgba(2,6,23,0.06));transform:rotate(6deg);z-index:7;border-radius:2px;opacity:.9}
    .clip-one::after{right:10px;top:12px}
    .clip-two::after{right:-6px;bottom:10px}
        
    /* layer ordering to create depth */
    .clip-two{z-index:2}
    .clip-three{z-index:3}
    .clip-one{z-index:4}
        
    /* mid-line partial separators (not full borders) */
    .auth-visual-side .divider{position:absolute;width:2px;height:48%;top:26%;border-radius:2px;pointer-events:none;z-index:6;background:linear-gradient(180deg, rgba(255,255,255,0.9), rgba(255,255,255,0.6) 30%, rgba(2,6,23,0.06));box-shadow:0 6px 18px rgba(2,6,23,0.06)}
    .auth-visual-side .divider.d1{right:36%;transform:rotate(6deg)}
    .auth-visual-side .divider.d2{right:20%;transform:rotate(-6deg)}
    @media (max-width:980px){.auth-visual-side .divider{display:none}}

    /* svg container responsiveness */
    .auth-svg{width:100%;max-width:520px;height:100%;display:block}
    .auth-svg svg{width:100%;height:100%;display:block}

    /* hide earlier clip-based elements on wider screens (kept as fallback) */
    .clip-one,.clip-two,.clip-three{display:none}

    /* New professional three-card visual layout */
    .visual-grid{width:100%;max-width:520px;display:grid;grid-template-columns:1fr; animation: heavyFloat 3s infinite ease-in-out; gap:18px;padding:6px}
@keyframes heavyFloat {
  0%, 100% {
    transform: translateY(0);
  }
  25% {
    transform: translateY(-15px);
  }
  50% {
    transform: translateY(0);
  }
  75% {
    transform: translateY(10px);
  }
}


    @media (min-width:780px){.visual-grid{grid-template-columns:58% 40%;gap:18px}}
    .visual-left{border-radius:18px;overflow:visible;position:relative;background:#fff;box-shadow:0 20px 50px rgba(12,18,39,0.08)}
    .visual-left img{width:100%;height:100%;display:block;object-fit:cover}
    .visual-right{display:flex;flex-direction:column;gap:18px}
    .visual-card{background:#fff;border-radius:14px;overflow:visible;position:relative;box-shadow:0 12px 30px rgba(12,18,39,0.06);min-height:140px}
    .visual-card.small{min-height:110px}
    .visual-card img{width:100%;height:100%;display:block;object-fit:cover}

    /* badges and floating widgets (compact, edge-aligned) */
    .badge{position:absolute;right:8px;top:8px;color:#fff;padding:6px 8px;border-radius:10px;font-weight:700;letter-spacing:0.1px;display:inline-flex;align-items:center;gap:8px;z-index:30;
        background:linear-gradient(180deg,#3b2bf7 0%,#7c6cff 100%);box-shadow:0 8px 18px rgba(30,22,80,0.08), 0 4px 8px rgba(2,6,23,0.04);border:1px solid rgba(255,255,255,0.06);transform:translateZ(0);font-size:0.78rem}
    .badge::before{content:'';position:absolute;left:6px;top:50%;transform:translateY(-50%);width:26px;height:26px;border-radius:8px;background:linear-gradient(180deg,rgba(255,255,255,0.12),rgba(255,255,255,0.04));box-shadow:inset 0 1px 0 rgba(255,255,255,0.12);filter:blur(.2px);pointer-events:none}
    .badge .icon{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:6px;background:rgba(255,255,255,0.12);margin-left:6px;z-index:2;font-size:12px}
    .badge span{display:inline-block;vertical-align:middle;z-index:2;text-align:left;line-height:1}
    /* subtle sparkle */
    .badge::after{content:'';position:absolute;right:6px;top:6px;width:6px;height:6px;border-radius:2px;background:radial-gradient(circle at 35% 30%, rgba(255,255,255,0.95), rgba(255,255,255,0.12));opacity:0.85;transform:translateZ(0)}

    /* modifiers: edge-aligned small badges */
    /* place badges on the very edge of their image cards (slightly outside) */
    .badge--big{padding:6px 8px;border-radius:10px;right:-10px;top:-10px;transform:none;font-size:0.82rem;border:2px solid rgba(255,255,255,0.95)}
    .badge--alt{background:linear-gradient(90deg,#1f3fb6,#6b8cff);right:-10px;bottom:-10px;top:auto;transform:none;font-size:0.82rem;border:2px solid rgba(255,255,255,0.95)}
    .badge--alt::before{width:22px;height:22px;border-radius:6px}
    .card-pill{position:absolute;left:12px;bottom:12px;background:#111827;color:#fff;padding:6px 10px;border-radius:999px;font-weight:600}

    /* responsive tweaks */
    @media (max-width:980px){.visual-grid{grid-template-columns:1fr}.visual-right{flex-direction:row;gap:12px}.visual-card{min-height:120px}.visual-card.small{min-height:100px}}

        /* subtle decorative gradient circle */
        .decor-circle{position:absolute;left:8%;top:10%;width:180px;height:180px;border-radius:999px;background:radial-gradient(circle at 30% 30%, rgba(99,102,241,0.12), rgba(99,102,241,0.03));filter:blur(18px);z-index:0}

        /* responsive */
        @media (max-width:980px){.auth-layout{grid-template-columns:1fr;gap:18px}.auth-visual-side{order:2;padding:28px 12px;height:320px}.auth-form-side{order:1;padding:28px}.clip-one{position:absolute;right:6%;top:10%;width:64%}.clip-two{position:absolute;right:6%;bottom:6%;width:46%}.decor-circle{left:6%;top:6%;width:120px;height:120px}}
    </style>
    <style>
        /* top-left fixed brand */
    .top-brand{position:fixed;right:18px;top:14px;display:flex;align-items:center;gap:.6rem;padding:.3rem .6rem;border-radius:10px; z-index:60}
        .top-brand img{height:100px}
            .top-brand .site-name{font-weight:700;font-size:1.2rem;background-color: #5049ddde; padding: 5px; border-radius: 5px; color:#fff}
    @media (max-width:600px){.top-brand{right:10px;top:8px;padding:.2rem .4rem}.top-brand .site-name{display:none}}
   
    </style>
  
</head>
<body>
    <a class="top-brand" href="<?php echo rtrim(SITE_URL, '/'); ?>/">
        <img src="../../public/assets/images/logo.png" alt="logo">
      
        <span class="site-name">المعهد التقني للتدريب</span>
    </a>
    <div class="auth-layout">
        <div class="auth-form-side">
            <div class="form-panel">
                <div class="brand">
                    <div class="logo-wrap" aria-hidden="true">
                        <!-- trainee icon -->
                        <i class="fas fa-user-graduate" title="متدرب" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:1.05rem"><?php echo htmlspecialchars(SITE_NAME); ?></div>
                        <div class="muted" style="font-size:.9rem">أدخل بيانات حسابك للوصول إلى لوحة التحكم</div>
                    </div>

                   
                </div>

                <?php if (!empty($error)): ?>
                    <div class="mb-3 bg-red-50 text-red-700 border border-red-200 rounded-lg p-3 text-sm flex items-center" role="alert">
                        <i class="fas fa-exclamation-circle ml-2"></i>
                        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" autocomplete="on" class="space-y-3" id="loginForm">
                    <div>
                        <label for="username" class="block text-sm text-gray-700 mb-1">اسم المستخدم</label>
                        <div class="input-with-icon">
                            <input id="username" name="username" class="glass-input focus-ring" placeholder="اسم المستخدم أو البريد الإلكتروني" required aria-required="true">
                            <button type="button" class="action-btn" title="Clear" onclick="document.getElementById('username').value='';document.getElementById('username').focus();"><i class="fas fa-times"></i></button>
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-sm text-gray-700 mb-1">كلمة المرور</label>
                        <div class="input-with-icon">
                            <input id="password" name="password" type="password" class="glass-input focus-ring" placeholder="كلمة المرور" required aria-required="true">
                            <button id="pwdToggle" type="button" class="action-btn" title="إظهار/إخفاء" aria-pressed="false"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>

                 

                    <div>
                        <button id="submitBtn" type="submit" class="btn-primary" aria-live="polite">
                            <span id="submitText">تسجيل الدخول</span>
                            <span id="submitSpinner" style="display:none" class="ml-2"><span class="spinner" aria-hidden="true"></span></span>
                        </button>
                    </div>

                    <div id="formError" class="form-error" role="alert"></div>

                   
                </form>

                <script>
                    // password toggle
                    (function(){
                        var btn = document.getElementById('pwdToggle');
                        var pwd = document.getElementById('password');
                        btn && btn.addEventListener('click', function(){
                            var is = pwd.type === 'password';
                            pwd.type = is ? 'text' : 'password';
                            btn.setAttribute('aria-pressed', is ? 'true' : 'false');
                            btn.innerHTML = is ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
                        });

                        // simple client-side validation + spinner
                        var form = document.getElementById('loginForm');
                        var submitBtn = document.getElementById('submitBtn');
                        var submitText = document.getElementById('submitText');
                        var submitSpinner = document.getElementById('submitSpinner');
                        var formError = document.getElementById('formError');

                        form && form.addEventListener('submit', function(e){
                            formError.style.display = 'none';
                            var u = document.getElementById('username').value.trim();
                            var p = document.getElementById('password').value;
                            if(!u || !p){
                                e.preventDefault();
                                formError.textContent = 'الرجاء ملء اسم المستخدم وكلمة المرور.';
                                formError.style.display = 'block';
                                return false;
                            }
                            // show spinner
                            submitText.style.display = 'none';
                            submitSpinner.style.display = '';
                            submitBtn.disabled = true;
                        });
                    })();
                </script>

              

                <div style="margin-top:18px;font-size:.9rem;color:var(--muted)"><a href="#" class="text-blue-600 hover:underline"> © 2025 نظام إدارة المتدربين والتحصيل المالي . جميع الحقوق محفوظة.</a></div>
            </div>
        </div>

        <div class="auth-visual-side">
            <div class="decor-circle" aria-hidden="true"></div>

            <div class="visual-grid" aria-hidden="true">
                <div class="visual-left">
                    <img src="https://uqt.com.sa/ContentFront/img/home-6/hero/menAndWomen.png" alt="hero">
                    <div class="badge badge--big"><span class="icon"><i class="fas fa-graduation-cap"></i></span> <span>+3K<br> خريج وخريجة </span></div>
                </div>

                <div class="visual-right">
                    <div class="visual-card">
                        <img src="https://uqt.com.sa/ContentFront/img/home-6/hero/graduation-student.png" alt="certificate">
                                 <div class="badge badge--big"><span class="icon"><i class="fas fa-people-arrows"></i></span> <span><br> للرجال و النساء</span></div>
                    </div>
                    <div class="visual-card small">
                        <img src="https://uqt.com.sa/ContentFront/img/home-6/hero/2.png" alt="city">
                        <div class="badge badge--alt"><span class="icon"><i class="fas fa-certificate"></i></span> <span><br> شهادات معتمدة </span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// يجب تضمين config.php و includes/auth.php قبل هذا الملف في الصفحات
$userRole = $_SESSION['role'] ?? 'employee';
if ($userRole === 'supervisor') {
    $dashboardType = 'supervisor';
} elseif ($userRole === 'admin') {
    $dashboardType = 'admin';
} else {
    $dashboardType = 'employee';
}
// تحديد الرابط/المسار الحالي وتهيئة دالة تمييز الرابط النشط
$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!function_exists('link_is_active')) {
    function link_is_active($needle)
    {
        global $current_path;
        return strpos($current_path, $needle) !== false;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>المعهد التقني للتدريب</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts - Cairo -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- DataTables CSS - Tailwind Version -->
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css" rel="stylesheet">

    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Search Widget -->
    <script src="<?php echo rtrim(SITE_URL, '/'); ?>/public/assets/js/search.js"></script>

    <script>
           tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd', 400: '#60a5fa',
                            500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8', 800: '#1e40af', 900: '#1e3a8a'
                        },
                        dark: {
                            50: '#f8fafc', 100: '#f1f5f9', 200: '#e2e8f0', 300: '#cbd5e1',
                            400: '#94a3b8', 500: '#64748b', 600: '#475569', 700: '#334155',
                            800: '#1e293b', 900: '#0f172a', 950: '#020617'
                        }
                    },
                    fontFamily: { 'arabic': ['Cairo', 'Tahoma', 'sans-serif'] },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-in-out',
                        'slide-up': 'slideUp 0.3s ease-out',
                        'bounce-subtle': 'bounceSubtle 0.6s ease-in-out',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' }
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(10px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' }
                        },
                        bounceSubtle: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-5px)' }
                        }
                    }
                },
            },
        }
    </script>
    <!-- NOTE: removed duplicate Alpine include (previous line already loads Alpine) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo rtrim(SITE_URL, '/'); ?>/public/assets/css/dark-mode.css" rel="stylesheet">

    <script>
        /* Theme initialization and helper for layouts that use this file
           - honors localStorage 'theme' ('light'|'dark'|'system')
           - falls back to prefers-color-scheme when 'system' or unset
           - exposes setTheme(theme) and toggleTheme() on window
        */
        (function(){
            function applyTheme(t){
                if(t === 'dark'){
                    document.documentElement.classList.add('dark');
                    document.documentElement.setAttribute('data-theme', 'dark');
                } else {
                    document.documentElement.classList.remove('dark');
                    document.documentElement.setAttribute('data-theme', 'light');
                }
                // update any toggle buttons
                document.querySelectorAll('[data-theme-toggle]').forEach(function(btn){
                    var icon = btn.querySelector('i');
                    if(!icon) return;
                    if(t === 'dark') { icon.className = 'fas fa-sun'; btn.setAttribute('aria-pressed','true'); }
                    else { icon.className = 'fas fa-moon'; btn.setAttribute('aria-pressed','false'); }
                });
            }

            var stored = null;
            try { stored = localStorage.getItem('theme'); } catch(e) { stored = null; }

            var themeToApply = 'light';
            if(stored === 'dark' || (stored === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)){
                themeToApply = 'dark';
            }

            applyTheme(themeToApply);

            window.setTheme = function(theme){
                try {
                    if(theme === 'system') { localStorage.removeItem('theme'); }
                    else { localStorage.setItem('theme', theme); }
                } catch(e) {}
                if(theme === 'system'){
                    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                    applyTheme(prefersDark ? 'dark' : 'light');
                } else {
                    applyTheme(theme === 'dark' ? 'dark' : 'light');
                }
            };

            window.toggleTheme = function(){
                var isDark = document.documentElement.classList.contains('dark');
                window.setTheme(isDark ? 'light' : 'dark');
            };

            // react to system preference changes when stored preference is unset
            try {
                if(window.matchMedia){
                    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e){
                        try { if(!localStorage.getItem('theme')) applyTheme(e.matches ? 'dark' : 'light'); } catch(_){}
                    });
                }
            } catch(e) { /* some browsers use addListener - ignored for brevity */ }
        })();
    </script>
</head>
   
    <style>
        /* تخصيص السكرول بار ليكون رفيعاً وأزرق على جميع العناصر */
        ::-webkit-scrollbar {
            width: 2px;
            height: 2px;
        }
        
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #3b83f67c;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #2563eb;
        }
        
        /* للوضع المظلم */
        .dark ::-webkit-scrollbar-thumb {
            background: #60a5fa7e;
        }
        
        .dark ::-webkit-scrollbar-thumb:hover {
            background: #3b83f68c;
        }
        
        /* دعم Firefox */
        * {
            scrollbar-width: thin;
            scrollbar-color: #3b83f677 transparent;
        }
        
        .dark * {
            scrollbar-color: #60a5fa6b transparent;
        }
        
        /* تخصيص إضافي للسايد بار */
        .sidebar-scroll {
            /* scrollbar-width: thin; */
            scrollbar-color: #3b83f680 transparent;
        }
        
                .dark .sidebar-scroll {
            scrollbar-color: #60a5fa70 transparent;
        }

        /* WebKit scrollbar for sidebar - thin and blue */
        .sidebar-scroll::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }
        .sidebar-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: #bfdbfe; /* very light blue */
            border-radius: 999px;
            border: 1px solid transparent;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover {
            background: #93c5fd; /* slightly darker on hover */
        }
        /* dark mode slightly transparent thumb */
        .dark .sidebar-scroll::-webkit-scrollbar-thumb {
            background: rgba(191,219,254,0.5);
        }

        /* تأثيرات الانتقال بين الصفحات */
        .page-transition-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.98);
            z-index: 9999;
            pointer-events: none;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
        }

        .dark .page-transition-overlay {
            background: rgba(17, 24, 39, 0.98);
        }

        .page-transition-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .page-transition-wave {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
            width: 300vh;
            height: 300vh;
            background: radial-gradient(
                circle,
                rgba(59, 130, 246, 0.1) 0%,
                rgba(59, 130, 246, 0.05) 30%,
                transparent 70%
            );
            border-radius: 50%;
            z-index: 9998;
            animation: none;
        }

        .page-transition-content {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 10000;
            display: flex;
            flex-direction: column;
            align-items: center;
            opacity: 0;
            visibility: hidden;
        }

        .page-transition-content.active {
            opacity: 1;
            visibility: visible;
            animation: contentFadeIn 0.3s ease forwards;
        }

        /* إزالة الأيقونات الزخرفية/صور الخلفية في الوضع المظلم على مستوى النظام */
        /* يمنع هذا ظهور علامات التحقق/الآيكونات الصغيرة التي تُضاف كـ background-image */
        .dark input[type="text"],
        .dark input[type="search"],
        .dark input[type="number"],
        .dark input[type="date"],
        .dark input[type="time"],
        .dark input[type="email"],
        .dark textarea,
        .dark .form-control.is-valid,
        .dark .form-control.is-invalid,
        .dark .is-valid,
        .dark .is-invalid {
            background-image: none !important;
            background-repeat: no-repeat !important;
            background-position: right center !important;
        }

        /* للحفاظ على سهم الاختيار الافتراضي في القوائم المنسدلة، لا نستخدم appearance: none على select */
        .dark select {
            background-image: none !important;
            /* نسمح للمستعرض بإظهار السهم الافتراضي */
            appearance: auto !important;
            -webkit-appearance: menulist-button !important;
            -moz-appearance: auto !important;
        }

        .page-transition-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: #3b82f6;
            animation: iconFloat 1s ease infinite;
        }

        @keyframes waveExpand {
            0% {
                transform: translate(-50%, -50%) scale(0);
                opacity: 1;
            }
            100% {
                transform: translate(-50%, -50%) scale(1);
                opacity: 0;
            }
        }

        @keyframes contentFadeIn {
            0% {
                opacity: 0;
                transform: translate(-50%, -50%) scale(0.9);
            }
            100% {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }

        @keyframes iconFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        /* تأثير حركي متقدم للنص */
        .advanced-text {
            position: relative;
            display: inline-block;
            padding: 0 8px;
            background: linear-gradient(
                90deg,
                #000 0%,
                #333 20%,
                #000 40%,
                #333 60%,
                #000 80%,
                #333 100%
            );
            background-size: 200% auto;
            color: transparent;
            background-clip: text;
            -webkit-background-clip: text;
            animation: shine 3s linear infinite;
            transition: all 0.3s ease;
        }

        .dark .advanced-text {
            background: linear-gradient(
                90deg,
                #fff 0%,
                #ccc 20%,
                #fff 40%,
                #ccc 60%,
                #fff 80%,
                #ccc 100%
            );
            background-size: 200% auto;
            color: transparent;
            background-clip: text;
            -webkit-background-clip: text;
        }

        @keyframes shine {
            from {
                background-position: 200% center;
            }
            to {
                background-position: -200% center;
            }
        }

        .advanced-text:hover {
            transform: scale(1.05);
            filter: brightness(1.2);
            transition: all 0.3s ease;
        }

        /* تنسيقات السكرول بار */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: #3b83f677;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #3b82f6;
        }

        * {
            scrollbar-width: thin;
            scrollbar-color: #3b83f677 transparent;
        }

        .dark ::-webkit-scrollbar-track {
            background: #1e293b;
        }

        .dark ::-webkit-scrollbar-thumb {
            background: #60a5fa6b;
        }

        .dark ::-webkit-scrollbar-thumb:hover {
            background: #60a5fa;
        }

        .dark * {
            scrollbar-color: #60a5fa6b transparent;
        }

        /* كروت الإحصائيات - تصميم جديد */
        .stat-card {
            transition: all 0.3s ease;
            border-radius: 16px;
            overflow: hidden;
            background: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
        
        .dark .stat-card {
            background: #1e293b;
            border-color: #334155;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        /* محتوى البطاقات - تصميم جديد */
        .content-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
        
        .dark .content-card {
            background: #1e293b;
            border-color: #334155;
        }

        /* عناصر القوائم - تصميم جديد */
        .menu-item {
            transition: all 0.2s ease;
            border-radius: 8px;
            padding: 10px 12px;
            color: #64748b;
        }
        
        .menu-item:hover {
            background-color: #f1f5f9;
            color: #0ea5e9;
        }
        
        .dark .menu-item:hover {
            background-color: #1e293b;
            color: #38bdf8;
        }

        /* تأثيرات دخول */
        .animate-in {
            animation: slideIn 0.5s ease-out;
        }
        
        .delay-1 {
            animation-delay: 0.1s;
        }
        
        .delay-2 {
            animation-delay: 0.2s;
        }
        
        .delay-3 {
            animation-delay: 0.3s;
        }
        
        .delay-4 {
            animation-delay: 0.4s;
        }

        /* Improved, RTL-safe icon spacing across the dashboard. */
        .icon-inline,
        .navbar i.fas,
        .sidebar i.fas,
        .menu-item i.fas,
        .stat-card .stat-icon i.fas,
        .content-card i.fas {
            margin-inline-start: 0.5rem;
            margin-inline-end: 0.4rem;
            vertical-align: middle;
        }

        /* شريط التنقل العلوي - تصميم جديد */
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            height: 70px;
        }
        
        .dark .navbar {
            background: rgba(15, 23, 42, 0.95);
            border-bottom: 1px solid rgba(51, 65, 85, 0.5);
        }
        
        /* الشريط الجانبي - تصميم جديد */
        /* sidebar variables are defined in the main index.php to allow central control */
        .sidebar {
            background: white;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
            border-right: 1px solid rgba(226, 232, 240, 0.8);
            width: var(--sidebar-width);
            min-width: 18rem;
            max-width: 20rem;
        }
        
        .dark .sidebar {
            background: #0f172a;
            border-right: 1px solid rgba(51, 65, 85, 0.5);
        }
        
        .sidebar-item {
            transition: all 0.2s ease;
            border-radius: 10px;
            margin: 6px 12px;
            padding: 10px 12px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .sidebar-item:hover {
            background-color: #f1f5f9;
            color: #0ea5e9;
        }
        
        .dark .sidebar-item:hover {
            background-color: #1e293b;
            color: #38bdf8;
        }
        
        .sidebar-item.active {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(14, 165, 233, 0.2);
        }

        /* Ensure link text truncates nicely and icons keep spacing */
        .sidebar-item .font-medium {
            display: inline-block;
            vertical-align: middle;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: calc(var(--sidebar-width) - 5rem);
        }
        
        .dark .sidebar-item.active {
            background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%);
        }
        
        .logo-container {
            padding: 20px 16px;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }
        
        .dark .logo-container {
            border-color: rgba(51, 65, 85, 0.5);
        }
    </style>

<body class="font-arabic bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 transition-colors duration-300"
    x-data="{ 
    darkMode: localStorage.getItem('darkMode') === 'true' || false, 
    sidebarOpen: false,
    init() {
        this.$watch('darkMode', (value) => {
            localStorage.setItem('darkMode', value);
            document.documentElement.classList.toggle('dark', value);
        });
        // تطبيق الوضع المظلم عند التحميل
        document.documentElement.classList.toggle('dark', this.darkMode);
    }
}" :class="{ 'dark': darkMode }">
    <!-- إضافة عناصر التأثير الانتقالي -->
    <div class="page-transition-overlay">
        <div class="page-transition-wave"></div>
        <div class="page-transition-content">
            <i class="fas fa-sync page-transition-icon"></i>
            <span class="text-lg font-bold text-gray-800 dark:text-gray-200">جاري الانتقال...</span>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const overlay = document.querySelector(".page-transition-overlay");
            const wave = document.querySelector(".page-transition-wave");
            const content = document.querySelector(".page-transition-content");
            const navigationLinks = document.querySelectorAll("a[href]:not([href^='#']):not([href^='mailto']):not([href^='tel']):not([target='_blank'])");

            navigationLinks.forEach(link => {
                link.addEventListener("click", function(e) {
                    if (this.href.includes(window.location.origin)) {
                        e.preventDefault();
                        const targetHref = this.href;

                        // تحريك الموجة من موقع النقر
                        const clickX = e.clientX;
                        const clickY = e.clientY;
                        wave.style.top = clickY + "px";
                        wave.style.left = clickX + "px";
                        wave.style.transform = "translate(-50%, -50%) scale(0)";

                        // تفعيل التأثير الانتقالي
                        overlay.classList.add("active");
                        content.classList.add("active");
                        wave.style.animation = "waveExpand 0.8s ease-out forwards";

                        // تحديد أيقونة مخصصة حسب نوع الصفحة
                        const transitionIcon = document.querySelector(".page-transition-icon");
                        if (targetHref.includes("trainees")) {
                            transitionIcon.className = "fas fa-users page-transition-icon";
                        } else if (targetHref.includes("payments")) {
                            transitionIcon.className = "fas fa-money-bill-wave page-transition-icon";
                        } else if (targetHref.includes("communications")) {
                            transitionIcon.className = "fas fa-comments page-transition-icon";
                        } else if (targetHref.includes("tasks")) {
                            transitionIcon.className = "fas fa-tasks page-transition-icon";
                        } else if (targetHref.includes("reports")) {
                            transitionIcon.className = "fas fa-chart-bar page-transition-icon";
                        } else if (targetHref.includes("branches")) {
                            transitionIcon.className = "fas fa-code-branch page-transition-icon";
                        } else if (targetHref.includes("diplomas")) {
                            transitionIcon.className = "fas fa-graduation-cap page-transition-icon";
                        } else if (targetHref.includes("settings")) {
                            transitionIcon.className = "fas fa-cog page-transition-icon";
                        }

                        // الانتقال للصفحة الجديدة
                        setTimeout(() => {
                            window.location.href = targetHref;
                        }, 800);
                    }
                });
            });
        });
    </script>

    <!-- شريط التنقل العلوي -->
    <nav class="navbar fixed top-0 right-0 left-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-full">
            <div class="flex justify-between h-full items-center">
                <div class="flex items-center">
                    <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden p-2 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 mr-3">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div class="flex-shrink-0 flex items-center mr-4">
                        <img src="<?php echo rtrim(SITE_URL, '/'); ?>/public/assets/images/logo.png" alt="Logo" class="h-16 w-auto">
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/index.php" 
                           class="advanced-text mr-2 text-xl font-bold tracking-wider transition-all duration-300">
                           المعهد التقني للتدريب
                        </a>
                    </div>
                </div>

                <div class="flex items-center space-x-4 gap-4">
                    <!-- زر الوضع المظلم -->
                    <button @click="darkMode = !darkMode" class="p-2.5 rounded-full  text-gray-600 dark:text-gray-300  dark:hover:bg-gray-700">
                        <i class="fas fa-moon" x-show="!darkMode"></i>
                        <i class="fas fa-sun" x-show="darkMode"></i>
                    </button>
                    
                    <!-- إشعارات (جرس) -->
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <div class="relative">
                            <?php include_once __DIR__ . '/notifications_component.php'; ?>
                        </div>
                    <?php endif; ?>

                    <!-- الملف الشخصي -->
                    <div class="relative">
                        <button id="profileButton" class="flex items-center space-x-3 p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800">
                            <img class="w-9 h-9 rounded-full object-cover border-2 border-white dark:border-gray-700 shadow-sm"
                                 src="<?php echo htmlspecialchars(isset($_SESSION['avatar']) && $_SESSION['avatar'] ? $_SESSION['avatar'] : 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['full_name'] ?? 'User') . '&background=0ea5e9&color=fff&size=64'); ?>" alt="Profile">
                            <span class="text-sm font-medium hidden md:block">
                                <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'مستخدم'); ?>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <?php include_once __DIR__ . '/alerts.php'; ?>

    <div id="mobileSidebarOverlay" class="fixed inset-0 z-40 md:hidden hidden" x-show="sidebarOpen" @click="sidebarOpen = false" style="display: none;">
        <div class="fixed inset-0 bg-gray-600 bg-opacity-75"></div>
    </div>

    <!-- السايد بار للشاشات الصغيرة -->
    <div id="mobileSidebarPanel" class="fixed inset-y-0 right-0 z-50 bg-white dark:bg-gray-800 shadow-xl transform transition-transform duration-300 ease-in-out md:hidden translate-x-full"
        :class="{ 'translate-x-0': sidebarOpen, 'translate-x-full': !sidebarOpen }" x-show="sidebarOpen"
        style="display: none; width: var(--sidebar-width-sm); max-width: 90vw;">
        <div class="p-5 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-lg font-bold text-gray-800 dark:text-white">لوحة التحكم</h2>
            <button @click="sidebarOpen = false" class="text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- make this inner container scrollable on touch devices -->
        <div class="flex-1 p-4 space-y-6 sidebar-scroll" style="overflow-y:auto; -webkit-overflow-scrolling: touch; touch-action: pan-y; max-height: calc(100vh - 5.5rem);">
            <!-- القائمة الرئيسية -->
            <div class="space-y-2">
                <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">القائمة الرئيسية</h3>
                <ul class="space-y-1">
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/index.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/index.php') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-chart-pie text-base ml-3"></i>
                            <span class="font-medium">لوحة التحكم</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- إدارة المتدربين -->
            <?php if (hasAccess('trainees', 'view') || hasAccess('payments', 'view')): ?>
            <div class="space-y-2">
                <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">إدارة المتدربين</h3>
                <ul class="space-y-1">
                    <?php if (hasAccess('trainees', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/trainees/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-users text-base ml-3"></i>
                            <span class="font-medium">المتدربين</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasAccess('payments', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/payments/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/payments/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-money-bill-wave text-base ml-3"></i>
                            <span class="font-medium">المدفوعات</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- العمليات اليومية -->
            <?php if (hasAccess('contacts', 'view') || hasAccess('tasks', 'view')): ?>
            <div class="space-y-2">
                <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">العمليات اليومية</h3>
                <ul class="space-y-1">
                    <?php if (hasAccess('contacts', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/communications/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/communications/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-phone text-base ml-3"></i>
                            <span class="font-medium">التواصل</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasAccess('tasks', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/tasks/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/tasks/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-tasks text-base ml-3"></i>
                            <span class="font-medium">المهام</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- الإدارة -->
            <?php
            
            $adminSectionVisible = ($dashboardType === 'admin') || hasAccess('users','view') || hasAccess('reports','view') || hasAccess('branches','view') || hasAccess('diplomas','view');
            if ($adminSectionVisible): ?>
            <div class="space-y-2">
                <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">الإدارة</h3>
                <ul class="space-y-1">
                    <?php if (hasAccess('reports', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/reports/financial.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/reports/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-chart-bar text-base ml-3"></i>
                            <span class="font-medium">التقارير</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasAccess('branches', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/branches/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/branches/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-code-branch text-base ml-3"></i>
                            <span class="font-medium">الفروع</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasAccess('users', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/users/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/users/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-user-friends text-base ml-3"></i>
                            <span class="font-medium">المستخدمين</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasAccess('diplomas', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/diplomas/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/diplomas/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-certificate text-base ml-3"></i>
                            <span class="font-medium">الدبلومات</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/settings/index.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/settings/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-cogs text-base ml-3"></i>
                            <span class="font-medium">الإعدادات</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- تسجيل الخروج -->
            <div class="border-t border-gray-200 dark:border-gray-600 pt-4">
                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/auth/logout.php" 
                   class="sidebar-item flex items-center bg-red-500 px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 text-gray-50 dark:text-gray-50 hover:bg-red-600 dark:hover:bg-red-600/20">
                    <i class="fas fa-sign-out-alt text-base ml-3"></i>
                    <span class="font-medium">تسجيل الخروج</span>
                </a>
            </div>
        </div>
    </div>

    <!-- السايد بار للشاشات الكبيرة -->
    <aside id="sidebar" 
    class="sidebar fixed top-0 right-0 bottom-0 transform -translate-x-full lg:translate-x-0 
          transition-transform duration-300 z-40 flex flex-col h-screen hidden md:flex
          bg-white dark:bg-gray-900 shadow-lg"
    style="width: var(--sidebar-width);">

        <!-- رأس الشريط الجانبي -->
        <div class="p-5 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-lg font-bold text-gray-800 dark:text-white">لوحة التحكم</h2>
        </div>

    <!-- المحتوى مع Scroll داخلي -->
    <div class="flex-1 p-4 space-y-6 sidebar-scroll" style="overflow-y:auto; -webkit-overflow-scrolling: touch; touch-action: pan-y; max-height: calc(100vh - 5.5rem);">
            <!-- القائمة الرئيسية -->
            <div class="space-y-2">
                <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">القائمة الرئيسية</h3>
                <ul class="space-y-1">
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/index.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/index.php') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-chart-pie text-base ml-3"></i>
                            <span class="font-medium">لوحة التحكم</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- إدارة المتدربين -->
            <?php if (hasAccess('trainees', 'view') || hasAccess('payments', 'view')): ?>
            <div class="space-y-2">
                <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">إدارة المتدربين</h3>
                <ul class="space-y-1">
                    <?php if (hasAccess('trainees', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/trainees/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/trainees/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-users text-base ml-3"></i>
                            <span class="font-medium">المتدربين</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasAccess('payments', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/payments/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/payments/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-money-bill-wave text-base ml-3"></i>
                            <span class="font-medium">المدفوعات</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- العمليات اليومية -->
            <?php if (hasAccess('contacts', 'view') || hasAccess('tasks', 'view')): ?>
            <div class="space-y-2">
                <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">العمليات اليومية</h3>
                <ul class="space-y-1">
                    <?php if (hasAccess('contacts', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/communications/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/communications/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-phone text-base ml-3"></i>
                            <span class="font-medium">التواصل</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasAccess('tasks', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/tasks/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/tasks/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-tasks text-base ml-3"></i>
                            <span class="font-medium">المهام</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- الإدارة -->
            <?php
            // Desktop sidebar admin section mirror of the mobile one above
            $adminSectionVisible = ($dashboardType === 'admin') || hasAccess('users','view') || hasAccess('reports','view') || hasAccess('branches','view') || hasAccess('diplomas','view');
            if ($adminSectionVisible): ?>
            <div class="space-y-2">
                <h3 class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 font-semibold px-2">الإدارة</h3>
                <ul class="space-y-1">
                    <?php if (hasAccess('reports', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/reports/financial.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/reports/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-chart-bar text-base ml-3"></i>
                            <span class="font-medium">التقارير</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasAccess('branches', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/branches/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/branches/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-code-branch text-base ml-3"></i>
                            <span class="font-medium">الفروع</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasAccess('users', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/users/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/users/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-user-friends text-base ml-3"></i>
                            <span class="font-medium">المستخدمين</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasAccess('diplomas', 'view')): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/diplomas/list.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/diplomas/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-certificate text-base ml-3"></i>
                            <span class="font-medium">الدبلومات</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <li>
                        <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/settings/index.php" 
                           class="sidebar-item flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 <?php echo link_is_active('/modules/settings/') ? 'active' : 'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-cogs text-base ml-3"></i>
                            <span class="font-medium">الإعدادات</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

              <!-- تسجيل الخروج -->
            <div class="border-t border-gray-200 dark:border-gray-600 pt-4">
                <a href="<?php echo rtrim(SITE_URL, '/'); ?>/modules/auth/logout.php"
                    class="flex items-center px-4 py-2.5 rounded-lg transition-colors duration-200 gap-3 bg-red-600 text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                    role="button" aria-label="تسجيل الخروج">
                    <i class="fas fa-sign-out-alt text-base ml-3"></i>
                    <span class="font-medium">تسجيل الخروج</span>
                </a>
            </div>
        </div>
    </aside>

    <main class="pt-16 min-h-screen flex flex-col" style="margin-right: 0; width: 100%;">
        <style>
            @media (min-width: 768px) {
                main {
                    margin-right: 18rem !important;
                    /* 288px = w-72 */
                    width: calc(100% - 18rem) !important;
                }
            }
        </style>
        <div class="p-6 flex-1">
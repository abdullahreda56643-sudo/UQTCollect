<?php
// التحقق من الجلسة
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'login.php') {
    header('Location: ../modules/auth/login.php');
    exit;
}

// تحديد الصفحة الحالية
$current_page = basename($_SERVER['PHP_SELF']);
$is_rtl = true; // للغة العربية
?>
<!DOCTYPE html>
<html lang="ar" dir="<?php echo $is_rtl ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="نظام إدارة المتدربين والتحصيل المالي">
    <meta name="author" content="Training Management System">
    <meta name="keywords" content="تدريب, متدربين, تحصيل, إدارة">

    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    
    <!-- Initialize notifications count -->
    <?php
    require_once __DIR__ . '/notifications.php';
    require_once __DIR__ . '/../db/connection.php';
    
    $database = new Database();
    $db = $database->getConnection();
    $unreadCount = getUnreadNotificationsCount($db, $_SESSION['user_id']);
    ?>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../public/assets/images/favicon.ico">

    <!-- Bootstrap CSS RTL -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts - Cairo -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Custom JavaScript -->
    <script src="/my-works/Trainee-Management-System/public/assets/js/notifications.js" defer></script>

    <!-- Custom CSS -->
    <style>
        :root {
            --bs-font-sans-serif: 'Cairo', system-ui, -apple-system, sans-serif;
        }
        
        body {
            font-family: var(--bs-font-sans-serif);
            background-color: #f8f9fa;
        }

        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: none;
            margin-bottom: 1rem;
            background-color: #fff;
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid rgba(0,0,0,.125);
            padding: 1rem;
        }

        .table > :not(caption) > * > * {
            padding: 1rem 1rem;
        }

        .badge {
            padding: 0.5em 0.75em;
            font-weight: 500;
        }

        .stats-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .bg-primary-subtle { background-color: rgba(13, 110, 253, 0.1) !important; }
        .bg-success-subtle { background-color: rgba(25, 135, 84, 0.1) !important; }
        .bg-warning-subtle { background-color: rgba(255, 193, 7, 0.1) !important; }
        .bg-info-subtle { background-color: rgba(13, 202, 240, 0.1) !important; }
        .bg-danger-subtle { background-color: rgba(220, 53, 69, 0.1) !important; }

        .text-primary { color: #0d6efd !important; }
        .text-success { color: #198754 !important; }
        .text-warning { color: #ffc107 !important; }
        .text-info { color: #0dcaf0 !important; }
        .text-danger { color: #dc3545 !important; }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            border-radius: 0.25rem;
        }

        /* Notifications styles */
        .notifications-icon {
            position: relative;
            cursor: pointer;
        }

        .notifications-badge {
            top: 0;
            right: 0;
            transform: translate(50%, -50%);
            padding: 0.25rem;
            font-size: 0.75rem;
            min-width: 18px;
            height: 18px;
            line-height: 1;
            text-align: center;
        }

        .notifications-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            width: 350px;
            background: #fff;
            border-radius: 0.5rem;
            display: none;
            z-index: 1000;
            max-height: 450px;
            overflow-y: auto;
        }

        .notifications-dropdown.show {
            display: block;
        }

        .notification-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(0,0,0,.1);
            transition: background-color 0.2s;
            cursor: pointer;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item:hover {
            background-color: rgba(0,0,0,.05);
        }

        .notification-item.unread {
            background-color: rgba(13, 110, 253, 0.05);
        }

        .notifications-list {
            max-height: 350px;
            overflow-y: auto;
        }

        .notifications-dropdown.show {
            display: block;
        }

        .notification-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(0,0,0,.1);
            cursor: pointer;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item:hover {
            background-color: rgba(0,0,0,.05);
        }

        .notification-item.unread {
            background-color: rgba(13, 110, 253, 0.05);
        }
        }

        .numbers p {
            font-size: 0.875rem;
            margin-bottom: 0;
            color: #6c757d;
        }

        .numbers h5 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .list-group-item {
            border-right: 0;
            border-left: 0;
        }

        .text-sm {
            font-size: 0.875rem;
        }
        
        
        body {
            font-family: var(--bs-font-sans-serif);
        }

        .avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .avatar.avatar-lg {
            width: 48px;
            height: 48px;
        }

        .avatar-title {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Custom Bootstrap Colors */
        .bg-primary-subtle { background-color: rgba(var(--bs-primary-rgb), 0.1) !important; }
        .bg-success-subtle { background-color: rgba(var(--bs-success-rgb), 0.1) !important; }
        .bg-warning-subtle { background-color: rgba(var(--bs-warning-rgb), 0.1) !important; }
        .bg-info-subtle { background-color: rgba(var(--bs-info-rgb), 0.1) !important; }
        .bg-danger-subtle { background-color: rgba(var(--bs-danger-rgb), 0.1) !important; }

        .text-primary { color: var(--bs-primary) !important; }
        .text-success { color: var(--bs-success) !important; }
        .text-warning { color: var(--bs-warning) !important; }
        .text-info { color: var(--bs-info) !important; }
        .text-danger { color: var(--bs-danger) !important; }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- DataTables CSS - Tailwind Version -->
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css" rel="stylesheet">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            200: '#c7d2fe',
                            300: '#a5b4fc',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            800: '#3730a3',
                            900: '#312e81',
                        },
                        secondary: {
                            50: '#f8fafc',
                            100: '#f1f5f9',
                            200: '#e2e8f0',
                            300: '#cbd5e1',
                            400: '#94a3b8',
                            500: '#64748b',
                            600: '#475569',
                            700: '#334155',
                            800: '#1e293b',
                            900: '#0f172a',
                        }
                    },
                    fontFamily: {
                        sans: ['Cairo', 'sans-serif'],
                    },
                },
            },
            rtl: true,
        }
    </script>
    <style type="text/tailwindcss">
        @layer utilities {
            .scrollbar-thin::-webkit-scrollbar {
                width: 6px;
            }
            .scrollbar-thin::-webkit-scrollbar-track {
                background: #f1f1f1;
            }
            .scrollbar-thin::-webkit-scrollbar-thumb {
                background: #888;
                border-radius: 3px;
            }
            .scrollbar-thin::-webkit-scrollbar-thumb:hover {
                background: #555;
            }
        }
    </style>
    
    <!-- Google Fonts - Cairo for Arabic -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link href="../public/assets/css/sidebar.css" rel="stylesheet">
    <!-- Dark mode helpers -->
    <link href="<?php echo rtrim(SITE_URL, '/'); ?>/public/assets/css/dark-mode.css" rel="stylesheet">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

    <script>
        // Fallback theme initializer for pages that include header.php directly
        (function(){
            function applyTheme(t){
                if(t === 'dark') document.documentElement.classList.add('dark'); else document.documentElement.classList.remove('dark');
                document.querySelectorAll('[data-theme-toggle]').forEach(function(btn){
                    var i = btn.querySelector('i'); if(!i) return;
                    if(t === 'dark') { i.className = 'fas fa-sun'; btn.setAttribute('aria-pressed','true'); } else { i.className = 'fas fa-moon'; btn.setAttribute('aria-pressed','false'); }
                });
            }
            var stored = null;
            try { stored = localStorage.getItem('theme'); } catch(e) { stored = null; }
            var apply = 'light';
            if(stored === 'dark' || (stored === null && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) apply = 'dark';
            applyTheme(apply);

            window.toggleTheme = function(){
                var isDark = document.documentElement.classList.toggle('dark');
                try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch(e) {}
                applyTheme(isDark ? 'dark' : 'light');
            };
        })();
    </script>

    <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <!--[if lt IE 9]>
        <script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
        <script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
    <![endif]-->
</head>
<body class="bg-light <?php echo $is_rtl ? 'rtl' : 'ltr'; ?>">
    <!-- Page Wrapper -->
    <div id="wrapper">
        <?php if (isset($_SESSION['user_id'])): ?>
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>
        <!-- End of Sidebar -->
        <?php endif; ?>

        <!-- Content Wrapper -->
        <div id="content-wrapper" class="d-flex flex-column">
            <!-- Main Content -->
            <div id="content">
                <?php if (isset($_SESSION['user_id'])): ?>
                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow">
                    <!-- Sidebar Toggle (Topbar) -->
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars"></i>
                    </button>

                    <!-- Topbar Search -->
                    <form class="d-none d-sm-inline-block form-inline mr-auto ml-md-3 my-2 my-md-0 mw-100 navbar-search">
                        <div class="input-group">
                            <input type="text" class="form-control bg-light border-0 small" placeholder="بحث..." aria-label="Search" aria-describedby="basic-addon2">
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="button">
                                    <i class="fas fa-search fa-sm"></i>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Topbar Navbar -->
                    <ul class="navbar-nav ml-auto">
                        <!-- Nav Item - Notifications -->
                        <li class="nav-item dropdown no-arrow mx-1">
                            <div class="notifications-icon nav-link position-relative" id="notificationsIcon">
                                <i class="fas fa-bell fa-fw"></i>
                                <?php if ($unreadCount > 0): ?>
                                    <span class="badge bg-danger notifications-badge position-absolute"><?php echo $unreadCount; ?></span>
                                <?php endif; ?>
                                <div class="notifications-dropdown shadow" id="notificationsDropdown">
                                    <div class="dropdown-header d-flex justify-content-between align-items-center p-3 border-bottom">
                                        <h6 class="m-0 fw-bold">الإشعارات</h6>
                                        <?php if ($unreadCount > 0): ?>
                                            <button class="btn btn-link btn-sm p-0 text-primary mark-all-read">تعيين الكل كمقروء</button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notifications-list">
                                        <div class="text-center p-3 loading d-none">
                                            <div class="spinner-border spinner-border-sm text-primary" role="status">
                                                <span class="visually-hidden">جاري التحميل...</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                                <i class="fas fa-bell fa-fw"></i>
                                <!-- Counter - Alerts -->
                                <span class="badge badge-danger badge-counter">3+</span>
                            </a>
                        </li>

                        <!-- Nav Item - Theme Toggle -->
                        <li class="nav-item mx-1">
                            <button type="button" class="btn btn-sm btn-icon" aria-label="تبديل الوضع" data-theme-toggle onclick="toggleTheme()">
                                <i class="fas fa-moon" aria-hidden="true"></i>
                            </button>
                        </li>

                        <!-- Nav Item - Messages -->
                        <li class="nav-item dropdown no-arrow mx-1">
                            <a class="nav-link dropdown-toggle" href="#" id="messagesDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-envelope fa-fw"></i>
                                <!-- Counter - Messages -->
                                <span class="badge badge-danger badge-counter">7</span>
                            </a>
                        </li>

                        <div class="topbar-divider d-none d-sm-block"></div>

                        <!-- Nav Item - User Information -->
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-gray-600 small"><?php echo $_SESSION['full_name']; ?></span>
                                <img class="img-profile rounded-circle" src="../public/assets/images/user-profile.png" width="32" height="32">
                            </a>
                            <!-- Dropdown - User Information -->
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
                                <a class="dropdown-item" href="../modules/profile/edit.php">
                                    <i class="fas fa-user fa-sm fa-fw mr-2 text-gray-400"></i>
                                    الملف الشخصي
                                </a>
                                <a class="dropdown-item" href="../modules/profile/settings.php">
                                    <i class="fas fa-cogs fa-sm fa-fw mr-2 text-gray-400"></i>
                                    الإعدادات
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="#" data-toggle="modal" data-target="#logoutModal">
                                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i>
                                    تسجيل الخروج
                                </a>
                            </div>
                        </li>
                    </ul>
                </nav>
                <!-- End of Topbar -->
                <?php endif; ?>

                <!-- Begin Page Content -->
                <div class="container-fluid">

<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: Login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salon Workspace | Administration Control</title>
    <link rel="icon" type="image/x-icon" href="../Assets/icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />

    <style>
        :root {
            --primary-accent: #EBBB15;
            --charcoal-black: #121212;
            --panel-dark: #1A1A1A;
            --studio-blush: #FFEBE8;
            --border-subtle: #E5E7EB;
            --sidebar-width: 280px;
            --sidebar-collapsed: 78px;
            --sidebar-gradient: linear-gradient(185deg, #1A1A1A 0%, #151515 50%, #0E0E0E 100%);
        }

        *{
            margin:0;
            padding:0;
            box-sizing:border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #FFFFFF;
            color: var(--charcoal-black);
            overflow: hidden;
        }

        #sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-gradient);
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }

        .menu-trigger, .submenu-trigger {
            padding: 11px 14px;
            margin: 4px 12px;
            border-radius: 8px;
            color: #9CA3AF;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.25s ease;
            font-size: 0.85rem;
            border: 1px solid transparent;
        }

        .menu-trigger:hover, .submenu-trigger:hover {
            background: rgba(235, 187, 21, 0.06);
            color: var(--primary-accent);
            transform: translateX(2px);
        }

        .menu-item.active-parent .menu-trigger {
            color: white;
            background: rgba(255, 255, 255, 0.03);
            border-left: 3px solid var(--primary-accent);
            border-color: rgba(235, 187, 21, 0.2) rgba(235, 187, 21, 0.2) rgba(235, 187, 21, 0.2) var(--primary-accent);
        }

        .sub-menu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s ease-out;
            margin-left: 28px;
            border-left: 1px dashed rgba(235, 187, 21, 0.3);
        }

        .sub-menu.show {
            max-height: 800px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 9px 16px;
            margin: 3px 12px;
            color: #9CA3AF;
            font-size: 0.8rem;
            border-radius: 6px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .nav-link:hover {
            color: white;
            padding-left: 20px;
            background: rgba(255, 255, 255, 0.02);
        }

        .nav-link.active {
            background: var(--primary-accent);
            color: var(--charcoal-black) !important;
            font-weight: 500;
            box-shadow: 0 4px 20px rgba(235, 187, 21, 0.2);
        }

        .section-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            color: #555555;
            padding: 22px 24px 6px;
            letter-spacing: 0.08em;
        }

        .main-workspace {
            background: #FFFFFF;
            border: 1px solid var(--border-subtle);
        }

        .chevron-icon {
            transition: transform 0.2s;
            font-size: 0.65rem;
            color: #6B7280;
        }

        .rotate-chevron {
            transform: rotate(180deg);
            color: var(--primary-accent);
        }

        .custom-scroll::-webkit-scrollbar { 
            width: 4px; 
        }

        .custom-scroll::-webkit-scrollbar-thumb { 
            background: #E5E7EB; 
            border-radius: 4px; 
        }

        /* =========================
           TOP NAVBAR
        ========================== */

        .top-navbar{
            width:100%;
            height:60px;
            background:#fff;
            border-bottom:1px solid #e5e7eb;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:0 18px;
            position:relative;
            z-index:90;
        }

        .top-left{
            display:flex;
            align-items:center;
            gap:14px;
        }

        .top-right{
            display:flex;
            align-items:center;
            gap:12px;
        }

        .top-nav-btn{
            width:42px;
            height:42px;
            border-radius:12px;
            border:1px solid #ececec;
            background:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            cursor:pointer;
            transition:0.3s;
            color:#555;
            font-size:15px;
        }

        .top-nav-btn:hover{
            background:#111;
            color:#fff;
            transform:translateY(-2px);
        }

        .logout-btn{
            padding:11px 18px;
            border-radius:12px;
            background:#111;
            color:#fff;
            font-size:13px;
            font-weight:600;
            text-decoration:none;
            transition:0.3s;
            display:flex;
            align-items:center;
            gap:8px;
        }

        .logout-btn:hover{
            background:#EBBB15;
            color:#111;
        }

        /* Notification Modal */

        .notification-modal{
            position:fixed;
            top:0;
            left:0;
            width:100%;
            height:100%;
            background:rgba(0,0,0,0.45);
            backdrop-filter:blur(4px);
            z-index:9999;
            display:none;
            align-items:center;
            justify-content:center;
            padding:15px;
        }

        .notification-modal.active{
            display:flex;
        }

        .notification-box{
            width:100%;
            max-width:500px;
            background:#fff;
            border-radius:20px;
            overflow:hidden;
            animation:popup 0.3s ease;
            box-shadow:0 25px 60px rgba(0,0,0,0.2);
        }

        @keyframes popup{
            from{
                opacity:0;
                transform:scale(0.9);
            }
            to{
                opacity:1;
                transform:scale(1);
            }
        }

        .notification-header{
            padding:18px 22px;
            border-bottom:1px solid #eee;
            display:flex;
            align-items:center;
            justify-content:space-between;
        }

        .notification-body{
            height:450px;
            overflow:hidden;
        }

        .notification-body iframe{
            width:100%;
            height:100%;
            border:none;
        }

        .close-modal{
            width:38px;
            height:38px;
            border-radius:10px;
            background:#f3f4f6;
            border:none;
            cursor:pointer;
            font-size:16px;
            transition:0.3s;
        }

        .close-modal:hover{
            background:#111;
            color:#fff;
        }

        /* Mobile */

        @media (max-width: 768px) {

            #sidebar {
                position: fixed;
                left: -280px;
                top: 0;
                z-index: 999;
                height: 100%;
                transition:0.4s;
            }

            #sidebar.open {
                left: 0;
                width: var(--sidebar-width);
            }

            #sidebar.open .sidebar-brand-text {
                display: block !important;
            }

            .top-navbar{
                padding:0 10px;
            }

            .top-right{
                gap:8px;
            }

            .top-nav-btn{
                width:38px;
                height:38px;
                border-radius:10px;
            }

            .logout-btn span{
                display:none;
            }

            .logout-btn{
                width:42px;
                height:42px;
                padding:0;
                justify-content:center;
            }

            .notification-box{
                height:80vh;
            }

            .notification-body{
                height:calc(80vh - 70px);
            }
        }
    </style>
</head>

<body class="h-screen flex overflow-hidden">

    <!-- SIDEBAR -->

    <aside id="sidebar" class="flex flex-col border-r border-neutral-900 shadow-xl shrink-0" data-aos="fade-right" data-aos-duration="600">

        <div class="h-20 flex items-center justify-between px-5 border-b border-neutral-900 bg-neutral-950/40 backdrop-blur-sm">
            <div class="flex items-center gap-3 overflow-hidden">
                <div class="w-9 h-9 bg-neutral-900 border border-neutral-800 rounded-lg flex items-center justify-center shrink-0 shadow-md">
                    <img src="../Assets/icon.png" alt="Logo" class="w-6 h-6 object-contain">
                </div>

                <div class="sidebar-brand-text overflow-hidden">
                    <h1 class="font-medium text-sm text-neutral-200 tracking-tight leading-none">GLAMOUR_POS</h1>
                    <span class="text-[9px] text-[#EBBB15] tracking-widest uppercase opacity-80">Salon Engine</span>
                </div>
            </div>

            <button id="collapse-toggle" class="w-8 h-8 rounded-lg bg-neutral-900 border border-neutral-800/80 text-gray-400 hover:text-white hover:bg-neutral-850 transition flex items-center justify-center shadow-inner shrink-0">
                <i class="fa-solid fa-bars-staggered text-xs"></i>
            </button>
        </div>

        <nav class="flex-grow overflow-y-auto px-1 py-4 custom-scroll">

            <a href="dashboard.php" class="nav-link active mb-4" id="dashboard-root" target="content-frame">
                <i class="fa-solid fa-chart-pie w-5 text-sm"></i>
                <span class="sidebar-brand-text ml-1">Terminal Dashboard</span>
            </a>

            <div class="section-label sidebar-brand-text">Counter Desk</div>

            <div class="menu-item">
                <div class="menu-trigger" onclick="toggleMenu('billing-group', this)">
                    <i class="fa-solid fa-file-invoice-dollar w-5"></i>
                    <span class="sidebar-brand-text flex-grow ml-1">Billing Hub</span>
                    <i class="fa-solid fa-chevron-down chevron-icon sidebar-brand-text"></i>
                </div>

                <div id="billing-group" class="sub-menu">
                    <a href="billing_new.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                        <i class="fa-solid fa-plus w-4 mr-2"></i>New Invoice
                    </a>

                    <!-- <a href="edit_invoice.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                        <i class="fa-solid fa-pen-check w-4 mr-2"></i>Edit Invoice
                    </a> -->

                    <a href="billing_history.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                        <i class="fa-solid fa-list-check w-4 mr-2"></i>Edit Invoice
                    </a>
                </div>
            </div>

            <div class="section-label sidebar-brand-text">Salon Structure</div>

            <div class="menu-item">
                <div class="menu-trigger" onclick="toggleMenu('services-group', this)">
                    <i class="fa-solid fa-scissors w-5"></i>
                    <span class="sidebar-brand-text flex-grow ml-1">Services Lounge</span>
                    <i class="fa-solid fa-chevron-down chevron-icon sidebar-brand-text"></i>
                </div>

                <div id="services-group" class="sub-menu">
                    <a href="service_manage.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                        <i class="fa-solid fa-wand-magic-sparkles w-4 mr-2"></i>Service Menu
                    </a>

                    <a href="category_manage.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                        <i class="fa-solid fa-layer-group w-4 mr-2"></i>Service Groups
                    </a>
                </div>
            </div>

            <div class="menu-item">
                <div class="menu-trigger" onclick="toggleMenu('inventory-group', this)">
                    <i class="fa-solid fa-box-open w-5"></i>
                    <span class="sidebar-brand-text flex-grow ml-1">Products & Stock</span>
                    <i class="fa-solid fa-chevron-down chevron-icon sidebar-brand-text"></i>
                </div>

                <div id="inventory-group" class="sub-menu">
                    <a href="products.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                        <i class="fa-solid fa-box w-4 mr-2"></i>Retail Products
                    </a>

                    <a href="stock_ledger.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                        <i class="fa-solid fa-warehouse w-4 mr-2"></i>Internal Stock
                    </a>
                </div>
            </div>

            <div class="menu-item">
    <div class="menu-trigger" onclick="toggleMenu('subscription-group', this)">
        <i class="fa-solid fa-crown w-5"></i>
        <span class="sidebar-brand-text flex-grow ml-1">Subscriptions</span>
        <i class="fa-solid fa-chevron-down chevron-icon sidebar-brand-text"></i>
    </div>

    <div id="subscription-group" class="sub-menu">
        <a href="subscriptions.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
            <i class="fa-solid fa-list-check w-4 mr-2"></i>Manage Subscriptions
        </a>
        <a href="issue_membership.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
            <i class="fa-solid fa-list-check w-4 mr-2"></i>Issue Membership
        </a>

        <a href="redeemed_subscriptions.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
            <i class="fa-solid fa-receipt w-4 mr-2"></i>Redeemed Subscriptions
        </a>
    </div>
</div>

            <div class="section-label sidebar-brand-text">Workforce</div>

            <div class="menu-item">
                <div class="menu-trigger" onclick="toggleMenu('team-group', this)">
                    <i class="fa-solid fa-user-group w-5"></i>
                    <span class="sidebar-brand-text flex-grow ml-1">Stylist Registry</span>
                    <i class="fa-solid fa-chevron-down chevron-icon sidebar-brand-text"></i>
                </div>

                <div id="team-group" class="sub-menu">
                    <a href="stylists.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                        <i class="fa-solid fa-id-card w-4 mr-2"></i>Personnel Profile
                    </a>

                    <a href="commission_ledger.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                        <i class="fa-solid fa-percent w-4 mr-2"></i>Commission Split
                    </a>
                </div>
            </div>

            <div class="section-label sidebar-brand-text">System Settings</div>

            <a href="expenses.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                <i class="fa-solid fa-wallet w-5"></i>
                <span class="sidebar-brand-text ml-1">Expenses</span>
            </a>
            <a href="manage_manager.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                <i class="fa-solid fa-wallet w-5"></i>
                <span class="sidebar-brand-text ml-1">Manage Manager</span>
            </a>

            <a href="customers.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                <i class="fa-solid fa-users w-5"></i>
                <span class="sidebar-brand-text ml-1">Customers</span>
            </a>
            <a href="manage_loyalty.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                <i class="fa-solid fa-users w-5"></i>
                <span class="sidebar-brand-text ml-1">Loyalty Program</span>
            </a>

            <a href="security_settings.php" target="content-frame" class="nav-link" onclick="handleNavigation(this)">
                <i class="fa-solid fa-gears w-5"></i>
                <span class="sidebar-brand-text ml-1">Security Settings</span>
            </a>
        </nav>

        <div class="p-4 border-t border-neutral-900 bg-neutral-950/20">
            <div class="bg-[#1e1e1e] border border-neutral-800/80 p-3 rounded-xl flex items-center gap-3 shadow-md">
                <img src="https://ui-avatars.com/api/?name=Admin&background=EBBB15&color=121212" class="w-8 h-8 rounded-lg shadow-sm">

                <div class="flex-grow overflow-hidden sidebar-brand-text">
                    <p class="text-[10px] font-medium text-[#EBBB15] uppercase tracking-wider leading-none mb-1">Administrator</p>
                    <a href="logout.php" class="text-[11px] text-red-400 hover:text-red-300 font-medium transition">
                        Terminate Connection
                    </a>
                </div>
            </div>
        </div>

    </aside>

    <!-- MAIN AREA -->

    <div class="flex-1 flex flex-col min-w-0 bg-white" data-aos="fade-in" data-aos-duration="800">

        <!-- TOP NAVBAR -->

        <div class="top-navbar">

            <div class="top-left">
                <button id="mobile-menu-btn" class="top-nav-btn">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>

            <div class="top-right">

                <!-- Fullscreen -->

                <button class="top-nav-btn" id="fullscreen-btn">
                    <i class="fa-solid fa-expand"></i>
                </button>

                <!-- Notification -->

                <button class="top-nav-btn" id="notification-btn">
                    <i class="fa-solid fa-bell"></i>
                </button>

                <!-- User -->

                <button class="top-nav-btn">
                    <i class="fa-solid fa-user"></i>
                </button>

                <!-- Logout -->

                <a href="logout.php" class="logout-btn">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>

            </div>
        </div>

        <!-- CONTENT -->

        <main class="flex-1 p-3 bg-gray-50/50">
            <div class="w-full h-full rounded-2xl overflow-hidden main-workspace shadow-sm bg-white">
                <iframe id="content-frame" name="content-frame" src="dashboard.php" class="w-full h-full border-0"></iframe>
            </div>
        </main>
    </div>

    <!-- NOTIFICATION MODAL -->

    <div class="notification-modal" id="notification-modal">

        <div class="notification-box">

            <div class="notification-header">
                <h2 class="text-lg font-semibold">Notifications</h2>

                <button class="close-modal" id="close-modal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="notification-body">
                <iframe src="notification.php"></iframe>
            </div>

        </div>

    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>

    <script>

        AOS.init({ once: true });

        const sidebar = document.getElementById('sidebar');
        const collapseToggle = document.getElementById('collapse-toggle');
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');

        // Sidebar Toggle

        collapseToggle.addEventListener('click', () => {

            if (window.innerWidth <= 768) {

                sidebar.classList.toggle('open');

            } else {

                sidebar.classList.toggle('collapsed');

                document.querySelectorAll('.sidebar-brand-text').forEach(el => {
                    el.classList.toggle('hidden');
                });
            }
        });

        // Mobile Menu

        mobileMenuBtn.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });

        // Toggle Menu

        function toggleMenu(menuId, triggerElement) {

            if(sidebar.classList.contains('collapsed')) {

                sidebar.classList.remove('collapsed');

                document.querySelectorAll('.sidebar-brand-text').forEach(el => {
                    el.classList.remove('hidden');
                });
            }

            const targetedMenu = document.getElementById(menuId);
            const parentContainer = triggerElement.parentElement;
            const targetChevron = triggerElement.querySelector('.chevron-icon');
            const visibilityStatus = targetedMenu.classList.contains('show');

            document.querySelectorAll('.sub-menu').forEach(m => m.classList.remove('show'));
            document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active-parent'));
            document.querySelectorAll('.chevron-icon').forEach(c => c.classList.remove('rotate-chevron'));

            if(!visibilityStatus) {

                targetedMenu.classList.add('show');
                parentContainer.classList.add('active-parent');

                if(targetChevron) {
                    targetChevron.classList.add('rotate-chevron');
                }
            }
        }

        // Navigation

        function handleNavigation(targetElement) {

            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });

            targetElement.classList.add('active');

            if (window.innerWidth <= 768) {
                sidebar.classList.remove('open');
            }
        }

        document.getElementById('dashboard-root').addEventListener('click', function() {

            document.querySelectorAll('.sub-menu').forEach(m => m.classList.remove('show'));
            document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active-parent'));

            handleNavigation(this);
        });

        // Notification Modal

        const notificationBtn = document.getElementById('notification-btn');
        const notificationModal = document.getElementById('notification-modal');
        const closeModal = document.getElementById('close-modal');

        notificationBtn.addEventListener('click', () => {
            notificationModal.classList.add('active');
        });

        closeModal.addEventListener('click', () => {
            notificationModal.classList.remove('active');
        });

        notificationModal.addEventListener('click', (e) => {

            if(e.target === notificationModal){
                notificationModal.classList.remove('active');
            }
        });

        // Fullscreen

        const fullscreenBtn = document.getElementById('fullscreen-btn');

        fullscreenBtn.addEventListener('click', () => {

            if (!document.fullscreenElement) {

                document.documentElement.requestFullscreen();

            } else {

                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        });

    </script>

</body>
</html>
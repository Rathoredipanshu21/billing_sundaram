<?php
session_start();
include '../config/db.php'; // Ensure path is correct for Manager/index.php -> config/db.php

// SECURITY CHECK: Redirect to login if not logged in
if (!isset($_SESSION['manager_id'])) {
    header("Location: login.php"); 
    exit();
}

$manager_id = $_SESSION['manager_id'];

// 1. Fetch allowed pages for this specific manager from DB
$allowed_pages = [];
$stmt = $conn->prepare("SELECT page_url FROM manager_page_access WHERE manager_id = ?");
$stmt->bind_param("i", $manager_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $allowed_pages[] = $row['page_url'];
}
$stmt->close();

// Fetch manager details for the UI profile box
$mgr_stmt = $conn->prepare("SELECT name, email FROM managers WHERE id = ?");
$mgr_stmt->bind_param("i", $manager_id);
$mgr_stmt->execute();
$manager_data = $mgr_stmt->get_result()->fetch_assoc();
$manager_name = $manager_data['name'];
$mgr_stmt->close();

// 2. Define the complete system map (matching the admin allocations)
// Grouped by Sidebar Categories to match the dark theme UI
$system_map = [
    'COUNTER DESK' => [
        'Billing Hub' => [
            'icon' => 'fa-file-invoice-dollar',
            'links' => [
                'billing_new.php' => 'New Invoice',
                'billing_history.php' => 'Billing History'
            ]
        ]
    ],
    'SALON STRUCTURE' => [
        'Services Lounge' => [
            'icon' => 'fa-scissors',
            'links' => [
                'service_manage.php' => 'Service Menu',
                'category_manage.php' => 'Service Groups'
            ]
        ],
        'Products & Stock' => [
            'icon' => 'fa-box-open',
            'links' => [
                'products.php' => 'Retail Products',
                'stock_ledger.php' => 'Internal Stock'
            ]
        ],
        'Subscriptions' => [
            'icon' => 'fa-crown',
            'links' => [
                'subscriptions.php' => 'Manage Subscriptions',
                'issue_membership.php' => 'Issue Membership',
                'redeemed_subscriptions.php' => 'Redeemed Subscriptions'
            ]
        ]
    ],
    'WORKFORCE' => [
        'Stylist Registry' => [
            'icon' => 'fa-users',
            'links' => [
                'stylists.php' => 'Personnel Profile',
                'commission_ledger.php' => 'Commission Split'
            ]
        ],
        'System Settings' => [
            'icon' => 'fa-gear',
            'links' => [
                'expenses.php' => 'Expenses',
                'customers.php' => 'Customers',
                'manage_loyalty.php' => 'Loyalty Program'
            ]
        ]
    ]
];

// Helper function to check if a group has any accessible links
function getAccessibleLinks($links, $allowed) {
    $accessible = [];
    foreach ($links as $url => $title) {
        if (in_array($url, $allowed)) {
            $accessible[$url] = $title;
        }
    }
    return $accessible;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salon Workspace | Manager Panel</title>
    <link rel="icon" type="image/x-icon" href="../Assets/icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F3F4F6; }
        
        /* Dark Sidebar Styles based on screenshot */
        .sidebar { background-color: #121212; width: 280px; }
        
        .nav-category { 
            font-size: 10px; 
            font-weight: 700; 
            color: #52525B; 
            letter-spacing: 1px; 
            text-transform: uppercase; 
            padding: 20px 24px 8px 24px; 
        }
        
        .menu-trigger { 
            display: flex; align-items: center; padding: 12px 24px; color: #A1A1AA; font-size: 14px; cursor: pointer; transition: 0.2s; border-left: 3px solid transparent;
        }
        .menu-trigger:hover, .menu-trigger.active-group { 
            color: #ffffff; background-color: rgba(255, 255, 255, 0.03); 
        }
        
        .sub-menu { display: none; background-color: #0A0A0A; padding: 8px 0; border-radius: 0 0 12px 12px;}
        .sub-menu.open { display: block; }
        
        .sub-link { 
            display: flex; align-items: center; padding: 10px 24px 10px 54px; color: #71717A; font-size: 13px; text-decoration: none; transition: 0.2s;
        }
        .sub-link:hover, .sub-link.active { color: #EBBB15; background-color: rgba(235, 187, 21, 0.05); }
        
        .chevron-icon { transition: transform 0.3s; font-size: 10px; margin-left: auto; }
        .menu-trigger.open .chevron-icon { transform: rotate(180deg); }
        
        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-track { background: #121212; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #3F3F46; border-radius: 10px; }

        .terminal-btn {
            background-color: #EBBB15;
            color: #121212;
            font-weight: 600;
            margin: 16px 20px;
            padding: 12px 16px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s;
            box-shadow: 0 4px 14px rgba(235, 187, 21, 0.15);
        }
        .terminal-btn:hover { background-color: #FCD34D; }

        /* User Profile Bottom */
        .bottom-profile {
            background-color: #1A1A1A;
            margin: 16px;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid #27272A;
            display: flex;
            align-items: center;
            gap: 12px;
        }
    </style>
</head>
<body class="h-screen flex overflow-hidden">

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-20 hidden lg:hidden transition-opacity" onclick="toggleSidebar()"></div>

    <aside id="sidebar" class="sidebar flex flex-col shrink-0 z-30 shadow-2xl absolute inset-y-0 left-0 transform -translate-x-full lg:relative lg:translate-x-0 transition-transform duration-300 ease-in-out">
        
        <div class="h-[72px] flex items-center px-6 border-b border-[#27272A] gap-3 relative">
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-[#EBBB15]">
                <i class="fa-solid fa-gem text-xl drop-shadow-md"></i>
            </div>
            <div>
                <h1 class="font-bold text-white tracking-wide text-[15px] leading-tight">GLAMOUR_POS</h1>
                <p class="text-[#EBBB15] text-[10px] font-semibold tracking-widest uppercase">Salon Engine</p>
            </div>
            <button onclick="toggleSidebar()" class="lg:hidden absolute right-4 text-gray-400 hover:text-white bg-[#1A1A1A] p-2 rounded-lg border border-[#27272A]">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <nav class="flex-grow overflow-y-auto custom-scroll pb-4">
            
            <a href="../admin/dashboard.php" class="terminal-btn" target="content-frame" onclick="closeSidebarOnMobile()">
                <i class="fa-solid fa-chart-pie text-lg"></i>
                Terminal Dashboard
            </a>

            <?php foreach ($system_map as $category_name => $groups): ?>
                
                <?php 
                $category_has_access = false;
                $rendered_groups = "";

                foreach ($groups as $group_name => $group_data) {
                    $accessible_links = getAccessibleLinks($group_data['links'], $allowed_pages);
                    
                    if (!empty($accessible_links)) {
                        $category_has_access = true;
                        $group_id = strtolower(str_replace([' ', '&'], ['-', ''], $group_name));
                        
                        $rendered_groups .= '
                        <div class="menu-item border-b border-[#1A1A1A]">
                            <div class="menu-trigger" onclick="toggleMenu(\''.$group_id.'\', this)">
                                <i class="fa-solid '.$group_data['icon'].' w-6 text-center mr-3"></i>
                                <span class="flex-grow">'.$group_name.'</span>
                                <i class="fa-solid fa-chevron-down chevron-icon"></i>
                            </div>
                            <div id="'.$group_id.'" class="sub-menu shadow-inner">';
                        
                        foreach ($accessible_links as $url => $title) {
                            $rendered_groups .= '
                                <a href="../admin/'.$url.'" target="content-frame" class="sub-link nav-link" onclick="closeSidebarOnMobile()">
                                    <i class="fa-solid fa-plus mr-3 text-[10px] text-[#52525B]"></i> '.$title.'
                                </a>';
                        }
                        
                        $rendered_groups .= '</div></div>';
                    }
                }

                if ($category_has_access): 
                ?>
                    <div class="nav-category"><?= $category_name ?></div>
                    <?= $rendered_groups ?>
                <?php endif; ?>

            <?php endforeach; ?>
            
        </nav>

        <div class="mt-auto border-t border-[#27272A]">
            <div class="bottom-profile">
                <div class="w-10 h-10 bg-[#EBBB15] rounded-lg flex items-center justify-center font-bold text-[#121212] text-sm shrink-0">
                    <?= strtoupper(substr($manager_name, 0, 2)) ?>
                </div>
                <div class="flex-grow overflow-hidden">
                    <p class="text-[10px] text-[#EBBB15] font-bold tracking-widest uppercase truncate">Manager: <?= htmlspecialchars($manager_name) ?></p>
                    <a href="logout.php" class="text-xs text-red-500 hover:text-red-400 font-medium transition truncate block mt-0.5">
                        Terminate Connection
                    </a>
                </div>
            </div>
        </div>
    </aside>

    <main class="flex-grow flex flex-col h-full overflow-hidden bg-[#F4F7FE] relative w-full">
        
        <header class="h-[72px] bg-white border-b border-gray-200 flex items-center justify-between px-4 sm:px-6 shrink-0 z-10 shadow-sm">
            
            <div class="flex items-center gap-3 sm:gap-4">
                <button onclick="toggleSidebar()" class="lg:hidden bg-gray-50 border border-gray-200 text-gray-600 p-2 sm:p-2.5 rounded-lg hover:bg-gray-100 transition shadow-sm">
                    <i class="fa-solid fa-bars"></i>
                </button>
                
                <div class="flex items-center gap-2 border-l-2 border-[#EBBB15] pl-3">
                    <i class="fa-solid fa-user-shield text-[#EBBB15] hidden sm:block"></i>
                    <h2 class="text-lg sm:text-xl font-bold text-gray-800 tracking-tight whitespace-nowrap">Manager Portal</h2>
                </div>
            </div>

            <div class="flex items-center gap-2 sm:gap-4">
                <button class="hidden sm:flex w-9 h-9 sm:w-10 sm:h-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 hover:bg-gray-50 transition" title="Fullscreen">
                    <i class="fa-solid fa-expand"></i>
                </button>
                <button class="w-9 h-9 sm:w-10 sm:h-10 flex items-center justify-center rounded-full border border-gray-200 text-gray-500 hover:bg-gray-50 transition relative" title="Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <span class="absolute top-1 sm:top-2 right-1 sm:right-2 w-2 h-2 bg-red-500 rounded-full border border-white"></span>
                </button>
                <div class="hidden sm:flex w-10 h-10 bg-gray-100 rounded-full items-center justify-center border border-gray-200 text-gray-600">
                    <i class="fa-solid fa-user"></i>
                </div>
                <a href="logout.php" class="bg-gray-900 hover:bg-black text-white px-3 sm:px-5 py-2 sm:py-2.5 rounded-lg text-xs sm:text-sm font-medium flex items-center gap-2 transition shadow-md whitespace-nowrap">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> <span class="hidden sm:inline">Logout</span>
                </a>
            </div>
        </header>

        <div class="flex-grow relative w-full h-full">
            <iframe name="content-frame" src="../admin/dashboard.php" class="absolute inset-0 w-full h-full border-none bg-transparent" title="Main Content Area"></iframe>
        </div>
    </main>

    <script>
        // Sidebar Toggle Logic for Mobile Responsiveness
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }

        // Close sidebar automatically when a link is clicked on mobile devices
        function closeSidebarOnMobile() {
            if (window.innerWidth < 1024) { // lg breakpoint in Tailwind
                toggleSidebar();
            }
        }

        // Dropdown Toggle Logic
        function toggleMenu(menuId, triggerElement) {
            const subMenu = document.getElementById(menuId);
            const isOpening = !subMenu.classList.contains('open');

            subMenu.classList.toggle('open');
            triggerElement.classList.toggle('open');
            triggerElement.classList.toggle('active-group');
            
            if(isOpening) {
                triggerElement.style.borderLeftColor = '#EBBB15';
            } else {
                triggerElement.style.borderLeftColor = 'transparent';
            }
        }

        // Active State Link Logic for sub-links
        const links = document.querySelectorAll('.nav-link');
        links.forEach(link => {
            link.addEventListener('click', function() {
                links.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>
<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: Login.php");
    exit();
}

// Map out all the controllable pages in your system
$system_pages = [
    'Billing Hub' => [
        'billing_new.php' => 'New Invoice',
        'billing_history.php' => 'Billing History'
    ],
    'Services Lounge' => [
        'service_manage.php' => 'Service Menu',
        'category_manage.php' => 'Service Groups'
    ],
    'Products & Stock' => [
        'products.php' => 'Retail Products',
        'stock_ledger.php' => 'Internal Stock'
    ],
    'Subscriptions' => [
        'subscriptions.php' => 'Manage Subscriptions',
        'issue_membership.php' => 'Issue Membership',
        'redeemed_subscriptions.php' => 'Redeemed Subscriptions'
    ],
    'Stylist Registry' => [
        'stylists.php' => 'Personnel Profile',
        'commission_ledger.php' => 'Commission Split'
    ],
    'System Settings' => [
        'expenses.php' => 'Expenses',
        'customers.php' => 'Customers',
        'manage_loyalty.php' => 'Loyalty Program'
    ]
];

$success_msg = "";

// Handle Manager Creation & Allocation
if (isset($_POST['create_manager'])) {
    $name = $_POST['name'];
    $contact = $_POST['contact'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT); 

    // Insert Manager
    $stmt = $conn->prepare("INSERT INTO managers (name, contact, email, password) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $contact, $email, $password);
    
    if ($stmt->execute()) {
        $new_manager_id = $conn->insert_id;
        $allowed_pages = isset($_POST['pages']) ? $_POST['pages'] : [];

        // Insert Permissions
        if (!empty($allowed_pages)) {
            $stmt_access = $conn->prepare("INSERT INTO manager_page_access (manager_id, page_url) VALUES (?, ?)");
            foreach ($allowed_pages as $page_url) {
                $stmt_access->bind_param("is", $new_manager_id, $page_url);
                $stmt_access->execute();
            }
        }
        $success_msg = "Manager Created & Allocated Successfully!";
    }
}

// Handle Page Re-Allocation (Editing existing manager)
if (isset($_POST['edit_allocations'])) {
    $manager_id = $_POST['manager_id'];
    $allowed_pages = isset($_POST['pages']) ? $_POST['pages'] : [];

    // Clear existing
    $stmt = $conn->prepare("DELETE FROM manager_page_access WHERE manager_id = ?");
    $stmt->bind_param("i", $manager_id);
    $stmt->execute();

    // Insert new
    if (!empty($allowed_pages)) {
        $stmt = $conn->prepare("INSERT INTO manager_page_access (manager_id, page_url) VALUES (?, ?)");
        foreach ($allowed_pages as $page_url) {
            $stmt->bind_param("is", $manager_id, $page_url);
            $stmt->execute();
        }
    }
    $success_msg = "Access permissions updated successfully!";
}

// Handle Manager Deletion
if (isset($_POST['delete_manager'])) {
    $manager_id = $_POST['manager_id'];
    $stmt = $conn->prepare("DELETE FROM managers WHERE id = ?");
    $stmt->bind_param("i", $manager_id);
    $stmt->execute();
    $success_msg = "Manager account removed.";
}

// Fetch all managers for the grid
$managers_result = $conn->query("SELECT * FROM managers ORDER BY created_at DESC");
$managers = [];
while ($row = $managers_result->fetch_assoc()) {
    $managers[] = $row;
}

// Helper to get access list for a specific manager
function getManagerAccess($conn, $manager_id) {
    $access = [];
    $res = $conn->query("SELECT page_url FROM manager_page_access WHERE manager_id = $manager_id");
    while($row = $res->fetch_assoc()){
        $access[] = $row['page_url'];
    }
    return $access;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Allocations | Salon Workspace</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />

    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F9FAFB; }
        .modal-overlay {
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 999;
        }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #D1D5DB; border-radius: 4px; }
    </style>
</head>
<body class="p-6">

    <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center mb-8" data-aos="fade-down" data-aos-duration="600">
        <div>
            <h1 class="text-2xl font-bold text-gray-800"><i class="fa-solid fa-user-tie mr-2 text-[#EBBB15]"></i>Manager Administration</h1>
            <p class="text-sm text-gray-500 mt-1">Create managers and control their system access</p>
        </div>
        <button onclick="openModal('addManagerModal')" class="mt-4 md:mt-0 bg-[#121212] text-[#EBBB15] hover:bg-black px-5 py-2.5 rounded-xl font-medium shadow-lg transition duration-200 flex items-center gap-2">
            <i class="fa-solid fa-plus"></i> Add New Manager
        </button>
    </div>

    <?php if(!empty($success_msg)): ?>
        <div class="max-w-7xl mx-auto mb-6 bg-green-50 border-l-4 border-green-500 p-4 rounded-r-lg shadow-sm flex items-center gap-3" data-aos="fade-in">
            <i class="fa-solid fa-circle-check text-green-500 text-xl"></i>
            <p class="text-green-700 font-medium"><?= $success_msg ?></p>
        </div>
    <?php endif; ?>

    <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($managers as $index => $mgr): 
            $mgr_access = getManagerAccess($conn, $mgr['id']);
        ?>
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex flex-col relative" data-aos="fade-up" data-aos-delay="<?= $index * 100 ?>">
            
            <div class="flex items-center gap-4 mb-5">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($mgr['name']) ?>&background=EBBB15&color=121212&bold=true" class="w-14 h-14 rounded-full shadow-sm">
                <div>
                    <h3 class="text-lg font-bold text-gray-800 leading-tight"><?= htmlspecialchars($mgr['name']) ?></h3>
                    <p class="text-xs text-gray-500 uppercase tracking-wide font-semibold mt-1">ID: MGR-<?= str_pad($mgr['id'], 3, '0', STR_PAD_LEFT) ?></p>
                </div>
            </div>

            <div class="space-y-3 mb-6 flex-grow">
                <div class="flex items-center gap-3 text-sm text-gray-600">
                    <div class="w-6 flex justify-center"><i class="fa-solid fa-envelope text-gray-400"></i></div>
                    <?= htmlspecialchars($mgr['email']) ?>
                </div>
                <div class="flex items-center gap-3 text-sm text-gray-600">
                    <div class="w-6 flex justify-center"><i class="fa-solid fa-phone text-gray-400"></i></div>
                    <?= htmlspecialchars($mgr['contact']) ?>
                </div>
                <div class="flex items-center gap-3 text-sm text-gray-600">
                    <div class="w-6 flex justify-center"><i class="fa-solid fa-shield-halved text-[#EBBB15]"></i></div>
                    <span class="bg-yellow-50 text-yellow-700 px-2.5 py-0.5 rounded-full font-medium text-xs border border-yellow-200">
                        <?= count($mgr_access) ?> Pages Allocated
                    </span>
                </div>
            </div>

            <div class="flex gap-2 mt-auto border-t pt-4">
                <button onclick="openModal('editModal-<?= $mgr['id'] ?>')" class="flex-1 bg-gray-50 hover:bg-gray-100 text-gray-700 border border-gray-200 py-2 rounded-lg text-sm font-medium transition">
                    <i class="fa-solid fa-pen-to-square mr-1"></i> Edit Access
                </button>
                <form method="POST" onsubmit="return confirm('Are you sure you want to remove this manager completely?');">
                    <input type="hidden" name="manager_id" value="<?= $mgr['id'] ?>">
                    <button type="submit" name="delete_manager" class="w-10 h-full flex items-center justify-center bg-red-50 hover:bg-red-100 text-red-500 border border-red-100 rounded-lg transition" title="Delete Manager">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </form>
            </div>
        </div>

        <div id="editModal-<?= $mgr['id'] ?>" class="fixed inset-0 flex items-center justify-center modal-overlay hidden">
            <div class="bg-white w-full max-w-3xl rounded-2xl shadow-2xl overflow-hidden m-4 flex flex-col max-h-[90vh]">
                
                <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                    <h2 class="text-lg font-bold text-gray-800"><i class="fa-solid fa-sliders text-[#EBBB15] mr-2"></i>Edit Access: <?= htmlspecialchars($mgr['name']) ?></h2>
                    <button onclick="closeModal('editModal-<?= $mgr['id'] ?>')" class="text-gray-400 hover:text-red-500 transition"><i class="fa-solid fa-xmark text-xl"></i></button>
                </div>
                
                <div class="p-6 overflow-y-auto custom-scrollbar">
                    <form method="POST">
                        <input type="hidden" name="manager_id" value="<?= $mgr['id'] ?>">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <?php foreach($system_pages as $group_name => $pages): ?>
                                <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
                                    <h3 class="font-semibold text-gray-800 mb-3 text-sm border-b pb-2"><?= $group_name ?></h3>
                                    <div class="space-y-2">
                                        <?php foreach($pages as $url => $title): 
                                            $is_checked = in_array($url, $mgr_access) ? 'checked' : '';
                                        ?>
                                            <label class="flex items-center space-x-3 cursor-pointer group">
                                                <input type="checkbox" name="pages[]" value="<?= $url ?>" <?= $is_checked ?> class="w-4 h-4 text-[#EBBB15] bg-gray-100 border-gray-300 rounded focus:ring-[#EBBB15]">
                                                <span class="text-sm text-gray-600 group-hover:text-black transition"><?= $title ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-8 flex justify-end gap-3">
                            <button type="button" onclick="closeModal('editModal-<?= $mgr['id'] ?>')" class="px-5 py-2 text-gray-600 hover:bg-gray-100 rounded-lg font-medium transition">Cancel</button>
                            <button type="submit" name="edit_allocations" class="px-6 py-2 bg-[#EBBB15] text-black font-semibold rounded-lg hover:bg-yellow-500 shadow-md transition">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if(empty($managers)): ?>
            <div class="col-span-full bg-white border-2 border-dashed border-gray-200 rounded-2xl py-12 flex flex-col items-center justify-center text-gray-400" data-aos="fade-in">
                <i class="fa-solid fa-users-slash text-4xl mb-3"></i>
                <p>No managers have been created yet.</p>
            </div>
        <?php endif; ?>
    </div>

    <div id="addManagerModal" class="fixed inset-0 flex items-center justify-center modal-overlay hidden">
        <div class="bg-white w-full max-w-4xl rounded-2xl shadow-2xl overflow-hidden m-4 flex flex-col max-h-[90vh]">
            
            <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <h2 class="text-lg font-bold text-gray-800"><i class="fa-solid fa-user-plus text-[#EBBB15] mr-2"></i>Create New Manager & Allocate Pages</h2>
                <button onclick="closeModal('addManagerModal')" class="text-gray-400 hover:text-red-500 transition"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scrollbar">
                <form method="POST">
                    
                    <div class="mb-8">
                        <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4 border-b pb-2">1. Manager Details</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Full Name</label>
                                <input type="text" name="name" required class="w-full border border-gray-300 px-4 py-2.5 rounded-lg focus:outline-none focus:border-[#EBBB15] focus:ring-1 focus:ring-[#EBBB15] transition">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Contact Number</label>
                                <input type="text" name="contact" required class="w-full border border-gray-300 px-4 py-2.5 rounded-lg focus:outline-none focus:border-[#EBBB15] focus:ring-1 focus:ring-[#EBBB15] transition">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Email Address (Login ID)</label>
                                <input type="email" name="email" required class="w-full border border-gray-300 px-4 py-2.5 rounded-lg focus:outline-none focus:border-[#EBBB15] focus:ring-1 focus:ring-[#EBBB15] transition">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Account Password</label>
                                <input type="password" name="password" required class="w-full border border-gray-300 px-4 py-2.5 rounded-lg focus:outline-none focus:border-[#EBBB15] focus:ring-1 focus:ring-[#EBBB15] transition">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4 border-b pb-2">2. System Access Setup</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <?php foreach($system_pages as $group_name => $pages): ?>
                                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 shadow-sm">
                                    <h4 class="font-semibold text-gray-800 mb-3 text-sm"><?= $group_name ?></h4>
                                    <div class="space-y-2">
                                        <?php foreach($pages as $url => $title): ?>
                                            <label class="flex items-center space-x-3 cursor-pointer group">
                                                <input type="checkbox" name="pages[]" value="<?= $url ?>" class="w-4 h-4 text-[#EBBB15] bg-white border-gray-300 rounded focus:ring-[#EBBB15]">
                                                <span class="text-sm text-gray-600 group-hover:text-black transition"><?= $title ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mt-8 flex justify-end gap-3 border-t pt-5">
                        <button type="button" onclick="closeModal('addManagerModal')" class="px-5 py-2.5 text-gray-600 hover:bg-gray-100 rounded-lg font-medium transition">Cancel</button>
                        <button type="submit" name="create_manager" class="px-6 py-2.5 bg-[#121212] text-[#EBBB15] font-semibold rounded-lg hover:bg-black shadow-lg transition flex items-center gap-2">
                            <i class="fa-solid fa-check"></i> Create & Allocate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        // Initialize Animate on Scroll
        AOS.init({ once: true });

        // Modal Logic
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('hidden');
            // Slight delay to allow display:block to apply before animating opacity if desired, 
            // but Tailwind handles it well enough with raw classes.
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('hidden');
        }

        // Close modal if clicking outside the white box
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.classList.add('hidden');
            }
        }
    </script>
</body>
</html>
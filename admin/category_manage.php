<?php
session_start();
include '../config/db.php'; 

$message = '';
$messageType = '';

// Handle Add Category Form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    $category_name = mysqli_real_escape_string($conn, $_POST['category_name']);
    $category_code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['category_code']));
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    // Check for duplicate keys safely with error fallback interception
    $check_stmt = $conn->prepare("SELECT id FROM categories WHERE category_name = ? OR category_code = ?");
    
    if (!$check_stmt) {
        die("<div style='font-family:sans-serif; padding:20px; background:#fff1f2; color:#e11d48; border:1px solid #ffe4e6; border-radius:12px; margin:20px;'>
                <strong>SQL Preparation Failed:</strong> " . htmlspecialchars($conn->error) . "<br><br>
                <em>Tip: Please ensure you have created the 'categories' table in your database execution terminal.</em>
             </div>");
    }

    $check_stmt->bind_param("ss", $category_name, $category_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $message = "Category name or tracking code already exists in the registry.";
        $messageType = "error";
    } else {
        $sql = "INSERT INTO categories (category_name, category_code, status) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sss", $category_name, $category_code, $status);
            if ($stmt->execute()) {
                $message = "Service group successfully registered in the workspace system.";
                $messageType = "success";
            } else {
                $message = "System error mapping database record.";
                $messageType = "error";
            }
            $stmt->close();
        }
    }
    $check_stmt->close();
}

// Fetch all category data elements safely
$categoriesResult = $conn->query("SELECT * FROM categories ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Groups Management Menu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #FFFFFF;
        }
        .modal-blur-bg {
            background-color: rgba(26, 26, 26, 0.4);
            backdrop-filter: blur(4px);
        }
        .accent-focus:focus {
            border-color: #EBBB15;
            box-shadow: 0 0 0 3px rgba(235, 187, 21, 0.15);
        }
        .custom-scroll::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 4px; }
    </style>
</head>
<body class="p-4 lg:p-6 min-h-screen">

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-8 pb-4 border-b border-gray-100" data-aos="fade-down" data-aos-duration="600">
        <div>
            <h2 class="text-base font-medium text-neutral-800 tracking-tight flex items-center gap-2">
                <i class="fa-solid fa-layer-group text-amber-500"></i>
                <span>Service Groups Workspace</span>
            </h2>
            <p class="text-[11px] text-gray-400 uppercase tracking-wider mt-0.5">Manage operational categorization matrices</p>
        </div>
        
        <button id="openModalBtn" class="bg-[#222222] hover:bg-neutral-800 text-[#EBBB15] px-4 py-2.5 rounded-xl text-xs font-medium transition flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-folder-plus"></i>
            <span>Create New Category</span>
        </button>
    </div>

    <?php if (!empty($message)): ?>
        <div class="mb-6 p-3 rounded-xl text-xs flex items-center gap-2 max-w-xl mx-auto <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-rose-50 text-rose-700 border border-rose-100'; ?>" data-aos="fade-in">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo $message; ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-200/70 rounded-2xl shadow-sm overflow-hidden" data-aos="fade-up" data-aos-duration="800">
        <div class="overflow-x-auto custom-scroll">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50/70 border-b border-gray-100">
                        <th class="px-6 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">System Index</th>
                        <th class="px-6 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Category Name</th>
                        <th class="px-6 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Tracking Code</th>
                        <th class="px-6 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-6 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Date Configured</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-xs text-neutral-700">
                    <?php if ($categoriesResult && $categoriesResult->num_rows > 0): ?>
                        <?php $counter = 1; while($row = $categoriesResult->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50/40 transition">
                                <td class="px-6 py-4 text-gray-400">#<?php echo str_pad($counter++, 3, '0', STR_PAD_LEFT); ?></td>
                                <td class="px-6 py-4 font-medium text-neutral-800"><?php echo htmlspecialchars($row['category_name']); ?></td>
                                <td class="px-6 py-4">
                                    <span class="bg-gray-100 border border-gray-200 text-neutral-600 px-2 py-0.5 rounded text-[10px] font-mono uppercase">
                                        <?php echo htmlspecialchars($row['category_code']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-medium uppercase <?php echo $row['status'] === 'Active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'; ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?php echo $row['status'] === 'Active' ? 'bg-emerald-500' : 'bg-gray-400'; ?>"></span>
                                        <?php echo $row['status']; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-gray-400"><?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="py-12 bg-gray-50/30 border-none text-center">
                                <div class="w-10 h-10 rounded-xl bg-gray-100 text-gray-400 flex items-center justify-center mb-2 mx-auto">
                                    <i class="fa-solid fa-folder-open text-sm"></i>
                                </div>
                                <h4 class="text-[11px] font-medium text-neutral-600 uppercase tracking-wider">No Groups Registered</h4>
                                <p class="text-xs text-gray-400 mt-0.5">Initialize business departments by creating a category group layout structure.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="categoryModal" class="fixed inset-0 z-50 modal-blur-bg hidden opacity-0 transition-opacity duration-300 flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-2xl border border-gray-100 shadow-2xl transform scale-95 transition-transform duration-300 flex flex-col overflow-hidden">
            
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-folder-plus text-amber-500"></i>
                    <h3 class="text-xs font-medium text-neutral-800 uppercase tracking-wider">Add Service Category</h3>
                </div>
                <button id="closeModalBtn" class="text-gray-400 hover:text-gray-600 transition p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="" method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" value="add_category">

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Category Name</label>
                    <input type="text" name="category_name" required placeholder="e.g. Skin Treatments" id="categoryNameInput"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Tracking System Code</label>
                    <input type="text" name="category_code" required placeholder="e.g. SKIN" id="categoryCodeInput"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 font-mono uppercase outline-none transition-all accent-focus bg-gray-50/30">
                    <p class="text-[10px] text-gray-400 mt-1">Unique short tracking string key parameter.</p>
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Allocation Status</label>
                    <select name="status" required
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-white">
                        <option value="Active">Active Operational</option>
                        <option value="Inactive">Inactive Hold</option>
                    </select>
                </div>

                <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3 bg-gray-50/20 -mx-5 -mb-5 p-4 mt-6">
                    <button type="button" id="cancelModalBtn" class="px-4 py-2 border border-gray-200 text-gray-500 hover:text-neutral-800 hover:bg-gray-50 rounded-xl text-xs font-medium transition">
                        Discard
                    </button>
                    <button type="submit" class="px-4 py-2 bg-[#222222] text-[#EBBB15] hover:bg-neutral-800 rounded-xl text-xs font-medium transition shadow-sm">
                        Commit Record
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        AOS.init({ once: true });

        const categoryModal = document.getElementById('categoryModal');
        const openModalBtn = document.getElementById('openModalBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const cancelModalBtn = document.getElementById('cancelModalBtn');
        const modalContainer = categoryModal.querySelector('.transform');
        
        const catNameInput = document.getElementById('categoryNameInput');
        const catCodeInput = document.getElementById('categoryCodeInput');

        // Automatic smart tracking code generator logic
        catNameInput.addEventListener('input', (e) => {
            const value = e.target.value;
            if(value.length <= 4) {
                catCodeInput.value = value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            }
        });

        function openModal() {
            categoryModal.classList.remove('hidden');
            setTimeout(() => {
                categoryModal.classList.remove('opacity-0');
                modalContainer.classList.remove('scale-95');
            }, 10);
        }

        function closeModal() {
            categoryModal.classList.add('opacity-0');
            modalContainer.classList.add('scale-95');
            setTimeout(() => {
                categoryModal.classList.add('hidden');
            }, 300);
        }

        openModalBtn.addEventListener('click', openModal);
        closeModalBtn.addEventListener('click', closeModal);
        cancelModalBtn.addEventListener('click', closeModal);

        categoryModal.addEventListener('click', (e) => {
            if (e.target === categoryModal) closeModal();
        });
    </script>
</body>
</html>
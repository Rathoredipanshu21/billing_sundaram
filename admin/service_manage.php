<?php
session_start();
include '../config/db.php'; 

$message = '';
$messageType = '';

// Create target directory if it doesn't exist natively
$uploadDir = '../uploads/services/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Handle Add Service Form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'add_service') {
    $name = mysqli_real_escape_string($conn, $_POST['service_name']);
    // Storing the selected category ID safely as an integer parameter
    $category_id = intval($_POST['category']); 
    $price = floatval($_POST['price']);
    $duration = intval($_POST['duration']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    
    $imagePath = '';
    if (isset($_FILES['service_image']) && $_FILES['service_image']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['service_image']['tmp_name'];
        $fileName = $_FILES['service_image']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Sanitize file name to prevent collision paths
        $newFileName = time() . '_' . preg_replace('/[^A-Za-z0-9\-]/', '', $name) . '.' . $fileExtension;
        $targetFile = $uploadDir . $newFileName;
        
        if (move_uploaded_file($fileTmpPath, $targetFile)) {
            $imagePath = $targetFile;
        }
    }

    // Adjusted the column binding parameter slightly to support an integer category ID (i) instead of a string (s)
    $sql = "INSERT INTO services (service_name, category, price, duration, description, image_path, status) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sidisss", $name, $category_id, $price, $duration, $description, $imagePath, $status);
        if ($stmt->execute()) {
            $message = "Service successfully added to the lounge catalog.";
            $messageType = "success";
        } else {
            $message = "Database mapping error encountered.";
            $messageType = "error";
        }
        $stmt->close();
    }
}

// Fetch categories matching your strict SELECT query criteria for the dropdown select component
$categoriesDropdownResult = $conn->query("SELECT id, category_name, category_code, status, created_at FROM categories WHERE status = 'Active' ORDER BY category_name ASC");

// Fetch recorded catalog elements with a relational JOIN to pull the clean category name for display cards
$servicesResult = $conn->query("SELECT s.*, c.category_name AS assigned_category_name 
                                FROM services s 
                                LEFT JOIN categories c ON s.category = c.id 
                                ORDER BY s.id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Lounge Management Menu</title>
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
                <i class="fa-solid fa-wand-magic-sparkles text-amber-500"></i>
                <span>Services Lounge Menu</span>
            </h2>
            <p class="text-[11px] text-gray-400 uppercase tracking-wider mt-0.5">Configure operational catalogs</p>
        </div>
        
        <button id="openModalBtn" class="bg-[#222222] hover:bg-neutral-800 text-[#EBBB15] px-4 py-2.5 rounded-xl text-xs font-medium transition flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-plus-circle"></i>
            <span>Add New Service</span>
        </button>
    </div>

    <?php if (!empty($message)): ?>
        <div class="mb-6 p-3 rounded-xl text-xs flex items-center gap-2 max-w-xl mx-auto <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-rose-50 text-rose-700 border border-rose-100'; ?>" data-aos="fade-in">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo $message; ?></span>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6" data-aos="fade-up" data-aos-duration="800">
        <?php if ($servicesResult && $servicesResult->num_rows > 0): ?>
            <?php while($row = $servicesResult->fetch_assoc()): ?>
                <div class="bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm flex flex-col hover:shadow-md transition duration-300">
                    
                    <div class="h-44 w-full bg-gray-50 relative overflow-hidden group border-b border-gray-50">
                        <?php if (!empty($row['image_path']) && file_exists($row['image_path'])): ?>
                            <img src="<?php echo $row['image_path']; ?>" alt="Service graphic" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                        <?php else: ?>
                            <div class="w-full h-full flex flex-col items-center justify-center text-gray-300 gap-2 bg-gray-50">
                                <i class="fa-solid fa-image text-2xl"></i>
                                <span class="text-[10px] uppercase tracking-widest text-gray-400">Standard Placeholder</span>
                            </div>
                        <?php endif; ?>

                        <span class="absolute top-3 right-3 text-[9px] font-medium px-2.5 py-1 rounded-full uppercase <?php echo $row['status'] === 'Active' ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600'; ?>">
                            <?php echo $row['status']; ?>
                        </span>
                    </div>

                    <div class="p-4 flex-grow flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between gap-2 mb-1">
                                <span class="text-[10px] uppercase tracking-wider text-amber-600 font-medium"><?php echo htmlspecialchars($row['assigned_category_name'] ?: 'Unassigned Group'); ?></span>
                                <div class="flex items-center gap-1 text-gray-400 text-[11px]">
                                    <i class="fa-regular fa-clock"></i>
                                    <span><?php echo $row['duration']; ?> Mins</span>
                                </div>
                            </div>
                            
                            <h3 class="text-sm font-medium text-neutral-800 tracking-tight mb-2"><?php echo htmlspecialchars($row['service_name']); ?></h3>
                            <p class="text-xs text-gray-500 line-clamp-2 leading-relaxed mb-4"><?php echo htmlspecialchars($row['description'] ?: 'No structural description provided.'); ?></p>
                        </div>

                        <div class="pt-3 border-t border-gray-50 flex items-center justify-between">
                            <span class="text-[10px] font-medium uppercase text-gray-400">Standard Fee</span>
                            <span class="text-sm font-semibold text-neutral-800">₹<?php echo number_format($row['price'], 2); ?></span>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-span-full py-16 bg-gray-50/50 border border-dashed border-gray-200 rounded-2xl flex flex-col items-center justify-center text-center p-4">
                <div class="w-12 h-12 rounded-xl bg-gray-100 text-gray-400 flex items-center justify-center mb-3">
                    <i class="fa-solid fa-boxes-stacked text-base"></i>
                </div>
                <h4 class="text-xs font-medium text-neutral-700 uppercase tracking-wider">No Catalog Records Settled</h4>
                <p class="text-xs text-gray-400 mt-1 max-w-xs">Initialize operations by adding your first service profile to the active registry desk view.</p>
            </div>
        <?php endif; ?>
    </div>

    <div id="serviceModal" class="fixed inset-0 z-50 modal-blur-bg hidden opacity-0 transition-opacity duration-300 flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg rounded-2xl border border-gray-100 shadow-2xl transform scale-95 transition-transform duration-300 flex flex-col overflow-hidden max-h-[90vh]">
            
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-square-plus text-amber-500"></i>
                    <h3 class="text-xs font-medium text-neutral-800 uppercase tracking-wider">Register Service Profile</h3>
                </div>
                <button id="closeModalBtn" class="text-gray-400 hover:text-gray-600 transition p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="" method="POST" enctype="multipart/form-data" class="overflow-y-auto p-5 space-y-4 custom-scroll">
                <input type="hidden" name="action" value="add_service">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Service Name</label>
                        <input type="text" name="service_name" required placeholder="e.g. Premium Haircut"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Category Class</label>
                        <select name="category" required
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-white">
                            <?php if ($categoriesDropdownResult && $categoriesDropdownResult->num_rows > 0): ?>
                                <?php while($catRow = $categoriesDropdownResult->fetch_assoc()): ?>
                                    <option value="<?php echo $catRow['id']; ?>">
                                        <?php echo htmlspecialchars($catRow['category_name']); ?> [<?php echo htmlspecialchars($catRow['category_code']); ?>]
                                    </option>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <option value="">No Active Categories Configured</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Fee Rate (INR)</label>
                        <input type="number" step="0.01" name="price" required placeholder="0.00"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Duration (Minutes)</label>
                        <input type="number" name="duration" required placeholder="30"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Structural Description</label>
                    <textarea name="description" rows="3" placeholder="Provide service operational instructions details..."
                        class="w-full border border-gray-200 rounded-xl p-3 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30 resize-none"></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Display Image</label>
                        <input type="file" name="service_image" accept="image/*"
                            class="w-full text-xs text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-[11px] file:font-medium file:bg-neutral-100 file:text-neutral-700 hover:file:bg-neutral-200 file:cursor-pointer cursor-pointer border border-gray-200 rounded-xl p-1 bg-gray-50/30">
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Operational Status</label>
                        <select name="status" required
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-white">
                            <option value="Active">Active Operational</option>
                            <option value="Inactive">Inactive Hold</option>
                        </select>
                    </div>
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

        const serviceModal = document.getElementById('serviceModal');
        const openModalBtn = document.getElementById('openModalBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const cancelModalBtn = document.getElementById('cancelModalBtn');
        const modalContainer = serviceModal.querySelector('.transform');

        function openModal() {
            serviceModal.classList.remove('hidden');
            setTimeout(() => {
                serviceModal.classList.remove('opacity-0');
                modalContainer.classList.remove('scale-95');
            }, 10);
        }

        function closeModal() {
            serviceModal.classList.add('opacity-0');
            modalContainer.classList.add('scale-95');
            setTimeout(() => {
                serviceModal.classList.add('hidden');
            }, 300);
        }

        openModalBtn.addEventListener('click', openModal);
        closeModalBtn.addEventListener('click', closeModal);
        cancelModalBtn.addEventListener('click', closeModal);

        serviceModal.addEventListener('click', (e) => {
            if (e.target === serviceModal) closeModal();
        });
    </script>
</body>
</html>
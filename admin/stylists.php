<?php
session_start();
include '../config/db.php'; 

$message = '';
$messageType = '';

// Handle Add Stylist Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'add_stylist') {
    $stylist_name = mysqli_real_escape_string($conn, $_POST['stylist_name']);
    $contact_number = mysqli_real_escape_string($conn, $_POST['contact_number']);
    $specialty = mysqli_real_escape_string($conn, $_POST['specialty']);
    $commission_rate = floatval($_POST['commission_rate']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    $sql = "INSERT INTO stylists (stylist_name, contact_number, specialty, commission_rate, status) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sssds", $stylist_name, $contact_number, $specialty, $commission_rate, $status);
        if ($stmt->execute()) {
            $message = "Stylist profile successfully registered in the workforce directory.";
            $messageType = "success";
        } else {
            $message = "Database mapping error encountered.";
            $messageType = "error";
        }
        $stmt->close();
    }
}

// Fetch all recorded stylists
$stylistsResult = $conn->query("SELECT * FROM stylists ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stylist Registry Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #FFFFFF; color: #222222; }
        .modal-blur-bg { background-color: rgba(26, 26, 26, 0.4); backdrop-filter: blur(4px); }
        .accent-focus:focus { border-color: #EBBB15; box-shadow: 0 0 0 3px rgba(235, 187, 21, 0.15); }
        .custom-scroll::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 4px; }
    </style>
</head>
<body class="p-4 lg:p-6 min-h-screen">

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-8 pb-4 border-b border-gray-100" data-aos="fade-down" data-aos-duration="600">
        <div>
            <h2 class="text-base font-medium text-neutral-800 tracking-tight flex items-center gap-2">
                <i class="fa-solid fa-user-group text-amber-500"></i>
                <span>Personnel Profiles</span>
            </h2>
            <p class="text-[11px] text-gray-400 uppercase tracking-wider mt-0.5">Manage salon staff and expert stylists</p>
        </div>
        
        <button onclick="openModal()" class="bg-[#222222] hover:bg-neutral-800 text-[#EBBB15] px-4 py-2.5 rounded-xl text-xs font-medium transition flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-user-plus"></i>
            <span>Register Stylist</span>
        </button>
    </div>

    <?php if (!empty($message)): ?>
        <div class="mb-6 p-3 rounded-xl text-xs flex items-center gap-2 max-w-xl mx-auto <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-rose-50 text-rose-700 border border-rose-100'; ?>" data-aos="fade-in">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo $message; ?></span>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6" data-aos="fade-up" data-aos-duration="800">
        <?php if ($stylistsResult && $stylistsResult->num_rows > 0): ?>
            <?php while($row = $stylistsResult->fetch_assoc()): ?>
                <div class="bg-white border border-gray-100 rounded-2xl p-5 shadow-sm hover:shadow-md transition relative overflow-hidden group">
                    <div class="absolute top-0 right-0 p-4">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[9px] font-medium uppercase tracking-wider <?php echo $row['status'] === 'Active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'; ?>">
                            <span class="w-1.5 h-1.5 rounded-full <?php echo $row['status'] === 'Active' ? 'bg-emerald-500' : 'bg-gray-400'; ?>"></span>
                            <?php echo $row['status']; ?>
                        </span>
                    </div>
                    
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-14 h-14 rounded-full bg-gray-50 border border-gray-100 flex items-center justify-center text-xl text-amber-500 shadow-sm shrink-0">
                            <i class="fa-solid fa-user-tie"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-neutral-800 tracking-tight"><?php echo htmlspecialchars($row['stylist_name']); ?></h3>
                            <p class="text-[11px] text-gray-500 mt-0.5 uppercase tracking-wider"><?php echo htmlspecialchars($row['specialty']); ?></p>
                        </div>
                    </div>

                    <div class="space-y-2 pt-3 border-t border-gray-50">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-400"><i class="fa-solid fa-phone w-4"></i> Contact</span>
                            <span class="font-mono text-neutral-700"><?php echo htmlspecialchars($row['contact_number']); ?></span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-400"><i class="fa-solid fa-percent w-4"></i> Base Commission</span>
                            <span class="font-medium text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md"><?php echo floatval($row['commission_rate']); ?>%</span>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-span-full py-16 text-center bg-gray-50/30 border border-dashed border-gray-200 rounded-2xl">
                <div class="w-12 h-12 rounded-xl bg-white border border-gray-100 text-gray-300 flex items-center justify-center mb-3 mx-auto shadow-sm">
                    <i class="fa-solid fa-id-card text-lg"></i>
                </div>
                <h4 class="text-xs font-medium text-neutral-600 uppercase tracking-wider">No Stylists Configured</h4>
                <p class="text-[11px] text-gray-400 mt-1 max-w-sm mx-auto leading-relaxed">Register your salon staff to begin tracking their service commissions.</p>
            </div>
        <?php endif; ?>
    </div>

    <div id="stylistModal" class="fixed inset-0 z-50 modal-blur-bg hidden opacity-0 transition-opacity duration-300 flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-2xl border border-gray-100 shadow-2xl transform scale-95 transition-transform duration-300 flex flex-col overflow-hidden">
            
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-user-plus text-amber-500"></i>
                    <h3 class="text-xs font-medium text-neutral-800 uppercase tracking-wider">Register Stylist Profile</h3>
                </div>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="" method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" value="add_stylist">

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Full Name</label>
                    <input type="text" name="stylist_name" required placeholder="e.g. Rahul Sharma"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Mobile Number</label>
                    <input type="tel" name="contact_number" required placeholder="10-digit mobile number" pattern="[0-9]{10}"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Primary Specialty</label>
                    <input type="text" name="specialty" required placeholder="e.g. Senior Hair Expert"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Commission Rate (%)</label>
                        <input type="number" step="0.01" name="commission_rate" required placeholder="e.g. 15.00" min="0" max="100"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Account Status</label>
                        <select name="status" required
                            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-white">
                            <option value="Active">Active Duty</option>
                            <option value="Inactive">Suspended/Leave</option>
                        </select>
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3 bg-gray-50/20 -mx-5 -mb-5 p-4 mt-6">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 border border-gray-200 text-gray-500 hover:text-neutral-800 hover:bg-gray-50 rounded-xl text-xs font-medium transition">
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
        
        const modal = document.getElementById('stylistModal');
        const modalContainer = modal.querySelector('.transform');

        function openModal() {
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modalContainer.classList.remove('scale-95');
            }, 10);
        }

        function closeModal() {
            modal.classList.add('opacity-0');
            modalContainer.classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }
    </script>
</body>
</html>
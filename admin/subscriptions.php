<?php
session_start();
include '../config/db.php';

$message = '';
$statusType = '';

// --- Handle Form Submissions (Create / Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $plan_name = mysqli_real_escape_string($conn, $_POST['plan_name']);
    $cost = floatval($_POST['cost']);
    $validity_days = intval($_POST['validity_days']);
    $discount_percent = floatval($_POST['discount_percent']);
    
    // Process JSON array for Benefits (Service ID -> Quantity)
    $benefits = [];
    $service_ids = $_POST['service_id'] ?? [];
    $service_qtys = $_POST['service_qty'] ?? [];
    
    for ($i = 0; $i < count($service_ids); $i++) {
        if (!empty($service_ids[$i]) && !empty($service_qtys[$i]) && $service_qtys[$i] > 0) {
            $benefits[] = [
                'service_id' => intval($service_ids[$i]),
                'qty' => intval($service_qtys[$i])
            ];
        }
    }
    $service_benefits_json = json_encode($benefits);

    if ($_POST['action'] === 'add') {
        $stmt = $conn->prepare("INSERT INTO subscriptions (plan_name, cost, validity_days, discount_percent, service_benefits, status) VALUES (?, ?, ?, ?, ?, 'Active')");
        $stmt->bind_param("sddds", $plan_name, $cost, $validity_days, $discount_percent, $service_benefits_json);
        if ($stmt->execute()) {
            $message = "Membership Plan Created Successfully!";
            $statusType = "success";
        } else {
            $message = "Error creating plan!";
            $statusType = "error";
        }
        $stmt->close();
    } 
    elseif ($_POST['action'] === 'edit') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("UPDATE subscriptions SET plan_name=?, cost=?, validity_days=?, discount_percent=?, service_benefits=? WHERE id=?");
        $stmt->bind_param("sdddsi", $plan_name, $cost, $validity_days, $discount_percent, $service_benefits_json, $id);
        if ($stmt->execute()) {
            $message = "Membership Plan Updated Successfully!";
            $statusType = "success";
        } else {
            $message = "Error updating plan!";
            $statusType = "error";
        }
        $stmt->close();
    }
}

// --- Handle Status Toggle ---
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $new_status = $_GET['toggle_status'] === 'Active' ? 'Active' : 'Inactive';
    $conn->query("UPDATE subscriptions SET status='$new_status' WHERE id=$id");
    header("Location: subscriptions.php");
    exit();
}

// --- Fetch Data for UI ---
$subscriptions = $conn->query("SELECT * FROM subscriptions ORDER BY id DESC");

// Fetch active services for the dropdowns and mapping
$services_result = $conn->query("SELECT id, service_name FROM services WHERE status='Active' ORDER BY service_name ASC");
$services_options = "";
$services_map = [];
while ($row = $services_result->fetch_assoc()) {
    $services_options .= "<option value='{$row['id']}'>" . htmlspecialchars($row['service_name']) . "</option>";
    $services_map[$row['id']] = $row['service_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Memberships & Subscriptions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f4f4; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
        .modal { transition: opacity 0.3s ease, visibility 0.3s ease; }
        .modal-content { transition: transform 0.3s ease; }
        .modal.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
        .modal.hidden .modal-content { transform: scale(0.95) translateY(-20px); }
    </style>
</head>
<body class="p-4 h-screen flex flex-col overflow-hidden">

    <div class="flex justify-between items-center bg-white p-5 rounded-[24px] border shadow-sm mb-4 shrink-0" data-aos="fade-down">
        <div>
            <h1 class="text-[18px] font-bold text-[#1f1f1f] uppercase tracking-wide flex items-center gap-2">
                <i class="fa-solid fa-gem text-yellow-500"></i> Manage Memberships
            </h1>
            <p class="text-[12px] text-gray-500 mt-1">Create and monitor client subscription plans & benefits.</p>
        </div>
        <button onclick="openModal('addModal')" class="bg-[#1f1f1f] hover:bg-black transition text-yellow-400 px-6 py-3 rounded-xl uppercase tracking-widest text-[12px] font-semibold shadow-md">
            <i class="fa-solid fa-plus mr-2"></i> Add New Membership
        </button>
    </div>

    <?php if ($message): ?>
        <div class="p-4 mb-4 rounded-xl text-[13px] font-medium <?php echo $statusType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>" data-aos="fade-in">
            <i class="fa-solid <?php echo $statusType === 'success' ? 'fa-check-circle' : 'fa-triangle-exclamation'; ?>"></i> <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-[24px] border shadow-sm flex-1 overflow-hidden flex flex-col" data-aos="fade-up" data-aos-delay="100">
        <div class="flex-1 overflow-y-auto custom-scroll p-4">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b text-[11px] uppercase text-gray-400 bg-[#fafafa]">
                        <th class="p-4 font-semibold rounded-tl-xl">Plan Name</th>
                        <th class="p-4 font-semibold text-right">Cost (₹)</th>
                        <th class="p-4 font-semibold text-center">Validity</th>
                        <th class="p-4 font-semibold text-center">Discount</th>
                        <th class="p-4 font-semibold text-center">Status</th>
                        <th class="p-4 font-semibold text-right rounded-tr-xl">Actions</th>
                    </tr>
                </thead>
                <tbody class="text-[13px] text-neutral-800">
                    <?php if ($subscriptions->num_rows > 0): ?>
                        <?php while ($row = $subscriptions->fetch_assoc()): ?>
                            <tr class="border-b hover:bg-gray-50 transition">
                                <td class="p-4 font-semibold text-[#1f1f1f] flex items-center gap-2">
                                    <i class="fa-solid fa-crown text-yellow-500 text-sm"></i>
                                    <?php echo htmlspecialchars($row['plan_name']); ?>
                                </td>
                                <td class="p-4 font-mono font-bold text-right">₹<?php echo number_format($row['cost'], 2); ?></td>
                                <td class="p-4 text-center">
                                    <span class="bg-blue-50 text-blue-600 px-3 py-1 rounded-full text-[11px] font-medium">
                                        <?php echo $row['validity_days']; ?> Days
                                    </span>
                                </td>
                                <td class="p-4 text-center font-mono text-green-600 font-bold"><?php echo floatval($row['discount_percent']); ?>%</td>
                                <td class="p-4 text-center">
                                    <?php if ($row['status'] === 'Active'): ?>
                                        <a href="?toggle_status=Inactive&id=<?php echo $row['id']; ?>" class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider hover:bg-green-200 transition">Active</a>
                                    <?php else: ?>
                                        <a href="?toggle_status=Active&id=<?php echo $row['id']; ?>" class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider hover:bg-red-200 transition">Disabled</a>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-right">
                                    <button onclick="viewDetails(<?php echo htmlspecialchars(json_encode($row)); ?>)" class="text-blue-500 hover:text-blue-700 mr-3 transition" title="View Benefits">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)" class="text-yellow-500 hover:text-yellow-600 transition" title="Edit Plan">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="p-10 text-center text-gray-400">
                                <i class="fa-solid fa-box-open text-4xl mb-3"></i>
                                <p class="uppercase tracking-widest text-[11px]">No Subscriptions Found</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <div id="addModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="modal-content bg-white w-full max-w-2xl rounded-[24px] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="px-6 py-4 border-b flex justify-between items-center bg-[#f8f8f8]">
                <h3 class="font-bold text-[#1f1f1f] text-[15px] uppercase tracking-wider">
                    <i class="fa-solid fa-plus-circle text-yellow-500 mr-2"></i> Create Membership
                </h3>
                <button onclick="closeModal('addModal')" class="text-gray-400 hover:text-red-500 text-lg"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <form method="POST" class="flex-1 overflow-y-auto custom-scroll p-6 flex flex-col gap-4">
                <input type="hidden" name="action" value="add">
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] text-gray-500 font-semibold mb-1 uppercase tracking-wider">Plan Name *</label>
                        <input type="text" name="plan_name" required placeholder="e.g. Gold Member" class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition">
                    </div>
                    <div>
                        <label class="block text-[11px] text-gray-500 font-semibold mb-1 uppercase tracking-wider">Total Cost (₹) *</label>
                        <input type="number" step="0.01" name="cost" required placeholder="0.00" class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] text-gray-500 font-semibold mb-1 uppercase tracking-wider">Validity Period *</label>
                        <select name="validity_days" required class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition">
                            <option value="">Select Validity...</option>
                            <option value="90">3 Months (90 Days)</option>
                            <option value="180">6 Months (180 Days)</option>
                            <option value="270">9 Months (270 Days)</option>
                            <option value="365">1 Year (365 Days)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] text-gray-500 font-semibold mb-1 uppercase tracking-wider">Global Discount (%)</label>
                        <input type="number" step="0.01" name="discount_percent" placeholder="e.g. 10" value="0" class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition">
                    </div>
                </div>

                <div class="mt-2 border-t pt-4">
                    <div class="flex justify-between items-center mb-3">
                        <label class="block text-[12px] text-[#1f1f1f] font-bold uppercase tracking-wider">Service Benefits Included</label>
                        <button type="button" onclick="addBenefitRow('addBenefitsContainer')" class="text-[10px] bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1.5 rounded-lg transition font-semibold"><i class="fa-solid fa-plus"></i> Add Service</button>
                    </div>
                    <div id="addBenefitsContainer" class="flex flex-col gap-2">
                        </div>
                </div>

                <div class="pt-4 mt-2 border-t flex justify-end gap-3 sticky bottom-0 bg-white">
                    <button type="button" onclick="closeModal('addModal')" class="px-5 py-2.5 rounded-xl border text-[12px] font-semibold text-gray-600 hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" class="bg-[#1f1f1f] hover:bg-black text-yellow-400 px-6 py-2.5 rounded-xl text-[12px] font-semibold uppercase tracking-wider transition">Save Plan</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="modal-content bg-white w-full max-w-2xl rounded-[24px] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="px-6 py-4 border-b flex justify-between items-center bg-[#f8f8f8]">
                <h3 class="font-bold text-[#1f1f1f] text-[15px] uppercase tracking-wider">
                    <i class="fa-solid fa-pen text-yellow-500 mr-2"></i> Edit Membership
                </h3>
                <button onclick="closeModal('editModal')" class="text-gray-400 hover:text-red-500 text-lg"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <form method="POST" class="flex-1 overflow-y-auto custom-scroll p-6 flex flex-col gap-4">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] text-gray-500 font-semibold mb-1 uppercase tracking-wider">Plan Name *</label>
                        <input type="text" id="edit_plan_name" name="plan_name" required class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition">
                    </div>
                    <div>
                        <label class="block text-[11px] text-gray-500 font-semibold mb-1 uppercase tracking-wider">Total Cost (₹) *</label>
                        <input type="number" step="0.01" id="edit_cost" name="cost" required class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] text-gray-500 font-semibold mb-1 uppercase tracking-wider">Validity Period (Days)*</label>
                        <input type="number" id="edit_validity" name="validity_days" required class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition" placeholder="e.g. 90">
                    </div>
                    <div>
                        <label class="block text-[11px] text-gray-500 font-semibold mb-1 uppercase tracking-wider">Global Discount (%)</label>
                        <input type="number" step="0.01" id="edit_discount" name="discount_percent" class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition">
                    </div>
                </div>

                <div class="mt-2 border-t pt-4">
                    <div class="flex justify-between items-center mb-3">
                        <label class="block text-[12px] text-[#1f1f1f] font-bold uppercase tracking-wider">Service Benefits Included</label>
                        <button type="button" onclick="addBenefitRow('editBenefitsContainer')" class="text-[10px] bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1.5 rounded-lg transition font-semibold"><i class="fa-solid fa-plus"></i> Add Service</button>
                    </div>
                    <div id="editBenefitsContainer" class="flex flex-col gap-2">
                        </div>
                </div>

                <div class="pt-4 mt-2 border-t flex justify-end gap-3 sticky bottom-0 bg-white">
                    <button type="button" onclick="closeModal('editModal')" class="px-5 py-2.5 rounded-xl border text-[12px] font-semibold text-gray-600 hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" class="bg-[#1f1f1f] hover:bg-black text-yellow-400 px-6 py-2.5 rounded-xl text-[12px] font-semibold uppercase tracking-wider transition">Update Plan</button>
                </div>
            </form>
        </div>
    </div>

    <div id="viewModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="modal-content bg-white w-full max-w-md rounded-[24px] shadow-2xl overflow-hidden">
            <div class="px-6 py-4 border-b flex justify-between items-center bg-[#f8f8f8]">
                <h3 class="font-bold text-[#1f1f1f] text-[15px] uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-gem text-yellow-500"></i> Plan Details
                </h3>
                <button onclick="closeModal('viewModal')" class="text-gray-400 hover:text-red-500 text-lg"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <h4 id="view_plan_name" class="text-xl font-bold text-gray-800"></h4>
                    <p class="text-[12px] text-gray-500 font-mono mt-1">₹<span id="view_cost"></span> / <span id="view_validity"></span> Days</p>
                </div>
                
                <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 mb-5 flex justify-between items-center">
                    <span class="text-[11px] font-semibold text-blue-800 uppercase">Global Product/Service Discount:</span>
                    <span id="view_discount" class="font-bold font-mono text-blue-700 text-lg"></span>
                </div>

                <h5 class="text-[11px] uppercase tracking-wider font-bold text-gray-400 mb-2 border-b pb-1">Included Services</h5>
                <ul id="view_benefits_list" class="space-y-2 max-h-[40vh] overflow-y-auto custom-scroll">
                    </ul>
            </div>
        </div>
    </div>


    <script>
        // Initialize AOS animations
        AOS.init({ duration: 600, once: true });

        // Pre-fetched Services Map for View/Edit UI mapping
        const servicesMap = <?php echo json_encode($services_map); ?>;
        const servicesOptions = `<?php echo $services_options; ?>`;

        // -- Modal Utility --
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        // -- Add dynamic Benefit Row --
        function addBenefitRow(containerId, serviceId = '', qty = '') {
            const container = document.getElementById(containerId);
            const row = document.createElement('div');
            row.className = "flex gap-2 items-center bg-[#fafafa] p-2 rounded-xl border border-[#e5e7eb]";
            row.innerHTML = `
                <select name="service_id[]" required class="flex-1 border-none bg-transparent outline-none text-[12px]">
                    <option value="">Select Service...</option>
                    ${servicesOptions}
                </select>
                <input type="number" name="service_qty[]" value="${qty}" required min="1" placeholder="Qty" class="w-20 border-l px-3 bg-transparent outline-none text-[12px] text-center" title="Quantity allowed">
                <button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-600 px-2 transition"><i class="fa-solid fa-trash-can"></i></button>
            `;
            // Auto select if data exists (for Edit Modal)
            if(serviceId) row.querySelector('select').value = serviceId;
            container.appendChild(row);
        }

        // Initialize "Add" modal with one blank row
        document.getElementById('addBenefitsContainer').innerHTML = '';
        addBenefitRow('addBenefitsContainer');

        // -- Edit Logic --
        function openEditModal(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_plan_name').value = data.plan_name;
            document.getElementById('edit_cost').value = data.cost;
            document.getElementById('edit_validity').value = data.validity_days;
            document.getElementById('edit_discount').value = data.discount_percent;

            const container = document.getElementById('editBenefitsContainer');
            container.innerHTML = ''; 
            
            let benefits = [];
            if(data.service_benefits) {
                try { benefits = JSON.parse(data.service_benefits); } catch(e){}
            }

            if(benefits.length > 0) {
                benefits.forEach(b => addBenefitRow('editBenefitsContainer', b.service_id, b.qty));
            } else {
                addBenefitRow('editBenefitsContainer'); // at least one blank row
            }

            openModal('editModal');
        }

        // -- View Logic --
        function viewDetails(data) {
            document.getElementById('view_plan_name').innerText = data.plan_name;
            document.getElementById('view_cost').innerText = parseFloat(data.cost).toFixed(2);
            document.getElementById('view_validity').innerText = data.validity_days;
            document.getElementById('view_discount').innerText = parseFloat(data.discount_percent) + '%';
            
            const list = document.getElementById('view_benefits_list');
            list.innerHTML = '';
            
            let benefits = [];
            if(data.service_benefits) {
                try { benefits = JSON.parse(data.service_benefits); } catch(e){}
            }

            if (benefits.length > 0) {
                benefits.forEach(b => {
                    const serviceName = servicesMap[b.service_id] || 'Unknown Service';
                    list.innerHTML += `
                        <li class="flex justify-between items-center bg-gray-50 border border-gray-100 px-3 py-2 rounded-xl">
                            <div class="flex items-center gap-2 text-[13px] font-medium text-gray-700">
                                <i class="fa-solid fa-scissors text-yellow-500 text-[10px]"></i> ${serviceName}
                            </div>
                            <div class="bg-[#1f1f1f] text-yellow-400 text-[10px] font-bold px-2 py-0.5 rounded-md">
                                x${b.qty}
                            </div>
                        </li>
                    `;
                });
            } else {
                list.innerHTML = `<li class="text-[12px] text-gray-400 italic py-2"><i class="fa-solid fa-ban mr-1"></i> No specific services attached. Only global discount applies.</li>`;
            }

            openModal('viewModal');
        }
    </script>
</body>
</html>
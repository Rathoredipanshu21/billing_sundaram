<?php
session_start();
include '../config/db.php';

$message = '';
$statusType = '';

// --- Handle Issuing New Membership ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_membership') {
    
    $client_name = mysqli_real_escape_string($conn, $_POST['client_name']);
    $client_email = mysqli_real_escape_string($conn, $_POST['client_email']);
    $client_contact = mysqli_real_escape_string($conn, $_POST['client_contact']);
    $client_address = mysqli_real_escape_string($conn, $_POST['client_address']);
    $plan_id = intval($_POST['subscription_plan_id']);
    
    // Capture user-defined start date
    $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);
    if (empty($start_date)) {
        $start_date = date('Y-m-d'); // Fallback to today
    }
    
    // Fetch the validity days of the selected plan to securely calculate Expiry Date on the backend
    $planStmt = $conn->prepare("SELECT validity_days FROM subscriptions WHERE id = ?");
    $planStmt->bind_param("i", $plan_id);
    $planStmt->execute();
    $planResult = $planStmt->get_result();
    $planData = $planResult->fetch_assoc();
    $planStmt->close();

    if ($planData) {
        $validity_days = intval($planData['validity_days']);
        
        // Calculate end date accurately based on the chosen start date
        $end_date = date('Y-m-d', strtotime($start_date . " + {$validity_days} days"));
        
        // Generate Unique Membership Code: MEM-{YEAR}{MONTH}-{RANDOM}
        $membership_code = 'MEM-' . date('Ym') . '-' . rand(10000, 99999);

        $stmt = $conn->prepare("INSERT INTO client_subscriptions (membership_code, client_name, client_email, client_contact, client_address, subscription_plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
        
        $stmt->bind_param("sssssiss", $membership_code, $client_name, $client_email, $client_contact, $client_address, $plan_id, $start_date, $end_date);
        
        if ($stmt->execute()) {
            $message = "Membership Issued Successfully! Code: " . $membership_code;
            $statusType = "success";
        } else {
            $message = "Error issuing membership: " . $conn->error;
            $statusType = "error";
        }
        $stmt->close();
    } else {
        $message = "Invalid Plan Selected.";
        $statusType = "error";
    }
}

// --- Handle Status Revoke/Activate ---
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $new_status = $_GET['toggle_status'] === 'Active' ? 'Active' : 'Revoked';
    $conn->query("UPDATE client_subscriptions SET status='$new_status' WHERE id=$id");
    header("Location: issue_membership.php");
    exit();
}

// --- Fetch Active Plans for Dropdown ---
$plansResult = $conn->query("SELECT id, plan_name, cost, validity_days FROM subscriptions WHERE status='Active' ORDER BY id DESC");
$plans = [];
while($p = $plansResult->fetch_assoc()){
    $plans[] = $p;
}

// --- Fetch Issued Memberships for Table ---
$issuedQuery = "
    SELECT cs.*, s.plan_name 
    FROM client_subscriptions cs 
    JOIN subscriptions s ON cs.subscription_plan_id = s.id 
    ORDER BY cs.id DESC
";
$issuedMemberships = $conn->query($issuedQuery);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issue Memberships</title>
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
        .vip-card-bg {
            background: linear-gradient(135deg, #111111 0%, #2a2a2a 100%);
            border: 1px solid #333;
        }
    </style>
</head>
<body class="p-4 h-screen flex flex-col overflow-hidden">

    <div class="flex justify-between items-center bg-white p-5 rounded-[24px] border shadow-sm mb-4 shrink-0" data-aos="fade-down">
        <div>
            <h1 class="text-[18px] font-bold text-[#1f1f1f] uppercase tracking-wide flex items-center gap-2">
                <i class="fa-solid fa-address-card text-yellow-500"></i> Client Memberships
            </h1>
            <p class="text-[12px] text-gray-500 mt-1">Issue and manage VIP access and subscription passes for clients.</p>
        </div>
        <button onclick="openModal('issueModal')" class="bg-[#1f1f1f] hover:bg-black transition text-yellow-400 px-6 py-3 rounded-xl uppercase tracking-widest text-[12px] font-semibold shadow-md flex items-center gap-2">
            <i class="fa-solid fa-user-plus"></i> Issue New Membership
        </button>
    </div>

    <?php if ($message): ?>
        <div class="p-4 mb-4 rounded-xl text-[13px] font-medium flex items-center gap-2 <?php echo $statusType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>" data-aos="fade-in">
            <i class="fa-solid <?php echo $statusType === 'success' ? 'fa-check-circle' : 'fa-triangle-exclamation'; ?> text-lg"></i> <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-[24px] border shadow-sm flex-1 overflow-hidden flex flex-col" data-aos="fade-up" data-aos-delay="100">
        <div class="flex-1 overflow-y-auto custom-scroll p-4">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b text-[11px] uppercase text-gray-400 bg-[#fafafa]">
                        <th class="p-4 font-semibold rounded-tl-xl w-48">Membership Code</th>
                        <th class="p-4 font-semibold">Client Info</th>
                        <th class="p-4 font-semibold">Subscribed Plan</th>
                        <th class="p-4 font-semibold text-center">Validity</th>
                        <th class="p-4 font-semibold text-center">Status</th>
                        <th class="p-4 font-semibold text-right rounded-tr-xl">Action</th>
                    </tr>
                </thead>
                <tbody class="text-[13px] text-neutral-800">
                    <?php if ($issuedMemberships->num_rows > 0): ?>
                        <?php while ($row = $issuedMemberships->fetch_assoc()): ?>
                            <?php 
                                // Check if expired automatically
                                $isExpired = (strtotime($row['end_date']) < strtotime(date('Y-m-d')));
                                $displayStatus = $row['status'];
                                if ($displayStatus === 'Active' && $isExpired) {
                                    $displayStatus = 'Expired';
                                }
                            ?>
                            <tr class="border-b hover:bg-gray-50 transition">
                                <td class="p-4">
                                    <div class="bg-[#1f1f1f] text-yellow-400 font-mono text-[11px] font-bold px-3 py-1.5 rounded-lg inline-flex items-center gap-2 shadow-sm">
                                        <i class="fa-solid fa-qrcode"></i> <?php echo htmlspecialchars($row['membership_code']); ?>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <div class="font-bold text-[#1f1f1f] mb-0.5"><i class="fa-solid fa-user text-gray-400 mr-1 text-[10px]"></i> <?php echo htmlspecialchars($row['client_name']); ?></div>
                                    <div class="text-[11px] text-gray-500"><i class="fa-solid fa-phone text-gray-400 mr-1"></i> <?php echo htmlspecialchars($row['client_contact']); ?></div>
                                </td>
                                <td class="p-4 font-semibold text-blue-700">
                                    <i class="fa-solid fa-crown text-yellow-500 mr-1"></i> <?php echo htmlspecialchars($row['plan_name']); ?>
                                </td>
                                <td class="p-4 text-center">
                                    <div class="text-[11px] text-gray-500">Starts: <?php echo date('d M Y', strtotime($row['start_date'])); ?></div>
                                    <div class="text-[11px] font-bold text-red-500">Ends: <?php echo date('d M Y', strtotime($row['end_date'])); ?></div>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if ($displayStatus === 'Active'): ?>
                                        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider"><i class="fa-solid fa-circle-check mr-1"></i>Active</span>
                                    <?php elseif ($displayStatus === 'Expired'): ?>
                                        <span class="bg-orange-100 text-orange-700 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider"><i class="fa-solid fa-clock-rotate-left mr-1"></i>Expired</span>
                                    <?php else: ?>
                                        <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider"><i class="fa-solid fa-ban mr-1"></i>Revoked</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-right">
                                    <button onclick="viewCard(<?php echo htmlspecialchars(json_encode($row)); ?>)" class="text-blue-500 hover:text-blue-700 mr-3 transition" title="View Digital Card">
                                        <i class="fa-solid fa-id-card text-lg"></i>
                                    </button>
                                    
                                    <?php if ($row['status'] === 'Active'): ?>
                                        <a href="?toggle_status=Revoked&id=<?php echo $row['id']; ?>" class="text-red-400 hover:text-red-600 transition" title="Revoke Access" onclick="return confirm('Are you sure you want to revoke this membership?');">
                                            <i class="fa-solid fa-power-off text-lg"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="?toggle_status=Active&id=<?php echo $row['id']; ?>" class="text-green-500 hover:text-green-700 transition" title="Restore Access">
                                            <i class="fa-solid fa-rotate-left text-lg"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="p-10 text-center text-gray-400">
                                <i class="fa-solid fa-id-card-clip text-4xl mb-3"></i>
                                <p class="uppercase tracking-widest text-[11px]">No Memberships Issued Yet</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <div id="issueModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="modal-content bg-white w-full max-w-2xl rounded-[24px] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="px-6 py-4 border-b flex justify-between items-center bg-[#f8f8f8]">
                <h3 class="font-bold text-[#1f1f1f] text-[15px] uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-user-plus text-yellow-500"></i> Assign Membership to Client
                </h3>
                <button onclick="closeModal('issueModal')" class="text-gray-400 hover:text-red-500 text-lg"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <form method="POST" class="flex-1 overflow-y-auto custom-scroll p-6 flex flex-col gap-5">
                <input type="hidden" name="action" value="issue_membership">
                
                <div class="bg-yellow-50/50 border border-yellow-200 p-4 rounded-xl flex flex-col gap-4">
                    <div>
                        <label class="block text-[11px] text-gray-600 font-bold mb-2 uppercase tracking-wider">
                            <i class="fa-solid fa-star text-yellow-500 mr-1"></i> Select Subscription Plan *
                        </label>
                        <select name="subscription_plan_id" id="planSelect" required onchange="calculateEndDate()" class="w-full border-2 border-white focus:border-yellow-400 rounded-xl px-4 py-3 text-[13px] bg-white outline-none transition font-semibold shadow-sm">
                            <option value="" data-validity="0"> Choose a Plan </option>
                            <?php foreach($plans as $plan): ?>
                                <option value="<?php echo $plan['id']; ?>" data-validity="<?php echo $plan['validity_days']; ?>">
                                    <?php echo htmlspecialchars($plan['plan_name']); ?> - ₹<?php echo number_format($plan['cost'], 2); ?> (<?php echo $plan['validity_days']; ?> Days)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4 border-t border-yellow-200 pt-4">
                        <div>
                            <label class="block text-[11px] text-gray-600 font-bold mb-1 uppercase tracking-wider">
                                <i class="fa-regular fa-calendar text-gray-400 mr-1"></i> Start Date *
                            </label>
                            <input type="date" name="start_date" id="startDate" value="<?php echo date('Y-m-d'); ?>" required onchange="calculateEndDate()" class="w-full border-2 border-white focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-white outline-none transition shadow-sm cursor-pointer text-gray-700 font-semibold">
                        </div>
                        <div class="bg-white rounded-xl border border-dashed border-yellow-400 px-3 py-2.5 flex flex-col justify-center items-center">
                            <label class="block text-[10px] text-red-400 font-bold mb-0.5 uppercase tracking-wider">Auto-Calculated End Date</label>
                            <span id="endDateDisplay" class="text-red-500 font-mono font-bold text-sm tracking-wider">-- -- ----</span>
                        </div>
                    </div>
                </div>

                <div class="border-t pt-4">
                    <h4 class="text-[11px] text-gray-400 font-bold mb-3 uppercase tracking-wider"><i class="fa-solid fa-address-book mr-1"></i> Client Details</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[11px] text-gray-500 font-semibold mb-1">Full Name *</label>
                            <input type="text" name="client_name" required placeholder="John Doe" class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition">
                        </div>
                        <div>
                            <label class="block text-[11px] text-gray-500 font-semibold mb-1">Contact Number *</label>
                            <input type="text" name="client_contact" required placeholder="+91 xxxxxxxxxx" class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] text-gray-500 font-semibold mb-1">Email Address (Optional)</label>
                    <input type="email" name="client_email" placeholder="client@example.com" class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition">
                </div>

                <div>
                    <label class="block text-[11px] text-gray-500 font-semibold mb-1">Full Address (Optional)</label>
                    <textarea name="client_address" rows="2" placeholder="e.g. Bank More, Dhanbad, Jharkhand" class="w-full border-2 border-[#e5e7eb] focus:border-yellow-400 rounded-xl px-3 py-2.5 text-[13px] bg-[#fafafa] outline-none transition custom-scroll"></textarea>
                </div>

                <div class="pt-4 mt-2 border-t flex justify-end gap-3 sticky bottom-0 bg-white">
                    <button type="button" onclick="closeModal('issueModal')" class="px-5 py-2.5 rounded-xl border text-[12px] font-semibold text-gray-600 hover:bg-gray-50 transition">Cancel</button>
                    <button type="submit" class="bg-[#1f1f1f] hover:bg-black text-yellow-400 px-6 py-2.5 rounded-xl text-[12px] font-semibold uppercase tracking-wider transition shadow-md">
                        Generate & Assign Plan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="cardModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="modal-content w-full max-w-sm rounded-[24px] shadow-2xl overflow-hidden relative vip-card-bg">
            <button onclick="closeModal('cardModal')" class="absolute top-4 right-4 text-gray-400 hover:text-white transition z-10"><i class="fa-solid fa-xmark text-xl"></i></button>
            
            <div class="p-8 relative overflow-hidden">
                <i class="fa-solid fa-crown absolute text-[120px] text-[#ffffff05] -top-5 -right-5 rotate-12"></i>
                
                <div class="flex items-center gap-3 mb-6 relative z-10">
                    <div class="w-12 h-12 bg-[#222] rounded-xl flex items-center justify-center border border-[#444]">
                        <i class="fa-solid fa-gem text-yellow-500 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-white font-bold tracking-widest uppercase text-sm">VIP Pass</h3>
                        <p class="text-yellow-500 text-[10px] uppercase font-bold tracking-widest" id="card_plan_name"></p>
                    </div>
                </div>

                <div class="space-y-4 relative z-10">
                    <div>
                        <p class="text-[10px] text-gray-400 uppercase tracking-widest mb-1">Card Holder</p>
                        <p class="text-white text-lg font-bold" id="card_client_name"></p>
                        <p class="text-gray-300 text-xs mt-0.5"><i class="fa-solid fa-phone text-[10px] mr-1"></i> <span id="card_client_contact"></span></p>
                    </div>

                    <div class="bg-[#222] border border-[#444] rounded-xl p-3 text-center mt-4">
                        <p class="text-[9px] text-gray-400 uppercase tracking-widest mb-1">Unique Membership ID</p>
                        <p class="text-yellow-400 font-mono font-bold tracking-widest text-sm" id="card_code"></p>
                    </div>

                    <div class="flex justify-between items-center border-t border-[#444] pt-4 mt-4">
                        <div>
                            <p class="text-[9px] text-gray-400 uppercase tracking-widest mb-1">Valid From</p>
                            <p class="text-white text-xs font-mono" id="card_start"></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[9px] text-gray-400 uppercase tracking-widest mb-1">Valid Until</p>
                            <p class="text-white text-xs font-mono" id="card_end"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script>
        // Initialize AOS animations
        AOS.init({ duration: 600, once: true });

        // -- Modal Utility --
        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        // -- Auto Calculate End Date --
        function calculateEndDate() {
            const planSelect = document.getElementById('planSelect');
            const startDateInput = document.getElementById('startDate');
            const endDateDisplay = document.getElementById('endDateDisplay');

            const selectedOption = planSelect.options[planSelect.selectedIndex];
            const validityDays = parseInt(selectedOption.getAttribute('data-validity')) || 0;
            const startDateValue = startDateInput.value;

            if (validityDays > 0 && startDateValue) {
                const startDate = new Date(startDateValue);
                // Add the days to the date object
                startDate.setDate(startDate.getDate() + validityDays);
                
                // Format for display (e.g., 25 Oct 2024)
                const options = { day: '2-digit', month: 'short', year: 'numeric' };
                endDateDisplay.innerText = startDate.toLocaleDateString('en-GB', options);
            } else {
                endDateDisplay.innerText = '-- -- ----';
            }
        }
        
        // Run once on load just in case plan is pre-selected (browser caching)
        document.addEventListener('DOMContentLoaded', calculateEndDate);

        // -- View Digital Card Logic --
        function viewCard(data) {
            document.getElementById('card_plan_name').innerText = data.plan_name;
            document.getElementById('card_client_name').innerText = data.client_name;
            document.getElementById('card_client_contact').innerText = data.client_contact;
            document.getElementById('card_code').innerText = data.membership_code;
            
            // Format Dates
            const startDate = new Date(data.start_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
            const endDate = new Date(data.end_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
            
            document.getElementById('card_start').innerText = startDate;
            document.getElementById('card_end').innerText = endDate;

            openModal('cardModal');
        }
    </script>
</body>
</html>
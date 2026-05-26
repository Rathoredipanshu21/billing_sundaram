<?php
session_start();
include '../config/db.php';

// Fetch a mapping of all services (ID => Name) so we can display actual names instead of IDs
$servicesMap = [];
$servResult = $conn->query("SELECT id, service_name FROM services");
if ($servResult) {
    while ($row = $servResult->fetch_assoc()) {
        $servicesMap[$row['id']] = $row['service_name'];
    }
}

// Fetch all issued (redeemed) subscriptions with their master plan details
$query = "
    SELECT 
        cs.*, 
        s.plan_name, 
        s.discount_percent, 
        s.service_benefits as total_benefits 
    FROM client_subscriptions cs 
    JOIN subscriptions s ON cs.subscription_plan_id = s.id 
    ORDER BY cs.id DESC
";
$redeemedResult = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redeemed Subscriptions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f4f4; }
        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        /* Modal Transitions */
        .modal { transition: opacity 0.3s ease, visibility 0.3s ease; }
        .modal-content { transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .modal.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
        .modal.hidden .modal-content { transform: scale(0.9) translateY(20px); }
    </style>
</head>
<body class="h-screen overflow-hidden p-4 flex flex-col">

    <div class="bg-white rounded-[24px] border shadow-sm p-5 mb-4 shrink-0 flex justify-between items-center" data-aos="fade-down">
        <div>
            <h1 class="text-[18px] font-bold text-[#1f1f1f] uppercase tracking-wider flex items-center gap-2">
                <i class="fa-solid fa-receipt text-yellow-500"></i> Redeemed Subscriptions
            </h1>
            <p class="text-[12px] text-gray-500 mt-1">Track client memberships, active statuses, and remaining service quotas.</p>
        </div>
        <div class="bg-yellow-50 text-yellow-700 px-4 py-2 rounded-xl border border-yellow-200 font-semibold text-[12px] shadow-sm">
            <i class="fa-solid fa-chart-pie mr-1"></i> Total Issued: <?php echo $redeemedResult->num_rows; ?>
        </div>
    </div>

    <div class="bg-white rounded-[24px] border shadow-sm flex-1 overflow-hidden flex flex-col" data-aos="fade-up" data-aos-delay="100">
        <div class="flex-1 overflow-y-auto custom-scroll p-4">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b text-[10px] uppercase tracking-widest text-gray-400 bg-[#fafafa]">
                        <th class="p-4 font-bold rounded-tl-xl w-40">Mem Code</th>
                        <th class="p-4 font-bold">Client Details</th>
                        <th class="p-4 font-bold">Subscribed Plan</th>
                        <th class="p-4 font-bold text-center">Validity Range</th>
                        <th class="p-4 font-bold text-center">Status</th>
                        <th class="p-4 font-bold text-right rounded-tr-xl">Action</th>
                    </tr>
                </thead>
                <tbody class="text-[13px] text-neutral-800">
                    <?php if ($redeemedResult->num_rows > 0): ?>
                        <?php while ($row = $redeemedResult->fetch_assoc()): ?>
                            <?php 
                                // Auto-expire logic for display
                                $isExpired = (strtotime($row['end_date']) < strtotime(date('Y-m-d')));
                                $displayStatus = $row['status'];
                                if ($displayStatus === 'Active' && $isExpired) {
                                    $displayStatus = 'Expired';
                                }

                                // Handle NULL remaining benefits (means none used yet)
                                if (empty($row['remaining_benefits'])) {
                                    $row['remaining_benefits'] = $row['total_benefits'];
                                }
                            ?>
                            <tr class="border-b border-gray-100 hover:bg-gray-50/80 transition">
                                <td class="p-4">
                                    <div class="bg-[#111] text-yellow-400 font-mono text-[11px] font-bold px-3 py-1.5 rounded-lg inline-flex items-center gap-2 shadow-sm">
                                        <i class="fa-solid fa-hashtag text-[9px] text-gray-500"></i> <?php echo htmlspecialchars($row['membership_code']); ?>
                                    </div>
                                </td>
                                <td class="p-4">
                                    <div class="font-bold text-[#1f1f1f] text-[13px] mb-0.5"><?php echo htmlspecialchars($row['client_name']); ?></div>
                                    <div class="text-[11px] text-gray-500 font-medium"><i class="fa-solid fa-phone text-gray-400 mr-1 text-[10px]"></i> <?php echo htmlspecialchars($row['client_contact']); ?></div>
                                </td>
                                <td class="p-4">
                                    <div class="font-bold text-emerald-700 flex items-center gap-1.5">
                                        <i class="fa-solid fa-crown text-yellow-500"></i> <?php echo htmlspecialchars($row['plan_name']); ?>
                                    </div>
                                    <div class="text-[10px] uppercase font-bold text-gray-400 mt-1">Disc: <span class="text-emerald-500"><?php echo floatval($row['discount_percent']); ?>%</span></div>
                                </td>
                                <td class="p-4 text-center">
                                    <div class="text-[11px] font-medium text-gray-600 mb-0.5">Start: <?php echo date('d M Y', strtotime($row['start_date'])); ?></div>
                                    <div class="text-[11px] font-bold text-rose-500">End: <?php echo date('d M Y', strtotime($row['end_date'])); ?></div>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if ($displayStatus === 'Active'): ?>
                                        <span class="bg-emerald-50 text-emerald-600 border border-emerald-200 px-3 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest shadow-sm">Active</span>
                                    <?php elseif ($displayStatus === 'Expired'): ?>
                                        <span class="bg-orange-50 text-orange-600 border border-orange-200 px-3 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest shadow-sm">Expired</span>
                                    <?php else: ?>
                                        <span class="bg-rose-50 text-rose-600 border border-rose-200 px-3 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest shadow-sm">Revoked</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-right">
                                    <button onclick='openDetailsModal(<?php echo json_encode($row); ?>)' class="bg-gray-100 hover:bg-[#111] text-gray-600 hover:text-yellow-400 px-4 py-2 rounded-xl text-[11px] font-bold uppercase tracking-wider transition shadow-sm flex items-center justify-center ml-auto gap-2">
                                        <i class="fa-solid fa-eye"></i> View Details
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="p-16 text-center text-gray-400">
                                <i class="fa-solid fa-box-open text-4xl mb-4 text-gray-300"></i>
                                <p class="uppercase tracking-widest text-[12px] font-bold">No Subscriptions Redeemed Yet</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <div id="detailsModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div class="modal-content bg-white w-full max-w-3xl rounded-[24px] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
            
            <div class="px-6 py-4 bg-[#111111] flex justify-between items-center border-b border-neutral-800 shrink-0">
                <h3 class="font-bold text-white text-[15px] uppercase tracking-widest flex items-center gap-2">
                    <i class="fa-solid fa-chart-pie text-yellow-500"></i> Membership Usage Details
                </h3>
                <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-rose-400 transition text-xl"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="p-6 overflow-y-auto custom-scroll flex-1 bg-[#fcfcfc]">
                
                <div class="bg-white border border-gray-200 rounded-2xl p-5 mb-6 shadow-sm flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 bg-yellow-50 rounded-full border-2 border-yellow-400 flex justify-center items-center shrink-0">
                            <i class="fa-solid fa-user-check text-yellow-600 text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-[18px] font-bold text-[#111]" id="modClientName"></h2>
                            <p class="text-[12px] text-gray-500 font-mono mt-0.5" id="modClientContact"></p>
                        </div>
                    </div>
                    <div class="text-right border-l border-gray-100 pl-6">
                        <p class="text-[10px] uppercase font-bold text-gray-400 tracking-widest mb-1">Active Package</p>
                        <p class="text-[14px] font-bold text-emerald-600 flex items-center justify-end gap-1.5">
                            <i class="fa-solid fa-crown text-yellow-500 text-[12px]"></i> <span id="modPlanName"></span>
                        </p>
                        <p class="text-[11px] font-mono font-bold text-neutral-800 mt-1" id="modMemCode"></p>
                    </div>
                </div>

                <div>
                    <div class="flex justify-between items-center border-b border-gray-200 pb-3 mb-4">
                        <h4 class="text-[12px] font-bold uppercase tracking-widest text-gray-600 flex items-center gap-2">
                            <i class="fa-solid fa-layer-group text-blue-500"></i> Service Quota Breakdown
                        </h4>
                        <div class="bg-emerald-50 text-emerald-600 px-3 py-1 rounded border border-emerald-200 text-[10px] font-bold uppercase tracking-widest shadow-sm" id="modGlobalDiscount">
                            </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
                        <table class="w-full text-left">
                            <thead class="bg-gray-50 border-b border-gray-200 text-[10px] uppercase tracking-widest text-gray-500 font-bold">
                                <tr>
                                    <th class="p-3 pl-4">Service Included</th>
                                    <th class="p-3 text-center w-24">Total</th>
                                    <th class="p-3 text-center w-24">Used</th>
                                    <th class="p-3 text-center w-24">Remaining</th>
                                </tr>
                            </thead>
                            <tbody id="benefitsTableBody" class="text-[13px] font-medium">
                                </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <div class="px-6 py-4 bg-white border-t border-gray-200 flex justify-end shrink-0 rounded-b-[24px]">
                <button onclick="closeDetailsModal()" class="px-6 py-2.5 rounded-xl bg-[#111] hover:bg-black text-white text-[12px] font-bold uppercase tracking-wider transition shadow-md">
                    Close Details
                </button>
            </div>
        </div>
    </div>


    <script>
        // Initialize AOS Animations
        AOS.init({ duration: 600, once: true });

        // Parse PHP mapping to JS
        const servicesMap = <?php echo json_encode($servicesMap); ?>;

        // Modal Logic
        const modal = document.getElementById('detailsModal');

        function openDetailsModal(data) {
            // Set Header Info
            document.getElementById('modClientName').innerText = data.client_name;
            document.getElementById('modClientContact').innerText = "+91 " + data.client_contact;
            document.getElementById('modPlanName').innerText = data.plan_name;
            document.getElementById('modMemCode').innerText = data.membership_code;

            // Set Global Discount
            if (parseFloat(data.discount_percent) > 0) {
                document.getElementById('modGlobalDiscount').innerText = `Global Discount: ${data.discount_percent}%`;
                document.getElementById('modGlobalDiscount').style.display = 'block';
            } else {
                document.getElementById('modGlobalDiscount').style.display = 'none';
            }

            // Parse Benefits JSON
            let totalBenefits = [];
            let remainingBenefits = [];
            
            try { totalBenefits = JSON.parse(data.total_benefits || '[]'); } catch(e){}
            try { remainingBenefits = JSON.parse(data.remaining_benefits || '[]'); } catch(e){}

            // Map Remaining benefits for quick lookup
            let remainingMap = {};
            remainingBenefits.forEach(rb => {
                remainingMap[rb.service_id] = parseInt(rb.qty);
            });

            // Populate Table
            const tbody = document.getElementById('benefitsTableBody');
            tbody.innerHTML = '';

            if (totalBenefits.length > 0) {
                totalBenefits.forEach(tb => {
                    const sId = tb.service_id;
                    const totalQty = parseInt(tb.qty);
                    const remainQty = remainingMap[sId] !== undefined ? remainingMap[sId] : 0;
                    const usedQty = totalQty - remainQty;
                    
                    const serviceName = servicesMap[sId] || "Unknown Service";

                    // Determine color coding for remaining
                    let remainColorClass = "text-emerald-600 bg-emerald-50 border-emerald-200"; // Plenty left
                    if (remainQty === 0) remainColorClass = "text-rose-500 bg-rose-50 border-rose-200"; // None left
                    else if (remainQty <= (totalQty * 0.3)) remainColorClass = "text-orange-500 bg-orange-50 border-orange-200"; // Running low

                    tbody.innerHTML += `
                        <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50/50 transition">
                            <td class="p-3 pl-4 font-bold text-[#1f1f1f] flex items-center gap-2">
                                <i class="fa-solid fa-check-circle text-gray-300 text-[11px]"></i> ${serviceName}
                            </td>
                            <td class="p-3 text-center font-mono text-gray-600 font-bold">${totalQty}</td>
                            <td class="p-3 text-center font-mono text-gray-600 font-bold">${usedQty}</td>
                            <td class="p-3 text-center">
                                <span class="px-2 py-0.5 rounded border text-[11px] font-bold font-mono ${remainColorClass}">
                                    ${remainQty}
                                </span>
                            </td>
                        </tr>
                    `;
                });
            } else {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="4" class="p-6 text-center text-[12px] text-gray-400 italic">
                            No specific service quotas are included in this plan. Only global discounts apply.
                        </td>
                    </tr>
                `;
            }

            // Show Modal
            modal.classList.remove('hidden');
        }

        function closeDetailsModal() {
            modal.classList.add('hidden');
        }
    </script>
</body>
</html>
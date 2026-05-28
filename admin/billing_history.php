<?php
session_start();
include '../config/db.php'; 

// Fetch all settled invoices from the database, newest first
$invoiceResult = $conn->query("SELECT * FROM invoices ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settled Bills & Invoice History</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #FFFFFF;
            color: #222222;
        }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 6px; }
    </style>
</head>
<body class="p-4 lg:p-8 min-h-screen">

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-8 pb-4 border-b border-gray-100" data-aos="fade-down" data-aos-duration="600">
        <div>
            <h2 class="text-xl font-bold text-neutral-800 tracking-tight flex items-center gap-2">
                <i class="fa-solid fa-list-check text-amber-500"></i>
                <span>Settled Bills & History</span>
            </h2>
            <p class="text-xs text-gray-500 uppercase tracking-wider mt-1 font-medium">Review past transactions and edit or reprint invoices</p>
        </div>
        
        <div class="flex items-center gap-3">
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><i class="fa-solid fa-magnifying-glass text-sm"></i></span>
                <input type="text" id="searchInput" onkeyup="filterLedger()" placeholder="Search Invoice or Client..." 
                    class="bg-gray-50 border border-gray-200 rounded-xl pl-9 pr-4 py-2.5 text-sm font-medium text-neutral-800 outline-none focus:border-[#EBBB15] focus:ring-2 focus:ring-[#EBBB15]/20 transition-all w-72">
            </div>
            <button onclick="window.print()" class="bg-[#222222] hover:bg-neutral-800 text-[#EBBB15] px-5 py-2.5 rounded-xl text-sm font-semibold transition flex items-center gap-2 shadow-sm">
                <i class="fa-solid fa-file-export"></i>
                <span>Export Ledger</span>
            </button>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden" data-aos="fade-up" data-aos-duration="800">
        <div class="overflow-x-auto custom-scroll">
            <table class="w-full text-left border-collapse" id="historyTable">
                <thead>
                    <tr class="bg-gray-50/70 border-b border-gray-100">
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider text-gray-500">Invoice Ref</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider text-gray-500">Timestamp</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider text-gray-500">Client Profile</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider text-gray-500">Clearance Mode</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider text-gray-500 text-right">Gross Value</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider text-gray-500 text-right">Discount</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider text-gray-500 text-right">GST</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider text-gray-500 text-right">Net Settled</th>
                        <th class="px-5 py-4 text-xs font-bold uppercase tracking-wider text-gray-500 text-center">Controls</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm text-neutral-700">
                    <?php if ($invoiceResult && $invoiceResult->num_rows > 0): ?>
                        <?php while($row = $invoiceResult->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50/50 transition group">
                                <td class="px-5 py-4 font-mono text-sm font-semibold text-neutral-800">
                                    <?php echo htmlspecialchars($row['invoice_no']); ?>
                                </td>
                                <td class="px-5 py-4 font-medium text-gray-600">
                                    <?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-bold text-neutral-800 text-sm"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                                    <div class="text-xs text-gray-500 font-mono mt-0.5"><?php echo htmlspecialchars($row['customer_mobile']); ?></div>
                                </td>
                                <td class="px-5 py-4">
                                    <?php 
                                        $mode = $row['payment_mode'];
                                        $badgeColor = 'bg-gray-100 text-gray-600';
                                        $icon = 'fa-money-bill';
                                        
                                        if($mode === 'Cash') { $badgeColor = 'bg-emerald-50 text-emerald-700 border border-emerald-100'; $icon = 'fa-money-bill-wave'; }
                                        if($mode === 'UPI') { $badgeColor = 'bg-indigo-50 text-indigo-700 border border-indigo-100'; $icon = 'fa-qrcode'; }
                                        if($mode === 'Card') { $badgeColor = 'bg-blue-50 text-blue-700 border border-blue-100'; $icon = 'fa-credit-card'; }
                                        if($mode === 'Split') { $badgeColor = 'bg-amber-50 text-amber-700 border border-amber-100'; $icon = 'fa-layer-group'; }
                                    ?>
                                    <div class="flex flex-col items-start gap-1.5">
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold uppercase <?php echo $badgeColor; ?>">
                                            <i class="fa-solid <?php echo $icon; ?>"></i>
                                            <?php echo htmlspecialchars($mode); ?>
                                        </span>
                                        <?php if ($mode === 'Split'): ?>
                                            <div class="text-xs text-gray-600 font-mono leading-relaxed mt-1">
                                                <?php 
                                                $splits = [];
                                                if ($row['split_cash'] > 0) $splits[] = "Cash: ₹".number_format($row['split_cash'], 2);
                                                if ($row['split_upi'] > 0) $splits[] = "UPI: ₹".number_format($row['split_upi'], 2);
                                                if ($row['split_card'] > 0) $splits[] = "Card: ₹".number_format($row['split_card'], 2);
                                                echo implode('<br>', $splits);
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-right text-gray-600 font-mono font-medium">
                                    ₹<?php echo number_format($row['total_amount'], 2); ?>
                                </td>
                                <td class="px-5 py-4 text-right font-mono">
                                    <?php if ($row['discount_percent'] > 0 || $row['discount'] > 0): ?>
                                        <div class="text-rose-500 font-semibold"><?php echo floatval($row['discount_percent']); ?>%</div>
                                        <div class="text-xs text-gray-400 mt-0.5">-₹<?php echo number_format($row['discount'], 2); ?></div>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 text-right font-mono">
                                    <?php if ($row['gst_enabled'] == 1 && $row['gst_amount'] > 0): ?>
                                        <div class="text-emerald-600 font-semibold">+₹<?php echo number_format($row['gst_amount'], 2); ?></div>
                                        <div class="text-xs text-gray-400 mt-0.5">(<?php echo floatval($row['gst_percent']); ?>%)</div>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 text-right font-mono font-bold text-neutral-900 text-base">
                                    ₹<?php echo number_format($row['net_payable'], 2); ?>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <button onclick="window.open('print_invoice.php?id=<?php echo $row['id']; ?>', '_blank')" class="w-9 h-9 rounded-xl bg-gray-50 border border-gray-200 text-gray-500 hover:text-[#EBBB15] hover:border-[#EBBB15] hover:bg-[#FFFDF5] transition flex items-center justify-center shadow-sm" title="Print Receipt">
                                            <i class="fa-solid fa-print text-sm"></i>
                                        </button>
                                        
                                        <button onclick="window.location.href='edit_invoice.php?id=<?php echo $row['id']; ?>'" class="w-9 h-9 rounded-xl bg-blue-50 border border-blue-200 text-blue-500 hover:text-white hover:border-blue-600 hover:bg-blue-600 transition flex items-center justify-center shadow-sm" title="Edit Invoice">
                                            <i class="fa-solid fa-pen-to-square text-sm"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="py-20 text-center bg-gray-50/30 border-none">
                                <div class="w-14 h-14 rounded-2xl bg-white border border-gray-200 text-gray-400 flex items-center justify-center mb-4 mx-auto shadow-sm">
                                    <i class="fa-solid fa-receipt text-xl"></i>
                                </div>
                                <h4 class="text-sm font-bold text-neutral-700 uppercase tracking-wider">No Settled Bills Found</h4>
                                <p class="text-xs text-gray-500 mt-2 max-w-md mx-auto leading-relaxed">Transactions generated from the Counter Desk will be permanently logged in this history matrix for auditing.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        AOS.init({ once: true });

        function filterLedger() {
            const input = document.getElementById("searchInput");
            const filter = input.value.toUpperCase();
            const table = document.getElementById("historyTable");
            const tr = table.getElementsByTagName("tr");

            for (let i = 1; i < tr.length; i++) { // Skip header row
                let tdInvoice = tr[i].getElementsByTagName("td")[0];
                let tdClient = tr[i].getElementsByTagName("td")[2];
                
                if (tdInvoice || tdClient) {
                    let txtValueInvoice = tdInvoice.textContent || tdInvoice.innerText;
                    let txtValueClient = tdClient.textContent || tdClient.innerText;
                    
                    if (txtValueInvoice.toUpperCase().indexOf(filter) > -1 || txtValueClient.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }       
            }
        }
    </script>
</body>
</html>
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #FFFFFF;
            color: #222222;
        }
        .custom-scroll::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 4px; }
    </style>
</head>
<body class="p-4 lg:p-6 min-h-screen">

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-8 pb-4 border-b border-gray-100" data-aos="fade-down" data-aos-duration="600">
        <div>
            <h2 class="text-base font-medium text-neutral-800 tracking-tight flex items-center gap-2">
                <i class="fa-solid fa-list-check text-amber-500"></i>
                <span>Settled Bills & History</span>
            </h2>
            <p class="text-[11px] text-gray-400 uppercase tracking-wider mt-0.5">Review past transactions and generate reprints</p>
        </div>
        
        <div class="flex items-center gap-3">
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400"><i class="fa-solid fa-magnifying-glass text-xs"></i></span>
                <input type="text" id="searchInput" onkeyup="filterLedger()" placeholder="Search Invoice or Client..." 
                    class="bg-gray-50 border border-gray-200 rounded-xl pl-8 pr-4 py-2 text-xs text-neutral-800 outline-none focus:border-[#EBBB15] focus:ring-2 focus:ring-[#EBBB15]/20 transition-all w-64">
            </div>
            <button onclick="window.print()" class="bg-[#222222] hover:bg-neutral-800 text-[#EBBB15] px-4 py-2 rounded-xl text-xs font-medium transition flex items-center gap-2 shadow-sm">
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
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Invoice Ref</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Timestamp</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Client Profile</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Clearance Mode</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 text-right">Gross Value</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 text-right">Discount</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 text-right">Net Settled</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 text-center">Controls</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-xs text-neutral-700">
                    <?php if ($invoiceResult && $invoiceResult->num_rows > 0): ?>
                        <?php while($row = $invoiceResult->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="px-5 py-4 font-mono text-[11px] font-medium text-neutral-800">
                                    <?php echo htmlspecialchars($row['invoice_no']); ?>
                                </td>
                                <td class="px-5 py-4 text-gray-500">
                                    <?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-medium text-neutral-800"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                                    <div class="text-[10px] text-gray-400 font-mono mt-0.5"><?php echo htmlspecialchars($row['customer_mobile']); ?></div>
                                </td>
                                <td class="px-5 py-4">
                                    <?php 
                                        $mode = $row['payment_mode'];
                                        $badgeColor = 'bg-gray-100 text-gray-600';
                                        $icon = 'fa-money-bill';
                                        
                                        if($mode === 'Cash') { $badgeColor = 'bg-emerald-50 text-emerald-700 border border-emerald-100'; $icon = 'fa-money-bill-wave'; }
                                        if($mode === 'UPI') { $badgeColor = 'bg-indigo-50 text-indigo-700 border border-indigo-100'; $icon = 'fa-qrcode'; }
                                        if($mode === 'Card') { $badgeColor = 'bg-blue-50 text-blue-700 border border-blue-100'; $icon = 'fa-credit-card'; }
                                    ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-medium uppercase <?php echo $badgeColor; ?>">
                                        <i class="fa-solid <?php echo $icon; ?>"></i>
                                        <?php echo htmlspecialchars($mode); ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-right text-gray-500 font-mono">
                                    ₹<?php echo number_format($row['total_amount'], 2); ?>
                                </td>
                                <td class="px-5 py-4 text-right text-rose-500 font-mono">
                                    <?php echo $row['discount_percent'] > 0 ? $row['discount_percent'] . '%' : '-'; ?> 
                                    <span class="text-[10px] text-gray-400">(₹<?php echo number_format($row['discount'], 2); ?>)</span>
                                </td>
                                <td class="px-5 py-4 text-right font-mono font-semibold text-neutral-800">
                                    ₹<?php echo number_format($row['net_payable'], 2); ?>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <button onclick="window.open('print_invoice.php?id=<?php echo $row['id']; ?>', '_blank')" class="w-8 h-8 rounded-lg bg-gray-50 border border-gray-200 text-gray-400 hover:text-[#EBBB15] hover:border-[#EBBB15] hover:bg-[#FFFDF5] transition flex items-center justify-center mx-auto shadow-sm" title="Print Receipt">
                                        <i class="fa-solid fa-print text-xs"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="py-16 text-center bg-gray-50/30 border-none">
                                <div class="w-12 h-12 rounded-xl bg-white border border-gray-100 text-gray-300 flex items-center justify-center mb-3 mx-auto shadow-sm">
                                    <i class="fa-solid fa-receipt text-lg"></i>
                                </div>
                                <h4 class="text-xs font-medium text-neutral-600 uppercase tracking-wider">No Settled Bills Found</h4>
                                <p class="text-[11px] text-gray-400 mt-1 max-w-sm mx-auto leading-relaxed">Transactions generated from the Counter Desk will be permanently logged in this history matrix for auditing.</p>
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
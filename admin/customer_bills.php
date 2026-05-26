<?php
// Note: If you still get a completely blank screen, uncomment the next 3 lines to force PHP to show the error.
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

include '../config/db.php';

// Check if mobile number is provided
if (!isset($_GET['mobile']) || empty($_GET['mobile'])) {
    die("<div style='padding: 20px; font-family: sans-serif;'>Error: Customer Mobile Number is required. <a href='customers.php' style='color: blue; text-decoration: underline;'>Go Back</a></div>");
}

$mobile = mysqli_real_escape_string($conn, $_GET['mobile']);

// 1. Fetch Customer Summary
$summaryQuery = mysqli_query($conn, "
    SELECT 
        MAX(customer_name) as name, 
        customer_mobile, 
        COUNT(id) as total_visits, 
        COALESCE(SUM(net_payable), 0) as total_spent,
        COALESCE(SUM(loyalty_points_earned), 0) as total_loyalty_earned
    FROM invoices 
    WHERE customer_mobile = '$mobile'
");

if (!$summaryQuery) {
    die("<div style='padding: 20px; color: red; font-family: monospace;'>Summary Query Error: " . mysqli_error($conn) . "</div>");
}

$customer = mysqli_fetch_assoc($summaryQuery);

// If customer doesn't exist/no bills
if (empty($customer['customer_mobile'])) {
    die("<div style='padding: 20px; font-family: sans-serif;'>No billing history found for this mobile number. <a href='customers.php' style='color: blue; text-decoration: underline;'>Go Back</a></div>");
}

// 2. Fetch All Invoices for this Customer
$invoicesQuery = mysqli_query($conn, "
    SELECT * FROM invoices 
    WHERE customer_mobile = '$mobile' 
    ORDER BY created_at DESC
");

if (!$invoicesQuery) {
    die("<div style='padding: 20px; color: red; font-family: monospace;'>Invoices Query Error: " . mysqli_error($conn) . "</div>");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Billing History</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="p-4 md:p-6 text-[#1f2937]">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-4">
            <a href="customers.php" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 w-10 h-10 rounded-lg flex items-center justify-center transition shadow-sm">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h1 class="text-[18px] font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-file-invoice-dollar text-gray-500"></i> Billing History
                </h1>
                <p class="text-[12px] text-gray-500 mt-1 font-medium">
                    View all past transactions and invoices for this client.
                </p>
            </div>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 mb-6 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-full bg-gray-100 border border-gray-200 text-gray-600 flex items-center justify-center text-[20px] font-bold shrink-0">
                <?php echo strtoupper(substr($customer['name'], 0, 1)); ?>
            </div>
            <div>
                <h2 class="text-[18px] font-bold text-gray-900 leading-tight"><?php echo htmlspecialchars($customer['name'] ?: 'Unknown Client'); ?></h2>
                <div class="text-[13px] font-mono text-gray-500 mt-0.5 flex items-center gap-1.5">
                    <i class="fa-solid fa-phone text-[10px]"></i> <?php echo htmlspecialchars($customer['customer_mobile']); ?>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-6 md:gap-10">
            <div>
                <div class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-1">Total Visits</div>
                <div class="text-[18px] font-bold text-gray-900"><?php echo $customer['total_visits']; ?></div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-1">Total Spent</div>
                <div class="text-[18px] font-bold font-mono text-emerald-600">₹<?php echo number_format($customer['total_spent'], 2); ?></div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-1">Loyalty Earned</div>
                <div class="text-[18px] font-bold font-mono text-purple-600 flex items-center gap-1.5">
                    <i class="fa-solid fa-star text-[12px]"></i> <?php echo number_format($customer['total_loyalty_earned'], 2); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
            <h2 class="text-[13px] font-bold text-gray-700 uppercase tracking-wider">
                Transaction Records
            </h2>
        </div>

        <div class="overflow-x-auto custom-scroll">
            <table class="w-full text-left border-collapse">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Date</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Invoice No.</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Gross Amt</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Discount</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">GST</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Net Paid</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Payment Mode</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php while($invoice = mysqli_fetch_assoc($invoicesQuery)): ?>
                    <tr class="hover:bg-gray-50/50 transition">
                        
                        <td class="px-5 py-4">
                            <div class="text-[13px] font-semibold text-gray-900">
                                <?php echo date('d M Y', strtotime($invoice['created_at'])); ?>
                            </div>
                            <div class="text-[11px] text-gray-500 mt-0.5">
                                <?php echo date('h:i A', strtotime($invoice['created_at'])); ?>
                            </div>
                        </td>

                        <td class="px-5 py-4">
                            <div class="text-[13px] font-bold font-mono text-blue-600">
                                <?php echo htmlspecialchars($invoice['invoice_no']); ?>
                            </div>
                        </td>

                        <td class="px-5 py-4">
                            <div class="text-[13px] font-medium text-gray-600">
                                ₹<?php echo number_format($invoice['total_amount'], 2); ?>
                            </div>
                        </td>

                        <td class="px-5 py-4">
                            <?php if($invoice['discount'] > 0): ?>
                                <div class="text-[12px] font-medium text-rose-500">
                                    -₹<?php echo number_format($invoice['discount'], 2); ?> 
                                    <span class="text-[10px] text-gray-400">(<?php echo floatval($invoice['discount_percent']); ?>%)</span>
                                </div>
                            <?php else: ?>
                                <span class="text-[12px] text-gray-400">-</span>
                            <?php endif; ?>
                        </td>

                        <td class="px-5 py-4">
                            <?php if($invoice['gst_enabled']): ?>
                                <div class="text-[12px] font-medium text-gray-600">
                                    +₹<?php echo number_format($invoice['gst_amount'], 2); ?>
                                    <span class="text-[10px] text-gray-400">(<?php echo floatval($invoice['gst_percent']); ?>%)</span>
                                </div>
                            <?php else: ?>
                                <span class="text-[12px] text-gray-400">No GST</span>
                            <?php endif; ?>
                        </td>

                        <td class="px-5 py-4">
                            <div class="text-[14px] font-bold font-mono text-gray-900">
                                ₹<?php echo number_format($invoice['net_payable'], 2); ?>
                            </div>
                        </td>

                        <td class="px-5 py-4">
                            <?php 
                                $mode = $invoice['payment_mode'];
                                $badgeClass = "bg-gray-100 text-gray-600 border-gray-200"; // default
                                
                                if($mode === 'Cash') $badgeClass = "bg-emerald-50 text-emerald-600 border-emerald-200";
                                if($mode === 'UPI') $badgeClass = "bg-blue-50 text-blue-600 border-blue-200";
                                if($mode === 'Card') $badgeClass = "bg-purple-50 text-purple-600 border-purple-200";
                                if($mode === 'Split') $badgeClass = "bg-orange-50 text-orange-600 border-orange-200";
                            ?>
                            <span class="border px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider <?php echo $badgeClass; ?>">
                                <?php echo htmlspecialchars($mode); ?>
                            </span>
                            
                            <?php if($mode === 'Split'): ?>
                                <div class="text-[9px] text-gray-500 mt-1 leading-tight">
                                    C: ₹<?php echo number_format($invoice['split_cash'],0); ?> | 
                                    U: ₹<?php echo number_format($invoice['split_upi'],0); ?> | 
                                    Cr: ₹<?php echo number_format($invoice['split_card'],0); ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td class="px-5 py-4 text-right">
                            <a href="print_invoice.php?id=<?php echo $invoice['id']; ?>" target="_blank"
                               class="inline-flex items-center gap-1.5 px-4 py-2 bg-[#111] hover:bg-black text-yellow-400 text-[11px] font-bold uppercase tracking-wider rounded-lg transition shadow-sm">
                                <i class="fa-solid fa-print text-[12px]"></i> Print
                            </a>
                        </td>

                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            
            <?php if(mysqli_num_rows($invoicesQuery) == 0): ?>
                <div class="text-center py-10">
                    <p class="text-[13px] text-gray-500 font-medium">No invoices found for this customer.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>
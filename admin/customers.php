<?php
include '../config/db.php';

/*
|--------------------------------------------------------------------------
| CUSTOMER SUMMARY
|--------------------------------------------------------------------------
*/

// Updated query to safely fetch loyalty points using a subquery
$customerQuery = mysqli_query($conn,"
    SELECT
        i.customer_mobile,
        MAX(i.customer_name) as customer_name,
        COUNT(i.id) as total_visits,
        COALESCE(SUM(i.net_payable),0) as total_spent,
        MAX(i.created_at) as last_visit,
        MIN(i.created_at) as first_visit,
        COALESCE((SELECT total_points FROM customer_loyalty WHERE customer_mobile = i.customer_mobile LIMIT 1), 0) as loyalty_points
    FROM invoices i
    WHERE i.customer_mobile != ''
    GROUP BY i.customer_mobile
    ORDER BY total_spent DESC
");

/*
|--------------------------------------------------------------------------
| TOTAL STATS
|--------------------------------------------------------------------------
*/

$totalCustomers = mysqli_fetch_assoc(mysqli_query($conn,"
    SELECT COUNT(DISTINCT customer_mobile) as total
    FROM invoices
    WHERE customer_mobile != ''
"))['total'];

$totalRevenue = mysqli_fetch_assoc(mysqli_query($conn,"
    SELECT COALESCE(SUM(net_payable),0) as total
    FROM invoices
"))['total'];

$totalVisits = mysqli_fetch_assoc(mysqli_query($conn,"
    SELECT COUNT(*) as total
    FROM invoices
"))['total'];

$repeatCustomers = mysqli_fetch_assoc(mysqli_query($conn,"
    SELECT COUNT(*) as total FROM (
        SELECT customer_mobile
        FROM invoices
        GROUP BY customer_mobile
        HAVING COUNT(id) > 1
    ) as repeat_data
"))['total'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
        }
        .custom-scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        .custom-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
    </style>
</head>
<body class="p-4 md:p-6 text-[#1f2937]">

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-[18px] font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                <i class="fa-solid fa-users-viewfinder text-gray-500"></i> Customer Directory
            </h1>
            <p class="text-[12px] text-gray-500 mt-1 font-medium">
                Manage client profiles, view billing history, and track loyalty programs.
            </p>
        </div>
        <div class="bg-white border border-gray-200 px-4 py-2 rounded-lg shadow-sm flex items-center gap-3">
            <div class="w-8 h-8 bg-gray-100 rounded-md flex items-center justify-center">
                <i class="fa-solid fa-building text-gray-600 text-[13px]"></i>
            </div>
            <div>
                <div class="text-[9px] uppercase tracking-widest text-gray-400 font-bold">Location</div>
                <div class="text-[13px] font-semibold text-gray-800">Sundaram Salon</div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        
        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center shrink-0 border border-blue-100">
                <i class="fa-solid fa-users text-blue-500 text-[15px]"></i>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-1">Total Customers</div>
                <div class="text-[18px] font-bold text-gray-900 leading-none"><?php echo number_format($totalCustomers); ?></div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center shrink-0 border border-emerald-100">
                <i class="fa-solid fa-wallet text-emerald-500 text-[15px]"></i>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-1">Total Revenue</div>
                <div class="text-[18px] font-bold text-gray-900 leading-none">₹<?php echo number_format($totalRevenue, 2); ?></div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg bg-purple-50 flex items-center justify-center shrink-0 border border-purple-100">
                <i class="fa-solid fa-scissors text-purple-500 text-[15px]"></i>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-1">Total Visits</div>
                <div class="text-[18px] font-bold text-gray-900 leading-none"><?php echo number_format($totalVisits); ?></div>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
            <div class="w-10 h-10 rounded-lg bg-orange-50 flex items-center justify-center shrink-0 border border-orange-100">
                <i class="fa-solid fa-rotate text-orange-500 text-[15px]"></i>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-widest text-gray-500 font-bold mb-1">Repeat Clients</div>
                <div class="text-[18px] font-bold text-gray-900 leading-none"><?php echo number_format($repeatCustomers); ?></div>
            </div>
        </div>

    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden flex flex-col">
        
        <div class="px-5 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
            <h2 class="text-[13px] font-bold text-gray-700 uppercase tracking-wider">
                Client Roster
            </h2>
        </div>

        <div class="overflow-x-auto custom-scroll">
            <table class="w-full text-left border-collapse">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Customer Details</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Visits</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Total Spent</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Loyalty Points</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Last Visit</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Status</th>
                        <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php while($customer = mysqli_fetch_assoc($customerQuery)): ?>
                    <tr class="hover:bg-gray-50/50 transition">
                        
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded bg-gray-100 border border-gray-200 text-gray-600 flex items-center justify-center text-[12px] font-bold shrink-0">
                                    <?php echo strtoupper(substr($customer['customer_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="text-[13px] font-semibold text-gray-900 leading-tight">
                                        <?php echo htmlspecialchars($customer['customer_name'] ?: 'Unknown'); ?>
                                    </div>
                                    <div class="text-[11px] font-mono text-gray-500 mt-0.5">
                                        <?php echo htmlspecialchars($customer['customer_mobile']); ?>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td class="px-5 py-3">
                            <div class="text-[13px] font-semibold text-gray-700">
                                <?php echo $customer['total_visits']; ?>
                            </div>
                        </td>

                        <td class="px-5 py-3">
                            <div class="text-[13px] font-mono font-semibold text-emerald-600">
                                ₹<?php echo number_format($customer['total_spent'], 2); ?>
                            </div>
                        </td>

                        <td class="px-5 py-3">
                            <div class="inline-flex items-center gap-1.5 bg-purple-50 border border-purple-100 text-purple-600 px-2 py-1 rounded text-[11px] font-bold font-mono">
                                <i class="fa-solid fa-star text-[9px]"></i>
                                <?php echo number_format($customer['loyalty_points'], 2); ?>
                            </div>
                        </td>

                        <td class="px-5 py-3">
                            <div class="text-[12px] text-gray-600 font-medium">
                                <?php echo date('d M Y', strtotime($customer['last_visit'])); ?>
                            </div>
                        </td>

                        <td class="px-5 py-3">
                            <?php if($customer['total_visits'] > 3): ?>
                                <span class="bg-emerald-50 text-emerald-600 border border-emerald-200 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider">
                                    Loyal
                                </span>
                            <?php else: ?>
                                <span class="bg-gray-100 text-gray-600 border border-gray-200 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider">
                                    Standard
                                </span>
                            <?php endif; ?>
                        </td>

                        <td class="px-5 py-3 text-right">
                            <a href="customer_bills.php?mobile=<?php echo urlencode($customer['customer_mobile']); ?>" 
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-300 hover:border-gray-400 text-gray-700 text-[11px] font-bold rounded-md transition shadow-sm">
                                <i class="fa-solid fa-file-invoice text-[10px]"></i> Bills
                            </a>
                        </td>

                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>
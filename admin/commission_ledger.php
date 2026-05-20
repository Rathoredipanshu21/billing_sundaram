<?php
session_start();
include '../config/db.php'; 

/*
|--------------------------------------------------------------------------
| TOTAL SUMMARY
|--------------------------------------------------------------------------
*/

$totalEmployeesQuery = mysqli_query($conn,"SELECT COUNT(*) as total FROM stylists WHERE status='Active'");
$totalEmployeesData = mysqli_fetch_assoc($totalEmployeesQuery);
$totalEmployees = $totalEmployeesData['total'];

$totalCommissionQuery = mysqli_query($conn,"SELECT COALESCE(SUM(commission_amount),0) as total FROM stylist_commissions");
$totalCommissionData = mysqli_fetch_assoc($totalCommissionQuery);
$totalCommission = $totalCommissionData['total'];

$totalServicesQuery = mysqli_query($conn,"SELECT COUNT(*) as total FROM stylist_commissions");
$totalServicesData = mysqli_fetch_assoc($totalServicesQuery);
$totalServices = $totalServicesData['total'];

$totalRevenueQuery = mysqli_query($conn,"SELECT COALESCE(SUM(service_amount),0) as total FROM stylist_commissions");
$totalRevenueData = mysqli_fetch_assoc($totalRevenueQuery);
$totalRevenue = $totalRevenueData['total'];

/*
|--------------------------------------------------------------------------
| EMPLOYEE COMMISSION SUMMARY
|--------------------------------------------------------------------------
*/

$employeeQuery = mysqli_query($conn,"
    SELECT s.id, s.stylist_name, s.specialty, s.commission_rate,
    COUNT(sc.id) as total_services,
    COALESCE(SUM(sc.service_amount),0) as service_total,
    COALESCE(SUM(sc.commission_amount),0) as commission_total
    FROM stylists s
    LEFT JOIN stylist_commissions sc ON s.id = sc.stylist_id
    WHERE s.status='Active'
    GROUP BY s.id
    ORDER BY commission_total DESC
");

/*
|--------------------------------------------------------------------------
| COMMISSION HISTORY
|--------------------------------------------------------------------------
*/

$historyQuery = mysqli_query($conn,"
    SELECT sc.*, s.stylist_name, sv.service_name, i.invoice_no, i.customer_name
    FROM stylist_commissions sc
    INNER JOIN stylists s ON sc.stylist_id = s.id
    INNER JOIN services sv ON sc.service_id = sv.id
    INNER JOIN invoices i ON sc.invoice_id = i.id
    ORDER BY sc.id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission Ledger Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #FFFFFF; color: #222222; }
        .custom-scroll::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 4px; }
    </style>
</head>
<body class="p-4 lg:p-6 min-h-screen">

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-8 pb-4 border-b border-gray-100" data-aos="fade-down" data-aos-duration="600">
        <div>
            <h2 class="text-base font-medium text-neutral-800 tracking-tight flex items-center gap-2">
                <i class="fa-solid fa-percent text-amber-500"></i>
                <span>Commission Split Ledger</span>
            </h2>
            <p class="text-[11px] text-gray-400 uppercase tracking-wider mt-0.5">Track staff service earnings and incentive payouts</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8" data-aos="fade-up" data-aos-duration="800">
        <div class="bg-white border border-gray-100 p-5 rounded-2xl shadow-sm">
            <div class="text-[10px] uppercase tracking-wider text-gray-400">Total Active Employees</div>
            <div class="text-2xl font-bold mt-1 text-neutral-800"><?php echo $totalEmployees; ?></div>
        </div>
        <div class="bg-white border border-gray-100 p-5 rounded-2xl shadow-sm">
            <div class="text-[10px] uppercase tracking-wider text-gray-400">Total Services Logged</div>
            <div class="text-2xl font-bold mt-1 text-neutral-800"><?php echo $totalServices; ?></div>
        </div>
        <div class="bg-white border border-gray-100 p-5 rounded-2xl shadow-sm">
            <div class="text-[10px] uppercase tracking-wider text-gray-400">Total Service Revenue</div>
            <div class="text-2xl font-bold mt-1 text-neutral-800">₹<?php echo number_format($totalRevenue,2); ?></div>
        </div>
        <div class="bg-white border border-gray-100 p-5 rounded-2xl shadow-sm">
            <div class="text-[10px] uppercase tracking-wider text-amber-600 font-medium">Total Incentives Paid</div>
            <div class="text-2xl font-bold mt-1 text-neutral-800">₹<?php echo number_format($totalCommission,2); ?></div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-8" data-aos="fade-up" data-aos-delay="200">
        <?php while($employee = mysqli_fetch_assoc($employeeQuery)): ?>
            <div class="bg-white border border-gray-100 rounded-2xl p-5 shadow-sm">
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-12 h-12 rounded-full bg-gray-50 flex items-center justify-center text-amber-500 shadow-sm">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-neutral-800"><?php echo htmlspecialchars($employee['stylist_name']); ?></h3>
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars($employee['specialty']); ?></p>
                    </div>
                </div>
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between"><span class="text-gray-400">Rate:</span> <span class="font-medium"><?php echo $employee['commission_rate']; ?>%</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Services:</span> <span class="font-medium"><?php echo $employee['total_services']; ?></span></div>
                    <div class="flex justify-between pt-2 border-t mt-2"><span class="text-gray-400">Total Incentive:</span> <span class="font-bold text-amber-600">₹<?php echo number_format($employee['commission_total'],2); ?></span></div>
                </div>
            </div>
        <?php endwhile; ?>
    </div>

    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden" data-aos="fade-up" data-aos-delay="400">
        <div class="overflow-x-auto custom-scroll">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50/70 border-b border-gray-100">
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Invoice Ref</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Client</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Employee</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Service</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Incentive</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-xs">
                    <?php while($history = mysqli_fetch_assoc($historyQuery)): ?>
                    <tr class="hover:bg-gray-50/50 transition">
                        <td class="px-5 py-4 font-mono font-medium"><?php echo $history['invoice_no']; ?></td>
                        <td class="px-5 py-4"><?php echo htmlspecialchars($history['customer_name']); ?></td>
                        <td class="px-5 py-4 font-medium"><?php echo htmlspecialchars($history['stylist_name']); ?></td>
                        <td class="px-5 py-4 text-gray-500"><?php echo htmlspecialchars($history['service_name']); ?></td>
                        <td class="px-5 py-4 font-bold text-emerald-600">₹<?php echo number_format($history['commission_amount'],2); ?> <span class="text-[9px] text-gray-400 font-normal">(<?php echo $history['commission_percent']; ?>%)</span></td>
                        <td class="px-5 py-4 text-gray-400"><?php echo date('d M Y, h:i A', strtotime($history['created_at'])); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script> AOS.init({ duration: 700, once: true }); </script>
</body>
</html>
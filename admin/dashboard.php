<?php
session_start();
include '../config/db.php';

/*
|--------------------------------------------------------------------------
| TOTAL STATS
|--------------------------------------------------------------------------
*/

$totalInvoices = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM invoices"))['total'];
$totalRevenue = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(net_payable),0) as total FROM invoices"))['total'];
$totalExpense = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount),0) as total FROM expenses"))['total'];
$totalProfit = $totalRevenue - $totalExpense;
$totalProducts = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM products"))['total'];
$totalServices = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM services"))['total'];
$totalStylists = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM stylists WHERE status='Active'"))['total'];
$totalCategories = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM categories"))['total'];
$totalCommission = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(commission_amount),0) as total FROM stylist_commissions"))['total'];

/* Charts Data */
$salesChartQuery = mysqli_query($conn, "SELECT MONTHNAME(created_at) as month_name, SUM(net_payable) as total FROM invoices GROUP BY MONTH(created_at)");
$salesLabels = []; $salesData = [];
while($s = mysqli_fetch_assoc($salesChartQuery)) { $salesLabels[] = $s['month_name']; $salesData[] = $s['total']; }

$expenseChartQuery = mysqli_query($conn, "SELECT category, SUM(amount) as total FROM expenses GROUP BY category");
$expenseLabels = []; $expenseData = [];
while($e = mysqli_fetch_assoc($expenseChartQuery)) { $expenseLabels[] = $e['category']; $expenseData[] = $e['total']; }

$topStylistsQuery = mysqli_query($conn, "SELECT s.stylist_name, COUNT(sc.id) as total_services, COALESCE(SUM(sc.commission_amount),0) as total_commission FROM stylists s LEFT JOIN stylist_commissions sc ON s.id = sc.stylist_id GROUP BY s.id ORDER BY total_commission DESC LIMIT 5");
$recentInvoicesQuery = mysqli_query($conn, "SELECT * FROM invoices ORDER BY id DESC LIMIT 10");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salon Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css"/>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f4f4; }
        .dashboard-card { background: white; border: 1px solid #e5e7eb; box-shadow: 0 5px 15px rgba(0,0,0,0.03); }
        .custom-scroll::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
    </style>
</head>
<body class="p-4 md:p-5">

<div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8" data-aos="fade-down">
    <div>
        <h1 class="text-3xl font-black uppercase tracking-wide text-[#1f1f1f]">Salon Dashboard</h1>
        <p class="text-gray-500 text-sm mt-1">Business Analytics & Performance Overview</p>
    </div>
    <div class="bg-[#1f1f1f] text-yellow-400 px-6 py-4 rounded-3xl shadow-2xl">
        <div class="text-[10px] uppercase tracking-widest">Sundaram Salon</div>
        <div class="text-lg font-bold">Admin Dashboard</div>
    </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php 
    $cards = [
        ['Revenue', '₹'.number_format($totalRevenue,0), 'fa-wallet', 'yellow', 'bg-black text-yellow-400'],
        ['Expense', '₹'.number_format($totalExpense,0), 'fa-money-bill-wave', 'red', 'bg-red-500 text-white'],
        ['Profit', '₹'.number_format($totalProfit,0), 'fa-chart-line', 'green', 'bg-green-500 text-white'],
        ['Invoices', $totalInvoices, 'fa-file-invoice', 'yellow', 'bg-yellow-400 text-black']
    ];
    foreach($cards as $c): ?>
    <div class="dashboard-card rounded-2xl p-4 <?php echo $c[4]; ?>" data-aos="zoom-in">
        <div class="flex justify-between items-center">
            <div>
                <div class="text-[9px] uppercase tracking-widest opacity-80"><?php echo $c[0]; ?></div>
                <div class="text-lg font-black mt-1"><?php echo $c[1]; ?></div>
            </div>
            <i class="fa-solid <?php echo $c[2]; ?> text-lg opacity-50"></i>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
    <?php
    $stats = [['Products',$totalProducts,'fa-box','blue'],['Services',$totalServices,'fa-scissors','green'],['Stylists',$totalStylists,'fa-user','yellow'],['Categories',$totalCategories,'fa-layer-group','purple'],['Comm.',"₹".number_format($totalCommission,0),'fa-coins','red']];
    foreach($stats as $stat): ?>
    <div class="dashboard-card rounded-2xl p-4 flex items-center justify-between" data-aos="fade-up">
        <div>
            <div class="text-gray-400 uppercase tracking-widest text-[9px]"><?php echo $stat[0]; ?></div>
            <div class="text-md font-black text-[#1f1f1f]"><?php echo $stat[1]; ?></div>
        </div>
        <i class="fa-solid <?php echo $stat[2]; ?> text-<?php echo $stat[3]; ?>-500 text-md"></i>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="dashboard-card rounded-3xl p-5" data-aos="fade-right"><h2 class="text-xs font-bold mb-4 uppercase">Monthly Revenue</h2><canvas id="salesChart" class="max-h-[200px]"></canvas></div>
        <div class="dashboard-card rounded-3xl p-5" data-aos="fade-left"><h2 class="text-xs font-bold mb-4 uppercase">Expense Dist.</h2><canvas id="expenseChart" class="max-h-[200px]"></canvas></div>
    </div>
    <div class="dashboard-card rounded-3xl p-6" data-aos="fade-up">
        <h2 class="text-sm font-bold mb-4">Top Stylists</h2>
        <div class="space-y-3">
            <?php while($stylist = mysqli_fetch_assoc($topStylistsQuery)): ?>
            <div class="flex justify-between items-center border-b pb-2">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-black text-yellow-400 flex items-center justify-center font-bold text-[10px]"><?php echo strtoupper(substr($stylist['stylist_name'],0,1)); ?></div>
                    <div><div class="font-bold text-xs"><?php echo $stylist['stylist_name']; ?></div></div>
                </div>
                <div class="font-bold text-yellow-600 text-xs">₹<?php echo number_format($stylist['total_commission'],0); ?></div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
</div>

<script src="https://unpkg.com/aos@next/dist/aos.js"></script>
<script>
    AOS.init({ duration:700, once:true });
    new Chart(document.getElementById('salesChart'), { type:'bar', data: { labels: <?php echo json_encode($salesLabels); ?>, datasets:[{ data: <?php echo json_encode($salesData); ?>, backgroundColor:'#facc15', borderRadius:8 }] }, options:{ responsive:true, maintainAspectRatio:false } });
    new Chart(document.getElementById('expenseChart'), { type:'doughnut', data: { labels: <?php echo json_encode($expenseLabels); ?>, datasets:[{ data: <?php echo json_encode($expenseData); ?>, backgroundColor:['#facc15','#ef4444','#22c55e','#3b82f6','#8b5cf6'] }] }, options:{ responsive:true, maintainAspectRatio:false } });
</script>
</body>
</html>
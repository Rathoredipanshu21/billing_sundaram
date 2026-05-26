<?php
session_start();
include '../config/db.php';

date_default_timezone_set('Asia/Kolkata');

/*
|--------------------------------------------------------------------------
| FILTER LOGIC
|--------------------------------------------------------------------------
*/

$filter = $_GET['filter'] ?? 'today';

$whereInvoice = "";
$whereExpense = "";
$whereSubscription = "";
$whereCustomer = "";
$whereService = "";

switch($filter){

    case 'yesterday':
        $whereInvoice = "DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        $whereExpense = "DATE(expense_date)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        $whereSubscription = "DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
        break;

    case 'week':
        $whereInvoice = "YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1)";
        $whereExpense = "YEARWEEK(expense_date,1)=YEARWEEK(CURDATE(),1)";
        $whereSubscription = "YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1)";
        break;

    case 'month':
        $whereInvoice = "MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())";
        $whereExpense = "MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())";
        $whereSubscription = "MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())";
        break;

    case 'year':
        $whereInvoice = "YEAR(created_at)=YEAR(CURDATE())";
        $whereExpense = "YEAR(expense_date)=YEAR(CURDATE())";
        $whereSubscription = "YEAR(created_at)=YEAR(CURDATE())";
        break;

    default:
        $whereInvoice = "DATE(created_at)=CURDATE()";
        $whereExpense = "DATE(expense_date)=CURDATE()";
        $whereSubscription = "DATE(created_at)=CURDATE()";
        break;
}

/*
|--------------------------------------------------------------------------
| MAIN STATS
|--------------------------------------------------------------------------
*/

$totalInvoices = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM invoices WHERE $whereInvoice"))['total'];

$totalRevenue = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(net_payable),0) as total FROM invoices WHERE $whereInvoice"))['total'];

$totalExpense = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE $whereExpense"))['total'];

$totalProfit = $totalRevenue - $totalExpense;

$totalSubscriptions = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM client_subscriptions WHERE $whereSubscription"))['total'];

$totalCustomers = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(DISTINCT customer_mobile) as total FROM invoices WHERE $whereInvoice"))['total'];

$totalServices = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM services"))['total'];

$totalProducts = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM products"))['total'];

$totalStylists = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM stylists WHERE status='Active'"))['total'];

$totalCoupons = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM coupons"))['total'];

$totalCategories = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) as total FROM categories"))['total'];

$totalLoyalty = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(total_points),0) as total FROM customer_loyalty"))['total'];

/*
|--------------------------------------------------------------------------
| SALES GRAPH
|--------------------------------------------------------------------------
*/

$salesLabels = [];
$salesData = [];

$graphQuery = mysqli_query($conn,"
SELECT DATE(created_at) as sale_date,
SUM(net_payable) as total
FROM invoices
GROUP BY DATE(created_at)
ORDER BY DATE(created_at) ASC
");

while($g = mysqli_fetch_assoc($graphQuery)){
    $salesLabels[] = date('d M',strtotime($g['sale_date']));
    $salesData[] = $g['total'];
}

/*
|--------------------------------------------------------------------------
| EXPENSE GRAPH
|--------------------------------------------------------------------------
*/

$expenseLabels = [];
$expenseData = [];

$expenseQuery = mysqli_query($conn,"
SELECT category,
SUM(amount) as total
FROM expenses
GROUP BY category
");

while($e = mysqli_fetch_assoc($expenseQuery)){
    $expenseLabels[] = $e['category'];
    $expenseData[] = $e['total'];
}

/*
|--------------------------------------------------------------------------
| TOP SERVICES
|--------------------------------------------------------------------------
*/

$topServices = mysqli_query($conn,"
SELECT s.service_name,
COUNT(ii.id) as total_bookings,
SUM(ii.subtotal) as total_sales
FROM invoice_items ii
LEFT JOIN services s ON ii.item_id=s.id
WHERE ii.item_type='service'
GROUP BY ii.item_id
ORDER BY total_sales DESC
LIMIT 5
");

/*
|--------------------------------------------------------------------------
| TOP STYLISTS
|--------------------------------------------------------------------------
*/

$topStylists = mysqli_query($conn,"
SELECT stylist_name,
specialty,
commission_rate
FROM stylists
ORDER BY commission_rate DESC
LIMIT 5
");

/*
|--------------------------------------------------------------------------
| RECENT INVOICES
|--------------------------------------------------------------------------
*/

$recentInvoices = mysqli_query($conn,"
SELECT *
FROM invoices
ORDER BY id DESC
LIMIT 10
");

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Salon Professional Dashboard</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>

body{
    font-family: sans-serif;
    background:#f5f7fb;
}

.card{
    background:white;
    border-radius:20px;
    padding:20px;
    box-shadow:0 5px 20px rgba(0,0,0,0.05);
}

</style>

</head>

<body class="p-4 md:p-6">

<div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-6">

<div>
<h1 class="text-3xl font-black">Salon Dashboard</h1>
<p class="text-gray-500 text-sm">Professional Business Analytics</p>
</div>

<form method="GET">

<select name="filter"
onchange="this.form.submit()"
class="border rounded-xl px-4 py-3 font-semibold">

<option value="today" <?= $filter=='today'?'selected':'' ?>>Today</option>
<option value="yesterday" <?= $filter=='yesterday'?'selected':'' ?>>Yesterday</option>
<option value="week" <?= $filter=='week'?'selected':'' ?>>This Week</option>
<option value="month" <?= $filter=='month'?'selected':'' ?>>This Month</option>
<option value="year" <?= $filter=='year'?'selected':'' ?>>This Year</option>

</select>

</form>

</div>

<!-- MAIN STATS -->

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

<?php

$stats = [

['Revenue','₹'.number_format($totalRevenue),'fa-wallet','bg-black text-yellow-400'],
['Profit','₹'.number_format($totalProfit),'fa-chart-line','bg-green-500 text-white'],
['Expense','₹'.number_format($totalExpense),'fa-money-bill-wave','bg-red-500 text-white'],
['Invoices',$totalInvoices,'fa-file-invoice','bg-blue-500 text-white'],

['Subscriptions',$totalSubscriptions,'fa-id-card','bg-purple-500 text-white'],
['Customers',$totalCustomers,'fa-users','bg-pink-500 text-white'],
['Services',$totalServices,'fa-scissors','bg-indigo-500 text-white'],
['Products',$totalProducts,'fa-box','bg-orange-500 text-white'],

['Stylists',$totalStylists,'fa-user','bg-gray-800 text-white'],
['Coupons',$totalCoupons,'fa-ticket','bg-teal-500 text-white'],
['Categories',$totalCategories,'fa-layer-group','bg-cyan-500 text-white'],
['Loyalty Points',$totalLoyalty,'fa-gift','bg-yellow-500 text-black']

];

foreach($stats as $s):

?>

<div class="card <?= $s[3] ?>">

<div class="flex justify-between items-center">

<div>

<div class="uppercase text-[10px] tracking-widest opacity-80">
<?= $s[0] ?>
</div>

<div class="text-2xl font-black mt-2">
<?= $s[1] ?>
</div>

</div>

<i class="fa-solid <?= $s[2] ?> text-3xl opacity-40"></i>

</div>

</div>

<?php endforeach; ?>

</div>

<!-- CHARTS -->

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

<div class="card">
<h2 class="font-bold mb-4">Sales Analytics</h2>
<canvas id="salesChart"></canvas>
</div>

<div class="card">
<h2 class="font-bold mb-4">Expense Analytics</h2>
<canvas id="expenseChart"></canvas>
</div>

</div>

<!-- TOP SERVICES & STYLISTS -->

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

<div class="card">

<h2 class="font-bold mb-4">Top Services</h2>

<div class="space-y-4">

<?php while($service=mysqli_fetch_assoc($topServices)): ?>

<div class="flex justify-between items-center border-b pb-3">

<div>
<div class="font-bold"><?= $service['service_name'] ?></div>
<div class="text-sm text-gray-500">
<?= $service['total_bookings'] ?> Bookings
</div>
</div>

<div class="font-black text-green-600">
₹<?= number_format($service['total_sales']) ?>
</div>

</div>

<?php endwhile; ?>

</div>

</div>

<div class="card">

<h2 class="font-bold mb-4">Top Stylists</h2>

<div class="space-y-4">

<?php while($stylist=mysqli_fetch_assoc($topStylists)): ?>

<div class="flex justify-between items-center border-b pb-3">

<div>
<div class="font-bold"><?= $stylist['stylist_name'] ?></div>
<div class="text-sm text-gray-500">
<?= $stylist['specialty'] ?>
</div>
</div>

<div class="font-black text-blue-600">
<?= $stylist['commission_rate'] ?>%
</div>

</div>

<?php endwhile; ?>

</div>

</div>

</div>

<!-- RECENT INVOICES -->

<div class="card overflow-auto">

<div class="flex justify-between items-center mb-4">

<h2 class="font-bold">Recent Invoices</h2>

</div>

<table class="w-full">

<thead>

<tr class="border-b">

<th class="text-left py-3">Invoice</th>
<th class="text-left py-3">Customer</th>
<th class="text-left py-3">Payment</th>
<th class="text-left py-3">Amount</th>
<th class="text-left py-3">Date</th>

</tr>

</thead>

<tbody>

<?php while($invoice=mysqli_fetch_assoc($recentInvoices)): ?>

<tr class="border-b hover:bg-gray-50">

<td class="py-3 font-bold">
<?= $invoice['invoice_no'] ?>
</td>

<td>
<?= $invoice['customer_name'] ?>
</td>

<td>
<?= $invoice['payment_mode'] ?>
</td>

<td class="font-bold text-green-600">
₹<?= number_format($invoice['net_payable']) ?>
</td>

<td>
<?= date('d M Y',strtotime($invoice['created_at'])) ?>
</td>

</tr>

<?php endwhile; ?>

</tbody>

</table>

</div>

<script>

new Chart(document.getElementById('salesChart'),{

type:'line',

data:{
labels:<?= json_encode($salesLabels) ?>,
datasets:[{
label:'Sales',
data:<?= json_encode($salesData) ?>,
fill:true,
tension:0.4
}]
}

});

new Chart(document.getElementById('expenseChart'),{

type:'doughnut',

data:{
labels:<?= json_encode($expenseLabels) ?>,
datasets:[{
data:<?= json_encode($expenseData) ?>
}]
}

});

</script>

</body>
</html>
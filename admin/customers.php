<?php
include '../config/db.php';

/*
|--------------------------------------------------------------------------
| CUSTOMER SUMMARY
|--------------------------------------------------------------------------
*/

$customerQuery = mysqli_query($conn,"
    SELECT

        customer_mobile,

        customer_name,

        COUNT(id) as total_visits,

        COALESCE(SUM(net_payable),0) as total_spent,

        MAX(created_at) as last_visit,

        MIN(created_at) as first_visit

    FROM invoices

    WHERE customer_mobile != ''

    GROUP BY customer_mobile

    ORDER BY total_spent DESC
");

/*
|--------------------------------------------------------------------------
| TOTAL STATS
|--------------------------------------------------------------------------
*/

$totalCustomers =
mysqli_fetch_assoc(
mysqli_query($conn,"
SELECT COUNT(DISTINCT customer_mobile) as total
FROM invoices
WHERE customer_mobile != ''
"))['total'];

$totalRevenue =
mysqli_fetch_assoc(
mysqli_query($conn,"
SELECT COALESCE(SUM(net_payable),0) as total
FROM invoices
"))['total'];

$totalVisits =
mysqli_fetch_assoc(
mysqli_query($conn,"
SELECT COUNT(*) as total
FROM invoices
"))['total'];

$repeatCustomers =
mysqli_fetch_assoc(
mysqli_query($conn,"
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

<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<title>Customer Management</title>

<script src="https://cdn.tailwindcss.com"></script>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<link rel="stylesheet"
href="https://unpkg.com/aos@next/dist/aos.css"/>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap"
rel="stylesheet">

<style>

body{
    font-family:'Inter',sans-serif;
    background:#f5f5f5;
}

.dashboard-card{
    background:white;
    border:1px solid #e5e7eb;
    box-shadow:
    0 10px 30px rgba(0,0,0,0.05);
}

.glow-card{
    position:relative;
    overflow:hidden;
}

.glow-card::before{
    content:'';
    position:absolute;
    width:180px;
    height:180px;
    background:rgba(255,255,255,0.08);
    border-radius:50%;
    top:-90px;
    right:-90px;
}

.custom-scroll::-webkit-scrollbar{
    width:5px;
    height:5px;
}

.custom-scroll::-webkit-scrollbar-thumb{
    background:#d1d5db;
    border-radius:20px;
}

.stats-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:18px;
}

@media(max-width:768px){

    .stats-grid{
        grid-template-columns:1fr;
    }

    .stat-value{
        font-size:26px !important;
    }

}

</style>

</head>

<body class="p-3 md:p-5 overflow-x-hidden">

<!-- HEADER -->

<div
class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-5 mb-7"
data-aos="fade-down">

    <div>

        <h1 class="text-3xl md:text-4xl font-black uppercase tracking-wide text-[#1f1f1f]">

            Customer Management

        </h1>

        <p class="text-gray-500 mt-2 text-sm">

            Customer Reports • Visit Analytics • Billing History

        </p>

    </div>

    <div class="bg-[#1f1f1f] text-yellow-400 px-6 py-4 rounded-3xl shadow-2xl w-fit">

        <div class="text-xs uppercase tracking-widest">

            Sundaram Salon

        </div>

        <div class="text-xl md:text-2xl font-bold mt-1">

            Customer Dashboard

        </div>

    </div>

</div>

<!-- STATS -->

<div class="stats-grid mb-7">

<!-- CARD -->

<div
class="dashboard-card glow-card rounded-3xl p-5 bg-gradient-to-br from-black to-[#2d2d2d] text-white"
data-aos="zoom-in"
data-aos-delay="100">

    <div class="flex justify-between items-start">

        <div>

            <div class="text-[10px] uppercase tracking-[3px] text-gray-300">

                Total Customers

            </div>

            <div class="stat-value text-4xl font-black mt-4 text-yellow-400">

                <?php echo $totalCustomers; ?>

            </div>

        </div>

        <div class="w-14 h-14 rounded-2xl bg-yellow-400/20 flex items-center justify-center">

            <i class="fa-solid fa-users text-yellow-400 text-xl"></i>

        </div>

    </div>

</div>

<!-- CARD -->

<div
class="dashboard-card glow-card rounded-3xl p-5 bg-gradient-to-br from-green-500 to-green-700 text-white"
data-aos="zoom-in"
data-aos-delay="200">

    <div class="flex justify-between items-start">

        <div>

            <div class="text-[10px] uppercase tracking-[3px] text-green-100">

                Total Revenue

            </div>

            <div class="stat-value text-4xl font-black mt-4">

                ₹<?php echo number_format($totalRevenue,2); ?>

            </div>

        </div>

        <div class="w-14 h-14 rounded-2xl bg-white/20 flex items-center justify-center">

            <i class="fa-solid fa-wallet text-white text-xl"></i>

        </div>

    </div>

</div>

<!-- CARD -->

<div
class="dashboard-card glow-card rounded-3xl p-5 bg-gradient-to-br from-blue-500 to-blue-700 text-white"
data-aos="zoom-in"
data-aos-delay="300">

    <div class="flex justify-between items-start">

        <div>

            <div class="text-[10px] uppercase tracking-[3px] text-blue-100">

                Total Visits

            </div>

            <div class="stat-value text-4xl font-black mt-4">

                <?php echo $totalVisits; ?>

            </div>

        </div>

        <div class="w-14 h-14 rounded-2xl bg-white/20 flex items-center justify-center">

            <i class="fa-solid fa-scissors text-white text-xl"></i>

        </div>

    </div>

</div>

<!-- CARD -->

<div
class="dashboard-card glow-card rounded-3xl p-5 bg-gradient-to-br from-red-500 to-red-700 text-white"
data-aos="zoom-in"
data-aos-delay="400">

    <div class="flex justify-between items-start">

        <div>

            <div class="text-[10px] uppercase tracking-[3px] text-red-100">

                Repeat Customers

            </div>

            <div class="stat-value text-4xl font-black mt-4">

                <?php echo $repeatCustomers; ?>

            </div>

        </div>

        <div class="w-14 h-14 rounded-2xl bg-white/20 flex items-center justify-center">

            <i class="fa-solid fa-rotate text-white text-xl"></i>

        </div>

    </div>

</div>

</div>

<!-- CUSTOMER TABLE -->

<div
class="dashboard-card rounded-3xl overflow-hidden"
data-aos="fade-up">

    <!-- HEADER -->

    <div class="flex items-center justify-between px-6 py-5 border-b">

        <div class="flex items-center gap-4">

            <div class="w-14 h-14 rounded-2xl bg-yellow-100 flex items-center justify-center">

                <i class="fa-solid fa-user-group text-yellow-500 text-xl"></i>

            </div>

            <div>

                <h2 class="text-2xl font-bold text-[#1f1f1f]">

                    Customer Reports

                </h2>

                <p class="text-sm text-gray-500 mt-1">

                    Complete Customer Analytics & Billing History

                </p>

            </div>

        </div>

    </div>

    <!-- TABLE -->

    <div class="overflow-auto custom-scroll">

        <table class="w-full min-w-[1300px]">

            <thead class="bg-[#1f1f1f] text-yellow-400">

                <tr class="uppercase tracking-widest text-xs">

                    <th class="text-left px-6 py-5">
                        Customer
                    </th>

                    <th class="text-left px-6 py-5">
                        Mobile
                    </th>

                    <th class="text-left px-6 py-5">
                        Total Visits
                    </th>

                    <th class="text-left px-6 py-5">
                        Total Spent
                    </th>

                    <th class="text-left px-6 py-5">
                        First Visit
                    </th>

                    <th class="text-left px-6 py-5">
                        Last Visit
                    </th>

                    <th class="text-left px-6 py-5">
                        Status
                    </th>

                    <th class="text-left px-6 py-5">
                        View Bills
                    </th>

                </tr>

            </thead>

            <tbody>

                <?php while($customer = mysqli_fetch_assoc($customerQuery)): ?>

                <tr class="border-b hover:bg-[#fafafa] transition">

                    <!-- CUSTOMER -->

                    <td class="px-6 py-5">

                        <div class="flex items-center gap-4">

                            <div class="w-12 h-12 rounded-2xl bg-[#1f1f1f] text-yellow-400 flex items-center justify-center font-bold">

                                <?php echo strtoupper(substr($customer['customer_name'],0,1)); ?>

                            </div>

                            <div>

                                <div class="font-bold text-[#1f1f1f]">

                                    <?php echo htmlspecialchars($customer['customer_name']); ?>

                                </div>

                                <div class="text-sm text-gray-500 mt-1">

                                    Premium Customer

                                </div>

                            </div>

                        </div>

                    </td>

                    <!-- MOBILE -->

                    <td class="px-6 py-5 font-semibold text-[#1f1f1f]">

                        <?php echo $customer['customer_mobile']; ?>

                    </td>

                    <!-- VISITS -->

                    <td class="px-6 py-5">

                        <span class="bg-blue-100 text-blue-600 px-4 py-2 rounded-full text-sm font-semibold">

                            <?php echo $customer['total_visits']; ?> Visits

                        </span>

                    </td>

                    <!-- SPENT -->

                    <td class="px-6 py-5 font-bold text-green-600">

                        ₹<?php echo number_format($customer['total_spent'],2); ?>

                    </td>

                    <!-- FIRST -->

                    <td class="px-6 py-5 text-gray-600">

                        <?php echo date('d M Y',strtotime($customer['first_visit'])); ?>

                    </td>

                    <!-- LAST -->

                    <td class="px-6 py-5 text-gray-600">

                        <?php echo date('d M Y',strtotime($customer['last_visit'])); ?>

                    </td>

                    <!-- STATUS -->

                    <td class="px-6 py-5">

                        <?php if($customer['total_visits'] > 3): ?>

                        <span class="bg-green-100 text-green-600 px-4 py-2 rounded-full text-sm font-semibold">

                            Loyal Customer

                        </span>

                        <?php else: ?>

                        <span class="bg-yellow-100 text-yellow-600 px-4 py-2 rounded-full text-sm font-semibold">

                            New Customer

                        </span>

                        <?php endif; ?>

                    </td>

                    <!-- VIEW -->

                    <td class="px-6 py-5">

                        <a
                        href="customer_bills.php?mobile=<?php echo $customer['customer_mobile']; ?>"
                        class="bg-[#1f1f1f] hover:bg-black transition text-yellow-400 px-5 py-3 rounded-2xl text-sm font-semibold inline-flex items-center gap-2">

                            <i class="fa-solid fa-eye"></i>

                            View Bills

                        </a>

                    </td>

                </tr>

                <?php endwhile; ?>

            </tbody>

        </table>

    </div>

</div>

<script src="https://unpkg.com/aos@next/dist/aos.js"></script>

<script>

AOS.init({
    duration:700,
    once:true
});

</script>

</body>
</html>
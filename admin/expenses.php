<?php
session_start();
include '../config/db.php'; 

$message = '';
$messageType = '';

// Handle Add Expense
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    $expense_date = $_POST['expense_date'];
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $amount = floatval($_POST['amount']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);

    $sql = "INSERT INTO expenses (expense_date, category, amount, description) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssds", $expense_date, $category, $amount, $description);
    
    if ($stmt->execute()) {
        $message = "Expense successfully recorded.";
        $messageType = "success";
    } else {
        $message = "Database execution failure.";
        $messageType = "error";
    }
    $stmt->close();
}

// Fetch Expenses
$expensesResult = $conn->query("SELECT * FROM expenses ORDER BY expense_date DESC, id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Management | Sundaram Salon</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F8F9FA; }
        .accent-focus:focus { border-color: #EBBB15; box-shadow: 0 0 0 3px rgba(235, 187, 21, 0.15); }
        .custom-scroll::-webkit-scrollbar { width: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
    </style>
</head>
<body class="p-4 lg:p-6 min-h-screen">

    <div class="flex justify-between items-center mb-8 pb-4 border-b border-gray-100" data-aos="fade-down">
        <div>
            <h2 class="text-base font-bold uppercase tracking-wider flex items-center gap-2"><i class="fa-solid fa-wallet text-amber-500"></i> Expense Management</h2>
            <p class="text-[11px] text-gray-400 uppercase tracking-wider mt-1">Track salon outflows and operational costs</p>
        </div>
        <button onclick="document.getElementById('expenseModal').classList.remove('hidden')" class="bg-[#1f1f1f] text-yellow-400 px-6 py-3 rounded-2xl text-xs font-bold uppercase tracking-widest hover:bg-black transition">
            <i class="fa-solid fa-plus mr-2"></i> Record Expense
        </button>
    </div>

    <div class="bg-white border rounded-3xl shadow-sm overflow-hidden" data-aos="fade-up">
        <div class="overflow-x-auto custom-scroll">
            <table class="w-full text-left text-xs">
                <thead class="bg-gray-50 uppercase text-gray-400 tracking-wider">
                    <tr>
                        <th class="px-6 py-4">Date</th>
                        <th class="px-6 py-4">Category</th>
                        <th class="px-6 py-4">Description</th>
                        <th class="px-6 py-4 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php while($row = $expensesResult->fetch_assoc()): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4"><?php echo date('d M Y', strtotime($row['expense_date'])); ?></td>
                            <td class="px-6 py-4 font-bold text-neutral-800 uppercase"><?php echo $row['category']; ?></td>
                            <td class="px-6 py-4 text-gray-600"><?php echo $row['description']; ?></td>
                            <td class="px-6 py-4 text-right font-bold text-red-600">₹<?php echo number_format($row['amount'], 2); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="expenseModal" class="fixed inset-0 bg-black/50 hidden flex items-center justify-center p-4 z-50">
        <div class="bg-white w-full max-w-sm rounded-3xl p-6">
            <h3 class="text-xs font-bold uppercase mb-4">Record New Expense</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_expense">
                <input type="date" name="expense_date" required class="w-full border rounded-xl p-3 text-xs mb-3">
                <select name="category" required class="w-full border rounded-xl p-3 text-xs mb-3">
                    <option value="Rent">Rent</option>
                    <option value="Electricity">Electricity</option>
                    <option value="Supplies">Supplies</option>
                    <option value="Staff Refreshments">Staff Refreshments</option>
                    <option value="Maintenance">Maintenance</option>
                    <option value="Other">Other</option>
                </select>
                <input type="number" name="amount" required placeholder="Amount (INR)" class="w-full border rounded-xl p-3 text-xs mb-3">
                <input type="text" name="description" placeholder="Short description" class="w-full border rounded-xl p-3 text-xs mb-4">
                <div class="flex gap-2">
                    <button type="button" onclick="this.closest('#expenseModal').classList.add('hidden')" class="flex-1 border py-3 rounded-xl text-xs font-bold uppercase">Cancel</button>
                    <button type="submit" class="flex-1 bg-black text-white py-3 rounded-xl text-xs font-bold uppercase">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>AOS.init({ once: true });</script>
</body>
</html>
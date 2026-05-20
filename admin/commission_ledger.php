<?php
session_start();
include '../config/db.php'; 

$message = '';
$messageType = '';

// Handle Commission Ledger Adjustment
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'log_transaction') {
    $stylist_id = intval($_POST['stylist_id']);
    $transaction_type = mysqli_real_escape_string($conn, $_POST['transaction_type']);
    $amount = floatval($_POST['amount']);
    $reference_note = mysqli_real_escape_string($conn, $_POST['reference_note']);

    if ($amount <= 0) {
        $message = "Invalid financial amount. Must be greater than zero.";
        $messageType = "error";
    } else {
        $sql = "INSERT INTO commission_ledger (stylist_id, transaction_type, amount, reference_note) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("isds", $stylist_id, $transaction_type, $amount, $reference_note);
            if ($stmt->execute()) {
                $message = "Ledger transaction successfully booked into the system.";
                $messageType = "success";
            } else {
                $message = "Database execution error.";
                $messageType = "error";
            }
            $stmt->close();
        }
    }
}

// Fetch lists for dropdowns
$stylistsList = $conn->query("SELECT id, stylist_name FROM stylists WHERE status = 'Active' ORDER BY stylist_name ASC");

// Fetch financial ledger history joining with stylist profile names
$ledgerResult = $conn->query("
    SELECT c.*, s.stylist_name 
    FROM commission_ledger c 
    LEFT JOIN stylists s ON c.stylist_id = s.id 
    ORDER BY c.id DESC LIMIT 100
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stylist Commission Ledger</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #FFFFFF; color: #222222; }
        .modal-blur-bg { background-color: rgba(26, 26, 26, 0.4); backdrop-filter: blur(4px); }
        .accent-focus:focus { border-color: #EBBB15; box-shadow: 0 0 0 3px rgba(235, 187, 21, 0.15); }
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
            <p class="text-[11px] text-gray-400 uppercase tracking-wider mt-0.5">Track staff service earnings and salary payouts</p>
        </div>
        
        <button onclick="openModal()" class="bg-[#222222] hover:bg-neutral-800 text-[#EBBB15] px-4 py-2.5 rounded-xl text-xs font-medium transition flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-money-check-dollar"></i>
            <span>Log Ledger Entry</span>
        </button>
    </div>

    <?php if (!empty($message)): ?>
        <div class="mb-6 p-3 rounded-xl text-xs flex items-center gap-2 max-w-xl mx-auto <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-rose-50 text-rose-700 border border-rose-100'; ?>" data-aos="fade-in">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo $message; ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden" data-aos="fade-up" data-aos-duration="800">
        <div class="overflow-x-auto custom-scroll">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50/70 border-b border-gray-100">
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Timestamp</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Stylist Agent</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 text-center">Ledger Type</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Transaction Value</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Audit Note / Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-xs text-neutral-700">
                    <?php if ($ledgerResult && $ledgerResult->num_rows > 0): ?>
                        <?php while($row = $ledgerResult->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50/40 transition">
                                <td class="px-5 py-4 text-gray-400 font-mono text-[10px]">
                                    <?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?>
                                </td>
                                <td class="px-5 py-4 font-medium text-neutral-800">
                                    <?php echo htmlspecialchars($row['stylist_name'] ?: 'Unknown Staff'); ?>
                                </td>
                                <td class="px-5 py-4 text-center">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-semibold uppercase tracking-wider <?php echo $row['transaction_type'] === 'Earning' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?>">
                                        <i class="fa-solid <?php echo $row['transaction_type'] === 'Earning' ? 'fa-arrow-turn-down' : 'fa-arrow-turn-up'; ?>"></i>
                                        <?php echo $row['transaction_type']; ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 font-mono font-medium <?php echo $row['transaction_type'] === 'Earning' ? 'text-emerald-600' : 'text-rose-600'; ?>">
                                    <?php echo $row['transaction_type'] === 'Earning' ? '+' : '-'; ?>₹<?php echo number_format($row['amount'], 2); ?>
                                </td>
                                <td class="px-5 py-4 text-gray-500">
                                    <?php echo htmlspecialchars($row['reference_note'] ?: 'No notes attached to log.'); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="py-16 text-center bg-gray-50/20 border-none">
                                <div class="w-12 h-12 rounded-xl bg-white border border-gray-100 text-gray-300 flex items-center justify-center mb-3 mx-auto shadow-sm">
                                    <i class="fa-solid fa-file-invoice-dollar text-lg"></i>
                                </div>
                                <h4 class="text-xs font-medium text-neutral-600 uppercase tracking-wider">Empty Financial Ledger</h4>
                                <p class="text-[11px] text-gray-400 mt-1 max-w-sm mx-auto leading-relaxed">Commission earnings and salary payouts will populate here once recorded in the system.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="ledgerModal" class="fixed inset-0 z-50 modal-blur-bg hidden opacity-0 transition-opacity duration-300 flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-2xl border border-gray-100 shadow-2xl transform scale-95 transition-transform duration-300 flex flex-col overflow-hidden">
            
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-money-check-dollar text-amber-500"></i>
                    <h3 class="text-xs font-medium text-neutral-800 uppercase tracking-wider">Log Commission Entry</h3>
                </div>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="" method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" value="log_transaction">

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Select Stylist Account</label>
                    <select name="stylist_id" required
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-white">
                        <?php if ($stylistsList && $stylistsList->num_rows > 0): ?>
                            <?php while($sRow = $stylistsList->fetch_assoc()): ?>
                                <option value="<?php echo $sRow['id']; ?>"><?php echo htmlspecialchars($sRow['stylist_name']); ?></option>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <option value="">No Active Stylists Configured</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Log Type</label>
                        <select name="transaction_type" required
                            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-white">
                            <option value="Earning">Add Earning (+)</option>
                            <option value="Payout">Settle Payout (-)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Amount (INR)</label>
                        <input type="number" step="0.01" name="amount" required placeholder="0.00" min="1"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Note / Description</label>
                    <input type="text" name="reference_note" required placeholder="e.g. Commission for Invoice #INV-1234 or Salary Settlement"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                </div>

                <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3 bg-gray-50/20 -mx-5 -mb-5 p-4 mt-6">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 border border-gray-200 text-gray-500 hover:text-neutral-800 hover:bg-gray-50 rounded-xl text-xs font-medium transition">
                        Discard
                    </button>
                    <button type="submit" class="px-4 py-2 bg-[#222222] text-[#EBBB15] hover:bg-neutral-800 rounded-xl text-xs font-medium transition shadow-sm">
                        Commit Ledger Record
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        AOS.init({ once: true });
        
        const modal = document.getElementById('ledgerModal');
        const modalContainer = modal.querySelector('.transform');

        function openModal() {
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modalContainer.classList.remove('scale-95');
            }, 10);
        }

        function closeModal() {
            modal.classList.add('opacity-0');
            modalContainer.classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }
    </script>
</body>
</html>
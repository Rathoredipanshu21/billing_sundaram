<?php
session_start();
include '../config/db.php'; 

$message = '';
$messageType = '';

// Handle Stock Adjustment Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'adjust_stock') {
    $product_id = intval($_POST['product_id']);
    $transaction_type = mysqli_real_escape_string($conn, $_POST['transaction_type']);
    $quantity = intval($_POST['quantity']);
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);
    $user_operator = $_SESSION['admin'] ?? 'Admin';

    if ($quantity <= 0) {
        $message = "Please insert a valid quantity value matching criteria.";
        $messageType = "error";
    } else {
        // Start explicit database transaction for security data mapping control
        $conn->begin_transaction();

        try {
            // 1. Log transaction into ledger parameters
            $log_sql = "INSERT INTO stock_ledger (product_id, transaction_type, quantity, reason, performed_by) VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_stmt->bind_param("isiss", $product_id, $transaction_type, $quantity, $reason, $user_operator);
            $log_stmt->execute();
            $log_stmt->close();

            // 2. Adjust core quantity balance parameters on master products sheet
            if ($transaction_type === 'Stock In') {
                $update_sql = "UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?";
            } else {
                $update_sql = "UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?";
            }

            $update_stmt = $conn->prepare($update_sql);
            if ($transaction_type === 'Stock In') {
                $update_stmt->bind_param("ii", $quantity, $product_id);
            } else {
                $update_stmt->bind_param("iii", $quantity, $product_id, $quantity);
            }
            
            $update_stmt->execute();

            if ($transaction_type === 'Stock Out' && $update_stmt->affected_rows === 0) {
                throw new Exception("Insufficient stock available to complete deduction profile processing.");
            }

            $update_stmt->close();
            $conn->commit();
            
            $message = "Stock balances updated successfully in inventory ledger system.";
            $messageType = "success";
        } catch (Exception $e) {
            $conn->rollback();
            $message = $e->getMessage();
            $messageType = "error";
        }
    }
}

// Fetch master list of products for input dropdown selection configuration arrays
$productsDropdownResult = $conn->query("SELECT id, product_name, sku_code, stock_qty FROM products WHERE status = 'Active' ORDER BY product_name ASC");

// Fetch historical record log arrays joining tables to display readable data parameters
$ledgerResult = $conn->query("SELECT l.*, p.product_name, p.sku_code 
                              FROM stock_ledger l 
                              LEFT JOIN products p ON l.product_id = p.id 
                              ORDER BY l.id DESC LIMIT 100");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internal Stock Ledger Registry</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #FFFFFF;
        }
        .modal-blur-bg {
            background-color: rgba(26, 26, 26, 0.4);
            backdrop-filter: blur(4px);
        }
        .accent-focus:focus {
            border-color: #EBBB15;
            box-shadow: 0 0 0 3px rgba(235, 187, 21, 0.15);
        }
        .custom-scroll::-webkit-scrollbar { width: 4px; height: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 4px; }
    </style>
</head>
<body class="p-4 lg:p-6 min-h-screen">

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-8 pb-4 border-b border-gray-100" data-aos="fade-down" data-aos-duration="600">
        <div>
            <h2 class="text-base font-medium text-neutral-800 tracking-tight flex items-center gap-2">
                <i class="fa-solid fa-warehouse text-amber-500"></i>
                <span>Internal Stock Ledger</span>
            </h2>
            <p class="text-[11px] text-gray-400 uppercase tracking-wider mt-0.5">Control warehouse load and consumable audits</p>
        </div>
        
        <button id="openModalBtn" class="bg-[#222222] hover:bg-neutral-800 text-[#EBBB15] px-4 py-2.5 rounded-xl text-xs font-medium transition flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-right-left"></i>
            <span>Adjust Stock Level</span>
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
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-400">Timestamp</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Product Particulars</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">SKU Code</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 text-center">Movement Type</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 text-center">Volume Shift</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Adjustment Note / Reason</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Auditor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-xs text-neutral-700">
                    <?php if ($ledgerResult && $ledgerResult->num_rows > 0): ?>
                        <?php while($row = $ledgerResult->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50/40 transition">
                                <td class="px-5 py-4 text-gray-400"><?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?></td>
                                <td class="px-5 py-4 font-medium text-neutral-800"><?php echo htmlspecialchars($row['product_name'] ?: 'Destroyed Profile Product'); ?></td>
                                <td class="px-5 py-4 font-mono text-[10px] uppercase text-gray-500"><?php echo htmlspecialchars($row['sku_code'] ?: 'N/A'); ?></td>
                                <td class="px-5 py-4 text-center">
                                    <span class="px-2.5 py-0.5 rounded text-[10px] font-medium uppercase <?php echo $row['transaction_type'] === 'Stock In' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'; ?>">
                                        <?php echo $row['transaction_type']; ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-center font-semibold <?php echo $row['transaction_type'] === 'Stock In' ? 'text-emerald-600' : 'text-amber-600'; ?>">
                                    <?php echo $row['transaction_type'] === 'Stock In' ? '+' : '-'; ?> <?php echo $row['quantity']; ?>
                                </td>
                                <td class="px-5 py-4 text-gray-500 max-w-xs truncate"><?php echo htmlspecialchars($row['reason'] ?: 'Routine adjustment profile reset.'); ?></td>
                                <td class="px-5 py-4 text-gray-400 font-medium"><?php echo htmlspecialchars($row['performed_by']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="py-12 text-center bg-gray-50/20 border-none">
                                <div class="w-10 h-10 rounded-xl bg-gray-100 text-gray-400 flex items-center justify-center mb-2 mx-auto">
                                    <i class="fa-solid fa-list-check text-sm"></i>
                                </div>
                                <h4 class="text-[11px] font-medium text-neutral-600 uppercase tracking-wider">No Historical Ledger Pulled</h4>
                                <p class="text-xs text-gray-400 mt-0.5">Inventory adjustments and inbound/outbound logging logs will manifest inside this arena layout matrix frame view.</p>
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
                    <i class="fa-solid fa-right-left text-amber-500"></i>
                    <h3 class="text-xs font-medium text-neutral-800 uppercase tracking-wider">Adjust Stock Ledger Balance</h3>
                </div>
                <button id="closeModalBtn" class="text-gray-400 hover:text-gray-600 transition p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="" method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" value="adjust_stock">

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Target Product Particular</label>
                    <select name="product_id" required
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-white">
                        <?php if ($productsDropdownResult && $productsDropdownResult->num_rows > 0): ?>
                            <?php while($prodRow = $productsDropdownResult->fetch_assoc()): ?>
                                <option value="<?php echo $prodRow['id']; ?>">
                                    <?php echo htmlspecialchars($prodRow['product_name']); ?> [SKU: <?php echo htmlspecialchars($prodRow['sku_code']); ?>] (Current: <?php echo $prodRow['stock_qty']; ?>)
                                </option>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <option value="">No valid active inventory parameters configured</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Movement Vector Type</label>
                    <select name="transaction_type" required
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-white">
                        <option value="Stock In">Stock In (Fresh Shipments / Inward Procurement)</option>
                        <option value="Stock Out">Stock Out (Internal Consumables / Wastage / Defective Handouts)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Quantity Count Volume</label>
                    <input type="number" name="quantity" required placeholder="0" min="1"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Audit Note / Reason for Adjustments</label>
                    <input type="text" name="reason" required placeholder="e.g. New bundle bulk box arrival or Damaged item dispose"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                </div>

                <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3 bg-gray-50/20 -mx-5 -mb-5 p-4 mt-6">
                    <button type="button" id="cancelModalBtn" class="px-4 py-2 border border-gray-200 text-gray-500 hover:text-neutral-800 hover:bg-gray-50 rounded-xl text-xs font-medium transition">
                        Discard
                    </button>
                    <button type="submit" class="px-4 py-2 bg-[#222222] text-[#EBBB15] hover:bg-neutral-800 rounded-xl text-xs font-medium transition shadow-sm">
                        Commit Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        AOS.init({ once: true });

        const ledgerModal = document.getElementById('ledgerModal');
        const openModalBtn = document.getElementById('openModalBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const cancelModalBtn = document.getElementById('cancelModalBtn');
        const modalContainer = ledgerModal.querySelector('.transform');

        function openModal() {
            ledgerModal.classList.remove('hidden');
            setTimeout(() => {
                ledgerModal.classList.remove('opacity-0');
                modalContainer.classList.remove('scale-95');
            }, 10);
        }

        function closeModal() {
            ledgerModal.classList.add('opacity-0');
            modalContainer.classList.add('scale-95');
            setTimeout(() => {
                ledgerModal.classList.add('hidden');
            }, 300);
        }

        openModalBtn.addEventListener('click', openModal);
        closeModalBtn.addEventListener('click', closeModal);
        cancelModalBtn.addEventListener('click', closeModal);

        ledgerModal.addEventListener('click', (e) => {
            if (e.target === ledgerModal) closeModal();
        });
    </script>
</body>
</html>
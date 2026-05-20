<?php
session_start();
include '../config/db.php'; 

$message = '';
$messageType = '';

// Handle Add Product Form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'add_product') {
    $product_name = mysqli_real_escape_string($conn, $_POST['product_name']);
    $category_id = intval($_POST['category_id']);
    $sku_code = strtoupper(mysqli_real_escape_string($conn, $_POST['sku_code']));
    $purchase_price = floatval($_POST['purchase_price']);
    $selling_price = floatval($_POST['selling_price']);
    $stock_qty = intval($_POST['stock_qty']);
    $alert_qty = intval($_POST['alert_qty']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    // Check for duplicate SKU codes to prevent collision errors
    $check_stmt = $conn->prepare("SELECT id FROM products WHERE sku_code = ?");
    $check_stmt->bind_param("s", $sku_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $message = "Product barcode tracking SKU code already exists in the ledger system.";
        $messageType = "error";
    } else {
        $sql = "INSERT INTO products (product_name, category_id, sku_code, purchase_price, selling_price, stock_qty, alert_qty, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sisddiis", $product_name, $category_id, $sku_code, $purchase_price, $selling_price, $stock_qty, $alert_qty, $status);
            if ($stmt->execute()) {
                $message = "Retail inventory item successfully added to the system database.";
                $messageType = "success";
            } else {
                $message = "Database mapping execution failure encountered.";
                $messageType = "error";
            }
            $stmt->close();
        }
    }
    $check_stmt->close();
}

// Fetch categories from database for relational dropdown selection
$categoriesResult = $conn->query("SELECT id, category_name FROM categories WHERE status = 'Active' ORDER BY category_name ASC");

// Fetch products with join to map clean category context details 
$productsResult = $conn->query("SELECT p.*, c.category_name 
                                FROM products p 
                                LEFT JOIN categories c ON p.category_id = c.id 
                                ORDER BY p.id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retail Products Management Inventory</title>
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
                <i class="fa-solid fa-box text-amber-500"></i>
                <span>Retail Inventory Registry</span>
            </h2>
            <p class="text-[11px] text-gray-400 uppercase tracking-wider mt-0.5">Track products and cosmetic stock balances</p>
        </div>
        
        <button id="openModalBtn" class="bg-[#222222] hover:bg-neutral-800 text-[#EBBB15] px-4 py-2.5 rounded-xl text-xs font-medium transition flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-plus-circle"></i>
            <span>Register New Product</span>
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
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-400">SKU Code</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Product Name</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Category</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Purchase Rate</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Retail Fee</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 text-center">Available Stock</th>
                        <th class="px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-xs text-neutral-700">
                    <?php if ($productsResult && $productsResult->num_rows > 0): ?>
                        <?php while($row = $productsResult->fetch_assoc()): 
                            $isLowStock = ($row['stock_qty'] <= $row['alert_qty']);
                        ?>
                            <tr class="hover:bg-gray-50/40 transition">
                                <td class="px-5 py-4 font-mono text-[10px] text-gray-500 uppercase tracking-wider"><?php echo htmlspecialchars($row['sku_code']); ?></td>
                                <td class="px-5 py-4 font-medium text-neutral-800"><?php echo htmlspecialchars($row['product_name']); ?></td>
                                <td class="px-5 py-4 text-gray-500"><?php echo htmlspecialchars($row['category_name'] ?: 'Unassigned'); ?></td>
                                <td class="px-5 py-4 text-gray-400">₹<?php echo number_format($row['purchase_price'], 2); ?></td>
                                <td class="px-5 py-4 font-medium text-neutral-800">₹<?php echo number_format($row['selling_price'], 2); ?></td>
                                <td class="px-5 py-4 text-center">
                                    <?php if($isLowStock): ?>
                                        <span class="bg-rose-50 text-rose-600 px-2.5 py-1 rounded-full text-[10px] font-medium inline-block shadow-sm">
                                            <?php echo $row['stock_qty']; ?> <span class="text-[9px] opacity-70">(Low Warning)</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-neutral-100 text-neutral-700 px-2.5 py-1 rounded-full text-[10px] font-medium inline-block">
                                            <?php echo $row['stock_qty']; ?> Units
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium uppercase <?php echo $row['status'] === 'Active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-50 text-gray-500'; ?>">
                                        <span class="w-1 h-1 rounded-full <?php echo $row['status'] === 'Active' ? 'bg-emerald-500' : 'bg-gray-400'; ?>"></span>
                                        <?php echo $row['status']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="py-12 text-center bg-gray-50/20 border-none">
                                <div class="w-10 h-10 rounded-xl bg-gray-100 text-gray-400 flex items-center justify-center mb-2 mx-auto">
                                    <i class="fa-solid fa-box-open text-sm"></i>
                                </div>
                                <h4 class="text-[11px] font-medium text-neutral-600 uppercase tracking-wider">No Stock Records Configured</h4>
                                <p class="text-xs text-gray-400 mt-0.5">Initialize stock tracking parameters by adding products via the control interface module button.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="productModal" class="fixed inset-0 z-50 modal-blur-bg hidden opacity-0 transition-opacity duration-300 flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg rounded-2xl border border-gray-100 shadow-2xl transform scale-95 transition-transform duration-300 flex flex-col overflow-hidden max-h-[92vh]">
            
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-square-plus text-amber-500"></i>
                    <h3 class="text-xs font-medium text-neutral-800 uppercase tracking-wider">Add Stock Product Profile</h3>
                </div>
                <button id="closeModalBtn" class="text-gray-400 hover:text-gray-600 transition p-1 text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form action="" method="POST" class="overflow-y-auto p-5 space-y-4 custom-scroll">
                <input type="hidden" name="action" value="add_product">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Product Item Name</label>
                        <input type="text" name="product_name" required placeholder="e.g. L'Oreal Serum 100ml"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Category allocation</label>
                        <select name="category_id" required
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-white">
                            <?php if ($categoriesResult && $categoriesResult->num_rows > 0): ?>
                                <?php while($catRow = $categoriesResult->fetch_assoc()): ?>
                                    <option value="<?php echo $catRow['id']; ?>"><?php echo htmlspecialchars($catRow['category_name']); ?></option>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <option value="">No active groups mapped</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Tracking Barcode / SKU Code</label>
                    <input type="text" name="sku_code" required placeholder="e.g. LOR-SER-001"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs text-neutral-800 font-mono uppercase outline-none transition-all accent-focus bg-gray-50/30">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Purchase Cost (INR)</label>
                        <input type="number" step="0.01" name="purchase_price" required placeholder="0.00"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Retail Selling Value (INR)</label>
                        <input type="number" step="0.01" name="selling_price" required placeholder="0.00"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Initial Stock Quantity</label>
                        <input type="number" name="stock_qty" required placeholder="0" min="0"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                    </div>

                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Low Stock Alert Threshold</label>
                        <input type="number" name="alert_qty" required placeholder="5" min="1"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-gray-50/30">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wider mb-1.5">Operational Status</label>
                    <select name="status" required
                        class="w-full border border-gray-200 rounded-xl px-3 py-2 text-xs text-neutral-800 outline-none transition-all accent-focus bg-white">
                        <option value="Active">Active Operational</option>
                        <option value="Inactive">Inactive Hold</option>
                    </select>
                </div>

                <div class="pt-4 border-t border-gray-100 flex items-center justify-end gap-3 bg-gray-50/20 -mx-5 -mb-5 p-4 mt-6">
                    <button type="button" id="cancelModalBtn" class="px-4 py-2 border border-gray-200 text-gray-500 hover:text-neutral-800 hover:bg-gray-50 rounded-xl text-xs font-medium transition">
                        Discard
                    </button>
                    <button type="submit" class="px-4 py-2 bg-[#222222] text-[#EBBB15] hover:bg-neutral-800 rounded-xl text-xs font-medium transition shadow-sm">
                        Commit Record
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        AOS.init({ once: true });

        const productModal = document.getElementById('productModal');
        const openModalBtn = document.getElementById('openModalBtn');
        const closeModalBtn = document.getElementById('closeModalBtn');
        const cancelModalBtn = document.getElementById('cancelModalBtn');
        const modalContainer = productModal.querySelector('.transform');

        function openModal() {
            productModal.classList.remove('hidden');
            setTimeout(() => {
                productModal.classList.remove('opacity-0');
                modalContainer.classList.remove('scale-95');
            }, 10);
        }

        function closeModal() {
            productModal.classList.add('opacity-0');
            modalContainer.scale-95;
            setTimeout(() => {
                productModal.classList.add('hidden');
            }, 300);
        }

        openModalBtn.addEventListener('click', openModal);
        closeModalBtn.addEventListener('click', closeModal);
        cancelModalBtn.addEventListener('click', closeModal);

        productModal.addEventListener('click', (e) => {
            if (e.target === productModal) closeModal();
        });
    </script>
</body>
</html>
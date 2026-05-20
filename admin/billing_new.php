<?php
session_start();
include '../config/db.php'; 

$message = '';
$messageType = '';

// Handle Invoice Submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'generate_invoice') {
    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $customer_mobile = mysqli_real_escape_string($conn, $_POST['customer_mobile']);
    $discount_percent = floatval($_POST['discount_percent']);
    $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode']);
    
    $types = $_POST['item_type'] ?? [];
    $item_ids = $_POST['item_id'] ?? [];
    $prices = $_POST['price'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    if (empty($item_ids)) {
        $message = "Please append at least one service or retail product to generate the bill.";
        $messageType = "error";
    } else {
        $conn->begin_transaction();
        try {
            // Generate unique invoice sequential number
            $invoice_no = "INV-" . date("Ymd") . "-" . rand(1000, 9999);
            
            // Calculate totals safely on the backend
            $calculated_total = 0;
            $items_to_save = [];
            
            for ($i = 0; $i < count($item_ids); $i++) {
                $sub = floatval($prices[$i]) * intval($quantities[$i]);
                $calculated_total += $sub;
                $items_to_save[] = [
                    'type' => $types[$i],
                    'id' => intval($item_ids[$i]),
                    'price' => floatval($prices[$i]),
                    'qty' => intval($quantities[$i]),
                    'sub' => $sub
                ];
            }
            
            // Apply percentage discount
            $discount_amount = ($calculated_total * $discount_percent) / 100;
            $net_payable = $calculated_total - $discount_amount;
            if ($net_payable < 0) $net_payable = 0;

            // 1. Insert Master Invoice details
            $inv_sql = "INSERT INTO invoices (invoice_no, customer_name, customer_mobile, total_amount, discount_percent, discount, net_payable, payment_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $inv_stmt = $conn->prepare($inv_sql);
            $inv_stmt->bind_param("sssdddds", $invoice_no, $customer_name, $customer_mobile, $calculated_total, $discount_percent, $discount_amount, $net_payable, $payment_mode);
            $inv_stmt->execute();
            $invoice_db_id = $inv_stmt->insert_id;
            $inv_stmt->close();

            // 2. Insert line items loop details
            $item_sql = "INSERT INTO invoice_items (invoice_id, item_type, item_id, price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?)";
            $item_stmt = $conn->prepare($item_sql);
            
            foreach ($items_to_save as $item) {
                $item_stmt->bind_param("isidid", $invoice_db_id, $item['type'], $item['id'], $item['price'], $item['qty'], $item['sub']);
                $item_stmt->execute();
                
                // 3. Deduct stock balance automatically if item is a product
                if ($item['type'] === 'Product') {
                    $deduct_sql = "UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?";
                    $deduct_stmt = $conn->prepare($deduct_sql);
                    $deduct_stmt->bind_param("ii", $item['qty'], $item['id']);
                    $deduct_stmt->execute();
                    $deduct_stmt->close();
                }
            }
            $item_stmt->close();
            
            $conn->commit();
            
            // REDIRECT DIRECTLY TO INVOICE PAGE (Bypasses all popup blockers!)
            header("Location: print_invoice.php?id=" . $invoice_db_id);
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = "Billing process failure: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

// Fetch active catalog configurations for the POS catalog layout
$servicesResult = $conn->query("SELECT id, service_name, price, category FROM services WHERE status = 'Active' ORDER BY service_name ASC");
$productsResult = $conn->query("SELECT id, product_name, selling_price, stock_qty FROM products WHERE status = 'Active' ORDER BY product_name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic POS | Sundaram Salon</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F8F9FA; color: #222222; }
        .accent-focus:focus { border-color: #EBBB15; box-shadow: 0 0 0 3px rgba(235, 187, 21, 0.15); }
        .custom-scroll::-webkit-scrollbar { width: 4px; height: 4px;}
        .custom-scroll::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 4px; }
        .pos-grid { height: calc(100vh - 40px); }
        .receipt-container { background-image: radial-gradient(#E5E7EB 1px, transparent 0); background-size: 20px 20px; }
        .border-dashed-thick { border-bottom: 2px dashed #E5E7EB; }
    </style>
</head>
<body class="p-3 lg:p-4 h-screen overflow-hidden">

    <?php if (!empty($message)): ?>
        <div class="fixed top-4 left-1/2 transform -translate-x-1/2 z-50 p-3 rounded-xl text-xs flex items-center gap-2 shadow-lg <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-rose-50 text-rose-700 border border-rose-100'; ?>" data-aos="fade-down">
            <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <span><?php echo $message; ?></span>
        </div>
    <?php endif; ?>

    <div class="flex flex-col lg:flex-row gap-4 h-full pos-grid">
        
        <div class="w-full lg:w-7/12 xl:w-8/12 flex flex-col bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden" data-aos="fade-right" data-aos-duration="600">
            <div class="p-4 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                <h2 class="text-sm font-semibold text-neutral-800 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-layer-group text-amber-500"></i> Service & Retail Catalog
                </h2>
                <div class="flex items-center gap-2 bg-gray-100 p-1 rounded-lg">
                    <button type="button" onclick="toggleCatalog('services')" id="btn-services" class="px-4 py-1.5 rounded-md text-xs font-medium bg-white shadow-sm text-neutral-800 transition">Services</button>
                    <button type="button" onclick="toggleCatalog('products')" id="btn-products" class="px-4 py-1.5 rounded-md text-xs font-medium text-gray-500 hover:text-neutral-800 transition">Products</button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 custom-scroll bg-gray-50/30">
                <div id="grid-services" class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3">
                    <?php if ($servicesResult && $servicesResult->num_rows > 0): ?>
                        <?php while($row = $servicesResult->fetch_assoc()): ?>
                            <div onclick="addToCart('Service', <?php echo $row['id']; ?>, '<?php echo addslashes($row['service_name']); ?>', <?php echo $row['price']; ?>)" 
                                 class="bg-white border border-gray-200 hover:border-[#EBBB15] rounded-xl p-3 cursor-pointer transition flex flex-col justify-between min-h-[100px] shadow-sm hover:shadow-md">
                                <div class="text-[10px] text-gray-400 uppercase tracking-wider mb-1"><i class="fa-solid fa-scissors mr-1 text-amber-400"></i>Service</div>
                                <h3 class="text-xs font-medium text-neutral-800 leading-snug"><?php echo htmlspecialchars($row['service_name']); ?></h3>
                                <div class="mt-2 text-sm font-semibold text-[#222222]">₹<?php echo number_format($row['price'], 2); ?></div>
                            </div>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </div>

                <div id="grid-products" class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3 hidden">
                    <?php if ($productsResult && $productsResult->num_rows > 0): ?>
                        <?php while($row = $productsResult->fetch_assoc()): ?>
                            <div onclick="addToCart('Product', <?php echo $row['id']; ?>, '<?php echo addslashes($row['product_name']); ?>', <?php echo $row['selling_price']; ?>)" 
                                 class="bg-white border border-gray-200 hover:border-[#EBBB15] rounded-xl p-3 cursor-pointer transition flex flex-col justify-between min-h-[100px] shadow-sm hover:shadow-md <?php echo ($row['stock_qty'] <= 0) ? 'opacity-50 pointer-events-none' : ''; ?>">
                                <div class="flex justify-between items-start mb-1">
                                    <div class="text-[10px] text-gray-400 uppercase tracking-wider"><i class="fa-solid fa-box mr-1 text-emerald-500"></i>Retail</div>
                                    <div class="text-[9px] font-medium bg-gray-100 text-gray-600 px-1.5 rounded">Qty: <?php echo $row['stock_qty']; ?></div>
                                </div>
                                <h3 class="text-xs font-medium text-neutral-800 leading-snug"><?php echo htmlspecialchars($row['product_name']); ?></h3>
                                <div class="mt-2 text-sm font-semibold text-[#222222]">₹<?php echo number_format($row['selling_price'], 2); ?></div>
                            </div>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="w-full lg:w-5/12 xl:w-4/12 flex flex-col bg-white rounded-2xl border border-gray-200 shadow-xl overflow-hidden relative receipt-container" data-aos="fade-left" data-aos-duration="600">
            
            <form action="" method="POST" id="checkoutForm" class="flex flex-col h-full">
                <input type="hidden" name="action" value="generate_invoice">
                
                <div class="p-5 bg-white border-dashed-thick">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-neutral-900 rounded-xl flex items-center justify-center shrink-0 border border-neutral-800">
                            <img src="../Assets/icon.png" alt="Logo" class="w-8 h-8 object-contain">
                        </div>
                        <div>
                            <h1 class="text-base font-semibold text-[#222222] tracking-tight uppercase">Sundaram Salon</h1>
                            <p class="text-[9px] text-gray-500 mt-0.5 leading-relaxed">
                                1st Floor, Yaspal Skyrise, Amrapali Marg,<br>
                                beside Tamanna tower, above Nilkamal Furniture,<br>
                                Nemi Nagar Ext, B Block, Vaishali Nagar, Jaipur 302021
                            </p>
                            <p class="text-[10px] font-medium text-neutral-700 mt-1">
                                <i class="fa-solid fa-phone text-amber-500 mr-1"></i> +91 8585856832
                            </p>
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-white border-b border-gray-100 flex gap-3">
                    <div class="flex-1">
                        <input type="text" name="customer_name" required placeholder="Client Name"
                            class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-xs text-neutral-800 outline-none accent-focus transition">
                    </div>
                    <div class="flex-1">
                        <input type="tel" name="customer_mobile" required placeholder="Mobile Number" pattern="[0-9]{10}"
                            class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-xs text-neutral-800 outline-none accent-focus transition">
                    </div>
                </div>

                <div class="flex-1 bg-white overflow-y-auto custom-scroll p-4">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-[10px] text-gray-400 uppercase tracking-wider border-b border-gray-100">
                                <th class="pb-2 font-medium">Item Details</th>
                                <th class="pb-2 font-medium text-center">Qty</th>
                                <th class="pb-2 font-medium text-right">Rate</th>
                                <th class="pb-2 font-medium text-right">Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="cartTableBody" class="text-xs text-neutral-800 divide-y divide-gray-50">
                        </tbody>
                    </table>
                    
                    <div id="emptyCartIndicator" class="text-center py-10 opacity-60">
                        <i class="fa-solid fa-basket-shopping text-2xl text-gray-300 mb-2"></i>
                        <p class="text-[11px] text-gray-500 uppercase tracking-wider">Invoice is currently empty</p>
                    </div>
                </div>

                <div id="hiddenCartInputs"></div>

                <div class="bg-white p-5 border-dashed-thick shadow-[0_-10px_20px_rgba(0,0,0,0.03)] z-10">
                    
                    <div class="flex justify-between items-center text-xs mb-2">
                        <span class="text-gray-500 uppercase tracking-wider">Gross Total</span>
                        <span class="font-mono text-neutral-800 font-medium">₹<span id="displayGross">0.00</span></span>
                    </div>

                    <div class="flex justify-between items-center text-xs mb-3">
                        <span class="text-gray-500 uppercase tracking-wider">Apply Discount (%)</span>
                        <div class="flex items-center gap-2">
                            <input type="number" name="discount_percent" id="discountPercent" value="0" min="0" max="100" oninput="calculateTotals()"
                                class="w-16 bg-gray-50 border border-gray-200 rounded-lg px-2 py-1 text-xs text-right font-mono outline-none accent-focus">
                            <span class="font-mono text-rose-500 w-16 text-right">-₹<span id="displayDiscountVal">0.00</span></span>
                        </div>
                    </div>

                    <div class="flex justify-between items-center text-sm mb-4 pt-3 border-t border-gray-100">
                        <span class="font-semibold text-neutral-800 uppercase tracking-wider">Net Amount</span>
                        <span class="font-mono font-bold text-[#EBBB15] text-lg bg-[#222222] px-3 py-1 rounded-lg">₹<span id="displayNet">0.00</span></span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 mb-4">
                        <label class="border border-gray-200 rounded-lg py-2 text-center cursor-pointer hover:bg-gray-50 transition has-[:checked]:border-[#EBBB15] has-[:checked]:bg-[#FFFDF5]">
                            <input type="radio" name="payment_mode" value="Cash" checked class="hidden">
                            <span class="text-[11px] font-medium text-neutral-700 uppercase"><i class="fa-solid fa-money-bill text-emerald-600 mr-1"></i> Cash</span>
                        </label>
                        <label class="border border-gray-200 rounded-lg py-2 text-center cursor-pointer hover:bg-gray-50 transition has-[:checked]:border-[#EBBB15] has-[:checked]:bg-[#FFFDF5]">
                            <input type="radio" name="payment_mode" value="UPI" class="hidden">
                            <span class="text-[11px] font-medium text-neutral-700 uppercase"><i class="fa-solid fa-qrcode text-indigo-600 mr-1"></i> UPI</span>
                        </label>
                        <label class="border border-gray-200 rounded-lg py-2 text-center cursor-pointer hover:bg-gray-50 transition has-[:checked]:border-[#EBBB15] has-[:checked]:bg-[#FFFDF5]">
                            <input type="radio" name="payment_mode" value="Card" class="hidden">
                            <span class="text-[11px] font-medium text-neutral-700 uppercase"><i class="fa-solid fa-credit-card text-blue-600 mr-1"></i> Card</span>
                        </label>
                    </div>

                    <button type="submit" id="submitBtn" disabled class="w-full bg-[#222222] text-[#EBBB15] hover:bg-neutral-800 font-medium text-xs uppercase tracking-widest py-3.5 rounded-xl transition shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-print mr-2"></i> Generate & Print Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        AOS.init({ once: true });

        function toggleCatalog(target) {
            document.getElementById('grid-services').classList.add('hidden');
            document.getElementById('grid-products').classList.add('hidden');
            document.getElementById('btn-services').className = 'px-4 py-1.5 rounded-md text-xs font-medium text-gray-500 hover:text-neutral-800 transition';
            document.getElementById('btn-products').className = 'px-4 py-1.5 rounded-md text-xs font-medium text-gray-500 hover:text-neutral-800 transition';
            document.getElementById(`grid-${target}`).classList.remove('hidden');
            document.getElementById(`btn-${target}`).className = 'px-4 py-1.5 rounded-md text-xs font-medium bg-white shadow-sm text-neutral-800 transition';
        }

        let cartItems = [];

        function addToCart(type, id, name, price) {
            let existingItem = cartItems.find(item => item.type === type && item.id === id);
            if(existingItem) {
                existingItem.qty++;
            } else {
                cartItems.push({ type: type, id: id, name: name, price: parseFloat(price), qty: 1 });
            }
            renderCartUI();
        }

        function updateCartQty(index, delta) {
            if(cartItems[index].qty + delta > 0) {
                cartItems[index].qty += delta;
            } else {
                cartItems.splice(index, 1);
            }
            renderCartUI();
        }

        function removeCartItem(index) {
            cartItems.splice(index, 1);
            renderCartUI();
        }

        function renderCartUI() {
            const tableBody = document.getElementById('cartTableBody');
            const hiddenInputs = document.getElementById('hiddenCartInputs');
            const emptyIndicator = document.getElementById('emptyCartIndicator');
            const submitBtn = document.getElementById('submitBtn');
            
            tableBody.innerHTML = '';
            hiddenInputs.innerHTML = '';
            
            if(cartItems.length === 0) {
                emptyIndicator.classList.remove('hidden');
                submitBtn.disabled = true;
            } else {
                emptyIndicator.classList.add('hidden');
                submitBtn.disabled = false;
                
                cartItems.forEach((item, index) => {
                    const subtotal = item.price * item.qty;
                    tableBody.innerHTML += `
                        <tr>
                            <td class="py-3">
                                <div class="font-medium text-neutral-800 leading-tight">${item.name}</div>
                                <div class="text-[9px] text-gray-400 uppercase tracking-wider">${item.type}</div>
                            </td>
                            <td class="py-3 text-center">
                                <div class="flex items-center justify-center gap-2 bg-gray-50 rounded-lg px-2 py-1 w-max mx-auto border border-gray-100">
                                    <button type="button" onclick="updateCartQty(${index}, -1)" class="text-gray-400 hover:text-rose-500"><i class="fa-solid fa-minus text-[10px]"></i></button>
                                    <span class="font-mono text-xs w-4 text-center">${item.qty}</span>
                                    <button type="button" onclick="updateCartQty(${index}, 1)" class="text-gray-400 hover:text-emerald-500"><i class="fa-solid fa-plus text-[10px]"></i></button>
                                </div>
                            </td>
                            <td class="py-3 text-right font-mono text-gray-500">${item.price.toFixed(2)}</td>
                            <td class="py-3 text-right font-mono font-medium">${subtotal.toFixed(2)}</td>
                            <td class="py-3 text-right pl-2">
                                <button type="button" onclick="removeCartItem(${index})" class="text-gray-300 hover:text-rose-500 transition"><i class="fa-solid fa-xmark"></i></button>
                            </td>
                        </tr>
                    `;
                    hiddenInputs.innerHTML += `
                        <input type="hidden" name="item_type[]" value="${item.type}">
                        <input type="hidden" name="item_id[]" value="${item.id}">
                        <input type="hidden" name="price[]" value="${item.price}">
                        <input type="hidden" name="quantity[]" value="${item.qty}">
                    `;
                });
            }
            calculateTotals();
        }

        function calculateTotals() {
            let grossTotal = 0;
            cartItems.forEach(item => { grossTotal += (item.price * item.qty); });
            const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
            const discountAmount = (grossTotal * discountPercent) / 100;
            let netPayable = grossTotal - discountAmount;
            if(netPayable < 0) netPayable = 0;
            
            document.getElementById('displayGross').textContent = grossTotal.toFixed(2);
            document.getElementById('displayDiscountVal').textContent = discountAmount.toFixed(2);
            document.getElementById('displayNet').textContent = netPayable.toFixed(2);
        }
    </script>
</body>
</html>
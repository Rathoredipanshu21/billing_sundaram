<?php
session_start();
include '../config/db.php';

if (!isset($_GET['id'])) {
    die("Invoice ID is required for editing.");
}
$invoice_id = intval($_GET['id']);

// --- Fetch Admin Loyalty Percentage ---
$loyalty_percent = 10;
try {
    $lp_query = $conn->query("SELECT percentage FROM loyalty_settings LIMIT 1");
    if ($lp_query && $lp_query->num_rows > 0) {
        $loyalty_percent = floatval($lp_query->fetch_assoc()['percentage']);
    }
} catch (Exception $e) {}

// --- Fetch Existing Invoice Data ---
$invStmt = $conn->prepare("SELECT * FROM invoices WHERE id = ?");
$invStmt->bind_param("i", $invoice_id);
$invStmt->execute();
$invoice = $invStmt->get_result()->fetch_assoc();
$invStmt->close();

if (!$invoice) {
    die("Invoice not found.");
}

// Fetch Existing Items
$itemsStmt = $conn->prepare("
    SELECT ii.*, 
           COALESCE(s.service_name, p.product_name) as item_name 
    FROM invoice_items ii 
    LEFT JOIN services s ON ii.item_type = 'Service' AND ii.item_id = s.id 
    LEFT JOIN products p ON ii.item_type = 'Product' AND ii.item_id = p.id 
    WHERE ii.invoice_id = ?
");
$itemsStmt->bind_param("i", $invoice_id);
$itemsStmt->execute();

// FIXED: Store the result first so we don't call get_result() in an infinite loop
$result = $itemsStmt->get_result();
$existingItems = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $existingItems[] = $row;
    }
}
$itemsStmt->close();

// --- AJAX Endpoints (Same as billing_new.php) ---
if (isset($_GET['ajax_search_customers'])) {
    header('Content-Type: application/json');
    $search = '%' . $_GET['ajax_search_customers'] . '%';
    $stmt = $conn->prepare("SELECT customer_mobile, MAX(customer_name) as customer_name FROM invoices WHERE customer_mobile LIKE ? OR customer_name LIKE ? GROUP BY customer_mobile LIMIT 15");
    $stmt->bind_param("ss", $search, $search);
    $stmt->execute();
    $res = $stmt->get_result();
    $customers = [];
    while ($row = $res->fetch_assoc()) { $customers[] = $row; }
    echo json_encode(['success' => true, 'data' => $customers]);
    exit;
}

if (isset($_GET['ajax_check_coupon'])) {
    header('Content-Type: application/json');
    $code = mysqli_real_escape_string($conn, $_GET['ajax_check_coupon']);
    $res = $conn->query("SELECT discount_percent, discount_amount FROM coupons WHERE code='$code' AND status='Active' LIMIT 1");
    if ($res && $res->num_rows > 0) {
        echo json_encode(['success' => true, 'data' => $res->fetch_assoc()]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_GET['ajax_get_loyalty'])) {
    header('Content-Type: application/json');
    $mobile = mysqli_real_escape_string($conn, $_GET['ajax_get_loyalty']);
    $res = $conn->query("SELECT total_points FROM customer_loyalty WHERE customer_mobile='$mobile' LIMIT 1");
    if ($res && $res->num_rows > 0) {
        echo json_encode(['success' => true, 'points' => floatval($res->fetch_assoc()['total_points'])]);
    } else {
        echo json_encode(['success' => true, 'points' => 0]);
    }
    exit;
}

$message = '';
$servicesResult = $conn->query("SELECT id, service_name, category, price FROM services WHERE status='Active' ORDER BY service_name ASC");
$productsResult = $conn->query("SELECT p.id, p.product_name, p.selling_price, p.stock_qty, c.category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.status='Active' ORDER BY p.product_name ASC");
$stylistsResult = $conn->query("SELECT id, stylist_name, commission_rate FROM stylists WHERE status='Active' ORDER BY stylist_name ASC");

// --- Handle Update Logic ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'update_invoice') {
    
    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $customer_mobile = mysqli_real_escape_string($conn, $_POST['customer_mobile']);
    $discount_percent = floatval($_POST['discount_percent']);
    
    $coupon_code = !empty($_POST['coupon_code']) ? mysqli_real_escape_string($conn, $_POST['coupon_code']) : null;
    $gst_enabled = isset($_POST['gst_enabled']) ? 1 : 0;
    $gst_percent = isset($_POST['gst_percent']) ? floatval($_POST['gst_percent']) : 0;
    $gst_amount = isset($_POST['gst_amount']) ? floatval($_POST['gst_amount']) : 0;
    
    $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode'] ?? 'Cash');
    $split_cash = isset($_POST['split_cash']) ? floatval($_POST['split_cash']) : 0;
    $split_upi = isset($_POST['split_upi']) ? floatval($_POST['split_upi']) : 0;
    $split_card = isset($_POST['split_card']) ? floatval($_POST['split_card']) : 0;
    
    $coupon_discount_val = isset($_POST['coupon_discount_val']) ? floatval($_POST['coupon_discount_val']) : 0;
    $lp_discount_val = isset($_POST['lp_discount_val']) ? floatval($_POST['lp_discount_val']) : 0;

    if ($payment_mode !== 'Split') {
        $split_cash = 0; $split_upi = 0; $split_card = 0;
    }

    $types = $_POST['item_type'] ?? [];
    $item_ids = $_POST['item_id'] ?? [];
    $prices = $_POST['price'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $stylist_ids = $_POST['stylist_id'] ?? [];
    
    if (empty($item_ids)) {
        $message = "Please add at least one item.";
    } else {
        $conn->begin_transaction();
        try {
            // 1. REVERT OLD DATA
            // Revert Stock
            $oldProdStmt = $conn->query("SELECT item_id, quantity FROM invoice_items WHERE invoice_id = $invoice_id AND item_type = 'Product'");
            while($oldProd = $oldProdStmt->fetch_assoc()) {
                $conn->query("UPDATE products SET stock_qty = stock_qty + " . $oldProd['quantity'] . " WHERE id = " . $oldProd['item_id']);
            }
            
            // Revert Earned Loyalty Points
            $old_lp_earned = floatval($invoice['loyalty_points_earned']);
            $old_mobile = $invoice['customer_mobile'];
            $conn->query("UPDATE customer_loyalty SET total_points = GREATEST(0, total_points - $old_lp_earned) WHERE customer_mobile = '$old_mobile'");
            
            // Delete old items
            $conn->query("DELETE FROM invoice_items WHERE invoice_id = $invoice_id");

            // 2. CALCULATE NEW TOTALS
            $gross_total = 0;
            $items_to_save = [];
            for ($i = 0; $i < count($item_ids); $i++) {
                $subtotal = floatval($prices[$i]) * intval($quantities[$i]);
                $gross_total += $subtotal;
                $items_to_save[] = [
                    'type' => $types[$i],
                    'id' => intval($item_ids[$i]),
                    'price' => floatval($prices[$i]),
                    'qty' => intval($quantities[$i]),
                    'stylist_id' => !empty($stylist_ids[$i]) ? intval($stylist_ids[$i]) : null,
                    'subtotal' => $subtotal
                ];
            }

            $global_discount_amt = ($gross_total * $discount_percent) / 100;
            $discount_amount = $global_discount_amt + $coupon_discount_val + $lp_discount_val;
            $subtotal_after_discount = max(0, $gross_total - $discount_amount);
            $net_amount = $subtotal_after_discount + $gst_amount;
            
            $new_loyalty_points_earned = ($net_amount * $loyalty_percent) / 100;

            // 3. UPDATE INVOICE
            $updateSql = "
                UPDATE invoices SET 
                    customer_name=?, customer_mobile=?, total_amount=?, discount_percent=?, 
                    discount=?, coupon_code=?, gst_enabled=?, gst_percent=?, gst_amount=?, 
                    net_payable=?, loyalty_points_earned=?, payment_mode=?, split_cash=?, 
                    split_upi=?, split_card=?
                WHERE id=?
            ";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("sssddsddddsddddi", 
                $customer_name, $customer_mobile, $gross_total, $discount_percent, 
                $discount_amount, $coupon_code, $gst_enabled, $gst_percent, $gst_amount, 
                $net_amount, $new_loyalty_points_earned, $payment_mode, $split_cash, 
                $split_upi, $split_card, $invoice_id
            );
            $updateStmt->execute();
            $updateStmt->close();

            // 4. INSERT NEW ITEMS & DEDUCT NEW STOCK
            $itemSql = "INSERT INTO invoice_items(invoice_id, item_type, item_id, stylist_id, price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $itemStmt = $conn->prepare($itemSql);
            foreach ($items_to_save as $item) {
                $itemStmt->bind_param("isiidid", $invoice_id, $item['type'], $item['id'], $item['stylist_id'], $item['price'], $item['qty'], $item['subtotal']);
                $itemStmt->execute();

                if ($item['type'] === 'Product') {
                    $conn->query("UPDATE products SET stock_qty = stock_qty - " . $item['qty'] . " WHERE id = " . $item['id']);
                }
            }
            $itemStmt->close();

            // 5. ADD NEW LP EARNED
            $lpSql = "INSERT INTO customer_loyalty (customer_mobile, total_points) VALUES (?, ?) ON DUPLICATE KEY UPDATE total_points = total_points + ?";
            $lpStmt = $conn->prepare($lpSql);
            $lpStmt->bind_param("sdd", $customer_mobile, $new_loyalty_points_earned, $new_loyalty_points_earned);
            $lpStmt->execute();
            $lpStmt->close();

            // Note: LP Deducted (Redeemed) logic is complex for edits. Assuming standard edit flow where we re-deduct if explicitly set in UI.
            if ($lp_discount_val > 0) {
                $conn->query("UPDATE customer_loyalty SET total_points = GREATEST(0, total_points - $lp_discount_val) WHERE customer_mobile = '$customer_mobile'");
            }

            $conn->commit();
            header("Location: print_invoice.php?id=" . $invoice_id);
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = "Update Failed : " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Invoice #<?php echo $invoice['invoice_no']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f4f4; }
        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .card-hover:hover { border-color: #eab308; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(234, 179, 8, 0.1); }
        .active-tab { background: white; color: black; box-shadow: 0 2px 5px rgba(0,0,0,0.08); }
        .payment-option { transition: 0.2s; }
        .payment-option:hover { border-color: #eab308; }
        .payment-option:has(input:checked) { background: #1f1f1f; color: #facc15; border-color: #1f1f1f; }
        #checkoutContent { transition: all 0.3s ease-in-out; transform-origin: top; }
        .modal { transition: opacity 0.3s ease, visibility 0.3s ease; }
        .modal-content { transition: transform 0.3s ease; }
        .modal.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
        .modal.hidden .modal-content { transform: scale(0.95) translateY(-20px); }
        @media(max-width:992px) { .main-wrapper { flex-direction: column; height: auto; } .left-panel, .right-panel { width: 100%; } body { overflow: auto; } }
    </style>
</head>
<body class="h-screen overflow-hidden p-3">

<div class="h-full bg-[#f8f8f8] rounded-[24px] border p-3 flex flex-col">
    <div class="bg-blue-600 text-white rounded-xl px-5 py-3 mb-3 flex justify-between items-center shrink-0 shadow-sm">
        <div class="flex items-center gap-3">
            <a href="billing_history.php" class="bg-blue-700 hover:bg-blue-800 p-2 rounded-lg transition"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <h1 class="text-sm font-bold uppercase tracking-widest">Editing Invoice</h1>
                <p class="text-blue-200 text-xs font-mono"><?php echo $invoice['invoice_no']; ?></p>
            </div>
        </div>
    </div>

    <div class="main-wrapper flex gap-3 h-full overflow-hidden">
        <div class="left-panel w-[58%] bg-white rounded-[24px] border overflow-hidden flex flex-col shadow-sm relative">
            <div class="flex justify-between items-center px-5 py-3 border-b shrink-0">
                <div class="flex items-center gap-4">
                    <h2 class="text-[14px] font-bold text-[#1f1f1f] uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-layer-group text-yellow-500"></i> Catalog
                    </h2>
                </div>
                <div class="bg-[#f3f3f3] p-1 rounded-xl flex gap-1">
                    <button type="button" onclick="toggleCatalog('services')" id="servicesBtn" class="active-tab text-[12px] px-6 py-2.5 rounded-lg font-semibold transition">Services</button>
                    <button type="button" onclick="toggleCatalog('products')" id="productsBtn" class="text-[12px] px-6 py-2.5 rounded-lg font-semibold text-gray-500 transition">Products</button>
                </div>
            </div>

            <div class="px-5 py-3 border-b bg-[#fafafa] shrink-0">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 transform -translate-y-1/2 text-gray-400 text-[13px]"></i>
                    <input type="text" id="catalogSearch" onkeyup="filterCatalog()" placeholder="Search by name or category..." class="w-full pl-9 pr-4 py-2.5 border-2 border-[#e5e7eb] rounded-xl text-[13px] focus:outline-none focus:border-yellow-400 bg-white transition shadow-sm">
                </div>
            </div>

            <div class="p-4 overflow-y-auto custom-scroll flex-1">
                <div id="servicesGrid" class="grid grid-cols-3 gap-3">
                    <?php while($row = $servicesResult->fetch_assoc()): ?>
                    <div data-name="<?php echo htmlspecialchars(strtolower($row['service_name'])); ?>" data-category="<?php echo htmlspecialchars(strtolower($row['category'])); ?>" onclick="openItemModal('Service', <?php echo $row['id']; ?>, '<?php echo addslashes($row['service_name']); ?>', <?php echo $row['price']; ?>)" class="catalog-item card-hover bg-white border border-gray-200 rounded-2xl p-3.5 cursor-pointer transition min-h-[110px] flex flex-col justify-between">
                        <div>
                            <div class="text-[9px] font-bold text-gray-400 uppercase tracking-widest flex justify-between items-center mb-2">
                                <span><i class="fa-solid fa-scissors text-yellow-500 mr-1"></i> Srvc</span>
                            </div>
                            <h3 class="text-[13px] font-semibold leading-snug text-[#1f1f1f] line-clamp-2"><?php echo htmlspecialchars($row['service_name']); ?></h3>
                        </div>
                        <div class="mt-2 text-[15px] font-bold font-mono text-[#1f1f1f]">₹<?php echo number_format($row['price'], 2); ?></div>
                    </div>
                    <?php endwhile; ?>
                </div>

                <div id="productsGrid" class="hidden grid grid-cols-3 gap-3">
                    <?php while($row = $productsResult->fetch_assoc()): ?>
                    <div data-name="<?php echo htmlspecialchars(strtolower($row['product_name'])); ?>" data-category="<?php echo htmlspecialchars(strtolower($row['category_name'] ?? '')); ?>" onclick="openItemModal('Product', <?php echo $row['id']; ?>, '<?php echo addslashes($row['product_name']); ?>', <?php echo $row['selling_price']; ?>)" class="catalog-item card-hover bg-white border border-gray-200 rounded-2xl p-3.5 cursor-pointer transition min-h-[110px] flex flex-col justify-between">
                        <div>
                            <div class="text-[9px] font-bold text-gray-400 uppercase tracking-widest flex justify-between items-center mb-2">
                                <span><i class="fa-solid fa-box text-green-500 mr-1"></i> Prod</span>
                            </div>
                            <h3 class="text-[13px] font-semibold leading-snug text-[#1f1f1f] line-clamp-2"><?php echo htmlspecialchars($row['product_name']); ?></h3>
                        </div>
                        <div class="mt-2 text-[15px] font-bold font-mono text-[#1f1f1f]">₹<?php echo number_format($row['selling_price'], 2); ?></div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>

        <div class="right-panel w-[42%] bg-white rounded-[24px] border overflow-hidden flex flex-col shadow-sm">
            <form method="POST" class="h-full flex flex-col flex-1 overflow-hidden min-h-0" id="billingForm">
                <input type="hidden" name="action" value="update_invoice">
                
                <input type="hidden" name="split_cash" id="formSplitCash" value="<?php echo $invoice['split_cash']; ?>">
                <input type="hidden" name="split_upi" id="formSplitUpi" value="<?php echo $invoice['split_upi']; ?>">
                <input type="hidden" name="split_card" id="formSplitCard" value="<?php echo $invoice['split_card']; ?>">
                
                <input type="hidden" name="coupon_discount_val" id="hiddenCouponDiscount" value="0">
                <input type="hidden" name="lp_discount_val" id="hiddenLpDiscount" value="0">
                <input type="hidden" name="gst_percent" value="5">
                <input type="hidden" name="gst_amount" id="gstAmountInput" value="<?php echo $invoice['gst_amount']; ?>">
                <div id="hiddenInputs"></div>

                <div class="px-4 pb-3 pt-4 border-b flex gap-3 shrink-0 bg-[#fafafa]">
                    <div class="relative w-1/2">
                        <i class="fa-solid fa-user absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-[12px]"></i>
                        <input type="text" name="customer_name" id="custNameInput" required value="<?php echo htmlspecialchars($invoice['customer_name']); ?>" class="w-full pl-8 pr-3 py-2.5 border-2 border-white focus:border-yellow-400 rounded-xl text-[13px] bg-white outline-none transition shadow-sm font-medium text-neutral-800">
                    </div>
                    <div class="relative w-1/2">
                        <i class="fa-solid fa-phone absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-[12px]"></i>
                        <input type="text" name="customer_mobile" id="custMobileInput" required value="<?php echo htmlspecialchars($invoice['customer_mobile']); ?>" class="w-full pl-8 pr-3 py-2.5 border-2 border-white focus:border-yellow-400 rounded-xl text-[13px] bg-white outline-none transition shadow-sm font-medium text-neutral-800">
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto custom-scroll relative bg-white flex flex-col p-2 min-h-0" id="cartContainer">
                    <div id="cartTableBody" class="flex flex-col gap-2"></div>
                    <div id="emptyCart" class="text-center py-16 text-gray-300 flex-1 flex flex-col justify-center items-center" style="display:none;">
                        <i class="fa-solid fa-basket-shopping text-2xl text-gray-300 mb-3"></i>
                        <p class="uppercase tracking-widest text-[10px] font-bold">Cart is Empty</p>
                    </div>
                </div>

                <div class="border-t bg-white shadow-[0_-4px_15px_rgba(0,0,0,0.05)] z-10 flex flex-col relative shrink-0 max-h-[60%]">
                    
                    <div class="px-5 py-3 bg-gray-50 flex justify-between items-center cursor-pointer hover:bg-gray-100 border-b transition shrink-0" onclick="toggleCheckoutPanel()">
                        <div class="font-bold text-[11px] text-gray-500 uppercase tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-chevron-down transition-transform duration-300" id="checkoutToggleIcon"></i> Bill Summary & Payment
                        </div>
                        <div class="font-bold text-[14px] font-mono text-[#111]">
                            Net Total: ₹<span id="miniNetAmount">0.00</span>
                        </div>
                    </div>

                    <div id="checkoutContent" class="overflow-y-auto custom-scroll bg-white min-h-0 transition-all duration-300">
                        <div class="p-4 border-b border-gray-100">
                            
                            <div class="flex justify-between items-center mb-2 text-[13px]">
                                <span class="font-bold text-gray-500">Gross Total</span>
                                <span class="font-mono font-bold text-gray-800">₹<span id="grossTotal">0.00</span></span>
                            </div>

                            <div class="flex justify-between items-center mb-2 text-[13px]">
                                <span class="font-bold text-gray-500 flex items-center gap-1">Global Discount %</span>
                                <div class="flex items-center gap-2">
                                    <input type="number" name="discount_percent" id="discountPercent" value="<?php echo floatval($invoice['discount_percent']); ?>" min="0" max="100" oninput="calculateTotals()" class="w-16 border rounded-lg px-2 py-1 text-[12px] text-right font-mono outline-none focus:border-yellow-400 bg-white">
                                    <div class="text-rose-500 font-mono text-[12px] font-bold w-16 text-right">-₹<span id="discountAmount">0.00</span></div>
                                </div>
                            </div>

                            <div class="flex justify-between items-center mb-4 text-[13px]">
                                <label class="flex items-center gap-2 cursor-pointer font-bold text-gray-500">
                                    <input type="checkbox" id="gstCheckbox" name="gst_enabled" value="1" <?php echo ($invoice['gst_enabled'] ? 'checked' : ''); ?> onchange="calculateTotals()" class="w-4 h-4 accent-yellow-500">
                                    Apply GST (5%)
                                </label>
                                <div class="text-emerald-600 font-mono text-[12px] font-bold">+₹<span id="gstAmount">0.00</span></div>
                            </div>

                            <div class="border-t border-gray-200 pt-3 flex justify-between items-center">
                                <div class="font-extrabold uppercase tracking-wider text-[14px] text-[#111]">Net Payable</div>
                                <div class="bg-[#111111] text-[#60a5fa] px-4 py-2 rounded-xl font-bold text-[20px] font-mono shadow-md">
                                    ₹<span id="netAmount">0.00</span>
                                </div>
                            </div>
                        </div>

                        <div class="p-4 pt-3 shrink-0">
                            <div class="grid grid-cols-4 gap-2 mb-1">
                                <label class="payment-option border rounded-xl py-2.5 text-center text-[12px] font-semibold cursor-pointer bg-gray-50">
                                    <input type="radio" name="payment_mode" value="Cash" <?php echo ($invoice['payment_mode'] == 'Cash' ? 'checked' : ''); ?> hidden onchange="toggleSplitPayment()">
                                    <i class="fa-solid fa-money-bill text-emerald-500 mb-1 block text-lg"></i> Cash
                                </label>
                                <label class="payment-option border rounded-xl py-2.5 text-center text-[12px] font-semibold cursor-pointer bg-gray-50">
                                    <input type="radio" name="payment_mode" value="UPI" <?php echo ($invoice['payment_mode'] == 'UPI' ? 'checked' : ''); ?> hidden onchange="toggleSplitPayment()">
                                    <i class="fa-solid fa-qrcode text-indigo-500 mb-1 block text-lg"></i> UPI
                                </label>
                                <label class="payment-option border rounded-xl py-2.5 text-center text-[12px] font-semibold cursor-pointer bg-gray-50">
                                    <input type="radio" name="payment_mode" value="Card" <?php echo ($invoice['payment_mode'] == 'Card' ? 'checked' : ''); ?> hidden onchange="toggleSplitPayment()">
                                    <i class="fa-solid fa-credit-card text-blue-500 mb-1 block text-lg"></i> Card
                                </label>
                                <label class="payment-option border rounded-xl py-2.5 text-center text-[12px] font-semibold cursor-pointer bg-gray-50" onclick="if(document.getElementById('splitRadio').checked) openSplitModal();">
                                    <input type="radio" id="splitRadio" name="payment_mode" value="Split" <?php echo ($invoice['payment_mode'] == 'Split' ? 'checked' : ''); ?> hidden onchange="toggleSplitPayment()">
                                    <i class="fa-solid fa-layer-group text-orange-500 mb-1 block text-lg"></i> Split
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="px-4 pb-6 pt-3 bg-white w-full border-t border-gray-50 z-20 shrink-0 mt-auto">
                        <button type="submit" id="submitBtn" class="w-full bg-blue-600 hover:bg-blue-700 transition text-white py-3.5 rounded-xl uppercase tracking-widest text-[13px] font-bold shadow-lg flex items-center justify-center gap-2">
                            <i class="fa-solid fa-floppy-disk text-[15px]"></i> Update Invoice
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>
</div>

<div id="itemSelectionModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="modal-content bg-white w-full max-w-md rounded-[24px] shadow-2xl overflow-hidden flex flex-col">
        <div class="px-6 py-4 bg-[#111111] flex justify-between items-center border-b border-neutral-800">
            <h3 class="font-bold text-white text-[15px] uppercase tracking-widest flex items-center gap-2">
                <i class="fa-solid fa-plus text-yellow-500"></i> Configure & Add Item
            </h3>
            <button type="button" onclick="closeItemModal()" class="text-gray-400 hover:text-white transition text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <div class="p-6 space-y-4 bg-gray-50/50">
            <h4 id="modalItemNameDisplay" class="text-[16px] font-bold text-gray-800 leading-tight"></h4>
            <div id="modalStylistWrapper">
                <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">Select Employee / Stylist</label>
                <select id="modalItemStylist" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] bg-white outline-none focus:border-yellow-400 transition font-medium shadow-sm"></select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">Manual Price (₹)</label>
                    <input type="number" id="modalItemPrice" step="0.01" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] font-medium shadow-sm font-mono">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">Discount (%)</label>
                    <input type="number" id="modalItemDiscount" step="0.01" min="0" max="100" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] font-medium shadow-sm font-mono">
                </div>
            </div>
        </div>

        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3 rounded-b-[24px]">
            <button type="button" onclick="closeItemModal()" class="px-6 py-2.5 rounded-xl border border-gray-300 text-[12px] font-bold">Cancel</button>
            <button type="button" onclick="confirmAddItem()" class="px-6 py-2.5 rounded-xl bg-[#111] text-yellow-400 text-[12px] font-bold flex items-center gap-2">Add to Cart</button>
        </div>
    </div>
</div>

<div id="splitPaymentModal" class="modal hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="modal-content bg-white w-full max-w-md rounded-[24px] shadow-2xl overflow-hidden flex flex-col">
        <div class="px-6 py-4 bg-[#111111] flex justify-between items-center border-b border-neutral-800">
            <h3 class="font-bold text-white text-[15px] uppercase tracking-widest flex items-center gap-2">Split Amounts</h3>
            <button type="button" onclick="closeSplitModal()" class="text-gray-400 hover:text-white transition text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="p-6 bg-orange-50/30">
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="text-[10px] uppercase font-bold">Cash Amount</label>
                    <input type="number" step="0.01" min="0" id="modalSplitCash" value="<?php echo $invoice['split_cash']; ?>" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] font-mono" oninput="calculateSplitRemaining()">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-bold">UPI Amount</label>
                    <input type="number" step="0.01" min="0" id="modalSplitUpi" value="<?php echo $invoice['split_upi']; ?>" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] font-mono" oninput="calculateSplitRemaining()">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-bold">Card Amount</label>
                    <input type="number" step="0.01" min="0" id="modalSplitCard" value="<?php echo $invoice['split_card']; ?>" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] font-mono" oninput="calculateSplitRemaining()">
                </div>
            </div>
            <div class="mt-5 pt-4 border-t border-orange-200/50 flex justify-between items-center">
                <span class="text-gray-600 font-bold uppercase tracking-wider text-[12px]">Remaining Due</span>
                <span class="font-mono text-[20px] font-bold text-rose-500">₹<span id="remainingAmount">0.00</span></span>
            </div>
        </div>
        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3 rounded-b-[24px]">
            <button type="button" onclick="closeSplitModal()" class="px-6 py-2.5 rounded-xl bg-[#111] text-white text-[12px] font-bold w-full">Done & Save Amounts</button>
        </div>
    </div>
</div>

<script>
let stylistOptionsHtml = '<option value="">Select Employee</option>';
let stylistMap = {};
<?php
mysqli_data_seek($stylistsResult, 0);
while($stylist = $stylistsResult->fetch_assoc()) {
    echo 'stylistOptionsHtml += `<option value="'.$stylist['id'].'">'.addslashes($stylist['stylist_name']).'</option>`;';
    echo 'stylistMap['.$stylist['id'].'] = "'.addslashes($stylist['stylist_name']).'";';
}
?>

// Inject Existing Cart Items
let cartItems = <?php 
    $js_items = [];
    foreach($existingItems as $item) {
        $js_items[] = [
            'type' => $item['item_type'],
            'id' => intval($item['item_id']),
            'name' => addslashes($item['item_name']),
            'original_price' => floatval($item['price']),
            'manual_price' => floatval($item['price']),
            'price' => floatval($item['price']),
            'qty' => intval($item['quantity']),
            'isBenefit' => false, // Set to false to avoid complexity of membership edits
            'stylist_id' => $item['stylist_id']
        ];
    }
    echo json_encode($js_items);
?>;

let currentItemSelection = null;
let currentCoupon = { code: '', discount_percent: 0, discount_amount: 0 };
let currentLpDiscount = 0; // Keeping 0 for edit flow unless explicit logic is needed

window.onload = () => {
    renderCart();
};

function toggleCatalog(type) {
    document.getElementById('servicesGrid').classList.add('hidden');
    document.getElementById('productsGrid').classList.add('hidden');
    document.getElementById(type+'Grid').classList.remove('hidden');
    document.getElementById('servicesBtn').classList.remove('active-tab');
    document.getElementById('productsBtn').classList.remove('active-tab');
    document.getElementById(type+'Btn').classList.add('active-tab');
}

function filterCatalog() {
    const query = document.getElementById('catalogSearch').value.toLowerCase();
    const items = document.querySelectorAll('.catalog-item');
    items.forEach(item => {
        const name = item.getAttribute('data-name') || '';
        const cat = item.getAttribute('data-category') || '';
        item.style.display = (name.includes(query) || cat.includes(query)) ? '' : 'none';
    });
}

function toggleCheckoutPanel() {
    const content = document.getElementById('checkoutContent');
    const icon = document.getElementById('checkoutToggleIcon');
    if (content.classList.contains('hidden')) {
        content.classList.remove('hidden');
        icon.style.transform = 'rotate(0deg)';
    } else {
        content.classList.add('hidden');
        icon.style.transform = 'rotate(180deg)';
    }
}

function openItemModal(type, id, name, original_price) {
    currentItemSelection = { type, id, name, original_price };
    document.getElementById('modalItemNameDisplay').innerText = name;
    document.getElementById('modalItemPrice').value = original_price;
    document.getElementById('modalItemDiscount').value = 0;
    
    const stylistSelect = document.getElementById('modalItemStylist');
    stylistSelect.innerHTML = stylistOptionsHtml;
    document.getElementById('modalStylistWrapper').style.display = (type === 'Service') ? 'block' : 'none';
    document.getElementById('itemSelectionModal').classList.remove('hidden');
}

function closeItemModal() {
    document.getElementById('itemSelectionModal').classList.add('hidden');
    currentItemSelection = null;
}

function confirmAddItem() {
    if(!currentItemSelection) return;
    let { type, id, name, original_price } = currentItemSelection;
    let stylist_id = document.getElementById('modalItemStylist').value;
    let edited_price = parseFloat(document.getElementById('modalItemPrice').value) || 0;
    let discount_percent = parseFloat(document.getElementById('modalItemDiscount').value) || 0;
    
    let manual_price = edited_price - (edited_price * discount_percent / 100);
    
    let existing = cartItems.find(item => item.type === type && item.id === id && item.price === manual_price && item.stylist_id === stylist_id);
    if(existing) {
        existing.qty++;
    } else {
        cartItems.push({ 
            type: type, id: id, name: name, 
            original_price: parseFloat(original_price), 
            manual_price: manual_price, price: manual_price, 
            qty: 1, isBenefit: false, stylist_id: stylist_id
        });
    }
    renderCart();
    closeItemModal();
}

function updateQty(index, delta) {
    if(cartItems[index].qty + delta > 0) cartItems[index].qty += delta;
    else cartItems.splice(index, 1);
    renderCart();
}

function removeItem(index) {
    cartItems.splice(index, 1);
    renderCart();
}

function renderCart() {
    const tableBody = document.getElementById('cartTableBody');
    const hiddenInputs = document.getElementById('hiddenInputs');
    tableBody.innerHTML = '';
    hiddenInputs.innerHTML = '';

    if(cartItems.length === 0) {
        document.getElementById('emptyCart').style.display = 'flex';
        document.getElementById('submitBtn').disabled = true;
    } else {
        document.getElementById('emptyCart').style.display = 'none';
        document.getElementById('submitBtn').disabled = false; 
    }

    cartItems.forEach((item, index) => {
        const subtotal = item.price * item.qty;
        tableBody.innerHTML += `
            <div class="p-3 rounded-xl border bg-white border-gray-200 flex flex-col gap-2">
                <div class="flex justify-between items-start gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="text-[13px] font-bold text-[#1f1f1f]">${item.name}</div>
                        <span class="text-[9px] uppercase tracking-widest text-gray-400 font-bold">${item.type}</span>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-[14px] font-mono font-bold text-[#1f1f1f]">₹${subtotal.toFixed(2)}</div>
                        <div class="text-[10px] font-mono text-gray-400">₹${item.price.toFixed(2)} x ${item.qty}</div>
                    </div>
                </div>
                <div class="flex justify-between items-end gap-2 mt-1">
                    <div class="w-[60%]">
                        ${item.type === 'Service' ? `<div class="text-[11px] text-gray-500 font-medium bg-gray-50 px-2 py-1 rounded border inline-block truncate max-w-full"><i class="fa-solid fa-user-pen text-gray-400"></i> ${stylistMap[item.stylist_id] || 'No Stylist Selected'}</div>` : ``}
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex items-center border border-gray-200 rounded-lg overflow-hidden bg-white shadow-sm">
                            <button type="button" onclick="updateQty(${index}, -1)" class="w-7 h-7 flex items-center justify-center hover:bg-gray-100 font-bold">-</button>
                            <span class="text-[12px] font-bold min-w-[24px] text-center font-mono">${item.qty}</span>
                            <button type="button" onclick="updateQty(${index}, 1)" class="w-7 h-7 flex items-center justify-center hover:bg-gray-100 font-bold">+</button>
                        </div>
                        <button type="button" onclick="removeItem(${index})" class="text-rose-400 hover:text-rose-600 hover:bg-rose-50 w-7 h-7 rounded-lg flex items-center justify-center transition"><i class="fa-solid fa-trash-can text-[12px]"></i></button>
                    </div>
                </div>
            </div>`;
            
        hiddenInputs.innerHTML += `
            <input type="hidden" name="item_type[]" value="${item.type}">
            <input type="hidden" name="item_id[]" value="${item.id}">
            <input type="hidden" name="price[]" value="${item.price}">
            <input type="hidden" name="quantity[]" value="${item.qty}">
            <input type="hidden" name="stylist_id[]" value="${item.stylist_id || ''}">
        `;
    });
    calculateTotals();
}

function calculateTotals() {
    let gross = 0;
    cartItems.forEach(item => { gross += item.price * item.qty; });

    const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
    const globalDiscountAmount = (gross * discountPercent) / 100;
    const couponDiscountAmount = (currentCoupon.discount_percent > 0) ? ((gross * currentCoupon.discount_percent) / 100) : currentCoupon.discount_amount;
    const totalDiscount = globalDiscountAmount + couponDiscountAmount + currentLpDiscount;
    
    let subtotal = Math.max(0, gross - totalDiscount);
    const gstEnabled = document.getElementById('gstCheckbox').checked;
    const gstAmount = gstEnabled ? (subtotal * 5) / 100 : 0;
    let net = subtotal + gstAmount;

    document.getElementById('grossTotal').textContent = gross.toFixed(2);
    document.getElementById('discountAmount').textContent = globalDiscountAmount.toFixed(2);
    document.getElementById('gstAmount').textContent = gstAmount.toFixed(2);
    document.getElementById('gstAmountInput').value = gstAmount.toFixed(2);
    document.getElementById('netAmount').textContent = net.toFixed(2);
    document.getElementById('miniNetAmount').textContent = net.toFixed(2);

    calculateSplitRemaining();
}

function openSplitModal() { document.getElementById('splitPaymentModal').classList.remove('hidden'); calculateSplitRemaining(); }
function closeSplitModal() { document.getElementById('splitPaymentModal').classList.add('hidden'); }
function toggleSplitPayment() { if(document.querySelector('input[name="payment_mode"]:checked').value === 'Split') openSplitModal(); }

function calculateSplitRemaining() {
    const net = parseFloat(document.getElementById('netAmount').textContent) || 0;
    const cash = parseFloat(document.getElementById('modalSplitCash').value) || 0;
    const upi = parseFloat(document.getElementById('modalSplitUpi').value) || 0;
    const card = parseFloat(document.getElementById('modalSplitCard').value) || 0;
    
    document.getElementById('formSplitCash').value = cash;
    document.getElementById('formSplitUpi').value = upi;
    document.getElementById('formSplitCard').value = card;

    document.getElementById('remainingAmount').textContent = (net - (cash + upi + card)).toFixed(2);
}
</script>
</body>
</html>
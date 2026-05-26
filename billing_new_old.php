<?php
session_start();
include '../config/db.php';

// --- AJAX Endpoint for Membership Search ---
if (isset($_GET['ajax_search_member'])) {
    header('Content-Type: application/json');
    $search = '%' . $_GET['ajax_search_member'] . '%';
    
    // Find active membership matching Code, Name, or Mobile
    $stmt = $conn->prepare("
        SELECT cs.*, s.plan_name, s.discount_percent, s.service_benefits as plan_benefits 
        FROM client_subscriptions cs 
        JOIN subscriptions s ON cs.subscription_plan_id = s.id 
        WHERE (cs.membership_code LIKE ? OR cs.client_name LIKE ? OR cs.client_contact LIKE ?) 
        AND cs.status = 'Active' AND cs.end_date >= CURDATE() 
        LIMIT 1
    ");
    $stmt->bind_param("sss", $search, $search, $search);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows > 0) {
        $member = $res->fetch_assoc();
        
        // Lazy load: If remaining_benefits is NULL (new issue or older record), sync it from the master plan
        if (empty($member['remaining_benefits'])) {
            $member['remaining_benefits'] = $member['plan_benefits'];
            $update = $conn->prepare("UPDATE client_subscriptions SET remaining_benefits = ? WHERE id = ?");
            $update->bind_param("si", $member['plan_benefits'], $member['id']);
            $update->execute();
        }
        
        // Decode benefits to fetch actual service names for the UI
        $benefits_list = [];
        $benefits_arr = json_decode($member['remaining_benefits'], true);
        if (is_array($benefits_arr)) {
            foreach ($benefits_arr as $b) {
                if ($b['qty'] > 0) {
                    $srvStmt = $conn->query("SELECT service_name FROM services WHERE id = " . intval($b['service_id']));
                    $srvName = $srvStmt->fetch_assoc()['service_name'] ?? 'Unknown';
                    $benefits_list[] = [
                        'service_id' => $b['service_id'],
                        'service_name' => $srvName,
                        'qty' => $b['qty']
                    ];
                }
            }
        }
        $member['parsed_benefits'] = $benefits_list;
        
        echo json_encode(['success' => true, 'data' => $member]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}
// --- End AJAX ---

$message = '';

// Updated queries to include category for search functionality
$servicesResult = $conn->query("SELECT id, service_name, category, price FROM services WHERE status='Active' ORDER BY service_name ASC");

$productsResult = $conn->query("
    SELECT p.id, p.product_name, p.selling_price, p.stock_qty, c.category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.status='Active' 
    ORDER BY p.product_name ASC
");

$stylistsResult = $conn->query("SELECT id, stylist_name, commission_rate FROM stylists WHERE status='Active' ORDER BY stylist_name ASC");

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'generate_invoice') {

    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $customer_mobile = mysqli_real_escape_string($conn, $_POST['customer_mobile']);
    $discount_percent = floatval($_POST['discount_percent']);
    
    $client_subscription_id = !empty($_POST['client_subscription_id']) ? intval($_POST['client_subscription_id']) : null;

    $gst_enabled = isset($_POST['gst_enabled']) ? 1 : 0;
    $gst_percent = isset($_POST['gst_percent']) ? floatval($_POST['gst_percent']) : 0;
    $gst_amount = isset($_POST['gst_amount']) ? floatval($_POST['gst_amount']) : 0;

    $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode'] ?? 'Cash');

    $split_cash = isset($_POST['split_cash']) ? floatval($_POST['split_cash']) : 0;
    $split_upi = isset($_POST['split_upi']) ? floatval($_POST['split_upi']) : 0;
    $split_card = isset($_POST['split_card']) ? floatval($_POST['split_card']) : 0;

    if ($payment_mode !== 'Split') {
        $split_cash = 0;
        $split_upi = 0;
        $split_card = 0;
    }

    $types = $_POST['item_type'] ?? [];
    $item_ids = $_POST['item_id'] ?? [];
    $prices = $_POST['price'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $stylist_ids = $_POST['stylist_id'] ?? [];
    $is_benefits = $_POST['is_benefit'] ?? [];

    if (empty($item_ids)) {
        $message = "Please add at least one item.";
    } else {
        $conn->begin_transaction();

        try {
            $invoice_no = "INV-" . date("Ymd") . "-" . rand(1000, 9999);
            $gross_total = 0;
            $items_to_save = [];
            $used_benefits_map = []; // Track used benefits for this transaction

            for ($i = 0; $i < count($item_ids); $i++) {
                $subtotal = floatval($prices[$i]) * intval($quantities[$i]);
                $gross_total += $subtotal;
                
                $benefit_flag = isset($is_benefits[$i]) && $is_benefits[$i] == 1 ? 1 : 0;

                if ($benefit_flag === 1 && $types[$i] === 'Service') {
                    $srv_id = intval($item_ids[$i]);
                    if (!isset($used_benefits_map[$srv_id])) $used_benefits_map[$srv_id] = 0;
                    $used_benefits_map[$srv_id] += intval($quantities[$i]);
                }

                $items_to_save[] = [
                    'type' => $types[$i],
                    'id' => intval($item_ids[$i]),
                    'price' => floatval($prices[$i]),
                    'qty' => intval($quantities[$i]),
                    'stylist_id' => !empty($stylist_ids[$i]) ? intval($stylist_ids[$i]) : null,
                    'subtotal' => $subtotal
                ];
            }

            $discount_amount = ($gross_total * $discount_percent) / 100;
            $subtotal_after_discount = $gross_total - $discount_amount;
            $net_amount = $subtotal_after_discount + $gst_amount;

            $invoiceSql = "
                INSERT INTO invoices(
                    invoice_no, customer_name, customer_mobile, total_amount, 
                    discount_percent, discount, gst_enabled, gst_percent, 
                    gst_amount, net_payable, payment_mode, split_cash, 
                    split_upi, split_card
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $invoiceStmt = $conn->prepare($invoiceSql);
            $invoiceStmt->bind_param(
                "sssdddidddsddd",
                $invoice_no, $customer_name, $customer_mobile, $gross_total,
                $discount_percent, $discount_amount, $gst_enabled, $gst_percent,
                $gst_amount, $net_amount, $payment_mode, $split_cash,
                $split_upi, $split_card
            );
            $invoiceStmt->execute();
            $invoice_id = $invoiceStmt->insert_id;
            $invoiceStmt->close();

            $itemSql = "
                INSERT INTO invoice_items(
                    invoice_id, item_type, item_id, stylist_id, price, quantity, subtotal
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ";
            $itemStmt = $conn->prepare($itemSql);

            foreach ($items_to_save as $item) {
                $itemStmt->bind_param(
                    "isiidid",
                    $invoice_id, $item['type'], $item['id'], $item['stylist_id'],
                    $item['price'], $item['qty'], $item['subtotal']
                );
                $itemStmt->execute();

                if ($item['type'] === 'Product') {
                    $stockSql = "UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?";
                    $stockStmt = $conn->prepare($stockSql);
                    $stockStmt->bind_param("ii", $item['qty'], $item['id']);
                    $stockStmt->execute();
                    $stockStmt->close();
                }
            }
            $itemStmt->close();

            // --- Deduct Used Benefits ---
            if ($client_subscription_id && !empty($used_benefits_map)) {
                $benStmt = $conn->prepare("SELECT remaining_benefits FROM client_subscriptions WHERE id = ?");
                $benStmt->bind_param("i", $client_subscription_id);
                $benStmt->execute();
                $benResult = $benStmt->get_result();
                $currentBenefits = json_decode($benResult->fetch_assoc()['remaining_benefits'] ?? '[]', true);
                $benStmt->close();

                if (is_array($currentBenefits)) {
                    foreach ($currentBenefits as &$cb) {
                        $s_id = $cb['service_id'];
                        if (isset($used_benefits_map[$s_id])) {
                            $cb['qty'] = max(0, $cb['qty'] - $used_benefits_map[$s_id]);
                        }
                    }
                    $updatedBenefitsJson = json_encode($currentBenefits);
                    $updateBenStmt = $conn->prepare("UPDATE client_subscriptions SET remaining_benefits = ? WHERE id = ?");
                    $updateBenStmt->bind_param("si", $updatedBenefitsJson, $client_subscription_id);
                    $updateBenStmt->execute();
                    $updateBenStmt->close();
                }
            }

            $conn->commit();
            header("Location: print_invoice.php?id=" . $invoice_id);
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = "Billing Failed : " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salon POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f4f4; }
        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .card-hover:hover { border-color: #eab308; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(234, 179, 8, 0.1); }
        .active-tab { background: white; color: black; box-shadow: 0 2px 5px rgba(0,0,0,0.08); }
        .payment-option { transition: 0.2s; }
        .payment-option:hover { border-color: #eab308; }
        .payment-option:has(input:checked) { background: #1f1f1f; color: #facc15; border-color: #1f1f1f; }
        @media(max-width:992px) {
            .main-wrapper { flex-direction: column; height: auto; }
            .left-panel, .right-panel { width: 100%; }
            body { overflow: auto; }
        }
    </style>
</head>
<body class="h-screen overflow-hidden p-3">

<div class="h-full bg-[#f8f8f8] rounded-[24px] border p-3">
    <div class="main-wrapper flex gap-3 h-full">

        <div class="left-panel w-[58%] bg-white rounded-[24px] border overflow-hidden flex flex-col shadow-sm">
            
            <div class="flex justify-between items-center px-5 py-3 border-b shrink-0">
                <h2 class="text-[14px] font-bold text-[#1f1f1f] uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-layer-group text-yellow-500"></i> Catalog
                </h2>
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
                    <div data-name="<?php echo htmlspecialchars(strtolower($row['service_name'])); ?>" data-category="<?php echo htmlspecialchars(strtolower($row['category'])); ?>" onclick="addToCart('Service', <?php echo $row['id']; ?>, '<?php echo addslashes($row['service_name']); ?>', <?php echo $row['price']; ?>)" class="catalog-item card-hover bg-white border border-gray-200 rounded-2xl p-3.5 cursor-pointer transition min-h-[110px] flex flex-col justify-between">
                        <div>
                            <div class="text-[9px] font-bold text-gray-400 uppercase tracking-widest flex justify-between items-center mb-2">
                                <span><i class="fa-solid fa-scissors text-yellow-500 mr-1"></i> Srvc</span>
                                <span class="bg-gray-100 px-1.5 py-0.5 rounded truncate max-w-[60px] text-right" title="<?php echo htmlspecialchars($row['category']); ?>"><?php echo htmlspecialchars($row['category'] ?? ''); ?></span>
                            </div>
                            <h3 class="text-[13px] font-semibold leading-snug text-[#1f1f1f] line-clamp-2"><?php echo htmlspecialchars($row['service_name']); ?></h3>
                        </div>
                        <div class="mt-2 text-[15px] font-bold font-mono text-[#1f1f1f]">₹<?php echo number_format($row['price'], 2); ?></div>
                    </div>
                    <?php endwhile; ?>
                </div>

                <div id="productsGrid" class="hidden grid grid-cols-3 gap-3">
                    <?php while($row = $productsResult->fetch_assoc()): ?>
                    <div data-name="<?php echo htmlspecialchars(strtolower($row['product_name'])); ?>" data-category="<?php echo htmlspecialchars(strtolower($row['category_name'] ?? '')); ?>" onclick="addToCart('Product', <?php echo $row['id']; ?>, '<?php echo addslashes($row['product_name']); ?>', <?php echo $row['selling_price']; ?>)" class="catalog-item card-hover bg-white border border-gray-200 rounded-2xl p-3.5 cursor-pointer transition min-h-[110px] flex flex-col justify-between">
                        <div>
                            <div class="text-[9px] font-bold text-gray-400 uppercase tracking-widest flex justify-between items-center mb-2">
                                <span><i class="fa-solid fa-box text-green-500 mr-1"></i> Prod</span>
                                <span class="bg-gray-100 px-1.5 py-0.5 rounded">Stock: <?php echo $row['stock_qty']; ?></span>
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
            
            <div class="bg-[#111111] p-4 shrink-0 text-white relative">
                <h3 class="text-[11px] uppercase tracking-widest text-yellow-500 font-bold mb-2 flex items-center gap-2">
                    <i class="fa-solid fa-magnifying-glass"></i> Member Check
                </h3>
                <div class="flex gap-2">
                    <input type="text" id="memberSearchInput" placeholder="Code, Name, or Mobile..." class="flex-1 border border-neutral-700 bg-neutral-800 text-white rounded-xl px-3 py-2 text-[12px] outline-none focus:border-yellow-500 transition placeholder:text-gray-500">
                    <button type="button" onclick="searchMember()" class="bg-yellow-500 hover:bg-yellow-400 text-[#111] px-5 py-2 rounded-xl text-[12px] font-bold transition shadow-sm">Verify</button>
                </div>
                
                <div id="activeMemberBox" class="hidden mt-3 p-3 bg-neutral-800 border border-neutral-700 rounded-xl relative overflow-hidden">
                    <button type="button" onclick="clearMember()" class="absolute top-2 right-2 text-gray-400 hover:text-red-400 transition"><i class="fa-solid fa-xmark"></i></button>
                    <div class="flex gap-3 items-center mb-2">
                        <i class="fa-solid fa-crown text-yellow-500 text-2xl"></i>
                        <div>
                            <div class="text-[13px] font-bold text-white" id="memNameDisplay"></div>
                            <div class="text-[10px] text-gray-400 font-mono tracking-wider mt-0.5" id="memCodeDisplay"></div>
                        </div>
                        <div class="ml-auto text-right pr-4">
                            <div class="text-[9px] uppercase tracking-widest text-gray-400">Discount</div>
                            <div class="text-[14px] font-bold text-emerald-400" id="memDiscountDisplay"></div>
                        </div>
                    </div>
                    <div class="border-t border-neutral-700 pt-2 mt-2" id="memBenefitsArea">
                        </div>
                </div>
            </div>

            <form method="POST" class="h-full flex flex-col flex-1 overflow-hidden" id="billingForm">
                <input type="hidden" name="action" value="generate_invoice">
                <input type="hidden" name="client_subscription_id" id="clientSubIdInput" value="">

                <div class="px-4 py-3 border-b flex gap-3 shrink-0 bg-[#fafafa]">
                    <div class="relative w-1/2">
                        <i class="fa-solid fa-user absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-[12px]"></i>
                        <input type="text" name="customer_name" id="custNameInput" required placeholder="Client Name" class="w-full pl-8 pr-3 py-2.5 border-2 border-white focus:border-yellow-400 rounded-xl text-[13px] bg-white outline-none transition shadow-sm font-medium">
                    </div>
                    <div class="relative w-1/2">
                        <i class="fa-solid fa-phone absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-[12px]"></i>
                        <input type="text" name="customer_mobile" id="custMobileInput" required placeholder="Mobile Number" class="w-full pl-8 pr-3 py-2.5 border-2 border-white focus:border-yellow-400 rounded-xl text-[13px] bg-white outline-none transition shadow-sm font-medium">
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto custom-scroll relative bg-white flex flex-col p-2" id="cartContainer">
                    <div id="cartTableBody" class="flex flex-col gap-2">
                        </div>

                    <div id="emptyCart" class="text-center py-16 text-gray-300 flex-1 flex flex-col justify-center items-center">
                        <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-3 border border-gray-100">
                            <i class="fa-solid fa-basket-shopping text-2xl text-gray-300"></i>
                        </div>
                        <p class="uppercase tracking-widest text-[10px] font-bold">Cart is Empty</p>
                    </div>
                </div>

                <div id="hiddenInputs"></div>
                <input type="hidden" name="gst_percent" value="5">
                <input type="hidden" name="gst_amount" id="gstAmountInput" value="0">

                <div class="p-4 border-t bg-white shrink-0 shadow-[0_-4px_15px_rgba(0,0,0,0.03)] z-10">
                    <div class="flex justify-between items-center mb-2.5 text-[13px]">
                        <span class="font-bold text-gray-500">Gross Total</span>
                        <span class="font-mono font-bold text-gray-800">₹<span id="grossTotal">0.00</span></span>
                    </div>

                    <div class="flex justify-between items-center mb-2.5 text-[13px]">
                        <span class="font-bold text-gray-500 flex items-center gap-1">Discount % <i class="fa-solid fa-tags text-yellow-500 text-[10px]" id="discountIcon" style="display:none;"></i></span>
                        <div class="flex items-center gap-2">
                            <input type="number" name="discount_percent" id="discountPercent" value="0" min="0" max="100" oninput="calculateTotals()" class="w-16 border rounded-lg px-2 py-1 text-[12px] text-right font-mono outline-none focus:border-yellow-400">
                            <div class="text-rose-500 font-mono text-[12px] font-semibold w-16 text-right">-₹<span id="discountAmount">0.00</span></div>
                        </div>
                    </div>

                    <div class="flex justify-between items-center mb-4 text-[13px]">
                        <label class="flex items-center gap-2 cursor-pointer font-bold text-gray-500">
                            <input type="checkbox" id="gstCheckbox" name="gst_enabled" value="1" checked onchange="calculateTotals()" class="w-4 h-4 accent-yellow-500">
                            Apply GST (5%)
                        </label>
                        <div class="text-emerald-600 font-mono text-[12px] font-semibold">+₹<span id="gstAmount">0.00</span></div>
                    </div>

                    <div class="border-t border-dashed border-gray-200 pt-3 flex justify-between items-center mb-4">
                        <div class="font-extrabold uppercase tracking-wider text-[14px] text-[#111]">Net Payable</div>
                        <div class="bg-[#111111] text-yellow-400 px-4 py-2 rounded-xl font-bold text-[20px] font-mono shadow-md">
                            ₹<span id="netAmount">0.00</span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="grid grid-cols-4 gap-2 mb-3">
                            <label class="payment-option border rounded-xl py-2.5 text-center text-[12px] font-semibold cursor-pointer bg-gray-50">
                                <input type="radio" name="payment_mode" value="Cash" checked hidden onchange="toggleSplitPayment()">
                                <i class="fa-solid fa-money-bill text-emerald-500 mb-1 block text-lg"></i> Cash
                            </label>
                            <label class="payment-option border rounded-xl py-2.5 text-center text-[12px] font-semibold cursor-pointer bg-gray-50">
                                <input type="radio" name="payment_mode" value="UPI" hidden onchange="toggleSplitPayment()">
                                <i class="fa-solid fa-qrcode text-indigo-500 mb-1 block text-lg"></i> UPI
                            </label>
                            <label class="payment-option border rounded-xl py-2.5 text-center text-[12px] font-semibold cursor-pointer bg-gray-50">
                                <input type="radio" name="payment_mode" value="Card" hidden onchange="toggleSplitPayment()">
                                <i class="fa-solid fa-credit-card text-blue-500 mb-1 block text-lg"></i> Card
                            </label>
                            <label class="payment-option border rounded-xl py-2.5 text-center text-[12px] font-semibold cursor-pointer bg-gray-50">
                                <input type="radio" name="payment_mode" value="Split" hidden onchange="toggleSplitPayment()">
                                <i class="fa-solid fa-layer-group text-orange-500 mb-1 block text-lg"></i> Split
                            </label>
                        </div>

                        <div id="splitPaymentBox" class="hidden border border-orange-200 rounded-xl p-3 bg-orange-50/30">
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">Cash</label>
                                    <input type="number" step="0.01" min="0" name="split_cash" id="splitCash" value="0" class="w-full border-2 border-white rounded-lg px-2 py-1.5 text-[12px] font-mono outline-none focus:border-yellow-400 shadow-sm" oninput="calculateSplitRemaining()">
                                </div>
                                <div>
                                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">UPI</label>
                                    <input type="number" step="0.01" min="0" name="split_upi" id="splitUpi" value="0" class="w-full border-2 border-white rounded-lg px-2 py-1.5 text-[12px] font-mono outline-none focus:border-yellow-400 shadow-sm" oninput="calculateSplitRemaining()">
                                </div>
                                <div>
                                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">Card</label>
                                    <input type="number" step="0.01" min="0" name="split_card" id="splitCard" value="0" class="w-full border-2 border-white rounded-lg px-2 py-1.5 text-[12px] font-mono outline-none focus:border-yellow-400 shadow-sm" oninput="calculateSplitRemaining()">
                                </div>
                            </div>
                            <div class="mt-2 pt-2 border-t border-orange-200/50 flex justify-between items-center text-[11px] font-bold">
                                <span class="text-gray-600 uppercase tracking-wider">Remaining Due</span>
                                <span class="font-mono text-[13px] text-rose-500">₹<span id="remainingAmount">0.00</span></span>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="submitBtn" disabled class="w-full bg-[#111111] hover:bg-black transition text-yellow-500 py-3.5 rounded-xl uppercase tracking-widest text-[13px] font-bold disabled:opacity-50 disabled:cursor-not-allowed shadow-lg flex items-center justify-center gap-2">
                        <i class="fa-solid fa-print"></i> Generate Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// --- Search Filter Logic ---
function filterCatalog() {
    const query = document.getElementById('catalogSearch').value.toLowerCase();
    const items = document.querySelectorAll('.catalog-item');
    
    items.forEach(item => {
        const name = item.getAttribute('data-name') || '';
        const cat = item.getAttribute('data-category') || '';
        if (name.includes(query) || cat.includes(query)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

// --- Membership Logic Variables ---
let activeMember = null;
let benefitMap = {}; // Tracks { service_id: remaining_qty } internally

// --- Search Member AJAX ---
async function searchMember() {
    const query = document.getElementById('memberSearchInput').value.trim();
    if (!query) return;
    
    try {
        const response = await fetch(`billing_new.php?ajax_search_member=${encodeURIComponent(query)}`);
        const result = await response.json();
        
        if (result.success) {
            setupActiveMember(result.data);
        } else {
            alert('No active membership found for this query. It may be expired or invalid.');
        }
    } catch (e) {
        console.error("Search failed", e);
    }
}

function setupActiveMember(data) {
    activeMember = data;
    
    benefitMap = {};
    if (data.parsed_benefits) {
        data.parsed_benefits.forEach(b => {
            benefitMap[b.service_id] = parseInt(b.qty);
        });
    }

    document.getElementById('activeMemberBox').classList.remove('hidden');
    document.getElementById('memNameDisplay').innerText = data.client_name + " (" + data.plan_name + ")";
    document.getElementById('memCodeDisplay').innerText = data.membership_code;
    
    document.getElementById('clientSubIdInput').value = data.id;
    document.getElementById('custNameInput').value = data.client_name;
    document.getElementById('custNameInput').readOnly = true;
    document.getElementById('custMobileInput').value = data.client_contact;
    document.getElementById('custMobileInput').readOnly = true;

    if (parseFloat(data.discount_percent) > 0) {
        document.getElementById('memDiscountDisplay').innerText = data.discount_percent + '%';
        document.getElementById('discountPercent').value = data.discount_percent;
        document.getElementById('discountIcon').style.display = 'inline-block';
    } else {
        document.getElementById('memDiscountDisplay').innerText = 'None';
    }

    renderMemberBenefitsUI();
    
    if(cartItems.length > 0) {
        alert("Cart items cleared to apply membership rules automatically.");
        cartItems = [];
        renderCart();
    }
}

function clearMember() {
    activeMember = null;
    benefitMap = {};
    document.getElementById('activeMemberBox').classList.add('hidden');
    document.getElementById('clientSubIdInput').value = '';
    document.getElementById('custNameInput').value = '';
    document.getElementById('custNameInput').readOnly = false;
    document.getElementById('custMobileInput').value = '';
    document.getElementById('custMobileInput').readOnly = false;
    
    document.getElementById('discountPercent').value = '0';
    document.getElementById('discountIcon').style.display = 'none';
    
    cartItems = [];
    renderCart();
}

function renderMemberBenefitsUI() {
    const area = document.getElementById('memBenefitsArea');
    if (!activeMember.parsed_benefits || activeMember.parsed_benefits.length === 0) {
        area.innerHTML = `<div class="text-[10px] text-gray-500 italic">No specific service benefits included.</div>`;
        return;
    }
    
    let html = `<div class="text-[9px] uppercase tracking-widest text-gray-400 mb-1.5 font-bold">Included Services Available</div><div class="flex flex-wrap gap-1.5">`;
    activeMember.parsed_benefits.forEach(b => {
        const remaining = benefitMap[b.service_id];
        if(remaining > 0) {
            html += `<span class="bg-yellow-500/10 border border-yellow-500/30 text-yellow-500 px-2 py-1 rounded-md text-[10px] font-bold"><i class="fa-solid fa-check text-[8px] mr-1"></i>${b.service_name} (x${remaining})</span>`;
        }
    });
    html += `</div>`;
    area.innerHTML = html;
}

// --- Cart Logic ---
function toggleCatalog(type) {
    document.getElementById('servicesGrid').classList.add('hidden');
    document.getElementById('productsGrid').classList.add('hidden');
    document.getElementById(type+'Grid').classList.remove('hidden');
    
    document.getElementById('servicesBtn').classList.remove('active-tab');
    document.getElementById('productsBtn').classList.remove('active-tab');
    document.getElementById(type+'Btn').classList.add('active-tab');
    
    // Clear search when switching tabs to avoid confusion
    document.getElementById('catalogSearch').value = '';
    filterCatalog();
}

let cartItems = [];

function addToCart(type, id, name, original_price) {
    let price = parseFloat(original_price);
    let isBenefit = false;

    // Apply Benefit Check
    if (activeMember && type === 'Service' && benefitMap[id] > 0) {
        price = 0;
        isBenefit = true;
        benefitMap[id]--; 
        renderMemberBenefitsUI();
    }

    let existing = cartItems.find(item => item.type === type && item.id === id && item.isBenefit === isBenefit);
    if(existing && !isBenefit) { 
        existing.qty++;
    } else {
        cartItems.push({ 
            type: type, 
            id: id, 
            name: name, 
            original_price: parseFloat(original_price), 
            price: price, 
            qty: 1,
            isBenefit: isBenefit
        });
    }
    renderCart();
    
    // Scroll to bottom of cart
    const cartContainer = document.getElementById('cartContainer');
    cartContainer.scrollTop = cartContainer.scrollHeight;
}

function updateQty(index, delta) {
    let item = cartItems[index];
    
    if (item.isBenefit) {
        if (delta < 0) {
            benefitMap[item.id]++; 
            renderMemberBenefitsUI();
            cartItems.splice(index, 1);
        } else {
            alert("To use another benefit for this service, please select it from the catalog again.");
            return;
        }
    } else {
        if(item.qty + delta > 0) {
            item.qty += delta;
        } else {
            cartItems.splice(index, 1);
        }
    }
    renderCart();
}

function removeItem(index) {
    let item = cartItems[index];
    if (item.isBenefit) {
        benefitMap[item.id] += item.qty; 
        renderMemberBenefitsUI();
    }
    cartItems.splice(index, 1);
    renderCart();
}

// --- Refactored Flexbox Render Cart ---
function renderCart() {
    const tableBody = document.getElementById('cartTableBody');
    const hiddenInputs = document.getElementById('hiddenInputs');
    const emptyCart = document.getElementById('emptyCart');
    const submitBtn = document.getElementById('submitBtn');

    tableBody.innerHTML = '';
    hiddenInputs.innerHTML = '';

    if(cartItems.length === 0) {
        emptyCart.style.display = 'flex';
        submitBtn.disabled = true;
    } else {
        emptyCart.style.display = 'none';
        submitBtn.disabled = false;
    }

    cartItems.forEach((item, index) => {
        const subtotal = item.price * item.qty;
        
        // Build Stylist Options dynamically
        let stylistOptions = `<option value="">Select Stylist</option>`;
        <?php
        mysqli_data_seek($stylistsResult, 0);
        while($stylist = $stylistsResult->fetch_assoc()) {
            echo 'stylistOptions += `<option value="'.$stylist['id'].'">'.addslashes($stylist['stylist_name']).'</option>`;';
        }
        ?>

        const row = document.createElement('div');
        row.className = `p-3 rounded-xl border flex flex-col gap-2 transition ${item.isBenefit ? 'bg-yellow-50 border-yellow-200' : 'bg-white border-gray-200 hover:border-gray-300'}`;
        
        row.innerHTML = `
            <div class="flex justify-between items-start gap-3">
                <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-bold text-[#1f1f1f] leading-snug break-words">${item.name}</div>
                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                        <span class="text-[9px] uppercase tracking-widest text-gray-400 font-bold">${item.type}</span>
                        ${item.isBenefit ? '<span class="bg-yellow-500 text-[#111] text-[9px] px-1.5 py-0.5 rounded font-bold uppercase tracking-widest shadow-sm">Membership</span>' : ''}
                    </div>
                </div>
                <div class="text-right shrink-0">
                    <div class="text-[14px] font-mono font-bold ${item.isBenefit ? 'text-emerald-600' : 'text-[#1f1f1f]'}">₹${subtotal.toFixed(2)}</div>
                    ${item.isBenefit 
                        ? `<div class="text-[10px] font-mono text-gray-400 line-through">₹${item.original_price.toFixed(2)} x ${item.qty}</div>` 
                        : `<div class="text-[10px] font-mono text-gray-400">₹${item.price.toFixed(2)} x ${item.qty}</div>`}
                </div>
            </div>
            
            <div class="flex justify-between items-end gap-2 mt-1">
                <div class="w-[55%]">
                    ${item.type === 'Service' 
                        ? `<select name="stylist_id[]" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-[11px] bg-gray-50 outline-none focus:border-yellow-400 transition font-medium">${stylistOptions}</select>` 
                        : `<input type="hidden" name="stylist_id[]" value="">`}
                </div>
                
                <div class="flex items-center gap-2">
                    <div class="flex items-center border border-gray-200 rounded-lg overflow-hidden bg-gray-50 shadow-sm">
                        <button type="button" onclick="updateQty(${index}, -1)" class="w-7 h-7 flex items-center justify-center hover:bg-gray-200 text-gray-600 font-bold transition">-</button>
                        <span class="text-[12px] font-bold min-w-[24px] text-center font-mono">${item.qty}</span>
                        ${item.isBenefit 
                            ? `<div class="w-7 h-7"></div>` 
                            : `<button type="button" onclick="updateQty(${index}, 1)" class="w-7 h-7 flex items-center justify-center hover:bg-gray-200 text-gray-600 font-bold transition">+</button>`}
                    </div>
                    <button type="button" onclick="removeItem(${index})" class="text-rose-400 hover:text-rose-600 hover:bg-rose-50 w-7 h-7 rounded-lg flex items-center justify-center transition" title="Remove Item">
                        <i class="fa-solid fa-trash-can text-[12px]"></i>
                    </button>
                </div>
            </div>
        `;
        
        tableBody.appendChild(row);
        
        hiddenInputs.innerHTML += `
            <input type="hidden" name="item_type[]" value="${item.type}">
            <input type="hidden" name="item_id[]" value="${item.id}">
            <input type="hidden" name="price[]" value="${item.price}">
            <input type="hidden" name="quantity[]" value="${item.qty}">
            <input type="hidden" name="is_benefit[]" value="${item.isBenefit ? '1' : '0'}">
        `;
    });
    calculateTotals();
}

function calculateTotals() {
    let gross = 0;
    cartItems.forEach(item => {
        gross += item.price * item.qty;
    });

    const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
    const discountAmount = (gross * discountPercent) / 100;
    let subtotal = gross - discountAmount;
    if(subtotal < 0) subtotal = 0;

    const gstEnabled = document.getElementById('gstCheckbox').checked;
    let gstAmount = 0;
    if(gstEnabled) {
        gstAmount = (subtotal * 5) / 100;
    }

    let net = subtotal + gstAmount;

    document.getElementById('grossTotal').textContent = gross.toFixed(2);
    document.getElementById('discountAmount').textContent = discountAmount.toFixed(2);
    document.getElementById('gstAmount').textContent = gstAmount.toFixed(2);
    document.getElementById('gstAmountInput').value = gstAmount.toFixed(2);
    document.getElementById('netAmount').textContent = net.toFixed(2);
    
    calculateSplitRemaining();
}

function toggleSplitPayment() {
    const paymentMode = document.querySelector('input[name="payment_mode"]:checked').value;
    const splitBox = document.getElementById('splitPaymentBox');

    if(paymentMode === 'Split') {
        splitBox.classList.remove('hidden');
        calculateSplitRemaining();
    } else {
        splitBox.classList.add('hidden');
    }
}

function calculateSplitRemaining() {
    const net = parseFloat(document.getElementById('netAmount').textContent) || 0;
    const cash = parseFloat(document.getElementById('splitCash').value) || 0;
    const upi = parseFloat(document.getElementById('splitUpi').value) || 0;
    const card = parseFloat(document.getElementById('splitCard').value) || 0;
    
    const remaining = net - (cash + upi + card);
    document.getElementById('remainingAmount').textContent = remaining.toFixed(2);
}
</script>

</body>
</html>
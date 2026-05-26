<?php
session_start();
include '../config/db.php';

// --- Fetch Admin Loyalty Percentage ---
$loyalty_percent = 10; // Default fallback
try {
    $lp_query = $conn->query("SELECT percentage FROM loyalty_settings LIMIT 1");
    if ($lp_query && $lp_query->num_rows > 0) {
        $loyalty_percent = floatval($lp_query->fetch_assoc()['percentage']);
    }
} catch (Exception $e) {}

// --- AJAX Endpoint for Existing Customer Search ---
if (isset($_GET['ajax_search_customers'])) {
    header('Content-Type: application/json');
    $search = '%' . $_GET['ajax_search_customers'] . '%';
    
    // Group by mobile to avoid duplicates, get the most recent name
    $stmt = $conn->prepare("
        SELECT customer_mobile, MAX(customer_name) as customer_name
        FROM invoices 
        WHERE customer_mobile LIKE ? OR customer_name LIKE ?
        GROUP BY customer_mobile
        LIMIT 15
    ");
    $stmt->bind_param("ss", $search, $search);
    $stmt->execute();
    $res = $stmt->get_result();
    $customers = [];
    while ($row = $res->fetch_assoc()) {
        $customers[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $customers]);
    exit;
}

// --- AJAX Endpoint for Coupon Check ---
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

// --- AJAX Endpoint for Loyalty Check ---
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

// --- AJAX Endpoint for Membership Search ---
if (isset($_GET['ajax_search_member'])) {
    header('Content-Type: application/json');
    $search = '%' . $_GET['ajax_search_member'] . '%';
    
    $stmt = $conn->prepare("
        SELECT cs.*, s.plan_name, s.discount_percent, s.service_benefits as plan_benefits 
        FROM client_subscriptions cs 
        JOIN subscriptions s ON cs.subscription_plan_id = s.id 
        WHERE (cs.membership_code LIKE ? OR cs.client_name LIKE ? OR cs.client_contact LIKE ?) 
        AND cs.status = 'Active' AND cs.end_date >= CURDATE() 
        LIMIT 1
    ");
    $stmt->bind_param("sss", $search, $search);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows > 0) {
        $member = $res->fetch_assoc();
        
        if (empty($member['remaining_benefits'])) {
            $member['remaining_benefits'] = $member['plan_benefits'];
            $update = $conn->prepare("UPDATE client_subscriptions SET remaining_benefits = ? WHERE id = ?");
            $update->bind_param("si", $member['plan_benefits'], $member['id']);
            $update->execute();
        }
        
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
        $member['valid_until'] = date('d M Y', strtotime($member['end_date']));

        $histStmt = $conn->prepare("SELECT invoice_no, net_payable, created_at FROM invoices WHERE customer_mobile = ? ORDER BY id DESC LIMIT 5");
        $histStmt->bind_param("s", $member['client_contact']);
        $histStmt->execute();
        $histRes = $histStmt->get_result();
        $history = [];
        while($h = $histRes->fetch_assoc()) {
            $history[] = [
                'invoice_no' => $h['invoice_no'],
                'amount' => floatval($h['net_payable']),
                'date' => date('d M Y', strtotime($h['created_at']))
            ];
        }
        $member['history'] = $history;
        
        echo json_encode(['success' => true, 'data' => $member]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

$message = '';

$servicesResult = $conn->query("SELECT id, service_name, category, price FROM services WHERE status='Active' ORDER BY service_name ASC");

$productsResult = $conn->query("
    SELECT p.id, p.product_name, p.selling_price, p.stock_qty, c.category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.status='Active' 
    ORDER BY p.product_name ASC
");

$stylistsResult = $conn->query("SELECT id, stylist_name, commission_rate FROM stylists WHERE status='Active' ORDER BY stylist_name ASC");

// Generate Invoice Logic
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'generate_invoice') {

    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $customer_mobile = mysqli_real_escape_string($conn, $_POST['customer_mobile']);
    $discount_percent = floatval($_POST['discount_percent']);
    
    $client_subscription_id = !empty($_POST['client_subscription_id']) ? intval($_POST['client_subscription_id']) : null;
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
            $used_benefits_map = []; 

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

            // Calculate overall discount
            $global_discount_amt = ($gross_total * $discount_percent) / 100;
            $discount_amount = $global_discount_amt + $coupon_discount_val + $lp_discount_val;

            $subtotal_after_discount = $gross_total - $discount_amount;
            if ($subtotal_after_discount < 0) $subtotal_after_discount = 0;

            $net_amount = $subtotal_after_discount + $gst_amount;
            
            // Calculate Loyalty Points (Percentage of Net Amount)
            $loyalty_points_earned = ($net_amount * $loyalty_percent) / 100;

            $invoiceSql = "
                INSERT INTO invoices(
                    invoice_no, customer_name, customer_mobile, total_amount, 
                    discount_percent, discount, coupon_code, gst_enabled, gst_percent, 
                    gst_amount, net_payable, loyalty_points_earned, payment_mode, split_cash, 
                    split_upi, split_card
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $invoiceStmt = $conn->prepare($invoiceSql);
            $invoiceStmt->bind_param(
                "sssdddsdidddsddd",
                $invoice_no, $customer_name, $customer_mobile, $gross_total,
                $discount_percent, $discount_amount, $coupon_code, $gst_enabled, $gst_percent,
                $gst_amount, $net_amount, $loyalty_points_earned, $payment_mode, $split_cash,
                $split_upi, $split_card
            );
            $invoiceStmt->execute();
            $invoice_id = $invoiceStmt->insert_id;
            $invoiceStmt->close();

            // Insert Items
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

            // Deduct LP used
            if ($lp_discount_val > 0) {
                $lpDeduct = $conn->prepare("UPDATE customer_loyalty SET total_points = GREATEST(0, total_points - ?) WHERE customer_mobile = ?");
                $lpDeduct->bind_param("ds", $lp_discount_val, $customer_mobile);
                $lpDeduct->execute();
                $lpDeduct->close();
            }

            // Add new LP Earned
            $lpSql = "INSERT INTO customer_loyalty (customer_mobile, total_points) VALUES (?, ?) ON DUPLICATE KEY UPDATE total_points = total_points + ?";
            $lpStmt = $conn->prepare($lpSql);
            $lpStmt->bind_param("sdd", $customer_mobile, $loyalty_points_earned, $loyalty_points_earned);
            $lpStmt->execute();
            $lpStmt->close();

            // Deduct Used Benefits
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
    <title>Salon POS - Billing</title>
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
        
        #checkoutContent {
            transition: all 0.3s ease-in-out;
            transform-origin: top;
        }

        .modal { transition: opacity 0.3s ease, visibility 0.3s ease; }
        .modal-content { transition: transform 0.3s ease; }
        .modal.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
        .modal.hidden .modal-content { transform: scale(0.95) translateY(-20px); }
        
        @media(max-width:992px) {
            .main-wrapper { flex-direction: column; height: auto; }
            .left-panel, .right-panel { width: 100%; }
            body { overflow: auto; }
        }
    </style>
</head>
<body class="h-screen overflow-hidden p-3">

<div class="h-full bg-[#f8f8f8] rounded-[24px] border p-3 flex flex-col">
    <div class="main-wrapper flex gap-3 h-full overflow-hidden">

        <div class="left-panel w-[58%] bg-white rounded-[24px] border overflow-hidden flex flex-col shadow-sm relative">
            
            <div class="flex justify-between items-center px-5 py-3 border-b shrink-0">
                <div class="flex items-center gap-4">
                    <button type="button" onclick="toggleFullScreen()" title="Fullscreen" class="text-gray-400 hover:text-gray-800 transition bg-gray-100 hover:bg-gray-200 w-8 h-8 rounded-lg flex items-center justify-center shadow-sm">
                        <i class="fa-solid fa-expand"></i>
                    </button>
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
                    <div data-name="<?php echo htmlspecialchars(strtolower($row['product_name'])); ?>" data-category="<?php echo htmlspecialchars(strtolower($row['category_name'] ?? '')); ?>" onclick="openItemModal('Product', <?php echo $row['id']; ?>, '<?php echo addslashes($row['product_name']); ?>', <?php echo $row['selling_price']; ?>)" class="catalog-item card-hover bg-white border border-gray-200 rounded-2xl p-3.5 cursor-pointer transition min-h-[110px] flex flex-col justify-between">
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
            
            <div class="bg-[#111111] p-4 shrink-0 text-white relative z-20">
                <h3 class="text-[11px] uppercase tracking-widest text-yellow-500 font-bold mb-2 flex items-center gap-2">
                    <i class="fa-solid fa-magnifying-glass"></i> Verify Membership
                </h3>
                <div class="flex gap-2">
                    <input type="text" id="memberSearchInput" placeholder="Code, Name, or Mobile..." class="flex-1 border border-neutral-700 bg-neutral-800 text-white rounded-xl px-3 py-2 text-[12px] outline-none focus:border-yellow-500 transition placeholder:text-gray-500">
                    <button type="button" onclick="searchMember()" class="bg-yellow-500 hover:bg-yellow-400 text-[#111] px-5 py-2 rounded-xl text-[12px] font-bold transition shadow-sm">Check</button>
                </div>
                
                <div id="activeMemberBox" class="hidden mt-3 p-3 bg-neutral-800 border border-neutral-700 rounded-xl relative overflow-hidden">
                    <button type="button" onclick="clearMember()" class="absolute top-3 right-3 text-gray-400 hover:text-red-400 transition text-lg"><i class="fa-solid fa-xmark"></i></button>
                    <div class="flex gap-3 items-center mb-2">
                        <i class="fa-solid fa-crown text-yellow-500 text-3xl"></i>
                        <div>
                            <div class="text-[14px] font-bold text-white flex items-center gap-2" id="memNameDisplay"></div>
                            <div class="text-[10px] text-gray-400 font-mono tracking-wider mt-0.5" id="memCodeDisplay"></div>
                        </div>
                        <div class="ml-auto text-right pr-6">
                            <div class="text-[9px] uppercase tracking-widest text-gray-400 mb-0.5">Global Discount</div>
                            <div class="text-[16px] font-bold text-emerald-400" id="memDiscountDisplay"></div>
                        </div>
                    </div>
                    <div class="border-t border-neutral-700 pt-2 mt-2" id="memBenefitsArea"></div>
                </div>
            </div>

            <form method="POST" class="h-full flex flex-col flex-1 overflow-hidden" id="billingForm">
                <input type="hidden" name="action" value="generate_invoice">
                <input type="hidden" name="client_subscription_id" id="clientSubIdInput" value="">
                
                <input type="hidden" name="split_cash" id="formSplitCash" value="0">
                <input type="hidden" name="split_upi" id="formSplitUpi" value="0">
                <input type="hidden" name="split_card" id="formSplitCard" value="0">
                
                <input type="hidden" name="coupon_discount_val" id="hiddenCouponDiscount" value="0">
                <input type="hidden" name="lp_discount_val" id="hiddenLpDiscount" value="0">
                <input type="hidden" name="gst_percent" value="5">
                <input type="hidden" name="gst_amount" id="gstAmountInput" value="0">
                <div id="hiddenInputs"></div>

                <div class="px-4 pt-3 pb-2 flex justify-between items-center shrink-0 bg-[#fafafa]">
                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest flex items-center gap-1.5"><i class="fa-solid fa-user-pen"></i> Client Details</span>
                    <button type="button" onclick="openCustomerSearchModal()" class="text-[9px] bg-white border border-gray-200 text-gray-700 hover:text-[#111] hover:border-yellow-500 hover:bg-yellow-50 px-2.5 py-1.5 rounded-lg font-bold uppercase tracking-wider transition shadow-sm flex items-center gap-1">
                        <i class="fa-solid fa-search text-yellow-500"></i> Existing Customer
                    </button>
                </div>

                <div class="px-4 pb-3 border-b flex gap-3 shrink-0 bg-[#fafafa]">
                    <div class="relative w-1/2">
                        <i class="fa-solid fa-user absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-[12px]"></i>
                        <input type="text" name="customer_name" id="custNameInput" required placeholder="Client Name" class="w-full pl-8 pr-3 py-2.5 border-2 border-white focus:border-yellow-400 rounded-xl text-[13px] bg-white outline-none transition shadow-sm font-medium text-neutral-800">
                    </div>
                    <div class="relative w-1/2">
                        <i class="fa-solid fa-phone absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 text-[12px]"></i>
                        <input type="text" name="customer_mobile" id="custMobileInput" required placeholder="Mobile Number" class="w-full pl-8 pr-3 py-2.5 border-2 border-white focus:border-yellow-400 rounded-xl text-[13px] bg-white outline-none transition shadow-sm font-medium text-neutral-800">
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto custom-scroll relative bg-white flex flex-col p-2" id="cartContainer">
                    <div id="cartTableBody" class="flex flex-col gap-2"></div>

                    <div id="emptyCart" class="text-center py-16 text-gray-300 flex-1 flex flex-col justify-center items-center">
                        <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-3 border border-gray-100">
                            <i class="fa-solid fa-basket-shopping text-2xl text-gray-300"></i>
                        </div>
                        <p class="uppercase tracking-widest text-[10px] font-bold">Cart is Empty</p>
                    </div>
                </div>

                <div class="border-t bg-white shrink-0 shadow-[0_-4px_15px_rgba(0,0,0,0.05)] z-10 flex flex-col relative">
                    
                    <div class="px-5 py-3 bg-gray-50 flex justify-between items-center cursor-pointer hover:bg-gray-100 border-b transition" onclick="toggleCheckoutPanel()">
                        <div class="font-bold text-[11px] text-gray-500 uppercase tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-chevron-down transition-transform duration-300" id="checkoutToggleIcon"></i>
                            Bill Summary & Payment
                        </div>
                        <div class="font-bold text-[14px] font-mono text-[#111]">
                            Net Total: ₹<span id="miniNetAmount">0.00</span>
                        </div>
                    </div>

                    <div id="checkoutContent" class="overflow-y-auto custom-scroll max-h-[45vh] bg-white">
                        <div class="p-4 border-b border-gray-100">
                            
                            <div class="flex justify-between items-center mb-2 text-[13px]">
                                <span class="font-bold text-gray-500">Gross Total</span>
                                <span class="font-mono font-bold text-gray-800">₹<span id="grossTotal">0.00</span></span>
                            </div>

                            <div class="flex justify-between items-center mb-2 text-[13px]">
                                <span class="font-bold text-gray-500 flex items-center gap-1">Global Discount % <i class="fa-solid fa-tags text-yellow-500 text-[10px]" id="discountIcon" style="display:none;"></i></span>
                                <div class="flex items-center gap-2">
                                    <input type="number" name="discount_percent" id="discountPercent" value="0" min="0" max="100" oninput="calculateTotals()" class="w-16 border rounded-lg px-2 py-1 text-[12px] text-right font-mono outline-none focus:border-yellow-400 bg-white">
                                    <div class="text-rose-500 font-mono text-[12px] font-bold w-16 text-right">-₹<span id="discountAmount">0.00</span></div>
                                </div>
                            </div>

                            <div class="flex justify-between items-center mb-2 text-[13px]">
                                <div>
                                    <span class="font-bold text-gray-500 block">Coupon Code</span>
                                    <span id="couponMsg" class="text-[9px]"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input type="text" name="coupon_code" id="couponCodeInput" placeholder="ENTER CODE" class="w-24 border border-gray-200 rounded-lg px-2 py-1.5 text-[11px] font-bold tracking-widest uppercase outline-none focus:border-yellow-400 bg-white">
                                    <button type="button" onclick="applyCoupon()" class="bg-gray-100 hover:bg-gray-200 border px-2 py-1.5 rounded-lg text-[10px] font-bold uppercase transition">Apply</button>
                                    <div class="text-rose-500 font-mono text-[12px] font-bold w-16 text-right">-₹<span id="uiCouponDiscount">0.00</span></div>
                                </div>
                            </div>
                            
                            <div class="flex justify-between items-center mb-3 text-[13px]">
                                <div>
                                    <span class="font-bold text-gray-500 block">Use Loyalty Points</span>
                                    <button type="button" onclick="removeLoyalty()" id="removeLpBtn" class="text-[9px] text-rose-500 font-bold hidden hover:underline">Remove</button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="checkLoyalty()" class="bg-purple-50 border border-purple-100 hover:bg-purple-100 text-purple-600 px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase transition flex items-center gap-1"><i class="fa-solid fa-star text-[9px]"></i> Check</button>
                                    <div class="text-purple-600 font-mono text-[12px] font-bold w-16 text-right">-₹<span id="uiLpDiscount">0.00</span></div>
                                </div>
                            </div>

                            <div class="flex justify-between items-center mb-4 text-[13px]">
                                <label class="flex items-center gap-2 cursor-pointer font-bold text-gray-500">
                                    <input type="checkbox" id="gstCheckbox" name="gst_enabled" value="1" checked onchange="calculateTotals()" class="w-4 h-4 accent-yellow-500">
                                    Apply GST (5%)
                                </label>
                                <div class="text-emerald-600 font-mono text-[12px] font-bold">+₹<span id="gstAmount">0.00</span></div>
                            </div>

                            <div class="border-t border-dashed border-gray-200 pt-3 flex justify-between items-center mb-2">
                                <div class="font-bold text-[11px] text-gray-400 uppercase tracking-widest flex items-center gap-1">
                                    <i class="fa-solid fa-gift"></i> LP Earned (<span id="lpPercentLabel"><?php echo $loyalty_percent; ?></span>%)
                                </div>
                                <div class="text-gray-400 font-mono text-[12px] font-bold">+<span id="lpEarned">0.00</span> LP</div>
                            </div>

                            <div class="border-t border-gray-200 pt-3 flex justify-between items-center">
                                <div class="font-extrabold uppercase tracking-wider text-[14px] text-[#111]">Net Payable</div>
                                <div class="bg-[#111111] text-[#60a5fa] px-4 py-2 rounded-xl font-bold text-[20px] font-mono shadow-md">
                                    ₹<span id="netAmount">0.00</span>
                                </div>
                            </div>
                        </div>

                        <div class="p-4 pt-3">
                            <div class="grid grid-cols-4 gap-2 mb-1">
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
                                <label class="payment-option border rounded-xl py-2.5 text-center text-[12px] font-semibold cursor-pointer bg-gray-50" onclick="if(document.getElementById('splitRadio').checked) openSplitModal();">
                                    <input type="radio" id="splitRadio" name="payment_mode" value="Split" hidden onchange="toggleSplitPayment()">
                                    <i class="fa-solid fa-layer-group text-orange-500 mb-1 block text-lg"></i> Split
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="px-4 pb-5 pt-3 bg-white w-full border-t border-gray-50 z-20">
                        <button type="submit" id="submitBtn" disabled class="w-full bg-[#111111] hover:bg-black transition text-yellow-500 py-3.5 rounded-xl uppercase tracking-widest text-[13px] font-bold disabled:opacity-50 disabled:cursor-not-allowed shadow-lg flex items-center justify-center gap-2">
                            <i class="fa-solid fa-print text-[15px]"></i> Generate Invoice
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>
</div>

<div id="customerSearchModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="modal-content bg-white w-full max-w-md rounded-[24px] shadow-2xl overflow-hidden flex flex-col">
        <div class="px-6 py-4 bg-[#111111] flex justify-between items-center border-b border-neutral-800">
            <h3 class="font-bold text-white text-[15px] uppercase tracking-widest flex items-center gap-2">
                <i class="fa-solid fa-users text-yellow-500"></i> Search Customers
            </h3>
            <button type="button" onclick="closeCustomerSearchModal()" class="text-gray-400 hover:text-white transition text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <div class="p-6 bg-gray-50/50 flex flex-col gap-4">
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 transform -translate-y-1/2 text-gray-400 text-[13px]"></i>
                <input type="text" id="existingCustomerSearchInput" oninput="fetchExistingCustomers(this.value)" placeholder="Search by name or mobile number..." class="w-full pl-9 pr-4 py-3 border-2 border-gray-200 rounded-xl text-[13px] font-medium text-[#1f1f1f] focus:outline-none focus:border-yellow-400 bg-white transition shadow-sm" autocomplete="off">
            </div>
            
            <div id="customerSuggestionsList" class="max-h-[300px] overflow-y-auto custom-scroll flex flex-col gap-2">
                <div class="text-center py-6 text-gray-400 text-[11px] font-medium">Type to search history...</div>
            </div>
        </div>
    </div>
</div>

<div id="loyaltyModal" class="modal hidden fixed inset-0 z-[70] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="modal-content bg-white w-full max-w-sm rounded-[24px] shadow-2xl overflow-hidden flex flex-col">
        <div class="px-6 py-4 bg-purple-600 flex justify-between items-center border-b border-purple-800">
            <h3 class="font-bold text-white text-[15px] uppercase tracking-widest flex items-center gap-2">
                <i class="fa-solid fa-star text-purple-200"></i> Redeem LP
            </h3>
            <button type="button" onclick="document.getElementById('loyaltyModal').classList.add('hidden')" class="text-purple-200 hover:text-white transition text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="p-6 bg-purple-50/30 text-center">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-widest mb-1">Total Available Points</p>
            <p class="text-3xl font-mono font-bold text-purple-600 mb-4"><span id="modalLpTotal">0</span></p>
            
            <div class="bg-white border border-purple-100 rounded-xl p-3 shadow-sm inline-block w-full">
                <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Maximum Usable (50%)</p>
                <p class="text-lg font-mono font-bold text-neutral-800 mt-1">₹<span id="modalLpUsable">0.00</span></p>
            </div>
            <p class="text-[9px] text-gray-400 mt-3 italic">*Only 50% of total LP can be redeemed on a single bill.</p>
        </div>
        <div class="px-6 py-4 border-t bg-gray-50 flex justify-between gap-3 rounded-b-[24px]">
            <button type="button" onclick="document.getElementById('loyaltyModal').classList.add('hidden')" class="px-4 py-2.5 rounded-xl border border-gray-300 text-gray-600 text-[12px] font-bold hover:bg-white transition uppercase tracking-wider w-1/3">Cancel</button>
            <button type="button" onclick="applyLoyalty()" class="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-[12px] font-bold uppercase tracking-wider transition shadow-md w-2/3">Apply 50% LP</button>
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
            <div>
                <h4 id="modalItemNameDisplay" class="text-[16px] font-bold text-gray-800 leading-tight"></h4>
                <span id="modalBenefitBadge" class="hidden bg-yellow-500 text-[#111] text-[9px] px-2 py-0.5 rounded font-bold uppercase tracking-widest shadow-sm mt-2"><i class="fa-solid fa-crown mr-1"></i> Membership Included (Free)</span>
            </div>

            <div id="modalStylistWrapper">
                <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">Select Employee / Stylist</label>
                <select id="modalItemStylist" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] bg-white outline-none focus:border-yellow-400 transition font-medium shadow-sm">
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">Manual Price (₹)</label>
                    <input type="number" id="modalItemPrice" step="0.01" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] bg-white outline-none focus:border-yellow-400 transition font-medium shadow-sm font-mono">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">Discount (%)</label>
                    <input type="number" id="modalItemDiscount" step="0.01" min="0" max="100" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] bg-white outline-none focus:border-yellow-400 transition font-medium shadow-sm font-mono">
                </div>
            </div>
        </div>

        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3 rounded-b-[24px]">
            <button type="button" onclick="closeItemModal()" class="px-6 py-2.5 rounded-xl border border-gray-300 text-gray-600 text-[12px] font-bold hover:bg-white transition uppercase tracking-wider shadow-sm">Cancel</button>
            <button type="button" onclick="confirmAddItem()" class="px-6 py-2.5 rounded-xl bg-[#111] hover:bg-black text-yellow-400 text-[12px] font-bold uppercase tracking-wider transition shadow-md flex items-center gap-2">
                <i class="fa-solid fa-check"></i> Add to Cart
            </button>
        </div>
    </div>
</div>

<div id="splitPaymentModal" class="modal hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="modal-content bg-white w-full max-w-md rounded-[24px] shadow-2xl overflow-hidden flex flex-col">
        <div class="px-6 py-4 bg-[#111111] flex justify-between items-center border-b border-neutral-800">
            <h3 class="font-bold text-white text-[15px] uppercase tracking-widest flex items-center gap-2">
                <i class="fa-solid fa-layer-group text-orange-500"></i> Split Payment amounts
            </h3>
            <button type="button" onclick="closeSplitModal()" class="text-gray-400 hover:text-white transition text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="p-6 bg-orange-50/30">
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">Cash Amount</label>
                    <input type="number" step="0.01" min="0" id="modalSplitCash" value="0" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] font-mono outline-none focus:border-yellow-400 shadow-sm" oninput="calculateSplitRemaining()">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">UPI Amount</label>
                    <input type="number" step="0.01" min="0" id="modalSplitUpi" value="0" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] font-mono outline-none focus:border-yellow-400 shadow-sm" oninput="calculateSplitRemaining()">
                </div>
                <div>
                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1 block">Card Amount</label>
                    <input type="number" step="0.01" min="0" id="modalSplitCard" value="0" class="w-full border-2 border-gray-200 rounded-xl px-3 py-2 text-[13px] font-mono outline-none focus:border-yellow-400 shadow-sm" oninput="calculateSplitRemaining()">
                </div>
            </div>
            
            <div class="mt-5 pt-4 border-t border-orange-200/50 flex justify-between items-center">
                <span class="text-gray-600 font-bold uppercase tracking-wider text-[12px]">Remaining Due</span>
                <span class="font-mono text-[20px] font-bold text-rose-500">₹<span id="remainingAmount">0.00</span></span>
            </div>
        </div>
        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3 rounded-b-[24px]">
            <button type="button" onclick="closeSplitModal()" class="px-6 py-2.5 rounded-xl bg-[#111] hover:bg-black text-white text-[12px] font-bold uppercase tracking-wider transition shadow-md w-full">Done & Save Amounts</button>
        </div>
    </div>
</div>

<div id="memberConfirmModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="modal-content bg-white w-full max-w-2xl rounded-[24px] shadow-2xl overflow-hidden flex flex-col">
        <div class="px-6 py-4 bg-[#111111] flex justify-between items-center border-b border-neutral-800">
            <h3 class="font-bold text-white text-[15px] uppercase tracking-widest flex items-center gap-2">
                <i class="fa-solid fa-address-card text-yellow-500"></i> Verify Member Details
            </h3>
            <button onclick="closeMemberModal()" class="text-gray-400 hover:text-white transition text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <div class="p-6 overflow-y-auto custom-scroll max-h-[70vh]">
            <div class="bg-gray-50 border border-gray-200 rounded-2xl p-4 mb-5 flex gap-4 items-center shadow-sm">
                <div class="w-16 h-16 bg-yellow-100 rounded-full border-2 border-yellow-400 flex justify-center items-center shrink-0">
                    <i class="fa-solid fa-user-tie text-yellow-600 text-2xl"></i>
                </div>
                <div class="grid grid-cols-2 gap-x-6 gap-y-3 flex-1">
                    <div>
                        <p class="text-[9px] uppercase tracking-widest text-gray-400 font-bold mb-0.5">Client Name</p>
                        <p class="text-[14px] font-bold text-[#111]" id="modalClientName"></p>
                    </div>
                    <div>
                        <p class="text-[9px] uppercase tracking-widest text-gray-400 font-bold mb-0.5">Mobile Number</p>
                        <p class="text-[14px] font-bold font-mono text-[#111]" id="modalClientMobile"></p>
                    </div>
                    <div>
                        <p class="text-[9px] uppercase tracking-widest text-gray-400 font-bold mb-0.5">Active Plan</p>
                        <p class="text-[12px] font-bold text-emerald-600 bg-emerald-50 inline-block px-2 py-0.5 rounded border border-emerald-100 mt-0.5 shadow-sm" id="modalClientPlan"></p>
                    </div>
                    <div>
                        <p class="text-[9px] uppercase tracking-widest text-gray-400 font-bold mb-0.5">Valid Until</p>
                        <p class="text-[13px] font-bold text-rose-500 font-mono mt-0.5" id="modalClientValidity"></p>
                    </div>
                </div>
            </div>

            <div class="mb-6">
                <h4 class="text-[11px] font-bold uppercase tracking-widest text-gray-400 mb-3 border-b pb-2 flex justify-between items-center">
                    <span>Remaining Service Benefits</span>
                    <span class="text-yellow-600 font-bold bg-yellow-50 border border-yellow-200 px-2 py-0.5 rounded shadow-sm" id="modalGlobalDiscount"></span>
                </h4>
                <div id="modalBenefitsArea" class="flex flex-wrap gap-2"></div>
            </div>

            <div>
                <h4 class="text-[11px] font-bold uppercase tracking-widest text-gray-400 mb-3 border-b pb-2">Recent Visit History</h4>
                <ul id="modalHistoryArea" class="space-y-2"></ul>
            </div>
        </div>

        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3 rounded-b-[24px]">
            <button onclick="closeMemberModal()" class="px-6 py-2.5 rounded-xl border border-gray-300 text-gray-600 text-[12px] font-bold hover:bg-white transition uppercase tracking-wider shadow-sm">Cancel</button>
            <button onclick="confirmMember()" class="px-6 py-2.5 rounded-xl bg-[#111] hover:bg-black text-yellow-400 text-[12px] font-bold uppercase tracking-wider transition shadow-md flex items-center gap-2">
                <i class="fa-solid fa-check"></i> Yes, Apply Membership
            </button>
        </div>
    </div>
</div>

<script>
AOS.init({ duration: 600, once: true });

const LOYALTY_PERCENT = <?php echo json_encode($loyalty_percent); ?>;

let stylistOptionsHtml = '<option value="">Select Employee</option>';
let stylistMap = {};
<?php
mysqli_data_seek($stylistsResult, 0);
while($stylist = $stylistsResult->fetch_assoc()) {
    echo 'stylistOptionsHtml += `<option value="'.$stylist['id'].'">'.addslashes($stylist['stylist_name']).'</option>`;';
    echo 'stylistMap['.$stylist['id'].'] = "'.addslashes($stylist['stylist_name']).'";';
}
?>

function toggleFullScreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch((err) => {
            console.warn(`Error attempting to enable fullscreen: ${err.message}`);
        });
    } else {
        document.exitFullscreen();
    }
}

// === EXISTING CUSTOMER SEARCH LOGIC ===
function openCustomerSearchModal() {
    document.getElementById('customerSearchModal').classList.remove('hidden');
    document.getElementById('existingCustomerSearchInput').value = '';
    document.getElementById('customerSuggestionsList').innerHTML = '<div class="text-center py-6 text-gray-400 text-[11px] font-medium">Type to search history...</div>';
    setTimeout(() => document.getElementById('existingCustomerSearchInput').focus(), 100);
}

function closeCustomerSearchModal() {
    document.getElementById('customerSearchModal').classList.add('hidden');
}

let customerSearchTimeout = null;
function fetchExistingCustomers(query) {
    query = query.trim();
    const listContainer = document.getElementById('customerSuggestionsList');
    
    if (query.length < 2) {
        listContainer.innerHTML = '<div class="text-center py-6 text-gray-400 text-[11px] font-medium">Type at least 2 characters...</div>';
        return;
    }

    if (customerSearchTimeout) clearTimeout(customerSearchTimeout);
    
    customerSearchTimeout = setTimeout(async () => {
        listContainer.innerHTML = '<div class="text-center py-6 text-gray-400 text-[11px] font-medium"><i class="fa-solid fa-circle-notch fa-spin text-yellow-500 mb-2 text-lg"></i><br>Searching...</div>';
        try {
            const res = await fetch(`billing_new.php?ajax_search_customers=${encodeURIComponent(query)}`);
            const data = await res.json();
            
            if (data.success && data.data.length > 0) {
                let html = '';
                data.data.forEach(cust => {
                    html += `
                        <div onclick="selectExistingCustomer('${cust.customer_name}', '${cust.customer_mobile}')" class="p-3 bg-white border border-gray-200 rounded-xl hover:border-yellow-400 hover:bg-yellow-50/30 cursor-pointer transition flex justify-between items-center shadow-sm">
                            <div>
                                <div class="text-[13px] font-bold text-gray-800">${cust.customer_name || 'Unknown Client'}</div>
                                <div class="text-[11px] font-mono text-gray-500 mt-0.5"><i class="fa-solid fa-phone text-[9px] mr-1"></i>${cust.customer_mobile}</div>
                            </div>
                            <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center text-gray-400">
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                        </div>
                    `;
                });
                listContainer.innerHTML = html;
            } else {
                listContainer.innerHTML = '<div class="text-center py-6 text-gray-400 text-[11px] font-medium">No customers found.</div>';
            }
        } catch (err) {
            listContainer.innerHTML = '<div class="text-center py-6 text-rose-400 text-[11px] font-medium">Error fetching data.</div>';
        }
    }, 300);
}

function selectExistingCustomer(name, mobile) {
    document.getElementById('custNameInput').value = name;
    document.getElementById('custMobileInput').value = mobile;
    closeCustomerSearchModal();
}
// ======================================


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

function openSplitModal() {
    document.getElementById('splitPaymentModal').classList.remove('hidden');
    calculateSplitRemaining();
}
function closeSplitModal() {
    document.getElementById('splitPaymentModal').classList.add('hidden');
}
function toggleSplitPayment() {
    const paymentMode = document.querySelector('input[name="payment_mode"]:checked').value;
    if(paymentMode === 'Split') openSplitModal();
}

function calculateSplitRemaining() {
    const net = parseFloat(document.getElementById('netAmount').textContent) || 0;
    const cash = parseFloat(document.getElementById('modalSplitCash').value) || 0;
    const upi = parseFloat(document.getElementById('modalSplitUpi').value) || 0;
    const card = parseFloat(document.getElementById('modalSplitCard').value) || 0;
    
    document.getElementById('formSplitCash').value = cash;
    document.getElementById('formSplitUpi').value = upi;
    document.getElementById('formSplitCard').value = card;

    const remaining = net - (cash + upi + card);
    document.getElementById('remainingAmount').textContent = remaining.toFixed(2);
}

// Global state variables
let currentItemSelection = null;
let activeMember = null;
let pendingMemberData = null; 
let benefitMap = {}; 
let cartItems = [];

// New logic variables
let currentCoupon = { code: '', discount_percent: 0, discount_amount: 0 };
let currentLpDiscount = 0;
let customerTotalLp = 0;

async function applyCoupon() {
    const code = document.getElementById('couponCodeInput').value.trim();
    const msgEl = document.getElementById('couponMsg');
    
    if(!code) {
        currentCoupon = { code: '', discount_percent: 0, discount_amount: 0 };
        msgEl.innerText = '';
        calculateTotals();
        return;
    }
    
    try {
        const res = await fetch(`billing_new.php?ajax_check_coupon=${encodeURIComponent(code)}`);
        const data = await res.json();
        
        if(data.success) {
            currentCoupon = {
                code: code,
                discount_percent: parseFloat(data.data.discount_percent) || 0,
                discount_amount: parseFloat(data.data.discount_amount) || 0
            };
            msgEl.innerText = '✓ Applied';
            msgEl.className = 'text-[9px] text-emerald-500 font-bold ml-2';
        } else {
            currentCoupon = { code: '', discount_percent: 0, discount_amount: 0 };
            msgEl.innerText = 'Invalid / Expired';
            msgEl.className = 'text-[9px] text-rose-500 font-bold ml-2';
        }
        calculateTotals();
    } catch(err) {
        console.error("Coupon fetch error:", err);
    }
}

async function checkLoyalty() {
    const mobile = document.getElementById('custMobileInput').value.trim();
    if(!mobile) return alert('Please enter Client Mobile Number first to verify loyalty points.');
    
    try {
        const res = await fetch(`billing_new.php?ajax_get_loyalty=${encodeURIComponent(mobile)}`);
        const data = await res.json();
        
        customerTotalLp = data.points || 0;
        const usableLp = customerTotalLp * 0.5;

        document.getElementById('modalLpTotal').innerText = customerTotalLp.toFixed(2);
        document.getElementById('modalLpUsable').innerText = usableLp.toFixed(2);
        document.getElementById('loyaltyModal').classList.remove('hidden');
    } catch(err) {
        console.error("LP fetch error:", err);
    }
}

function applyLoyalty() {
    currentLpDiscount = customerTotalLp * 0.5;
    document.getElementById('loyaltyModal').classList.add('hidden');
    document.getElementById('removeLpBtn').classList.remove('hidden');
    calculateTotals();
}

function removeLoyalty() {
    currentLpDiscount = 0;
    document.getElementById('removeLpBtn').classList.add('hidden');
    calculateTotals();
}

function openItemModal(type, id, name, original_price) {
    currentItemSelection = { type, id, name, original_price };
    document.getElementById('modalItemNameDisplay').innerText = name;
    document.getElementById('modalItemPrice').value = original_price;
    document.getElementById('modalItemDiscount').value = 0;
    
    const stylistSelect = document.getElementById('modalItemStylist');
    stylistSelect.innerHTML = stylistOptionsHtml;
    stylistSelect.value = '';
    
    document.getElementById('modalStylistWrapper').style.display = (type === 'Service') ? 'block' : 'none';

    let isBenefit = (activeMember && type === 'Service' && benefitMap[id] > 0);
    
    if(isBenefit) {
        document.getElementById('modalItemPrice').value = 0;
        document.getElementById('modalItemPrice').readOnly = true;
        document.getElementById('modalItemDiscount').readOnly = true;
        document.getElementById('modalBenefitBadge').style.display = 'inline-block';
    } else {
        document.getElementById('modalItemPrice').readOnly = false;
        document.getElementById('modalItemDiscount').readOnly = false;
        document.getElementById('modalBenefitBadge').style.display = 'none';
    }
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
    let final_price = manual_price;
    
    let isBenefit = false;
    if (activeMember && type === 'Service' && benefitMap[id] > 0) {
        final_price = 0;
        isBenefit = true;
        benefitMap[id]--;
        renderMemberBenefitsUI();
    }

    let existing = cartItems.find(item => 
        item.type === type && item.id === id && 
        item.isBenefit === isBenefit && item.price === final_price && 
        item.stylist_id === stylist_id
    );
    
    if(existing) {
        existing.qty++;
    } else {
        cartItems.push({ 
            type: type, id: id, name: name, 
            original_price: parseFloat(original_price), 
            manual_price: manual_price, price: final_price, 
            qty: 1, isBenefit: isBenefit, stylist_id: stylist_id
        });
    }
    
    renderCart();
    closeItemModal();
    const cartContainer = document.getElementById('cartContainer');
    cartContainer.scrollTop = cartContainer.scrollHeight;
}

async function searchMember() {
    const query = document.getElementById('memberSearchInput').value.trim();
    if (!query) return;
    try {
        const response = await fetch(`billing_new.php?ajax_search_member=${encodeURIComponent(query)}`);
        const result = await response.json();
        if (result.success) {
            pendingMemberData = result.data;
            openMemberModal(result.data);
        } else {
            alert('No active membership found for this query.');
        }
    } catch (e) { console.error("Search failed", e); }
}

function openMemberModal(data) {
    document.getElementById('modalClientName').innerText = data.client_name;
    document.getElementById('modalClientMobile').innerText = data.client_contact;
    document.getElementById('modalClientPlan').innerText = data.plan_name + " (" + data.membership_code + ")";
    document.getElementById('modalClientValidity').innerText = data.valid_until;
    
    if (parseFloat(data.discount_percent) > 0) {
        document.getElementById('modalGlobalDiscount').innerText = `+ ${data.discount_percent}% Global Discount`;
    } else {
        document.getElementById('modalGlobalDiscount').innerText = '';
    }

    const bArea = document.getElementById('modalBenefitsArea');
    bArea.innerHTML = '';
    if (data.parsed_benefits && data.parsed_benefits.length > 0) {
        data.parsed_benefits.forEach(b => {
            if(b.qty > 0) {
                bArea.innerHTML += `
                    <div class="bg-white border border-yellow-300 px-3 py-1.5 rounded-lg flex items-center gap-2 shadow-sm">
                        <i class="fa-solid fa-check text-yellow-500 text-[10px]"></i>
                        <span class="text-[12px] font-bold text-gray-800">${b.service_name}</span>
                        <span class="bg-[#111] text-yellow-400 text-[10px] font-mono font-bold px-1.5 py-0.5 rounded ml-1">x${b.qty}</span>
                    </div>`;
            }
        });
    } else {
        bArea.innerHTML = `<p class="text-[11px] text-gray-400 italic">No specific service quotas remaining.</p>`;
    }

    const hArea = document.getElementById('modalHistoryArea');
    hArea.innerHTML = '';
    if (data.history && data.history.length > 0) {
        data.history.forEach(h => {
            hArea.innerHTML += `
                <li class="flex justify-between items-center bg-gray-50 border border-gray-200 p-3 rounded-xl shadow-sm">
                    <div><p class="text-[10px] uppercase text-gray-400 font-bold tracking-widest">${h.date}</p><p class="text-[13px] font-bold text-gray-700 mt-0.5"><i class="fa-solid fa-file-invoice text-gray-300 mr-1"></i> ${h.invoice_no}</p></div>
                    <div class="text-[14px] font-mono font-bold text-[#111]">₹${h.amount.toFixed(2)}</div>
                </li>`;
        });
    } else {
        hArea.innerHTML = `<p class="text-[11px] text-gray-400 italic">No previous visits recorded.</p>`;
    }
    document.getElementById('memberConfirmModal').classList.remove('hidden');
}

function closeMemberModal() {
    document.getElementById('memberConfirmModal').classList.add('hidden');
    pendingMemberData = null; 
}

function confirmMember() {
    const dataToApply = pendingMemberData; 
    closeMemberModal(); 
    if(dataToApply) setupActiveMember(dataToApply);
}

function setupActiveMember(data) {
    activeMember = data;
    benefitMap = {};
    if (data.parsed_benefits) {
        data.parsed_benefits.forEach(b => { benefitMap[b.service_id] = parseInt(b.qty); });
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

    if(cartItems.length > 0) {
        let oldCart = [...cartItems];
        cartItems = [];
        oldCart.forEach(item => {
            for(let i=0; i < item.qty; i++) {
               let price = item.manual_price !== undefined ? item.manual_price : parseFloat(item.original_price);
               let isBenefit = false;
               if (activeMember && item.type === 'Service' && benefitMap[item.id] > 0) {
                   price = 0; isBenefit = true; benefitMap[item.id]--;
               }
               let existing = cartItems.find(c => c.type === item.type && c.id === item.id && c.isBenefit === isBenefit && c.price === price && c.stylist_id === item.stylist_id);
               if (existing) existing.qty++;
               else cartItems.push({ ...item, price: price, qty: 1, isBenefit: isBenefit });
            }
        });
    }
    renderMemberBenefitsUI();
    renderCart();
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
    
    if (cartItems.length > 0) {
        let oldCart = [...cartItems];
        cartItems = [];
        oldCart.forEach(item => {
            for(let i=0; i < item.qty; i++) {
                let restoredPrice = item.manual_price !== undefined ? item.manual_price : parseFloat(item.original_price);
                let existing = cartItems.find(c => c.type === item.type && c.id === item.id && c.price === restoredPrice && c.stylist_id === item.stylist_id);
                if (existing) existing.qty++;
                else cartItems.push({ ...item, manual_price: restoredPrice, price: restoredPrice, qty: 1, isBenefit: false });
            }
        });
    }
    renderCart();
}

function renderMemberBenefitsUI() {
    const area = document.getElementById('memBenefitsArea');
    if (!activeMember.parsed_benefits || activeMember.parsed_benefits.length === 0) {
        area.innerHTML = `<div class="text-[10px] text-gray-500 italic mt-1">No specific service benefits included.</div>`;
        return;
    }
    let html = `<div class="text-[9px] uppercase tracking-widest text-gray-400 mb-1.5 font-bold">Included Services Available</div><div class="flex flex-wrap gap-1.5">`;
    activeMember.parsed_benefits.forEach(b => {
        const remaining = benefitMap[b.service_id];
        if(remaining > 0) html += `<span class="bg-transparent border border-yellow-500/50 text-yellow-400 px-2 py-1 rounded text-[10px] font-bold"><i class="fa-solid fa-check text-[8px] mr-1"></i>${b.service_name} (x${remaining})</span>`;
    });
    area.innerHTML = html + `</div>`;
}

function toggleCatalog(type) {
    document.getElementById('servicesGrid').classList.add('hidden');
    document.getElementById('productsGrid').classList.add('hidden');
    document.getElementById(type+'Grid').classList.remove('hidden');
    document.getElementById('servicesBtn').classList.remove('active-tab');
    document.getElementById('productsBtn').classList.remove('active-tab');
    document.getElementById(type+'Btn').classList.add('active-tab');
    document.getElementById('catalogSearch').value = '';
    filterCatalog();
}

function updateQty(index, delta) {
    let item = cartItems[index];
    if (item.isBenefit) {
        if (delta < 0) {
            benefitMap[item.id]++; item.qty--;
            if (item.qty === 0) cartItems.splice(index, 1);
            renderMemberBenefitsUI();
        } else if (delta > 0) {
            if (benefitMap[item.id] > 0) {
                benefitMap[item.id]--; item.qty++;
                renderMemberBenefitsUI();
            } else {
                alert("No more membership benefits available. It will be added as paid if selected from catalog.");
            }
        }
    } else {
        if(item.qty + delta > 0) item.qty += delta;
        else cartItems.splice(index, 1);
    }
    renderCart();
}

function removeItem(index) {
    let item = cartItems[index];
    if (item.isBenefit) { benefitMap[item.id] += item.qty; renderMemberBenefitsUI(); }
    cartItems.splice(index, 1);
    renderCart();
}

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
        const row = document.createElement('div');
        row.className = `p-3 rounded-xl border flex flex-col gap-2 transition ${item.isBenefit ? 'bg-yellow-50/50 border-yellow-300' : 'bg-white border-gray-200 hover:border-gray-300'}`;
        
        row.innerHTML = `
            <div class="flex justify-between items-start gap-3">
                <div class="flex-1 min-w-0">
                    <div class="text-[13px] font-bold text-[#1f1f1f] leading-snug break-words">${item.name}</div>
                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                        <span class="text-[9px] uppercase tracking-widest text-gray-400 font-bold">${item.type}</span>
                        ${item.isBenefit ? '<span class="bg-yellow-500 text-[#111] text-[9px] px-1.5 py-0.5 rounded font-bold uppercase tracking-widest shadow-sm">Membership Included</span>' : ''}
                    </div>
                </div>
                <div class="text-right shrink-0">
                    <div class="text-[14px] font-mono font-bold ${item.isBenefit ? 'text-emerald-600' : 'text-[#1f1f1f]'}">₹${subtotal.toFixed(2)}</div>
                    ${item.isBenefit ? `<div class="text-[10px] font-mono text-gray-400 line-through">₹${item.original_price.toFixed(2)} x ${item.qty}</div>` : `<div class="text-[10px] font-mono text-gray-400">₹${item.price.toFixed(2)} x ${item.qty}</div>`}
                </div>
            </div>
            <div class="flex justify-between items-end gap-2 mt-1">
                <div class="w-[60%]">
                    ${item.type === 'Service' ? `<div class="text-[11px] text-gray-500 font-medium bg-gray-50 px-2 py-1 rounded border border-gray-100 inline-block truncate max-w-full"><i class="fa-solid fa-user-pen mr-1 text-gray-400"></i> ${stylistMap[item.stylist_id] || 'No Stylist Selected'}</div>` : ``}
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex items-center border border-gray-200 rounded-lg overflow-hidden bg-white shadow-sm">
                        <button type="button" onclick="updateQty(${index}, -1)" class="w-7 h-7 flex items-center justify-center hover:bg-gray-100 text-gray-600 font-bold transition">-</button>
                        <span class="text-[12px] font-bold min-w-[24px] text-center font-mono">${item.qty}</span>
                        <button type="button" onclick="updateQty(${index}, 1)" class="w-7 h-7 flex items-center justify-center hover:bg-gray-100 text-gray-600 font-bold transition">+</button>
                    </div>
                    <button type="button" onclick="removeItem(${index})" class="text-rose-400 hover:text-rose-600 hover:bg-rose-50 w-7 h-7 rounded-lg flex items-center justify-center transition" title="Remove Item"><i class="fa-solid fa-trash-can text-[12px]"></i></button>
                </div>
            </div>`;
        tableBody.appendChild(row);
        
        hiddenInputs.innerHTML += `
            <input type="hidden" name="item_type[]" value="${item.type}">
            <input type="hidden" name="item_id[]" value="${item.id}">
            <input type="hidden" name="price[]" value="${item.price}">
            <input type="hidden" name="quantity[]" value="${item.qty}">
            <input type="hidden" name="is_benefit[]" value="${item.isBenefit ? '1' : '0'}">
            <input type="hidden" name="stylist_id[]" value="${item.stylist_id || ''}">
        `;
    });
    calculateTotals();
}

function calculateTotals() {
    let gross = 0;
    cartItems.forEach(item => { gross += item.price * item.qty; });

    // Calculate Discounts independently 
    const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
    const globalDiscountAmount = (gross * discountPercent) / 100;
    
    const couponDiscountAmount = (currentCoupon.discount_percent > 0) ? ((gross * currentCoupon.discount_percent) / 100) : currentCoupon.discount_amount;
    
    const totalDiscount = globalDiscountAmount + couponDiscountAmount + currentLpDiscount;
    
    let subtotal = gross - totalDiscount;
    if(subtotal < 0) subtotal = 0;

    const gstEnabled = document.getElementById('gstCheckbox').checked;
    const gstAmount = gstEnabled ? (subtotal * 5) / 100 : 0;

    let net = subtotal + gstAmount;
    
    const lpEarned = (net * LOYALTY_PERCENT) / 100;

    // Update UI 
    document.getElementById('grossTotal').textContent = gross.toFixed(2);
    document.getElementById('discountAmount').textContent = globalDiscountAmount.toFixed(2);
    document.getElementById('uiCouponDiscount').textContent = couponDiscountAmount.toFixed(2);
    document.getElementById('uiLpDiscount').textContent = currentLpDiscount.toFixed(2);
    
    document.getElementById('gstAmount').textContent = gstAmount.toFixed(2);
    document.getElementById('gstAmountInput').value = gstAmount.toFixed(2);
    document.getElementById('netAmount').textContent = net.toFixed(2);
    document.getElementById('miniNetAmount').textContent = net.toFixed(2);
    document.getElementById('lpEarned').textContent = lpEarned.toFixed(2);

    // Update Hidden Inputs for POST submission
    document.getElementById('hiddenCouponDiscount').value = couponDiscountAmount;
    document.getElementById('hiddenLpDiscount').value = currentLpDiscount;

    calculateSplitRemaining();
}
</script>

</body>
</html>
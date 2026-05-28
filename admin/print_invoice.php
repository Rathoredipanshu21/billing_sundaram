<?php
session_start();
include '../config/db.php';

if (!isset($_GET['id'])) {
    die("Invalid Invoice Routing. Please provide a valid bill reference.");
}

$invoice_id = intval($_GET['id']);

// Fetch Master Invoice Info
$inv_stmt = $conn->prepare("SELECT * FROM invoices WHERE id = ?");
$inv_stmt->bind_param("i", $invoice_id);
$inv_stmt->execute();
$inv_result = $inv_stmt->get_result();
$invoice = $inv_result->fetch_assoc();
$inv_stmt->close();

if (!$invoice) {
    die("Invoice record not found in the system registry.");
}

// Fetch Customer's Current Loyalty Points Status
$total_lp_now = 0;
$lp_stmt = $conn->prepare("SELECT total_points FROM customer_loyalty WHERE customer_mobile = ?");
$lp_stmt->bind_param("s", $invoice['customer_mobile']);
$lp_stmt->execute();
$lp_res = $lp_stmt->get_result();
if ($lp_res->num_rows > 0) {
    $total_lp_now = floatval($lp_res->fetch_assoc()['total_points']);
}
$lp_stmt->close();

// Fetch Billed Line Items
$items_stmt = $conn->prepare("
    SELECT ii.*, 
           COALESCE(s.service_name, p.product_name) as item_name 
    FROM invoice_items ii 
    LEFT JOIN services s ON ii.item_type = 'Service' AND ii.item_id = s.id 
    LEFT JOIN products p ON ii.item_type = 'Product' AND ii.item_id = p.id 
    WHERE ii.invoice_id = ?
");
$items_stmt->bind_param("i", $invoice_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = $row;
}
$items_stmt->close();

// Pre-fill WhatsApp message (Professional, Bold & Italic)
$host = $_SERVER['HTTP_HOST'];
$uri = rtrim(dirname($_SERVER['REQUEST_URI']), '/');
$invoice_url = "http://" . $host . $uri . "/print_invoice.php?id=" . $invoice['id'];

$wa_msg = "*_Dear " . htmlspecialchars($invoice['customer_name']) . ",_*\n\n*_Thank you for choosing Sundaram Salon, Jaipur! We hope you had a wonderful and refreshing experience with us._*\n\n*_For your convenience, please find the link to your digital invoice below:_*\n$invoice_url\n\n*_We look forward to serving you again soon. Don't forget to redeem your loyalty points on your next visit!_*";
$wa_link = "https://wa.me/91" . preg_replace('/[^0-9]/', '', $invoice['customer_mobile']) . "?text=" . rawurlencode($wa_msg);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo $invoice['invoice_no']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="../Assets/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f3f4f6; 
            color: #222222; 
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important; 
            color-adjust: exact !important;
        }
        .invoice-card { 
            max-width: 850px; 
            margin: 40px auto; 
            background: white; 
            padding: 50px 60px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.05); 
            position: relative;
            overflow: hidden;
        }
        .dashed-divider { border-bottom: 2px dashed #E5E7EB; margin: 24px 0; }
        
        .watermark {
            position: absolute;
            top: 55%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 400px;
            opacity: 0.15; /* Increased opacity */
            pointer-events: none;
            z-index: 0;
        }
        .content-layer {
            position: relative;
            z-index: 10;
        }

        @media print {
            body { background: white; margin: 0; padding: 0; }
            .invoice-card { box-shadow: none; margin: 0; padding: 20px; width: 100%; max-width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body> <div class="no-print bg-white border-b border-gray-200 px-6 py-4 flex justify-between items-center sticky top-0 z-50 shadow-sm">
        <a href="billing_new.php" class="text-sm font-medium text-gray-500 hover:text-neutral-900 transition flex items-center gap-2">
            <i class="fa-solid fa-arrow-left"></i> Back to POS Counter
        </a>
        <div class="flex items-center gap-3">
            <a href="<?php echo $wa_link; ?>" target="_blank" class="bg-emerald-500 hover:bg-emerald-600 text-white px-5 py-2.5 rounded-xl text-xs font-semibold uppercase tracking-wider transition shadow-sm flex items-center gap-2">
                <i class="fa-brands fa-whatsapp text-lg"></i> Send via WhatsApp
            </a>
            <button onclick="window.print()" class="bg-[#222222] hover:bg-neutral-800 text-[#EBBB15] px-5 py-2.5 rounded-xl text-xs font-semibold uppercase tracking-wider transition shadow-sm flex items-center gap-2">
                <i class="fa-solid fa-print"></i> Print / Download PDF
            </button>
        </div>
    </div>

    <div class="invoice-card border border-gray-200">
        
        <img src="../Assets/icon.png" alt="Watermark" class="watermark">
        
        <div class="content-layer">
            <div class="flex justify-between items-start">
                <div class="flex items-start gap-5">
                    <div class="w-24 h-24 bg-[#111111] flex items-center justify-center rounded-2xl shadow-md border border-neutral-800 shrink-0">
                        <img src="../Assets/icon.png" alt="Logo" class="w-14 h-14 object-contain">
                    </div>
                    <div class="pt-1">
                        <h1 class="text-3xl font-bold text-[#111111] uppercase tracking-tight mb-2">Sundaram Salon</h1>
                        <p class="text-[11px] text-gray-500 leading-relaxed">
                            1st Floor, Yaspal Skyrise, Amrapali Marg,<br>
                            beside Tamanna tower, above Nilkamal Furniture,<br>
                            Nemi Nagar Extension, B Block, Vaishali Nagar, Jaipur 302021
                        </p>
                        <p class="text-[11px] font-semibold text-neutral-700 mt-2">
                            <i class="fa-solid fa-phone text-[#EBBB15]"></i> +91 8585856832 &nbsp;|&nbsp; 
                            <i class="fa-solid fa-globe text-[#EBBB15]"></i> www.sundaramsalon.com
                        </p>
                    </div>
                </div>
                <div class="text-right pt-1">
                    <h2 class="text-4xl font-extrabold text-[#EBBB15] tracking-widest mb-2">INVOICE</h2>
                    <p class="text-sm font-semibold text-neutral-800">#<?php echo htmlspecialchars($invoice['invoice_no']); ?></p>
                    <p class="text-xs text-gray-500 mt-1">Date: <?php echo date('d M Y, h:i A', strtotime($invoice['created_at'])); ?></p>
                </div>
            </div>

            <div class="dashed-divider"></div>

            <div class="flex justify-between items-center mb-8 mt-6">
                <div>
                    <p class="text-[10px] text-gray-400 font-semibold uppercase tracking-widest mb-1.5">Billed To </p>
                    <p class="text-base font-bold text-neutral-800"><?php echo htmlspecialchars($invoice['customer_name']); ?></p>
                    <p class="text-sm text-gray-500 mt-0.5"><?php echo htmlspecialchars($invoice['customer_mobile']); ?></p>
                </div>
                <div class="text-right bg-gray-50/80 px-6 py-3 border border-gray-100 rounded-xl min-w-[200px]">
                    <p class="text-[9px] text-gray-400 font-semibold uppercase tracking-widest mb-1.5">Clearance Status</p>
                    
                    <p class="text-sm font-bold text-emerald-600 uppercase tracking-wide">
                        PAID VIA 
                        <?php 
                        if ($invoice['payment_mode'] === 'Split') {
                            $split_modes = [];
                            if ($invoice['split_cash'] > 0) $split_modes[] = 'CASH';
                            if ($invoice['split_upi'] > 0) $split_modes[] = 'UPI';
                            if ($invoice['split_card'] > 0) $split_modes[] = 'CARD';
                            echo 'SPLIT (' . implode(' + ', $split_modes) . ')';
                        } else {
                            echo htmlspecialchars(strtoupper($invoice['payment_mode']));
                        }
                        ?>
                    </p>
                    
                    <?php if ($invoice['payment_mode'] === 'Split'): ?>
                    <div class="mt-2.5 pt-2.5 border-t border-gray-200 text-[11px] text-gray-600 font-medium flex flex-col gap-1.5">
                        <?php if ($invoice['split_cash'] > 0): ?>
                            <div class="flex justify-between"><span>Cash:</span> <span>₹<?php echo number_format($invoice['split_cash'], 2); ?></span></div>
                        <?php endif; ?>
                        <?php if ($invoice['split_upi'] > 0): ?>
                            <div class="flex justify-between"><span>UPI:</span> <span>₹<?php echo number_format($invoice['split_upi'], 2); ?></span></div>
                        <?php endif; ?>
                        <?php if ($invoice['split_card'] > 0): ?>
                            <div class="flex justify-between"><span>Card:</span> <span>₹<?php echo number_format($invoice['split_card'], 2); ?></span></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <table class="w-full text-left border-collapse border border-gray-300 mb-8 bg-white">
                <thead>
                    <tr class="bg-[#111111] text-[#EBBB15] text-[10px] font-bold uppercase tracking-widest">
                        <th class="py-3.5 px-4 w-12 text-center border border-neutral-800">#</th>
                        <th class="py-3.5 px-4 border border-neutral-800">Item Description</th>
                        <th class="py-3.5 px-4 text-center w-24 border border-neutral-800">Type</th>
                        <th class="py-3.5 px-4 text-center w-16 border border-neutral-800">Qty</th>
                        <th class="py-3.5 px-4 text-right w-28 border border-neutral-800">Rate</th>
                        <th class="py-3.5 px-4 text-right w-32 border border-neutral-800">Total</th>
                    </tr>
                </thead>
                <tbody class="text-sm text-neutral-800">
                    <?php $idx = 1; foreach ($items as $item): ?>
                        <tr class="border-b border-gray-200 hover:bg-gray-50/50">
                            <td class="py-4 px-4 text-center text-gray-400 font-medium border-r border-gray-200"><?php echo str_pad($idx++, 2, '0', STR_PAD_LEFT); ?></td>
                            <td class="py-4 px-4 font-medium border-r border-gray-200"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td class="py-4 px-4 text-center text-[10px] text-gray-500 font-bold uppercase tracking-wider border-r border-gray-200"><?php echo htmlspecialchars($item['item_type']); ?></td>
                            <td class="py-4 px-4 text-center font-mono border-r border-gray-200"><?php echo htmlspecialchars($item['quantity']); ?></td>
                            <td class="py-4 px-4 text-right font-mono text-gray-600 border-r border-gray-200">₹<?php echo number_format($item['price'], 2); ?></td>
                            <td class="py-4 px-4 text-right font-mono font-medium text-neutral-900">₹<?php echo number_format($item['subtotal'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="flex justify-end mb-10">
                <div class="w-80">
                    <div class="flex justify-between items-center text-sm py-2">
                        <span class="text-gray-500 font-semibold">Gross Amount</span>
                        <span class="font-mono text-neutral-800 font-medium">₹<?php echo number_format($invoice['total_amount'], 2); ?></span>
                    </div>
                    
                    <?php if ($invoice['discount'] > 0): ?>
                    <div class="flex justify-between items-center text-sm py-2">
                        <span class="text-gray-500 font-semibold flex items-center gap-1.5"><i class="fa-solid fa-tags text-[#EBBB15]"></i> Applied Discount</span>
                        <span class="font-mono text-rose-500 font-medium">-₹<?php echo number_format($invoice['discount'], 2); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($invoice['gst_enabled'] == 1 && $invoice['gst_amount'] > 0): ?>
                    <div class="flex justify-between items-center text-sm py-2">
                        <span class="text-gray-500 font-semibold">GST (<?php echo floatval($invoice['gst_percent']); ?>%)</span>
                        <span class="font-mono text-emerald-600 font-medium">+₹<?php echo number_format($invoice['gst_amount'], 2); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="flex justify-between items-center border-t-2 border-gray-200 pt-4 mt-2 mb-4">
                        <span class="font-extrabold text-[#111111] uppercase tracking-widest text-base">Net Payable</span>
                        <span class="font-mono font-bold text-2xl text-[#111111]">₹<?php echo number_format($invoice['net_payable'], 2); ?></span>
                    </div>

                    <div class="bg-purple-50/70 border border-purple-200 p-4 rounded-xl text-center">
                        <h4 class="text-[10px] font-bold text-purple-600 uppercase tracking-widest mb-1.5"><i class="fa-solid fa-star"></i> Loyalty Rewards</h4>
                        <div class="flex justify-between text-[11px] font-medium text-neutral-800 border-t border-purple-100 pt-2 mt-1">
                            <div class="text-left">Earned this visit<br><span class="font-bold text-purple-600 font-mono text-[14px]">+<?php echo number_format($invoice['loyalty_points_earned'], 2); ?> LP</span></div>
                            <div class="text-right">Available Balance<br><span class="font-bold text-purple-600 font-mono text-[14px]"><?php echo number_format($total_lp_now, 2); ?> LP</span></div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="dashed-divider"></div>

            <div class="text-center mt-8">
                <p class="text-sm font-bold text-[#111111] uppercase tracking-widest mb-2">Thank you for choosing Sundaram Salon!</p>
                <p class="text-[11px] font-semibold text-purple-600 mb-3 tracking-wide">"Visit again to redeem your points and enjoy exclusive discounts on your next service!"</p>
                <p class="text-[10px] text-gray-500 leading-relaxed max-w-xl mx-auto italic">
                    All rendered services and used retail products are non-refundable. To book your next appointment, please visit our website.
                </p>
            </div>
        </div> 
    </div>

</body>
</html>
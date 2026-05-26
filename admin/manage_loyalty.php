<?php
session_start();
include '../config/db.php';

$message = '';
$messageType = '';

// --- Handle Loyalty Form Submission ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_loyalty'])) {
    $new_percentage = floatval($_POST['loyalty_percentage']);

    try {
        $check = $conn->query("SELECT id FROM loyalty_settings LIMIT 1");
        
        if ($check && $check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE loyalty_settings SET percentage = ?");
            $stmt->bind_param("d", $new_percentage);
        } else {
            $stmt = $conn->prepare("INSERT INTO loyalty_settings (percentage) VALUES (?)");
            $stmt->bind_param("d", $new_percentage);
        }

        if ($stmt->execute()) {
            $message = "Loyalty percentage updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating database: " . $conn->error;
            $messageType = "error";
        }
        $stmt->close();
    } catch (Exception $e) {
        $message = "System Error: " . $e->getMessage();
        $messageType = "error";
    }
}

// --- Handle Add Coupon Submission ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_coupon'])) {
    $code = strtoupper(trim(mysqli_real_escape_string($conn, $_POST['coupon_code'])));
    $discount_type = $_POST['discount_type'];
    $discount_value = floatval($_POST['discount_value']);

    if (!empty($code) && $discount_value > 0) {
        
        // Determine which column gets the value
        $discount_percent = ($discount_type === 'percentage') ? $discount_value : 0.00;
        $discount_amount = ($discount_type === 'amount') ? $discount_value : 0.00;

        try {
            $stmt = $conn->prepare("INSERT INTO coupons (code, discount_percent, discount_amount, status, created_at) VALUES (?, ?, ?, 'Active', NOW())");
            $stmt->bind_param("sdd", $code, $discount_percent, $discount_amount);
            
            if ($stmt->execute()) {
                $message = "Coupon code '$code' added successfully!";
                $messageType = "success";
            } else {
                $message = "Error adding coupon: " . $conn->error;
                $messageType = "error";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "System Error: " . $e->getMessage();
            $messageType = "error";
        }
    } else {
        $message = "Please enter a valid code and discount value.";
        $messageType = "error";
    }
}

// --- Handle Coupon Actions (Toggle / Delete) ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    if ($_GET['action'] === 'toggle') {
        $conn->query("UPDATE coupons SET status = IF(status='Active', 'Inactive', 'Active') WHERE id = $id");
        header("Location: manage_loyalty.php");
        exit;
    } elseif ($_GET['action'] === 'delete') {
        $conn->query("DELETE FROM coupons WHERE id = $id");
        header("Location: manage_loyalty.php");
        exit;
    }
}

// --- Fetch Current Loyalty Percentage ---
$current_percentage = 10; // Default fallback
try {
    $query = $conn->query("SELECT percentage FROM loyalty_settings LIMIT 1");
    if ($query && $query->num_rows > 0) {
        $current_percentage = floatval($query->fetch_assoc()['percentage']);
    }
} catch (Exception $e) {}

// --- Fetch All Coupons ---
$couponsResult = [];
try {
    $cQuery = $conn->query("SELECT * FROM coupons ORDER BY created_at DESC");
    if ($cQuery) {
        while ($row = $cQuery->fetch_assoc()) {
            $couponsResult[] = $row;
        }
    }
} catch (Exception $e) {}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Loyalty & Coupons</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="p-4 md:p-6 text-[#1f2937]">

    <div class="max-w-6xl mx-auto">
        
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-[18px] font-bold text-gray-900 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-tags text-gray-500"></i> Promotions & Loyalty
                </h1>
                <p class="text-[12px] text-gray-500 mt-1 font-medium">
                    Configure customer reward points and manage active discount codes.
                </p>
            </div>
            <a href="billing_new.php" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded-lg text-[12px] font-bold uppercase tracking-wider transition shadow-sm inline-flex items-center gap-2">
                <i class="fa-solid fa-arrow-left"></i> Back to Billing
            </a>
        </div>

        <?php if ($message): ?>
            <div class="mb-5 p-3 rounded-xl text-[13px] font-bold flex items-center gap-2 shadow-sm <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : 'bg-rose-50 text-rose-600 border border-rose-200'; ?>">
                <i class="fa-solid <?php echo $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> text-[15px]"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="lg:col-span-1 space-y-6">
                
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col">
                    <div class="px-5 py-3 bg-[#111111] flex justify-between items-center border-b border-neutral-800">
                        <h3 class="font-bold text-white text-[13px] uppercase tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-star text-purple-400"></i> Loyalty Settings
                        </h3>
                    </div>
                    <div class="p-5 bg-gray-50/50">
                        <form method="POST" action="">
                            <div class="mb-4">
                                <label class="text-[10px] uppercase font-bold text-gray-500 mb-1.5 block tracking-widest">Reward Percentage (%)</label>
                                <input type="number" name="loyalty_percentage" step="0.01" min="0" max="100" required
                                       value="<?php echo htmlspecialchars($current_percentage); ?>"
                                       class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-[14px] font-mono font-bold text-[#1f1f1f] focus:outline-none focus:border-yellow-400 bg-white transition shadow-sm">
                                <p class="text-[10px] text-gray-400 mt-1.5 font-medium leading-snug">
                                    Determines the loyalty points earned based on net invoice amount.
                                </p>
                            </div>
                            <button type="submit" name="update_loyalty" class="w-full bg-[#111111] hover:bg-black transition text-yellow-500 py-3 rounded-lg uppercase tracking-widest text-[12px] font-bold shadow-md flex items-center justify-center gap-2">
                                <i class="fa-solid fa-floppy-disk"></i> Save Percentage
                            </button>
                        </form>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col">
                    <div class="px-5 py-3 bg-[#111111] flex justify-between items-center border-b border-neutral-800">
                        <h3 class="font-bold text-white text-[13px] uppercase tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-plus text-emerald-400"></i> Create Coupon
                        </h3>
                    </div>
                    <div class="p-5 bg-gray-50/50">
                        <form method="POST" action="">
                            <div class="mb-4">
                                <label class="text-[10px] uppercase font-bold text-gray-500 mb-1.5 block tracking-widest">Coupon Code</label>
                                <input type="text" name="coupon_code" required placeholder="e.g., SUMMER20"
                                       class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-[14px] font-bold text-[#1f1f1f] uppercase focus:outline-none focus:border-yellow-400 bg-white transition shadow-sm">
                            </div>
                            
                            <div class="grid grid-cols-2 gap-3 mb-5">
                                <div>
                                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1.5 block tracking-widest">Type</label>
                                    <select name="discount_type" class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-[13px] font-semibold text-[#1f1f1f] focus:outline-none focus:border-yellow-400 bg-white transition shadow-sm">
                                        <option value="percentage">Percentage (%)</option>
                                        <option value="amount">Flat Amount (₹)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] uppercase font-bold text-gray-500 mb-1.5 block tracking-widest">Value</label>
                                    <input type="number" name="discount_value" step="0.01" min="0" required placeholder="0.00"
                                           class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg text-[14px] font-mono font-bold text-[#1f1f1f] focus:outline-none focus:border-yellow-400 bg-white transition shadow-sm">
                                </div>
                            </div>

                            <button type="submit" name="add_coupon" class="w-full bg-[#111111] hover:bg-black transition text-white py-3 rounded-lg uppercase tracking-widest text-[12px] font-bold shadow-md flex items-center justify-center gap-2">
                                <i class="fa-solid fa-check"></i> Add Coupon
                            </button>
                        </form>
                    </div>
                </div>

            </div>

            <div class="lg:col-span-2">
                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden flex flex-col h-full">
                    <div class="px-5 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                        <h2 class="text-[13px] font-bold text-gray-700 uppercase tracking-wider">
                            Active & Historic Coupons
                        </h2>
                    </div>

                    <div class="overflow-x-auto custom-scroll flex-1">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Coupon Code</th>
                                    <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Discount</th>
                                    <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Status</th>
                                    <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest">Created Date</th>
                                    <th class="px-5 py-3 text-[10px] font-bold text-gray-500 uppercase tracking-widest text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if(empty($couponsResult)): ?>
                                    <tr>
                                        <td colspan="5" class="px-5 py-10 text-center text-[12px] text-gray-500 font-medium">
                                            No coupons found. Create your first coupon using the form.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($couponsResult as $coupon): ?>
                                    <tr class="hover:bg-gray-50/50 transition">
                                        
                                        <td class="px-5 py-3">
                                            <div class="inline-flex items-center gap-2 bg-gray-100 border border-gray-200 px-3 py-1 rounded-md text-[13px] font-bold font-mono text-gray-900 tracking-wider">
                                                <i class="fa-solid fa-tag text-gray-400 text-[10px]"></i> <?php echo htmlspecialchars($coupon['code']); ?>
                                            </div>
                                        </td>

                                        <td class="px-5 py-3">
                                            <div class="text-[14px] font-bold text-emerald-600 font-mono">
                                                <?php 
                                                    if (isset($coupon['discount_percent']) && floatval($coupon['discount_percent']) > 0) {
                                                        echo floatval($coupon['discount_percent']) . '%';
                                                    } elseif (isset($coupon['discount_amount']) && floatval($coupon['discount_amount']) > 0) {
                                                        echo '₹' . floatval($coupon['discount_amount']);
                                                    } else {
                                                        echo '0';
                                                    }
                                                ?>
                                            </div>
                                        </td>

                                        <td class="px-5 py-3">
                                            <?php if($coupon['status'] === 'Active'): ?>
                                                <span class="bg-emerald-50 text-emerald-600 border border-emerald-200 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider">
                                                    Active
                                                </span>
                                            <?php else: ?>
                                                <span class="bg-gray-100 text-gray-500 border border-gray-200 px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider">
                                                    Inactive
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="px-5 py-3 text-[12px] text-gray-600 font-medium">
                                            <?php echo date('d M Y', strtotime($coupon['created_at'])); ?>
                                        </td>

                                        <td class="px-5 py-3 text-right">
                                            <div class="flex items-center justify-end gap-2">
                                                
                                                <a href="?action=toggle&id=<?php echo $coupon['id']; ?>" 
                                                   class="w-8 h-8 rounded border flex items-center justify-center transition shadow-sm <?php echo $coupon['status'] === 'Active' ? 'bg-orange-50 text-orange-600 border-orange-200 hover:bg-orange-100' : 'bg-emerald-50 text-emerald-600 border-emerald-200 hover:bg-emerald-100'; ?>"
                                                   title="<?php echo $coupon['status'] === 'Active' ? 'Deactivate Coupon' : 'Activate Coupon'; ?>">
                                                    <i class="fa-solid <?php echo $coupon['status'] === 'Active' ? 'fa-pause' : 'fa-play'; ?> text-[11px]"></i>
                                                </a>

                                                <a href="?action=delete&id=<?php echo $coupon['id']; ?>" 
                                                   onclick="return confirm('Are you sure you want to permanently delete this coupon code?');"
                                                   class="w-8 h-8 rounded border bg-rose-50 text-rose-500 border-rose-200 hover:bg-rose-100 hover:text-rose-600 flex items-center justify-center transition shadow-sm"
                                                   title="Delete Coupon">
                                                    <i class="fa-solid fa-trash-can text-[11px]"></i>
                                                </a>

                                            </div>
                                        </td>

                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

</body>
</html>
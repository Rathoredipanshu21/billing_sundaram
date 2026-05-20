<?php
session_start();
include '../config/db.php';

$message = '';
$messageType = '';

/*
|--------------------------------------------------------------------------
| FETCH DATA
|--------------------------------------------------------------------------
*/

$servicesResult = $conn->query("
    SELECT id, service_name, price
    FROM services
    WHERE status = 'Active'
    ORDER BY service_name ASC
");

$productsResult = $conn->query("
    SELECT id, product_name, selling_price, stock_qty
    FROM products
    WHERE status = 'Active'
    ORDER BY product_name ASC
");

$stylistsResult = $conn->query("
    SELECT id, stylist_name, commission_rate
    FROM stylists
    WHERE status = 'Active'
    ORDER BY stylist_name ASC
");

/*
|--------------------------------------------------------------------------
| HANDLE BILLING
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST['action'])
    && $_POST['action'] === 'generate_invoice'
) {

    $customer_name = mysqli_real_escape_string(
        $conn,
        $_POST['customer_name']
    );

    $customer_mobile = mysqli_real_escape_string(
        $conn,
        $_POST['customer_mobile']
    );

    $discount_percent =
        floatval($_POST['discount_percent']);

    $payment_mode = mysqli_real_escape_string(
        $conn,
        $_POST['payment_mode']
    );

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

            $invoice_no =
                "INV-" . date("Ymd") . "-" . rand(1000,9999);

            $gross_total = 0;

            $items_to_save = [];

            for ($i=0; $i<count($item_ids); $i++) {

                $subtotal =
                    floatval($prices[$i]) *
                    intval($quantities[$i]);

                $gross_total += $subtotal;

                $items_to_save[] = [
                    'type' => $types[$i],
                    'id' => intval($item_ids[$i]),
                    'price' => floatval($prices[$i]),
                    'qty' => intval($quantities[$i]),
                    'stylist_id' =>
                        !empty($stylist_ids[$i])
                        ? intval($stylist_ids[$i])
                        : null,
                    'subtotal' => $subtotal
                ];
            }

            $discount_amount =
                ($gross_total * $discount_percent) / 100;

            $net_amount =
                $gross_total - $discount_amount;

            if ($net_amount < 0) {
                $net_amount = 0;
            }

            /*
            |--------------------------------------------------------------------------
            | INSERT INVOICE
            |--------------------------------------------------------------------------
            */

            $invoiceSql = "
                INSERT INTO invoices (
                    invoice_no,
                    customer_name,
                    customer_mobile,
                    total_amount,
                    discount_percent,
                    discount,
                    net_payable,
                    payment_mode
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $invoiceStmt = $conn->prepare($invoiceSql);

            $invoiceStmt->bind_param(
                "sssdddds",
                $invoice_no,
                $customer_name,
                $customer_mobile,
                $gross_total,
                $discount_percent,
                $discount_amount,
                $net_amount,
                $payment_mode
            );

            $invoiceStmt->execute();

            $invoice_id =
                $invoiceStmt->insert_id;

            $invoiceStmt->close();

            /*
            |--------------------------------------------------------------------------
            | INSERT ITEMS
            |--------------------------------------------------------------------------
            */

            $itemSql = "
                INSERT INTO invoice_items (
                    invoice_id,
                    item_type,
                    item_id,
                    stylist_id,
                    price,
                    quantity,
                    subtotal
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";

            $itemStmt = $conn->prepare($itemSql);

            foreach ($items_to_save as $item) {

                $itemStmt->bind_param(
                    "isiidid",
                    $invoice_id,
                    $item['type'],
                    $item['id'],
                    $item['stylist_id'],
                    $item['price'],
                    $item['qty'],
                    $item['subtotal']
                );

                $itemStmt->execute();

                $invoice_item_id =
                    $itemStmt->insert_id;

                /*
                |--------------------------------------------------------------------------
                | AUTO COMMISSION
                |--------------------------------------------------------------------------
                */

                if (
                    $item['type'] === 'Service'
                    && !empty($item['stylist_id'])
                ) {

                    $stylistQuery = $conn->prepare("
                        SELECT commission_rate
                        FROM stylists
                        WHERE id = ?
                    ");

                    $stylistQuery->bind_param(
                        "i",
                        $item['stylist_id']
                    );

                    $stylistQuery->execute();

                    $stylistResult =
                        $stylistQuery->get_result();

                    if ($stylistResult->num_rows > 0) {

                        $stylistData =
                            $stylistResult->fetch_assoc();

                        $commissionPercent =
                            floatval(
                                $stylistData['commission_rate']
                            );

                        $commissionAmount =
                            ($item['subtotal']
                            * $commissionPercent) / 100;

                        $commissionSql = "
                            INSERT INTO stylist_commissions (
                                stylist_id,
                                invoice_id,
                                invoice_item_id,
                                service_id,
                                commission_percent,
                                service_amount,
                                commission_amount
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ";

                        $commissionStmt =
                            $conn->prepare($commissionSql);

                        $commissionStmt->bind_param(
                            "iiiiddd",
                            $item['stylist_id'],
                            $invoice_id,
                            $invoice_item_id,
                            $item['id'],
                            $commissionPercent,
                            $item['subtotal'],
                            $commissionAmount
                        );

                        $commissionStmt->execute();

                        $commissionStmt->close();
                    }

                    $stylistQuery->close();
                }

                /*
                |--------------------------------------------------------------------------
                | PRODUCT STOCK DEDUCT
                |--------------------------------------------------------------------------
                */

                if ($item['type'] === 'Product') {

                    $stockSql = "
                        UPDATE products
                        SET stock_qty = stock_qty - ?
                        WHERE id = ?
                    ";

                    $stockStmt =
                        $conn->prepare($stockSql);

                    $stockStmt->bind_param(
                        "ii",
                        $item['qty'],
                        $item['id']
                    );

                    $stockStmt->execute();

                    $stockStmt->close();
                }
            }

            $itemStmt->close();

            $conn->commit();

            header(
                "Location: print_invoice.php?id=" . $invoice_id
            );

            exit();

        } catch (Exception $e) {

            $conn->rollback();

            $message =
                "Billing Failed : " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width, initial-scale=1.0">

<title>Sundaram Salon POS</title>

<script src="https://cdn.tailwindcss.com"></script>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap"
rel="stylesheet">

<style>

body{
    font-family:'Inter',sans-serif;
    background:#f4f4f4;
}

.custom-scroll::-webkit-scrollbar{
    width:4px;
}

.custom-scroll::-webkit-scrollbar-thumb{
    background:#d1d5db;
    border-radius:10px;
}

.card-hover:hover{
    border-color:#eab308;
    transform:translateY(-2px);
}

.active-tab{
    background:white;
    color:black;
    box-shadow:0 2px 5px rgba(0,0,0,0.08);
}

</style>

</head>

<body class="h-screen overflow-hidden p-3">

<div class="h-full bg-[#f8f8f8] rounded-[24px] border p-3">

<div class="flex gap-3 h-full">

<!-- LEFT SIDE -->

<div class="w-[58%] bg-white rounded-[24px] border overflow-hidden">

<!-- TOP -->

<div class="flex justify-between items-center px-4 py-3 border-b">

<h2 class="text-[13px] font-semibold uppercase tracking-wider flex items-center gap-2">

<i class="fa-solid fa-layer-group text-yellow-500"></i>

SERVICE & RETAIL CATALOG

</h2>

<div class="bg-[#f3f3f3] p-1 rounded-xl flex gap-1">

<button
type="button"
onclick="toggleCatalog('services')"
id="servicesBtn"
class="active-tab text-[12px] px-5 py-2 rounded-lg font-medium">

Services

</button>

<button
type="button"
onclick="toggleCatalog('products')"
id="productsBtn"
class="text-[12px] px-5 py-2 rounded-lg font-medium text-gray-500">

Products

</button>

</div>

</div>

<!-- GRID -->

<div class="p-4 overflow-y-auto custom-scroll h-[calc(100%-70px)]">

<!-- SERVICES -->

<div
id="servicesGrid"
class="grid grid-cols-3 gap-3">

<?php while($row = $servicesResult->fetch_assoc()): ?>

<div
onclick="addToCart(
'Service',
<?php echo $row['id']; ?>,
'<?php echo addslashes($row['service_name']); ?>',
<?php echo $row['price']; ?>
)"
class="card-hover bg-white border rounded-2xl p-3 cursor-pointer transition duration-200 min-h-[120px]">

<div class="text-[9px] text-gray-400 uppercase flex items-center gap-1 mb-2">

<i class="fa-solid fa-scissors text-yellow-500"></i>

Service

</div>

<h3 class="text-[13px] font-medium leading-5 text-[#1f1f1f] min-h-[48px]">

<?php echo htmlspecialchars($row['service_name']); ?>

</h3>

<div class="mt-3 text-[15px] font-bold">

₹<?php echo number_format($row['price'],2); ?>

</div>

</div>

<?php endwhile; ?>

</div>

<!-- PRODUCTS -->

<div
id="productsGrid"
class="hidden grid grid-cols-3 gap-3">

<?php while($row = $productsResult->fetch_assoc()): ?>

<div
onclick="addToCart(
'Product',
<?php echo $row['id']; ?>,
'<?php echo addslashes($row['product_name']); ?>',
<?php echo $row['selling_price']; ?>
)"
class="card-hover bg-white border rounded-2xl p-3 cursor-pointer transition duration-200 min-h-[120px]">

<div class="flex justify-between items-center mb-2">

<div class="text-[9px] text-gray-400 uppercase flex items-center gap-1">

<i class="fa-solid fa-box text-green-500"></i>

Product

</div>

<div class="text-[9px] bg-gray-100 px-2 py-1 rounded-full">

<?php echo $row['stock_qty']; ?>

</div>

</div>

<h3 class="text-[13px] font-medium leading-5 text-[#1f1f1f] min-h-[48px]">

<?php echo htmlspecialchars($row['product_name']); ?>

</h3>

<div class="mt-3 text-[15px] font-bold">

₹<?php echo number_format($row['selling_price'],2); ?>

</div>

</div>

<?php endwhile; ?>

</div>

</div>

</div>

<!-- RIGHT SIDE -->

<div class="w-[42%] bg-white rounded-[24px] border overflow-hidden flex flex-col">

<form method="POST" class="h-full flex flex-col">

<input type="hidden"
name="action"
value="generate_invoice">

<!-- HEADER -->

<div class="p-4 border-b border-dashed">

<div class="flex gap-4">

<div class="w-[58px] h-[58px] rounded-2xl bg-black overflow-hidden flex items-center justify-center">

<img
src="../Assets/icon.png"
class="w-full h-full object-cover">

</div>

<div>

<h1 class="text-[17px] font-bold uppercase">

Sundaram Salon

</h1>

<p class="text-[11px] text-gray-500 leading-5 mt-1">

1st Floor, Yaspal Skyrise, Amrapali Marg,<br>
beside Tamanna tower, above Nilkamal Furniture,<br>
Nemi Nagar Ext, B Block, Vaishali Nagar, Jaipur 302021

</p>

<p class="text-[12px] font-semibold mt-2">

<i class="fa-solid fa-phone text-yellow-500 mr-1"></i>

+91 8585856832

</p>

</div>

</div>

</div>

<!-- CUSTOMER -->

<div class="p-4 border-b flex gap-3">

<input
type="text"
name="customer_name"
required
placeholder="Client Name"
class="w-1/2 border rounded-xl px-3 py-2 text-[13px] bg-[#fafafa] outline-none">

<input
type="text"
name="customer_mobile"
required
placeholder="Mobile Number"
class="w-1/2 border rounded-xl px-3 py-2 text-[13px] bg-[#fafafa] outline-none">

</div>

<!-- CART -->

<div class="flex-1 overflow-y-auto custom-scroll">

<table class="w-full">

<thead>

<tr class="border-b text-[10px] uppercase text-gray-400">

<th class="text-left p-4">Item</th>
<th>Qty</th>
<th class="text-right">Rate</th>
<th class="text-right pr-4">Total</th>

</tr>

</thead>

<tbody id="cartTableBody"></tbody>

</table>

<div
id="emptyCart"
class="text-center py-16 text-gray-300">

<i class="fa-solid fa-basket-shopping text-3xl mb-3"></i>

<p class="uppercase tracking-widest text-[10px]">

Invoice Empty

</p>

</div>

</div>

<div id="hiddenInputs"></div>

<!-- FOOTER -->

<div class="p-4 border-t border-dashed">

<div class="flex justify-between items-center mb-3 text-[13px]">

<span class="uppercase tracking-wider text-gray-500">

Gross Total

</span>

<span class="font-mono">

₹<span id="grossTotal">0.00</span>

</span>

</div>

<div class="flex justify-between items-center mb-4 text-[13px]">

<span class="uppercase tracking-wider text-gray-500">

Discount %

</span>

<div class="flex items-center gap-3">

<input
type="number"
name="discount_percent"
id="discountPercent"
value="0"
min="0"
max="100"
oninput="calculateTotals()"
class="w-[70px] border rounded-lg px-2 py-1 text-[12px] text-right">

<div class="text-red-400 font-mono text-[12px]">

-₹<span id="discountAmount">0.00</span>

</div>

</div>

</div>

<div class="border-t pt-4 flex justify-between items-center mb-4">

<div class="font-bold uppercase text-[15px]">

Net Amount

</div>

<div class="bg-[#1f1f1f] text-yellow-400 px-4 py-2 rounded-xl font-bold text-[18px] font-mono">

₹<span id="netAmount">0.00</span>

</div>

</div>

<div class="grid grid-cols-3 gap-2 mb-4">

<label class="border rounded-xl py-3 text-center text-[12px] cursor-pointer">

<input type="radio"
name="payment_mode"
value="Cash"
checked
hidden>

<i class="fa-solid fa-money-bill text-green-600 mr-1"></i>

Cash

</label>

<label class="border rounded-xl py-3 text-center text-[12px] cursor-pointer">

<input type="radio"
name="payment_mode"
value="UPI"
hidden>

<i class="fa-solid fa-qrcode text-indigo-600 mr-1"></i>

UPI

</label>

<label class="border rounded-xl py-3 text-center text-[12px] cursor-pointer">

<input type="radio"
name="payment_mode"
value="Card"
hidden>

<i class="fa-solid fa-credit-card text-blue-600 mr-1"></i>

Card

</label>

</div>

<button
type="submit"
id="submitBtn"
disabled
class="w-full bg-[#1f1f1f] hover:bg-black transition text-yellow-400 py-4 rounded-2xl uppercase tracking-widest text-[12px] font-semibold disabled:opacity-50">

<i class="fa-solid fa-print mr-2"></i>

Generate & Print Invoice

</button>

</div>

</form>

</div>

</div>

</div>

<script>

function toggleCatalog(type){

    document.getElementById('servicesGrid')
    .classList.add('hidden');

    document.getElementById('productsGrid')
    .classList.add('hidden');

    document.getElementById(type + 'Grid')
    .classList.remove('hidden');

    document.getElementById('servicesBtn')
    .classList.remove('active-tab');

    document.getElementById('productsBtn')
    .classList.remove('active-tab');

    document.getElementById(type + 'Btn')
    .classList.add('active-tab');
}

let cartItems = [];

function addToCart(type,id,name,price){

    let existing =
        cartItems.find(
            item =>
            item.type === type &&
            item.id === id
        );

    if(existing){

        existing.qty++;

    }else{

        cartItems.push({
            type:type,
            id:id,
            name:name,
            price:parseFloat(price),
            qty:1
        });
    }

    renderCart();
}

function updateQty(index,delta){

    if(cartItems[index].qty + delta > 0){

        cartItems[index].qty += delta;

    }else{

        cartItems.splice(index,1);
    }

    renderCart();
}

function removeItem(index){

    cartItems.splice(index,1);

    renderCart();
}

function renderCart(){

    const tableBody =
        document.getElementById('cartTableBody');

    const hiddenInputs =
        document.getElementById('hiddenInputs');

    const emptyCart =
        document.getElementById('emptyCart');

    const submitBtn =
        document.getElementById('submitBtn');

    tableBody.innerHTML = '';

    hiddenInputs.innerHTML = '';

    if(cartItems.length === 0){

        emptyCart.style.display = 'block';

        submitBtn.disabled = true;

    }else{

        emptyCart.style.display = 'none';

        submitBtn.disabled = false;
    }

    cartItems.forEach((item,index)=>{

        const subtotal =
            item.price * item.qty;

        tableBody.innerHTML += `
        <tr class="border-b">

            <td class="p-4">

                <div class="text-[13px] font-medium">

                    ${item.name}

                </div>

                <div class="text-[9px] uppercase text-gray-400 mt-1">

                    ${item.type}

                </div>

                ${
                    item.type === 'Service'
                    ? `
                    <select
                    name="stylist_id[]"
                    class="mt-2 w-full border rounded-lg px-2 py-2 text-[11px] bg-[#fafafa]">

                        <option value="">
                            Select Stylist
                        </option>

                        <?php
                        mysqli_data_seek($stylistsResult,0);

                        while($stylist = $stylistsResult->fetch_assoc()){
                        ?>

                        <option value="<?php echo $stylist['id']; ?>">

                            <?php echo $stylist['stylist_name']; ?>

                            (<?php echo $stylist['commission_rate']; ?>%)

                        </option>

                        <?php } ?>

                    </select>
                    `
                    : `
                    <input type="hidden"
                    name="stylist_id[]"
                    value="">
                    `
                }

            </td>

            <td class="text-center">

                <div class="flex items-center justify-center gap-1">

                    <button
                    type="button"
                    onclick="updateQty(${index},-1)"
                    class="w-6 h-6 rounded-full border text-[11px]">

                    -

                    </button>

                    <span class="text-[12px]">

                    ${item.qty}

                    </span>

                    <button
                    type="button"
                    onclick="updateQty(${index},1)"
                    class="w-6 h-6 rounded-full border text-[11px]">

                    +

                    </button>

                </div>

            </td>

            <td class="text-right text-[12px] font-mono">

                ₹${item.price.toFixed(2)}

            </td>

            <td class="text-right pr-4 text-[12px] font-mono font-semibold">

                ₹${subtotal.toFixed(2)}

            </td>

            <td class="pr-3">

                <button
                type="button"
                onclick="removeItem(${index})"
                class="text-red-400 text-[12px]">

                <i class="fa-solid fa-xmark"></i>

                </button>

            </td>

        </tr>
        `;

        hiddenInputs.innerHTML += `
            <input type="hidden"
            name="item_type[]"
            value="${item.type}">

            <input type="hidden"
            name="item_id[]"
            value="${item.id}">

            <input type="hidden"
            name="price[]"
            value="${item.price}">

            <input type="hidden"
            name="quantity[]"
            value="${item.qty}">
        `;
    });

    calculateTotals();
}

function calculateTotals(){

    let gross = 0;

    cartItems.forEach(item=>{

        gross += item.price * item.qty;
    });

    const discountPercent =
        parseFloat(
            document.getElementById('discountPercent').value
        ) || 0;

    const discountAmount =
        (gross * discountPercent) / 100;

    let net =
        gross - discountAmount;

    if(net < 0){
        net = 0;
    }

    document.getElementById('grossTotal')
    .textContent = gross.toFixed(2);

    document.getElementById('discountAmount')
    .textContent = discountAmount.toFixed(2);

    document.getElementById('netAmount')
    .textContent = net.toFixed(2);
}

</script>

</body>
</html>
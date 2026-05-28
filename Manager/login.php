<?php
session_start();
include '../config/db.php'; // Update this path to your actual database connection file

// Redirect to dashboard if already logged in
if (isset($_SESSION['manager_id'])) {
    header("Location: index.php");
    exit();
}

$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $login_id = trim($_POST['login_id']); // This captures either email or contact
    $password = $_POST['password'];

    if (!empty($login_id) && !empty($password)) {
        // Query checks for a match in either the email OR contact column
        $stmt = $conn->prepare("SELECT id, password, name FROM managers WHERE email = ? OR contact = ?");
        $stmt->bind_param("ss", $login_id, $login_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            // Verify the hashed password
            if (password_verify($password, $row['password'])) {
                $_SESSION['manager_id'] = $row['id'];
                $_SESSION['manager_name'] = $row['name'];
                header("Location: index.php");
                exit();
            } else {
                $error_msg = "Invalid password.";
            }
        } else {
            $error_msg = "No manager account found with that email or contact number.";
        }
        $stmt->close();
    } else {
        $error_msg = "Please fill in all fields.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Login | Workspace</title>
    <link rel="icon" type="image/x-icon" href="../Assets/icon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background-color: #F9FAFB; }
    </style>
</head>
<body class="flex items-center justify-center h-screen">

    <div class="bg-white p-8 rounded-xl shadow-sm border border-gray-200 w-full max-w-md">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-neutral-900 text-[#EBBB15] rounded-lg mb-4">
                <i class="fa-solid fa-user-shield text-lg"></i>
            </div>
            <h1 class="text-lg font-bold text-gray-800">Manager Access</h1>
            <p class="text-sm text-gray-500 mt-1">Please sign in to your dashboard</p>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="bg-red-50 text-red-600 p-3 rounded-lg text-sm mb-4 border border-red-100 flex items-center gap-2">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Email or Contact Number</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fa-solid fa-user text-gray-400 text-sm"></i>
                    </div>
                    <input type="text" name="login_id" required class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-[#EBBB15] focus:ring-1 focus:ring-[#EBBB15] transition text-sm" placeholder="Enter email or phone">
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Password</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fa-solid fa-lock text-gray-400 text-sm"></i>
                    </div>
                    <input type="password" name="password" required class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-[#EBBB15] focus:ring-1 focus:ring-[#EBBB15] transition text-sm" placeholder="Enter your password">
                </div>
            </div>

            <button type="submit" class="w-full bg-neutral-900 text-[#EBBB15] font-semibold py-2.5 rounded-lg hover:bg-black transition flex justify-center items-center gap-2 mt-2 shadow-sm text-sm">
                Sign In <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>
    </div>

</body>
</html>
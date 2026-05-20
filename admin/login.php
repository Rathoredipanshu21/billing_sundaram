<?php
session_start();
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    include '../config/db.php';

    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    $sql = "SELECT password FROM admin WHERE username = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            if ($password === $row['password']) {
                $_SESSION['admin'] = $username;
                header("Location: index.php");
                exit();
            } else {
                $error = "Invalid credentials managed.";
            }
        } else {
            $error = "Invalid credentials managed.";
        }
        $stmt->close();
    } else {
        $error = "System connectivity issue.";
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salon POS | Management Access</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #222222;
        }
        .accent-border:focus {
            border-color: #EBBB15;
            box-shadow: 0 0 0 3px rgba(235, 187, 21, 0.15);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md bg-[#1a1a1a] border border-neutral-800 rounded-2xl p-8" data-aos="fade-up" data-aos-duration="800">
        <div class="text-center mb-8">
            <div class="w-16 h-16 mx-auto mb-4 bg-[#222222] border border-neutral-800 rounded-xl flex items-center justify-center">
                <img src="../Assets/icon.png" alt="Salon Logo" class="w-10 h-10 object-contain">
            </div>
            <h1 class="text-lg font-medium text-white tracking-tight">SALON MANAGEMENT SYSTEM</h1>
            <p class="text-xs text-neutral-500 uppercase tracking-widest mt-1">Secured Terminal Gate</p>
        </div>

        <form method="POST" action="" class="space-y-5">
            <div>
                <label class="block text-xs font-medium text-neutral-400 uppercase tracking-wider mb-2">Operator Username</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-neutral-500 text-sm">
                        <i class="fa-solid fa-user-tie"></i>
                    </span>
                    <input type="text" name="username" placeholder="Username" required autocomplete="off"
                        class="w-full bg-[#222222] border border-neutral-800 rounded-xl py-3 pl-11 pr-4 text-sm text-neutral-200 placeholder-neutral-600 outline-none transition-all accent-border">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-neutral-400 uppercase tracking-wider mb-2">Secure Passcode</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-neutral-500 text-sm">
                        <i class="fa-solid fa-shield-halved"></i>
                    </span>
                    <input type="password" name="password" placeholder="Password" required
                        class="w-full bg-[#222222] border border-neutral-800 rounded-xl py-3 pl-11 pr-4 text-sm text-neutral-200 placeholder-neutral-600 outline-none transition-all accent-border">
                </div>
            </div>

            <button type="submit" class="w-full bg-[#EBBB15] text-[#222222] font-medium text-sm py-3 rounded-xl transition-transform hover:opacity-90 flex items-center justify-center gap-2 mt-2">
                <span>Access Workspace</span>
                <i class="fa-solid fa-arrow-right-long text-xs"></i>
            </button>

            <?php if (!empty($error)): ?>
                <div class="bg-red-950/30 border border-red-900/50 text-red-400 rounded-xl p-3 text-xs flex items-center gap-2 justify-center" data-aos="shake">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>AOS.init({ once: true });</script>
</body>
</html>
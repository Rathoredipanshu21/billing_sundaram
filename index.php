<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sundaram Salon | Entry Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; margin: 0; padding: 0; overflow: hidden; background: #000; }
        
        /* Animated Background */
        .bg-animated {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: radial-gradient(circle at 50% 50%, #1a1a1a 0%, #000 100%);
            z-index: -1;
        }
        .particle {
            position: absolute;
            width: 2px; height: 2px; background: rgba(235, 187, 21, 0.5);
            border-radius: 50%;
            animation: move 10s infinite linear;
        }
        @keyframes move {
            0% { transform: translateY(0) translateX(0); }
            100% { transform: translateY(-100vh) translateX(50px); }
        }
    </style>
</head>
<body class="h-screen flex items-center justify-center">

    <div class="bg-animated" id="particle-container"></div>

    <div class="max-w-md w-full p-8 text-center" style="z-index: 1;">
        <div class="w-24 h-24 bg-neutral-900 border border-neutral-800 rounded-3xl flex items-center justify-center mx-auto mb-6 shadow-2xl">
            <img src="Assets/icon.png" alt="Logo" class="w-16 h-16 object-contain">
        </div>

        <h1 class="text-3xl font-extrabold text-white uppercase tracking-widest mb-2">Sundaram Salon</h1>
        <p class="text-gray-400 text-xs uppercase tracking-[0.2em] mb-10">Select your access portal</p>

        <div class="space-y-4">
            <a href="manager/index.php" class="block w-full bg-[#EBBB15] hover:bg-yellow-500 text-black font-bold py-4 rounded-2xl transition duration-300 shadow-[0_0_20px_rgba(235,187,21,0.3)] uppercase text-xs tracking-widest">
                <i class="fa-solid fa-user-tie mr-2"></i> Manager Portal
            </a>
            <a href="admin/index.php" class="block w-full bg-transparent border border-neutral-700 hover:border-[#EBBB15] text-white hover:text-[#EBBB15] font-bold py-4 rounded-2xl transition duration-300 uppercase text-xs tracking-widest">
                <i class="fa-solid fa-shield-halved mr-2"></i> Master Admin
            </a>
        </div>
    </div>

    <script>
        // Create particles
        const container = document.getElementById('particle-container');
        for (let i = 0; i < 50; i++) {
            const p = document.createElement('div');
            p.className = 'particle';
            p.style.left = Math.random() * 100 + 'vw';
            p.style.animationDuration = (Math.random() * 10 + 5) + 's';
            p.style.opacity = Math.random();
            container.appendChild(p);
        }
    </script>
</body>
</html>
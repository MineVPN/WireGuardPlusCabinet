<?php
// ----- ВСЯ ТВОЯ PHP-ЛОГИКА ОСТАЕТСЯ ЗДЕСЬ БЕЗ ИЗМЕНЕНИЙ -----
session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

if(isset($_POST['menu'])){
    $_GET['menu'] = $_POST['menu'];
}

$menu_item = isset($_GET['menu']) ? $_GET['menu'] : 'openvpn';

$menu_pages = [
    'wireguard' => 'wireguard.php',
    'ping' => 'pinger.php',
    'route' => 'route.php'
];

if (!array_key_exists($menu_item, $menu_pages)) {
    $menu_item = 'wireguard';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>WireGuard+</title>
    <script src="tailwindcss.js"></script>

    <style>
    body { font-family: 'Inter', sans-serif; background-color: #0F172A; }
    .glassmorphism { 
        background: rgba(30, 41, 59, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    /* ДОБАВЬ НОВЫЕ СТИЛИ СЮДА */
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #1e293b; }
    ::-webkit-scrollbar-thumb { background-color: #475569; border-radius: 10px; border: 2px solid #1e293b; }
    ::-webkit-scrollbar-thumb:hover { background-color: #64748b; }
    * { scrollbar-width: thin; scrollbar-color: #475569 #1e293b; }

    </style>

    <script>
    function Notice(text) {
        var elements = document.querySelectorAll('.notice');
        elements.forEach(function(element) {
            element.textContent = text;
            if (element.classList.contains('hidden')) {
                element.classList.remove('hidden');
            }
        });
    }
    </script>
</head>
<body class="text-slate-300">

    <div class="flex min-h-screen">
        <aside class="w-64 flex-shrink-0 bg-slate-900 p-4 flex flex-col border-r border-slate-800">
            <a href="cabinet.php" class="p-4 mb-6 text-center block">
                <img src="logo.png" alt="Logo" class="w-48 h-48 mx-auto transition-all">
            </a>

            <nav class="flex flex-col gap-2">
                <a href="cabinet.php?menu=wireguard" class="flex items-center gap-4 px-4 py-3 rounded-lg transition-colors
                    <?php echo ($menu_item == 'wireguard') ? 'bg-violet-500/20 text-white shadow-inner' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'; ?>">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                    <span class="font-medium">WireGuard</span>
                </a>
                <a href="cabinet.php?menu=route" class="flex items-center gap-4 px-4 py-3 rounded-lg transition-colors
                    <?php echo ($menu_item == 'route') ? 'bg-violet-500/20 text-white shadow-inner' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'; ?>">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span class="font-medium">Route</span>
                </a>
                <a href="cabinet.php?menu=ping" class="flex items-center gap-4 px-4 py-3 rounded-lg transition-colors
                    <?php echo ($menu_item == 'ping') ? 'bg-violet-500/20 text-white shadow-inner' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200'; ?>">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    <span class="font-medium">Ping</span>
                </a>
                
                <a href="logout.php" class="flex items-center gap-4 px-4 py-3 rounded-lg text-slate-400 hover:bg-red-500/20 hover:text-red-400 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    <span class="font-medium">Выход</span>
                </a>
            </nav>

        </aside>

        <main class="flex-grow p-4 sm:p-8 w-full">
            

            <div class="glassmorphism rounded-2xl p-6 sm:p-8 h-full">
                <div class="notice hidden bg-green-500/20 text-green-300 p-4 rounded-xl border border-green-500/30 mb-6"></div>
                <?php
                include_once $menu_pages[$menu_item];
                ?>
            </div>
        </main>
    </div>
</body>
</html>

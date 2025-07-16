<?php
// ----- ВСЯ ТВОЯ PHP-ЛОГИКА ОСТАЕТСЯ ЗДЕСЬ БЕЗ ИЗМЕНЕНИЙ -----
// Сессия уже запущена в cabinet.php, поэтому здесь ее можно закомментировать или удалить
// session_start(); 
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

$wg0ConfigFile = '/etc/wireguard/wg0.conf';
$routesFile = 'routes.txt';
$preference = 30000;

function addBypassToWg0($ip, $pref) {
    global $wg0ConfigFile;
    $postUp = "PostUp = ip rule add to $ip table main preference $pref";
    $postDown = "PostDown = ip rule del to $ip table main preference $pref";
    $config = file_get_contents($wg0ConfigFile);
    $config = preg_replace('/(\[Interface\]\s*\n)/', "$1$postUp\n$postDown\n", $config, 1);
    file_put_contents($wg0ConfigFile, $config);
}

function removeBypassFromWg0($ip, $pref) {
    global $wg0ConfigFile;
    $postUp = "PostUp = ip rule add to $ip table main preference $pref";
    $postDown = "PostDown = ip rule del to $ip table main preference $pref";
    $lines = file($wg0ConfigFile, FILE_IGNORE_NEW_LINES);
    $newLines = [];
    foreach ($lines as $line) {
        if (trim($line) !== trim($postUp) && trim($line) !== trim($postDown)) {
            $newLines[] = $line;
        }
    }
    file_put_contents($wg0ConfigFile, implode(PHP_EOL, $newLines) . PHP_EOL);
}

$routes = file_exists($routesFile) ? file($routesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_ip'])) {
    $new_ip = trim($_POST['new_ip']);
    if (filter_var($new_ip, FILTER_VALIDATE_IP) && !in_array($new_ip, $routes)) {
        exec("sudo ip rule add to $new_ip table main preference $preference");
        addBypassToWg0($new_ip, $preference);
        exec("sudo systemctl restart wg-quick@wg0");
        $routes[] = $new_ip;
        file_put_contents($routesFile, implode(PHP_EOL, $routes) . PHP_EOL, LOCK_EX);
        echo "<script>Notice('Маршрут для $new_ip успешно добавлен!', 'success'); window.setTimeout(() => window.location = 'cabinet.php?menu=route', 1500);</script>";
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ip'])) {
    $delete_ip = trim($_POST['delete_ip']);
    if (in_array($delete_ip, $routes)) {
        exec("sudo ip rule del to $delete_ip table main preference $preference");
        removeBypassFromWg0($delete_ip, $preference);
        exec("sudo systemctl restart wg-quick@wg0");
        $routes = array_filter($routes, fn($ip) => $ip !== $delete_ip);
        file_put_contents($routesFile, implode(PHP_EOL, $routes) . PHP_EOL, LOCK_EX);
        echo "<script>Notice('Маршрут для $delete_ip удален.', 'success'); window.setTimeout(() => window.location = 'cabinet.php?menu=route', 1500);</script>";
        exit();
    }
}
?>

<div class="space-y-8">

    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-2xl font-bold text-white mb-2">Обход VPN</h2>
        <p class="text-slate-400 mb-6">Трафик на эти IP-адреса будет идти напрямую, игнорируя туннель.</p>
        
        <div class="space-y-3">
            <?php if (!empty($routes)): ?>
                <?php foreach ($routes as $route): ?>
                    <div class="flex items-center justify-between bg-slate-800/50 p-3 rounded-lg">
                        <code class="text-lg text-sky-300 font-mono"><?= htmlspecialchars($route) ?></code>
                        <form method="POST" class="m-0">
                            <input type="hidden" name="delete_ip" value="<?= htmlspecialchars($route) ?>">
                            <button type="submit" class="bg-red-500/20 text-red-400 hover:bg-red-500/40 hover:text-white rounded-md px-3 py-1 text-sm font-medium transition-colors">
                                Удалить
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center text-slate-500 py-8">
                    Список IP-адресов для обхода пуст.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="glassmorphism rounded-2xl p-6">
         <h2 class="text-2xl font-bold text-white mb-6">Добавить IP для обхода</h2>
        <form method="POST" class="flex flex-col sm:flex-row items-center gap-4">
            <input type="text" name="new_ip" placeholder="Введите IP-адрес" required pattern="^(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])$" title="Введите корректный IP-адрес" class="flex-grow w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
            <button type="submit" class="w-full sm:w-auto bg-green-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-green-700 transition-all">
                Добавить
            </button>
        </form>
    </div>

</div>
<?php
/**
 * WGPlus — обход VPN (direct routes)
 *
 * Правило `ip rule add to <IP> table main` заставляет трафик на указанный адрес
 * идти напрямую, минуя туннель. Применяется сразу (live) и дублируется в
 * PostUp/PostDown wg0.conf — чтобы пережить перезагрузку.
 */

require_once __DIR__ . '/includes/wgp_helpers.php';

// Явная защита вместо прежней проверки $_SESSION без session_start()
// (она работала только по счастливой случайности).
wgp_require_auth();
wgp_csrf_require();

$wg0ConfigFile = WGP_WG0_CONF;
$routesFile    = WGP_ROUTES_FILE;   // вне docroot, в каталоге с правами на запись
$preference    = 30000;
$net           = wgp_wg0_net();

/** Атомарная запись списка маршрутов. */
function wgp_saveRoutes(array $routes, string $file): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        wgp_log('ERR', "Каталог $dir не существует");
        return false;
    }
    if (!is_writable($dir)) {
        wgp_log('ERR', "Нет прав на запись в $dir — маршруты не сохранятся");
        return false;
    }
    $tmp  = $file . '.tmp';
    $data = $routes ? implode(PHP_EOL, $routes) . PHP_EOL : '';
    if (@file_put_contents($tmp, $data) === false) {
        wgp_log('ERR', "Не удалось записать $tmp");
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        wgp_log('ERR', "Не удалось обновить $file");
        return false;
    }
    @chmod($file, 0664);
    return true;
}

function addBypassToWg0(string $ip, int $pref, string $confFile): void {
    $postUp   = "PostUp = ip rule add to $ip table main preference $pref";
    $postDown = "PostDown = ip rule del to $ip table main preference $pref";
    $config = @file_get_contents($confFile);
    if ($config === false) return;
    // Не дублируем, если правило уже прописано.
    if (strpos($config, $postUp) !== false) return;
    $config = preg_replace('/(\[Interface\]\s*\r?\n)/', "$1$postUp\n$postDown\n", $config, 1);
    @file_put_contents($confFile, $config);
}

function removeBypassFromWg0(string $ip, int $pref, string $confFile): void {
    $postUp   = "PostUp = ip rule add to $ip table main preference $pref";
    $postDown = "PostDown = ip rule del to $ip table main preference $pref";
    $lines = @file($confFile, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return;
    $kept = [];
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t !== trim($postUp) && $t !== trim($postDown)) $kept[] = $line;
    }
    @file_put_contents($confFile, implode(PHP_EOL, $kept) . PHP_EOL);
}

$routes = file_exists($routesFile)
    ? (file($routesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
    : [];

// ── Добавление ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_ip'])) {
    $new_ip = trim($_POST['new_ip']);

    if (filter_var($new_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        echo "<script>Notice('Некорректный IP-адрес.', 'error');</script>";

    } elseif (in_array($new_ip, $routes, true)) {
        echo "<script>Notice('Этот адрес уже в списке.', 'error');</script>";

    } else {
        // IP валидирован как IPv4, но escapeshellarg ставим всё равно.
        $routes[] = $new_ip;

        // СНАЧАЛА сохраняем и ПРОВЕРЯЕМ результат.
        // Раньше результат не проверялся — при отказе записи пользователь
        // видел зелёное "успешно добавлено", а список оставался пустым.
        if (!wgp_saveRoutes($routes, $routesFile)) {
            echo "<script>Notice('Не удалось сохранить маршрут. Проверьте права на " . WGP_DATA_DIR . " и лог панели.', 'error');</script>";
        } else {
            exec('sudo ip rule add to ' . escapeshellarg($new_ip) . " table main preference $preference");
            addBypassToWg0($new_ip, $preference, $wg0ConfigFile);
            // НЕ рестартуем wg0: правило уже применено выше живьём,
            // а запись в wg0.conf нужна только чтобы пережить перезагрузку.
            wgp_log('OK', "Добавлен обход VPN для $new_ip");
            wgp_event('route_add', $new_ip);
            echo "<script>Notice('Маршрут для " . htmlspecialchars($new_ip, ENT_QUOTES) . " добавлен.', 'success'); window.setTimeout(() => window.location = 'cabinet.php?menu=route', 1200);</script>";
            exit();
        }
    }
}

// ── Удаление ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ip'])) {
    $delete_ip = trim($_POST['delete_ip']);
    if (in_array($delete_ip, $routes, true)
        && filter_var($delete_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {

        exec('sudo ip rule del to ' . escapeshellarg($delete_ip) . " table main preference $preference");
        removeBypassFromWg0($delete_ip, $preference, $wg0ConfigFile);
        $routes = array_values(array_filter($routes, fn($ip) => $ip !== $delete_ip));
        if (!wgp_saveRoutes($routes, $routesFile)) {
            echo "<script>Notice('Правило снято, но список не сохранён. Проверьте права на " . WGP_DATA_DIR . ".', 'error');</script>";
        } else {
            wgp_log('OK', "Удалён обход VPN для $delete_ip");
            wgp_event('route_del', $delete_ip);
            echo "<script>Notice('Маршрут для " . htmlspecialchars($delete_ip, ENT_QUOTES) . " удалён.', 'success'); window.setTimeout(() => window.location = 'cabinet.php?menu=route', 1200);</script>";
            exit();
        }
    }
}
?>

<div class="space-y-8">

    <div class="glassmorphism rounded-2xl p-6">
        <h2 class="text-2xl font-bold text-white mb-2">Обход VPN</h2>
        <p class="text-slate-400 mb-6">
            Трафик на эти IP-адреса пойдёт напрямую, минуя туннель.
            Подсеть клиентов: <span class="font-mono text-sky-300"><?= htmlspecialchars($net['cidr']) ?></span>
        </p>

        <div class="space-y-3">
            <?php if (!empty($routes)): ?>
                <?php foreach ($routes as $route): ?>
                    <div class="flex items-center justify-between bg-slate-800/50 p-3 rounded-lg">
                        <code class="text-lg text-sky-300 font-mono"><?= htmlspecialchars($route) ?></code>
                        <form method="POST" class="m-0">
                            <?= wgp_csrf_field() ?>
                            <input type="hidden" name="delete_ip" value="<?= htmlspecialchars($route) ?>">
                            <input type="hidden" name="menu" value="route">
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
            <?= wgp_csrf_field() ?>
            <input type="text" name="new_ip" placeholder="Введите IP-адрес" required
                   pattern="^(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])$"
                   title="Введите корректный IP-адрес"
                   class="flex-grow w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
            <input type="hidden" name="menu" value="route">
            <button type="submit" class="w-full sm:w-auto bg-green-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-green-700 transition-all">
                Добавить
            </button>
        </form>
    </div>

</div>

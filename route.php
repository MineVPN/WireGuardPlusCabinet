<?php
session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

// НОВЫЙ ПУТЬ: Указываем правильный путь к скрипту
$upstreamRouteFile = '/etc/wireguard/scripts/upstream-route.sh';

// Все остальные функции (safeReadFile, cleanUpstreamRouteFile, etc.) остаются без изменений
// так как они работают с системными командами, не зависящими от типа VPN.
// Здесь я привожу полный код для ясности.

function safeReadFile($filename) {
    return file_exists($filename) ? trim(file_get_contents($filename)) : '';
}

function cleanUpstreamRouteFile($file) {
    if (!file_exists($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_filter($lines, 'trim');
    file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
}

function updateUpstreamRouteFile($file, $route, $rule) {
    if (!file_exists($file)) {
        file_put_contents($file, "#!/bin/bash\n\nexit 0\n");
        chmod($file, 0755);
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!in_array($route, $lines) && !in_array($rule, $lines)) {
        $newLines = [];
        foreach ($lines as $line) {
            if (trim($line) === "exit 0") {
                $newLines[] = $route;
                $newLines[] = $rule;
            }
            $newLines[] = $line;
        }
        file_put_contents($file, implode(PHP_EOL, $newLines) . PHP_EOL);
    }
}

function removeUpstreamRoute($file, $route, $rule) {
    if (!file_exists($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $newLines = [];
    foreach ($lines as $line) {
        if (trim($line) !== trim($route) && trim($line) !== trim($rule)) {
            $newLines[] = $line;
        }
    }
    file_put_contents($file, implode(PHP_EOL, $newLines) . PHP_EOL);
}

$nic = safeReadFile('NIC.txt');
$gateway = safeReadFile('gateway.txt');
$routesFile = 'routes.txt';
$routes = file_exists($routesFile) ? file($routesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_ip'])) {
    $new_ip = trim($_POST['new_ip']);
    if (filter_var($new_ip, FILTER_VALIDATE_IP) && !in_array($new_ip, $routes)) {
        $route = "ip route add $new_ip via $gateway dev $nic";
        $rule = "ip rule add to $new_ip"; // Упрощено, без table
        updateUpstreamRouteFile($upstreamRouteFile, $route, $rule);
        exec("sudo $route");
        exec("sudo $rule");
        $routes[] = $new_ip;
        file_put_contents($routesFile, implode(PHP_EOL, $routes) . PHP_EOL, LOCK_EX);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ip'])) {
    $delete_ip = trim($_POST['delete_ip']);
    if (in_array($delete_ip, $routes)) {
        $route = "ip route add $delete_ip via $gateway dev $nic";
        $rule = "ip rule add to $delete_ip"; // Упрощено
        $routedel = "ip route del $delete_ip";
        $ruledel = "ip rule del to $delete_ip";
        removeUpstreamRoute($upstreamRouteFile, $route, $rule);
        exec("sudo $routedel");
        exec("sudo $ruledel");
        $routes = array_filter($routes, fn($ip) => $ip !== $delete_ip);
        file_put_contents($routesFile, implode(PHP_EOL, $routes) . PHP_EOL, LOCK_EX);
    }
}

cleanUpstreamRouteFile($upstreamRouteFile);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление маршрутами обхода</title>
</head>
<body>
    <h2>Список IP для обхода второго VPN (`wg1`)</h2>
    <p>Трафик до этих адресов пойдет напрямую через первый VPN (`wg0`), игнорируя второй.</p>
    
    <table id="routeWindow">
        <?php if (!empty($routes)): ?>
            <?php foreach ($routes as $route_ip): ?>
                <tr>
                    <th class="route-ip"><?= htmlspecialchars($route_ip) ?></th>
                    <th>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="delete_ip" value="<?= htmlspecialchars($route_ip) ?>">
                            <button type="submit" class="red-button">Удалить</button>
                        </form>
                    </th>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="2" style="text-align:center; padding:10px;">Список пуст</td></tr>
        <?php endif; ?>
    </table>

    <h2>Добавить IP для обхода</h2>
    <form method="POST">
        <input type="text" name="new_ip" placeholder="Введите IP-адрес" required pattern="^(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])$" title="Введите корректный IP-адрес">
        <button type="submit" class="green-button">Добавить</button>
    </form>
</body>
</html>

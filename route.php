<?php

session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

function safeReadFile($filename) {
    return file_exists($filename) ? trim(file_get_contents($filename)) : '';
}

function cleanUpstreamRouteFile($file) {
    if (!file_exists($file)) return;

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_filter($lines, 'trim'); // Убираем пустые строки
    file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
}

function updateUpstreamRouteFile($file, $route, $rule) {
    if (!file_exists($file)) {
        file_put_contents($file, "#!/bin/bash\n\nexit 0\n");
        chmod($file, 0755);
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    // Проверяем, есть ли уже эти правила
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
$upstreamRouteFile = '/etc/openvpn/upstream-route.sh';

// Обрабатываем добавление нового IP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_ip'])) {
    $new_ip = trim($_POST['new_ip']);
    if (filter_var($new_ip, FILTER_VALIDATE_IP) && !in_array($new_ip, $routes)) {
        $route = "ip route add $new_ip via $gateway dev $nic table $nic";
        $rule = "ip rule add to $new_ip table $nic";

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
        $route = "ip route add $delete_ip via $gateway dev $nic table $nic";
        $rule = "ip rule add to $delete_ip table $nic";
        $routedel = "ip route del $delete_ip via $gateway dev $nic table $nic";
        $ruledel = "ip rule del to $delete_ip table $nic";

        // Удаляем маршруты из upstream-route.sh
        removeUpstreamRoute($upstreamRouteFile, $route, $rule);

        // Выполняем удаление маршрутов в системе
        exec("sudo $routedel");
        exec("sudo $ruledel");

        // Удаляем IP из списка
        $routes = array_filter($routes, fn($ip) => $ip !== $delete_ip);
        file_put_contents($routesFile, implode(PHP_EOL, $routes) . PHP_EOL, LOCK_EX);
    }
}


// Удаляем пустые строки из upstream-route.sh
cleanUpstreamRouteFile($upstreamRouteFile);

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление маршрутами</title>
</head>
<body>
    <h2>Список IP-адресов для обхода второго VPN</h2>
    <p>Эти адреса будут использовать только первый VPN.</p>
    
    <table id="routeWindow">
        <?php if (!empty($routes)): ?>
            <?php foreach ($routes as $route): ?>
                <tr>
                    <th class="route-ip">
                        <?= htmlspecialchars($route) ?>
                    </th>
                    <th>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="delete_ip" value="<?= htmlspecialchars($route) ?>">
                            <button type="submit" class="red-button">Удалить</button>
                        </form>
                    </th>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="2" style="text-align:center; padding:10px;">Список пуст, добавьте IP</td>
            </tr>
        <?php endif; ?>
    </table>

    <h2>Добавить IP</h2>
    <p>Введите IP, который должен работать через первый VPN.</p>
    <form method="POST">
        <input type="text" name="new_ip" placeholder="Введите IP-адрес" required
        pattern="^(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])$"
        title="Введите корректный IP-адрес (например, 192.168.1.1)">
        <button type="submit" class="green-button">Добавить</button>
    </form>
</body>
</html>


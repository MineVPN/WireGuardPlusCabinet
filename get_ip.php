<?php
// Этот файл уже не требует проверки сессии, так как включается в vpn.php

$openvpn_config_path = '/etc/openvpn/tun1.conf';
$wireguard_config_path = '/etc/wireguard/tun1.conf';
$type = null;
$tun_up = false;

echo "<h2>Статус VPN:</h2><hr>";

// Проверяем статус интерфейса tun1
$status_output = shell_exec("ip addr show tun1 2>&1");
if (strpos($status_output, 'does not exist') === false) {
    $tun_up = true;
}

// Определяем тип конфигурации
if (file_exists($openvpn_config_path)) {
    $type = "openvpn";
    $config_content = file_get_contents($openvpn_config_path);
    if (preg_match('/^\s*remote\s+([^\s]+)/m', $config_content, $matches)) {
        echo "<span class='contaiter-param'>Загружена конфигурация:</span> OpenVPN<br>";
        echo "<span class='contaiter-param'>IP:</span> {$matches[1]} <br>";
    }
} elseif (file_exists($wireguard_config_path)) {
    $type = "wireguard";
    $config_content = file_get_contents($wireguard_config_path);
    if (preg_match('/^\s*Endpoint\s*=\s*([\d\.]+):\d+/m', $config_content, $matches)) {
        echo "<span class='contaiter-param'>Загружена конфигурация:</span> WireGuard<br>";
        echo "<span class='contaiter-param'>IP:</span> {$matches[1]}<br>";
    }
} else {
    echo "Конфигурация для второго VPN не загружена.<br>";
}

if ($type) {
    echo "<span class='contaiter-param'>Соединение: </span>";
    if ($tun_up) {
        echo "<span class='connected'>Установлено</span>";
    } else {
        echo "<span class='disconnected'>Разорвано</span>";
    }
}

echo "<br><br>";

// Обработка кнопок старт/стоп
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = null;
    if (isset($_POST['start'])) $action = 'start';
    if (isset($_POST['stop'])) $action = 'stop';

    if ($action && $type) {
        $service = ($type == 'openvpn') ? 'openvpn@tun1' : 'wg-quick@tun1';
        shell_exec("sudo systemctl $action $service");
        sleep(3);
        header("Location: " . $_SERVER['PHP_SELF'] . "?menu=vpn");
        exit();
    }
}
?>
<form method="post" class="container-form">
    <?php if ($type): ?>
        <?php if ($tun_up): ?>
            <input type="submit" class="red-button" name="stop" value="Остановить <?= strtoupper($type) ?>">
        <?php else: ?>
            <input type="submit" class="green-button" name="start" value="Запустить <?= strtoupper($type) ?>">
        <?php endif; ?>
    <?php endif; ?>
</form>
<?php
// Проверка сессии не нужна, т.к. файл включается в vpn_manager.php

$config_path = '/etc/wireguard/wg1.conf';
$interface_name = 'wg1';
$is_running = false;

echo "<h2>Статус VPN (`wg1`):</h2><hr>";

// Проверяем, запущен ли интерфейс
if (strpos(shell_exec("ip addr show $interface_name 2>&1"), 'does not exist') === false) {
    $is_running = true;
}

// Определяем тип конфигурации
if (file_exists($config_path)) {
    $config_content = file_get_contents($config_path);
    if (preg_match('/^\s*Endpoint\s*=\s*([\d\.]+):\d+/m', $config_content, $matches)) {
        echo "<span class='contaiter-param'>Загружена конфигурация:</span> WireGuard<br>";
        echo "<span class='contaiter-param'>IP конечного сервера:</span> {$matches[1]}<br>";
    }
} else {
    echo "Конфигурация для `{$interface_name}` не загружена.<br>";
}

echo "<span class='contaiter-param'>Соединение: </span>";
if ($is_running) {
    echo "<span class='connected'>Установлено</span>";
} else {
    echo "<span class='disconnected'>Разорвано</span>";
}
echo "<br><br>";

// Обработка кнопок старт/стоп
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = null;
    if (isset($_POST['start'])) $action = 'start';
    if (isset($_POST['stop'])) $action = 'stop';

    if ($action && file_exists($config_path)) {
        shell_exec("sudo systemctl $action wg-quick@{$interface_name}");
        sleep(3);
        header("Location: " . $_SERVER['PHP_SELF'] . "?menu=vpn_manager");
        exit();
    }
}
?>
<form method="post" class="container-form">
    <?php if (file_exists($config_path)): ?>
        <?php if ($is_running): ?>
            <input type="submit" class="red-button" name="stop" value="Остановить wg1">
        <?php else: ?>
            <input type="submit" class="green-button" name="start" value="Запустить wg1">
        <?php endif; ?>
    <?php endif; ?>
</form>

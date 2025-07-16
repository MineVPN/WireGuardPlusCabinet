<?php
// Рекомендуется добавить эти строки в начале для отладки
ini_set('display_errors', 1);
error_reporting(E_ALL);

$wireguard_config_path = '/etc/wireguard/wg1.conf';
// Имя интерфейса должно соответствовать имени файла конфигурации (без .conf)
$interface_name = 'wg1'; 

$type = null;
$tun = null; // Переименовал в $is_disconnected для ясности
echo "<h2>Статус VPN:</h2><hr>";

// 1. Проверяем наличие файла конфигурации WireGuard
if (file_exists($wireguard_config_path)) {
    // Проверяем, есть ли у PHP права на чтение файла
    if (is_readable($wireguard_config_path)) {
        $wireguard_config_content = file_get_contents($wireguard_config_path);

        if (preg_match('/^\s*Endpoint\s*=\s*([\d\.]+):\d+/m', $wireguard_config_content, $matchesw)) {
            $wireguard_ip = $matchesw[1];
            echo "<span class='contaiter-param'>Загружена конфигурация:</span> WireGuard<br>";
            echo "<span class='contaiter-param'>IP:</span> $wireguard_ip<br>";
            $type = "wireguard";
        } else {
            echo "<h3>Некорректный WireGuard конфиг. Отсутствует или неверно указан Endpoint.</h3>";
        }
    } else {
        echo "<h3>Ошибка: Нет прав на чтение файла конфигурации '{$wireguard_config_path}'.</h3>";
        echo "<p>Выполните: <code>sudo chmod 644 {$wireguard_config_path}</code> и <code>sudo chown www-data:www-data {$wireguard_config_path}</code> (замените www-data на вашего пользователя веб-сервера).</p>";
    }
} else {
    // ✅ ВОТ РЕШЕНИЕ: Этот блок выполнится, если файл не найден
    echo "<h3>Ошибка: Конфигурационный файл WireGuard не найден по пути: {$wireguard_config_path}</h3>";
}

// 2. Проверяем статус туннеля, только если конфигурация была успешно загружена
if ($type === "wireguard") {
    // Используем ip link show вместо ifconfig для большей надежности
    $status = shell_exec("ip link show {$interface_name} 2>&1");

    echo "<span class='contaiter-param'>Соединение: </span>";
    // Если в выводе нет имени интерфейса, значит он не поднят
    if (strpos($status, $interface_name) === false) {
        echo "<span class='disconnected'>Разорвано</span>";
        $is_disconnected = true;
    } else {
        echo "<span class='connected'>Установлено</span>";
        $is_disconnected = false;
    }
    echo "<br><br>";
}


// --- Обработка POST-запросов ---

if (isset($_POST['wireguard_start']) && $type === "wireguard") {
    // Команда должна соответствовать имени интерфейса
    shell_exec("sudo systemctl start wg-quick@{$interface_name}");
    sleep(5);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_POST['wireguard_stop']) && $type === "wireguard") {
    shell_exec("sudo systemctl stop wg-quick@{$interface_name}");
    sleep(3);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>
<form method="post" class="container-form">
    <?php if ($type === "wireguard" && $is_disconnected): ?>
        <input type="submit" class="green-button" name="wireguard_start" value="Запустить WireGuard">
    <?php elseif ($type === "wireguard" && !$is_disconnected): ?>
        <input type="submit" class="red-button" name="wireguard_stop" value="Остановить WireGuard">
    <?php endif; ?>
</form>

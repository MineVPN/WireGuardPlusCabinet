<div class="container">
    <?php
    $wireguard_config_path = '/etc/wireguard/wg1.conf';

    $type = null;
    $tun = null;
    echo "<h2>Статус VPN:</h2><hr>";

    // Проверяем наличие файла конфигурации WireGuard
    if (file_exists($wireguard_config_path)) {
        // Читаем содержимое файла
        $wireguard_config_content = file_get_contents($wireguard_config_path);

        if (preg_match('/^\s*Endpoint\s*=\s*([\d\.]+):\d+/m', $wireguard_config_content, $matchesw)) {
            $wireguard_ip = $matchesw[1];
            echo "<span class='contaiter-param'>Загружена конфигурация:</span> WireGuard<br>";
            echo "<span class='contaiter-param'>IP:</span> $wireguard_ip<br>";
            $type = "wireguard";
        } else {
            echo "<h3>Не корректный WireGuard конфиг.</h3>";
        }
    }

    // Выполняем команду для проверки статуса туннеля tun0
    $status = shell_exec("ifconfig tun0 2>&1");

    // Проверяем, содержит ли вывод информацию о туннеле
    echo "<span class='contaiter-param'>Соединение: </span>";
    if (strpos($status, 'Device not found') !== false) {
        echo "<span class='disconnected'>Разорвано</span>";
        $tun = "yes";
    } else {
        echo "<span class='connected'>Установлено</span>";
        $tun = "no";
    }

    echo "<br><br>";

    if(isset($_POST['wireguard_start']) && $type == "wireguard") {
        shell_exec("sudo systemctl start wg-quick@tun0");
        sleep(5);
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }

    if(isset($_POST['wireguard_stop']) && $type == "wireguard") {
        shell_exec("sudo systemctl stop wg-quick@tun0");
        sleep(3);
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }
    ?>
    <form method="post" class="container-form">        
        <?php if ($type == "wireguard" && $tun == "yes"): ?>
            <input type="submit" class="green-button" name="wireguard_start" value="Запустить WireGuard">
        <?php elseif ($type == "wireguard" && $tun == "no"): ?>
            <input type="submit" class="red-button" name="wireguard_stop" value="Остановить WireGuard">
        <?php endif; ?>
    </form>
</div>

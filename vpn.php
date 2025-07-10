<?php
session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

// --- Обработка загрузки OpenVPN ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["ovpn_config_file"])) {
    $allowed_extensions = array('ovpn');
    $file_extension = strtolower(pathinfo($_FILES["ovpn_config_file"]["name"], PATHINFO_EXTENSION));

    if (in_array($file_extension, $allowed_extensions)) {
        // Останавливаем все возможные туннели на tun1
        shell_exec('sudo systemctl stop openvpn@tun1');
        shell_exec('sudo systemctl stop wg-quick@tun1');
        shell_exec('sudo rm /etc/openvpn/tun1.conf /etc/wireguard/tun1.conf');

        $upload_dir = '/etc/openvpn/';
        $config_file_ovpn = $upload_dir . "tun1.conf";

        if (move_uploaded_file($_FILES["ovpn_config_file"]["tmp_name"], $config_file_ovpn)) {
            $file_content = file_get_contents($config_file_ovpn);
            $file_content = preg_replace('/\bdev tun\b/', 'dev tun1', $file_content);
            
            $insert_text = "pull-filter ignore \"redirect-gateway\"\n" .
                           "script-security 2\n" .
                           "up /etc/openvpn/upstream-route.sh";
            $file_content = preg_replace('/(<ca>)/', $insert_text . "\n$1", $file_content, 1);
            file_put_contents($config_file_ovpn, $file_content);

            shell_exec('sudo systemctl start openvpn@tun1');
            sleep(4);
            echo "<script>Notice('OpenVPN конфигурация успешно установлена!');</script>";
        }
    }
}

// --- Обработка загрузки WireGuard ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["wg_config_file"])) {
    $allowed_extensions = array('conf');
    $file_extension = strtolower(pathinfo($_FILES["wg_config_file"]["name"], PATHINFO_EXTENSION));

    if (in_array($file_extension, $allowed_extensions)) {
        // Останавливаем все возможные туннели на tun1
        shell_exec('sudo systemctl stop openvpn@tun1');
        shell_exec('sudo systemctl stop wg-quick@tun1');
        shell_exec('sudo rm /etc/openvpn/tun1.conf /etc/wireguard/tun1.conf');
        
        $upload_dir = '/etc/wireguard/';
        $config_file_wg = $upload_dir . "tun1.conf";

        if (move_uploaded_file($_FILES["wg_config_file"]["tmp_name"], $config_file_wg)) {
            $file_content = file_get_contents($config_file_wg);
            
            // ВАЖНО: Заменяем AllowedIPs, чтобы второй VPN не перехватывал весь трафик.
            // Вместо 0.0.0.0/0 мы ставим маршрут по-умолчанию в отдельную таблицу "120".
            // Это позволяет нам управлять маршрутизацией через ip rule.
            $file_content = preg_replace('/^\s*AllowedIPs\s*=\s*0\.0\.0\.0\/0/m', 'Table = 120', $file_content);
            
            // Добавляем хук для запуска скрипта маршрутизации
            if (strpos($file_content, 'PostUp') === false) {
                 $file_content .= "\nPostUp = /etc/openvpn/upstream-route.sh";
            }

            file_put_contents($config_file_wg, $file_content);
            
            shell_exec('sudo systemctl start wg-quick@tun1');
            sleep(4);
            echo "<script>Notice('WireGuard конфигурация успешно установлена!');</script>";
        }
    }
}


// Подключаем файл статуса
include_once 'get_ip.php';
?>

<div class="container">
    <h2>Установка OpenVPN</h2>
    <form method="post" enctype="multipart/form-data" class="container-form">
        <label for="ovpn_config_file">Выберите файл конфигурации (*.ovpn):</label><br>
        <input type="file" id="ovpn_config_file" name="ovpn_config_file" accept=".ovpn">
        <input type="hidden" name="menu" value="vpn">
        <input type="submit" class="green-button" value="Установить OpenVPN">
    </form>
</div>

<div class="container">
    <h2>Установка WireGuard</h2>
    <form method="post" enctype="multipart/form-data" class="container-form">
        <label for="wg_config_file">Выберите файл конфигурации (*.conf):</label><br>
        <input type="file" id="wg_config_file" name="wg_config_file" accept=".conf">
        <input type="hidden" name="menu" value="vpn">
        <input type="submit" class="green-button" value="Установить WireGuard">
    </form>
</div>
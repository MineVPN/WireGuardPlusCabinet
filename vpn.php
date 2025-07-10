<?php
session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

// --- Обработка загрузки WireGuard ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["wg_config_file"])) {
    $allowed_extensions = array('conf');
    $file_extension = strtolower(pathinfo($_FILES["wg_config_file"]["name"], PATHINFO_EXTENSION));

    if (in_array($file_extension, $allowed_extensions)) {
        // Останавливаем интерфейс wg1, если он был запущен
        shell_exec('sudo systemctl stop wg-quick@wg1');
        
        $config_file_wg = '/etc/wireguard/wg1.conf';

        if (move_uploaded_file($_FILES["wg_config_file"]["tmp_name"], $config_file_wg)) {
            $file_content = file_get_contents($config_file_wg);
            
            // ИСПРАВЛЕНО: Корректная логика модификации конфига для двойного VPN
            if (preg_match('/^\s*AllowedIPs\s*=.*0\.0\.0\.0\/0/m', $file_content)) {
                // 1. Убираем 0.0.0.0/0 из AllowedIPs, чтобы не перехватывать весь трафик по-умолчанию
                $file_content = preg_replace('/0\.0\.0\.0\/0,?/', '', $file_content);
                // 2. Добавляем Table = 120 в секцию [Interface] для policy-based routing
                $file_content = preg_replace('/(\[Interface\])/', "$1\nTable = 120", $file_content);
            }
            
            // Добавляем хук для запуска скрипта маршрутизации, если его еще нет
            if (strpos($file_content, 'PostUp') === false) {
                 // ИСПОЛЬЗУЕМ НОВЫЙ ПУТЬ
                 $file_content .= "\nPostUp = /etc/wireguard/scripts/upstream-route.sh";
            }

            file_put_contents($config_file_wg, $file_content);
            
            // ИСПРАВЛЕНО: Устанавливаем безопасные права на файл конфига
            chmod($config_file_wg, 0600);
            
            shell_exec('sudo systemctl start wg-quick@wg1');
            sleep(4);
            echo "<script>Notice('WireGuard конфигурация для wg1 успешно установлена!');</script>";
        }
    }
}

// Подключаем файл статуса
include_once 'get_ip.php';
?>

<div class="container">
    <h2>Установка конфигурации для `wg1`</h2>
    <p>Загрузите файл конфигурации от вашего второго VPN-провайдера.</p>
    <form method="post" enctype="multipart/form-data" class="container-form">
        <label for="wg_config_file">Выберите файл конфигурации (*.conf):</label><br>
        <input type="file" id="wg_config_file" name="wg_config_file" accept=".conf" required>
        <input type="hidden" name="menu" value="vpn_manager">
        <input type="submit" class="green-button" value="Установить и запустить wg1">
    </form>
</div>

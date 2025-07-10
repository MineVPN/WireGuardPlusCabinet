<?php
session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

if(isset($_POST['menu'])){
    $_GET['menu'] = $_POST['menu'];
}

$menu_item = isset($_GET['menu']) ? $_GET['menu'] : 'vpn_manager';

$menu_pages = [
    'vpn_manager' => 'vpn_manager.php',
    'route' => 'route.php',
    'ping' => 'pinger.php'
];

if (!array_key_exists($menu_item, $menu_pages)) {
    $menu_item = 'vpn_manager';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>WireGuard+ Cabinet</title>
    <link rel="stylesheet" href="styles.css">
    <script>
    function Notice(text) {
        const element = document.querySelector('.notice');
        if (element) {
            element.textContent = text;
            element.classList.remove('hidden');
        }
    }
    </script>
</head>
<body>
    <div class="sidebar">
        <img src="logo.png" class="logo">
        <a class="menu-item" href="cabinet.php?menu=vpn_manager">Управление VPN (wg1)</a>
        <a class="menu-item" href="cabinet.php?menu=route">Маршруты обхода</a>
        <a class="menu-item" href="cabinet.php?menu=ping">Ping</a>
        <a class="menu-item" href="logout.php">Выход</a>
    </div>

    <div class="notice hidden"></div>
    <div class="page">
        <?php include_once $menu_pages[$menu_item]; ?>
    </div>
</body>
</html>

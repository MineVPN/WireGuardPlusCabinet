<?php
session_start(); // Начало сессии

// Проверяем, установлена ли сессия
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}

if(isset($_POST['menu'])){
    $_GET['menu'] = $_POST['menu'];
}

// По умолчанию открывается страница VPN
$menu_item = isset($_GET['menu']) ? $_GET['menu'] : 'vpn';

// Пути к страницам меню
$menu_pages = [
    'vpn' => 'vpn.php', // Изменили на общий vpn.php
    'ping' => 'pinger.php',
    'route' => 'route.php'
];

if (!array_key_exists($menu_item, $menu_pages)) {
    $menu_item = 'vpn';
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>VPN+</title>
    <link rel="stylesheet" href="styles.css">
    <script>
    function Notice(text) {
        var elements = document.querySelectorAll('.notice');
        elements.forEach(function(element) {
            element.textContent = text;
            if (element.classList.contains('hidden')) {
                element.classList.remove('hidden');
            }
        });
    }
    </script>
</head>
<body>
    <div class="sidebar">
        <img src="logo.png" class="logo">
        <a class="menu-item" href="cabinet.php?menu=vpn">VPN Status</a>
        <a class="menu-item" href="cabinet.php?menu=route">Route</a>
        <a class="menu-item" href="cabinet.php?menu=ping">Ping</a>
        <a class="menu-item" href="logout.php">Выход</a>
    </div>

    <div class="notice hidden"></div>
    <div class="page">
        <?php
        include_once $menu_pages[$menu_item];
        ?>
    </div>
</body>
</html>
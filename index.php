<?php
// Точка входа. Хелперы ПЕРЕД session_start() — в них выставляются
// параметры cookie сессии, после старта их уже не применить.
require_once __DIR__ . '/includes/wgp_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Строгая проверка: раньше было `if ($_SESSION["authenticated"] == true)`
// без isset — на PHP 8 это Warning "Undefined array key" при первом заходе,
// плюс нестрогое сравнение.
if (isset($_SESSION["authenticated"]) && $_SESSION["authenticated"] === true) {
    include "cabinet.php";
} else {
    include "login.php";
}

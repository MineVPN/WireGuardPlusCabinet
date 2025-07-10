<?php
session_start(); // Начинаем сессию
session_unset(); // Очищаем все переменные сессии
session_destroy(); // Разрушаем сессию
header("Location: login.php"); // Перенаправляем пользователя на страницу входа
exit(); // Завершаем скрипт
?>
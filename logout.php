<?php
/**
 * WGPlus — выход из панели.
 *
 * Было: session_start() + session_unset() + session_destroy().
 * Этого мало — cookie с ID сессии оставалась в браузере, а session_start()
 * без проверки статуса давал Notice, если сессия уже поднята.
 */

require_once __DIR__ . '/includes/wgp_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['authenticated'])) {
    wgp_log('INFO', 'Выход из панели');
    wgp_event('logout');
}

// Чистим данные, гасим cookie и уничтожаем сессию на сервере.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: login.php');
exit();

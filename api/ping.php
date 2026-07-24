<?php
/**
 * WGPlus — проверка доступности адреса.
 *
 * GET:
 *   host  — IPv4 или имя хоста
 *   iface — wg1 (через туннель) | nic (напрямую) | пусто (как пойдёт)
 *
 * Возвращает число миллисекунд, OK или NO PING.
 *
 * Безопасность: раньше $host уходил в exec() без проверок и без
 * авторизации — это было выполнение произвольных команд на сервере.
 * Теперь: сессия обязательна, host валидируется, имя интерфейса
 * берётся из фиксированного набора, всё экранируется.
 */

require_once __DIR__ . '/../includes/wgp_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (wgp_session_invalid_reason() !== '') {
    http_response_code(403);
    die('NO PING');
}

$host  = trim($_GET['host'] ?? '');
$which = trim($_GET['iface'] ?? '');

if ($host === '') die('NO PING');

$isIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
$isHost = strlen($host) <= 255
       && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]{0,253}[a-zA-Z0-9])?$/', $host) === 1;
if (!$isIp && !$isHost) die('NO PING');

// Интерфейс не берём из ввода напрямую — только фиксированный выбор.
$via = '';
if ($which === 'wg1') {
    $via = 'wg1';
} elseif ($which === 'nic') {
    $via = wgp_wan_iface();
}

$cmd = 'ping -c 1 -W 1';
if ($via !== '') $cmd .= ' -I ' . escapeshellarg($via);
$cmd .= ' ' . escapeshellarg($host);

exec($cmd, $out, $rc);

if ($rc === 0) {
    foreach ($out as $l) {
        if (strpos($l, 'time=') !== false) {
            echo trim(explode(' ', explode('time=', $l)[1])[0]);
            exit;
        }
    }
    echo 'OK';
} else {
    echo 'NO PING';
}

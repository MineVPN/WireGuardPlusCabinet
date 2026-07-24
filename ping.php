<?php
/**
 * WGPlus — Ping endpoint
 *
 * GET-параметры:
 *   host  — IPv4 или hostname
 *   iface — опционально: 'wg1' (через туннель) или 'nic' (напрямую через WAN).
 *           Позволяет сравнить маршруты и понять, где рвётся цепочка.
 *
 * Безопасность (было RCE — $host уходил в exec() без фильтрации и БЕЗ авторизации):
 *   • проверка сессии (403 без неё)
 *   • host валидируется как IPv4 или RFC-1123 hostname
 *   • имя интерфейса берётся из whitelist, а не из пользовательского ввода
 *   • escapeshellarg() на всё, что попадает в командную строку
 */

require_once __DIR__ . '/includes/wgp_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (wgp_session_invalid_reason() !== '') {
    http_response_code(403);
    die('Unauthorized');
}
$_SESSION['last_activity'] = time();

$host  = trim($_GET['host'] ?? '');
$ifaceParam = trim($_GET['iface'] ?? '');

if ($host === '') {
    die('NO PING');
}

// Разрешаем только IPv4 или валидный hostname. Всё остальное — отказ.
$isIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
$isHostname = strlen($host) <= 255
              && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]{0,253}[a-zA-Z0-9])?$/', $host) === 1;
if (!$isIp && !$isHostname) {
    die('NO PING');
}

// Интерфейс НЕ берём из ввода напрямую — только фиксированный выбор.
$pingVia = '';
if ($ifaceParam === 'wg1') {
    $pingVia = 'wg1';
} elseif ($ifaceParam === 'nic') {
    // Общий хелпер: NIC.txt с валидацией и фолбэком на живое состояние ядра.
    $pingVia = wgp_wan_iface();
}

$cmd = 'ping -c 1 -W 1';
if ($pingVia !== '') {
    $cmd .= ' -I ' . escapeshellarg($pingVia);
}
$cmd .= ' ' . escapeshellarg($host);

exec($cmd, $output, $result);

if ($result === 0) {
    foreach ($output as $line) {
        if (strpos($line, 'time=') !== false) {
            $part = explode('time=', $line)[1];
            echo trim(explode(' ', $part)[0]);
            exit;
        }
    }
    // Ответ есть, но время не распарсилось — не считаем это провалом.
    echo 'OK';
} else {
    echo 'NO PING';
}

<?php
/**
 * WGPlus — проверка доступности адреса.
 *
 * GET:
 *   host  — IPv4 или имя хоста
 *   iface — пусто (как у клиента) | wg1 (через второй впн) | nic (напрямую)
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

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (wgp_session_invalid_reason() !== '') {
    http_response_code(403);
    die('NO PING');
}

// Сессия больше не нужна, а файловый обработчик держит на ней эксклюзивную
// блокировку до конца запроса. Страница «Пинг» шлёт запрос раз в секунду,
// каждый до секунды длиной — без этой строки запросы выстраиваются
// в очередь сами к себе и подвешивают остальные вкладки панели.
session_write_close();

$host  = is_string($_GET['host']  ?? null) ? trim($_GET['host'])  : '';
$which = is_string($_GET['iface'] ?? null) ? trim($_GET['iface']) : '';

if ($host === '') die('NO PING');

// IPv6 принимаем наравне с IPv4: Endpoint второго впн вполне может быть
// IPv6-литералом, и живой индикатор на странице «Подключение» иначе
// вечно показывал бы «не отвечает».
$isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
$isHost = strlen($host) <= 255
       && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]{0,253}[a-zA-Z0-9])?$/', $host) === 1;
if (!$isIp && !$isHost) die('NO PING');

// Интерфейс не берём из ввода напрямую — только фиксированный выбор.
//
// ПО УМОЛЧАНИЮ пингуем С АДРЕСА ШЛЮЗА (10.55.55.1 или другой —
// берётся из wg0.conf). Это воспроизводит путь конечного клиента:
// адрес шлюза лежит в клиентской подсети, поэтому пакет попадает
// под те же правила policy routing, что и трафик клиентов:
//
//   • есть второй впн  → from <подсеть> table 120 → уходит в туннель
//   • второго впн нет → таблица 120 пуста → провал в main → напрямую
//   • адрес в обходе    → to <адрес> table main preference 30000
//                        срабатывает раньше (30000 < 32765) → напрямую
//
// Раньше без -I пинг шёл с дефолтного маршрута СЕРВЕРА — всегда
// напрямую через NIC, что не отражало реальность клиента.
$via = '';
if ($which === 'wg1') {
    $via = 'wg1';                       // жёстко через туннель
} elseif ($which === 'nic') {
    $via = wgp_wan_iface();             // жёстко напрямую
} else {
    $net = wgp_wg0_net();
    $via = $net['gw'];                  // как у клиента
}

// Для IPv6-адреса нужен ping6 (или ping -6): обычный ping его не примет.
$isIp6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
$cmd = $isIp6 ? 'ping -6 -c 1 -W 1' : 'ping -c 1 -W 1';
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

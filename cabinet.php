<?php
/**
 * WGPlus — каркас панели: боковое меню + рабочая область.
 *
 * Хелперы подключаются ДО session_start(): в них выставляются параметры
 * cookie сессии, после старта их уже не применить.
 */

require_once __DIR__ . '/includes/wgp_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$invalid = wgp_session_invalid_reason();
if ($invalid !== '') {
    wgp_session_kill($invalid);
}

// CSRF проверяем ДО включения страницы, чтобы ни один обработчик POST
// не успел ничего сделать. Все формы шлют POST сюда же.
wgp_csrf_require();

if (isset($_POST['menu'])) {
    $_GET['menu'] = $_POST['menu'];
}

$pages = [
    'tunnel' => ['file' => 'pages/tunnel.php', 'title' => 'Туннель'],
    'route'  => ['file' => 'pages/route.php',  'title' => 'Обход VPN'],
    'ping'   => ['file' => 'pages/ping.php',   'title' => 'Пинг'],
    'logs'   => ['file' => 'pages/logs.php',   'title' => 'События'],
];

$menu = $_GET['menu'] ?? 'tunnel';
if (!array_key_exists($menu, $pages)) $menu = 'tunnel';

// Версия для сброса кеша браузера при обновлении стилей.
$v = '2.1';

function nav_item(string $key, string $current, string $title, string $icon): void {
    $on = $key === $current ? ' navlink--on' : '';
    echo '<a class="navlink' . $on . '" href="cabinet.php?menu=' . $key . '">'
       . $icon . '<span>' . htmlspecialchars($title) . '</span></a>';
}

$icoTunnel = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h4"/><path d="M16 12h4"/><circle cx="12" cy="12" r="3.2"/></svg>';
$icoRoute  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="18" r="2.4"/><circle cx="18" cy="6" r="2.4"/><path d="M8.4 18H14a4 4 0 0 0 0-8H9"/></svg>';
$icoPing   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2.5-6 5 12L17 12h4"/></svg>';
$icoLogs   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h14v16H5z"/><path d="M9 9h6M9 13h6M9 17h3"/></svg>';
$icoExit   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17l5-5-5-5"/><path d="M20 12H9"/><path d="M12 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h6"/></svg>';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pages[$menu]['title']) ?> — WGPlus</title>
<link rel="icon" type="image/png" href="assets/img/favicon.png">
<link rel="stylesheet" href="assets/css/tokens.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/base.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/layout.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/components.css?v=<?= $v ?>">
</head>
<body>

<div class="shell">
  <aside class="side">
    <a class="side__brand" href="cabinet.php">
      <img class="side__logo" src="assets/img/logo.png" alt="">
      <span>
        <span class="side__name">WGPlus</span><br>
        <span class="side__sub">цепочка туннелей</span>
      </span>
    </a>
    <nav class="side__nav">
      <?php
        nav_item('tunnel', $menu, 'Туннель',   $icoTunnel);
        nav_item('route',  $menu, 'Обход VPN', $icoRoute);
        nav_item('ping',   $menu, 'Пинг',      $icoPing);
        nav_item('logs',   $menu, 'События',   $icoLogs);
      ?>
    </nav>

    <div class="side__foot">
      <a class="navlink navlink--exit" href="logout.php"><?= $icoExit ?><span>Выйти</span></a>
    </div>
  </aside>

  <main class="main">
    <?php include __DIR__ . '/' . $pages[$menu]['file']; ?>
  </main>
</div>

</body>
</html>

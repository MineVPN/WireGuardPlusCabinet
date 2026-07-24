<?php
/**
 * WireGuard+ — каркас панели: боковое меню + рабочая область.
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
    'tunnel' => ['file' => 'pages/tunnel.php', 'title' => 'Подключение'],
    'route'  => ['file' => 'pages/route.php',  'title' => 'Обход VPN'],
    'ping'   => ['file' => 'pages/ping.php',   'title' => 'Пинг'],
    'logs'   => ['file' => 'pages/logs.php',   'title' => 'События'],
    'help'   => ['file' => 'pages/help.php',   'title' => 'Инструкция'],
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
// Иконка обхода: поток раздваивается на два пути со стрелками —
// часть трафика идёт мимо туннеля. Прежняя из двух кружков и дуги
// не читалась: линии не сходились и выглядели оборванными.
$icoRoute  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h4c3.5 0 4.5 10 8 10h3"/><path d="M3 17h4c1.6 0 2.6-2 3.4-4"/><path d="M15 4l3 3-3 3"/><path d="M15 14l3 3-3 3"/></svg>';
$icoPing   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2.5-6 5 12L17 12h4"/></svg>';
$icoLogs   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h14v16H5z"/><path d="M9 9h6M9 13h6M9 17h3"/></svg>';
// Инструкция — знак вопроса в круге: узнаваемый символ помощи.
$icoHelp   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.2 9.3a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4.2"/><path d="M12 17.5h.01"/></svg>';
$icoExit   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17l5-5-5-5"/><path d="M20 12H9"/><path d="M12 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h6"/></svg>';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pages[$menu]['title']) ?> — WireGuard+</title>
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
        <span class="side__name">WireGuard+</span><br>
        <span class="side__sub">двойной впн</span>
      </span>
    </a>
    <nav class="side__nav">
      <?php
        nav_item('tunnel', $menu, 'Подключение', $icoTunnel);
        nav_item('route',  $menu, 'Обход VPN',   $icoRoute);
        nav_item('ping',   $menu, 'Пинг',        $icoPing);
        nav_item('logs',   $menu, 'События',     $icoLogs);
        nav_item('help',   $menu, 'Инструкция',  $icoHelp);
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

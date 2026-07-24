<?php
/**
 * WGPlus — страница «Обход VPN».
 *
 * Адреса из этого списка идут напрямую через сервер, минуя туннель
 * провайдера. Правило применяется сразу и дублируется в wg0.conf,
 * чтобы пережить перезагрузку.
 */

require_once __DIR__ . '/../includes/wgp_helpers.php';

wgp_require_auth();
wgp_csrf_require();

$wg0Conf    = WGP_WG0_CONF;
$routesFile = WGP_ROUTES_FILE;
$pref       = 30000;
$net        = wgp_wg0_net();
$notice     = '';
$noticeKind = 'ok';

/** Атомарная запись списка. Каталог обязан быть доступен на запись. */
function wgp_saveRoutes(array $routes, string $file): bool {
    $dir = dirname($file);
    if (!is_dir($dir) || !is_writable($dir)) {
        wgp_log('ERR', "Нет доступа на запись в $dir — список обхода не сохранён");
        return false;
    }
    $tmp  = $file . '.tmp';
    $data = $routes ? implode(PHP_EOL, $routes) . PHP_EOL : '';
    if (@file_put_contents($tmp, $data) === false) {
        wgp_log('ERR', "Не удалось записать $tmp");
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        wgp_log('ERR', "Не удалось обновить $file");
        return false;
    }
    @chmod($file, 0664);
    return true;
}

function wgp_addBypass(string $ip, int $pref, string $conf): void {
    $up   = "PostUp = ip rule add to $ip table main preference $pref";
    $down = "PostDown = ip rule del to $ip table main preference $pref";
    $c = @file_get_contents($conf);
    if ($c === false || strpos($c, $up) !== false) return;
    $c = preg_replace('/(\[Interface\]\s*\r?\n)/', "$1$up\n$down\n", $c, 1);
    @file_put_contents($conf, $c);
}

function wgp_delBypass(string $ip, int $pref, string $conf): void {
    $up   = "PostUp = ip rule add to $ip table main preference $pref";
    $down = "PostDown = ip rule del to $ip table main preference $pref";
    $lines = @file($conf, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return;
    $kept = [];
    foreach ($lines as $l) {
        $t = trim($l);
        if ($t !== trim($up) && $t !== trim($down)) $kept[] = $l;
    }
    @file_put_contents($conf, implode(PHP_EOL, $kept) . PHP_EOL);
}

$routes = file_exists($routesFile)
    ? (file($routesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
    : [];

// ── Добавление ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ip'])) {
    $ip = trim($_POST['add_ip']);

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        $notice = 'Введите корректный IPv4-адрес';
        $noticeKind = 'err';

    } elseif (in_array($ip, $routes, true)) {
        $notice = "Адрес $ip уже в списке";
        $noticeKind = 'err';

    } else {
        $routes[] = $ip;
        // Сохраняем ПЕРВЫМ и проверяем результат: если запись не удалась,
        // показать «успешно» было бы враньём.
        if (!wgp_saveRoutes($routes, $routesFile)) {
            $notice = 'Не удалось сохранить список. Проверьте права на ' . WGP_DATA_DIR;
            $noticeKind = 'err';
            array_pop($routes);
        } else {
            exec('sudo ip rule add to ' . escapeshellarg($ip) . " table main preference $pref");
            wgp_addBypass($ip, $pref, $wg0Conf);
            wgp_log('OK', "Добавлен обход VPN для $ip");
            header('Location: cabinet.php?menu=route'); exit();
        }
    }
}

// ── Удаление ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_ip'])) {
    $ip = trim($_POST['del_ip']);
    if (in_array($ip, $routes, true)
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {

        exec('sudo ip rule del to ' . escapeshellarg($ip) . " table main preference $pref");
        wgp_delBypass($ip, $pref, $wg0Conf);
        $routes = array_values(array_filter($routes, fn($r) => $r !== $ip));

        if (!wgp_saveRoutes($routes, $routesFile)) {
            $notice = 'Правило снято, но список не сохранён. Проверьте права на ' . WGP_DATA_DIR;
            $noticeKind = 'err';
        } else {
            wgp_log('OK', "Убран обход VPN для $ip");
            header('Location: cabinet.php?menu=route'); exit();
        }
    }
}
?>

<?php if ($notice !== ''): ?>
  <div class="notice notice--<?= $noticeKind ?>"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<div class="page-head">
  <div class="page-head__title"><h1>Обход VPN</h1></div>
  <p class="page-head__note">
    Трафик на эти адреса пойдёт напрямую через сервер, минуя туннель провайдера.
    Обычно сюда добавляют банки и сервисы, которые блокируют доступ из чужой страны.
  </p>
</div>

<div class="stack">

  <div class="card">
    <div class="card__head">
      <h2 class="card__title">Добавить адрес</h2>
    </div>

    <form method="post" class="row">
      <?= wgp_csrf_field() ?>
      <input type="hidden" name="menu" value="route">
      <input type="text" name="add_ip" class="input grow" required
             placeholder="например 1.1.1.1"
             pattern="^(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])$"
             title="IPv4-адрес, четыре числа через точку">
      <button type="submit" class="btn btn--primary">Добавить</button>
    </form>
  </div>

  <div class="card">
    <div class="card__head">
      <h2 class="card__title">Список</h2>
      <span class="dim data"><?= count($routes) ?></span>
    </div>

    <?php if (!$routes): ?>
      <div class="empty">
        Пока пусто. Добавьте адрес выше — трафик к нему пойдёт мимо туннеля.
      </div>
    <?php else: ?>
      <div class="items">
        <?php foreach ($routes as $r): ?>
          <div class="item">
            <span class="item__v"><?= htmlspecialchars($r) ?></span>
            <form method="post" style="margin:0">
              <?= wgp_csrf_field() ?>
              <input type="hidden" name="menu" value="route">
              <input type="hidden" name="del_ip" value="<?= htmlspecialchars($r) ?>">
              <button type="submit" class="btn btn--danger btn--sm">Убрать</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
/**
 * WGPlus — страница «Обход VPN».
 *
 * Адреса из этого списка идут напрямую через сервер, минуя туннель
 * второго впн. Список — единственный источник правды; правила
 * маршрутизации и цепочку iptables по нему строит healthcheck-демон,
 * он же восстанавливает их после перезагрузки.
 */

require_once __DIR__ . '/../includes/wgp_helpers.php';

wgp_require_auth();
wgp_csrf_require();

$routesFile = WGP_ROUTES_FILE;
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

/*
 * ПАНЕЛЬ БОЛЬШЕ НЕ ТРОГАЕТ НИ wg0.conf, НИ ПРАВИЛА МАРШРУТИЗАЦИИ.
 *
 * Раньше здесь было три функции: две дописывали в wg0.conf строки
 * PostUp/PostDown для сохранения обхода между перезагрузками, третья
 * перезаписывала файл. Всё это убрано, потому что:
 *
 *   • wg0.conf содержит приватный ключ сервера и всех пиров. Каждое
 *     изменение списка обхода переписывало живой файл целиком — обрыв
 *     PHP посреди записи стоил бы полной переустановки сервера.
 *
 *   • Немедленное применение шло через sudo, для чего в sudoers стоял
 *     шаблон 'ip rule add to * …'. Звёздочка у sudo матчит и слэш,
 *     поэтому 'to 0.0.0.0/0' проходил проверку и уводил весь трафик
 *     клиентов мимо цепочки.
 *
 * Теперь единственный источник правды — routes.txt, а применяет его
 * healthcheck-демон: и цепочку iptables, и правила ip rule. Он же
 * восстанавливает их после перезагрузки, так что дублировать состояние
 * в конфиг больше не нужно.
 */

$routes = file_exists($routesFile)
    ? (file($routesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
    : [];

// ── Добавление ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ip'])) {
    // is_string обязателен: add_ip[]=x даёт TypeError в trim() и 500
    // посреди уже отрисованной страницы.
    $ip = is_string($_POST['add_ip']) ? trim($_POST['add_ip']) : '';

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        $notice = 'Введите корректный IPv4-адрес';
        $noticeKind = 'err';

    } elseif (wgp_is_probe_host($ip)) {
        // Этими адресами демон проверяет, жив ли туннель. Если пустить их
        // мимо туннеля — проверка будет успешна всегда, и падения второго
        // впн перестанут замечаться вовсе.
        // Без htmlspecialchars: экранирование делается один раз при выводе
        // ($notice проходит через htmlspecialchars в разметке ниже).
        $notice = 'Адрес ' . $ip . ' используется для проверки связи и в обход не добавляется. '
                . 'Иначе сервер перестанет замечать падения второго впн. '
                . 'Для DNS укажите другой сервер, например 77.88.8.8 или 208.67.222.222.';
        $noticeKind = 'err';
        wgp_log('WARN', "Отклонён обход для $ip — это проверочный адрес демона");

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
            // Применяет демон в течение нескольких секунд — см. комментарий
            // в начале файла о том, почему панель этого больше не делает.
            wgp_log('OK', "Добавлен обход второго впн для $ip");
            header('Location: cabinet.php?menu=route'); exit();
        }
    }
}

// ── Удаление ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_ip'])) {
    $ip = is_string($_POST['del_ip']) ? trim($_POST['del_ip']) : '';
    if (in_array($ip, $routes, true)
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {

        $routes = array_values(array_filter($routes, fn($r) => $r !== $ip));

        if (!wgp_saveRoutes($routes, $routesFile)) {
            $notice = 'Правило снято, но список не сохранён. Проверьте права на ' . WGP_DATA_DIR;
            $noticeKind = 'err';
        } else {
            wgp_log('OK', "Убран обход второго впн для $ip");
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
    Трафик на эти адреса пойдёт напрямую через промежуточный сервер, минуя туннель второго впн.
    Обычно сюда добавляют телефонии и другие сервисы, которым нужна минимальная задержка.
    Изменения применяются в течение нескольких секунд.
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
             placeholder="например 91.219.29.10"
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
        Пока пусто. Добавьте адрес выше — трафик к нему пойдёт мимо второго впн.
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

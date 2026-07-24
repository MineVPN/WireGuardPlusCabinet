<?php
/**
 * WGPlus — страница «Туннель».
 *
 * Показывает адрес второго VPN, к которому подключён сервер, живой
 * пинг до него и состояние. Одна кнопка удаления: сначала снимает
 * туннель, потом удаляет конфиг.
 */

require_once __DIR__ . '/../includes/wgp_helpers.php';

wgp_require_auth();
wgp_csrf_require();

$net = wgp_wg0_net();

$hasConfig  = file_exists(WGP_WG1_CONF);
$host       = '';   // адрес второго VPN
$port       = '';
$up         = wgp_iface_exists();
$notice     = '';
$noticeKind = 'ok';

if ($hasConfig) {
    $conf = @file_get_contents(WGP_WG1_CONF);
    if ($conf !== false && preg_match('/^\s*Endpoint\s*=\s*([\w\.\-]+):(\d+)/m', $conf, $m)) {
        $host = $m[1];
        $port = $m[2];
    }
}

// ══════════════════════════════════════════════════════════════
// ПОДГОТОВКА КОНФИГА
// ══════════════════════════════════════════════════════════════

/**
 * Готовит загруженный конфиг к работе в цепочке.
 * 1. Вычищает служебные директивы из исходника — иначе при повторной
 *    загрузке уже обработанного файла правила задвоятся.
 * 2. Вставляет маршрутизацию под РЕАЛЬНУЮ подсеть wg0.
 * 3. Добавляет PersistentKeepalive — держит туннель живым через NAT.
 */
function wgp_prepare_config(string $raw, array $net): string {
    $clean = preg_replace('/^\s*(Table|PostUp|PostDown|PreUp|PreDown)\s*=.*$\r?\n?/mi', '', $raw);

    $tbl    = WGP_TABLE_ID;
    $cidr   = $net['cidr'];

    // ИДЕМПОТЕНТНЫЕ PostUp — вторая линия обороны.
    //
    // 'ip rule add' падает с "File exists", если правило уже есть — а wg-quick
    // работает с set -e и обрывается на первой же ошибке. Любой остаток от
    // прошлой установки делал загрузку даже рабочего конфига невозможной.
    //
    // Сначала чистим (если есть), потом добавляем. Для маршрута — 'replace',
    // она идемпотентна по определению.
    // '|| true' обязателен: 'ip rule del' без существующего правила — тоже ошибка.
    $inject = "Table = off\n"
            . "PostUp = ip rule del from {$cidr} table {$tbl} preference 32765 2>/dev/null || true\n"
            . "PostUp = ip rule add from {$cidr} table {$tbl} preference 32765\n"
            . "PostUp = ip route replace default dev %i table {$tbl}\n"
            . "PostDown = ip rule del from {$cidr} table {$tbl} preference 32765 2>/dev/null || true\n"
            . "PostDown = ip route flush table {$tbl} 2>/dev/null || true";

    $out = preg_replace('/(\[Interface\]\s*\r?\n)/i', "$1{$inject}\n", $clean, 1);

    if (!preg_match('/^\s*PersistentKeepalive\s*=/mi', $out) && preg_match('/\[Peer\]/i', $out)) {
        $out = rtrim($out) . "\nPersistentKeepalive = 25\n";
    }
    return $out;
}

function wgp_start_tunnel(): bool {
    shell_exec('sudo systemctl enable wg-quick@wg1 2>&1');
    shell_exec('sudo systemctl start wg-quick@wg1 2>&1');
    return wgp_wait_up(10);
}

/**
 * Проверяет, не пересекается ли адресация второго впн с подсетью клиентов.
 *
 * ЗАЧЕМ: любая адресация второго впн работает — кроме случая, когда она
 * перекрывается с подсетью наших клиентов. Ядро создаёт connected route
 * на каждый адрес интерфейса, и в main-таблице появляются ДВА одинаковых
 * маршрута:
 *
 *   10.55.55.0/24 dev wg0   ← наши клиенты
 *   10.55.55.0/24 dev wg1   ← адрес от второго впн
 *
 * Какой из них выберет ядро — вопрос порядка добавления. Сразу после
 * загрузки обычно выигрывает wg0 и всё работает, но после перезагрузки
 * или перезапуска порядок может смениться — и клиенты станут недоступны
 * без понятной причины. Лучше отклонить сразу с объяснением.
 *
 * @return string '' если конфликта нет, иначе проблемный адрес
 */
function wgp_addr_conflict(string $raw, array $net): string {
    // Берём все IPv4-адреса из Address — их может быть несколько
    // через запятую, плюс рядом может стоять IPv6 — его игнорируем.
    if (!preg_match_all('/^\s*Address\s*=\s*(.+)$/mi', $raw, $lines)) return '';

    $ourNet  = ip2long($net['network']);
    $ourPfx  = (int) $net['prefix'];

    foreach ($lines[1] as $line) {
        foreach (explode(',', $line) as $part) {
            $part = trim($part);
            if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?:\/(\d{1,2}))?$/', $part, $m)) continue;

            $ip  = $m[1];
            $pfx = isset($m[2]) ? (int) $m[2] : 32;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) continue;
            if ($pfx < 1 || $pfx > 32) continue;

            // Две сети пересекаются, если под более широкой маской
            // их адреса сети совпадают.
            $common = min($pfx, $ourPfx);
            $mask   = (0xFFFFFFFF << (32 - $common)) & 0xFFFFFFFF;
            if (((ip2long($ip) & $mask) & 0xFFFFFFFF) === (($ourNet & $mask) & 0xFFFFFFFF)) {
                return $ip . '/' . $pfx;
            }
        }
    }
    return '';
}

// ══════════════════════════════════════════════════════════════
// ДЕЙСТВИЯ
// ══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['start'])) {
        wgp_state_set('busy');
        wgp_log('INFO', 'Запуск туннеля из панели');
        $ok = wgp_start_tunnel();
        // Ставим 'running' даже при неудаче: это НАМЕРЕНИЕ (туннель должен
        // работать), а не факт. Со статусом 'stopped' демон полностью
        // устраняется — и если второй VPN был недоступен ровно в эту секунду,
        // туннель никогда бы не поднялся без повторного нажатия вручную.
        wgp_state_set('running');
        wgp_log($ok ? 'OK' : 'WARN', $ok
            ? 'Туннель поднят'
            : 'Туннель пока не поднялся — мониторинг продолжит попытки');
        header('Location: cabinet.php?menu=tunnel'); exit();
    }

    if (isset($_POST['restart'])) {
        wgp_state_set('busy');
        wgp_log('INFO', 'Перезапуск туннеля из панели');
        wgp_bring_down();
        $ok = wgp_start_tunnel();
        // Тот же принцип: конфиг на месте и пользователь хочет связь —
        // значит демон обязан добиваться результата.
        wgp_state_set('running');
        wgp_log($ok ? 'OK' : 'WARN', $ok
            ? 'Туннель перезапущен'
            : 'Туннель пока не поднялся — мониторинг продолжит попытки');
        header('Location: cabinet.php?menu=tunnel'); exit();
    }

    if (isset($_POST['remove'])) {
        // ПОРЯДОК ВАЖЕН: сначала снимаем туннель, ПОТОМ удаляем конфиг.
        //
        // wg-quick down ЧИТАЕТ wg1.conf, чтобы узнать свои PostDown. Если
        // удалить конфиг раньше, он не найдёт файл, PostDown не выполнятся,
        // и правило 'ip rule from <подсеть> table 120' останется висеть навсегда.
        // Следующая загрузка конфига тогда падает: PostUp делает 'ip rule add'
        // → "File exists" → wg-quick работает с set -e и обрывается.
        //
        // Раньше конфиг удалялся первым — чтобы демон не воскресил туннель
        // между шагами. Сейчас это не нужно: флаг busy держит демона в стороне.
        wgp_state_set('busy');
        wgp_log('INFO', 'Отключение и удаление конфига второго VPN');

        // 1. Снимаем туннель, пока конфиг НА МЕСТЕ — PostDown отработает
        //    и сам уберёт правила маршрутизации.
        $down = wgp_bring_down();

        // 2. Теперь убираем автозапуск и сам конфиг.
        shell_exec('sudo systemctl disable wg-quick@wg1 2>&1');
        if (file_exists(WGP_WG1_CONF)) {
            @copy(WGP_WG1_CONF, WGP_WG1_BAK);
            shell_exec('sudo rm /etc/wireguard/wg1.conf 2>&1');
        }

        wgp_state_set('stopped');
        wgp_log($down ? 'OK' : 'ERR', $down
            ? 'Конфиг удалён, туннель снят. Клиенты выходят напрямую через сервер'
            : 'Конфиг удалён, но интерфейс снять не удалось');
        header('Location: cabinet.php?menu=tunnel'); exit();
    }

    if (isset($_FILES['config']) && !empty($_FILES['config']['name'])) {
        $ext = strtolower(pathinfo($_FILES['config']['name'], PATHINFO_EXTENSION));

        if ($ext !== 'conf') {
            $notice = 'Нужен файл с расширением .conf';
            $noticeKind = 'err';
            wgp_log('WARN', "Отклонён файл с расширением .$ext");

        } elseif (($_FILES['config']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $notice = 'Файл не загрузился. Попробуйте ещё раз';
            $noticeKind = 'err';

        } else {
            $raw = @file_get_contents($_FILES['config']['tmp_name']);

            if ($raw === false || stripos($raw, '[Interface]') === false
                || !preg_match('/^\s*PrivateKey\s*=/mi', $raw)) {
                $notice = 'Это не конфиг WireGuard: нет секции [Interface] или PrivateKey';
                $noticeKind = 'err';
                wgp_log('WARN', 'Отклонён невалидный впн конфиг');

            } elseif (($bad = wgp_addr_conflict($raw, $net)) !== '') {
                // Ловим ДО записи: иначе туннель поднимется и будет работать,
                // а сломается позже — после перезагрузки, без видимой связи с конфигом.
                $notice = 'Конфиг не подходит: адрес ' . htmlspecialchars($bad)
                        . ' пересекается с подсетью ваших клиентов ' . htmlspecialchars($net['cidr'])
                        . '. Попросите у продавца второго впн конфиг с другой адресацией.';
                $noticeKind = 'err';
                wgp_log('WARN', "Отклонён впн конфиг: адрес $bad пересекается с подсетью клиентов " . $net['cidr']);

            } else {
                wgp_state_set('busy');
                wgp_log('INFO', 'Установка впн конфига, подсеть клиентов ' . $net['cidr']);

                $hadPrevious = file_exists(WGP_WG1_CONF);
                if ($hadPrevious) @copy(WGP_WG1_CONF, WGP_WG1_BAK);

                wgp_bring_down();
                if (file_exists(WGP_WG1_CONF)) shell_exec('sudo rm /etc/wireguard/wg1.conf 2>&1');

                if (@file_put_contents(WGP_WG1_CONF, wgp_prepare_config($raw, $net)) === false) {
                    wgp_state_set('stopped');
                    $notice = 'Не удалось записать конфиг. Проверьте права на /etc/wireguard/';
                    $noticeKind = 'err';
                    wgp_log('ERR', 'Не удалось записать впн конфиг');

                } else {
                    @chmod(WGP_WG1_CONF, 0660);
                    // Остаточный интерфейс уронит запуск с «File exists».
                    if (wgp_iface_exists()) {
                        shell_exec('sudo ip link delete dev wg1 2>&1');
                        usleep(500000);
                    }

                    if (wgp_start_tunnel()) {
                        wgp_state_set('running');
                        wgp_log('OK', 'Впн конфиг установлен, туннель поднят');
                        header('Location: cabinet.php?menu=tunnel'); exit();
                    }

                    // Откат: новый конфиг не поднялся.
                    wgp_log('ERR', 'Новый впн конфиг не поднялся');
                    wgp_bring_down();

                    if ($hadPrevious && file_exists(WGP_WG1_BAK)) {
                        @copy(WGP_WG1_BAK, WGP_WG1_CONF);
                        @chmod(WGP_WG1_CONF, 0660);
                        $back = wgp_start_tunnel();
                        // Конфиг на месте — значит демон должен продолжать попытки,
                        // даже если откат с первого раза не сработал.
                        wgp_state_set('running');
                        wgp_log($back ? 'OK' : 'ERR', $back
                            ? 'Вернули предыдущий рабочий конфиг'
                            : 'Откат не помог, туннель не поднимается');
                        $notice = $back
                            ? 'Новый конфиг не поднялся — вернули предыдущий'
                            : 'Новый конфиг не поднялся, и откат не помог. Проверьте сеть сервера';
                    } else {
                        if (file_exists(WGP_WG1_CONF)) shell_exec('sudo rm /etc/wireguard/wg1.conf 2>&1');
                        wgp_state_set('stopped');
                        $notice = 'Туннель не поднялся. Проверьте Endpoint, ключи и доступность второго впн';
                    }
                    $noticeKind = 'err';
                }
            }
        }
        $hasConfig = file_exists(WGP_WG1_CONF);
        $up        = wgp_iface_exists();
        if ($hasConfig) {
            $conf = @file_get_contents(WGP_WG1_CONF);
            if ($conf !== false && preg_match('/^\s*Endpoint\s*=\s*([\w\.\-]+):(\d+)/m', $conf, $m)) {
                $host = $m[1]; $port = $m[2];
            }
        } else { $host = ''; $port = ''; }
    }
}

// ── Состояние для отрисовки ────────────────────────────────────
if (!$hasConfig) {
    $stateKind = 'off';
    $stateText = 'Не настроено';
    $stateNote = 'Сейчас сервер работает как обычный WireGuard. Клиенты выходят в интернет с адреса самого сервера. Загрузите впн конфиг, чтобы включить двойной впн.';
} elseif ($up) {
    $stateKind = 'ok';
    $stateText = 'Подключено';
    $stateNote = 'Трафик клиентов идёт через второй впн. Выход мимо туннеля закрыт.';
} else {
    $stateKind = 'err';
    $stateText = 'Нет связи';
    $stateNote = 'Впн конфиг загружен, но соединение не установлено. Интернета у клиентов сейчас нет — иначе трафик пошёл бы с адреса сервера.';
}
?>

<?php if ($notice !== ''): ?>
  <div class="notice notice--<?= $noticeKind ?>"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<div class="page-head">
  <div class="page-head__title">
    <h1>Подключение</h1>
    <span class="badge badge--<?= $stateKind ?>"><?= htmlspecialchars($stateText) ?></span>
  </div>
  <p class="page-head__note"><?= htmlspecialchars($stateNote) ?></p>
</div>

<div class="grid-2">

  <!-- ══ Состояние ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Состояние</h2></div>

    <div class="hero">
      <div class="hero__k">Адрес второго VPN</div>
      <div class="hero__v"><?= $host !== '' ? htmlspecialchars($host) : '—' ?></div>
      <div class="hero__meta">
        <?php if ($host === ''): ?>
          <span class="dot dot--off"></span> конфиг не загружен
        <?php else: ?>
          <span class="dot dot--<?= $up ? 'ok' : 'err' ?>" id="ping-dot"></span>
          <span id="ping-text">проверяем отклик…</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="facts" style="margin-top: var(--s-5)">
      <div class="fact">
        <span class="fact__k">Порт</span>
        <span class="fact__v"><?= $port !== '' ? htmlspecialchars($port) : '—' ?></span>
      </div>
      <div class="fact">
        <span class="fact__k">Соединение</span>
        <span class="fact__v"><?= $up ? 'активно' : 'нет' ?></span>
      </div>
      <div class="fact">
        <span class="fact__k">Подсеть клиентов</span>
        <span class="fact__v"><?= htmlspecialchars($net['cidr']) ?></span>
      </div>
    </div>

    <form method="post" style="margin-top: var(--s-6); display: flex; flex-direction: column; gap: var(--s-3)">
      <?= wgp_csrf_field() ?>
      <input type="hidden" name="menu" value="tunnel">

      <?php if (!$hasConfig): ?>
        <button class="btn btn--block" disabled>Загрузите второй VPN конфиг</button>
      <?php else: ?>
        <?php if (!$up): ?>
          <button type="submit" name="start" class="btn btn--ok btn--block">Подключить</button>
        <?php else: ?>
          <button type="submit" name="restart" class="btn btn--block">Переподключить</button>
        <?php endif; ?>
        <button type="submit" name="remove" class="btn btn--danger btn--block"
                onclick="return confirm('Отключить туннель и удалить впн конфиг?')">
          Отключить и удалить конфиг
        </button>
      <?php endif; ?>
    </form>
  </div>

  <!-- ══ Загрузка ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Второй VPN</h2></div>

    <form method="post" enctype="multipart/form-data"
          style="display: flex; flex-direction: column; gap: var(--s-4)">
      <?= wgp_csrf_field() ?>
      <input type="hidden" name="menu" value="tunnel">

      <label class="drop" id="drop" for="config">
        <span class="drop__main" id="drop-main">Перетащите файл или нажмите</span>
        <span class="drop__sub">файл .conf от второго VPN</span>
        <input type="file" id="config" name="config" accept=".conf" hidden>
      </label>

      <button type="submit" class="btn btn--primary btn--block">Установить и подключить</button>
    </form>

    <p class="card__hint" style="margin-top: var(--s-4)">
      Маршрутизация подставится сама под подсеть
      <span class="data"><?= htmlspecialchars($net['cidr']) ?></span>.
      Если новый конфиг не поднимется, вернётся предыдущий.
    </p>
  </div>
</div>

<script>
(function () {
  /* ── Выбор файла ── */
  var drop = document.getElementById('drop'),
      input = document.getElementById('config'),
      main = document.getElementById('drop-main');

  function show(name) { main.className = 'drop__file'; main.textContent = name; }

  ['dragenter', 'dragover'].forEach(function (e) {
    drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.add('drop--hot'); });
  });
  ['dragleave', 'drop'].forEach(function (e) {
    drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.remove('drop--hot'); });
  });
  drop.addEventListener('drop', function (ev) {
    if (ev.dataTransfer.files.length) { input.files = ev.dataTransfer.files; show(ev.dataTransfer.files[0].name); }
  });
  input.addEventListener('change', function () {
    if (input.files.length) show(input.files[0].name);
  });

  /* ── Живой пинг до второго VPN ──
     Проверяем напрямую через сервер: его адрес обязан быть
     доступен именно так, иначе туннель к нему не построится. */
  var host = <?= json_encode($host, JSON_UNESCAPED_UNICODE) ?>;
  var dot = document.getElementById('ping-dot'),
      txt = document.getElementById('ping-text');
  if (!host || !dot) return;

  function check() {
    fetch('api/ping.php?iface=nic&host=' + encodeURIComponent(host),
          { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.text(); })
      .then(function (t) {
        var ms = parseFloat(t);
        if (t.indexOf('NO PING') === -1 && !isNaN(ms)) {
          dot.className = 'dot dot--ok';
          txt.textContent = 'отвечает за ' + ms.toFixed(0) + ' мс';
        } else if (t.trim() === 'OK') {
          dot.className = 'dot dot--ok';
          txt.textContent = 'отвечает';
        } else {
          dot.className = 'dot dot--err';
          txt.textContent = 'не отвечает';
        }
      })
      .catch(function () {
        dot.className = 'dot dot--warn';
        txt.textContent = 'не удалось проверить';
      });
  }

  check();
  setInterval(check, 10000);
})();
</script>

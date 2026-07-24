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
    $inject = "Table = off\n"
            . "PostUp = ip route add default dev %i table {$tbl}\n"
            . "PostUp = ip rule add from {$cidr} table {$tbl} preference 32765\n"
            . "PostDown = ip rule del from {$cidr} table {$tbl} preference 32765\n"
            . "PostDown = ip route del default dev %i table {$tbl}";

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

// ══════════════════════════════════════════════════════════════
// ДЕЙСТВИЯ
// ══════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['start'])) {
        wgp_state_set('busy');
        wgp_log('INFO', 'Запуск туннеля из панели');
        $ok = wgp_start_tunnel();
        wgp_state_set($ok ? 'running' : 'stopped');
        wgp_log($ok ? 'OK' : 'ERR', $ok ? 'Туннель поднят' : 'Туннель не поднялся');
        header('Location: cabinet.php?menu=tunnel'); exit();
    }

    if (isset($_POST['restart'])) {
        wgp_state_set('busy');
        wgp_log('INFO', 'Перезапуск туннеля из панели');
        wgp_bring_down();
        $ok = wgp_start_tunnel();
        wgp_state_set($ok ? 'running' : 'stopped');
        wgp_log($ok ? 'OK' : 'ERR', $ok ? 'Туннель перезапущен' : 'Туннель не поднялся после перезапуска');
        header('Location: cabinet.php?menu=tunnel'); exit();
    }

    if (isset($_POST['remove'])) {
        // Сначала снимаем туннель, потом удаляем конфиг.
        // Порядок важен: демон поднимает туннель только при наличии
        // конфига — без файла он его уже не воскресит между шагами.
        wgp_state_set('busy');
        wgp_log('INFO', 'Отключение и удаление конфига провайдера');
        shell_exec('sudo systemctl disable wg-quick@wg1 2>&1');
        shell_exec('sudo systemctl stop wg-quick@wg1 2>&1');
        if (file_exists(WGP_WG1_CONF)) {
            @copy(WGP_WG1_CONF, WGP_WG1_BAK);
            shell_exec('sudo rm /etc/wireguard/wg1.conf 2>&1');
        }
        $down = wgp_bring_down();
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
                wgp_log('WARN', 'Отклонён невалидный конфиг провайдера');

            } else {
                wgp_state_set('busy');
                wgp_log('INFO', 'Установка конфига провайдера, подсеть цепочки ' . $net['cidr']);

                $hadPrevious = file_exists(WGP_WG1_CONF);
                if ($hadPrevious) @copy(WGP_WG1_CONF, WGP_WG1_BAK);

                wgp_bring_down();
                if (file_exists(WGP_WG1_CONF)) shell_exec('sudo rm /etc/wireguard/wg1.conf 2>&1');

                if (@file_put_contents(WGP_WG1_CONF, wgp_prepare_config($raw, $net)) === false) {
                    wgp_state_set('stopped');
                    $notice = 'Не удалось записать конфиг. Проверьте права на /etc/wireguard/';
                    $noticeKind = 'err';
                    wgp_log('ERR', 'Не удалось записать конфиг провайдера');

                } else {
                    @chmod(WGP_WG1_CONF, 0660);
                    // Остаточный интерфейс уронит запуск с «File exists».
                    if (wgp_iface_exists()) {
                        shell_exec('sudo ip link delete dev wg1 2>&1');
                        usleep(500000);
                    }

                    if (wgp_start_tunnel()) {
                        wgp_state_set('running');
                        wgp_log('OK', 'Конфиг провайдера установлен, туннель поднят');
                        header('Location: cabinet.php?menu=tunnel'); exit();
                    }

                    // Откат: новый конфиг не поднялся.
                    wgp_log('ERR', 'Новый конфиг провайдера не поднялся');
                    wgp_bring_down();

                    if ($hadPrevious && file_exists(WGP_WG1_BAK)) {
                        @copy(WGP_WG1_BAK, WGP_WG1_CONF);
                        @chmod(WGP_WG1_CONF, 0660);
                        $back = wgp_start_tunnel();
                        wgp_state_set($back ? 'running' : 'stopped');
                        wgp_log($back ? 'OK' : 'ERR', $back
                            ? 'Вернули предыдущий рабочий конфиг'
                            : 'Откат не помог, туннель не поднимается');
                        $notice = $back
                            ? 'Новый конфиг не поднялся — вернули предыдущий'
                            : 'Новый конфиг не поднялся, и откат не помог. Проверьте сеть сервера';
                    } else {
                        if (file_exists(WGP_WG1_CONF)) shell_exec('sudo rm /etc/wireguard/wg1.conf 2>&1');
                        wgp_state_set('stopped');
                        $notice = 'Туннель не поднялся. Проверьте Endpoint, ключи и доступность провайдера';
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
    $stateText = 'Конфиг не загружен';
    $stateNote = 'Сейчас сервер работает как обычный WireGuard. Клиенты выходят в интернет с адреса самого сервера.';
} elseif ($up) {
    $stateKind = 'ok';
    $stateText = 'Подключено';
    $stateNote = 'Трафик клиентов идёт через провайдера. Выход мимо туннеля закрыт.';
} else {
    $stateKind = 'err';
    $stateText = 'Туннель не поднят';
    $stateNote = 'Конфиг есть, но туннель лежит. Интернета у клиентов сейчас нет — иначе трафик пошёл бы с адреса сервера.';
}
?>

<?php if ($notice !== ''): ?>
  <div class="notice notice--<?= $noticeKind ?>"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<div class="page-head">
  <div class="page-head__title">
    <h1>Туннель</h1>
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
        <span class="fact__k">Интерфейс wg1</span>
        <span class="fact__v"><?= $up ? 'поднят' : 'снят' ?></span>
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
                onclick="return confirm('Отключить туннель и удалить конфиг провайдера?')">
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

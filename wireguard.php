<?php
require_once __DIR__ . '/includes/wgp_helpers.php';

// Защита работает и при включении из cabinet.php, и при прямом запросе
// на /wireguard.php — раньше здесь не было НИКАКОЙ проверки.
wgp_require_auth();
wgp_csrf_require();

$net = wgp_wg0_net();   // подсеть клиентов — из wg0.conf, без хардкода

$type              = null;
$connection_status = 'disconnected';
$ip_address        = 'Не определен';
$config_type       = 'Нет';

// ── Текущее состояние ────────────────────────────────────────────
if (file_exists(WGP_WG1_CONF)) {
    $conf = @file_get_contents(WGP_WG1_CONF);
    if ($conf !== false && preg_match('/^\s*Endpoint\s*=\s*([\w\.\-]+):\d+/m', $conf, $m)) {
        $ip_address  = $m[1];
        $config_type = 'WireGuard';
        $type        = 'wireguard';
    }
}
if (wgp_iface_exists()) {
    $connection_status = 'connected';
}

// ══════════════════════════════════════════════════════════════════
// ПОДГОТОВКА КОНФИГА
// ══════════════════════════════════════════════════════════════════

/**
 * Готовит загруженный конфиг к работе в цепочке wg0 -> wg1.
 *
 * 1. Вычищает Table/PostUp/PostDown/PreUp/PreDown из исходника — иначе при
 *    повторной загрузке уже обработанного файла правила задвоятся.
 * 2. Вставляет policy routing под РЕАЛЬНУЮ подсеть wg0 (не под захардкоженную).
 * 3. Добавляет PersistentKeepalive если его нет — держит туннель живым через
 *    NAT провайдера и делает проверку по latest-handshake достоверной.
 */
function wgp_prepare_config(string $raw, array $net): string {
    // 1. Чистим служебные директивы wg-quick (они бывают только в [Interface]).
    $clean = preg_replace('/^\s*(Table|PostUp|PostDown|PreUp|PreDown)\s*=.*$\r?\n?/mi', '', $raw);

    // 2. Policy routing под фактическую подсеть.
    $tbl   = WGP_TABLE_ID;
    $cidr  = $net['cidr'];
    $inject = "Table = off\n"
            . "PostUp = ip route add default dev %i table {$tbl}\n"
            . "PostUp = ip rule add from {$cidr} table {$tbl}\n"
            . "PostDown = ip rule del from {$cidr} table {$tbl}\n"
            . "PostDown = ip route del default dev %i table {$tbl}";

    $result = preg_replace('/(\[Interface\]\s*\r?\n)/i', "$1{$inject}\n", $clean, 1);

    // 3. PersistentKeepalive — только если пользователь его не задал.
    if (!preg_match('/^\s*PersistentKeepalive\s*=/mi', $result)
        && preg_match('/\[Peer\]/i', $result)) {
        $result = rtrim($result) . "\nPersistentKeepalive = 25\n";
    }

    return $result;
}

/** Запуск wg1 с ожиданием. Возвращает true если поднялся. */
function wgp_start_tunnel(): bool {
    shell_exec('sudo systemctl enable wg-quick@wg1 2>&1');
    shell_exec('sudo systemctl start wg-quick@wg1 2>&1');
    return wgp_wait_up(10);
}

// ══════════════════════════════════════════════════════════════════
// ОБРАБОТКА ФОРМ
// ══════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Запуск ────────────────────────────────────────────────────
    if (isset($_POST['wireguard_start'])) {
        wgp_state_set('busy');
        wgp_log('INFO', 'Запуск wg1 по запросу из панели');
        $up = wgp_start_tunnel();
        wgp_state_set($up ? 'running' : 'stopped');
        wgp_event($up ? 'tunnel_up' : 'tunnel_fail', 'start');
        wgp_log($up ? 'OK' : 'ERR', $up ? 'wg1 запущен' : 'wg1 не поднялся при запуске');
        echo "<script>window.location = 'cabinet.php?menu=wireguard';</script>";
        exit();
    }

    // ── Перезапуск ────────────────────────────────────────────────
    if (isset($_POST['wireguard_restart'])) {
        wgp_state_set('busy');
        wgp_log('INFO', 'Перезапуск wg1 по запросу из панели');
        // Полное опускание + подъём: устраняет случай осиротевшего интерфейса,
        // который systemctl restart сам по себе не чинит.
        wgp_bring_down();
        $up = wgp_start_tunnel();
        wgp_state_set($up ? 'running' : 'stopped');
        wgp_event($up ? 'tunnel_up' : 'tunnel_fail', 'restart');
        wgp_log($up ? 'OK' : 'ERR', $up ? 'wg1 перезапущен' : 'wg1 не поднялся после перезапуска');
        echo "<script>window.location = 'cabinet.php?menu=wireguard';</script>";
        exit();
    }

    // ── Удаление конфига ──────────────────────────────────────────
    if (isset($_POST['wireguard_del'])) {
        // ПОРЯДОК ВАЖЕН — фикс гонки, из-за которой оставался висящий интерфейс:
        // 0) busy — daemon не вмешивается, пока идёт операция.
        wgp_state_set('busy');
        wgp_log('INFO', 'Удаление конфига wg1 по запросу из панели');
        // 1) Снимаем автозапуск.
        shell_exec('sudo systemctl disable wg-quick@wg1 2>&1');
        // 2) Конфиг убираем ПЕРВЫМ — вторая линия обороны: любой healthcheck
        //    поднимает туннель только при наличии конфига.
        if (file_exists(WGP_WG1_CONF)) {
            @copy(WGP_WG1_CONF, WGP_WG1_BAK);   // на случай "удалил по ошибке"
            shell_exec('sudo rm /etc/wireguard/wg1.conf 2>&1');
        }
        // 3) Гарантированно опускаем интерфейс, включая осиротевший.
        $down = wgp_bring_down();
        wgp_state_set('stopped');
        wgp_event('config_deleted');
        wgp_log($down ? 'OK' : 'ERR',
                $down ? 'Конфиг удалён, wg1 опущен' : 'Конфиг удалён, но wg1 снять не удалось');
        echo "<script>window.location = 'cabinet.php?menu=wireguard';</script>";
        exit();
    }

    // ── Загрузка конфига ──────────────────────────────────────────
    if (isset($_FILES['config_file']) && !empty($_FILES['config_file']['name'])) {

        $ext = strtolower(pathinfo($_FILES['config_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'conf') {
            wgp_log('WARN', 'Отклонён файл с расширением .' . $ext);
            echo "<script>Notice('Разрешены только файлы с расширением .conf', 'error');</script>";

        } elseif (($_FILES['config_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            wgp_log('WARN', 'Ошибка загрузки файла, код ' . $_FILES['config_file']['error']);
            echo "<script>Notice('Файл не загрузился. Попробуйте ещё раз.', 'error');</script>";

        } else {
            // Валидируем ДО того, как разрушать текущее рабочее состояние.
            $raw = @file_get_contents($_FILES['config_file']['tmp_name']);

            if ($raw === false || stripos($raw, '[Interface]') === false
                || !preg_match('/^\s*PrivateKey\s*=/mi', $raw)) {
                wgp_log('WARN', 'Отклонён невалидный конфиг (нет [Interface] или PrivateKey)');
                echo "<script>Notice('Это не похоже на конфиг WireGuard: нет [Interface] или PrivateKey.', 'error');</script>";

            } else {
                wgp_state_set('busy');
                wgp_log('INFO', 'Загрузка нового конфига wg1 (подсеть цепочки: ' . $net['cidr'] . ')');

                // Бэкап текущего рабочего конфига для авто-отката.
                $hadPrevious = file_exists(WGP_WG1_CONF);
                if ($hadPrevious) {
                    @copy(WGP_WG1_CONF, WGP_WG1_BAK);
                }

                wgp_bring_down();
                if (file_exists(WGP_WG1_CONF)) {
                    shell_exec('sudo rm /etc/wireguard/wg1.conf 2>&1');
                }

                $prepared = wgp_prepare_config($raw, $net);

                if (@file_put_contents(WGP_WG1_CONF, $prepared) === false) {
                    wgp_state_set('stopped');
                    wgp_log('ERR', 'Не удалось записать /etc/wireguard/wg1.conf');
                    echo "<script>Notice('Ошибка записи конфига. Проверьте права на /etc/wireguard/.', 'error');</script>";

                } else {
                    @chmod(WGP_WG1_CONF, 0660);

                    // Страховка: остаточный интерфейс уронит wg-quick up с "File exists".
                    if (wgp_iface_exists()) {
                        shell_exec('sudo ip link delete dev wg1 2>&1');
                        usleep(500000);
                    }

                    if (wgp_start_tunnel()) {
                        wgp_state_set('running');
                        wgp_event('config_uploaded', 'ok');
                        wgp_log('OK', 'Новый конфиг установлен, wg1 поднят');
                        echo "<script>window.location = 'cabinet.php?menu=wireguard';</script>";

                    } else {
                        // ── АВТО-ОТКАТ ────────────────────────────
                        // Раньше старый конфиг удалялся до проверки нового: залил
                        // битый — остался вообще без туннеля. Теперь возвращаем бэкап.
                        wgp_log('ERR', 'Новый конфиг не поднялся');
                        wgp_bring_down();

                        if ($hadPrevious && file_exists(WGP_WG1_BAK)) {
                            @copy(WGP_WG1_BAK, WGP_WG1_CONF);
                            @chmod(WGP_WG1_CONF, 0660);
                            $back = wgp_start_tunnel();
                            wgp_state_set($back ? 'running' : 'stopped');
                            wgp_event('config_rollback', $back ? 'ok' : 'fail');
                            wgp_log($back ? 'OK' : 'ERR',
                                    $back ? 'Выполнен откат на предыдущий конфиг'
                                          : 'Откат не помог — туннель не поднимается');
                            $msg = $back
                                 ? 'Новый конфиг не поднялся — вернули предыдущий рабочий.'
                                 : 'Новый конфиг не поднялся, откат тоже не помог. Проверьте сеть.';
                        } else {
                            if (file_exists(WGP_WG1_CONF)) {
                                shell_exec('sudo rm /etc/wireguard/wg1.conf 2>&1');
                            }
                            wgp_state_set('stopped');
                            wgp_event('config_uploaded', 'fail');
                            $msg = 'Конфиг не поднялся. Проверьте Endpoint, ключи и доступность сервера.';
                        }
                        echo "<script>Notice(" . json_encode($msg, JSON_UNESCAPED_UNICODE) . ", 'error');</script>";
                    }
                }
            }
        }
    }
}
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
    <div class="glassmorphism rounded-2xl p-6 flex flex-col">
        <h2 class="text-2xl font-bold text-white mb-6">Статус VPN</h2>
        <div class="space-y-4 text-slate-300 flex-grow">
            <div class="flex justify-between">
                <span class="font-medium">Конфигурация:</span>
                <span class="text-white font-semibold"><?= htmlspecialchars($config_type) ?></span>
            </div>
            <div class="flex justify-between">
                <span class="font-medium">Endpoint:</span>
                <span class="text-white font-semibold font-mono"><?= htmlspecialchars($ip_address) ?></span>
            </div>
            <div class="flex justify-between">
                <span class="font-medium">Подсеть клиентов:</span>
                <span class="text-white font-semibold font-mono"><?= htmlspecialchars($net['cidr']) ?></span>
            </div>
            <div class="flex justify-between items-center">
                <span class="font-medium">Соединение:</span>
                <?php if ($connection_status === 'connected'): ?>
                    <span class="bg-green-500/20 text-green-300 px-3 py-1 rounded-full text-sm font-semibold">Установлено</span>
                <?php else: ?>
                    <span class="bg-red-500/20 text-red-300 px-3 py-1 rounded-full text-sm font-semibold">Разорвано</span>
                <?php endif; ?>
            </div>
        </div>

        <form method="post" class="mt-8">
            <?= wgp_csrf_field() ?>
            <input type="hidden" name="menu" value="wireguard">
            <?php if ($type === 'wireguard'): ?>
                <div class="flex flex-col space-y-4">
                    <?php if ($connection_status === 'disconnected'): ?>
                        <button type="submit" name="wireguard_start" class="w-full bg-green-600 text-white font-bold py-3 rounded-lg hover:bg-green-700 transition-all">
                            Запустить WireGuard
                        </button>
                    <?php else: ?>
                        <button type="submit" name="wireguard_restart" class="w-full bg-blue-600 text-white font-bold py-3 rounded-lg hover:bg-blue-700 transition-all">
                            Перезапустить WireGuard
                        </button>
                    <?php endif; ?>

                    <button type="submit" name="wireguard_del" class="w-full bg-red-600 text-white font-bold py-3 rounded-lg hover:bg-red-700 transition-all">
                        Удалить WireGuard конфиг
                    </button>
                </div>
            <?php else: ?>
                <button disabled class="w-full bg-slate-700 text-slate-500 font-bold py-3 rounded-lg cursor-not-allowed">
                    Действий нет
                </button>
            <?php endif; ?>
        </form>
    </div>

    <div class="glassmorphism rounded-2xl p-6 flex flex-col">
        <h2 class="text-2xl font-bold text-white mb-6">Установка конфигурации</h2>
        <form id="upload-form" method="post" enctype="multipart/form-data" class="flex flex-col flex-grow">
            <div class="flex-grow">
                <label id="drop-zone" for="config_file" class="flex flex-col items-center justify-center w-full h-full border-2 border-dashed border-slate-600 rounded-xl cursor-pointer hover:border-violet-500 transition-colors">
                    <div class="flex flex-col items-center justify-center pt-5 pb-6">
                        <p id="drop-zone-text" class="mb-2 text-sm text-slate-400"><span class="font-semibold">Кликните для выбора</span> или перетащите файл</p>
                        <p class="text-xs text-slate-500">только *.conf</p>
                    </div>
                    <input type="file" id="config_file" name="config_file" accept=".conf" class="hidden">
                </label>
            </div>
            <input type="hidden" name="menu" value="wireguard">
            <?= wgp_csrf_field() ?>
            <button type="submit" class="w-full bg-violet-600 text-white font-bold py-3 mt-8 rounded-lg hover:bg-violet-700 transition-all">Установить и запустить</button>
        </form>
        <p class="text-xs text-slate-500 mt-4">
            Маршрутизация подставляется автоматически под подсеть <span class="font-mono"><?= htmlspecialchars($net['cidr']) ?></span>.
            Если новый конфиг не поднимется — вернётся предыдущий.
        </p>
    </div>
</div>

<script>
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('config_file');
    const dropZoneText = document.getElementById('drop-zone-text');
    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('border-violet-500'); dropZone.classList.remove('border-slate-600'); });
    dropZone.addEventListener('dragleave', (e) => { e.preventDefault(); dropZone.classList.remove('border-violet-500'); dropZone.classList.add('border-slate-600'); });
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('border-violet-500');
        dropZone.classList.add('border-slate-600');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            dropZoneText.innerHTML = `<span class="font-semibold text-green-400">Файл выбран:</span> ${files[0].name}`;
        }
    });
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            dropZoneText.innerHTML = `<span class="font-semibold text-green-400">Файл выбран:</span> ${fileInput.files[0].name}`;
        }
    });
</script>

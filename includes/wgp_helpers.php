<?php
/**
 * WGPlus — Shared helpers
 *
 * Общая библиотека для всех файлов панели. Префикс wgp_ — чтобы
 * `grep -r "wgp_"` находил всё использование без ложных совпадений
 * со встроенными функциями PHP.
 *
 * Группы:
 *   • СЕТЬ      — wgp_wg0_net (динамическое определение подсети, БЕЗ хардкода)
 *   • ИНТЕРФЕЙС — wgp_iface_exists, wgp_bring_down, wgp_wait_up
 *   • STATE     — wgp_state_set (мьютекс с healthcheck daemon)
 *   • ЛОГИ      — wgp_log, wgp_event, wgp_tail
 *
 * НЕ вызывать напрямую через HTTP.
 */

// Защита от прямого вызова по HTTP (не зависит от .htaccess/AllowOverride).
if (isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

// ══════════════════════════════════════════════════════════════════
// ПАРАМЕТРЫ COOKIE СЕССИИ
// ══════════════════════════════════════════════════════════════════
//
// Выставлять ОБЯЗАТЕЛЬНО до session_start() — потом поздно.
// Поэтому все точки входа делают require_once этого файла ПЕРВЫМ.
//
//   HttpOnly — JS не видит cookie, то есть XSS не уводит сессию
//   SameSite=Strict — второй рубеж против CSRF: браузер не приложит
//                     cookie к запросу со стороннего сайта вообще
//   Secure — только под HTTPS. При работе по HTTP его ставить НЕЛЬЗЯ —
//            браузер перестанет слать cookie и вход сломается.
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? '') === '443')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

// ══════════════════════════════════════════════════════════════════
// КОНСТАНТЫ
// ══════════════════════════════════════════════════════════════════

if (!defined('WGP_WG0_CONF'))   define('WGP_WG0_CONF',   '/etc/wireguard/wg0.conf');
if (!defined('WGP_WG1_CONF'))   define('WGP_WG1_CONF',   '/etc/wireguard/wg1.conf');
if (!defined('WGP_WG1_BAK'))    define('WGP_WG1_BAK',    '/etc/wireguard/wg1.conf.bak');
if (!defined('WGP_STATE_FILE')) define('WGP_STATE_FILE', '/var/www/wg-state');
if (!defined('WGP_TABLE_ID'))   define('WGP_TABLE_ID',   '120');
if (!defined('WGP_LOG_DIR'))    define('WGP_LOG_DIR',    '/var/log/wgplus');
if (!defined('WGP_LOG_PANEL'))  define('WGP_LOG_PANEL',  WGP_LOG_DIR . '/panel.log');
if (!defined('WGP_LOG_HEALTH')) define('WGP_LOG_HEALTH', WGP_LOG_DIR . '/health.log');
if (!defined('WGP_LOG_EVENTS')) define('WGP_LOG_EVENTS', WGP_LOG_DIR . '/events.log');
if (!defined('WGP_LOG_MAX'))    define('WGP_LOG_MAX',    2097152); // 2 MB
if (!defined('WGP_LOG_KEEP'))   define('WGP_LOG_KEEP',   800);     // строк после ротации
if (!defined('WGP_AUTH_FILE'))  define('WGP_AUTH_FILE',  '/var/www/wgplus-auth');
if (!defined('WGP_SESSION_MAX')) define('WGP_SESSION_MAX', 8 * 3600); // абсолютный лимит
if (!defined('WGP_SESSION_IDLE')) define('WGP_SESSION_IDLE', 30 * 60); // простой

// ══════════════════════════════════════════════════════════════════
// АУТЕНТИФИКАЦИЯ
// ══════════════════════════════════════════════════════════════════

/**
 * Проверка пароля панели.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ФАЙЛ С ХЕШЕМ:
 * раньше пароль лежал открытым текстом прямо в login.php — то есть В DOCROOT.
 * Стоит PHP один раз не отработать (сломанный модуль, опечатка в конфиге
 * Apache, .php отдан как text/plain) — и пароль уезжает в браузер целиком.
 * Плюс его видно любому, кто получил чтение файлов.
 *
 * Теперь: хеш в /var/www/wgplus-auth — ВНЕ docroot, недоступен по HTTP.
 *
 * Почему не PAM (проверка системного root-пароля, как делают некоторые панели):
 * это переносит риск, а не убирает. Компрометация веб-панели тогда means
 * компрометация root-пароля сервера. Отдельный хеш даёт изоляцию.
 *
 * Legacy-фолбэк на $truepassword оставлен для установок, которые ещё не
 * прошли миграцию через update.sh.
 */
function wgp_check_password(string $input, ?string $legacyPlain = null): bool {
    if ($input === '' || strlen($input) > 256) return false;

    if (is_readable(WGP_AUTH_FILE)) {
        $hash = trim((string) @file_get_contents(WGP_AUTH_FILE));
        if ($hash !== '') {
            return password_verify($input, $hash);
        }
    }

    // Legacy: старая установка без файла хеша.
    if ($legacyPlain !== null && $legacyPlain !== '') {
        return hash_equals($legacyPlain, $input);
    }
    return false;
}

/**
 * Единая проверка живости сессии: абсолютный лимит, простой, привязка к IP.
 *
 * БЫЛО: login.php записывал login_time / last_activity / ip, но НИКТО их
 * не проверял — данные лежали мёртвым грузом, а таймаута фактически не было.
 *
 * @return string '' если сессия валидна, иначе причина для редиректа
 */
function wgp_session_invalid_reason(): string {
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return 'auth';
    }
    $now = time();
    if (isset($_SESSION['login_time']) && ($now - (int) $_SESSION['login_time']) > WGP_SESSION_MAX) {
        return 'timeout';
    }
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > WGP_SESSION_IDLE) {
        return 'timeout';
    }
    // Смена IP в рамках одной сессии — признак угона cookie.
    if (!empty($_SESSION['ip']) && ($_SERVER['REMOTE_ADDR'] ?? '') !== $_SESSION['ip']) {
        return 'hijack';
    }
    return '';
}

/** Завершает сессию и уводит на логин. */
function wgp_session_kill(string $reason = ''): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    $q = ($reason !== '' && $reason !== 'auth') ? '?reason=' . urlencode($reason) : '';
    header('Location: login.php' . $q);
    exit();
}

/**
 * Гарантия авторизации для ЛЮБОЙ страницы — и когда она включена из
 * cabinet.php, и когда к ней обратились напрямую по HTTP.
 *
 * КРИТИЧНО: все файлы панели лежат в docroot, то есть доступны напрямую.
 * wireguard.php раньше не проверял авторизацию вообще — POST на
 * /wireguard.php позволял без входа удалить конфиг или залить свой,
 * то есть завернуть весь трафик клиентов на чужой сервер.
 * route.php и logs.php были защищены случайно (читали $_SESSION без
 * session_start, поэтому массив пустой) — на такое полагаться нельзя.
 */
function wgp_require_auth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $reason = wgp_session_invalid_reason();
    if ($reason !== '') {
        wgp_session_kill($reason);
    }
}

// ══════════════════════════════════════════════════════════════════
// CSRF
// ══════════════════════════════════════════════════════════════════

/**
 * Токен на сессию. Без него любой сторонний сайт мог отправить форму
 * на панель от имени залогиненного админа — удалить конфиг, добавить
 * маршрут обхода, перезапустить туннель.
 */
function wgp_csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Скрытое поле для вставки в <form>. */
function wgp_csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(wgp_csrf_token(), ENT_QUOTES) . '">';
}

/** Валиден ли токен в текущем POST. */
function wgp_csrf_valid(): bool {
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $sent);
}

/**
 * Жёсткая проверка для обработчиков POST: при провале — 403 и стоп.
 * Вызывать ДО любых side effects.
 */
function wgp_csrf_require(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (wgp_csrf_valid()) return;
    wgp_log('WARN', 'Отклонён POST без валидного CSRF-токена: ' . ($_SERVER['REQUEST_URI'] ?? '?'));
    http_response_code(403);
    exit('Forbidden: invalid CSRF token');
}

// ══════════════════════════════════════════════════════════════════
// СЕТЬ — динамическое определение подсети wg0
// ══════════════════════════════════════════════════════════════════

/**
 * Определяет подсеть клиентов из wg0.conf — единственного источника правды.
 *
 * ЗАЧЕМ: раньше подсеть была прописана строкой в инсталляторе, wireguard.php
 * и healthcheck. Стоило поставить сервер на другой адресации (10.66.66.0/24,
 * 172.16.0.0/22, любой другой) — панель продолжала писать в конфиг правила
 * для чужой подсети, и цепочка wg0 -> wg1 просто не работала.
 *
 * Читаем 'Address = X.X.X.X/NN' из [Interface] wg0 и считаем адрес сети
 * настоящей битовой арифметикой — строковые трюки вида ${SUBNET::-4}
 * ломаются на всём, кроме /24.
 *
 * @return array{gw:string, network:string, prefix:int, cidr:string}
 */
function wgp_wg0_net(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    // Fallback — исторический дефолт. Используется только если wg0.conf нечитаем.
    $fallback = [
        'gw'      => '10.55.55.1',
        'network' => '10.55.55.0',
        'prefix'  => 24,
        'cidr'    => '10.55.55.0/24',
    ];

    if (!is_readable(WGP_WG0_CONF)) return $cache = $fallback;
    $conf = @file_get_contents(WGP_WG0_CONF);
    if ($conf === false) return $cache = $fallback;

    if (!preg_match('/^\s*Address\s*=\s*(\d{1,3}(?:\.\d{1,3}){3})\s*\/\s*(\d{1,2})/mi', $conf, $m)) {
        return $cache = $fallback;
    }

    $gw     = $m[1];
    $prefix = (int) $m[2];

    if (filter_var($gw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || $prefix < 8 || $prefix > 30) {
        return $cache = $fallback;
    }

    // Битовая маска. Всё держим в пределах 32 бит — ip2long на 64-битной
    // системе вернёт положительное число, но маскируем явно для надёжности.
    $mask    = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    $network = long2ip((ip2long($gw) & $mask) & 0xFFFFFFFF);

    return $cache = [
        'gw'      => $gw,
        'network' => $network,
        'prefix'  => $prefix,
        'cidr'    => $network . '/' . $prefix,
    ];
}

/**
 * WAN-интерфейс: из NIC.txt, с фолбэком на живое состояние ядра.
 * Возвращает '' если определить не удалось.
 */
function wgp_wan_iface(): string {
    $nic = @file_get_contents(__DIR__ . '/../NIC.txt');
    $nic = is_string($nic) ? trim($nic) : '';
    if ($nic !== '' && preg_match('/^[a-zA-Z0-9_:@.-]{1,20}$/', $nic) === 1) {
        return $nic;
    }
    // Исключаем wg/tun — иначе при поднятом туннеле примем VPN за WAN.
    $out = shell_exec("ip route show default 2>/dev/null | grep -v 'dev wg\|dev tun' | grep -oP 'dev \\K\\S+' | head -1");
    $out = is_string($out) ? trim($out) : '';
    return preg_match('/^[a-zA-Z0-9_:@.-]{1,20}$/', $out) === 1 ? $out : '';
}

// ══════════════════════════════════════════════════════════════════
// ИНТЕРФЕЙС wg1
// ══════════════════════════════════════════════════════════════════

/** Наличие интерфейса по коду возврата (надёжнее парсинга строк ifconfig). */
function wgp_iface_exists(string $iface = 'wg1'): bool {
    exec('ip link show ' . escapeshellarg($iface) . ' 2>/dev/null', $o, $rc);
    return $rc === 0;
}

/** Есть ли на интерфейсе IPv4-адрес. */
function wgp_iface_has_ip(string $iface = 'wg1'): bool {
    exec('ip -4 addr show ' . escapeshellarg($iface) . ' 2>/dev/null | grep -q "inet "', $o, $rc);
    return $rc === 0;
}

/**
 * Гарантированно опускает wg1.
 *
 * Сначала штатный systemctl stop. Если интерфейс всё равно висит — значит он
 * осиротел (конфига нет, wg-quick down бесполезен) и сносим напрямую через
 * ip link delete. Без этого 'wg-quick up' на новый конфиг падает с
 * "RTNETLINK answers: File exists" и продолжает работать старый туннель.
 */
function wgp_bring_down(): bool {
    shell_exec('sudo systemctl stop wg-quick@wg1 2>&1');
    for ($i = 0; $i < 10; $i++) {
        if (!wgp_iface_exists()) return true;
        usleep(500000);
    }
    if (wgp_iface_exists()) {
        wgp_log('WARN', 'wg1 не опустился штатно — принудительное ip link delete');
        shell_exec('sudo ip link delete dev wg1 2>&1');
        for ($i = 0; $i < 6; $i++) {
            if (!wgp_iface_exists()) return true;
            usleep(500000);
        }
    }
    return !wgp_iface_exists();
}

/** Ждёт поднятия wg1 с IP (polling вместо слепого sleep). */
function wgp_wait_up(int $timeoutSec = 8): bool {
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        if (wgp_iface_exists() && wgp_iface_has_ip()) return true;
        usleep(500000);
    }
    return false;
}

// ══════════════════════════════════════════════════════════════════
// STATE — мьютекс с healthcheck daemon
// ══════════════════════════════════════════════════════════════════

/**
 * Выставляет состояние для wg-healthcheck daemon.
 *   busy    — панель выполняет операцию, daemon не вмешивается
 *   running — туннель должен работать, daemon мониторит
 *   stopped — туннель намеренно выключен
 *
 * BUSY_SINCE нужен daemon'у чтобы снять зависший busy, если PHP упал
 * посреди операции. Запись атомарная: tmp -> rename.
 * chmod ПОСЛЕ rename обязателен — rename переносит владельца tmp-файла.
 */
function wgp_state_set(string $state): void {
    $ts  = ($state === 'busy') ? time() : 0;
    $tmp = WGP_STATE_FILE . '.php.tmp';
    if (@file_put_contents($tmp, "STATE={$state}\nBUSY_SINCE={$ts}\n") !== false) {
        @rename($tmp, WGP_STATE_FILE);
        @chmod(WGP_STATE_FILE, 0666);
    }
}

// ══════════════════════════════════════════════════════════════════
// ЛОГИРОВАНИЕ
// ══════════════════════════════════════════════════════════════════

/** Ротация: при превышении лимита оставляем последние WGP_LOG_KEEP строк. */
function wgp_rotate(string $file): void {
    $size = @filesize($file);
    if ($size === false || $size <= WGP_LOG_MAX) return;
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) <= WGP_LOG_KEEP) return;
    $keep = array_slice($lines, -WGP_LOG_KEEP);
    @file_put_contents($file, implode("\n", $keep) . "\n", LOCK_EX);
}

/**
 * Лог действий панели. Пишем кто (IP) и что сделал — при разборе инцидентов
 * важно отличать действие пользователя от самодеятельности daemon.
 */
function wgp_log(string $level, string $message): void {
    if (!is_dir(WGP_LOG_DIR)) @mkdir(WGP_LOG_DIR, 0755, true);
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'local';
    $line = sprintf(
        "[%s] [%s] [%s] %s\n",
        date('Y-m-d H:i:s'), $level, $ip,
        str_replace(["\n", "\r"], ' ', $message)
    );
    @file_put_contents(WGP_LOG_PANEL, $line, FILE_APPEND | LOCK_EX);
    wgp_rotate(WGP_LOG_PANEL);
}

/**
 * Структурированное событие для журнала в UI.
 * Формат: TIME|TYPE|F1|F2|F3 — разделители из полей вычищаются,
 * иначе парсер в вебе разъедется.
 * Этот же файл пишет healthcheck daemon — журнал получается единый.
 */
function wgp_event(string $type, string ...$fields): void {
    if (!is_dir(WGP_LOG_DIR)) @mkdir(WGP_LOG_DIR, 0755, true);
    $clean = array_map(
        fn($v) => str_replace(['|', "\n", "\r"], ['/', ' ', ' '], (string) $v),
        $fields
    );
    $line = date('Y-m-d H:i:s') . '|' . $type;
    if ($clean) $line .= '|' . implode('|', $clean);
    @file_put_contents(WGP_LOG_EVENTS, $line . "\n", FILE_APPEND | LOCK_EX);
    wgp_rotate(WGP_LOG_EVENTS);
}

/**
 * Последние N строк файла без загрузки его целиком.
 * Читаем с конца блоками — на 2 МБ логе это заметно дешевле file().
 *
 * @return string[] строки в исходном порядке (старые сверху)
 */
function wgp_tail(string $file, int $lines = 200): array {
    if (!is_readable($file)) return [];
    $fp = @fopen($file, 'rb');
    if (!$fp) return [];

    fseek($fp, 0, SEEK_END);
    $size   = ftell($fp);
    $buffer = '';
    $pos    = 0;
    $chunk  = 8192;

    while ($size + $pos > 0 && substr_count($buffer, "\n") <= $lines) {
        $read = (int) min($chunk, $size + $pos);
        $pos -= $read;
        fseek($fp, $pos, SEEK_END);
        $buffer = fread($fp, $read) . $buffer;
    }
    fclose($fp);

    $all = explode("\n", $buffer);
    $all = array_values(array_filter($all, fn($l) => trim($l) !== ''));
    return array_slice($all, -$lines);
}

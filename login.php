<?php
/**
 * WGPlus — страница входа.
 *
 * ВАЖНО: обработка POST идёт ДО вывода HTML. В прежней версии форма
 * печаталась первой, а проверка пароля шла в конце файла — из-за этого
 * session_start()/header() срабатывали после начала вывода
 * ("headers already sent"), а редирект приходилось делать через JS.
 */

// Хелперы ПЕРЕД session_start() — в них выставляются параметры cookie
// (HttpOnly, SameSite=Strict), которые после старта сессии уже не применить.
require_once __DIR__ . '/includes/wgp_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Уже авторизован — незачем показывать форму.
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header('Location: index.php');
    exit();
}

// LEGACY: инсталлятор старых версий подставлял сюда пароль через sed.
// Сейчас пароль хранится хешем в /var/www/wgplus-auth (ВНЕ docroot),
// а эта строка затирается инсталлятором до пустого значения.
// Формат менять нельзя — по нему работает sed в инсталляторе.
$truepassword = 'defaultpass';

$error_message = '';
$attempts_file = sys_get_temp_dir() . '/wgplus_login_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if (($_GET['reason'] ?? '') === 'timeout') {
    $error_message = 'Сессия завершена из-за неактивности. Войдите снова.';
} elseif (($_GET['reason'] ?? '') === 'hijack') {
    $error_message = 'Сессия завершена: сменился IP-адрес. Войдите снова.';
}

/**
 * Brute-force: не больше 5 попыток за 5 минут с одного IP.
 * flock — иначе параллельные POST затрут счётчик друг друга.
 */
function wgp_login_allowed(string $file): bool {
    if (!file_exists($file)) return true;
    $fp = @fopen($file, 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $data = json_decode($raw, true);
    if (!is_array($data)) return true;
    if (isset($data['lock_until']) && time() < $data['lock_until']) return false;

    $recent = array_filter($data['attempts'] ?? [], fn($t) => $t > time() - 300);
    return count($recent) < 5;
}

function wgp_login_failed(string $file): void {
    $fp = @fopen($file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true) ?: ['attempts' => []];
    $data['attempts'][] = time();
    $data['attempts'] = array_values(array_filter($data['attempts'], fn($t) => $t > time() - 300));
    if (count($data['attempts']) >= 5) {
        $data['lock_until'] = time() + 300;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!wgp_login_allowed($attempts_file)) {
        $error_message = 'Слишком много попыток. Подождите 5 минут.';
        wgp_log('WARN', 'Вход заблокирован: превышен лимит попыток');
    } else {
        // Проверка по хешу вне docroot; legacy-пароль — только фолбэк
        // для старых установок. Обе ветки сравнивают за постоянное время.
        if (wgp_check_password((string) $_POST['password'], $truepassword)) {
            session_regenerate_id(true);              // против session fixation
            $_SESSION['authenticated'] = true;
            $_SESSION['login_time']    = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['ip']            = $_SERVER['REMOTE_ADDR'] ?? '';
            unset($_SESSION['csrf']);                // новая сессия — новый токен
            @unlink($attempts_file);
            wgp_log('OK', 'Успешный вход в панель');
            wgp_event('login', 'ok');
            header('Location: index.php');
            exit();
        }
        wgp_login_failed($attempts_file);
        wgp_log('WARN', 'Неудачная попытка входа');
        wgp_event('login', 'fail');
        $error_message = 'Неверный пароль.';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>WireGuard+ Login</title>
    <script src="tailwindcss.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0F172A; }
        .glassmorphism { background: rgba(30, 41, 59, 0.6); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .neon-button:hover { box-shadow: 0 0 8px #8b5cf6, 0 0 16px #8b5cf6; }
    </style>
</head>
<body class="text-slate-300">

    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md glassmorphism rounded-2xl p-8">

            <form class="space-y-6" action="login.php" method="POST">
                <div>
                    <img src="logo.png" alt="WireGuard Logo" class="w-48 h-48 mx-auto mb-4">
                </div>
                <h2 class="text-3xl font-bold text-white mb-6 text-center">Вход</h2>
                <div>
                    <label for="password" class="block mb-2 text-sm font-medium text-slate-400">Пароль:</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password"
                           class="w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
                </div>

                <?php if ($error_message !== ''): ?>
                    <p class="text-red-400 text-sm text-center"><?= htmlspecialchars($error_message) ?></p>
                <?php endif; ?>

                <button type="submit" class="w-full bg-violet-600 text-white font-bold py-3 rounded-lg hover:bg-violet-700 transition-all duration-300 neon-button">
                    Войти
                </button>
            </form>
        </div>
    </div>

</body>
</html>

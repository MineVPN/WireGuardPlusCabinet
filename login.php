<?php
/**
 * WireGuard+ — вход в панель.
 *
 * Обработка POST идёт ДО вывода HTML: иначе session_start() и header()
 * срабатывают после начала вывода и редирект приходится делать скриптом.
 */

// Хелперы ПЕРЕД session_start() — в них выставляются параметры cookie.
require_once __DIR__ . '/includes/wgp_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header('Location: index.php');
    exit();
}

// Пароль хранится ТОЛЬКО хешем в /var/www/wgplus-auth (вне docroot).
// Переменная оставлена пустой намеренно: инсталлятор старых версий
// подставлял сюда пароль через sed, и wgp_check_password имел запасной
// путь на это значение. Запасной путь убран — теперь при недоступном
// файле хеша вход просто невозможен, а не открыт значением по умолчанию.
$truepassword = '';

$error = '';
$attempts = sys_get_temp_dir() . '/wgplus_login_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if (($_GET['reason'] ?? '') === 'timeout') {
    $error = 'Срок сессии истёк. Войдите снова.';
}

/** Не больше 5 попыток за 5 минут с одного адреса. */
function wgp_login_allowed(string $file): bool {
    if (!file_exists($file)) return true;
    $fp = @fopen($file, 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $d = json_decode($raw, true);
    if (!is_array($d)) return true;
    if (isset($d['lock_until']) && time() < $d['lock_until']) return false;
    return count(array_filter($d['attempts'] ?? [], fn($t) => $t > time() - 300)) < 5;
}

function wgp_login_failed(string $file): void {
    $fp = @fopen($file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $d = json_decode(stream_get_contents($fp), true) ?: ['attempts' => []];
    $d['attempts'][] = time();
    $d['attempts'] = array_values(array_filter($d['attempts'], fn($t) => $t > time() - 300));
    if (count($d['attempts']) >= 5) $d['lock_until'] = time() + 300;
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($d));
    flock($fp, LOCK_UN);
    fclose($fp);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!wgp_login_allowed($attempts)) {
        $error = 'Слишком много попыток. Подождите пять минут.';
        wgp_log('WARN', 'Вход заблокирован: превышен лимит попыток');
    } elseif (wgp_check_password((string) $_POST['password'], $truepassword)) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time']    = time();
        unset($_SESSION['csrf']);
        @unlink($attempts);
        wgp_log('OK', 'Вход в панель');
        header('Location: index.php');
        exit();
    } else {
        wgp_login_failed($attempts);
        wgp_log('WARN', 'Неверный пароль при входе');
        $error = 'Неверный пароль.';
    }
}

$v = '2.1';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — WireGuard+</title>
<link rel="icon" type="image/png" href="assets/img/favicon.png">
<link rel="stylesheet" href="assets/css/tokens.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/base.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/css/components.css?v=<?= $v ?>">
</head>
<body>

<div class="auth">
  <div class="auth__card">
    <img class="auth__logo" src="assets/img/logo.png" alt="">
    <div class="auth__title">WireGuard+</div>
    <div class="auth__sub">Двойной впн</div>

    <form class="auth__form" method="POST" action="login.php">
      <div class="field">
        <label class="label" for="password">Пароль</label>
        <input class="input" type="password" id="password" name="password"
               required autocomplete="current-password" autofocus>
      </div>

      <?php if ($error !== ''): ?>
        <div class="auth__err"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <button type="submit" class="btn btn--primary btn--block">Войти</button>
    </form>
  </div>
</div>

</body>
</html>

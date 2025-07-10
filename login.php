<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenVPN+ Login</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class='login-page'>
        <div class="login-container">
            <h2>Вход</h2>
            <form class="login-form" action="login.php" method="POST">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" required>
                <input type="submit" class="green-button" value="Войти">
            </form>
            <?php
        // Проверка, была ли отправлена форма
            if ($_SERVER["REQUEST_METHOD"] == "POST") {
            // Получение пароля из формы
                $password = $_POST["password"];
                $truepassword = 'defaultpass';

            // Проверка успешности выполнения команды
                if ($password == $truepassword) {
                // Стартуем сессию
                    session_start();
                // Устанавливаем флаг авторизации
                    $_SESSION["authenticated"] = true;
                // Перенаправляем на защищенную страницу
                    header("Location: index.php");
                    exit();
                } else {
                // Авторизация неуспешна, показываем сообщение об ошибке
                    echo "<p class='error-message'>Неверный пароль.</p>";
                }
            }
            ?>
        </div>
    </div>
</body>
</html>

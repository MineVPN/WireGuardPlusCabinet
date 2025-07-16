<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SERVER Login</title>
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
            <h2 class="text-3xl font-bold text-white mb-6 text-center">Вход</h2>
            
            <form class="space-y-6" action="login.php" method="POST">
                <div>
                    <label for="username" class="block mb-2 text-sm font-medium text-slate-400">Пользователь:</label>
                    <input type="text" id="username" name="username" required value="root" class="w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
                </div>
                <div>
                    <label for="password" class="block mb-2 text-sm font-medium text-slate-400">Пароль:</label>
                    <input type="password" id="password" name="password" required class="w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
                </div>
                
                <button type="submit" class="w-full bg-violet-600 text-white font-bold py-3 rounded-lg hover:bg-violet-700 transition-all duration-300 neon-button">
                    Войти
                </button>
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
                    echo '<script>window.location.href = "index.php";</script>';
                    exit();
                } else {
                // Авторизация неуспешна, показываем сообщение об ошибке
                    echo "<p class='text-red-400 text-sm text-center mt-4'>Неверный пароль.</p>";
                }
            }
            ?>
        </div>
    </div>

</body>
</html>
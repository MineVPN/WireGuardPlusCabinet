<?php
$host = $_GET['host'];

// Выполняем команду ping с таймаутом в 1 секунду
exec("ping -c 1 -W 1 " . $host, $output, $result);

// Обрабатываем результаты
if ($result == 0) {
    foreach ($output as $line) {
        if (strpos($line, "time=") !== false) {
            // Извлекаем время из строки
            $time = explode("time=", $line)[1];
            echo $time;
            break;
        }
    }
} else {
    echo "NO PING";
}
?>

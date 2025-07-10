<?php
session_start(); // Начало сессии

// Проверяем, установлена ли сессия
if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    // Сессия не установлена или пользователь не аутентифицирован, перенаправляем на страницу входа
    header("Location: login.php");
    exit(); // Важно вызвать exit() после перенаправления, чтобы предотвратить дальнейшее выполнение кода
}

// Весь ваш код для страницы кабинета может быть добавлен здесь
?>


<div><span class='contaiter-param'>IP: </span>
    <input type="text" id="targetAddress" placeholder="Введите адрес" value="8.8.8.8" class='input-ip'>
    <button id="startButton" class="green-button">Старт</button>
    <button id="stopButton" class="red-button">Стоп</button>
</div>
<table class="pinger-table">
    <tr>
        <td>Всего</td>
        <td><span id="allCount">0</span> шт.</td>
        <td>Минимальный</td>
        <td><span id="minPing">-</span> мс.</td>
    </tr>
    <tr>
        <td>Успешных</td>
        <td><span id="successCount">0</span> шт.</td>
        <td>Средний</td>
        <td><span id="avgPing">-</span> мс.</td>
    </tr>
    <tr>
        <td>Неуспешных</td>
        <td><span id="failCount">0</span> шт.</td>
        <td>Максимальный</td>
        <td><span id="maxPing">-</span> мс.</td>
    </tr>
    <tr>
        <td>Процент потерь</td>
        <td><span id="lossPercent">0%</span></td>
        <td>Последний</td>
        <td><span id="lastPing">-</span> мс.</td>
    </tr>
</table>
<div id="logWindow"></div> <!-- Окошко с логом -->

<script>
        var intervalId; // Идентификатор интервала измерения пинга
        var allCount = 0;
        var successCount = 0;
        var failCount = 0;
        var minPing = Infinity;
        var maxPing = -Infinity;
        var totalPing = 0;

        document.getElementById("startButton").addEventListener("click", function() {
            var targetAddress = document.getElementById("targetAddress").value;
            if (targetAddress.trim() === "") {
                alert("Введите адрес для проверки пинга!");
                return;
            }
            // Очищаем лог перед началом нового измерения
            document.getElementById("logWindow").innerHTML = "";
            // Если уже запущен интервал измерения, останавливаем его
            if (intervalId) {
                clearInterval(intervalId);
            }
            // Сбрасываем счетчики перед новым измерением
            allCount = 0;
            successCount = 0;
            failCount = 0;
            minPing = Infinity;
            maxPing = -Infinity;
            totalPing = 0;
            // Запускаем интервал измерения пинга
            intervalId = setInterval(function() {
                measurePing(targetAddress);
            }, 1000);
            // Запускаем измерение пинга сразу после нажатия кнопки
            measurePing(targetAddress);
        });

        document.getElementById("stopButton").addEventListener("click", function() {
            // Очищаем интервал измерения пинга
            clearInterval(intervalId);
        });

        function measurePing(targetAddress) {
            var xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4) {
            // Получаем текущую дату и время
                    var now = new Date().toLocaleString();
                    allCount++;
                    if (xhr.status == 200 && xhr.responseText.indexOf("NO PING") == -1) {
                        successCount++;
                var ping = parseFloat(xhr.responseText); // Получаем значение пинга как число
                // Обновляем минимальный и максимальный пинг
                minPing = Math.min(minPing, ping);
                maxPing = Math.max(maxPing, ping);
                // Обновляем сумму пингов для подсчета среднего значения
                totalPing += ping;
                // Создаем новую строку с результатом замера
                var logEntry = document.createElement('p');
                logEntry.textContent = now + ': ' + ping + ' мс';
                // Проверяем условие для окраски строки в красный цвет
                var avgPing = totalPing / successCount;
                if (!isNaN(avgPing) && ping > (avgPing + 20)) {
                    logEntry.style.color = 'orange';
                }
                // Добавляем строку в окошко с логом
                document.getElementById("logWindow").appendChild(logEntry);
            } else {
                failCount++;
                // Создаем новую строку с сообщением об ошибке
                var logEntry = document.createElement('p');
                logEntry.textContent = now + ': NO PING';
                logEntry.style.color = 'red';
                // Добавляем строку в окошко с логом
                document.getElementById("logWindow").appendChild(logEntry);
            }
            // Обновляем счетчики на странице
            document.getElementById("allCount").textContent = allCount;
            document.getElementById("successCount").textContent = successCount;
            document.getElementById("failCount").textContent = failCount;
            // Подсчитываем процент потерь и обновляем на странице
            var total = successCount + failCount;
            var lossPercent = (failCount / total * 100).toFixed(2);
            document.getElementById("lossPercent").textContent = lossPercent + "%";
            // Обновляем минимальный, максимальный и средний пинг на странице
            document.getElementById("minPing").textContent = (minPing == Infinity) ? "-" : minPing.toFixed(2);
            document.getElementById("maxPing").textContent = (maxPing == -Infinity) ? "-" : maxPing.toFixed(2);
            var avgPing = totalPing / successCount;
            document.getElementById("avgPing").textContent = isNaN(avgPing) ? "-" : avgPing.toFixed(2);
            var lastPing = ping; 
            document.getElementById("lastPing").textContent = isNaN(lastPing) ? "-" : lastPing.toFixed(2);
            // Прокручиваем окошко с логом до самого низа (новые записи)
            document.getElementById("logWindow").scrollTop = document.getElementById("logWindow").scrollHeight;
        }
    };
    xhr.open("GET", "ping.php?host=" + encodeURIComponent(targetAddress), true);
    xhr.send();
}

</script>
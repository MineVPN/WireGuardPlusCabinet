<?php
// Твоя PHP-логика остается здесь без изменений
session_start();

if (!isset($_SESSION["authenticated"]) || $_SESSION["authenticated"] !== true) {
    header("Location: login.php");
    exit();
}
?>

<div class="flex flex-col gap-8">

    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex flex-col sm:flex-row items-center gap-4">
            <label for="targetAddress" class="text-lg font-medium text-white">IP:</label>
            <input type="text" id="targetAddress" placeholder="Введите адрес, например, 8.8.8.8" value="8.8.8.8" class="flex-grow w-full bg-slate-700/50 border border-slate-600 rounded-lg p-3 text-white placeholder-slate-400 focus:ring-2 focus:ring-violet-500 focus:outline-none transition">
            <div class="flex w-full sm:w-auto gap-4">
                <button id="startButton" class="w-1/2 sm:w-auto flex-grow bg-green-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-green-700 transition-all">Старт</button>
                <button id="stopButton" class="w-1/2 sm:w-auto flex-grow bg-red-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-red-700 transition-all">Стоп</button>
            </div>
        </div>
    </div>

    <div class="glassmorphism rounded-2xl p-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 text-center">
            
            <div class="bg-slate-800/50 p-4 rounded-lg">
                <p class="text-sm font-medium text-slate-400">Отправлено</p>
                <p class="text-3xl font-semibold text-white mt-1"><span id="allCount">0</span></p>
            </div>
            <div class="bg-slate-800/50 p-4 rounded-lg">
                <p class="text-sm font-medium text-slate-400">Успешно</p>
                <p class="text-3xl font-semibold text-green-400 mt-1"><span id="successCount">0</span></p>
            </div>
            <div class="bg-slate-800/50 p-4 rounded-lg">
                <p class="text-sm font-medium text-slate-400">Потеряно</p>
                <p class="text-3xl font-semibold text-red-400 mt-1"><span id="failCount">0</span></p>
            </div>
            <div class="bg-slate-800/50 p-4 rounded-lg">
                <p class="text-sm font-medium text-slate-400">Потери</p>
                <p class="text-3xl font-semibold text-orange-400 mt-1"><span id="lossPercent">0%</span></p>
            </div>

            <div class="bg-slate-800/50 p-4 rounded-lg">
                <p class="text-sm font-medium text-slate-400">Мин.</p>
                <p class="text-3xl font-semibold text-sky-400 mt-1"><span id="minPing">-</span> <span class="text-lg">мс</span></p>
            </div>
            <div class="bg-slate-800/50 p-4 rounded-lg">
                <p class="text-sm font-medium text-slate-400">Сред.</p>
                <p class="text-3xl font-semibold text-sky-400 mt-1"><span id="avgPing">-</span> <span class="text-lg">мс</span></p>
            </div>
             <div class="bg-slate-800/50 p-4 rounded-lg">
                <p class="text-sm font-medium text-slate-400">Макс.</p>
                <p class="text-3xl font-semibold text-sky-400 mt-1"><span id="maxPing">-</span> <span class="text-lg">мс</span></p>
            </div>
            <div class="bg-slate-800/50 p-4 rounded-lg">
                <p class="text-sm font-medium text-slate-400">Последний</p>
                <p class="text-3xl font-semibold text-white mt-1"><span id="lastPing">-</span> <span class="text-lg">мс</span></p>
            </div>

        </div>
    </div>

    <div class="glassmorphism rounded-2xl p-2">
        <div id="logWindow" class="w-full h-96 bg-slate-900/70 rounded-lg p-4 font-mono text-sm overflow-y-auto">
            </div>
    </div>

</div>


<script>
    var intervalId; 
    var allCount = 0, successCount = 0, failCount = 0;
    var minPing = Infinity, maxPing = -Infinity, totalPing = 0;

    document.getElementById("startButton").addEventListener("click", function() {
        var targetAddress = document.getElementById("targetAddress").value;
        if (targetAddress.trim() === "") {
            Notice("Введите адрес для проверки пинга!", "error"); // Используем нашу новую функцию уведомлений
            return;
        }
        document.getElementById("logWindow").innerHTML = "";
        if (intervalId) { clearInterval(intervalId); }
        allCount = 0; successCount = 0; failCount = 0;
        minPing = Infinity; maxPing = -Infinity; totalPing = 0;
        intervalId = setInterval(function() { measurePing(targetAddress); }, 1000);
        measurePing(targetAddress);
    });

    document.getElementById("stopButton").addEventListener("click", function() {
        clearInterval(intervalId);
    });

    function measurePing(targetAddress) {
        var xhr = new XMLHttpRequest();
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4) {
                var now = new Date().toLocaleString();
                allCount++;
                var ping; // Объявляем переменную здесь

                if (xhr.status == 200 && xhr.responseText.indexOf("NO PING") == -1) {
                    successCount++;
                    ping = parseFloat(xhr.responseText);
                    minPing = Math.min(minPing, ping);
                    maxPing = Math.max(maxPing, ping);
                    totalPing += ping;
                    var logEntry = document.createElement('p');
                    logEntry.textContent = now + ': ' + ping.toFixed(2) + ' мс';
                    var avgPing = totalPing / successCount;
                    if (!isNaN(avgPing) && ping > (avgPing + 20)) {
                        logEntry.style.color = '#f97316'; // orange-500
                    } else {
                        logEntry.style.color = '#22c55e'; // green-500
                    }
                    document.getElementById("logWindow").appendChild(logEntry);
                } else {
                    failCount++;
                    ping = NaN; // Устанавливаем в NaN при ошибке
                    var logEntry = document.createElement('p');
                    logEntry.textContent = now + ': NO PING';
                    logEntry.style.color = '#ef4444'; // red-500
                    document.getElementById("logWindow").appendChild(logEntry);
                }
                
                // Обновляем статистику
                document.getElementById("allCount").textContent = allCount;
                document.getElementById("successCount").textContent = successCount;
                document.getElementById("failCount").textContent = failCount;
                var total = successCount + failCount;
                var lossPercent = (total === 0) ? 0 : (failCount / total * 100);
                document.getElementById("lossPercent").textContent = lossPercent.toFixed(1) + "%";
                document.getElementById("minPing").textContent = (minPing == Infinity) ? "-" : minPing.toFixed(2);
                document.getElementById("maxPing").textContent = (maxPing == -Infinity) ? "-" : maxPing.toFixed(2);
                var avgPing = totalPing / successCount;
                document.getElementById("avgPing").textContent = isNaN(avgPing) ? "-" : avgPing.toFixed(2);
                document.getElementById("lastPing").textContent = isNaN(ping) ? "-" : ping.toFixed(2);
                
                document.getElementById("logWindow").scrollTop = document.getElementById("logWindow").scrollHeight;
            }
        };
        xhr.open("GET", "ping.php?host=" + encodeURIComponent(targetAddress), true);
        xhr.send();
    }
</script>
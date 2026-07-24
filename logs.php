<?php
/**
 * WGPlus — страница «Логи»
 * Включается из cabinet.php (сессия и layout уже подняты там).
 */

require_once __DIR__ . '/includes/wgp_helpers.php';
wgp_require_auth();
?>

<div class="flex flex-col gap-6">

    <div class="glassmorphism rounded-2xl p-6">
        <div class="flex flex-col lg:flex-row lg:items-center gap-4">

            <div class="flex gap-2" id="log-tabs">
                <button data-source="panel"  class="log-tab px-4 py-2 rounded-lg font-medium transition-colors bg-violet-500/20 text-white">Панель</button>
                <button data-source="health" class="log-tab px-4 py-2 rounded-lg font-medium transition-colors text-slate-400 hover:bg-slate-800/50">Healthcheck</button>
                <button data-source="events" class="log-tab px-4 py-2 rounded-lg font-medium transition-colors text-slate-400 hover:bg-slate-800/50">События</button>
            </div>

            <div class="flex-grow"></div>

            <div class="flex items-center gap-3">
                <select id="log-lines" class="bg-slate-700/50 border border-slate-600 rounded-lg p-2 text-white text-sm focus:ring-2 focus:ring-violet-500 focus:outline-none">
                    <option value="100">100 строк</option>
                    <option value="200" selected>200 строк</option>
                    <option value="500">500 строк</option>
                    <option value="1000">1000 строк</option>
                </select>

                <label class="flex items-center gap-2 text-sm text-slate-300 cursor-pointer select-none">
                    <input type="checkbox" id="log-auto" checked class="w-4 h-4 accent-violet-600">
                    Авто
                </label>

                <button id="log-refresh" class="bg-violet-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-violet-700 transition-all text-sm">
                    Обновить
                </button>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
            <span>Файл: <span id="log-file" class="font-mono">—</span></span>
            <span>Строк: <span id="log-count">0</span></span>
            <span>Размер: <span id="log-size">0</span></span>
            <span>Обновлено: <span id="log-time">—</span></span>
        </div>
    </div>

    <div class="glassmorphism rounded-2xl p-2">
        <div id="log-window" class="w-full bg-slate-900/70 rounded-lg p-4 font-mono text-xs leading-relaxed overflow-auto"
             style="height: 62vh; min-height: 320px;">
            <div class="text-slate-500">Загрузка…</div>
        </div>
    </div>
</div>

<script>
(function () {
    const win     = document.getElementById('log-window');
    const tabs    = document.querySelectorAll('.log-tab');
    const linesEl = document.getElementById('log-lines');
    const autoEl  = document.getElementById('log-auto');
    const btn     = document.getElementById('log-refresh');

    let source  = 'panel';
    let timer   = null;
    let loading = false;

    const ACTIVE   = ['bg-violet-500/20', 'text-white'];
    const INACTIVE = ['text-slate-400', 'hover:bg-slate-800/50'];

    function setTab(el) {
        tabs.forEach(t => { t.classList.remove(...ACTIVE); t.classList.add(...INACTIVE); });
        el.classList.remove(...INACTIVE);
        el.classList.add(...ACTIVE);
    }

    function human(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    // Подсветка по уровню. Для events разбираем формат TIME|TYPE|F1|F2.
    function colorFor(line) {
        if (/\[(ERR|CRIT)\]/.test(line) || /\|(tunnel_fail|config_rollback|recovery_failed|isp_down)\|?/.test(line)) return '#f87171';
        if (/\[WARN\]/.test(line)) return '#fb923c';
        if (/\[OK\]/.test(line)  || /\|(tunnel_up|config_uploaded|isp_restored)\|?/.test(line)) return '#4ade80';
        if (/\[INFO\]/.test(line)) return '#94a3b8';
        return '#cbd5e1';
    }

    function renderEvent(line) {
        const p = line.split('|');
        const time = p[0] || '';
        const type = p[1] || '';
        const rest = p.slice(2).filter(Boolean).join(' · ');
        return `${time}  ${type}${rest ? '  ' + rest : ''}`;
    }

    function render(data) {
        win.innerHTML = '';
        if (!data.exists) {
            win.innerHTML = '<div class="text-slate-500">Файл ещё не создан: ' + data.file + '</div>';
            return;
        }
        if (!data.lines.length) {
            win.innerHTML = '<div class="text-slate-500">Пусто.</div>';
            return;
        }
        const frag = document.createDocumentFragment();
        data.lines.forEach(function (line) {
            const div = document.createElement('div');
            div.textContent = (data.source === 'events') ? renderEvent(line) : line;
            div.style.color = colorFor(line);
            div.style.whiteSpace = 'pre-wrap';
            div.style.wordBreak = 'break-all';
            frag.appendChild(div);
        });
        win.appendChild(frag);
        win.scrollTop = win.scrollHeight;
    }

    function load() {
        if (loading) return;
        loading = true;
        const url = 'log_api.php?source=' + encodeURIComponent(source) +
                    '&lines=' + encodeURIComponent(linesEl.value);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(function (data) {
                if (!data.ok) throw new Error(data.error || 'error');
                document.getElementById('log-file').textContent  = data.file;
                document.getElementById('log-count').textContent = data.count;
                document.getElementById('log-size').textContent  = human(data.size);
                document.getElementById('log-time').textContent  = data.fetched;
                render(data);
            })
            .catch(function () {
                win.innerHTML = '<div class="text-red-400">Не удалось загрузить лог.</div>';
            })
            .finally(function () { loading = false; });
    }

    function restartTimer() {
        if (timer) clearInterval(timer);
        if (autoEl.checked) timer = setInterval(load, 5000);
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            source = t.dataset.source;
            setTab(t);
            load();
        });
    });

    linesEl.addEventListener('change', load);
    btn.addEventListener('click', load);
    autoEl.addEventListener('change', restartTimer);

    load();
    restartTimer();
})();
</script>

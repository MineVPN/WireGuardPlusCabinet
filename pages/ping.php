<?php
/**
 * WGPlus — страница «Пинг».
 *
 * Замер задержки до адреса раз в секунду с накоплением статистики.
 *
 * По умолчанию пинг идёт с адреса шлюза — то есть тем же путём, что
 * и трафик конечного клиента. Два остальных варианта задают путь жёстко —
 * сравнение их результатов показывает, где именно рвётся связь.
 */

require_once __DIR__ . '/../includes/wgp_helpers.php';
wgp_require_auth();
?>

<div class="page-head">
  <div class="page-head__title"><h1>Пинг</h1></div>
  <p class="page-head__note">
    По умолчанию пинг идёт тем же путём, что и трафик клиента: через второй впн,
    если он подключён, и напрямую — если нет или адрес в списке обхода.
    Остальные варианты — чтобы сравнить и понять, где именно рвётся связь.
  </p>
</div>

<div class="stack">

  <!-- ══ Управление ══ -->
  <div class="card">
    <div class="ping-controls">
      <div class="field">
        <label class="label" for="host">Адрес или домен</label>
        <input type="text" id="host" class="input" value="208.67.222.222" placeholder="208.67.222.222 или google.com">
      </div>
      <div class="field">
        <label class="label" for="path">Путь</label>
        <select id="path" class="select">
          <option value="">По умолчанию (как у клиента)</option>
          <option value="wg1">Только через второй впн</option>
          <option value="nic">Только напрямую</option>
        </select>
      </div>
      <div class="field">
        <span class="label">&nbsp;</span>
        <div class="ping-controls__btns">
          <button type="button" id="go" class="btn btn--primary">Старт</button>
          <button type="button" id="stop" class="btn btn--danger">Стоп</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ Показатели ══ -->
  <div class="card">
    <div class="stats" style="margin-bottom: var(--s-3)">
      <div class="stat">
        <span class="stat__k">Отправлено</span>
        <span class="stat__v" id="s-all">0</span>
      </div>
      <div class="stat stat--ok">
        <span class="stat__k">Успешно</span>
        <span class="stat__v" id="s-ok">0</span>
      </div>
      <div class="stat stat--err">
        <span class="stat__k">Потеряно</span>
        <span class="stat__v" id="s-lost">0</span>
      </div>
      <div class="stat stat--warn">
        <span class="stat__k">Потери</span>
        <span class="stat__v" id="s-loss">0%</span>
      </div>
    </div>

    <div class="stats">
      <div class="stat stat--info">
        <span class="stat__k">Минимум</span>
        <span><span class="stat__v" id="s-min">—</span><span class="stat__u">мс</span></span>
      </div>
      <div class="stat stat--info">
        <span class="stat__k">Средний</span>
        <span><span class="stat__v" id="s-avg">—</span><span class="stat__u">мс</span></span>
      </div>
      <div class="stat stat--info">
        <span class="stat__k">Максимум</span>
        <span><span class="stat__v" id="s-max">—</span><span class="stat__u">мс</span></span>
      </div>
      <div class="stat">
        <span class="stat__k">Последний</span>
        <span><span class="stat__v" id="s-last">—</span><span class="stat__u">мс</span></span>
      </div>
    </div>
  </div>

  <!-- ══ Поток замеров ══ -->
  <div class="card" style="padding: var(--s-3)">
    <div id="pinglog" class="pinglog"></div>
  </div>
</div>

<script>
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };

  var timer = null,
      all = 0, ok = 0, lost = 0,
      min = Infinity, max = -Infinity, sum = 0;

  function reset() {
    all = ok = lost = sum = 0;
    min = Infinity; max = -Infinity;
    $('s-all').textContent = '0';
    $('s-ok').textContent = '0';
    $('s-lost').textContent = '0';
    $('s-loss').textContent = '0%';
    $('s-min').textContent = '—';
    $('s-avg').textContent = '—';
    $('s-max').textContent = '—';
    $('s-last').textContent = '—';
    $('pinglog').innerHTML = '';
  }

  function add(text, cls) {
    var log = $('pinglog');
    var p = document.createElement('p');
    p.textContent = text;
    if (cls) p.className = cls;
    log.appendChild(p);
    // Ограничение памяти: держим последние 500 замеров.
    while (log.children.length > 500) log.firstChild.remove();
    log.scrollTop = log.scrollHeight;
  }

  function measure(target, via) {
    var url = 'api/ping.php?host=' + encodeURIComponent(target);
    if (via) url += '&iface=' + encodeURIComponent(via);

    fetch(url, { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.text(); })
      .then(function (data) {
        var now = new Date().toLocaleTimeString();
        all++;
        var ms = NaN;

        if (data.indexOf('NO PING') === -1) {
          ok++;
          ms = parseFloat(data);
          if (!isNaN(ms)) {
            min = Math.min(min, ms);
            max = Math.max(max, ms);
            sum += ms;
            var avg = sum / ok;
            // Всплеск: заметно выше среднего — помечаем жёлтым.
            add(now + '  ·  ping → ' + target + '  ·  ' + ms.toFixed(1) + ' мс',
                ms > avg + 20 ? 'slow' : 'ok');
          } else {
            add(now + '  ·  ping → ' + target + '  ·  ответ есть', 'ok');
          }
        } else {
          lost++;
          add(now + '  ·  ping → ' + target + '  ·  нет ответа', 'fail');
        }

        $('s-all').textContent = all;
        $('s-ok').textContent = ok;
        $('s-lost').textContent = lost;
        $('s-loss').textContent = (all === 0 ? 0 : lost / all * 100).toFixed(1) + '%';
        $('s-min').textContent = (min === Infinity) ? '—' : min.toFixed(1);
        $('s-max').textContent = (max === -Infinity) ? '—' : max.toFixed(1);
        var a = sum / ok;
        $('s-avg').textContent = isNaN(a) ? '—' : a.toFixed(1);
        $('s-last').textContent = isNaN(ms) ? '—' : ms.toFixed(1);
      })
      .catch(function () {
        all++; lost++;
        $('s-all').textContent = all;
        $('s-lost').textContent = lost;
        add(new Date().toLocaleTimeString() + '  ·  панель не ответила', 'fail');
      });
  }

  $('go').addEventListener('click', function () {
    var target = $('host').value.trim();
    if (!target) { add('Введите адрес для проверки', 'fail'); return; }
    if (timer) clearInterval(timer);
    reset();
    var via = $('path').value;
    measure(target, via);
    timer = setInterval(function () { measure(target, via); }, 1000);
  });

  $('stop').addEventListener('click', function () {
    if (timer) { clearInterval(timer); timer = null; add('остановлено'); }
  });

  // Enter в поле адреса запускает проверку.
  $('host').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); $('go').click(); }
  });
})();
</script>

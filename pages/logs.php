<?php
/**
 * WGPlus — страница «События».
 *
 * Один список вместо трёх вкладок. Панель и демон пишут в общий файл
 * с меткой источника, поэтому всё идёт одной хронологией.
 */

require_once __DIR__ . '/../includes/wgp_helpers.php';
wgp_require_auth();
?>

<div class="page-head">
  <div class="page-head__title"><h1>События</h1></div>
  <p class="page-head__note">
    Что делали вы и что делал сервер сам — одной лентой, новые записи снизу.
  </p>
</div>

<div class="card">
  <div class="card__head">
    <div class="row">
      <label class="check">
        <input type="checkbox" id="only-problems"> Только проблемы
      </label>
      <select id="lines" class="select" style="width:auto">
        <option value="200">200 строк</option>
        <option value="500" selected>500 строк</option>
        <option value="1500">1500 строк</option>
      </select>
    </div>
    <div class="row">
      <label class="check"><input type="checkbox" id="auto" checked> Обновлять</label>
      <button id="refresh" class="btn btn--sm">Обновить</button>
    </div>
  </div>

  <div id="log" class="console">
    <div class="faint">Загружаем…</div>
  </div>

  <div class="row faint" style="margin-top: var(--s-3); font-size: var(--fs-xs)">
    <span>Записей: <span id="count" class="data">0</span></span>
    <span>Размер: <span id="size" class="data">0</span></span>
    <span>Обновлено: <span id="at" class="data">—</span></span>
  </div>
</div>

<script>
(function () {
  var box = document.getElementById('log'),
      linesSel = document.getElementById('lines'),
      onlyBad = document.getElementById('only-problems'),
      auto = document.getElementById('auto'),
      btn = document.getElementById('refresh'),
      timer = null, busy = false;

  function human(b) {
    if (b < 1024) return b + ' Б';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' КБ';
    return (b / 1048576).toFixed(1) + ' МБ';
  }

  function render(rows) {
    box.textContent = '';
    if (!rows.length) {
      box.innerHTML = '<div class="faint">Пока ничего не записано.</div>';
      return;
    }
    var frag = document.createDocumentFragment();
    rows.forEach(function (r) {
      var el = document.createElement('div');
      el.className = 'line' + (r.level ? ' line--' + r.level : '');
      el.innerHTML =
        '<span class="line__t"></span><span class="line__src"></span><span class="line__m"></span>';
      el.children[0].textContent = r.time;
      el.children[1].textContent = r.source;
      el.children[2].textContent = r.text;
      frag.appendChild(el);
    });
    box.appendChild(frag);
    box.scrollTop = box.scrollHeight;
  }

  function load() {
    if (busy) return;
    busy = true;
    var url = 'api/logs.php?lines=' + encodeURIComponent(linesSel.value) +
              (onlyBad.checked ? '&only=problems' : '');
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error();
        document.getElementById('count').textContent = d.count;
        document.getElementById('size').textContent = human(d.size);
        document.getElementById('at').textContent = d.at;
        render(d.rows);
      })
      .catch(function () {
        box.innerHTML = '<div class="line line--err"><span></span><span></span>' +
                        '<span class="line__m">Не удалось загрузить события.</span></div>';
      })
      .finally(function () { busy = false; });
  }

  function retime() {
    if (timer) clearInterval(timer);
    if (auto.checked) timer = setInterval(load, 5000);
  }

  [linesSel, onlyBad].forEach(function (el) { el.addEventListener('change', load); });
  auto.addEventListener('change', retime);
  btn.addEventListener('click', load);

  load();
  retime();
})();
</script>

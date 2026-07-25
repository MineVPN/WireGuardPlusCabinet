<?php
/**
 * WireGuard+ — страница «Инструкция».
 *
 * Написана для человека, который не администрирует серверы. Никаких
 * терминов без объяснения, каждый шаг — что нажать и что должно
 * получиться. Подсеть и адрес подставляются реальные, чтобы человек
 * видел свои значения, а не абстрактный пример.
 */

require_once __DIR__ . '/../includes/wgp_helpers.php';
wgp_require_auth();

$net       = wgp_wg0_net();
$hasConfig = file_exists(WGP_WG1_CONF);
$up        = wgp_iface_exists();
?>

<div class="page-head">
  <div class="page-head__title"><h1>Инструкция</h1></div>
  <p class="page-head__note">
    Как это работает и что делать по шагам. Если что-то непонятно — читайте раздел
    «Если не работает» внизу страницы.
  </p>
</div>

<div class="stack">

  <!-- ══ Что это ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Что такое WireGuard+</h2></div>

    <p style="color: var(--text-dim); line-height: 1.7; margin-bottom: var(--s-5)">
      Это услуга для подключения к любой стране без ограничений — например, из Украины в Россию.
      Напрямую так подключиться нельзя. Нужен <strong style="color: var(--text)">промежуточный
      сервер в Европе</strong>, и WireGuard+ как раз им и является.
    </p>

    <div class="flow">
      <div class="flow__node">
        <div class="flow__icon">📱</div>
        <div class="flow__name">Ваше устройство</div>
        <div class="flow__sub">телефон, ноутбук, роутер</div>
      </div>
      <div class="flow__arrow">→</div>
      <div class="flow__node flow__node--accent">
        <div class="flow__icon">🇪🇺</div>
        <div class="flow__name">Этот сервер</div>
        <div class="flow__sub">промежуточный,<br>в Европе</div>
      </div>
      <div class="flow__arrow">→</div>
      <div class="flow__node">
        <div class="flow__icon">🌍</div>
        <div class="flow__name">Второй впн</div>
        <div class="flow__sub">нужная вам страна</div>
      </div>
      <div class="flow__arrow">→</div>
      <div class="flow__node">
        <div class="flow__icon">🌐</div>
        <div class="flow__name">Интернет</div>
        <div class="flow__sub">сайты и сервисы</div>
      </div>
    </div>

    <div class="callout callout--warn" style="margin-top: var(--s-5)">
      <span class="callout__mark">❗</span>
      <span>
        <strong>Второй впн в комплект не входит</strong> — его нужно купить отдельно.
        Вы покупаете этот промежуточный сервер и панель управления, а конфиг нужной
        страны берёте у любого другого продавца.
      </span>
    </div>
  </div>

  <!-- ══ Что уже есть ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Что у вас уже есть</h2></div>

    <div class="bullets">
      <div class="bullet">
        <span class="bullet__mark">🔑</span>
        <span><strong style="color: var(--text)">Конфиги для ваших устройств</strong> —
        файлы <code>.conf</code>, которые вам выдал продавец. Каждый файл — на одно устройство.</span>
      </div>
      <div class="bullet">
        <span class="bullet__mark">🖥️</span>
        <span><strong style="color: var(--text)">Эта панель управления</strong> — вы сейчас в ней.
        Здесь ставится второй впн и настраиваются исключения.</span>
      </div>
      <div class="bullet">
        <span class="bullet__mark">🔢</span>
        <span><strong style="color: var(--text)">Подсеть <?= htmlspecialchars($net['cidr']) ?></strong> —
        внутренние адреса ваших устройств. Меняется только при переустановке.</span>
      </div>
    </div>
  </div>

  <!-- ══ Шаги ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Что делать по шагам</h2></div>

    <div class="steps">

      <div class="step">
        <div class="step__num">1</div>
        <div>
          <div class="step__title">Подключите свои устройства</div>
          <div class="step__body">
            <p>Установите приложение <strong>WireGuard</strong> — оно бесплатное и есть
            в Google Play, App Store и на сайте wireguard.com для компьютера.</p>
            <p>Откройте приложение, нажмите <strong>+</strong> и добавьте файл конфига,
            который вам выдали. Можно отсканировать QR-код, если продавец прислал картинку.</p>
            <p>Включите переключатель. Всё — устройство подключено к этому серверу.</p>
          </div>
        </div>
      </div>

      <div class="step">
        <div class="step__num">2</div>
        <div>
          <div class="step__title">Проверьте, что подключение работает</div>
          <div class="step__body">
            <p>Откройте в браузере сайт <strong>2ip.ru</strong> или <strong>whatismyip.com</strong>.
            Он покажет ваш адрес и страну.</p>
            <p>Должна быть страна <strong>этого сервера</strong> (обычно европейская), а не ваша.
            Если так — первая половина цепочки работает.</p>
          </div>
        </div>
      </div>

      <div class="step">
        <div class="step__num">3</div>
        <div>
          <div class="step__title">Купите второй впн нужной страны</div>
          <div class="step__body">
            <p>Нужен конфиг <strong>именно для WireGuard</strong> — файл с расширением
            <code>.conf</code>. Форматы <code>.ovpn</code> и логин с паролем сюда не подойдут.</p>
            <p>Покупать можно у любого продавца. Главное — попросить конфиг WireGuard
            и страну, которая вам нужна.</p>
          </div>
        </div>
      </div>

      <div class="step">
        <div class="step__num">4</div>
        <div>
          <div class="step__title">Загрузите его в панель</div>
          <div class="step__body">
            <p>Откройте раздел <strong>Подключение</strong> в меню слева.</p>
            <p>Справа есть поле <strong>«Перетащите файл или нажмите»</strong> — перетащите
            туда файл <code>.conf</code> или кликните и выберите его на компьютере.</p>
            <p>Нажмите <strong>«Установить и подключить»</strong> и подождите несколько секунд.</p>
            <p>Если наверху появилась зелёная надпись <strong>«Подключено»</strong> — готово.
            Ничего больше настраивать не нужно.</p>
          </div>
        </div>
      </div>

      <div class="step">
        <div class="step__num">5</div>
        <div>
          <div class="step__title">Проверьте результат</div>
          <div class="step__body">
            <p>Снова откройте <strong>2ip.ru</strong> с подключённого устройства.</p>
            <p>Теперь должна показываться страна <strong>второго впн</strong>, а не европейская.
            Значит цепочка собралась полностью.</p>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ══ Обход ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Раздел «Обход VPN» — зачем он</h2></div>

    <p style="color: var(--text-dim); line-height: 1.7; margin-bottom: var(--s-4)">
      Трафик через два впн идёт дольше, чем через один. Для сайтов это незаметно,
      а вот для звонков и телефонии задержка мешает.
    </p>

    <p style="color: var(--text-dim); line-height: 1.7; margin-bottom: var(--s-4)">
      В разделе <strong style="color: var(--text)">Обход VPN</strong> можно указать адреса,
      которые пойдут <strong style="color: var(--text)">напрямую через этот сервер</strong>,
      минуя второй впн. Получается короче и быстрее.
    </p>

    <div class="bullets">
      <div class="bullet">
        <span class="bullet__mark">📞</span>
        <span>Серверы телефонии и SIP — чтобы не было эха и задержки в разговоре</span>
      </div>
      <div class="bullet">
        <span class="bullet__mark">💼</span>
        <span>CRM и рабочие сервисы, где важна скорость отклика</span>
      </div>
      <div class="bullet">
        <span class="bullet__mark">🎮</span>
        <span>Игровые серверы, если важен пинг</span>
      </div>
    </div>

    <div class="callout callout--info" style="margin-top: var(--s-5)">
      <span class="callout__mark">💡</span>
      <span>
        Добавлять нужно <strong>IP-адрес</strong>, а не название сайта. Узнать адрес можно
        командой <code>ping адрес-сервиса.ru</code> в командной строке — он покажется в скобках.
      </span>
    </div>
  </div>

  <!-- ══ Остальные разделы ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Остальные разделы панели</h2></div>

    <div class="facts">
      <div class="fact">
        <span class="fact__k" style="font-weight:600;color:var(--text)">Подключение</span>
        <span class="fact__v" style="font-family:var(--font-ui);text-align:right;max-width:60%">
          Главная страница. Здесь ставится второй впн, видно его адрес и состояние связи
        </span>
      </div>
      <div class="fact">
        <span class="fact__k" style="font-weight:600;color:var(--text)">Обход VPN</span>
        <span class="fact__v" style="font-family:var(--font-ui);text-align:right;max-width:60%">
          Адреса, которые идут напрямую, минуя второй впн
        </span>
      </div>
      <div class="fact">
        <span class="fact__k" style="font-weight:600;color:var(--text)">Пинг</span>
        <span class="fact__v" style="font-family:var(--font-ui);text-align:right;max-width:60%">
          Проверка, отвечает ли нужный адрес и за сколько миллисекунд
        </span>
      </div>
      <div class="fact">
        <span class="fact__k" style="font-weight:600;color:var(--text)">События</span>
        <span class="fact__v" style="font-family:var(--font-ui);text-align:right;max-width:60%">
          Что происходило с сервером: когда связь пропадала и когда восстановилась
        </span>
      </div>
    </div>
  </div>

  <!-- ══ Если не работает ══ -->
  <div class="card">
    <div class="card__head"><h2 class="card__title">Если не работает</h2></div>

    <div class="qa">

      <details class="qa__item">
        <summary class="qa__q">Загрузил конфиг, но пишет «Нет связи»</summary>
        <div class="qa__a">
          <p style="margin-bottom:var(--s-2)">Чаще всего причина в самом конфиге второго впн:</p>
          <p style="margin-bottom:var(--s-2)">1. Проверьте, что он <strong>для WireGuard</strong>,
          а не для OpenVPN. Внутри файла должно быть написано <code>[Interface]</code>
          и <code>[Peer]</code> — откройте блокнотом и посмотрите.</p>
          <p style="margin-bottom:var(--s-2)">2. Возможно, у продавца второго впн закончилась
          подписка или сервер временно лежит. Попробуйте тот же конфиг на телефоне напрямую —
          если и там не работает, дело в нём.</p>
          <p>3. Зайдите в раздел <strong>Пинг</strong>, выберите «Только напрямую»
          и проверьте адрес второго впн — он показан на странице «Подключение».
          Если ответа нет, сервер недоступен.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Какая страна будет, если второй впн упадёт</summary>
        <div class="qa__a">
          <p style="margin-bottom:var(--s-2)">По умолчанию интернет продолжит работать —
          трафик пойдёт через этот сервер. Сайты в этот момент увидят его страну,
          а не ту, что вы подключали. Когда второй впн оживёт, всё вернётся само.</p>
          <p>Если так нельзя — на странице <strong>Подключение</strong> внизу есть
          блок <strong>«Если второй впн упадёт»</strong>. Выберите там
          <strong>Kill Switch</strong> — тогда интернет будет отключаться полностью,
          и ни один запрос точно не уйдёт с адреса этого сервера.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">У клиентов пропал интернет</summary>
        <div class="qa__a">
          <p style="margin-bottom:var(--s-2)">Значит включён <strong>Kill Switch</strong>,
          а второй впн сейчас недоступен. Так и задумано: лучше без интернета,
          чем с чужого адреса.</p>
          <p style="margin-bottom:var(--s-2)">Быстрое решение: на странице <strong>Подключение</strong>
          в блоке <strong>«Если второй впн упадёт»</strong> поставьте
          <strong>«Продолжать работу напрямую»</strong> и нажмите Сохранить.
          Интернет появится через несколько секунд, но уже без второй страны.</p>
          <p>Если Kill Switch выключен, а интернета всё равно нет — проблема
          не в настройке. Проверьте статус на странице Подключение.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Показывает не ту страну</summary>
        <div class="qa__a">
          <p style="margin-bottom:var(--s-2)">Если показывает <strong>европейскую</strong> —
          значит второй впн не подключён. Посмотрите статус на странице «Подключение».</p>
          <p>Если показывает <strong>вашу собственную</strong> — устройство вообще не подключено
          к впн. Проверьте, включён ли переключатель в приложении WireGuard.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Всё работает, но медленно</summary>
        <div class="qa__a">
          <p style="margin-bottom:var(--s-2)">Трафик проходит через два сервера подряд —
          это всегда медленнее прямого подключения.</p>
          <p>Если тормозит конкретный сервис — добавьте его адрес в
          <strong>Обход VPN</strong>, он пойдёт коротким путём.
          Если тормозит вообще всё — скорее всего медленный второй впн, попробуйте другого продавца.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Связь пропадает и появляется сама</summary>
        <div class="qa__a">
          <p style="margin-bottom:var(--s-2)">Сервер следит за связью и восстанавливает её
          автоматически за несколько секунд — вмешиваться не нужно.</p>
          <p>Посмотрите раздел <strong>События</strong>: там видно, как часто это происходит.
          Если обрывы постоянные — проблема на стороне второго впн.</p>
        </div>
      </details>

      <details class="qa__item">
        <summary class="qa__q">Забыл пароль от панели</summary>
        <div class="qa__a">
          Пароль выдаётся при установке сервера и хранится у того, кто его ставил.
          Восстановить через панель нельзя — обратитесь к продавцу услуги.
        </div>
      </details>

    </div>
  </div>

  <!-- ══ Текущее состояние ══ -->
  <?php if (!$hasConfig): ?>
    <div class="callout callout--info">
      <span class="callout__mark">👉</span>
      <span>
        Сейчас второй впн не загружен — сервер работает как обычный WireGuard.
        Чтобы включить двойной впн, перейдите в раздел <strong>Подключение</strong>
        и загрузите конфиг.
      </span>
    </div>
  <?php elseif ($up): ?>
    <div class="callout callout--ok">
      <span class="callout__mark">✅</span>
      <span>Сейчас всё настроено и работает: трафик идёт через второй впн.</span>
    </div>
  <?php else: ?>
    <div class="callout callout--warn">
      <span class="callout__mark">⚠️</span>
      <span>
        Конфиг второго впн загружен, но связи с ним нет. Посмотрите раздел
        <strong>«Если не работает»</strong> выше — первый вопрос про это.
      </span>
    </div>
  <?php endif; ?>

</div>

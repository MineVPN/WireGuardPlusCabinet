#!/bin/bash
# ══════════════════════════════════════════════════════════════════
#  WGPlus — VPN Health Check daemon (v3)
# ══════════════════════════════════════════════════════════════════
#
# systemd Type=simple, Restart=always. Заменяет старую связку
# service+timer (oneshot), которая не хранила состояние между запусками
# и поэтому не могла делать cooldown, backoff и детект flapping.
#
# Архитектура WGPlus:
#   wg0  — сервер для клиентов (подсеть определяется ДИНАМИЧЕСКИ из wg0.conf)
#   wg1  — исходящий туннель к внешнему VPN
#   Цепочка: ip rule from <подсеть> table 120 -> table 120: default dev wg1
#   wg1.conf ставится с 'Table = off' — маршрутов wg1 в main-таблице нет.
#
# v3: подсеть больше НЕ захардкожена. Читается из 'Address =' в wg0.conf,
#     адрес сети считается битовой арифметикой — работает на любой маске,
#     не только на /24.
#
# Синхронизация с панелью: /var/www/wg-state (STATE=running|stopped|busy).
# Пока панель выполняет операцию (busy) — daemon не вмешивается. Это
# устраняет гонку, из-за которой между 'stop' и 'rm' туннель поднимался
# обратно и оставался осиротевший интерфейс.
# ══════════════════════════════════════════════════════════════════

INTERFACE="wg1"
WG_CONFIG="/etc/wireguard/wg1.conf"
WG0_CONF="/etc/wireguard/wg0.conf"
TABLE_ID="120"
STATE_FILE="/var/www/wgplus/state"
NIC_FILE="/var/www/html/NIC.txt"
ROUTES_FILE="/var/www/wgplus/routes.txt"
SETTINGS_FILE="/var/www/wgplus/settings"
PANEL_PORT=8998          # адрес из lk.txt должен работать ВСЕГДА
SSH_PORT=22
LOG_DIR="/var/log/wgplus"
# ОДИН журнал на всю систему — туда же пишет панель.
# Формат одинаковый, различается только метка источника.
LOG="${LOG_DIR}/wgplus.log"
LOCK="/run/wg-healthcheck.lock"
MAX_LOG=2097152          # 2 MB
KEEP_LINES=1500

PING_HOSTS=("8.8.8.8" "1.1.1.1" "9.9.9.9")
PING_TIMEOUT=2           # таймаут одного ping (с)
CHECK_INTERVAL=2         # период цикла — связь проверяется каждые 2с
RETRY_DELAY=0.4          # пауза перед второй попыткой ping (с)
POLL_MAX=10              # макс ожидание подъёма после перезапуска (с)
WARMUP_TIMEOUT=120       # ждём стабилизации после старта (с)
COOLDOWN_INITIAL=10      # начальная пауза между попытками (с)
COOLDOWN_MAX=60          # потолок паузы (с)
BUSY_STALE=180           # если панель забыла снять busy (с)
RELOAD_INTERVAL=300      # перечитывание WAN и подсети wg0
DOWN_DEDUP=300           # одну и ту же причину не повторяем чаще (с)

# Значения по умолчанию — перезаписываются load_wg0_net()
WG0_GW="10.55.55.1"
WG0_SUBNET="10.55.55.0/24"

# ═══════════════════════════════════════════════════════
# ЛОГИРОВАНИЕ
# ═══════════════════════════════════════════════════════

rotate() {
    local f="$1" sz
    [ -f "$f" ] || return
    sz=$(stat -c%s "$f" 2>/dev/null || echo 0)
    if [ "$sz" -gt "$MAX_LOG" ]; then
        tail -n "$KEEP_LINES" "$f" > "${f}.tmp" 2>/dev/null && mv -f "${f}.tmp" "$f"
    fi
}

log() {
    mkdir -p "$LOG_DIR" 2>/dev/null
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$1] [демон] $2" >> "$LOG"
    chmod 664 "$LOG" 2>/dev/null || true
    logger -t "WGPlus" "[$1] $2"
    rotate "$LOG"
}

# Совместимость: раньше события писались отдельным кодом в свой файл,
# и одно действие попадало в журнал дважды — прозой и кодом.
# Теперь вызовы оставлены как есть, но ничего не пишут: вся информация
# уже есть в соседней строке log() нормальным текстом.
log_event() { :; }

# ═══════════════════════════════════════════════════════
# ПОДСЕТЬ wg0 — динамически, без хардкода
# ═══════════════════════════════════════════════════════

ip_to_int() {
    local a b c d
    IFS=. read -r a b c d <<< "$1"
    echo $(( (a << 24) + (b << 16) + (c << 8) + d ))
}

int_to_ip() {
    local i=$1
    echo "$(( (i >> 24) & 255 )).$(( (i >> 16) & 255 )).$(( (i >> 8) & 255 )).$(( i & 255 ))"
}

# Читает 'Address = X.X.X.X/NN' из wg0.conf и вычисляет адрес сети.
# Строковые трюки вида ${SUBNET::-4} работают только на /24 — здесь
# честная битовая маска, поэтому любая адресация подходит.
load_wg0_net() {
    local addr gw prefix mask net_int
    [ -f "$WG0_CONF" ] || { log "WARN" "Не найден $WG0_CONF — использую подсеть по умолчанию $WG0_SUBNET"; return; }

    addr=$(grep -oP '^\s*Address\s*=\s*\K[0-9.]+/[0-9]+' "$WG0_CONF" 2>/dev/null | head -1)
    [ -z "$addr" ] && { log "WARN" "В $WG0_CONF нет Address — использую $WG0_SUBNET"; return; }

    gw="${addr%%/*}"
    prefix="${addr##*/}"

    if ! [[ "$prefix" =~ ^[0-9]+$ ]] || [ "$prefix" -lt 8 ] || [ "$prefix" -gt 30 ]; then
        log "WARN" "Некорректная маска /$prefix в $WG0_CONF — использую $WG0_SUBNET"
        return
    fi
    if ! [[ "$gw" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
        log "WARN" "Некорректный адрес $gw в $WG0_CONF — использую $WG0_SUBNET"
        return
    fi

    mask=$(( (0xFFFFFFFF << (32 - prefix)) & 0xFFFFFFFF ))
    net_int=$(( $(ip_to_int "$gw") & mask ))

    local new_subnet="$(int_to_ip $net_int)/${prefix}"
    if [ "$new_subnet" != "$WG0_SUBNET" ]; then
        log "INFO" "Подсеть клиентов: $new_subnet (шлюз $gw)"
    fi
    WG0_GW="$gw"
    WG0_SUBNET="$new_subnet"
}

# ═══════════════════════════════════════════════════════
# STATE
# ═══════════════════════════════════════════════════════

read_state() {
    VPN_STATE="running"
    BUSY_SINCE=0
    [ -f "$STATE_FILE" ] || return
    local line
    while IFS= read -r line; do
        case "$line" in
            STATE=*)      VPN_STATE="${line#STATE=}" ;;
            BUSY_SINCE=*) BUSY_SINCE="${line#BUSY_SINCE=}" ;;
        esac
    done < "$STATE_FILE"
    [[ "$BUSY_SINCE" =~ ^[0-9]+$ ]] || BUSY_SINCE=0
}

# Атомарно: tmp -> mv -> chmod. chmod ПОСЛЕ mv обязателен: mv переносит
# владельца tmp-файла (root, umask 022 => 0644), и www-data теряет запись.
save_state() {
    local tmp="${STATE_FILE}.hc.tmp"
    if printf 'STATE=%s\nBUSY_SINCE=0\n' "$1" > "$tmp" 2>/dev/null && mv -f "$tmp" "$STATE_FILE"; then
        chown root:www-data "$STATE_FILE" 2>/dev/null || true
        chmod 664 "$STATE_FILE" 2>/dev/null || true
    else
        rm -f "$tmp" 2>/dev/null
    fi
}

# ═══════════════════════════════════════════════════════
# ПРОВЕРКИ
# ═══════════════════════════════════════════════════════

config_exists() { [ -f "$WG_CONFIG" ]; }
iface_exists()  { ip link show "$INTERFACE" &>/dev/null; }
iface_has_ip()  { ip -4 addr show "$INTERFACE" 2>/dev/null | grep -q "inet "; }

load_nic() {
    WAN_IF=""
    [ -f "$NIC_FILE" ] && WAN_IF=$(head -1 "$NIC_FILE" 2>/dev/null | tr -d '[:space:]')
    if [ -z "$WAN_IF" ] || ! ip link show "$WAN_IF" &>/dev/null; then
        WAN_IF=$(ip route show default 2>/dev/null | grep -v 'dev wg\|dev tun' | grep -oP 'dev \K\S+' | head -1)
    fi
    [ -z "$WAN_IF" ] && log "WARN" "Не удалось определить WAN-интерфейс"
}

# Параллельный ping по трём хостам — один заблокированный адрес
# не должен давать ложный вердикт "VPN лежит".
ping_via() {
    local via="$1"
    [ -z "$via" ] && return 1
    local flag
    flag=$(mktemp /tmp/wghc.XXXXXX) || return 1
    rm -f "$flag"
    local h pids=()
    for h in "${PING_HOSTS[@]}"; do
        ( ping -c 1 -W "$PING_TIMEOUT" -I "$via" "$h" &>/dev/null && : > "$flag" ) &
        pids+=($!)
    done
    wait "${pids[@]}" 2>/dev/null
    if [ -f "$flag" ]; then rm -f "$flag"; return 0; fi
    rm -f "$flag"
    return 1
}

ping_wan() {
    [ -z "$WAN_IF" ] && return 0
    ping_via "$WAN_IF"
}

# Возраст последнего handshake в секундах (-1 если его не было).
#
# Только для диагностики — показать в журнале, как давно туннель молчит.
# Раньше свежий handshake считался достаточным доказательством жизни,
# и ping не делался вовсе. Но WireGuard обновляет handshake раз в ~2 минуты —
# значит упавший туннель выглядел живым до 3 минут.
handshake_age() {
    local hs now
    hs=$(wg show "$INTERFACE" latest-handshakes 2>/dev/null | awk '{print $2}' | sort -rn | head -1)
    if [ -z "$hs" ] || [ "$hs" = "0" ]; then echo "-1"; return 1; fi
    now=$(date +%s)
    echo $((now - hs))
}

# Связь через туннель: ping с адреса шлюза — тот же путь, что у клиентов.
ping_tunnel() { ping_via "$WG0_GW"; }

# Проверка связи, корректная В ЛЮБОМ режиме.
#
# ПРОБЛЕМА, КОТОРУЮ РЕШАЕТ:
# в режиме обхода правило 'from <подсеть> table 120' снято, поэтому
# обычный ping с адреса шлюза уходит через NIC и ВСЕГДА успешен — даже
# когда сервер второго впн выключен. Демон решал «туннель ожил», возвращал
# правило, связь снова падала — и так по кругу каждые 13 секунд.
#
# Решение: на время проверки добавляем узкое правило только для адреса
# шлюза (один /32) с preference 90. Клиентский трафик не затрагивается:
# у клиентов другие адреса, они продолжают идти напрямую.
probe_tunnel() {
    if [ "${CHAIN_BYPASSED:-0}" -eq 0 ]; then
        ping_tunnel
        return $?
    fi
    local rc=0
    ip rule add from "$WG0_GW" table "$TABLE_ID" preference 90 2>/dev/null
    ping_tunnel || rc=1
    ip rule del from "$WG0_GW" table "$TABLE_ID" preference 90 2>/dev/null
    return $rc
}

# Запись причины падения без спама: одна и та же причина попадает
# в журнал не чаще раза в DOWN_DEDUP секунд. Без этого при длительной
# аварии журнал забивается одной строкой каждые 5 секунд.
note_down() {
    local reason="$1" now age
    now=$(date +%s)
    if [ "$reason" = "$LAST_DOWN_REASON" ] && [ $((now - LAST_DOWN_AT)) -lt "$DOWN_DEDUP" ]; then
        return 0
    fi
    LAST_DOWN_REASON="$reason"
    LAST_DOWN_AT=$now
    age=$(handshake_age)
    if [ "$age" -ge 0 ] 2>/dev/null; then
        log "WARN" "Туннель не работает: ${reason} (последний отклик ${age}с назад)"
    else
        log "WARN" "Туннель не работает: ${reason} (связи с узлом не было вовсе)"
    fi
}

has_chain_rule() {
    ip rule show 2>/dev/null | grep -q "from ${WG0_SUBNET} lookup \(vpnchain\|${TABLE_ID}\)"
}

# Правило, выводящее локальный трафик подсети из-под цепочки.
# Без него ответ сервера клиенту (источник = адрес шлюза, он В подсети)
# попадает под "from <подсеть> table 120" и уходит в wg1. Ломает панель
# на адресе шлюза, ping шлюза и трафик между клиентами.
has_local_rule() {
    ip rule show 2>/dev/null | grep -q "to ${WG0_SUBNET} lookup main"
}

# Проверка ПОРЯДКА правил, а не только их наличия.
# Локальное правило ОБЯЗАНО иметь номер МЕНЬШЕ, чем цепочка — иначе оно
# бесполезно. Такое бывает, если цепочку добавили без preference после
# локального правила — тогда она получает 99 и перехватывает всё.
rules_order_ok() {
    local loc chain
    loc=$(ip rule show 2>/dev/null | grep "to ${WG0_SUBNET} lookup main" | head -1 | cut -d: -f1)
    chain=$(ip rule show 2>/dev/null | grep "from ${WG0_SUBNET} lookup \(vpnchain\|${TABLE_ID}\)" | head -1 | cut -d: -f1)
    [ -z "$loc" ] || [ -z "$chain" ] && return 1
    [ "$loc" -lt "$chain" ]
}

# Переставляет правила в правильном порядке.
fix_rules_order() {
    log "WARN" "Правила маршрутизации в неверном порядке — переставляю"
    # Сносим все копии обоих правил (их могло накопиться несколько)
    while ip rule show 2>/dev/null | grep -q "from ${WG0_SUBNET} lookup \(vpnchain\|${TABLE_ID}\)"; do
        ip rule del from "$WG0_SUBNET" table "$TABLE_ID" 2>/dev/null || break
    done
    while ip rule show 2>/dev/null | grep -q "to ${WG0_SUBNET} lookup main"; do
        ip rule del to "$WG0_SUBNET" lookup main 2>/dev/null || break
    done
    ip rule add to "$WG0_SUBNET" lookup main preference 100 2>/dev/null
    ip rule add from "$WG0_SUBNET" table "$TABLE_ID" preference 32765 2>/dev/null
    if rules_order_ok; then
        log "OK" "Порядок правил восстановлен (100 локальный, 32765 цепочка)"
        log_event rules_reordered
    else
        log "ERR" "Не удалось восстановить порядок правил"
    fi
}

has_chain_route() {
    ip route show table "$TABLE_ID" 2>/dev/null | grep -q "^default dev ${INTERFACE}"
}

# Self-heal без перезапуска VPN: network events (carrier down на WAN,
# рестарт networking) сносят правила из PostUp, а wg-quick их назад не ставит.
heal_chain() {
    local healed=0
    # ПОРЯДОК ВАЖЕН: сначала локальное правило (preference 100),
    # оно должно срабатывать раньше, чем правило цепочки (32765).
    if ! has_local_rule; then
        log "WARN" "Потеряно правило 'to ${WG0_SUBNET} lookup main' — восстанавливаю"
        ip rule add to "$WG0_SUBNET" lookup main preference 100 2>/dev/null && healed=1
    fi
    # В режиме временного обхода правило цепочки снято НАМЕРЕННО —
    # восстанавливать его здесь значит сразу же снова отобрать интернет.
    if [ "${CHAIN_BYPASSED:-0}" -eq 0 ] && ! has_chain_rule; then
        log "WARN" "Потеряно ip rule (from ${WG0_SUBNET} table ${TABLE_ID}) — восстанавливаю"
        # preference ЗАДАЁМ ЯВНО: без него ip rule берёт номер на 1 меньше
        # текущего минимума и окажется ВЫШЕ правила 100 — тогда цепочка
        # перехватит локальный трафик и сломает панель и связь между клиентами.
        ip rule add from "$WG0_SUBNET" table "$TABLE_ID" preference 32765 2>/dev/null && healed=1
    fi
    if iface_exists && ! has_chain_route; then
        log "WARN" "Потерян default route в таблице ${TABLE_ID} — восстанавливаю"
        ip route replace default dev "$INTERFACE" table "$TABLE_ID" 2>/dev/null && healed=1
    fi
    if [ "$healed" -eq 1 ]; then
        log "OK" "Цепочка маршрутизации восстановлена без перезапуска VPN"
        log_event chain_healed "$WG0_SUBNET"
    fi
}

# ══════════════════════════════════════════════════════
# ВРЕМЕННЫЙ ОБХОД — работа напрямую, пока второй впн лежит
# ══════════════════════════════════════════════════════
#
# ПОЧЕМУ НЕДОСТАТОЧНО ПРАВИЛ iptables:
# правило 'from <подсеть> table 120' забирает весь трафик клиентов в таблицу 120,
# а там default ведёт в wg1. Если интерфейс есть, но связи нет — пакеты
# просто гибнут в нём. До FORWARD дело вообще не доходит, поэтому разрешающее
# правило 'wg0 -> NIC ACCEPT' ничего не давало: клиенты сидели без интернета
# всё время, пока демон перезапускал туннель.
#
# Решение: снять само правило — тогда трафик проваливается в main-таблицу
# и уходит через NIC. Когда туннель оживёт — правило возвращается.

enter_failover() {
    # ИДЕМПОТЕНТНО: смотрим на ФАКТИЧЕСКОЕ наличие правила, а не на флаг.
    #
    # Почему важно: после каждого restart_tunnel срабатывает PostUp из wg1.conf,
    # который добавляет правило обратно — даже если туннель так и не заработал.
    # С проверкой по флагу мы бы решили "уже в обходе" и ничего не сделали —
    # а клиенты снова остались без интернета до следующего цикла.
    if has_chain_rule; then
        while ip rule show 2>/dev/null | grep -q "from ${WG0_SUBNET} lookup \(vpnchain\|${TABLE_ID}\)"; do
            ip rule del from "$WG0_SUBNET" table "$TABLE_ID" preference 32765 2>/dev/null || break
        done
        # Сообщаем только при первом переходе, иначе журнал забьётся
        # одной строкой после каждого перезапуска.
        [ "${CHAIN_BYPASSED:-0}" -eq 0 ] && \
            log "WARN" "Второй впн недоступен — клиенты временно выходят напрямую через этот сервер"
    fi
    CHAIN_BYPASSED=1
}

exit_failover() {
    [ "${CHAIN_BYPASSED:-0}" -eq 0 ] && return 0
    ip rule add from "$WG0_SUBNET" table "$TABLE_ID" preference 32765 2>/dev/null
    CHAIN_BYPASSED=0
    log "OK" "Второй впн вернулся — трафик снова идёт через него"
}

# ВАЖЕН ПОРЯДОК: ping с source-адресом шлюза идёт тем же путём, что и трафик
# клиентов. Но если default route в таблице 120 пропал, пакет проваливается
# в main, уходит через NIC под MASQUERADE и ping УСПЕШЕН при мёртвом туннеле —
# ложноположительный результат и одновременно реальная утечка. Поэтому
# маршрут проверяется ПЕРВЫМ.
tunnel_alive() {
    iface_exists || return 1
    iface_has_ip || return 1
    has_chain_route || return 1
    probe_tunnel
}

# ═══════════════════════════════════════════════════════
# РЕЖИМЫ МАРШРУТИЗАЦИИ КЛИЕНТСКОГО ТРАФИКА
# ═══════════════════════════════════════════════════════
#
# ДВА РЕЖИМА, переключаются АВТОМАТИЧЕСКИ по наличию wg1.conf:
#
#   direct — второго конфига НЕТ.
#            Работаем как обычный WireGuard-сервер: клиенты выходят
#            в интернет через NIC сервера.
#
#   chain  — wg1.conf есть.
#            Весь трафик идёт только через wg1. Выход через NIC закрыт,
#            иначе при падении wg1 трафик тихо пойдёт с реальным IP
#            сервера, а пользователь этого не заметит. Исключение —
#            адреса из routes.txt (цепочка WGPLUS_BYPASS).
#            killswitch=false в настройках отключает эту блокировку.
#
# Почему этим занимается демон: иначе www-data пришлось бы дать sudo на
# iptables — а это практически root. Панель только кладёт wg1.conf,
# а режим переключается сам в течение 5 секунд.

killswitch_enabled() {
    # По умолчанию ВЫКЛЮЧЕН: если второй впн ляжет, клиенты продолжат
    # работать через этот сервер. Остаться без интернета вообще хуже
    # для большинства сценариев, чем временно выйти с другого адреса.
    # Включается переключателем в панели на странице «Подключение».
    [ -f "$SETTINGS_FILE" ] || return 1
    grep -q '^killswitch=true$' "$SETTINGS_FILE"
}

# Без iptables все нижеследующие вызовы молча провалятся (у них 2>/dev/null).
iptables_available() {
    command -v iptables >/dev/null 2>&1
}

# Сносит ВСЕ наши правила FORWARD для wg0 — перед установкой нового режима.
# while — потому что от предыдущих запусков могли остаться дубли.
clear_forward_rules() {
    [ -z "$WAN_IF" ] && return 0
    while iptables -C FORWARD -i wg0 -o "$INTERFACE" -j ACCEPT 2>/dev/null; do
        iptables -D FORWARD -i wg0 -o "$INTERFACE" -j ACCEPT 2>/dev/null || break
    done
    while iptables -C FORWARD -i "$INTERFACE" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null; do
        iptables -D FORWARD -i "$INTERFACE" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || break
    done
    while iptables -C FORWARD -i wg0 -j WGPLUS_BYPASS 2>/dev/null; do
        iptables -D FORWARD -i wg0 -j WGPLUS_BYPASS 2>/dev/null || break
    done
    while iptables -C FORWARD -i wg0 -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null; do
        iptables -D FORWARD -i wg0 -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null || break
    done
    while iptables -C FORWARD -i wg0 -o "$WAN_IF" -j ACCEPT 2>/dev/null; do
        iptables -D FORWARD -i wg0 -o "$WAN_IF" -j ACCEPT 2>/dev/null || break
    done
    while iptables -C FORWARD -i "$WAN_IF" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null; do
        iptables -D FORWARD -i "$WAN_IF" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || break
    done
}

# Ожидаемый режим по текущему состоянию системы.
desired_mode() {
    if config_exists; then echo "chain"; else echo "direct"; fi
}

# Проверяет, стоят ли правила заявленного режима.
mode_rules_ok() {
    [ -z "$WAN_IF" ] && return 0
    case "$1" in
        direct)
            iptables -C FORWARD -i wg0 -o "$WAN_IF" -j ACCEPT 2>/dev/null
            ;;
        chain)
            iptables -C FORWARD -i wg0 -o "$INTERFACE" -j ACCEPT 2>/dev/null || return 1
            if killswitch_enabled; then
                iptables -C FORWARD -i wg0 -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null
            else
                return 0
            fi
            ;;
    esac
}

apply_forward_mode() {
    local mode="$1"
    if ! iptables_available; then
        log "ERR" "iptables не найден — правила FORWARD не настроены"
        return 1
    fi
    if [ -z "$WAN_IF" ]; then
        log "ERR" "WAN-интерфейс не определён — правила FORWARD не настроены"
        return 1
    fi

    iptables -N WGPLUS_BYPASS 2>/dev/null || true
    clear_forward_rules

    if [ "$mode" = "direct" ]; then
        # Обычный WireGuard-сервер: выход через NIC.
        iptables -A FORWARD -i wg0 -o "$WAN_IF" -j ACCEPT
        iptables -A FORWARD -i "$WAN_IF" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT
        log "OK" "Режим: прямой выход через ${WAN_IF} (второго конфига нет)"
        log_event mode_direct
    else
        # Цепочка: только через wg1.
        iptables -A FORWARD -i wg0 -o "$INTERFACE" -j ACCEPT
        iptables -A FORWARD -i "$INTERFACE" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT
        iptables -A FORWARD -i wg0 -j WGPLUS_BYPASS
        if killswitch_enabled; then
            iptables -A FORWARD -i wg0 -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable
            log "OK" "Режим: весь трафик через ${INTERFACE}, аварийный выход закрыт (Kill Switch)"
        else
            iptables -A FORWARD -i wg0 -o "$WAN_IF" -j ACCEPT
            iptables -A FORWARD -i "$WAN_IF" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT
            log "OK" "Режим: весь трафик через ${INTERFACE}, при его падении — напрямую"
        fi
        log_event mode_chain
    fi

    CURRENT_MODE="$mode"
    iptables-save > /etc/iptables/rules.v4 2>/dev/null || true
    BYPASS_HASH=""   # цепочку обхода пересоберём
    return 0
}

# Проверка каждые 15с: сменился ли режим или снесли ли правила.
check_forward_mode() {
    iptables_available || return 0
    local now want
    now=$(date +%s)
    [ $((now - LAST_KS_CHECK)) -lt 15 ] && return 0
    LAST_KS_CHECK=$now
    [ -z "$WAN_IF" ] && return 0

    want=$(desired_mode)
    if [ "$want" != "$CURRENT_MODE" ]; then
        log "INFO" "Смена режима: ${CURRENT_MODE:-неизвестен} -> ${want}"
        apply_forward_mode "$want"
    elif ! mode_rules_ok "$want"; then
        log "WARN" "Правила FORWARD для режима '${want}' потеряны — восстанавливаю"
        apply_forward_mode "$want"
        log_event forward_rules_restored "$want"
    fi
}

# Синхронизация списка обхода из routes.txt. Пересобираем только при
# изменении файла — иначе дёргали бы iptables каждые несколько секунд.
sync_bypass() {
    local now hash ip count=0
    iptables_available || return 0
    now=$(date +%s)
    [ $((now - LAST_BYPASS_SYNC)) -lt 10 ] && return 0
    LAST_BYPASS_SYNC=$now

    hash=$(md5sum "$ROUTES_FILE" 2>/dev/null | awk '{print $1}')
    [ "$hash" = "$BYPASS_HASH" ] && return 0
    BYPASS_HASH="$hash"

    iptables -N WGPLUS_BYPASS 2>/dev/null || true
    iptables -F WGPLUS_BYPASS 2>/dev/null || true

    if [ -f "$ROUTES_FILE" ]; then
        while IFS= read -r ip || [ -n "$ip" ]; do
            ip=$(printf '%s' "$ip" | tr -d '[:space:]')
            [ -z "$ip" ] && continue
            # Файл пишет веб-панель — валидируем, не доверяем содержимому.
            [[ "$ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || continue
            iptables -A WGPLUS_BYPASS -d "$ip" -j ACCEPT 2>/dev/null && count=$((count + 1))
        done < "$ROUTES_FILE"
    fi

    log "INFO" "Bypass-цепочка синхронизирована: ${count} адресов"
    iptables-save > /etc/iptables/rules.v4 2>/dev/null || true
}

# ГАРАНТИЯ ДОСТУПА к панели, SSH и порту WireGuard.
#
# Требование: URL из lk.txt (http://<адрес>:8998) должен работать ВСЕГДА —
# и когда клиент подключён к VPN, и когда нет.
#
# Почему это нужно: apply_killswitch и sync_bypass делают iptables-save в rules.v4.
# Если в этот момент INPUT-правила окажутся сбиты (iptables -F, чужой
# скрипт, ошибка админа) — сломанное состояние сохранится навсегда,
# и доступ к серверу будет потерян до консоли хостера.
# Проверяем каждые 30с и восстанавливаем.
ensure_access_rules() {
    iptables_available || return 0
    local now
    now=$(date +%s)
    [ $((now - LAST_ACCESS_CHECK)) -lt 30 ] && return 0
    LAST_ACCESS_CHECK=$now

    local changed=0

    if ! iptables -C INPUT -p tcp --dport "$PANEL_PORT" -j ACCEPT 2>/dev/null; then
        iptables -I INPUT -p tcp --dport "$PANEL_PORT" -j ACCEPT 2>/dev/null && changed=1
        log "WARN" "Восстановлен доступ к панели (tcp/${PANEL_PORT})"
        log_event panel_access_restored
    fi

    if ! iptables -C INPUT -p tcp --dport "$SSH_PORT" -j ACCEPT 2>/dev/null; then
        iptables -I INPUT -p tcp --dport "$SSH_PORT" -j ACCEPT 2>/dev/null && changed=1
        log "WARN" "Восстановлен доступ по SSH (tcp/${SSH_PORT})"
    fi

    # Порт WireGuard берём из конфига — он случайный на каждой установке.
    local wgport
    wgport=$(grep -oP '^\s*ListenPort\s*=\s*\K\d+' "$WG0_CONF" 2>/dev/null | head -1)
    if [ -n "$wgport" ] && ! iptables -C INPUT -p udp --dport "$wgport" -j ACCEPT 2>/dev/null; then
        iptables -I INPUT -p udp --dport "$wgport" -j ACCEPT 2>/dev/null && changed=1
        log "WARN" "Восстановлен порт WireGuard (udp/${wgport})"
    fi

    [ "$changed" -eq 1 ] && iptables-save > /etc/iptables/rules.v4 2>/dev/null
    return 0
}

# ═══════════════════════════════════════════════════════
# УПРАВЛЕНИЕ
# ═══════════════════════════════════════════════════════

# Осиротевший = конфига нет, а интерфейс висит. 'wg-quick down' тут бесполезен
# (нужен конфиг), и новый конфиг не поднять — 'wg-quick up' падает с
# "RTNETLINK answers: File exists".
kill_orphan() {
    log "WARN" "Осиротевший интерфейс ${INTERFACE} (конфига нет) — удаляю"
    ip link delete dev "$INTERFACE" 2>/dev/null
    ip rule del from "$WG0_SUBNET" table "$TABLE_ID" 2>/dev/null
    local i=0
    while [ $i -lt 6 ]; do
        iface_exists || { log "OK" "Осиротевший интерфейс удалён"; log_event orphan_removed; return 0; }
        sleep 1; i=$((i + 1))
    done
    log "ERR" "Не удалось удалить интерфейс ${INTERFACE}"
    return 1
}

restart_tunnel() {
    log "INFO" "Перезапуск ${INTERFACE}..."
    systemctl restart "wg-quick@${INTERFACE}" &>/dev/null
    local i=0
    while [ $i -lt "$POLL_MAX" ]; do
        sleep 1; i=$((i + 1))
        if tunnel_alive; then
            log "OK" "${INTERFACE} восстановлен за ${i}с"
            return 0
        fi
        # PostUp вернул правило цепочки — а туннель ещё не работает.
        # Снимаем его сразу, чтобы клиенты не теряли интернет на время попытки.
        [ "${CHAIN_BYPASSED:-0}" -eq 1 ] && enter_failover
    done
    log "WARN" "${INTERFACE} не поднялся за ${POLL_MAX}с"
    return 1
}

do_recovery() {
    local now
    now=$(date +%s)
    [ "$COOLDOWN_UNTIL" -gt 0 ] && [ "$now" -lt "$COOLDOWN_UNTIL" ] && return 1

    if restart_tunnel; then
        if [ "$LAST_OK" -gt 0 ] && [ $((now - LAST_OK)) -lt 120 ]; then
            COOLDOWN=$COOLDOWN_INITIAL
            COOLDOWN_UNTIL=$((now + COOLDOWN_INITIAL))
            log "WARN" "Частые перезапуски (flapping) — cooldown ${COOLDOWN_INITIAL}с"
        else
            COOLDOWN=0; COOLDOWN_UNTIL=0
        fi
        LAST_OK=$(date +%s)
        log_event tunnel_recovered
        return 0
    fi

    COOLDOWN=$((COOLDOWN + COOLDOWN_INITIAL))
    [ "$COOLDOWN" -gt "$COOLDOWN_MAX" ] && COOLDOWN=$COOLDOWN_MAX
    COOLDOWN_UNTIL=$(( $(date +%s) + COOLDOWN ))
    log "INFO" "Cooldown ${COOLDOWN}с до следующей попытки"
    return 1
}

# ═══════════════════════════════════════════════════════
# ГЛАВНЫЙ ЦИКЛ
# ═══════════════════════════════════════════════════════

main_loop() {
    COOLDOWN=0; COOLDOWN_UNTIL=0; LAST_OK=0; LAST_RELOAD=0
    LAST_DOWN_REASON=""; LAST_DOWN_AT=0; CHAIN_BYPASSED=0
    LAST_KS_CHECK=0; LAST_BYPASS_SYNC=0; BYPASS_HASH=""; LAST_ACCESS_CHECK=0; CURRENT_MODE=""
    WAN_STATE="ok"; WAN_DOWN_SINCE=0; vpn_ok=0

    mkdir -p "$LOG_DIR" 2>/dev/null
    load_wg0_net
    load_nic
    LAST_RELOAD=$(date +%s)
    log "INFO" "WGPlus Health Check v3 запущен (подсеть=${WG0_SUBNET}, WAN=${WAN_IF:-неизвестен})"

    # Kill Switch выставляем СРАЗУ на старте, до warmup: если туннель после
    # загрузки не поднимется, трафик клиентов не должен утечь наружу
    # в этот промежуток.
    # Режим выставляем СРАЗУ по факту наличия wg1.conf.
    apply_forward_mode "$(desired_mode)" || log "CRIT" "Не удалось настроить правила FORWARD"
    sync_bypass

    # Доступ к панели проверяем сразу после Kill Switch: если правила
    # были сбиты до старта демона — восстановим до того, как iptables-save
    # зафиксирует сломанное состояние.
    ensure_access_rules

    # Локальное правило маршрутизации — должно стоять даже если wg1 ещё нет:
    # без него сломается трафик между клиентами сразу, как только появится
    # правило цепочки.
    if ! has_local_rule; then
        ip rule add to "$WG0_SUBNET" lookup main preference 100 2>/dev/null \
            && log "OK" "Добавлено правило 'to ${WG0_SUBNET} lookup main'"
    fi
    # На старых установках цепочка могла быть добавлена без preference и
    # оказаться выше локального правила — проверяем и правим при старте.
    rules_order_ok || fix_rules_order

    trap 'log "INFO" "Daemon остановлен"; exit 0' TERM INT

    # WARMUP: wg-quick@wg1 и daemon стартуют параллельно, handshake занимает
    # 5-15с. Без паузы daemon увидит "нет связи" и начнёт рестартить исправный туннель.
    if config_exists; then
        local warmup_start elapsed=0
        warmup_start=$(date +%s)
        log "INFO" "Warmup: ждём стабилизации туннеля (до ${WARMUP_TIMEOUT}с)"
        while [ "$elapsed" -lt "$WARMUP_TIMEOUT" ]; do
            read_state
            [ "$VPN_STATE" = "stopped" ] && break
            config_exists || break
            if tunnel_alive; then
                log "OK" "Туннель стабилен через ${elapsed}с warmup"
                vpn_ok=1; LAST_OK=$(date +%s)
                break
            fi
            sleep 3
            elapsed=$(( $(date +%s) - warmup_start ))
        done
        [ "$vpn_ok" -eq 0 ] && log "WARN" "Туннель не поднялся за warmup — переходим в обычный режим"
    fi

    while true; do
        read_state

        # Kill Switch и список обхода проверяем ПЕРВЫМ ДЕЛОМ, до всех выходов из цикла.
        #
        # ПОЧЕМУ ИМЕННО ЗДЕСЬ: раньше эти вызовы стояли ПОСЛЕ проверок
        # busy/stopped с их `continue` — и в состоянии stopped (конфиг удалён,
        # туннеля нет) Kill Switch не проверялся вообще. Если в этот момент
        # правила сносил iptables -F или перезагрузка rules.v4 — весь трафик
        # клиентов уходил через NIC с реальным IP, и daemon этого не замечал.
        # Именно в stopped защита нужна больше всего — туннеля нет совсем.
        #
        # Во время busy это тоже безопасно: мы трогаем только iptables, а не wg1,
        # так что с операциями панели не конфликтуем. routes.txt панель пишет
        # атомарно (tmp -> rename), поэтому недочитать половину файла нельзя.
        # Режим маршрутизации и список обхода проверяем ПЕРВЫМ ДЕЛОМ,
        # до всех выходов из цикла — именно здесь переключение direct <-> chain
        # после того, как панель залила или удалила wg1.conf.
        check_forward_mode
        sync_bypass
        ensure_access_rules

        # Панель занята — в туннель не вмешиваемся. Это и есть защита от гонки.
        if [ "$VPN_STATE" = "busy" ]; then
            now=$(date +%s)
            if [ "$BUSY_SINCE" -gt 0 ] && [ $((now - BUSY_SINCE)) -gt "$BUSY_STALE" ]; then
                log "WARN" "Флаг busy висит >${BUSY_STALE}с (панель упала?) — снимаю"
                save_state "running"
            fi
            sleep 2; continue
        fi

        if [ "$VPN_STATE" = "stopped" ]; then
            vpn_ok=0
            sleep "$CHECK_INTERVAL"; continue
        fi

        # Конфига нет — мониторить нечего. Висящий интерфейс = сирота, сносим.
        if ! config_exists; then
            iface_exists && kill_orphan
            vpn_ok=0
            sleep "$CHECK_INTERVAL"; continue
        fi

        # Периодически перечитываем подсеть и WAN — их могли поменять
        # (переустановка wg0, смена интерфейса) без перезапуска daemon.
        now=$(date +%s)
        if [ $((now - LAST_RELOAD)) -ge "$RELOAD_INTERVAL" ]; then
            load_wg0_net; load_nic; LAST_RELOAD=$now
        fi

        if [ "$WAN_STATE" = "down" ]; then
            if ping_wan; then
                local dur=$(( $(date +%s) - WAN_DOWN_SINCE ))
                log "OK" "Интернет на сервере восстановлен (был недоступен ${dur}с)"
                log_event isp_restored "${dur}с"
                WAN_STATE="ok"; COOLDOWN=0; COOLDOWN_UNTIL=0
                if ! tunnel_alive; then
                    heal_chain
                    tunnel_alive || restart_tunnel
                fi
            else
                sleep "$CHECK_INTERVAL"
            fi
            continue
        fi

        # КАСКАД ПРОВЕРОК — каждая говорит, ЧТО именно сломалось.
        # Раньше была одна общая проверка — в журнале всегда было "нет связи",
        # без понимания причины.
        if ! iface_exists; then
            note_down "интерфейс ${INTERFACE} пропал"
            vpn_ok=0
            killswitch_enabled || enter_failover
            do_recovery && vpn_ok=1
            sleep "$CHECK_INTERVAL"; continue
        fi

        if ! iface_has_ip; then
            note_down "на интерфейсе нет IP-адреса"
            vpn_ok=0
            killswitch_enabled || enter_failover
            do_recovery && vpn_ok=1
            sleep "$CHECK_INTERVAL"; continue
        fi

        # Если Kill Switch включили в панели, пока действовал обход —
        # возвращаем правило сразу, не дожидаясь подъёма туннеля.
        [ "${CHAIN_BYPASSED:-0}" -eq 1 ] && killswitch_enabled && exit_failover

        # В режиме обхода правило цепочки снято намеренно — не считаем это поломкой.
        if [ "${CHAIN_BYPASSED:-0}" -eq 0 ]; then
            if ! has_chain_rule || ! has_chain_route || ! has_local_rule; then
                heal_chain
            fi
            # Правила могут быть ВСЕ на месте, но в неверном порядке —
            # тогда локальное правило не работает, хотя и существует.
            rules_order_ok || fix_rules_order
        else
            # Локальное правило нужно в любом случае: без него ломается
            # связь между клиентами и доступ к панели по адресу шлюза.
            has_local_rule || ip rule add to "$WG0_SUBNET" lookup main preference 100 2>/dev/null
        fi

        # Связь проверяется пингом каждые 2с — без опоры на handshake.
        # Две попытки с короткой паузой: одиночная потеря пакета не повод
        # для переключения, но и тянуть целую секунду незачем.
        if ! probe_tunnel; then
            sleep "$RETRY_DELAY"
            if ! probe_tunnel; then
                # Прежде чем винить туннель — жив ли интернет самого сервера?
                # Если лёг интернет самого сервера — туннель не виноват, дёргать его бессмысленно.
                if ! ping_wan; then
                    log "WARN" "Интернет на сервере недоступен — туннель не трогаем, ждём"
                    WAN_STATE="down"; WAN_DOWN_SINCE=$(date +%s); vpn_ok=0
                    sleep "$CHECK_INTERVAL"; continue
                fi
                note_down "нет связи через туннель"
                vpn_ok=0
                # Kill Switch выключен — отдаём трафик напрямую, пока чиним туннель.
                killswitch_enabled || enter_failover
                do_recovery && vpn_ok=1
                sleep "$CHECK_INTERVAL"; continue
            fi
        fi

        if [ "$vpn_ok" -eq 0 ]; then
            vpn_ok=1; LAST_OK=$(date +%s); COOLDOWN=0; COOLDOWN_UNTIL=0
            LAST_DOWN_REASON=""; LAST_DOWN_AT=0
            log "OK" "Туннель работает"
        fi

        # Туннель жив — возвращаем трафик в цепочку, если был обход.
        # Проверка стоит здесь, а не в начале цикла: возвращать трафик в туннель
        # можно только после того, как убедились, что он реально работает.
        exit_failover

        sleep "$CHECK_INTERVAL"
    done
}

# flock — защита от двух одновременных экземпляров
exec 200>"$LOCK"
if ! flock -n 200; then
    echo "Другой экземпляр wg-healthcheck уже запущен" >&2
    exit 1
fi

main_loop

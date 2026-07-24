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
SETTINGS_FILE="/var/www/wgplus-settings"
PANEL_PORT=8998          # адрес из lk.txt должен работать ВСЕГДА
SSH_PORT=22
LOG_DIR="/var/log/wgplus"
LOG="${LOG_DIR}/health.log"
EVENTS="${LOG_DIR}/events.log"
LOCK="/run/wg-healthcheck.lock"
MAX_LOG=2097152          # 2 MB
KEEP_LINES=800

PING_HOSTS=("8.8.8.8" "1.1.1.1" "9.9.9.9")
PING_TIMEOUT=2
CHECK_INTERVAL=5
HANDSHAKE_MAX_AGE=180
POLL_MAX=15
WARMUP_TIMEOUT=90
COOLDOWN_INITIAL=15
COOLDOWN_MAX=120
BUSY_STALE=180
RELOAD_INTERVAL=300      # перечитывание WAN и подсети wg0

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
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$1] $2" >> "$LOG"
    logger -t "WGPlus" "[$1] $2"
    rotate "$LOG"
}

# Событие для журнала в веб-панели. Формат TIME|TYPE|F1|F2 —
# тот же файл пишет PHP через wgp_event(), журнал получается единый.
log_event() {
    mkdir -p "$LOG_DIR" 2>/dev/null
    local type="$1"; shift
    local line="$(date '+%Y-%m-%d %H:%M:%S')|${type}" f
    for f in "$@"; do
        f=$(printf '%s' "$f" | tr '|\n\r' '/  ')
        line="${line}|${f}"
    done
    echo "$line" >> "$EVENTS"
    chmod 666 "$EVENTS" 2>/dev/null || true
    rotate "$EVENTS"
}

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

# Свежий handshake — самый надёжный признак живого WireGuard: не зависит
# ни от маршрутов, ни от policy routing. На простаивающем туннеле handshake
# стареет, поэтому "не свежий" != "мёртв" — это лишь повод проверить пингом.
handshake_fresh() {
    local hs now age
    hs=$(wg show "$INTERFACE" latest-handshakes 2>/dev/null | awk '{print $2}' | sort -rn | head -1)
    [ -z "$hs" ] || [ "$hs" = "0" ] && return 1
    now=$(date +%s)
    age=$((now - hs))
    [ "$age" -lt "$HANDSHAKE_MAX_AGE" ]
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
    if ! has_chain_rule; then
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

# ВАЖЕН ПОРЯДОК: ping с source-адресом шлюза идёт тем же путём, что и трафик
# клиентов. Но если default route в таблице 120 пропал, пакет проваливается
# в main, уходит через NIC под MASQUERADE и ping УСПЕШЕН при мёртвом туннеле —
# ложноположительный результат и одновременно реальная утечка. Поэтому
# маршрут проверяется ПЕРВЫМ.
tunnel_alive() {
    iface_exists || return 1
    iface_has_ip || return 1
    has_chain_route || return 1
    handshake_fresh && return 0
    ping_via "$WG0_GW"
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
    # По умолчанию включён: отсутствие файла настроек не должно означать утечку.
    [ -f "$SETTINGS_FILE" ] || return 0
    ! grep -q '^killswitch=false$' "$SETTINGS_FILE"
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
            log "OK" "Режим: цепочка через ${INTERFACE}, выход мимо VPN закрыт"
        else
            iptables -A FORWARD -i wg0 -o "$WAN_IF" -j ACCEPT
            iptables -A FORWARD -i "$WAN_IF" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT
            log "WARN" "Режим: цепочка, но Kill Switch выключён — при падении ${INTERFACE} возможна утечка IP"
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
                log "OK" "Интернет провайдера восстановлен (был недоступен ${dur}с)"
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

        if ! iface_exists; then
            [ "$vpn_ok" -eq 1 ] && { log "WARN" "Интерфейс ${INTERFACE} пропал"; log_event tunnel_down "интерфейс пропал"; vpn_ok=0; }
            do_recovery && vpn_ok=1
            sleep "$CHECK_INTERVAL"; continue
        fi

        if ! has_chain_rule || ! has_chain_route || ! has_local_rule; then
            heal_chain
        fi
        # Правила могут быть ВСЕ на месте, но в неверном порядке —
        # тогда локальное правило не работает, хотя и существует.
        rules_order_ok || fix_rules_order

        if ! tunnel_alive; then
            sleep 1
            if ! tunnel_alive; then
                # Прежде чем винить VPN — жив ли интернет провайдера?
                if ! ping_wan; then
                    log "WARN" "Интернет провайдера недоступен — VPN не трогаем, ждём"
                    log_event isp_down
                    WAN_STATE="down"; WAN_DOWN_SINCE=$(date +%s); vpn_ok=0
                    sleep "$CHECK_INTERVAL"; continue
                fi
                [ "$vpn_ok" -eq 1 ] && { log "WARN" "Нет связи через ${INTERFACE}"; log_event tunnel_down "нет связи"; vpn_ok=0; }
                do_recovery && vpn_ok=1
                sleep "$CHECK_INTERVAL"; continue
            fi
        fi

        if [ "$vpn_ok" -eq 0 ]; then
            vpn_ok=1; LAST_OK=$(date +%s); COOLDOWN=0; COOLDOWN_UNTIL=0
            log "OK" "Туннель стабилен"
            log_event tunnel_up
        fi

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

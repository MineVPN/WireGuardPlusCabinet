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
STATE_FILE="/var/www/wg-state"
NIC_FILE="/var/www/html/NIC.txt"
ROUTES_FILE="/var/www/html/routes.txt"
SETTINGS_FILE="/var/www/wgplus-settings"
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
        chmod 666 "$STATE_FILE" 2>/dev/null || true
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

has_chain_route() {
    ip route show table "$TABLE_ID" 2>/dev/null | grep -q "^default dev ${INTERFACE}"
}

# Self-heal без перезапуска VPN: network events (carrier down на WAN,
# рестарт networking) сносят правила из PostUp, а wg-quick их назад не ставит.
heal_chain() {
    local healed=0
    if ! has_chain_rule; then
        log "WARN" "Потеряно ip rule (from ${WG0_SUBNET} table ${TABLE_ID}) — восстанавливаю"
        ip rule add from "$WG0_SUBNET" table "$TABLE_ID" 2>/dev/null && healed=1
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
# KILL SWITCH
# ═══════════════════════════════════════════════════════
#
# ПРОБЛЕМА, КОТОРУЮ РЕШАЕМ:
# При падении wg1 трафик клиентов проваливается из таблицы 120 в main,
# уходит через NIC и попадает под MASQUERADE из инсталлятора — то есть
# продолжает работать, но уже с РЕАЛЬНЫМ IP сервера. Пользователь этого
# не замечает: интернет есть, VPN «работает». Это утечка по построению.
#
# РЕШЕНИЕ: FORWARD из wg0 наружу разрешён только в туннель. Исключение —
# цепочка WGPLUS_BYPASS с адресами из routes.txt (страница «Route»).
#
# ПОЧЕМУ ЭТИМ ЗАНИМАЕТСЯ ДЕМОН, А НЕ ПАНЕЛЬ:
# иначе www-data пришлось бы дать sudo на iptables — а это уже практически
# root. Демон и так работает от root, читает routes.txt и синхронизирует
# цепочку сам. Панель просто пишет файл.

killswitch_enabled() {
    # По умолчанию включён: отсутствие файла настроек не должно означать утечку.
    [ -f "$SETTINGS_FILE" ] || return 0
    ! grep -q '^killswitch=false$' "$SETTINGS_FILE"
}

# Без iptables все нижеследующие вызовы молча провалятся (у них 2>/dev/null),
# и Kill Switch будет считаться активным, не будучи таковым. Проверяем явно.
# Актуально для CentOS с firewalld и минимальных образов без iptables.
iptables_available() {
    command -v iptables >/dev/null 2>&1
}

apply_killswitch() {
    if ! iptables_available; then
        log "ERR" "iptables не найден — Kill Switch НЕ работает, возможна утечка IP"
        return 1
    fi
    if [ -z "$WAN_IF" ]; then
        log "ERR" "WAN-интерфейс не определён — Kill Switch НЕ работает, возможна утечка IP"
        return 1
    fi
    iptables -N WGPLUS_BYPASS 2>/dev/null || true

    # Снимаем свои правила перед добавлением — иначе при повторном вызове
    # они задвоятся, а REJECT окажется не последним.
    iptables -D FORWARD -i wg0 -o "$INTERFACE" -j ACCEPT 2>/dev/null || true
    iptables -D FORWARD -i "$INTERFACE" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || true
    iptables -D FORWARD -i wg0 -j WGPLUS_BYPASS 2>/dev/null || true
    iptables -D FORWARD -i wg0 -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null || true

    iptables -A FORWARD -i wg0 -o "$INTERFACE" -j ACCEPT
    iptables -A FORWARD -i "$INTERFACE" -o wg0 -m state --state RELATED,ESTABLISHED -j ACCEPT
    iptables -A FORWARD -i wg0 -j WGPLUS_BYPASS
    iptables -A FORWARD -i wg0 -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable

    iptables-save > /etc/iptables/rules.v4 2>/dev/null || true
    return 0
}

remove_killswitch() {
    [ -z "$WAN_IF" ] && return 0
    iptables -D FORWARD -i wg0 -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null || true
    iptables-save > /etc/iptables/rules.v4 2>/dev/null || true
}

# Проверка целостности + self-heal. Правила может снести сторонний софт,
# iptables -F или перезапуск networking.
check_killswitch() {
    local now
    now=$(date +%s)
    [ $((now - LAST_KS_CHECK)) -lt 15 ] && return 0
    LAST_KS_CHECK=$now
    iptables_available || return 0
    [ -z "$WAN_IF" ] && return 0

    if ! killswitch_enabled; then
        # Выключён в настройках — снимаем REJECT, если он остался.
        if iptables -C FORWARD -i wg0 -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null; then
            log "INFO" "Kill Switch отключён в настройках — снимаю правило"
            remove_killswitch
        fi
        return 0
    fi

    if ! iptables -C FORWARD -i wg0 -o "$WAN_IF" -j REJECT --reject-with icmp-net-unreachable 2>/dev/null \
       || ! iptables -C FORWARD -i wg0 -o "$INTERFACE" -j ACCEPT 2>/dev/null; then
        log "WARN" "Kill Switch потерян — восстанавливаю"
        if apply_killswitch; then
            log "OK" "Kill Switch восстановлен"
            log_event killswitch_restored
            BYPASS_HASH=""   # цепочку тоже пересоберём
        fi
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
    LAST_KS_CHECK=0; LAST_BYPASS_SYNC=0; BYPASS_HASH=""
    WAN_STATE="ok"; WAN_DOWN_SINCE=0; vpn_ok=0

    mkdir -p "$LOG_DIR" 2>/dev/null
    load_wg0_net
    load_nic
    LAST_RELOAD=$(date +%s)
    log "INFO" "WGPlus Health Check v3 запущен (подсеть=${WG0_SUBNET}, WAN=${WAN_IF:-неизвестен})"

    # Kill Switch выставляем СРАЗУ на старте, до warmup: если туннель после
    # загрузки не поднимется, трафик клиентов не должен утечь наружу
    # в этот промежуток.
    if killswitch_enabled; then
        if apply_killswitch; then
            log "OK" "Kill Switch активен (выход только через ${INTERFACE})"
        else
            # Раньше здесь был `apply_killswitch && log ...` — при отказе не писалось
            # НИЧЕГО. Защитная функция не должна отказывать молча — иначе
            # админ уверен, что защита есть, а её нет.
            log "CRIT" "Не удалось включить Kill Switch — трафик клиентов может уйти мимо VPN"
            log_event killswitch_failed
        fi
    else
        log "WARN" "Kill Switch отключён в настройках — при падении туннеля возможна утечка IP"
    fi
    sync_bypass

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
        check_killswitch
        sync_bypass

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

        if ! has_chain_rule || ! has_chain_route; then
            heal_chain
        fi

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

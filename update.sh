#!/bin/bash
# ══════════════════════════════════════════════════════════════════
#  WGPlus — скрипт обновления панели
# ══════════════════════════════════════════════════════════════════
#
# Запускается по cron (см. /etc/cron.d/wgplus-update) или вручную:
#   sudo bash /var/www/html/update.sh
#
# Раньше этого файла в репозитории НЕ БЫЛО, хотя инсталлятор прописывал
# на него root-cron — то есть задание падало каждую ночь.
#
# Что делает:
#   • git pull --ff-only (без слияний и перезаписи локальных правок)
#   • миграция legacy-пароля из login.php в хеш /var/www/wgplus-auth
#   • переустановка healthcheck daemon с проверкой синтаксиса
#   • восстановление прав на файлы и каталоги
#   • перезапуск служб только если что-то реально изменилось
#
# ВНИМАНИЕ про цепочку поставок: скрипт тянет код из внешнего git-репозитория.
# Компрометация репозитория = компрометация всех серверов. Поэтому:
#   • только --ff-only (никаких merge и force-обновлений)
#   • bash -n перед установкой демона
#   • php -l перед перезапуском Apache
# Если такой риск неприемлем — уберите /etc/cron.d/wgplus-update и
# обновляйтесь вручную.
# ══════════════════════════════════════════════════════════════════

WEB_DIR="/var/www/html"
LOG_DIR="/var/log/wgplus"
LOG="${LOG_DIR}/update.log"
LOCK="/run/wgplus-update.lock"
AUTH_FILE="/var/www/wgplus-auth"
SETTINGS_FILE="/var/www/wgplus-settings"
STATE_FILE="/var/www/wg-state"
HC_SRC="${WEB_DIR}/wg-healthcheck.sh"
HC_DST="/usr/local/bin/wg-healthcheck.sh"

mkdir -p "$LOG_DIR" 2>/dev/null

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$1] $2" >> "$LOG"
    logger -t "WGPlus-update" "[$1] $2"
    [ -t 1 ] && echo "[$1] $2"
}

# Ротация лога обновлений
if [ -f "$LOG" ]; then
    sz=$(stat -c%s "$LOG" 2>/dev/null || echo 0)
    [ "$sz" -gt 1048576 ] && tail -n 500 "$LOG" > "${LOG}.tmp" && mv -f "${LOG}.tmp" "$LOG"
fi

# Только root: скрипт правит /usr/local/bin и перезапускает службы.
if [ "$EUID" -ne 0 ]; then
    log "ERR" "Требуются права root"
    exit 1
fi

# Один экземпляр за раз — cron и ручной запуск не должны пересечься.
exec 200>"$LOCK"
if ! flock -n 200; then
    log "WARN" "Обновление уже выполняется — выходим"
    exit 0
fi

log "INFO" "=== Запуск обновления ==="

# ── 1. Обновление кода ────────────────────────────────────────────
CHANGED=0
if [ -d "${WEB_DIR}/.git" ]; then
    cd "$WEB_DIR" || exit 1
    BEFORE=$(git rev-parse HEAD 2>/dev/null)
    git fetch --quiet origin 2>>"$LOG"
    # --ff-only: если история разошлась (локальные правки) — обновление
    # просто не применится, вместо тихой перезаписи чужих изменений.
    if git merge --ff-only FETCH_HEAD >>"$LOG" 2>&1; then
        AFTER=$(git rev-parse HEAD 2>/dev/null)
        if [ "$BEFORE" != "$AFTER" ]; then
            CHANGED=1
            log "OK" "Код обновлён: ${BEFORE:0:8} -> ${AFTER:0:8}"
        else
            log "INFO" "Обновлений нет"
        fi
    else
        log "WARN" "git merge --ff-only не прошёл (локальные правки?) — код не тронут"
    fi
else
    log "WARN" "${WEB_DIR} не git-репозиторий — пропускаю обновление кода"
fi

# ── 2. Проверка синтаксиса PHP ────────────────────────────────────
# Битый PHP не должен доехать до продакшена вместе с обновлением.
PHP_BAD=0
if command -v php >/dev/null 2>&1; then
    while IFS= read -r f; do
        if ! php -l "$f" >/dev/null 2>&1; then
            log "ERR" "Синтаксическая ошибка в $f"
            PHP_BAD=1
        fi
    done < <(find "$WEB_DIR" -maxdepth 2 -name '*.php' 2>/dev/null)
    [ "$PHP_BAD" -eq 0 ] && log "OK" "PHP-файлы проверены"
fi

# ── 3. Миграция пароля в хеш ──────────────────────────────────────
# Старые установки держали пароль открытым текстом в login.php (в docroot).
# Переносим в хеш вне docroot и затираем исходную строку.
if [ ! -s "$AUTH_FILE" ] && [ -f "${WEB_DIR}/login.php" ]; then
    LEGACY=$(grep -oP "^\\\$truepassword\s*=\s*'\K[^']*" "${WEB_DIR}/login.php" 2>/dev/null | head -1)
    if [ -n "$LEGACY" ] && [ "$LEGACY" != "defaultpass" ]; then
        HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' -- "$LEGACY" 2>/dev/null)
        if [ -n "$HASH" ]; then
            printf '%s\n' "$HASH" > "$AUTH_FILE"
            chown root:www-data "$AUTH_FILE"
            chmod 640 "$AUTH_FILE"
            sed -i "s/\(\$truepassword = '\)[^']*\(';\)/\1\2/" "${WEB_DIR}/login.php"
            log "OK" "Пароль перенесён в хеш ${AUTH_FILE}, открытый текст затёрт"
        else
            log "ERR" "Не удалось создать хеш пароля — миграция отложена"
        fi
    fi
fi

# ── 4. Файлы состояния и настроек ─────────────────────────────────
if [ ! -f "$SETTINGS_FILE" ]; then
    printf 'killswitch=true\n' > "$SETTINGS_FILE"
    chown root:www-data "$SETTINGS_FILE" 2>/dev/null || true
    chmod 664 "$SETTINGS_FILE"
    log "OK" "Создан ${SETTINGS_FILE} (killswitch=true)"
fi
if [ ! -f "$STATE_FILE" ]; then
    printf 'STATE=running\nBUSY_SINCE=0\n' > "$STATE_FILE"
    chmod 666 "$STATE_FILE"
    log "OK" "Создан ${STATE_FILE}"
fi

# ── 5. Права ──────────────────────────────────────────────────────
# git pull мог принести файлы с чужими правами, а chown -R по docroot
# в инсталляторе — сбить владельца ключей.
chown -R root:www-data /etc/wireguard 2>/dev/null || true
chmod 770 /etc/wireguard 2>/dev/null || true
chmod g+s /etc/wireguard 2>/dev/null || true
find /etc/wireguard -type f -exec chmod 660 {} \; 2>/dev/null || true

touch "${LOG_DIR}/panel.log" "${LOG_DIR}/events.log" "${LOG_DIR}/health.log" 2>/dev/null
chmod 666 "${LOG_DIR}/panel.log" "${LOG_DIR}/events.log" 2>/dev/null
chmod 644 "${LOG_DIR}/health.log" 2>/dev/null
[ -f "${WEB_DIR}/routes.txt" ] && chmod 666 "${WEB_DIR}/routes.txt"

# ── 6. Healthcheck daemon ─────────────────────────────────────────
if [ -f "$HC_SRC" ]; then
    if ! bash -n "$HC_SRC" 2>>"$LOG"; then
        log "ERR" "Синтаксическая ошибка в ${HC_SRC} — демон НЕ обновлён"
    else
        NEED_RESTART=0
        if [ ! -f "$HC_DST" ]; then
            NEED_RESTART=1
        elif ! cmp -s "$HC_SRC" "$HC_DST"; then
            NEED_RESTART=1
        fi

        if [ "$NEED_RESTART" -eq 1 ]; then
            cp "$HC_SRC" "$HC_DST"
            chown root:root "$HC_DST"   # www-data не должен мочь переписать демон
            chmod 755 "$HC_DST"
            log "OK" "wg-healthcheck.sh обновлён"

            # Гарантируем, что unit — это daemon, а не старый oneshot+timer.
            if [ ! -f /etc/systemd/system/wg-healthcheck.service ] \
               || ! grep -q 'Type=simple' /etc/systemd/system/wg-healthcheck.service; then
cat > /etc/systemd/system/wg-healthcheck.service << 'EOF'
[Unit]
Description=WGPlus VPN Health Check Daemon
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/wg-healthcheck.sh
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF
                systemctl stop wg-healthcheck.timer &>/dev/null || true
                systemctl disable wg-healthcheck.timer &>/dev/null || true
                rm -f /etc/systemd/system/wg-healthcheck.timer
                log "OK" "systemd unit переведён в режим daemon"
            fi

            systemctl daemon-reload
            systemctl enable wg-healthcheck.service &>/dev/null || true
            systemctl restart wg-healthcheck.service
            log "OK" "wg-healthcheck перезапущен"
        else
            log "INFO" "wg-healthcheck без изменений"
        fi
    fi
fi

# ── 7. Apache ─────────────────────────────────────────────────────
if [ "$CHANGED" -eq 1 ] && [ "$PHP_BAD" -eq 0 ]; then
    systemctl reload apache2 2>>"$LOG" && log "OK" "Apache перезагружен"
fi

log "INFO" "=== Обновление завершено ==="
exit 0

#!/usr/bin/env bash
#
# diagnose-iclock.sh — trace why a ZKTeco terminal never completes its handshake.
#
# Run this ON THE SERVER that hosts the ADMS app (e.g. adms.halobayi.co.id).
# RUN IT AS ROOT (sudo): the nginx vhost directory is usually 0700 root-only,
# and without it the redirect search silently finds nothing.
#
#   sudo bash scripts/diagnose-iclock.sh [SN] [HOST]
#
# Both arguments are optional. HOST is auto-detected from the nginx vhost whose
# `root` points at this project, falling back to APP_URL.
#
# Read-only: two HTTP probes plus log/config/DB reads. It changes nothing.
#
set -u

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$APP_DIR/.env"
LOG_FILE="$APP_DIR/storage/logs/laravel.log"

if [ ! -f "$ENV_FILE" ]; then
    echo "!! $ENV_FILE not found. Run this from inside the project." >&2
    exit 1
fi

env_get() {
    grep -E "^$1=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- \
        | tr -d '"' | tr -d "'" | sed -E 's/[[:space:]]+$//'
}

DB_NAME="$(env_get DB_DATABASE)"
DB_USER="$(env_get DB_USERNAME)"
DB_PASS="$(env_get DB_PASSWORD)"
DB_HOST="$(env_get DB_HOST)"; [ -z "$DB_HOST" ] && DB_HOST=127.0.0.1
DB_PORT="$(env_get DB_PORT)"; [ -z "$DB_PORT" ] && DB_PORT=3306

VHOST_GLOBS="/www/server/panel/vhost/nginx /etc/nginx/conf.d /etc/nginx/sites-enabled /usr/local/nginx/conf"

# The vhost that actually serves this app: the one whose root mentions APP_DIR.
detect_host_from_vhost() {
    local f name
    for f in /www/server/panel/vhost/nginx/*.conf /etc/nginx/conf.d/*.conf \
             /etc/nginx/sites-enabled/* /www/server/nginx/conf/*.conf; do
        [ -f "$f" ] || continue
        grep -q "$APP_DIR" "$f" 2>/dev/null || continue
        name="$(awk '/^[[:space:]]*server_name/ {print $2; exit}' "$f" | tr -d ';')"
        if [ -n "$name" ] && [ "$name" != "_" ]; then
            echo "$name"
            return
        fi
    done
}

HOST="${2:-}"
if [ -z "$HOST" ]; then
    HOST="$(detect_host_from_vhost)"
fi
if [ -z "$HOST" ]; then
    HOST="$(env_get APP_URL | sed -E 's#^[a-z]+://##; s#/.*$##; s#:[0-9]+$##')"
fi
[ -z "$HOST" ] && HOST="adms.halobayi.co.id"

mysql_q() {
    mysql --connect-timeout=5 -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" \
        -N -B "$DB_NAME" -e "$1" 2>/dev/null
}

SN="${1:-}"
if [ -z "$SN" ]; then
    SN="$(mysql_q 'SELECT serial_number FROM devices ORDER BY id LIMIT 1;')"
fi
[ -z "$SN" ] && SN="6339151200543"

hr() { printf '\n%s\n' "----------------------------------------------------------------"; }

echo "ADMS iclock diagnostic"
echo "  app dir : $APP_DIR"
echo "  host    : $HOST"
echo "  serial  : $SN"
echo "  db      : $DB_USER@$DB_HOST:$DB_PORT/$DB_NAME"
echo "  app_url : $(env_get APP_URL)"
echo "  app_env : $(env_get APP_ENV)   debug: $(env_get APP_DEBUG)"
echo "  as root : $([ "$(id -u)" = "0" ] && echo yes || echo 'NO — re-run with sudo, section 5 will be blind')"

hr
echo "0. What is actually listening?"
echo "   (explains 'connection refused' if APP_URL points at a dead port)"
echo
(ss -ltnp 2>/dev/null || netstat -ltnp 2>/dev/null) | grep -E ':80 |:443 |:3006 |nginx|php' | sed 's/^/   /' \
    || echo "   (ss/netstat unavailable)"

hr
echo "1. HTTP :80 — is the request still being redirected?"
echo "   (a 301 here means the terminal never reaches PHP)"
echo
curl -sS -i -m 10 "http://$HOST/iclock/cdata?SN=$SN&options=all" 2>&1 | head -14

hr
echo "2. HTTPS :443 — does the app answer the handshake at all?"
echo "   (expect: HTTP 200 and a body starting with 'GET OPTION FROM:')"
echo "   NOTE: a 200 here WRITES devices.online — that is a real handshake."
echo
curl -sS -i -m 15 "https://$HOST/iclock/cdata?SN=$SN&options=all" 2>&1 | head -14

hr
echo "2b. Every device endpoint, over plain HTTP"
echo "    419 => the route is missing from VerifyCsrfToken::\$except"
echo "    500 => the action threw; check storage/logs/laravel.log"
echo
for p in "cdata?SN=$SN&options=all" "getrequest?SN=$SN" "test?SN=$SN" "rtdata?SN=$SN"; do
    printf '    GET  /iclock/%-30s -> %s\n' "$p" \
        "$(curl -sS -o /dev/null -w '%{http_code}' -m 10 "http://$HOST/iclock/$p" 2>/dev/null)"
done
for p in cdata devicecmd querydata upload-log; do
    printf '    POST /iclock/%-30s -> %s\n' "$p" \
        "$(curl -sS -o /dev/null -w '%{http_code}' -m 10 -X POST "http://$HOST/iclock/$p" 2>/dev/null)"
done

hr
echo "3. Did ANY handshake ever reach PHP?"
echo "   handshake() logs 'call handshake' as its very first statement."
echo "   0  => requests never arrive (web-server layer, keep reading)"
echo "   >0 => they arrive; inspect the entries themselves"
echo
if [ -f "$LOG_FILE" ]; then
    COUNT="$(grep -c 'call handshake' "$LOG_FILE" 2>/dev/null || echo 0)"
    echo "   count: $COUNT"
    if [ "$COUNT" != "0" ]; then
        echo "   last 3 occurrences:"
        grep -n 'call handshake' "$LOG_FILE" | tail -3
    fi
else
    echo "   !! $LOG_FILE not found"
fi

hr
echo "4. EFFECTIVE nginx config — every redirect, wherever it hides"
echo "   'nginx -T' dumps the merged config, so this catches includes too."
echo "   This is the authoritative search; prefer it over grepping files."
echo
if command -v nginx >/dev/null 2>&1; then
    nginx -T 2>/dev/null \
        | grep -nE "return 30[0-9]|rewrite .*(permanent|redirect)|HTTP_TO_HTTPS|HSTS|Strict-Transport" \
        | sed 's/^/   /' || echo "   (no redirect directives found in the merged config)"
    echo
    echo "   context around the first redirect (which file/block):"
    nginx -T 2>/dev/null | grep -nE "^# configuration file|return 30[0-9]|rewrite .*(permanent|redirect)" \
        | sed 's/^/   /' | head -30
else
    echo "   !! nginx binary not on PATH — run: sudo /www/server/nginx/sbin/nginx -T"
fi

hr
echo "5. vhost files on disk"
echo
for d in $VHOST_GLOBS; do
    [ -d "$d" ] || continue
    echo "   --- $d"
    grep -rnE "return 30[0-9]|rewrite .*(permanent|redirect)|HTTP_TO_HTTPS|server_name|ssl_certificate" "$d" 2>/dev/null \
        | head -20 | sed 's/^/     /'
done

hr
echo "6. access log: last lines mentioning iclock"
echo
for f in /www/wwwlogs/*access*.log /var/log/nginx/*access*.log /www/wwwlogs/*.log; do
    [ -f "$f" ] || continue
    HITS="$(grep -c 'iclock' "$f" 2>/dev/null || echo 0)"
    [ "$HITS" = "0" ] && continue
    echo "   --- $f ($HITS hits)"
    grep 'iclock' "$f" | tail -5 | sed 's/^/   /'
done

hr
echo "7. database: devices.online"
echo
mysql_q "SELECT id, name, serial_number, online, idoficina FROM devices;" \
    | sed 's/^/   /' || echo "   (mysql query failed — check credentials in .env)"

hr
echo "8. database: recent device_log / error_log rows"
echo
mysql_q "SELECT id, created_at, url, sn, option FROM device_log ORDER BY id DESC LIMIT 5;" \
    | sed 's/^/   device_log: /'
mysql_q "SELECT * FROM error_log ORDER BY id DESC LIMIT 3;" \
    | sed 's/^/   error_log: /'

hr
cat <<'EOF'
HOW TO READ THIS

  Section 1 -> 301   the redirect is still active. Section 4 names the file and
                     line. On aaPanel this is usually the "Force HTTPS" toggle,
                     which writes:
                         if ($server_port !~ 443){
                             rewrite ^(/.*)$ https://$host$1 permanent;
                         }
                     Replace it with the $redir_https variant so /iclock/ is
                     excluded. A `location ^~ /iclock/` block CANNOT help: the
                     server-level `if` runs in the rewrite phase, before
                     location matching.

  Section 1 -> 200   the redirect is fixed; the terminal can handshake again.

  Section 2 -> 200   the app side is healthy. It also sets devices.online, so
                     the device should read "Online" for the next few minutes.

  Section 3 = 0      requests never reach PHP. Combined with section 2 = 200,
                     that confirms the problem is purely the web-server layer.

  Section 2 -> 200 but section 7 still NULL
                     the DB write is failing; check section 8.

  Section 5 empty even as root
                     the site may be served by Docker or by a proxy in front
                     of this machine. Check section 0 for the real listener.
EOF

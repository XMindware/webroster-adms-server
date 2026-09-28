#!/usr/bin/env bash
#
# fix-iclock-redirect.sh — stop the "Force HTTPS" redirect from swallowing the
# ZKTeco /iclock/ endpoints, so HTTP/1.0 terminals can handshake again.
#
# Run ON THE SERVER, AS ROOT, from the project root:
#
#   sudo bash scripts/fix-iclock-redirect.sh [vhost.conf]
#
# With no argument it targets
#   /www/server/panel/vhost/nginx/<domain>.conf
# where <domain> is taken from the nginx vhost whose `root` points at this
# project. Pass an explicit path to override.
#
# It rewrites only the #HTTP_TO_HTTPS_START ... #HTTP_TO_HTTPS_END block:
#
#   before                                after
#   if ($server_port !~ 443){             set $redir_https 0;
#       rewrite ^(/.*)$ https://...       if ($server_port !~ 443)         { set $redir_https 1; }
#           permanent;                     if ($request_uri ~* "^/iclock/") { set $redir_https 0; }
#   }                                      if ($redir_https = 1)            { rewrite ... permanent; }
#
# Safety: makes a timestamped backup, aborts without writing if the markers are
# not found, and only reloads nginx when `nginx -t` passes. A `location` block
# cannot be used here — the server-level `if` runs in the rewrite phase, before
# location matching.
#
set -u

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ "$(id -u)" != "0" ]; then
    echo "!! run as root (sudo) — the vhost directory is root-only" >&2
    exit 1
fi

NGINX_BIN="$(command -v nginx || echo /www/server/nginx/sbin/nginx)"
[ -x "$NGINX_BIN" ] || { echo "!! nginx binary not found" >&2; exit 1; }

CONF="${1:-}"
if [ -z "$CONF" ]; then
    for f in /www/server/panel/vhost/nginx/*.conf; do
        [ -f "$f" ] || continue
        grep -q "$APP_DIR" "$f" 2>/dev/null || continue
        grep -q 'HTTP_TO_HTTPS_START' "$f" 2>/dev/null || continue
        CONF="$f"
        break
    done
fi

if [ -z "$CONF" ] || [ ! -f "$CONF" ]; then
    echo "!! could not find the vhost. Candidates holding a Force HTTPS block:" >&2
    grep -l 'HTTP_TO_HTTPS_START' /www/server/panel/vhost/nginx/*.conf 2>/dev/null | sed 's/^/   /' >&2
    echo "   re-run with an explicit path: sudo bash $0 <file>" >&2
    exit 1
fi

echo "target vhost : $CONF"

BACKUP="$CONF.bak.$(date +%Y%m%d-%H%M%S)"
cp -a "$CONF" "$BACKUP" || { echo "!! backup failed, aborting" >&2; exit 1; }
echo "backup       : $BACKUP"

python3 - "$CONF" <<'PY'
import sys

path = sys.argv[1]
raw = open(path, 'rb').read().decode('utf-8')
eol = '\r\n' if '\r\n' in raw else '\n'

START, END = '#HTTP_TO_HTTPS_START', '#HTTP_TO_HTTPS_END'
i = raw.find(START)
j = raw.find(END)

if i < 0 or j < 0 or j < i:
    sys.exit('!! markers %s / %s not found — file left untouched' % (START, END))

line_start = raw.rfind('\n', 0, i) + 1
line_end = raw.find('\n', j)
if line_end < 0:
    line_end = len(raw)

block = raw[line_start:line_end]
indent = block[:len(block) - len(block.lstrip())]

new_block = eol.join([
    indent + START,
    indent + 'set $redir_https 0;',
    indent + 'if ($server_port !~ 443)         { set $redir_https 1; }',
    indent + 'if ($request_uri ~* "^/iclock/") { set $redir_https 0; }',
    indent + 'if ($redir_https = 1)            { rewrite ^(/.*)$ https://$host$1 permanent; }',
    indent + END,
])

if '/iclock/' in block:
    sys.exit('!! /iclock/ already exempt in this block — nothing to do')

open(path, 'wb').write((raw[:line_start] + new_block + raw[line_end:]).encode('utf-8'))
print('patched block:\n')
for line in new_block.split(eol):
    print('   ' + line)
PY

STATUS=$?
if [ "$STATUS" != "0" ]; then
    echo "aborted; restoring backup"
    cp -a "$BACKUP" "$CONF"
    exit "$STATUS"
fi

echo
echo "testing config..."
if "$NGINX_BIN" -t 2>&1 | sed 's/^/   /'; then
    "$NGINX_BIN" -s reload && echo "   reloaded"
    echo
    echo "verify with:"
    echo "   curl -s -o /dev/null -w '%{http_code}\\n' \"http://\$(grep -m1 server_name $CONF | awk '{print \$2}' | tr -d ';')/iclock/cdata?SN=TEST&options=all\""
    echo "   expect 200, not 301"
else
    echo "!! nginx -t FAILED — restoring backup"
    cp -a "$BACKUP" "$CONF"
    "$NGINX_BIN" -t 2>&1 | sed 's/^/   /'
    exit 1
fi

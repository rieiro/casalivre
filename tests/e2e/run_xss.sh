#!/usr/bin/env bash
# Sobe php -S com banco semeado e roda o E2E do fix/xss-views.
# Uso: tests/e2e/run_xss.sh <raiz_do_app> <dir_prints> [python]
set -euo pipefail
app="$1"; shots="$2"; py="${3:-python3}"
here="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$shots"
db="$(mktemp -d)/xss.sqlite"
seed="$(cd "$app" && php "$here/seed_xss.php" "$db" 2>/dev/null || php tests/e2e/seed_xss.php "$db")"
email="$(echo "$seed" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["email"];')"
pass="$(echo "$seed" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["password"];')"
hash="$(echo "$seed" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["hash"];')"
port=$((20000 + RANDOM % 20000))
( cd "$app" && DB_DRIVER=sqlite DB_DSN="sqlite:$db" APP_ENV=dev APP_URL="http://127.0.0.1:$port" LOG_SALT=e2e-salt-0123456789abcdef0123456789 \
  exec php -S "127.0.0.1:$port" -t public public/index.php >/dev/null 2>&1 ) &
srv=$!
trap 'kill $srv 2>/dev/null || true' EXIT
for i in $(seq 1 50); do (echo > /dev/tcp/127.0.0.1/$port) 2>/dev/null && break; sleep 0.1; done
"$py" "$here/xss_views.py" "http://127.0.0.1:$port" "$db" "$email" "$pass" "$hash" "$shots"

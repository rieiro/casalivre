#!/usr/bin/env bash
# Sobe php -S com banco semeado e roda o E2E da issue #20.
# Uso: tests/e2e/run_issue20.sh <raiz_do_app> <dir_prints> <before|after> [python]
set -euo pipefail
app="$1"; shots="$2"; mode="$3"; py="${4:-python3}"
here="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$shots"
# PostgreSQL only: E2E_PG_DSN = DSN de administração (como o TEST_PG_DSN, ex. pgsql:host=$CL_HOME/run;dbname=postgres),
# E2E_PG_USER opcional. Cria o banco casalivre_e2e_<pid> e apaga no fim.
: "${E2E_PG_DSN:?defina E2E_PG_DSN (PostgreSQL only; ex.: pgsql:host=/tmp/env/run;dbname=postgres)}"
export DB_USER="${E2E_PG_USER:-}"
dbname="casalivre_e2e_$$"
pgq() { php -r '$p = new PDO($argv[1], getenv("DB_USER") ?: null); $p->exec($argv[2]);' "$E2E_PG_DSN" "$1"; }
pgq "CREATE DATABASE $dbname"
case $E2E_PG_DSN in *dbname=*) db="$(echo "$E2E_PG_DSN" | sed "s/dbname=[^;]*/dbname=$dbname/")" ;; *) db="$E2E_PG_DSN;dbname=$dbname" ;; esac
seed="$(cd "$app" && php "$here/seed_issue20.php" "$db" 2>/dev/null || php tests/e2e/seed_issue20.php "$db")"
email="$(echo "$seed" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["email"];')"
pass="$(echo "$seed" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["password"];')"
hash="$(echo "$seed" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["hash"];')"
port=$((20000 + RANDOM % 20000))
( cd "$app" && DB_DSN="$db" APP_ENV=dev APP_URL="http://127.0.0.1:$port" LOG_SALT=e2e-salt-0123456789abcdef0123456789 \
  exec php -S "127.0.0.1:$port" -t public public/index.php >/dev/null 2>&1 ) &
srv=$!
trap 'kill $srv 2>/dev/null || true; sleep 0.2; pgq "DROP DATABASE IF EXISTS $dbname WITH (FORCE)" || true' EXIT
for i in $(seq 1 50); do (echo > /dev/tcp/127.0.0.1/$port) 2>/dev/null && break; sleep 0.1; done
"$py" "$here/issue20_rooms.py" "http://127.0.0.1:$port" "$db" "$email" "$pass" "$hash" "$shots" "$mode"

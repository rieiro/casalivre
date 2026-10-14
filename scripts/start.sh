#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
if [[ ! -f storage/db/casalivre.sqlite ]]; then
  "$ROOT/scripts/setup.sh"
fi
# .env só preenche o que o ambiente não definiu (APP_ENV=dev scripts/start.sh vence APP_ENV=production do
# .env). Leitura KEY=VALUE segura, sem executar o arquivo.
# shellcheck source=scripts/env-lib.sh
source "$ROOT/scripts/env-lib.sh"
load_env_file "$ROOT/.env"
PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1 && [[ ! -x "$PHP_BIN" ]]; then
  echo "PHP não encontrado. Rode scripts/setup.sh"
  exit 1
fi
mkdir -p storage/sessions storage/logs storage/mail public/uploads/users
"$PHP_BIN" "$ROOT/scripts/load-ceps.php"
PORT="${PORT:-8080}"
BIND="${BIND_ADDR:-0.0.0.0}"
export APP_URL="${APP_URL:-http://127.0.0.1:${PORT}}"
echo "CasaLivre neste computador: http://127.0.0.1:${PORT}"
echo "Na mesma rede, abra no celular:"
if command -v ip >/dev/null 2>&1; then
  ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | while read -r addr; do
    [[ -n "$addr" ]] && echo "  http://${addr}:${PORT}"
  done
else
  echo "  http://IP-DESTE-PC:${PORT}"
fi
echo "Pare com scripts/stop.sh"
exec "$PHP_BIN" \
  -d upload_max_filesize=16M \
  -d post_max_size=128M \
  -d max_file_uploads=40 \
  -d max_input_time=180 \
  -d display_startup_errors=0 \
  -d display_errors=0 \
  -d log_errors=1 \
  -d error_log="$ROOT/storage/logs/php.log" \
  -S "${BIND}:${PORT}" -t public public/index.php

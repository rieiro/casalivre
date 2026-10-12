#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
elif [[ -x /opt/php/php ]]; then
  PHP_BIN="/opt/php/php"
else
  echo "PHP 8.2+ não encontrado. No Debian/Ubuntu: sudo apt install php-cli php-sqlite3 php-mbstring php-gd php-xml"
  exit 1
fi

mkdir -p storage/db storage/logs storage/mail storage/sessions public/uploads/users
chmod 0777 storage/db storage/logs storage/mail storage/sessions public/uploads || true

if [[ ! -f storage/db/casalivre.sqlite ]]; then
  "$PHP_BIN" -r 'exit(0);'
  sqlite3 storage/db/casalivre.sqlite < database/schema.sql 2>/dev/null || \
    "$PHP_BIN" -r '
      $pdo = new PDO("sqlite:storage/db/casalivre.sqlite");
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $pdo->exec(file_get_contents("database/schema.sql"));
      echo "Banco criado\n";
    '
fi

printf 'APP_URL=http://127.0.0.1:8080\nPHP_BIN=%s\nDB_DRIVER=sqlite\nDB_DSN=\nDB_USER=\nDB_PASSWORD=\nLOG_DRIVER=sqlite\nLOG_MONGO_URI=\nLOG_MONGO_DB=\nSTRIPE_SECRET_KEY=\nPAGARME_API_KEY=\nAWS_ACCESS_KEY_ID=\nAWS_SECRET_ACCESS_KEY=\n' "$PHP_BIN" > .env
echo "Infraestrutura pronta em $ROOT"
"$PHP_BIN" "$ROOT/scripts/load-ceps.php"
echo "Banco: storage/db/casalivre.sqlite"
echo "Log de atividades: storage/logs/activity.sqlite (LOG_DRIVER=mongodb para migrar)"
echo "Suba o sistema com: scripts/start.sh"

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

# Pastas de runtime e banco novo a partir de database/schema.sql: mesma rotina do scripts/migrate.php (src/Db.php).
"$PHP_BIN" -r '
  require "src/Db.php";
  Db::ensureStorageDirs();
  $created = Db::ensureSqliteFromSchema();
  if ($created !== null) { echo "Banco criado: $created\n"; }
'
chmod 0777 storage/db storage/logs storage/mail storage/sessions public/uploads || true

# .env: cria (de .env.example ou do zero) ou mantém o existente; garante GEO_SALT (>= 32) e chmod 600.
"$ROOT/scripts/setup-env.sh" "$PHP_BIN"
echo "Infraestrutura pronta em $ROOT"
"$PHP_BIN" "$ROOT/scripts/load-ceps.php"
echo "Banco: storage/db/casalivre.sqlite"
echo "Log de atividades: storage/logs/activity.sqlite (LOG_DRIVER=mongodb para migrar)"
echo "Suba o sistema com: scripts/start.sh"

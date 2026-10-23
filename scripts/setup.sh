#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
elif [[ -x /opt/php/php ]]; then
  PHP_BIN="/opt/php/php"
else
  echo "PHP 8.4 não encontrado. No Debian/Ubuntu: sudo apt install php-cli php-pgsql php-mbstring php-gd php-xml"
  exit 1
fi

# Pastas de runtime: mesma rotina do scripts/migrate.php (src/Db.php).
"$PHP_BIN" -r 'require "src/Db.php"; Db::ensureStorageDirs();'
chmod 0777 storage/db storage/logs storage/mail storage/sessions public/uploads || true

# .env: cria (de .env.example ou do zero) ou mantém o existente; garante GEO_SALT (>= 32) e chmod 600.
"$ROOT/scripts/setup-env.sh" "$PHP_BIN"
echo "Infraestrutura pronta em $ROOT"
# PostgreSQL only: the DB is recreated from scratch (no data migration). The dev env does all of this:
# ops/dev-env/db-setup.sh. By hand, with DB_DSN/DB_USER/DB_PASSWORD in .env and the roles of ops/dev-env/pg-roles.sql:
echo "Banco (só PostgreSQL): psql -f database/schema.pgsql.sql; php scripts/migrate.php; php scripts/load-ceps.php"
echo "  ou use o ambiente dev: ops/dev-env/up.sh -d && ops/dev-env/db-setup.sh"
echo "Suba o sistema com: scripts/start.sh"

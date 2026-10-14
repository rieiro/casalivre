#!/usr/bin/env bash
# Prepara uma cópia descartável do app com banco novo para os testes E2E.
# Uso: prepare-app.sh <APP_DIR> <RUN_DIR>
set -euo pipefail
APP_DIR="$(cd "$1" && pwd)"; RUN_DIR="$2"; HERE="$(cd "$(dirname "$0")/.." && pwd)"
rm -rf "$RUN_DIR"; mkdir -p "$RUN_DIR/app"
# Copia o código sem storage/, vendor/, .git e uploads (banco e arquivos sempre novos).
tar -C "$APP_DIR" --exclude=./storage --exclude=./vendor --exclude=./.git --exclude=./public/uploads --exclude=./.env --exclude=./tests/e2e-playwright/node_modules -cf - . | tar -x -C "$RUN_DIR/app"
cd "$RUN_DIR/app"
bash scripts/setup.sh > "$RUN_DIR/setup.log" 2>&1
# Migrações explícitas, quando o branch tiver (PR #10).
if [[ -f scripts/migrate.php ]]; then php scripts/migrate.php >> "$RUN_DIR/setup.log" 2>&1; fi
grep -q "^LOG_SALT=" .env || echo "LOG_SALT=salt-e2e-local-123" >> .env
# Carga de seeds do repositório, na ordem do prompts/deploy.md.
for s in seed.php seed_massive.php seed-users.php seed-owners.php; do
  if [[ -f scripts/$s ]]; then echo "== $s" >> "$RUN_DIR/setup.log"; php scripts/$s >> "$RUN_DIR/setup.log" 2>&1; fi
done
php "$HERE/fixtures/seed-e2e.php" "$RUN_DIR/app" "$RUN_DIR/fixture.json" >> "$RUN_DIR/setup.log" 2>&1
cp "$HERE/fixtures/feed.ics" public/e2e-feed.ics
echo "App pronto em $RUN_DIR/app"

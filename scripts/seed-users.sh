#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
elif [[ -x /opt/php/php ]]; then
  PHP_BIN="/opt/php/php"
else
  echo "PHP não encontrado." >&2
  exit 1
fi

exec "$PHP_BIN" "$ROOT/scripts/seed.php"

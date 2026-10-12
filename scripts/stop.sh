#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ -f "$ROOT/storage/server.pid" ]]; then
  kill "$(cat "$ROOT/storage/server.pid")" 2>/dev/null || true
  rm -f "$ROOT/storage/server.pid"
fi
pkill -f 'php -S 0.0.0.0:8080' 2>/dev/null || true
pkill -f 'php -S 127.0.0.1:8080' 2>/dev/null || true
echo "Servidor parado."

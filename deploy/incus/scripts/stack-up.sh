#!/usr/bin/env bash
# Master orchestrator: networks/volumes/profiles -> db -> app -> edge host init -> verify.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
EDGE_DIR="$(cd "$ROOT_DIR/../infra-nginx-hardened" && pwd)"

log() { printf '[stack] %s\n' "$*"; }
die() { printf '[stack] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
[ "$(id -u)" -eq 0 ] || die "run as root (the edge init applies nftables/sysctl)"
if [ -z "${APP_DB_PASSWORD:-}" ] && [ ! -r "$ROOT_DIR/secrets/app_db_password" ]; then
  die "set APP_DB_PASSWORD or create $ROOT_DIR/secrets/app_db_password"
fi

log "1/5 networks, ACLs, volumes and profiles"
"$SCRIPT_DIR/00-init-networks.sh"

log "2/5 database tier"
"$SCRIPT_DIR/01-deploy-db.sh"

log "3/5 application tier"
"$SCRIPT_DIR/02-deploy-app.sh"

log "4/5 edge host initialization (../infra-nginx-hardened/scripts/00-init-host.sh)"
"$EDGE_DIR/scripts/00-init-host.sh"

log "5/5 verification"
"$SCRIPT_DIR/verify-stack.sh"

log "stack is up"

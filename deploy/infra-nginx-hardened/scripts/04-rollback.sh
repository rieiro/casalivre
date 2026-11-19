#!/usr/bin/env bash
# Revert host DNAT (80/443) to the previous container and bring it back up.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
STATE_DIR="$ROOT_DIR/artifacts/state"

TARGET="${1:-${TARGET:-}}"
PUBLIC_IFACE="${PUBLIC_IFACE:-eth0}"
TARGET_IP="${TARGET_IP:-}"

log() { printf '[rollback] %s\n' "$*"; }
die() { printf '[rollback] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require nft
require curl

[ "$(id -u)" -eq 0 ] || die "run as root (nftables)"
nft list table inet casalivre_edge >/dev/null 2>&1 || die "nftables table missing; run scripts/00-init-host.sh first"

if [ -z "$TARGET" ]; then
  TARGET="$(cat "$STATE_DIR/previous-container" 2>/dev/null || true)"
fi
[ -n "$TARGET" ] || die "no previous container recorded; pass TARGET=<container>"
incus info "$TARGET" >/dev/null 2>&1 || die "previous container not found: $TARGET"

state="$(incus list "$TARGET" -cs --format csv 2>/dev/null || true)"
if [ "$state" != "RUNNING" ]; then
  log "starting $TARGET"
  incus start "$TARGET"
fi

log "waiting for $TARGET to become healthy"
healthy=0
for _ in $(seq 1 30); do
  if [ -z "$TARGET_IP" ]; then
    TARGET_IP="$(incus list "$TARGET" -c4 --format csv | tr ',' '\n' \
      | grep -Eo '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | head -n1 || true)"
  fi
  if [ -n "$TARGET_IP" ] && curl -fsS --max-time 3 "http://$TARGET_IP:8080/healthz" >/dev/null 2>&1; then
    healthy=1
    break
  fi
  sleep 1
done
[ "$healthy" -eq 1 ] || die "$TARGET did not become healthy; production traffic was not changed"

current_container="$(cat "$STATE_DIR/active-container" 2>/dev/null || true)"
mkdir -p "$STATE_DIR"

log "switching DNAT back to $TARGET ($TARGET_IP)"
nft -f - <<EOF
flush chain inet casalivre_edge prerouting
flush chain inet casalivre_edge forward
add rule inet casalivre_edge prerouting iifname "${PUBLIC_IFACE}" tcp dport 80 dnat ip to ${TARGET_IP}:8080
add rule inet casalivre_edge prerouting iifname "${PUBLIC_IFACE}" tcp dport 443 dnat ip to ${TARGET_IP}:8443
add rule inet casalivre_edge forward ct state established,related accept
add rule inet casalivre_edge forward ip daddr ${TARGET_IP} tcp dport { 8080, 8443 } ct state new accept
EOF

printf '%s\n' "$TARGET"    > "$STATE_DIR/active-container"
printf '%s\n' "$TARGET_IP" > "$STATE_DIR/active-ip"

if [ -n "$current_container" ] && [ "$current_container" != "$TARGET" ]; then
  printf '%s\n' "$current_container" > "$STATE_DIR/previous-container"
  incus config set "$current_container" boot.autostart=false >/dev/null 2>&1 || true
  log "stopping $current_container (kept for inspection)"
  sleep 2
  incus stop "$current_container" >/dev/null 2>&1 || log "warning: could not stop $current_container"
fi

incus config set "$TARGET" boot.autostart=true >/dev/null 2>&1 || true
log "rollback complete: traffic is back on $TARGET ($TARGET_IP)"

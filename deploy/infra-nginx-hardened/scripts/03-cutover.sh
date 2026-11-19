#!/usr/bin/env bash
# Atomically repoint host DNAT (80/443) to the staged green container, then stop blue (kept for rollback).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
STATE_DIR="$ROOT_DIR/artifacts/state"

CONTAINER="${1:-${CONTAINER:-$(cat "$STATE_DIR/pending-container" 2>/dev/null || true)}}"
[ -n "$CONTAINER" ] || CONTAINER="nginx-prod-green"
PUBLIC_IFACE="${PUBLIC_IFACE:-eth0}"
GREEN_IP="${GREEN_IP:-}"

log() { printf '[cutover] %s\n' "$*"; }
die() { printf '[cutover] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require nft
require curl

[ "$(id -u)" -eq 0 ] || die "run as root (nftables)"
nft list table inet casalivre_edge >/dev/null 2>&1 || die "nftables table missing; run scripts/00-init-host.sh first"
incus info "$CONTAINER" >/dev/null 2>&1 || die "green container not found: $CONTAINER"

if [ -z "$GREEN_IP" ]; then
  GREEN_IP="$(cat "$STATE_DIR/pending-ip" 2>/dev/null || true)"
fi
if [ -z "$GREEN_IP" ]; then
  GREEN_IP="$(incus list "$CONTAINER" -c4 --format csv | tr ',' '\n' \
    | grep -Eo '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -n1 || true)"
fi
[ -n "$GREEN_IP" ] || die "could not determine the green IPv4 (pass GREEN_IP=...)"

log "health-checking $CONTAINER at $GREEN_IP:8080"
healthy=0
for _ in $(seq 1 20); do
  if curl -fsS --max-time 3 "http://$GREEN_IP:8080/healthz" >/dev/null 2>&1; then
    healthy=1
    break
  fi
  sleep 1
done
[ "$healthy" -eq 1 ] || die "green is not healthy; production traffic was not changed"

prev_container="$(cat "$STATE_DIR/active-container" 2>/dev/null || true)"
prev_ip="$(cat "$STATE_DIR/active-ip" 2>/dev/null || true)"
if [ -z "$prev_ip" ]; then
  prev_ip="$(nft list chain inet casalivre_edge prerouting 2>/dev/null \
    | grep -Eo 'dnat ip to [0-9.]+' | awk '{print $4}' | head -n1 || true)"
fi

mkdir -p "$STATE_DIR"

# One atomic nftables transaction: flush + add never leaves a half-applied ruleset.
log "switching DNAT to $GREEN_IP (80->8080, 443->8443)"
nft -f - <<EOF
flush chain inet casalivre_edge prerouting
flush chain inet casalivre_edge forward
add rule inet casalivre_edge prerouting iifname "${PUBLIC_IFACE}" tcp dport 80 dnat ip to ${GREEN_IP}:8080
add rule inet casalivre_edge prerouting iifname "${PUBLIC_IFACE}" tcp dport 443 dnat ip to ${GREEN_IP}:8443
add rule inet casalivre_edge forward ct state established,related accept
add rule inet casalivre_edge forward ip daddr ${GREEN_IP} tcp dport { 8080, 8443 } ct state new accept
EOF

printf '%s\n' "$CONTAINER" > "$STATE_DIR/active-container"
printf '%s\n' "$GREEN_IP"  > "$STATE_DIR/active-ip"

if [ -n "$prev_container" ] && [ "$prev_container" != "$CONTAINER" ]; then
  printf '%s\n' "$prev_container" > "$STATE_DIR/previous-container"
  [ -n "$prev_ip" ] && printf '%s\n' "$prev_ip" > "$STATE_DIR/previous-ip"
  incus config set "$prev_container" boot.autostart=false >/dev/null 2>&1 || true
  log "stopping previous container $prev_container (kept for rollback)"
  sleep 2
  incus stop "$prev_container" >/dev/null 2>&1 || log "warning: could not stop $prev_container"
fi

incus config set "$CONTAINER" boot.autostart=true >/dev/null 2>&1 || true
log "cutover complete: traffic is on $CONTAINER ($GREEN_IP)"
log "rollback: sudo $ROOT_DIR/scripts/04-rollback.sh"

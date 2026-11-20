#!/usr/bin/env bash
# Persist the active nginx edge container's static network in-guest.
#
# incusbr-dmz has DHCP disabled, but the Alpine edge image defaults to `iface eth0 inet dhcp`.
# After a reboot the container comes back without an address and nginx (which binds the static
# address) crashes; the host DNAT for 80/443 then points at nothing. This writes a static
# /etc/network/interfaces, applied by OpenRC's `networking` service before nginx starts.
#
# It also moves the OpenRC pidfile off /run/nginx/nginx.pid: start-stop-daemon creates that file
# as root, while nginx runs as the nginx user, so the master cannot open it (EACCES) on boot.
# OpenRC keeps its own /run/nginx/openrc.pid; nginx keeps its configured pid path.
#
# Idempotent: safe to re-run. EDGE=<container> overrides the detected active edge.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

EDGE="${EDGE:-}"
log() { printf '[edge-net] %s\n' "$*"; }
die() { printf '[edge-net] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus

state_of() { incus list "$1" -cs --format csv 2>/dev/null | head -n1; }

if [ -z "$EDGE" ]; then
  EDGE="$(cat "$ROOT_DIR/../infra-nginx-hardened/artifacts/state/active-container" 2>/dev/null || true)"
fi
if [ -z "$EDGE" ]; then
  for c in nginx-prod-blue nginx-prod-green; do
    if [ "$(state_of "$c")" = "RUNNING" ]; then EDGE="$c"; break; fi
  done
fi
[ -n "$EDGE" ] || die "no active nginx-prod-* container found"
[ "$(state_of "$EDGE")" = "RUNNING" ] || die "$EDGE is not RUNNING"

# Address from the instance NIC; gateway from the DMZ bridge (both are static by design).
IP="$(incus config device get "$EDGE" eth0 ipv4.address 2>/dev/null || true)"
if [ -z "$IP" ]; then
  IP="$(incus list "$EDGE" -c4 --format csv | tr ',' '\n' | grep -E '^10\.10\.10\.[0-9]+$' | head -n1)"
fi
[ -n "$IP" ] || die "cannot determine the DMZ address of $EDGE"
case "$IP" in *[!0-9.]*) die "invalid DMZ address: $IP" ;; esac
GW="$(incus network get incusbr-dmz ipv4.address 2>/dev/null | cut -d/ -f1 || true)"
[ -n "$GW" ] || GW="10.10.10.1"

# 1. Static in-guest network (keep one copy of the image's DHCP file for reference).
incus exec "$EDGE" -- sh -c 'test -f /etc/network/interfaces.casalivre.bak || cp -a /etc/network/interfaces /etc/network/interfaces.casalivre.bak'
incus exec "$EDGE" -- sh -c 'cat > /etc/network/interfaces' <<NET
auto lo
iface lo inet loopback

# CasaLivre edge: static address (incusbr-dmz has DHCP disabled).
auto eth0
iface eth0 inet static
    address $IP/24
    gateway $GW
NET

# 2. OpenRC pidfile: start-stop-daemon creates it as root; nginx runs as nginx.
incus exec "$EDGE" -- sh -c 'if grep -q "^pidfile=\"/run/nginx/nginx.pid\"" /etc/init.d/nginx-hardened; then sed -i "s|^pidfile=\"/run/nginx/nginx.pid\"|pidfile=\"/run/nginx/openrc.pid\"|" /etc/init.d/nginx-hardened; fi'

# 3. Apply now, without restarting a healthy edge.
if ! incus exec "$EDGE" -- sh -c "ip -4 addr show eth0 | grep -q 'inet $IP/'"; then
  incus exec "$EDGE" -- rc-service networking restart
fi
if ! incus exec "$EDGE" -- rc-service nginx-hardened status >/dev/null 2>&1; then
  incus exec "$EDGE" -- rc-service nginx-hardened start
fi

log "$EDGE: static $IP/24 via $GW; OpenRC pidfile at /run/nginx/openrc.pid"

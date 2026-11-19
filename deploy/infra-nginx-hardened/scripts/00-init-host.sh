#!/usr/bin/env bash
# First-time host initialisation: Incus profiles, DMZ ACL, IPv4 forwarding and the nftables skeleton.
# Idempotent: safe to re-run after editing a profile or the ACL.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

log() { printf '[init]  %s\n' "$*"; }
die() { printf '[init] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require nft
require sed

[ "$(id -u)" -eq 0 ] || die "run as root (this script changes sysctl and nftables)"

DMZ_NETWORK="${DMZ_NETWORK:-incusbr0}"
BUILDER_NETWORK="${BUILDER_NETWORK:-incusbr0}"
STORAGE_POOL="${STORAGE_POOL:-default}"
BACKEND_IP="${BACKEND_IP:-}"
BACKEND_PORT="${BACKEND_PORT:-}"
PUBLIC_IFACE="${PUBLIC_IFACE:-eth0}"

[ -n "$BACKEND_IP" ]   || die "BACKEND_IP is required (the upstream the edge is allowed to reach)"
[ -n "$BACKEND_PORT" ] || die "BACKEND_PORT is required (e.g. 8080)"

incus network show "$DMZ_NETWORK" >/dev/null 2>&1 || die "Incus network not found: $DMZ_NETWORK"
incus storage show "$STORAGE_POOL" >/dev/null 2>&1 || die "Incus storage pool not found: $STORAGE_POOL"

# Default the DNS/NTP egress to the DMZ bridge gateway.
bridge_cidr="$(incus network get "$DMZ_NETWORK" ipv4.address 2>/dev/null || true)"
bridge_gw="${bridge_cidr%%/*}"
DNS_RESOLVER="${DNS_RESOLVER:-$bridge_gw}"
NTP_SERVER="${NTP_SERVER:-$bridge_gw}"
[ -n "$DNS_RESOLVER" ] || die "could not derive DNS_RESOLVER; set it explicitly"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

render() { # render <src> <dst>
  local src="$1" dst="$2"
  sed \
    -e "s|__DMZ_NETWORK__|${DMZ_NETWORK}|g" \
    -e "s|__BUILDER_NETWORK__|${BUILDER_NETWORK}|g" \
    -e "s|__STORAGE_POOL__|${STORAGE_POOL}|g" \
    -e "s|__BACKEND_IP__|${BACKEND_IP}|g" \
    -e "s|__BACKEND_PORT__|${BACKEND_PORT}|g" \
    -e "s|__DNS_RESOLVER__|${DNS_RESOLVER}|g" \
    -e "s|__NTP_SERVER__|${NTP_SERVER}|g" \
    -e "s|__PUBLIC_IFACE__|${PUBLIC_IFACE}|g" \
    "$src" > "$dst"
}

apply_profile() { # apply_profile <name> <yaml>
  local name="$1" src="$2" out="$tmp/$name.yaml"
  render "$src" "$out"
  if incus profile show "$name" >/dev/null 2>&1; then
    incus profile edit "$name" < "$out"
    log "profile updated: $name"
  else
    incus profile create "$name"
    incus profile edit "$name" < "$out"
    log "profile created: $name"
  fi
}

apply_profile casalivre-nginx-prod    "$ROOT_DIR/incus/profile-prod.yaml"
apply_profile casalivre-nginx-builder "$ROOT_DIR/incus/profile-builder.yaml"

# DMZ ACL (rendered: backend IP/port, DNS and NTP).
acl_out="$tmp/acl-dmz.yaml"
render "$ROOT_DIR/incus/acl-dmz.yaml" "$acl_out"
if incus network acl show casalivre-nginx-dmz >/dev/null 2>&1; then
  incus network acl edit casalivre-nginx-dmz < "$acl_out"
  log "ACL updated: casalivre-nginx-dmz"
else
  incus network acl create casalivre-nginx-dmz
  incus network acl edit casalivre-nginx-dmz < "$acl_out"
  log "ACL created: casalivre-nginx-dmz"
fi

# Persist IPv4 forwarding for the DNAT path.
if [ "$(sysctl -n net.ipv4.ip_forward 2>/dev/null || echo 0)" != "1" ]; then
  sysctl -w net.ipv4.ip_forward=1 >/dev/null
  log "enabled net.ipv4.ip_forward"
fi
printf 'net.ipv4.ip_forward = 1\n' > /etc/sysctl.d/99-casalivre-edge.conf

# nftables skeleton. The active DNAT is applied by 03-cutover/04-rollback.
if nft list table inet casalivre_edge >/dev/null 2>&1; then
  log "nftables table inet casalivre_edge already present"
else
  nft add table inet casalivre_edge
  nft add chain inet casalivre_edge prerouting '{ type nat hook prerouting priority dstnat; policy accept; }'
  nft add chain inet casalivre_edge forward '{ type filter hook forward priority filter; policy accept; }'
  log "nftables table inet casalivre_edge created"
fi

log "done: backend ${BACKEND_IP}:${BACKEND_PORT}, DMZ ${DMZ_NETWORK}, DNS/NTP ${DNS_RESOLVER}"
log "next: $ROOT_DIR/scripts/01-build-artifact.sh"

#!/usr/bin/env bash
# First-time host initialisation for the edge module: Incus profiles, DMZ ACL, IPv4 forwarding and
# the nftables skeleton. Reuses a DMZ bridge already created by /deploy/incus. Idempotent.
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

# 3-tier contract: edge on incusbr-dmz (10.10.10.0/24), app-php (PHP-FPM) at 10.10.10.20:9000.
DMZ_NETWORK="${DMZ_NETWORK:-incusbr-dmz}"
BUILDER_NETWORK="${BUILDER_NETWORK:-incusbr0}"
STORAGE_POOL="${STORAGE_POOL:-default}"
PUBLIC_IFACE="${PUBLIC_IFACE:-eth0}"
DMZ_SUBNET="${DMZ_SUBNET:-10.10.10.0/24}"

incus storage show "$STORAGE_POOL" >/dev/null 2>&1 || die "Incus storage pool not found: $STORAGE_POOL"

# The DMZ bridge is owned by /deploy/incus/scripts/00-init-networks.sh: reuse it, never recreate it.
if incus network show "$DMZ_NETWORK" >/dev/null 2>&1; then
  log "reusing existing network: $DMZ_NETWORK (expected subnet $DMZ_SUBNET)"
else
  die "network $DMZ_NETWORK not found; run /deploy/incus/scripts/00-init-networks.sh first"
fi

bridge_cidr="$(incus network get "$DMZ_NETWORK" ipv4.address 2>/dev/null || true)"
case "$bridge_cidr" in
  10.10.10.*) : ;;
  '') log "warning: $DMZ_NETWORK has no IPv4 address; set DNS_RESOLVER/NTP_SERVER explicitly" ;;
  *)  log "warning: $DMZ_NETWORK is $bridge_cidr, expected $DMZ_SUBNET" ;;
esac

# Default the DNS/NTP egress to the bridge gateway.
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

# DMZ ACL (rendered: DNS/NTP; the FastCGI backend is fixed at 10.10.10.20:9000).
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

log "done: edge on ${DMZ_NETWORK} (${DMZ_SUBNET}), PHP-FPM 10.10.10.20:9000"
log "next: $ROOT_DIR/scripts/01-build-artifact.sh"

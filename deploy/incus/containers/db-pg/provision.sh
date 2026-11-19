#!/usr/bin/env bash
# In-container provisioning for db-pg (Void Linux glibc + runit).
# The caller attaches a temporary DMZ NIC for xbps and removes it immediately afterwards.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PG_VERSION="${PG_VERSION:-17}"

log() { printf '[db-pg provision] %s\n' "$*"; }
die() { printf '[db-pg provision] ERROR: %s\n' "$*" >&2; exit 1; }

command -v xbps-install >/dev/null 2>&1 || die "xbps-install not found"

log "provisioner: $SCRIPT_DIR"

log "installing postgresql${PG_VERSION} and contrib"
xbps-install -Syu -y
xbps-install -y "postgresql${PG_VERSION}" "postgresql${PG_VERSION}-contrib" ca-certificates

# The database must not carry remote shells, downloaders or compilers.
for pkg in openssh curl wget gcc make; do
    if xbps-query -p pkgver "$pkg" >/dev/null 2>&1; then
        log "removing forbidden package: $pkg"
        xbps-remove -y "$pkg" >/dev/null 2>&1 || true
    fi
done

log "preparing PostgreSQL directories"
mkdir -p /run/postgresql /var/log
chown -R postgres:postgres /var/lib/postgresql /run/postgresql
chmod 0700 /run/postgresql

log "done"

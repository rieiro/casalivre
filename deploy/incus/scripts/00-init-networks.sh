#!/usr/bin/env bash
# Create/update the shared networks, internal ACL, persistent volumes and hardened profiles.
# Idempotent: safe to re-run after editing a YAML file.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

log() { printf '[init] %s\n' "$*"; }
die() { printf '[init] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require sed

STORAGE_POOL="${STORAGE_POOL:-default}"
incus storage show "$STORAGE_POOL" >/dev/null 2>&1 || die "Incus storage pool not found: $STORAGE_POOL"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

apply_profile() { # apply_profile <name> <rendered-yaml>
  local name="$1" file="$2"
  if incus profile show "$name" >/dev/null 2>&1; then
    incus profile edit "$name" < "$file"
    log "profile updated: $name"
  else
    incus profile create "$name"
    incus profile edit "$name" < "$file"
    log "profile created: $name"
  fi
}

render_profile() { # render_profile <src> <dst>
  sed -e "s|__STORAGE_POOL__|${STORAGE_POOL}|g" "$1" > "$2"
}

# 1. Internal ACL first: incusbr-internal references it.
if incus network acl show acl-internal >/dev/null 2>&1; then
  incus network acl edit acl-internal < "$ROOT_DIR/acls/acl-internal.yaml"
  log "ACL updated: acl-internal"
else
  incus network acl create acl-internal
  incus network acl edit acl-internal < "$ROOT_DIR/acls/acl-internal.yaml"
  log "ACL created: acl-internal"
fi

# 2. Shared networks.
apply_network() { # apply_network <name> <yaml>
  local name="$1" file="$2"
  if incus network show "$name" >/dev/null 2>&1; then
    incus network edit "$name" < "$file"
    log "network updated: $name"
  else
    incus network create "$name" < "$file"
    log "network created: $name"
  fi
}
apply_network incusbr-dmz      "$ROOT_DIR/networks/incusbr-dmz.yaml"
apply_network incusbr-internal "$ROOT_DIR/networks/incusbr-internal.yaml"

# 3. Persistent custom volumes.
for vol in casalivre-db casalivre-media; do
  if incus storage volume show "$STORAGE_POOL" "$vol" >/dev/null 2>&1; then
    log "storage volume exists: $vol"
  else
    incus storage volume create "$STORAGE_POOL" "$vol"
    log "storage volume created: $vol"
  fi
done

# 4. Hardened profiles (role profiles render the storage-pool token).
apply_profile base-void-hardened "$ROOT_DIR/profiles/base-void-hardened.yaml"
render_profile "$ROOT_DIR/profiles/app-php.yaml" "$tmp/app-php.yaml"
apply_profile app-php "$tmp/app-php.yaml"
render_profile "$ROOT_DIR/profiles/db-pg.yaml" "$tmp/db-pg.yaml"
apply_profile db-pg "$tmp/db-pg.yaml"

log "done: incusbr-dmz 10.10.10.0/24 (nat), incusbr-internal 10.10.20.0/24 (air-gapped)"
log "next: $ROOT_DIR/scripts/01-deploy-db.sh"

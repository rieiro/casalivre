#!/usr/bin/env bash
# Compile the hardened nginx artifact in a disposable Incus builder and pull the tarball + SHA256.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ARTIFACTS_DIR="$ROOT_DIR/artifacts"

BUILDER_NAME="${BUILDER_NAME:-casalivre-nginx-builder}"
BUILDER_PROFILE="${BUILDER_PROFILE:-casalivre-nginx-builder}"

log() { printf '[build] %s\n' "$*"; }
die() { printf '[build] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require sha256sum

# Pinned versions; an explicit ALPINE_IMAGE wins over the file.
_alpine_override="${ALPINE_IMAGE:-}"
. "$ROOT_DIR/build/versions.env"
[ -n "$_alpine_override" ] && ALPINE_IMAGE="$_alpine_override"

mkdir -p "$ARTIFACTS_DIR"

cleanup() {
  if incus info "$BUILDER_NAME" >/dev/null 2>&1; then
    incus config set "$BUILDER_NAME" security.protection.delete=false >/dev/null 2>&1 || true
    incus stop "$BUILDER_NAME" --force >/dev/null 2>&1 || true
    incus delete "$BUILDER_NAME" --force >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

if incus info "$BUILDER_NAME" >/dev/null 2>&1; then
  log "removing stale builder container"
  cleanup
fi

log "launching builder ($ALPINE_IMAGE)"
incus launch "$ALPINE_IMAGE" "$BUILDER_NAME" --no-profiles --profile "$BUILDER_PROFILE"

log "installing bash/git/curl in the builder (waits for network)"
installed=0
for _ in $(seq 1 40); do
  if incus exec "$BUILDER_NAME" -- sh -c 'apk add --no-cache bash git curl ca-certificates >/dev/null 2>&1'; then
    installed=1
    break
  fi
  sleep 2
done
[ "$installed" -eq 1 ] || die "builder could not install base tools (no network?)"

log "pushing build/ into the builder"
incus exec "$BUILDER_NAME" -- mkdir -p /root/build
incus file push -r "$ROOT_DIR/build/." "$BUILDER_NAME/root/build/"

log "compiling (this can take a few minutes)"
incus exec "$BUILDER_NAME" -- bash /root/build/build.sh

version="$(incus exec "$BUILDER_NAME" -- sh -c '. /root/build/versions.env && printf %s "$NGINX_VERSION"')"
artifact="nginx-${version}-hardened.tar.gz"

log "pulling $artifact"
incus file pull "$BUILDER_NAME/root/out/$artifact" "$ARTIFACTS_DIR/$artifact"
incus file pull "$BUILDER_NAME/root/out/$artifact.sha256" "$ARTIFACTS_DIR/$artifact.sha256"

( cd "$ARTIFACTS_DIR" && sha256sum -c "$artifact.sha256" >/dev/null )

log "artifact ready: $ARTIFACTS_DIR/$artifact"
log "sha256: $(cut -d' ' -f1 "$ARTIFACTS_DIR/$artifact.sha256")"

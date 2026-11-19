#!/usr/bin/env bash
# Install the daily logrotate service in the application container (idempotent).
# Covers the PHP-FPM file logs and casalivre-alert's alerts.log, which are not captured by
# socklog/svlogd. Worker logs are already rotated by casalivre-svlogd.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

CONTAINER="${CONTAINER:-app-php}"
SERVICE="casalivre-logrotate"
CONFIG="/etc/logrotate.d/casalivre"
STATUS="/var/lib/casalivre/logrotate.status"

log() { printf '[logs] %s\n' "$*"; }
die() { printf '[logs] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
[ "$(incus list "$CONTAINER" -cs --format csv 2>/dev/null | head -n1)" = "RUNNING" ] || die "$CONTAINER is not RUNNING"

if ! incus exec "$CONTAINER" -- xbps-query -p pkgver logrotate >/dev/null 2>&1; then
  log "installing logrotate"
  incus exec "$CONTAINER" -- xbps-install -y logrotate
else
  log "logrotate already installed"
fi

incus exec "$CONTAINER" -- /bin/sh -c "
  set -e
  install -d -o root -g root -m 0755 /etc/logrotate.d /var/lib/casalivre /etc/sv/$SERVICE/log
"

incus file push "$ROOT_DIR/containers/app-php/config/logrotate-casalivre" "$CONTAINER$CONFIG"
incus file push "$ROOT_DIR/containers/app-php/sv/$SERVICE/run" "$CONTAINER/etc/sv/$SERVICE/run"
incus file push "$ROOT_DIR/containers/app-php/sv/$SERVICE/log/run" "$CONTAINER/etc/sv/$SERVICE/log/run"

incus exec "$CONTAINER" -- chown root:root "$CONFIG" "/etc/sv/$SERVICE/run" "/etc/sv/$SERVICE/log/run"
incus exec "$CONTAINER" -- chmod 0644 "$CONFIG"
incus exec "$CONTAINER" -- chmod 0755 "/etc/sv/$SERVICE/run" "/etc/sv/$SERVICE/log/run"
incus exec "$CONTAINER" -- ln -sfn "/etc/sv/$SERVICE" "/var/service/$SERVICE"

# Validate the policy without rotating anything (debug mode).
incus exec "$CONTAINER" -- logrotate -d "$CONFIG" >/dev/null 2>&1 \
  || die "logrotate config failed validation: $CONFIG"

incus exec "$CONTAINER" -- sv up "$SERVICE" >/dev/null 2>&1 || true
incus exec "$CONTAINER" -- sv restart "$SERVICE" >/dev/null 2>&1 || incus exec "$CONTAINER" -- sv up "$SERVICE" >/dev/null 2>&1 || true

log "ready: $SERVICE (status $STATUS)"

#!/usr/bin/env bash
# Provision CasaLivre Valkey in the application container (idempotent).
# Installs valkey + php8.4-redis, creates the _valkey user, generates the app secret, renders the
# ACL, installs the runit service and verifies an authenticated PING over the Unix socket.
#
# Called by 02-deploy-app.sh (provisioning) and 04-jumpstart-app.sh (ensure it is present/current).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

CONTAINER="${CONTAINER:-app-php}"
SECRETS_DIR="${SECRETS_DIR:-$ROOT_DIR/secrets}"
SECRET_FILE="$SECRETS_DIR/valkey-app.secret"
VALKEY_PACKAGES="${VALKEY_PACKAGES:-valkey php8.4-redis}"

VALKEY_USER="cl_app"
VALKEY_SOCKET="/run/casalivre-valkey/valkey.sock"
VALKEY_PASSWORD_FILE="/etc/casalivre/valkey-app.secret"
VALKEY_CONF="/etc/casalivre-valkey/valkey.conf"
VALKEY_ACL="/etc/casalivre-valkey/valkey.acl"
SERVICE="casalivre-valkey"

log() { printf '[valkey] %s\n' "$*"; }
die() { printf '[valkey] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require openssl
require sha256sum

state_of() { incus list "$1" -cs --format csv 2>/dev/null | head -n1; }
[ "$(state_of "$CONTAINER")" = "RUNNING" ] || die "$CONTAINER is not RUNNING"

# ---------------------------------------------------------------- packages
missing=0
for pkg in $VALKEY_PACKAGES; do
  incus exec "$CONTAINER" -- xbps-query -p pkgver "$pkg" >/dev/null 2>&1 || missing=1
done
if [ "$missing" -eq 1 ]; then
  log "installing $VALKEY_PACKAGES"
  # shellcheck disable=SC2086 # package list is space-separated by design
  incus exec "$CONTAINER" -- xbps-install -y $VALKEY_PACKAGES
else
  log "valkey packages already installed"
fi

# ---------------------------------------------------------------- user and directories
incus exec "$CONTAINER" -- /bin/sh -c '
  set -e
  getent group appuser >/dev/null 2>&1 || groupadd -r appuser
  id -u _valkey >/dev/null 2>&1 || useradd -r -g appuser -d /var/lib/casalivre/media/valkey -s /sbin/nologin _valkey
  install -d -o _valkey -g appuser -m 0750 /run/casalivre-valkey /var/lib/casalivre/media/valkey
  install -d -o root -g appuser -m 0750 /etc/casalivre
  install -d -o root -g root -m 0755 /etc/casalivre-valkey /etc/sv/casalivre-valkey /etc/sv/casalivre-valkey/log
'

# ---------------------------------------------------------------- secret + ACL
mkdir -p "$SECRETS_DIR"
if [ ! -s "$SECRET_FILE" ]; then
  log "generating $SECRET_FILE"
  ( umask 077; printf '%s' "$(openssl rand -hex 32)" > "$SECRET_FILE" )
fi
# Normalize away a possible trailing newline: AUTH uses the file contents and the ACL stores its sha256.
tmp_secret="$(mktemp)"
tr -d '\n' < "$SECRET_FILE" > "$tmp_secret"
mv "$tmp_secret" "$SECRET_FILE"
chmod 0600 "$SECRET_FILE"
hash="$(sha256sum < "$SECRET_FILE" | cut -d' ' -f1)"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
sed "s/__VALKEY_APP_HASH__/$hash/" "$ROOT_DIR/containers/app-php/config/valkey.acl.in" > "$work/valkey.acl"
grep -q '^user cl_app on #' "$work/valkey.acl" || die "rendered ACL has no cl_app password hash"

incus file push "$ROOT_DIR/containers/app-php/config/valkey.conf" "$CONTAINER$VALKEY_CONF"
incus file push "$work/valkey.acl" "$CONTAINER$VALKEY_ACL"
incus file push "$SECRET_FILE" "$CONTAINER$VALKEY_PASSWORD_FILE"
incus file push "$ROOT_DIR/containers/app-php/sv/casalivre-valkey/run" "$CONTAINER/etc/sv/$SERVICE/run"
incus file push "$ROOT_DIR/containers/app-php/sv/casalivre-valkey/log/run" "$CONTAINER/etc/sv/$SERVICE/log/run"

incus exec "$CONTAINER" -- chown _valkey:_valkey "$VALKEY_CONF" "$VALKEY_ACL"
incus exec "$CONTAINER" -- chmod 0600 "$VALKEY_CONF" "$VALKEY_ACL"
incus exec "$CONTAINER" -- chown root:appuser "$VALKEY_PASSWORD_FILE"
incus exec "$CONTAINER" -- chmod 0640 "$VALKEY_PASSWORD_FILE"
incus exec "$CONTAINER" -- chmod 0755 "/etc/sv/$SERVICE/run" "/etc/sv/$SERVICE/log/run"

# ---------------------------------------------------------------- start and verify
# A log/ directory added after the service is already supervised is only picked up by a fresh
# runsv, so cycle the /var/service symlink instead of relying on `sv restart`.
incus exec "$CONTAINER" -- sv down "$SERVICE" >/dev/null 2>&1 || true
incus exec "$CONTAINER" -- rm -f "/var/service/$SERVICE"
sleep 6   # runsvdir scans /var/service every 5s; let it drop the old supervisor
incus exec "$CONTAINER" -- ln -sfn "/etc/sv/$SERVICE" "/var/service/$SERVICE"
sleep 2
incus exec "$CONTAINER" -- sv up "$SERVICE" >/dev/null 2>&1 || true
# Wait until the new supervisor has started the log service too.
for _ in $(seq 1 20); do
  if incus exec "$CONTAINER" -- /bin/sh -c 'ps -ef | grep -q "[v]logger -t casalivre-valkey"'; then
    break
  fi
  sleep 1
done

ready=0
for _ in $(seq 1 30); do
  if incus exec "$CONTAINER" -- test -S "$VALKEY_SOCKET" >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
[ "$ready" -eq 1 ] || die "valkey socket $VALKEY_SOCKET did not appear"

if ! incus exec "$CONTAINER" -- su -s /bin/sh appuser -c \
  "valkey-cli -s $VALKEY_SOCKET --user $VALKEY_USER --pass \"\$(cat $VALKEY_PASSWORD_FILE)\" --no-auth-warning PING" \
  | grep -q PONG; then
  die "valkey PING failed for $VALKEY_USER (check the ACL and $VALKEY_PASSWORD_FILE)"
fi

log "ready: $VALKEY_SOCKET (user $VALKEY_USER)"

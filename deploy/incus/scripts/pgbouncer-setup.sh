#!/usr/bin/env bash
# Provision CasaLivre PgBouncer inside app-php (idempotent).
#
# Session pooling is intentional until the app's SET ROLE / session advisory lock usage is
# refactored. The local PHP -> PgBouncer hop uses MD5 (loopback only) so PHP-FPM does not run
# SCRAM PBKDF2 per request; PgBouncer uses the plaintext userlist to keep SCRAM+TLS server
# connections warm to db-pg.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

CONTAINER="${CONTAINER:-app-php}"
DB="${DB:-db-pg}"
APP_ENV_FILE="${APP_ENV_FILE:-$ROOT_DIR/secrets/app.env}"
SECRETS_DIR="${SECRETS_DIR:-$ROOT_DIR/secrets}"
SERVICE="casalivre-pgbouncer"
CONF_DIR="/etc/casalivre-pgbouncer"
RUN_DIR="/run/casalivre-pgbouncer"
USERLIST="$CONF_DIR/userlist.txt"
CONF="$CONF_DIR/pgbouncer.ini"
DB_CA="${DB_CA:-$ROOT_DIR/secrets/db-pg.crt}"
ADMIN_SECRET="${ADMIN_SECRET:-$SECRETS_DIR/pgbouncer-admin.secret}"

log() { printf '[pgbouncer] %s\n' "$*"; }
die() { printf '[pgbouncer] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require openssl

[ "$(incus list "$CONTAINER" -cs --format csv 2>/dev/null | head -n1)" = "RUNNING" ] || die "$CONTAINER is not RUNNING"
[ -r "$APP_ENV_FILE" ] || die "env file not readable: $APP_ENV_FILE"

env_value() { sed -n "s/^$1=//p" "$APP_ENV_FILE" | head -n1 | sed -e 's/^"//' -e 's/"$//'; }
esc() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }

DB_USER="$(env_value DB_USER)"; DB_PW="$(env_value DB_PASSWORD)"
LOG_USER="$(env_value LOG_DB_USER)"; LOG_PW="$(env_value LOG_DB_PASSWORD)"
JOB_USER="$(env_value LOG_JOB_DB_USER)"; JOB_PW="$(env_value LOG_JOB_DB_PASSWORD)"
OPS_USER="$(env_value OPS_DB_USER)"; OPS_PW="$(env_value OPS_DB_PASSWORD)"
[ -n "$DB_USER" ] || DB_USER="casalivre_app"
[ -n "$DB_PW" ] || die "DB_PASSWORD is empty in $APP_ENV_FILE"
[ -n "$LOG_USER" ] && [ -n "$LOG_PW" ] || die "LOG_DB_USER/LOG_DB_PASSWORD missing in $APP_ENV_FILE"
[ -n "$JOB_USER" ] && [ -n "$JOB_PW" ] || die "LOG_JOB_DB_USER/LOG_JOB_DB_PASSWORD missing in $APP_ENV_FILE"

# ---------------------------------------------------------------- packages
missing=0
for pkg in pgbouncer postgresql-client; do
  incus exec "$CONTAINER" -- xbps-query -p pkgver "$pkg" >/dev/null 2>&1 || missing=1
done
if [ "$missing" -eq 1 ]; then
  log "installing pgbouncer and postgresql-client"
  incus exec "$CONTAINER" -- xbps-install -y pgbouncer postgresql-client
fi

# ---------------------------------------------------------------- user and dirs
incus exec "$CONTAINER" -- /bin/sh -c '
  set -e
  getent group _pgbouncer >/dev/null 2>&1 || groupadd -r _pgbouncer
  id -u _pgbouncer >/dev/null 2>&1 || useradd -r -g _pgbouncer -d /var/lib/pgbouncer -s /sbin/nologin _pgbouncer
  install -d -o root -g _pgbouncer -m 0750 /etc/casalivre-pgbouncer
  install -d -o _pgbouncer -g _pgbouncer -m 0750 /run/casalivre-pgbouncer /var/lib/pgbouncer
  install -d -o root -g root -m 0755 /etc/sv/casalivre-pgbouncer/log
'

# ---------------------------------------------------------------- admin secret
mkdir -p "$SECRETS_DIR"
if [ ! -s "$ADMIN_SECRET" ]; then
  ( umask 077; printf '%s' "$(openssl rand -hex 32)" > "$ADMIN_SECRET" )
fi
chmod 0600 "$ADMIN_SECRET"
ADMIN_PW="$(tr -d '\n' < "$ADMIN_SECRET")"

# ---------------------------------------------------------------- userlist/config
work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
if [ ! -r "$DB_CA" ]; then
  log "DB CA not found at $DB_CA; pulling db-pg server.crt"
  incus file pull "$DB/var/lib/postgresql/data/server.crt" "$work/db-ca.crt"
  DB_CA="$work/db-ca.crt"
fi
{
  printf '"%s" "%s"\n' "$(esc "$DB_USER")" "$(esc "$DB_PW")"
  [ -n "$LOG_USER" ] && printf '"%s" "%s"\n' "$(esc "$LOG_USER")" "$(esc "$LOG_PW")"
  [ -n "$JOB_USER" ] && printf '"%s" "%s"\n' "$(esc "$JOB_USER")" "$(esc "$JOB_PW")"
  [ -n "$OPS_USER" ] && [ -n "$OPS_PW" ] && printf '"%s" "%s"\n' "$(esc "$OPS_USER")" "$(esc "$OPS_PW")"
  printf '"pgbouncer_admin" "%s"\n' "$(esc "$ADMIN_PW")"
} > "$work/userlist.txt"

incus file push "$ROOT_DIR/containers/app-php/config/pgbouncer.ini.in" "$CONTAINER$CONF"
incus file push "$work/userlist.txt" "$CONTAINER$USERLIST"
incus file push "$DB_CA" "$CONTAINER$CONF_DIR/db-ca.crt"

incus exec "$CONTAINER" -- chown root:_pgbouncer "$CONF" "$CONF_DIR/db-ca.crt"
incus exec "$CONTAINER" -- chmod 0640 "$CONF"
incus exec "$CONTAINER" -- chmod 0644 "$CONF_DIR/db-ca.crt"
incus exec "$CONTAINER" -- chown _pgbouncer:_pgbouncer "$USERLIST"
incus exec "$CONTAINER" -- chmod 0600 "$USERLIST"

# ---------------------------------------------------------------- service
incus file push "$ROOT_DIR/containers/app-php/sv/$SERVICE/run" "$CONTAINER/etc/sv/$SERVICE/run"
incus file push "$ROOT_DIR/containers/app-php/sv/$SERVICE/log/run" "$CONTAINER/etc/sv/$SERVICE/log/run"
incus exec "$CONTAINER" -- chown root:root "/etc/sv/$SERVICE/run" "/etc/sv/$SERVICE/log/run"
incus exec "$CONTAINER" -- chmod 0755 "/etc/sv/$SERVICE/run" "/etc/sv/$SERVICE/log/run"

# Cycle the symlink (6s) so a runsv already supervising the service picks up log/run.
incus exec "$CONTAINER" -- sv down "$SERVICE" >/dev/null 2>&1 || true
incus exec "$CONTAINER" -- rm -f "/var/service/$SERVICE"
sleep 6
incus exec "$CONTAINER" -- ln -sfn "/etc/sv/$SERVICE" "/var/service/$SERVICE"
sleep 2
incus exec "$CONTAINER" -- sv up "$SERVICE" >/dev/null 2>&1 || true

ready=0
for _ in $(seq 1 30); do
  if incus exec "$CONTAINER" -- su -s /bin/sh appuser -c \
    "PGPASSWORD='$DB_PW' psql -h 127.0.0.1 -p 6432 -U '$DB_USER' -d casalivre -tAc 'select 1'" \
    >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[ "$ready" -eq 1 ] || die "PgBouncer did not accept a client connection on 127.0.0.1:6432"

# Wait for the runsv log service to be picked up.
for _ in $(seq 1 20); do
  if incus exec "$CONTAINER" -- /bin/sh -c 'ps -ef | grep -q "[v]logger -t casalivre-pgbouncer"'; then
    break
  fi
  sleep 1
done

log "ready: 127.0.0.1:6432 (user $DB_USER, session pooling)"

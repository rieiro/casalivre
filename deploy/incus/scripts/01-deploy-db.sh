#!/usr/bin/env bash
# Deploy the air-gapped PostgreSQL tier. Packages are installed through a temporary DMZ NIC
# that is removed immediately afterwards (a trap removes it even on failure).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SECRETS_DIR="$ROOT_DIR/secrets"

CONTAINER="${CONTAINER:-db-pg}"
IMAGE="${IMAGE:-images:voidlinux/current}"
PG_VERSION="${PG_VERSION:-17}"
STORAGE_POOL="${STORAGE_POOL:-default}"
APP_DB_USER="${APP_DB_USER:-casalivre_app}"
APP_DB_NAME="${APP_DB_NAME:-casalivre}"
APP_DB_PASSWORD="${APP_DB_PASSWORD:-}"
APP_DB_PASSWORD_FILE="${APP_DB_PASSWORD_FILE:-$SECRETS_DIR/app_db_password}"
DATA_DIR="/var/lib/postgresql/data"

log() { printf '[db] %s\n' "$*"; }
die() { printf '[db] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require openssl

if [ -z "$APP_DB_PASSWORD" ] && [ -r "$APP_DB_PASSWORD_FILE" ]; then
  APP_DB_PASSWORD="$(cat "$APP_DB_PASSWORD_FILE")"
fi
[ -n "$APP_DB_PASSWORD" ] || die "set APP_DB_PASSWORD or create $APP_DB_PASSWORD_FILE"
case "$APP_DB_PASSWORD" in
  *[!A-Za-z0-9._-]*) die "APP_DB_PASSWORD may contain only letters, digits, dot, underscore and hyphen" ;;
esac

mkdir -p "$SECRETS_DIR"
chmod 0700 "$SECRETS_DIR"

tmp="$(mktemp -d)"
MAINT_NIC=0
cleanup() {
  if [ "$MAINT_NIC" = "1" ]; then
    incus stop "$CONTAINER" >/dev/null 2>&1 || true
    incus config device remove "$CONTAINER" maint-nic >/dev/null 2>&1 || true
    incus start "$CONTAINER" >/dev/null 2>&1 || true
  fi
  rm -rf "$tmp"
}
trap cleanup EXIT

if incus info "$CONTAINER" >/dev/null 2>&1; then
  log "container exists: $CONTAINER"
else
  log "launching $CONTAINER ($IMAGE)"
  incus launch "$IMAGE" "$CONTAINER" --no-profiles \
    --profile base-void-hardened --profile db-pg
fi

if ! incus exec "$CONTAINER" -- xbps-query -p pkgver "postgresql${PG_VERSION}" >/dev/null 2>&1; then
  log "installing postgresql${PG_VERSION} through a temporary DMZ NIC"
  incus stop "$CONTAINER" >/dev/null 2>&1 || true
  incus config device add "$CONTAINER" maint-nic nic network=incusbr-dmz
  MAINT_NIC=1
  incus start "$CONTAINER"

  got_ip=0
  for _ in $(seq 1 30); do
    if incus list "$CONTAINER" -c4 --format csv | tr ',' '\n' | grep -Eq '^10\.10\.10\.[0-9]+$'; then
      got_ip=1
      break
    fi
    sleep 1
  done
  [ "$got_ip" -eq 1 ] || die "maintenance NIC did not get a DHCP address"

  incus exec "$CONTAINER" -- mkdir -p /root/db-pg
  incus file push -r "$ROOT_DIR/containers/db-pg/." "$CONTAINER/root/db-pg/"
  incus exec "$CONTAINER" -- env PG_VERSION="$PG_VERSION" bash /root/db-pg/provision.sh
  incus exec "$CONTAINER" -- rm -rf /root/db-pg

  log "removing the maintenance NIC (db-pg is air-gapped again)"
  incus stop "$CONTAINER"
  incus config device remove "$CONTAINER" maint-nic
  MAINT_NIC=0
  incus start "$CONTAINER"
else
  log "postgresql${PG_VERSION} already installed"
fi

# Void service defaults: pin PGROOT to the persistent volume and enforce SCRAM for host auth.
cat > "$tmp/void-default" <<EOF
PGROOT="/var/lib/postgresql"
PGLOG="/var/log/postgresql${PG_VERSION}.log"
INITOPTS="-A peer --auth-host=scram-sha-256 --auth-local=peer --locale=C --encoding=UTF8"
EOF
incus file push "$tmp/void-default" "$CONTAINER/etc/psql${PG_VERSION}/default/postgresql"
incus exec "$CONTAINER" -- chmod 0644 "/etc/psql${PG_VERSION}/default/postgresql"

incus exec "$CONTAINER" -- sh -c '
  set -e
  mkdir -p /run/postgresql
  chown -R postgres:postgres /var/lib/postgresql /run/postgresql
  chmod 0700 /run/postgresql
'

if ! incus exec "$CONTAINER" -- test -f "$DATA_DIR/PG_VERSION"; then
  log "initializing the PostgreSQL cluster"
  incus exec "$CONTAINER" -- su - postgres -c \
    "/usr/lib/psql${PG_VERSION}/bin/initdb -D '$DATA_DIR' -A peer --auth-host=scram-sha-256 --auth-local=peer --locale=C --encoding=UTF8"
fi

log "installing postgresql.conf, pg_hba.conf and the TLS certificate"
incus file push "$ROOT_DIR/containers/db-pg/config/postgresql.conf" "$CONTAINER$DATA_DIR/postgresql.conf"
incus file push "$ROOT_DIR/containers/db-pg/config/pg_hba.conf" "$CONTAINER$DATA_DIR/pg_hba.conf"

if [ ! -s "$SECRETS_DIR/db-pg.crt" ] || [ ! -s "$SECRETS_DIR/db-pg.key" ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -keyout "$SECRETS_DIR/db-pg.key" -out "$SECRETS_DIR/db-pg.crt" \
    -subj "/CN=db-pg" >/dev/null 2>&1
  chmod 0600 "$SECRETS_DIR/db-pg.key"
  chmod 0644 "$SECRETS_DIR/db-pg.crt"
fi
incus file push "$SECRETS_DIR/db-pg.crt" "$CONTAINER$DATA_DIR/server.crt"
incus file push "$SECRETS_DIR/db-pg.key" "$CONTAINER$DATA_DIR/server.key"

incus exec "$CONTAINER" -- chown postgres:postgres \
  "$DATA_DIR/postgresql.conf" "$DATA_DIR/pg_hba.conf" "$DATA_DIR/server.crt" "$DATA_DIR/server.key"
incus exec "$CONTAINER" -- chmod 0600 \
  "$DATA_DIR/postgresql.conf" "$DATA_DIR/pg_hba.conf" "$DATA_DIR/server.key"
incus exec "$CONTAINER" -- chmod 0644 "$DATA_DIR/server.crt"

log "enabling /var/service/postgresql"
incus exec "$CONTAINER" -- sh -c "ln -sfn /etc/sv/postgresql${PG_VERSION} /var/service/postgresql"
incus exec "$CONTAINER" -- sv up postgresql >/dev/null 2>&1 || incus exec "$CONTAINER" -- sv restart postgresql >/dev/null 2>&1 || true

ready=0
for _ in $(seq 1 30); do
  if incus exec "$CONTAINER" -- su - postgres -c \
    "/usr/lib/psql${PG_VERSION}/bin/pg_isready -h /run/postgresql -p 5432" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[ "$ready" -eq 1 ] || die "PostgreSQL did not become ready; check: incus exec $CONTAINER -- sv status /var/service/postgresql"

log "creating role $APP_DB_USER and database $APP_DB_NAME (idempotent)"
cat > "$tmp/bootstrap.sql" <<EOF
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$APP_DB_USER') THEN
    CREATE ROLE $APP_DB_USER LOGIN;
  END IF;
END
\$\$;
ALTER ROLE $APP_DB_USER PASSWORD :'app_password';
SELECT 'CREATE DATABASE $APP_DB_NAME OWNER $APP_DB_USER'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '$APP_DB_NAME')\gexec
ALTER DATABASE $APP_DB_NAME SET timezone TO 'UTC';
EOF
incus file push "$tmp/bootstrap.sql" "$CONTAINER/tmp/bootstrap.sql"
incus exec "$CONTAINER" -- su - postgres -c \
  "PGHOST=/run/postgresql psql -v ON_ERROR_STOP=1 -v app_password='$APP_DB_PASSWORD' -f /tmp/bootstrap.sql" >/dev/null
incus exec "$CONTAINER" -- rm -f /tmp/bootstrap.sql

log "db-pg is ready and air-gapped: 10.10.20.10:5432 (TLS + SCRAM)"
log "next: $ROOT_DIR/scripts/02-deploy-app.sh"

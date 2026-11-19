#!/usr/bin/env bash
# Major PostgreSQL upgrade: snapshot the data volume, build a new container on a new volume with the
# new major version, restore a logical dump, verify it, then swap (gated by CONFIRM=1).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SECRETS_DIR="$ROOT_DIR/secrets"

OLD_CONTAINER="${OLD_CONTAINER:-db-pg}"
NEW_CONTAINER="${NEW_CONTAINER:-db-pg-new}"
OLD_PG_VERSION="${OLD_PG_VERSION:-17}"
NEW_PG_VERSION="${NEW_PG_VERSION:-18}"
STORAGE_POOL="${STORAGE_POOL:-default}"
OLD_VOLUME="${OLD_VOLUME:-casalivre-db}"
NEW_VOLUME="${NEW_VOLUME:-casalivre-db-new}"
NEW_IP="${NEW_IP:-10.10.20.12}"
APP_CONTAINER="${APP_CONTAINER:-app-php}"
SNAPSHOT="${SNAPSHOT:-pre-upgrade-$(date -u +%Y%m%dT%H%M%SZ)}"
CONFIRM="${CONFIRM:-0}"
DATA_DIR="/var/lib/postgresql/data"

log() { printf '[upgrade] %s\n' "$*"; }
die() { printf '[upgrade] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require openssl

[ "$OLD_PG_VERSION" != "$NEW_PG_VERSION" ] || die "OLD_PG_VERSION and NEW_PG_VERSION must differ"
incus info "$OLD_CONTAINER" >/dev/null 2>&1 || die "old container not found: $OLD_CONTAINER"
if incus info "$NEW_CONTAINER" >/dev/null 2>&1; then
  [ "${REPLACE:-0}" = "1" ] || die "$NEW_CONTAINER already exists (set REPLACE=1)"
  incus config set "$NEW_CONTAINER" security.protection.delete=false >/dev/null 2>&1 || true
  incus stop "$NEW_CONTAINER" --force >/dev/null 2>&1 || true
  incus delete "$NEW_CONTAINER" --force
fi
if incus storage volume show "$STORAGE_POOL" "$NEW_VOLUME" >/dev/null 2>&1; then
  die "volume $NEW_VOLUME already exists; remove it or set NEW_VOLUME"
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

log "1/7 snapshot $OLD_VOLUME as $SNAPSHOT"
incus storage volume snapshot create "$STORAGE_POOL" "$OLD_VOLUME" "$SNAPSHOT"

log "2/7 create $NEW_VOLUME"
incus storage volume create "$STORAGE_POOL" "$NEW_VOLUME"

log "3/7 launch $NEW_CONTAINER with PostgreSQL $NEW_PG_VERSION (temporary IP $NEW_IP)"
incus launch images:voidlinux/current "$NEW_CONTAINER" --no-profiles \
  --profile base-void-hardened --profile db-pg
incus config set "$NEW_CONTAINER" security.protection.delete=false
incus config device override "$NEW_CONTAINER" data source="$NEW_VOLUME"
incus config device override "$NEW_CONTAINER" eth0 ipv4.address="$NEW_IP"

incus config device add "$NEW_CONTAINER" maint-nic nic network=incusbr-dmz
incus restart "$NEW_CONTAINER"
got_ip=0
for _ in $(seq 1 30); do
  if incus list "$NEW_CONTAINER" -c4 --format csv | tr ',' '\n' | grep -Eq '^10\.10\.10\.[0-9]+$'; then
    got_ip=1
    break
  fi
  sleep 1
done
[ "$got_ip" -eq 1 ] || die "$NEW_CONTAINER maintenance NIC did not get an address"

incus exec "$NEW_CONTAINER" -- mkdir -p /root/db-pg
incus file push -r "$ROOT_DIR/containers/db-pg/." "$NEW_CONTAINER/root/db-pg/"
incus exec "$NEW_CONTAINER" -- env PG_VERSION="$NEW_PG_VERSION" bash /root/db-pg/provision.sh
incus exec "$NEW_CONTAINER" -- rm -rf /root/db-pg
incus stop "$NEW_CONTAINER"
incus config device remove "$NEW_CONTAINER" maint-nic
incus start "$NEW_CONTAINER"

# Cluster + configuration + TLS for the new major.
cat > "$tmp/void-default" <<EOF
PGROOT="/var/lib/postgresql"
PGLOG="/var/log/postgresql${NEW_PG_VERSION}.log"
INITOPTS="-A peer --auth-host=scram-sha-256 --auth-local=peer --locale=C --encoding=UTF8"
EOF
incus file push "$tmp/void-default" "$NEW_CONTAINER/etc/psql${NEW_PG_VERSION}/default/postgresql"
incus exec "$NEW_CONTAINER" -- chmod 0644 "/etc/psql${NEW_PG_VERSION}/default/postgresql"
incus exec "$NEW_CONTAINER" -- sh -c '
  set -e
  mkdir -p /run/postgresql
  chown -R postgres:postgres /var/lib/postgresql /run/postgresql
  chmod 0700 /run/postgresql
'
incus exec "$NEW_CONTAINER" -- su - postgres -c \
  "/usr/lib/psql${NEW_PG_VERSION}/bin/initdb -D '$DATA_DIR' -A peer --auth-host=scram-sha-256 --auth-local=peer --locale=C --encoding=UTF8"

incus file push "$ROOT_DIR/containers/db-pg/config/postgresql.conf" "$NEW_CONTAINER$DATA_DIR/postgresql.conf"
incus file push "$ROOT_DIR/containers/db-pg/config/pg_hba.conf" "$NEW_CONTAINER$DATA_DIR/pg_hba.conf"

if [ ! -s "$SECRETS_DIR/db-pg.crt" ] || [ ! -s "$SECRETS_DIR/db-pg.key" ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -keyout "$SECRETS_DIR/db-pg.key" -out "$SECRETS_DIR/db-pg.crt" \
    -subj "/CN=db-pg" >/dev/null 2>&1
  chmod 0600 "$SECRETS_DIR/db-pg.key"
  chmod 0644 "$SECRETS_DIR/db-pg.crt"
fi
incus file push "$SECRETS_DIR/db-pg.crt" "$NEW_CONTAINER$DATA_DIR/server.crt"
incus file push "$SECRETS_DIR/db-pg.key" "$NEW_CONTAINER$DATA_DIR/server.key"
incus exec "$NEW_CONTAINER" -- chown postgres:postgres \
  "$DATA_DIR/postgresql.conf" "$DATA_DIR/pg_hba.conf" "$DATA_DIR/server.crt" "$DATA_DIR/server.key"
incus exec "$NEW_CONTAINER" -- chmod 0600 \
  "$DATA_DIR/postgresql.conf" "$DATA_DIR/pg_hba.conf" "$DATA_DIR/server.key"
incus exec "$NEW_CONTAINER" -- chmod 0644 "$DATA_DIR/server.crt"
incus exec "$NEW_CONTAINER" -- sh -c "ln -sfn /etc/sv/postgresql${NEW_PG_VERSION} /var/service/postgresql"
incus exec "$NEW_CONTAINER" -- sv up postgresql >/dev/null 2>&1 || true

ready=0
for _ in $(seq 1 30); do
  if incus exec "$NEW_CONTAINER" -- su - postgres -c \
    "/usr/lib/psql${NEW_PG_VERSION}/bin/pg_isready -h /run/postgresql -p 5432" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[ "$ready" -eq 1 ] || die "the new PostgreSQL instance did not become ready"

log "4/7 dump $OLD_CONTAINER"
incus exec "$OLD_CONTAINER" -- su - postgres -c \
  'PGHOST=/run/postgresql pg_dumpall --clean --if-exists' > "$tmp/upgrade.sql"
[ -s "$tmp/upgrade.sql" ] || die "pg_dumpall produced an empty dump"

log "5/7 restore into $NEW_CONTAINER"
incus file push "$tmp/upgrade.sql" "$NEW_CONTAINER/tmp/upgrade.sql"
incus exec "$NEW_CONTAINER" -- su - postgres -c \
  'PGHOST=/run/postgresql psql -v ON_ERROR_STOP=1 -f /tmp/upgrade.sql' >/dev/null
incus exec "$NEW_CONTAINER" -- rm -f /tmp/upgrade.sql

log "6/7 verify the restored database"
incus exec "$NEW_CONTAINER" -- su - postgres -c \
  'PGHOST=/run/postgresql psql -d casalivre -v ON_ERROR_STOP=1 -tAc "SELECT 1"' | grep -qx '1' \
  || die "restored database did not answer SELECT 1"

if [ "$CONFIRM" != "1" ]; then
  log "dry run complete: snapshot, clone, restore and verification succeeded"
  log "re-run with CONFIRM=1 to swap the containers and volumes"
  exit 0
fi

TS="$(date -u +%Y%m%dT%H%M%SZ)"
OLD_CONTAINER_KEEP="${OLD_CONTAINER}-old-${TS}"
OLD_VOLUME_KEEP="${OLD_VOLUME}-old-${TS}"

log "7/7 freeze the app, stop the old database, and swap"
incus exec "$APP_CONTAINER" -- sv down php-fpm >/dev/null 2>&1 || true
incus exec "$OLD_CONTAINER" -- sv down postgresql >/dev/null 2>&1 || true
incus stop "$OLD_CONTAINER"

incus storage volume rename "$STORAGE_POOL" "$OLD_VOLUME" "$OLD_VOLUME_KEEP"
incus config device override "$OLD_CONTAINER" data source="$OLD_VOLUME_KEEP"
incus move "$OLD_CONTAINER" "$OLD_CONTAINER_KEEP"

incus storage volume rename "$STORAGE_POOL" "$NEW_VOLUME" "$OLD_VOLUME"
incus config device set "$NEW_CONTAINER" data source="$OLD_VOLUME"
incus config device set "$NEW_CONTAINER" eth0 ipv4.address="10.10.20.10"
incus config set "$NEW_CONTAINER" security.protection.delete=true
incus move "$NEW_CONTAINER" "$OLD_CONTAINER"

incus start "$OLD_CONTAINER"
incus exec "$OLD_CONTAINER" -- sv up postgresql >/dev/null 2>&1 || true
incus exec "$APP_CONTAINER" -- sv up php-fpm >/dev/null 2>&1 || true

log "upgrade complete: $OLD_CONTAINER now runs PostgreSQL $NEW_PG_VERSION"
log "kept for rollback: $OLD_CONTAINER_KEEP (volume $OLD_VOLUME_KEEP, snapshot $SNAPSHOT)"
log "rollback: stop $OLD_CONTAINER, restore names/volumes from $OLD_CONTAINER_KEEP, start it, restart php-fpm"

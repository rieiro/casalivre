#!/usr/bin/env bash
# Jumpstart (and re-deploy) the CasaLivre application on an already-provisioned 3-tier stack.
#
# Prerequisites: 00-init-networks.sh, 01-deploy-db.sh, 02-deploy-app.sh and the hardened edge
# (infra-nginx-hardened 00..03) already ran, so `db-pg`, `app-php` and an active `nginx-prod-*`
# container exist. This script is idempotent: it pushes code + .env, applies DB roles/schema,
# seeds only empty datasets, publishes assets and tiles to the edge and (re)installs the workers.
#
# Usage:
#   sudo ./scripts/04-jumpstart-app.sh
#   sudo SEED_DEMO=0 ./scripts/04-jumpstart-app.sh        # production data, no demo rows
#   sudo FORCE_TILES=1 ./scripts/04-jumpstart-app.sh      # re-copy the 1.5G PMTiles archive
#
# Secrets: APP_ENV_FILE (default: secrets/app.env, mode 0600) must define DB_DSN, DB_USER,
# DB_PASSWORD, LOG_DB_USER, LOG_DB_PASSWORD and LOG_SALT. It is never printed.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="${REPO_DIR:-$(cd "$ROOT_DIR/../.." && pwd)}"

DB="${DB:-db-pg}"
APP="${APP:-app-php}"
EDGE="${EDGE:-}"
APP_ENV_FILE="${APP_ENV_FILE:-$ROOT_DIR/secrets/app.env}"
TILES_FILE="${TILES_FILE:-$REPO_DIR/storage/tiles/brasil.pmtiles}"
SEED_DEMO="${SEED_DEMO:-1}"
FORCE_TILES="${FORCE_TILES:-0}"
PHP="${PHP:-/usr/sbin/php}"
PHP_MEMORY="${PHP_MEMORY:-512M}"
EDGE_STATIC="${EDGE_STATIC:-/srv/static}"
DB_APP_CIDR="10.10.20.11/32"

log() { printf '[jumpstart] %s\n' "$*"; }
die() { printf '[jumpstart] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require tar
require base64

state_of() { incus list "$1" -cs --format csv 2>/dev/null | head -n1; }

[ "$(state_of "$DB")" = "RUNNING" ] || die "$DB is not RUNNING (run 01-deploy-db.sh first)"
[ "$(state_of "$APP")" = "RUNNING" ] || die "$APP is not RUNNING (run 02-deploy-app.sh first)"
[ -r "$APP_ENV_FILE" ] || die "missing $APP_ENV_FILE (copy .env.example, fill it, chmod 0600)"

if [ -z "$EDGE" ]; then
  EDGE="$(cat "$ROOT_DIR/../infra-nginx-hardened/artifacts/state/active-container" 2>/dev/null || true)"
fi
if [ -z "$EDGE" ]; then
  for c in nginx-prod-blue nginx-prod-green; do
    if [ "$(state_of "$c")" = "RUNNING" ]; then EDGE="$c"; break; fi
  done
fi
[ -n "$EDGE" ] && [ "$(state_of "$EDGE")" = "RUNNING" ] || die "no active nginx-prod-* container found"

# The DMZ bridge has DHCP disabled; persist the edge's static address and nginx pidfile before publishing.
EDGE="$EDGE" "$SCRIPT_DIR/07-edge-static-network.sh"

# Never publish into a live-dev bind: /srv/app would be the host git tree, and the chown/chmod
# below would rewrite the working copy's ownership and modes through the idmapped mount.
DEV_MARKER="$ROOT_DIR/../infra-nginx-hardened/artifacts/state/dev-live"
if [ -f "$DEV_MARKER" ] || incus config device list "$APP" 2>/dev/null | grep -qx 'dev-live-src'; then
  [ "${ALLOW_LIVE_DEV:-0}" = "1" ] || die "live-dev overlay is active on $APP; /srv/app is the host git tree.
       Run scripts/05-dev-live.sh off first (or set ALLOW_LIVE_DEV=1 to force)."
fi

psql_db() { incus exec "$DB" -- su - postgres -c "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -v ON_ERROR_STOP=1 $*"; }
scalar() { incus exec "$DB" -- su - postgres -c "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -tAc \"$1\"" | tr -d '[:space:]'; }

# Read a secret from the env file without exporting or printing it.
env_value() { sed -n "s/^$1=//p" "$APP_ENV_FILE" | head -n1 | sed -e 's/^"//' -e 's/"$//'; }

log "tiers: db=$DB app=$APP edge=$EDGE"

# ---------------------------------------------------------------- database
log "database roles, passwords and schema"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

incus exec "$DB" -- mkdir -p /tmp/jumpstart
incus file push "$REPO_DIR/ops/dev-env/pg-roles.sql" "$DB/tmp/jumpstart/pg-roles.sql"
incus exec "$DB" -- su - postgres -c 'PGHOST=/run/postgresql /usr/bin/psql -v ON_ERROR_STOP=1 -f /tmp/jumpstart/pg-roles.sql'

APP_PW="$(env_value DB_PASSWORD)"
LOG_PW="$(env_value LOG_DB_PASSWORD)"
JOB_PW="$(env_value LOG_JOB_DB_PASSWORD)"
[ -n "$APP_PW" ] && [ -n "$LOG_PW" ] && [ -n "$JOB_PW" ] || die "DB_PASSWORD / LOG_DB_PASSWORD / LOG_JOB_DB_PASSWORD missing in $APP_ENV_FILE"
sqlesc() { printf '%s' "$1" | sed "s/'/''/g"; }
cat > "$WORK/roles.sql" <<SQL
ALTER ROLE casalivre_app PASSWORD '$(sqlesc "$APP_PW")';
ALTER ROLE cl_logw PASSWORD '$(sqlesc "$LOG_PW")';
ALTER ROLE cl_retention_job PASSWORD '$(sqlesc "$JOB_PW")';
SQL
incus file push "$WORK/roles.sql" "$DB/tmp/jumpstart/roles.sql" >/dev/null
incus exec "$DB" -- su - postgres -c 'PGHOST=/run/postgresql /usr/bin/psql -v ON_ERROR_STOP=1 -q -f /tmp/jumpstart/roles.sql' >/dev/null
rm -f "$WORK/roles.sql"

if [ "$(scalar "SELECT to_regclass('public.users') IS NULL")" = "t" ]; then
  log "loading database/schema.pgsql.sql (PostgreSQL $(scalar 'SHOW server_version' | cut -d. -f1))"
  incus file push "$REPO_DIR/database/schema.pgsql.sql" "$DB/tmp/jumpstart/schema.pgsql.sql"
  psql_db '-f /tmp/jumpstart/schema.pgsql.sql' >/dev/null
else
  log "schema already present; applying idempotent migrations (up to migration_038_ical_export_sync.sql)"
  for mig in "$REPO_DIR"/database/migrations/migration_*.sql; do
    [ -f "$mig" ] || continue
    mname="$(basename "$mig")"
    incus file push "$mig" "$DB/tmp/jumpstart/$mname" >/dev/null
    psql_db "-f /tmp/jumpstart/$mname" >/dev/null
  done
fi

# The schema is loaded by `postgres`; grant the runtime roles explicitly (idempotent).
cat > "$WORK/grants.sql" <<'SQL'
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
GRANT USAGE, CREATE ON SCHEMA public TO casalivre_app;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO casalivre_app;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO casalivre_app;
GRANT ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA public TO casalivre_app;
SQL
incus file push "$WORK/grants.sql" "$DB/tmp/jumpstart/grants.sql"
psql_db '-f /tmp/jumpstart/grants.sql' >/dev/null

incus exec "$DB" -- /bin/sh -c "
  f=/var/lib/postgresql/data/pg_hba.conf
  grep -q 'cl_logw $DB_APP_CIDR' \"\$f\" || printf 'hostssl all cl_logw $DB_APP_CIDR scram-sha-256\nhostssl all cl_retention_job $DB_APP_CIDR scram-sha-256\n' >> \"\$f\""
incus exec "$DB" -- su - postgres -c "PGHOST=/run/postgresql /usr/bin/psql -c 'SELECT pg_reload_conf()'" >/dev/null
incus exec "$DB" -- rm -rf /tmp/jumpstart

# ---------------------------------------------------------------- application code
log "publishing application code to /srv/app"
incus exec "$APP" -- mkdir -p /srv/app
tar -C "$REPO_DIR" -cf - \
  --exclude=.git --exclude=.env --exclude='.env.*' --exclude=node_modules \
  --exclude=storage --exclude=deploy --exclude=tests --exclude=DEPLOY_E2E_STATUS.md . \
  | incus exec "$APP" -- tar -C /srv/app -xf -
incus file push "$APP_ENV_FILE" "$APP/srv/app/.env"
incus exec "$APP" -- /bin/sh -c '
  set -e
  chown -R root:appuser /srv/app
  chmod 0750 /srv/app /srv/app/.env
  chmod 0640 /srv/app/.env
  find /srv/app -type d -exec chmod 0750 {} +
  find /srv/app -type f -exec chmod 0640 {} +
  mkdir -p /srv/app/storage/cache /srv/app/storage/db /srv/app/storage/logs /srv/app/storage/mail /srv/app/storage/sessions
  chown -R appuser:appuser /srv/app/storage
  find /srv/app/storage -type d -exec chmod 0750 {} +
'
log "restarting php-fpm"
incus exec "$APP" -- sv restart php-fpm >/dev/null 2>&1 || incus exec "$APP" -- sv up php-fpm >/dev/null 2>&1 || true

log "ensuring Valkey is configured"
CONTAINER="$APP" bash "$SCRIPT_DIR/valkey-setup.sh"

log "ensuring log rotation"
CONTAINER="$APP" bash "$SCRIPT_DIR/logs-setup.sh"

log "ensuring PgBouncer"
CONTAINER="$APP" APP_ENV_FILE="$APP_ENV_FILE" bash "$SCRIPT_DIR/pgbouncer-setup.sh"
incus exec "$APP" -- sv restart php-fpm >/dev/null 2>&1 || true
incus exec "$APP" -- /usr/sbin/php -r 'exit(extension_loaded("redis") ? 0 : 1);' \
  || die "php redis extension is not loaded; run 02-deploy-app.sh to publish php.ini"

# ---------------------------------------------------------------- data
php() { incus exec "$APP" -- su -s /bin/sh appuser -c "cd /srv/app && $PHP -d memory_limit=$PHP_MEMORY $*"; }
php_migrate() {
  incus exec "$APP" -- su -s /bin/sh appuser -c \
    "cd /srv/app && DB_DSN='pgsql:host=10.10.20.10;dbname=casalivre;sslmode=require' $PHP -d memory_limit=$PHP_MEMORY scripts/migrate.php"
}

log "running migrations/seeds (idempotent)"
php_migrate
if [ "$(scalar 'SELECT count(*) FROM ceps')" = "0" ]; then
  log "loading the CEP database (database/cep.csv.bz2)"
  php scripts/load-ceps.php
fi
if [ "$SEED_DEMO" = "1" ] && [ "$(scalar 'SELECT count(*) FROM properties')" = "0" ]; then
  log "seeding demo users/properties (SEED_DEMO=1)"
  php scripts/seed.php
fi
log "processing the image queue once"
php scripts/queue-worker.php image --once

# ---------------------------------------------------------------- workers
log "installing runit workers"
for h in casalivre-alert casalivre-calendar-run casalivre-daily casalivre-finish casalivre-queue-run casalivre-resolve casalivre-svlogd; do
  incus file push "$REPO_DIR/deploy/runit/$h" "$APP/usr/local/sbin/$h"
  incus exec "$APP" -- chmod 0755 "/usr/local/sbin/$h"
done
incus file push -r "$REPO_DIR/deploy/runit/casalivre-mail-worker" "$APP/etc/sv/casalivre-mail-worker"
incus file push -r "$REPO_DIR/deploy/runit/casalivre-image-worker" "$APP/etc/sv/casalivre-image-worker-1"
incus file push -r "$REPO_DIR/deploy/runit/casalivre-calendar-worker" "$APP/etc/sv/casalivre-calendar-worker"
incus file push -r "$REPO_DIR/deploy/runit/casalivre-rank-verify" "$APP/etc/sv/casalivre-rank-verify"
incus file push -r "$REPO_DIR/deploy/runit/casalivre-log-retention" "$APP/etc/sv/casalivre-log-retention"
incus file push -r "$REPO_DIR/deploy/runit/casalivre-ota-verify" "$APP/etc/sv/casalivre-ota-verify"
for s in casalivre-mail-worker casalivre-image-worker-1 casalivre-calendar-worker casalivre-rank-verify casalivre-log-retention casalivre-ota-verify; do
  extra=""
  case "$s" in
    casalivre-mail-worker)
      extra='VALKEY_MAIL_USER=cl_app\nVALKEY_MAIL_PASSWORD_FILE=/etc/casalivre/valkey-app.secret' ;;
    casalivre-image-worker-1)
      extra='VALKEY_IMAGE_USER=cl_app\nVALKEY_IMAGE_PASSWORD_FILE=/etc/casalivre/valkey-app.secret' ;;
  esac
  printf 'APP_DIR=/srv/app\nAPP_USER=appuser\nPHP_BIN=%s\n%b\n' "$PHP" "$extra" \
    | incus exec "$APP" -- /bin/sh -c "cat > /etc/sv/$s/conf && chmod 0640 /etc/sv/$s/conf"
done
incus exec "$APP" -- /bin/sh -c '
  set -e
  svc=casalivre-verify-domains
  mkdir -p "/etc/sv/$svc/log" /var/log/casalivre
  cat > "/etc/sv/$svc/run" <<"EOF"
#!/bin/sh
exec 2>&1
[ -r ./conf ] && . ./conf
APP_DIR="${APP_DIR:-/srv/app}"
APP_USER="${APP_USER:-appuser}"
PHP_BIN="${PHP_BIN:-php}"
cd "$APP_DIR" || exit 1
while true; do
  chpst -u "$APP_USER" "$PHP_BIN" scripts/verify-domains.php
  sleep 3600
done
EOF
  cat > "/etc/sv/$svc/log/run" <<"EOF"
#!/bin/sh
exec "${CASALIVRE_RESOLVE:-/usr/local/sbin/casalivre-resolve}" casalivre-svlogd casalivre-verify-domains /var/log/casalivre/verify-domains
EOF
  printf "APP_DIR=/srv/app\nAPP_USER=appuser\nPHP_BIN='"$PHP"'\n" > "/etc/sv/$svc/conf"
  chmod 0755 "/etc/sv/$svc/run" "/etc/sv/$svc/log/run"
  chmod 0640 "/etc/sv/$svc/conf"
  for s in casalivre-mail-worker casalivre-image-worker-1 casalivre-calendar-worker casalivre-rank-verify casalivre-log-retention casalivre-verify-domains casalivre-ota-verify; do
    chmod 0755 "/etc/sv/$s/run" "/etc/sv/$s/finish" "/etc/sv/$s/log/run" 2>/dev/null || true
    chmod 0640 "/etc/sv/$s/conf" 2>/dev/null || true
    ln -sfn "/etc/sv/$s" "/var/service/$s"
    sv restart "$s" >/dev/null 2>&1 || sv up "$s" >/dev/null 2>&1 || true
  done
'

# ---------------------------------------------------------------- edge statics
log "publishing assets to $EDGE:$EDGE_STATIC"
incus exec "$EDGE" -- mkdir -p "$EDGE_STATIC/assets" "$EDGE_STATIC/uploads" "$EDGE_STATIC/tiles"
tar -C "$REPO_DIR/public" -cf - assets favicon.ico robots.txt llms.txt \
  | incus exec "$EDGE" -- tar -C "$EDGE_STATIC" -xf -
if [ -d "$REPO_DIR/public/uploads" ]; then
  tar -C "$REPO_DIR/public/uploads" -cf - . | incus exec "$EDGE" -- tar -C "$EDGE_STATIC/uploads" -xf - 2>/dev/null || true
fi
# User uploads live in the app media volume; mirror them only when the source changed.
UPLOADS_SIG="$(incus exec "$APP" -- /bin/sh -c 'find /var/lib/casalivre/media/uploads -type f 2>/dev/null | wc -l; du -sb /var/lib/casalivre/media/uploads 2>/dev/null | cut -f1' | tr '\n' '-')"
EDGE_SIG="$(incus exec "$EDGE" -- /bin/sh -c "cat $EDGE_STATIC/.uploads.sig 2>/dev/null" || true)"
if [ "$UPLOADS_SIG" != "$EDGE_SIG" ]; then
  log "mirroring user uploads to the edge ($UPLOADS_SIG)"
  incus exec "$APP" -- /bin/sh -c 'tar -C /var/lib/casalivre/media/uploads -cf - . 2>/dev/null' \
    | incus exec "$EDGE" -- tar -C "$EDGE_STATIC/uploads" -xf - 2>/dev/null || true
  incus exec "$EDGE" -- /bin/sh -c "printf '%s' '$UPLOADS_SIG' > $EDGE_STATIC/.uploads.sig"
else
  log "user uploads already in sync on the edge"
fi

if [ -f "$TILES_FILE" ]; then
  if [ "$FORCE_TILES" = "1" ] || ! incus exec "$EDGE" -- test -s "$EDGE_STATIC/tiles/brasil.pmtiles"; then
    log "publishing $(du -h "$TILES_FILE" | cut -f1) PMTiles archive (one-time; set FORCE_TILES=0 to skip next time)"
    incus file push "$TILES_FILE" "$EDGE$EDGE_STATIC/tiles/brasil.pmtiles"
  else
    log "PMTiles already present on the edge (FORCE_TILES=1 to refresh)"
  fi
else
  log "WARNING: $TILES_FILE not found; the map will be unavailable"
fi

log "reloading the edge"
incus exec "$EDGE" -- su -s /bin/sh -c '/usr/local/nginx/sbin/nginx -t -c /usr/local/nginx/conf/nginx.conf' nginx >/dev/null
incus exec "$EDGE" -- su -s /bin/sh -c '/usr/local/nginx/sbin/nginx -c /usr/local/nginx/conf/nginx.conf -s reload' nginx

# ---------------------------------------------------------------- verification
log "one-shot worker checks"
php scripts/queue-worker.php mail --once || true
php scripts/calendar-worker.php --once || true
php scripts/rank-verify.php || true
php scripts/log-retention.php || true
php scripts/verify-domains.php || true
php scripts/check_pending_ota_verifications.php || true
incus exec "$DB" -- su - postgres -c "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -c \"UPDATE booking_intents SET status='expired' WHERE status='held' AND expires_at <= now()\"" >/dev/null

log "jumpstart complete: $(scalar 'SELECT count(*) FROM users') users, $(scalar 'SELECT count(*) FROM properties') properties, $(scalar 'SELECT count(*) FROM ceps') CEPs"
log "next: ./scripts/verify-stack.sh and the edge E2E checks"

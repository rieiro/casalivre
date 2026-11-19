#!/usr/bin/env bash
# Toggle the live-development overlay: bind the host git tree into app-php and the edge so edits
# show up on the next request (no copy, no container rebuild). Pair with 04-jumpstart-app.sh:
# 04 publishes a read-only copy for normal deploys, 05 swaps it for the live tree and back.
#
# Usage:
#   sudo ./scripts/05-dev-live.sh on       # apply the overlay (idempotent)
#   sudo ./scripts/05-dev-live.sh off      # remove it and restore php.ini + nginx.conf
#   ./scripts/05-dev-live.sh status        # show current mode and settings
#
# Defaults: REPO_DIR is the repo root (two levels above deploy/incus); APP/EDGE can be overridden.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
INFRA_DIR="$(cd "$ROOT_DIR/../infra-nginx-hardened" && pwd)"
REPO_DIR="${REPO_DIR:-$(cd "$ROOT_DIR/../.." && pwd)}"

APP="${APP:-app-php}"
EDGE="${EDGE:-}"
STATE_DIR="${STATE_DIR:-$INFRA_DIR/artifacts/state}"
BACKUP_DIR="${BACKUP_DIR:-$INFRA_DIR/artifacts/dev-backups}"
MARKER="$STATE_DIR/dev-live"

PHP_INI="/etc/php8.4/php.ini"
NGINX_CONF="/usr/local/nginx/conf/sites-enabled/default.conf"
DEV_APP="dev-live-src"
DEV_STATIC="dev-live-static"
DEV_TILES="dev-live-tiles"

log() { printf '[dev-live] %s\n' "$*"; }
die() { printf '[dev-live] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require setfacl

state_of() { incus list "$1" -cs --format csv 2>/dev/null | head -n1; }
marker_get() { sed -n "s/^$1=//p" "$MARKER" 2>/dev/null | head -n1; }

active_edge() {
  if [ -n "$EDGE" ]; then printf '%s\n' "$EDGE"; return; fi
  local e
  e="$(marker_get MARKER_EDGE)"
  if [ -z "$e" ]; then
    e="$(cat "$INFRA_DIR/artifacts/state/active-container" 2>/dev/null || true)"
  fi
  if [ -z "$e" ]; then
    for c in nginx-prod-blue nginx-prod-green; do
      if [ "$(state_of "$c")" = "RUNNING" ]; then e="$c"; break; fi
    done
  fi
  printf '%s\n' "$e"
}

app_host_uid() {
  local base uid
  base="$(incus config get "$APP" volatile.idmap.base 2>/dev/null || true)"
  uid="$(incus exec "$APP" -- id -u appuser 2>/dev/null || true)"
  [ -n "$base" ] && [ -n "$uid" ] || die "cannot resolve the host uid for appuser on $APP"
  printf '%s\n' "$((base + uid))"
}

device_source() {
  incus config device get "$1" "$2" source 2>/dev/null || true
}

ensure_device() {
  local c="$1" name="$2" src="$3" path="$4" ro="${5:-false}"
  if incus config device list "$c" 2>/dev/null | grep -qx "$name"; then
    [ "$(device_source "$c" "$name")" = "$src" ] \
      || die "$c/$name already exists with a different source ($(device_source "$c" "$name"))"
    log "$c/$name already present -> $path"
    return
  fi
  if [ "$ro" = "true" ]; then
    incus config device add "$c" "$name" disk source="$src" path="$path" readonly=true >/dev/null
  else
    incus config device add "$c" "$name" disk source="$src" path="$path" >/dev/null
  fi
  log "$c/$name -> $path"
}

remove_device() {
  if incus config device list "$1" 2>/dev/null | grep -qx "$2"; then
    incus config device remove "$1" "$2" >/dev/null 2>&1 || true
    log "removed $1/$2"
  fi
}

patch_php_ini() {
  local f="$1"
  sed -E -i 's/^opcache\.validate_timestamps=.*/opcache.validate_timestamps=1/' "$f"
  grep -q '^opcache\.validate_timestamps=' "$f" || printf 'opcache.validate_timestamps=1\n' >> "$f"
  sed -E -i 's/^opcache\.revalidate_freq=.*/opcache.revalidate_freq=0/' "$f"
  grep -q '^opcache\.revalidate_freq=' "$f" || printf 'opcache.revalidate_freq=0\n' >> "$f"
}

patch_nginx_conf() {
  sed -E -i 's/^([[:space:]]*)expires 30d;[[:space:]]*$/\1expires off;\
\1add_header Cache-Control "no-store" always;/' "$1"
  # Tiles live in a separate top-level bind (/srv/tiles) to avoid a mount nested under
  # /srv/static, which Incus cannot hot-plug. Serve them through an alias.
  if grep -q 'location = /tiles/brasil.pmtiles' "$1"; then
    sed -i '/location = \/tiles\/brasil\.pmtiles {/,/^[[:space:]]*}/ {
      s|^[[:space:]]*root /srv/static;|        alias /srv/tiles/brasil.pmtiles;|
      /^[[:space:]]*try_files \/tiles\/brasil\.pmtiles =404;/d
    }' "$1"
  fi
}

edge_base_url() {
  local ip
  ip="$(incus list "$1" -c4 --format csv 2>/dev/null | tr -d '"' | tr ',' '\n' \
    | grep -Eo '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | head -n1 || true)"
  [ -n "$ip" ] || die "could not determine the IPv4 of $1"
  printf 'https://%s:8443\n' "$ip"
}

smoke() {
  local url code
  url="$(edge_base_url "$EDGE")"
  for path in / /busca /assets/app.css; do
    code="$(curl -k -sS -o /dev/null -w '%{http_code}' --max-time 10 "$url$path" || echo 000)"
    [ "$code" = "200" ] || die "smoke failed: $url$path -> $code"
  done
  if [ -f "$REPO_DIR/storage/tiles/brasil.pmtiles" ]; then
    code="$(curl -k -sS -r 0-1023 -o /dev/null -w '%{http_code}' --max-time 10 "$url/tiles/brasil.pmtiles" || echo 000)"
    [ "$code" = "206" ] || die "tiles smoke failed: $url/tiles/brasil.pmtiles -> $code"
  fi
  log "smoke ok: $url/"
}

env_value() {
  sed -n "s/^$1=//p" "$REPO_DIR/.env" 2>/dev/null | head -n1 | sed -e 's/^"//' -e 's/"$//'
}

# The overlay makes app-php read $REPO_DIR/.env. A leftover single-container env (localhost DB,
# Valkey queue) would break every request, so validate it before touching the running stack.
check_env() {
  local f="$REPO_DIR/.env" dsn qd appurl
  [ -f "$f" ] || die "$f is missing: copy deploy/incus/secrets/app.env (or .env.example) before enabling live-dev"
  dsn="$(env_value DB_DSN)"
  qd="$(env_value QUEUE_DRIVER)"
  appurl="$(env_value APP_URL)"
  [ -n "$dsn" ] || die "DB_DSN is empty in $f"

  case "$dsn" in
    *host=127.0.0.1*|*host=localhost*|*host=/run/postgresql*|*host=/var/run/postgresql*)
      [ "${ALLOW_LOCAL_DB:-0}" = "1" ] || die "DB_DSN points to a local database ($dsn), but this stack keeps PostgreSQL in db-pg (10.10.20.10).
       Update $f (copy deploy/incus/secrets/app.env) or set ALLOW_LOCAL_DB=1 if a local DB really exists."
      log "WARNING: DB_DSN uses a local database ($dsn); continuing because ALLOW_LOCAL_DB=1"
      ;;
  esac
  case "$qd" in
    valkey|redis) log "WARNING: QUEUE_DRIVER=$qd but this stack has no Valkey/Redis; use QUEUE_DRIVER=db" ;;
  esac
  case "$appurl" in
    *127.0.0.1*|*10.64.215.59*) log "WARNING: APP_URL=$appurl will generate wrong links; use the public edge URL" ;;
  esac

  log "env preflight ok: db_host=$(printf '%s' "$dsn" | sed -n 's/.*host=\([^;]*\).*/\1/p') queue=${qd:-unset}"
}

do_on() {
  [ "$(state_of "$APP")" = "RUNNING" ] || die "$APP is not RUNNING"
  EDGE="$(active_edge)"
  [ -n "$EDGE" ] && [ "$(state_of "$EDGE")" = "RUNNING" ] || die "no active nginx-prod-* container"
  [ -d "$REPO_DIR/.git" ] || die "$REPO_DIR is not a git working tree (set REPO_DIR=...)"
  [ -d "$REPO_DIR/public" ] || die "$REPO_DIR/public is missing"
  [ -d "$REPO_DIR/storage/tiles" ] || log "WARNING: $REPO_DIR/storage/tiles is missing; the map will 404"
  check_env

  mkdir -p "$STATE_DIR" "$BACKUP_DIR"
  if [ ! -f "$MARKER" ]; then
    local ts
    ts="$(date -u +%Y%m%dT%H%M%SZ)"
    incus file pull "$APP$PHP_INI" "$BACKUP_DIR/php.ini.$ts" >/dev/null
    incus file pull "$EDGE$NGINX_CONF" "$BACKUP_DIR/default.conf.$ts" >/dev/null
    {
      printf 'MARKER_EDGE=%s\n' "$EDGE"
      printf 'BACKUP_PHP=%s\n' "$BACKUP_DIR/php.ini.$ts"
      printf 'BACKUP_NGINX=%s\n' "$BACKUP_DIR/default.conf.$ts"
      printf 'CREATED=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    } > "$MARKER"
    log "backed up php.ini and edge default.conf"
  else
    log "overlay marker already present; reusing backups"
  fi

  local appuid
  appuid="$(app_host_uid)"
  log "granting host uid $appuid (mapped appuser) read/write access to $REPO_DIR"
  setfacl -R -m "u:$appuid:rwX" "$REPO_DIR"
  find "$REPO_DIR" -type d -exec setfacl -m "d:u:$appuid:rwX" {} +

  ensure_device "$APP" "$DEV_APP" "$REPO_DIR" "/srv/app"
  ensure_device "$EDGE" "$DEV_STATIC" "$REPO_DIR/public" "/srv/static" true
  ensure_device "$EDGE" "$DEV_TILES" "$REPO_DIR/storage/tiles" "/srv/tiles" true
  EDGE="$(marker_get MARKER_EDGE)"

  local php_tmp nginx_tmp
  php_tmp="$(mktemp)"; nginx_tmp="$(mktemp)"
  cp "$(marker_get BACKUP_PHP)" "$php_tmp"
  patch_php_ini "$php_tmp"
  incus file push "$php_tmp" "$APP$PHP_INI" >/dev/null
  incus exec "$APP" -- chown root:root "$PHP_INI"
  incus exec "$APP" -- chmod 0644 "$PHP_INI"

  cp "$(marker_get BACKUP_NGINX)" "$nginx_tmp"
  patch_nginx_conf "$nginx_tmp"
  incus file push "$nginx_tmp" "$EDGE$NGINX_CONF" >/dev/null
  incus exec "$EDGE" -- chown root:nginx "$NGINX_CONF"
  incus exec "$EDGE" -- chmod 0640 "$NGINX_CONF"
  rm -f "$php_tmp" "$nginx_tmp"

  incus exec "$EDGE" -- su -s /bin/sh -c '/usr/local/nginx/sbin/nginx -t -c /usr/local/nginx/conf/nginx.conf' nginx >/dev/null
  incus exec "$EDGE" -- su -s /bin/sh -c '/usr/local/nginx/sbin/nginx -c /usr/local/nginx/conf/nginx.conf -s reload' nginx
  incus exec "$APP" -- sv restart php-fpm >/dev/null 2>&1 || true

  smoke
  log "live dev is ON: edit $REPO_DIR and refresh the browser"
}

do_off() {
  EDGE="$(active_edge)"
  local backup_php backup_nginx
  backup_php="$(marker_get BACKUP_PHP)"
  backup_nginx="$(marker_get BACKUP_NGINX)"

  remove_device "$APP" "$DEV_APP"
  remove_device "$EDGE" "$DEV_STATIC"
  remove_device "$EDGE" "$DEV_TILES"

  if [ -n "$backup_php" ] && [ -f "$backup_php" ]; then
    incus file push "$backup_php" "$APP$PHP_INI" >/dev/null
    incus exec "$APP" -- chown root:root "$PHP_INI"
    incus exec "$APP" -- chmod 0644 "$PHP_INI"
    incus exec "$APP" -- sv restart php-fpm >/dev/null 2>&1 || true
    log "restored php.ini"
  else
    log "WARNING: no php.ini backup found; re-run 02-deploy-app.sh if opcache settings matter"
  fi

  if [ -n "$backup_nginx" ] && [ -f "$backup_nginx" ]; then
    incus file push "$backup_nginx" "$EDGE$NGINX_CONF" >/dev/null
    incus exec "$EDGE" -- chown root:nginx "$NGINX_CONF"
    incus exec "$EDGE" -- chmod 0640 "$NGINX_CONF"
    incus exec "$EDGE" -- su -s /bin/sh -c '/usr/local/nginx/sbin/nginx -t -c /usr/local/nginx/conf/nginx.conf' nginx >/dev/null
    incus exec "$EDGE" -- su -s /bin/sh -c '/usr/local/nginx/sbin/nginx -c /usr/local/nginx/conf/nginx.conf -s reload' nginx
    log "restored the edge configuration"
  else
    log "WARNING: no nginx backup found; the edge keeps the dev cache headers"
  fi

  rm -f "$MARKER"
  log "live dev is OFF; /srv/app shows the previous deployed copy"
  log "run scripts/04-jumpstart-app.sh to republish the current tree"
}

do_status() {
  if [ -f "$MARKER" ]; then
    EDGE="$(marker_get MARKER_EDGE)"
    log "mode: ON (marker: $MARKER)"
    log "edge: $EDGE"
  else
    log "mode: OFF"
    EDGE="$(active_edge)"
  fi
  for pair in "$APP:$DEV_APP" "$EDGE:$DEV_STATIC" "$EDGE:$DEV_TILES"; do
    local c="${pair%%:*}" n="${pair##*:}"
    if incus config device list "$c" 2>/dev/null | grep -qx "$n"; then
      log "device $c/$n source=$(device_source "$c" "$n")"
    else
      log "device $c/$n absent"
    fi
  done
  log "opcache.validate_timestamps=$(incus exec "$APP" -- /usr/sbin/php -i 2>/dev/null \
    | sed -n 's/^opcache.validate_timestamps => //p' | head -n1)"
  if [ -n "${EDGE:-}" ]; then
    log "edge no-store rules=$(incus exec "$EDGE" -- grep -c 'no-store' "$NGINX_CONF" 2>/dev/null || true)"
  fi
}

usage() {
  printf 'usage: %s on|off|status\n' "$0"
  exit 2
}

case "${1:-}" in
  on) do_on ;;
  off) do_off ;;
  status) do_status ;;
  *) usage ;;
esac

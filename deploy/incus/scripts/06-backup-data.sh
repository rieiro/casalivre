#!/usr/bin/env bash
# Host-level data backup for the CasaLivre 3-tier stack.
#
# Complements backup-incus-containers.sh (which exports the containers/volumes):
#   - consistent PostgreSQL logical dump + role/globals dump
#   - host working tree uploads/originals/secrets (live-dev + deployment env)
#   - the casalivre-media volume (production uploads/originals/Valkey data)
#
# Not included: the host repo storage/tiles (1.5G). The edge rootfs backup contains
# /srv/static/tiles; see deploy/incus/README.md ("Backups").
#
# Usage (root; needs Incus and read access to deploy/incus/secrets):
#   sudo ./scripts/06-backup-data.sh
#
# Env overrides: BACKUP_DIR, DB, POOL, MEDIA_VOLUME, MAX_BACKUPS, REPO_DIR, LOG_FILE.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="${REPO_DIR:-$(cd "$ROOT_DIR/../.." && pwd)}"

BACKUP_DIR="${BACKUP_DIR:-/backup-sdcard}"
DB="${DB:-db-pg}"
DB_PGHOST="${DB_PGHOST:-/run/postgresql}"
APP="${APP:-app-php}"
POOL="${POOL:-default}"
MEDIA_VOLUME="${MEDIA_VOLUME:-casalivre-media}"
MAX_BACKUPS="${MAX_BACKUPS:-7}"
MIN_FREE_MB="${MIN_FREE_MB:-1024}"
DATE="$(date +%Y%m%d_%H%M%S)"
LOG_FILE="${LOG_FILE:-$BACKUP_DIR/casalivre-data-backup.log}"

umask 077

log() { printf '[%s] %s\n' "$(date +%Y-%m-%dT%H:%M:%S%z)" "$*" | tee -a "$LOG_FILE"; }
die() { log "ERROR: $*"; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require tar
require gzip

[ "$(id -u)" -eq 0 ] || die "run as root (needs Incus and deploy/incus/secrets)"
[ -d "$BACKUP_DIR" ] || die "backup directory does not exist: $BACKUP_DIR"
[ -w "$BACKUP_DIR" ] || die "backup directory is not writable: $BACKUP_DIR"
[[ "$MAX_BACKUPS" =~ ^[0-9]+$ ]] && [ "$MAX_BACKUPS" -ge 1 ] || die "MAX_BACKUPS must be a positive integer"
[[ "$MIN_FREE_MB" =~ ^[0-9]+$ ]] && [ "$MIN_FREE_MB" -ge 1 ] || die "MIN_FREE_MB must be a positive integer"
if [ ! -d "$REPO_DIR" ]; then
  log "WARNING: repo dir $REPO_DIR is missing; host data will be skipped"
  REPO_DIR=""
fi
avail_kb="$(df -Pk "$BACKUP_DIR" | awk 'NR==2 {print $4}')"
[ -n "$avail_kb" ] && [ "$avail_kb" -ge "$((MIN_FREE_MB * 1024))" ] \
  || die "not enough free space in $BACKUP_DIR (need ${MIN_FREE_MB}MB, have $((avail_kb / 1024))MB)"

tmp_files=()
cleanup() {
  local f
  for f in "${tmp_files[@]:-}"; do
    [ -n "$f" ] && rm -f "$f"
  done
}
trap cleanup EXIT

log "==== casalivre data backup start ===="
log "repo=${REPO_DIR:-none} backup=$BACKUP_DIR db=$DB pghost=$DB_PGHOST app=$APP media=$POOL/$MEDIA_VOLUME keep=$MAX_BACKUPS"

# ---------------------------------------------------------------- PostgreSQL
db_dump="$BACKUP_DIR/casalivre-db_${DATE}.dump"
db_tmp="$db_dump.tmp"
tmp_files+=("$db_tmp")
incus exec "$DB" -- su - postgres -c "PGHOST=$DB_PGHOST pg_dump -Fc casalivre" \
  > "$db_tmp" 2>>"$LOG_FILE"
[ -s "$db_tmp" ] || die "pg_dump produced an empty file"
mv "$db_tmp" "$db_dump"
log "database: $db_dump ($(du -h "$db_dump" | cut -f1))"

globals="$BACKUP_DIR/casalivre-globals_${DATE}.sql"
globals_tmp="$globals.tmp"
tmp_files+=("$globals_tmp")
incus exec "$DB" -- su - postgres -c "PGHOST=$DB_PGHOST pg_dumpall --globals-only" \
  > "$globals_tmp" 2>>"$LOG_FILE"
[ -s "$globals_tmp" ] || die "pg_dumpall --globals-only produced an empty file"
mv "$globals_tmp" "$globals"
log "globals: $globals"

# ---------------------------------------------------------------- app uploads (authoritative in both modes)
app_tar="$BACKUP_DIR/casalivre-app_${DATE}.tar.gz"
app_tmp="$app_tar.tmp"
tmp_files+=("$app_tmp")
if incus exec "$APP" -- test -d /srv/app/public/uploads >/dev/null 2>&1; then
  incus exec "$APP" -- tar -C /srv/app -czf - public/uploads > "$app_tmp" 2>>"$LOG_FILE"
  [ -s "$app_tmp" ] || die "app uploads tar produced an empty file"
  mv "$app_tmp" "$app_tar"
  log "app uploads: $app_tar ($(du -h "$app_tar" | cut -f1))"
else
  log "WARNING: /srv/app/public/uploads not found in $APP; skipping app uploads archive"
fi

# ---------------------------------------------------------------- host data (optional)
host_tar="$BACKUP_DIR/casalivre-host_${DATE}.tar.gz"
host_tmp="$host_tar.tmp"
paths=()
if [ -n "$REPO_DIR" ]; then
  for rel in storage/fotos deploy/incus/secrets; do
    [ -e "$REPO_DIR/$rel" ] && paths+=("$rel")
  done
  [ -f "$REPO_DIR/.env" ] && paths+=(.env)
fi
if [ "${#paths[@]}" -gt 0 ]; then
  tmp_files+=("$host_tmp")
  tar -C "$REPO_DIR" -czf "$host_tmp" "${paths[@]}" 2>>"$LOG_FILE"
  [ -s "$host_tmp" ] || die "host tar produced an empty file"
  mv "$host_tmp" "$host_tar"
  log "host data: $host_tar ($(du -h "$host_tar" | cut -f1)) [${paths[*]}]"
else
  log "host data: no host paths found; skipping host archive"
fi

# ---------------------------------------------------------------- media volume
media_tar="$BACKUP_DIR/casalivre-media_${DATE}.tar.gz"
media_tmp="$media_tar.tmp"
tmp_files+=("$media_tmp")
incus storage volume export "$POOL" "$MEDIA_VOLUME" "$media_tmp" \
  --volume-only --compression gzip >>"$LOG_FILE" 2>&1
[ -s "$media_tmp" ] || die "storage volume export produced an empty file"
mv "$media_tmp" "$media_tar"
log "media volume: $media_tar ($(du -h "$media_tar" | cut -f1))"

# ---------------------------------------------------------------- retention
for prefix in casalivre-db_ casalivre-globals_ casalivre-app_ casalivre-host_ casalivre-media_; do
  mapfile -t old < <(ls -1t "$BACKUP_DIR/${prefix}"* 2>/dev/null || true)
  if [ "${#old[@]}" -gt "$MAX_BACKUPS" ]; then
    printf '%s\n' "${old[@]:$MAX_BACKUPS}" | xargs -r rm -f
    log "retention: removed $((${#old[@]} - MAX_BACKUPS)) old ${prefix}* file(s)"
  fi
done

log "tiles: host storage/tiles is not in this data backup; the edge rootfs export contains /srv/static/tiles"
log "disk: $(df -h "$BACKUP_DIR" | awk 'NR==2 {print $4 " free"}')"
log "==== casalivre data backup done ===="

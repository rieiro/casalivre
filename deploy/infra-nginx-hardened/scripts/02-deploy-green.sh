#!/usr/bin/env bash
# Stage a new production nginx container ("green"): install the verified artifact + read-only config,
# validate with `nginx -t`, and start it as the unprivileged nginx user.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ARTIFACTS_DIR="$ROOT_DIR/artifacts"
STATE_DIR="$ARTIFACTS_DIR/state"

CONTAINER="${CONTAINER:-casalivre-nginx-green}"
PROFILE="${PROFILE:-casalivre-nginx-prod}"
PRIMARY_DOMAIN="${PRIMARY_DOMAIN:-}"
BACKEND_UPSTREAM="${BACKEND_UPSTREAM:-}"
TLS_CERT="${TLS_CERT:-}"
TLS_KEY="${TLS_KEY:-}"
ARTIFACT="${ARTIFACT:-}"

log() { printf '[green] %s\n' "$*"; }
die() { printf '[green] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus
require sha256sum

_alpine_override="${ALPINE_IMAGE:-}"
. "$ROOT_DIR/build/versions.env"
[ -n "$_alpine_override" ] && ALPINE_IMAGE="$_alpine_override"

[ -n "$PRIMARY_DOMAIN" ]  || die "PRIMARY_DOMAIN is required (e.g. example.com)"
[ -n "$BACKEND_UPSTREAM" ] || die "BACKEND_UPSTREAM is required (host:port)"
[ -n "$TLS_CERT" ] && [ -f "$TLS_CERT" ] || die "TLS_CERT must be a readable certificate file"
[ -n "$TLS_KEY" ]  && [ -f "$TLS_KEY" ]  || die "TLS_KEY must be a readable private key file"

# Artifact: explicit ARTIFACT or the newest hardened tarball.
if [ -z "$ARTIFACT" ]; then
  ARTIFACT="$(ls -1t "$ARTIFACTS_DIR"/nginx-*-hardened.tar.gz 2>/dev/null | head -n1 || true)"
fi
[ -n "$ARTIFACT" ] && [ -f "$ARTIFACT" ] || die "no artifact found; run scripts/01-build-artifact.sh"
[ -f "$ARTIFACT.sha256" ] || die "missing $ARTIFACT.sha256"
( cd "$(dirname "$ARTIFACT")" && sha256sum -c "$(basename "$ARTIFACT").sha256" >/dev/null )
log "artifact verified: $(basename "$ARTIFACT")"

mkdir -p "$STATE_DIR"

if incus info "$CONTAINER" >/dev/null 2>&1; then
  [ "${REPLACE:-0}" = "1" ] || die "$CONTAINER already exists (set REPLACE=1 to rebuild it)"
  log "REPLACE=1: removing existing $CONTAINER"
  incus config set "$CONTAINER" security.protection.delete=false
  incus stop "$CONTAINER" --force >/dev/null 2>&1 || true
  incus delete "$CONTAINER" --force
fi

log "launching $CONTAINER ($ALPINE_IMAGE)"
incus launch "$ALPINE_IMAGE" "$CONTAINER" --profile "$PROFILE"
incus config set "$CONTAINER" security.protection.delete=true
incus config set "$CONTAINER" boot.autostart=false

log "waiting for network"
ready=0
for _ in $(seq 1 30); do
  if incus list "$CONTAINER" -c4 --format csv 2>/dev/null | grep -Eq '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+'; then
    ready=1
    break
  fi
  sleep 2
done
[ "$ready" -eq 1 ] || die "$CONTAINER did not get a network route"

# Dedicated non-root service account and runtime directories.
incus exec "$CONTAINER" -- sh -c '
  set -e
  addgroup -S nginx 2>/dev/null || true
  adduser -S -D -H -h /var/lib/nginx -s /sbin/nologin -G nginx nginx 2>/dev/null || true
  mkdir -p /run/nginx /var/log/nginx \
    /var/cache/nginx/client_body /var/cache/nginx/proxy \
    /var/cache/nginx/fastcgi /var/cache/nginx/uwsgi /var/cache/nginx/scgi \
    /usr/local/nginx/conf/snippets /usr/local/nginx/conf/sites-enabled \
    /etc/nginx-hardened/tls /var/www/acme
  chown -R nginx:nginx /run/nginx /var/log/nginx /var/cache/nginx
  chmod 0750 /run/nginx /var/log/nginx /var/cache/nginx
'

log "installing artifact"
incus file push "$ARTIFACT" "$CONTAINER/root/nginx-hardened.tar.gz"
incus exec "$CONTAINER" -- sh -c '
  set -e
  tar -xzf /root/nginx-hardened.tar.gz -C /
  rm -f /root/nginx-hardened.tar.gz
'

log "installing configuration"
incus file push -r "$ROOT_DIR/config/." "$CONTAINER/usr/local/nginx/conf/"

log "installing TLS material"
incus file push "$TLS_CERT" "$CONTAINER/etc/nginx-hardened/tls/fullchain.pem"
incus file push "$TLS_KEY"  "$CONTAINER/etc/nginx-hardened/tls/privkey.pem"

log "rendering deployment parameters"
incus exec "$CONTAINER" -- sh -c "
  set -e
  cd /usr/local/nginx/conf
  find . -type f -name '*.conf' -print0 | xargs -0 sed -i \
    -e 's|__PRIMARY_DOMAIN__|$PRIMARY_DOMAIN|g' \
    -e 's|__BACKEND_UPSTREAM__|$BACKEND_UPSTREAM|g' \
    -e 's|__TLS_CERT__|/etc/nginx-hardened/tls/fullchain.pem|g' \
    -e 's|__TLS_KEY__|/etc/nginx-hardened/tls/privkey.pem|g'
"

log "locking down configuration"
incus exec "$CONTAINER" -- sh -c '
  set -e
  chown -R root:nginx /usr/local/nginx/conf /etc/nginx-hardened
  chmod 0750 /usr/local/nginx/conf /usr/local/nginx/conf/snippets /usr/local/nginx/conf/sites-enabled \
    /etc/nginx-hardened /etc/nginx-hardened/tls
  find /usr/local/nginx/conf -type f -exec chmod 0640 {} +
  chmod 0640 /etc/nginx-hardened/tls/*
'

log "validating configuration (nginx -t) as nginx"
if ! incus exec "$CONTAINER" -- su -s /bin/sh -c '/usr/local/nginx/sbin/nginx -t -c /usr/local/nginx/conf/nginx.conf' nginx; then
  die "nginx -t failed; $CONTAINER was not started"
fi

log "installing the OpenRC service"
incus exec "$CONTAINER" -- sh -c '
  set -e
  cat > /usr/local/bin/nginx-foreground <<"WRAP"
#!/bin/sh
exec /usr/local/nginx/sbin/nginx -c /usr/local/nginx/conf/nginx.conf -g "daemon off;"
WRAP
  chmod 0755 /usr/local/bin/nginx-foreground

  cat > /etc/init.d/nginx-hardened <<"INIT"
#!/sbin/openrc-run
description="CasaLivre hardened nginx edge"
pidfile="/run/nginx/nginx.pid"
command="/usr/local/bin/nginx-foreground"
command_args=""
command_user="nginx:nginx"
command_background="true"
respawn_delay=3
respawn_max=0

depend() { need net; }

start_pre() {
    checkpath -d -o nginx:nginx -m 0750 /run/nginx
}
INIT
  chmod 0755 /etc/init.d/nginx-hardened
  rc-update add nginx-hardened default >/dev/null 2>&1 || true
'

log "starting $CONTAINER"
incus exec "$CONTAINER" -- su -s /bin/sh -c '/usr/local/nginx/sbin/nginx -c /usr/local/nginx/conf/nginx.conf' nginx

ip="$(incus list "$CONTAINER" -c4 --format csv | tr ',' '\n' | grep -Eo '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -n1 || true)"
[ -n "$ip" ] || die "could not determine $CONTAINER IPv4"
printf '%s\n' "$ip" > "$STATE_DIR/$CONTAINER.ip"

log "green is up: $CONTAINER at $ip:8080 (health http://$ip:8080/healthz)"
log "next: sudo $ROOT_DIR/scripts/03-cutover.sh"

#!/usr/bin/env bash
# Deploy the PHP-FPM application tier with read-only code and a writable media volume.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

CONTAINER="${CONTAINER:-app-php}"
IMAGE="${IMAGE:-images:voidlinux/current}"
PHP_PACKAGES="${PHP_PACKAGES:-php8.4 php8.4-fpm php8.4-pgsql php8.4-gd php8.4-intl ca-certificates}"
CA_BUNDLE="${CA_BUNDLE:-/etc/ssl/certs/ca-certificates.crt}"

log() { printf '[app] %s\n' "$*"; }
die() { printf '[app] ERROR: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || die "required tool not found: $1"; }

require incus

if incus info "$CONTAINER" >/dev/null 2>&1; then
  log "container exists: $CONTAINER"
else
  log "launching $CONTAINER ($IMAGE)"
  incus launch "$IMAGE" "$CONTAINER" --no-profiles \
    --profile base-void-hardened --profile app-php
fi

# The Void image does not apply Incus' static IPs; configure both NICs in-guest.
for _ in $(seq 1 10); do
  if incus exec "$CONTAINER" -- test -e /sys/class/net/eth0 >/dev/null 2>&1 \
     && incus exec "$CONTAINER" -- test -e /sys/class/net/eth1 >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
incus exec "$CONTAINER" -- sh -c 'printf "nameserver 10.10.10.1\n" > /etc/resolv.conf'
incus exec "$CONTAINER" -- ip addr replace 10.10.10.20/24 dev eth0
incus exec "$CONTAINER" -- ip link set eth0 up
incus exec "$CONTAINER" -- ip route replace default via 10.10.10.1 dev eth0
incus exec "$CONTAINER" -- ip addr replace 10.10.20.11/24 dev eth1
incus exec "$CONTAINER" -- ip link set eth1 up
if [ -r "$CA_BUNDLE" ]; then
  incus file push "$CA_BUNDLE" "$CONTAINER/etc/ssl/certs/ca-certificates.crt"
  incus file push "$CA_BUNDLE" "$CONTAINER/root/ca-bundle.crt"
fi

got_ip=0
for _ in $(seq 1 30); do
  if incus list "$CONTAINER" -c4 --format csv | tr ',' '\n' | grep -Eq '^10\.10\.10\.20( |$)'; then
    got_ip=1
    break
  fi
  sleep 1
done
[ "$got_ip" -eq 1 ] || die "$CONTAINER did not get 10.10.10.20"

if ! incus exec "$CONTAINER" -- xbps-query -p pkgver php8.4-fpm >/dev/null 2>&1; then
  log "installing PHP 8.4 FPM and extensions"
  incus exec "$CONTAINER" -- mkdir -p /root/app-php
  incus file push -r "$ROOT_DIR/containers/app-php/." "$CONTAINER/root/app-php/"
  incus exec "$CONTAINER" -- env PHP_PACKAGES="$PHP_PACKAGES" bash /root/app-php/provision.sh
  incus exec "$CONTAINER" -- rm -rf /root/app-php
else
  log "PHP 8.4 FPM already installed"
fi

# The unprivileged account is created here as well so the deploy is idempotent and the
# ownership steps below never depend on the provisioner having run in this invocation.
incus exec "$CONTAINER" -- sh -c '
  set -e
  groupadd -r appuser 2>/dev/null || true
  useradd -r -g appuser -d /srv/app -s /sbin/nologin appuser 2>/dev/null || true
  mkdir -p /srv/app /var/lib/casalivre/media /var/lib/php/sessions /var/log/php-fpm
  chown -R root:appuser /srv/app
  chown -R appuser:appuser /var/lib/casalivre/media /var/lib/php/sessions /var/log/php-fpm
  find /srv/app -type d -exec chmod 0750 {} +
  find /srv/app -type f -exec chmod 0640 {} + 2>/dev/null || true
  chmod 0750 /var/lib/casalivre/media /var/lib/php/sessions /var/log/php-fpm
'

log "installing php.ini and the PHP-FPM pool"
incus file push "$ROOT_DIR/containers/app-php/config/php.ini" "$CONTAINER/etc/php8.4/php.ini"
incus file push "$ROOT_DIR/containers/app-php/config/php-fpm.d/www.conf" "$CONTAINER/etc/php8.4/php-fpm.d/www.conf"
incus exec "$CONTAINER" -- chown root:root /etc/php8.4/php.ini /etc/php8.4/php-fpm.d/www.conf
incus exec "$CONTAINER" -- chmod 0644 /etc/php8.4/php.ini /etc/php8.4/php-fpm.d/www.conf

log "enabling /var/service/php-fpm"
incus exec "$CONTAINER" -- sh -c 'ln -sfn /etc/sv/php-fpm8.4 /var/service/php-fpm'
incus exec "$CONTAINER" -- sv up php-fpm >/dev/null 2>&1 || incus exec "$CONTAINER" -- sv restart php-fpm >/dev/null 2>&1 || true

ready=0
for _ in $(seq 1 30); do
  if incus exec "$CONTAINER" -- sh -c 'ss -ltn 2>/dev/null | grep -q "10.10.10.20:9000"'; then
    ready=1
    break
  fi
  sleep 1
done
[ "$ready" -eq 1 ] || die "PHP-FPM did not listen on 10.10.10.20:9000"

incus exec "$CONTAINER" -- /usr/sbin/php -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);' || die "OPcache is not loaded"
incus exec "$CONTAINER" -- /usr/sbin/php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);' || die "pdo_pgsql is not loaded"

log "app-php is ready: 10.10.10.20:9000 (allowed: 10.10.10.10,10.10.10.11)"
log "deploy the application code (read-only) into /srv/app before serving traffic"
log "next: sudo $ROOT_DIR/../infra-nginx-hardened/scripts/00-init-host.sh"

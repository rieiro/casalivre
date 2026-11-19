#!/usr/bin/env bash
# In-container provisioning for app-php (Void Linux glibc + runit).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { printf '[app-php provision] %s\n' "$*"; }
die() { printf '[app-php provision] ERROR: %s\n' "$*" >&2; exit 1; }

command -v xbps-install >/dev/null 2>&1 || die "xbps-install not found"

log "provisioner: $SCRIPT_DIR"

PHP_PACKAGES="${PHP_PACKAGES:-php8.4 php8.4-fpm php8.4-pgsql php8.4-gd php8.4-intl ca-certificates}"

log "installing PHP 8.4 FPM and extensions"
xbps-install -Syu -y
if [ -r /root/ca-bundle.crt ]; then cp /root/ca-bundle.crt /etc/ssl/certs/ca-certificates.crt; fi
# shellcheck disable=SC2086 # package names are space-separated by design
xbps-install -y $PHP_PACKAGES

# No remote shells, downloaders or compilers on the application tier.
for pkg in openssh curl wget gcc make; do
    if xbps-query -p pkgver "$pkg" >/dev/null 2>&1; then
        log "removing forbidden package: $pkg"
        xbps-remove -y "$pkg" >/dev/null 2>&1 || true
    fi
done

# openssh is a base dependency on Void; remove the server binary even when the package stays.
rm -f /usr/sbin/sshd

log "creating the unprivileged application user"
groupadd -r appuser 2>/dev/null || true
useradd -r -g appuser -d /srv/app -s /sbin/nologin appuser 2>/dev/null || true

log "preparing directories"
mkdir -p /srv/app /var/lib/casalivre/media /var/lib/php/sessions /var/log/php-fpm
chown -R root:appuser /srv/app
chown -R appuser:appuser /var/lib/casalivre/media /var/lib/php/sessions /var/log/php-fpm
chmod 0750 /srv/app /var/lib/casalivre/media /var/lib/php/sessions /var/log/php-fpm
chmod 1777 /tmp   # PHP tempnam()/uploads need a world-writable sticky temp dir
find /srv/app -type d -exec chmod 0750 {} +
find /srv/app -type f -exec chmod 0640 {} + 2>/dev/null || true

log "done"

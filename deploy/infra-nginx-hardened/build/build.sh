#!/usr/bin/env bash
# Compiles the hardened nginx artifact inside the disposable Alpine builder container.
# Invoked by scripts/01-build-artifact.sh. Never runs on the production container.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=versions.env
. "$HERE/versions.env"
# shellcheck source=configure.flags
. "$HERE/configure.flags"

OUT_DIR="${OUT_DIR:-/root/out}"
WORK_DIR="${WORK_DIR:-/root/work}"
JOBS="${JOBS:-$(nproc 2>/dev/null || echo 2)}"

log() { printf '[build] %s\n' "$*" >&2; }
die() { printf '[build] ERROR: %s\n' "$*" >&2; exit 1; }

command -v apk >/dev/null 2>&1 || die "apk not found: run inside the Alpine builder"

log "installing build dependencies"
apk add --no-cache build-base pcre2-dev zlib-dev openssl-dev linux-headers \
  curl ca-certificates git bash musl-utils >/dev/null
if [ -r /root/ca-bundle.crt ]; then cp /root/ca-bundle.crt /etc/ssl/certs/ca-certificates.crt; fi

mkdir -p "$OUT_DIR" "$WORK_DIR"

src="$WORK_DIR/nginx-$NGINX_VERSION"
rm -rf "$src"
log "downloading nginx $NGINX_VERSION"
curl -fsSL --retry 3 --proto '=https' --tlsv1.2 \
  -o "$WORK_DIR/nginx.tar.gz" "https://nginx.org/download/nginx-$NGINX_VERSION.tar.gz"
printf '%s  %s\n' "$NGINX_SHA256" "nginx.tar.gz" > "$WORK_DIR/nginx.sha256"
( cd "$WORK_DIR" && sha256sum -c nginx.sha256 >/dev/null ) || die "nginx SHA256 mismatch"
log "nginx SHA256 verified"
tar -xzf "$WORK_DIR/nginx.tar.gz" -C "$WORK_DIR"

hm="$WORK_DIR/headers-more"
rm -rf "$hm"
log "fetching headers-more $HEADERS_MORE_VERSION ($HEADERS_MORE_COMMIT)"
git clone --quiet "$HEADERS_MORE_URL" "$hm"
git -C "$hm" checkout --quiet "$HEADERS_MORE_COMMIT"
actual="$(git -C "$hm" rev-parse HEAD)"
[ "$actual" = "$HEADERS_MORE_COMMIT" ] || die "headers-more commit mismatch: expected $HEADERS_MORE_COMMIT, got $actual"
log "headers-more commit verified"

cd "$src"
log "configuring nginx"
./configure \
  "${NGINX_CONFIGURE_FLAGS[@]}" \
  --add-module="$hm" \
  --with-cc-opt="$HARDEN_CFLAGS" \
  --with-ld-opt="$HARDEN_LDFLAGS"

log "compiling with $JOBS job(s)"
make -j"$JOBS"
make install DESTDIR="$WORK_DIR/pkg" >/dev/null

stage="$WORK_DIR/stage"
rm -rf "$stage"
mkdir -p "$stage"
cp -a "$WORK_DIR/pkg/." "$stage/"

# Bundle the runtime libraries so the production container needs no package manager.
mkdir -p "$stage/usr/lib"
if command -v ldd >/dev/null 2>&1; then
  ldd "$stage/usr/local/nginx/sbin/nginx" 2>/dev/null | awk '/=>/ {print $3}' | while read -r lib; do
    if [ -f "$lib" ]; then cp -L "$lib" "$stage/usr/lib/"; fi
  done
fi

cat > "$stage/BUILD-INFO" <<EOF
nginx_version=$NGINX_VERSION
nginx_sha256=$NGINX_SHA256
headers_more_version=$HEADERS_MORE_VERSION
headers_more_commit=$HEADERS_MORE_COMMIT
cflags=$HARDEN_CFLAGS
ldflags=$HARDEN_LDFLAGS
built_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)
builder=$(uname -a)
EOF

artifact="$OUT_DIR/nginx-$NGINX_VERSION-hardened.tar.gz"
rm -f "$artifact" "$artifact.sha256"
tar -C "$stage" -czf "$artifact" .
( cd "$OUT_DIR" && sha256sum "$(basename "$artifact")" > "$(basename "$artifact").sha256" )
log "artifact ready"
cat "$OUT_DIR/$(basename "$artifact").sha256"

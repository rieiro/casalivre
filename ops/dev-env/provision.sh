#!/usr/bin/env bash
# Provisiona o rootfs Void musl do ambiente dev em $CL_HOME/rootfs (P0-1).
# Idempotente: pode rodar de novo; reinstala só o que divergir do versions.lock.
# Variáveis: ver env.example. CL_ROOTFS_TARBALL=<arquivo> usa um tarball local
# (o sha256 do versions.lock continua obrigatório).
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults

command -v bwrap >/dev/null || die "bwrap (bubblewrap) não encontrado no host"

name=$(lock_get rootfs)
sha=$(lock_get rootfs_sha256)
url=$(lock_get rootfs_url)
repo=$(lock_get repo)
mapfile -t pkgs < <(lock_pkgs)

mkdir -p "$CL_CACHE/xbps" "$CL_CACHE/dl" "$CL_RUN" "$CL_HOME/etc" "$CL_HOME/log" "$CL_HOME/data"
tarball=$CL_CACHE/dl/$name
if [ ! -f "$tarball" ]; then
	if [ -n "${CL_ROOTFS_TARBALL:-}" ]; then
		cp "$CL_ROOTFS_TARBALL" "$tarball.part"
	else
		log "baixando $url"
		curl -fL --retry 3 -o "$tarball.part" "$url"
	fi
	mv "$tarball.part" "$tarball"
fi
echo "$sha  $tarball" | sha256sum -c --quiet - || { rm -f "$tarball"; die "sha256 do rootfs diverge do versions.lock"; }

if [ ! -x "$CL_ROOTFS/usr/bin/xbps-install" ]; then
	log "extraindo rootfs em $CL_ROOTFS"
	rm -rf "$CL_ROOTFS.part"
	mkdir -p "$CL_ROOTFS.part"
	tar -xJf "$tarball" -C "$CL_ROOTFS.part" --no-same-owner --exclude='./dev/*'
	mv "$CL_ROOTFS.part" "$CL_ROOTFS"
fi

# Root falso (user namespace) só para o gerenciador de pacotes. Cache do xbps
# fica fora do rootfs ($CL_CACHE/xbps).
as_root() {
	bwrap --bind "$CL_ROOTFS" / --dev /dev --proc /proc --tmpfs /tmp \
		--ro-bind /etc/resolv.conf /etc/resolv.conf \
		--bind "$CL_CACHE/xbps" /var/cache/xbps \
		--unshare-user --uid 0 --gid 0 --unshare-pid --unshare-ipc --share-net \
		--setenv PATH /usr/bin:/usr/sbin \
		-- "$@"
}

echo "repository=$repo" >"$CL_ROOTFS/etc/xbps.d/00-repository-main.conf"
log "atualizando xbps e base"
as_root xbps-install -Suy xbps
as_root xbps-install -Suy
pkgname() { printf '%s\n' "${1%-*}"; }
installed() { as_root xbps-query -p pkgver "$1" 2>/dev/null || true; }
# ca-certificates: https de dentro do ambiente (critério 13); gettext: envsubst
# para renderizar os templates (o host pode não ter).
want=()
for p in ca-certificates gettext; do [ -n "$(installed "$p")" ] || want+=("$p"); done
for p in "${pkgs[@]}"; do [ "$(installed "$(pkgname "$p")")" = "$p" ] || want+=("$p"); done
if [ "${#want[@]}" -gt 0 ]; then
	log "instalando: ${want[*]}"
	as_root xbps-install -y "${want[@]}" ||
		die "falha ao instalar as versões travadas (o repo pode ter avançado; ver README, 'Versões')"
fi

bad=0
for p in "${pkgs[@]}"; do
	got=$(installed "$(pkgname "$p")")
	if [ "$got" != "$p" ]; then echo "dev-env: divergência: esperado $p, instalado ${got:-nada}" >&2; bad=1; fi
done
[ "$bad" -eq 0 ] || die "versões instaladas divergem do versions.lock"

# Usuário do host dentro do rootfs (mesmo uid/gid), para os serviços rodarem
# sem root.
uid=$(id -u); gid=$(id -g); user=$(id -un); group=$(id -gn)
grep -q "^[^:]*:x:$uid:" "$CL_ROOTFS/etc/passwd" ||
	echo "$user:x:$uid:$gid:casalivre dev:$CL_HOME:/bin/sh" >>"$CL_ROOTFS/etc/passwd"
grep -q "^[^:]*:x:$gid:" "$CL_ROOTFS/etc/group" ||
	echo "$group:x:$gid:" >>"$CL_ROOTFS/etc/group"

# /tmp 1777 (critério 9).
chmod 1777 "$CL_ROOTFS/tmp"
[ "$(stat -c %a "$CL_ROOTFS/tmp")" = 1777 ] || die "/tmp do rootfs não ficou 1777"

# php.ini: pdo_pgsql, redis, OPcache.
ini=$CL_ROOTFS/etc/php8.4/php.ini
[ -f "$ini" ] || die "php.ini não encontrado em $ini"
sed -i -e 's/^;\(extension=openssl\)/\1/' -e 's/^;\(extension=pdo_pgsql\)/\1/' -e 's/^;\(extension=pgsql\)/\1/' \
	-e 's/^;\(extension=exif\)/\1/' -e 's/^upload_max_filesize = .*/upload_max_filesize = 16M/' \
	-e 's/^post_max_size = .*/post_max_size = 20M/' -e 's/^max_file_uploads = .*/max_file_uploads = 20/' \
	-e 's/^;\(zend_extension=opcache\)/\1/' -e 's/^;\(opcache.enable=\).*/\11/' "$ini"
grep -q '^extension=pdo_pgsql' "$ini" || echo 'extension=pdo_pgsql' >>"$ini"
mkdir -p "$CL_ROOTFS/etc/php8.4/conf.d"
if ! as_root php8.4 -m 2>/dev/null | grep -qx redis; then
	echo 'extension=redis' >"$CL_ROOTFS/etc/php8.4/conf.d/redis.ini"
fi
mods=$(as_root php8.4 -m)
for m in openssl pdo_pgsql redis exif 'Zend OPcache'; do
	grep -qx "$m" <<<"$mods" || die "extensão PHP ausente: $m"
done

# https de dentro (critério 13), como o usuário comum, com a validação de CA.
bwrap --ro-bind "$CL_ROOTFS" / --dev /dev --proc /proc --tmpfs /tmp \
	--ro-bind /etc/resolv.conf /etc/resolv.conf --unshare-user --unshare-pid --share-net \
	-- /usr/bin/php8.4 -r 'exit(file_get_contents("https://repo-default.voidlinux.org/") === false ? 1 : 0);' ||
	die "https de dentro do rootfs falhou (CA?)"

# P0-3 (critério 10): dependências da suíte, dentro do rootfs. O script vem do #111.
if [ -f "$CL_DEVENV_DIR/check-test-deps.sh" ]; then
	bwrap --ro-bind "$CL_ROOTFS" / --dev /dev --proc /proc --tmpfs /tmp --ro-bind "$CL_REPO" "$CL_REPO" \
		--unshare-all --setenv PATH /usr/bin:/usr/sbin --setenv CL_PHP php8.4 \
		-- /bin/bash "$CL_DEVENV_DIR/check-test-deps.sh" || die "dependências da suíte ausentes"
else
	log "aviso: check-test-deps.sh ausente (chega com o #111)"
fi

date -u +%FT%TZ >"$CL_HOME/.provisioned"
log "provision ok em $CL_HOME"

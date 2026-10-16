#!/usr/bin/env bash
# P0-5 (criterion 3): brings the dev env up in an unprivileged Incus container, with the SAME rootfs,
# configs and sv/ as up-bwrap.sh. Only the entry layer changes:
#   - the rootfs is $CL_ROOTFS from provision.sh, packed as-is into a local Incus image (no build, no
#     installs); the image alias carries a hash of versions.lock + .provisioned, so it is re-imported only
#     when the rootfs changes;
#   - the same dirs as up-bwrap.sh are disk devices: the repo and $CL_HOME/{run,etc,log,sv,data}.
#     $CL_HOME/admin (cl_admin secret) and the rest of CL_HOME are never mounted;
#   - raw.idmap maps this uid/gid 1:1, and init is runsvdir -P $CL_HOME/sv as this uid (lxc.init.uid);
#   - PG, Valkey and FPM stay on Unix sockets in $CL_RUN; the only port is a proxy device on
#     127.0.0.1:$CL_PORT_HTTP.
# Idempotent: a running container for this CL_HOME is left alone; a stopped one is recreated (state lives
# in the mounted CL_HOME dirs). down.sh stops it. Needs the incus client and a daemon that allows raw.idmap
# for this uid (subuid/subgid "root:<uid>:1"). Usage: up-incus.sh  (always detached)
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults
[ $# -eq 0 ] || die "usage: $0"
command -v incus >/dev/null 2>&1 || die "incus client not found"
[ -f "$CL_HOME/.provisioned" ] || die "run provision.sh first (CL_HOME=$CL_HOME)"

name=$(incus_name)
status=$(incus list "$name" --format csv --columns s 2>/dev/null || true)
if [ "$status" = RUNNING ]; then
	log "already up (incus $name)"
	exit 0
fi
[ -z "$status" ] || incus delete --force "$name"

prestart incus

# Image from the same rootfs dir (packed, not rebuilt).
tag=$(cat "$CL_DEVENV_DIR/versions.lock" "$CL_HOME/.provisioned" | sha256sum | cut -c1-12)
alias=cl-rootfs-$tag
if ! incus image info "$alias" >/dev/null 2>&1; then
	tmp=$(mktemp -d "$CL_CACHE/incus-img.XXXXXX")
	trap 'rm -rf "$tmp"' EXIT
	printf 'architecture: %s\ncreation_date: %s\nproperties:\n  description: casalivre dev rootfs %s\n' \
		"$(uname -m)" "$(date +%s)" "$tag" >"$tmp/metadata.yaml"
	tar -C "$tmp" -czf "$tmp/meta.tar.gz" metadata.yaml
	tar -C "$CL_ROOTFS" --numeric-owner -czf "$tmp/rootfs.tar.gz" .
	incus image import "$tmp/meta.tar.gz" "$tmp/rootfs.tar.gz" --alias "$alias"
	log "incus image $alias imported from $CL_ROOTFS"
fi

uid=$(id -u) gid=$(id -g)
incus init "$alias" "$name" \
	-c security.privileged=false -c security.nesting=false \
	-c raw.idmap="$(printf 'uid %s %s\ngid %s %s' "$uid" "$uid" "$gid" "$gid")" \
	-c raw.lxc="$(printf 'lxc.init.cmd = /usr/bin/runsvdir -P %s/sv\nlxc.init.uid = %s\nlxc.init.gid = %s' "$CL_HOME" "$uid" "$gid")" \
	-c environment.HOME="$CL_HOME" -c environment.PATH=/usr/bin:/usr/sbin -c environment.LANG=C.UTF-8 \
	-c environment.CL_HOME="$CL_HOME" -c environment.CL_REPO="$CL_REPO" -c environment.CL_PORT_HTTP="$CL_PORT_HTTP"
incus config device add "$name" repo disk source="$CL_REPO" path="$CL_REPO"
for d in run etc log sv data; do
	incus config device add "$name" "cl-$d" disk source="$CL_HOME/$d" path="$CL_HOME/$d"
done
incus config device add "$name" http proxy \
	listen="tcp:127.0.0.1:$CL_PORT_HTTP" connect="tcp:127.0.0.1:$CL_PORT_HTTP" bind=host
incus start "$name"
echo "$name" >"$CL_RUN/incus.name"
log "up: incus $name (http $CL_PORT_HTTP; PG/Valkey/FPM on sockets in $CL_RUN)"

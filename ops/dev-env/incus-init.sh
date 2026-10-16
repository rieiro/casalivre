#!/bin/sh
# P0-5: PID 1 of the Incus container (raw.lxc lxc.init.cmd), run as the container's root.
#   incus-init.sh <CL_HOME> <uid> <gid>
# 1) brings lo up (no distro init runs, so loopback starts down and the 127.0.0.1 proxy/nginx fail);
# 2) runs runsvdir -P $CL_HOME/sv as <uid>:<gid> (chpst), like the bwrap runtime;
# 3) on SIGPWR (incus stop) or TERM/INT: stops the services cleanly, PostgreSQL first (fast mode, as in
#    down.sh), then HUP to runsvdir and exits, so `incus stop` does not time out.
# CL_INIT_PATH overrides PATH (tests).
PATH=${CL_INIT_PATH:-/usr/bin:/usr/sbin}
export PATH
home=$1 uid=$2 gid=$3
[ -n "$home" ] && [ -n "$uid" ] && [ -n "$gid" ] || { echo "usage: $0 CL_HOME UID GID" >&2; exit 2; }

ip link set lo up || echo "incus-init: could not bring lo up" >&2

as_user() { chpst -u ":$uid:$gid" "$@"; }
pid=
shutdown() {
	trap '' PWR TERM INT
	[ ! -d "$home/sv/postgresql" ] || as_user sv -w 30 stop "$home/sv/postgresql" || true
	for s in "$home"/sv/*/; do
		[ -d "$s" ] && [ "${s%/}" != "$home/sv/postgresql" ] && as_user sv -w 10 stop "${s%/}" || true
	done
	[ -z "$pid" ] || { kill -HUP "$pid" 2>/dev/null; wait "$pid"; }
	exit 0
}
trap shutdown PWR TERM INT
as_user runsvdir -P "$home/sv" &
pid=$!
# wait returns on each trapped signal; loop until runsvdir exits.
while kill -0 "$pid" 2>/dev/null; do wait "$pid"; done

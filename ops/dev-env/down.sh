#!/usr/bin/env bash
# Derruba o ambiente dev deste CL_HOME. Mata só o PID registrado em
# $CL_HOME/run/env.pid (nunca por nome: a máquina pode ser compartilhada).
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults

read -r bw child init _ < <(read_pids; echo) || true
if ! pid_is_ours "${bw:-}"; then
	log "não está no ar (CL_HOME=$CL_HOME)"
	rm -f "$CL_PIDFILE"
	exit 0
fi

if runsvdir_ok "${child:-}"; then
	# PostgreSQL primeiro, com parada rápida esperando terminar.
	if [ -d "$CL_HOME/sv/postgresql" ]; then
		in_env "$child" sv -w 30 stop "$CL_HOME/sv/postgresql" || true
	fi
	for pgdata in "$CL_HOME"/data/*/PG_VERSION; do
		[ -f "$pgdata" ] || continue
		in_env "$child" pg_ctl -D "$(dirname "$pgdata")" stop -m fast -w 2>/dev/null || true
	done
	svs=("$CL_HOME"/sv/*/)
	if [ -d "${svs[0]}" ]; then
		in_env "$child" sv -w 30 stop "${svs[@]%/}" || true
	fi
	# HUP: runsvdir encerra os runsv e sai; o bwrap sai junto.
	kill -HUP "$child" 2>/dev/null || true
fi

# Sem --die-with-parent, matar só o bwrap externo deixaria o namespace vivo:
# o último recurso é o init do namespace (PID 1 lá dentro), registrado no up.
for sig in none TERM KILL; do
	case $sig in
	TERM) ! runsvdir_ok "$child" || kill -TERM "$child" 2>/dev/null || true ;;
	KILL) ! ns_init_ok "${init:-}" "$bw" || kill -KILL "$init" 2>/dev/null; kill -KILL "$bw" 2>/dev/null || true ;;
	esac
	for _ in $(seq 50); do
		pid_is_ours "$bw" || pid_is_ours "${init:-}" || break 2
		sleep 0.1
	done
done
if pid_is_ours "$bw" || pid_is_ours "${init:-}"; then die "bwrap $bw não saiu"; fi
rm -f "$CL_PIDFILE"
log "fora do ar (CL_HOME=$CL_HOME)"

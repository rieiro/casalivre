#!/usr/bin/env bash
# Derruba o ambiente dev deste CL_HOME. Mata só o PID registrado em
# $CL_HOME/run/env.pid (nunca por nome: a máquina pode ser compartilhada).
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults

# P0-5: env brought up by up-incus.sh: clean PG stop, then incus stop. A stale incus.name (instance not
# running or absent) is cleared and falls through to the bwrap path, which may still be up.
if [ -f "$CL_RUN/incus.name" ]; then
	name=$(cat "$CL_RUN/incus.name")
	[ "$name" = "$(incus_name)" ] || die "$CL_RUN/incus.name does not match this CL_HOME"
	if [ "$(incus_status)" = RUNNING ]; then
		incus exec "$name" --user "$(id -u)" --group "$(id -g)" --cwd "$CL_HOME" -- \
			sv -w 30 stop "$CL_HOME/sv/postgresql" || true
		# SIGPWR -> incus-init.sh stops the rest cleanly; --force only if that times out.
		incus stop --timeout 30 "$name" || incus stop --force "$name"
		[ "$(incus_status)" != RUNNING ] || die "incus $name still running; $CL_RUN/incus.name kept"
		rm -f "$CL_RUN/incus.name"
		log "down (incus $name)"
		exit 0
	fi
	log "stale $CL_RUN/incus.name ($name not running): cleared"
	rm -f "$CL_RUN/incus.name"
fi

read -r bw child init _ < <(read_pids; echo) || true
if ! env_alive "${bw:-}" "${init:-}"; then
	log "não está no ar (CL_HOME=$CL_HOME)"
	rm -f "$CL_PIDFILE"
	exit 0
fi

# Ambiente fora? (nem o bwrap nem o init do namespace vivos)
gone() { ! env_alive "${bw:-}" "${init:-}"; }
# Espera até 5 s o ambiente sair.
wait_gone() {
	local _
	for _ in $(seq 50); do gone && return 0; sleep 0.1; done
	return 1
}

# 1) Parada limpa: serviços via sv (PostgreSQL primeiro; o serviço dele, do
#    P0-2, para em modo fast), depois HUP no runsvdir, que encerra os runsv e
#    sai; o bwrap sai junto.
if runsvdir_ok "${child:-}"; then
	if [ -d "$CL_HOME/sv/postgresql" ]; then
		in_env "$child" sv -w 30 stop "$CL_HOME/sv/postgresql" || true
	fi
	svs=("$CL_HOME"/sv/*/)
	if [ -d "${svs[0]}" ]; then
		in_env "$child" sv -w 30 stop "${svs[@]%/}" || true
	fi
	kill -HUP "$child" 2>/dev/null || true
fi

# 2) runsvdir não saiu: TERM nele.
if ! wait_gone && runsvdir_ok "$child"; then
	kill -TERM "$child" 2>/dev/null || true
fi

# 3) Último recurso: KILL no init do namespace (PID 1 lá dentro, derruba tudo)
#    e no bwrap. Sem --die-with-parent, matar só o bwrap externo deixaria o
#    namespace vivo.
if ! wait_gone; then
	! pid_is_ours "${init:-}" || kill -KILL "$init" 2>/dev/null || true
	! pid_is_ours "${bw:-}" || kill -KILL "$bw" 2>/dev/null || true
	wait_gone || die "bwrap $bw não saiu"
fi
rm -f "$CL_PIDFILE"
log "fora do ar (CL_HOME=$CL_HOME)"

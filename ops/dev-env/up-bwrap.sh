#!/usr/bin/env bash
# Sobe o ambiente dev: bwrap (sem root) com runsvdir supervisionando $CL_HOME/sv.
#   up-bwrap.sh      em primeiro plano (Ctrl-C derruba)
#   up-bwrap.sh -d   em segundo plano (setsid + nohup), sobrevive ao shell
# Idempotente: se o PID registrado em $CL_HOME/run/env.pid está vivo, não sobe outro.
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults

detach=0
case "${1:-}" in -d) detach=1 ;; "") ;; *) die "uso: $0 [-d]" ;; esac

[ -f "$CL_HOME/.provisioned" ] || die "rode provision.sh antes (CL_HOME=$CL_HOME)"

read -r bw child init _ < <(read_pids; echo) || true
if env_alive "${bw:-}" "${init:-}"; then
	log "já está no ar (bwrap ${bw:-}, runsvdir ${child:-}, init ${init:-})"
	exit 0
fi
rm -f "$CL_PIDFILE"

prestart bwrap

# Runtime com rootfs somente leitura (serviço comprometido não altera os
# binários); /tmp em tmpfs 1777; graváveis só os diretórios do CL_HOME e o repo.
bwrap_base_args ro-bind
args=("${BW_BASE[@]}" --perms 1777 --tmpfs /tmp --bind "$CL_REPO" "$CL_REPO")
for d in run etc log sv data; do args+=(--bind "$CL_HOME/$d" "$CL_HOME/$d"); done
# Mount points: bwrap cannot create them on a read-only rootfs, so they are created here,
# on the host side, where the rootfs is writable (it is read-only only inside the runtime).
# They are empty directories, created idempotently on every up. They stay here, not in
# provision.sh, because they depend on CL_REPO/CL_HOME at up time (a provisioned rootfs can
# be reused by another CL_HOME or checkout). Paths under /tmp are skipped: /tmp is a tmpfs
# in the runtime, so its mount points live there.
for d in "$CL_REPO" "$CL_HOME"/run "$CL_HOME"/etc "$CL_HOME"/log "$CL_HOME"/sv "$CL_HOME"/data; do
	case $d in /tmp/*) ;; *) mkdir -p "$CL_ROOTFS$d" ;; esac
done
args+=(
	--chdir "$CL_REPO" --info-fd 3
	-- /usr/bin/runsvdir -P "$CL_HOME/sv"
)

info=$CL_RUN/bwrap-info.json
rm -f "$info"
if [ "$detach" -eq 1 ]; then
	setsid nohup bwrap "${args[@]}" 3>"$info" </dev/null >>"$CL_HOME/log/env.log" 2>&1 &
else
	bwrap "${args[@]}" 3>"$info" &
fi
bw=$!

# Com o script fora de líder de grupo, o setsid não faz fork: $! é o bwrap.
# child-pid do --info-fd é o init do bwrap no namespace; o runsvdir é filho dele.
child='' init=''
for _ in $(seq 50); do
	init=$(sed -n 's/.*"child-pid": *\([0-9]*\).*/\1/p' "$info" 2>/dev/null)
	if [ -n "$init" ]; then
		child=$(cat /proc/"$init"/task/*/children 2>/dev/null | awk '{print $1}') || child=''
		[ -n "$child" ] && grep -q runsvdir "/proc/$child/cmdline" 2>/dev/null && break
		child=
	fi
	kill -0 "$bw" 2>/dev/null || die "bwrap saiu ao subir (ver $CL_HOME/log/env.log)"
	sleep 0.1
done
if [ -z "$child" ]; then
	! pid_is_ours "${init:-}" || kill -KILL "$init" 2>/dev/null || true
	kill -KILL "$bw" 2>/dev/null || true
	die "bwrap não informou o PID do runsvdir"
fi
# Formato: <bwrap> <runsvdir> <init do namespace>. Matar o init derruba o namespace inteiro.
echo "$bw $child $init" >"$CL_PIDFILE"
log "no ar: bwrap $bw, runsvdir $child (http $CL_PORT_HTTP; PG/Valkey/FPM em socket em $CL_RUN)"

if [ "$detach" -eq 0 ]; then
	trap '"$CL_DEVENV_DIR/down.sh"' INT TERM
	wait "$bw" || true
	env_alive "$bw" "$init" || rm -f "$CL_PIDFILE"
fi

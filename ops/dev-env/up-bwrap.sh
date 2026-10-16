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

read -r bw child _ _ < <(read_pids; echo) || true
if pid_is_ours "${bw:-}"; then
	log "já está no ar (bwrap $bw, runsvdir $child)"
	exit 0
fi
rm -f "$CL_PIDFILE"

mkdir -p "$CL_RUN" "$CL_HOME/etc" "$CL_HOME/log" "$CL_HOME/sv" "$CL_HOME/data"

# O ambiente anterior morreu, então todo PID registrado lá dentro está morto:
# postmaster.pid que sobrou é velho e colidiria com PIDs reusados no namespace novo.
for pm in "$CL_HOME"/data/*/postmaster.pid; do
	[ -f "$pm" ] || continue
	log "removendo $pm (ambiente anterior morto)"
	rm -f "$pm"
done

# Templates: envsubst do próprio rootfs, só com as variáveis listadas (não
# toca em $uri, $args etc. do nginx).
# shellcheck disable=SC2016  # literal de propósito: lista para o envsubst
vars='${CL_HOME} ${CL_REPO} ${CL_PORT_HTTP} ${CL_PORT_PG} ${CL_PORT_VALKEY} ${CL_PORT_FPM}'
for t in "$CL_DEVENV_DIR"/templates/*.in; do
	out=$CL_HOME/etc/$(basename "${t%.in}")
	bwrap --ro-bind "$CL_ROOTFS" / --unshare-all -- \
		/usr/bin/envsubst "$vars" <"$t" >"$out.tmp"
	mv "$out.tmp" "$out"
done

args=(
	--bind "$CL_ROOTFS" / --dev /dev --proc /proc
	--ro-bind /etc/resolv.conf /etc/resolv.conf
	--bind "$CL_REPO" "$CL_REPO"
)
for d in run etc log sv data; do args+=(--bind "$CL_HOME/$d" "$CL_HOME/$d"); done
args+=(
	--unshare-user --unshare-pid --unshare-ipc --share-net
	--setenv HOME "$CL_HOME" --setenv PATH /usr/bin:/usr/sbin
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

# child-pid do --info-fd é o init do bwrap no namespace; o runsvdir é filho dele.
child=
for _ in $(seq 50); do
	init=$(sed -n 's/.*"child-pid": *\([0-9]*\).*/\1/p' "$info" 2>/dev/null)
	if [ -n "$init" ]; then
		child=$(cat /proc/"$init"/task/*/children 2>/dev/null | awk '{print $1}')
		[ -n "$child" ] && grep -q runsvdir "/proc/$child/cmdline" 2>/dev/null && break
		child=
	fi
	kill -0 "$bw" 2>/dev/null || die "bwrap saiu ao subir (ver $CL_HOME/log/env.log)"
	sleep 0.1
done
[ -n "$child" ] || { kill "$bw"; die "bwrap não informou o PID do runsvdir"; }
# Formato: <bwrap> <runsvdir> <init do namespace>. Matar o init derruba o namespace inteiro.
echo "$bw $child $init" >"$CL_PIDFILE"
log "no ar: bwrap $bw, runsvdir $child (http $CL_PORT_HTTP, pg $CL_PORT_PG, valkey $CL_PORT_VALKEY, fpm $CL_PORT_FPM)"

if [ "$detach" -eq 0 ]; then
	trap '"$CL_DEVENV_DIR/down.sh"' INT TERM
	wait "$bw" || true
	rm -f "$CL_PIDFILE"
fi

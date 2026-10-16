# shellcheck shell=bash
# Funções e defaults comuns do ambiente dev (P0-1). Usar via: . "$(dirname "$0")/lib.sh"
set -euo pipefail
# Tudo que os scripts criam fica só para o dono (máquina compartilhada).
umask 077

CL_DEVENV_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
CL_REPO=${CL_REPO:-$(cd "$CL_DEVENV_DIR/../.." && pwd)}

die() { echo "dev-env: ERRO: $*" >&2; exit 1; }
log() { echo "dev-env: $*" >&2; }

refuse_root() {
	[ "$(id -u)" -ne 0 ] || die "não rode como root (nem com sudo): o ambiente roda sem privilégio"
}

# Defaults (fonte única; README e env.example remetem aqui). A única porta TCP
# é a HTTP, derivada do UID: 20000+(UID%4000)*10. PG, Valkey e FPM só escutam
# em socket Unix em $CL_HOME/run.
cl_defaults() {
	local uid base
	uid=$(id -u)
	base=$((20000 + (uid % 4000) * 10))
	: "${CL_HOME:=$HOME/.casalivre-dev}"
	: "${CL_PORT_HTTP:=$base}"
	: "${CL_ROOTFS:=$CL_HOME/rootfs}"
	: "${CL_CACHE:=$CL_HOME/cache}"
	case $CL_HOME in /*) ;; *) die "CL_HOME precisa ser caminho absoluto: $CL_HOME" ;; esac
	CL_HOME=${CL_HOME%/}
	CL_RUN=$CL_HOME/run
	CL_PIDFILE=$CL_RUN/env.pid
	export CL_HOME CL_REPO CL_PORT_HTTP CL_ROOTFS CL_CACHE CL_RUN
}

# Lê uma chave de versions.lock (formato chave=valor).
lock_get() {
	local v
	v=$(sed -n "s/^$1=//p" "$CL_DEVENV_DIR/versions.lock")
	[ -n "$v" ] || die "versions.lock sem a chave $1"
	printf '%s\n' "$v"
}

# Pacotes travados: linhas pkg.<nome>=<pkgver>
lock_pkgs() { sed -n 's/^pkg\.[^=]*=//p' "$CL_DEVENV_DIR/versions.lock"; }

# Lê "<bwrap> <runsvdir> <init do namespace>" do PID file; vazio se ausente.
read_pids() { [ -f "$CL_PIDFILE" ] && cat "$CL_PIDFILE" || true; }

# PID vivo e é um bwrap de runtime deste CL_HOME (o externo ou o init do
# namespace, que tem o mesmo argv). Protege contra reuso de PID.
pid_is_ours() {
	local pid=$1
	[ -n "$pid" ] && [ -r "/proc/$pid/cmdline" ] || return 1
	local cmd
	cmd=$({ tr '\0' ' ' <"/proc/$pid/cmdline"; } 2>/dev/null) || return 1
	# Comparação literal e ancorada (sem regex): começa com bwrap e monta
	# exatamente este rootfs como / (o caminho termina em " / ").
	case $cmd in "bwrap "*) ;; *) return 1 ;; esac
	grep -qF -- " --ro-bind $CL_ROOTFS / " <<<"$cmd"
}

# Ambiente vivo: o bwrap externo OU o init do namespace. Se o externo morrer
# (OOM, kill), o init e o runsvdir seguem vivos e o ambiente continua no ar.
env_alive() { pid_is_ours "${1:-}" || pid_is_ours "${2:-}"; }

# Algum postgres vivo com cwd neste PGDATA (o postmaster faz chdir para lá)?
pgdata_in_use() {
	local dir=$1 p
	for p in /proc/[0-9]*; do
		[ "$(readlink "$p/cwd" 2>/dev/null)" = "$dir" ] || continue
		[ "$(cat "$p/comm" 2>/dev/null)" = postgres ] && return 0
	done
	return 1
}

# runsvdir deste CL_HOME (protege contra reuso de PID).
runsvdir_ok() {
	local pid=$1
	[ -n "$pid" ] && [ -r "/proc/$pid/cmdline" ] || return 1
	[ "$({ tr '\0' ' ' <"/proc/$pid/cmdline"; } 2>/dev/null)" = "/usr/bin/runsvdir -P $CL_HOME/sv " ]
}

# Cria os diretórios do CL_HOME em 0700 e confere com stat (falha se outro
# uid puder ler: logs, sockets e dados ficam aqui).
ensure_dirs() {
	local d
	for d in "$CL_HOME" "$CL_RUN" "$CL_HOME/etc" "$CL_HOME/admin" "$CL_HOME/log" "$CL_HOME/sv" "$CL_HOME/data" "$CL_CACHE"; do
		mkdir -p "$d"
		chmod 700 "$d"
		[ "$(stat -c %a "$d")" = 700 ] || die "$d precisa ser 0700"
	done
}

# Argumentos comuns a todo bwrap do ambiente: todos os namespaces separados
# menos a rede (precisa do 127.0.0.1 do host), sessão nova (sem acesso ao
# terminal do host), ambiente limpo só com o necessário, rootfs como /, /dev,
# /proc e o resolv.conf do host somente leitura.
# $1 = bind | ro-bind (modo do rootfs). Resultado em BW_BASE.
bwrap_base_args() {
	local v
	BW_BASE=(--unshare-all --share-net --new-session --clearenv
		--setenv HOME "$CL_HOME" --setenv PATH /usr/bin:/usr/sbin
		--setenv LANG "${LANG:-C.UTF-8}")
	[ -z "${TZ:-}" ] || BW_BASE+=(--setenv TZ "$TZ")
	for v in CL_HOME CL_REPO CL_PORT_HTTP; do
		BW_BASE+=(--setenv "$v" "${!v}")
	done
	BW_BASE+=(--"$1" "$CL_ROOTFS" / --dev /dev --proc /proc
		--ro-bind /etc/resolv.conf /etc/resolv.conf)
}

# Executa um comando dentro do ambiente vivo (mesmos namespaces do runsvdir).
in_env() {
	local child=$1; shift
	nsenter --preserve-credentials -U -m -p -i -r -w -t "$child" -- "$@"
}

# Renders templates/*.in into $CL_HOME/etc with the rootfs's own envsubst (in a throwaway bwrap
# without network), only for the listed variables ($uri, $args etc. of nginx stay untouched).
# $1 = bwrap (up-bwrap.sh) | host (up-incus.sh: the Incus host may not have bwrap; uses the host envsubst).
render_templates() {
	local t out mode=$1
	# shellcheck disable=SC2016  # literal on purpose: list for envsubst
	local vars='${CL_HOME} ${CL_REPO} ${CL_PORT_HTTP}'
	for t in "$CL_DEVENV_DIR"/templates/*.in; do
		out=$CL_HOME/etc/$(basename "${t%.in}")
		if [ "$mode" = bwrap ]; then
			bwrap_base_args ro-bind
			bwrap "${BW_BASE[@]}" --unshare-net -- /usr/bin/envsubst "$vars" <"$t" >"$out.tmp"
		else
			command -v envsubst >/dev/null 2>&1 || die "envsubst (gettext) is needed on the host"
			envsubst "$vars" <"$t" >"$out.tmp"
		fi
		mv "$out.tmp" "$out"
	done
}

# Incus container name for this uid + CL_HOME (one env per CL_HOME, like env.pid).
incus_name() {
	printf 'cl-dev-%s-%s\n' "$(id -u)" "$(printf '%s' "$CL_HOME" | sha256sum | cut -c1-8)"
}

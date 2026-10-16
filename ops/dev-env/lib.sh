# shellcheck shell=bash
# Funções e defaults comuns do ambiente dev (P0-1). Usar via: . "$(dirname "$0")/lib.sh"
set -euo pipefail

CL_DEVENV_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
CL_REPO=${CL_REPO:-$(cd "$CL_DEVENV_DIR/../.." && pwd)}

die() { echo "dev-env: ERRO: $*" >&2; exit 1; }
log() { echo "dev-env: $*" >&2; }

refuse_root() {
	[ "$(id -u)" -ne 0 ] || die "não rode como root (nem com sudo): o ambiente roda sem privilégio"
}

# Defaults derivados do UID: cada usuário (bot) recebe um bloco de 10 portas.
cl_defaults() {
	local uid base
	uid=$(id -u)
	base=$((20000 + (uid % 4000) * 10))
	: "${CL_HOME:=$HOME/.casalivre-dev}"
	: "${CL_PORT_HTTP:=$base}"
	: "${CL_PORT_PG:=$((base + 1))}"
	: "${CL_PORT_VALKEY:=$((base + 2))}"
	: "${CL_PORT_FPM:=$((base + 3))}"
	: "${CL_ROOTFS:=$CL_HOME/rootfs}"
	: "${CL_CACHE:=$CL_HOME/cache}"
	case $CL_HOME in /*) ;; *) die "CL_HOME precisa ser caminho absoluto: $CL_HOME" ;; esac
	CL_HOME=${CL_HOME%/}
	CL_RUN=$CL_HOME/run
	CL_PIDFILE=$CL_RUN/env.pid
	export CL_HOME CL_REPO CL_PORT_HTTP CL_PORT_PG CL_PORT_VALKEY CL_PORT_FPM CL_ROOTFS CL_CACHE CL_RUN
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

# Lê "bwrap_pid runsvdir_pid" do PID file; vazio se ausente.
read_pids() { [ -f "$CL_PIDFILE" ] && cat "$CL_PIDFILE" || true; }

# PID vivo e é o bwrap deste CL_HOME (protege contra reuso de PID).
pid_is_ours() {
	local pid=$1
	[ -n "$pid" ] && [ -r "/proc/$pid/cmdline" ] || return 1
	tr '\0' ' ' <"/proc/$pid/cmdline" | grep -q -- "bwrap .*$CL_ROOTFS"
}

# init do namespace: filho direto do bwrap registrado, também bwrap deste CL_HOME.
ns_init_ok() {
	local pid=$1 bwpid=$2
	pid_is_ours "$pid" && [ "$(awk '{print $4}' "/proc/$pid/stat" 2>/dev/null)" = "$bwpid" ]
}

# runsvdir deste CL_HOME (protege contra reuso de PID).
runsvdir_ok() {
	local pid=$1
	[ -n "$pid" ] && [ -r "/proc/$pid/cmdline" ] || return 1
	[ "$(tr '\0' ' ' <"/proc/$pid/cmdline")" = "/usr/bin/runsvdir -P $CL_HOME/sv " ]
}

# Executa um comando dentro do ambiente vivo (mesmos namespaces do runsvdir).
in_env() {
	local child=$1; shift
	nsenter --preserve-credentials -U -m -p -i -r -w -t "$child" -- "$@"
}

#!/usr/bin/env bash
# Runs PHPUnit inside the rootless ops/dev-env runtime (PHP 8.4 + PostgreSQL 18 + casalivre_retention + Node 24).
# Usage: ops/dev-env/test-unit.sh [phpunit args...]   (no args: --testsuite Unit)
set -euo pipefail
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults

read -r bw child init _ < <(read_pids; echo) || true
if ! env_alive "${bw:-}" "${init:-}" || ! runsvdir_ok "${child:-}"; then
	[ -f "$CL_HOME/.provisioned" ] || die "dev-env not provisioned: run ops/dev-env/provision.sh first"
	"$CL_DEVENV_DIR/up-bwrap.sh" -d
	read -r bw child init _ < <(read_pids; echo) || true
	env_alive "${bw:-}" "${init:-}" && runsvdir_ok "${child:-}" || die "failed to start dev-env"
fi

for _ in $(seq 50); do
	in_env "$child" pg_isready -q -h "$CL_RUN" -U casalivre_dev -d casalivre 2>/dev/null && break
	sleep 0.1
done
in_env "$child" pg_isready -q -h "$CL_RUN" -U casalivre_dev -d casalivre 2>/dev/null \
	|| die "PostgreSQL unreachable in $CL_RUN (run ops/dev-env/db-setup.sh)"

if [ $# -eq 0 ]; then
	set -- --testsuite Unit
fi

in_env "$child" /bin/sh -c \
	'. "$1" && cd "$2" && shift 2 && export APP_ENV=testing && exec php8.4 vendor/bin/phpunit --do-not-cache-result "$@"' \
	sh "$CL_HOME/etc/dev-env.env" "$CL_REPO" "$@"

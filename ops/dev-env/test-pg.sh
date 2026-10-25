#!/usr/bin/env bash
# P0-3 (criterion 11): runs the full standalone suite on the env's PG (PostgreSQL only, #137).
# Each test creates its own casalivre_t_<pid>_<rand> database and drops only that;
# run.php sweeps leftovers of finished processes.
# PG: the env's (#112), socket only in $CL_RUN, as casalivre_dev (CREATEDB, peer); or TEST_PG_DSN=<dsn>.
# PHP: by default the container's php8.4, in a short-lived bwrap with the runtime's base binds (read-only rootfs,
# repo and $CL_RUN bound read-write). CL_PHP=<host php binary> runs on the host instead.
# The env must be up (up-bwrap.sh creates the repo mount point in the rootfs).
# Usage: ops/dev-env/test-pg.sh [file_test.php ...]   (no arguments: the whole suite)
set -euo pipefail
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults
: "${TEST_PG_DSN:=pgsql:host=$CL_RUN;dbname=casalivre}"
: "${TEST_PG_USER:=casalivre_dev}"
php=${CL_PHP:-php8.4}
export TEST_PG_DSN TEST_PG_USER
[ -z "${TEST_PG_PASSWORD:-}" ] || export TEST_PG_PASSWORD

if [ "$php" = php8.4 ]; then
	[ -d "$CL_ROOTFS$CL_REPO" ] || case $CL_REPO in /tmp/*) ;; *) die "no repo mount point in the rootfs: run ops/dev-env/up-bwrap.sh first" ;; esac
	bwrap_base_args ro-bind
	run() {
		local args=("${BW_BASE[@]}" --perms 1777 --tmpfs /tmp --bind "$CL_REPO" "$CL_REPO" --bind "$CL_RUN" "$CL_RUN"
			--chdir "$CL_REPO" --setenv CL_PHP php8.4) v
		# P0-4: Valkey as cl_app for valkey_queue_test (socket in $CL_RUN; $CL_HOME/etc read-only, as in the
		# runtime; the cl_admin secret is in $CL_HOME/admin, never bound).
		if [ -S "$CL_RUN/valkey.sock" ] && [ -r "$CL_HOME/etc/valkey-app.secret" ]; then
			args+=(--ro-bind "$CL_HOME/etc" "$CL_HOME/etc"
				--setenv VALKEY_SOCKET "$CL_RUN/valkey.sock" --setenv VALKEY_USER cl_app
				--setenv VALKEY_PASSWORD_FILE "$CL_HOME/etc/valkey-app.secret")
		fi
		for v in TEST_PG_DSN TEST_PG_USER TEST_PG_PASSWORD; do
			[ -z "${!v:-}" ] || args+=(--setenv "$v" "${!v}")
		done
		bwrap "${args[@]}" -- "$@"
	}
	bin=/usr/bin/php8.4
else
	run() { (cd "$CL_REPO" && env -u APP_URL -u DB_DSN CL_PHP="$php" "$@"); }
	bin=$php
fi

run /bin/bash "$CL_REPO/ops/dev-env/check-test-deps.sh"
run "$bin" -r '$u = getenv("TEST_PG_USER") ?: null; new PDO(getenv("TEST_PG_DSN"), $u, getenv("TEST_PG_PASSWORD") ?: null);' \
	|| die "PG unreachable at $TEST_PG_DSN (is the env up? ops/dev-env/up-bwrap.sh)"
if [ $# -eq 0 ]; then
	run "$bin" tests/standalone/run.php
	exit
fi
rc=0
# bz2 is not in php.ini (same as production). The neighborhood seed needs it, like load-ceps.
for f in "$@"; do run "$bin" -d extension=bz2 "tests/standalone/$(basename "$f")" || rc=1; done
exit $rc

#!/usr/bin/env bash
# P0-3 (critério 11): roda a suíte standalone completa no PG do ambiente, com schema_parity_test incluído.
# Cada teste cria o próprio banco casalivre_t_<pid>_<rand> (e o parity, casalivre_parity_<pid>) e apaga só ele.
# PG: o do ambiente (#112), só socket em $CL_RUN, como casalivre_dev (CREATEDB, peer); ou TEST_PG_DSN=<dsn> para outro.
# Uso: ops/dev-env/test-pg.sh [arquivo_test.php ...]   (sem argumentos: a suíte inteira)
set -euo pipefail
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults
: "${TEST_PG_DSN:=pgsql:host=$CL_RUN;dbname=casalivre}"
: "${TEST_PG_USER:=casalivre_dev}"
php=${CL_PHP:-php}
export TEST_PG_DSN TEST_PG_USER PARITY_PG_DSN=$TEST_PG_DSN PARITY_PG_USER=$TEST_PG_USER
CL_PHP=$php "$CL_DEVENV_DIR/check-test-deps.sh"
"$php" -r '$u = getenv("TEST_PG_USER") ?: null; new PDO(getenv("TEST_PG_DSN"), $u, getenv("TEST_PG_PASSWORD") ?: null);' \
	|| die "PG inacessível em $TEST_PG_DSN (o ambiente está no ar? ops/dev-env/up-bwrap.sh)"
[ -z "${TEST_PG_PASSWORD:-}" ] || export PARITY_PG_PASSWORD=$TEST_PG_PASSWORD
cd "$CL_REPO"
if [ $# -eq 0 ]; then
	exec env -u APP_URL -u DB_DSN -u DB_DRIVER "$php" tests/standalone/run.php
fi
rc=0
for f in "$@"; do env -u APP_URL -u DB_DSN -u DB_DRIVER "$php" "tests/standalone/$(basename "$f")" || rc=1; done
exit $rc

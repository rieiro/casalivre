#!/usr/bin/env bash
# P0-2: com o ambiente no ar, confere a ACL do Valkey (Segurança):
# cl_app faz LPUSH/RPOP em cl:*, e 'keys *', 'config get *' e 'get x' falham;
# default sem acesso. Sai 1 se algo divergir.
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults

read -r bw _ init _ < <(read_pids; echo) || true
env_alive "${bw:-}" "${init:-}" || die "env is down: run up-bwrap.sh -d"
child=$init  # namespace init: same namespaces as runsvdir, alive even if the outer bwrap died

# S1: socket 0660. #112 L2: the cl_admin secret is not visible inside the env.
sock_mode=$(in_env "$child" stat -c %a "$CL_RUN/valkey.sock")
[ "$sock_mode" = 660 ] && echo "  ok   valkey.sock mode $sock_mode" || { echo "  FALHA valkey.sock mode $sock_mode (expected 660)"; exit 1; }
if in_env "$child" /bin/sh -c "cat '$CL_HOME/admin/valkey-admin.secret' || cat '$CL_HOME/etc/valkey-admin.secret'" >/dev/null 2>&1; then
	echo "  FALHA cl_admin secret readable inside the env"; exit 1
fi
echo "  ok   cl_admin secret not readable inside the env"

in_env "$child" /bin/sh -c ". '$CL_HOME/etc/dev-env.env' && exec php8.4 -r '
\$fail = 0;
\$c = function (?string \$user, ?string \$pass) {
	\$r = new Redis();
	\$r->connect(getenv(\"VALKEY_SOCKET\"));
	if (\$user !== null) { \$r->auth([\$user, \$pass]); }
	return \$r;
};
\$try = function (callable \$f): string {
	try { \$v = \$f(); return \$v === false ? \"falhou\" : \"ok\"; } catch (Throwable \$e) { return \"falhou\"; }
};
\$pass = trim(file_get_contents(getenv(\"VALKEY_PASSWORD_FILE\")));
\$app = \$c(getenv(\"VALKEY_USER\"), \$pass);
\$want = [
	\"lpush cl:smoke\" => [\"ok\", fn () => \$app->lPush(\"cl:smoke\", \"x\")],
	\"rpop cl:smoke\" => [\"ok\", fn () => \$app->rPop(\"cl:smoke\")],
	\"keys *\" => [\"falhou\", fn () => \$app->rawCommand(\"KEYS\", \"*\")],
	\"config get *\" => [\"falhou\", fn () => \$app->rawCommand(\"CONFIG\", \"GET\", \"*\")],
	\"get x\" => [\"falhou\", fn () => \$app->rawCommand(\"GET\", \"x\")],
	\"xadd cl:s\" => [\"falhou\", fn () => \$app->rawCommand(\"XADD\", \"cl:s\", \"*\", \"a\", \"1\")],
	\"eval\" => [\"falhou\", fn () => \$app->rawCommand(\"EVAL\", \"return 1\", \"0\")],
	\"default ping\" => [\"falhou\", fn () => \$c(null, null)->ping()],
];
foreach (\$want as \$name => [\$exp, \$f]) {
	\$got = \$try(\$f);
	echo (\$got === \$exp ? \"  ok   \" : \"  FALHA \"), \"cl_app \$name: \$got (esperado \$exp)\", PHP_EOL;
	\$fail |= (int) (\$got !== \$exp);
}
exit(\$fail);
'"

#!/usr/bin/env bash
# P0-2: com o ambiente no ar, confere a ACL do Valkey (Segurança):
# cl_app faz LPUSH/RPOP em cl:*, e 'keys *', 'config get *' e 'get x' falham;
# HLEN/HEXISTS/RENAME (ADR 001 §11) work inside cl:* and are refused (NOPERM)
# on keys outside cl:*, including RENAME into or out of cl:*;
# IpRateLimiter (#158): MULTI INCR + EXPIRE NX + TTL, GET, DECR, DEL work on cl:rl:* and are refused outside cl:*;
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

in_env "$child" /bin/sh -c ". '$CL_HOME/etc/dev-env.env' && export VALKEY_MAIL_PASSWORD_FILE='$CL_HOME/etc/valkey-mail.secret' VALKEY_IMAGE_PASSWORD_FILE='$CL_HOME/etc/valkey-image.secret' && exec php8.4 -r '
\$fail = 0;
\$c = function (?string \$user, ?string \$pass) {
	\$r = new Redis();
	\$r->connect(getenv(\"VALKEY_SOCKET\"));
	if (\$user !== null) { \$r->auth([\$user, \$pass]); }
	return \$r;
};
\$try = function (callable \$f) use (&\$app): string {
	// NOPERM when the ACL refused the command (checked for the ADR 001 §11 cases); falhou for any other error.
	\$app?->clearLastError();
	try { \$v = \$f(); \$err = \$v === false ? (string) \$app?->getLastError() : null; } catch (Throwable \$e) { \$err = \$e->getMessage(); }
	return \$err === null ? \"ok\" : (str_contains(\$err, \"NOPERM\") ? \"NOPERM\" : \"falhou\");
};
\$pass = trim(file_get_contents(getenv(\"VALKEY_PASSWORD_FILE\")));
\$app = \$c(getenv(\"VALKEY_USER\"), \$pass);
\$want = [
	\"lpush cl:smoke\" => [\"ok\", fn () => \$app->lPush(\"cl:smoke\", \"x\")],
	\"rpop cl:smoke\" => [\"ok\", fn () => \$app->rPop(\"cl:smoke\")],
	\"hlen cl:x\" => [\"ok\", fn () => \$app->rawCommand(\"HLEN\", \"cl:x\")],
	\"hlen x\" => [\"NOPERM\", fn () => \$app->rawCommand(\"HLEN\", \"x\")],
	\"hexists cl:x f\" => [\"ok\", fn () => \$app->rawCommand(\"HEXISTS\", \"cl:x\", \"f\")],
	\"hexists x f\" => [\"NOPERM\", fn () => \$app->rawCommand(\"HEXISTS\", \"x\", \"f\")],
	\"rename cl:a cl:b\" => [\"ok\", fn () => \$app->set(\"cl:a\", \"1\") && \$app->rawCommand(\"RENAME\", \"cl:a\", \"cl:b\") && \$app->del(\"cl:b\") !== false],
	\"rename cl:a x\" => [\"NOPERM\", fn () => \$app->set(\"cl:a\", \"1\") && \$app->rawCommand(\"RENAME\", \"cl:a\", \"x\")],
	\"rename x cl:a\" => [\"NOPERM\", fn () => \$app->rawCommand(\"RENAME\", \"x\", \"cl:a\")],
	\"multi incr+expire nx+ttl cl:rl:x\" => [\"ok\", fn () => is_array(\$o = \$app->multi()->incr(\"cl:rl:x\")->expire(\"cl:rl:x\", 60, \"NX\")->ttl(\"cl:rl:x\")->exec()) && !in_array(false, \$o, true) && \$o[2] > 0],
	\"get+decr+del cl:rl:x\" => [\"ok\", fn () => \$app->get(\"cl:rl:x\") !== false && \$app->decr(\"cl:rl:x\") !== false && \$app->del(\"cl:rl:x\") !== false],
	\"incr rl:x\" => [\"NOPERM\", fn () => \$app->rawCommand(\"INCR\", \"rl:x\")],
	\"expire rl:x nx\" => [\"NOPERM\", fn () => \$app->rawCommand(\"EXPIRE\", \"rl:x\", \"60\", \"NX\")],
	\"keys *\" => [\"falhou\", fn () => \$app->rawCommand(\"KEYS\", \"*\")],
	\"config get *\" => [\"falhou\", fn () => \$app->rawCommand(\"CONFIG\", \"GET\", \"*\")],
	\"get x\" => [\"falhou\", fn () => \$app->rawCommand(\"GET\", \"x\")],
	\"xadd cl:s\" => [\"falhou\", fn () => \$app->rawCommand(\"XADD\", \"cl:s\", \"*\", \"a\", \"1\")],
	\"eval\" => [\"falhou\", fn () => \$app->rawCommand(\"EVAL\", \"return 1\", \"0\")],
	\"default ping\" => [\"falhou\", fn () => \$c(null, null)->ping()],
];
foreach (\$want as \$name => [\$exp, \$f]) {
	\$got = \$try(\$f);
	\$match = \$got === \$exp || (\$exp === \"falhou\" && \$got === \"NOPERM\");
	echo (\$match ? \"  ok   \" : \"  FALHA \"), \"cl_app \$name: \$got (esperado \$exp)\", PHP_EOL;
	\$fail |= (int) !\$match;
}
\$side = function (string \$user, string \$file) use (\$c) {
	return \$c(\$user, trim((string) file_get_contents(\$file)));
};
\$mail = \$side('cl_mail', (string) getenv('VALKEY_MAIL_PASSWORD_FILE'));
\$image = \$side('cl_image', (string) getenv('VALKEY_IMAGE_PASSWORD_FILE'));
\$own = [
	['cl_mail incr cl:q:mail:seq', 'ok', fn () => \$mail->incr('cl:q:mail:seq') !== false && \$mail->del('cl:q:mail:seq') !== false],
	['cl_mail incr cl:q:image:seq', 'NOPERM', fn () => \$mail->rawCommand('INCR', 'cl:q:image:seq')],
	['cl_mail hlen cl:q:mail:x', 'NOPERM', fn () => \$mail->rawCommand('HLEN', 'cl:q:mail:x')],
	['cl_image incr cl:q:image:seq', 'ok', fn () => \$image->incr('cl:q:image:seq') !== false && \$image->del('cl:q:image:seq') !== false],
	['cl_image incr cl:q:mail:seq', 'NOPERM', fn () => \$image->rawCommand('INCR', 'cl:q:mail:seq')],
	['cl_image get cl:rl:x', 'NOPERM', fn () => \$image->rawCommand('GET', 'cl:rl:x')],
];
foreach (\$own as [\$name, \$exp, \$f]) {
	\$got = \$try(\$f);
	\$match = \$got === \$exp;
	echo (\$match ? \"  ok   \" : \"  FALHA \"), \$name, \": \$got (esperado \$exp)\", PHP_EOL;
	\$fail |= (int) !\$match;
}
exit(\$fail);
'"

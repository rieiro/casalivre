#!/usr/bin/env bash
# P0-2: instala os serviços runit (postgresql, php-fpm, nginx, valkey) em
# $CL_HOME/sv e gera o env dos serviços. Chamado pelo up-bwrap.sh antes de subir
# o runsvdir; idempotente (segredos gerados uma vez e reaproveitados).
# Os serviços não herdam env do host: cada run lê $CL_HOME/etc/dev-env.env (0600).
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults
umask 077

etc=$CL_HOME/etc
mkdir -p "$etc" "$CL_HOME/sv" "$CL_HOME/log" "$CL_HOME/data" "$CL_RUN"
# PG: o app, os testes e o db-setup conectam como casalivre_dev (peer + map).
# O FPM roda com o mesmo uid do bot (ambiente de usuário único).
pg_user=$(id -un)
pg_group=$(id -gn)
dsn="pgsql:host=$CL_RUN;dbname=casalivre;user=casalivre_dev"

# Valkey: ACL de /workspace/programador-notas/p0-4-valkey-acl.md (Segurança).
# Senhas geradas uma vez, em arquivo 0440 (na produção root:casalivre; aqui,
# sem root, o próprio uid), nunca em env. A ACL guarda só o sha256.
gen_secret() {
	[ -s "$1" ] || { od -An -tx1 -N32 /dev/urandom | tr -d ' \n' >"$1.tmp" && mv "$1.tmp" "$1"; }
	chmod 0440 "$1"
	sha256sum <"$1" | cut -d' ' -f1
}
secret=$etc/valkey-app.secret
app_hash=$(gen_secret "$secret")
admin_hash=$(gen_secret "$etc/valkey-admin.secret")
app_cmds='+get +set +del +unlink +exists +expire +pexpire +ttl +pttl +incr +incrby +decr +mget +mset
+hget +hset +hdel +hmget +hgetall +hincrby
+lpush +rpush +lpop +rpop +blpop +brpop +lmove +blmove +llen +lrange +lrem
+zadd +zrem +zrange +zrangebyscore +zremrangebyscore +zcard
+sadd +srem +smembers +sismember
+multi +exec +discard +watch +unwatch
+ping +hello +auth +select|0'
# Sem Lua (nem +eval/+script/+evalsha), sem streams. aclfile 0600, fora do valkey.conf.
{
	echo 'user default off resetkeys resetchannels -@all'
	echo "user cl_app on #$app_hash resetkeys ~cl:* resetchannels &cl:* -@all $(echo $app_cmds)"
	echo "user cl_admin on #$admin_hash ~* &* +@all"
} >"$etc/valkey.acl.tmp"
chmod 0600 "$etc/valkey.acl.tmp"
mv "$etc/valkey.acl.tmp" "$etc/valkey.acl"
rm -f "$etc/valkey.secret"  # nome antigo (ACL provisória)

cat >"$etc/dev-env.env.tmp" <<ENV
# Gerado por services.sh — não editar. Lido pelos run do runit.
export CL_HOME='$CL_HOME' CL_REPO='$CL_REPO' CL_RUN='$CL_RUN' CL_PG_USER='$pg_user' CL_PG_GROUP='$pg_group'
export HOME='$CL_HOME' PATH=/usr/bin:/usr/sbin LANG=C.UTF-8
export PGHOST='$CL_RUN' PGUSER=casalivre_dev PGDATABASE=casalivre
export APP_ENV=dev DB_DRIVER=pgsql DB_DSN='$dsn'
export VALKEY_SOCKET='$CL_RUN/valkey.sock' VALKEY_USER=cl_app VALKEY_PASSWORD_FILE='$secret'
ENV
mv "$etc/dev-env.env.tmp" "$etc/dev-env.env"

# Pool do FPM com clear_env = yes: só estas variáveis chegam ao app.
cat >"$etc/php-fpm-env.conf.tmp" <<ENV
; Gerado por services.sh — não editar.
[www]
env[PATH] = /usr/bin:/usr/sbin
env[APP_ENV] = dev
env[DB_DRIVER] = pgsql
env[DB_DSN] = "$dsn"
env[VALKEY_SOCKET] = $CL_RUN/valkey.sock
env[VALKEY_USER] = cl_app
env[VALKEY_PASSWORD_FILE] = $secret
ENV
mv "$etc/php-fpm-env.conf.tmp" "$etc/php-fpm-env.conf"

# Serviços: corpo versionado em ops/dev-env/sv, com cabeçalho que carrega o env.
for src in "$CL_DEVENV_DIR"/sv/*/; do
	name=$(basename "$src")
	dst=$CL_HOME/sv/$name
	mkdir -p "$dst"
	while IFS= read -r f; do
		rel=${f#"$src"}
		mkdir -p "$(dirname "$dst/$rel")"
		{ printf '#!/bin/sh\n. %s/dev-env.env\n' "$etc"; cat "$f"; } >"$dst/$rel.tmp"
		chmod 700 "$dst/$rel.tmp"
		mv "$dst/$rel.tmp" "$dst/$rel"
	done < <(find "$src" -type f)
done
log "serviços instalados em $CL_HOME/sv: $(cd "$CL_DEVENV_DIR/sv" && echo */ | tr -d /)"

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
pg_user=$(id -un)
dsn="pgsql:host=$CL_RUN;dbname=casalivre"

# Senha do Valkey: gerada uma vez; a ACL guarda só o sha256.
secret=$etc/valkey.secret
[ -s "$secret" ] || od -An -tx1 -N32 /dev/urandom | tr -d ' \n' >"$secret"
# 0440: na produção root:casalivre; aqui (sem root) o dono é o nosso uid.
chmod 0440 "$secret"
vpass=$(cat "$secret")
vhash=$(printf '%s' "$vpass" | sha256sum | cut -d' ' -f1)
printf 'user default off\nuser casalivre on #%s ~* &* +@all -@dangerous\n' "$vhash" >"$etc/valkey.acl.tmp"
mv "$etc/valkey.acl.tmp" "$etc/valkey.acl"

cat >"$etc/dev-env.env.tmp" <<ENV
# Gerado por services.sh — não editar. Lido pelos run do runit.
export CL_HOME='$CL_HOME' CL_REPO='$CL_REPO' CL_RUN='$CL_RUN' CL_PG_USER='$pg_user'
export HOME='$CL_HOME' PATH=/usr/bin:/usr/sbin LANG=C.UTF-8
export PGHOST='$CL_RUN' PGDATABASE=casalivre
export APP_ENV=dev DB_DRIVER=pgsql DB_DSN='$dsn'
export VALKEY_SOCKET='$CL_RUN/valkey.sock' VALKEY_USER=casalivre VALKEY_PASSWORD_FILE='$secret'
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
env[VALKEY_USER] = casalivre
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

#!/usr/bin/env bash
# P0-2: com o ambiente no ar, cria o banco, carrega o schema PG, roda o migrate,
# os CEPs e o seed. Idempotente: schema só em banco vazio; seed só se não houver usuários.
#   db-setup.sh            schema + migrate + CEP + seed
#   db-setup.sh --no-seed  sem o seed
. "$(dirname "$0")/lib.sh"
refuse_root
cl_defaults

seed=1
case "${1:-}" in --no-seed) seed=0 ;; "") ;; *) die "uso: $0 [--no-seed]" ;; esac

# Runtime: CL_RUNTIME=bwrap|incus, or detected (the Incus container of this CL_HOME is RUNNING).
runtime=${CL_RUNTIME:-}
[ -n "$runtime" ] || { [ "$(incus_status)" = RUNNING ] && runtime=incus || runtime=bwrap; }
case $runtime in
incus)
	[ "$(incus_status)" = RUNNING ] || die "incus env is down: run up.sh --incus"
	name=$(incus_name)
	# Everything inside the env as this uid, with the services' env.
	envsh() {
		incus exec "$name" --user "$(id -u)" --group "$(id -g)" --cwd "$CL_REPO" -- \
			/bin/sh -c ". '$CL_HOME/etc/dev-env.env' && cd '$CL_REPO' && $1"
	}
	;;
bwrap)
	read -r bw child init _ < <(read_pids; echo) || true
	if ! env_alive "${bw:-}" "${init:-}" || ! runsvdir_ok "${child:-}"; then die "ambiente fora do ar: rode up-bwrap.sh -d"; fi
	# Tudo dentro do ambiente, com o mesmo env dos serviços.
	envsh() { in_env "$child" /bin/sh -c ". '$CL_HOME/etc/dev-env.env' && cd '$CL_REPO' && $1"; }
	;;
*) die "CL_RUNTIME must be bwrap or incus (got: $runtime)" ;;
esac

for _ in $(seq 100); do
	envsh 'pg_isready -q -U postgres -d postgres' 2>/dev/null && break
	sleep 0.1
done
envsh 'pg_isready -q -U postgres -d postgres' || die "PostgreSQL não respondeu (ver $CL_HOME/log e sv status)"

# Como postgres (peer, mapa admin): papéis, extensões no template1 e o banco.
pgadm() { envsh "psql -X -q -v ON_ERROR_STOP=1 -U postgres $1"; }
pgadm "-d postgres -f '$CL_DEVENV_DIR/pg-roles.sql'"
# Extensões que exigem superusuário entram no template1 (o banco de teste herda).
for ext in ${CL_PG_EXTENSIONS:-}; do
	pgadm "-d template1 -c 'CREATE EXTENSION IF NOT EXISTS \"$ext\"'"
done
pgadm "-d postgres -tAc \"SELECT 1 FROM pg_database WHERE datname = 'casalivre'\"" | grep -q 1 ||
	pgadm "-d postgres -c 'CREATE DATABASE casalivre OWNER casalivre_dev'"
# Daqui em diante tudo como casalivre_dev (PGUSER/DB_DSN). Fumaça: sem superusuário.
[ "$(envsh 'psql -X -tAc "SELECT rolsuper::text || rolcreatedb::text FROM pg_roles WHERE rolname = current_user"')" = falsetrue ] ||
	die "casalivre_dev precisa ser NOSUPERUSER e CREATEDB"
if ! envsh 'psql -tAc "SELECT to_regclass('\''public.schema_meta'\'')" | grep -q schema_meta'; then
	log "carregando database/schema.pgsql.sql"
	envsh 'psql -q -v ON_ERROR_STOP=1 -f database/schema.pgsql.sql'
fi
# bz2 só nestas chamadas (o php.ini fica igual ao da produção, sem a extensão).
envsh 'php8.4 -d extension=bz2 scripts/migrate.php'
envsh 'php8.4 -d extension=bz2 scripts/load-ceps.php'
if [ "$seed" -eq 1 ]; then
	if [ "$(envsh 'psql -tAc "SELECT count(*) FROM users"')" = 0 ]; then
		envsh 'umask 077 && php8.4 scripts/seed.php >/dev/null'
		log "seed ok; credenciais em storage/seed-users.txt (0600, fora do public/)"
	else
		log "seed pulado: já há usuários"
	fi
fi
# shellcheck disable=SC2016  # expanded inside the env on purpose
log "banco pronto: $(envsh 'echo $DB_DSN')"

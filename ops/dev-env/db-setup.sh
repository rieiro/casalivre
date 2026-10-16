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

read -r bw child _ < <(read_pids; echo) || true
pid_is_ours "${bw:-}" && runsvdir_ok "${child:-}" || die "ambiente fora do ar: rode up-bwrap.sh -d"

# Tudo dentro do ambiente, com o mesmo env dos serviços.
envsh() { in_env "$child" /bin/sh -c ". '$CL_HOME/etc/dev-env.env' && cd '$CL_REPO' && $1"; }

for _ in $(seq 100); do
	envsh 'pg_isready -q -d postgres' 2>/dev/null && break
	sleep 0.1
done
envsh 'pg_isready -q -d postgres' || die "PostgreSQL não respondeu (ver $CL_HOME/log e sv status)"

envsh 'psql -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '\''casalivre'\''" | grep -q 1 || createdb casalivre'
if ! envsh 'psql -tAc "SELECT to_regclass('\''public.schema_meta'\'')" | grep -q schema_meta'; then
	log "carregando database/schema.pgsql.sql"
	envsh 'psql -q -v ON_ERROR_STOP=1 -f database/schema.pgsql.sql'
fi
envsh 'php8.4 scripts/migrate.php'
# bz2 só nesta chamada (o php.ini fica igual ao da produção).
envsh 'php8.4 -d extension=bz2 scripts/load-ceps.php'
if [ "$seed" -eq 1 ]; then
	if [ "$(envsh 'psql -tAc "SELECT count(*) FROM users"')" = 0 ]; then
		envsh 'umask 077 && php8.4 scripts/seed.php >/dev/null'
		log "seed ok; credenciais em storage/seed-users.txt (0600, fora do public/)"
	else
		log "seed pulado: já há usuários"
	fi
fi
log "banco pronto: $(envsh 'echo $DB_DSN')"

-- P0-2: papéis e banco do dev (desenho do Bot BD). Roda como postgres; idempotente.
-- Log no PG (#137): casalivre_log_writer (INSERT no activity_log; login cl_logw), casalivre_ops_logread (EXECUTE nas
-- funções de leitura), casalivre_log_job (EXECUTE nas funções de retenção; login cl_retention_job). Os logins nunca
-- são membros de casalivre_retention (dono do schema audit).
DO $$
DECLARE r text;
BEGIN
	FOREACH r IN ARRAY ARRAY['casalivre_app', 'casalivre_access_writer', 'casalivre_ops_judicial',
		'casalivre_contract', 'casalivre_retention', 'casalivre_fiscal_owner',
		'casalivre_log_writer', 'casalivre_ops_logread', 'casalivre_log_job'] LOOP
		IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
			EXECUTE format('CREATE ROLE %I NOLOGIN', r);
		END IF;
	END LOOP;
	IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'casalivre_dev') THEN
		CREATE ROLE casalivre_dev LOGIN CREATEDB NOSUPERUSER NOCREATEROLE;
	END IF;
	-- CREATEDB só no dev/teste; na produção nenhum usuário do app tem CREATEDB.
	ALTER ROLE casalivre_dev LOGIN CREATEDB NOSUPERUSER NOCREATEROLE;
	IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'cl_logw') THEN
		CREATE ROLE cl_logw LOGIN CONNECTION LIMIT 20;   -- 20 é [E]: medir o pico de workers FPM (V-12)
	END IF;
	IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'cl_retention_job') THEN
		CREATE ROLE cl_retention_job LOGIN;
	END IF;
	ALTER ROLE cl_logw LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE CONNECTION LIMIT 20;
	ALTER ROLE cl_retention_job LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
	GRANT casalivre_log_writer TO cl_logw;
	GRANT casalivre_log_job TO cl_retention_job;
	FOREACH r IN ARRAY ARRAY['casalivre_app', 'casalivre_access_writer', 'casalivre_ops_judicial',
		'casalivre_contract', 'casalivre_retention', 'casalivre_fiscal_owner',
		'casalivre_log_writer', 'casalivre_ops_logread', 'casalivre_log_job', 'cl_logw', 'cl_retention_job'] LOOP
		EXECUTE format('GRANT %I TO casalivre_dev WITH INHERIT FALSE, SET TRUE', r);
		EXECUTE format('ALTER ROLE %I SET timezone = %L', r, 'UTC');
	END LOOP;
	-- O app do dev roda como casalivre_dev: herda casalivre_app (só INSERT nas tabelas do schema audit).
	GRANT casalivre_app TO casalivre_dev WITH INHERIT TRUE, SET TRUE;
	ALTER ROLE casalivre_dev SET timezone = 'UTC';
END
$$;

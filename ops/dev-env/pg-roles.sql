-- P0-2: papéis e banco do dev (desenho do Bot BD). Roda como postgres; idempotente.
DO $$
DECLARE r text;
BEGIN
	FOREACH r IN ARRAY ARRAY['casalivre_app', 'casalivre_access_writer', 'casalivre_ops_judicial',
		'casalivre_contract', 'casalivre_retention', 'casalivre_fiscal_owner'] LOOP
		IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
			EXECUTE format('CREATE ROLE %I NOLOGIN', r);
		END IF;
	END LOOP;
	IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'casalivre_dev') THEN
		CREATE ROLE casalivre_dev LOGIN CREATEDB NOSUPERUSER NOCREATEROLE;
	END IF;
	-- CREATEDB só no dev/teste; na produção nenhum usuário do app tem CREATEDB.
	ALTER ROLE casalivre_dev LOGIN CREATEDB NOSUPERUSER NOCREATEROLE;
	FOREACH r IN ARRAY ARRAY['casalivre_app', 'casalivre_access_writer', 'casalivre_ops_judicial',
		'casalivre_contract', 'casalivre_retention', 'casalivre_fiscal_owner'] LOOP
		EXECUTE format('GRANT %I TO casalivre_dev WITH INHERIT FALSE, SET TRUE', r);
		EXECUTE format('ALTER ROLE %I SET timezone = %L', r, 'UTC');
	END LOOP;
	ALTER ROLE casalivre_dev SET timezone = 'UTC';
END
$$;

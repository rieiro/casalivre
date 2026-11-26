-- Migration 045: ceps.lat and ceps.lng for precise map positioning
BEGIN;

ALTER TABLE ceps
    ADD COLUMN IF NOT EXISTS lat DOUBLE PRECISION,
    ADD COLUMN IF NOT EXISTS lng DOUBLE PRECISION;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'casalivre_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON ceps TO casalivre_app;
    END IF;
END
$$;

INSERT INTO schema_meta (skey, value)
VALUES ('schema_version', '45')
ON CONFLICT (skey) DO UPDATE SET value = '45';

COMMIT;

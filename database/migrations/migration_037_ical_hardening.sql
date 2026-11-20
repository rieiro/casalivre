-- Migration 037: shared iCal hardening (encrypted feed URLs + deterministic URL hash)
BEGIN;

ALTER TABLE calendar_feeds
    ADD COLUMN IF NOT EXISTS url_hash TEXT NULL
    CHECK (url_hash IS NULL OR url_hash ~ '^[a-f0-9]{64}$');

UPDATE calendar_feeds
   SET url_hash = encode(sha256(convert_to(url, 'UTF8')), 'hex')
 WHERE url_hash IS NULL
   AND url NOT LIKE 'enc:v1:%';

ALTER TABLE calendar_feeds
    DROP CONSTRAINT IF EXISTS calendar_feeds_property_id_url_key;

CREATE UNIQUE INDEX IF NOT EXISTS uq_calendar_feeds_property_url_hash
    ON calendar_feeds (property_id, url_hash)
    WHERE url_hash IS NOT NULL;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'casalivre_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON calendar_feeds TO casalivre_app;
    END IF;
END
$$;

INSERT INTO schema_meta (skey, value)
VALUES ('schema_version', '37')
ON CONFLICT (skey) DO UPDATE SET value = '37';

COMMIT;

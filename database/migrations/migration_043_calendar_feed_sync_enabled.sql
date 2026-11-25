-- Migration 043: calendar_feeds.sync_enabled (host pause/resume of imported dates)
BEGIN;

ALTER TABLE calendar_feeds
    ADD COLUMN IF NOT EXISTS sync_enabled SMALLINT NOT NULL DEFAULT 1
        CHECK (sync_enabled IN (0, 1));

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'casalivre_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON calendar_feeds TO casalivre_app;
    END IF;
END
$$;

-- Admin audit codes for pausing/resuming an imported calendar (ActivityLog CATALOG 65/66).
INSERT INTO activity_actions (code, description, retention_class)
VALUES
    (65, 'Sincronização de calendário externo pausada', 'admin'),
    (66, 'Sincronização de calendário externo reativada', 'admin')
ON CONFLICT (code) DO NOTHING;

INSERT INTO schema_meta (skey, value)
VALUES ('schema_version', '43')
ON CONFLICT (skey) DO UPDATE SET value = '43';

COMMIT;

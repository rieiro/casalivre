-- Migration 034: Google Places ID on properties and ephemeral TTL cache
BEGIN;

ALTER TABLE properties
    ADD COLUMN IF NOT EXISTS google_place_id TEXT NULL
    CHECK (google_place_id IS NULL OR google_place_id ~ '^[A-Za-z0-9_-]{10,128}$');

CREATE TABLE IF NOT EXISTS google_places_cache (
    place_id          TEXT PRIMARY KEY CHECK (place_id ~ '^[A-Za-z0-9_-]{10,128}$'),
    rating            DOUBLE PRECISION NULL CHECK (rating IS NULL OR (rating >= 1.0 AND rating <= 5.0)),
    user_rating_count INTEGER NOT NULL DEFAULT 0 CHECK (user_rating_count >= 0),
    google_maps_uri   TEXT NULL,
    reviews_json      JSONB NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(reviews_json) = 'array'),
    fetched_at        TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp(),
    expires_at        TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_google_places_cache_expires ON google_places_cache (expires_at);

-- Grants do app em bancos existentes (o 04-jumpstart-app.sh só concede em instalações novas).
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'casalivre_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON google_places_cache TO casalivre_app;
    END IF;
END
$$;

INSERT INTO schema_meta (skey, value)
VALUES ('schema_version', '34')
ON CONFLICT (skey) DO UPDATE SET value = '34';

COMMIT;

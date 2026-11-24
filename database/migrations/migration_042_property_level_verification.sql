-- Migration 042: property-level verification flag (Model B) synchronized with OTA feeds
BEGIN;

ALTER TABLE properties
    ADD COLUMN IF NOT EXISTS is_verified SMALLINT NOT NULL DEFAULT 0
        CHECK (is_verified IN (0, 1)),
    ADD COLUMN IF NOT EXISTS verified_at TIMESTAMPTZ NULL;

-- Backfill once when upgrading from < 42 so live properties with approved OTA feeds stay verified.
DO $$
DECLARE
    v INTEGER;
BEGIN
    SELECT COALESCE((SELECT NULLIF(value, '')::int FROM schema_meta WHERE skey = 'schema_version'), 0) INTO v;
    IF v < 42 THEN
        UPDATE properties p
           SET is_verified = 1,
               verified_at = COALESCE(
                   p.verified_at,
                   (SELECT MIN(f.verified_at)
                      FROM calendar_feeds f
                     WHERE f.property_id = p.id
                       AND f.verification_mode = 'ota'
                       AND f.source_platform IN ('airbnb', 'booking', 'vrbo')
                       AND f.status = 'active'
                       AND f.verified_at IS NOT NULL
                       AND f.review_status = 'approved')
               )
         WHERE EXISTS (
             SELECT 1
               FROM calendar_feeds f
              WHERE f.property_id = p.id
                AND f.verification_mode = 'ota'
                AND f.source_platform IN ('airbnb', 'booking', 'vrbo')
                AND f.status = 'active'
                AND f.verified_at IS NOT NULL
                AND f.review_status = 'approved'
         );

        UPDATE properties p
           SET is_verified = 0
         WHERE p.is_verified <> 0
           AND NOT EXISTS (
               SELECT 1
                 FROM calendar_feeds f
                WHERE f.property_id = p.id
                  AND f.verification_mode = 'ota'
                  AND f.source_platform IN ('airbnb', 'booking', 'vrbo')
                  AND f.status = 'active'
                  AND f.verified_at IS NOT NULL
                  AND f.review_status = 'approved'
           );
    END IF;
END
$$;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'casalivre_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON properties TO casalivre_app;
    END IF;
END
$$;

INSERT INTO schema_meta (skey, value)
VALUES ('schema_version', '42')
ON CONFLICT (skey) DO UPDATE SET value = '42';

COMMIT;

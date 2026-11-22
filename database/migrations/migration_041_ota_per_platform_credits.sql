-- Migration 041: once-per-platform OTA verification credits (permanent anti-farming ledger)
BEGIN;

ALTER TABLE ota_credit_ledger
    ADD COLUMN IF NOT EXISTS platform TEXT NULL,
    ADD COLUMN IF NOT EXISTS reward_type TEXT NOT NULL DEFAULT 'ota_verification';

-- Backfill platform from the canonical id, the feed or the legacy verification, with an 'other' fallback.
UPDATE ota_credit_ledger l
   SET platform = CASE
       WHEN split_part(COALESCE(l.canonical_listing_id, ''), ':', 1) IN ('airbnb', 'booking', 'vrbo')
           THEN split_part(l.canonical_listing_id, ':', 1)
       ELSE COALESCE(
           (SELECT f.source_platform
              FROM calendar_feeds f
             WHERE f.property_id = l.property_id
               AND f.verification_mode = 'ota'
               AND f.source_platform IN ('airbnb', 'booking', 'vrbo')
             ORDER BY f.id
             LIMIT 1),
           (SELECT pev.platform
              FROM property_external_verifications pev
             WHERE pev.property_id = l.property_id
               AND pev.platform IN ('airbnb', 'booking', 'vrbo')
             ORDER BY pev.id
             LIMIT 1),
           'other'
       )
   END
 WHERE l.platform IS NULL;

ALTER TABLE ota_credit_ledger ALTER COLUMN platform SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conrelid = 'ota_credit_ledger'::regclass
           AND conname = 'ota_credit_ledger_platform_check'
    ) THEN
        ALTER TABLE ota_credit_ledger
            ADD CONSTRAINT ota_credit_ledger_platform_check
            CHECK (platform IN ('airbnb', 'booking', 'vrbo', 'other'));
    END IF;
    ALTER TABLE ota_credit_ledger ALTER COLUMN user_id DROP NOT NULL;
    ALTER TABLE ota_credit_ledger DROP CONSTRAINT IF EXISTS ota_credit_ledger_user_id_fkey;
    ALTER TABLE ota_credit_ledger
        ADD CONSTRAINT ota_credit_ledger_user_id_fkey
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
END
$$;

-- Legacy indexes are replaced by once-per-platform / once-per-canonical uniqueness.
DROP INDEX IF EXISTS uq_ota_credit_ledger_property;
DROP INDEX IF EXISTS uq_ota_credit_ledger_canonical;

-- Keep one ledger row per (canonical_listing_id, reward_type) and per (property_id, platform, reward_type).
WITH ranked AS (
    SELECT id, row_number() OVER (
        PARTITION BY canonical_listing_id, reward_type
        ORDER BY awarded_at, id
    ) AS rn
      FROM ota_credit_ledger
     WHERE canonical_listing_id IS NOT NULL
)
DELETE FROM ota_credit_ledger WHERE id IN (SELECT id FROM ranked WHERE rn > 1);

WITH ranked AS (
    SELECT id, row_number() OVER (
        PARTITION BY property_id, platform, reward_type
        ORDER BY awarded_at, id
    ) AS rn
      FROM ota_credit_ledger
     WHERE property_id IS NOT NULL
)
DELETE FROM ota_credit_ledger WHERE id IN (SELECT id FROM ranked WHERE rn > 1);

CREATE UNIQUE INDEX IF NOT EXISTS uq_ota_credit_ledger_property
    ON ota_credit_ledger (property_id, platform, reward_type)
    WHERE property_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_ota_credit_ledger_canonical
    ON ota_credit_ledger (canonical_listing_id, reward_type)
    WHERE canonical_listing_id IS NOT NULL;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'casalivre_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON ota_credit_ledger TO casalivre_app;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO casalivre_app;
    END IF;
END
$$;

INSERT INTO schema_meta (skey, value)
VALUES ('schema_version', '41')
ON CONFLICT (skey) DO UPDATE SET value = '41';

COMMIT;

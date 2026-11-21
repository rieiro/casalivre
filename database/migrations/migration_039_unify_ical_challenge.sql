-- Migration 039: unify iCal ownership challenge with calendar feed input sync
BEGIN;

ALTER TABLE calendar_feeds
    ADD COLUMN IF NOT EXISTS status TEXT NOT NULL DEFAULT 'pending_verification'
        CHECK (status IN ('pending_verification', 'active', 'error')),
    ADD COLUMN IF NOT EXISTS challenge_date DATE NULL,
    ADD COLUMN IF NOT EXISTS challenge_expires_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS verified_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS last_checked_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS attempts SMALLINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_passive_check_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS passive_fail_count SMALLINT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS credits_awarded BOOLEAN NOT NULL DEFAULT FALSE;

-- The data backfill runs once: a re-run must never promote a pending feed or re-add a removed one.
DO $$
DECLARE
    v INTEGER;
BEGIN
    SELECT COALESCE((SELECT NULLIF(value, '')::int FROM schema_meta WHERE skey = 'schema_version'), 0) INTO v;
    IF v < 39 THEN
    -- Pre-39 feeds: stopped/failed rows are errors, everything else keeps syncing.
    UPDATE calendar_feeds
       SET status = CASE
           WHEN last_status = 'NOK' OR next_sync_at = 'infinity' THEN 'error'
           ELSE 'active'
       END;

    -- Copy the OTA challenge/verification state onto matching feeds.
    UPDATE calendar_feeds f
       SET verified_at = COALESCE(f.verified_at, pev.verified_at),
           challenge_date = COALESCE(f.challenge_date, pev.challenge_date),
           challenge_expires_at = COALESCE(f.challenge_expires_at, pev.challenge_expires_at),
           last_checked_at = COALESCE(f.last_checked_at, pev.last_checked_at),
           attempts = GREATEST(f.attempts, pev.attempts),
           last_passive_check_at = COALESCE(f.last_passive_check_at, pev.last_passive_check_at),
           passive_fail_count = GREATEST(f.passive_fail_count, pev.fail_count),
           credits_awarded = f.credits_awarded OR (pev.credits_awarded = 1),
           status = CASE
               WHEN pev.status = 'verified' THEN 'active'
               WHEN pev.status = 'failed' THEN 'error'
               ELSE f.status
           END
      FROM property_external_verifications pev
     WHERE pev.property_id = f.property_id
       AND pev.ical_url_hash IS NOT NULL
       AND pev.ical_url_hash = f.url_hash;

    -- Deterministically keep one row per url_hash before the global unique index.
    WITH ranked AS (
        SELECT id,
               row_number() OVER (
                   PARTITION BY url_hash
                   ORDER BY verified_at DESC NULLS LAST, last_synced_at DESC NULLS LAST, id ASC
               ) AS rn
          FROM calendar_feeds
         WHERE url_hash IS NOT NULL
    )
    UPDATE calendar_days
       SET blocked = 0, origin = '', feed_id = NULL
     WHERE feed_id IN (SELECT id FROM ranked WHERE rn > 1);

    WITH ranked AS (
        SELECT id,
               row_number() OVER (
                   PARTITION BY url_hash
                   ORDER BY verified_at DESC NULLS LAST, last_synced_at DESC NULLS LAST, id ASC
               ) AS rn
          FROM calendar_feeds
         WHERE url_hash IS NOT NULL
    )
    DELETE FROM calendar_feeds WHERE id IN (SELECT id FROM ranked WHERE rn > 1);

    -- Explicit ids in legacy data do not advance the identity sequence; ON CONFLICT would hide a PK clash.
    PERFORM setval(
        pg_get_serial_sequence('calendar_feeds', 'id'),
        COALESCE((SELECT max(id) FROM calendar_feeds), 1),
        (SELECT max(id) FROM calendar_feeds) IS NOT NULL
    );

    -- Move remaining OTA rows into calendar_feeds when the property still has a free slot.
    INSERT INTO calendar_feeds (
        property_id, slot, url, url_hash, source_platform, title, status,
        challenge_date, challenge_expires_at, verified_at, last_checked_at, attempts,
        last_passive_check_at, passive_fail_count, credits_awarded,
        last_status, next_sync_at
    )
    SELECT pev.property_id,
           s.slot,
           pev.ical_url,
           pev.ical_url_hash,
           CASE WHEN pev.platform IN ('airbnb', 'booking', 'vrbo') THEN pev.platform ELSE 'other' END,
           CASE pev.platform
               WHEN 'airbnb' THEN 'Airbnb'
               WHEN 'booking' THEN 'Booking.com'
               WHEN 'vrbo' THEN 'Vrbo'
               ELSE COALESCE(NULLIF(pev.platform, ''), 'Outro')
           END,
           CASE pev.status
               WHEN 'verified' THEN 'active'
               WHEN 'failed' THEN 'error'
               ELSE 'pending_verification'
           END,
           pev.challenge_date,
           pev.challenge_expires_at,
           pev.verified_at,
           pev.last_checked_at,
           pev.attempts,
           pev.last_passive_check_at,
           pev.fail_count,
           (pev.credits_awarded = 1),
           'PENDENTE',
           CASE WHEN pev.status = 'failed' THEN 'infinity'::timestamptz ELSE now() END
      FROM property_external_verifications pev
      CROSS JOIN LATERAL (
          SELECT min(g)::smallint AS slot
            FROM generate_series(1, 5) g
           WHERE NOT EXISTS (
               SELECT 1 FROM calendar_feeds f2
                WHERE f2.property_id = pev.property_id AND f2.slot = g
           )
      ) s
     WHERE pev.ical_url IS NOT NULL
       AND pev.ical_url_hash IS NOT NULL
       AND s.slot IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM calendar_feeds f3 WHERE f3.url_hash = pev.ical_url_hash)
    ON CONFLICT DO NOTHING;
    END IF;
END
$$;

DROP INDEX IF EXISTS uq_calendar_feeds_property_url_hash;

CREATE UNIQUE INDEX IF NOT EXISTS uq_calendar_feeds_url_hash
    ON calendar_feeds (url_hash)
    WHERE url_hash IS NOT NULL;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'casalivre_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON calendar_feeds TO casalivre_app;
    END IF;
END
$$;

INSERT INTO schema_meta (skey, value)
VALUES ('schema_version', '39')
ON CONFLICT (skey) DO UPDATE SET value = '39';

COMMIT;

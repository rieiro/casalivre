-- PR #137 follow-up evidence: real schema (database/schema.pgsql.sql), ~105 daily leaves, EXPLAIN (ANALYZE, BUFFERS).
\set ON_ERROR_STOP 1
\timing off
SET ROLE casalivre_retention;
DO $$ DECLARE c text; d date; keep int;
BEGIN
  FOREACH c IN ARRAY ARRAY['security','other'] LOOP
    keep := CASE c WHEN 'security' THEN 90 ELSE 14 END;
    FOR d IN SELECT generate_series(current_date - keep, current_date - 1, '1 day')::date LOOP
      IF to_regclass(format('audit.activity_log_%s_p%s', c, to_char(d,'YYYYMMDD'))) IS NULL THEN
        EXECUTE format('CREATE TABLE audit.%I PARTITION OF audit.%I FOR VALUES FROM (%L) TO (%L)',
          format('activity_log_%s_p%s', c, to_char(d,'YYYYMMDD')), 'activity_log_' || c,
          d::timestamp AT TIME ZONE 'UTC', (d + 1)::timestamp AT TIME ZONE 'UTC');
      END IF;
    END LOOP;
  END LOOP;
END $$;
SELECT p.relname AS parent, count(*) AS leaves FROM pg_inherits i JOIN pg_class p ON p.oid = i.inhparent
 WHERE p.relname IN ('activity_log_security','activity_log_other') GROUP BY 1 ORDER BY 1;
-- seed: security 1000/day (90 d), other 3000/day (14 d); 1 in 50 rows has a user actor_ref (200 distinct users)
INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind, actor_ref, actor_key_version, access_event_id, payload)
SELECT uuidv7(ts - now()), ts, (ARRAY[1,2,3,18,19,20,21,30,45,46])[1 + (g % 10)], 'security',
       CASE WHEN g % 50 = 0 THEN 'user' ELSE 'anon' END,
       CASE WHEN g % 50 = 0 THEN sha256(('u' || (g / 50) % 200)::bytea) END,
       CASE WHEN g % 50 = 0 THEN 1 END, gen_random_uuid(), '{"canal":"web"}'
  FROM generate_series(1, 90000) g, LATERAL (SELECT now() - (g * interval '86 seconds')) t(ts);
INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind, actor_ref, actor_key_version, access_event_id, payload)
SELECT uuidv7(ts - now()), ts, (ARRAY[5,14,15,17,22,29])[1 + (g % 6)], 'other',
       CASE WHEN g % 50 = 0 THEN 'user' ELSE 'anon' END,
       CASE WHEN g % 50 = 0 THEN sha256(('u' || (g / 50) % 200)::bytea) END,
       CASE WHEN g % 50 = 0 THEN 1 END, gen_random_uuid(), '{"canal":"api","raio":10}'
  FROM generate_series(1, 42000) g, LATERAL (SELECT now() - (g * interval '28.8 seconds')) t(ts);
ANALYZE audit.activity_log;
SELECT retention_class, count(*) FROM audit.activity_log GROUP BY 1 ORDER BY 1;
\echo == activity_recent(NULL, 50): inner query
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, SUMMARY ON) SELECT * FROM audit.activity_log ORDER BY id DESC LIMIT 50;
\echo == activity_recent(2, 50): inner query
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, SUMMARY ON) SELECT * FROM audit.activity_log WHERE code = 2 ORDER BY id DESC LIMIT 50;
\echo == activity_by_actor(1 ref): inner query
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, SUMMARY ON)
SELECT * FROM (SELECT * FROM audit.activity_log l WHERE l.actor_ref = ANY (ARRAY[sha256('u7'::bytea)])
  UNION ALL SELECT h.id, h.created_at, h.code, h.retention_class, h.subtype, h.actor_kind, h.actor_ref, h.actor_key_version,
         h.access_event_id, h.payload, h.before_value, h.after_value FROM audit.activity_log_held h WHERE h.actor_ref = ANY (ARRAY[sha256('u7'::bytea)])) x ORDER BY x.id;
RESET ROLE;
\echo == the functions themselves, as casalivre_ops_logread (each writes ACTIVITY_LOG_READ)
SET ROLE casalivre_ops_logread;
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, SUMMARY ON) SELECT * FROM audit.activity_recent(NULL, 50);
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, SUMMARY ON) SELECT * FROM audit.activity_recent(2::smallint, 50);
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, SUMMARY ON) SELECT * FROM audit.activity_by_actor(ARRAY[sha256('u7'::bytea)]);
RESET ROLE;
SET ROLE casalivre_retention;
SELECT code, payload FROM audit.audit_log WHERE code = 47 ORDER BY id;
RESET ROLE;

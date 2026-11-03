source: BD, 2026-11-03, draft input for PRs, not code
# PR7 demand log: `search_demand_daily` (DB design, Bot BD, 2026-11-03)
Sources (develop @561df1a): ADR 001 §11.3 (Segurança 1–7, Jurídico art. 12 design rule), §11.4 (draft DDL, flush), decisions.md
2026-10-25 (kind `search`), backlog PR7, docs/lgpd/lia-demand-log.md. Validated on a scratch PG 18.6 cluster (port 55251, data directory pg); scripts and logs in demand-log-work/.
**[M]** = measured here, **[E]** = estimate. All bulk data is **SYNTHETIC** (gen.sql). Nothing in `src/` uses `cl:demand` yet.

## 1. DDL (own schema, like `audit`)
The app logs in as the owner of `public`, and an owner can always read its own tables, so a REVOKE in `public` cannot meet Segurança 7.
Schema `demand`, owned by NOLOGIN `casalivre_demand`, same pattern as `audit`/`casalivre_retention`.
```sql
-- as the DB owner (public objects), like the audit block:
GRANT REFERENCES (id) ON places TO casalivre_demand;                          -- the only FK (art. 12)
GRANT SELECT (id) ON places TO casalivre_demand_writer;                        -- flush drops unknown place_id
GRANT SELECT (id, type, name, label, uf) ON places TO casalivre_demand_read;   -- report labels
CREATE SCHEMA demand AUTHORIZATION casalivre_demand;
SET ROLE casalivre_demand;
REVOKE ALL ON SCHEMA demand FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE casalivre_demand IN SCHEMA demand REVOKE ALL ON TABLES FROM PUBLIC;
CREATE TABLE demand.search_demand_daily (
  day date NOT NULL,                                     -- no time finer than a day (Segurança 4)
  kind text NOT NULL CONSTRAINT sdd_kind_ck CHECK (kind IN ('unresolved', 'zero_result', 'search')),
  term text COLLATE "C" NULL CONSTRAINT sdd_term_ck CHECK (char_length(term) BETWEEN 1 AND 64 AND term !~ '@'
       AND char_length(regexp_replace(term, '[^0-9]', '', 'g')) < 6),    -- backstop for Segurança 1 (redaction is in PHP)
  place_id int NULL REFERENCES public.places (id) ON DELETE CASCADE,     -- places has no personal data
  hits int NOT NULL CONSTRAINT sdd_hits_k_ck CHECK (hits >= CASE kind WHEN 'search' THEN 1 ELSE 5 END),
  CONSTRAINT sdd_shape_ck CHECK ((kind = 'unresolved' AND term IS NOT NULL AND place_id IS NULL)
      OR (kind IN ('zero_result', 'search') AND place_id IS NOT NULL AND term IS NULL)));
CREATE UNIQUE INDEX sdd_unresolved_uk ON demand.search_demand_daily (day, term) WHERE kind = 'unresolved';
CREATE UNIQUE INDEX sdd_zero_uk ON demand.search_demand_daily (day, place_id) WHERE kind = 'zero_result';
CREATE UNIQUE INDEX sdd_search_uk ON demand.search_demand_daily (day, place_id) WHERE kind = 'search';
GRANT USAGE ON SCHEMA demand TO casalivre_demand_writer, casalivre_demand_read;
GRANT INSERT (day, kind, term, place_id, hits), SELECT (day, kind), DELETE ON demand.search_demand_daily TO casalivre_demand_writer;
GRANT SELECT ON demand.search_demand_daily TO casalivre_demand_read;
RESET ROLE;
```
- Privacy by structure [M]: 5 columns, no user/session/IP/time column, one FK (`places`). Rejected: hits 4 (unresolved/zero_result),
  hits 0 (search), term of 0/65 chars, with `@` or 6+ digits, term+place_id together, unknown place_id; 5 digits pass. Deleting a
  place as the app (no rights on `demand`) still cascades.
- k-threshold: draft matches the texts. Segurança 2 / ADR §11.4 DDL: `hits >= 5` (zero_result too); backlog PR7: `hits >= 1` for `search`.
- No PK. Jurídico's text says "PRIMARY KEY …", but no PK can cover both term and place_id without NULLs. The 3 partial uniques do that job.
- **BRIN on day dropped** (draft had it). Cheap [M]: 24 kB (1 y) / 40 kB (5 y); one-day `DELETE WHERE day` 8 ms vs 824 ms seq scan (5 y).
  But the partial btrees serve every query below, retention included (≤ 9 ms) [M], and once retention frees old pages new days
  reuse them, so BRIN ranges only widen and it degrades [E, not measured]. Index count: **3**.

## 2. Flush (worker, once per closed day)
Valkey hand-off as in ADR §11.4: `RENAME` → `HGETALL` → INSERT → `DEL :flush`. Keys: `cl:demand:{d}` (field = term) and
`cl:demand:search:{d}` (field = place_id). **No key for zero_result is defined anywhere yet.** I propose `cl:demand:zero:{d}`
(field = place_id; `~cl:*` ACL already covers it). PHP filter per field:
```php
if ($f === '_overflow' || $n < ($kind === 'search' ? 1 : 5)) continue;           // k-threshold; _overflow only alerts
// unresolved: 1 <= mb_strlen($f) <= 64; zero_result/search: ctype_digit($f) && 0 < (int)$f <= 2147483647
```
SQL, as one transaction with chunks of 1000 rows (5 params/row; the limit is 65535). The day is bound once:
```sql
INSERT INTO demand.search_demand_daily (day, kind, term, place_id, hits)
SELECT ?::date, v.kind, v.term, v.place_id, v.hits
FROM (VALUES (?::text, ?::text, ?::int, ?::int), ...) AS v (kind, term, place_id, hits)
WHERE v.hits >= CASE v.kind WHEN 'search' THEN 1 ELSE 5 END                 -- same k filter in SQL: never a CHECK abort
  AND (v.place_id IS NULL OR EXISTS (SELECT 1 FROM public.places p WHERE p.id = v.place_id))
ON CONFLICT DO NOTHING;
```
- **DO NOTHING** (ADR §11.4: never incremental; rerun idempotent). The `:flush` hash holds the final day count, so a rerun inserts the
  same values. Needs only INSERT; `DO UPDATE SET hits = EXCLUDED.hits` would need UPDATE + SELECT and one statement per kind, for no gain.
- **No conflict target.** `ON CONFLICT (day, place_id) WHERE kind = 'search'` picks one arbiter, so a mixed batch fails on another
  kind: `duplicate key … "sdd_zero_uk"` [M]. Without a target all 3 uniques are arbiters.
- PDO test [M] (`flush.php`, server-side prepares, as cl_demand_job): 500 mixed fields → 450 after the PHP filter → 449 inserted
  in 33 ms (1 place_id not in `places`, dropped by EXISTS); re-flush 0 rows, 14 ms. 30k fields (25.8k rows, 26 chunks): 954 ms;
  re-flush 0 rows, 565 ms. Rows below k sent past the PHP filter: 0 inserted, no error.
- The CHECKs are a backstop: if one fires (redaction bug), the day's tx rolls back, exit 1, `finish` alerts; `:flush` lives 48 h.

## 3. Read queries (owner/product; BI role)
`kind` must be a **literal** in SQL, not a bound parameter. A generic plan cannot match a partial-index predicate.
```sql
-- Q1 top unresolved terms, 30 d
SELECT term, sum(hits) hits, count(*) days FROM demand.search_demand_daily
 WHERE kind = 'unresolved' AND day >= :d - 30 GROUP BY term ORDER BY hits DESC LIMIT 50;
-- Q2 zero-result places, 30 d: same shape on place_id, kind = 'zero_result', then JOIN places p ON p.id = place_id for labels
-- Q3a one place by day:   WHERE kind = 'search' AND place_id = :p AND day >= :d - 90 ORDER BY day
-- Q3b top places per week: WHERE kind = 'search' AND day >= :d - 6 GROUP BY place_id ORDER BY sum(hits) DESC LIMIT 50
```
Volume assumption [E]; there is no traffic measurement. 75,144 places (real seed counts: 5,571 cities, 61,543 neighborhoods, 8,030 POIs).
Per day: `search` on 10,000 places (every city + 4,429 others), `zero_result` on 500 places with ≥ 5 hits, `unresolved` on 1,000
terms with ≥ 5 hits (the 20k-field cap / k=5 bounds it). That is 11,500 rows/day.
Steady state with 12-month retention = 1 y; 5 y happens only if retention never runs.

| SYNTHETIC, warm, 2nd run [M] | 1 y (4.2M rows) | 5 y (21.0M rows) | plan |
|---|---|---|---|
| Q1 top unresolved 30 d | 15.8 ms | 17.2 ms | Index Scan sdd_unresolved_uk (day range) + HashAgg |
| Q2 zero-result 30 d + labels | 6.4 ms | 6.4 ms | Index Scan sdd_zero_uk + 50 places_pkey probes |
| Q3a one place, 90 d / 365 d | 1.2 / 2.9 ms | 1.1 / 2.6 ms | sdd_search_uk with PG 18 **skip scan** (92 / 367 Index Searches) |
| Q3b top places, 7 d | 25.1 ms | 26.8 ms | Index Scan sdd_search_uk, 70k rows |
| Q3c top places, 1 day | 2.1 ms | 2.2 ms | Index Scan sdd_search_uk |
| Sizes: heap / unresolved_uk / zero_uk / search_uk / total | 212 / 15 / 5.8 / 124 / 357 MB | 1059 / 79 / 29 / 621 / 1788 MB | |
Skip scan means the draft's `(day, place_id)` also serves per-place series. No extra `(place_id, day)` index is needed.

## 4. Retention
ADR §11.3 item 5, LIA §4.5 and backlog: rows are kept **12 months**, then deleted or rolled up to a monthly top-N. Chosen: delete (simpler).
The job is the worker (`cl_demand_job`), daily after the flush. Batches go one day per kind, autocommit, and repeat until 0 rows:
```sql
DELETE FROM demand.search_demand_daily WHERE kind = 'search'          -- likewise 'unresolved', 'zero_result' (literals)
   AND day = (SELECT min(day) FROM demand.search_demand_daily WHERE kind = 'search' AND day < :today - interval '12 months');
```
[M] 5 y: 9.2 / 1.1 / 0.5 ms per batch (Index Only Scan for min, Index Scan for the delete); [M] 1 y: with `kind IN (...)` it is a seq scan.
- A DELETE without WHERE is forbidden. This WHERE-bound retention DELETE is a normal **runtime job**, like `log-retention`.
  Any other destructive operation on production data (manual purge, TRUNCATE, rollup rewrite) needs Rafael's approval (team rule).
  Jurídico's "delete a frequent term that names a third party" is one of those manual cases.

## 5. Roles (ops/dev-env/pg-roles.sql + prod provisioning)
- `casalivre_demand` NOLOGIN: owns schema/table; no login is a member of it.
- `casalivre_demand_writer` (login `cl_demand_job`, env `DEMAND_DB_USER`, required outside dev like `LOG_JOB_DB_USER`):
  INSERT on 5 cols, SELECT (day, kind) only, DELETE. [M] `SELECT term`/`hits` → permission denied; DO UPDATE → denied.
- `casalivre_demand_read` (BI/admin; login e.g. `cl_bi` or `OPS_DB_USER` + `SET ROLE`, like scripts/activity-log.php): SELECT only.
  [M] INSERT/DELETE → denied.
- Web/app login (owner of `public`, `casalivre_app`): no USAGE on `demand`. [M] SELECT and INSERT → "permission denied for schema demand".
- As for the other roles: `GRANT … TO casalivre_dev WITH INHERIT FALSE, SET TRUE`, `ALTER ROLE … SET timezone = 'UTC'`, pg_ident map.
- Segurança will check: no identifier columns, only FK = places, PUBLIC revoked, web role has no USAGE, writer cannot read
  terms/hits, reader read-only, separate logins never members of `casalivre_demand`, the pg-roles.sql diff + prod provisioning.

## 6. Open items (sign-off; not written here)
- Jurídico: lia-demand-log.md already has kind `search` (2026-10-25); confirm zero_result place_id + k=5 and the separate schema.
- Privacy policy "Busca" row covers unsuccessful terms only; `search` counts wait for #155 (backlog: do not edit now).
- Segurança: (a) the admin report path. Produto wants an admin report, but Segurança 7 says the web role may not read. Proposal:
  a CLI/ops report via `casalivre_demand_read`, not the web login. (b) The `cl:demand:zero:{d}` key name. (c) The CHECK backstops in §1.
- SCHEMA number: develop is already at **30** (#243 photos). The "30 calendar / 31 demand" reservation is stale.
  The demand log takes the next number when it merges (31 or 32).

## 7. Edits, index count, tests
- `database/schema.pgsql.sql`: §1 block after the audit block (no seed rows). `src/Db.php`: SCHEMA_VERSION +1 and comment.
- `ops/dev-env/pg-roles.sql`: 3 NOLOGIN roles + login `cl_demand_job` (BI login in prod); `ops/dev-env/sv/postgresql/run` peer map;
  scripts/demand-flush.php (flush + retention) + deploy/runit/casalivre-demand-flush (casalivre-daily), dev sv, deploy.md.
- Indexes: 3 new partial uniques, no BRIN, other tables unchanged. No migration: DB recreated from schema + seeds.
- Tests: `demand_schema_test`, `demand_privileges_test`, `demand_flush_test` (500 mixed, re-flush 0, below-k/unknown/_overflow dropped),
  `demand_retention_test` (12-month cutoff, no Seq Scan), `schema_upgrade_test` (version), `valkey_queue_test` (zero key under `cl:*`).

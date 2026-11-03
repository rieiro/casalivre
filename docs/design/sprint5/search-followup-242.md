source: BD, 2026-11-03, draft input for PRs, not code
# PR #242 follow-up: one small PR for Programador (BD spec, develop @561df1a)

Source: pr242/RESUMO.md + pr242-verdict*.md followups (verdict @4a83ed6). #242 is merged as c946144, so the lab is current develop.
Lab: PG 18.6 scratch (port 55250), develop schema + migrate + the pr242 load: 142,624 properties, 128,284 active, SP 44,979 active.
listing_search was filled by develop `rewriteAll`. calendar_days has 10.95M rows (30k properties x 365 nights, 20% blocked).
Groups: 20 groups of 254-323 properties.
SQL is captured from the real `SearchSql::batch` and `SearchSql::totalAprox` (PDO, custom plans). Warm = median of 5 after warm-up.
Cold = PG restarted per case; the OS cache stays warm, and the box carried another load, so treat +-20% as noise. Logs/plans: design/log/.
**M** = measured, **E** = estimate.

## Rule: which shape a query gets
- **limit-first** (listing_search-only subquery with `ORDER BY ... LIMIT n`, then join properties/places):
  used when `propertyFilters` is empty, i.e. no neighborhood, no group, no checkin/checkout.
  After F4, `score_min` is a listing filter, so it qualifies too. The same shape covers both the bbox and the city (no bbox) branch.
- **group-first**: bbox + group (any other filters).
- **current join shape**: everything else (neighborhood, dates without group). Unchanged.

## F1: LIMIT back in the subquery (limit-first)
```sql
SELECT p.*, s.rank_base, s.rating_rank, (c.name || ', ' || rtrim(c.uf)) AS city
FROM (SELECT s.* FROM listing_search s
      WHERE s.status = 'active' [AND s.city_place_id = ?] [AND s.max_guests >= ?] [AND s.rating_tenths >= ?]
            [AND point(s.lng, s.lat) <@ box(point(?, ?), point(?, ?))] [AND (s.<col>, s.id) < (?, ?)]
      ORDER BY s.<col> DESC, s.id DESC LIMIT :n) s
JOIN properties p ON p.id = s.id JOIN places c ON c.id = p.city_place_id
ORDER BY s.<col> DESC, s.id DESC LIMIT :n
```
| SP 20 km (warm M) | p1 | rating p1 | mid | 99% | hosp8 | 10 km p1 | 3 km p1 | POI 20 p1 | Tiradentes 20 |
|---|---|---|---|---|---|---|---|---|---|
| before | 27.2 | 28.0 | 18.1 | 8.2 | 14.2 | 17.8 | 1.8 | 28.2 | 0.40 |
| limit-first | 14.4 | 14.8 | 11.9 | 8.2 | 10.5 | 7.1 | 1.3 | 15.9 | 0.38 |
Cold SP 20 p1: 27.9 -> 13.6 ms. Results are id-for-id equal (same order) on SP/POI/Tiradentes 20 km, both sorts, hospedes 0/8/16 (log/equality.txt).
Acceptance: same ids as the current SQL; bbox 20 km p1 <= 16 ms warm at 142k.

## F2: group + bbox, read the group first
Use this when `$bbox !== null && $groupId !== null`. The box must be written as BETWEEN (same inclusive bounds as `<@ box`).
With `<@ box` the planner still hash-joins a full idx_ls_geo scan: 14-18 ms (M).
```sql
WITH g AS MATERIALIZED (SELECT id FROM properties WHERE group_id = ?)
SELECT p.*, s.rank_base, s.rating_rank, (c.name || ', ' || rtrim(c.uf)) AS city
FROM (SELECT s.* FROM g JOIN listing_search s ON s.id = g.id
      WHERE s.status = 'active' AND s.lng BETWEEN ? AND ? AND s.lat BETWEEN ? AND ? [AND s.max_guests >= ?] [AND s.rating_tenths >= ?] [keyset]
      ORDER BY s.<col> DESC, s.id DESC) s
JOIN properties p ON p.id = s.id JOIN places c ON c.id = p.city_place_id
WHERE p.group_id = ? [AND NOT EXISTS (calendar_days ... as today)]
ORDER BY s.<col> DESC, s.id DESC LIMIT :n
-- totalAprox: WITH g AS MATERIALIZED (...) SELECT 1 FROM g JOIN listing_search s ON s.id = g.id JOIN properties p ON p.id = s.id WHERE <same> LIMIT 101
```
| group 13 (323, largest) + SP 20 km, warm M | plain | dates | score 9 | dates+score 9 | mid keyset |
|---|---|---|---|---|---|
| batch before -> after (ms) | 92.8 -> 1.08 | 76.9 -> 1.32 | 128.5 -> 1.11 | 116.6 -> 1.19 | 72.5 -> 1.19 |
| totalAprox before -> after | 140.1 -> 0.93 | 106.6 -> 1.24 | 105.4 -> 0.51 | 114.5 -> 0.92 | |
POI 20 km behaves the same (77-132 ms -> 0.5-1.3 ms). Cold: batch 74.1 -> 1.2 ms, total 89.0 -> 1.3 ms. Ids are equal (plain, dates+score).
Plan: IS idx_properties_group > CTE > NL listing_search_pkey > Sort of <= 323 rows.
Group without bbox (2-3 ms, already group-first) stays unchanged.
Acceptance: group + raio 20 search (total + batch) under 5 ms warm; same ids as now.

## F3: totalAprox without properties when propertyFilters is empty
`SELECT 1 FROM listing_search s WHERE <listing filters incl. rating_tenths> LIMIT 101`, run as an Index Only Scan on idx_ls_place_rating or idx_ls_geo.
| warm M (ms) | city | city hosp8 | SP 20 km | POI 20 | Tiradentes 20 | city score 9.5 | city score 10 | SP 20 score 9 |
|---|---|---|---|---|---|---|---|---|
| before | 0.19 | 0.20 | 0.25 | 0.23 | 0.15 | 6.0 | 8.7 | 1.8 |
| after | 0.03 | 0.06 | 0.09 | 0.08 | 0.06 | 0.03 | 0.03 | 0.20 |
Counts are equal in all 5 checked cases. Acceptance: the same count/'100+'.

## F4: rating_tenths in listing_search
`rating_tenths SMALLINT CHECK (rating_tenths BETWEEN 10 AND 100)`, = NULL when rating_count = 0, else `(20*rating_sum + rating_count) / (2*rating_count)`.
Predicate: `s.rating_tenths >= t` with t = ceil(round(10*score_min, 6)), as today.
**Exactness (M):**
- 142,624 rows x t = 1..100: 0 mismatches vs `p.rating_count > 0 AND 20*sum + count >= 2*t*count`.
- Grid count 1..2000, sum count..10*count (18,011,000 pairs): the threshold matches for every integer t, and r equals floor(10*sum/count + 0.5) (round-half-up) with 0 differences.
- Explicit t = 1..100 on count 1..300 (40.7M checks): 0 mismatches.
- Proof: for integers, `20s+c >= 2tc` <=> `t <= (20s+c) div (2c)`.

**Indexes:** adding INCLUDE gives Index Only Scans for the walk. Without INCLUDE the column is a heap filter: city score 9.5 mid goes 66 -> 5.6-8.5 ms instead of 1.5.
```sql
CREATE INDEX idx_ls_place_rank   ON listing_search (city_place_id, rank_base DESC, id DESC)   INCLUDE (max_guests, rating_tenths, rating_rank) WHERE status = 'active';
CREATE INDEX idx_ls_place_rating ON listing_search (city_place_id, rating_rank DESC, id DESC) INCLUDE (max_guests, rating_tenths, rank_base) WHERE status = 'active';
CREATE INDEX idx_ls_geo ON listing_search USING gist (point(lng, lat)) INCLUDE (id, lat, lng, rank_base, rating_rank, max_guests, rating_tenths) WHERE status = 'active';
```
Sizes (M, fresh build): rank and rating each 4.07 -> 5.58 MB; geo 16.39 -> 16.79 MB; heap 12.19 MB unchanged (alignment). Total +3.4 MB (E: ~24 MB at 1M).
| SP city, warm M (ms) | p1 | mid keyset (nothing after) | deep 99% | rating mid | SP 20 km p1 | SP 20 km mid (nothing after) |
|---|---|---|---|---|---|---|
| before (properties filter) | 0.38-0.41 | 52.8-66.2 (cold 47) | 1.0-1.3 | 50.8-70.0 | 27.3 | 78.4 (cold 54.3) |
| after (limit-first, IOS) | 0.32-0.36 | 1.42-1.50 (cold 1.58) | 0.11-0.13 | 1.44 | 8.5 | 7.5 (cold 7.0) |
Ids are equal (city p1/keyset, both sorts, score 0.1/4.3/9/9.5/10; bbox SP/POI/Tiradentes).
**Score queries and totalAprox no longer join properties** (the batch still joins for `p.*` on the final n rows).
Score + dates or + neighborhood keeps the join shape with the predicate on `s.`.

**ListingSearch sync:** add `rating_tenths` as the **last** column of the CREATE TABLE and as the last output column of `projection()`:
inner `CASE WHEN p.rating_count > 0 THEN ((20*p.rating_sum + p.rating_count) / (2*p.rating_count))::smallint END AS rating_tenths`,
outer `..., rank_base, s.rating_tenths`. Also add it to the INSERT column list and the ON CONFLICT SET list.
`ls IS DISTINCT FROM fresh` and `listing_search IS DISTINCT FROM EXCLUDED` compare positionally, so the order must match.
Measured with a patched copy: no-op `rewriteAll` 0 rows, 797-1011 ms, 0 B WAL; current develop is 929-976 ms, 0 B WAL.
3 corrupted rows were detected and repaired (10 kB WAL). The fill pass took 66 s for 99,713 rows (only on an existing DB; not needed since the DB is recreated).
Acceptance: rank-verify on a fresh seed writes 0 rows; adding a review updates rating_tenths through `sync()`.

## F5: hospedes above every listing
Form/validation cap is `max_guests` 1..999 (HouseService.php:77, views/property_form.php:113), so clamping to the cap doesn't help (16 < 999).
The cheapest fix has two parts, with no new index:
- (a) The limit-first IOS above turns the city walk into an index-only walk: SP city hospedes 16 batch 12.8 -> 2.5 ms (cold 13.2 -> 2.9).
- (b) In `SearchService::listed`, skip the batch loop when `total_aprox === 0`. It is exact: the batch filters are the same plus the keyset.
  Also return 0 without SQL when `hospedes > 999`, like `scoreMin > 10`.
After this, a no-match search costs only its totalAprox: city 2.3 ms; SP 20 km bbox 7.2 ms (IOS over 45k geo entries).
Before, total + batch were ~15 and ~13 ms.
Acceptance: hospedes 16 in SP returns 0 items, `iteracoes` 0, and total under 10 ms warm.

## F2': partial index calendar_days (property_id, day) WHERE blocked = 1: **no**
Measured (M) at 10.95M rows / 20% blocked:
- Index size 47 MB (PK 235 MB). Search pages are unchanged:
  - city dates p1 0.57 -> 0.61 ms
  - SP 20 km dates 27.2 -> 27.8 ms (sort-bound; dates is a property filter, so no limit-first)
  - totalAprox dates 0.82 -> 0.35 ms
  - The probe is already a PK range read of 3-30 rows.
- It only helps synthetic probe-everything counts: 45k SP probes 86-90 -> 53 ms; 30k calendar properties x 30 nights 170 -> 73 ms.
- Cost: every block/unblock becomes non-HOT plus an extra index write. Import WAL rises +13% (diff import) and +46% (naive replace).
  Index count would be +1. Revisit only if a dates walk shows up in slow logs.

## SCHEMA / seed edits (one SCHEMA bump: next version)
- `listing_search`: add `rating_tenths SMALLINT CHECK (rating_tenths BETWEEN 10 AND 100)` as the last column. Redefine the 3 idx_ls_* with the INCLUDE lists above.
  Seeds need nothing new: rank-verify/rewriteAll fills the projection. SCHEMA_VERSION comment: "rating_tenths + idx_ls INCLUDE".
- Index count: public 58 -> 58 (redefined, none added). F2' rejected.
Order in the PR:
1. Schema (column + 3 index definitions) + ListingSearch projection/apply. Tests: rank-verify no-op is 0 rows.
2. SearchSql: rating_tenths predicate moves to listingFilters; limit-first for empty propertyFilters (F1/F4/F5a); totalAprox without join (F3).
3. SearchSql: group-first for bbox + group (F2), batch and totalAprox.
4. SearchService: skip batches on total 0, and hospedes > 999 returns 0 (F5b).
5. Bump SCHEMA_VERSION once.
Acceptance tests (names): `search_limit_first_equal_test`, `search_group_bbox_test`, `search_rating_tenths_exact_test` (grid + t 1..100),
`listing_search_rating_tenths_sync_test`, `search_total_aprox_nojoin_test`, `search_no_match_short_circuit_test`; rerun `char_busca_sql_test`, `busca_mapa_test`, `schema_upgrade_test`.

## Risks
- Index-only scans need the visibility map. listing_search already has autovacuum scale 0.02, and rank-verify runs `VACUUM (ANALYZE)` (scripts/rank-verify.php:23).
  Right after a mass rewrite, until vacuum runs, the walks fall back to heap reads (measured 5-8 ms instead of 1.5).
- The limit-first subquery must keep using only the INCLUDE'd columns outside it (id, rank_base, rating_rank). Using `s.lat`/`s.lng` outside breaks the city IOS; the bbox IOS keeps them.
- Group-first is linear in group size. Groups are 254-323 today; revisit if a group reaches thousands (E: ~10k rows is about 10-15 ms).
- BETWEEN vs `<@ box`: both are inclusive on the same doubles; ids were equal in every case above.

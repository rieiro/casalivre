source: Arquitetura, 2026-11-03, draft input for PRs, not code
# Performance budgets: ADR 001 PR5 (map bbox), PR6 (price sorts), Calendar-sync worker

Owner: Arquitetura (targets). Draft 2026-11-03, read against develop @561df1a. PostgreSQL 18 only, no data migration.
Measurement owners (team.md): **BD** = EXPLAIN/indexes; **Teste** = per-PR load tests; Arquitetura sets the numbers below.

**Tags.** `[B]` budget/target set here · `[A]` assumption · `[M]` already measured (source given; single-session warm DB time, not p95 under load) · `[BD?]` needs Bot Banco de Dados confirmation. No number below is a new measurement.

## 0. Shared assumptions and rules
| Item | Value | Tag |
|---|---|---|
| Catalog | 150k listings (138.8k active); dense box SP metro 44k candidates (BD's scaled seed, ADR §12.8). Pilot: ≤ 5k listings | [A] |
| Hardware | Prod: ValueHost bare metal, Brazil (decisions.md 2026-10-16); **VPS: 4 cores, 16 GB RAM (Rafael, 2026-11-03)**, PG + nginx + FPM + Valkey + all workers on the one host; disk type not stated (assume SSD/NVMe). Bench: Void musl dev container, PG 18.6, `random_page_cost = 1.1` | [A] |
| Host budget (4 cores / 16 GB) | PG `shared_buffers` ≈ 4 GB + `effective_cache_size` ≈ 10 GB; FPM ≤ 16 children × ~60 MB ≈ 1 GB; Valkey ≤ 512 MB; workers: 1 mail + 3 image + calendar N, each RSS ≤ 64 MB (≤ 256M limit) ≈ 0.5 GB; OS/page cache keeps ≥ 8 GB. Image workers (CPU-bound, cores − 1 = 3) must run at `nice 10` so they never starve FPM/PG; calendar and mail workers are I/O-bound. The 4 cores are shared by every process, so CPU, not RAM, is the limit | [A] |
| Search load | **on 4 cores the design peak drops to 20 req/s `/api/busca` total** (50 req/s × ~60 ms mean FPM+DB time would need ~3 cores, leaving none for PG/workers); revisit if Rafael expects more traffic or the VPS grows (list + map are 2 parallel requests per search; map pans debounced 500 ms, spec-busca-mapa-pr5 §2); load test 100 concurrent (ADR §12.9) | [A] |
| Endpoint gate | `/api/busca` and `modo=mapa` p95 < 300 ms end to end on VPS-class FPM, 100 concurrent, 0 × 5xx (ADR acceptance 1, 3, 12) | [B] (ADR) |
| DB gate | every allowed case ≤ ~30 ms DB, in all 5 churn states (fresh, 1×/3× recompute unvacuumed, vacuumed) (ADR §12.9 #12, #18) | [B] (ADR) |
| Backstop | `SET LOCAL statement_timeout = '150ms'` per search tx (`SearchService::STATEMENT_TIMEOUT_MS`), 503 `retry` | [M] built |
| **Index guard** | Search indexes are capped at 6 (Rafael 2026-10-21, ADR §12.6). On develop: 3 built (`idx_ls_place_rank`, `idx_ls_place_rating`, `idx_ls_geo`); slots 4–6 are reserved for `channel_rank` PK, `idx_chrank_place`, `avail_runs` PK. **PR5 and PR6 add 0 indexes.** PR6 widens INCLUDE of the 3 existing ones only. Any new search index needs Rafael's approval via Gerente. | [B] |
| Open index question | Does `idx_prop_nb_active` (properties, neighborhood) count toward the 6? BD #238 counted properties 8 + listing_search 4. | [BD?] |

## 1. PR5: map bbox (`modo=mapa`, G-2 5,000 / G-3 8° / 300 pins + grid clusters)
Shape (ADR §12.5, spec-busca-ordenacao-filtros §5): G-3 check in PHP (no query) → one statement: `Index Only Scan idx_ls_geo` → `CTE MATERIALIZED LIMIT 5001` → count; ≤ 5,000: top 300 in `sort_effective` (+ availability probe with dates) and a SQL grid aggregate (≤ 32×32 cells) over the rest; > 5,000: page nodes never executed.

| Budget | p95 | p99 | Tag |
|---|---|---|---|
| Endpoint end to end (100 concurrent) | < 300 ms | < 500 ms | [B] |
| Server time in FPM (request log), allowed case | ≤ 120 ms | ≤ 250 ms | [B] |
| G-3 span response (no SQL) | ≤ 20 ms | ≤ 50 ms | [B] |
| DB statement under load, allowed case (incl. 44k all filters, 4,999 + clusters) | ≤ 40 ms | ≤ 80 ms | [B] [BD?] |
| DB statement, G-2 guard only (5,001) | ≤ 10 ms | ≤ 20 ms | [B]; [M] 2.7–8.5 ms single (ADR §12.7) |
| SQL statements per map request (excl. `SET LOCAL`) | ≤ 2 | — | [B] |
| Response size, 300 pins + 1,024 clusters (worst) | ≤ 100 KB raw, ≤ 30 KB gzip | — | [B] (ADR left the KB cap to Teste; this is the ceiling to confirm) |
| Response, guard (G-2/G-3/G-4) | ≤ 1 KB | — | [B] |
| Coordinates | 4 decimals, `public_coord` only | — | [B] (spec) |

**EXPLAIN (ANALYZE, BUFFERS, SETTINGS) acceptance [B] [BD?]** (jit off, median of 3, 5 churn states):
- `Index Only Scan using idx_ls_geo`; **no Seq Scan or Bitmap Heap Scan on `listing_search`** in vacuumed states; `Heap Fetches: 0` when vacuumed. In the unvacuumed window a Bitmap/heap fallback is tolerated only if the case stays ≤ 30 ms.
- CTE `MATERIALIZED` with `LIMIT 5001`; GiST read **once** (count, pins and clusters all read the CTE).
- Over cap: pins/clusters nodes `(never executed)`.
- Top-300: `top-N heapsort` or `quicksort` in memory; no `external merge`. Grid: `HashAggregate`, no disk spill.
- Availability probe: `calendar_days_pkey` (Index/Index Only Scan), executed only after the filters, loops ≤ 5,000 [BD?: pins-only probe ≤ ~300 loops is the intent].
- Buffers (shared hit+read) ≤ 1,500 at the G-2 cap; ≤ 3,000 for an 8° box with all filters. Rows Removed by Filter ≤ 60k at 8° [A: BD saw ≈ 88k at 16°]. Planning ≤ 2 ms.

**Cache/Valkey:** none for map responses (bbox keys don't repeat; availability must be live). `Cache-Control: no-store` is fine. Client: 500 ms debounce + abort in flight (spec §2) is the rate control. [B]

## 2. PR6: price sorts (`total_price` with dates, G-1a 2,000; `nightly_price` without, G-1b 5,000)
Shape: same capped CTE (`LIMIT 2001` / `LIMIT 5001`) from `idx_ls_geo` (map) or `idx_ls_place_rank` with `ORDER BY` inside the CTE (place path, BD rule ADR §12.5); total = LATERAL over `calendar_days` (≤ 30 nights, G-5) + `cleaning_fee`; Sort in memory; ties newest first. Needs `nightly_price`, `cleaning_fee` (+ filter columns) added to INCLUDE: schema bump, recreate only.

| Budget | p95 | p99 | Tag |
|---|---|---|---|
| `/api/busca` end to end, price sort | < 300 ms | < 500 ms | [B] (ADR) |
| Server time in FPM | ≤ 150 ms | ≤ 300 ms | [B] |
| DB, total price at 2,000, 7 n / 30 n, warm, under load | ≤ 30 / ≤ 40 ms | ≤ 80 ms | [B]; [M] 12.6 / 17.3 ms single (ADR §12.8) |
| DB, total price at 2,000, **cold cache** | ≤ 120 ms (must stay under the 150 ms backstop) | — | [B]; **not measured** (Teste item 1); if missed → lower G-1a |
| DB, nightly price at 5,000 | ≤ 15 ms | ≤ 30 ms | [B]; [M] 4.9 [7.3] ms |
| G-1 fallback response (2,001 / 5,001) | same budget as relevance | — | [B] |
| Page loop (G-8) | ≤ 4 queries, ≤ 240 rows; p95 `iteracoes` = 1 | — | [B] (ADR) |
| List first page | < 50 KB gzip | — | [B] (ADR acc. 2) |
| Write cost after INCLUDE widening, per listing/review write (sync) | ≤ 2.0 ms median | — | [B]; [M] 1.21–1.32 ms at PR4b (BD #238) |
| Index size after widening, fresh, 142k listings | `idx_ls_geo` ≤ 32 MB, each place index ≤ 10 MB | — | [B] [BD?]; [M] 16 / 3.9 MB at PR4b |
| Payload of page 2+ | same as page 1 (cursor recomputes ≤ 2,000 / 5,000 candidates) | — | [B] |

**EXPLAIN acceptance [B] [BD?]:** no Seq Scan on `listing_search` (place path keeps `ORDER BY` in the CTE); one index scan feeding the CTE; `Sort` in memory over ≤ 2,000/5,000 rows (no disk); total-price LATERAL uses `calendar_days_pkey`, loops ≤ 2,000, ≤ 30 rows/loop; over cap the price nodes are `never executed`; buffers ≤ 8,000 for total price at 2,000 × 30 n [A: ~3–4 buffers per probe]; planning ≤ 2 ms. **[BD?]** mixed-direction keyset (`total ASC, id DESC`) can't be one row comparison; confirm the in-CTE predicate form.
**Cache/Valkey:** none. Prices and availability are per-date and must be live. [B]

## 3. Calendar-sync worker (Postgres-only, decisions.md 2026-10-29; backlog "Calendar-sync")
Design in force: claim one due feed with `UPDATE … WHERE id = (SELECT … FOR UPDATE SKIP LOCKED)`, 10-min lease; fetch outside any tx; then one tx with `pg_advisory_xact_lock(property_id)` replaces that feed's nights; `next_sync_at = now() + 30 min`; backoff `LEAST(30 min × 2^fail_count, 6 h)`; runit `casalivre-calendar-worker` × N, `memory_limit=256M`, no Valkey.

| Item | Budget / assumption | Tag |
|---|---|---|
| Feeds | pilot 3,000 feeds; scale 45k (150k listings × 20% with feeds × 1.5) ; ≤ 5 feeds/listing; horizon ≤ 366 nights (`calendar_months` 6 default, 12 cap) | [A] |
| Fetch time | p50 1 s, p95 4 s per feed (remote hosts) | [A] |
| Workers N | `N = ceil(feeds / 1800 s × mean fetch s × 1.5)`: pilot → 3 on paper, **cap N = 2 on the 4-core VPS** (the fetch time is mostly network wait, so 2 workers handle ~3,000 feeds only if mean fetch ≤ 1 s; otherwise lag budget slips before CPU does: measure, then raise to 3); dev → 1. At 45k → ~38, which is too many processes: **revisit then** (longer interval or `curl_multi` in one worker), not now | [B] |
| Per-feed timeout | connect 5 s, total 15 s (cron default today is 20 s; `RESYNC_FEED_TIMEOUT` 6 s stays for manual resync in web requests); body ≤ 2 MB (`MAX_BYTES`), ≤ 3 redirects | [B] |
| Jitter | `next_sync_at` + random 0–300 s, so a restart or outage doesn't send all feeds at once | [B] |
| Rate per remote host | ≤ 2 concurrent fetches per host = N ≤ 3 is enough at pilot; HTTP 429/5xx = temporary (backoff, honour `Retry-After` up to 6 h) | [B] [A] |
| Queue depth (due, unleased) | p95 ≤ feeds/1800 × 300 s (≤ 5 min of work); alert if > 2× that for 15 min | [B] |
| Lag (`now() − min(next_sync_at)` of due feeds) | p95 ≤ 5 min, p99 ≤ 15 min; **ALERT > 30 min** (one full interval missed) | [B] |
| Claim query | ≤ 2 ms p99, Index Scan on the `next_sync_at` index with LIMIT 1 + SKIP LOCKED, no Seq Scan on `calendar_feeds` above 1k rows; the active-account join must not scan `users`/`properties` | [B] [BD?] (also whether the index is partial and that it is back-office, outside the 6) |
| Statements per sync | ≤ 6: lock, read the feed's current nights, delete the nights no longer in the feed, one multi-row upsert (`unnest`/`VALUES`), update feed row, commit. Today `import_ics` does one INSERT per night (up to 366) | [B] [BD?] |
| Rows written per sync | only changed nights; **no-op sync writes 0 `calendar_days` rows** (diff first; BD #238: `ON CONFLICT` on unchanged rows still locks them and writes WAL) | [B] [BD?] |
| WAL per sync | no-op ≤ 8 kB; full replace of 366 nights ≤ 200 kB | [B] [BD?] |
| Search-table writes per sync | 0 on `listing_search`/`channel_rank` (availability is probed on `calendar_days`); `avail_runs` rebuild only once flexible dates ship ([M] 0.17 + 0.29 ms, ADR §12.3) | [B] |
| Transaction | one per feed (all under the property lock); duration p95 ≤ 50 ms, p99 ≤ 200 ms; no network I/O inside | [B] |
| Lock scope vs bookings | lock = `pg_advisory_xact_lock(<ns>, property_id)` (two-int form, own namespace, like `MailQueue::PG_COOLDOWN_LOCK`); **booking, hold and host calendar writes must take the same lock**, otherwise rely on `calendar_days` row locks. A booking write waits ≤ sync p99 (200 ms). No deadlock: one lock per tx, always first. | [B] [BD?] (develop has no advisory lock on booking/calendar writes today) |
| Memory | `memory_limit=256M`; peak RSS ≤ 64 MB with a 2 MB feed; no growth over 1,000 syncs (> 10% = leak) | [B] |
| Failure handling | permanent (`bad_url`, `private_ip`, `http_4xx`, `not_ics`, `too_big`): 1 try, logged; temporary (`timeout`, `dns`, `http_5xx`): backoff; stop scheduling after 72 h of consecutive failures until the owner re-saves | [B]; 72 h is [A] (backlog says "several days"; Produto to confirm) |
| Alerts (ALERT line → `casalivre-alert`) | lag > 30 min; fleet temporary-failure rate > 20% over 1 h (egress/DNS outage); lease expiries > 1% of claims per hour (crash or hang); worker non-zero exits > 3 in 10 min; DB-lost handling as `QueueWorkerLoop::halt` (pause 5–30 s, exit 1) | [B] |

## 4. How to measure (Void musl dev container, PostgreSQL 18 only)
- **DB (BD):** scratch cluster with BD's 142k-listing load (`/workspace/banco-de-dados/pr235/load.sql`, as in #238), `VACUUM (ANALYZE)` then the 5 churn states. Per case: `SET jit = off; EXPLAIN (ANALYZE, BUFFERS, SETTINGS, SUMMARY) <query>;` × 3, median. Under load: `pgbench -n -c 16 -j 2 -T 120 -P 10 --random-seed=42 -r -l -f map_dense_all.sql@3 -f map_g2cap.sql@2 -f map_8deg.sql@1 -f total2000_30n.sql@2 -f nightly5000.sql@2 <db>` with `\set` random bbox offsets inside the SP box; p95/p99 from the `-l` logs (`sort -n` on column 3). Cold: restart PG + `echo 3 > /proc/sys/vm/drop_caches` where allowed, first run only. Reuse `/workspace/adr/bench-adr001-sorts/*.sql` shapes.
- **WAL/rows per sync:** `SELECT pg_current_wal_lsn()` before/after, `pg_stat_statements` calls per sync, `n_tup_ins`, `n_tup_upd` and `n_tup_del` from `pg_stat_user_tables`.
- **Endpoint (Teste):** FPM in the dev env, k6 (or `wrk` + Lua if k6 is missing on musl): 100 VUs, 5 min, thresholds `p(95)<300`, `p(99)<500`, `http_req_failed<0.001`, mix 40% map, 40% list, 20% price sort; payload sizes from `Content-Length` with `Accept-Encoding: gzip`. Include one run during `rank-verify --recompute` (unvacuumed window).
- **Calendar-sync (Teste):** scratch DB with 3,000 feeds pointing at a local `php -S` fake feed server (366-night `.ics`, injected 1–4 s latency, 5% timeouts, 5% 5xx, 1% 2 MB bodies); N = 2 then 3 workers, 60 min each, on a 4-core container (`--cpus=4`) with image workers idle and then busy; sample lag/depth every 10 s with one SQL query; concurrent booking-write loop to record lock waits (`log_lock_waits = on`, `deadlock_timeout = 100ms`); `ps -o rss` per worker every minute.

## 5. Pass/fail
| # | Check | Pass if | Owner |
|---|---|---|---|
| 1 | Index count | PR5 +0, PR6 +0 search indexes; total ≤ 6 | BD |
| 2 | Map plan | IOS `idx_ls_geo`, one GiST pass, no Seq Scan on `listing_search`, buffers ≤ 1,500 / 3,000 | BD |
| 3 | Map DB | ≤ 30 ms single, all churn states; p95 ≤ 40 ms under load | BD |
| 4 | Map endpoint | p95 < 300 ms, p99 < 500 ms, 0 × 5xx; worst payload ≤ 30 KB gzip | Teste |
| 5 | Price-sort plan | capped CTE, in-memory Sort, LATERAL loops ≤ 2,000, over-cap nodes never executed | BD |
| 6 | Price-sort DB | warm ≤ 30/40 ms (7/30 n); **cold at 2,000 ≤ 120 ms**, else lower G-1a | BD / Teste |
| 7 | Write cost after INCLUDE widening | sync ≤ 2.0 ms median; index sizes within §2 | BD |
| 8 | Calendar lag | p95 ≤ 5 min, p99 ≤ 15 min, no 30-min alert in 60 min run | Teste |
| 9 | Calendar writes | ≤ 6 statements/sync, no-op = 0 rows, tx p99 ≤ 200 ms, booking wait ≤ 200 ms, 0 deadlocks | BD / Teste |
| 10 | Calendar worker | RSS ≤ 64 MB, no leak over 1,000 syncs, alerts fire on injected outage | Teste |

Note: the PR5 spec lists "map performance measurement (deferred by Rafael, #187)" as out of scope; checks 3–4 run when Gerente resumes #187. Checks 1–2 are BD's normal `needs:db` review of PR5.

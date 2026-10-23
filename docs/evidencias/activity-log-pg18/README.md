# Activity log on PostgreSQL 18: evidence for the PostgreSQL-only PR (#137)

Run on 2026-10-21 in a freshly provisioned dev env (`ops/dev-env`, PostgreSQL 18.6, Void musl), torn down afterwards.

- `01-ddl-checks.pg18.log`, `02-retention-step.pg18.log`: BD's scripts from the spec (`evidence-activity-log/`), rerun
  unchanged on PG 18 in a throwaway database as `postgres` (`ON_ERROR_STOP 0`). The two "role already exists" errors
  at the top are expected: the dev cluster already has `casalivre_retention` and `casalivre_app`. Results match the
  PG 17.11 run: 216 leaves then 0 (idempotent), 42501 on SELECT, FK 23503 for an admin code, V-4 CHECK, 16-byte
  `actor_ref` CHECK, a `-03` session routes 23:30 to the next UTC leaf, dry-run, refusal with open holds and no refs,
  96 leaves dropped, 1 held row copied, 96 `purge_runs` rows, Merge Append of backward index scans. (The two
  `pg_class` counts in 02 also count each leaf's indexes; they are not leaf counts.)
- `03-explain-pr.sql` / `.pg18.log`: the real `database/schema.pgsql.sql`, 120 daily leaves (security 90 d + other
  14 d + 8 ahead each) plus the two DEFAULT partitions, 132k rows, `EXPLAIN (ANALYZE, BUFFERS)` of the three read
  paths and of the DEFINER functions as `casalivre_ops_logread` (each call writes one `ACTIVITY_LOG_READ` row, listed
  at the end). No timings target is implied: one run, warm cache, laptop-class box.

# Decisions in force

One line per decision in force, newest first within each area. Superseded decisions are removed. Only Gerente edits this file.

## Process
- 2026-10-16: Work order: XSS -> P0 -> ADR 001 -> schema spec.
- 2026-10-16: Team protocol in [prompts/team.md](../prompts/team.md) (PR labels as state machine, fixed verdict format), approved by Rafael; replaces `prompts/regrasbots.md`.
- 2026-10-16: English everywhere (bots, Rafael, PRs).
- 2026-10-14: No staging/homologation per batch while there is no production; it returns once, before the first go-live. Focused QA per PR, one full regression per batch.
- Tests run only on PHP 8.4 (local and CI), no per-version runs.

## Infrastructure and database
- 2026-10-16: Production-like dev env: Void Linux musl container with nginx, PHP-FPM 8.4, PostgreSQL and Valkey, no Docker; recipe in `ops/dev-env/` (#112). Rafael's Incus container is only for his own tests. Production: ValueHost bare-metal in Brazil.
- 2026-10-16: SQLite must keep working; optimization, index tuning and performance measurement happen only on PostgreSQL.
- 2026-10-14: The DB is recreated from scratch during sprints (no data migration or backfill); `Db::SCHEMA_VERSION` still bumps on schema change.
- No DROP, TRUNCATE, DELETE without WHERE, or use of production data without Rafael's explicit approval.
- `RESTRICT` on `properties.user_id`.
- activity_log moves to PostgreSQL; the Mongo driver is removed.
- Valkey ACL as merged in #112: default user off; `cl_app` limited to `~cl:* &cl:* -@all` plus allowlist (no streams/Lua); separate `cl_admin`; aclfile 0600, password files 0440, socket 0660.
- Queues must be able to move to Valkey in production.
- Security logs go to PostgreSQL via an INSERT-only app role (separate purge role), in their own transaction; on PG failure they fall back to a local sealed JSONL buffer (dir 0750) that a runit worker loads into PG with COPY, with an alert.

## Security
- Separate `LOG_SALT` in production.
- iCal feeds: https only; a failed download returns 502.
- Audit: UPDATE/DELETE record the previous value; every API query is logged.

## Product
- Email (signup, password reset): code ready but off, with no provider; both stay closed in production until a provider is chosen.
- Maps: no Google Maps; OSM tiles today, PMTiles Brazil (Protomaps) + MapLibre GL in backlog.
- Property rating screen frozen for redesign.
- Foreign guest without CPF is in the backlog; CPF stays required (`guest_fiscal_party_id NOT NULL`).

## Business
- Franchise split: owner pays 10% = 4% brokerage (franchisee, NFS-e item 10.05) + 3% fund + 3% casalivre royalty (invoiced to the franchisee, item 17.08); all percentages are parameters.
- D12: OTA bookings 8% = 4+2+2, base = gross nightly rates without cleaning, OTA fees or taxes.
- Pilot in Regiao dos Lagos (RJ); platform supports franchise, regional partner (domain/slug) or no partner; core is single, each model is configuration.
- S7/D3 are discussed at the end of sprint 6.

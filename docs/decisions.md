# Decisions in force

One line per decision in force, newest first within each area. A superseded decision is struck through and points to the decision that replaces it. Only Gerente edits this file.

## Process
- 2026-10-21: `docs/arquitetura.md` is a low-priority explanatory overview and may be outdated. Don't keep it in sync, don't open PRs or backlog items just to update it, and don't block PRs on it. It is refreshed from the latest specs only when Rafael asks (Rafael, 2026-10-21).
- 2026-10-21: Money-code standards: [docs/money-standards.md](money-standards.md) (Rafael, 2026-10-21).
- 2026-10-16: Work order: XSS -> P0 -> ADR 001 -> schema spec.
- 2026-10-16: Team protocol in [prompts/team.md](../prompts/team.md) (PR labels as state machine, fixed verdict format), approved by Rafael; replaces the old `regrasbots.md`.
- 2026-10-16: English everywhere (bots, Rafael, PRs).
- 2026-10-14: No staging/homologation per batch while there is no production; it returns once, before the first go-live. Focused QA per PR, one full regression per batch.
- Tests run only on PHP 8.4 (local and CI), no per-version runs.

## Infrastructure and database
- 2026-10-21: No data migration. All data is created from scratch (DB recreated, seeds). No migration code, legacy-data conversion, backfills or migration deploy steps; schema changes update the schema and seeds only (`Db::SCHEMA_VERSION` still bumps). Data migration comes only later, by explicit Rafael decision.
- 2026-10-21: The rate limiter (`IpRateLimiter`) moves to Valkey now, replacing the files in `storage/cache/ratelimit`.
- 2026-10-21: Accepted dev-only risk: Rafael's Incus dev container has a writable container root and outbound network through the default NIC. Not for production.
- 2026-10-16: Production-like dev env: Void Linux musl container with nginx, PHP-FPM 8.4, PostgreSQL and Valkey, no Docker; recipe in `ops/dev-env/` (#112). Rafael's Incus container is only for his own tests. Production: ValueHost bare-metal in Brazil.
- 2026-10-21: PostgreSQL only. SQLite and MongoDB are dropped (Rafael, 2026-10-21).
- ~~2026-10-16: SQLite must keep working; optimization, index tuning and performance measurement happen only on PostgreSQL.~~ Superseded by "2026-10-21: PostgreSQL only".
- ~~2026-10-14: The DB is recreated from scratch during sprints (no data migration or backfill); `Db::SCHEMA_VERSION` still bumps on schema change.~~ Superseded by "2026-10-21: No data migration".
- No DROP, TRUNCATE, DELETE without WHERE, or use of production data without Rafael's explicit approval.
- `RESTRICT` on `properties.user_id`.
- ~~activity_log moves to PostgreSQL; the Mongo driver is removed.~~ Superseded by "2026-10-21: PostgreSQL only", which covers it.
- Valkey ACL as merged in #112: default user off; `cl_app` limited to `~cl:* &cl:* -@all` plus allowlist (no streams/Lua); separate `cl_admin`; aclfile 0600, password files 0440, socket 0660.
- Queues must be able to move to Valkey in production.
- Security logs go to PostgreSQL via an INSERT-only app role (separate purge role), in their own transaction; on PG failure they fall back to a local sealed JSONL buffer (dir 0750) that a runit worker loads into PG with COPY, with an alert.

## Security
- Separate `LOG_SALT` in production.
- iCal feeds: https only; a failed download returns 502.
- Audit: UPDATE/DELETE record the previous value; every API query is logged.

## Product
- 2026-10-21: D1 approved: management tree of free depth plus separate storefront channels (Group -> Organization + Channel), Sprint 5 (P2a); group domains and themes move to the channel.
- 2026-10-21: Channel membership: a channel manager may remove an owner's property from their channel without the owner's consent, but it is never deleted or hidden from the platform; the owner gets a notice and an audit log entry. Adding a property to a channel needs the owner's consent.
- 2026-10-21: Themes stay repo-only (`themes/<name>/`, prepared by the team, contrast checked when prepared); no in-app theme editor or logo upload for now.
- 2026-10-21: No blanket move of forms to fetch/API: the P10 listing wizard decides its own approach, and no-JS forms stay where they work.
- Email (signup, password reset): code ready but off, with no provider; both stay closed in production until a provider is chosen.
- Maps: no Google Maps; OSM tiles today, PMTiles Brazil (Protomaps) + MapLibre GL in backlog.
- Property rating screen frozen for redesign.
- Foreign guest without CPF is in the backlog; CPF stays required (`guest_fiscal_party_id NOT NULL`).

## Business
- Franchise split: owner pays 10% = 4% brokerage (franchisee, NFS-e item 10.05) + 3% fund + 3% casalivre royalty (invoiced to the franchisee, item 17.08); all percentages are parameters.
- D12: OTA bookings 8% = 4+2+2, base = gross nightly rates without cleaning, OTA fees or taxes.
- Pilot in Regiao dos Lagos (RJ); platform supports franchise, regional partner (domain/slug) or no partner; core is single, each model is configuration.
- S7/D3 are discussed at the end of sprint 6.
- 2026-10-16: Bots write to GitHub (PR comments, labels, reviews) without asking Rafael first.

# Decisions in force

One line per decision in force, newest first within each area. A superseded decision is struck through and points to the decision that replaces it. Only Gerente edits this file.

## Process
- 2026-10-21: Backlog plan: a cleanup sprint first (line-143 live bug, `IpRateLimiter` to Valkey, remove `migrateLegacy`, remove SQLite, close the backlog-review DROP items; about 1-2 days), then the standard plan resumes. Each sprint clears the KEEP items touching the code it changes plus about 20% of its effort on the remaining backlog. A new backlog item must name its sprint or it is dropped; Gerente reviews the backlog once per sprint (Rafael, 2026-10-21).
- 2026-10-21: Verdict comments are capped at about 400 chars: the `VERDICT <role> @sha7: APPROVED|CHANGES` header, one Blockers line and one Followups line ("none" if empty). Evidence goes in a linked file, gist or collapsed section. Applies to every reviewer role. No per-PR hop counts in ready-to-merge messages (Rafael, 2026-10-21).
- 2026-10-21: `docs/arquitetura.md` is a low-priority explanatory overview and may be outdated. Don't keep it in sync, don't open PRs or backlog items just to update it, and don't block PRs on it. It is refreshed from the latest specs only when Rafael asks (Rafael, 2026-10-21).
- 2026-10-21: Money-code standards: [docs/money-standards.md](money-standards.md) (Rafael, 2026-10-21).
- 2026-10-16: Work order: XSS -> P0 -> ADR 001 -> schema spec.
- 2026-10-16: Team protocol in [prompts/team.md](../prompts/team.md) (PR labels as state machine, fixed verdict format), approved by Rafael; replaces the old `regrasbots.md`.
- 2026-10-16: English everywhere (bots, Rafael, PRs).
- 2026-10-14: No staging/homologation per batch while there is no production; it returns once, before the first go-live. Focused QA per PR, one full regression per batch.
- Tests run only on PHP 8.4 (local and CI), no per-version runs.
- 2026-10-21: Simplicity rule: handle only cases that can realistically happen here; code for a rare case says in the PR why it's worth it. The Revisor may return a PR as `changes:review` for unnecessary complexity, as for a bug. First application: the worker file caches reload when `filemtime()` differs from the value stored at load (after `clearstatcache()`), without inode/size or same-second checks; a stale file in the rare same-second case is acceptable (Rafael, 2026-10-21).
- 2026-10-21: Author self-review: before opening a PR or pushing a new head, the author rereads its own diff and fixes duplicate or unused code, leftovers/debug, failing lint or tests, and code more complex than the job needs (in the role files of the bots that open code PRs: Programador, Teste) (Rafael, 2026-10-21).
- 2026-10-21: Review and test runs (from a review of 13 code PRs, #142-#193): the Revisor returns a PR with author-catchable hygiene (duplicate or unused code, leftovers, needless complexity) as `changes:review` right away, without a full review; a Revisor follow-up is reported once, then fixed in that PR or dropped, never re-reported. Only Teste runs the full suite; authors run only the tests their change touches; Arquitetura measures performance and does not re-run the suite (Rafael, 2026-10-21).
- 2026-10-21: Revisor findings are blockers or not written: duplicate or copy-pasted functions, unused or dead code, leftovers and unnecessary complexity are always blockers (`changes:review`); Revisor verdicts have no Followups line. The Revisor decides blocker-or-not when writing; a disputed blocker is decided by Gerente; anything larger than the PR goes to the backlog with a sprint. Supersedes the #195 follow-up wording (Rafael, 2026-10-21).

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
- 2026-10-21: Search sorts and filters (ADR 001 §12, `docs/adr/001-busca-sql-paginada.md`): every sort uses keyset pagination over scores precomputed on write or by a Valkey job, never per request; 6 search indexes regardless of channel count, plus the write-path primary key `listing_search_pkey` whose cost is measured (narrow `listing_search` table kept, Rafael 2026-10-21). Zoom-in guards are OK (Rafael): a map-area list over 5,000 filtered candidates returns 'zoom in' under any sort; total-price sort capped at 2,000 candidates; map span capped at 8°. Relevance (G1) is the default sort (Produto). A paid boost may lag up to 5 min: position and 'Patrocinado' label come from the same row, billing starts at go-live, the host screen says so, and stale minutes aren't billed (Produto with Jurídico).
- 2026-10-21: G1 ranking: the product supports per-channel weights for paid placement and own-network preference, both 0 (off) by default; a paid boost shows a visible "Patrocinado" label; only `admin_plataforma` sets the weights; turning either on is a later decision (Rafael, 2026-10-21). Sponsored map pins are supported (Rafael, 2026-10-21): tagged "Patrocinado", never clustered, inside the 300-pin cap (spec #189).
- 2026-10-21: G13: platform max stay is 30 nights for now (pilot and P6), a platform cap stored in the booking snapshot; a host's `max_nights` cannot exceed it (Rafael, 2026-10-21).
- 2026-10-21: D1 approved: management tree of free depth plus separate storefront channels (Group -> Organization + Channel), Sprint 5 (P2a); group domains and themes move to the channel.
- 2026-10-21: Channel membership: a channel manager may remove an owner's property from their channel without the owner's consent, but it is never deleted or hidden from the platform; the owner gets a notice and an audit log entry. Adding a property to a channel needs the owner's consent. New properties are ON by default only in the casalivre marketplace (own storefront); every other organization's or partner's channel needs the owner's opt-in, with no automatic inclusion of a node's new properties (Rafael, 2026-10-21).
- 2026-10-21: Themes stay repo-only (`themes/<name>/`, prepared by the team, contrast checked when prepared); no in-app theme editor or logo upload for now.
- 2026-10-21: No blanket move of forms to fetch/API: the P10 listing wizard decides its own approach, and no-JS forms stay where they work.
- Email (signup, password reset): code ready but off, with no provider; both stay closed in production until a provider is chosen.
- Maps: no Google Maps; OSM tiles today, PMTiles Brazil (Protomaps) + MapLibre GL in backlog.
- Property rating screen frozen for redesign.
- Foreign guest without CPF is in the backlog; CPF stays required (`guest_fiscal_party_id NOT NULL`).
- 2026-10-21: During development Juridico does not draft or update host/owner terms or any other legal contract (terms of use, privacy and cancellation policies, rental and service contracts). It acts only as a consultant guiding Produto (legal/tax answers on specs, PR verdicts via `needs:legal`). All contracts are written at once near the end of the project, only when Rafael asks; the texts in `docs/produto/juridico/textos/` stay as they are until then (Rafael, 2026-10-21).

## Business
- Franchise split: owner pays 10% = 4% brokerage (franchisee, NFS-e item 10.05) + 3% fund + 3% casalivre royalty (invoiced to the franchisee, item 17.08); all percentages are parameters.
- D12: OTA bookings 8% = 4+2+2, base = gross nightly rates without cleaning, OTA fees or taxes.
- Pilot in Regiao dos Lagos (RJ); platform supports franchise, regional partner (domain/slug) or no partner; core is single, each model is configuration.
- S7/D3 are discussed at the end of sprint 6.
- 2026-10-16: Bots write to GitHub (PR comments, labels, reviews) without asking Rafael first.

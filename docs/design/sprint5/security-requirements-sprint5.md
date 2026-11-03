source: Seguranca, 2026-11-03, draft input for PRs, not code
# CasaLivre: security requirements for Sprint 5 (Segurança, develop 561df1a, 2026-11-03)
Testable must/must-not rules. Sources: docs/decisions.md:27 (calendar sync is Postgres-only, no Valkey), :46 (iCal https only); prompts/backlog.md:60-65 (calendar-sync design), :222-236 (groups rewrite); spec-04 (04-6 invites, 04-7 domains). Existing code to keep: `CalendarSync::fetch` (src/CalendarSync.php:168-212: https-only, CURLOPT_RESOLVE pin, 3 redirects, 2 MB cap, NOPROXY), `isPublicIp` (:259-319), `logRef` (:127).

## 1. Calendar-sync fetch (scripts/calendar-worker.php, runit `casalivre-calendar-worker`)
**SSRF**
1.1 Must accept only `https://` with a host and no userinfo, using `CalendarSync::isHttpsUrl` (:149), on save and on every fetch. There is no http exception, including for old feeds (decisions.md:46).
1.2 Must resolve DNS once per hop, refuse if ANY A/AAAA record is non-public, and connect only to the pinned IP (CURLOPT_RESOLVE, :330).
1.3 Must keep `isPublicIp` blocking 0/8, 10/8, 100.64/10 (CGNAT), 127/8, 169.254/16 (metadata), 172.16/12, 192.0.0/24, 192.168/16, 198.18/15, 224/4, 240/4, ::/96, ::ffff:0:0/96 (IPv4-mapped), 64:ff9b::/96, 2002::/16, 2001::/32, fc00::/7, fe80::/10, ff00::/8. Test each class.
1.4 Must re-run 1.1-1.3 on every redirect hop: at most 3 redirects (MAX_REDIRECTS, :84), https→https only, no FOLLOWLOCATION.
1.5 NEW: the port must be 443. Today `resolveTarget` takes any port (:225), so `https://host:22/` is allowed. Refuse other ports as `bad_url` (it is also a port-scan vector).
1.6 Must keep the connect timeout ≤5 s and the total ≤20 s (:331-332), and keep the 2 MB cap enforced while streaming (write callback abort plus MAXFILESIZE, :336-351).
1.7 Must require a body containing `BEGIN:VCALENDAR`; anything else is `not_ics`. Content-Type is advisory only (feeds send text/plain).
**Parser** (`import_ics`, src/helpers/ical.php:80)
1.8 NEW: the day loop must clamp start/end to [today, max_calendar_day] BEFORE iterating. Today `DTSTART:00010101`/`DTEND:99991231` loops about 3.6M times per event and skips each day inside the loop (ical.php:123-127), a CPU DoS.
1.9 Must cap events per feed (e.g. 2,000) and blocked nights written per feed (≤ horizon days); over the cap → `too_big`.
1.10 Must not expand RRULE/RDATE (the parser ignores them; keep it that way). No XML or entity parsing. `STATUS:CANCELLED` frees nights (backlog.md:64).
**Failure reasons**
1.11 `last_error` must hold only the fixed enum `bad_url|private_ip|http_4xx|not_ics|too_big|timeout|dns|http_5xx` (backlog.md:64). Never raw curl/PHP error text, URL, IP, resolved addresses, headers or body.
1.12 The owner UI must show a translated text per coarse class. `bad_url` and `private_ip` show the same text ("URL not accepted"), and nothing echoes back except the escaped feed title and host. This keeps the reasons from working as an internal host/port scanner.
**Lease, backoff, limits**
1.13 Claim with `UPDATE … WHERE id = (SELECT … FOR UPDATE SKIP LOCKED)` and a 10-minute lease (Postgres, not Valkey: decisions.md:27). At most one fetch per feed at a time, and only for active accounts.
1.14 The HTTP fetch must run outside any DB transaction. The import runs in one transaction under `pg_advisory_xact_lock(property_id)`.
1.15 Backoff must be `LEAST(30 min·2^fail_count, 6 h)` with ±10% jitter. Permanent reasons stop scheduling until the owner re-saves the feed, and the feed stops after N days of failures.
1.16 Must keep ≤5 feeds per property, enforced in the DB. Manual resync must go through a rate-limit bucket per user (e.g. 6/h) and a global cap per worker loop. No fetch on page view or in web requests (backlog.md:62: today `ICalImport` and `resyncProperty` download inside the request).
**Worker isolation and logs**
1.17 The worker must have no Valkey credentials at all (Postgres-only). It needs its own DB role with SELECT/UPDATE on `calendar_feeds`, SELECT on properties/users (status only), and INSERT/DELETE on `calendar_days` limited to feed-tagged rows, plus the audit INSERT path. No DDL and no other tables.
1.18 Outbound traffic for iCal must go only through the worker: TCP 443 plus DNS, with nftables dropping private/metadata ranges as defence in depth. PHP-FPM gets no general egress once the web download is removed.
1.19 Log dir 0700 owned by the service user, files 0600 (`calendar-sync.log`, backlog.md:64). Log only feed id, property id, reason code, HTTP status class and duration.
1.20 The feed URL must never be logged or echoed: iCal URLs carry secret tokens. Use `logRef` (host + sha256/12) everywhere, including ActivityLog payloads (`logFeed`, :137). Show the URL masked in the UI (host + "…").
1.21 ActivityLog: add `CALENDAR_FEED_FAIL` (class admin) for a permanent failure and the stop-after-days event. `CALENDAR_SYNC` stays `other` (ActivityLog.php CLASSES). Tag every new code in `activity_actions` and CLASSES in the same PR.

## 2. Sprint 5 groups rewrite (org tree + channels)
**Invites** (spec-04 04-6, backlog.md:225)
2.1 Token must be ≥128 bits from `random_bytes`, stored only as sha256, compared by hash lookup (or `hash_equals`), single use (atomic `UPDATE … WHERE used_at IS NULL AND expires_at > now() AND revoked_at IS NULL RETURNING`).
2.2 Validity 72 h, revocable, fixed role and node chosen by the inviter. If issued to an email/phone, accept requires the logged-in account to match.
2.3 Used, expired, revoked and unknown tokens must give the same response, status and timing (spec-04:106 text). No enumeration of users or emails on send or accept.
2.4 Cap pending invites per inviter and per node (e.g. 20). CSRF on send, revoke, accept and decline. Accept is POST only (GET only shows the page).
**AuthZ**
2.5 Every group/org/channel route must check role server-side on the target node (or an ancestor), loading by id plus node scope. Test an IDOR on another group's id for each route.
2.6 The last owner/manager of a node cannot be removed or demoted. A manager cannot grant a role above their own. Removing a property from a channel never deletes or hides it, and writes an audit entry and a notice (decisions.md:61).
2.7 Draft, unpublished or disabled channels return the same 404 as an unknown slug (backlog.md:228).
**Rate limits** (`IpRateLimiter`, HMAC-keyed `cl:rl:<bucket>:<HMAC>`)
2.8 Named buckets: `grupo-convite-envio` (per IP and per inviter user), `grupo-convite-aceite` (per IP), `dominio-verificar` (per IP and per channel), `grupo-criar` (per user), and theme select. `/admin/*` and `/grupos/*` have none today (backlog.md:226).
2.9 Invite send must be in `FAIL_CLOSED_BUCKETS` (IpRateLimiter.php:42) once D3 sends email or SMS. Every other new bucket fails open (rule at :37-40).
2.10 Per-user subjects need a key from `LogPrivacy::userRef`-style HMAC, never a raw id or email in the key (today `key()` is IP-only, :80-87).
**Domain verify** (`DomainVerification`, TXT `_casalivre.<domain>`)
2.11 Token from `random_bytes(16)` (DomainVerification.php:46). It is public in DNS, so plain storage is OK, but it must be bound to (channel, domain) and reset on each new claim.
2.12 Normalize: lowercase, IDNA to ASCII (punycode), strip the trailing dot. Reject IP literals, single-label names, `localhost`/`.local`/`.internal`/`.arpa`, the platform's own domains, and public suffixes (e.g. `com.br`).
2.13 `group_domains.domain` is globally UNIQUE (schema.pgsql.sql:236). An unverified claim must expire (e.g. 7 days) so a squatter cannot block the real owner. A verified domain moves only after a fresh challenge by the new channel.
2.14 Bounded checks (≤ 1 per minute per domain on demand, hourly cron) with a system resolver timeout (2 s × 2). Reverify on a schedule; on N failures mark it unverified (`GROUP_DOMAIN_UNVERIFIED`, class security) and stop serving the domain.
2.15 Any email-domain auto-join only for verified domains, never for free-mail providers (gmail, hotmail, outlook, yahoo, uol, bol, icloud…), and only into a member role.
**PII / LGPD and logs**
2.16 Members see names and roles only. Emails and phones are visible only to node managers, and no member list or CSV export carries them.
2.17 Logs and ActivityLog payloads carry no email, phone or token (actor_ref HMAC only). Invite before/after values mask email/phone (spec-04 04-2).
2.18 Expired or used invites are purged after 30 days, and revoked ones after 30 days.
2.19 Activity codes: reuse `GROUP_INVITE*`, `GROUP_MEMBER_*`, `GROUP_DOMAIN_*` (class admin; VERIFIED/UNVERIFIED security). New: `CHANNEL_PROPERTY_REMOVE` (admin) and `ROLE_CHANGE` (security), each in `activity_actions` and CLASSES.

## 3. Open Segurança followups (change requests)
3.1 (#242) src/SearchSql.php: `propertyFilters` adds `p.status='active'` only for neighborhoods (:142-143). The city and POI branches (`listingFilters` :117-119, `batch` non-bbox :185-187) and the bbox/map-area branch (:173-183) filter only `s.status` (:112). Fix: start `$where = [self::active('p')]` at :140, drop the duplicate at :143, so every branch checks `p.status` after the join. Test: a property with `listing_search.status='active'` but `properties.status<>'active'` does not appear in the city, POI or bbox searches.
3.2 (#243) src/PhotoStorage.php:42 `$label = (string) $name`. Strip control chars, `<>`, and bidi/zero-width characters, keep the basename, and cap at 100 chars (mb_substr plus "…") before any use in `$rejected` (:48, :60, :65, :69), session, JSON or notice. Test: a 5 KB name containing `\n` and `\u202e` comes back ≤101 chars with no control chars, on web and API.

## Deploy conditions
- The calendar worker runs under its own Unix user and DB role (1.17) with no Valkey secret. The outbound nftables rule allows only 443 plus DNS from that user (1.18).
- The resolver uses `options timeout:2 attempts:2` (dns_get_record has no per-call timeout).
- Remove the `sync-calendars.php` cron lines (prompts/deploy.md:165, :287) in the same deploy that enables the worker.
- `calendar-sync.log` is 0600 in a 0700 dir. The nginx log_format omits query strings and redacts `/ical/` (deploy.md:50).
- Rate-limit buckets for invites and domain verification are live before group invites or domains open in production. The public-suffix list is shipped and pinned.

# CasaLivre — External Ownership Verification & Credit Control Reference Specification

> Grounded strictly in the `develop` branch (`f1e336e`, Schema `41`, PostgreSQL 18+).

## 1. Architecture Overview & Unified Data Model

CasaLivre unifies external OTA ownership verification (`airbnb`, `booking`, `vrbo`) with iCal calendar sync on **`calendar_feeds`** (Schemas 37, 39, 40, and 41), backed by a permanent **`ota_credit_ledger`** table and **`ota_challenge_attempts`** rate-limit log (`database/schema.pgsql.sql`, `migrations/migration_036`..`041`).

| Platform (`source_platform`) | URL ↔ iCal Binding Proof | Post-Challenge Status (`status` / `review_status`) | Credit & Badge Release |
| --- | --- | --- | --- |
| `airbnb` | Automated exact numeric listing ID match (`/rooms/{id}` / `/h/{id}` == `/calendar/ical/{id}.ics`) | `active` / `approved` (`verified_at` set) | Immediate inside `SELECT ... FOR UPDATE` (`ONBOARDING_CREDIT_REWARD = 5`, 1× per `(property_id, platform, reward_type)` and 1× per `(canonical_listing_id, reward_type)`) |
| `booking` | Opaque iCal token → Stronger Manual Proof (admin verifies blocked `challenge_date` on `property_url`) | `active` / `pending_review` (`verified_at` set; badge & credits withheld) → `active` / `approved` or `error` / `rejected` (`verified_at = NULL`) | Only upon admin approve inside `SELECT ... FOR UPDATE` (1× per property+platform and 1× per canonical listing) |
| `vrbo` | Opaque iCal token → Stronger Manual Proof (admin verifies blocked `challenge_date` on `property_url`) | `active` / `pending_review` (`verified_at` set; badge & credits withheld) → `active` / `approved` or `error` / `rejected` (`verified_at = NULL`) | Only upon admin approve inside `SELECT ... FOR UPDATE` (1× per property+platform and 1× per canonical listing) |

### Feed State Lifecycle (`calendar_feeds`)
- `sync_only` mode (`verification_mode = 'sync_only'`): accepts any valid `https://` feed URL (including PMS / Channel Manager links), immediately `status = 'active'`, `review_status = 'approved'`, no verified badge or credits.
- `ota` mode (`verification_mode = 'ota'`):
  - Created as `status = 'pending_verification'`, `challenge_date` set (`72h` expiry on `calendar_feeds` via `challenge_expires_at = $now + 259200`), `review_status = 'pending_review'` for platforms where `requiresAdminReview === true` (`booking`, `vrbo`) or `'approved'` (`airbnb`).
  - Challenge confirmed (`HouseRepository::verifyFeed()`):
    - `airbnb` → `status = 'active'`, `verified_at = to_timestamp($now)`, `review_status = 'approved'`, public badge unlocked, `awardVerificationCreditsIfEligible()` called.
    - `booking` / `vrbo` → `status = 'active'`, `verified_at = to_timestamp($now)`, `review_status = 'pending_review'` (no credits, not returned by `listVerifiedForProperty()` or `hasVerifiedPlatform()`).
  - Admin review (`OtaVerificationService::approveFeed()` / `rejectFeed()`):
    - Approve → `review_status = 'approved'`, `reviewed_by`, `reviewed_at`, badge unlocked, `awardVerificationCreditsIfEligible()` called.
    - Reject → requires non-empty `review_note`, sets `review_status = 'rejected'`, `status = 'error'`, `verified_at = NULL`, `credits_awarded = FALSE`, unlocking URL editing so the host can fix and restart.
  - Passive revocation (`HouseRepository::passiveVerifyFeed()`): `3` consecutive daily HTTP `401`/`403`/`404`/`410` responses (`PASSIVE_DOWNGRADE_FAIL_DAYS = 3`) downgrade `status` to `'pending_verification'` and clear `verified_at = NULL`.

*(Legacy note: pre-Schema-39 table `property_external_verifications` and `OtaVerificationService::checkChallenge()` still exist for legacy tests/compat with `CHALLENGE_TTL_SECONDS = 172800` [48h]; all active web, API, and cron verification flows operate on `calendar_feeds`.)*

---

## 2. Unified Pre-Save Fraud Gate (`OtaPlatformRegistry` + `OtaFeedGate`)

All platform, URL, share-link, uniqueness, and live `.ics` checks run in `OtaFeedGate::prepare()` (`src/OtaFeedGate.php`) and `HouseRepository::addFeed()` / `updateFeed()` **before** persisting `calendar_feeds` rows. Failures raise `OtaFeedGateException($field, $message)` with localized messages (`database/messages.php`, `pt`/`en`/`es`).

### A. Permitted Platform Registry (`src/OtaPlatformRegistry.php`)
- Closed server-side registry (`PLATFORMS`) defining `airbnb`, `booking`, and `vrbo` with exact `public_hosts`, `share_hosts`, `ical_hosts`, `public_path_regex`, `requires_admin_review`, and UI placeholders (`OtaPlatformRegistry::uiMeta()`).
- Host UI (`views/calendar.php`) enforces an explicit `<select name="platform">` (no free text) when `mode = 'ota'`, validating both `property_url` and `external_ical_url` exclusively against the chosen platform.
- Each property is capped at `FEED_SLOTS = 5` feeds (`calendar_feeds.slot BETWEEN 1 AND 5`), and each user is capped at `MAX_DAILY_CHALLENGES_PER_USER = 5` OTA challenges per `24h` (`ota_challenge_attempts`, retained `7` days via `CHALLENGE_ATTEMPTS_RETENTION_DAYS`).

### B. URL Hygiene, Domain Allowlist & Path Validation
- **Protocol & Format (`OtaVerificationService::assertUrlHygiene()` + `CalendarSync::isHttpsUrl()`):** Enforces `https://` only, max length `CalendarSync::MAX_URL_LENGTH` (`2048` bytes), ASCII hostname syntax (`[a-z0-9.-]+`, no `..`), no whitespace or control chars, no `user`/`pass` components (`@` userinfo rejected), and no custom ports (only port `443` or omitted).
- **Exact Host Matching:** Compares `strtolower(parse_url($url, PHP_URL_HOST))` via strict `in_array(..., true)` against `OtaPlatformRegistry::publicHosts()` and `icalHosts()` (never substring `str_contains()` matching). PMS / Channel Manager links (e.g., Stays, Beds24, Hostaway) fail the OTA gate with instructions guiding hosts either to native OTA links or to `sync_only` mode.
- **SSRF & Network Transport (`CalendarSync` + `OtaVerificationService`):** DNS resolution verifies public IPs only (`CalendarSync::isPublicIp()`), pins connections with `CURLOPT_RESOLVE`, disables redirects (`CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_PROTOCOLS_STR = 'https'`), enforces `FETCH_TIMEOUT_SECONDS = 8` and `MAX_ICS_BYTES = 2097152` (2 MB), and requires a valid `BEGIN:VCALENDAR` payload.
- **Listing Path Regexes (`OtaPlatformRegistry::PLATFORMS` / `OtaFeedGate::canonicalize()`):**
  - `airbnb`: public path `#\A/rooms/(\d+)\z#` (canonicalize also supports `/h/{id}`); iCal path `#\A/calendar/ical/(\d+)\.ics\z#`.
  - `booking`: public path `~\A/hotel/([a-z]{2})/([a-z0-9][a-z0-9_-]*)(?:\.[a-z]{2}(?:-[a-z]{2})?)?\.html\z~i`; iCal path `#\A/v1/export\z#i` with non-empty query string or `#\.ics\z#i`.
  - `vrbo`: public hosts include `vrbo.com` & `aluguetemporada.com.br`; public path `~\A/(?:pt-br/)?(?:imovel/[^/?#]+/)?(\d+|p\d+)(?:ha|vb)?\z~i`; iCal path `#\.ics\z#i`.
- **Safe Mobile Share Resolution (`OtaFeedGate::resolveMobileShareUrl()`):** For `abnb.me` (`airbnb`) and `www.booking.com`/`booking.com` paths matching `#\A/(?:Share-[A-Za-z0-9_-]+|share/)#` (`booking`), manually follows at most **2** HTTP `3xx` `Location` hops with `CURLOPT_FOLLOWLOCATION => false`, re-checking public IP, URL hygiene, and the selected platform's exact host allowlist on every hop.

### C. Cross-Link Binding & Metadata Extraction
- **Airbnb ID Match (`OtaFeedGate::canonicalize()`):** Extracts numeric listing IDs from both `property_url` and `external_ical_url` and rejects with `ota.err_airbnb_id` on mismatch.
- **Minimal iCal Metadata (`OtaVerificationService::extractIcsMetadata()`):** While `.ics` content is in memory, extracts only sanitized `PRODID` and `X-WR-CALNAME` (max `200` chars) into `calendar_feeds.ics_metadata` for admin spot-checks. Raw `.ics` files are never persisted or logged.

### D. Canonicalization, Encryption, Global Uniqueness & Multi-Unit Rules
- **Canonical Listing ID:** Strips query strings, trailing slashes, and regional domains into `{platform}:{identifier}` (e.g. `airbnb:12345`, `booking:br/casa-teste`, `vrbo:12345`).
- **Encrypted Feed URL + Hash:** Encrypts the normalized `url` at rest (`CalendarSync::encryptUrl()`) and indexes SHA-256 `url_hash` with partial unique index `uq_calendar_feeds_url_hash` across all feeds globally. Masked URLs (`CalendarSync::maskUrl` / `'https://' . $host . '/••••'`) are returned to UI/API responses so private export tokens are never leaked.
- **Canonical Uniqueness (`OtaFeedGate::assertCanonicalAvailable()` + DB index):**
  - Partial unique index `uq_calendar_feeds_canonical_non_booking` forbids duplicate `canonical_listing_id` across non-Booking platforms (`airbnb`, `vrbo`).
  - `booking` multi-unit pousadas may share one `canonical_listing_id` across multiple units **only when owned by the same `user_id`**. Any attempt by another `user_id`—including via lingering `ota_credit_ledger` entries (`OtaFeedGate.php` checks `user_id IS NOT NULL AND user_id <> $userId`)—is rejected with `ota.err_canonical_other_host`.

---

## 3. Challenge Mechanics & Stronger Manual Proof Flow

### Date-Block Challenge (`OtaVerificationService`)
- **Date Selection (`pickOpenDateForFeed()`):** Scans months `[14, 16]`, falling back to `[3, 6]` then `[1, 4]` (`MAX_DATE_PICK_ATTEMPTS = 10` random picks per window, then deterministic forward scan), avoiding already-pending challenge dates on the property and requiring `D-1`, `D`, and `D+7` open (`isCandidateDateEligible()`).
- **Challenge Check (`HouseRepository::verifyFeed()`):**
  - Rate-limited by `CHECK_COOLDOWN_SECONDS = 60` (`429 Retry-After` in `Api.php`) and `72h` expiry.
  - `isIsolatedManualBlockInIcs()` confirms `challenge_date` is covered by an active opaque `VEVENT`, both `D-1` and `D+1` are free (`isolated === true`), and `SUMMARY` is not a guest reservation (`Reserved` / `Reservado`, case-insensitive).

### Host UI & Pending-Review Lock
- **Pending-Review Link Lock:** While `verification_mode = 'ota'`, `verified_at !== null`, and `review_status = 'pending_review'`:
  - `HouseRepository::feedEditLock()` rejects edits both before `OtaFeedGate::prepare()` and again inside `Db::transactional()` after `SELECT ... FOR UPDATE` (`status => 'pending_review_locked'`, closing TOCTOU races).
  - `views/calendar.php` hides the `.sync-edit-row` form during `pending_review`, renders badge **`Em análise pela equipe`** (`is-review`), and displays the formatted `DD/MM/YYYY` instruction telling the host to keep `challenge_date` blocked until team review completes.
  - `public/assets/pages/calendar-3.js` displays an `info` toast (`Em análise pela equipe`) on `pending_review` instead of success.
- **Approved / Rejected UI:** When `review_status = 'approved'` with a `challenge_date`, `views/calendar.php` shows `cal.verified_unblock_hint` telling the host they may unblock the challenge date. When `review_status = 'rejected'`, `views/calendar.php` shows `Rejeitado` (`is-error`), displays the admin's `review_note`, and re-exposes the edit form so links can be corrected and restarted.
- **Public Visibility (`PublicListing.php` / `OtaVerificationService`):** `listVerifiedForProperty()` and `hasVerifiedPlatform()` strictly filter `verification_mode = 'ota' AND verified_at IS NOT NULL AND review_status = 'approved'`.

### Admin Review Queue (`OtaVerificationService`, `public/index.php`, `src/Api.php`, `views/admin/verificacoes.php`)
Protected by session admin auth (`require_admin()` / `api_require_admin()` returning `401`/`403`), `casa_privado()`, CSRF token verification, and Valkey IP rate limits (`seguranca/api_limites.php`):
- **Queue Queries (`listPendingReviews()`):** Selects `verification_mode = 'ota' AND verified_at IS NOT NULL AND review_status = 'pending_review'`, ordered oldest-first (`ORDER BY f.verified_at ASC, f.id ASC`, backed by `idx_calendar_feeds_pending_review`), joining `properties`, `users`, and `places` (`city_place_id` → `property_city`) with `platform_label`, masked iCal host, `canonical_listing_id`, `challenge_date_br` (`DD/MM/YYYY`), and `ics_metadata`.
- **SSR Routes (`/admin/verificacoes*`) + JSON API Routes (`/api/admin/verifications/*`):**
  - `GET /admin/verificacoes` & `GET /api/admin/verifications/pending` — Strict-CSP UI (`public/assets/pages/admin-verificacoes.js` for approve confirmation + preset rejection reasons, `admin.css` checklist banner, `rel="noopener noreferrer"` public listing link, session flash `admin_verificacoes_error` instead of raw query reflection).
  - `POST /admin/verificacoes/{id}/aprovar` & `POST /api/admin/verifications/{id}/approve` — Inside `SELECT ... FOR UPDATE`, enforces `review_status === 'pending_review' && verified_at !== null` (`already_approved` if already approved, `409 invalid_state` otherwise), updates to `approved`, and calls `awardVerificationCreditsIfEligible()`.
  - `POST /admin/verificacoes/{id}/rejeitar` & `POST /api/admin/verifications/{id}/reject` — Requires non-empty `rejection_reason` / `review_note` (`422 reason_required`, max 500 chars), enforces `pending_review` state inside `FOR UPDATE`, marks `review_status = 'rejected'`, `status = 'error'`, `verified_at = NULL`, `credits_awarded = FALSE`.

---

## 4. Once-Per-Platform Credits & Permanent Anti-Farming Ledger (`Schema 41`)

### Business Rule
Each property may earn `ONBOARDING_CREDIT_REWARD = 5` quote credits **once per native OTA platform** (`airbnb`, `booking`, `vrbo`), and each external listing (`canonical_listing_id`) may generate onboarding credits **at most once across all of CasaLivre**.

### Permanent Ledger (`ota_credit_ledger`, `database/schema.pgsql.sql`)
- `id` (`INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY`)
- `property_id` (`INTEGER NULL REFERENCES properties(id) ON DELETE SET NULL`) — survives property deletion.
- `user_id` (`INTEGER NULL REFERENCES users(id) ON DELETE SET NULL`) — survives user deletion while preserving `canonical_listing_id`.
- `verification_id` (`INTEGER NULL`)
- `platform` (`TEXT NOT NULL CHECK (platform IN ('airbnb', 'booking', 'vrbo', 'other'))`)
- `canonical_listing_id` (`TEXT NULL`)
- `reward_type` (`TEXT NOT NULL DEFAULT 'ota_verification'`)
- `credits` (`INTEGER NOT NULL CHECK (credits > 0)`)
- `awarded_at` (`TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp()`)
- **Dual Partial Unique Indexes:**
  1. `uq_ota_credit_ledger_property ON ota_credit_ledger (property_id, platform, reward_type) WHERE property_id IS NOT NULL` — caps payouts at 1× Airbnb, 1× Booking.com, and 1× Vrbo per CasaLivre property even across link deletions/edits.
  2. `uq_ota_credit_ledger_canonical ON ota_credit_ledger (canonical_listing_id, reward_type) WHERE canonical_listing_id IS NOT NULL` — caps payouts at 1× per external listing across deleted/recreated properties, multi-unit Booking.com pousadas, and secondary accounts.

### Race-Safe Idempotent Payout Helper (`OtaVerificationService::awardVerificationCreditsIfEligible()`)
Called inside the caller's `Db::transactional()` transaction after `SELECT ... FOR UPDATE` locks the updated source row (`verifyFeed()`, `approveFeed()`, and legacy `checkChallenge()`):
1. Validates eligible completed state (`verification_mode = 'ota'`, `verified_at IS NOT NULL`, `review_status = 'approved'`, `credits_awarded = FALSE` for `calendar_feeds`; `status = 'verified'`, `credits_awarded = 0` for legacy `property_external_verifications`) and `OtaPlatformRegistry::isAllowed($platform)`.
2. Resolves owner `user_id` from `properties`.
3. Executes PostgreSQL `INSERT INTO ota_credit_ledger (...) VALUES (...) ON CONFLICT DO NOTHING`.
4. **Only when `rowCount() === 1`:** increments `users.quote_credits += ONBOARDING_CREDIT_REWARD`, sets `credits_awarded = TRUE` (or `1`), and invokes `self::$creditHook` if present; otherwise returns `false` without throwing so trust badges remain unaffected.
5. On URL/link updates (`HouseRepository::updateFeed()`), `credits_awarded = CASE WHEN source_platform = ? THEN credits_awarded ELSE FALSE END` preserves `credits_awarded` on same-platform resets (returning `0` new credits on re-verify) while allowing first-time credit checks if the host switches slot platform (still guarded by `uq_ota_credit_ledger_property`).

---

## 5. Audit, Privacy & Test Coverage Summary
- **Pipeline Security (`public/index.php`):** `campos_puros` preserves raw URLs/tokens (`external_ical_url`, `property_url`, `platform`, `mode`, `consent`, `public_url`, `ical_url`); `casa_privado()` enables sessions for authenticated `/imoveis/*/ical*`, `/admin/*`, `/api/admin/*` routes.
- **Audit Logging (`ActivityLog` + `CalendarSync::logRef()`):** Records `OTA_VERIFY_START`, `OTA_VERIFY_VERIFIED`, `OTA_VERIFY_DOWNGRADED`, `OTA_VERIFY_REMOVE`, `OTA_FEED_EDIT`, `OTA_REVIEW_PENDING`, `OTA_REVIEW_APPROVE`, `OTA_REVIEW_REJECT`, storing redacted iCal URL refs (`host#sha256_12`) and HMAC-pseudonymized `verification_ip`.
- **Automated Verification Suites (`tests/`):**
  - Unit: `tests/Unit/OtaUrlBindingTest.php`, `tests/Unit/OtaVerificationServiceTest.php`, `tests/Unit/IcalFeedLifecycleTest.php`, `tests/Unit/CalendarPageTest.php` (run via `ops/dev-env/test-unit.sh`).
  - Standalone PostgreSQL / HTTP / Deploy contracts: `tests/standalone/ical_routes_test.php`, `tests/standalone/places_schema_test.php`, `tests/standalone/incus_stack_test.php` (run via `ops/dev-env/test-pg.sh`).

# CasaLivre Autonomous Factory & Coding Specifications

## 1. Context & Operational Limits
- **Minimal Verbosity:** Do not explain the code or summarize actions in prose. Return only:
  1. Modified files list.
  2. Static analysis result (Pass/Fail).
  3. Test suite output (assertions, failures, duration).
- **Surgical Inspection:** Use line-targeted file reads (`StartLine`/`EndLine`) or `grep` (or `incus exec <container> -- sed -n 'X,Yp'` / `grep -n` when files live inside an Incus container). Never read entire files >150 lines into context.
- **No Redundant Full-Repo Scans:** Rely on Section 7 below to avoid broad repository discovery scans. When changing or adding features, perform targeted surgical inspections in `/srv/dev/casalivre` until certain.
- **Diffs Only:** Make surgical line changes; never regenerate entire source files.
- **Diff-Scoped Static Analysis:** Fix ONLY typing or linting errors introduced in your changes. Do not refactor surrounding untouched code.

## 2. Test Retention & Historical Immutability
- **Read First:** Prior to editing `src/<Path>/<File>.*`, inspect matching tests in `tests/Unit/`, `tests/Integration/`, `tests/standalone/`, and `tests/e2e/`. Existing tests are the authoritative baseline.
- **Preserve Existing Tests:** Never delete, disable, or overwrite existing test methods.
- **Append Only:** Add new scenarios and edge cases to the bottom of the test file.
- **Documented Deprecation:** If a change intentionally alters an established business behavior, update the test assertion and add an inline comment explaining the date and requirement change:
  `// Updated: YYYY-MM - Requirement changed from X to Y`

## 3. Database & Environmental Isolation
- **Environment Targeting & Test Runners:** All commands must run under `APP_ENV=testing`. Always prefer the rootless `ops/dev-env/` wrappers (`ops/dev-env/test-unit.sh` for PHPUnit with PHP 8.4 + `casalivre_retention` + Node.js = 0 skips; `ops/dev-env/test-pg.sh <file_test.php ...>` for standalone DB tests) rather than bare host `php` (not installed) or `app-php` (which lacks Node.js and `casalivre_retention`). Ensure `ops/dev-env/up-bwrap.sh -d` is up before calling `test-pg.sh`; `test-unit.sh` starts `up-bwrap.sh -d` automatically if needed.
- **Database Transactions:** Unit/Repository tests must extend `DatabaseTestCase` (or equivalent transaction wrapper) and rely exclusively on transaction rollbacks. Never issue manual `COMMIT` statements in unit tests.
- **Minimal Graph Fixtures:** Use factory fixtures/DTOs for minimal valid models. Do not rely on mutable global seeders or deep mocks for plain data containers.
- **Deterministic Clocks:** Never use raw `time()` or `now()`. Inject `ClockInterface` or use a test clock stub to control time progression.
- **Network Boundaries:** Do not make external API or HTTP requests in test suites. Use local mock/null transports.
- **Standalone Test Template DB (`tests/standalone/lib.php`):** Standalone DB tests must call standard `t_temp_db()`, which transparently caches a content-hashed PostgreSQL template (`casalivre_t_tpl_<hash>` computed from `schema.pgsql.sql`, seed PHP files, `messages.php`, and place `.json`/`.bz2` archives) and clones from it in ~1s with `synchronous_commit = off`. Only tests that explicitly verify cold bootstrap seeding (`CityRepository::ensure()` / initial migration) may pass `t_temp_db(false)`. Never manually manage or duplicate template logic in tests; set `T_PG_REBUILD_TEMPLATE=1` only to force a rebuild, run targeted tests first during iteration, and omit slow cold-bootstrap standalone tests (`places_schema_test.php`, `places_pois_test.php` ~3 min each) unless schema DDL, `CityRepository`, or place seed files were actually modified.
- **Schema Changes (Always Create & Apply Incremental Migration):** Whenever a change modifies `database/schema.pgsql.sql` or bumps `Db::SCHEMA_VERSION`, **always** create a matching idempotent incremental migration in `database/migrations/migration_<NNN>_<slug>.sql` (updating `schema_meta`), update `deploy/incus/scripts/04-jumpstart-app.sh` and `tests/standalone/incus_stack_test.php`, and apply it to the running database (`incus exec db-pg -- su - postgres -c "PGHOST=/run/postgresql psql -v ON_ERROR_STOP=1 -d casalivre" < database/migrations/<migration>.sql` + `php scripts/migrate.php`, verifying `schema_meta(skey='schema_version')` matches `Db::SCHEMA_VERSION`) without asking the user first.

## 4. Execution Pipeline & Circuit Breaker
When implementing any task, follow this sequential loop:
1. **Failing Test (TDD):** Append the failing test assertion first. Verify it fails with the expected assertion error.
2. **Minimal Implementation:** Write only the code required to make the test pass.
3. **Deploy Script Verification:** For every change in code (migrations/tables, workers/crons, env vars, runtime directories, assets, or routes), verify whether the deploy scripts (`deploy/incus/`, `deploy/runit/`, `deploy/infra-nginx-hardened/`) and their contract tests (`tests/standalone/incus_stack_test.php`, `tests/standalone/runit_services_test.php`) need to be updated.
4. **Tier 1 (Static Analysis):** Run typechecks/linters on touched files only. If it fails, fix types immediately before running runtime tests.
5. **Tier 2 (Targeted Unit / Standalone Test):** Run the corresponding test file(s).
6. **Tier 3 (Targeted E2E / Snapshots):** If UI or API contract changed, run the matching E2E spec in `tests/e2e/` and retain snapshots in `tests/standalone/snapshots/`.
7. **Circuit Breaker (Rule of 2):** Maximum TWO (2) remediation passes if tests fail. If still failing on pass 2, revert your changes, halt execution, and output the exact error trace.
- **Git Sandbox Hygiene:** Clean up any untracked temporary files or test artifacts before finishing.

## 5. Git Branching Strategy & Lifecycle
- **Base Branch:** All feature and bugfix tasks must originate from local `develop` (whenever the user says `dev`, it always means `develop`; there is no `dev` branch, only `develop`). Never commit directly to `main` and never pull from or push to remote `origin`.
- **Branch Naming:** Create a dedicated branch for every separate development:
  - `feature/<kebab-case-name>` for new capabilities or changes.
  - `fix/<kebab-case-name>` for bug fixes.
- **Task Lifecycle:**
  1. Verify clean state and branch off local `develop`: `git checkout -b <type>/<task-slug> develop`.
  2. Execute the TDD + Verification Loop (Steps 1–6).
  3. On completion, commit atomically: `<type>(<scope>): <concise description>` with body `Intent: <what this change fixes or enables>.`.
  4. Merge the task branch back into local `develop` only upon user approval.
  5. Merging to `main` occurs strictly upon explicit user approval.

## 6. CasaLivre Architecture & Workspace Topology
- **Host Git Repository:** `/srv/dev/casalivre` on the host is the primary git repository for CasaLivre.
- **3-Container Incus Stack:**
  1. `nginx-prod-green` (Edge tier on `incusbr-dmz`: `10.10.10.11`, Alpine Linux).
  2. `app-php` (Application tier on `incusbr-dmz`: `10.10.10.20` and `incusbr-int`: `10.10.20.11`, Void Linux glibc + runit, PHP-FPM + Valkey + workers).
  3. `db-pg` (Database tier on `incusbr-int`: `10.10.20.10`, air-gapped Void Linux glibc + runit, PostgreSQL).

## 7. CasaLivre Coding Standards & Architectural Patterns
- **PHP Conventions (`PHP 8.2+ / 8.4`):**
  - All files start with `declare(strict_types=1);`. No PSR-4 autoloader for `src/`: load dependencies with `require_once __DIR__ . '/...'`.
  - Domain services/repositories live in `src/<Name>.php` (`final class`). Use-case actions live in `src/Acoes/<Action>.php`, validate untrusted scalars with `TextoBruto::ok()` / `InteiroBruto`, and return `Resultado` (`src/Acoes/Resultado.php`: `$res->tipo`, `$res->mensagem`, `$res->dados`, `$res->eventos`, `$res->registrar()`).
- **PostgreSQL & PDO Layer (`src/Db.php`, `database/schema.pgsql.sql`, `database/migrations/`):**
  - **PostgreSQL 18+ only** (`db()` / `Db::pdo()`, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`). Never use MySQL types (`DATETIME`, `TINYINT(1)`, `AUTO_INCREMENT`); use `TIMESTAMPTZ` / `TIMESTAMP`, `DATE`, `INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY`, `SMALLINT`, `JSONB`, `TEXT`, and explicit `CHECK` constraints.
  - Wrap multi-step writes in `Db::transactional(db(), static function (PDO $pdo) { ... })` (uses `SAVEPOINT` when nested).
  - `Db::SCHEMA_VERSION` in `src/Db.php` must match `schema_meta(skey='schema_version')`. Web requests only check `Db::ensureSchemaCurrent()` (503 `SchemaDesatualizado` if behind); `scripts/migrate.php` runs under `pg_advisory_lock` and seeds `Settings`, `I18n`, `CityRepository`, and `ActivityLog`.
  - Runtime DB user is `casalivre_app` (non-superuser, routed via local PgBouncer `127.0.0.1:6432` on `app-php` to `db-pg` `10.10.20.10:5432`).
- **Routing, Security Pipeline & Rate Limiting (`public/index.php`, `src/Api.php`, `seguranca/`):**
  - `public/index.php` is the single front controller (`Seguranca::pipeline(...)` -> `dispatch()` for web routes or `handle_api($path, $method)` in `src/Api.php` for `/api/*`).
  - **`campos_puros` (`public/index.php`):** `Seguranca::pipeline` sanitizes input strings unless the field name is in `'campos_puros'` (e.g., `password`, `password_confirm`, `_csrf`, `external_ical_url`, `google_place_id`). Add any new field carrying raw URLs/tokens to `'campos_puros'`.
  - **`casa_privado($path, $method)` (`public/index.php`):** Must return `true` for authenticated web/API routes so `SecureSession::start()` runs.
  - **CSRF & Ownership:** Non-`GET`/`HEAD` `/api/*` calls check `_csrf` / `HTTP_X_CSRF_TOKEN` in `handle_api()` (HTTP 419 on mismatch); web POSTs call `require_user()` and `csrf_check()`. Properties use integer `id` internally and 16-hex `public_hash` (`property_ref($house)`) externally; verify ownership with `HouseRepository::findManageableOrFail((int) $user['id'], $propertyId)` or `api_owned_property($user, $hash)`. Never leak PII (`ical_hash`, raw private `ical_url`, `pix_key`, street address) in `src/PublicListing.php`.
  - **Rate Limits (`seguranca/api_limites.php`):** Per-IP limits `[method, path_regex, max, window_s, bucket]` enforced by `IpRateLimiter` on Valkey (`/run/casalivre-valkey/valkey.sock`, user `cl_app`, key prefix `cl:*`).
- **Outbound HTTP, iCal & Test Hooks (`src/CalendarSync.php`, `src/helpers/ical.php`, `src/GooglePlacesService.php`, `src/OtaVerificationService.php`):**
  - Enforce HTTPS port 443 (`CalendarSync::isHttpsUrl()`), DNS resolution + private/loopback/CGNAT IP rejection (`CalendarSync::resolveTarget()`, `CalendarSync::isPublicIp()`), `CURLOPT_RESOLVE` pinning, manual redirect re-validation, strict timeouts, and byte limits.
  - Services with external HTTP or time dependencies expose `public static ?Closure $httpTransport = null;` and `public static ?Closure $clock = null;` for zero-network deterministic unit tests.
- **Money Handling & Pricing (`src/helpers/money.php`, `src/QuoteGenerator.php`, `src/QuoteRepository.php`, `src/AgentApi.php`, `src/PixPayload.php`):**
  - All monetary columns (`properties.{nightly_price,cleaning_fee}`, `calendar_days.price`, `quotes.{nightly_price,cleaning_fee,taxes,special_offer,total_price}`, `booking_intents.{nightly_price,cleaning_fee,total_price,amount_due_now,balance_due}`) store **whole BRL reais as `INTEGER`** (legacy `money_unit` conversion was removed in schema 18). Never introduce float columns or `* 100` / `/ 100` centavos conversions against existing tables.
  - Validate scalar types (`TextoBruto::ok()`, `HouseService::typeError()`) before parsing with `parse_money(string $raw): int`, and enforce explicit bounds afterward (`1..999999` for `nightly_price` and calendar `price`, `0..999999` for `cleaning_fee`; `parse_money()` returns `0` on invalid input or non-zero cents).
  - Compute quotes and payment splits server-side inside `Db::transactional(...)` using `intdiv()` + subtraction remainder for splits (`sinal_50`, `cartao_12x`) so `amount_due_now + balance_due = total_price` holds exactly. Derive `PixPayload::generate()` amounts strictly from server-side quote/`booking_intents` state, format display values via `money()`/`money_brl()`/`CLMapData.brl()`, and log price mutations via `activity(...)`.
- **Audit Logging (`src/ActivityLog.php`, `src/RequestLog.php`):**
  - All actor actions (host, guest, admin, manager) and DB value changes must be logged via `activity(ActivityLog::<CODE>, $params, $old, $new)`.
  - Register new codes in `ActivityLog::CATALOG`, `ActivityLog::CLASSES` (`ADMIN` -> `audit.audit_log`, `SECURITY` -> `audit.activity_log` via `LOG_DB_USER`, `OTHER` -> shutdown buffer), `ActivityLog::ALLOWED_PARAMS`, and `ActivityLog::VALUE_KEYS`.
  - Redact sensitive URLs in logs with `CalendarSync::logRef($url)` (`host#sha256_12`).
- **Internationalization (`database/messages.php`, `src/I18n.php`):**
  - All user-facing strings use `t('key')` and must be added to `database/messages.php` for `'pt'` (`pt-BR`), `'en'`, and `'es'`.
- **Frontend Views, Assets & Strict CSP (`views/`, `public/assets/`, `scripts/build-assets.php`):**
  - Responsive desktop + mobile views in `views/*.php`, escaped with `e(...)`.
  - **Strict CSP (`script-src 'self'; style-src 'self'`):** Never use inline `<script>`, inline `on*` handlers, or inline `style="..."` attributes. Pass data via `page_data([...])` (`CL.data()`) or `data-*` attributes. Place styles in `public/assets/app.css` and scripts in `public/assets/pages/<page>.js` (`CL.api(...)` sends `X-CSRF-Token`). Rebuild `manifest.json` and `.gz` assets with `php scripts/build-assets.php`.
- **Deploy, Runit & Contract Tests (`deploy/incus/`, `deploy/runit/`, `tests/standalone/`):**
  - CLI/cron scripts in `scripts/*.php` use `QueueWorker::openLock($root . '/storage/<name>.lock')` with non-blocking `flock`.
  - Runit services in `deploy/runit/casalivre-<name>/{run,finish,log/run}` (`0755`) must use a single `exec "${CASALIVRE_RESOLVE:-/usr/local/sbin/casalivre-resolve}" ...` in `finish` and `log/run` (tested by `tests/standalone/runit_services_test.php`).
  - `deploy/incus/scripts/04-jumpstart-app.sh` provisions `/srv/app` (`root:appuser` read-only, `/srv/app/storage/*` writable by `appuser`), applies schema/migrations, installs runit workers, syncs `/srv/static` to edge, and runs one-shot checks; `deploy/incus/scripts/verify-stack.sh` and `tests/standalone/incus_stack_test.php` verify stack contracts.

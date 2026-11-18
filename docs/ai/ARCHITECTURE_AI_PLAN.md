# CasaLivre — implementation spec for agent and crawler access

Status: specification only. Inspected 2026-11-18 from the live tree in Incus `void-musl` at commit `63b7550` (`develop`). This file replaces the previous plan in the same path. It does not change code, schema, or Nginx.

`/srv/casalivre` is a symlink to `/home/rafael/factory/casalivre`. This file is at the repository root, outside `public/`. Nginx will not serve it.

The task names `/imovel/:slug`, `action_log`, and a booking table. Those names are not in this stack. The sections below use the real names and say where the task’s names land.

## 1. Live stack

| Piece | What is running |
| --- | --- |
| App | PHP 8.4.22. Front controller `public/index.php`. Paths under `/api` go to `handle_api()` in `src/Api.php`. |
| Security | `seguranca/Seguranca.php`, outside in: `ApiRateLimit` (rules in `seguranca/api_limites.php`, counts in Valkey via `IpRateLimiter`), `BodyLimit`, `Csrf`, login lock, `casa_privado()`. Headers: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, CSP from `csp_header()` (`script-src 'self'`, no `unsafe-inline`). Every non-GET under `/api` also checks session CSRF inside `handle_api()`. `sem_csrf` is empty. |
| HTTP | Nginx port 80, `root /srv/casalivre/public`, unknown `Host` returns 444. `try_files $uri /index.php`. A file on disk under `public/` never reaches PHP. PHP-FPM pool `casalivre`, socket `/run/php-fpm-casalivre.sock`. `php-redis` is loaded. APCu is not. Workers do not share memory. |
| Data | PostgreSQL 18.6, database `casalivre`, `Db::SCHEMA_VERSION = 32`. Valkey on `/run/casalivre-valkey/valkey.sock` (`VALKEY_SOCKET`, ACL user, password file). |
| Workers | Runit: queue, calendar (`CalendarWorker`, `pcntl_signal(SIGTERM)`), mail, image, log retention, valkey. |

Money is a whole-currency integer (BRL), not cents. `nightly_price` and `cleaning_fee` on `properties`. `QuoteGenerator::calculate()` and `stay_quote()` in `src/helpers/money.php` are the only price functions agents may call.

### 1.1 Tables that matter

There is no `action_log` table and no `bookings` table. `\dt` on the live database shows the public tables plus `audit.activity_log*` (owner `casalivre_retention`).

`properties` (public listing identity and the fields that must not leak):

- `public_hash` — 16 hex, unique. This is the public id (`property_ref()`). There is no slug column.
- `title`, `description`, `summary`, `city_place_id`, `neighborhood_place_id`.
- `address`, `cep`, `street`, `address_number`, `address_complement` — exact street. Never in a bot, crawler, JSON-LD, or markdown payload.
- `lat`, `lng` — stored exact. Public map pins are offset with `GEO_SALT`. Agent and JSON-LD payloads omit coordinates.
- `bedrooms`, `beds`, `rooms`, `max_guests`, `min_nights`, `checkin_time` (default `15:00`), `checkout_time` (default `11:00`).
- `nightly_price`, `cleaning_fee` — whole BRL.
- `amenities` JSON shaped by `Amenities::GROUPS`.
- `pix_key` — host receipt key. Never in a payload sent before a human confirms a stay.
- `ical_hash` (32 hex), `external_ical_url` — unlisted calendar capability. Never returned to agents.
- `status` `active` or `inactive`. `group_id` for vitrines.

`users.cpf`, `users.phone`, `users.email`, `users.address` exist. None of them are read by the agent functions in this spec.

`calendar_days` is the availability fact: `(property_id, day)` primary key, `blocked`, `price`, `min_nights`, `origin`, `feed_id`. Inbound iCal (`import_ics()`) writes `origin = 'sync'` for block-like summaries and `origin = 'reserva'` otherwise, skipping UIDs that start with `casalivre-{propertyId}-`. Outbound export is `export_ics()` at `GET /ical/{ical_hash}` (32 hex) and `GET /api/ical/{ical_hash}`. `GET /imoveis/{hash}/calendario.ics` is a hard 404. Feeds live in `calendar_feeds` (at most 5 per property) and are pulled by `CalendarWorker`. Agents read `calendar_days` through `QuoteRepository::checkAvailability()`. They do not receive feed URLs and they do not call `export_ics()`.

`quotes` is the human checkout row: `quote_hash` (16 hex), dates, whole-BRL totals, `status` default `pending`, `expires_at` set to 30 minutes in `QuoteRepository::create()`, and a `pix_key` column. `confirmPayment()` is what inserts `calendar_days` with `blocked = 1`, `origin = 'reserva'`, and sets `status = 'confirmed'`. `checkAvailability()` treats any `blocked = 1` row as taken. When confirming an existing quote it ignores `origin = 'pre-reserva'`. Nothing in the tree writes `pre-reserva`. Pending quotes do not block the calendar. `views/payment.php` renders the quote’s `pix_key` and a Pix copia-e-cola payload while the quote is still pending. That is the leak this spec closes for agent checkouts.

`audit.activity_log` is the human and security audit trail. Append-only, list-partitioned by `retention_class` (`security`, `other`) then by day. `actor_kind` is `user`, `anon`, or `system`. `actor_ref` is a 32-byte HMAC of the user id under stable `LOG_SALT`. There is no IP column. Payload cap is 4096 bytes. PHP only inserts. This spec does not add a column, a code, or a bot row to it.

`listing_search` is the search read model. `SearchService::search(SearchQuery, $host)` is the only search entry. Page size is `SearchService::PAGE_SIZE` (20).

### 1.2 Public surface today

- `GET /api/busca` — `SearchQuery::fromRequest($_GET)`. Parameters: `localidade`, `local_id`, `checkin`, `checkout`, `hospedes`, `raio` (cap 20), `preco_min`, `preco_max`, `score_min`, `amenidade[]`, `grupo`, `sort` (`relevance` or `rating`), `pagina` or `page`, `cursor`.
- `GET /api/imoveis/{public_hash}` — public listing. Includes title, description, summary, city, neighborhood (from CEP, not street), ready photos (`path`, `is_primary`, `sort_order`), score, optional `quote` from `stay_quote()`, amenity keys, capacity, times, `cleaning_fee`, `blocked_days`. Runs `GrupoResolvedor::imovelNoHost`. Writes `ActivityLog::PROPERTY_VIEW`. This is the fact source for JSON-LD and markdown.
- `api_public_house()` is the owner shape. It includes `user_id` and `address`. Agents must not call it.
- `GET /imoveis/{public_hash}` renders `views/property.php` with `use_api = true`, which prints “Carregando…”. `public/assets/pages/property.js` fills the page from the API. Crawlers that do not run JavaScript see the shell.
- `POST /api/imoveis/{hash}/reservar` creates a 30-minute quote and returns `redirect: /pagamento/{quote_hash}`. It requires a session CSRF token. Agents must not call it.
- Group hosts (`/g/{slug}`, custom domains) only show that group’s active listings (`SomenteVitrine`, `GrupoResolvedor`).

`IpRateLimiter::FAIL_CLOSED_BUCKETS` is only `/senha/esqueci`, `/cadastro`, and `ativacao-reenvio`. Every other bucket fails open when Valkey is down. The new intent bucket is added to the fail-closed list so a limiter outage cannot mint holds.

## 2. Discovery layer

`robots.txt` and `llms.txt` are static files under `public/`. Nginx `try_files` serves them. `llms-full.txt` and `openapi.json` are PHP routes. They must not exist as files under `public/`. Nginx gets an exact location for each that `fastcgi_pass`es to `index.php`; otherwise `try_files` would serve a stale file and hide the route.

### 2.1 `public/robots.txt`

```text
User-agent: OAI-SearchBot
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: Google-Extended
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: *
Allow: /
Disallow: /admin
Disallow: /conta
Disallow: /painel
Disallow: /entrar
Disallow: /cadastro
Disallow: /senha/
Disallow: /pagamento/
Disallow: /reservas
Disallow: /ical/
Disallow: /api/casas
Disallow: /api/users
Disallow: /api/sessao
Disallow: /api/auth
Disallow: /mcp
```

`/mcp` and `/pagamento/` are disallowed because they are actions, not documents. `/api/v1/agent/` stays allowed so a crawler can see the tool surface described by `llms.txt`. No `Sitemap:` line until a sitemap route exists.

### 2.2 `public/llms.txt`

```text
# CasaLivre

CasaLivre lists seasonal rentals in Brazil. Prices are whole reais (BRL), not cents.

Canonical listing: https://{host}/imoveis/{public_hash}
{public_hash} is 16 lowercase hex characters. There is no slug.

Full catalog (active listings only): /llms-full.txt
Machine API: /openapi.json
Search: /api/v1/agent/search
One listing: /api/v1/agent/properties/{public_hash}
Quote (no hold): /api/v1/agent/properties/{public_hash}/quote
Hold for 15 minutes: POST /api/v1/agent/properties/{public_hash}/intent
MCP: POST /mcp

A hold returns a checkout URL. It does not confirm a stay, charge a card, or block the calendar.
Do not request host CPF, the host Pix key, the street address, or an iCal URL. They are not in these responses.
Group domains show only that group's listings.
```

`{host}` is the request `Host` when a later PHP version of this file is generated. The static file uses the public site host the operator configures. Do not hard-code a Tailscale or LAN address from the Nginx `server_name` list.

### 2.3 `GET /llms-full.txt`

Dispatched in `public/index.php` before the `/api` branch. `Content-Type: text/plain; charset=utf-8`. `Cache-Control: public, max-age=300`.

Built by paging `SearchService::search()` with an empty `localidade` is not valid (search wants a place). The generator iterates active cities that have at least one `listing_search` row (`status = 'active'`), 20 listings per page, and emits one block per listing:

```text
## {title}
url: /imoveis/{public_hash}
city: {city}
neighborhood: {neighborhood or -}
guests: {max_guests}
bedrooms: {bedrooms}
beds: {beds}
nightly_brl: {nightly_price}
cleaning_brl: {cleaning_fee}
min_nights: {min_nights}
checkin: {checkin_time}
checkout: {checkout_time}
amenities: {comma-separated keys}
summary: {summary one line, newlines stripped}
```

No street, CEP, coordinates, Pix, CPF, email, phone, or `ical_hash`. Cap the response at 500 listings and append `truncated: true` plus the city cursor when more exist. Add the path to `head_permitido()`.

### 2.4 `GET /openapi.json`

`Content-Type: application/json`. OpenAPI 3.1 document. The body is the YAML in §4.4 rendered as JSON. It describes only `/api/v1/agent/*`. It does not describe `/api/casas` or session login. `info.version` is `1.0.0`. Servers entry is the request scheme and host, path `/`.

## 3. Semantic web

### 3.1 JSON-LD

On `GET /imoveis/{public_hash}` (and `/g/{slug}/imoveis/{hash}` after `GrupoResolvedor::imovelNoHost` returns the house), build the public listing array with the same function as `GET /api/imoveis/{hash}`. Pass it to `views/layout.php` as `$json_ld`. The layout prints it in `<head>`:

```html
<script type="application/ld+json">{json}</script>
```

Encode with `JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`, the same flags as `page_data_script()`. The type is not JavaScript. Do not add `'unsafe-inline'` to `csp_header()`.

The visible body may stay the “Carregando…” shell. The JSON-LD may not wait for `property.js`.

```json
{
  "@context": "https://schema.org",
  "@type": "VacationRental",
  "name": "{title}",
  "description": "{description}",
  "url": "https://{host}/imoveis/{public_hash}",
  "identifier": "{public_hash}",
  "address": {
    "@type": "PostalAddress",
    "addressLocality": "{city}",
    "addressRegion": "{uf or empty}",
    "addressCountry": "BR"
  },
  "containsPlace": {
    "@type": "Accommodation",
    "occupancy": { "@type": "QuantitativeValue", "value": 0, "maxValue": "{max_guests}" },
    "numberOfBedrooms": "{bedrooms}",
    "numberOfBeds": "{beds}"
  },
  "checkinTime": "{checkin_time}",
  "checkoutTime": "{checkout_time}",
  "image": ["{ready photo paths, primary first}"],
  "amenityFeature": [{ "@type": "LocationFeatureSpecification", "name": "{amenity key}", "value": true }],
  "offers": {
    "@type": "Offer",
    "priceCurrency": "BRL",
    "price": "{nightly_price}",
    "priceSpecification": [
      { "@type": "UnitPriceSpecification", "price": "{nightly_price}", "priceCurrency": "BRL", "unitText": "NIGHT" },
      { "@type": "UnitPriceSpecification", "price": "{cleaning_fee}", "priceCurrency": "BRL", "unitText": "CLEANING" }
    ]
  }
}
```

`aggregateRating` is added only when `rating_count > 0`: `ratingValue` from `HouseRepository::score()`, `bestRating` 10, `reviewCount` = `rating_count`. Reviews are stored 1–10. Do not rescale.

Neighborhood, when `house_neighborhood()` returns one, is `address.streetAddress` omitted and a `containedInPlace` name set to the neighborhood. Do not put `street`, `address_number`, `cep`, `lat`, `lng`, `pix_key`, or any `users` column into this object.

### 3.2 `Accept: text/markdown`

On the same GET, if `Accept` contains `text/markdown` with a q greater than or equal to the q of `text/html` (missing `Accept` means HTML), skip `view('property')` and return:

- `Content-Type: text/markdown; charset=utf-8`
- `Vary: Accept`
- Body: title, city, neighborhood, guests, bedrooms, beds, nightly BRL, cleaning BRL, min nights, check-in, check-out, amenity keys, summary, and the canonical HTML URL.

No street, Pix, CPF, or iCal URL. One `ActivityLog::PROPERTY_VIEW` is not written for the markdown response. Bot hits go to `bot_action_logs` (§5). Human HTML hits stay on the existing activity path and do not enter `bot_action_logs`.

Add this GET to `head_permitido()` only for the HTML and markdown representations. `HEAD` must not write either log.

The path `/imovel/:slug` is not registered. `llms.txt` documents `/imoveis/{public_hash}` as the only listing URL.

## 4. Agent tools

New handler `handle_agent()` called from `dispatch()` for `/api/v1/agent` and for `/mcp`. It runs inside the existing `Seguranca` pipeline (body limit and rate limit still apply). It does not use `handle_api()`, so it does not inherit the blanket session CSRF check. Only these paths are added to `sem_csrf`:

- `POST /api/v1/agent/properties/{public_hash}/intent`
- `POST /mcp`
- `GET /mcp`

Every existing write keeps session CSRF.

Reads are anonymous. The intent does not confirm, charge, or write `calendar_days`.

### 4.1 `search_properties`

`GET /api/v1/agent/search`

Query string is the same set as `GET /api/busca`. Implementation: `SearchQuery::fromRequest($_GET)`, then if `GrupoResolvedor` says this host is a vitrine, force `grupo` to that slug. `SearchService::search($query, $host, true, $after)`. Response is the `/api/busca` JSON (`items`, `facets`, `next_cursor`, `total_aprox`, `page`, `per_page`) with each item reduced to: `id` (public hash), `title`, `city`, `nightly_price`, `max_guests`, `bedrooms`, `photo` (ready primary path or null). Drop address, coordinates, and owner fields if `formatMapItems()` includes them.

`RequestLog::setBusca()` stays so the existing API log still has iteration counts. This route must not call `ActivityLog::record()`. Bot rows are §5 only.

Errors: `400` invalid cursor (`InvalidSearchCursor`), `503` `SearchTimeout`.

### 4.2 `get_property_details`

`GET /api/v1/agent/properties/{public_hash}`

Same array as public `GET /api/imoveis/{hash}` after a shared `public_listing(array $house, string $checkin, string $checkout): array` extracted from that branch. 404 when missing, inactive, or off-vitrine. Does not call `ActivityLog::PROPERTY_VIEW`.

### 4.3 `check_availability_and_quote`

`GET /api/v1/agent/properties/{public_hash}/quote?checkin=YYYY-MM-DD&checkout=YYYY-MM-DD&hospedes=N`

Resolve the active on-vitrine property. Call `QuoteRepository::checkAvailability($id, $checkin, $checkout)` with no quote hash, so every `blocked = 1` night counts, including iCal sync nights. Then the same min-nights and per-night price loop as `QuoteRepository::create()`, then `stay_quote()` / `QuoteGenerator::calculate()`. Insert nothing.

`200`:

```json
{
  "available": true,
  "checkin": "2026-12-01",
  "checkout": "2026-12-05",
  "nights": 4,
  "nightly_price": 200,
  "cleaning_fee": 80,
  "total": 880,
  "currency": "BRL",
  "plans": [
    { "id": "pix_a_vista", "amount_due_now": 880, "balance_due": 0, "installments": 1 },
    { "id": "sinal_50", "amount_due_now": 440, "balance_due": 440, "installments": 1 },
    { "id": "cartao_12x", "amount_due_now": 880, "balance_due": 0, "installments": 12, "installment_amount": 74, "installment_remainder": 66 }
  ]
}
```

`amount_due_now` for `sinal_50` is `intdiv(total, 2)`. `balance_due` is `total - amount_due_now`, so the two sum to `total`. For `cartao_12x`, `installment_amount` is `intdiv(total, 12)` and `installment_remainder` is `total - installment_amount * 11`, applied to the last installment. All figures are whole BRL.

`409` when `available` is false, body `{ "available": false, "conflict_date": "YYYY-MM-DD" }`. `422` for a short stay or inverted dates, using the existing `InvalidArgumentException` message from the quote path. No `quotes` row and no `booking_intents` row.

These three plans are not implemented in the checkout today. `docs/produto/planejamento/pagamentos.md` still discusses a 20–30% signal and card installments up to 6x. This contract follows the task: 50% signal, and 12 installments. The tool does not charge either. Card numbers, PAN, and CVV are not accepted on this route or stored in any new table. A later PSP integration tokenizes the card in the browser.

### 4.4 `create_booking_intent`

`POST /api/v1/agent/properties/{public_hash}/intent`

JSON body:

```json
{ "checkin": "2026-12-01", "checkout": "2026-12-05", "hospedes": 2, "plan": "pix_a_vista" }
```

`plan` is required and is one of `pix_a_vista`, `sinal_50`, `cartao_12x`. Unknown plan is `422`. No guest name, email, phone, CPF, or card field. If present, `422` with `{"error":"unexpected_field"}` and no insert (do not silently strip and proceed).

Steps:

1. Resolve the listing or `404`.
2. Run the quote function from §4.3. On conflict or min-nights failure, return that status and insert nothing.
3. Count rows in `booking_intents` for this `property_id` with `status = 'held'` and `expires_at > now()` whose date range overlaps. If the count is already 3, return `429` with `Retry-After: 60`. Do not insert.
4. Insert one `booking_intents` row (§6) with `expires_at = now() + interval '15 minutes'`, `status = 'held'`. Do not insert `quotes`. Do not insert `calendar_days`. Do not copy `pix_key`.
5. Return `201`:

```json
{
  "intent_token": "{32 hex}",
  "expires_at": "{ISO-8601 UTC}",
  "plan": "pix_a_vista",
  "total": 880,
  "amount_due_now": 880,
  "balance_due": 0,
  "installments": 1,
  "checkout_url": "https://{host}/pagamento/intencao/{token}?plano=pix_a_vista"
}
```

The checkout path is new on purpose. `GET /pagamento/{16 hex}` is the human quote page, which shows the Pix key and whose POST confirm blocks the calendar. Agent checkout is `GET /pagamento/intencao/{token}` and must not render `pix_key`, CPF, or street. It shows dates, city, plan, `amount_due_now`, and `balance_due`. Confirmation stays a later human step and is out of this spec. Until that step exists, the page explains that payment is not captured yet.

`QuoteRepository::create()` stays at 30 minutes for humans. Do not change its default.

### 4.5 Rate limits

Add to `seguranca/api_limites.php`:

| Method | Path regex | Max | Window | Bucket | Valkey down |
| --- | --- | --- | --- | --- | --- |
| GET | `#^/api/v1/agent/search$#` | 60 | 60 | `/api/v1/agent/search` | fail open |
| GET | `#^/api/v1/agent/properties/[a-f0-9]{16}$#` | 120 | 60 | `/api/v1/agent/property` | fail open |
| GET | `#^/api/v1/agent/properties/[a-f0-9]{16}/quote$#` | 30 | 60 | `/api/v1/agent/quote` | fail open |
| POST | `#^/api/v1/agent/properties/[a-f0-9]{16}/intent$#` | 10 | 600 | `/api/v1/agent/intent` | fail closed |
| POST | `#^/mcp$#` | 60 | 60 | `/mcp` | fail closed |

Add `/api/v1/agent/intent` and `/mcp` to `IpRateLimiter::FAIL_CLOSED_BUCKETS`. The intent function checks that same bucket again when called from MCP, so a tool call cannot skip the 10/600 hold limit. The per-property cap of 3 open holds is the calendar-lock guard. A hold never sets `calendar_days.blocked`.

`casa_privado()` returns false for these routes. GET agent routes are added to `head_permitido()`. The POST routes are not.

CORS stays the existing `CORS_ORIGINS` allow-list. Do not emit `*`.

### 4.6 MCP

`POST /mcp` and `GET /mcp` for the Streamable HTTP transport. Nginx `location = /mcp` and `location = /llms-full.txt` and `location = /openapi.json` fastcgi to `index.php`. Stateless: no server-side session. Initialize advertises no roots and no prompts. Tools are exactly:

| Tool | Arguments | Calls | Result |
| --- | --- | --- | --- |
| `search_properties` | the search query fields | §4.1 | the reduced search JSON |
| `get_property_details` | `public_hash`, optional `checkin`, `checkout` | §4.2 | public listing JSON |
| `check_availability_and_quote` | `public_hash`, `checkin`, `checkout`, `hospedes` | §4.3 | quote JSON or the 409 body |
| `create_booking_intent` | `public_hash`, `checkin`, `checkout`, `hospedes`, `plan` | §4.4 | the 201 body, including `checkout_url` |

Any other tool name is JSON-RPC error `-32602`. There is no `book` or `pay` tool. `tools/call` for the intent uses the fail-closed intent bucket.

### 4.7 OpenAPI 3.1 (served at `/openapi.json`)

```yaml
openapi: 3.1.0
info:
  title: CasaLivre agent API
  version: 1.0.0
  summary: Read listings, quote a stay, and place a 15-minute hold. No payment capture.
servers:
  - url: /
paths:
  /api/v1/agent/search:
    get:
      operationId: search_properties
      parameters:
        - { name: localidade, in: query, schema: { type: string, maxLength: 80 } }
        - { name: checkin, in: query, schema: { type: string, format: date } }
        - { name: checkout, in: query, schema: { type: string, format: date } }
        - { name: hospedes, in: query, schema: { type: integer, minimum: 0, maximum: 100 } }
        - { name: raio, in: query, schema: { type: integer, minimum: 0, maximum: 20 } }
        - { name: preco_min, in: query, schema: { type: integer, minimum: 0 } }
        - { name: preco_max, in: query, schema: { type: integer, minimum: 0 } }
        - { name: score_min, in: query, schema: { type: number, minimum: 0, maximum: 10 } }
        - { name: amenidade, in: query, schema: { type: array, items: { type: string } }, style: form, explode: true }
        - { name: sort, in: query, schema: { type: string, enum: [relevance, rating] } }
        - { name: pagina, in: query, schema: { type: integer, minimum: 1 } }
        - { name: cursor, in: query, schema: { type: string } }
      responses:
        "200": { description: Page of public listings }
        "400": { description: Invalid cursor }
        "503": { description: Search timeout }
  /api/v1/agent/properties/{public_hash}:
    get:
      operationId: get_property_details
      parameters:
        - { name: public_hash, in: path, required: true, schema: { type: string, pattern: "^[a-f0-9]{16}$" } }
        - { name: checkin, in: query, schema: { type: string, format: date } }
        - { name: checkout, in: query, schema: { type: string, format: date } }
      responses:
        "200": { description: Public listing }
        "404": { description: Not found on this host }
  /api/v1/agent/properties/{public_hash}/quote:
    get:
      operationId: check_availability_and_quote
      parameters:
        - { name: public_hash, in: path, required: true, schema: { type: string, pattern: "^[a-f0-9]{16}$" } }
        - { name: checkin, in: query, required: true, schema: { type: string, format: date } }
        - { name: checkout, in: query, required: true, schema: { type: string, format: date } }
        - { name: hospedes, in: query, required: true, schema: { type: integer, minimum: 1, maximum: 100 } }
      responses:
        "200": { description: Available, with pix_a_vista, sinal_50, and cartao_12x figures }
        "409": { description: Calendar conflict }
        "422": { description: Invalid range or minimum nights }
  /api/v1/agent/properties/{public_hash}/intent:
    post:
      operationId: create_booking_intent
      parameters:
        - { name: public_hash, in: path, required: true, schema: { type: string, pattern: "^[a-f0-9]{16}$" } }
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              additionalProperties: false
              required: [checkin, checkout, hospedes, plan]
              properties:
                checkin: { type: string, format: date }
                checkout: { type: string, format: date }
                hospedes: { type: integer, minimum: 1, maximum: 100 }
                plan: { type: string, enum: [pix_a_vista, sinal_50, cartao_12x] }
      responses:
        "201": { description: Hold created. Body includes checkout_url. Calendar is not blocked. }
        "409": { description: Dates unavailable }
        "422": { description: Bad dates, bad plan, or unexpected field }
        "429": { description: IP bucket or 3 open holds on this property }
```

## 5. Bot telemetry (`bot_action_logs`)

`audit.activity_log` is not altered and is not written by this middleware. Human requests are not inserted here. There is no foreign key, so an insert does not lock `properties` or `users`.

```sql
CREATE TABLE bot_action_logs (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    created_at    timestamptz NOT NULL DEFAULT statement_timestamp(),
    actor_type    text        NOT NULL CHECK (actor_type = 'bot'),
    bot_name      text        NOT NULL CHECK (length(bot_name) BETWEEN 1 AND 64),
    channel       text        NOT NULL CHECK (channel IN ('html', 'markdown', 'rest', 'mcp')),
    action_name   text        NOT NULL CHECK (length(action_name) BETWEEN 1 AND 64),
    property_id   integer     NULL,
    search_params jsonb       NULL CHECK (search_params IS NULL OR jsonb_typeof(search_params) = 'object'),
    status_code   smallint    NOT NULL,
    latency_ms    integer     NOT NULL CHECK (latency_ms >= 0 AND latency_ms <= 600000),
    ip_hash       bytea       NULL CHECK (ip_hash IS NULL OR octet_length(ip_hash) = 32)
);

CREATE INDEX bot_action_logs_created ON bot_action_logs (created_at);
```

That is the only index. Analytics queries in §5.4 are allowed to seq-scan a short retention window.

`search_params` allow-list, and nothing else: `localidade` (truncated to 80 chars), `checkin`, `checkout`, `hospedes`, `preco_min`, `preco_max`, `raio`, `sort`, `amenidades` (array of known `Amenities::GROUPS` keys, max 20). No raw query string, cookie, CPF, address, Pix, or iCal URL.

`ip_hash` is HMAC-SHA256 of the client IP with a salt that rotates each UTC day. The salt is 32 random bytes in `storage/bot-ip-salt/YYYYMMDD`, mode `0600`, written by the flusher if missing. It is not `LOG_SALT` and not `GEO_SALT`. After midnight the previous file stays on disk for the retention window so a same-day query can be explained, and it is deleted with the rows. If the salt cannot be read, store NULL and still flush the rest.

`bot_name` comes from `User-Agent`, first match wins:

| Substring | `bot_name` |
| --- | --- |
| `OAI-SearchBot` | `OAI-SearchBot` |
| `ClaudeBot` | `ClaudeBot` |
| `Google-Extended` | `Google-Extended` |
| `PerplexityBot` | `PerplexityBot` |
| `CasaLivre-MCP-Sim` | `CasaLivre-MCP-Sim` |

A call whose path is `/api/v1/agent` or `/mcp` and whose UA matches none of these is `bot_name = agent`. Ignore any client header that claims a bot name. A browser UA on `/`, `/busca`, `/imoveis/{hash}` HTML, or `/api/busca` is human: the middleware returns without enqueueing.

`channel` is `mcp` for `/mcp`, `rest` for `/api/v1/agent`, `markdown` when the listing GET negotiated markdown, `html` when a listed crawler fetches the HTML listing or a discovery file.

`action_name` is the tool name, or `discover` for robots, llms, and openapi.

Retention is 14 days, deleted by the flusher with `DELETE FROM bot_action_logs WHERE created_at < now() - interval '14 days'` at the start of each UTC day. Do not send these rows through `LogRetention::pgStep()` or into `audit.*`.

Bump `Db::SCHEMA_VERSION` to 33 when this table and `booking_intents` land in `database/schema.pgsql.sql`. This project recreates a database that is behind the version. There is no online `ALTER` on a request.

### 5.1 Middleware and buffer

Class `src/BotTelemetry.php`, called from the `RequestLog` shutdown in `public/index.php` only when the request is a bot as defined above. Order:

1. Build the event array in memory. This is a fixed ring on the PHP-FPM worker: 256 slots, head index, write is one assignment. O(1). If the ring is full, increment `BotTelemetry::$dropped` and return. Do not block the response.
2. The shutdown function tries one non-blocking pipeline to Valkey: `LPUSH cl:bot_log` of the drained slots, then `LTRIM cl:bot_log 0 9999`. Socket timeout is the producer timeout already used by `ValkeyJobQueue` (short). On any Redis error, increment `$dropped` and return. Do not open PostgreSQL on the request.
3. Before the push, if `LLEN cl:bot_log` is at least 10000, drop the batch. If it is at least 5000, keep 1 event out of 10 (sample on `hrtime(true) % 10 == 0`) and drop the rest. Sampling is the backpressure valve. The high-water drop protects PostgreSQL from a flush stall.

PHP-FPM workers do not share a heap, and APCu is not loaded, so the ring cannot be the cross-worker buffer. Valkey is already the shared memory for rate limits (`/run/casalivre-valkey/valkey.sock`). The ring exists so the request does not wait on the list.

### 5.2 Flush worker

New runit service `casalivre-bot-log`, script `scripts/bot-log-worker.php`, same `pcntl_signal` shape as `CalendarWorker::run()`.

- Loop: `LRANGE`/`LTRIM` up to 500 items, or wake at 5 seconds, whichever comes first.
- One multi-row `INSERT INTO bot_action_logs`.
- On `SIGTERM` and `SIGINT`: stop accepting, drain the Valkey list to PostgreSQL, then exit 0. A failed insert puts the batch back with `LPUSH` and exits non-zero so runit restarts it.
- The worker is the only session that inserts into `bot_action_logs`.

### 5.3 What humans do

`GET /api/busca` and the HTML property page keep writing `audit.activity_log` exactly as they do now (`SEARCH`, `PROPERTY_VIEW`). `BotTelemetry` returns immediately for a UA that is not on the list and a path that is not an agent or MCP path. The simulator’s `human_mix` scenario asserts a zero delta on `bot_action_logs`.

### 5.4 Admin queries

Traffic by bot, last 24 hours:

```sql
SELECT bot_name,
       count(*) AS hits,
       count(*) FILTER (WHERE status_code >= 400) AS errors,
       round(avg(latency_ms)) AS avg_ms
FROM bot_action_logs
WHERE created_at >= now() - interval '24 hours'
GROUP BY bot_name
ORDER BY hits DESC;
```

Top cities, date pairs, and amenities from agent searches, last 7 days:

```sql
SELECT COALESCE(search_params->>'localidade', '') AS city,
       COALESCE(search_params->>'checkin', '') AS checkin,
       COALESCE(search_params->>'checkout', '') AS checkout,
       COALESCE(amenity, '') AS amenity,
       count(*) AS hits
FROM bot_action_logs
LEFT JOIN LATERAL jsonb_array_elements_text(
    COALESCE(search_params->'amenidades', '[]'::jsonb)
) AS amenity ON true
WHERE action_name = 'search_properties'
  AND channel IN ('rest', 'mcp')
  AND created_at >= now() - interval '7 days'
GROUP BY GROUPING SETS (
    (search_params->>'localidade'),
    (search_params->>'checkin', search_params->>'checkout'),
    (amenity)
)
ORDER BY hits DESC
LIMIT 100;
```

Quote to hold conversion, last 7 days:

```sql
SELECT bot_name,
       count(*) FILTER (
           WHERE action_name = 'check_availability_and_quote' AND status_code = 200
       ) AS quotes,
       count(*) FILTER (
           WHERE action_name = 'create_booking_intent' AND status_code = 201
       ) AS intents,
       round(
           100.0 * count(*) FILTER (
               WHERE action_name = 'create_booking_intent' AND status_code = 201
           ) / NULLIF(count(*) FILTER (
               WHERE action_name = 'check_availability_and_quote' AND status_code = 200
           ), 0),
           1
       ) AS conversion_pct
FROM bot_action_logs
WHERE created_at >= now() - interval '7 days'
GROUP BY bot_name
ORDER BY intents DESC;
```

## 6. Soft hold (`booking_intents`)

```sql
CREATE TABLE booking_intents (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    token            text        NOT NULL UNIQUE CHECK (token ~ '^[a-f0-9]{32}$'),
    property_id      integer     NOT NULL,
    checkin          date        NOT NULL,
    checkout         date        NOT NULL,
    guests           smallint    NOT NULL CHECK (guests BETWEEN 1 AND 100),
    nights           integer     NOT NULL CHECK (nights >= 1),
    nightly_price    integer     NOT NULL CHECK (nightly_price >= 0),
    cleaning_fee     integer     NOT NULL CHECK (cleaning_fee >= 0),
    total_price      integer     NOT NULL CHECK (total_price >= 0),
    plan             text        NOT NULL CHECK (plan IN ('pix_a_vista', 'sinal_50', 'cartao_12x')),
    amount_due_now   integer     NOT NULL CHECK (amount_due_now >= 0),
    balance_due      integer     NOT NULL CHECK (balance_due >= 0),
    installments     smallint    NOT NULL CHECK (installments IN (1, 12)),
    status           text        NOT NULL DEFAULT 'held' CHECK (status IN ('held', 'expired', 'cancelled')),
    expires_at       timestamptz NOT NULL,
    created_at       timestamptz NOT NULL DEFAULT statement_timestamp(),
    CHECK (checkout > checkin),
    CHECK (amount_due_now + balance_due = total_price)
);

CREATE INDEX booking_intents_open
    ON booking_intents (property_id, expires_at)
    WHERE status = 'held';
```

No foreign key to `properties` or `users`. The insert must not take a row lock on the listing. `property_id` is checked in PHP before the insert. No `pix_key`, no CPF, no street, no email. `token` is `bin2hex(random_bytes(16))`.

A sweep in the bot-log worker, once a minute, runs:

```sql
UPDATE booking_intents
SET status = 'expired'
WHERE status = 'held' AND expires_at <= now();
```

Expired rows do not block later quotes. They never wrote `calendar_days`, so there is nothing to unlock.

`GET /pagamento/intencao/{token}` loads the row. `404` when missing. `410` when `expired` or `expires_at` has passed. The template shows city and title (from `properties`, public columns only), dates, plan label, `amount_due_now`, and `balance_due`. It does not join `users` and does not select `properties.pix_key`, `properties.street`, `properties.address`, or `users.cpf`.

Plan labels on that page:

| `plan` | What the guest sees |
| --- | --- |
| `pix_a_vista` | Pay the full total by Pix at checkout. |
| `sinal_50` | Pay half now. The other half is due later, before check-in. |
| `cartao_12x` | Pay the total by card in 12 installments. The card form, when it exists, is a PSP frame. The server never sees the PAN. |

The current human page `views/payment.php` shows `pix_key` before `confirmPayment()`. Agent checkout must not share that template. A later change should also stop rendering the raw key on the human page until `quotes.status = 'confirmed'`. That change is outside this spec’s file list, but the leak is real today.

## 7. LGPD isolation

Denied on every response in §2, §3, §4, and §6, including errors:

- `users.cpf` and any other `users` column except nothing (no user object at all).
- `properties.pix_key` and the Pix copia-e-cola payload (`src/PixPayload.php`).
- `address`, `street`, `address_number`, `address_complement`, `cep`.
- Exact `lat` and `lng`.
- `ical_hash`, `external_ical_url`, feed URLs.

Allowed location: city name, UF when the place row has it, neighborhood from `house_neighborhood()` (CEP lookup result’s neighborhood label only).

`bot_action_logs.ip_hash` is the daily HMAC from §5. `RequestLog` keeps its own truncated IP for `/api/busca` and is unchanged. Do not copy `RequestLog` lines into `bot_action_logs`.

## 8. Traffic simulator (spec only)

File to be written later: `scripts/simulate_traffic.py`. Do not add it in the same change as this document. The repo’s `scripts/` directory is PHP today; this script is the exception the task asks for. It uses only the Python standard library (`urllib.request`, `argparse`, `json`, `time`).

```text
python3 scripts/simulate_traffic.py --scenario <name> --base-url <origin> [--duration 30] [--rps 2] [--public-hash <16 hex>]
```

| Flag | Meaning |
| --- | --- |
| `--scenario` | `search_bots`, `mcp_agents`, `human_mix`, or `spike_abuse` |
| `--base-url` | Origin with no path, for example `http://127.0.0.1` |
| `--duration` | Seconds. Default 30. `spike_abuse` ignores this and sends 30 intent POSTs as fast as the client allows. |
| `--rps` | Target requests per second. Default 2. |
| `--public-hash` | Listing to read and quote. Required for every scenario except when the scenario only hits `/api/v1/agent/search`. |

Exit 0 when the assertions below hold. Exit 1 with one line on stderr otherwise. The script does not connect to PostgreSQL. The operator checks the tables. The script checks HTTP status only, and prints the counts it expects the operator to see.

`search_bots`. Rotate User-Agent `OAI-SearchBot`, `ClaudeBot`, `Google-Extended`, `PerplexityBot`. For each, `GET /robots.txt`, `GET /llms.txt`, `GET /api/v1/agent/search?localidade=Florianópolis&hospedes=2`, and `GET /imoveis/{hash}` with `Accept: text/markdown`. Expect HTTP 200. Expect new `bot_action_logs` rows with those `bot_name` values and zero new `audit.activity_log` rows during the run.

`mcp_agents`. `POST /mcp` `tools/call` for `search_properties`, `get_property_details`, `check_availability_and_quote`, and `create_booking_intent` with User-Agent `CasaLivre-MCP-Sim`. Expect the intent `201` and a `checkout_url` containing `/pagamento/intencao/`. Expect `channel = mcp` rows. Expect no new `calendar_days` for that property. Expect no new `audit.activity_log` rows.

`human_mix`. User-Agent `Mozilla/5.0 (compatible; CasaLivreHumanSim/1.0)`. `GET /` and `GET /api/busca?localidade=Florianópolis`. Expect HTTP 200. Expect zero new `bot_action_logs` rows. `audit.activity_log` may gain a `SEARCH` row from the existing `/api/busca` path. That is the split: humans stay out of `bot_action_logs`.

`spike_abuse`. 30 times `POST` the intent URL with User-Agent `OAI-SearchBot` and a valid body, from one source IP. Expect the first responses to follow §4.4 (201 until the IP bucket or the 3-hold cap), then `429` with `Retry-After`. Expect `bot_action_logs.status_code = 429` for the rejected calls. Expect `calendar_days` row count for that `property_id` unchanged from before the run.

## 9. Build order

Each step is a later change. This document is step 0.

1. Static `robots.txt` and `llms.txt`.
2. `public_listing()` shared by the current public API, JSON-LD, and markdown.
3. PHP `llms-full.txt` and `openapi.json` plus the three Nginx exact locations.
4. Read-only agent routes (§4.1–§4.3) and the OpenAPI file matching them.
5. `booking_intents`, the intent route, the intent checkout page, fail-closed buckets, the 3-hold cap.
6. `/mcp` calling the same four functions.
7. `bot_action_logs`, `BotTelemetry`, Valkey list, `scripts/bot-log-worker.php`.
8. `scripts/simulate_traffic.py` and a run of the four scenarios against a dev host.

## 10. Acceptance checks

- `GET /robots.txt` contains `Allow: /` under `OAI-SearchBot`, `ClaudeBot`, `Google-Extended`, and `PerplexityBot`.
- `GET /llms-full.txt` and `GET /openapi.json` are produced by PHP. Neither path exists as a file in `public/`.
- `GET /imoveis/{hash}` HTML contains one `VacationRental` object whose `price` equals `nightly_price` and which has no street, CEP, or Pix key.
- `Accept: text/markdown` on that URL returns markdown and `Vary: Accept`. A normal browser Accept still gets the HTML shell.
- `GET /api/v1/agent/search` returns the same public hashes as `GET /api/busca` for the same query, without `address`.
- `GET .../quote` inserts neither `quotes` nor `booking_intents` nor `calendar_days`.
- `POST .../intent` returns `checkout_url` under `/pagamento/intencao/`, `expires_at` about 15 minutes out, `plan` echoed, and does not change `calendar_days`.
- A fourth overlapping hold on the same property returns 429. The eleventh intent from one IP inside 600 seconds returns 429 even when Valkey is up. With Valkey stopped, the intent route returns 429 and search still returns 200.
- `POST /api/imoveis/{hash}/reservar` without a CSRF token still returns 419.
- After `search_bots`, `bot_action_logs` has rows and `audit.activity_log` has no new rows from those requests. After `human_mix`, `bot_action_logs` has no new rows.
- Stopping the flush worker does not change the HTTP status of a bot search. Starting it inserts the queued batch within 5 seconds. `SIGTERM` to the worker drains the list before exit.
- The intent JSON, the markdown body, and the JSON-LD contain none of: CPF, `pix_key`, street, street number, CEP.

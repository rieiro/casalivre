# IP rate limits for humans and agents

Recommendation for CasaLivre. It combines the limiter that is running today with the agent routes in `ARCHITECTURE_AI_PLAN.md`.

This file is a recommendation only. It does not change code, schema, or Nginx.

## Current system

`seguranca/ApiRateLimit` runs first in the `Seguranca` pipeline. It counts only the routes listed in `seguranca/api_limites.php`. The counter is `IpRateLimiter` on Valkey: a fixed window (`INCR` plus `EXPIRE NX`), a 100 ms timeout, and an HMAC key. IPv4 is the whole address. IPv6 is grouped by `/64`. The address is `REMOTE_ADDR` only.

Buckets that exist today:

| Route | Limit | Bucket | Valkey down |
| --- | --- | --- | --- |
| `GET /api/cidades` | 60 per minute | `/api/cidades` | fail open |
| `GET /api/ceps` | 60 per minute | `/api/ceps` | fail open |
| `GET /api/busca` | 60 per minute | `/api/busca` | fail open |
| `POST /cadastro` and `POST /api/users` | 10 per hour | `/cadastro` | fail closed |
| `POST /senha/esqueci` and `POST /api/auth/senha/esqueci` | 5 per 15 minutes | `/senha/esqueci` | fail closed |
| `POST /imoveis/{hash}/fotos` and `POST /api/casas/{hash}/fotos` | 30 per 10 minutes | `/api/casas/*/fotos` | fail open |
| `POST /imoveis/{hash}/avaliar` and `POST /api/imoveis/{hash}/avaliar` | 10 per hour | `/api/imoveis/*/avaliar` | fail open |

`LoginAttempt` also calls `IpRateLimiter::reserve` on `ativacao-reenvio`, which fails closed. `IpRateLimiter::FAIL_CLOSED_BUCKETS` is only `/senha/esqueci`, `/cadastro`, and `ativacao-reenvio`.

Login is a second limiter. `POST /entrar` and `POST /api/sessao` use `LoginRateLimiter` and the PostgreSQL table `login_rate_limit` (default 5 failures, then 15 minutes). That table stores the raw IP. A separate account lock lives on `users.failed_logins` via `AccountFlow`.

Any PHP path that is not in the list is not counted. That includes `GET /api/imoveis/{hash}`, `GET /ical/{hash}`, `POST /api/imoveis/{hash}/reservar`, calendar import, and property edits. `ApiRateLimit` stops at the first matching rule. Nginx has no `limit_req` and no `real_ip`. There is no front proxy in this container, so `REMOTE_ADDR` is the client.

The architecture plan adds `/api/v1/agent/*`, `POST /mcp`, `GET /llms-full.txt`, and `booking_intents`. Those routes do not exist yet, so they are not limited.

## Recommendation

Keep one Valkey limiter for every PHP request. Make the agent routes spend the same budgets as the human routes they copy. Keep the account lock on `users.failed_logins` in Postgres. `bot_action_logs` only records the result.

### Rules

1. `IpRateLimiter` is the only IP counter. `POST /entrar` and `POST /api/sessao` move off `login_rate_limit` onto a Valkey bucket. That table stores the raw IP. The Valkey key is already an HMAC, with IPv4 whole and IPv6 grouped by `/64`, taken from `REMOTE_ADDR` only.
2. Every request that reaches `public/index.php` increments a default bucket and any specific bucket whose method and path match. `ApiRateLimit` today stops at the first match, so a short burst window and a specific cap never both run. Change it so all matching rules must pass.
3. A write, an email, a search, a listing read, a quote, or a hold fails closed when Valkey is down (HTTP 429, `Retry-After: 30`). Cheap page loads fail open on the default bucket, so the homepage still opens during a Valkey blip.
4. The agent route and the MCP tool call the same PHP function and the same bucket as the human route. `POST /mcp` also spends an outer `/mcp` budget, so a tool call cannot skip the limit that the REST route has.
5. The three-open-hold cap on `booking_intents` stays a per-property rule. It is not an IP limit. A hold still does not write `calendar_days`.

A future proxy has to set Nginx `real_ip` from that proxy only. Until then, do not read `X-Forwarded-For`.

### Budgets

The default row applies to every PHP request. The other rows are the extra caps.

| Bucket | Who spends it | Limit | Valkey down |
| --- | --- | --- | --- |
| `/php` | Every request to `index.php` | 300 per minute | fail open |
| `/entrar` | `POST /entrar`, `POST /api/sessao` | 5 per 15 minutes, same settings as today (`login_ip_max_failures`, `login_ip_lock_minutes`) | fail closed |
| `/cadastro` | `POST /cadastro`, `POST /api/users` | 10 per hour | fail closed |
| `/senha/esqueci` | Web and `POST /api/auth/senha/esqueci` | 5 per 15 minutes | fail closed |
| `ativacao-reenvio` | `LoginAttempt` resend | unchanged | fail closed |
| `/api/busca` | `GET /api/busca`, `GET /api/v1/agent/search`, MCP `search_properties` | 60 per minute and 15 per 10 seconds | fail closed |
| `/api/imoveis` | `GET /api/imoveis/{hash}`, `GET /api/v1/agent/properties/{hash}`, MCP `get_property_details` | 120 per minute and 30 per 10 seconds | fail closed |
| `/ical` | `GET /ical/{hash}`, `GET /api/ical/{hash}` | 30 per minute | fail closed |
| `/llms-full` | `GET /llms-full.txt` | 10 per minute | fail closed |
| `/api/casas/*/fotos` | Web and `POST /api/casas/{hash}/fotos` | 30 per 10 minutes | fail closed |
| `/api/imoveis/*/avaliar` | Web and API review POST | 10 per hour | fail closed |
| `/reserva` | `POST /api/imoveis/{hash}/reservar` and the web reserve POST | 10 per 10 minutes | fail closed |
| `/api/v1/agent/quote` | `GET .../quote`, MCP `check_availability_and_quote` | 30 per minute and 10 per 10 seconds | fail closed |
| `/api/v1/agent/intent` | `POST .../intent`, MCP `create_booking_intent` | 10 per 10 minutes | fail closed |
| `/mcp` | Every `POST /mcp` and `GET /mcp`, on top of the tool bucket | 60 per minute | fail closed |

`GET /api/cidades` and `GET /api/ceps` keep their current 60-per-minute buckets and stay fail open. They are small lookups. They also spend `/php`.

`robots.txt`, `llms.txt`, and `openapi.json` are cheap. They spend only `/php`. Static files under `public/` never reach PHP, so they stay outside this table.

A search from an agent costs one hit on `/php` and one hit on `/api/busca`. The same search through MCP costs those two plus one hit on `/mcp`. A human `GET /api/busca` spends the same search budget, so a bot cannot add a second quota by using the new path.

`/reserva` and `/api/v1/agent/intent` stay separate. The human route creates a 30-minute `quotes` row. The agent route creates a 15-minute `booking_intents` row and does not block nights. Sharing one bucket would let checkout traffic exhaust the agent budget. Both are fail closed because both insert rows.

The short windows (15 per 10 seconds, 30 per 10 seconds, 10 per 10 seconds) sit on the routes that can dump a full minute of budget into one second. Signup, login, photos, and reviews are already tight enough that a second window adds little.

Two windows on one bucket mean two rules in `api_limites.php` with the same bucket name and different max and window. Both must pass. A Valkey outage still consults `FAIL_CLOSED_BUCKETS` once per bucket, not once per window.

### What stays beside the IP limit

- `users.failed_logins` still locks one account after repeated bad passwords. That stops a distributed guess against one email. The IP bucket stops one address from spraying many emails.
- Three `booking_intents` rows with `status = 'held'` and an overlapping date range remain the cap per property. The eleventh intent from one IP in 10 minutes is the IP cap. Either one returns 429.
- `BotTelemetry` does not delay or reject. A 429 is written to `bot_action_logs` with `status_code = 429` for a listed crawler or an agent route. Humans still never land in that table. `audit.activity_log` stays the human and security trail.

### Order of work

1. Make `ApiRateLimit` apply every matching rule, and add the `/php` default.
2. Move login onto `/entrar` and stop writing `login_rate_limit`.
3. Flip `/api/busca` and the photo bucket to fail closed. Add `/api/imoveis`, `/ical`, and `/reserva` for the routes that exist today.
4. When the agent routes from `ARCHITECTURE_AI_PLAN.md` are built, register them on the shared buckets above. Do not give them a private search quota. Add `/llms-full`, `/api/v1/agent/quote`, `/api/v1/agent/intent`, and `/mcp` in that same change.

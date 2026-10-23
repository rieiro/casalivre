# Spec: search ranking, sorts, filters and flexible dates (G1, G2, G3, G4; draft, 2026-10-21)

**Base:** [ADR 001 §12](../../adr/001-busca-sql-paginada.md) (rev 3, #179; guard responses §12.7a, #181; map pins and clusters §12.7b, #184; `rank_penalty` DDL §12.11, #188) and [docs/decisions.md](../../decisions.md) (2026-10-21, search sorts and filters; G1 and G13 decisions). Product sources: `docs/produto/planejamento/backlog.md` ("Competitor gaps": G1, G2, G3, G4, G13) and [spec-busca-localidade-resolvida.md](spec-busca-localidade-resolvida.md) (resolved place, map area, combobox, official copy table).
**Scope:** what the guest sees and what the API returns. How each option is stored and indexed is §12 (Arquitetura, BD). Screen layout of the zoom states is UI's (section 7, merged from UI's screen spec).
**Aligned:** spec-01 §2 and spec-busca-localidade-resolvida item 8 carry the 2,000-candidate total-price cap (G-1a) with a relevance fallback; the zoom-13/12 client hint is dropped (§12.7a) and the price sorts are always enabled (section 7).
[E] = estimate to calibrate (Negocios), no usage data yet.

## 0. Rules that apply to every section
1. Every sort reads a **stored, indexed score** (`rank_base`, `rating_rank`, `channel_rank.score`, `id` as tiebreaker), written on the write path or by a Valkey job. **No score is computed per request.** The only per-request computation is the total price, and only under its guard (G-1a). (§12.0)
2. Any combination that can't meet the target returns a **guard response** (section 6), never a slow query.
3. The default sort is **Relevância** (G1).
4. Targets (§12.9 criterion 12): with every option on (all G3 filters, each sort, a channel with weights > 0, flexible dates, total price at 2,000 candidates, 7 and 30 nights), **`/api/busca` and `modo=mapa` p95 < 300 ms** end to end, **DB time ≤ about 30 ms** per allowed case, autocomplete p95 < 50 ms. Measured by Teste on the real seed (§12.9).
5. Stay length: at most **30 nights** (G13, Rafael 2026-10-21). A longer stay is refused before any query (G-5).

## 1. G1: relevance ranking
### Problem
"Relevância" is the default sort but has no definition: today it is insertion order (`id`). Each storefront also needs to tune its order, and Rafael decided (2026-10-21) that ranking supports **paid placement** and **own-network preference** as per-channel weights, both **0 (off)** by default, with a visible **"Patrocinado"** label. Turning them on is a later decision.

### Solution
- **Base score `rank_base`** (one per listing, all channels), written by `RankScore::base()` on every listing, review, status or amenity write. Factors (Produto), each normalized to 0..1000, combined with platform weights stored in settings (Negocios calibration, `/workspace/negocios/calibracao-busca.md`, 2026-10-21; revisit after 90 days of pilot data):

  | Factor | Source | Default weight (setting) |
  |---|---|---|
  | Rating (G2 `rating_rank`) | reviews | 35% |
  | Listing quality: recommended checklist items (10+ photos, alt text on photos, accessibility amenities; spec-03 §4) | listing | 25% |
  | Host reliability, last 12 months (spec-02 §4c) | cancellations | 20% |
  | Instant booking | listing | 10% |
  | Self check-in (G7 check-in method: key box or smart lock) | listing (P10, S7) | 10% |
  | Response time (G8) | requests | 0% until pilot data exists |

  - **Host cancellation demotion** (spec-09 §5: "perde o destaque na busca por 90 dias"; Negocios calibration, settings): **×0.7** for the 1st host-responsible cancellation in 90 days, **×0.5** from the 2nd, stored as a permille multiplier: `rank_penalty` **700** and **500** (1000 = no penalty). Only cancellations whose G14 `responsible` = host count; others don't.
    - Arquitetura (`/workspace/adr/notes-produto-spec-busca.md` (a)): the penalty is **folded into the stored, indexed score** (`rank_base` in `listing_search` and each `channel_rank.score`; Arquitetura's "rank_score"), never subtracted at query time. `listing_search` stores `rank_penalty` and `penalty_until`.
    - **Columns (BD; in ADR 001 [§12.11](../../adr/001-busca-sql-paginada.md), added by Arquitetura in #188):** `rank_penalty smallint NOT NULL DEFAULT 1000 CHECK (rank_penalty BETWEEN 1 AND 1000)`, a multiplier in permille (1000 = none, 700 = ×0.7, 500 = ×0.5); `penalty_until timestamptz NULL`; `CHECK ((rank_penalty = 1000) = (penalty_until IS NULL))`, so a penalty always has an expiry and an expiry always has a penalty. Integer math matches the int scores: `rank_base = raw * rank_penalty / 1000`, the same for each `channel_rank.score`. The factors are Negocios settings inside the range CHECK, so recalibrating needs no schema change.
    - **On a host-responsible cancellation, in the same transaction as the cancel:** `rank_penalty` = the factor for the count of host-responsible cancellations in the last 90 days (counted from the G14 cancellations table, not stored here); `penalty_until = GREATEST(coalesce(penalty_until, now()), now() + interval '90 days')`; then `rank_base` and the listing's `channel_rank` rows are recomputed.
    - A **daily `rank:sweep`** resets expired rows (`penalty_until <= now()`) to (1000, NULL) and recomputes them in one transaction (≤ 24 h staleness on expiry [E]). No other time factor in the score; no new search index. Seeds use the default (1000, NULL).
  - No price-vs-comparable or popularity factor now (they need per-date or behavioural data).
- **Per-channel score `channel_rank.score`** (§12.2). A channel has its own factor weights plus the two commercial weights:
  - `peso_pago` (paid placement, default 0): applies only to listings with a **live boost** in that channel.
  - `peso_rede` (own-network preference, default 0): applies to listings managed by the channel's own organization or node subtree.
  - Both **default to 0**. With every weight of a channel at its default there are no `channel_rank` rows and the base order serves (§12.2 option c).
- **Who configures:** only **admin_plataforma**, in Gerenciar → Canais → Ranking. Every change is logged (author, channel, old and new value). Other roles don't see the section; the API returns 403.
- **"Patrocinado" label:** shown on the card, the detail page and the **map pin** (section 7.4, Q-P2) **in that channel** whenever the listing's position in that channel got a paid boost, i.e. its `channel_rank` row has `sponsored = true`. A boost can only be bought for a channel whose `peso_pago` > 0, so the label appears exactly when the paid weight is above 0 for that listing in that channel. Position and label always come from the **same row** (§12.3a.1).
- **Boost lag (§12.3a, Produto with UI and Jurídico):**
  - a purchase, edit or cancel reaches the ranking within **≤ 5 min** (`rank:sweep`);
  - the paid period and **billing start at go-live** (`live_from`), not at purchase;
  - the host screen shows **"Agendado"** until go-live, then **"Ativo desde <data e hora>"**, and says a boost can take up to 5 minutes to start;
  - minutes between the boost's end or cancellation and the sweep clearing the row are **not billed**; a boost cancelled before go-live bills **0**.
- **Before enabling:** turning `peso_pago` or `peso_rede` above 0 in production needs **Jurídico's review** of the label and the guest disclosure, and Rafael's decision.

### User flow
1. Guest searches a place or map area; results come in Relevância order for the current channel.
2. In a channel with `peso_pago` > 0, a boosted listing shows "Patrocinado" next to its title on the card and on the detail page.
3. admin_plataforma opens Gerenciar → Canais → [canal] → Ranking, edits weights (0 by default), confirms ("Salvar pesos"); the screen shows "Atualizando a ordem (até 1 min)" until `rank_ready`.
4. Host (when boosts are enabled) buys a boost for an eligible channel and sees "Agendado", then "Ativo desde …".

### Acceptance criteria
1. With every channel weight at its default (paid and network 0), the order in that channel is exactly the base order (`rank_base DESC, id DESC`), and no `channel_rank` row exists for it.
2. Changing one channel's weights changes only that channel's order, within ≤ 60 s [E] (`rank:channel:{id}`); other channels are unchanged.
3. Each channel has `peso_pago` and `peso_rede`, both 0 in a new or unconfigured channel.
4. A listing whose row in channel X has `sponsored = true` shows "Patrocinado" (text, not only an icon, with screen-reader text) on its card, its detail page and its map pin in X; the same listing in a channel where its row is not sponsored, or has no row, shows no label. A boosted position never appears without the label, and the label never appears without the boost.
5. Only admin_plataforma can read or change the weights; any other role gets 403 and doesn't see the menu item. Each change creates an audit entry.
6. A boost can't be bought for a channel with `peso_pago` = 0 (422 with the message).
7. With a simulated clock (§12.9 criterion 16): billing starts at `live_from`; "Agendado" shows until then; stale minutes are not billed; a cancel before go-live bills 0; go-live is ≤ 5 min after purchase.
8. A host-responsible cancellation sets `rank_penalty` to 700 (1st in 90 days) or 500 (2nd onwards) and `penalty_until` to `GREATEST(current, now() + 90 days)` in the same transaction, and recomputes the score in `listing_search` and every `channel_rank` row (`score × rank_penalty / 1000`); a cancellation with `responsible` ≠ host changes nothing; the daily sweep resets expired rows to (1000, NULL) and restores the score (≤ 24 h), with no other time-based change to `rank_base`. The schema rejects a penalty without an expiry, an expiry without a penalty, and a `rank_penalty` outside 1–1000.
9. Relevance pagination is keyset over the stored score; no request computes a score (builder test, §12.9 criterion 13). p95 within section 0 targets with a channel at weights > 0.

## 2. G2: best rated, weighted by review count
### Problem
A 5.0 with 3 reviews outranks a 4.9 with 200.

### Solution
- **Sort "Melhor avaliação"** reads `rating_rank` = Bayesian mean `(C × m + Σ notas) ÷ (C + n)`, stored as an integer ×1000 (0..5000), written inline with each review (§12.1, §12.3).
- `C` (prior weight) and `m` (prior mean) are platform settings (Negocios calibration): **`C = 5`**, **`m = 4,5`** for the pilot; once the platform has **100+ reviews**, `m` switches to the **platform average rating** (also a setting). A change runs `rank:all` (≤ 60 s [E]).
- The card keeps showing the plain average with the count ("★ 4,8 (23)", spec-07); listings with fewer than 3 reviews show "Novo" (spec-07). The `score_min` filter keeps the plain average (§4a). Only the sort order uses the Bayesian value.
- `rating_rank` is also the rating factor of G1.

### User flow
Guest picks "Melhor avaliação" in the sort control; the list reorders; the choice stays in the URL (`sort=rating`).

### Acceptance criteria
1. With `C = 5`, `m = 4,5`, a listing with three 5-star reviews (4.69) ranks below a listing with 200 reviews averaging 4.9 (4.89).
2. A new review updates `rating_rank` in the same transaction; the next search reflects it.
3. Changing `C` or `m` needs no deploy and is applied to every listing within ≤ 60 s [E].
4. Card, detail and `score_min` show and use the plain average; only the order uses `rating_rank`.
5. No per-request score computation; p95 within section 0 targets with all filters on.
6. With the "platform average after 100 reviews" setting on and 100+ reviews on the platform, `m` equals the platform average and the change is applied by `rank:all`.

## 3. G3: filter panel
### Problem
UC-H04 lists filters that spec-01 §2 never specified, though the data exists.

### Solution
A "Filtros" panel (sheet on mobile, side panel on desktop; layout by UI) with these filters. Each is a test on a stored column inside the index scan (§12.5); none computes a score.

| Filter | Param | Values | Stored as |
|---|---|---|---|
| Property type | `tipo[]` | codes from `search_codes` (multi) | `property_type` |
| Bedrooms (minimum) | `quartos_min` | 0..10 | `bedrooms` |
| Beds (minimum) | `camas_min` | 0..20 | `beds_total` (derived from rooms, G5) |
| Bathrooms (minimum) | `banheiros_min` | 0..10 | `bathrooms` |
| Pets allowed | `pets=1` | flag | `features` bit |
| Instant booking | `instantanea=1` | flag | `features` bit |
| Self check-in | `self_checkin=1` | flag | `features` bit (from G7) |
| Accessibility (e.g. step-free entrance) | `amenidade[]` | existing amenity keys | `features` bits |
| Amenities | `amenidade[]` (existing) | keys | `features` bits |
| Full refund until a date | `reembolso_total=1` | flag; **needs dates** | `cancel_policy = ANY(:codes)` from (check-in, today) |
| Price per night | `preco_min`, `preco_max` (existing) | integers | unchanged (§4a) |
| Minimum rating | `score_min` (existing) | 1 decimal | unchanged (§4a) |

- No counts on filters (Gerente decision, §5b `facets`).
- "Reembolso total até <data>" is offered only with dates; the date shown is the latest full-refund date of the qualifying policies for those dates.
- "Self check-in" is hidden until G7 check-in data exists (S7, P10).
- Changing any filter resets the cursor; an old cursor with other filters returns 400 (G-7), and the client restarts from page 1.
- The price filter label says "por noite" (it filters nightly price while cards show the total).

### User flow
1. Guest taps "Filtros" (button shows the number of active filters: "Filtros (3)").
2. Picks options; taps "Mostrar imóveis"; the list and map refresh from page 1, URL updated with `pushState`.
3. "Limpar filtros" resets all; each active filter also shows as a removable chip above the list.

### Acceptance criteria
1. Each filter returns exactly the listings whose stored columns match, on the place path and the map path; results equal a reference query over `properties` (Teste).
2. `reembolso_total=1` without dates returns 422 with the message; with dates it returns only listings whose policy gives 100% until the date shown.
3. All filters on together, under each sort, in a map area up to the G-2/G-3 caps and in the 44k dense area: DB ≤ about 30 ms and p95 within section 0 targets.
4. No filter computes a per-row score; filters run inside the index-only scan (BD EXPLAIN in the implementation PR).
5. The panel is reachable by keyboard, each control is labelled, the active count is announced, and closing returns focus to "Filtros" (WCAG 2.2 AA).

## 4. G4: flexible dates (pilot, simplest form)
### Problem
"Datas flexíveis" appears in the calendar and in the empty state with no behavior.

### Solution (pilot, §12.4)
- "Datas flexíveis" **clears the dates**. Cards show the **nightly price**, not the total. The price sort becomes **"Menor diária"** (`sort=nightly_price`) under G-1b (cap 5,000). Cost is zero.
- "Any N nights in window W" (±N days) is **later**: it needs `avail_runs` (§12.4), caps `1 ≤ N ≤ 30`, `W ≤ 31 days`, `N ≤ W`, else **422 `flex_invalid`** (G-6). Not in this delivery.

### User flow
Guest taps "Datas flexíveis" in the calendar or in the empty state → dates clear → list shows nightly prices with the notice "Sem datas: mostrando o preço por noite".

### Acceptance criteria
1. After "Datas flexíveis", the request has no `checkin`/`checkout`, cards show the nightly price and "Menor preço total" is replaced by "Menor diária".
2. "Menor diária" over 5,000 filtered candidates falls back to relevance with `sort_unavailable: "zoom_in"` (G-1b).
3. No ±N parameter is accepted in this delivery.

## 5. API
`GET /api/busca` (list) and `GET /api/busca?modo=mapa` (pins), on top of §5, §5b and spec-busca-localidade-resolvida.

**New request params:** `sort` = `relevance` (default) | `rating` | `total_price` (needs dates) | `nightly_price` (no dates), the one list in ADR 001 §12.0 rule 5; the channel comes from the storefront (host), never from the client; the G3 params in section 3.
- `sort=total_price` without dates, or `nightly_price` with dates, is ignored and the response uses relevance.
- The cursor is §12.1's `{v:2, sort, channel, last_key, id, checkin, checkout, hospedes, filters_hash}`, plus `sort_effective` and `sort_fallback_from` under a fallback (§12.7a). A mismatch returns 400 (G-7), except a request with `sort=total_price` or `nightly_price` plus a fallback cursor, which is valid and continues in relevance order.
- **Client config** (§12.7a): `max_span_deg` (8) gates G-3 on the client; the 5,000 and 2,000 caps are exposed for information only, never for client-side gating.

**List response additions:**
```json
{
  "items": [ { "id": "...", "patrocinado": false, "...": "§5b fields" } ],
  "next_cursor": "opaque or null",
  "sort_effective": "relevance",
  "sort_fallback_from": null,
  "sort_unavailable": null,
  "zoom_in": false,
  "guard": null,
  "suggested_bbox": null
}
```
- `items[].patrocinado` mirrors `channel_rank.sponsored` for the current channel (false when no row).
- `sort_effective` is the sort actually applied; under a G-1 fallback it is `"relevance"` and `sort_fallback_from` is `"total_price"` (G-1a) or `"nightly_price"` (G-1b). The fallback holds for the whole cursor chain; price order returns only on a new search with no cursor (§12.7a).
- `guard` is `"span"` (G-3), `"candidates"` (G-2), `"price_sort"` (G-1a/G-1b) or `null` (§12.7a).
- `suggested_bbox` (the requested centre clipped to 8°, computed, no query) comes **only** with `guard: "span"`.
- **Map response (`modo=mapa`), pins and clusters (§12.7b):** the map request also sends `vp_w` and `vp_h`, the map element's size in CSS px.
  ```json
  {
    "pins": [ { "id": "...", "lat": 0, "lng": 0, "preco": 0, "sponsored": false } ],
    "clusters": [ { "lat": 0, "lng": 0, "count": 0, "bounds": [0, 0, 0, 0] } ],
    "pins_truncated": false,
    "counts_approximate": false,
    "sort_effective": "relevance",
    "sort_fallback_from": null
  }
  ```
  - 300 filtered candidates or fewer: all are pins, `clusters: []`.
  - 301–5,000: `pins` holds the **first 300 candidates in `sort_effective`** (the order the list uses, including the relevance fallback under G-1, with `sort_fallback_from`), so the map highlights the listings the list ranks first. Each pin carries its id, lat/lng, price and the `sponsored` flag from the same row (§12.3a); sponsored pins count inside the 300 and never add pins beyond it. The remaining candidates are grouped in SQL by a grid snapped to the bbox and sized from the viewport: `ceil(vp_w/64) × ceil(vp_h/64)` cells, capped at 32×32, default 16×16 without a viewport [E]. `clusters` (1,024 or fewer) holds `{lat, lng (mean), count, bounds: [s, w, n, e]}` per cell, and `pins_truncated: true` when clusters are present.
  - With dates, pins are availability-checked (the top 300 available candidates) but cluster counts are not, so `counts_approximate: true` and the UI shows "~N"; without dates the counts are exact and `counts_approximate` is false.
  - Over 5,000: `items: [], pins: [], clusters: []`, `zoom_in: true`, `guard: "candidates"`, no counts (G-4 on the map, section 7.4). There is no `acima_do_limite` field.
  - Under G-3: the same with `guard: "span"` and `suggested_bbox`. A resolved place wider than 8° opens with a map viewport inside 8°, centred on its anchor point (§12.7a).
- Errors: 422 `{ "code": "stay_over_cap" }` (G-5) and 422 `{ "code": "flex_invalid" }` (G-6, later) are distinct codes.

## 6. Guards (§12.7), explicit
| # | Trigger | Response | Guest sees |
|---|---|---|---|
| G-1a | Total-price sort with **> 2,000** filtered candidates (place or map) | 200, relevance order, `sort_unavailable: "zoom_in"`, `guard: "price_sort"`, `sort_effective: "relevance"`, `sort_fallback_from: "total_price"` | map area: "Ordenado por relevância. Aproxime o mapa ou adicione filtros para ordenar pelo preço total."; place: "Ordenado por relevância. Adicione filtros para ordenar pelo preço total." |
| G-1b | Nightly-price sort ("Menor diária") with **> 5,000** filtered candidates | as G-1a, with `sort_fallback_from: "nightly_price"` | map area: "Ordenado por relevância. Aproxime o mapa ou adicione filtros para ordenar pela menor diária."; place: "Ordenado por relevância. Adicione filtros para ordenar pela menor diária." |
| G-2 | Map-area list with **> 5,000** filtered candidates, **any sort**, any channel | 200, `items: []`, `zoom_in: true`, `guard: "candidates"` | "Há imóveis demais nesta área para mostrar a lista. Aproxime o mapa ou adicione filtros." |
| G-3 | Map span **> 8°** (`max_span_deg`) in latitude or longitude | **no query**; 200, `items: []`, `pins: []`, `zoom_in: true`, `guard: "span"`, `suggested_bbox` | "Esta área do mapa é grande demais para buscar. Aproxime o mapa." |
| G-4 | Map with **> 5,000** filtered candidates (§12.7b; the old "pins > 300" G-4 is superseded) | `items: [], pins: [], clusters: []`, `zoom_in: true`, `guard: "candidates"`; 301–5,000 is **not** a guard: top 300 pins in `sort_effective` plus `clusters`, `pins_truncated: true` | "Aproxime o mapa para ver os imóveis" (map only); 301–5,000: pins plus count bubbles, no message |
| G-5 | Stay **> 30 nights**, or a listing `max_nights` above the platform cap | 422 `code: "stay_over_cap"`, before any query (distinct from G-6) | "A estadia máxima é de 30 noites." |
| G-6 | Flexible window out of bounds (later) | 422 `flex_invalid` | — (not in this delivery) |
| G-7 | Cursor whose sort, channel or filters changed | 400 | list restarts from page 1 |
| G-9 | Backstop timeout 150 ms | 503 `retry` | "Não conseguimos carregar agora. Tentar novamente" |

- **Order in a map area:** G-3 first (no query), then G-2 for every sort, then G-1a for the total price. 2,001–5,000 candidates list normally under relevance, rating and nightly price; total price there falls back to relevance.
- On the place path, only G-1a/G-1b apply (and, on its map, the §12.7b pins and bubbles, or the G-4 pill above 5,000).
- **Guards count filtered candidates before availability** (§12.5). Zooming in or adding filters (guests included) can clear G-2 or G-1; **adding dates can't**, and no guard state offers it (section 7).
- Acceptance: each guard has a test at its limit and limit + 1, and the over-limit path executes no page query (§12.9 criterion 14).

## 7. Estados de zoom (spec de tela da UI)
Source: UI's screen spec **rev 3** (`/workspace/ui/zoom-states-spec.md`, 2026-10-21, approved by Produto), with Produto's answers to Q2, Q6, Q7, Q8 (as amended by Q10) and ADR 001 [§12.7a](../../adr/001-busca-sql-paginada.md) (Arquitetura's answers to UI, `/workspace/adr/adr001-s12-addendum-ui.md`). UI/UX owns layout; thresholds are §12.7 (section 6). Copy key: **[O]** official string, **[A]** given by Produto, **[P]** proposed by UI and accepted here; all new strings are in section 9. **Copy never shows a cap number**, because the caps may still move (§12.9).

### 7.0 Shared rules
**Guard signal (§12.7a).**
- Every guard response carries `guard`: `"span"` (G-3), `"candidates"` (G-2) or `"price_sort"` (G-1a/G-1b), next to `zoom_in` or `sort_unavailable`. The map over 5,000 candidates (G-4) returns `items/pins/clusters: []`, `zoom_in: true`, `guard: "candidates"`; `acima_do_limite` is removed (§12.7b A3). 301–5,000 candidates return the top 300 pins plus clusters with `pins_truncated: true`, which is not a guard state.
- **Copy and actions are chosen from `guard`, never from client counts.** Client config exposes `max_span_deg` (8). The 5,000 and 2,000 caps are for information only and never gate anything on the client.

**The price-sort options.** "Menor preço total" [O] and "Menor diária" [A] are **always enabled**. The client zoom-13/12 hint is dropped; the server decides (§12.7a, Q1).

**What "zoom in" means.**
- **Manual zoom** (wheel, pinch, +/−, keyboard) follows the existing rules: desktop, "Buscar ao mover o mapa" [O] is ON and re-queries after the move; mobile, search-on-move is OFF and the "Buscar nesta área" [O] button appears.
- **The "Aproximar" button [O]** is an explicit search action and always re-queries, mobile included.
  - `guard: "span"`: it fits `suggested_bbox` (the requested centre clipped to 8°). One tap always leaves G-3, though it may land in G-2, G-1 or G-4, or on a map with count bubbles.
  - `guard: "candidates"` or `"price_sort"`: it zooms **one level** on the current centre (no suggested area for these: finding one would cost a query).
- If the state is still active after a tap, the message stays, the screen-reader text says "still", and the button doesn't move.
- At maximum zoom, "Aproximar" is hidden and the first secondary action is promoted.
- The zoom animation respects `prefers-reduced-motion`.

**Exit rule.** A guard action is offered **only if it can clear the state**. G-2 and G-1 count filtered candidates **before** availability (§12.5; Q10), so zooming in or adding filters can lower the count; **adding dates can't**.

**Narrowing slot** (Produto Q2, amended by Q10). Facets have no counts (§5b), so the generic "Filtros" is used. Wherever a state has a narrowing slot, it takes **the first of**:
1. "Adicionar hóspedes" [P], when guests are unset. It opens the "Quem vai?" [O] step. The guest count is a candidate filter (`s.max_guests >= :h` inside the capped CTE, §12.5).
2. "Filtros" [P]. It opens the existing filter panel.

The filter bar stays visible, so anything not in a slot is still reachable.

**Dates are not a guard action.** "Quando?" stays in the search bar as usual; dates still give availability-checked results, total prices on the cards and the total-price sort. But they never clear G-2 or G-1 (under G-1b, adding dates even turns the price sort into total price, whose cap is lower). **No guard state offers "Adicionar datas", and no guard copy mentions dates.**

**Action limit.** Each state shows at most **1 primary action plus 2 secondary actions**.

**Back and URL** (spec-busca-localidade-resolvida item 7; Q7).
- `pushState`: "Aproximar", "Buscar nesta área", picking a place and "Ver no mapa" [P].
- `replaceState`: moves made by "Buscar ao mover o mapa", the cursor and the scroll position.
- No guard state goes in the URL. It is re-derived from `bbox`/`local_id`, filters and sort on reload or share. Back returns to the previous area and re-shows its guard state.
- **Sort fallback (§12.7a, Q4):** the URL keeps the user's `sort` (`total_price` or `nightly_price`). The response returns `sort_effective: "relevance"` and `sort_fallback_from`, and the cursor encodes both. "Mostrar mais imóveis" continues in relevance order under the same chain, even if the area has dropped under the cap. Price order returns only on a **new search with no cursor** (a zoom, filter, date, guest or place change), never mid-list.
- A guard with no list (G-2, G-3) clears the cursor and the scroll position.

**Place path vs map area** (§12.7 "Interplay", §12.7a, Q5).

| State | Resolved place (`local_id`) | Map area (`bbox`, "Área do mapa ×" [O]) |
|---|---|---|
| G-1a total price > 2,000; G-1b nightly price > 5,000 | Applies | Applies |
| G-2 list > 5,000 | **Never** | Applies (any sort, channel included) |
| G-3 span > 8° | The list never hits it. The map opens fitted to 8° at the place's anchor point, so its pins don't trip it on open. | Applies to the list **and** the pins (empty pins + `zoom_in`), before any query |
| G-4 map > 5,000 candidates (no pins) | Map only (the list is unaffected) | Map only, next to G-2 |

- "Escolha uma cidade" [O] always leaves G-2 and G-3.
- On the place path, a desktop map zoom with search-on-move ON switches the search to a map area; × restores the place (spec-busca-localidade-resolvida item 6).

**Priority.** G-3 > G-2 > G-1 > G-4. Count bubbles (`pins_truncated`) are not a guard state and are never announced as one. Only the highest active state is announced, at most one polite announcement per response; lower states stay as visible text in their own region.

**Accessibility, all states.**
- One shared polite `role="status"` region (the existing results-status region). Nothing is assertive.
- **When to announce:** on entering a state, and after a user action that leaves the result still guarded (the "still" text). Never on an unchanged automatic re-query.
- **Focus:** never moved on entering a state. After a tap, focus stays on the button while the state lasts; if the button disappears, focus goes to the results heading (`tabindex="-1"`) and the usual count is announced.
- **DOM order:** message, primary, then secondaries. All are real buttons or links.
- **Targets:** at least 24×24 px (WCAG 2.5.8); 44 px tall on mobile. The message never relies on colour alone.
- **Mobile wording:** the list/map toggle is the existing floating **"Mapa" / "Lista"** button. Guard cards that send the user back to the list use the same **"Lista"** label. "Ver no mapa" [P] is a different action: it turns a place search into a map area (7.2).

### 7.1 G-2: map-area list over 5,000 filtered candidates (`guard: "candidates"`)
Response: `items: []`, `zoom_in: true`, `guard: "candidates"`.
- **Message** [P]: "Há imóveis demais nesta área para mostrar a lista. Aproxime o mapa ou adicione filtros." / "There are too many listings in this area to show a list. Zoom in or add filters."
- **Actions:**
  - Primary: "Aproximar" [O], one level.
  - Secondary 1: the narrowing slot ("Adicionar hóspedes" when guests are unset, otherwise "Filtros").
  - Secondary 2: "Escolha uma cidade" [O]. It focuses "Onde?" [O] with the list open (mobile: the full-screen "Onde?" step). A place never hits G-2, so this always clears the state.
  - At maximum zoom, "Escolha uma cidade" becomes primary, the narrowing slot is secondary 1, and "Filtros" is secondary 2 when the slot is "Adicionar hóspedes".
- **Placement:**
  - Desktop 1280: the block replaces the cards at the top of the list column, under the results header. The sort control stays; the count line and "Mostrar mais imóveis" are hidden. The map shows only the G-4 pill (the map is over 5,000 too), with no button of its own.
  - Mobile 390, list view: the block replaces the cards. "Aproximar" opens the map view and zooms there. The floating "Mapa" button stays.
  - Mobile 390, map view: one card in the carousel position. "Buscar nesta área" is hidden while it shows.
- **Screen reader:** the message is announced on entry. After a guarded action [P]: "Ainda há imóveis demais. Aproxime mais o mapa ou adicione filtros." / "Still too many listings. Zoom in further or add filters."
- **Combines with:** G-3 replaces it. It hides G-1 (no list; the sort stays in the URL). G-4 shows on the map, silently.
- **AC:**
  1. A response with `guard: "candidates"` shows the block and no cards, with no client count check. A mocked 5,000/5,001 pair covers the edge; the server limit test is Teste's (§12.9 item 14).
  2. Never shown with `local_id`.
  3. "Escolha uma cidade" reaches a list in one choice. "Aproximar" zooms one level and pushes one history entry.
  4. At most 1 primary + 2 secondary actions. The slot shows "Adicionar hóspedes" when guests are unset and "Filtros" otherwise.
  5. Announced once on entry and once per guarded action; never on an unchanged automatic re-query.
  6. Focus is never lost when the block disappears.
  7. "Adicionar datas" never appears as a guard action, and no guard text mentions dates. Adding dates through "Quando?" stays possible, and the state stays.

### 7.2 G-1a total price over 2,000 / G-1b nightly price over 5,000: fallback to relevance (`guard: "price_sort"`)
- G-1a's cap is **2,000** and needs dates. G-1b, "Menor diária" [A], is the price sort when the search has no dates (§12.4), cap **5,000**. Both apply on either path.
- Response: relevance order plus `sort_unavailable: "zoom_in"`, `guard: "price_sort"`, `sort_effective: "relevance"` and `sort_fallback_from`: `"total_price"` (G-1a) or `"nightly_price"` (G-1b; Q11). Copy is picked from `sort_fallback_from`.
- In a map area, a G-1a case with 2,001–5,000 candidates lists normally, in relevance order.

**Message**

| | Map area | Resolved place |
|---|---|---|
| Total price | [P] "Ordenado por relevância. Aproxime o mapa ou adicione filtros para ordenar pelo preço total." / "Sorted by relevance. Zoom in or add filters to sort by total price." (replaces the former official string) | [P] "Ordenado por relevância. Adicione filtros para ordenar pelo preço total." / "Sorted by relevance. Add filters to sort by total price." |
| Nightly price | [P] "Ordenado por relevância. Aproxime o mapa ou adicione filtros para ordenar pela menor diária." / "Sorted by relevance. Zoom in or add filters to sort by lowest nightly price." (replaces Produto's Q8 line) | [P] "Ordenado por relevância. Adicione filtros para ordenar pela menor diária." / "Sorted by relevance. Add filters to sort by lowest nightly price." |

"Aproxime o mapa…" never appears on a `local_id` list.

**Actions (at most 1 + 2)**

| Context | Primary | Secondary |
|---|---|---|
| Map area | "Aproximar" [O] (one level) | narrowing slot; "Escolha uma cidade" [O] |
| City | "Escolha um bairro" [P] (opens "Onde?" pre-filled with the city, so its neighborhoods are listed) | narrowing slot; "Ver no mapa" [P] |
| Neighborhood, beach or region (Q6) | 1st item of the list below | 2nd item of the list; "Ver no mapa" [P] |

For a neighborhood, beach or region, the list runs: (1) "Adicionar hóspedes" (only when guests are unset); (2) "Filtros"; (3) "Mudar ordenação" [P] (opens the sort control; choosing another sort ends the fallback). So with guests set: "Filtros", "Mudar ordenação", "Ver no mapa". With guests unset: "Adicionar hóspedes", "Filtros", "Ver no mapa" ("Mudar ordenação" drops off, but the sort control is still next to the text).

"Ver no mapa" [P] fits the map to the place and turns the search into a map area ("Área do mapa ×", where × restores the place), with `pushState`; on mobile it also opens the map view. It clears the state only in two steps (the fitted map has the same candidates, so the user then needs "Aproximar"); it is kept because it is the only way to narrow by area (Q6).

- **Placement:** desktop, one line under the results header, tied to the sort control with `aria-describedby`, actions inline after the text; the sort menu has no disabled option. Mobile: the same line above the first card, at most 2 lines, with 44 px text buttons on the next line. Cards, count and "Mostrar mais imóveis" are unchanged; the list keeps going in relevance order.
- **Screen reader:** the fallback is announced once. After a guarded new search [P]: "Ainda ordenado por relevância. …" / "Still sorted by relevance. …", followed by the context's message. When price order returns [P]: "Ordenado pelo menor preço total." / "Sorted by lowest total price."; "Ordenado pela menor diária." / "Sorted by lowest nightly price."
- **Combines with:** G-2 and G-3 replace it. G-4 can't be active with G-1 in the same map area (G-4 needs more than 5,000 candidates, where G-2 hides the list). From 2,001 to 5,000 candidates the map shows pins plus count bubbles, which are not a state; the pins follow `sort_effective` (relevance). On a resolved place, G-1 and G-4 can coexist (the place list falls back while its map is over 5,000): the list line is announced and the pill is silent.
- **AC:**
  1. With `guard: "price_sort"`, the list is in relevance order with the line for its sort and path. The client never disables the price options and never gates on counts. Mocked edges: 2,000/2,001 for total price, 5,000/5,001 for nightly price. The copy follows `sort_fallback_from`: `"total_price"` gives the total-price line, `"nightly_price"` the "menor diária" line.
  2. The URL keeps the user's `sort`. "Mostrar mais imóveis" with the returned cursor continues in relevance order and never gets a 400 (G-7), even if the area has dropped under the cap, for both `sort_fallback_from` values.
  3. Price order returns only on a new search with no cursor (a zoom, filter, date, guest or place change), never mid-list. It is announced once.
  4. On a `local_id` list the text never says "Aproxime o mapa". In every context, the text names only exits that can clear the state (zoom or filters), never dates.
  5. Actions follow the table, at most 1 + 2. A neighborhood, beach or region with guests set shows "Filtros", "Mudar ordenação" and "Ver no mapa"; with guests unset, "Adicionar hóspedes" is primary. "Adicionar datas" never appears, including under G-1b.
  6. "Ver no mapa" switches to a map area with one history entry, and × restores the place with its relevance list.
  7. Each fallback and each return is announced once.

### 7.3 G-3: map span over 8° (`guard: "span"`)
Response: `zoom_in: true`, `guard: "span"` and `suggested_bbox`, before any query; the list and the pins are both empty (§12.7a, Q5).
- **Message** [P]: "Esta área do mapa é grande demais para buscar. Aproxime o mapa." / "This map area is too large to search. Zoom in."
- **Actions:** primary "Aproximar" [O], which fits `suggested_bbox` (one tap always exits); secondary 1 "Escolha uma cidade" [O]. No narrowing slot: filters don't lift a span guard.
- **Placement:** desktop, the block sits in the list column, and a centred pill at the top of the map carries the same "Aproximar" (the only map pill with an action). Mobile: list view as G-2; map view, the carousel-position card. The client may skip the request when the span is over `max_span_deg` from config and show the state with a locally clipped bbox; the behaviour is identical.
- **Screen reader:** announced on entry; no "still" text is needed.
- **Combines with:** replaces all other states; after the tap, the next state is announced by itself.
- **AC:**
  1. `guard: "span"` shows the state with empty list and pins, on either axis. A span exactly at `max_span_deg` queries normally.
  2. One "Aproximar" fits `suggested_bbox` and leaves G-3.
  3. Changing `max_span_deg` in config changes the behaviour with no code change. The 5,000 and 2,000 caps are never used to gate.
  4. Never shown for a `local_id` list.
  5. A place wider than 8° opens with its map fitted to 8° at its anchor point and its list unaffected, so G-3 doesn't fire on open.

### 7.4 Map pins, count bubbles and G-4 (§12.7b)
Sources: UI's zoom states **rev 3.1** (`/workspace/ui/zoom-states-spec.md`, G-4 aligned with §12.7b) and UI's **map count bubbles and sponsored pins** screen spec **rev 2.1** (`/workspace/ui/map-count-bubbles-spec.md`, 2026-10-21), with Arquitetura's A1–A5 (ADR 001 [§12.7b](../../adr/001-busca-sql-paginada.md)) and Produto's Q-P1/Q-P2. The bubble and the sponsored tag are listed under "Componentes" in [spec-telas-busca-mapa-upload-fotos.md](spec-telas-busca-mapa-upload-fotos.md) §1.

| Filtered candidates in the map bbox | Map response | Map shows | List |
|---|---|---|---|
| ≤ 300 | `pins` (all), `clusters: []` | price pins only | normal |
| 301–5,000 | `pins` (top 300 in `sort_effective`), `clusters` with `{lat, lng, count, bounds:[s,w,n,e]}`, `pins_truncated: true` | pins + count bubbles; **no pill** | normal: all results, 20 per load, "Mostrar mais imóveis" |
| > 5,000 (G-4) | `items: [], pins: [], clusters: []`, `zoom_in: true`, `guard: "candidates"`; no counts | no pins, no bubbles; the pill "Aproxime o mapa para ver os imóveis" [O] | map area: the G-2 block (7.1); resolved place: normal |
| span > 8° | same as above + `guard: "span"`, `suggested_bbox` | G-3 (7.3) | G-3 |

- `acima_do_limite` is gone (§12.7b A3). The pill is driven by the map response's `guard: "candidates"`.
- **Bubbles are not a guard state:** no message, no action row, no live announcement.

**Pins.**
- **Order (A5):** pins follow `sort_effective`, so they match the order of the list actually shown, including under the G-1 fallback (`sort_fallback_from`). Each pin carries `{id, lat/lng, preco, sponsored}`.
- **With dates (A4):** the pins are availability-checked (the top 300 *available* candidates); the bubble counts aren't, so the response sets `counts_approximate: true`. Without dates the counts are exact and `counts_approximate` is false.
- **Pin cap and sponsored pins (Produto, 2026-10-21):** sponsored pins count **inside** the 300-pin cap and never add pins beyond it. A sponsored listing is a pin only if it is among the top 300 in `sort_effective`.

**Sponsored pins (Produto, Q-P2; UI rev 2.1 §5).** Sponsored pins are part of the 300 pins, never extra. A pin with `sponsored: true` **always** shows "Patrocinado" [O] (EN "Sponsored") on the pin itself, at every zoom and in every state, because any paid boost is labelled (Rafael; section 1). Position and label come from the **same `channel_rank` row** (§12.3a.1); the pin's `sponsored` flag is that row's flag.
- The price pill is unchanged: same size, and the price text is never shortened. A compact **text tag** "Patrocinado" sits **above** the pill, attached to it like a tab, with the same width or narrower and smaller text, a 1 px dark border and its own fill. The cue is the **word**, not a colour or an icon (1.4.1); tag text ≥ 4.5:1 and tag border ≥ 3:1.
- Pin states: normal, the tag shows; selected (inverted), the tag inverts with the pin; visited, the tag stays at full contrast.
- **Accessible name** of the pin button [P]: "Anúncio patrocinado. R$ 2.250 total." / "Sponsored listing. R$ 2,250 total." With no dates the price part follows the card's existing price text (nightly). The sponsored text comes first; the tag is not a separate tab stop.
- **Never clustered:** sponsored pins are excluded from client clustering and always drawn on their own, above clusters and bubbles; two overlapping sponsored pins both stay drawn, the selected one on top.
- **Bubbles never carry "Patrocinado":** a listing inside a bubble is not drawn as a pin, so no position is shown for it. Display merging merges bubbles only, never pins.
- Mobile uses the same tag at the same text size, never truncated or replaced by an icon; the 44 px target covers the pin and its tag together. The desktop popup and the mobile carousel cards show "Patrocinado" by the card rule.

**Relation to the list (Produto, Q-P1, accepted for the pilot).** List cards beyond the top 300 pins **highlight nothing on the map**: hovering or focusing such a card changes nothing on the map and shows no message. List items carry no lat/lng (§5b) and **no cell id**, to keep the payload small. Cards that have a pin keep "Card e pin ficam destacados juntos" (map spec §1). Mobile carousel: cards of rendered pins only; bubbles are not in it. *Backlog candidate (note only, not added):* "Map highlight for listings outside the top-300 pins: needs a cell id or a coarse position per list item (Arquitetura)."

**Grid (A2).** The client sends **its map viewport** as `vp_w`/`vp_h` in CSS px (the map element, not the window: desktop, the map column, 40% of 1280 = 512 px wide; mobile, the full-screen map), on every map request, including after a resize or rotation. The server uses `ceil(vp_w/64) × ceil(vp_h/64)` cells, capped at 32×32; with no viewport it defaults to 16×16. Cells are therefore about 64 px or more, so overlaps are rare. **Fallback:** bubbles whose boxes overlap at the current zoom are merged for display; the merged count is the sum (keeping "~" if `counts_approximate`), and a tap fits the union of the members' `bounds`.

**Bubble vs client cluster.** The map spec's "Cluster" stays: the client-side cluster of rendered pins (MapLibre, over at most 300 points). The two are never merged.

| | Client cluster (existing) | Count bubble (new) |
|---|---|---|
| Represents | rendered pins overlapping at this zoom | listings **not** drawn as pins |
| Shape | **circle**, solid brand fill, white number | **rounded square**, light neutral fill, dark 1.5 px border, dark number, a **"+"** zoom glyph |
| Layer | above bubbles | below pins and clusters |
| Tap | expands to its pins | fits `bounds` and searches |

Shape, glyph and text tell them apart, never colour alone (1.4.1).

**Bubble states and count format.**
- Default: ≥ 24×24 CSS px (2.5.8), 44 px on mobile. Hover (desktop): thicker border, light shadow, pointer; the visible tooltip is the accessible name. Focus: 2 px ring outside the border, ≥ 3:1 against the map, never obscured by the search bar, toggle or carousel (2.4.11; the map pans it into view). Pressed: darker fill, no scale change under `prefers-reduced-motion`. Area just tapped: the tapped `bounds` outlined (2 px, dark, not colour-only) until the next move or search. Loading after tap: the "+" becomes a spinner; old pins and bubbles stay dimmed with "Atualizando resultados…" [O] (EN [P] "Updating results…"); `aria-busy="true"` on the map region.
- Count: exact with the locale separator, pt-BR "1.234", EN "1,234"; **approximate** (`counts_approximate: true`): pt-BR "~1.234", EN "~1,234". No compact "1,2 mil" form (a count is ≤ 4,700).

**Tap behaviour and history (A1).** A tap, click or Enter/Space on a bubble fits its **`bounds`** (or the union for a merged bubble) with the map's padding; the animation respects reduced motion. It is **the same as "Aproximar" [O]** (7.0): one map and list search for the new view, `pushState`, one history entry; Back returns to the previous view with its bubbles.
- Desktop with "Buscar ao mover o mapa" [O] ON: the tap's search replaces the move-triggered one (no second search, no `replaceState` for the same move).
- Desktop with the toggle OFF, and mobile: the tap searches at once; "Buscar nesta área" [O] doesn't appear after a bubble tap, only after a manual pan or zoom.
- On a resolved place: the search becomes "Área do mapa ×" [O], and × restores the place.

**Accessibility.**
- Each bubble is a `<button>`. Accessible names [P]: exact, "1.234 imóveis nesta área. Aproximar." (singular "1 imóvel nesta área. Aproximar.") / "1,234 listings in this area. Zoom in." (singular "1 listing in this area. Zoom in."); approximate, "Cerca de 1.234 imóveis nesta área. Aproximar." (singular "Cerca de 1 imóvel nesta área. Aproximar.") / "About 1,234 listings in this area. Zoom in." (singular "About 1 listing in this area. Zoom in."). The "~" is visual only and never read out.
- Keyboard order: pins (in `sort_effective` order), then bubbles in reading order (north to south, west to east). A "Pular o mapa" [P] / "Skip the map" link comes before the map layer. The list stays the accessible path (map spec §1).
- Focus after a zoom goes to the map region (`tabindex="-1"`, labelled "Mapa"). No live region for bubbles or truncation; the existing list-count update still applies. Contrast: number ≥ 4.5:1; border and glyph ≥ 3:1; focus ring ≥ 3:1.

**G-4: map over 5,000 candidates** (rev 3.1).
- **Message** [O]: "Aproxime o mapa para ver os imóveis" / "Zoom in to see listings".
- **Actions:** primary "Aproximar" [O], one level. Secondary, mobile map view only: "Lista" (the same action as the floating "Lista" button).
- **Placement:** desktop, a centred pill at the top of the map, under the "Buscar ao mover o mapa" toggle, with "Aproximar" inline; the attribution stays visible. Mobile map view: one card replaces the carousel, with the message, "Aproximar" and "Lista". Mobile list view: nothing.
- **Screen reader:** announced only when the map is the main view (mobile map view) or right after a keyboard zoom or "Aproximar" on the map; silent on desktop while the list or the G-2 block is shown.
- **Combines with:** G-3 replaces it. In a map area it always comes with G-2: the G-2 block is the one announced, and the pill is silent. On a resolved place it is alone: the list is normal.

**AC** (mocked `modo=mapa` responses):
1. **300 candidates:** 300 pins, `clusters: []`, no bubbles, no pill.
2. **301 candidates:** 300 pins and bubbles summing to 1, with **no pill** and no announcement. Exact: the name is "1 imóvel nesta área. Aproximar." With `counts_approximate: true`: the bubble shows "~1" and the name is "Cerca de 1 imóvel nesta área. Aproximar."
3. **5,000 candidates:** 300 pins plus bubbles, no pill. Without dates the bubbles sum to exactly 4,700 ("4.700" / "4,700"), and pins plus bubbles equal the candidate count. With dates every bubble shows "~", and no AC checks the sum against the list.
4. **5,001 candidates:** the response has `items/pins/clusters: []`, `zoom_in: true` and `guard: "candidates"`. The map shows the pill with no pins and no bubbles. The list shows the G-2 block in a map area, and is normal on a resolved place. No code reads `acima_do_limite`.
5. **Tap or Enter on a bubble:** it fits exactly that cluster's `bounds`, runs one search and pushes one history entry; with desktop search-on-move ON there is no extra search; on mobile there is no "Buscar nesta área"; Back restores the previous view.
6. **Grid:** every map request sends `vp_w`/`vp_h` of the map element. At the default 1280 and 390 layouts, no two bubbles overlap in the mocked fixtures. When they do overlap (a fixture with close means), they merge with the summed count ("~" kept) and a tap fits the union of their bounds.
7. **Pin order:** follows `sort_effective` (relevance, rating, nightly price; under the G-1 fallback the pins match the relevance list's first 300).
8. **Sponsored pin (Q-P2):** a pin with `sponsored: true` shows the "Patrocinado" tag in the normal, selected and visited states, on desktop and mobile, at every zoom; the price is unchanged; its name starts with "Anúncio patrocinado"; it is never absorbed into a client cluster; a greyscale screenshot still shows the tag; `sponsored: false` shows no tag; bubbles never show the tag.
9. **Sponsored inside the cap:** sponsored pins count inside the 300-pin cap and never add pins beyond it (a response with 300 pins, some sponsored, draws exactly 300); a sponsored listing outside the top 300 in `sort_effective` is not a pin.
10. **Q-P1:** hovering or focusing a card whose listing is not among the pins changes nothing on the map; a card with a pin highlights it. List items carry no cell id.
11. **Visual and accessibility:** bubbles and clusters differ in shape and glyph (greyscale check); the states above exist, and loading sets `aria-busy`; the names follow the accessibility rules, exact and approximate; Tab order is pins then bubbles, with a skip link; focus goes to the map region after a zoom; targets are ≥ 24 px (44 px on mobile); a focused element is never obscured.
12. On mobile, "Lista" returns to the list at the same scroll position. No "Ver lista" label appears anywhere.
13. Neither the pill nor a bubble covers the attribution or the zoom control.

G-5, G-6, G-7 and G-9 are error states, not zoom states (section 6).

## 8. Open points
**Resolved and folded in (2026-10-21):**
- Arquitetura's notes (`/workspace/adr/notes-produto-spec-busca.md`): demotion folded into the stored score with `penalty_until` and a daily sweep (section 1); 422 `stay_over_cap` (section 6); the fallback is relevance, not `id` (§11.1 superseded by §12 G-1a).
- Negocios calibration (`/workspace/negocios/calibracao-busca.md`): weights, demotion and G2 prior (sections 1-2).
- ADR 001 §12.7a (#181) and UI rev 3:
  - **Q1:** the client zoom hint is dropped; the server decides through G-1a. The price sorts are always enabled.
  - **Q3:** every guard carries `guard` (`span` / `candidates` / `price_sort`); `max_span_deg` (8) is in client config; the 5,000 and 2,000 caps are information only; `suggested_bbox` comes only with `guard: "span"`.
  - **Q4:** `sort_effective` and `sort_fallback_from` are in the response and carried in the cursor; the fallback holds for the whole cursor chain, and price order returns only on a new search with no cursor.
  - **Q5:** G-3 also covers the pins (empty pins + `zoom_in`); a place wider than 8° opens inside 8°, centred on its anchor point; the place-path list is unaffected.
  - **Q10:** guards count candidates before availability, so dates never clear a guard. Guard states offer only actions that can clear them: "Adicionar datas" is removed from every guard state; the narrowing slot is "Adicionar hóspedes" when guests are unset (a candidate filter, §12.5), otherwise "Filtros"; the copy says "aproxime o mapa ou adicione filtros" (Produto approved rev 3).
  - **Q11:** the "Menor diária" fallback returns `sort_fallback_from: "nightly_price"` with `guard: "price_sort"`.
- ADR 001 [§12.7b](../../adr/001-busca-sql-paginada.md) (#184):
  - **Q9:** the pin cap stays 300. For 301–5,000 filtered candidates the map gets the top 300 pins in `sort_effective` plus SQL grid clusters, with `pins_truncated: true`; tapping a bubble zooms to its bounds. Over 5,000, G-2 applies unchanged (sections 5, 6, 7.4).
- ADR 001 §12.7b count bubbles (A1–A5, #188), UI's zoom states rev 3.1 and map count bubbles rev 2.1 (section 7.4):
  - **Q-A1 (cell bounds):** each cluster returns `bounds: [s, w, n, e]`; a tap fits it.
  - **Q-A2 (grid vs viewport):** the client sends `vp_w`/`vp_h`; the grid is `ceil(vp_w/64) × ceil(vp_h/64)`, capped at 32×32, default 16×16. Display merging stays as a rare fallback.
  - **Q-A3 (fields above 5,000):** `items: [], pins: [], clusters: []`, `zoom_in: true`, `guard: "candidates"`, no counts; span adds `suggested_bbox`. `acima_do_limite` is removed; the ADR §5 and §12.7 G-4 row are marked superseded by §12.7b (#188).
  - **Q-A4 (availability in counts):** with dates, pins are availability-checked and cluster counts are not (`counts_approximate: true`, "~N", "Cerca de…"); without dates counts are exact.
  - **Q-A5 (effective sort for pins):** the map response returns `sort_effective` and `sort_fallback_from`; pins follow `sort_effective`.
  - **Bubble screen spec from UI:** delivered (rev 2.1) and folded into section 7.4 and section 9; closed.
- Produto, 2026-10-21 (section 7.4):
  - **Q-P1:** accepted for the pilot: list cards beyond the top 300 pins highlight nothing on the map; list items carry no cell id, to keep the payload small. Backlog candidate only (a cell id or coarse position per list item); not added to the backlog.
  - **Q-P2:** a sponsored pin always shows the "Patrocinado" tag, and is never clustered; position and label come from the same `channel_rank` row (Rafael: any paid boost is labelled).
  - **Sponsored pins and the pin cap:** sponsored pins count inside the 300-pin cap and never add pins beyond it.
- BD (`rank_penalty` / `penalty_until`): `smallint` permille multiplier with a 1–1000 CHECK, nullable `timestamptz`, and a pair CHECK; set in the cancel transaction and reset by the sweep (section 1). In ADR 001 §12.11 (#188).
- Produto: UI's Q2, Q6, Q7, Q8 (section 7, as amended by Q10).

**Still open:**
- None for this spec.

**Deferred (not open):**
- **Per-view cap on sponsored pins** (UI rev 2.1 §10, closed there as deferred: never-clustered sponsored pins may clutter dense views): deferred until paid placement is switched on. Both channel weights are 0 by default, and switching paid placement on is a later Rafael decision. Until then, sponsored pins are bounded by the 300-pin cap.
- **Map performance measurement** (EXPLAIN of the 4,999-candidate map with clusters, map payload size, mobile p95): deferred by Rafael (#187); the §12.7b figures stay [E].

## 9. pt-BR strings (official copy table)
To add to the "Textos da interface (pt-BR, oficial)" table in spec-busca-localidade-resolvida.md; they go into the i18n PT keys, EN/ES follow.

| English | pt-BR |
|---|---|
| Relevance | Relevância |
| Best rated | Melhor avaliação |
| Lowest total price | Menor preço total |
| Lowest nightly price | Menor diária |
| Sponsored | Patrocinado |
| Sponsored listing (screen reader) | Anúncio patrocinado |
| Zoom in to see listings | Aproxime o mapa para ver os imóveis |
| The maximum stay is 30 nights. | A estadia máxima é de 30 noites. |
| We couldn't load this now. Try again | Não conseguimos carregar agora. Tentar novamente |
| Filters | Filtros |
| Filters (N) | Filtros (N) |
| Show listings | Mostrar imóveis |
| Clear filters | Limpar filtros |
| Property type | Tipo de imóvel |
| Bedrooms | Quartos |
| Beds | Camas |
| Bathrooms | Banheiros |
| Pets allowed | Aceita pets |
| Instant booking | Reserva instantânea |
| Self check-in | Check-in autônomo |
| Accessibility | Acessibilidade |
| Amenities | Comodidades |
| Full refund until <date> | Reembolso total até <data> |
| Price per night | Preço por noite |
| Minimum rating | Nota mínima |
| Pick dates to use this filter | Escolha as datas para usar este filtro |
| Flexible dates | Datas flexíveis |
| No dates: showing the price per night | Sem datas: mostrando o preço por noite |
| Ranking (admin menu) | Ranking |
| Paid placement weight | Peso de posição paga |
| Own-network weight | Peso da própria rede |
| Save weights | Salvar pesos |
| Updating the order (up to 1 min) | Atualizando a ordem (até 1 min) |
| Scheduled | Agendado |
| Active since <date and time> | Ativo desde <data e hora> |
| A boost can take up to 5 minutes to start. | O destaque pode levar até 5 minutos para começar. |
| Paid placement is off in this channel. | A posição paga está desligada neste canal. |
| *UI zoom states, rev 3 (section 7), [P]:* | |
| There are too many listings in this area to show a list. Zoom in or add filters. | Há imóveis demais nesta área para mostrar a lista. Aproxime o mapa ou adicione filtros. |
| Still too many listings. Zoom in further or add filters. | Ainda há imóveis demais. Aproxime mais o mapa ou adicione filtros. |
| Sorted by relevance. Zoom in or add filters to sort by total price. | Ordenado por relevância. Aproxime o mapa ou adicione filtros para ordenar pelo preço total. |
| Sorted by relevance. Add filters to sort by total price. | Ordenado por relevância. Adicione filtros para ordenar pelo preço total. |
| Sorted by relevance. Zoom in or add filters to sort by lowest nightly price. | Ordenado por relevância. Aproxime o mapa ou adicione filtros para ordenar pela menor diária. |
| Sorted by relevance. Add filters to sort by lowest nightly price. | Ordenado por relevância. Adicione filtros para ordenar pela menor diária. |
| Still sorted by relevance. … | Ainda ordenado por relevância. … |
| Sorted by lowest total price. | Ordenado pelo menor preço total. |
| Sorted by lowest nightly price. | Ordenado pela menor diária. |
| This map area is too large to search. Zoom in. | Esta área do mapa é grande demais para buscar. Aproxime o mapa. |
| Pick a neighborhood | Escolha um bairro |
| Change sort | Mudar ordenação |
| Add guests | Adicionar hóspedes |
| Show on map | Ver no mapa |
| *UI map count bubbles and sponsored pins, rev 2.1 (section 7.4), [P]; the count is formatted with the locale separator:* | |
| 1,234 listings in this area. Zoom in. | 1.234 imóveis nesta área. Aproximar. |
| 1 listing in this area. Zoom in. | 1 imóvel nesta área. Aproximar. |
| About 1,234 listings in this area. Zoom in. | Cerca de 1.234 imóveis nesta área. Aproximar. |
| About 1 listing in this area. Zoom in. | Cerca de 1 imóvel nesta área. Aproximar. |
| Skip the map | Pular o mapa |
| Sponsored listing. R$ 2,250 total. | Anúncio patrocinado. R$ 2.250 total. |
| Updating results… | Atualizando resultados… (existing, map spec §1) |

Approximate bubble counts show "~1.234" (pt-BR) / "~1,234" (EN); the "~" is visual only. The sponsored pin tag reuses "Sponsored" / "Patrocinado" above, and its accessible name starts with "Anúncio patrocinado" / "Sponsored listing" (the price part follows the card's price text).

Superseded and removed (rev 3): "Sorted by relevance. Zoom in to sort by total price." (former official string; replaced in the official table), "Zoom in to sort by total price" (former official disabled-option hint; removed from the official table, since the price sorts are never disabled), "Sorted by relevance. Zoom in to sort by lowest nightly price." (Produto's Q8 line), "Add dates" / "Adicionar datas" (no longer a guard action), "Show list" / "Ver lista" (the existing "Lista" is used), and the rev-1 G-2, "still" and place-path lines.

Boost and label copy for hosts is UI's, reviewed by Jurídico (§12.3a.3); the lines above are the starting proposal.

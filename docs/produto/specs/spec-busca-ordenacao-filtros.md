# Spec: search ranking, sorts, filters and flexible dates (G1, G2, G3, G4; draft, 2026-10-21)

**Base:** [ADR 001 §12](../../adr/001-busca-sql-paginada.md) (rev 3, #179) and [docs/decisions.md](../../decisions.md) (2026-10-21, search sorts and filters; G1 and G13 decisions). Product sources: `docs/produto/planejamento/backlog.md` ("Competitor gaps": G1, G2, G3, G4, G13) and [spec-busca-localidade-resolvida.md](spec-busca-localidade-resolvida.md) (resolved place, map area, combobox, official copy table).
**Scope:** what the guest sees and what the API returns. How each option is stored and indexed is §12 (Arquitetura, BD). Screen layout of the zoom states is UI's (section 7, merged from UI's screen spec).
**Aligned:** spec-01 §2 and spec-busca-localidade-resolvida item 8 now carry the 2,000-candidate total-price cap (G-1a) with a relevance fallback; their zoom-13/12 hint is superseded by section 7.
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

  - **Host cancellation demotion** (spec-09 §5: "perde o destaque na busca por 90 dias"; Negocios calibration, settings): **×0.7** for the 1st host-responsible cancellation in 90 days, **×0.5** from the 2nd. Only cancellations whose G14 `responsible` = host count; others don't.
    - Arquitetura (`/workspace/adr/notes-produto-spec-busca.md` (a)): the penalty is **folded into the stored, indexed score** (`rank_base` in `listing_search` and each `channel_rank.score`; Arquitetura's "rank_score"), never subtracted at query time. `listing_search` stores `rank_penalty` and `penalty_until` (timestamptz).
    - A host cancellation sets both and recomputes the listing's rows **in the same transaction**. A **daily `rank:sweep`** clears expired penalties and recomputes those rows (≤ 24 h staleness on expiry [E]). No other time factor in the score; no new index.
    - Follow-up: column types and CHECKs go to BD in the schema spec.
  - No price-vs-comparable or popularity factor now (they need per-date or behavioural data).
- **Per-channel score `channel_rank.score`** (§12.2). A channel has its own factor weights plus the two commercial weights:
  - `peso_pago` (paid placement, default 0): applies only to listings with a **live boost** in that channel.
  - `peso_rede` (own-network preference, default 0): applies to listings managed by the channel's own organization or node subtree.
  - Both **default to 0**. With every weight of a channel at its default there are no `channel_rank` rows and the base order serves (§12.2 option c).
- **Who configures:** only **admin_plataforma**, in Gerenciar → Canais → Ranking. Every change is logged (author, channel, old and new value). Other roles don't see the section; the API returns 403.
- **"Patrocinado" label:** shown on the card and the detail page **in that channel** whenever the listing's position in that channel got a paid boost, i.e. its `channel_rank` row has `sponsored = true`. A boost can only be bought for a channel whose `peso_pago` > 0, so the label appears exactly when the paid weight is above 0 for that listing in that channel. Position and label always come from the **same row** (§12.3a.1).
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
4. A listing whose row in channel X has `sponsored = true` shows "Patrocinado" (text, not only an icon, with screen-reader text) on its card and detail page in X; the same listing in a channel where its row is not sponsored, or has no row, shows no label. A boosted position never appears without the label, and the label never appears without the boost.
5. Only admin_plataforma can read or change the weights; any other role gets 403 and doesn't see the menu item. Each change creates an audit entry.
6. A boost can't be bought for a channel with `peso_pago` = 0 (422 with the message).
7. With a simulated clock (§12.9 criterion 16): billing starts at `live_from`; "Agendado" shows until then; stale minutes are not billed; a cancel before go-live bills 0; go-live is ≤ 5 min after purchase.
8. A host-responsible cancellation multiplies the listing's score by 0.7 (1st in 90 days) or 0.5 (2nd onwards) in the same transaction, in `listing_search` and every `channel_rank` row; a cancellation with `responsible` ≠ host changes nothing; the daily sweep restores the score after `penalty_until` (≤ 24 h), with no other time-based change to `rank_base`.
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
Guest picks "Melhor avaliação" in the sort control; the list reorders; the choice stays in the URL (`ordem=avaliacao`).

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
- "Datas flexíveis" **clears the dates**. Cards show the **nightly price**, not the total. The price sort becomes **"Menor diária"** (`ordem=preco_noite`) under G-1b (cap 5,000). Cost is zero.
- "Any N nights in window W" (±N days) is **later**: it needs `avail_runs` (§12.4), caps `1 ≤ N ≤ 30`, `W ≤ 31 days`, `N ≤ W`, else **422 `flex_invalid`** (G-6). Not in this delivery.

### User flow
Guest taps "Datas flexíveis" in the calendar or in the empty state → dates clear → list shows nightly prices with the notice "Sem datas: mostrando o preço por noite".

### Acceptance criteria
1. After "Datas flexíveis", the request has no `checkin`/`checkout`, cards show the nightly price and "Menor preço total" is replaced by "Menor diária".
2. "Menor diária" over 5,000 filtered candidates falls back to relevance with `sort_unavailable: "zoom_in"` (G-1b).
3. No ±N parameter is accepted in this delivery.

## 5. API
`GET /api/busca` (list) and `GET /api/busca?modo=mapa` (pins), on top of §5, §5b and spec-busca-localidade-resolvida.

**New request params:** `ordem` = `relevancia` (default) | `avaliacao` | `preco_total` (needs dates) | `preco_noite` (no dates); `canal` comes from the storefront (host), never from the client; the G3 params in section 3.
- `ordem=preco_total` without dates, or `preco_noite` with dates, is ignored and the response uses relevance.
- The cursor is §12.1's `{v:2, sort, channel, last_key, id, checkin, checkout, hospedes, filters_hash}`; a mismatch returns 400.

**List response additions:**
```json
{
  "items": [ { "id": "...", "patrocinado": false, "...": "§5b fields" } ],
  "next_cursor": "opaque or null",
  "ordem": "relevancia",
  "sort_unavailable": null,
  "zoom_in": false,
  "guard": null
}
```
- `items[].patrocinado` mirrors `channel_rank.sponsored` for the current channel (false when no row).
- `ordem` is the sort actually applied (it may differ from the request after a guard; contract to confirm, section 8, Q4).
- `guard` is `"candidates"` (G-2), `"span"` (G-3) or `null` (Arquitetura).
- Errors: 422 `{ "code": "stay_over_cap" }` (G-5) and 422 `{ "code": "flex_invalid" }` (G-6, later) are distinct codes.

## 6. Guards (§12.7), explicit
| # | Trigger | Response | Guest sees |
|---|---|---|---|
| G-1a | Total-price sort with **> 2,000** filtered candidates (place or map) | 200, relevance order, `ordem: "relevancia"`, `sort_unavailable: "zoom_in"` | "Ordenado por relevância. Aproxime o mapa para ordenar pelo preço total." |
| G-1b | Nightly-price sort ("Menor diária") with > 5,000 filtered candidates | same as G-1a | "Ordenado por relevância. Aproxime o mapa para ordenar pela menor diária." |
| G-2 | Map-area list with **> 5,000** filtered candidates, **any sort**, any channel | 200, `items: []`, `zoom_in: true`, `guard: "candidates"` | "Há imóveis demais nesta área para mostrar a lista. Aproxime o mapa ou escolha uma cidade." |
| G-3 | Map span **> 8°** in latitude or longitude | **no query**; 200, `items: []`, `pins: []`, `zoom_in: true`, `guard: "span"` | "Esta área do mapa é grande demais para buscar. Aproxime o mapa." |
| G-4 | Map pins > 300 | `pins: []`, `acima_do_limite: true` | "Aproxime o mapa para ver os imóveis" (map only) |
| G-5 | Stay **> 30 nights**, or a listing `max_nights` above the platform cap | 422 `code: "stay_over_cap"`, before any query (distinct from G-6) | "A estadia máxima é de 30 noites." |
| G-6 | Flexible window out of bounds (later) | 422 `flex_invalid` | — (not in this delivery) |
| G-7 | Cursor whose sort, channel or filters changed | 400 | list restarts from page 1 |
| G-9 | Backstop timeout 150 ms | 503 `retry` | "Não conseguimos carregar agora. Tentar novamente" |

- **Order in a map area:** G-3 first (no query), then G-2 for every sort, then G-1a for the total price. 2,001–5,000 candidates list normally under relevance, rating and nightly price; total price there falls back to relevance.
- On the place path, only G-1a/G-1b apply.
- Acceptance: each guard has a test at its limit and limit + 1, and the over-limit path executes no page query (§12.9 criterion 14).

## 7. Estados de zoom (spec de tela da UI)
Source: UI's screen spec `/workspace/ui/zoom-states-spec.md` (2026-10-21), merged here with Produto's answers to UI's Q2, Q6, Q7 and Q8 and Arquitetura's notes (`/workspace/adr/notes-produto-spec-busca.md`). UI/UX owns layout; thresholds are §12.7 (section 6). Copy key: **[O]** official string (spec-busca-localidade-resolvida table), **[P]** new string, listed in section 9. **Copy never shows a threshold number**, because the caps may still move (§12.9).

### 7.0 Shared rules
**What "zoom in" means.**
- **Manual zoom** (wheel, pinch, +/− control, keyboard) keeps the existing rules: desktop "Buscar ao mover o mapa" ON re-queries after the move; mobile search-on-move is OFF, so "Buscar nesta área" appears.
- **The "Aproximar" button** [O] in a guard state is an explicit search action and always re-queries, on mobile too.
  - **G-2, G-1a, G-4: exactly one zoom step** (+1 level, about half the span on each axis), on the current map center. The server returns only a flag (`zoom_in` / `acima_do_limite` / `guard`), with no density or area hint, so one step is predictable and keeps the user oriented.
  - **G-3 is the exception:** "Aproximar" fits the largest view whose span is within the cap, on the same center, so one tap always leaves G-3 (it may land in G-2 or G-4).
- If the state is still active after a tap, the message stays, the screen-reader text says "still", and the button stays in place.
- At maximum zoom, "Aproximar" is hidden and the secondary action becomes primary.
- The zoom animation respects `prefers-reduced-motion`.

**Back and URL** (extends spec-busca-localidade-resolvida item 7; Produto, Q7).
- **No guard state goes in the URL.** It is derived from the response, so reload or share re-derives it from the same `bbox`, `local_id`, filters and sort.
- **"Aproximar" and "Buscar nesta área" use `pushState`** with the new `bbox` (4 decimals). Back returns to the wider area and shows its guard state again (expected).
- **Moves made by "Buscar ao mover o mapa" use `replaceState`**, so panning doesn't flood the history (Produto decision, Q7).
- **Sort intent is kept.** Under the G-1a/G-1b fallback the URL keeps the user's `ordem` while the list shows relevance; zooming or filtering back under the cap restores that order with no extra tap. The cursor must follow the order actually shown (open, section 8, Q4).
- A guard state with no list (G-2, G-3) clears the cursor and the scroll position.

**Place path vs map area** (§12.7 "Interplay").

| State | Resolved place (`local_id`) | Map area (`bbox`, "Área do mapa ×") |
|---|---|---|
| G-1a total price > 2,000 / G-1b nightly price > 5,000 | Applies | Applies |
| G-2 list > 5,000 | **Never** | Applies, any sort, channel included |
| G-3 span > 8° | List: never. Pins: open (section 8, Q5) | Applies first, before any query; list and pins empty |
| G-4 pins > 300 | Map only | Map only |

So **"Escolha uma cidade" [O] always leaves G-2 and G-3**: the guaranteed exit for map areas. On the place path, zooming the desktop map with search-on-move ON switches to "Área do mapa ×" [O], and × restores the place.

**Priority when states combine:** G-3 > G-2 > G-1a/G-1b > G-4. Only the highest active state is announced; lower states still visible stay as text in their own region (the map), unannounced. At most one polite announcement per response.

**Accessibility, all states.**
- One shared polite live region (`role="status"`, the existing results-status region); no `aria-live="assertive"`.
- Announce on entering a state, and after a user action ("Aproximar", a filter, a sort change) whose result is still guarded (with the "still" text); never on an automatic re-query that leaves the state unchanged.
- Focus is never moved on entering a state. After "Aproximar": if the state is still active, focus stays on the button; if it clears and the button disappears, focus moves to the results heading (`tabindex="-1"`) and the usual count is announced.
- Message, primary and secondary actions are real buttons or links, in that DOM order. Targets ≥ 24×24 px (WCAG 2.5.8), 44 px tall on mobile. Never colour alone.

**Filter suggestions (Produto, Q2).** Facets have no counts (§5b), so the UI offers the generic **"Filtros"**. When the search has **no dates**, **"Adicionar datas"** [P] comes first; when **guests are unset**, **"Adicionar hóspedes"** [P] comes next; then "Filtros".

### 7.1 G-2: map-area list over 5,000 filtered candidates (any sort)
Response: `items: []`, `zoom_in: true`, `guard: "candidates"`.
- **Message** [P]: "Há imóveis demais nesta área para mostrar a lista. Aproxime o mapa ou escolha uma cidade."
- **Actions:** primary "Aproximar" [O] (one step); secondary "Escolha uma cidade" [O] (focuses "Onde?" [O] with the list open, or opens the full-screen "Onde?" step on mobile); then the filter suggestions of 7.0 ("Adicionar datas", "Adicionar hóspedes", "Filtros").
- **Placement:**
  - Desktop 1280: the block replaces the cards at the top of the list column, under the results header; the sort control stays visible and enabled; the count line and "Mostrar mais imóveis" are hidden. The map shows only the G-4 pill, with no second button.
  - Mobile 390, list view: the block replaces the cards; "Aproximar" opens the map view and zooms one step. The floating "Mapa" button stays.
  - Mobile 390, map view: one card where the carousel sits; "Buscar nesta área" [O] is hidden while it shows.
- **Screen reader:** announce the message on entry. Still active after "Aproximar" [P]: "Ainda há imóveis demais. Aproxime mais o mapa ou escolha uma cidade."
- **Combines with:** G-3 replaces it. It hides G-1a/G-1b (no list, no fallback text; sort intent stays in the URL). G-4 shows on the map unannounced.
- **AC:**
  1. A mocked 5,001 response shows the block with no cards; 5,000 lists normally.
  2. Never shown with a `local_id` search.
  3. "Escolha uma cidade" reaches a list in one choice; "Aproximar" pushes one history entry with the new `bbox`.
  4. Announced once on entry and once per guarded action; nothing on an unchanged automatic re-query.
  5. Focus is never lost when the block disappears.
  6. With no dates, "Adicionar datas" is the first filter suggestion; with guests unset, "Adicionar hóspedes" follows.

### 7.2 G-1a: total price over 2,000 candidates (and G-1b: nightly price over 5,000), fallback to relevance
Response: relevance order, `ordem: "relevancia"`, `sort_unavailable: "zoom_in"`. Applies on both paths. In a map area with 2,001–5,000 candidates the list shows normally in relevance order.
- **Message, map area:** [O] "Ordenado por relevância. Aproxime o mapa para ordenar pelo preço total." For G-1b: [P] "Ordenado por relevância. Aproxime o mapa para ordenar pela menor diária." (Produto, Q8).
- **Message, resolved place** ("zoom in" doesn't apply to a `local_id` list) [P]: "Ordenado por relevância. Escolha um bairro ou use filtros para ordenar pelo preço total."
- **Actions:**
  - Map area: primary "Aproximar" [O] (one step); secondary "Escolha uma cidade" [O].
  - City: primary "Escolha um bairro" [P] (opens "Onde?" pre-filled with the city, listing its neighborhoods); secondary "Filtros". On desktop, zooming the map is also allowed (switches to "Área do mapa ×").
  - Neighborhood, beach or region (Produto, Q6): "Adicionar datas" [P] first when dates are missing, then "Filtros", "Mudar ordenação" [P] (opens the sort control) and "Ver no mapa" [P] (opens the map on the place, where "Aproximar" applies).
- **Placement:** desktop, one line under the results header next to the sort control, tied with `aria-describedby`, action inline after the text; in the sort menu, "Menor preço total" [O] may show as disabled with "Aproxime o mapa para ordenar pelo preço total" [O], "Aproximar" [O] and "Escolha uma cidade" [O] (when to disable is open, section 8, Q1; until then the server response is the only signal). Mobile: the same line above the first card, at most 2 lines; the action is a 44 px text button on its own line. Cards, count line and "Mostrar mais imóveis" behave normally.
- **Screen reader:** announce once per fallback. Still over the cap after "Aproximar" [P]: "Ainda ordenado por relevância. Aproxime mais o mapa para ordenar pelo preço total." When the sort resumes [P]: "Ordenado pelo menor preço total." (for G-1b: "Ordenado pela menor diária.").
- **Combines with:** G-2 and G-3 replace it. G-4 can be active at the same time (common: 300 < 2,000); the map shows its pill unannounced, the list line is announced.
- **AC:**
  1. A mocked 2,001 response shows relevance order plus the line; 2,000 shows total-price order with no line (G-1b: 5,001 / 5,000).
  2. The URL keeps `ordem` under fallback, and zooming under the cap restores that order without a tap.
  3. "Mostrar mais imóveis" under fallback never returns a 400 (section 8, Q4).
  4. Each path shows its own text; "Aproxime o mapa…" never appears on a `local_id` list.
  5. Each fallback and each resume is announced once.

### 7.3 G-3: map span over 8° (latitude or longitude)
Response (Arquitetura): G-2 shape, **no query**: `items: []`, `pins: []`, `zoom_in: true`, `guard: "span"`. The 8° is [E] (Teste may shrink it), so the client reads the cap from config, never hard-codes it (section 8, Q3).
- **Message** [P]: "Esta área do mapa é grande demais para buscar. Aproxime o mapa."
- **Actions:** primary "Aproximar" [O] (fits the largest view within the cap, same center; one tap exits); secondary "Escolha uma cidade" [O].
- **Placement:** desktop, the block in the list column (as G-2) plus the same message as a centred pill at the top of the map with its own "Aproximar" (the only map pill with an action, since there are no pins); both actions do the same. Mobile: list view as G-2; map view, the carousel-position card. Optionally the client may skip the request and show this state directly (span is computable); behaviour is the same.
- **Screen reader:** announce on entry; no "still" text (one tap exits).
- **Combines with:** replaces every other state; after the tap the next state is announced on its own.
- **AC:**
  1. A span at the cap queries normally; just over it shows this state, on either axis.
  2. One "Aproximar" always produces a span within the cap.
  3. Changing the cap in config changes the behaviour with no code change.
  4. Never shown for a `local_id` list.

### 7.4 G-4: map pins over 300
Response: `pins: []`, `acima_do_limite: true`. The map shows the message only; the list is unaffected.
- **Message** [O]: "Aproxime o mapa para ver os imóveis".
- **Actions:** primary "Aproximar" [O] (one step). Secondary: on mobile map view, "Ver lista" [P]; desktop needs none (the list is next to the map).
- **Placement:** desktop, a centred pill at the top of the map, below the "Buscar ao mover o mapa" toggle, with an inline "Aproximar"; attribution stays visible. Mobile map view: the carousel is replaced by one card (message, "Aproximar", "Ver lista"). Mobile list view: nothing.
- **Screen reader:** announced only when the map is the main view (mobile map view) or right after a keyboard zoom or "Aproximar" on the map; not on desktop with a list.
- **Combines with:** G-3 replaces it; shows silently with G-2 and G-1a. Expected often: any area with more than 300 filtered listings (section 8, Q9).
- **AC:**
  1. A mocked 301 response shows the pill and no pins; 300 shows pins and clusters.
  2. On mobile map view, "Ver lista" returns to the list at the same scroll position.
  3. The pill never covers the attribution or the zoom control.

G-5, G-6, G-7 and G-9 are error states, not zoom states (section 6).

## 8. Open points (waiting on others)
**Answered and folded in (2026-10-21):** Arquitetura's notes (`/workspace/adr/notes-produto-spec-busca.md`): demotion folded into the stored score with `penalty_until` and a daily sweep (section 1); G-3 shape with `guard: "span"` and empty pins (sections 5-7); 422 `stay_over_cap` (section 6); the fallback is relevance, not `id` (§11.1 line 372 is superseded by §12 G-1a). Negocios calibration (`/workspace/negocios/calibracao-busca.md`): weights, demotion and G2 prior (sections 1-2). Produto: UI's Q2, Q6, Q7, Q8 (section 7).

**Follow-up (BD, schema spec):** column types and CHECKs for `rank_penalty` and `penalty_until` on `listing_search`.

**Open with Arquitetura** (UI's questions, not answered in the notes):
- **Q1:** the zoom-13/12 client hint was computed for the old 5,000 cap; recompute it for 2,000 or drop it so the server response is the only signal. Until then the server response is the only signal (section 7.2).
- **Q3:** `guard` now says why (`span` / `candidates`). Still open: exposing the caps (G-3 span, ideally G-1a/G-2) in config for the client, and an optional suggested `bbox`.
- **Q4:** under the G-1a/G-1b fallback, `next_cursor` must carry the order actually used and the next page must not trigger the G-7 400; confirm the contract (this spec proposes `ordem` = effective sort in the response).
- **Q5:** G-3 empties the pins in a map-area search; still open: what happens when a resolved place's own bbox is wider than the cap (UI proposes the map opens fitted within the cap).

**Open with Arquitetura and Produto:**
- **Q9:** G-4 (300 pins) is far below G-2 (5,000), so most city-scale views will show a list with an empty map. A server-side count grid would fix it but is new scope and cost; not proposed here.

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
| Sorted by relevance. Zoom in to sort by total price. | Ordenado por relevância. Aproxime o mapa para ordenar pelo preço total. |
| Sorted by relevance. Zoom in to sort by lowest nightly price. | Ordenado por relevância. Aproxime o mapa para ordenar pela menor diária. |
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

| *UI zoom states (section 7), [P]:* | |
| There are too many listings in this area to show a list. Zoom in or pick a city. | Há imóveis demais nesta área para mostrar a lista. Aproxime o mapa ou escolha uma cidade. |
| Still too many listings. Zoom in further or pick a city. | Ainda há imóveis demais. Aproxime mais o mapa ou escolha uma cidade. |
| Sorted by relevance. Pick a neighborhood or use filters to sort by total price. | Ordenado por relevância. Escolha um bairro ou use filtros para ordenar pelo preço total. |
| Pick a neighborhood | Escolha um bairro |
| Change sort | Mudar ordenação |
| Still sorted by relevance. Zoom in further to sort by total price. | Ainda ordenado por relevância. Aproxime mais o mapa para ordenar pelo preço total. |
| Sorted by lowest total price. | Ordenado pelo menor preço total. |
| This map area is too large to search. Zoom in. | Esta área do mapa é grande demais para buscar. Aproxime o mapa. |
| Show list | Ver lista |
| *Produto answers to UI (Q2, Q6, Q8):* | |
| Add dates | Adicionar datas |
| Add guests | Adicionar hóspedes |
| Show on map | Ver no mapa |
| Sorted by lowest nightly price. | Ordenado pela menor diária. |

Boost and label copy for hosts is UI's, reviewed by Jurídico (§12.3a.3); the lines above are the starting proposal.

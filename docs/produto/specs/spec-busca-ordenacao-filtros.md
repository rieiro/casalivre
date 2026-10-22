# Spec: search ranking, sorts, filters and flexible dates (G1, G2, G3, G4; draft, 2026-10-21)

**Base:** [ADR 001 §12](../../adr/001-busca-sql-paginada.md) (rev 3, #179) and [docs/decisions.md](../../decisions.md) (2026-10-21, search sorts and filters; G1 and G13 decisions). Product sources: `docs/produto/planejamento/backlog.md` ("Competitor gaps": G1, G2, G3, G4, G13) and [spec-busca-localidade-resolvida.md](spec-busca-localidade-resolvida.md) (resolved place, map area, combobox, official copy table).
**Scope:** what the guest sees and what the API returns. How each option is stored and indexed is §12 (Arquitetura, BD). Screen layout of the zoom states is UI's (section 7, TODO).
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
- **Base score `rank_base`** (one per listing, all channels), written by `RankScore::base()` on every listing, review, status or amenity write. Factors (Produto), each normalized to 0..1000, combined with platform weights stored in settings [E, Negocios to calibrate]:

  | Factor | Source | Default weight [E] |
  |---|---|---|
  | Rating (G2 `rating_rank`) | reviews | 40% |
  | Listing quality: recommended checklist items (10+ photos, alt text on photos, accessibility amenities; spec-03 §4) | listing | 20% |
  | Host reliability, last 12 months (spec-02 §4c) | cancellations | 20% |
  | Instant booking | listing | 10% |
  | Self check-in (G7 check-in method: key box or smart lock) | listing (P10, S7) | 10% |
  | Response time (G8) | requests | 0% until pilot data exists |

  - **Host cancellation demotion** (spec-09 §5: "perde o destaque na busca por 90 dias"): a stored penalty that halves `rank_base` [E], set on the cancellation write and cleared by a daily job when the 90 days end. No continuous time decay (§12.3). *Needs Arquitetura confirmation (section 8, conflict 2).*
  - No price-vs-comparable or popularity factor now (they need per-date or behavioural data).
- **Per-channel score `channel_rank.score`** (§12.2). A channel has its own factor weights plus the two commercial weights:
  - `peso_pago` (paid placement): applies only to listings with a **live boost** in that channel.
  - `peso_rede` (own-network preference): applies to listings managed by the channel's own organization or node subtree.
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
8. A host cancellation without force majeure lowers the listing's relevance for 90 days and restores it on day 91 (job), with no other time-based change to `rank_base`.
9. Relevance pagination is keyset over the stored score; no request computes a score (builder test, §12.9 criterion 13). p95 within section 0 targets with a channel at weights > 0.

## 2. G2: best rated, weighted by review count
### Problem
A 5.0 with 3 reviews outranks a 4.9 with 200.

### Solution
- **Sort "Melhor avaliação"** reads `rating_rank` = Bayesian mean `(C × m + Σ notas) ÷ (C + n)`, stored as an integer ×1000 (0..5000), written inline with each review (§12.1, §12.3).
- `C` (prior weight) and `m` (prior mean) are platform settings [E]: proposal `C = 10`, `m = 4,5`, Negocios to calibrate. A change runs `rank:all` (≤ 60 s [E]).
- The card keeps showing the plain average with the count ("★ 4,8 (23)", spec-07); listings with fewer than 3 reviews show "Novo" (spec-07). The `score_min` filter keeps the plain average (§4a). Only the sort order uses the Bayesian value.
- `rating_rank` is also the rating factor of G1.

### User flow
Guest picks "Melhor avaliação" in the sort control; the list reorders; the choice stays in the URL (`ordem=avaliacao`).

### Acceptance criteria
1. With the default `C` and `m`, a listing with three 5-star reviews ranks below a listing with 200 reviews averaging 4.9.
2. A new review updates `rating_rank` in the same transaction; the next search reflects it.
3. Changing `C` or `m` needs no deploy and is applied to every listing within ≤ 60 s [E].
4. Card, detail and `score_min` show and use the plain average; only the order uses `rating_rank`.
5. No per-request score computation; p95 within section 0 targets with all filters on.

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
  "zoom_in": false
}
```
- `items[].patrocinado` mirrors `channel_rank.sponsored` for the current channel (false when no row).
- `ordem` is the sort actually applied (it may differ from the request after a guard).

## 6. Guards (§12.7), explicit
| # | Trigger | Response | Guest sees |
|---|---|---|---|
| G-1a | Total-price sort with **> 2,000** filtered candidates (place or map) | 200, relevance order, `ordem: "relevancia"`, `sort_unavailable: "zoom_in"` | "Ordenado por relevância. Aproxime o mapa para ordenar pelo preço total." |
| G-1b | Nightly-price sort with > 5,000 filtered candidates | same as G-1a | "Ordenado por relevância. Aproxime o mapa para ordenar pela diária." |
| G-2 | Map-area list with **> 5,000** filtered candidates, **any sort**, any channel | 200, `items: []`, `zoom_in: true` | "Aproxime o mapa para ver a lista" |
| G-3 | Map span **> 8°** in latitude or longitude | `zoom_in` before any query (same shape as G-2; see section 8) | "Aproxime o mapa para ver a lista" |
| G-4 | Map pins > 300 | `pins: []`, `acima_do_limite: true` | "Aproxime o mapa para ver os imóveis" (map only) |
| G-5 | Stay **> 30 nights**, or a listing `max_nights` above the platform cap | 422 with the message, before any query | "A estadia máxima é de 30 noites." |
| G-6 | Flexible window out of bounds (later) | 422 `flex_invalid` | — (not in this delivery) |
| G-7 | Cursor whose sort, channel or filters changed | 400 | list restarts from page 1 |
| G-9 | Backstop timeout 150 ms | 503 `retry` | "Não conseguimos carregar agora. Tentar novamente" |

- **Order in a map area:** G-3 first (no query), then G-2 for every sort, then G-1a for the total price. 2,001–5,000 candidates list normally under relevance, rating and nightly price; total price there falls back to relevance.
- On the place path, only G-1a/G-1b apply.
- Acceptance: each guard has a test at its limit and limit + 1, and the over-limit path executes no page query (§12.9 criterion 14).

## 7. Estados de zoom (spec de tela da UI)
> **TODO (UI):** UI delivers `/workspace/ui/zoom-states-spec.md` (screens for G-1a, G-1b, G-2, G-3, G-4: copy placement, live-region announcements, the "Aproximar" action, focus, mobile). Produto merges it here. Until then the strings in section 9 are proposals. This section supersedes the zoom-13/12 hint formerly in spec-busca-localidade-resolvida item 8 and spec-01 §2.

## 8. Open points (waiting on others)
**Arquitetura** (answers expected in `/workspace/adr/notes-produto-spec-busca.md`):
1. The 90-day host-cancellation demotion (spec-09 §5) needs a daily clear job, while §12.3 says no time-decay factor goes into `rank_base`.
2. G-3 response shape is not defined in §12.7 ("`zoom_in` before any query"); this spec assumes the G-2 shape (200, `items: []`, `zoom_in: true`) so the UI has one zoom state.
3. G-5 has no error code in §12.7; proposal `stay_over_cap`.

**Negocios** (calibration expected in `/workspace/negocios/calibracao-busca.md`):
4. G2 `C` and `m` defaults (proposal `C = 10`, `m = 4,5` [E]) and the G1 base-factor weights and demotion factor [E].

**Decided (Produto, 2026-10-21):** spec-01 §2 and spec-busca-localidade-resolvida item 8 now use the 2,000 cap with a relevance fallback, and the zoom-13/12 hint is superseded by section 7; "Menor diária" is in spec-01's sort list; self check-in stays hidden until G7 data exists; the "Patrocinado" reconciliation in section 1 stands.

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
| Sorted by relevance. Zoom in to sort by nightly price. | Ordenado por relevância. Aproxime o mapa para ordenar pela diária. |
| Zoom in to see the list | Aproxime o mapa para ver a lista |
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

Boost and label copy for hosts is UI's, reviewed by Jurídico (§12.3a.3); the lines above are the starting proposal.

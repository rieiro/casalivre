# Spec: search map (list and map side by side), and price-sort behavior (ADR 001 PR5 and PR6; draft, 2026-11-03)

Owner: Produto. Screen specs: UI (section 6). This spec adds the behavior that was still open for PR5 and PR6. It does not repeat what is already settled; the sources below stay the source of truth for their parts.

**Already specified (do not redo):**
- Layout, components, states and accessibility: [spec-telas-busca-mapa-upload-fotos.md](spec-telas-busca-mapa-upload-fotos.md) §1.
- Guards, count bubbles, sponsored pins, the map response and the price-sort fallback: [spec-busca-ordenacao-filtros.md](spec-busca-ordenacao-filtros.md) §5–§7.
- Map as a resolved place, URL and history: [spec-busca-localidade-resolvida.md](spec-busca-localidade-resolvida.md) UX items 6–7.
- Contract and limits: [ADR 001](../../adr/001-busca-sql-paginada.md) §5, §12.7, §12.7b.

## 1. PR5 scope (map mode)
In: `modo=mapa` with `bbox`; guards G-2, G-3 and the 300-pin cap with count bubbles; `lat`/`lng` leave the list items; list and map side by side on desktop; "Buscar ao mover o mapa" and "Buscar nesta área"; `bbox` in the page URL; card skeletons; pin and card sync; the popup and the mobile carousel.
Out: price sorts and feature filters (PR6); "Perto de mim"; any change to sponsored rules; map performance measurement (deferred by Rafael, #187); the exact host pin map (S5, spec-03).

## 2. Decisions (Produto, 2026-11-03)
**Search as I move the map**
1. Only a user move (drag, zoom control, keyboard, wheel) can start a search. A programmatic move (fit to a place, "Aproximar", a bubble tap, restoring from the URL) never starts a second search; those actions run their own single search.
2. The search starts 500 ms after the move ends, never during a drag. A new move cancels the pending timer and aborts the request in flight; only the latest response is drawn.
3. The setting is ON by default on desktop and OFF on mobile, and is not remembered: it resets on each page load.
4. With the setting OFF, a move shows "Buscar nesta área" and changes nothing else. The button disappears after the search runs or when the map returns to the searched area.
5. The list and the map are two parallel requests for the same search. The list never waits for the map, and the map never waits for the list.

**URL and history** (completes localidade item 7)
6. The page URL holds `bbox=lat_min,lng_min,lat_max,lng_max` with 4 decimals, and no `local_id`. `modo=mapa` exists only in the API request, never in the page URL.
7. Leaving a place for a map area pushes one history entry (`pushState`). While the URL already holds a `bbox` set by "Buscar ao mover o mapa", each later automatic search replaces the entry (`replaceState`), so Back returns to the place and not to a pile of pans. A click on "Buscar nesta área" always pushes.
8. On mobile, the map or list view is in the URL as `vista=mapa` (absent means list), so Back from a listing returns to the same view.
9. A shared `bbox` URL opens the map fitted to that box. A different map size may show slightly more or less area; the search results are still those of the box.

**Pin and card sync** (extends Q-P1)
10. Desktop: hovering or focusing a card highlights its pin, and hovering or focusing a pin highlights its card. Mobile: swiping the carousel selects the pin, and selecting a pin scrolls the carousel to its card.
11. A pin click opens the popup and marks the pin selected. If the card is in the loaded list, the list scrolls it into view and highlights it. If it is not loaded, the popup gets its data from `GET /api/imoveis/<h>` (ADR §5) and the list does not change or load more.
12. The selected state ends with Esc, a click on empty map, or a new search. It is never in the URL.
13. A pin shows the same price text as its card: the total with dates, the nightly price without dates. Pins show only `public_coord`, never the exact coordinate.

**Loading**
14. First load, or a search with no items on screen: 6 skeleton cards with the same size and image ratio as a real card, so nothing shifts when the cards arrive. A refresh with items on screen keeps them and shows "Atualizando resultados…" (map spec §1). "Mostrar mais imóveis" shows no skeletons, only "Carregando…" on the button.
15. The map script and tiles load after the list's first paint on desktop, and only when the map view opens on mobile. Until then the map area shows "Carregando mapa…". A map failure never blocks the list (without WebGL the list stays, with the notice already in the map spec).

**Known limitation.** Property coordinates today are the city center plus an offset (`HouseService.php:86-93`), so pins in a listing can sit kilometers from the real place. The host pin map (S5, spec-03) fixes it; PR5 does not work around it.

## 3. PR5 acceptance criteria
1. On desktop at 1280 px the list is 60% and the map 40%, and the map stays fixed while the list scrolls.
2. With "Buscar ao mover o mapa" ON, a pan ends, 500 ms pass, and one list request and one map request go out, both with the same `bbox`; two moves 300 ms apart produce one search.
3. A response that arrives after a newer move is not drawn.
4. A fit to a place, "Aproximar" or a bubble tap produces exactly one search.
5. After the first automatic search the URL has `bbox` and no `local_id`; three more pans leave one extra history entry in total; Back restores the place and its list.
6. "Buscar nesta área" pushes a history entry on every click; with the setting ON the button is absent.
7. A map above 300 pins and up to 5,000 candidates shows pins and count bubbles; above 5,000 or a span above 8°, the states of spec-busca-ordenacao-filtros §7.1 and §7.3.
8. List items no longer carry `lat` or `lng` (contract test).
9. Hovering a card with a pin highlights the pin, and the reverse; a card without a pin changes nothing on the map.
10. A pin click for a card not yet loaded opens the popup from `/api/imoveis/<h>` and the list stays as it was.
11. First load shows 6 skeletons and no layout shift when they are replaced by cards; a refresh keeps the cards and shows "Atualizando resultados…".
12. No response, URL, or alt text carries an exact coordinate.
13. Mobile: the Mapa/Lista button switches the view, `vista=mapa` is in the URL, the carousel and pins stay in sync, and the setting is OFF until turned on.
14. Keyboard and screen reader criteria of the map spec §1 hold (list as alternative, focusable pins, the live count).

## 4. PR6 decisions: price sorts
Sorts in PR6: "Menor preço total" (`total_price`) and "Menor diária" (`nightly_price`). Guards G-1a, G-1b and their fallback to relevance stay exactly as in spec-busca-ordenacao-filtros §7.2.
1. **One choice for the user: "lowest price".** With dates, the control reads "Menor preço total" and sends `total_price`. Without dates it reads "Menor diária" and sends `nightly_price`. The two are never offered together. If the user adds or clears dates while a price sort is chosen, the client swaps the sort in the URL to the matching one and announces it once. The server still ignores a mismatched sort and uses relevance, as a safety net.
2. **Lowest first only.** There is no "highest first".
3. **The sort key is the number on the card.** `total_price` orders by the same total the card shows (nights, period price exceptions, cleaning fee and fees included); `nightly_price` orders by the card's nightly price without dates. Test: the order of the cards equals the order of their displayed prices.
4. **Ties** break by newest listing first, like relevance ties.
5. **Neutral sort.** A price sort ignores rank score and channel weights: a sponsored or own-network listing gets no lift and keeps its "Patrocinado" label rule. Sponsored pins on the map follow the same order.
6. **Pins follow `sort_effective`** (already in §7.4), and under a price sort show the same price as the card.
7. **Availability:** with dates, only available listings are sorted (as today). A listing whose minimum stay is not met by the dates is not listed, so it is not in the price order either.
8. **Fallback text is unchanged** (§7.2 messages, announcements and actions).

### PR6 acceptance criteria
1. With dates, choosing "Menor preço total" under 2,000 candidates returns cards in ascending displayed total; at 2,001 it returns relevance with the §7.2 text and keeps `sort=total_price` in the URL.
2. Without dates, "Menor diária" does the same with caps of 5,000 and 5,001.
3. Adding dates with "Menor diária" chosen changes the URL to `total_price` and announces it once; clearing dates reverses it.
4. The control never shows both price options and never disables one.
5. Two listings with the same price appear newest first.
6. With paid or own-network weights above 0, a price sort order is unchanged (test with weights set).

## 5. Open questions
None for Produto. Arquitetura aligns ADR §10 and §12.11 for the rating decision (PR4), not for this spec.

## 6. UI screen specs
To be added by UI (Bot UI): desktop 60/40 layout and sticky map; the move setting, "Buscar nesta área" and loading states; pin, card and popup highlight states; skeleton cards and map loading; mobile Mapa/Lista toggle with the synced carousel; keyboard order. Source file and summary go here when delivered.

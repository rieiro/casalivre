# Proposal: search location must be a resolved place, not free text (draft, 2026-10-16)

## Problem
The location field on home and search (`views/home.php:8`, `views/search.php:21`) is free text. The autocomplete only suggests; the user can edit "Rio de Janeiro, RJ" into "Rio de Jan" and submit.
- `CityRepository::match()` (`conta/Geo/CityRepository.php:72`) only accepts exact matches, so a partial name resolves to nothing.
- `SearchService::search()` (`src/SearchService.php:40-70`) then loads every listing (`HouseRepository::all()`) and does a substring scan over city and neighborhood in PHP. That is the most expensive path, and it also turns off the radius filter and the map center.
- UX: the user gets odd or empty results with no explanation, and the URL is not a stable, shareable place.

## Solution
1. The search submits a resolved place: `local_id` (city, or city + neighborhood) plus the display label. The free text is only used to find suggestions.
2. Autocomplete: prefix match on folded names, served by an indexed query on PostgreSQL (accent- and case-insensitive), from 2 characters, at most 8 suggestions, with a debounce.
3. If the user submits without picking a suggestion, the server resolves the text once:
   - exactly one place matches by prefix: use it, and show the full label in the field;
   - several match: show "Did you mean…" with the options (no search runs yet);
   - none match: show "We couldn't find that place" with nearby or popular suggestions.
4. The substring scan over all listings is removed. Search never runs on unresolved text.
5. Old URLs with `localidade=` text keep working through the same resolution in step 3.

## Acceptance criteria
1. Given I type "Rio de Jan" and submit, the field becomes "Rio de Janeiro, RJ" and results are for that city (one match).
2. Given I type "São" and submit, I see a "Did you mean" list and no result query runs.
3. Given I type "Xyzabc", I see "We couldn't find that place" and suggestions, and no full listing scan runs.
4. The result URL always contains `local_id`; opening it gives the same results.
5. Radius and map work for every search, because there is always a center.
6. Performance (PostgreSQL only): an autocomplete query p95 < 50 ms and a search never loads all listings. Measured by Arquitetura.
7. SQLite keeps working functionally.

## Priority and fit
Should, RICE high on effort/benefit. Fits inside ADR 001 (SQL search), which already rewrites `SearchService`, so it costs no rework if done there; doing it separately now would be redone by ADR 001.

## Changes after the Negocios review (2026-10-16)
- **The place type is data, not code.** A table of places with a type (region, city, neighborhood, beach or point of interest), a center point and an optional area (bounding box or polygon). With ADR 001: city, neighborhood and beach/POI. Pilot seeds include beaches such as Geribá, Praia do Forte and Ferradura. Regions use their area instead of center + radius, and come right after ADR 001.
- **The map counts as a resolved place.** "Search this area" sends the bounding box of the map. "Near me" sends the user's location; it comes after ADR 001 and needs a permission prompt.
- **Demand signal.** Log unresolved text and places with zero results as aggregated counts (folded text, place, date). No user id and no IP. This feeds property and franchisee recruitment and the regional fund.
- **A regional channel's site** searches its own region by default.
- Extra acceptance: typing "Geribá" suggests the beach; "Search this area" returns only listings inside the visible map; the demand report lists the top unresolved terms with counts.

## UX and accessibility (UI review, accepted by Produto 2026-10-16)
Source: UI review of this spec. Applies to home and search (spec-01 §1–2) and to the map (`spec-telas-busca-mapa-upload-fotos.md` §1).

1. **Combobox (W3C APG, editable combobox with list autocomplete).** The input has `role="combobox"`, `aria-expanded`, `aria-controls` and `aria-activedescendant`. Down/Up move through the options, Enter selects, Esc closes the list (a second Esc clears the text), and Tab leaves without selecting. A polite live region announces the count ("5 places"). Each option is at least 24×24 px (WCAG 2.5.8).
2. **Matching and labels.** Matching is **word-start plus aliases**, with accents and case folded: the typed text matches the start of any word in the name, or the start of an alias. So "Forte" finds "Praia do Forte", and "sao" finds "São Pedro da Aldeia". The server also matches no-accent forms. It does **not** match the middle of a word or typos. Each suggestion shows the name, the type and the parent place: "Geribá · Beach · Búzios, RJ", "Centro · Neighborhood · Cabo Frio, RJ", "São Pedro da Aldeia · City · RJ". The matched word start is highlighted, and when the match comes from an alias, the label shows the official name.
   AC: "sao" and "São" give the same list; "Forte" lists Praia do Forte; two homonyms never get the same label.
3. **"Did you mean" is a choice, not a results page.** It keeps the typed text, lists up to 8 options with the labels from item 2, and moves focus to the list heading. With exactly one match, the server resolves it (Solution step 3): the field shows the full label, and a "Not this place? Change" link reopens the field. It never picks for the user when a city and a neighborhood or beach both match a prefix ("Centro", "Forte"); both are listed.
4. **No match is never a dead end.** "We couldn't find 'Xyzabc'" offers popular places in the channel's region and "Search on the map" (opens the map on the channel's default area). The typed text stays editable.
   AC: from this state the user reaches a result list in 1 tap. There are no typo or close-spelling suggestions: the server does not support fuzzy matching (gap for Arquitetura).
5. **Suggestions API error.** The field keeps accepting text, and Enter goes through the server resolution of Solution step 3.
   AC: with the suggestions API down, Enter still leads to results, "Did you mean" or the no-match state. A raw-text search never runs.
6. **Map area.**
   - "Search as I move the map" is ON by default on desktop and OFF on mobile, where the "Search this area" button is used.
   - When a map area replaces the place, the field shows "Map area" with a clear (×) that returns to the last place.
   - Above the pin cap of ADR 001 §5, the map says "Zoom in to see listings".
   AC: after a pan with search on, the URL has `bbox` and no `local_id`; × restores `local_id` and its results.
7. **URL and history.** The canonical URL holds `local_id` or `bbox` (4 decimals), dates, guests, filters and sort. Picking a place or running "Search this area" uses `pushState`; the cursor and the scroll position use `replaceState` (ADR 001 §5c).
   AC: Back returns to the previous place or area with its list and scroll; a shared URL gives the same results; an old `localidade=` URL goes through items 3–4 and never to an error page.
8. **"Lowest total price" sort: the server decides.** Decision by Gerente (Produto + Arquitetura), 2026-10-16. This is a performance guard only.
   - **Server rule:** the sort runs when the filtered set has **≤ 5,000 candidates**. Above that, the server returns the default order with `sort_unavailable: "zoom_in"`. This response is final, even for a city, a neighborhood or a small map area.
   - **Client hint, not the guard:** on the map, the option is **visible but disabled** below **zoom 13 on desktop** and **zoom 12 on mobile**, unless a city or neighborhood is fixed. These zoom levels are calculated, not measured. Inline explanation, tied to the option with `aria-describedby`: "Zoom in to sort by total price", with a "Zoom in" action and a "Pick a city" link. The option is never hidden without saying why.
   - **Fallback on `sort_unavailable: "zoom_in"`** (or when the hint applies while the sort is active): the list shows Relevance; a polite live region announces "Sorted by relevance. Zoom in to sort by total price."; the same text stays next to the sort control until the user zooms in or changes the sort.
   AC: (1) with a city above 5,000 candidates, the server response causes the fallback and the inline text, even though the client offered the sort; (2) below zoom 13 (desktop) or 12 (mobile) without a fixed city or neighborhood, the option is disabled with the text; (3) the fallback is announced once.
9. **Mobile.** The full-screen "Where?" step (spec-01 §1) shows recent places and "Near me" (once it ships) before any typing. "Did you mean" and no-match render inside that step, not on another page.
   AC: at 360 px the selected place and Back stay visible and the keyboard does not cover the options.
10. **Regional channel.** A regional channel's field starts with the region as a removable chip ("Região dos Lagos ×"). Removing it searches the whole marketplace only if the channel allows it.

Sources: W3C APG Combobox Pattern (https://www.w3.org/WAI/ARIA/apg/patterns/combobox/) and its list autocomplete example (https://www.w3.org/WAI/ARIA/apg/patterns/combobox/examples/combobox-autocomplete-list/); WCAG 2.2 4.1.3 (https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html) and 2.5.8 (https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html); NN/g, Site Search Suggestions (https://www.nngroup.com/articles/site-search-suggestions/) and "No Results" pages (https://www.nngroup.com/articles/search-no-results-serp/); Baymard, Autocomplete Suggestions (https://baymard.com/blog/autocomplete-design).

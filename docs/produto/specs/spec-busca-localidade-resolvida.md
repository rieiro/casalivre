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

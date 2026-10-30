<?php

declare(strict_types=1);

require_once __DIR__ . '/GrupoResolvedor.php';
require_once __DIR__ . '/SearchQuery.php';
require_once __DIR__ . '/SearchSql.php';
require_once __DIR__ . '/CityRepository.php';
require_once __DIR__ . '/Amenities.php';
require_once __DIR__ . '/PhotoRepository.php';
require_once __DIR__ . '/helpers.php';

/** Public search on a resolved place: keyset pages from the shared SQL builder. */
final class SearchService
{
    public const PAGE_SIZE = 20;
    public const BATCH_SIZE = 60;
    public const MAX_QUERIES = 4;
    public const STATEMENT_TIMEOUT_MS = 150;
    private const POI_RADIUS_KM = 3;

    private static function statementTimeoutMs(): int
    {
        $override = getenv('SEARCH_STATEMENT_TIMEOUT_MS');
        return $override === false || $override === '' ? self::STATEMENT_TIMEOUT_MS : (int) $override;
    }

    /**
     * @return array{filters:array,items:list<array>,searched:bool,facets:list<string>,next_cursor:?string,total_aprox:int|string|null,unresolved:bool,resolution:?string,options:list<array>,iteracoes:int,linhas_lidas:int}
     */
    /** @param array{key:int,id:int}|null $after */
    public static function search(SearchQuery $query, mixed $host, bool $log = false, ?array $after = null): array
    {
        $result = self::find($query, $host, $after);
        if ($log && $query->searched()) {
            require_once __DIR__ . '/ActivityLog.php';
            activity(ActivityLog::SEARCH, $query->filters() + [
                'place_id' => $result['place_id'],
                'resultados' => count($result['items']),
            ]);
        }
        unset($result['place_id']);
        return $result;
    }

    /**
     * @param array{key:int,id:int}|null $after
     * @return array{filters:array,items:list<array>,searched:bool,facets:list<string>,next_cursor:?string,total_aprox:int|string|null,unresolved:bool,resolution:?string,options:list<array>,iteracoes:int,linhas_lidas:int,place_id:?int}
     */
    private static function find(SearchQuery $query, mixed $host, ?array $after): array
    {
        $out = [
            'filters' => $query->filters(),
            'items' => [],
            'searched' => $query->searched(),
            'facets' => $query->searched() ? Amenities::keys() : [],
            'next_cursor' => null,
            'total_aprox' => null,
            'unresolved' => false,
            'resolution' => null,
            'options' => [],
            'iteracoes' => 0,
            'linhas_lidas' => 0,
            'place_id' => null,
        ];
        if (!$query->searched()) {
            return $out;
        }
        try {
            return Db::transactional(db(), static function () use ($query, $host, $after, $out): array {
                db()->exec("SET LOCAL statement_timeout = '" . self::statementTimeoutMs() . "ms'");
                return self::listed($query, $host, $after, $out);
            });
        } catch (PDOException $e) {
            if (Db::sqlState($e) !== '57014') {
                throw $e;
            }
            error_log('search statement timeout');
            throw new SearchTimeout();
        }
    }

    /**
     * @param array{key:int,id:int}|null $after
     * @param array{filters:array,items:list<array>,searched:bool,facets:list<string>,next_cursor:?string,total_aprox:int|string|null,unresolved:bool,resolution:?string,options:list<array>,iteracoes:int,linhas_lidas:int,place_id:?int} $out
     * @return array{filters:array,items:list<array>,searched:bool,facets:list<string>,next_cursor:?string,total_aprox:int|string|null,unresolved:bool,resolution:?string,options:list<array>,iteracoes:int,linhas_lidas:int,place_id:?int}
     */
    private static function listed(SearchQuery $query, mixed $host, ?array $after, array $out): array
    {
        $place = self::place($query, $out);
        if ($place === null) {
            return $out;
        }
        $out['place_id'] = (int) $place['id'];
        $groupId = self::groupId($query, $host);
        if ($groupId === false) {
            return $out;
        }
        // Reviews are 1..10, so a score above 10 matches nothing. Skip the listing queries.
        if ($query->scoreMin > 10) {
            $out['total_aprox'] = 0;
            return $out;
        }
        $nights = nights_between($query->checkin, $query->checkout);
        $checkin = $nights[0] ?? null;
        $checkout = $checkin === null
            ? null
            : (new DateTimeImmutable($nights[array_key_last($nights)]))->modify('+1 day')->format('Y-m-d');
        $radiusKm = self::radiusKm($place, $query->raio);
        $bbox = $radiusKm > 0 ? SearchSql::bbox((float) $place['lat'], (float) $place['lng'], $radiusKm) : null;
        $out['total_aprox'] = SearchSql::totalAprox($place, $groupId, $query->hospedes, $bbox, $checkin, $checkout, $query->scoreMin);

        $known = Amenities::keys();
        $wanted = array_values(array_filter(
            $query->amenidades,
            static fn (string $key): bool => in_array($key, $known, true)
        ));
        $page = [];
        $column = SearchSql::sortColumn($query->sort);
        $hasMore = true;
        $read = 0;
        $queries = 0;
        while (count($page) < self::PAGE_SIZE && $queries < self::MAX_QUERIES && $hasMore) {
            $queries++;
            $fetched = SearchSql::batch($place, $groupId, $query->hospedes, $bbox, $checkin, $checkout, $after, self::BATCH_SIZE + 1, $query->scoreMin, $query->sort);
            $hasMore = count($fetched) > self::BATCH_SIZE;
            if ($hasMore) {
                array_pop($fetched);
            }
            if ($fetched === []) {
                break;
            }
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $fetched);
            $calendars = $nights !== [] ? HouseRepository::calendarForProperties($ids, $nights) : [];
            $photos = PhotoRepository::primaryPaths($ids);
            foreach ($fetched as $property) {
                $read++;
                $id = (int) $property['id'];
                $after = ['key' => (int) $property[$column], 'id' => $id];
                $kept = self::keep($property, $query, $nights, $place, $calendars[$id] ?? [], $wanted, $radiusKm);
                if ($kept === null) {
                    continue;
                }
                $kept['photo'] = $photos[$id] ?? null;
                $page[] = $kept;
                if (count($page) === self::PAGE_SIZE) {
                    break;
                }
            }
            if (count($page) === self::PAGE_SIZE && $after['id'] !== (int) $fetched[array_key_last($fetched)]['id']) {
                $hasMore = true;
            }
        }
        $out['items'] = $page;
        $out['iteracoes'] = $queries;
        $out['linhas_lidas'] = $read;
        if ($hasMore && $after !== null) {
            $out['next_cursor'] = SearchQuery::cursor($after['key'], $after['id'], $query->sort, $query->filtersHash());
        }
        return $out;
    }

    /**
     * The one place to search, or null when there is nothing to list.
     * Text with 0, 2–8 or 9+ hits sets unresolved and does not run a listing query.
     *
     * @param array{unresolved:bool,resolution:?string,options:list<array>} $out
     */
    private static function place(SearchQuery $query, array &$out): ?array
    {
        if ($query->localId !== null) {
            $place = $query->localId > 0 ? CityRepository::byId($query->localId) : null;
            if ($place === null) {
                $out['unresolved'] = true;
                $out['resolution'] = 'not_found';
            }
            return $place;
        }
        if ($query->localidade === '') {
            return null;
        }
        $resolved = CityRepository::resolve($query->localidade);
        if ($resolved['place'] === null) {
            $out['unresolved'] = true;
            $out['resolution'] = $resolved['resolution'];
            $out['options'] = $resolved['options'];
            return null;
        }
        return $resolved['place'];
    }

    /** @return int|null|false null = no group filter; false = the requested group does not resolve */
    private static function groupId(SearchQuery $query, mixed $host): int|null|false
    {
        if ($query->grupo !== null) {
            $group = GrupoResolvedor::grupoDoSlugNoHost($query->grupo, $host);
            return $group === null ? false : (int) $group['id'];
        }
        $hostGroup = GrupoResolvedor::doDominio($host);
        return $hostGroup === null ? null : (int) $hostGroup['id'];
    }

    /** Neighborhood coordinates are the parent city's, so a neighborhood is never a radius. An explicit raio wins for a city or a POI; a POI with no raio uses the default. */
    private static function radiusKm(array $place, int $requested): int
    {
        if ($place['type'] === 'neighborhood') {
            return 0;
        }
        if ($requested > 0 || $place['type'] !== 'poi') {
            return $requested;
        }
        return self::POI_RADIUS_KM;
    }

    /**
     * Price and amenities stay in PHP. Radius haversine too, after the SQL bbox.
     * The card score is HouseRepository::score. score_min is already in the SQL.
     *
     * @param list<string> $nights
     * @param array<string, array> $calendar
     * @param list<string> $wanted
     */
    private static function keep(array $property, SearchQuery $query, array $nights, array $place, array $calendar, array $wanted, int $radiusKm): ?array
    {
        if ($radiusKm > 0) {
            $distance = public_distance_km($place, $property);
            if ($distance > $radiusKm) {
                return null;
            }
            $property['distance_km'] = $distance;
        }
        if ($nights !== []) {
            $quote = stay_quote($property, $nights, $calendar);
            if ($quote === null) {
                return null;
            }
        } else {
            $nightly = (int) $property['nightly_price'];
            $quote = ['nights' => 0, 'total' => $nightly, 'nightly' => $nightly];
        }
        if ($query->precoMin > 0 && $quote['nightly'] < $query->precoMin) {
            return null;
        }
        if ($query->precoMax > 0 && $quote['nightly'] > $query->precoMax) {
            return null;
        }
        $score = HouseRepository::score($property);
        if ($wanted !== []) {
            $selected = Amenities::selected($property);
            foreach ($wanted as $key) {
                if (empty($selected[$key])) {
                    return null;
                }
            }
        }
        $property['quote'] = $quote;
        $property['score'] = $score;
        return $property;
    }
}

/** The listing query exceeded statement_timeout. The API answers 503. */
final class SearchTimeout extends RuntimeException
{
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/GrupoResolvedor.php';
require_once __DIR__ . '/SearchQuery.php';
require_once __DIR__ . '/SearchSql.php';
require_once __DIR__ . '/Amenities.php';
require_once __DIR__ . '/PhotoRepository.php';
require_once __DIR__ . '/helpers.php';

/** Public search on a resolved place: keyset pages from the shared SQL builder. */
final class SearchService
{
    public const PAGE_SIZE = 20;
    public const BATCH_SIZE = 60;
    public const MAX_QUERIES = 4;

    /**
     * @return array{filters:array,items:list<array>,searched:bool,facets:list<string>,next_cursor:?string,total_aprox:int|string|null,unresolved:bool,iteracoes:int,linhas_lidas:int}
     */
    public static function run(SearchQuery $query, bool $log, mixed $host): array
    {
        return self::search($query, $host, $log);
    }

    /**
     * @return array{filters:array,items:list<array>,searched:bool,facets:list<string>,next_cursor:?string,total_aprox:int|string|null,unresolved:bool,iteracoes:int,linhas_lidas:int}
     */
    public static function search(SearchQuery $query, mixed $host, bool $log = false): array
    {
        $result = self::find($query, $host);
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
     * @return array{filters:array,items:list<array>,searched:bool,facets:list<string>,next_cursor:?string,total_aprox:int|string|null,unresolved:bool,iteracoes:int,linhas_lidas:int,place_id:?int}
     */
    private static function find(SearchQuery $query, mixed $host): array
    {
        $out = [
            'filters' => $query->filters(),
            'items' => [],
            'searched' => $query->searched(),
            'facets' => $query->searched() ? Amenities::keys() : [],
            'next_cursor' => null,
            'total_aprox' => null,
            'unresolved' => false,
            'iteracoes' => 0,
            'linhas_lidas' => 0,
            'place_id' => null,
        ];
        if (!$query->searched()) {
            return $out;
        }
        $place = $query->localidade !== '' ? CityRepository::match($query->localidade) : null;
        if ($query->localidade !== '' && $place === null) {
            $out['unresolved'] = true;
            return $out;
        }
        if ($place === null) {
            return $out;
        }
        $out['place_id'] = (int) $place['id'];
        $groupId = self::groupId($query, $host);
        if ($groupId === false) {
            return $out;
        }
        $nights = nights_between($query->checkin, $query->checkout);
        $checkin = $nights[0] ?? null;
        $checkout = $checkin === null
            ? null
            : (new DateTimeImmutable($nights[array_key_last($nights)]))->modify('+1 day')->format('Y-m-d');
        $bbox = $query->raio > 0 ? SearchSql::bbox((float) $place['lat'], (float) $place['lng'], $query->raio) : null;
        $placeId = $bbox === null ? (int) $place['id'] : null;
        $out['total_aprox'] = SearchSql::totalAprox($placeId, $groupId, $query->hospedes, $bbox, $checkin, $checkout);

        $known = Amenities::keys();
        $wanted = array_values(array_filter(
            $query->amenidades,
            static fn (string $key): bool => in_array($key, $known, true)
        ));
        $page = [];
        $after = $query->lastId;
        $hasMore = true;
        $read = 0;
        $queries = 0;
        while (count($page) < self::PAGE_SIZE && $queries < self::MAX_QUERIES && $hasMore) {
            $queries++;
            $fetched = SearchSql::batch($placeId, $groupId, $query->hospedes, $bbox, $checkin, $checkout, $after, self::BATCH_SIZE + 1);
            $hasMore = count($fetched) > self::BATCH_SIZE;
            if ($hasMore) {
                array_pop($fetched);
            }
            if ($fetched === []) {
                $hasMore = false;
                break;
            }
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $fetched);
            $calendars = $nights !== [] ? HouseRepository::calendarForProperties($ids, $nights) : [];
            $scores = HouseRepository::averageScores($ids);
            $photos = PhotoRepository::primaryPaths($ids);
            foreach ($fetched as $property) {
                $read++;
                $id = (int) $property['id'];
                $after = $id;
                $kept = self::keep($property, $query, $nights, $place, $calendars[$id] ?? [], $scores[$id] ?? null, $wanted);
                if ($kept === null) {
                    continue;
                }
                $kept['photo'] = $photos[$id] ?? null;
                $page[] = $kept;
                if (count($page) === self::PAGE_SIZE) {
                    break;
                }
            }
            if (count($page) === self::PAGE_SIZE && $after !== (int) $fetched[array_key_last($fetched)]['id']) {
                $hasMore = true;
            }
        }
        $out['items'] = $page;
        $out['iteracoes'] = $queries;
        $out['linhas_lidas'] = $read;
        if ($hasMore && $read > 0) {
            $out['next_cursor'] = SearchQuery::cursor((int) $after, $query->filtersHash());
        }
        return $out;
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

    /**
     * Price, score and amenities stay in PHP. Radius haversine too, after the SQL bbox.
     *
     * @param list<string> $nights
     * @param array<string, array> $calendar
     * @param list<string> $wanted
     */
    private static function keep(array $property, SearchQuery $query, array $nights, array $place, array $calendar, ?float $score, array $wanted): ?array
    {
        if ($query->raio > 0) {
            $distance = public_distance_km($place, $property);
            if ($distance > $query->raio) {
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
        if ($query->scoreMin > 0 && ($score === null || $score < $query->scoreMin)) {
            return null;
        }
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

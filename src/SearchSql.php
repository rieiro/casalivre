<?php

declare(strict_types=1);

require_once __DIR__ . '/HouseRepository.php';
require_once __DIR__ . '/SearchQuery.php';

/**
 * One listing query for public search and the storefront.
 * Active means status = 'active' on listing_search and on properties (empty status is not active).
 * Order is rank_base (relevance) or rating_rank, then id DESC.
 *
 * No neighborhood, group, or dates: a limit-first listing_search subquery, then properties for the page.
 * totalAprox for that shape reads listing_search only. A bbox with a group reads the group first
 * and writes the box as BETWEEN. Score is listing_search.rating_tenths.
 */
final class SearchSql
{
    private const JOIN_PROPERTIES = 'JOIN properties p ON p.id = s.id';
    private const JOIN_LISTING = 'listing_search s ' . self::JOIN_PROPERTIES;
    private const GROUP_CTE = 'WITH g AS MATERIALIZED (SELECT id FROM properties WHERE group_id = ?) ';

    /** @var array<string, string> */
    private const SORT_COLUMN = [
        SearchQuery::SORT_RELEVANCE => 'rank_base',
        SearchQuery::SORT_RATING => 'rating_rank',
    ];

    /** @param ''|'p'|string $alias */
    public static function active(string $alias = 'p'): string
    {
        return ($alias === '' ? 'status' : $alias . '.status') . " = 'active'";
    }

    /** Whitelisted order column: rank_base or rating_rank. */
    public static function sortColumn(string $sort): string
    {
        return self::SORT_COLUMN[$sort];
    }

    /** ORDER BY fragment. Only rank_base, rating_rank, and id. */
    public static function orderBy(string $sort): string
    {
        return 's.' . self::sortColumn($sort) . ' DESC, s.id DESC';
    }

    /**
     * Radius prefilter. One extra kilometre so the public-coordinate offset (300–500 m)
     * cannot drop a row the haversine check would keep.
     *
     * @return array{s:float,n:float,w:float,e:float}
     */
    public static function bbox(float $lat, float $lng, int $radiusKm): array
    {
        $km = $radiusKm + 1;
        $dLat = $km / 111.32;
        $dLng = $km / (111.32 * cos(deg2rad($lat)));
        return ['s' => $lat - $dLat, 'n' => $lat + $dLat, 'w' => $lng - $dLng, 'e' => $lng + $dLng];
    }

    /**
     * Predicate for listing_search s JOIN properties p. Starts with p.status.
     * A city id applies only without a bbox. A neighborhood id always applies, with its city.
     * A bbox here is the idx_ls_geo box (the same inclusive bounds the group-first path writes as BETWEEN).
     * $sort is the keyset column when $after is set.
     *
     * @param array{id:int,type:string,parent_id?:int|null} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     * @param array{key:int,id:int}|null $after
     * @return array{0:string,1:list<int|float|string|null>}
     */
    public static function where(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, ?array $after, float $scoreMin, string $sort): array
    {
        [$where, $params] = self::filters($place, $groupId, $guests, $bbox, $checkin, $checkout, $scoreMin);
        return self::andKeyset($where, $params, $after, $sort);
    }

    /**
     * @param array{key:int,id:int}|null $after
     * @param list<int|float|string|null> $params
     * @return array{0:string,1:list<int|float|string|null>}
     */
    private static function andKeyset(string $where, array $params, ?array $after, string $sort): array
    {
        if ($after === null) {
            return [$where, $params];
        }
        $where .= ' AND (s.' . self::sortColumn($sort) . ', s.id) < (?, ?)';
        array_push($params, $after['key'], $after['id']);
        return [$where, $params];
    }

    /**
     * @param array{id:int,type:string,parent_id?:int|null} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     * @return array{0:string,1:list<int|float|string|null>}
     */
    private static function filters(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, float $scoreMin): array
    {
        [$where, $params] = self::listingFilters($place, $guests, $bbox, $scoreMin);
        $where = self::active('p') . ' AND ' . $where;
        [$propertyWhere, $propertyParams] = self::propertyFilters($place, $groupId, $checkin, $checkout);
        if ($propertyWhere !== '') {
            $where .= ' AND ' . $propertyWhere;
            array_push($params, ...$propertyParams);
        }
        return [$where, $params];
    }

    /**
     * Predicates on listing_search: status, city, guests, the geo box (or the group BETWEEN), and rating_tenths.
     * $groupBox writes the bbox as lng/lat BETWEEN (same inclusive bounds as <@ box).
     *
     * @param array{id:int,type:string,parent_id?:int|null} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     * @return array{0:string,1:list<int|float>}
     */
    private static function listingFilters(array $place, int $guests, ?array $bbox, float $scoreMin, bool $groupBox = false): array
    {
        $where = [self::active('s')];
        $params = [];
        if ($groupBox) {
            $where[] = 's.lng BETWEEN ? AND ?';
            $where[] = 's.lat BETWEEN ? AND ?';
            array_push($params, $bbox['w'], $bbox['e'], $bbox['s'], $bbox['n']);
        }
        if ($place['type'] === 'neighborhood') {
            $where[] = 's.city_place_id = ?';
            $params[] = (int) $place['parent_id'];
        } elseif ($place['type'] === 'city' && $bbox === null) {
            $where[] = 's.city_place_id = ?';
            $params[] = (int) $place['id'];
        }
        if ($guests > 0) {
            $where[] = 's.max_guests >= ?';
            $params[] = $guests;
        }
        if (!$groupBox && $bbox !== null) {
            $where[] = 'point(s.lng, s.lat) <@ box(point(?, ?), point(?, ?))';
            array_push($params, $bbox['w'], $bbox['s'], $bbox['e'], $bbox['n']);
        }
        if ($scoreMin > 0) {
            // t = ceil(round(10*score_min, 6)). rating_tenths stores (20*sum + count) / (2*count).
            $where[] = 's.rating_tenths >= ?';
            $params[] = (int) ceil(round(10 * $scoreMin, 6));
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Predicates that need properties: neighborhood, group, and dates. Score is a listing filter.
     * p.status is applied by the caller, once, on the properties side of the join.
     *
     * @param array{id:int,type:string,parent_id?:int|null} $place
     * @return array{0:string,1:list<int|string>}
     */
    private static function propertyFilters(array $place, ?int $groupId, ?string $checkin, ?string $checkout): array
    {
        $where = [];
        $params = [];
        if ($place['type'] === 'neighborhood') {
            $where[] = 'p.neighborhood_place_id = ?';
            $params[] = (int) $place['id'];
        }
        if ($groupId !== null) {
            $where[] = 'p.group_id = ?';
            $params[] = $groupId;
        }
        if ($checkin !== null && $checkout !== null) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM calendar_days c WHERE c.property_id = p.id AND c.day >= ? AND c.day < ? AND c.blocked = 1)';
            array_push($params, $checkin, $checkout);
        }
        return [implode(' AND ', $where), $params];
    }

    /** Neighborhood, group, or dates. Score and guests stay on listing_search. */
    private static function hasPropertyFilter(array $place, ?int $groupId, ?string $checkin, ?string $checkout): bool
    {
        return $place['type'] === 'neighborhood' || $groupId !== null || ($checkin !== null && $checkout !== null);
    }

    private static function pageSql(string $from): string
    {
        return 'SELECT p.*, s.rank_base, s.rating_rank, ' . HouseRepository::CITY_LABEL_SQL . ' AS city FROM ' . $from;
    }

    /** p.status, plus the property predicates when there are any. */
    private static function outerWhere(string $propertyWhere): string
    {
        return self::active('p') . ($propertyWhere !== '' ? ' AND ' . $propertyWhere : '');
    }

    /** Close the listing subquery and join properties and the city. */
    private static function joinPage(): string
    {
        return ') s ' . self::JOIN_PROPERTIES . ' ' . HouseRepository::JOIN_CITY_SQL;
    }

    /** @param list<int|float|string|null> $params */
    private static function rows(string $sql, array $params): array
    {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Listing predicates inside the group, and p.status plus the property predicates outside it.
     * Params start with the group id bound in the CTE.
     *
     * @param array{id:int,type:string,parent_id?:int|null} $place
     * @param array{s:float,n:float,w:float,e:float} $bbox
     * @param array{key:int,id:int}|null $after
     * @return array{0:string,1:string,2:list<int|float|string|null>}
     */
    private static function groupParts(array $place, int $groupId, int $guests, array $bbox, ?string $checkin, ?string $checkout, ?array $after, float $scoreMin, string $sort): array
    {
        [$inner, $params] = self::listingFilters($place, $guests, $bbox, $scoreMin, groupBox: true);
        [$inner, $params] = self::andKeyset($inner, $params, $after, $sort);
        [$propertyWhere, $propertyParams] = self::propertyFilters($place, $groupId, $checkin, $checkout);
        return [$inner, self::outerWhere($propertyWhere), [$groupId, ...$params, ...$propertyParams]];
    }

    /**
     * @param array{id:int,type:string,parent_id?:int|null} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     * @param array{key:int,id:int}|null $after
     * @return list<array>
     */
    public static function batch(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, ?array $after, int $limit, float $scoreMin, string $sort, int $offset = 0): array
    {
        $order = self::orderBy($sort);
        $offsetSql = $offset > 0 ? ' OFFSET ' . $offset : '';
        if ($bbox !== null && $groupId !== null) {
            [$inner, $outer, $params] = self::groupParts($place, $groupId, $guests, $bbox, $checkin, $checkout, $after, $scoreMin, $sort);
            $from = '(SELECT s.id, s.rank_base, s.rating_rank FROM g JOIN listing_search s ON s.id = g.id WHERE ' . $inner
                . ' ORDER BY ' . $order . self::joinPage()
                . ' WHERE ' . $outer . ' ORDER BY ' . $order . ' LIMIT ' . $limit . $offsetSql;
            return self::rows(self::GROUP_CTE . self::pageSql($from), $params);
        }
        $propertyFilter = self::hasPropertyFilter($place, $groupId, $checkin, $checkout);
        if ($propertyFilter && $bbox === null) {
            [$where, $params] = self::where($place, $groupId, $guests, $bbox, $checkin, $checkout, $after, $scoreMin, $sort);
            $from = self::JOIN_LISTING . ' ' . HouseRepository::JOIN_CITY_SQL
                . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit . $offsetSql;
            return self::rows(self::pageSql($from), $params);
        }
        // id, rank_base and rating_rank are in idx_ls_place_* and idx_ls_geo. s.* would read lat/lng
        // (city index) or city_place_id (geo index) and the walk could not stay index-only.
        [$where, $params] = self::listingFilters($place, $guests, $bbox, $scoreMin);
        [$where, $params] = self::andKeyset($where, $params, $after, $sort);
        $sub = '(SELECT s.id, s.rank_base, s.rating_rank FROM listing_search s WHERE ' . $where . ' ORDER BY ' . $order;
        if (!$propertyFilter) {
            // p.status is outside the inner LIMIT: a stale listing_search row is not returned. It can only shorten that page.
            $from = $sub . ' LIMIT ' . $limit . $offsetSql . self::joinPage()
                . ' WHERE ' . self::outerWhere('')
                . ' ORDER BY ' . $order . ' LIMIT ' . $limit;
            return self::rows(self::pageSql($from), $params);
        }
        [$propertyWhere, $propertyParams] = self::propertyFilters($place, $groupId, $checkin, $checkout);
        array_push($params, ...$propertyParams);
        $from = $sub . self::joinPage() . ' WHERE ' . self::outerWhere($propertyWhere) . ' ORDER BY ' . $order . ' LIMIT ' . $limit . $offsetSql;
        return self::rows(self::pageSql($from), $params);
    }

    /**
     * Capped candidate count (ADR §5b): the number, or "100+" past 100.
     * With no neighborhood, group, or dates this does not join properties: it counts listing_search.status.
     * That column is the projection of properties.status. A stale active row can be counted; batch still
     * requires p.status = 'active' and does not return it.
     *
     * @param array{id:int,type:string,parent_id?:int|null} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     */
    public static function totalCount(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, float $scoreMin): int
    {
        if ($bbox !== null && $groupId !== null) {
            [$inner, $outer, $params] = self::groupParts($place, $groupId, $guests, $bbox, $checkin, $checkout, null, $scoreMin, SearchQuery::SORT_RELEVANCE);
            $sql = self::GROUP_CTE . 'SELECT COUNT(*) FROM g JOIN listing_search s ON s.id = g.id JOIN properties p ON p.id = s.id WHERE '
                . $inner . ' AND ' . $outer;
        } elseif (!self::hasPropertyFilter($place, $groupId, $checkin, $checkout)) {
            [$where, $params] = self::listingFilters($place, $guests, $bbox, $scoreMin);
            $sql = 'SELECT COUNT(*) FROM listing_search s WHERE ' . $where;
        } else {
            [$where, $params] = self::filters($place, $groupId, $guests, $bbox, $checkin, $checkout, $scoreMin);
            $sql = 'SELECT COUNT(*) FROM ' . self::JOIN_LISTING . ' WHERE ' . $where;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function totalAprox(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, float $scoreMin): int|string
    {
        if ($bbox !== null && $groupId !== null) {
            [$inner, $outer, $params] = self::groupParts($place, $groupId, $guests, $bbox, $checkin, $checkout, null, $scoreMin, SearchQuery::SORT_RELEVANCE);
            $sql = self::GROUP_CTE . 'SELECT 1 FROM g JOIN listing_search s ON s.id = g.id JOIN properties p ON p.id = s.id WHERE '
                . $inner . ' AND ' . $outer . ' LIMIT 101';
        } elseif (!self::hasPropertyFilter($place, $groupId, $checkin, $checkout)) {
            [$where, $params] = self::listingFilters($place, $guests, $bbox, $scoreMin);
            $sql = 'SELECT 1 FROM listing_search s WHERE ' . $where . ' LIMIT 101';
        } else {
            [$where, $params] = self::filters($place, $groupId, $guests, $bbox, $checkin, $checkout, $scoreMin);
            $sql = 'SELECT 1 FROM ' . self::JOIN_LISTING . " WHERE $where LIMIT 101";
        }
        $n = count(self::rows($sql, $params));
        return $n > 100 ? '100+' : $n;
    }
}

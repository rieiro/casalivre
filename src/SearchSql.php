<?php

declare(strict_types=1);

require_once __DIR__ . '/HouseRepository.php';

/**
 * One listing query for public search and the storefront.
 * Active means status = 'active' (empty status is not active).
 * Order is rank_base (relevance) or rating_rank, then id DESC.
 */
final class SearchSql
{
    private const JOIN_LISTING = 'listing_search s JOIN properties p ON p.id = s.id';

    /** sort param => listing_search column. Anything else is relevance. */
    private const SORT_COLUMN = [
        'relevance' => 'rank_base',
        'rating' => 'rating_rank',
    ];

    /** @param ''|'p'|string $alias */
    public static function active(string $alias = 'p'): string
    {
        return ($alias === '' ? 'status' : $alias . '.status') . " = 'active'";
    }

    /** Whitelisted order column. Unknown sorts use rank_base. */
    public static function sortColumn(string $sort): string
    {
        return self::SORT_COLUMN[$sort] ?? self::SORT_COLUMN['relevance'];
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
     * A city id applies only without a bbox. A neighborhood id always applies, with its city.
     * A POI adds no id; its bbox uses the idx_ls_geo expression.
     *
     * @param array{id:int,type:string,parent_id?:int|null} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     * @param array{key:int,id:int}|null $after
     * @return array{0:string,1:list<int|float|string|null>}
     */
    public static function where(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, ?array $after, float $scoreMin, string $sort = 'relevance'): array
    {
        $where = [self::active('s')];
        $params = [];
        if ($place['type'] === 'neighborhood') {
            $where[] = 's.city_place_id = ? AND ' . self::active('p') . ' AND p.neighborhood_place_id = ?';
            array_push($params, (int) $place['parent_id'], (int) $place['id']);
        } elseif ($place['type'] === 'city' && $bbox === null) {
            $where[] = 's.city_place_id = ?';
            $params[] = (int) $place['id'];
        }
        if ($groupId !== null) {
            $where[] = 'p.group_id = ?';
            $params[] = $groupId;
        }
        if ($guests > 0) {
            $where[] = 's.max_guests >= ?';
            $params[] = $guests;
        }
        if ($bbox !== null) {
            if ($place['type'] === 'poi') {
                $where[] = 'point(s.lng, s.lat) <@ box(point(?, ?), point(?, ?))';
                array_push($params, $bbox['w'], $bbox['s'], $bbox['e'], $bbox['n']);
            } else {
                $where[] = 's.lat BETWEEN ? AND ? AND s.lng BETWEEN ? AND ?';
                array_push($params, $bbox['s'], $bbox['n'], $bbox['w'], $bbox['e']);
            }
        }
        if ($checkin !== null && $checkout !== null) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM calendar_days c WHERE c.property_id = p.id AND c.day >= ? AND c.day < ? AND c.blocked = 1)';
            array_push($params, $checkin, $checkout);
        }
        if ($after !== null) {
            $where[] = '(s.' . self::sortColumn($sort) . ', s.id) < (?, ?)';
            array_push($params, $after['key'], $after['id']);
        }
        if ($scoreMin > 0) {
            // t = ceil(round(10*score_min, 6)). 20*sum + count >= 2*t*count is round(sum/count, 1) >= score_min.
            $t = (int) ceil(round(10 * $scoreMin, 6));
            $where[] = 'p.rating_count > 0 AND 20 * p.rating_sum + p.rating_count >= ? * p.rating_count';
            $params[] = 2 * $t;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array{id:int,type:string,parent_id?:int|null} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     * @param array{key:int,id:int}|null $after
     * @return list<array>
     */
    public static function batch(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, ?array $after, int $limit, float $scoreMin, string $sort = 'relevance'): array
    {
        [$where, $params] = self::where($place, $groupId, $guests, $bbox, $checkin, $checkout, $after, $scoreMin, $sort);
        $sql = 'SELECT p.*, s.rank_base, s.rating_rank, ' . HouseRepository::CITY_LABEL_SQL . ' AS city FROM '
            . self::JOIN_LISTING . ' ' . HouseRepository::JOIN_CITY_SQL
            . " WHERE $where ORDER BY " . self::orderBy($sort) . " LIMIT $limit";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Capped candidate count (ADR §5b): the number, or "100+" past 100.
     *
     * @param array{id:int,type:string,parent_id?:int|null} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     */
    public static function totalAprox(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, float $scoreMin): int|string
    {
        [$where, $params] = self::where($place, $groupId, $guests, $bbox, $checkin, $checkout, null, $scoreMin);
        $stmt = db()->prepare('SELECT 1 FROM ' . self::JOIN_LISTING . " WHERE $where LIMIT 101");
        $stmt->execute($params);
        $n = count($stmt->fetchAll());
        return $n > 100 ? '100+' : $n;
    }
}

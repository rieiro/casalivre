<?php

declare(strict_types=1);

require_once __DIR__ . '/HouseRepository.php';

/**
 * One listing query for public search and the storefront.
 * Active means status = 'active' (empty status is not active). Order is id ASC until relevance ships.
 */
final class SearchSql
{
    /** @param ''|'p'|string $alias */
    public static function active(string $alias = 'p'): string
    {
        return ($alias === '' ? 'status' : $alias . '.status') . " = 'active'";
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
     * A city id applies only without a bbox. A neighborhood id always applies. A POI adds no id.
     *
     * @param array{id:int,type:string} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     * @return array{0:string,1:list<int|float|string|null>}
     */
    public static function where(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, ?int $afterId, float $scoreMin = 0.0): array
    {
        $where = [self::active('p')];
        $params = [];
        $column = match ($place['type']) {
            'neighborhood' => 'p.neighborhood_place_id',
            'city' => $bbox === null ? 'p.city_place_id' : null,
            default => null,
        };
        if ($column !== null) {
            $where[] = $column . ' = ?';
            $params[] = (int) $place['id'];
        }
        if ($groupId !== null) {
            $where[] = 'p.group_id = ?';
            $params[] = $groupId;
        }
        if ($guests > 0) {
            $where[] = 'p.max_guests >= ?';
            $params[] = $guests;
        }
        if ($bbox !== null) {
            $where[] = 'p.lat BETWEEN ? AND ? AND p.lng BETWEEN ? AND ?';
            array_push($params, $bbox['s'], $bbox['n'], $bbox['w'], $bbox['e']);
        }
        if ($checkin !== null && $checkout !== null) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM calendar_days c WHERE c.property_id = p.id AND c.day >= ? AND c.day < ? AND c.blocked = 1)';
            array_push($params, $checkin, $checkout);
        }
        if ($afterId !== null) {
            $where[] = 'p.id > ?';
            $params[] = $afterId;
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
     * @param array{id:int,type:string} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     * @return list<array>
     */
    public static function batch(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, ?int $afterId, int $limit, float $scoreMin = 0.0): array
    {
        [$where, $params] = self::where($place, $groupId, $guests, $bbox, $checkin, $checkout, $afterId, $scoreMin);
        $stmt = db()->prepare(HouseRepository::SQL_WITH_CITY . " WHERE $where ORDER BY p.id ASC LIMIT $limit");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Capped candidate count (ADR §5b): the number, or "100+" past 100. Not the post-PHP-filter total.
     *
     * @param array{id:int,type:string} $place
     * @param array{s:float,n:float,w:float,e:float}|null $bbox
     */
    public static function totalAprox(array $place, ?int $groupId, int $guests, ?array $bbox, ?string $checkin, ?string $checkout, float $scoreMin = 0.0): int|string
    {
        [$where, $params] = self::where($place, $groupId, $guests, $bbox, $checkin, $checkout, null, $scoreMin);
        $stmt = db()->prepare("SELECT 1 FROM properties p WHERE $where LIMIT 101");
        $stmt->execute($params);
        $n = count($stmt->fetchAll());
        return $n > 100 ? '100+' : $n;
    }
}

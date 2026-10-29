<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';

/** Writes the search projection. Search reads stay on properties until PR4c. */
final class ListingSearch
{
    /** Session lock so a second rank-verify exits without touching rows. */
    public const ADVISORY_LOCK = 48001402;

    private const BATCH = 1000;

    /**
     * One projection. %s is the properties filter. Binds: weight, C, m, C, then the filter.
     * rating_rank = round(1000 * (C*m + rating_sum) / (C + n)); n = 0 yields round(1000*m).
     * rank_base = round((rating_rank / 10) * weight / 100).
     */
    private const UPSERT_SQL = <<<'SQL'
INSERT INTO listing_search (id, status, city_place_id, lat, lng, max_guests, rating_rank, rank_base)
SELECT s.id, s.status, s.city_place_id, s.lat, s.lng, s.max_guests, s.rating_rank,
       round(s.rating_rank / 10.0 * ?::numeric / 100.0)::integer
FROM (
    SELECT p.id, p.status, p.city_place_id, p.lat, p.lng, p.max_guests::smallint AS max_guests,
           round((1000::numeric * (?::numeric * ?::numeric + p.rating_sum)) / (?::numeric + p.rating_count))::integer AS rating_rank
    FROM properties p
    WHERE %s
) s
ON CONFLICT (id) DO UPDATE SET
    status = EXCLUDED.status,
    city_place_id = EXCLUDED.city_place_id,
    lat = EXCLUDED.lat,
    lng = EXCLUDED.lng,
    max_guests = EXCLUDED.max_guests,
    rating_rank = EXCLUDED.rating_rank,
    rank_base = EXCLUDED.rank_base
WHERE listing_search IS DISTINCT FROM EXCLUDED
RETURNING id
SQL;

    public static function sync(int $id): void
    {
        self::apply('p.id = ?', [$id]);
    }

    /** @return int rows inserted or changed */
    private static function apply(string $where, array $params): int
    {
        $c = Settings::int('rank_c');
        $m = Settings::int('rank_m');
        $weight = Settings::int('rank_w_rating');
        $stmt = db()->prepare(sprintf(self::UPSERT_SQL, $where));
        $stmt->execute([$weight, $c, $m, $c, ...$params]);
        return count($stmt->fetchAll());
    }

    /** Walk every property in batches. One transaction per batch. */
    public static function rewriteAll(): int
    {
        $total = 0;
        $after = 0;
        while (true) {
            $stmt = db()->prepare('SELECT id FROM properties WHERE id > ? ORDER BY id LIMIT ' . self::BATCH);
            $stmt->execute([$after]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($ids === []) {
                return $total;
            }
            $through = (int) $ids[array_key_last($ids)];
            $total += Db::transactional(db(), static fn (): int => self::apply('p.id > ? AND p.id <= ?', [$after, $through]));
            $after = $through;
        }
    }
}

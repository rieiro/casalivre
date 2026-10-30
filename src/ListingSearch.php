<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/SearchSql.php';

/** Writes the search projection. Search reads stay on properties until PR4c. */
final class ListingSearch
{
    /** Session lock so a second rank-verify exits without touching rows. */
    public const ADVISORY_LOCK = 48001402;

    private const BATCH = 1000;

    /**
     * Fresh projection for the properties filter. Binds: weight, C, m, C, then the filter.
     * rating_rank = round(1000 * (C*m + rating_sum) / (C + n)); n = 0 yields round(1000*m).
     * rank_base = round((rating_rank / 10) * weight / 100).
     * Status follows SearchSql::active(): 'active' stays active, anything else is inactive.
     */
    private static function projection(string $where): string
    {
        return sprintf(
            <<<'SQL'
SELECT s.id, s.status, s.city_place_id, s.lat, s.lng, s.max_guests, s.rating_rank,
       round(s.rating_rank / 10.0 * ?::numeric / 100.0)::integer AS rank_base
FROM (
    SELECT p.id, CASE WHEN %s THEN 'active' ELSE 'inactive' END AS status,
           p.city_place_id, p.lat, p.lng, p.max_guests::smallint AS max_guests,
           round((1000::numeric * (?::numeric * ?::numeric + p.rating_sum)) / (?::numeric + p.rating_count))::integer AS rating_rank
    FROM properties p
    WHERE %s
) s
SQL,
            SearchSql::active('p'),
            $where,
        );
    }

    /** @return list<int> weight, C, m, C */
    private static function rankBinds(): array
    {
        return [Settings::int('rank_w_rating'), Settings::int('rank_c'), Settings::int('rank_m'), Settings::int('rank_c')];
    }

    public static function sync(int $id): void
    {
        self::apply('p.id = ?', [$id]);
    }

    /** @return int rows inserted or changed */
    private static function apply(string $where, array $params): int
    {
        $stmt = db()->prepare(
            'INSERT INTO listing_search (id, status, city_place_id, lat, lng, max_guests, rating_rank, rank_base) '
            . self::projection($where)
            . <<<'SQL'
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
SQL
        );
        $stmt->execute([...self::rankBinds(), ...$params]);
        return count($stmt->fetchAll());
    }

    /**
     * Read-only diff of each id batch, then one transaction per mismatched id.
     * The property row is locked before sync so a concurrent writer commits first
     * and the sync reads current data. A no-op pass does not write.
     */
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
            foreach (self::mismatches($after, $through) as $id) {
                $total += self::repair((int) $id);
            }
            $after = $through;
        }
    }

    /** @return list<int|string> ids whose projection is missing or different */
    private static function mismatches(int $after, int $through): array
    {
        $stmt = db()->prepare(
            'SELECT fresh.id FROM (' . self::projection('p.id > ? AND p.id <= ?') . ') fresh '
            . 'LEFT JOIN listing_search ls ON ls.id = fresh.id WHERE ls IS DISTINCT FROM fresh'
        );
        $stmt->execute([...self::rankBinds(), $after, $through]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function repair(int $id): int
    {
        return Db::transactional(db(), static function () use ($id): int {
            db()->prepare('SELECT 1 FROM properties WHERE id = ? FOR SHARE')->execute([$id]);
            return self::apply('p.id = ?', [$id]);
        });
    }
}

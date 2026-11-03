<?php

declare(strict_types=1);

// F4: rating_tenths = (20*sum + count) / (2*count) matches round-half-up and every integer threshold.
require __DIR__ . '/lib.php';

$dsn = t_temp_pg_empty();
$pdo = new PDO($dsn, getenv('TEST_PG_USER') ?: null, getenv('TEST_PG_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "grid\n";
$roundDiff = (int) $pdo->query(
    <<<'SQL'
SELECT COUNT(*) FROM generate_series(1, 2000) n
CROSS JOIN LATERAL generate_series(n, 10 * n) s
WHERE (20 * s + n) / (2 * n) <> floor((10 * s)::numeric / n + 0.5)::int
SQL
)->fetchColumn();
t_eq(0, $roundDiff, 'count 1..2000, sum count..10*count: tenths equals floor(10*sum/count + 0.5)');

echo "thresholds\n";
$thresholdDiff = (int) $pdo->query(
    <<<'SQL'
SELECT COUNT(*) FROM (
    SELECT n, s, (20 * s + n) / (2 * n) AS r
    FROM generate_series(1, 300) n
    CROSS JOIN LATERAL generate_series(n, 10 * n) s
) x
CROSS JOIN generate_series(1, 100) t
WHERE (x.r >= t) IS DISTINCT FROM (20 * x.s + x.n >= 2 * t * x.n)
SQL
)->fetchColumn();
t_eq(0, $thresholdDiff, 'count 1..300, t 1..100: rating_tenths >= t matches 20*sum + count >= 2*t*count');
t_eq(45, intdiv(20 * 9 + 2, 2 * 2), 'reviews 4 and 5 store 45');
t_eq(80, intdiv(20 * 159 + 20, 2 * 20), '19 eights and one seven store 80');
t_eq(100, intdiv(20 * 10 + 1, 2 * 1), 'a single 10 stores 100');
t_eq(10, intdiv(20 * 1 + 1, 2 * 1), 'a single 1 stores 10');

t_done();

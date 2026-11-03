<?php

declare(strict_types=1);

// F4: sync() writes rating_tenths, and a clean rank-verify rewrites nothing.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/ListingSearch.php';

t_temp_db();
$owner = t_seed_owner();
$id = (int) $owner['house_id'];
$read = static function () use ($id): mixed {
    $stmt = db()->prepare('SELECT rating_tenths FROM listing_search WHERE id = ?');
    $stmt->execute([$id]);
    $value = $stmt->fetchColumn();
    return $value === null ? null : (int) $value;
};
t_eq(null, $read(), 'no reviews store NULL');
t_eq(
    'rating_tenths',
    (string) db()->query("SELECT attname FROM pg_attribute WHERE attrelid = 'listing_search'::regclass AND attnum > 0 AND NOT attisdropped ORDER BY attnum DESC LIMIT 1")->fetchColumn(),
    'rating_tenths is the last column'
);

HouseRepository::addReview($id, 'Ana', 10, '');
HouseRepository::addReview($id, 'Bia', 9, '');
t_eq(95, $read(), 'two reviews sync (20*19+2)/(2*2) = 95');

$verify = static function (): array {
    exec(t_php_shell() . ' ' . escapeshellarg(CL_ROOT . '/scripts/rank-verify.php') . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
echo "verify\n";
[$code, $text] = $verify();
t_eq(0, $code, "clean verify exits 0 ($text)");
t_ok(str_contains($text, 'rewritten 0'), 'clean verify rewrites nothing');

db()->prepare('UPDATE listing_search SET rating_tenths = 50 WHERE id = ?')->execute([$id]);
t_eq(50, $read(), 'the row was tampered');
[$code, $text] = $verify();
t_eq(1, $code, "a tenths mismatch exits 1 ($text)");
t_ok(str_contains($text, 'rewritten 1'), 'verify rewrites the tampered row');
t_eq(95, $read(), 'sync restores 95');

echo "check\n";
$rejected = static function (int|null $value) use ($id): bool {
    try {
        db()->prepare('UPDATE listing_search SET rating_tenths = ? WHERE id = ?')->execute([$value, $id]);
        return false;
    } catch (PDOException) {
        return true;
    }
};
t_ok($rejected(9), '9 is rejected');
t_ok($rejected(101), '101 is rejected');
t_ok(!$rejected(10), '10 is accepted');
t_ok(!$rejected(100), '100 is accepted');
t_ok(!$rejected(null), 'NULL is accepted');
ListingSearch::sync($id);
t_eq(95, $read(), 'sync puts 95 back');

t_done();

#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/ListingSearch.php';

Db::loadEnv();
$asked = in_array('--recompute', $argv, true);
$pdo = db();
if (!$pdo->query('SELECT pg_try_advisory_lock(' . ListingSearch::ADVISORY_LOCK . ')')->fetchColumn()) {
    exit(0);
}

$applied = Settings::refresh()['rank_applied']['value'];
$current = Settings::rankFingerprint();
$paramsChanged = $applied !== $current;
$recompute = $asked || $paramsChanged;

$rewritten = ListingSearch::rewriteAll();
$pdo->exec('VACUUM (ANALYZE) listing_search');
if ($recompute) {
    foreach (['idx_ls_place_rank', 'idx_ls_place_rating', 'idx_ls_geo'] as $index) {
        $pdo->exec('REINDEX INDEX CONCURRENTLY ' . $index);
    }
}
Settings::set('rank_applied', $current);
if ($paramsChanged && !$asked) {
    echo "params changed\n";
}
echo "rewritten $rewritten\n";
exit($recompute || $rewritten === 0 ? 0 : 1);

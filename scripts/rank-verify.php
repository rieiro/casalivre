#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/ListingSearch.php';

Db::loadEnv();
if (!getenv('DB_DSN')) {
    fwrite(STDERR, "DB_DSN is not set.\n");
    exit(2);
}

$recompute = in_array('--recompute', $argv, true);
$pdo = db();
$locked = $pdo->query('SELECT pg_try_advisory_lock(' . ListingSearch::ADVISORY_LOCK . ')')->fetchColumn();
if ($locked === false || $locked === 'f' || $locked === 0 || $locked === '0') {
    exit(0);
}

try {
    $rewritten = ListingSearch::rewriteAll();
    $pdo->exec('VACUUM (ANALYZE) listing_search');
    echo "rewritten $rewritten\n";
    exit($recompute || $rewritten === 0 ? 0 : 1);
} finally {
    $pdo->query('SELECT pg_advisory_unlock(' . ListingSearch::ADVISORY_LOCK . ')');
}

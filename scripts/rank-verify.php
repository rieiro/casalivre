#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/ListingSearch.php';

Db::loadEnv();
$recompute = in_array('--recompute', $argv, true);
$pdo = db();
if (!$pdo->query('SELECT pg_try_advisory_lock(' . ListingSearch::ADVISORY_LOCK . ')')->fetchColumn()) {
    exit(0);
}

$rewritten = ListingSearch::rewriteAll();
$pdo->exec('VACUUM (ANALYZE) listing_search');
echo "rewritten $rewritten\n";
exit($recompute || $rewritten === 0 ? 0 : 1);

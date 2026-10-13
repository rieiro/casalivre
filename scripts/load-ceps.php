#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/JsonCache.php';
require_once $root . '/src/CepRepository.php';

$before = CepRepository::count();
$loaded = CepRepository::ensureLoaded();
echo $before > 0
    ? "CEPs já carregados: $before\n"
    : "CEPs importados: $loaded\n";
if (!is_file(JsonCache::path('cep.json'))) {
    $cached = CepRepository::refresh();
    echo "Cache storage/cache/cep.json ($cached CEPs)\n";
}

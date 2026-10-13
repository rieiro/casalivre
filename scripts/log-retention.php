#!/usr/bin/env php
<?php

declare(strict_types=1);

// Retenção diária de logs (padrão 14 dias). Cron sugerido:
//   15 3 * * *  php /caminho/casalivre/scripts/log-retention.php
// Uso: php scripts/log-retention.php [dias]

$root = dirname(__DIR__);
require_once $root . '/src/LogRetention.php';

$days = isset($argv[1]) ? max(1, (int) $argv[1]) : LogRetention::DEFAULT_DAYS;
$report = LogRetention::run($root, $days);
foreach ($report as $target => $count) {
    echo str_pad((string) $target, 34) . $count . "\n";
}

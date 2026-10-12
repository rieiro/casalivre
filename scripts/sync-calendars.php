#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/Db.php';
require $root . '/src/HouseRepository.php';
require $root . '/src/helpers.php';
require $root . '/src/CalendarSync.php';

$result = CalendarSync::run();
echo $result['summary'] . "\n";
foreach ($result['lines'] as $line) {
    echo $line . "\n";
}
echo "Registro: storage/logs/calendar-sync.log\n";
exit($result['nok'] > 0 ? 1 : 0);

#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/Settings.php';
require_once $root . '/src/HouseRepository.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/CalendarSync.php';
// Sem isto activity() não existe no cron e as auditorias CALENDAR_SYNC/CALENDAR_UPDATE são puladas em silêncio.
require_once $root . '/src/ActivityLog.php';

$trimmed = CalendarSync::trimHorizon();
echo "Noites além do horizonte removidas: $trimmed\n";
$result = CalendarSync::run();
echo $result['summary'] . "\n";
foreach ($result['lines'] as $line) {
    echo $line . "\n";
}
echo "Registro: storage/logs/calendar-sync.log\n";
exit($result['nok'] > 0 ? 1 : 0);

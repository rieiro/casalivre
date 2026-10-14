#!/usr/bin/env php
<?php

declare(strict_types=1);

// Worker da fila de e-mails: processa UM lote e sai. Cron (a cada minuto):
//   * * * * *  php /caminho/casalivre/scripts/mail-worker.php
// flock em storage/mail-worker.lock: se outro worker estiver rodando, sai sem fazer nada.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/MailWorker.php';
Db::loadEnv();

@mkdir($root . '/storage', 0700, true);
$report = MailWorker::run(MailQueues::make(), $root . '/storage/mail-worker.lock');
if ($report === null) {
    echo "outro worker em execução\n";
    exit(0);
}
foreach ($report as $k => $v) {
    echo str_pad($k, 14) . $v . "\n";
}

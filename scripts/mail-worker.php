#!/usr/bin/env php
<?php

declare(strict_types=1);

// Worker da fila de e-mails: processa UM lote e sai. Cron (a cada minuto):
//   * * * * *  php /caminho/casalivre/scripts/mail-worker.php
// flock em storage/mail-worker.lock: se outro worker estiver rodando, sai sem fazer nada.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/AppEnv.php';
require_once $root . '/src/Mailer.php';
require_once $root . '/src/MailWorker.php';
Db::loadEnv();

// Fora de dev/local, APP_URL válida (http/https com host) é obrigatória: os links dos e-mails saem dela. Checado antes
// de lock, banco ou fila: sem ela o worker não tira nada da fila (sem tentativa gasta) e sai com erro claro.
if (!AppEnv::isDev()) {
    try {
        Mailer::linkFromAppUrl('http://localhost/');
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'mail-worker: ' . $e->getMessage() . " Defina APP_URL (http:// ou https:// com host) no .env; nada foi tirado da fila.\n");
        exit(2);
    }
}

@mkdir($root . '/storage', 0700, true);
$report = MailWorker::run(MailQueues::make(), $root . '/storage/mail-worker.lock');
if ($report === null) {
    echo "outro worker em execução\n";
    exit(0);
}
foreach ($report as $k => $v) {
    echo str_pad($k, 14) . $v . "\n";
}

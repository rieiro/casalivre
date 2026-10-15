#!/usr/bin/env php
<?php

declare(strict_types=1);

// Reverificação DNS TXT dos domínios de grupo (pr64 C1/C2), fora da requisição. Cron sugerido (a cada hora: confere
// os pedidos de "Verificar agora" e reconfere cada domínio verificado uma vez por dia):
//   17 * * * *  php /caminho/casalivre/scripts/verify-domains.php
// flock em storage/domain-verify.lock (0600): se outra execução estiver rodando, sai com 0 sem fazer nada (pr65 C3).
// Sai com 0; o resumo vai para a saída padrão.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/Settings.php';
require_once $root . '/src/helpers.php';
// Sem isto activity() não existe no cron e os logs 45/46 são pulados em silêncio.
require_once $root . '/src/ActivityLog.php';
require_once $root . '/src/DomainReverification.php';
require_once $root . '/src/QueueWorker.php';
Db::loadEnv();

@mkdir($root . '/storage', 0700, true);
$lock = QueueWorker::openLock($root . '/storage/domain-verify.lock');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "outra verificação de domínios em execução\n";
    exit(0);
}

$report = (new DomainReverification(new DomainVerification()))->run();
foreach ($report as $key => $count) {
    echo str_pad($key, 16) . $count . "\n";
}
exit(0);

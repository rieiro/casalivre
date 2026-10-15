#!/usr/bin/env php
<?php

declare(strict_types=1);

// Reverificação DNS TXT dos domínios de grupo (pr64 C1/C2), fora da requisição. Cron sugerido (a cada hora: confere
// os pedidos de "Verificar agora" e reconfere cada domínio verificado uma vez por dia):
//   17 * * * *  php /caminho/casalivre/scripts/verify-domains.php
// Sai com 0; o resumo vai para a saída padrão.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/Settings.php';
require_once $root . '/src/helpers.php';
// Sem isto activity() não existe no cron e os logs 45/46 são pulados em silêncio.
require_once $root . '/src/ActivityLog.php';
require_once $root . '/src/DomainReverification.php';
Db::loadEnv();

$report = (new DomainReverification(new DomainVerification()))->run();
foreach ($report as $key => $count) {
    echo str_pad($key, 16) . $count . "\n";
}
exit(0);

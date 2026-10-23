#!/usr/bin/env php
<?php

declare(strict_types=1);

// Retenção diária de logs. Cron sugerido:
//   15 3 * * *  php /caminho/casalivre/scripts/log-retention.php
// Uso: php scripts/log-retention.php [dias] [--apply]
// - Arquivos locais (api-*, error.log, ...): [dias], padrão 14.
// - PostgreSQL (audit.*): cria as partições que faltam (uma por transação; linhas achadas numa DEFAULT vão para a
//   folha certa) e, com --apply, remove por DROP uma partição diária vencida por vez (security 90 d, other 14 d), via
//   funções DEFINER, como um login com casalivre_log_job (LOG_JOB_DB_USER/LOG_JOB_DB_PASSWORD). Sem --apply: dry-run
//   (só mostra a próxima partição). O primeiro DROP real em produção precisa da aprovação do Rafael.
// - LOG_JOB_DB_USER é obrigatório fora de APP_ENV=dev/local (AppEnv::isDev): o login do app não tem SET ROLE
//   casalivre_log_job, então sem ele nenhuma partição seria criada. Sem ele: saída 2, nada feito.
// - No fim roda audit.log_partitions_health(); qualquer check critical (DEFAULT com linhas, folha mais distante
//   < hoje+3, ...) vai para o STDERR como ALERT e a saída é 1.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/LogRetention.php';
require_once $root . '/src/AppEnv.php';
Db::loadEnv();

if (!AppEnv::isDev() && (string) (getenv('LOG_JOB_DB_USER') ?: '') === '') {
    fwrite(STDERR, "log-retention: LOG_JOB_DB_USER is not set. It is required outside APP_ENV=dev/local: the app login cannot "
        . "SET ROLE casalivre_log_job, so no partition would be created. Set LOG_JOB_DB_USER/LOG_JOB_DB_PASSWORD "
        . "(production: cl_retention_job). Nothing was done.\n");
    exit(2);
}

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$numeric = array_values(array_filter($args, 'ctype_digit'));
$days = $numeric !== [] ? max(1, (int) $numeric[0]) : LogRetention::DEFAULT_DAYS;
$report = LogRetention::run($root, $days);
$rc = 0;
try {
    $pdo = Db::connectAs('LOG_JOB_DB_USER', 'LOG_JOB_DB_PASSWORD');
    $report += LogRetention::pgStep($pdo, LogRetention::heldRefs($pdo), !$apply);
    if (($report['saude_criticos'] ?? 0) > 0) {
        foreach ($report as $target => $value) {
            if (str_starts_with((string) $target, 'saude ') && str_starts_with((string) $value, 'critical')) {
                fwrite(STDERR, "log-retention: ALERT $target: $value\n");
            }
        }
        error_log('CasaLivre ALERT: log retention: ' . $report['saude_criticos'] . ' critical partition health check(s).');
        $rc = 1;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'log-retention: PG step failed (' . $e::class . '): ' . $e->getMessage() . "\n");
    $rc = 1;
}
foreach ($report as $target => $count) {
    echo str_pad((string) $target, 44) . $count . "\n";
    if (str_starts_with((string) $target, 'aviso_')) {
        fwrite(STDERR, "log-retention: WARN $target: $count\n");
    }
}
exit($rc);

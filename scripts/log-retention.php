#!/usr/bin/env php
<?php

declare(strict_types=1);

// Retenção diária de logs. Cron sugerido:
//   15 3 * * *  php /caminho/casalivre/scripts/log-retention.php
// Uso: php scripts/log-retention.php [dias] [--apply]
// - Arquivos locais (api-*, error.log, ...): [dias], padrão 14.
// - PostgreSQL (audit.activity_log): partições criadas e, com --apply, uma partição diária vencida por vez é
//   removida por DROP (security 90 d, other 14 d), via funções DEFINER, como um login com casalivre_log_job
//   (LOG_JOB_DB_USER/LOG_JOB_DB_PASSWORD; vazio = DB_USER). Sem --apply: dry-run (só mostra a próxima partição).
//   O primeiro DROP real em produção precisa da aprovação do Rafael.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/LogRetention.php';
Db::loadEnv();

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$numeric = array_values(array_filter($args, 'ctype_digit'));
$days = $numeric !== [] ? max(1, (int) $numeric[0]) : LogRetention::DEFAULT_DAYS;
$report = LogRetention::run($root, $days);
$rc = 0;
try {
    $user = (string) (getenv('LOG_JOB_DB_USER') ?: '');
    $password = $user !== '' ? getenv('LOG_JOB_DB_PASSWORD') : getenv('DB_PASSWORD');
    $pdo = new PDO(Db::dsn(), $user !== '' ? $user : (getenv('DB_USER') ?: null), ($password === false || $password === '') ? null : $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $report += LogRetention::pgStep($pdo, LogRetention::heldRefs($pdo), !$apply);
} catch (Throwable $e) {
    fwrite(STDERR, 'log-retention: PG step failed (' . $e::class . '): ' . $e->getMessage() . "\n");
    $rc = 1;
}
foreach ($report as $target => $count) {
    echo str_pad((string) $target, 34) . $count . "\n";
}
exit($rc);

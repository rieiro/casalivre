#!/usr/bin/env php
<?php

declare(strict_types=1);

// Recent rows of audit.activity_log (security + other) through the DEFINER function audit.activity_recent, as a
// login with casalivre_ops_logread (SET ROLE; OPS_DB_USER/OPS_DB_PASSWORD, empty = DB_USER). Each call writes an
// ACTIVITY_LOG_READ row to audit.audit_log. Admin codes live in audit.audit_log: read them with the DEFINER
// function audit.audit_recent(code, limit) (same role, same audited-read contract).
// Uso: php scripts/activity-log.php [--codigo=N] [--limite=N]

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ActivityLog.php';

Db::loadEnv();

$code = null;
$limit = 50;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--codigo=')) {
        $code = (int) substr($arg, 9);
    } elseif (str_starts_with($arg, '--limite=')) {
        $limit = (int) substr($arg, 9);
    }
}

echo "Log de atividades (PostgreSQL, audit.activity_log)\n";
foreach (ActivityLog::CATALOG as $id => $label) {
    echo str_pad((string) $id, 3, ' ', STR_PAD_LEFT) . "  $label  [" . ActivityLog::classOf($id) . "]\n";
}
echo str_repeat('-', 72) . "\n";

$pdo = Db::connectAs('OPS_DB_USER', 'OPS_DB_PASSWORD');
$pdo->exec('SET ROLE casalivre_ops_logread');
$st = $pdo->prepare('SELECT * FROM audit.activity_recent(?::smallint, ?)');
$st->bindValue(1, $code, $code === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
$st->bindValue(2, $limit, PDO::PARAM_INT);
$st->execute();
foreach ($st as $row) {
    $actor = $row['actor_kind'] === 'user' ? 'user=' . bin2hex((string) (is_resource($row['actor_ref']) ? stream_get_contents($row['actor_ref']) : $row['actor_ref'])) : (string) $row['actor_kind'];
    $label = ActivityLog::CATALOG[(int) $row['code']] ?? '?';
    echo $row['created_at'] . '  [' . $row['code'] . '] ' . $label . '  ' . $actor . "\n";
    if ($row['payload'] !== '{}') {
        echo '    ' . $row['payload'] . "\n";
    }
    if ($row['before_value'] !== null) {
        echo '    anterior=' . $row['before_value'] . "\n";
    }
    if ($row['after_value'] !== null) {
        echo '    atual=' . $row['after_value'] . "\n";
    }
}

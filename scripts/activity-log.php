#!/usr/bin/env php
<?php

declare(strict_types=1);

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

echo "Log de atividades (" . (getenv('LOG_DRIVER') ?: 'sqlite') . ")\n";
foreach (ActivityLog::CATALOG as $id => $label) {
    echo str_pad((string) $id, 3, ' ', STR_PAD_LEFT) . "  $label\n";
}
echo str_repeat('-', 72) . "\n";

foreach (ActivityLog::store()->recent($limit, $code) as $row) {
    $when = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['created_at']);
    $stamp = $when ? $when->format('d/m/Y H:i:s') : (string) $row['created_at'];
    $actor = $row['actor_id'] !== null && $row['actor_id'] !== '' ? 'user=' . $row['actor_id'] : 'visitante';
    echo $stamp . '  [' . $row['action_code'] . '] ' . $row['action'] . '  ' . $actor . '  ip=' . $row['ip'] . "\n";
    $params = $row['params'];
    if ($params !== []) {
        echo '    ' . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n";
    }
    if (!empty($row['valor_anterior'])) {
        echo '    anterior=' . $row['valor_anterior'] . "\n";
    }
    if (!empty($row['valor_atual'])) {
        echo '    atual=' . $row['valor_atual'] . "\n";
    }
}

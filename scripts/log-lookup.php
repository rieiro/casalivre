#!/usr/bin/env php
<?php

declare(strict_types=1);

// Busca registros de um usuário nos logs pseudonimizados.
// Uso: php scripts/log-lookup.php <user_id> [--root=/caminho/do/app]
// Calcula o HMAC do id com LOG_SALT (mesma função de RequestLog/ActivityLog: LogPrivacy::user/userRef)
// e procura em: audit.activity_log (+ _held) pela função DEFINER audit.activity_by_actor, como um login com
// casalivre_ops_logread (SET ROLE; OPS_DB_USER/OPS_DB_PASSWORD, vazio = DB_USER), e em storage/logs/api/api-*.
// Recusa rodar sem LOG_SALT. Saída: uma linha JSON por registro encontrado.

$root = dirname(__DIR__);
$userId = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--root=')) {
        $root = rtrim(substr($arg, 7), '/');
    } elseif (ctype_digit($arg)) {
        $userId = (int) $arg;
    }
}
if ($userId === null) {
    fwrite(STDERR, "Uso: php scripts/log-lookup.php <user_id> [--root=/caminho]\n");
    exit(64);
}

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/LogPrivacy.php';
Db::loadEnv();
if ((string) (getenv('LOG_SALT') ?: '') === '') {
    fwrite(STDERR, "LOG_SALT não configurado: recusando a busca (os logs não teriam pseudônimo correspondente).\n");
    exit(2);
}
$ref = (string) LogPrivacy::user($userId);
fwrite(STDERR, "Pseudônimo do usuário $userId: $ref\n");
$found = 0;
$emit = static function (string $source, array $row) use (&$found): void {
    $found++;
    echo json_encode(['fonte' => $source] + $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
};

// 1) audit.activity_log (security + other) e audit.activity_log_held. Cada chamada grava ACTIVITY_LOG_READ no audit_log.
$pdo = Db::connectAs('OPS_DB_USER', 'OPS_DB_PASSWORD');
$pdo->exec('SET ROLE casalivre_ops_logread');
$stmt = $pdo->prepare('SELECT id, created_at, code, retention_class, actor_kind, payload, before_value, after_value FROM audit.activity_by_actor(?::bytea[])');
$stmt->execute([Db::byteaArrayLiteral([(string) LogPrivacy::userRef($userId)])]);
foreach ($stmt as $row) {
    $emit('activity_log', $row);
}

// 2) logs JSON da API
$files = glob($root . '/storage/logs/api/api-*') ?: [];
sort($files);
foreach ($files as $file) {
    $fh = @fopen($file, 'rb');
    if ($fh === false) {
        continue;
    }
    while (($line = fgets($fh)) !== false) {
        if (!str_contains($line, $ref)) {
            continue;
        }
        $row = json_decode($line, true);
        if (is_array($row) && ($row['user'] ?? null) === $ref) {
            $emit(basename($file), $row);
        }
    }
    fclose($fh);
}

fwrite(STDERR, "$found registro(s) encontrado(s).\n");
exit(0);

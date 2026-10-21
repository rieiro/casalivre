#!/usr/bin/env php
<?php

declare(strict_types=1);

// Aplica a migração do banco (rodar a cada deploy, antes de liberar o tráfego).
// Uso: php scripts/migrate.php [--force]
//   --force  reaplica os seeds mesmo se schema_meta já estiver em Db::SCHEMA_VERSION.
// PostgreSQL only, no data migration: carregue database/schema.pgsql.sql num banco NOVO antes (psql -f;
// ops/dev-env/db-setup.sh faz isso). Este script só cria as pastas de runtime, roda os seeds e grava a versão;
// um banco numa versão anterior aborta (recrie-o do zero).
// Saída: 0 = banco na versão esperada; 1 = migrou mas a versão ficou abaixo da esperada;
//        2 = falha (pastas, conexão, versão antiga, erro de SQL); a mensagem vai para o STDERR.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';

try {
    Db::ensureStorageDirs();
} catch (Throwable $e) {
    fwrite(STDERR, 'migrate.php: falha ao preparar pastas: ' . $e->getMessage() . "\n");
    exit(2);
}

$force = in_array('--force', $argv, true);
try {
    $version = Db::migrateNow($force);
} catch (Throwable $e) {
    fwrite(STDERR, 'migrate.php: FALHA na migração (' . $e::class . '): ' . $e->getMessage() . "\n"
        . "A transação da migração foi desfeita. Corrija e rode de novo; não libere o tráfego.\n");
    exit(2);
}
echo 'Banco migrado. Versão do schema: ' . $version . ' (esperada: ' . Db::SCHEMA_VERSION . ")\n";
exit($version >= Db::SCHEMA_VERSION ? 0 : 1);

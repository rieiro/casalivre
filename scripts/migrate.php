#!/usr/bin/env php
<?php

declare(strict_types=1);

// Aplica a migração do banco (rodar a cada deploy, antes de liberar o tráfego).
// Uso: php scripts/migrate.php [--force]
//   --force  reaplica migrate() mesmo se PRAGMA user_version já estiver em Db::SCHEMA_VERSION.
// Deploy limpo: cria storage/mail, storage/sessions e public/uploads/users e, se o banco SQLite
// não existir (ou estiver vazio, sem tabelas), carrega database/schema.sql como o scripts/setup.sh faz.
// Postgres novo (vazio): carregue database/schema.pgsql.sql ANTES (psql -f; ver README); este script não cria o schema PG.
// Saída: 0 = banco na versão esperada; 1 = migrou mas a versão ficou abaixo da esperada;
//        2 = falha (pastas, conexão, erro de SQL); a mensagem vai para o STDERR.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';

// Duas conexões PDO: ensureSqliteFromSchema() abre uma própria (e fecha) só para checar/criar o banco
// pelo schema.sql; depois migrateNow() usa a conexão compartilhada de Db::pdo(), já com os PRAGMAs (WAL etc.).
try {
    Db::ensureStorageDirs();
    $created = Db::ensureSqliteFromSchema();
} catch (Throwable $e) {
    fwrite(STDERR, 'migrate.php: falha ao preparar pastas/banco: ' . $e->getMessage() . "\n");
    exit(2);
}
if ($created !== null) {
    echo "Banco criado a partir de database/schema.sql: $created\n";
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

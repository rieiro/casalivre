#!/usr/bin/env php
<?php

declare(strict_types=1);

// Aplica a migração do banco (rodar a cada deploy, antes de liberar o tráfego).
// Uso: php scripts/migrate.php [--force]
//   --force  reaplica migrate() mesmo se PRAGMA user_version já estiver em Db::SCHEMA_VERSION.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';

$force = in_array('--force', $argv, true);
$version = Db::migrateNow($force);
echo 'Banco migrado. Versão do schema: ' . $version . ' (esperada: ' . Db::SCHEMA_VERSION . ")\n";
exit($version >= Db::SCHEMA_VERSION ? 0 : 1);

#!/usr/bin/env php
<?php

declare(strict_types=1);

// Aplica a migração do banco (rodar a cada deploy, antes de liberar o tráfego).
// Uso: php scripts/migrate.php [--force]
//   --force  reaplica migrate() mesmo se PRAGMA user_version já estiver em Db::SCHEMA_VERSION.
// Deploy limpo: cria storage/mail, storage/sessions e public/uploads/users e, se o banco SQLite
// não existir (ou estiver vazio, sem tabelas), carrega database/schema.sql como o scripts/setup.sh faz.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';

Db::loadEnv();
foreach (['storage/db', 'storage/logs', 'storage/mail', 'storage/sessions', 'public/uploads/users'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        fwrite(STDERR, "Não foi possível criar $path\n");
        exit(1);
    }
}

$driver = getenv('DB_DRIVER') ?: 'sqlite';
$dsn = getenv('DB_DSN') ?: '';
if ($dsn === '' && $driver === 'sqlite') {
    $dsn = 'sqlite:' . $root . '/storage/db/casalivre.sqlite';
}
if (str_starts_with($dsn, 'sqlite:')) {
    $file = substr($dsn, 7);
    if ($file !== '' && $file !== ':memory:') {
        $isNew = !is_file($file) || filesize($file) === 0;
        if (!$isNew) {
            $check = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $isNew = (int) $check->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn() === 0;
            $check = null;
        }
        if ($isNew) {
            $schema = file_get_contents($root . '/database/schema.sql');
            if ($schema === false) {
                fwrite(STDERR, "Não foi possível ler database/schema.sql\n");
                exit(1);
            }
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            $boot = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $boot->exec($schema);
            $boot = null;
            echo "Banco criado a partir de database/schema.sql: $file\n";
        }
    }
}

$force = in_array('--force', $argv, true);
$version = Db::migrateNow($force);
echo 'Banco migrado. Versão do schema: ' . $version . ' (esperada: ' . Db::SCHEMA_VERSION . ")\n";
exit($version >= Db::SCHEMA_VERSION ? 0 : 1);

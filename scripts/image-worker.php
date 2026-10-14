#!/usr/bin/env php
<?php

declare(strict_types=1);

// Worker da fila de imagens: processa UM lote (teto de tempo) e sai. Cron (a cada minuto):
//   * * * * *  php /caminho/casalivre/scripts/image-worker.php
// flock em storage/image-worker.lock (0600): se outro worker estiver rodando, sai sem fazer nada.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/ImageWorker.php';
Db::loadEnv();

if (!function_exists('imagewebp') || !(gd_info()['WebP Support'] ?? false)) {
    fwrite(STDERR, "GD sem suporte a WebP: instale/ative a extensão gd com WebP.\n");
    exit(1);
}
@mkdir($root . '/storage', 0700, true);
// Fotos antigas (/uploads/users/...) entram na fila aos poucos (100 por execução).
$legacy = PhotoStorage::migrateLegacy(100);
if ($legacy > 0) {
    echo str_pad('legado', 14) . $legacy . "\n";
}
$report = ImageWorker::run(ImageQueues::make(), $root . '/storage/image-worker.lock');
if ($report === null) {
    echo "outro worker em execução\n";
    exit(0);
}
foreach ($report as $k => $v) {
    echo str_pad($k, 14) . $v . "\n";
}

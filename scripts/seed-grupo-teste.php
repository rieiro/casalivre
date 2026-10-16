#!/usr/bin/env php
<?php

declare(strict_types=1);

// Seed opcional de desenvolvimento: grupo `teste` (tema contraste) com 3 imóveis de seed; regras em
// src/Seed/SeedGrupoTeste.php. Só APP_ENV=dev/local. Uso: APP_ENV=dev php scripts/seed-grupo-teste.php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once dirname(__DIR__) . '/src/Seed/SeedGrupoTeste.php';
$r = SeedGrupoTeste::run();
fwrite($r['ok'] ? STDOUT : STDERR, implode("\n", $r['linhas']) . "\n");
exit($r['ok'] ? 0 : 1);

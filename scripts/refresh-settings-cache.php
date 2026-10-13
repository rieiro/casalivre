#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/Settings.php';
require_once $root . '/src/I18n.php';
require_once $root . '/src/CityRepository.php';
if (!class_exists('JsonCache', false)) {
    require_once $root . '/src/JsonCache.php';
}
require_once $root . '/src/CepRepository.php';

$settings = Settings::refresh();
$translations = I18n::refresh();
$places = CityRepository::refresh();
CepRepository::ensureLoaded();
$ceps = CepRepository::refresh();

echo "Cache atualizado:\n";
echo "  storage/cache/settings.json (" . count($settings) . " parâmetros)\n";
$texts = 0;
foreach ($translations as $pack) {
    $texts += count($pack);
}
echo "  storage/cache/translations.json (" . count($translations) . " idiomas, $texts textos)\n";
echo "  storage/cache/cities.json (" . count($places['cities']) . " cidades, " . count($places['states']) . " estados)\n";
echo "  storage/cache/cep.json ($ceps CEPs)\n";
foreach ($settings as $key => $row) {
    echo $key . '=' . $row['value'] . '  ' . $row['description'] . "\n";
}

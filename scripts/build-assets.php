<?php

declare(strict_types=1);

// Gera os temas (themes/<nome>/brand.php -> public/assets/themes/) e depois public/assets/manifest.json
// (hash do conteúdo de CSS/JS/JSON em public/assets, mais logo/favicon dos temas) para asset_url().
// Uso: php scripts/build-assets.php          grava o manifesto
//      php scripts/build-assets.php --check  só confere (exit 1 se temas ou manifesto estiverem desatualizados)
// Nos dois modos, exit 1 se algum themes/<nome>/brand.php tiver token CSS, cor ou papel do mapa inválido.
require_once dirname(__DIR__) . '/src/AssetManifest.php';
require_once dirname(__DIR__) . '/src/ThemeBuilder.php';

$check = in_array('--check', $argv, true);
// brand.php com entrada inválida (token CSS, cor ou papel do mapa) falha o build nos dois modos, antes de gravar nada.
$erros = ThemeBuilder::errors();
if ($erros !== []) {
    fwrite(STDERR, "brand.php inválido (corrija e rode de novo):\n  " . implode("\n  ", $erros) . "\n");
    exit(1);
}
if ($check) {
    $velhos = ThemeBuilder::outdated();
    if ($velhos !== []) {
        fwrite(STDERR, "temas desatualizados (rode php scripts/build-assets.php):\n  " . implode("\n  ", $velhos) . "\n");
        exit(1);
    }
} else {
    echo 'temas: ' . implode(', ', ThemeBuilder::build()) . "\n";
}
$manifesto = AssetManifest::build();
$novo = AssetManifest::encode($manifesto);
$atual = is_file(AssetManifest::file()) ? (string) file_get_contents(AssetManifest::file()) : '';
if ($check) {
    if ($novo !== $atual) {
        fwrite(STDERR, "manifest.json desatualizado: rode php scripts/build-assets.php\n");
        exit(1);
    }
    echo "manifest.json em dia\n";
    exit(0);
}
if ($novo !== $atual) {
    $tmp = AssetManifest::file() . '.tmp';
    file_put_contents($tmp, $novo);
    rename($tmp, AssetManifest::file());
}
echo 'manifest.json: ' . count($manifesto) . " arquivos\n";

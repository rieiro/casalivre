<?php

declare(strict_types=1);

// Gera public/assets/manifest.json (hash do conteúdo de CSS/JS/JSON em public/assets) para asset_url().
// Uso: php scripts/build-assets.php          grava o manifesto
//      php scripts/build-assets.php --check  só confere (exit 1 se o manifesto estiver desatualizado)
require_once dirname(__DIR__) . '/src/AssetManifest.php';

$novo = AssetManifest::encode(AssetManifest::build());
$atual = is_file(AssetManifest::file()) ? (string) file_get_contents(AssetManifest::file()) : '';
if (in_array('--check', $argv, true)) {
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
echo 'manifest.json: ' . count(json_decode($novo, true)) . " arquivos\n";

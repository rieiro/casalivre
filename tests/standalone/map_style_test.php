<?php
// Etapa 3: estilo do mapa por tema. Tema sem 'mapa' (padrão) usa o estilo base, sem cópia; tema com 'mapa' ganha
// /assets/themes/<nome>/map-style.json com hash no manifesto. Cores só #rgb/#rrggbb e papéis da lista; expressões
// do estilo ficam intactas; nome de tema inválido cai no padrão.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/ThemeBuilder.php';
t_temp_db();
t_seed_owner();
$restore = static function (): void {
    $c = JsonCache::read('settings.json');
    if (is_array($c) && isset($c['theme'])) {
        $c['theme']['value'] = Theme::DEFAULT;
        JsonCache::write('settings.json', $c);
    }
};
register_shutdown_function($restore);
$setTheme = static function (string $v): void {
    db()->prepare('UPDATE settings SET value = ? WHERE skey = ?')->execute([$v, 'theme']);
    Settings::refresh();
};
$baseFile = AssetManifest::publicDir() . MapStyle::BASE;
$base = json_decode((string) file_get_contents($baseFile), true);
$manifest = AssetManifest::load();

echo "validação das cores\n";
$evil = [
    'fundo' => '#abc',
    'terra' => '#A1B2C3',
    'agua' => '"}],"x":"',
    'parque' => 'red',
    'edificio' => '#12345g',
    'via' => 'url(http://x)',
    'limite' => ['#000'],
    'rotulo' => '#0000',
    'inventado' => '#000000',
    0 => '#000000',
    'rotulo_halo' => " #ffffff",
];
t_eq(['fundo' => '#abc', 'terra' => '#a1b2c3'], MapStyle::clean($evil), 'só papéis conhecidos e #rgb/#rrggbb (minúsculo)');
t_eq([], MapStyle::clean('#000'), 'não-array -> vazio');
$gen = MapStyle::generate($base, $evil);
$json = MapStyle::encode($gen);
t_ok(json_decode($json, true) !== null, 'JSON gerado válido');
t_ok(!str_contains($json, '"x"') && !str_contains($json, 'url(') && !str_contains($json, 'red"'), 'valores inválidos não chegam ao JSON');
t_eq(array_keys($base), array_keys($gen), 'mesmas chaves de topo (sprite, glyphs, sources intactos)');
t_eq([$base['sprite'], $base['glyphs'], $base['sources']], [$gen['sprite'], $gen['glyphs'], $gen['sources']], 'sprite/glyphs/sources iguais');

echo "geração\n";
$byId = static fn (array $s): array => array_column($s['layers'], null, 'id');
$g = $byId(MapStyle::generate($base, ['fundo' => '#111111', 'via' => '#222222', 'rotulo' => '#333333']));
$b = $byId($base);
t_eq('#111111', $g['background']['paint']['background-color'], 'fundo -> background');
t_eq('#222222', $g['roads_major']['paint']['line-color'], 'via -> roads_major');
t_eq($b['roads_major_casing_early']['paint']['line-color'], $g['roads_major_casing_early']['paint']['line-color'], 'via não mexe no contorno');
t_eq($b['roads_minor']['paint']['line-color'], $g['roads_minor']['paint']['line-color'], 'expressão (roads_minor) intacta');
t_eq($b['roads_rail']['paint']['line-color'], $g['roads_rail']['paint']['line-color'], 'ferrovia fora de via');
t_eq('#333333', $g['places_locality']['paint']['text-color'], 'rotulo -> places_locality');
t_eq($b['pois']['paint']['text-color'], $g['pois']['paint']['text-color'], 'pois (expressão) intacto');
t_eq($base, MapStyle::generate($base, []), 'sem tokens: estilo igual ao base');

echo "seleção e fallback\n";
t_eq([], Theme::brand(Theme::DEFAULT)['mapa'], 'padrão sem mapa');
t_eq(MapStyle::BASE, Theme::mapStylePath(Theme::DEFAULT), 'padrão usa o estilo base');
t_ok(!is_file(AssetManifest::publicDir() . MapStyle::themePath(Theme::DEFAULT)), 'padrão sem map-style.json gerado');
t_eq(MapStyle::themePath('contraste'), Theme::mapStylePath('contraste'), 'contraste usa o estilo gerado');
foreach (['../padrao', 'inexistente', 'CONTRASTE', ''] as $bad) {
    t_eq(MapStyle::BASE, Theme::mapStylePath($bad), 'nome inválido/ausente cai no base: ' . json_encode($bad));
}
t_eq(MapStyle::BASE, Theme::mapStylePath('contraste', array_diff_key($manifest, [MapStyle::themePath('contraste') => 1])), 'gerado fora do manifesto -> base');
$built = (string) file_get_contents(AssetManifest::publicDir() . MapStyle::themePath('contraste'));
t_eq(MapStyle::encode(MapStyle::generate($base, Theme::brand('contraste')['mapa'])), $built, 'map-style.json do contraste em dia com o brand.php');
t_eq([], ThemeBuilder::outdated(), 'build em dia (--check)');

echo "hash ?v= e página do mapa\n";
t_eq(AssetManifest::hashFile(AssetManifest::publicDir() . MapStyle::themePath('contraste')), $manifest[MapStyle::themePath('contraste')] ?? null, 'manifesto com o hash do map-style.json');
t_eq(MapStyle::themePath('contraste') . '?v=' . $manifest[MapStyle::themePath('contraste')], asset_url(Theme::mapStylePath('contraste')), 'asset_url do estilo do tema com ?v=');
$base_url = t_server();
$style = static function () use ($base_url): string {
    $html = t_http('GET', $base_url . '/busca/mapa?localidade=Florian%C3%B3polis%2C%20SC')['body'];
    return preg_match('/data-style="([^"]+)"/', $html, $m) === 1 ? html_entity_decode($m[1]) : '';
};
$setTheme(Theme::DEFAULT);
t_eq(MapStyle::BASE . '?v=' . $manifest[MapStyle::BASE], $style(), 'página com tema padrão: estilo base com ?v= (igual à develop)');
$setTheme('contraste');
$u = $style();
t_eq(MapStyle::themePath('contraste') . '?v=' . $manifest[MapStyle::themePath('contraste')], $u, 'página com contraste: estilo do tema com ?v=');
$r = t_http('GET', $base_url . $u);
t_eq(200, $r['status'], 'estilo do tema servido (200)');
t_eq('#ffffff', $byId(json_decode($r['body'], true))['background']['paint']['background-color'], 'estilo servido traz a cor do tema');
$setTheme('../contraste');
t_eq(MapStyle::BASE . '?v=' . $manifest[MapStyle::BASE], $style(), 'settings.theme inválido: estilo base');
$setTheme(Theme::DEFAULT);

t_done();

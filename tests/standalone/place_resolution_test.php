<?php

declare(strict_types=1);

// ADR 001 PR3: resolution states and the shared OSM credit.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/I18n.php';
require_once CL_ROOT . '/src/Amenities.php';

t_temp_db();
I18n::refresh();

$filters = [
    'localidade' => 'Xyzabc',
    'checkin' => '',
    'checkout' => '',
    'hospedes' => 0,
    'raio' => 0,
    'preco_min' => 0,
    'preco_max' => 0,
    'score_min' => 0.0,
    'amenidades' => [],
];

$render = static function (string $resolution, array $options, array $filters): string {
    $searched = true;
    $use_api = true;
    $vitrine_base = '';
    ob_start();
    include CL_ROOT . '/views/search.php';
    return (string) ob_get_clean();
};

$poi = ['id' => 12, 'name' => 'Praia de Geribá', 'type' => 'poi', 'uf' => 'RJ', 'parent' => 'Armação dos Búzios'];
$nbhd = ['id' => 9, 'name' => 'Geribá', 'type' => 'neighborhood', 'uf' => 'RJ', 'parent' => 'Armação dos Búzios'];
$city = ['id' => 3, 'name' => 'São Paulo', 'type' => 'city', 'uf' => 'SP'];
$poi['line'] = place_option_text($poi);
$nbhd['line'] = place_option_text($nbhd);
$city['line'] = place_option_text($city);

echo "did_you_mean\n";
$mean = $render('did_you_mean', [$nbhd, $poi], $filters);
t_ok(str_contains($mean, 'Você quis dizer'), 'título Você quis dizer');
t_ok(str_contains($mean, 'data-focus="1"'), 'foco no título');
t_ok(str_contains($mean, 'Geribá · Bairro · Armação dos Búzios, RJ'), 'bairro com o pai');
t_ok(str_contains($mean, 'Praia de Geribá · Ponto de interesse · Armação dos Búzios, RJ'), 'POI com o pai');
t_ok(str_contains($mean, 'local_id=9') && str_contains($mean, 'local_id=12'), 'cada opção leva local_id');
t_ok(preg_match('/<\/template>.*openstreetmap\.org/s', $mean) === 1, 'crédito OSM visível quando há POI');
t_ok(str_contains($mean, 'rel="noopener noreferrer"') && str_contains($mean, 'target="_blank"'), 'link OSM com rel e target');
t_ok(!str_contains($mean, 'Continue digitando para refinar'), 'did_you_mean não pede para continuar digitando');
t_ok(!str_contains($mean, '/assets/pages/search.js'), 'did_you_mean não carrega a listagem');
t_ok(!preg_match('/<script\b(?![^>]*\bsrc=)/i', $mean), 'sem script inline');
t_ok(!preg_match('/\sstyle\s*=/i', $mean), 'sem style inline');
t_ok(str_contains($mean, 'value="Xyzabc"'), 'o texto digitado continua no campo');

echo "ambiguous\n";
$many = [];
for ($i = 1; $i <= 8; $i++) {
    $row = ['id' => $i, 'name' => 'Centro', 'type' => 'neighborhood', 'uf' => 'RJ', 'parent' => 'Cidade ' . $i];
    $row['line'] = place_option_text($row);
    $many[] = $row;
}
$ambiguous = $render('ambiguous', $many, $filters);
t_eq(8, preg_match_all('/local_id=\d+/', $ambiguous), 'ambiguous mostra 8 opções');
t_ok(str_contains($ambiguous, 'Continue digitando para refinar'), 'linha Continue digitando');
t_ok(str_contains($ambiguous, '8 de muitos lugares. Continue digitando para refinar.'), 'anúncio educado');
t_ok(str_contains($ambiguous, 'role="status"') && str_contains($ambiguous, 'aria-live="polite"'), 'live region polite');
t_ok(preg_match('/<\/template>.*openstreetmap\.org/s', $ambiguous) !== 1, 'lista só de bairros sem crédito OSM visível');
t_ok(!preg_match('/<a[^>]*>Continue digitando/', $ambiguous), 'a linha final não é um link');

echo "not_found\n";
$filters['localidade'] = '<script>alert(1)</script>';
$miss = $render('not_found', [], $filters);
t_ok(str_contains($miss, 'Não encontramos'), 'Não encontramos');
t_ok(str_contains($miss, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'o texto digitado é escapado');
t_ok(!str_contains($miss, '<script>alert'), 'o texto não vira script');
t_ok(str_contains($miss, 'href="/busca/mapa?') && str_contains($miss, 'Buscar no mapa'), 'link Buscar no mapa');
t_ok(preg_match('/<\/template>.*openstreetmap\.org/s', $miss) !== 1, 'not_found sem crédito OSM visível');
t_ok(str_contains($miss, 'value="&lt;script&gt;alert(1)&lt;/script&gt;"'), 'o campo continua editável com o texto');

echo "cidade\n";
$onlyCity = $render('did_you_mean', [$city], ['localidade' => 'sao', 'checkin' => '', 'checkout' => '', 'hospedes' => 0, 'raio' => 0, 'preco_min' => 0, 'preco_max' => 0, 'score_min' => 0.0, 'amenidades' => []]);
t_ok(str_contains($onlyCity, 'São Paulo · Cidade · SP'), 'cidade mostra a UF');
t_ok(preg_match('/<\/template>.*openstreetmap\.org/s', $onlyCity) !== 1, 'lista só de cidade sem crédito OSM visível');

echo "mapa e autocomplete\n";
$map = (string) file_get_contents(CL_ROOT . '/views/map.php');
$js = (string) file_get_contents(CL_ROOT . '/public/assets/pages/map.js');
$app = (string) file_get_contents(CL_ROOT . '/public/assets/app.js');
t_ok(str_contains($map, 'osm_attribution()'), 'o mapa usa o helper do crédito');
t_ok(!str_contains($js, 'openstreetmap.org'), 'map.js não copia o markup OSM');
t_ok(str_contains($js, '.osm-attribution') && str_contains($js, 'credit.remove()'), 'o controle do mapa reaproveita o crédito e não o duplica');
t_ok(str_contains($app, 'query.length < 2'), 'autocomplete pede a partir de 2 caracteres');
t_ok(!str_contains($app, 'query.length < 3'), 'o mínimo antigo de 3 saiu');
ob_start();
osm_attribution();
$credit = (string) ob_get_clean();
t_ok(str_contains($credit, '© OpenStreetMap contributors') && str_contains($credit, 'rel="noopener noreferrer"'), 'helper do crédito');

t_done();

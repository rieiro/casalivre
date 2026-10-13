<?php
// fix/xss-views: header CSP, páginas sem script/handler/style inline, str_field 422 (texto como array) e
// rooms/amenity só como array.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
db()->exec("INSERT OR IGNORE INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88000000', 'Rua A', 'Centro', 'Florianópolis', 'SC')");
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csp = "Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'";

echo "header CSP\n";
foreach (['/', '/entrar', '/busca?localidade=Florian%C3%B3polis', '/imoveis/' . $owner['hash'], '/api/busca?localidade=x'] as $path) {
    $r = t_http('GET', $base . $path);
    t_ok(stripos($r['headers'], $csp) !== false, "CSP presente em $path");
}

echo "HTML sem script/handler/style inline\n";
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$pages = ['/', '/entrar', '/cadastro', '/busca?localidade=Florian%C3%B3polis', '/imoveis/' . $owner['hash'], '/painel',
    '/imoveis/novo', '/imoveis/' . $owner['hash'] . '/editar', '/imoveis/' . $owner['hash'] . '/calendario', '/conta'];
foreach ($pages as $path) {
    $r = t_http('GET', $base . $path, null, [], $jar);
    $body = $r['body'];
    preg_match_all('/<script\b([^>]*)>/i', $body, $m);
    $inline = array_filter($m[1], static fn (string $a) => !str_contains($a, 'src=') && !str_contains($a, 'application/json'));
    t_eq(0, count($inline), "$path: nenhum <script> inline (status {$r['status']})");
    t_ok(!preg_match('/\son[a-z]+\s*=/i', $body), "$path: nenhum handler on*=");
    t_ok(!preg_match('/\sstyle\s*=/i', $body), "$path: nenhum atributo style=");
}

echo "str_field: texto como array -> 422\n";
$common = ['title' => 'Casa Teste', 'description' => 'Descrição de teste do imóvel.', 'cep' => '88000-000', 'street' => 'Rua A',
    'city' => 'Florianópolis, SC', 'address_number' => '1', 'address_complement' => '', 'max_guests' => '4',
    'nightly_price' => 'R$ 200,00', 'cleaning_fee' => 'R$ 0,00', 'checkin_time' => '15:00', 'checkout_time' => '11:00',
    'min_nights' => '1', 'calendar_months' => '6',
    'rooms' => [['beds' => [['type' => 'single', 'variant' => 'common']]]], 'amenity' => ['wifi' => '1']];
$put = static fn (array $data) => t_http('PUT', $base . '/api/casas/' . $owner['hash'], json_encode($data), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$r = $put($common);
t_eq(200, $r['status'], 'payload válido -> 200 (' . substr($r['body'], 0, 120) . ')');
foreach (['title', 'description', 'checkin_time', 'cep', 'nightly_price'] as $field) {
    $r = $put([$field => ['x' => 'y']] + $common);
    t_eq(422, $r['status'], "$field como array -> 422");
}
$r = $put(['title' => ['<b>a</b>']] + $common);
t_ok(!str_contains($r['body'], 'Array') && str_contains($r['body'], 'error'), '422 com mensagem de erro, sem "Array"');
foreach (['rooms', 'amenity'] as $field) {
    $r = $put([$field => 'texto'] + $common);
    t_eq(422, $r['status'], "$field como texto -> 422");
}
$r = t_http('POST', $base . '/api/casas', json_encode(['title' => ['a']] + $common), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(422, $r['status'], 'POST /api/casas com title array -> 422');
t_eq('Casa Teste', HouseRepository::find($owner['house_id'])['title'], 'imóvel não alterado pelos 422');

t_done();

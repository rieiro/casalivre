<?php
// Respostas públicas do imóvel sem endereço, property_id nem audit_hold (só cidade e bairro);
// o dono continua vendo o endereço no painel/edição e em /api/casas/{hash}.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
db()->exec("INSERT OR IGNORE INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88000000', 'Rua A', 'Centro', 'Florianópolis', 'SC')");
db()->prepare("INSERT INTO photos (property_id, path, is_primary, sort_order, audit_hold) VALUES (?, '/uploads/a.jpg', 1, 0, 'retido')")->execute([$owner['house_id']]);
$base = t_server();
$hidden = ['address', 'street', 'address_number', 'address_complement', 'cep', 'audit_hold', 'property_id', 'user_id', 'lat', 'lng'];

echo "público: GET /api/imoveis/{hash}\n";
$r = t_http('GET', $base . '/api/imoveis/' . $owner['hash']);
$j = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], '200');
t_eq([], array_values(array_intersect($hidden, array_keys($j))), 'imóvel sem endereço/ids internos');
t_eq(['Florianópolis, SC', 'Centro'], [$j['city'] ?? null, $j['neighborhood'] ?? null], 'cidade e bairro presentes');
t_eq(1, count($j['photos'] ?? []), 'uma foto');
t_eq(['path', 'is_primary', 'sort_order'], array_keys($j['photos'][0] ?? []), 'foto pública só com path, is_primary, sort_order (sem id)');
t_ok(!str_contains($r['body'], 'Rua A') && !str_contains($r['body'], 'retido') && !str_contains($r['body'], 'property_id') && !str_contains($r['body'], 'audit_hold'), 'corpo sem rua, audit_hold nem property_id');

echo "público: página HTML\n";
$r = t_http('GET', $base . '/imoveis/' . $owner['hash']);
t_eq(200, $r['status'], 'página do imóvel 200');
t_ok(!str_contains($r['body'], 'Rua A'), 'página sem a rua');
$js = (string) file_get_contents(CL_ROOT . '/public/assets/pages/property.js');
t_ok(!str_contains($js, 'address') && !str_contains($js, 'street') && str_contains($js, 'data.neighborhood'), 'property.js mostra bairro/cidade, nunca endereço');

echo "busca pública\n";
$r = t_http('GET', $base . '/api/busca?cidade=' . rawurlencode('Florianópolis, SC'));
t_ok(!str_contains($r['body'], 'Rua A') && !str_contains($r['body'], '"address"'), '/api/busca sem endereço');

echo "busca não consulta o endereço (oráculo)\n";
db()->prepare("UPDATE properties SET status = 'active' WHERE id = ?")->execute([$owner['house_id']]);
$count = static fn (string $q): int => count(json_decode(t_http('GET', $base . '/api/busca?localidade=' . rawurlencode($q))['body'], true)['items'] ?? []);
t_eq(0, $count('Rua A, 1'), 'localidade = rua e número: nenhum resultado');
t_eq(0, $count('Rua A'), 'localidade = rua: nenhum resultado');
t_ok($count('Centro') >= 1, 'localidade = bairro: encontra');
t_ok($count('Florianópolis') >= 1, 'localidade = cidade: encontra');

echo "dono\n";
$jar = tempnam(sys_get_temp_dir(), 'jar');
t_login($base, $jar, $owner['email'], $owner['password']);
$r = t_http('GET', $base . '/api/casas/' . $owner['hash'], null, [], $jar);
$j = json_decode($r['body'], true) ?: [];
t_eq('Rua A, 1', $j['casa']['address'] ?? null, 'dono: /api/casas/{hash} traz o endereço');
t_ok(isset($j['casa']['fotos'][0]['property_id']), 'dono: fotos completas');
$r = t_http('GET', $base . '/api/casas', null, [], $jar);
t_ok(str_contains($r['body'], 'Rua A, 1'), 'dono: /api/casas traz o endereço');
$r = t_http('GET', $base . '/imoveis/' . $owner['hash'] . '/editar', null, [], $jar);
t_ok(str_contains($r['body'], 'Rua A'), 'dono: tela de edição mostra o endereço');
t_done();

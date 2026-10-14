<?php
// Limite por IP no upload de fotos pelo formulário web (POST /imoveis/{hash}/fotos): mesmo limitador e
// mesmo balde da API (POST /api/casas/{hash}/fotos), 30 por 10 min somando os dois; 429 com Retry-After.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
$base = t_server();
$reset = static fn () => t_ratelimit_reset();
$form = ['Content-Type: application/x-www-form-urlencoded'];
$web = '/imoveis/' . $owner['hash'] . '/fotos';

echo "formulário web\n";
$reset();
$codes = [];
for ($i = 0; $i < 30; $i++) {
    $codes[] = t_http('POST', $base . $web, '_csrf=x', $form)['status'];
}
t_ok(!in_array(429, $codes, true), '30 envios sem 429');
$r = t_http('POST', $base . $web, '_csrf=x', $form);
t_eq(429, $r['status'], '31º -> 429');
t_ok((bool) preg_match('/^Retry-After:\s*([1-9]\d*)\s*$/mi', $r['headers'], $m) && (int) $m[1] <= 600, 'Retry-After presente (<= 600 s)');
t_ok(str_contains($r['headers'], 'text/html') && str_contains($r['body'], 'Muitas requisições'), '429 em HTML na web');
t_ok(t_http('GET', $base . '/imoveis/' . $owner['hash'])['status'] !== 429, 'GET da página não é limitado');

echo "balde compartilhado com a API\n";
$reset();
for ($i = 0; $i < 20; $i++) {
    t_http('POST', $base . '/api/casas/' . $owner['hash'] . '/fotos', '{}', ['Content-Type: application/json']);
}
$codes = [];
for ($i = 0; $i < 11; $i++) {
    $codes[] = t_http('POST', $base . $web, '_csrf=x', $form)['status'];
}
t_ok(!in_array(429, array_slice($codes, 0, 10), true) && $codes[10] === 429, '20 pela API + 11 pela web: o 31º -> 429 (' . implode(',', $codes) . ')');
$r = t_http('POST', $base . '/api/casas/' . $owner['hash'] . '/fotos', '{}', ['Content-Type: application/json']);
t_ok($r['status'] === 429 && str_contains($r['headers'], 'application/json'), 'API também bloqueada (JSON)');
$reset();
t_done();

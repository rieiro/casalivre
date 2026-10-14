<?php
// Camada CSRF: corpo (não-multipart) acima de Csrf::MAX_BODY_BYTES -> 413 padronizado, antes da validação do token.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/seguranca/Csrf.php';
t_temp_db();
$base = t_server();
$max = Csrf::MAX_BODY_BYTES;
t_eq(1048576, $max, 'limite de 1 MiB');

echo "API (JSON)\n";
$big = json_encode(['email' => 'a@b.c', 'pad' => str_repeat('x', $max)]);
$r = t_http('POST', $base . '/api/sessao', $big, ['Content-Type: application/json']);
t_eq(413, $r['status'], 'JSON acima do limite -> 413');
t_eq(['error' => 'Corpo da requisição grande demais.'], json_decode($r['body'], true), '413 no formato {"error": ...}');
t_ok((bool) preg_match('#^Content-Type:\s*application/json#mi', $r['headers']), '413 com Content-Type JSON');
$r = t_http('POST', $base . '/api/sessao', json_encode(['pad' => str_repeat('x', $max - 100)]), ['Content-Type: application/json']);
t_eq(419, $r['status'], 'abaixo do limite sem token -> 419 (não 413)');
t_eq(['error'], array_keys(json_decode($r['body'], true) ?: []), '419 tem o mesmo formato');

echo "web (form)\n";
$r = t_http('POST', $base . '/entrar', http_build_query(['email' => 'a@b.c', 'pad' => str_repeat('y', $max)]), ['Content-Type: application/x-www-form-urlencoded']);
t_eq(413, $r['status'], 'form acima do limite -> 413');
t_ok(str_contains($r['body'], 'Envio grande demais'), '413 web em HTML');

echo "GET não é afetado\n";
t_eq(200, t_http('GET', $base . '/api/cidades?q=flo')['status'], 'GET normal');
t_done();

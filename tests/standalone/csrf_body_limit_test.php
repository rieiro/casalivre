<?php
// BodyLimit: corpo (não-multipart) acima de BodyLimit::MAX_BYTES -> 413; sem Content-Length ou com
// Transfer-Encoding -> 411; antes do Sanitize/CSRF. Sessão só com cookie ou quando a página pede o token.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/seguranca/BodyLimit.php';
t_temp_db();
$base = t_server();
$max = BodyLimit::MAX_BYTES;
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

echo "411 (unidade)\n";
$case = static function (array $server, string $method): ?int {
    $bak = $_SERVER;
    foreach (['CONTENT_TYPE', 'CONTENT_LENGTH', 'HTTP_CONTENT_LENGTH', 'HTTP_TRANSFER_ENCODING'] as $k) {
        unset($_SERVER[$k]);
    }
    $_SERVER = $server + $_SERVER;
    $r = BodyLimit::status($method);
    $_SERVER = $bak;
    return $r;
};
t_eq(411, $case(['CONTENT_TYPE' => 'application/json', 'HTTP_TRANSFER_ENCODING' => 'chunked'], 'POST'), 'Transfer-Encoding chunked -> 411');
t_eq(411, $case(['CONTENT_TYPE' => 'application/json'], 'POST'), 'POST sem Content-Length -> 411');
t_eq(411, $case(['CONTENT_TYPE' => 'application/json'], 'PUT'), 'PUT sem Content-Length -> 411');
t_eq(null, $case([], 'DELETE'), 'DELETE sem corpo nem Content-Length: aceito');
t_eq(411, $case(['HTTP_TRANSFER_ENCODING' => 'chunked'], 'DELETE'), 'DELETE chunked -> 411');
t_eq(null, $case(['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_TRANSFER_ENCODING' => 'chunked'], 'POST'), 'multipart fica com post_max_size');
t_eq(413, $case(['CONTENT_LENGTH' => (string) ($max + 1)], 'PATCH'), 'PATCH acima -> 413');
t_eq(null, $case(['CONTENT_LENGTH' => '10'], 'POST'), 'POST pequeno: aceito');
t_eq(null, $case(['HTTP_TRANSFER_ENCODING' => 'chunked'], 'GET'), 'GET não é afetado');

echo "411 (HTTP, POST sem Content-Length)\n";
$u = parse_url($base);
$fp = fsockopen($u['host'], (int) $u['port'], $errno, $errstr, 5);
fwrite($fp, "POST /api/sessao HTTP/1.1\r\nHost: {$u['host']}\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n");
stream_set_timeout($fp, 5);
$raw = (string) stream_get_contents($fp);
fclose($fp);
t_ok(str_starts_with($raw, 'HTTP/1.1 411'), 'POST sem Content-Length -> 411 (' . strtok($raw, "\r") . ')');
t_ok(str_contains($raw, '{"error":"Content-Length obrigatório."}'), '411 no formato {"error": ...}');

echo "sessão sob demanda\n";
$r = t_http('GET', $base . '/api/cidades?q=flo');
t_ok(!preg_match('/^Set-Cookie:\s*PHPSESSID/mi', $r['headers']), 'GET anônimo na API: sem cookie de sessão');
$r = t_http('POST', $base . '/api/sessao', json_encode(['pad' => str_repeat('x', $max + 10)]), ['Content-Type: application/json']);
t_ok(!preg_match('/^Set-Cookie:\s*PHPSESSID/mi', $r['headers']), 'recusa 413 sem cookie de sessão');
$jar = tempnam(sys_get_temp_dir(), 'jar');
$r = t_http('GET', $base . '/entrar', null, [], $jar);
t_ok(preg_match('/^Set-Cookie:\s*PHPSESSID/mi', $r['headers']) === 1 && preg_match('/name="_csrf" value="([0-9a-f]{32})"/', $r['body'], $mm) === 1, 'página com formulário abre a sessão e traz o token');
$r = t_http('POST', $base . '/api/sessao', json_encode(['email' => 'ninguem@casalivre.test', 'password' => 'x']), ['Content-Type: application/json', 'X-CSRF-Token: ' . ($mm[1] ?? '')], $jar);
t_ok($r['status'] !== 419, 'token da página é aceito pelo CSRF (' . $r['status'] . ')');
$r = t_http('POST', $base . '/entrar', http_build_query(['_csrf' => $mm[1] ?? '', 'email' => 'ninguem@casalivre.test', 'password' => 'x']), ['Content-Type: application/x-www-form-urlencoded'], $jar);
t_ok($r['status'] !== 419, 'formulário web com o token passa no CSRF (' . $r['status'] . ')');
$r = t_http('GET', $base . '/painel');
t_ok(in_array($r['status'], [302, 303], true), 'rota autenticada sem cookie: redireciona para login');

echo "GET não é afetado\n";
t_eq(200, t_http('GET', $base . '/api/cidades?q=flo')['status'], 'GET normal');
t_done();

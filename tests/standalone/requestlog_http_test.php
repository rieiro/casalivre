<?php
// Testes HTTP do log da API e do 429 (php -S + banco PG temporário).
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$logDir = CL_ROOT . '/storage/logs/api';

echo "respostas dos middlewares são registradas\n";
$r = t_http('POST', $base . '/api/sessao', json_encode(['email' => $owner['email'], 'password' => 'errada-123']), ['Content-Type: application/json'], $jar);
t_eq(419, $r['status'], 'POST sem CSRF -> 419');
$e = log_entry($logDir, $r['headers']);
t_eq(419, $e['status'] ?? null, '419 aparece no log');
t_ok($e !== null && !array_key_exists('password', $e['body']) && !array_key_exists('email', $e['body']), 'senha e e-mail omitidos no log');

$r = t_http('PUT', $base . '/api/users/eu', '{}', ['Content-Type: application/json']);
$e = log_entry($logDir, $r['headers']);
t_ok(in_array($r['status'], [401, 419], true), 'rota privada sem sessão é recusada (' . $r['status'] . ')');
t_eq($r['status'], $e['status'] ?? null, 'recusa aparece no log com o mesmo status');

$r = t_http('GET', $base . '/api/ical/' . $owner['ical_hash'] . '.ics?t=SECRETQ');
$e = log_entry($logDir, $r['headers']);
t_ok($e !== null && !str_contains(json_encode($e), $owner['ical_hash']), 'ical_hash não aparece no log');
t_ok($e !== null && $e['query'] === [] && !str_contains(json_encode($e), 'SECRETQ'), 'query de rota iCal gravada vazia');
$r = t_http('GET', $base . '/api/cidades?q=flo&t=SECRETQ');
$e = log_entry($logDir, $r['headers']);
t_eq(['q' => null, 't' => null], $e['query'] ?? null, 'q e chave fora da allowlist: só o nome');

echo "POST/PUT /api/users sem dados pessoais no log\n";
$pii = ['name' => 'Maria PIISilva', 'email' => 'piimaria@x.com', 'password' => 'Senha-PII-123', 'password_confirm' => 'Senha-PII-123',
    'cpf' => '52998224725', 'phone' => '48999990000', 'cep' => '88015-200', 'street' => 'Rua PIIBocaiuva', 'address_number' => '4321',
    'address_complement' => 'Apto PII501', 'birth_date' => '1990-05-17'];
foreach ([['POST', '/api/users'], ['PUT', '/api/users/eu']] as [$m, $u]) {
    $r = t_http($m, $base . $u, json_encode($pii), ['Content-Type: application/json']);
    $e = log_entry($logDir, $r['headers']);
    $j = json_encode($e);
    t_ok($e !== null, "$m $u registrado no log");
    foreach (['PIISilva', 'piimaria', 'Senha-PII', '52998224725', '48999990000', '88015', 'PIIBocaiuva', '4321', 'PII501', '1990-05-17'] as $v) {
        t_ok($e !== null && !str_contains($j, $v), "$m $u: '$v' não aparece no log");
    }
}

echo "429 encerra antes do dispatch\n";
db()->prepare('INSERT INTO login_rate_limit (ip, failures, locked_until) VALUES (?, ?, ?)')
    ->execute(['127.0.0.1', 99, date('Y-m-d H:i:s', time() + 600)]);
$csrf = '';
$page = t_http('GET', $base . '/entrar', null, [], $jar);
preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
$csrf = $m[1] ?? '';
$r = t_http('POST', $base . '/api/sessao', json_encode(['email' => $owner['email'], 'password' => $owner['password']]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(429, $r['status'], 'login correto com IP bloqueado -> 429');
t_ok((bool) preg_match('/^Retry-After:\s*\d+/mi', $r['headers']), 'header Retry-After presente');
t_eq(1, substr_count($r['body'], '{"error"'), 'apenas um corpo JSON (dispatch não rodou)');
$me = t_http('GET', $base . '/api/users/eu', null, [], $jar);
t_eq(401, $me['status'], 'sessão NÃO foi aberta (dispatch não rodou)');
$e = log_entry($logDir, $r['headers']);
t_eq(429, $e['status'] ?? null, '429 aparece no log');
$r = t_http('POST', $base . '/entrar', http_build_query(['_csrf' => $csrf, 'email' => $owner['email'], 'password' => $owner['password']]), ['Content-Type: application/x-www-form-urlencoded'], $jar);
t_eq(429, $r['status'], 'login web com IP bloqueado -> 429');
t_ok(!preg_match('/^Location:/mi', $r['headers']), 'login web não redireciona para /painel');

@unlink($jar);
t_done();

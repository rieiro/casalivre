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

echo "local_id só entra no log como inteiro positivo\n";
require_once CL_ROOT . '/src/RequestLog.php';
t_eq(['local_id' => 42], RequestLog::queryForLog('/api/busca', ['local_id' => '42']), 'local_id válido vira inteiro');
t_eq(['local_id' => 42], RequestLog::queryForLog('/api/busca', ['local_id' => 42]), 'local_id int permanece');
t_eq(['local_id' => 2147483647], RequestLog::queryForLog('/api/busca', ['local_id' => '2147483647']), 'int4 máximo');
foreach (['//evil.com', "%0d%0a", "\r\nSet-Cookie: x=1", '\\evil', '0', '-1', '01', '2147483648', '12abc', '1e2', ''] as $bad) {
    $logged = RequestLog::queryForLog('/api/busca', ['local_id' => $bad, 'hospedes' => '2']);
    t_eq(['hospedes' => '2'], $logged, 'local_id inválido omitido: ' . json_encode($bad));
    if ($bad !== '') {
        t_ok(!str_contains((string) json_encode($logged), $bad), 'string crua fora do log: ' . json_encode($bad));
    }
}
t_eq(['hospedes' => '2'], RequestLog::queryForLog('/api/busca', ['local_id' => ['//evil.com'], 'hospedes' => '2']), 'local_id array é omitido');
t_eq(['local_id' => null], RequestLog::queryForLog('/api/ceps', ['local_id' => '//evil.com']), '/api/ceps continua só com o nome da chave');

$placeId = (int) db()->query("SELECT id FROM places WHERE type = 'city' AND name_fold = 'rio de janeiro' AND uf = 'RJ'")->fetchColumn();
t_ok($placeId > 0, 'Rio de Janeiro existe');
$r = t_http('GET', $base . '/api/busca?local_id=' . $placeId);
$e = log_entry($logDir, $r['headers']);
t_eq($placeId, $e['query']['local_id'] ?? null, 'HTTP: local_id válido gravado como inteiro');
$evil = '//evil.com';
$r = t_http('GET', $base . '/api/busca?local_id=' . rawurlencode($evil) . '&hospedes=2');
$e = log_entry($logDir, $r['headers']);
t_ok(is_array($e) && is_array($e['query'] ?? null) && !array_key_exists('local_id', $e['query']), 'HTTP: local_id inválido omitido');
t_eq('2', $e['query']['hospedes'] ?? null, 'HTTP: hospedes permanece');
t_ok(is_array($e) && !str_contains((string) json_encode($e), 'evil.com'), 'HTTP: a string crua não entra no log');

@unlink($jar);
t_done();

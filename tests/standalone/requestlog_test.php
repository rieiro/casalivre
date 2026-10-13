<?php
// Testes unitários do RequestLog: omissão, máscara de texto livre, URLs, hashes, IP, user, rotação e permissões.
require __DIR__ . '/lib.php';
require CL_ROOT . '/src/RequestLog.php';

echo "omissão de chaves sensíveis\n";
$in = ['email' => 'a@b.com', 'password' => 'x', 'password_confirm' => 'x', 'senha' => 'x', '_csrf' => 't',
    'token' => 't', 'cpf' => '52998224725', 'phone' => '48999999999', 'telefone' => '1', 'card_number' => '4111',
    'cvv' => '123', 'ics_body' => 'BEGIN', 'title' => 'Casa', 'n' => 3, 'nested' => ['api_key' => 'k', 'ok' => true]];
$out = RequestLog::mask($in);
foreach (['email', 'password', 'password_confirm', 'senha', '_csrf', 'token', 'cpf', 'phone', 'telefone', 'card_number', 'cvv', 'ics_body'] as $k) {
    t_ok(!array_key_exists($k, $out), "omite '$k'");
}
t_eq('Casa', $out['title'] ?? null, 'mantém campo comum');
t_eq(3, $out['n'] ?? null, 'mantém inteiro');
t_eq(['ok' => true], $out['nested'] ?? null, 'omite chave sensível aninhada');
$json = json_encode($out);
foreach (['a@b.com', '52998224725', '48999999999', '4111', 'BEGIN'] as $secret) {
    t_ok(!str_contains($json, $secret), "valor '$secret' não aparece");
}

echo "máscara de texto livre\n";
$txt = RequestLog::mask(['comment' => 'cpf 529.982.247-25 e 52998224725, cartão 4111 1111 1111 1111, mail joao@x.com'])['comment'];
t_ok(!preg_match('/\d{11}|\d{3}\.\d{3}/', $txt), 'CPF mascarado em texto livre');
t_ok(!str_contains($txt, '4111 1111'), 'cartão mascarado em texto livre');
t_ok(str_contains($txt, 'j***@x.com') && !str_contains($txt, 'joao@'), 'e-mail mascarado em texto livre');

echo "URLs e hashes\n";
$out = RequestLog::mask(['external_ical_url' => 'https://u:p@www.airbnb.com/calendar/ical/123.ics?s=SEGREDO#x',
    'url' => 'https://casalivre.test/ical/' . str_repeat('ab', 16) . '?t=1']);
t_eq('https://www.airbnb.com/calendar/ical/123.ics', $out['external_ical_url'], 'external_ical_url sem credenciais/query/fragmento');
t_eq('https://casalivre.test/ical/{hash}', $out['url'], 'url com ical_hash redigido');
t_eq('/api/ical/{hash}.ics', RequestLog::redactPath('/api/ical/' . str_repeat('0f', 16) . '.ics'), 'path com 32 hex vira {hash}');
t_eq('/api/imoveis/0123456789abcdef/calendario', RequestLog::redactPath('/api/imoveis/0123456789abcdef/calendario'), 'hash público de 16 hex mantido');

echo "IP e usuário\n";
t_eq('203.0.113.0', RequestLog::maskIp('203.0.113.77'), 'IPv4 truncado em /24');
t_eq('2001:db8:abcd::', RequestLog::maskIp('2001:db8:abcd:12:34::1'), 'IPv6 truncado em /48');
t_eq('', RequestLog::maskIp('lixo'), 'IP inválido vira vazio');
putenv('LOG_SALT=');
putenv('APP_KEY=');
t_eq(null, RequestLog::userRef(42), 'sem sal: usuário não é gravado');
putenv('LOG_SALT=sal-de-teste');
$ref = RequestLog::userRef(42);
t_ok(is_string($ref) && strlen($ref) === 16 && $ref !== '42', 'com sal: HMAC truncado');
t_eq($ref, RequestLog::userRef(42), 'pseudônimo estável');
t_ok($ref !== RequestLog::userRef(43), 'pseudônimos distintos por usuário');

echo "escrita, permissões e rotação\n";
$dir = sys_get_temp_dir() . '/rl-test-' . bin2hex(random_bytes(3));
RequestLog::$dir = $dir . '/api';
RequestLog::$maxBytes = 300;
for ($i = 0; $i < 5; $i++) {
    RequestLog::write(['i' => $i, 'pad' => str_repeat('x', 100)]);
}
$files = glob($dir . '/api/api-*') ?: [];
t_ok(count($files) >= 2, 'rotação por tamanho gera mais de um arquivo (' . count($files) . ')');
t_eq('700', substr(sprintf('%o', fileperms($dir . '/api')), -3), 'diretório 0700');
$current = $dir . '/api/api-' . date('Y-m-d') . '.log';
t_eq('600', substr(sprintf('%o', fileperms($current)), -3), 'arquivo 0600');
$lines = 0;
foreach ($files as $f) {
    foreach (file($f) as $line) {
        $lines++;
        t_ok(is_array(json_decode($line, true)), 'linha JSON válida');
    }
}
t_eq(5, $lines, 'nenhuma linha perdida na rotação');
array_map('unlink', glob($dir . '/api/*') ?: []);
@rmdir($dir . '/api');
@rmdir($dir);

t_done();

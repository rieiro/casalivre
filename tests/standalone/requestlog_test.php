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
putenv('LOG_SALT=sal-de-teste');
$out = RequestLog::mask(['external_ical_url' => 'https://u:p@www.airbnb.com/calendar/ical/123.ics?s=SEGREDO#x',
    'url' => 'https://casalivre.test/ical/' . str_repeat('ab', 16) . '?t=1']);
t_ok((bool) preg_match('/^www\.airbnb\.com#[0-9a-f]{16}$/', (string) $out['external_ical_url']), 'external_ical_url vira host#hmac');
t_ok((bool) preg_match('/^casalivre\.test#[0-9a-f]{16}$/', (string) $out['url']), 'url vira host#hmac (sem caminho/hash)');
$txt = RequestLog::mask(['comment' => 'veja https://x.com/a?token=SEGREDO e http://y.org/b'])['comment'];
t_ok(!str_contains($txt, 'x.com') && !str_contains($txt, 'SEGREDO') && !str_contains($txt, 'y.org'), 'URLs removidas de texto livre');
t_eq('/api/ical/{hash}.ics', RequestLog::redactPath('/api/ical/' . str_repeat('0f', 16) . '.ics'), 'path com 32 hex vira {hash}');
t_eq('/api/imoveis/0123456789abcdef/calendario', RequestLog::redactPath('/api/imoveis/0123456789abcdef/calendario'), 'hash público de 16 hex mantido');

echo "IP e usuário\n";
t_eq('203.0.113.0', RequestLog::maskIp('203.0.113.77'), 'IPv4 truncado em /24');
t_eq('2001:db8:abcd::', RequestLog::maskIp('2001:db8:abcd:12:34::1'), 'IPv6 truncado em /48');
t_eq('', RequestLog::maskIp('lixo'), 'IP inválido vira vazio');
putenv('LOG_SALT=');
putenv('APP_KEY=chave-que-nao-deve-ser-usada');
t_eq(null, RequestLog::userRef(42), 'sem LOG_SALT (mesmo com APP_KEY): usuário omitido');
t_eq(null, LogPrivacy::userAgent('Mozilla/5.0'), 'sem LOG_SALT: user agent omitido');
t_eq('x.com#-', LogPrivacy::url('https://x.com/a?t=1'), 'sem LOG_SALT: URL vira host#-');
putenv('APP_KEY=');
putenv('LOG_SALT=sal-de-teste');
$ua = LogPrivacy::userAgent('Mozilla/5.0');
t_ok(is_string($ua) && strlen($ua) === 16 && !str_contains($ua, 'Mozilla'), 'user agent vira HMAC');
t_ok(LogPrivacy::email('A@B.com') === LogPrivacy::email('a@b.com ') && strlen((string) LogPrivacy::email('a@b.com')) === 16, 'e-mail vira HMAC normalizado');
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
echo "sinalização de falha de escrita\n";
$before = RequestLog::$writeFailures;
RequestLog::$dir = '/proc/nao-gravavel/api';
$prevLog = ini_set('error_log', '/dev/null');
RequestLog::write(['x' => 1]);
ini_set('error_log', (string) $prevLog);
t_eq($before + 1, RequestLog::$writeFailures, 'falha de escrita incrementa contador');
array_map('unlink', glob($dir . '/api/*') ?: []);
@rmdir($dir . '/api');
@rmdir($dir);

echo "query string\n";
t_eq([], RequestLog::queryForLog('/api/ical/' . str_repeat('ab', 16) . '.ics', ['t' => 'SECRET']), '/api/ical: query vazia');
t_eq([], RequestLog::queryForLog('/ical/' . str_repeat('ab', 16), ['t' => 'SECRET']), '/ical: query vazia');
t_eq([], RequestLog::queryForLog('/api/qualquer.ics', ['t' => 'SECRET']), '*.ics: query vazia');
$q = RequestLog::queryForLog('/api/busca', ['localidade' => 'Floripa', 'hospedes' => '2', 't' => 'SECRET', 'token' => 'X']);
t_eq(['localidade' => 'Floripa', 'hospedes' => '2', 't' => null, 'token' => null], $q, 'allowlist: valores só de chaves permitidas; demais só o nome');
t_ok(!str_contains((string) json_encode($q), 'SECRET'), 'valor fora da allowlist não aparece');
t_eq(['q' => null], RequestLog::queryForLog('/api/ceps', ['q' => '88000000']), '/api/ceps: só nome da chave');
t_eq(['q' => 'flo'], RequestLog::queryForLog('/api/cidades', ['q' => 'flo']), '/api/cidades: q permitido');
$q = RequestLog::queryForLog('/api/busca', ['localidade' => 'meu cpf 52998224725']);
t_ok(!str_contains((string) json_encode($q), '52998224725'), 'valor permitido ainda passa pela máscara');

echo "ActivityLog\n";
require_once CL_ROOT . '/src/ActivityLog.php';
$p = ActivityLog::safeParams(['email' => 'joao@x.com', 'ip' => '203.0.113.9', 'cpf' => '52998224725', 'password' => 'x', 'imovel_id' => 3]);
t_eq(LogPrivacy::email('joao@x.com'), $p['email'], 'activity: e-mail vira HMAC');
t_eq('203.0.113.0', $p['ip'], 'activity: ip truncado');
t_ok($p['cpf'] !== '52998224725' && strlen((string) $p['cpf']) === 16, 'activity: cpf vira HMAC');
t_ok(!isset($p['password']) && $p['imovel_id'] === 3, 'activity: senha omitida, demais mantidos');

t_done();

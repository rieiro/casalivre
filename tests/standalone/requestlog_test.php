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

echo "corpo: allowlist (LGPD)\n";
$pii = ['name' => 'Maria Silva', 'cep' => '88015-200', 'street' => 'Rua Bocaiuva', 'address_number' => '1234',
    'address_complement' => 'Apto 501', 'birth_date' => '1990-05-17', 'email' => 'maria@x.com', 'cpf' => '52998224725',
    'description' => 'Ligue 48999990000', 'title' => 'Casa', 'nested' => ['street' => 'Rua X'],
    'price' => 250, 'min_nights' => 2, 'locale' => 'pt_BR'];
$out = RequestLog::bodyForLog($pii);
foreach (['name', 'cep', 'street', 'address_number', 'address_complement', 'birth_date', 'email', 'cpf'] as $k) {
    t_ok(!array_key_exists($k, $out), "corpo omite '$k'");
}
t_ok(array_key_exists('title', $out) && $out['title'] === null, 'chave fora da allowlist: só o nome');
t_ok(array_key_exists('nested', $out) && $out['nested'] === null, 'estrutura fora da allowlist não é gravada');
t_eq(250, $out['price'] ?? null, 'valor da allowlist mantido (price)');
t_eq('pt_BR', $out['locale'] ?? null, 'valor da allowlist mantido (locale)');
$json = json_encode($out);
foreach (['Maria', '88015', 'Bocaiuva', '1234', 'Apto', '1990-05-17', 'maria@', '529982', '4899999', 'Rua X'] as $secret) {
    t_ok(!str_contains($json, $secret), "corpo: '$secret' não aparece");
}


echo "ActivityLog: allowlist por ação\n";
putenv('LOG_SALT=sal-de-teste');
$p = ActivityLog::safeParams(['email' => 'joao@x.com', 'imovel_id' => 3, 'nome' => 'João Silva', 'cep' => '88015200', 'street' => 'Rua A', 'descricao' => 'texto'], ActivityLog::LOGIN_OK);
t_eq(['email', 'imovel_id'], array_keys($p), 'LOGIN_OK: só email (HMAC) e comuns; resto omitido');
t_eq(LogPrivacy::email('joao@x.com'), $p['email'], 'LOGIN_OK: email pseudonimizado');
$p = ActivityLog::safeParams(['autor' => 'Maria Souza', 'nota' => 5, 'comentario' => 'ligue 48999990000'], ActivityLog::REVIEW);
t_ok(isset($p['autor']) && $p['autor'] !== 'Maria Souza' && $p['nota'] === 5 && !isset($p['comentario']), 'REVIEW: autor HMAC, nota mantida, comentário omitido');
$p = ActivityLog::safeParams(['url' => 'https://www.airbnb.com/calendar/ical/1.ics?s=SEGREDO', 'novas' => 2, 'token' => 't'], ActivityLog::CALENDAR_SYNC);
t_ok(!str_contains((string) json_encode($p), 'SEGREDO') && $p['novas'] === 2 && !isset($p['token']), 'CALENDAR_SYNC: URL pseudonimizada, chave fora da lista omitida');
$p = ActivityLog::safeParams(['amenidades' => ['pool', 'bbq'], 'localidade' => 'Floripa', 'q' => 'x'], ActivityLog::SEARCH);
t_eq(['amenidades' => ['pool', 'bbq'], 'localidade' => 'Floripa'], $p, 'SEARCH: lista permitida mantida, q omitido');

echo "ActivityLog: valor_anterior/atual por allowlist\n";
$j = (string) ActivityLog::encodePrevious(['title' => 'Casa', 'description' => 'Fale comigo 48999990000', 'address' => 'Rua A, 1', 'cep' => '88000000',
    'street' => 'Rua A', 'lat' => -27.5, 'lng' => -48.5, 'nightly_price' => 200, 'ical_hash' => str_repeat('a', 32), 'user_id' => 7,
    'external_ical_url' => 'https://x.com/a.ics?s=SEG', 'fotos' => [['id' => 1, 'path' => 'p.jpg', 'audit_hold' => 'h']]]);
$v = json_decode($j, true);
foreach (['description', 'address', 'cep', 'street', 'lat', 'lng', 'ical_hash'] as $k) {
    t_ok(!array_key_exists($k, $v), "valor: omite '$k'");
}
t_ok($v['title'] === 'Casa' && $v['nightly_price'] === 200, 'valor: campos do imóvel mantidos');
t_ok($v['user_id'] !== 7 && !str_contains($j, 'SEG'), 'valor: user_id HMAC e URL sem token');
t_eq([['id' => 1, 'path' => 'p.jpg']], $v['fotos'], 'valor: lista de fotos filtrada (sem audit_hold)');

echo "ActivityLog: conta grava só nomes de campos alterados\n";
t_eq(['name', 'phone', 'senha'], ActivityLog::changedFieldNames(
    ['name' => 'A', 'phone' => '1', 'email' => 'a@x.com', 'password_hash' => 'h1'],
    ['name' => 'B', 'phone' => '2', 'email' => 'a@x.com', 'password_hash' => 'h2']), 'changedFieldNames');
require_once CL_ROOT . '/src/Db.php';
$dir = sys_get_temp_dir() . '/cl-act-' . bin2hex(random_bytes(3));
mkdir($dir, 0700);
putenv('ACTIVITY_DB_PATH=' . $dir . '/a.sqlite');
putenv('LOG_DRIVER=sqlite');
ActivityLog::record(ActivityLog::ACCOUNT_UPDATE, ['email' => 'joao@x.com', 'nome' => 'João'],
    ['name' => 'João Silva', 'cpf' => '52998224725', 'street' => 'Rua Velha'], ['name' => 'João S.', 'cpf' => '52998224725', 'street' => 'Rua Nova']);
ActivityLog::record(ActivityLog::ACCOUNT_DELETE, ['email' => 'joao@x.com', 'ip' => '203.0.113.9'],
    ['status' => 'active', 'email' => 'joao@x.com'], ['status' => 'deleted', 'email' => 'apagado-1@x']);
$rows = (new PDO('sqlite:' . $dir . '/a.sqlite'))->query('SELECT action_code, params, valor_anterior, valor_atual FROM activity_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$all = json_encode($rows, JSON_UNESCAPED_UNICODE);
t_eq(2, count($rows), 'duas linhas de conta gravadas');
t_eq(null, $rows[0]['valor_anterior'], 'ACCOUNT_UPDATE: sem valor_anterior');
t_eq(['campos_alterados' => ['name', 'street']], json_decode((string) $rows[0]['valor_atual'], true), 'ACCOUNT_UPDATE: só nomes alterados');
t_eq(['campos_alterados' => ['email', 'status']], json_decode((string) $rows[1]['valor_atual'], true), 'ACCOUNT_DELETE: só nomes alterados');
foreach (['João', 'Silva', 'Rua Velha', 'Rua Nova', '52998224725', 'joao@x.com', 'apagado-1', '203.0.113.9'] as $secret) {
    t_ok(!str_contains($all, $secret), "conta: '$secret' não aparece");
}
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
putenv('ACTIVITY_DB_PATH');

t_done();

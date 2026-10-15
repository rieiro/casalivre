<?php
// Divergência 7 (Alta): domínio verificado de grupo é só vitrine. Conta, login, cadastro, senha, painel, edição de
// imóvel, /grupos*, /admin* e sair -> 302 para o mesmo caminho no APP_URL (GET) ou 404 (POST); API de conta/casas e
// qualquer POST da API -> 404. Vitrine pública igual. Plataforma, Host desconhecido e domínio não verificado: nada muda.
// Nenhuma rota recusada abre sessão (sem Set-Cookie).
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
require_once CL_ROOT . '/src/GrupoResolvedor.php';
require_once CL_ROOT . '/src/Mailer.php';
require_once CL_ROOT . '/seguranca/SomenteVitrine.php';
t_temp_db();
$owner = t_seed_owner();
$adm = ['id' => 1, 'is_admin' => 1, 'status' => 'active'];
GrupoAdmin::create($adm, 'imob', 'Imob', 'padrao');
$g = GroupRepository::findBySlug('imob');
db()->prepare('UPDATE properties SET group_id = ? WHERE id = ?')->execute([$g['id'], $owner['house_id']]);
$ins = db()->prepare('INSERT INTO group_domains (group_id, domain, verify_token, verified_at) VALUES (?, ?, ?, ?)');
$ins->execute([$g['id'], 'imob.example.com', 'tok1', '2026-10-01 00:00:00']);
$ins->execute([$g['id'], 'pendente.example.com', 'tok2', null]);
$h = $owner['hash'];
$base = t_server();

$req = static function (string $method, string $path, string $host, array $extra = [], ?string $body = null) use ($base): array {
    $headers = array_merge(['Host: ' . $host], $extra);
    if ($body === null && !in_array($method, ['GET', 'HEAD', 'DELETE'], true)) {
        $body = '';
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    return t_http($method, $base . $path, $body, $headers);
};
$loc = static fn (array $r): ?string => preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) === 1 ? $m[1] : null;
$cookie = static fn (array $r): bool => preg_match('/^Set-Cookie:/mi', $r['headers']) === 1;
$dom = 'imob.example.com';

echo "web GET de conta no domínio do grupo -> 302 para o APP_URL\n";
$webGet = ['/entrar', '/cadastro', '/senha/esqueci', '/senha/redefinir?token=abc123', '/verificar?token=abc', '/conta',
    '/painel', '/imoveis/novo', "/imoveis/$h/editar", "/imoveis/$h/calendario", '/grupos', '/grupos/imob', '/admin/tema',
    '/admin/grupos', '/admin/grupos/imob', '/sair', '/entrar?volta=%2Fpainel'];
foreach ($webGet as $p) {
    foreach ([$dom, 'IMOB.example.com:443', 'imob.example.com.'] as $host) {
        $r = $req('GET', $p, $host);
        t_eq([302, $base . $p], [$r['status'], $loc($r)], "GET $p ($host): 302 para APP_URL no mesmo caminho");
        t_ok(!$cookie($r), "GET $p ($host): sem Set-Cookie");
    }
}
$r = $req('HEAD', '/entrar', $dom);
t_eq([302, $base . '/entrar'], [$r['status'], $loc($r)], 'HEAD /entrar: 302');
$longa = '/entrar?x=' . str_repeat('a', SomenteVitrine::MAX_QUERY + 10);
$r = $req('GET', $longa, $dom);
t_eq([302, $base . '/entrar'], [$r['status'], $loc($r)], 'query longa demais é descartada (caminho mantido)');
$r = $req('GET', '//evil.example/entrar', $dom);
t_ok(str_starts_with((string) $loc($r), $base . '/'), 'caminho //evil não vira outro host');

echo "web POST de conta no domínio do grupo -> 404\n";
$webPost = ['/entrar', '/cadastro', '/senha/esqueci', '/senha/redefinir', '/conta', '/conta/excluir', '/sair', '/imoveis',
    "/imoveis/$h", "/imoveis/$h/excluir", "/imoveis/$h/fotos", "/imoveis/$h/fotos/capa", "/imoveis/$h/calendario",
    "/imoveis/$h/ical/importar", "/imoveis/$h/grupo/aceitar", "/imoveis/$h/grupo/sair", '/grupos/imob/convites',
    '/admin/tema', '/admin/grupos', '/admin/grupos/imob', '/admin/grupos/imob/dominios', '/admin/grupos/imob/membros'];
foreach ($webPost as $p) {
    $r = $req('POST', $p, $dom, [], 'email=a%40b.test&password=x');
    t_eq(404, $r['status'], "POST $p: 404");
    t_ok(!$cookie($r) && $loc($r) === null, "POST $p: sem Set-Cookie nem Location");
}

echo "API de conta/casas e qualquer POST da API -> 404\n";
$json = ['Content-Type: application/json'];
$api = [['POST', '/api/sessao'], ['DELETE', '/api/sessao'], ['POST', '/api/users'], ['GET', '/api/users/eu'],
    ['PUT', '/api/users/eu'], ['DELETE', '/api/users/eu'], ['POST', '/api/auth/verificar'],
    ['POST', '/api/auth/senha/esqueci'], ['POST', '/api/auth/senha/redefinir'], ['GET', '/api/casas'],
    ['POST', '/api/casas'], ['GET', "/api/casas/$h"], ['PUT', "/api/casas/$h"], ['POST', "/api/casas/$h/fotos"],
    ['GET', '/api/painel'], ['POST', "/api/imoveis/$h/avaliar"], ['POST', '/api/idioma'],
    ['GET', "/api/imoveis/$h/calendario"], ['GET', "/api/imoveis/$h/editar"], ['GET', '/api/nao-existe']];
foreach ($api as [$m, $p]) {
    $r = $req($m, $p, $dom, $json, in_array($m, ['POST', 'PUT'], true) ? '{"email":"a@b.test","password":"x"}' : null);
    t_eq([404, 'Rota não encontrada.'], [$r['status'], json_decode($r['body'], true)['error'] ?? null], "$m $p: 404 JSON");
    t_ok(!$cookie($r), "$m $p: sem Set-Cookie");
}

echo "sessão válida da plataforma enviada ao domínio do grupo não abre nada\n";
$jar = tempnam(sys_get_temp_dir(), 'jar');
t_login($base, $jar, $owner['email'], $owner['password']);
preg_match('/\t(\S+)\t(\S+)$/m', (string) file_get_contents($jar), $cm);
$ck = ['Cookie: ' . ($cm[1] ?? '') . '=' . ($cm[2] ?? '')];
t_eq(200, t_http('GET', $base . '/api/users/eu', null, [], $jar)['status'], 'plataforma: /api/users/eu 200 com a sessão');
t_eq(404, $req('GET', '/api/users/eu', $dom, $ck)['status'], 'domínio do grupo: /api/users/eu 404 mesmo com cookie');
t_eq(302, $req('GET', '/painel', $dom, $ck)['status'], 'domínio do grupo: /painel 302 mesmo com cookie');
@unlink($jar);

echo "vitrine pública no domínio do grupo continua igual\n";
foreach (['/', '/busca', '/busca/mapa', "/imoveis/$h", '/g/imob/', '/g/imob/busca', "/g/imob/imoveis/$h",
    '/api/busca', '/api/cidades?q=flo', "/api/imoveis/$h", '/ical/' . $owner['ical_hash']] as $p) {
    $r = $req('GET', $p, $dom);
    t_eq(200, $r['status'], "GET $p: 200 no domínio do grupo");
}
t_eq(302, $req('GET', '/g/imob', $dom)['status'], '/g/imob sem barra: 302 da vitrine');
t_ok(!str_starts_with((string) $loc($req('GET', '/g/imob', $dom)), 'http'), '/g/imob: redirect relativo (não é a trava)');
t_eq(419, $req('POST', '/idioma', $dom)['status'], 'POST /idioma passa pela trava (419 sem CSRF)');
t_eq(419, $req('POST', "/imoveis/$h/avaliar", $dom)['status'], 'POST /imoveis/<h>/avaliar passa pela trava (419 sem CSRF)');

echo "sessão anônima da vitrine: cookie só do host, nunca user_id; avaliar com CSRF válido -> 302\n";
$vjar = tempnam(sys_get_temp_dir(), 'vjar');
$r = t_http('GET', $base . '/', null, ['Host: ' . $dom], $vjar);
t_eq(200, $r['status'], 'GET / no domínio do grupo: 200');
t_ok(preg_match_all('/^Set-Cookie:[^\r\n]*/mi', $r['headers'], $sc) > 0, 'GET /: abre sessão anônima (Set-Cookie)');
t_ok(array_filter($sc[0], static fn (string $c): bool => stripos($c, 'domain=') !== false) === [], 'Set-Cookie sem Domain= (cookie só do host do grupo)');
preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $tm) || preg_match('/"csrf":"([^"]+)"/', $r['body'], $tm);
$vcsrf = $tm[1] ?? '';
t_ok($vcsrf !== '', 'vitrine traz token CSRF');
$r = t_http('POST', $base . "/imoveis/$h/avaliar", http_build_query(['_csrf' => $vcsrf, 'author' => 'Hóspede V', 'score' => '9', 'comment' => 'Ótimo']), ['Host: ' . $dom, 'Content-Type: application/x-www-form-urlencoded'], $vjar);
t_eq(302, $r['status'], 'POST /imoveis/<h>/avaliar com CSRF válido no domínio do grupo: 302');
t_ok(str_starts_with((string) $loc($r), "/imoveis/$h"), 'avaliar: volta para o imóvel no mesmo host (relativo)');
$n = (int) db()->query("SELECT COUNT(*) FROM reviews WHERE author = 'Hóspede V'")->fetchColumn();
t_eq(1, $n, 'avaliação gravada');
foreach (['/entrar', '/cadastro'] as $p) {
    t_http('POST', $base . $p, http_build_query(['_csrf' => $vcsrf, 'email' => $owner['email'], 'password' => $owner['password']]), ['Host: ' . $dom, 'Content-Type: application/x-www-form-urlencoded'], $vjar);
}
t_http('POST', $base . '/api/sessao', json_encode(['email' => $owner['email'], 'password' => $owner['password']]), ['Host: ' . $dom, 'Content-Type: application/json', 'X-CSRF-Token: ' . $vcsrf], $vjar);
preg_match('/\t(PHPSESSID|[A-Za-z0-9_]+)\t(\S+)$/m', (string) file_get_contents($vjar), $vm);
$sessFile = CL_ROOT . '/storage/sessions/sess_' . ($vm[2] ?? 'x');
t_ok(is_file($sessFile), 'sessão do host do grupo existe no disco');
t_ok(!str_contains((string) @file_get_contents($sessFile), 'user_id'), 'sessão do host do grupo nunca tem user_id (mesmo após tentar login)');
@unlink($vjar);

echo "plataforma, Host desconhecido e domínio não verificado: nada muda\n";
foreach (['127.0.0.1', 'desconhecido.example.org', 'pendente.example.com'] as $host) {
    t_eq(200, $req('GET', '/entrar', $host)['status'], "$host: GET /entrar 200");
    t_eq(200, $req('GET', '/cadastro', $host)['status'], "$host: GET /cadastro 200");
    t_eq(302, $req('GET', '/painel', $host)['status'], "$host: /painel sem login 302");
    t_eq('/entrar', $loc($req('GET', '/painel', $host)), "$host: /painel -> /entrar (Auth normal)");
    t_eq(419, $req('POST', '/entrar', $host)['status'], "$host: POST /entrar 419 sem CSRF (pipeline normal)");
    t_eq(419, $req('POST', '/api/sessao', $host, $json, '{}')['status'], "$host: POST /api/sessao 419");
    t_eq(401, $req('GET', '/api/users/eu', $host)['status'], "$host: /api/users/eu 401");
}
$r = $req('GET', '/entrar', '127.0.0.1', ['X-Forwarded-Host: imob.example.com']);
t_eq(200, $r['status'], 'X-Forwarded-Host não ativa a trava');

echo "decisão pura\n";
t_eq(null, SomenteVitrine::decidir('localhost', '/entrar', 'GET'), 'sem formato de host: nada');
t_eq(['not_found', null], SomenteVitrine::decidir($dom, '/entrar', 'POST'), 'POST -> not_found');
t_eq(['not_found', null], SomenteVitrine::decidir($dom, '/api/busca', 'POST'), 'POST na API -> not_found');
t_eq(null, SomenteVitrine::decidir($dom, '/api/busca', 'GET'), 'GET /api/busca segue');
t_eq(['not_found', null], SomenteVitrine::decidir($dom, "/entrar\r\nX", 'GET'), 'caminho com CRLF -> not_found');
$old = getenv('APP_URL');
putenv('APP_URL=');
t_eq(['not_found', null], SomenteVitrine::decidir($dom, '/entrar', 'GET'), 'sem APP_URL: 404 (nunca redirect relativo, sem laço)');
putenv('APP_URL=' . $old);
t_done();

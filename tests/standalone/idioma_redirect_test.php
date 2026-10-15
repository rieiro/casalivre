<?php
// POST /idioma volta para o Referer do mesmo host só se for caminho local seguro (safe_local_return):
// "//evil", "/\evil", CR/LF (crus ou %0D%0A) -> "/". Antes: Referer https://host//evil.com/x -> Location: //evil.com/x.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/helpers.php';
t_temp_db();

echo "unidade\n";
foreach (['//evil.com/x', '/\\evil.com', '\\\\evil.com', "/a\r\nSet-Cookie: x=1", '/a%0D%0ASet-Cookie:%20x=1', '/%2F/evil.com',
    '/%5Cevil.com', 'http://evil.com/', 'evil.com', ''] as $v) {
    t_eq('/', safe_local_return($v), 'recusa ' . json_encode($v));
}
foreach (['/', '/busca?localidade=Floripa&checkin=2026-12-01', '/imoveis/' . str_repeat('a', 16), '/g/imob/busca?q=a%20b'] as $v) {
    t_eq($v, safe_local_return($v), 'aceita ' . $v);
}

echo "HTTP\n";
$base = t_server();
// O /idioma compara o host do Referer com o Host inteiro (com porta); o teste manda Host sem porta.
$host = (string) parse_url($base, PHP_URL_HOST);
$jar = tempnam(sys_get_temp_dir(), 'ijar');
$page = t_http('GET', $base . '/entrar', null, [], $jar);
preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
$csrf = $m[1] ?? '';
t_ok($csrf !== '', 'token CSRF');
$post = static function (string $referer) use ($base, $jar, $csrf, $host): ?string {
    $r = t_http('POST', $base . '/idioma', http_build_query(['_csrf' => $csrf, 'locale' => 'pt-BR']), ['Host: ' . $host, 'Content-Type: application/x-www-form-urlencoded', 'Referer: ' . $referer], $jar);
    return preg_match('/^Location:\s*([^\r\n]*)/mi', $r['headers'], $lm) === 1 ? $lm[1] : null;
};
t_eq('/', $post("http://$host//evil.com/x"), 'Referer //evil.com/x -> /');
t_eq('/', $post("http://$host/\\evil.com"), 'Referer /\\evil.com -> /');
t_eq('/', $post("http://$host/%0d%0aSet-Cookie:%20x=1"), 'Referer com CRLF codificado -> /');
t_eq('/', $post("http://$host/%2F/evil.com"), 'Referer /%2F/evil.com -> /');
t_eq('/', $post('http://evil.com/busca'), 'Referer de outro host -> /');
t_eq('/busca?localidade=Floripa', $post("http://$host/busca?localidade=Floripa"), 'Referer normal do mesmo host: mantido');
@unlink($jar);
t_done();

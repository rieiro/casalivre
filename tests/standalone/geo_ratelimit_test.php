<?php
// Limite por IP (ApiRateLimit/IpRateLimiter no Valkey) pela HTTP: rotas e limites, Retry-After, e o modo de falha
// com o Valkey fora do ar (429 + Retry-After 30 em cadastro/esqueci a senha; o resto passa). Unidade do limitador:
// ip_rate_limiter_test.php.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/IpRateLimiter.php';

echo "HTTP\n";
t_temp_db();
$base = t_server();
t_ratelimit_reset();
$codes = [];
for ($i = 0; $i < 61; $i++) {
    $codes[] = t_http('GET', $base . '/api/cidades?q=flo')['status'];
}
t_ok(!in_array(429, array_slice($codes, 0, 60), true), '60 primeiras em /api/cidades não recebem 429');
$r = t_http('GET', $base . '/api/cidades?q=flo');
t_eq(429, $r['status'], '/api/cidades acima do limite -> 429');
t_ok((bool) preg_match('/^Retry-After:\s*\d+/mi', $r['headers']), 'Retry-After presente');
$r = t_http('GET', $base . '/api/ceps?q=88000000');
t_ok($r['status'] !== 429, '/api/ceps tem contador próprio');
t_eq(['error' => 'Muitas requisições. Tente novamente em instantes.'], json_decode(t_http('GET', $base . '/api/cidades?q=flo')['body'], true), '429 no formato {"error": ...}');

echo "limites por rota\n";
$burst = static function (string $method, string $url, int $n, ?string $body = null, array $h = []) use ($base): array {
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $codes[] = t_http($method, $base . $url, $body, $h)['status'];
    }
    return $codes;
};
$c = $burst('POST', '/api/auth/senha/esqueci', 6, '{}', ['Content-Type: application/json']);
t_ok(!in_array(429, array_slice($c, 0, 5), true) && $c[5] === 429, 'esqueci a senha: 5 por 15 min (' . implode(',', $c) . ')');
$c = $burst('POST', '/api/users', 11, '{}', ['Content-Type: application/json']);
t_ok(!in_array(429, array_slice($c, 0, 10), true) && $c[10] === 429, 'cadastro: 10 por hora');
$c = $burst('POST', '/api/imoveis/0123456789abcdef/avaliar', 10, '{}', ['Content-Type: application/json']);
$c2 = $burst('POST', '/api/imoveis/fedcba9876543210/avaliar', 1, '{}', ['Content-Type: application/json']);
t_ok(!in_array(429, $c, true) && $c2[0] === 429, 'avaliar: 10 por hora, somando todos os imóveis');
$c = $burst('POST', '/api/casas/0123456789abcdef/fotos', 31, '{}', ['Content-Type: application/json']);
t_ok(!in_array(429, array_slice($c, 0, 30), true) && $c[30] === 429, 'upload de fotos: 30 por 10 min');
$c = $burst('GET', '/api/busca', 61);
t_ok(!in_array(429, array_slice($c, 0, 60), true) && $c[60] === 429, 'busca: 60 por minuto');
t_ok(t_http('GET', $base . '/api/casas')['status'] !== 429, 'rota sem regra não é limitada');
echo "rotas legadas removidas\n";
t_eq(404, t_http('GET', $base . '/cidades?q=flo')['status'], '/cidades (legado) -> 404');
t_eq(404, t_http('GET', $base . '/ceps?q=88000000')['status'], '/ceps (legado) -> 404');
$js = (string) file_get_contents(CL_ROOT . '/public/assets/app.js');
t_ok(!preg_match("#fetch\\('/(cidades|ceps)#", $js) && str_contains($js, "/api/cidades") && str_contains($js, "/api/ceps"), 'app.js usa só /api/cidades e /api/ceps');
t_ratelimit_reset();

echo "Valkey fora do ar (servidor com socket inexistente)\n";
$sock = getenv('VALKEY_SOCKET');
putenv('VALKEY_SOCKET=' . sys_get_temp_dir() . '/no-valkey-' . bin2hex(random_bytes(3)) . '.sock');
foreach (['VALKEY_USER' => 'cl_app', 'VALKEY_PASSWORD_FILE' => (string) getenv('CASALIVRE_ENV_FILE')] as $k => $v) {
    if (getenv($k) === false) {
        putenv("$k=$v");
    }
}
$down = t_server();
$sock === false ? putenv('VALKEY_SOCKET') : putenv('VALKEY_SOCKET=' . $sock);
$r = t_http('POST', $down . '/api/users', '{}', ['Content-Type: application/json']);
t_eq(429, $r['status'], 'fail-closed: POST /api/users (cadastro) -> 429');
t_ok((bool) preg_match('/^Retry-After:\s*30\s*$/mi', $r['headers']), 'fail-closed: Retry-After: 30');
t_eq(429, t_http('POST', $down . '/api/auth/senha/esqueci', '{}', ['Content-Type: application/json'])['status'], 'fail-closed: esqueci a senha (API) -> 429');
t_eq(429, t_http('POST', $down . '/cadastro', 'x=1')['status'], 'fail-closed: POST /cadastro (web) -> 429');
t_eq(429, t_http('POST', $down . '/senha/esqueci', 'x=1')['status'], 'fail-closed: POST /senha/esqueci (web) -> 429');
$r = t_http('GET', $down . '/api/cidades?q=flo');
t_ok($r['status'] === 200 && !preg_match('/^Retry-After:/mi', $r['headers']), 'fail-open: GET /api/cidades -> 200 (' . $r['status'] . ')');
t_ok(t_http('GET', $down . '/api/busca')['status'] !== 429, 'fail-open: GET /api/busca não recebe 429');
t_done();

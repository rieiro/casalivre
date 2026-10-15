<?php
// Limite por IP (ApiRateLimit/IpRateLimiter): rotas e limites, IPv6 por /64, limpeza, teto de arquivos e log de falha aberta.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/IpRateLimiter.php';

echo "IpRateLimiter\n";
IpRateLimiter::$dir = sys_get_temp_dir() . '/rl-geo-' . bin2hex(random_bytes(3));
$now = 1000;
for ($i = 1; $i <= 3; $i++) {
    t_eq(0, IpRateLimiter::hit('b', 3, 60, '1.2.3.4', $now), "requisição $i permitida");
}
t_eq(60, IpRateLimiter::hit('b', 3, 60, '1.2.3.4', $now), '4ª bloqueada, Retry-After 60');
t_eq(0, IpRateLimiter::hit('b', 3, 60, '5.6.7.8', $now), 'outro IP não é afetado');
t_eq(0, IpRateLimiter::hit('outro', 3, 60, '1.2.3.4', $now), 'outro endpoint não é afetado');
t_eq(0, IpRateLimiter::hit('b', 3, 60, '1.2.3.4', $now + 60), 'janela seguinte libera');
t_eq('700', substr(sprintf('%o', fileperms(IpRateLimiter::$dir)), -3), 'diretório 0700');
t_eq(0, IpRateLimiter::peek('pk', 2, 60, '1.2.3.4', $now), 'peek sem estado: libera');
IpRateLimiter::hit('pk', 2, 60, '1.2.3.4', $now);
t_eq(0, IpRateLimiter::peek('pk', 2, 60, '1.2.3.4', $now), 'peek com 1 de 2: libera');
t_eq(0, IpRateLimiter::peek('pk', 2, 60, '1.2.3.4', $now), 'peek não conta (repetido continua liberando)');
IpRateLimiter::hit('pk', 2, 60, '1.2.3.4', $now);
t_eq(60, IpRateLimiter::peek('pk', 2, 60, '1.2.3.4', $now), 'peek com 2 de 2: bloqueia (Retry-After 60)');
t_eq(0, IpRateLimiter::peek('pk', 2, 60, '1.2.3.4', $now + 60), 'peek na janela seguinte: libera');
$vistos = [];
$acao = static function (bool $feito) use (&$vistos): callable {
    return static function (bool $cabe) use ($feito, &$vistos): bool {
        $vistos[] = $cabe;
        return $feito;
    };
};
t_eq(false, IpRateLimiter::reserve('rs', 2, 60, '1.2.3.4', $acao(false), $now), 'reserve: devolve o retorno da ação');
t_eq(0, IpRateLimiter::peek('rs', 2, 60, '1.2.3.4', $now), 'reserve: ação não feita não conta');
IpRateLimiter::reserve('rs', 2, 60, '1.2.3.4', $acao(true), $now);
IpRateLimiter::reserve('rs', 2, 60, '1.2.3.4', $acao(true), $now);
IpRateLimiter::reserve('rs', 2, 60, '1.2.3.4', $acao(true), $now);
t_eq([true, true, true, false], $vistos, 'reserve: só as 2 primeiras ações feitas cabem; a 3ª recebe cabe=false');
t_eq(60, IpRateLimiter::peek('rs', 2, 60, '1.2.3.4', $now), 'reserve: ação feita sem caber não conta além do limite');

echo "IPv6 agrupado por /64\n";
IpRateLimiter::$purgeChance = 0;
for ($i = 1; $i <= 3; $i++) {
    t_eq(0, IpRateLimiter::hit('v6', 3, 60, "2001:db8:1:2::$i", $now), "IPv6 $i do mesmo /64 permitido");
}
t_eq(60, IpRateLimiter::hit('v6', 3, 60, '2001:db8:1:2:ffff:1:2:3', $now), 'outro endereço do mesmo /64 é bloqueado');
t_eq(0, IpRateLimiter::hit('v6', 3, 60, '2001:db8:1:3::1', $now), 'outro /64 não é afetado');
t_eq('1.2.3.4', IpRateLimiter::clientKey('::ffff:1.2.3.4'), 'IPv4 mapeado vira IPv4');

echo "limpeza e teto de arquivos\n";
$files = glob(IpRateLimiter::$dir . '/*.json');
touch($files[0], time() - IpRateLimiter::MAX_AGE - 10);
t_eq(1, IpRateLimiter::purge(), 'purge apaga arquivo mais velho que MAX_AGE');
t_eq(count($files) - 1, count(glob(IpRateLimiter::$dir . '/*.json')), 'recentes mantidos');
$log = tempnam(sys_get_temp_dir(), 'rlerr');
ini_set('error_log', $log);
IpRateLimiter::$maxFiles = count(glob(IpRateLimiter::$dir . '/*.json'));
t_eq(60, IpRateLimiter::hit('novo', 1, 60, '9.9.9.9', $now), 'teto atingido: chave nova recebe 429 (Retry-After 60)');
t_eq(IpRateLimiter::$maxFiles, count(glob(IpRateLimiter::$dir . '/*.json')), 'teto atingido: nenhum arquivo novo');
t_ok(str_contains((string) file_get_contents($log), 'ip_rate_limiter: teto de arquivos atingido, chave nova recusada'), 'recusa registrada no log');
t_eq(0, IpRateLimiter::hit('v6', 3, 60, '2001:db8:1:3::9', $now + 60), 'teto atingido: chave existente segue dentro do limite');
t_eq(0, IpRateLimiter::hit('v6', 3, 60, '2001:db8:1:3::9', $now + 60), 'chave existente: 2ª na janela');
t_eq(0, IpRateLimiter::hit('v6', 3, 60, '2001:db8:1:3::9', $now + 60), 'chave existente: 3ª na janela');
t_eq(60, IpRateLimiter::hit('v6', 3, 60, '2001:db8:1:3::9', $now + 60), 'chave existente: 4ª bloqueada pelo limite normal');
t_ok(!str_contains((string) file_get_contents($log), '9.9.9.9'), 'log sem IP');
foreach (glob(IpRateLimiter::$dir . '/*.json') as $f) {
    touch($f, time() - IpRateLimiter::MAX_AGE - 10);
}
t_eq(0, IpRateLimiter::hit('novo', 1, 60, '9.9.9.9', $now), 'teto atingido com arquivos velhos: limpa e aceita a chave nova');
t_eq(1, count(glob(IpRateLimiter::$dir . '/*.json')), 'só o arquivo novo fica');
IpRateLimiter::$maxFiles = IpRateLimiter::MAX_FILES;
exec('rm -rf ' . escapeshellarg(IpRateLimiter::$dir));
IpRateLimiter::$dir = '/proc/nao-gravavel/rl';
t_eq(0, IpRateLimiter::hit('b', 1, 60, '1.2.3.4', $now), 'diretório indisponível: falha aberta');
t_ok(str_contains((string) file_get_contents($log), 'falha aberta (diretório indisponível)'), 'registrado no log');
IpRateLimiter::$dir = sys_get_temp_dir() . '/rl-geo-' . bin2hex(random_bytes(3));
exec('rm -rf ' . escapeshellarg(IpRateLimiter::$dir));

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
t_done();

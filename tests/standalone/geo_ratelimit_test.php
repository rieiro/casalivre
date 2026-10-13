<?php
// Limite por IP em /api/cidades e /api/ceps.
require __DIR__ . '/lib.php';
require CL_ROOT . '/src/IpRateLimiter.php';

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
exec('rm -rf ' . escapeshellarg(IpRateLimiter::$dir));

echo "HTTP\n";
t_temp_db();
$base = t_server();
@exec('rm -rf ' . escapeshellarg(CL_ROOT . '/storage/cache/ratelimit'));
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
@exec('rm -rf ' . escapeshellarg(CL_ROOT . '/storage/cache/ratelimit'));
t_done();

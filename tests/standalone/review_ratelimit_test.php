<?php
// Limite por IP na avaliação: web (POST /imoveis/{hash}/avaliar) e API (POST /api/imoveis/{hash}/avaliar)
// usam o mesmo limitador (ApiRateLimit/IpRateLimiter) e o mesmo balde, 10 por hora somando os dois canais.
// O 11º pedido recebe 429 + Retry-After nos dois: JSON na API (resposta de antes) e página HTML na web.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
t_temp_db();
$owner = t_seed_owner();
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = c_csrf($base, $jar);
$h = '/imoveis/' . $owner['hash'];
$count = static fn (): int => (int) db()->query('SELECT COUNT(*) FROM reviews WHERE property_id = ' . $owner['house_id'])->fetchColumn();
$web = static fn (): array => c_form($base, $h . '/avaliar', ['_csrf' => $csrf, 'score' => 8], $jar);
$api = static fn (): array => c_api('POST', $base, '/api' . $h . '/avaliar', ['score' => 8], $csrf, $jar);

foreach (['web' => [$web, $api], 'api' => [$api, $web]] as $ultimo => [$call11, $other]) {
    echo "10 mistas, 11ª pela $ultimo\n";
    t_ratelimit_reset();
    $before = $count();
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $codes[] = ($i % 2 === 0 ? $web : $api)()['status'];
    }
    t_eq([302, 201, 302, 201, 302, 201, 302, 201, 302, 201], $codes, '10 avaliações (web 302 / API 201) passam');
    t_eq($before + 10, $count(), '10 reviews gravadas');
    $r = $call11();
    t_eq(429, $r['status'], "11ª pela $ultimo -> 429");
    t_ok((bool) preg_match('/^Retry-After:\s*([1-9]\d*)\s*$/mi', $r['headers'], $m) && (int) $m[1] <= 3600, "$ultimo: Retry-After presente (<= 3600 s)");
    if ($ultimo === 'api') {
        t_eq(['error' => 'Muitas requisições. Tente novamente em instantes.'], json_decode($r['body'], true), 'API: corpo JSON de antes');
    } else {
        t_ok(str_contains($r['headers'], 'text/html') && str_contains($r['body'], 'Muitas requisições'), 'web: 429 em página HTML');
    }
    t_eq(429, $other()['status'], 'o outro canal também recebe 429 (balde compartilhado)');
    t_eq($before + 10, $count(), 'nenhuma review gravada após o limite');
}
t_ok(t_http('GET', $base . $h)['status'] !== 429, 'GET da página do imóvel não é limitado');

t_done();

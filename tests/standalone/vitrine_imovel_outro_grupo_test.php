<?php
// Bug do Bot Teste: no domínio verificado do grupo X, /imoveis/<h> de imóvel de outro grupo (ou sem grupo) dava 200 e o
// POST /imoveis/<h>/avaliar era aceito. Regra do A1 do #67 (GrupoResolvedor::imovelNoHost): no domínio do grupo, só
// imóvel dele; senão 404 e nada gravado. Plataforma: como antes. HEAD responde como GET (status e cabeçalhos, sem corpo).
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
t_temp_db();
$owner = t_seed_owner();
$adm = ['id' => 1, 'is_admin' => 1, 'status' => 'active'];
GrupoAdmin::create($adm, 'grupo-x', 'Grupo X', 'padrao');
GrupoAdmin::create($adm, 'grupo-y', 'Grupo Y', 'padrao');
$gX = GroupRepository::findBySlug('grupo-x');
$gY = GroupRepository::findBySlug('grupo-y');
$house = static function (string $title, ?array $g) use ($owner): string {
    $id = HouseRepository::insert([
        'user_id' => $owner['user_id'], 'title' => $title, 'description' => 'Descrição de teste do imóvel.',
        'summary' => 'Resumo', 'city' => 'Florianópolis, SC', 'address' => 'Rua A, 1', 'cep' => '88000000',
        'street' => 'Rua A', 'address_number' => '1', 'address_complement' => '', 'lat' => -27.59, 'lng' => -48.54,
        'bedrooms' => 1, 'beds' => 1, 'max_guests' => 2, 'nightly_price' => 200, 'checkin_time' => '15:00',
        'checkout_time' => '11:00', 'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
    ]);
    if ($g !== null) {
        db()->prepare('UPDATE properties SET group_id = ? WHERE id = ?')->execute([$g['id'], $id]);
    }
    return HouseRepository::ensurePublicHash(HouseRepository::find($id));
};
$hX = $house('Casa do X', $gX);
$hY = $house('Casa do Y', $gY);
$hN = $house('Casa sem grupo', null);
$ins = db()->prepare('INSERT INTO group_domains (group_id, domain, verify_token, verified_at) VALUES (?, ?, ?, ?)');
$ins->execute([$gX['id'], 'x.example.com', 'tokx', '2026-10-01 00:00:00']);
$ins->execute([$gY['id'], 'y.example.com', 'toky', '2026-10-01 00:00:00']);
$base = t_server();
$dom = 'x.example.com';
$plat = '127.0.0.1';

$get = static fn (string $p, string $host, string $m = 'GET'): array => t_http($m, $base . $p, null, ['Host: ' . $host]);
$reviews = static function (string $ref): int {
    $st = db()->prepare('SELECT COUNT(*) FROM reviews r JOIN properties p ON p.id = r.property_id WHERE p.public_hash = ?');
    $st->execute([$ref]);
    return (int) $st->fetchColumn();
};
$avaliar = static function (string $ref, string $host) use ($base): array {
    $jar = tempnam(sys_get_temp_dir(), 'ojar');
    $page = t_http('GET', $base . '/', null, ['Host: ' . $host], $jar);
    preg_match('/"csrf":"([^"]+)"/', $page['body'], $m) || preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
    $csrf = $m[1] ?? '';
    $r = t_http('POST', $base . "/imoveis/$ref/avaliar", http_build_query(['_csrf' => $csrf, 'author' => 'Teste', 'score' => '8', 'comment' => 'ok']),
        ['Host: ' . $host, 'Content-Type: application/x-www-form-urlencoded'], $jar);
    @unlink($jar);
    t_ok($csrf !== '', "CSRF válido obtido em $host");
    return $r;
};

echo "domínio do grupo X: GET /imoveis/<h>\n";
t_eq(200, $get("/imoveis/$hX", $dom)['status'], 'imóvel do grupo X: 200');
t_eq(404, $get("/imoveis/$hY", $dom)['status'], 'imóvel do grupo Y: 404');
t_eq(404, $get("/imoveis/$hN", $dom)['status'], 'imóvel sem grupo: 404');
$r = $get("/imoveis/$hY", $dom);
t_ok(!str_contains($r['body'], 'Casa do Y') && !str_contains($r['body'], '"hash":"' . $hY . '"'), '404 não expõe o imóvel do Y');
t_eq(404, $get("/imoveis/$hY", 'X.EXAMPLE.COM:443')['status'], 'Host com maiúsculas/porta: mesma regra');
t_eq(200, $get("/imoveis/$hY", 'y.example.com')['status'], 'no domínio do Y, o imóvel do Y: 200');

echo "domínio do grupo X: GET /api/imoveis/<h> (dados que o property.js carrega)\n";
t_eq(200, $get("/api/imoveis/$hX", $dom)['status'], 'API imóvel do X: 200');
t_eq(404, $get("/api/imoveis/$hY", $dom)['status'], 'API imóvel do Y: 404');
t_eq(404, $get("/api/imoveis/$hN", $dom)['status'], 'API imóvel sem grupo: 404');

echo "domínio do grupo X: POST /imoveis/<h>/avaliar com CSRF válido\n";
$r = $avaliar($hX, $dom);
t_eq(302, $r['status'], 'avaliar imóvel do X: 302');
t_eq(1, $reviews($hX), 'avaliação do X gravada');
foreach (['grupo Y' => $hY, 'sem grupo' => $hN] as $label => $ref) {
    $r = $avaliar($ref, $dom);
    t_eq(404, $r['status'], "avaliar imóvel $label: 404");
    t_eq(0, $reviews($ref), "avaliar imóvel $label: nada gravado");
}

echo "plataforma: como antes\n";
foreach (['X' => $hX, 'Y' => $hY, 'sem grupo' => $hN] as $label => $ref) {
    t_eq(200, $get("/imoveis/$ref", $plat)['status'], "plataforma: imóvel $label 200");
    t_eq(200, $get("/api/imoveis/$ref", $plat)['status'], "plataforma: API imóvel $label 200");
}
$r = $avaliar($hY, $plat);
t_eq(302, $r['status'], 'plataforma: avaliar imóvel do Y 302');
t_eq(1, $reviews($hY), 'plataforma: avaliação do Y gravada');
$r = $avaliar($hN, 'desconhecido.example.org');
t_eq([302, 1], [$r['status'], $reviews($hN)], 'Host desconhecido: avaliar imóvel sem grupo 302 e gravado');
t_eq(200, $get("/imoveis/$hY", 'pendente.example.net')['status'], 'Host não verificado: como a plataforma');

echo "HEAD como GET\n";
$hdr = static fn (array $r, string $n): ?string => preg_match('/^' . preg_quote($n, '/') . ':\s*(.*?)\r?$/mi', $r['headers'], $m) === 1 ? $m[1] : null;
foreach ([[$dom, '/'], [$plat, '/'], [$dom, "/imoveis/$hX"], [$dom, "/imoveis/$hY"], [$dom, '/busca'], [$plat, '/entrar']] as [$host, $p]) {
    $g = $get($p, $host);
    $h = t_http('HEAD', $base . $p, null, ['Host: ' . $host]);
    t_eq($g['status'], $h['status'], "HEAD $p ($host): mesmo status do GET ({$g['status']})");
    t_eq('', $h['body'], "HEAD $p ($host): sem corpo");
    foreach (['Content-Type', 'Content-Security-Policy', 'X-Frame-Options', 'Cache-Control'] as $n) {
        t_eq($hdr($g, $n), $hdr($h, $n), "HEAD $p ($host): $n igual ao GET");
    }
}
t_eq(200, t_http('HEAD', $base . '/', null, ['Host: ' . $dom])['status'], 'HEAD / no domínio do grupo: 200');
t_done();

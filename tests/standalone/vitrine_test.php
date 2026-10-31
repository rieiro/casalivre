<?php
// Sprint 3, PR 8: vitrine do grupo em /g/<slug>/ (home, busca, mapa e página do imóvel). Só imóveis ativos do grupo;
// links ficam na vitrine; tema do grupo pelo GrupoResolvedor; slug inexistente -> 404 com o tema da plataforma;
// um grupo não mostra imóvel de outro; saída escapada; CSP estrita.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
t_temp_db();
$owner = t_seed_owner();
$admin = ['id' => 1, 'is_admin' => 1, 'status' => 'active'];
$house = static function (int $userId, string $title): array {
    $id = HouseRepository::insert([
        'user_id' => $userId, 'title' => $title, 'description' => 'Descrição de teste do imóvel.',
        'summary' => 'Resumo', 'city_place_id' => t_city_place_id('Florianópolis, SC'), 'address' => 'Rua A, 1', 'cep' => '88000000',
        'street' => 'Rua A', 'address_number' => '1', 'address_complement' => '', 'lat' => -27.59, 'lng' => -48.54,
        'bedrooms' => 1, 'beds' => 1, 'max_guests' => 2, 'nightly_price' => 200, 'checkin_time' => '15:00',
        'checkout_time' => '11:00', 'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
    ]);
    return ['id' => $id, 'hash' => HouseRepository::ensurePublicHash(HouseRepository::find($id))];
};
$xssName = 'Imob <script>alert(1)</script> & "Sul"';
GrupoAdmin::create($admin, 'imob-sul', $xssName, 'contraste');
GrupoAdmin::create($admin, 'outro', 'Outro Grupo', 'padrao');
$gA = GroupRepository::findBySlug('imob-sul');
$gB = GroupRepository::findBySlug('outro');
$uid = (int) $owner['user_id'];
$hA = ['id' => $owner['house_id'], 'hash' => $owner['hash']];
db()->prepare('UPDATE properties SET title = ? WHERE id = ?')->execute(['Casa A <img src=x onerror=alert(2)>', $hA['id']]);
ListingSearch::sync((int) $hA['id']);
$hA2 = $house($uid, 'Casa A inativa');
$hB = $house($uid, 'Casa do Outro');
$hNone = $house($uid, 'Casa sem grupo');
$link = static function (array $h, array $g): void {
    db()->prepare('UPDATE properties SET group_id = ? WHERE id = ?')->execute([$g['id'], $h['id']]);
    ListingSearch::sync((int) $h['id']);
};
$link($hA, $gA);
$link($hA2, $gA);
$link($hB, $gB);
db()->prepare("UPDATE properties SET status = 'inactive' WHERE id = ?")->execute([$hA2['id']]);
ListingSearch::sync((int) $hA2['id']);
$base = t_server();
$css = static fn (string $body): ?string => preg_match('#/assets/themes/(contraste|padrao)(?:\.[a-f0-9]+)?\.css#', $body, $m) === 1 ? $m[1] : null;
$hdr = static fn (array $r, string $n): ?string => preg_match('/^' . preg_quote($n, '/') . ':\s*(.*?)\r?$/mi', $r['headers'], $m) === 1 ? $m[1] : null;
$get = static fn (string $p): array => t_http('GET', $base . $p);
$strict = static function (array $r, string $label) use ($hdr): void {
    $csp = (string) $hdr($r, 'Content-Security-Policy');
    t_ok(str_contains($csp, "script-src 'self'") && !str_contains($csp, 'unsafe-inline'), "$label: CSP estrita");
    preg_match_all('/<script([^>]*)>/i', $r['body'], $sm);
    t_eq([], array_values(array_filter($sm[1], static fn (string $a) => !str_contains($a, 'src=') && !str_contains($a, 'application/json'))), "$label: sem script inline");
    t_ok(!preg_match('/<[^>]*\s(on[a-z]+|style)\s*=/i', $r['body']), "$label: sem handler nem style inline");
};
$api = static function (string $q) use ($get): array {
    $r = $get('/api/busca?' . $q);
    return [$r['status'], array_column(json_decode($r['body'], true)['items'] ?? [], 'id')];
};

echo "home da vitrine\n";
$r = $get('/g/imob-sul');
t_eq([302, '/g/imob-sul/'], [$r['status'], c_location($r)], '/g/<slug> sem barra -> /g/<slug>/');
$r = $get('/g/imob-sul/');
t_eq([200, 'contraste'], [$r['status'], $css($r['body'])], 'home 200 com o tema do grupo');
t_ok(str_contains($r['body'], '/g/imob-sul/imoveis/' . $hA['hash']), 'lista o imóvel ativo do grupo com link na vitrine');
t_ok(!str_contains($r['body'], $hA2['hash']) && !str_contains($r['body'], 'Casa A inativa'), 'não lista imóvel inativo do grupo');
t_ok(!str_contains($r['body'], $hB['hash']) && !str_contains($r['body'], 'Casa do Outro'), 'não lista imóvel de outro grupo');
t_ok(!str_contains($r['body'], $hNone['hash']), 'não lista imóvel sem grupo');
t_ok(str_contains($r['body'], 'action="/g/imob-sul/busca"'), 'formulário de busca fica na vitrine');
t_ok(str_contains($r['body'], 'class="brand" href="/g/imob-sul/"'), 'marca leva à home da vitrine');
t_ok(!preg_match('#href="/(busca|imoveis/)#', $r['body']), 'nenhum link de busca/imóvel sai da vitrine');
t_ok(!str_contains($r['body'], '<script>alert(1)') && str_contains($r['body'], 'Imob &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Sul&quot;'), 'nome do grupo escapado');
t_ok(!str_contains($r['body'], '<img src=x') && str_contains($r['body'], 'Casa A &lt;img src=x onerror=alert(2)&gt;'), 'título do imóvel escapado');
t_ok((bool) preg_match('/private|no-store/', (string) $hdr($r, 'Cache-Control')) && !str_contains((string) $hdr($r, 'Cache-Control'), 'public'), 'Cache-Control privado (não serve outro grupo)');
$strict($r, 'home');
$r = $get('/g/outro/');
t_eq([200, 'padrao'], [$r['status'], $css($r['body'])], 'outro grupo com o tema dele');
t_ok(str_contains($r['body'], $hB['hash']) && !str_contains($r['body'], $hA['hash']), 'outro grupo só com os imóveis dele');

echo "slug desconhecido ou inválido\n";
foreach (['/g/nao-existe/', '/g/nao-existe', '/g/IMOB-SUL/', '/g/imob-sul-/', '/g/%3Cscript%3E/', '/g/nao-existe/busca', '/g/nao-existe/imoveis/' . $hA['hash']] as $p) {
    $r = $get($p);
    t_eq([404, 'padrao'], [$r['status'], $css($r['body'])], "$p: 404 com o tema da plataforma");
}
$r = $get('/g/%3Cscript%3E/');
t_ok(!str_contains($r['body'], '<script>'), 'slug com HTML não volta na página');

echo "imóvel na vitrine\n";
$r = $get('/g/imob-sul/imoveis/' . $hA['hash']);
t_eq([200, 'contraste'], [$r['status'], $css($r['body'])], 'imóvel do grupo: 200 com o tema do grupo');
$strict($r, 'imóvel');
foreach ([[$hB, 'de outro grupo'], [$hA2, 'inativo do grupo'], [$hNone, 'sem grupo']] as [$h, $what]) {
    t_eq(404, $get('/g/imob-sul/imoveis/' . $h['hash'])['status'], "imóvel $what: 404 na vitrine");
}
t_eq(404, $get('/g/imob-sul/imoveis/0000000000000000')['status'], 'imóvel inexistente: 404');
t_eq(200, $get('/g/outro/imoveis/' . $hB['hash'])['status'], 'cada vitrine abre o próprio imóvel');

echo "busca e mapa\n";
$r = t_http('GET', $base . '/g/imob-sul/busca?localidade=Florian%C3%B3polis', null, [], null, true);
t_eq([200, 'contraste'], [$r['status'], $css($r['body'])], 'busca 200 com o tema do grupo');
t_ok(preg_match('#id="search-results" data-base="/g/imob-sul" data-api="/api/busca\?[^"]*grupo=imob-sul"#', $r['body']) === 1, 'resultados com base da vitrine e API filtrada pelo grupo');
t_ok(str_contains($r['body'], 'href="/g/imob-sul/busca/mapa?') && str_contains($r['body'], 'action="/g/imob-sul/busca"'), 'mapa e filtros ficam na vitrine');
$strict($r, 'busca');
$r = t_http('GET', $base . '/g/imob-sul/busca?localidade=Florian%C3%B3polis&grupo=outro', null, [], null, true);
t_ok(str_contains($r['body'], 'grupo=imob-sul') && !str_contains($r['body'], 'grupo=outro"'), '?grupo= na URL não troca o grupo da vitrine');
$r = t_http('GET', $base . '/g/imob-sul/busca/mapa?localidade=Florian%C3%B3polis', null, [], null, true);
t_eq([200, 'contraste'], [$r['status'], $css($r['body'])], 'mapa 200 com o tema do grupo');
t_ok(preg_match('#data-api="/api/busca\?[^"]*grupo=imob-sul"#', $r['body']) === 1 && str_contains($r['body'], 'data-base="/g/imob-sul"'), 'mapa com API filtrada e base da vitrine');
t_ok(str_contains($r['body'], 'href="/g/imob-sul/busca?'), 'voltar à lista fica na vitrine');
$strict($r, 'mapa');

echo "API filtrada pelo grupo\n";
$q = 'localidade=Florian%C3%B3polis';
t_eq([200, [$hA['hash']]], $api("$q&grupo=imob-sul"), 'grupo A: só o imóvel ativo de A');
t_eq([200, [$hB['hash']]], $api("$q&grupo=outro"), 'grupo B: só o de B');
foreach (['nao-existe', 'IMOB-SUL', '', '%3Cx%3E'] as $s) {
    t_eq([200, []], $api("$q&grupo=$s"), "grupo '$s': nenhum imóvel");
}
t_eq([200, []], $api("$q&grupo%5B%5D=imob-sul"), 'grupo como lista: nenhum imóvel, sem erro');
[$st, $all] = $api($q);
t_ok($st === 200 && in_array($hA['hash'], $all, true) && in_array($hB['hash'], $all, true) && in_array($hNone['hash'], $all, true) && !in_array($hA2['hash'], $all, true), 'sem grupo: busca da plataforma igual (todos os ativos)');

echo "plataforma sem mudança\n";
$r = $get('/');
t_eq('padrao', $css($r['body']), 'home da plataforma com o tema da plataforma');
t_ok(str_contains($r['body'], 'class="brand" href="/"') && str_contains($r['body'], 'action="/busca"') && !str_contains($r['body'], '/g/'), 'home da plataforma sem links de vitrine');
t_ok(!str_contains($get('/busca?localidade=x')['body'], 'data-base='), 'busca da plataforma sem data-base');
t_ok(t_http('POST', $base . '/g/imob-sul/', '', ['Content-Type: application/x-www-form-urlencoded'])['status'] >= 400, 'POST na vitrine não é aceito');

echo "A1: domínio verificado de um grupo só serve a vitrine dele\n";
db()->exec("INSERT INTO group_domains (group_id, domain, verify_token, verified_at) VALUES ({$gA['id']}, 'imob.example.com', 't1', CURRENT_TIMESTAMP), ({$gB['id']}, 'pendente.example.com', 't2', NULL)");
$onHost = static fn (string $p, string $h, bool $follow = false): array => t_http('GET', $base . $p, null, ['Host: ' . $h], null, $follow);
foreach (['/g/outro/', '/g/outro/busca?localidade=Florian%C3%B3polis', '/g/outro/busca/mapa?localidade=Florian%C3%B3polis', '/g/outro/imoveis/' . $hB['hash'], '/g/outro'] as $p) {
    $r = $onHost($p, 'imob.example.com');
    t_eq(404, $r['status'], "domínio de A, $p: 404");
    t_ok(!str_contains($r['body'], $hB['hash']) && !str_contains($r['body'], 'Outro Grupo'), "domínio de A, $p: nada de B");
}
foreach (['/g/imob-sul/', '/g/imob-sul/busca?localidade=Florian%C3%B3polis', '/g/imob-sul/busca/mapa?localidade=Florian%C3%B3polis', '/g/imob-sul/imoveis/' . $hA['hash']] as $p) {
    t_eq(200, $onHost($p, 'IMOB.example.com:8080', true)['status'], "domínio de A, $p: 200");
}
t_eq(404, $onHost('/g/nao-existe/', 'imob.example.com')['status'], 'domínio de A, slug inexistente: 404');
foreach (['desconhecido.example.com', 'pendente.example.com'] as $h) {
    foreach (['/g/outro/' => 200, '/g/imob-sul/' => 200, '/g/outro/imoveis/' . $hB['hash'] => 200, '/g/nao-existe/' => 404] as $p => $st) {
        t_eq($st, $onHost($p, $h)['status'], "Host $h (plataforma/não verificado), $p: $st");
    }
}
t_eq(200, $get('/g/outro/')['status'], 'Host da plataforma, /g/outro/: 200');

echo "A2: redirect sem barra mantém a query, sempre relativo\n";
$loc = static fn (string $p): array => [($r = $get($p))['status'], c_location($r)];
t_eq([302, '/g/imob-sul/?a=1&b=2'], $loc('/g/imob-sul?a=1&b=2'), 'query mantida');
t_eq([302, '/g/imob-sul/'], $loc('/g/imob-sul'), 'sem query: sem ?');
t_eq([302, '/g/imob-sul/'], $loc('/g/imob-sul?'), 'query vazia: sem ?');
t_eq([302, '/g/imob-sul/?next=//evil.com'], $loc('/g/imob-sul?next=//evil.com'), 'next=//evil fica na query');
t_eq([302, '/g/imob-sul/?//evil.com'], $loc('/g/imob-sul?//evil.com'), '?//evil.com fica na query');
t_eq([302, '/g/imob-sul/?x=%2F%2Fevil.com%0d%0aSet-Cookie:a=b'], $loc('/g/imob-sul?x=%2F%2Fevil.com%0d%0aSet-Cookie:a=b'), 'valores codificados ficam como vieram, sem quebra de header');
t_eq([302, '/g/imob-sul/?a=1'], $loc('//evil.com/g/imob-sul?a=1'), '//evil.com/g/...: Location relativa da vitrine, nunca para evil.com');
t_eq(404, $get('/g/evil.com')['status'], '/g/evil.com: slug inválido, 404');
t_eq(404, $get('/g/%2F%2Fevil.com')['status'], '/g/%2F%2Fevil.com: 404');
$r = t_http('GET', $base . '/g/imob-sul?a=1', null, ['Host: evil.com']);
t_eq('/g/imob-sul/?a=1', c_location($r), 'Location não usa o Host');
t_eq(1, preg_match_all('/^Set-Cookie:\s*a=b/mi', $get('/g/imob-sul?x=%0d%0aSet-Cookie:a=b')['headers']) === 0 ? 1 : 0, 'sem header injetado');

echo "status vazio: busca, mapa, página e home da vitrine exigem 'active'\n";
// status é NOT NULL (DEFAULT 'active'); '' é o único caso fora de 'active'/'inactive'. Busca, mapa,
// página e a home (properties()) exigem status = 'active'.
$hE = $house($uid, 'Casa status vazio');
$link($hE, $gA);
db()->prepare("UPDATE properties SET status = '' WHERE id = ?")->execute([$hE['id']]);
ListingSearch::sync((int) $hE['id']);
[, $ids] = $api('localidade=Florian%C3%B3polis&grupo=imob-sul');
t_eq([$hA['hash']], $ids, "status '': fora da busca e do mapa da vitrine (mesma API)");
t_eq(404, $get('/g/imob-sul/imoveis/' . $hE['hash'])['status'], "status '': página na vitrine 404");
t_ok(!str_contains($get('/g/imob-sul/')['body'], $hE['hash']), "status '': fora da home da vitrine");
[, $all] = $api('localidade=Florian%C3%B3polis');
t_ok(!in_array($hE['hash'], $all, true), "status '': fora da busca da plataforma");
t_ok(!in_array($hA2['hash'], $ids, true), "status 'inactive': fora da busca da vitrine");

echo "API no domínio verificado: ?grupo de outro grupo é vazio\n";
$apiOn = static function (string $q, string $h) use ($base): array {
    $r = t_http('GET', $base . '/api/busca?' . $q, null, ['Host: ' . $h]);
    return [$r['status'], array_column(json_decode($r['body'], true)['items'] ?? [], 'id')];
};
$q = 'localidade=Florian%C3%B3polis';
t_eq([200, []], $apiOn("$q&grupo=outro", 'imob.example.com'), 'domínio de A, grupo=B: 200 vazio');
t_eq([200, []], $apiOn("$q&grupo=nao-existe", 'imob.example.com'), 'domínio de A, grupo inexistente: 200 vazio (igual)');
t_eq([200, [$hA['hash']]], $apiOn("$q&grupo=imob-sul", 'IMOB.example.com:8080'), 'domínio de A, grupo=A: só os de A');
foreach (['desconhecido.example.com', 'pendente.example.com'] as $h) {
    t_eq([200, [$hB['hash']]], $apiOn("$q&grupo=outro", $h), "Host $h, grupo=B: inalterado");
    t_eq([200, [$hA['hash']]], $apiOn("$q&grupo=imob-sul", $h), "Host $h, grupo=A: inalterado");
}
t_eq([200, [$hB['hash']]], $api("$q&grupo=outro"), 'plataforma, grupo=B: inalterado');

require_once CL_ROOT . "/src/SearchService.php";
echo "SearchService: host obrigatório\n";
t_ok(!method_exists(SearchService::class, 'run'), 'SearchService::run removed');
$pr = static fn (string $m, string $n): ReflectionParameter => array_values(array_filter((new ReflectionMethod('SearchService', $m))->getParameters(), static fn ($p) => $p->getName() === $n))[0];
t_ok(!$pr('search', 'host')->isOptional(), 'search(..., mixed $host, ...): sem padrão');

echo "B3: domínio verificado sem ?grupo= força o grupo do domínio\n";
$q = 'localidade=Florian%C3%B3polis';
t_eq([200, [$hA['hash']]], $apiOn($q, 'imob.example.com'), 'domínio de A, sem grupo: só os de A');
t_eq([200, [$hA['hash']]], $apiOn($q, 'IMOB.example.com:8080'), 'domínio de A (maiúsculas e porta), sem grupo: só os de A');
foreach (['desconhecido.example.com', 'pendente.example.com'] as $h) {
    [$st, $ids] = $apiOn($q, $h);
    t_ok($st === 200 && in_array($hA['hash'], $ids, true) && in_array($hB['hash'], $ids, true) && in_array($hNone['hash'], $ids, true), "Host $h, sem grupo: catálogo inteiro (inalterado)");
}
[$st, $ids] = $api($q);
t_ok($st === 200 && in_array($hB['hash'], $ids, true) && in_array($hNone['hash'], $ids, true), 'plataforma, sem grupo: catálogo inteiro (inalterado)');
$r = $onHost('/busca?' . $q, 'imob.example.com', true);
t_eq(200, $r['status'], 'domínio de A: /busca 200');
t_ok(str_contains($r['body'], 'id="search-results"') && !str_contains($r['body'], 'data-api="/api/busca?') , '/busca no domínio usa a mesma /api/busca (filtrada pelo Host)');
t_done();

<?php
// Sprint 3, PR 7: resolvedor único de grupo/tema. Ordem: domínio verificado da requisição -> /g/<slug>/ -> grupo do
// imóvel -> tema da plataforma. C1: só domínio com verified_at. C3: Host normalizado como GrupoValidacao::domain
// (minúsculas, sem porta e ponto final, inválido recusado). Plataforma (APP_URL sem www. e PLATFORM_ROOT_DOMAIN)
// nunca resolve para grupo. Nunca X-Forwarded-Host; Host desconhecido não redireciona.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
require_once CL_ROOT . '/src/GrupoResolvedor.php';
t_temp_db();
$owner = t_seed_owner();
$setTheme = static function (string $t): void {
    db()->prepare('UPDATE settings SET value = ? WHERE skey = ?')->execute([$t, Theme::SETTING]);
    Settings::refresh();
};
$setTheme('padrao');
$admin = ['id' => 1, 'is_admin' => 1, 'status' => 'active'];
GrupoAdmin::create($admin, 'imob', 'Imob', 'contraste');
GrupoAdmin::create($admin, 'outro', 'Outro', 'padrao');
$g = GroupRepository::findBySlug('imob');
$g2 = GroupRepository::findBySlug('outro');
GrupoAdmin::addDomain($admin, $g, 'imob.example.com');
GrupoAdmin::addDomain($admin, $g, 'pendente.example.com');
GrupoAdmin::addDomain($admin, $g2, 'outro.example.com');
db()->exec("UPDATE group_domains SET verified_at = CURRENT_TIMESTAMP WHERE domain IN ('imob.example.com', 'outro.example.com')");
$res = static fn (mixed $host, string $path = '/', ?array $imovel = null): array => (static function () use ($host, $path, $imovel): array {
    GrupoResolvedor::clearCache(); // cada caso vê o banco atual (o cache de doDominio é por requisição)
    $r = GrupoResolvedor::resolver($host, $path, $imovel);
    return [$r['origem'], $r['grupo']['slug'] ?? null, $r['tema']];
})();

echo "normalização do Host (C3)\n";
foreach (['imob.example.com', 'IMOB.Example.COM', 'imob.example.com:8443', 'imob.example.com.', 'Imob.Example.com.:443'] as $h) {
    t_eq('imob.example.com', GrupoValidacao::host($h), 'host normalizado: ' . $h);
}
foreach (['', ' imob.example.com', 'imob.example.com ', 'imob..example.com', '*.example.com', 'imob.example.com:', 'imob.example.com:999999',
    '127.0.0.1', '127.0.0.1:8080', '[::1]:8080', 'imób.example.com', 'imob.example.com/x', 'user@imob.example.com', 'imob.example.com..', null, ['imob.example.com'], 1] as $bad) {
    t_eq(null, GrupoValidacao::host($bad), 'host recusado: ' . json_encode($bad));
}
t_eq('xn--imb-xpa.example.com', GrupoValidacao::host('XN--IMB-XPA.example.com'), 'punycode aceito (IDN cadastra em punycode)');

echo "1. domínio verificado\n";
t_eq(['dominio', 'imob', 'contraste'], $res('imob.example.com'), 'domínio verificado -> grupo e tema dele');
t_eq(['plataforma', null, 'padrao'], $res('imob.example.com:8080.'), 'ponto depois da porta: Host inválido, plataforma');
t_eq(['dominio', 'imob', 'contraste'], $res('Imob.Example.com.:8080'), 'Host com maiúsculas, ponto final e porta');
t_eq(['dominio', 'imob', 'contraste'], $res('imob.example.com', '/g/outro/'), 'domínio vence o /g/<slug>/');
t_eq(['dominio', 'imob', 'contraste'], $res('imob.example.com', '/', ['group_id' => $g2['id'], 'status' => 'active']), 'domínio vence o grupo do imóvel');
t_eq(['plataforma', null, 'padrao'], $res('pendente.example.com'), 'domínio sem verificação não resolve (C1)');
t_eq(['plataforma', null, 'padrao'], $res('x.imob.example.com'), 'subdomínio de domínio verificado não resolve (comparação exata)');
t_eq(['plataforma', null, 'padrao'], $res('desconhecido.example.com'), 'Host desconhecido -> plataforma');
t_eq(['slug', 'outro', 'padrao'], $res('pendente.example.com', '/g/outro/'), 'domínio sem verificação cai no /g/<slug>/');

echo "2. /g/<slug>/\n";
t_eq(['slug', 'imob', 'contraste'], $res(null, '/g/imob/'), '/g/imob/');
t_eq(['slug', 'imob', 'contraste'], $res('127.0.0.1:8080', '/g/imob'), '/g/imob sem barra, Host IP');
t_eq(['slug', 'imob', 'contraste'], $res('127.0.0.1', '/g/imob/busca'), '/g/imob/busca');
t_eq(['slug', 'outro', 'padrao'], $res(null, '/g/outro/', ['group_id' => $g['id']]), 'slug vence o grupo do imóvel');
foreach (['/g/nao-existe/', '/g/IMOB/', '/g/imob-/', '/g/../imob/', '/g//imob/', '/gimob/', '/x/g/imob/', '/g/'] as $p) {
    t_eq(['plataforma', null, 'padrao'], $res(null, $p), 'slug inválido ou inexistente cai: ' . $p);
}

echo "3. grupo do imóvel\n";
t_eq(['imovel', 'imob', 'contraste'], $res(null, '/imoveis/x', ['group_id' => $g['id'], 'status' => 'active']), 'imóvel do grupo');
t_eq(['plataforma', null, 'padrao'], $res(null, '/', ['group_id' => $g['id'], 'status' => 'inactive']), 'imóvel inativo não aplica grupo');
t_eq(['plataforma', null, 'padrao'], $res(null, '/', ['group_id' => null]), 'imóvel sem grupo');
t_eq(['plataforma', null, 'padrao'], $res(null, '/', ['group_id' => 999999]), 'grupo inexistente');

echo "4. plataforma\n";
db()->exec("UPDATE groups SET theme = 'sumiu' WHERE slug = 'imob'");
t_eq(['dominio', 'imob', 'padrao'], $res('imob.example.com'), 'tema do grupo fora da lista: tema da plataforma');
db()->exec("UPDATE groups SET theme = 'contraste' WHERE slug = 'imob'");

echo "plataforma nunca vira grupo (www. e PLATFORM_ROOT_DOMAIN)\n";
// Domínio já gravado que depois passa a ser da plataforma (ex.: troca de APP_URL): o resolvedor ignora.
db()->exec("INSERT INTO group_domains (group_id, domain, verify_token, verified_at) VALUES ({$g['id']}, 'casalivre.com.br', 't', CURRENT_TIMESTAMP), ({$g['id']}, 'app.casalivre.com.br', 't', CURRENT_TIMESTAMP)");
t_eq(['dominio', 'imob', 'contraste'], $res('casalivre.com.br'), 'sem APP_URL da raiz: casalivre.com.br verificado resolve');
putenv('APP_URL=https://www.casalivre.com.br');
t_eq(['plataforma', null, 'padrao'], $res('casalivre.com.br'), 'APP_URL com www.: o domínio pai é da plataforma');
t_eq(['plataforma', null, 'padrao'], $res('www.casalivre.com.br'), 'APP_URL com www.: o próprio www');
t_eq(['plataforma', null, 'padrao'], $res('app.casalivre.com.br'), 'APP_URL com www.: subdomínio do pai');
t_eq(['dominio', 'imob', 'contraste'], $res('imob.example.com'), 'APP_URL com www.: outros domínios seguem');
putenv('APP_URL=https://app.casalivre.com.br');
t_eq(['dominio', 'imob', 'contraste'], $res('casalivre.com.br'), 'APP_URL em app. sem PLATFORM_ROOT_DOMAIN: o pai não é barrado');
t_eq(['plataforma', null, 'padrao'], $res('app.casalivre.com.br'), 'APP_URL em app.: o próprio host');
putenv('PLATFORM_ROOT_DOMAIN=casalivre.com.br');
t_eq(['plataforma', null, 'padrao'], $res('casalivre.com.br'), 'PLATFORM_ROOT_DOMAIN: o pai é da plataforma');
t_eq(['slug', 'imob', 'contraste'], $res('casalivre.com.br', '/g/imob/'), 'Host da plataforma segue para o /g/<slug>/');
putenv('PLATFORM_ROOT_DOMAIN');
putenv('APP_URL');
db()->exec("DELETE FROM group_domains WHERE domain IN ('casalivre.com.br', 'app.casalivre.com.br')");

echo "HTTP: Host, X-Forwarded-Host e imóvel\n";
$base = t_server();
$css = static fn (string $body): ?string => preg_match('#/assets/themes/(contraste|padrao)(?:\.[a-f0-9]+)?\.css#', $body, $m) === 1 ? $m[1] : null;
$r = t_http('GET', $base . '/', null, ['Host: imob.example.com']);
t_eq([200, 'contraste'], [$r['status'], $css($r['body'])], 'Host verificado: página com o tema do grupo');
$r = t_http('GET', $base . '/', null, ['Host: IMOB.example.com.:8080']);
t_eq('contraste', $css($r['body']), 'Host com maiúsculas, ponto final e porta');
$r = t_http('GET', $base . '/', null, ['Host: pendente.example.com']);
t_eq([200, 'padrao'], [$r['status'], $css($r['body'])], 'Host sem verificação: tema da plataforma');
$r = t_http('GET', $base . '/', null, ['Host: desconhecido.example.com']);
t_eq([200, 'padrao', null], [$r['status'], $css($r['body']), c_location($r)], 'Host desconhecido: plataforma, sem redirecionar');
$setTheme('contraste');
t_eq('contraste', $css(t_http('GET', $base . '/', null, ['Host: desconhecido.example.com'])['body']), 'plataforma usa settings.theme');
t_eq('padrao', $css(t_http('GET', $base . '/g/outro/', null, ['Host: desconhecido.example.com'])['body']), '/g/<slug>/ (página ainda é 404 até o PR 8) usa o tema do grupo, não o da plataforma');
$setTheme('padrao');
$r = t_http('GET', $base . '/', null, ['X-Forwarded-Host: imob.example.com']);
t_eq('padrao', $css($r['body']), 'X-Forwarded-Host é ignorado');
$r = t_http('GET', $base . '/', null, ['Host: desconhecido.example.com', 'X-Forwarded-Host: imob.example.com', 'X-Original-Host: imob.example.com']);
t_eq('padrao', $css($r['body']), 'X-Forwarded-Host/X-Original-Host não sobrepõem o Host');
t_eq('padrao', $css(t_http('GET', $base . '/imoveis/' . $owner['hash'])['body']), 'imóvel sem grupo: tema da plataforma');
db()->prepare('UPDATE properties SET group_id = ? WHERE id = ?')->execute([$g['id'], $owner['house_id']]);
ListingSearch::sync((int) $owner['house_id']);
$r = t_http('GET', $base . '/imoveis/' . $owner['hash']);
t_eq([200, 'contraste'], [$r['status'], $css($r['body'])], 'imóvel do grupo: tema do grupo');
t_eq('padrao', $css(t_http('GET', $base . '/imoveis/' . $owner['hash'], null, ['Host: outro.example.com'])['body']), 'domínio verificado de outro grupo vence o imóvel');
t_eq('padrao', $css(t_http('GET', $base . '/imoveis/0000000000000000')['body']), 'imóvel inexistente: plataforma');
t_ok(str_contains(t_http('GET', $base . '/', null, ['Host: imob.example.com'])['headers'], 'Content-Security-Policy'), 'CSP mantida com tema de grupo');
echo "cache por requisição de doDominio (chave = Host normalizado)\n";
GrupoResolvedor::clearCache();
$cacheProp = new ReflectionProperty(GrupoResolvedor::class, 'cacheDominio');
GrupoResolvedor::clearCache();
t_eq([], $cacheProp->getValue(), 'clearCache: cache vazio');
$a = GrupoResolvedor::doDominio('Imob.Example.com.:8443');
$b = GrupoResolvedor::doDominio('imob.example.com');
t_eq(['imob.example.com'], array_keys($cacheProp->getValue()), 'Hosts brutos diferentes, mesma chave normalizada (nunca o Host bruto)');
t_eq($a, $b, 'mesmo grupo nas duas chamadas');
db()->exec("UPDATE group_domains SET verified_at = NULL WHERE domain = 'imob.example.com'");
t_eq('imob', GrupoResolvedor::doDominio('imob.example.com')['slug'] ?? null, 'segunda chamada na mesma requisição vem do cache (sem nova consulta)');
[$noGrupo] = GrupoResolvedor::imovelNoHost('nao-existe', 'imob.example.com');
t_ok($noGrupo && GrupoResolvedor::resolver('imob.example.com', '/')['origem'] === 'dominio', 'imovelNoHost e resolver reaproveitam o cache');
GrupoResolvedor::clearCache();
t_eq(null, GrupoResolvedor::doDominio('imob.example.com'), 'depois de clearCache: consulta de novo e vê o banco atual');
t_eq(['imob.example.com' => null], $cacheProp->getValue(), '"não achou" também fica no cache');
GrupoResolvedor::clearCache();
foreach (['127.0.0.1', '', null, ['x'], 'imob..example.com'] as $bad) {
    GrupoResolvedor::doDominio($bad);
}
t_eq([], $cacheProp->getValue(), 'Host inválido/plataforma não entra no cache');
db()->exec("UPDATE group_domains SET verified_at = CURRENT_TIMESTAMP WHERE domain = 'imob.example.com'");
GrupoResolvedor::clearCache();

t_done();

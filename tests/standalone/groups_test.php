<?php
// Sprint 3, PR 6: modelo de grupos. groups/group_members/group_domains/properties.group_id com aceite do dono.
// Permissões no servidor: admin cria/edita grupo, domínios e membros; gestor convida/remove imóvel; membro só vê;
// dono aceita/recusa/sai. Não-membro, não-dono e grupo inexistente: mesmo 404 (sem enumeração). Todo POST exige
// CSRF; entradas validadas; toda mudança vai para o activity_log (user_id pseudonimizado).
require __DIR__ . '/lib.php';

/** Trigger que aborta o INSERT em group_members do usuário (função + trigger, com SQLSTATE). */
function t_fail_trigger(string $name, int $userId, string $msg, string $pgState): void
{
    db()->exec("CREATE FUNCTION t_$name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.user_id = $userId THEN RAISE EXCEPTION '$msg' USING ERRCODE = '$pgState'; END IF; RETURN NEW; END \$\$");
    db()->exec("CREATE TRIGGER $name BEFORE INSERT ON group_members FOR EACH ROW EXECUTE FUNCTION t_$name()");
}

function t_drop_fail_trigger(string $name): void
{
    db()->exec("DROP TRIGGER $name ON group_members; DROP FUNCTION t_$name()");
}
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
require_once CL_ROOT . '/src/Acoes/GrupoGestor.php';
t_temp_db();
$owner = t_seed_owner();
$act = c_activity_file();
$q = static fn (string $sql, array $p = []): mixed => (static function () use ($sql, $p) {
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
})();
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
$admin = c_user('active');
db()->exec('UPDATE users SET is_admin = 1 WHERE id = ' . (int) $admin['id']);
$gestor = c_user('active');
$membro = c_user('active');
$outro = c_user('active');
$inativo = c_user('inactive');
$houseA = ['id' => $owner['house_id'], 'hash' => $owner['hash']];
$houseB = $house((int) $outro['id'], 'Casa do Outro');
$base = t_server();
$jars = [];
$login = static function (string $email, string $password) use ($base, &$jars): array {
    t_ratelimit_reset();
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $jars[] = $jar;
    return [$jar, t_login($base, $jar, $email, $password)];
};
[$jA, $cA] = $login($admin['email'], $admin['password']);
[$jG, $cG] = $login($gestor['email'], $gestor['password']);
[$jM, $cM] = $login($membro['email'], $membro['password']);
[$jX, $cX] = $login($outro['email'], $outro['password']);
[$jO, $cO] = $login($owner['email'], $owner['password']);
$groupCount = static fn (): int => (int) db()->query('SELECT COUNT(*) FROM groups')->fetchColumn();
$codes = static fn (string $mark, int $code): array => array_values(array_filter(c_logs($act, $mark), static fn ($l) => $l['code'] === $code));
$isGroupCode = static fn (int $c): bool => $c >= ActivityLog::GROUP_CREATE && $c <= ActivityLog::GROUP_DOMAIN_UNVERIFIED;
$noGroupLog = static function (string $mark, string $msg) use ($act, $isGroupCode): void {
    t_eq([], array_values(array_filter(c_codes($act, $mark), $isGroupCode)), $msg . ': sem log de grupo');
};

echo "acesso sem login e sem admin\n";
foreach (['/admin/grupos', '/grupos', '/grupos/qualquer', '/admin/grupos/qualquer'] as $p) {
    $r = t_http('GET', $base . $p);
    t_eq('/entrar', c_location($r), "sem login GET $p: para /entrar");
}
$mark = c_mark($act);
foreach ([[$jG, $cG], [$jO, $cO]] as [$jar, $csrf]) {
    t_eq(403, t_http('GET', $base . '/admin/grupos', null, [], $jar)['status'], 'não-admin GET /admin/grupos: 403');
    t_eq(403, c_form($base, '/admin/grupos', ['_csrf' => $csrf, 'slug' => 'invasor', 'nome' => 'X', 'tema' => 'padrao'], $jar)['status'], 'não-admin POST /admin/grupos: 403');
}
t_eq(0, $groupCount(), 'não-admin não cria grupo');
t_eq('nao_encontrado', GrupoAdmin::create(['id' => $gestor['id'], 'is_admin' => 0, 'status' => 'active'], 'x', 'X', 'padrao')->tipo, 'GrupoAdmin::create recusa não-admin (checagem no serviço)');
t_eq('nao_encontrado', GrupoAdmin::create(['id' => $inativo['id'], 'is_admin' => 1, 'status' => 'inactive'], 'x', 'X', 'padrao')->tipo, 'GrupoAdmin::create recusa admin inativo');
$noGroupLog($mark, 'não-admin');

echo "criação: validação\n";
$mark = c_mark($act);
$bad = [
    [['slug' => 'Maiuscula', 'nome' => 'A', 'tema' => 'padrao'], 'slug com maiúscula'],
    [['slug' => '-hifen', 'nome' => 'A', 'tema' => 'padrao'], 'slug com hífen no início'],
    [['slug' => 'hifen-', 'nome' => 'A', 'tema' => 'padrao'], 'slug com hífen no fim'],
    [['slug' => 'a_b', 'nome' => 'A', 'tema' => 'padrao'], 'slug com _'],
    [['slug' => '../x', 'nome' => 'A', 'tema' => 'padrao'], 'slug com ../'],
    [['slug' => str_repeat('a', 41), 'nome' => 'A', 'tema' => 'padrao'], 'slug com 41'],
    [['slug' => '', 'nome' => 'A', 'tema' => 'padrao'], 'slug vazio'],
    [['slug' => ['a'], 'nome' => 'A', 'tema' => 'padrao'], 'slug array'],
    [['slug' => 'ok', 'nome' => '  ', 'tema' => 'padrao'], 'nome vazio'],
    [['slug' => 'ok', 'nome' => str_repeat('é', 81), 'tema' => 'padrao'], 'nome com 81'],
    [['slug' => 'ok', 'nome' => "a\x01b", 'tema' => 'padrao'], 'nome com controle'],
    [['slug' => 'ok', 'nome' => ['a'], 'tema' => 'padrao'], 'nome array'],
    [['slug' => 'ok', 'nome' => 'A', 'tema' => '../padrao'], 'tema ../'],
    [['slug' => 'ok', 'nome' => 'A', 'tema' => 'inexistente'], 'tema fora do manifesto'],
    [['slug' => 'ok', 'nome' => 'A', 'tema' => 'PADRAO'], 'tema maiúsculo'],
    [['slug' => 'ok', 'nome' => 'A'], 'sem tema'],
];
foreach ($bad as [$f, $label]) {
    t_eq(422, c_form($base, '/admin/grupos', ['_csrf' => $cA] + $f, $jA)['status'], "criar com $label: 422");
}
t_eq(0, $groupCount(), 'entradas inválidas não criam grupo');
$noGroupLog($mark, 'criação inválida');

echo "criação válida e duplicada\n";
$mark = c_mark($act);
$r = c_form($base, '/admin/grupos', ['_csrf' => $cA, 'slug' => 'imob-sul', 'nome' => 'Imob <b>Sul</b>', 'tema' => 'contraste'], $jA);
t_eq(302, $r['status'], 'admin cria grupo: 302');
t_eq('/admin/grupos/imob-sul', c_location($r), 'vai para a página do grupo');
$g1 = GroupRepository::findBySlug('imob-sul');
// A entrada web passa pelo Sanitize do projeto (htmlspecialchars no POST): o nome é gravado já escapado, como os demais campos.
$nomeHtml = htmlspecialchars('Imob <b>Sul</b>', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
t_eq(['imob-sul', $nomeHtml, 'contraste'], [$g1['slug'] ?? null, $g1['name'] ?? null, $g1['theme'] ?? null], 'grupo gravado');
$l = $codes($mark, ActivityLog::GROUP_CREATE);
t_eq(1, count($l), 'um GROUP_CREATE');
t_eq(['imob-sul', 'contraste'], [$l[0]['params']['grupo'] ?? null, $l[0]['params']['tema'] ?? null], 'GROUP_CREATE com grupo e tema');
t_eq(409, c_form($base, '/admin/grupos', ['_csrf' => $cA, 'slug' => 'imob-sul', 'nome' => 'Outro', 'tema' => 'padrao'], $jA)['status'], 'slug repetido: 409');
t_eq(1, $groupCount(), 'slug repetido não cria');
c_form($base, '/admin/grupos', ['_csrf' => $cA, 'slug' => 'rede-norte', 'nome' => 'Rede Norte', 'tema' => 'padrao'], $jA);
$g2 = GroupRepository::findBySlug('rede-norte');
t_ok($g2 !== null, 'segundo grupo criado');
$page = t_http('GET', $base . '/admin/grupos/imob-sul', null, [], $jA);
t_eq(200, $page['status'], 'admin vê o grupo');
t_ok(!str_contains($page['body'], '<b>Sul</b>'), 'nome do grupo escapado no HTML');
t_eq('nao_encontrado', GrupoAdmin::update(['id' => $gestor['id'], 'is_admin' => 0, 'status' => 'active'], $g1, 'X', 'padrao')->tipo, 'GrupoAdmin::update recusa não-admin');
t_eq('ok', GrupoAdmin::create(['id' => $admin['id'], 'is_admin' => 1, 'status' => 'active'], 'direto', '<script>x</script>', 'padrao')->tipo, 'criação direta (sem Sanitize) aceita nome com <');
$dir = t_http('GET', $base . '/admin/grupos', null, [], $jA)['body'];
// pattern do HTML vale com a flag v (Chrome): hífen escapado dentro da classe, nenhum [..-] solto.
t_ok(str_contains($dir, 'pattern="[a-z0-9](?:[a-z0-9\\-]{0,38}[a-z0-9])?"'), 'pattern do slug com hífen escapado (válido com a flag v)');
preg_match_all('/pattern="([^"]*)"/', $dir, $pats);
t_eq([], array_values(array_filter($pats[1], static fn ($p) => preg_match('/(?<!\\\\)-\]/', $p) === 1)), 'nenhum pattern com hífen solto no fim da classe');
t_ok(str_contains($dir, '&lt;script&gt;x&lt;/script&gt;') && !str_contains($dir, '<script>x</script>'), 'nome cru escapado na saída (e())');
db()->exec("DELETE FROM groups WHERE slug = 'direto'");
t_eq(404, t_http('GET', $base . '/admin/grupos/nao-existe', null, [], $jA)['status'], 'admin: grupo inexistente 404');
t_eq(404, t_http('GET', $base . '/admin/grupos/imob-sul/dominios', null, [], $jA)['status'], 'admin: GET em rota de ação 404');

echo "CSRF em todos os POST\n";
$mark = c_mark($act);
$posts = [
    [$jA, '/admin/grupos', ['slug' => 'csrf-x', 'nome' => 'X', 'tema' => 'padrao']],
    [$jA, '/admin/grupos/imob-sul', ['nome' => 'Mudou', 'tema' => 'padrao']],
    [$jA, '/admin/grupos/imob-sul/dominios', ['dominio' => 'csrf.example.com']],
    [$jA, '/admin/grupos/imob-sul/dominios/remover', ['dominio' => 'csrf.example.com']],
    [$jA, '/admin/grupos/imob-sul/membros', ['email' => $gestor['email'], 'papel' => 'gestor']],
    [$jA, '/admin/grupos/imob-sul/membros/remover', ['usuario' => (string) $gestor['id']]],
    [$jG, '/grupos/imob-sul/convites', ['imovel' => $houseA['hash']]],
    [$jG, '/grupos/imob-sul/convites/cancelar', ['imovel' => $houseA['hash']]],
    [$jG, '/grupos/imob-sul/imoveis/remover', ['imovel' => $houseA['hash']]],
    [$jO, '/imoveis/' . $houseA['hash'] . '/grupo/aceitar', ['grupo' => 'imob-sul']],
    [$jO, '/imoveis/' . $houseA['hash'] . '/grupo/recusar', ['grupo' => 'imob-sul']],
    [$jO, '/imoveis/' . $houseA['hash'] . '/grupo/sair', []],
];
foreach ($posts as [$jar, $p, $f]) {
    t_eq(419, c_form($base, $p, $f, $jar)['status'], "POST $p sem _csrf: 419");
    t_eq(419, c_form($base, $p, ['_csrf' => str_repeat('0', 64)] + $f, $jar)['status'], "POST $p com _csrf errado: 419");
}
t_eq($nomeHtml, GroupRepository::findBySlug('imob-sul')['name'], 'sem CSRF: nome não muda');
t_eq(0, (int) $q('SELECT COUNT(*) FROM group_domains'), 'sem CSRF: sem domínio');
t_eq(0, (int) $q('SELECT COUNT(*) FROM group_members'), 'sem CSRF: sem membro');
t_eq(2, $groupCount(), 'sem CSRF: sem grupo novo');
$noGroupLog($mark, 'sem CSRF');

echo "edição\n";
$mark = c_mark($act);
t_eq(422, c_form($base, '/admin/grupos/imob-sul', ['_csrf' => $cA, 'nome' => 'Imob Sul', 'tema' => '../x'], $jA)['status'], 'editar com tema inválido: 422');
t_eq(422, c_form($base, '/admin/grupos/imob-sul', ['_csrf' => $cA, 'nome' => '', 'tema' => 'padrao'], $jA)['status'], 'editar com nome vazio: 422');
t_eq(302, c_form($base, '/admin/grupos/imob-sul', ['_csrf' => $cA, 'nome' => 'Imob Sul', 'tema' => 'padrao', 'slug' => 'trocado'], $jA)['status'], 'editar nome e tema: 302');
$g1 = GroupRepository::findBySlug('imob-sul');
t_eq(['Imob Sul', 'padrao'], [$g1['name'], $g1['theme']], 'nome e tema gravados; slug não muda pela edição');
t_eq(null, GroupRepository::findBySlug('trocado'), 'campo slug na edição é ignorado');
$l = $codes($mark, ActivityLog::GROUP_UPDATE);
t_eq(1, count($l), 'um GROUP_UPDATE');
t_eq([['nome' => $nomeHtml, 'tema' => 'contraste'], ['nome' => 'Imob Sul', 'tema' => 'padrao']], [$l[0]['anterior'], $l[0]['atual']], 'GROUP_UPDATE com anterior/atual');
c_form($base, '/admin/grupos/imob-sul', ['_csrf' => $cA, 'nome' => 'Imob Sul', 'tema' => 'padrao'], $jA);
t_eq(1, count($codes($mark, ActivityLog::GROUP_UPDATE)), 'sem mudança: sem log');

echo "domínios\n";
$mark = c_mark($act);
foreach (['http://imob.example.com', 'imob.example.com:8080', 'imob.example.com/x', 'localhost', '10.0.0.1', 'imob example.com', 'imob..example.com', '-imob.example.com', 'imob.example.com.', '*.example.com', str_repeat('a', 64) . '.com', ''] as $d) {
    t_eq(422, c_form($base, '/admin/grupos/imob-sul/dominios', ['_csrf' => $cA, 'dominio' => $d], $jA)['status'], 'domínio inválido 422: ' . json_encode($d));
}
t_eq(422, t_http('POST', $base . '/admin/grupos/imob-sul/dominios', '_csrf=' . urlencode($cA) . '&dominio[]=a.com', ['Content-Type: application/x-www-form-urlencoded'], $jA)['status'], 'domínio como array: 422');
t_eq(0, (int) $q('SELECT COUNT(*) FROM group_domains'), 'domínios inválidos não gravam');
$noGroupLog($mark, 'domínio inválido');
t_eq(302, c_form($base, '/admin/grupos/imob-sul/dominios', ['_csrf' => $cA, 'dominio' => '  Imob.Example.COM '], $jA)['status'], 'domínio válido: 302');
t_eq(['imob.example.com'], GroupRepository::domains((int) $g1['id']), 'domínio gravado em minúsculas, sem espaços');
t_eq(409, c_form($base, '/admin/grupos/rede-norte/dominios', ['_csrf' => $cA, 'dominio' => 'imob.example.com'], $jA)['status'], 'mesmo domínio em outro grupo: 409');
t_eq([], GroupRepository::domains((int) $g2['id']), 'domínio repetido não grava');
$l = $codes($mark, ActivityLog::GROUP_DOMAIN_ADD);
t_eq(1, count($l), 'um GROUP_DOMAIN_ADD');
t_eq('imob.example.com', $l[0]['params']['dominio'] ?? null, 'GROUP_DOMAIN_ADD com o domínio');
putenv('APP_URL=https://plataforma.casalivre.test');
t_eq(null, GrupoValidacao::domain('Plataforma.CasaLivre.test'), 'domínio da plataforma (APP_URL) recusado');
foreach (['www.plataforma.casalivre.test', 'x.plataforma.casalivre.test', 'a.b.Plataforma.casalivre.test'] as $sub) {
    t_eq(null, GrupoValidacao::domain($sub), 'subdomínio da plataforma recusado: ' . $sub);
}
foreach (['outraplataforma.casalivre.test', 'plataforma.casalivre.test.br', 'casalivre.test', 'xplataforma.casalivre.test'] as $vizinho) {
    t_eq($vizinho, GrupoValidacao::domain($vizinho), 'só o sufixo .host conta, não vizinhos: ' . $vizinho);
}
$adminRow = ['id' => $admin['id'], 'is_admin' => 1, 'status' => 'active'];
t_eq('invalido', GrupoAdmin::addDomain($adminRow, $g2, 'www.plataforma.casalivre.test')->tipo, 'GrupoAdmin::addDomain recusa subdomínio da plataforma');
t_eq([], GroupRepository::domains((int) $g2['id']), 'subdomínio da plataforma não grava');
putenv('APP_URL');
// APP_URL com www.: o domínio pai (e qualquer subdomínio dele) também é da plataforma.
putenv('APP_URL=https://www.casalivre.com.br');
foreach (['casalivre.com.br', 'www.casalivre.com.br', 'imob.casalivre.com.br'] as $p) {
    t_eq(null, GrupoValidacao::domain($p), 'APP_URL com www.: recusado ' . $p);
}
t_eq('outracasalivre.com.br', GrupoValidacao::domain('outracasalivre.com.br'), 'APP_URL com www.: vizinho aceito');
t_eq(['casalivre.com.br'], GrupoValidacao::platformRoots(), 'APP_URL com www.: raiz sem www.');
// PLATFORM_ROOT_DOMAIN: raiz extra (ex.: APP_URL num subdomínio app.) também barra o pai e os subdomínios.
putenv('APP_URL=https://app.casalivre.com.br');
t_eq('casalivre.com.br', GrupoValidacao::domain('casalivre.com.br'), 'sem PLATFORM_ROOT_DOMAIN: o pai de app. não é barrado');
putenv('PLATFORM_ROOT_DOMAIN=CasaLivre.com.br.');
foreach (['casalivre.com.br', 'www.casalivre.com.br', 'app.casalivre.com.br', 'x.casalivre.com.br'] as $p) {
    t_eq(null, GrupoValidacao::domain($p), 'PLATFORM_ROOT_DOMAIN: recusado ' . $p);
}
t_eq(['app.casalivre.com.br', 'casalivre.com.br'], GrupoValidacao::platformRoots(), 'PLATFORM_ROOT_DOMAIN normalizado (minúsculas, sem ponto final)');
putenv('PLATFORM_ROOT_DOMAIN=http://lixo/');
t_eq(['app.casalivre.com.br'], GrupoValidacao::platformRoots(), 'PLATFORM_ROOT_DOMAIN inválido é ignorado');
putenv('PLATFORM_ROOT_DOMAIN');
for ($i = 2; $i <= GrupoValidacao::MAX_DOMAINS; $i++) {
    c_form($base, '/admin/grupos/imob-sul/dominios', ['_csrf' => $cA, 'dominio' => "d$i.example.com"], $jA);
}
t_eq(GrupoValidacao::MAX_DOMAINS, count(GroupRepository::domains((int) $g1['id'])), 'até o limite de domínios');
t_eq(422, c_form($base, '/admin/grupos/imob-sul/dominios', ['_csrf' => $cA, 'dominio' => 'demais.example.com'], $jA)['status'], 'acima do limite: 422');
$mark = c_mark($act);
t_eq(302, c_form($base, '/admin/grupos/imob-sul/dominios/remover', ['_csrf' => $cA, 'dominio' => 'd2.example.com'], $jA)['status'], 'remover domínio: 302');
t_ok(!in_array('d2.example.com', GroupRepository::domains((int) $g1['id']), true), 'domínio removido');
c_form($base, '/admin/grupos/rede-norte/dominios/remover', ['_csrf' => $cA, 'dominio' => 'imob.example.com'], $jA);
t_ok(in_array('imob.example.com', GroupRepository::domains((int) $g1['id']), true), 'remover pelo outro grupo não apaga domínio alheio');
t_eq(1, count($codes($mark, ActivityLog::GROUP_DOMAIN_REMOVE)), 'um GROUP_DOMAIN_REMOVE (o sem efeito não loga)');

echo "membros\n";
$mark = c_mark($act);
$r1 = c_form($base, '/admin/grupos/imob-sul/membros', ['_csrf' => $cA, 'email' => 'ninguem@casalivre.test', 'papel' => 'gestor'], $jA);
$r2 = c_form($base, '/admin/grupos/imob-sul/membros', ['_csrf' => $cA, 'email' => $inativo['email'], 'papel' => 'gestor'], $jA);
t_eq([422, 422], [$r1['status'], $r2['status']], 'e-mail sem conta e conta inativa: 422');
$msg = static fn (string $b): string => preg_match('#<p class="error" role="alert">(.*?)</p>#s', $b, $m) ? $m[1] : '';
t_eq($msg($r1['body']), $msg($r2['body']), 'mesma mensagem para e-mail sem conta e conta inativa');
t_eq(422, c_form($base, '/admin/grupos/imob-sul/membros', ['_csrf' => $cA, 'email' => $gestor['email'], 'papel' => 'dono'], $jA)['status'], 'papel fora da lista: 422');
t_eq(422, c_form($base, '/admin/grupos/imob-sul/membros/remover', ['_csrf' => $cA, 'usuario' => '1 OR 1=1'], $jA)['status'], 'usuário inválido na remoção: 422');
t_eq(0, (int) $q('SELECT COUNT(*) FROM group_members'), 'inválidos não gravam membro');
$noGroupLog($mark, 'membro inválido');
c_form($base, '/admin/grupos/imob-sul/membros', ['_csrf' => $cA, 'email' => strtoupper($gestor['email']), 'papel' => 'membro'], $jA);
c_form($base, '/admin/grupos/imob-sul/membros', ['_csrf' => $cA, 'email' => $gestor['email'], 'papel' => 'gestor'], $jA);
c_form($base, '/admin/grupos/imob-sul/membros', ['_csrf' => $cA, 'email' => $gestor['email'], 'papel' => 'gestor'], $jA);
c_form($base, '/admin/grupos/imob-sul/membros', ['_csrf' => $cA, 'email' => $membro['email'], 'papel' => 'membro'], $jA);
t_eq('gestor', GroupRepository::activeRole((int) $g1['id'], (int) $gestor['id']), 'gestor incluído (e-mail sem diferenciar maiúsculas) e promovido');
t_eq('membro', GroupRepository::activeRole((int) $g1['id'], (int) $membro['id']), 'membro incluído');
$l = $codes($mark, ActivityLog::GROUP_MEMBER_SET);
t_eq(3, count($l), 'três GROUP_MEMBER_SET (incluir, trocar papel, incluir; o repetido não loga)');
t_eq([null, ['papel' => 'membro']], [$l[0]['anterior'], $l[1]['anterior']], 'troca de papel com anterior');
$uidLog = $l[0]['params']['user_id'] ?? null;
t_ok($uidLog === null || preg_match('/^[a-f0-9]{64}$/', (string) $uidLog) === 1, 'user_id nunca em claro no log (HMAC com LOG_SALT ou omitido)');
$adminPage = t_http('GET', $base . '/admin/grupos/imob-sul', null, [], $jA)['body'];
t_ok(str_contains($adminPage, e($gestor['email'])), 'admin vê os membros');

echo "setMember em transação\n";
// Corrida simulada por gatilho: o INSERT do membro bate num UNIQUE (outro save chegou antes) -> 'conflito', sem 500,
// sem gravar, sem log e sem transação pendente; outra falha propaga, também sem transação pendente.
$tx = c_user('active');
$adminRow = ['id' => $admin['id'], 'is_admin' => 1, 'status' => 'active'];
$mark = c_mark($act);
t_fail_trigger('corrida', (int) $tx['id'], 'UNIQUE constraint failed: group_members.group_id, group_members.user_id', '23505');
$r = GrupoAdmin::setMember($adminRow, $g2, $tx['email'], 'gestor');
t_eq('conflito', $r->tipo, 'setMember: UNIQUE na corrida vira conflito');
t_eq([], $r->eventos, 'setMember: conflito sem log');
t_ok(!Db::inTransaction(db()), 'setMember: nenhuma transação pendente após o conflito');
t_drop_fail_trigger('corrida');
t_fail_trigger('falha', (int) $tx['id'], 'falha simulada', 'P0001');
$err = null;
try {
    GrupoAdmin::setMember($adminRow, $g2, $tx['email'], 'gestor');
} catch (PDOException $e) {
    $err = $e;
}
t_drop_fail_trigger('falha');
t_ok($err !== null, 'setMember: outra falha de banco propaga');
t_ok(!Db::inTransaction(db()), 'setMember: nenhuma transação pendente após a falha');
t_eq(null, GroupRepository::storedRole((int) $g2['id'], (int) $tx['id']), 'setMember: nada gravado nas falhas');
t_eq('ok', GrupoAdmin::setMember($adminRow, $g2, $tx['email'], 'gestor')->tipo, 'setMember: sem gatilho grava');
t_eq('gestor', GroupRepository::activeRole((int) $g2['id'], (int) $tx['id']), 'setMember: papel gravado');
db()->prepare('DELETE FROM group_members WHERE user_id = ?')->execute([$tx['id']]);

echo "área do grupo: não-membro e grupo inexistente\n";
$r404 = t_http('GET', $base . '/grupos/nao-existe', null, [], $jX);
$rOut = t_http('GET', $base . '/grupos/imob-sul', null, [], $jX);
t_eq([404, 404], [$r404['status'], $rOut['status']], 'não-membro e inexistente: 404');
t_eq($r404['body'], $rOut['body'], 'mesmo corpo para não-membro e inexistente (sem enumeração)');
t_eq(404, t_http('GET', $base . '/grupos/imob-sul', null, [], $jA)['status'], 'admin que não é membro: 404 na área do grupo');
t_eq(404, t_http('GET', $base . '/grupos/Imob-Sul', null, [], $jG)['status'], 'slug fora do padrão: 404');
$mark = c_mark($act);
$rx1 = c_form($base, '/grupos/imob-sul/convites', ['_csrf' => $cX, 'imovel' => $houseB['hash']], $jX);
$rx2 = c_form($base, '/grupos/nao-existe/convites', ['_csrf' => $cX, 'imovel' => $houseB['hash']], $jX);
t_eq([404, 404], [$rx1['status'], $rx2['status']], 'não-membro convida: 404 (igual a grupo inexistente)');
t_eq($rx1['body'], $rx2['body'], 'mesmo corpo');
t_eq(404, c_form($base, '/grupos/imob-sul/imoveis/remover', ['_csrf' => $cX, 'imovel' => $houseA['hash']], $jX)['status'], 'não-membro remove imóvel: 404');
$rm = t_http('GET', $base . '/grupos/imob-sul', null, [], $jM);
t_eq(200, $rm['status'], 'membro vê o grupo');
t_ok(!str_contains($rm['body'], '/convites'), 'membro não vê o formulário de convite');
t_eq(404, c_form($base, '/grupos/imob-sul/convites', ['_csrf' => $cM, 'imovel' => $houseB['hash']], $jM)['status'], 'membro (sem papel gestor) convida: 404');
t_eq(404, c_form($base, '/grupos/rede-norte/convites', ['_csrf' => $cG, 'imovel' => $houseB['hash']], $jG)['status'], 'gestor de outro grupo: 404');
t_eq(0, (int) $q('SELECT COUNT(*) FROM group_property_invites'), 'sem permissão: nenhum convite');
$noGroupLog($mark, 'sem permissão');

echo "convite pelo gestor\n";
$mark = c_mark($act);
t_eq(422, c_form($base, '/grupos/imob-sul/convites', ['_csrf' => $cG, 'imovel' => 'xyz'], $jG)['status'], 'código mal formado: 422');
$rNo = c_form($base, '/grupos/imob-sul/convites', ['_csrf' => $cG, 'imovel' => 'ffffffffffffffff'], $jG);
t_eq(302, $rNo['status'], 'imóvel inexistente: mesma resposta (302)');
$pNo = t_http('GET', $base . '/grupos/imob-sul', null, [], $jG)['body'];
t_eq(0, (int) $q('SELECT COUNT(*) FROM group_property_invites'), 'imóvel inexistente: sem convite');
$noGroupLog($mark, 'imóvel inexistente');
$rYes = c_form($base, '/grupos/imob-sul/convites', ['_csrf' => $cG, 'imovel' => $houseA['hash']], $jG);
t_eq(302, $rYes['status'], 'convite válido: 302');
$pYes = t_http('GET', $base . '/grupos/imob-sul', null, [], $jG)['body'];
t_ok(str_contains($pNo, e(GrupoGestor::INVITE_SENT)) && str_contains($pYes, e(GrupoGestor::INVITE_SENT)), 'mesma mensagem com e sem imóvel (sem enumeração)');
c_form($base, '/grupos/imob-sul/convites', ['_csrf' => $cG, 'imovel' => $houseA['hash']], $jG);
t_eq(1, (int) $q('SELECT COUNT(*) FROM group_property_invites WHERE group_id = ? AND property_id = ?', [$g1['id'], $houseA['id']]), 'convite gravado uma vez');
t_eq(null, $q('SELECT group_id FROM properties WHERE id = ?', [$houseA['id']]), 'convite não vincula sem aceite');
$l = $codes($mark, ActivityLog::GROUP_INVITE);
t_eq(1, count($l), 'um GROUP_INVITE (repetido não loga)');
t_eq([(int) $houseA['id'], 'imob-sul'], [(int) ($l[0]['params']['imovel_id'] ?? 0), $l[0]['params']['grupo'] ?? null], 'GROUP_INVITE com imóvel e grupo');
db()->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')->execute([$g2['id'], $gestor['id'], 'gestor']);
c_form($base, '/grupos/rede-norte/convites', ['_csrf' => $cG, 'imovel' => $houseA['hash']], $jG);

echo "aceite: só o dono\n";
$mark = c_mark($act);
foreach ([[$jX, $cX, 'outro dono'], [$jG, $cG, 'gestor'], [$jA, $cA, 'admin']] as [$jar, $csrf, $who]) {
    $r = c_form($base, '/imoveis/' . $houseA['hash'] . '/grupo/aceitar', ['_csrf' => $csrf, 'grupo' => 'imob-sul'], $jar);
    t_eq(404, $r['status'], "$who aceita convite de imóvel alheio: 404 (IDOR)");
    t_eq(404, c_form($base, '/imoveis/' . $houseA['hash'] . '/grupo/sair', ['_csrf' => $csrf], $jar)['status'], "$who tira imóvel alheio: 404");
}
t_eq(404, c_form($base, '/imoveis/ffffffffffffffff/grupo/aceitar', ['_csrf' => $cO, 'grupo' => 'imob-sul'], $jO)['status'], 'imóvel inexistente: 404');
t_eq(null, $q('SELECT group_id FROM properties WHERE id = ?', [$houseA['id']]), 'sem o dono, o imóvel não entra no grupo');
foreach (['nao-existe', '../imob-sul', ['imob-sul']] as $s) {
    t_eq(422, c_form($base, '/imoveis/' . $houseA['hash'] . '/grupo/aceitar', ['_csrf' => $cO, 'grupo' => $s], $jO)['status'], 'aceitar grupo inválido/sem convite: 422 ' . json_encode($s));
}
$noGroupLog($mark, 'aceite recusado');
$og = t_http('GET', $base . '/grupos', null, [], $jO)['body'];
t_ok(str_contains($og, 'Imob Sul') && str_contains($og, 'Rede Norte'), 'dono vê os convites');
t_eq(302, c_form($base, '/imoveis/' . $houseA['hash'] . '/grupo/aceitar', ['_csrf' => $cO, 'grupo' => 'imob-sul'], $jO)['status'], 'dono aceita: 302');
t_eq((int) $g1['id'], (int) $q('SELECT group_id FROM properties WHERE id = ?', [$houseA['id']]), 'imóvel no grupo após o aceite');
t_eq(0, (int) $q('SELECT COUNT(*) FROM group_property_invites WHERE group_id = ? AND property_id = ?', [$g1['id'], $houseA['id']]), 'convite consumido');
t_eq(409, c_form($base, '/imoveis/' . $houseA['hash'] . '/grupo/aceitar', ['_csrf' => $cO, 'grupo' => 'rede-norte'], $jO)['status'], 'aceitar outro grupo estando num: 409');
t_eq((int) $g1['id'], (int) $q('SELECT group_id FROM properties WHERE id = ?', [$houseA['id']]), 'continua no primeiro grupo');
t_eq(302, c_form($base, '/imoveis/' . $houseA['hash'] . '/grupo/recusar', ['_csrf' => $cO, 'grupo' => 'rede-norte'], $jO)['status'], 'dono recusa: 302');
t_eq(0, (int) $q('SELECT COUNT(*) FROM group_property_invites'), 'convite recusado apagado');
t_eq([1, 1], [count($codes($mark, ActivityLog::GROUP_INVITE_ACCEPT)), count($codes($mark, ActivityLog::GROUP_INVITE_REJECT))], 'um ACCEPT e um REJECT');
$gp = t_http('GET', $base . '/grupos/imob-sul', null, [], $jM)['body'];
t_ok(str_contains($gp, 'Casa Teste'), 'membro vê o imóvel do grupo');

echo "saída do grupo\n";
$mark = c_mark($act);
t_eq(404, c_form($base, '/grupos/imob-sul/imoveis/remover', ['_csrf' => $cM, 'imovel' => $houseA['hash']], $jM)['status'], 'membro tira imóvel: 404');
t_eq(302, c_form($base, '/grupos/rede-norte/imoveis/remover', ['_csrf' => $cG, 'imovel' => $houseA['hash']], $jG)['status'], 'gestor de outro grupo: sem efeito');
t_eq((int) $g1['id'], (int) $q('SELECT group_id FROM properties WHERE id = ?', [$houseA['id']]), 'imóvel continua no grupo');
$noGroupLog($mark, 'remoção sem efeito');
t_eq(302, c_form($base, '/grupos/imob-sul/imoveis/remover', ['_csrf' => $cG, 'imovel' => $houseA['hash']], $jG)['status'], 'gestor tira imóvel: 302');
t_eq(null, $q('SELECT group_id FROM properties WHERE id = ?', [$houseA['id']]), 'imóvel fora do grupo');
$l = $codes($mark, ActivityLog::GROUP_PROPERTY_REMOVE);
t_eq('gestor', $l[0]['params']['por'] ?? null, 'GROUP_PROPERTY_REMOVE por gestor');
c_form($base, '/grupos/imob-sul/convites', ['_csrf' => $cG, 'imovel' => $houseA['hash']], $jG);
c_form($base, '/imoveis/' . $houseA['hash'] . '/grupo/aceitar', ['_csrf' => $cO, 'grupo' => 'imob-sul'], $jO);
t_eq(302, c_form($base, '/imoveis/' . $houseA['hash'] . '/grupo/sair', ['_csrf' => $cO], $jO)['status'], 'dono tira do grupo: 302');
t_eq(null, $q('SELECT group_id FROM properties WHERE id = ?', [$houseA['id']]), 'imóvel fora do grupo');
$l = $codes($mark, ActivityLog::GROUP_PROPERTY_REMOVE);
t_eq(['gestor', 'dono'], array_map(static fn ($x) => $x['params']['por'] ?? null, $l), 'GROUP_PROPERTY_REMOVE por dono');

echo "convite cancelado e imóvel inativo\n";
$mark = c_mark($act);
c_form($base, '/grupos/imob-sul/convites', ['_csrf' => $cG, 'imovel' => $houseB['hash']], $jG);
t_eq(302, c_form($base, '/grupos/imob-sul/convites/cancelar', ['_csrf' => $cG, 'imovel' => $houseB['hash']], $jG)['status'], 'gestor cancela convite');
t_eq(0, (int) $q('SELECT COUNT(*) FROM group_property_invites'), 'convite cancelado');
t_eq(1, count($codes($mark, ActivityLog::GROUP_INVITE_CANCEL)), 'um GROUP_INVITE_CANCEL');
db()->prepare("UPDATE properties SET status = 'inactive' WHERE id = ?")->execute([$houseB['id']]);
c_form($base, '/grupos/imob-sul/convites', ['_csrf' => $cG, 'imovel' => $houseB['hash']], $jG);
t_eq(0, (int) $q('SELECT COUNT(*) FROM group_property_invites'), 'imóvel inativo: sem convite');

echo "membro removido perde acesso\n";
$mark = c_mark($act);
t_eq(302, c_form($base, '/admin/grupos/imob-sul/membros/remover', ['_csrf' => $cA, 'usuario' => (string) $gestor['id']], $jA)['status'], 'admin remove gestor');
t_eq(1, count($codes($mark, ActivityLog::GROUP_MEMBER_REMOVE)), 'um GROUP_MEMBER_REMOVE');
t_eq(302, c_form($base, '/admin/grupos/imob-sul/membros/remover', ['_csrf' => $cA, 'usuario' => (string) $gestor['id']], $jA)['status'], 'segunda remoção do mesmo membro: 302');
t_eq(1, count($codes($mark, ActivityLog::GROUP_MEMBER_REMOVE)), 'segunda remoção não gera log');
// Corrida: a leitura ainda vê o membro, mas o DELETE não apaga nada (outra requisição chegou antes).
db()->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')->execute([$g1['id'], $gestor['id'], 'membro']);
// BEFORE DELETE que devolve NULL: o DELETE não apaga a linha
db()->exec('CREATE FUNCTION t_outra_remocao() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF OLD.user_id = ' . (int) $gestor['id'] . ' THEN RETURN NULL; END IF; RETURN OLD; END $$');
db()->exec('CREATE TRIGGER outra_remocao BEFORE DELETE ON group_members FOR EACH ROW EXECUTE FUNCTION t_outra_remocao()');
$rr = GrupoAdmin::removeMember(['id' => $admin['id'], 'is_admin' => 1, 'status' => 'active'], $g1, (string) $gestor['id']);
t_drop_fail_trigger('outra_remocao');
t_eq(['ok', false, []], [$rr->tipo, $rr->dados['mudou'] ?? null, $rr->eventos], 'removeMember com DELETE sem linha (corrida): sem log');
db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$g1['id'], $gestor['id']]);
t_eq(404, t_http('GET', $base . '/grupos/imob-sul', null, [], $jG)['status'], 'ex-gestor: 404');
t_eq(404, c_form($base, '/grupos/imob-sul/convites', ['_csrf' => $cG, 'imovel' => $houseA['hash']], $jG)['status'], 'ex-gestor não convida');
db()->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$membro['id']]);
t_eq(null, GroupRepository::activeRole((int) $g1['id'], (int) $membro['id']), 'conta inativa não tem papel');

foreach ($jars as $j) {
    @unlink($j);
}
t_done();

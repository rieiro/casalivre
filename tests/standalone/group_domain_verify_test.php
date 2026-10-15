<?php
// Sprint 3, PR 7 (condição C1 da Segurança): domínio de grupo só vale depois da prova de posse por DNS TXT.
// _casalivre.<domínio> = casalivre-verificacao=<token>. Token no banco, consulta DNS injetável (sem rede no teste),
// verified_at só na checagem certa; novo token zera a verificação; tudo com guarda de admin, CSRF e activity_log.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
require_once CL_ROOT . '/src/DomainReverification.php';
t_temp_db();
t_seed_owner();
$act = c_activity_file();
$admin = c_user('active');
db()->exec('UPDATE users SET is_admin = 1 WHERE id = ' . (int) $admin['id']);
$comum = c_user('active');
$adminRow = ['id' => $admin['id'], 'is_admin' => 1, 'status' => 'active'];
$codes = static fn (int $mark, int $code): array => array_values(array_filter(c_logs($act, $mark), static fn ($l) => $l['code'] === $code));
$dnsCalls = [];
$dns = [];
$fakeDns = new DomainVerification(static function (string $name) use (&$dns, &$dnsCalls): array {
    $dnsCalls[] = $name;
    return $dns[$name] ?? [];
});
// pr64 C2: "Verificar agora" só agenda; o cron (DomainReverification) consulta o DNS. $check = pedido + execução do cron.
$cron = new DomainReverification($fakeDns, static function (): void {
});
$check = static function (array $ator, array $grupo, mixed $dominio) use ($cron): Resultado {
    $r = GrupoAdmin::requestDomainCheck($ator, $grupo, $dominio);
    $r->registrar();
    if ($r->tipo === 'ok') {
        $cron->run();
    }
    return $r;
};
$verified = static fn (array $grupo, string $d): bool => GroupRepository::domainRow((int) $grupo['id'], $d)['verified_at'] !== null;

echo "token no cadastro\n";
t_eq('ok', GrupoAdmin::create($adminRow, 'imob', 'Imob', 'padrao')->tipo, 'grupo criado');
$g = GroupRepository::findBySlug('imob');
t_eq('ok', GrupoAdmin::addDomain($adminRow, $g, 'Imob.Example.com')->tipo, 'domínio incluído');
$row = GroupRepository::domainRow((int) $g['id'], 'imob.example.com');
t_ok(preg_match('/^[a-f0-9]{32}$/', (string) $row['verify_token']) === 1, 'token aleatório de 32 hex gravado');
t_eq(null, $row['verified_at'], 'domínio novo não verificado');
t_eq(null, GroupRepository::findByVerifiedDomain('imob.example.com'), 'não verificado: não resolve');
t_eq('_casalivre.imob.example.com', DomainVerification::txtName('imob.example.com'), 'nome do registro TXT');
$token = (string) $row['verify_token'];
GrupoAdmin::addDomain($adminRow, $g, 'outro.example.com');
t_ok(GroupRepository::domainRow((int) $g['id'], 'outro.example.com')['verify_token'] !== $token, 'cada domínio com o seu token');

echo "checagem sem o TXT\n";
$mark = c_mark($act);
$dnsCalls = [];
$r = GrupoAdmin::requestDomainCheck($adminRow, $g, 'imob.example.com');
t_eq(['ok', []], [$r->tipo, $dnsCalls], 'Verificar agora: ok, sem consulta DNS na requisição');
t_ok(str_contains((string) $r->mensagem, '_casalivre.imob.example.com') && str_contains((string) $r->mensagem, 'casalivre-verificacao=' . $token), 'mensagem com nome e valor do registro');
t_eq(null, $q = db()->query("SELECT last_check_at FROM group_domains WHERE domain = 'imob.example.com'")->fetchColumn() ?: null, 'pedido marca a checagem como pendente');
$cron->run();
t_ok(in_array('_casalivre.imob.example.com', $dnsCalls, true) && array_filter($dnsCalls, static fn ($n) => !str_starts_with($n, '_casalivre.')) === [], 'o cron consulta só TXT _casalivre.<domínio> (pelo resolvedor injetado)');
t_eq(false, $verified($g, 'imob.example.com'), 'sem TXT: não verificado');
$dns['_casalivre.imob.example.com'] = ['casalivre-verificacao=' . str_repeat('0', 32), 'v=spf1 -all', 'casalivre-verificacao=' . $token . 'x'];
$check($adminRow, $g, 'imob.example.com');
t_eq(false, $verified($g, 'imob.example.com'), 'token errado, outro TXT ou com sufixo: não verifica');
$dns['imob.example.com'] = ['casalivre-verificacao=' . $token];
$check($adminRow, $g, 'imob.example.com');
t_eq(false, $verified($g, 'imob.example.com'), 'TXT no próprio domínio (sem _casalivre.) não vale');
$dns['_casalivre.outro.example.com'] = ['casalivre-verificacao=' . $token];
$check($adminRow, $g, 'outro.example.com');
t_eq(false, $verified($g, 'outro.example.com'), 'token de outro domínio não vale');
t_eq(null, GroupRepository::domainRow((int) $g['id'], 'imob.example.com')['verified_at'], 'continua não verificado');
t_eq([], $codes($mark, ActivityLog::GROUP_DOMAIN_VERIFIED), 'falha não loga');

echo "checagem com o TXT\n";
$dns['_casalivre.imob.example.com'][] = '  casalivre-verificacao=' . $token . ' ';
$mark = c_mark($act);
$r = $check($adminRow, $g, 'IMOB.example.com');
t_eq(['ok', true], [$r->tipo, $verified($g, 'imob.example.com')], 'TXT certo (entre outros): verificado pelo cron');
t_ok(GroupRepository::domainRow((int) $g['id'], 'imob.example.com')['verified_at'] !== null, 'verified_at gravado');
t_eq('imob', GroupRepository::findByVerifiedDomain('imob.example.com')['slug'] ?? null, 'verificado: resolve para o grupo');
$l = $codes($mark, ActivityLog::GROUP_DOMAIN_VERIFIED);
t_eq(1, count($l), 'um GROUP_DOMAIN_VERIFIED');
t_eq('imob.example.com', $l[0]['params']['dominio'] ?? null, 'log com o domínio');
$mark = c_mark($act);
$r = $check($adminRow, $g, 'imob.example.com');
t_eq(['ok', []], [$r->tipo, $r->eventos], 'novo pedido de verificado: ok e sem log no pedido');
t_eq([], $codes($mark, ActivityLog::GROUP_DOMAIN_VERIFIED), 'reconfirmação pelo cron: sem log');
unset($dns['_casalivre.imob.example.com']);
$check($adminRow, $g, 'imob.example.com');
t_ok(GroupRepository::findByVerifiedDomain('imob.example.com') !== null, 'falha passageira (1 ausência) não derruba o domínio verificado');

echo "novo token\n";
$mark = c_mark($act);
$r = GrupoAdmin::startDomainVerification($adminRow, $g, 'imob.example.com');
$r->registrar();
t_eq(['ok', true], [$r->tipo, $r->dados['mudou'] ?? null], 'novo token: ok');
$row = GroupRepository::domainRow((int) $g['id'], 'imob.example.com');
t_ok($row['verify_token'] !== $token && preg_match('/^[a-f0-9]{32}$/', (string) $row['verify_token']) === 1, 'token trocado');
t_eq(null, $row['verified_at'], 'novo token zera a verificação');
t_eq([null, 0], [$row['last_check_at'], (int) $row['fail_count']], 'novo token: checagem pendente e contador zerado');
t_eq(null, GroupRepository::findByVerifiedDomain('imob.example.com'), 'sem verificação: não resolve');
t_eq(1, count($codes($mark, ActivityLog::GROUP_DOMAIN_VERIFY_START)), 'um GROUP_DOMAIN_VERIFY_START');
$dns['_casalivre.imob.example.com'] = ['casalivre-verificacao=' . $token];
$check($adminRow, $g, 'imob.example.com');
t_eq(false, $verified($g, 'imob.example.com'), 'token antigo não vale mais');

echo "entradas e permissão\n";
foreach (['nao.cadastrado.com', 'http://imob.example.com', '', null, ['x']] as $bad) {
    t_eq('invalido', GrupoAdmin::requestDomainCheck($adminRow, $g, $bad)->tipo, 'checagem recusa: ' . json_encode($bad));
    t_eq('invalido', GrupoAdmin::startDomainVerification($adminRow, $g, $bad)->tipo, 'novo token recusa: ' . json_encode($bad));
}
GrupoAdmin::create($adminRow, 'outro', 'Outro', 'padrao');
$g2 = GroupRepository::findBySlug('outro');
t_eq('invalido', GrupoAdmin::requestDomainCheck($adminRow, $g2, 'imob.example.com')->tipo, 'domínio de outro grupo: invalido');
$dnsCalls = [];
foreach ([['id' => $comum['id'], 'is_admin' => 0, 'status' => 'active'], ['id' => $admin['id'], 'is_admin' => 1, 'status' => 'inactive']] as $ator) {
    t_eq('nao_encontrado', GrupoAdmin::requestDomainCheck($ator, $g, 'imob.example.com')->tipo, 'sem admin ativo: nao_encontrado (checagem)');
    t_eq('nao_encontrado', GrupoAdmin::startDomainVerification($ator, $g, 'imob.example.com')->tipo, 'sem admin ativo: nao_encontrado (token)');
}
t_eq([], $dnsCalls, 'sem permissão: nem consulta o DNS');

echo "rotas e tela do admin\n";
$base = t_server();
t_ratelimit_reset();
$jA = tempnam(sys_get_temp_dir(), 'jar');
$cA = t_login($base, $jA, $admin['email'], $admin['password']);
$jC = tempnam(sys_get_temp_dir(), 'jar');
$cC = t_login($base, $jC, $comum['email'], $comum['password']);
$html = t_http('GET', $base . '/admin/grupos/imob', null, [], $jA)['body'];
$row = GroupRepository::domainRow((int) $g['id'], 'imob.example.com');
t_ok(str_contains($html, '_casalivre.imob.example.com') && str_contains($html, 'casalivre-verificacao=' . $row['verify_token']), 'tela mostra nome e valor do TXT');
t_ok(str_contains($html, 'não verificado') && str_contains($html, '/admin/grupos/imob/dominios/verificar') && str_contains($html, '/admin/grupos/imob/dominios/token'), 'tela com estado e botões Verificar e Gerar novo token');
preg_match_all('/<script([^>]*)>/i', $html, $sm);
t_eq([], array_values(array_filter($sm[1], static fn (string $a) => !str_contains($a, 'src=') && !str_contains($a, 'application/json'))), 'tela sem script inline (CSP estrita)');
t_ok(!preg_match('/\son[a-z]+\s*=|\sstyle\s*=/i', $html), 'tela sem handler nem style inline');
$mark = c_mark($act);
t_eq(419, c_form($base, '/admin/grupos/imob/dominios/token', ['_csrf' => 'errado', 'dominio' => 'imob.example.com'], $jA)['status'], 'novo token sem CSRF: 419');
t_eq(403, c_form($base, '/admin/grupos/imob/dominios/token', ['_csrf' => $cC, 'dominio' => 'imob.example.com'], $jC)['status'], 'novo token por não-admin: 403');
t_eq(403, c_form($base, '/admin/grupos/imob/dominios/verificar', ['_csrf' => $cC, 'dominio' => 'imob.example.com'], $jC)['status'], 'verificar por não-admin: 403');
t_eq(404, t_http('GET', $base . '/admin/grupos/imob/dominios/verificar', null, [], $jA)['status'], 'GET em verificar: 404');
t_eq($row['verify_token'], GroupRepository::domainRow((int) $g['id'], 'imob.example.com')['verify_token'], 'recusas não trocam o token');
t_eq(302, c_form($base, '/admin/grupos/imob/dominios/token', ['_csrf' => $cA, 'dominio' => 'imob.example.com'], $jA)['status'], 'admin gera novo token: 302');
t_ok(GroupRepository::domainRow((int) $g['id'], 'imob.example.com')['verify_token'] !== $row['verify_token'], 'token trocado pela rota');
t_eq(1, count($codes($mark, ActivityLog::GROUP_DOMAIN_VERIFY_START)), 'rota: um GROUP_DOMAIN_VERIFY_START');
t_eq(422, c_form($base, '/admin/grupos/imob/dominios/token', ['_csrf' => $cA, 'dominio' => 'nao.cadastrado.com'], $jA)['status'], 'domínio fora do grupo: 422');
db()->exec("UPDATE group_domains SET last_check_at = '2026-01-01 00:00:00' WHERE domain = 'imob.example.com'");
$r = c_form($base, '/admin/grupos/imob/dominios/verificar', ['_csrf' => $cA, 'dominio' => 'imob.example.com'], $jA);
t_eq(302, $r['status'], 'Verificar agora pela rota: 302 (agenda, sem DNS na requisição)');
t_eq(null, GroupRepository::domainRow((int) $g['id'], 'imob.example.com')['last_check_at'], 'rota marca a checagem pendente');
t_ok(str_contains(t_http('GET', $base . '/admin/grupos/imob', null, [], $jA)['body'], 'checagem pendente'), 'tela mostra checagem pendente');
// Sem token gravado a checagem nem consulta o DNS: exercita a rota sem tocar a rede.
GrupoAdmin::addDomain($adminRow, $g, 'semtoken.example.com');
db()->exec("UPDATE group_domains SET verify_token = '' WHERE domain = 'semtoken.example.com'");
$r = c_form($base, '/admin/grupos/imob/dominios/verificar', ['_csrf' => $cA, 'dominio' => 'semtoken.example.com'], $jA);
t_eq(422, $r['status'], 'verificar sem TXT pela rota: 422');
t_ok(str_contains($r['body'], 'Registro TXT não encontrado'), 'rota mostra a orientação');
t_eq(null, GroupRepository::domainRow((int) $g['id'], 'semtoken.example.com')['verified_at'], 'rota não verifica sem TXT');
@unlink($jA);
@unlink($jC);
t_done();

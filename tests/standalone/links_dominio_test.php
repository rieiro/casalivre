<?php
// Sprint 3, PR 9: links e e-mails por domínio. GrupoUrl: https://<domínio verificado da lista>/g/<slug>, senão
// APP_URL/g/<slug>. Nunca o Host da requisição (nem X-Forwarded-Host); domínio não verificado, inválido ou da
// plataforma não entra. Usado na página do grupo (admin e gestor) e no aviso de domínio desverificado (e-mail).
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
require_once CL_ROOT . '/src/GrupoUrl.php';
putenv('APP_URL=https://casalivre.example');
t_temp_db();
$owner = t_seed_owner();
$admin = c_user('active');
db()->exec('UPDATE users SET is_admin = 1 WHERE id = ' . (int) $admin['id']);
$gestor = c_user('active');
$adm = ['id' => (int) $admin['id'], 'is_admin' => 1, 'status' => 'active'];
GrupoAdmin::create($adm, 'imob', 'Imob <b>', 'padrao');
GrupoAdmin::create($adm, 'semdominio', 'Sem domínio', 'padrao');
$g = GroupRepository::findBySlug('imob');
$g2 = GroupRepository::findBySlug('semdominio');
GrupoAdmin::setMember($adm, $g, $gestor['email'], 'gestor');
$dom = static function (array $grp, string $d, bool $verified): void {
    db()->prepare('INSERT INTO group_domains (group_id, domain, verify_token, verified_at) VALUES (?, ?, ?, ?)')
        ->execute([$grp['id'], $d, 't' . bin2hex(random_bytes(3)), $verified ? '2026-10-01 00:00:00' : null]);
};
$_SERVER['HTTP_HOST'] = 'evil.example';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.example';

echo "GrupoUrl\n";
t_eq('https://casalivre.example/g/semdominio/', GrupoUrl::home($g2), 'sem domínio: APP_URL/g/<slug>/');
$dom($g, 'pendente.example.com', false);
t_eq('https://casalivre.example/g/imob/', GrupoUrl::home($g), 'domínio não verificado: APP_URL');
$dom($g, 'casalivre.example', true);
t_eq('https://casalivre.example/g/imob/', GrupoUrl::home($g), 'domínio da plataforma na lista: ignorado');
$dom($g, 'IMOB.Example.com', true);
t_eq('https://casalivre.example/g/imob/', GrupoUrl::home($g), 'domínio fora do formato normalizado: ignorado');
$dom($g, 'imob.example.com', true);
$dom($g, 'imob2.example.com', true);
t_eq('https://imob.example.com/g/imob', GrupoUrl::base($g), 'verificado: https://<domínio>/g/<slug> (primeiro por id)');
t_eq('https://imob.example.com/g/imob/', GrupoUrl::home($g), 'home com barra final');
t_ok(!str_contains(GrupoUrl::home($g) . GrupoUrl::home($g2), 'evil'), 'Host e X-Forwarded-Host nunca entram');
db()->exec("UPDATE group_domains SET verified_at = NULL WHERE domain = 'imob.example.com'");
t_eq('https://imob2.example.com/g/imob/', GrupoUrl::home($g), 'primeiro desverificado: o próximo verificado');
db()->exec("UPDATE group_domains SET verified_at = '2026-10-01 00:00:00' WHERE domain = 'imob.example.com'");
putenv('APP_URL=');
$envAntes = getenv('APP_ENV');
putenv('APP_ENV=dev');
t_eq('http://localhost/g/semdominio/', GrupoUrl::home($g2), 'dev sem APP_URL: host fixo, nunca o da requisição');
putenv('APP_ENV=production');
$err = null;
try {
    GrupoUrl::home($g2);
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
t_eq('APP_URL não configurada.', $err, 'produção sem APP_URL: erro claro (não cai no Host)');
putenv($envAntes === false ? 'APP_ENV' : 'APP_ENV=' . $envAntes);
putenv('APP_URL=https://casalivre.example');
unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_HOST']);

echo "e-mail de domínio desverificado\n";
db()->exec("UPDATE group_domains SET last_check_at = '2026-10-02 00:00:00' WHERE domain = 'pendente.example.com'");
[, $body] = Mailer::domainNotice(GroupRepository::unverifiedCheckedDomains());
t_ok(str_contains($body, 'https://casalivre.example/admin/grupos/imob;'), 'admin: link absoluto em APP_URL');
t_ok(str_contains($body, 'vitrine agora em https://imob.example.com/g/imob/'), 'vitrine: domínio verificado que sobrou');

echo "páginas do grupo (admin e gestor)\n";
$base = t_server();
$login = static function (string $email, string $password) use ($base): string {
    t_ratelimit_reset();
    $jar = (string) tempnam(sys_get_temp_dir(), 'jar');
    t_login($base, $jar, $email, $password);
    return $jar;
};
$jA = $login($admin['email'], $admin['password']);
$jG = $login($gestor['email'], $gestor['password']);
foreach ([['/admin/grupos/imob', $jA, 'admin'], ['/grupos/imob', $jG, 'gestor']] as [$p, $jar, $who]) {
    $r = t_http('GET', $base . $p, null, ['X-Forwarded-Host: evil.example', 'X-Original-Host: evil.example'], $jar);
    t_eq(200, $r['status'], "$who: 200");
    t_ok(str_contains($r['body'], 'class="group-storefront" href="https://imob.example.com/g/imob/"'), "$who: link da vitrine no domínio verificado");
    t_ok(!str_contains($r['body'], 'evil.example'), "$who: X-Forwarded-Host não entra no link");
}
$r = t_http('GET', $base . '/admin/grupos/semdominio', null, [], $jA);
t_ok(str_contains($r['body'], 'href="https://casalivre.example/g/semdominio/"'), 'sem domínio: link em APP_URL');
t_done();

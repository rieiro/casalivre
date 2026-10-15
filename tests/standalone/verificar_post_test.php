<?php
// PR #73 (Segurança): HEAD/GET do link /verificar?token= não ativam a conta nem queimam o token (scanners de e-mail).
// O GET só mostra o botão "Ativar conta" (form POST com token oculto + CSRF); o POST com CSRF consome o token.
// HEAD só reaproveita GET de páginas públicas somente leitura (head_permitido); o resto dá 405 sem rodar o handler.
// /senha/redefinir?token= (GET só mostra o form) também não consome o token em GET/HEAD.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
t_temp_db();
$actDir = sys_get_temp_dir() . '/vpost-act-' . bin2hex(random_bytes(4));
@mkdir($actDir, 0700, true);
$actFile = $actDir . '/activity.sqlite';
putenv('ACTIVITY_DB_PATH=' . $actFile);
putenv('LOG_DRIVER=sqlite');
register_shutdown_function(static function () use ($actDir): void {
    foreach (glob($actDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($actDir);
});
ActivityLog::store();
$act = static fn () => new PDO('sqlite:' . $actFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$owner = t_seed_owner();
$pending = static function (string $token, string $expires = '+1 hour'): int {
    $id = UserRepository::insert([
        'name' => 'Pendente', 'cpf' => '52998224725', 'address' => 'Rua A, 1', 'cep' => '88000000', 'street' => 'Rua A',
        'address_number' => '1', 'address_complement' => '', 'phone' => '48999999999',
        'email' => 'pend' . bin2hex(random_bytes(3)) . '@casalivre.test', 'password_hash' => password_make('Casa#123456'),
        'status' => 'pending',
    ]);
    db()->prepare('UPDATE users SET activation_token = ?, activation_expires_at = ? WHERE id = ?')
        ->execute([UserRepository::tokenHash($token), date('Y-m-d H:i:s', strtotime($expires)), $id]);
    return $id;
};
$state = static function (int $id): array {
    $st = db()->prepare('SELECT status, activation_token IS NOT NULL AS tem_token, activated_at FROM users WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return [$r['status'], (int) $r['tem_token'], $r['activated_at']];
};
$verifyLogs = static fn (): int => (int) $act()->query('SELECT COUNT(*) FROM activity_log WHERE action_code = ' . ActivityLog::EMAIL_VERIFY)->fetchColumn();
$base = t_server();
$form = static fn (array $r): string => preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) === 1 ? html_entity_decode($m[1]) : '';
$post = static fn (string $jar, array $data): array => t_http('POST', $base . '/verificar', http_build_query($data), ['Content-Type: application/x-www-form-urlencoded'], $jar);

echo "HEAD e GET do link não ativam\n";
$tok = bin2hex(random_bytes(16));
$id = $pending($tok);
$logs0 = $verifyLogs();
$h = t_http('HEAD', $base . '/verificar?token=' . $tok);
t_eq([405, ''], [$h['status'], $h['body']], 'HEAD /verificar?token=: 405, sem corpo');
t_eq(['pending', 1, null], $state($id), 'depois do HEAD: pendente, token intacto');
$jar = tempnam(sys_get_temp_dir(), 'vjar');
$g = t_http('GET', $base . '/verificar?token=' . $tok, null, [], $jar);
t_eq(200, $g['status'], 'GET /verificar?token=: 200');
t_ok(str_contains($g['body'], 'action="/verificar"') && str_contains($g['body'], 'name="token" value="' . $tok . '"'), 'GET mostra o form POST com o token oculto');
t_ok(str_contains($g['body'], 'Ativar conta') && !str_contains($g['body'], 'Conta ativada'), 'GET: botão "Ativar conta", sem ativar');
t_eq(['pending', 1, null], $state($id), 'depois do GET: pendente, token intacto');
t_eq($logs0, $verifyLogs(), 'HEAD/GET não gravam EMAIL_VERIFY');
$csrf = $form($g);
t_ok($csrf !== '', 'form traz CSRF');

echo "POST sem CSRF, CSRF errado ou token errado não ativam\n";
t_eq(419, $post($jar, ['token' => $tok])['status'], 'POST sem _csrf: 419');
t_eq(419, $post($jar, ['token' => $tok, '_csrf' => 'x' . $csrf])['status'], 'POST com _csrf errado: 419');
t_eq(419, t_http('POST', $base . '/verificar', http_build_query(['token' => $tok, '_csrf' => $csrf]), ['Content-Type: application/x-www-form-urlencoded'])['status'], 'POST com _csrf de outra sessão (sem cookie): 419');
$r = $post($jar, ['token' => strrev($tok), '_csrf' => $csrf]);
t_ok($r['status'] === 200 && str_contains($r['body'], 'Link inválido'), 'POST com token errado: link inválido');
t_eq(['pending', 1, null], $state($id), 'ainda pendente, token intacto');

echo "POST com CSRF válido ativa; repetir não muda nada\n";
$r = $post($jar, ['token' => $tok, '_csrf' => $csrf]);
t_ok($r['status'] === 200 && str_contains($r['body'], 'Conta ativada'), 'POST válido: Conta ativada');
[$s, $t, $at] = $state($id);
t_eq(['active', 0], [$s, $t], 'conta ativa e token consumido');
t_ok($verifyLogs() > $logs0, 'POST válido grava EMAIL_VERIFY (o contador de log funciona)');
$r = $post($jar, ['token' => $tok, '_csrf' => $csrf]);
t_ok(str_contains($r['body'], 'Link inválido'), 'POST repetido: link inválido');
t_eq(['active', 0, $at], $state($id), 'POST repetido não muda a ativação');

echo "token vencido não ativa\n";
$tok2 = bin2hex(random_bytes(16));
$id2 = $pending($tok2, '-1 minute');
$g = t_http('GET', $base . '/verificar?token=' . $tok2, null, [], $jar);
$r = $post($jar, ['token' => $tok2, '_csrf' => $form($g)]);
t_ok(str_contains($r['body'], 'Link inválido'), 'token vencido: link inválido');
t_eq('pending', $state($id2)[0], 'token vencido: continua pendente');

echo "redefinição de senha: GET/HEAD do link não consomem o token\n";
$rt = bin2hex(random_bytes(16));
db()->prepare('UPDATE users SET reset_token = ?, reset_expires_at = ? WHERE id = ?')
    ->execute([UserRepository::tokenHash($rt), date('Y-m-d H:i:s', strtotime('+30 minutes')), $owner['user_id']]);
t_eq(405, t_http('HEAD', $base . '/senha/redefinir?token=' . $rt)['status'], 'HEAD /senha/redefinir?token=: 405');
$g = t_http('GET', $base . '/senha/redefinir?token=' . $rt);
t_ok($g['status'] === 200 && str_contains($g['body'], 'action="/senha/redefinir"'), 'GET /senha/redefinir: só o form');
$st = db()->prepare('SELECT reset_token FROM users WHERE id = ?');
$st->execute([$owner['user_id']]);
t_eq(UserRepository::tokenHash($rt), $st->fetchColumn(), 'token de redefinição intacto depois de HEAD/GET');

echo "domínio do grupo: /verificar continua só no APP_URL\n";
$adm = ['id' => 1, 'is_admin' => 1, 'status' => 'active'];
GrupoAdmin::create($adm, 'gv', 'GV', 'padrao');
db()->prepare('INSERT INTO group_domains (group_id, domain, verify_token, verified_at) VALUES (?, ?, ?, ?)')
    ->execute([GroupRepository::findBySlug('gv')['id'], 'gv.example.com', 't', '2026-10-01 00:00:00']);
t_eq(302, t_http('GET', $base . '/verificar?token=abc', null, ['Host: gv.example.com'])['status'], 'GET /verificar no domínio do grupo: 302 para o APP_URL');
t_eq(404, t_http('POST', $base . '/verificar', 'token=abc', ['Host: gv.example.com', 'Content-Type: application/x-www-form-urlencoded'])['status'], 'POST /verificar no domínio do grupo: 404');

echo "HEAD: lista explícita\n";
foreach (['/', '/busca', '/busca/mapa', '/imoveis/' . $owner['hash'], '/entrar', '/cadastro', '/senha/esqueci', '/api/busca', '/api/cidades?q=flo'] as $p) {
    $g = t_http('GET', $base . $p);
    $h = t_http('HEAD', $base . $p);
    t_eq([$g['status'], ''], [$h['status'], $h['body']], "HEAD $p: mesmo status do GET, sem corpo");
}
echo "HEAD na lista não grava activity_log; GET grava como antes\n";
$allLogs = static fn (): int => (int) $act()->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
$headLista = ['/', '/busca', '/busca/mapa', '/imoveis/' . $owner['hash'], '/entrar', '/cadastro', '/senha/esqueci',
    '/api/busca?localidade=Florian%C3%B3polis', '/api/busca', '/api/cidades?q=flo', '/api/imoveis/' . $owner['hash']];
foreach ($headLista as $p) {
    $a0 = $allLogs();
    $h = t_http('HEAD', $base . $p);
    t_eq($a0, $allLogs(), "HEAD $p ({$h['status']}): nada em activity_log");
}
foreach (['/api/busca?localidade=Florian%C3%B3polis', '/api/imoveis/' . $owner['hash']] as $p) {
    $a0 = $allLogs();
    $g = t_http('GET', $base . $p);
    t_eq(200, $g['status'], "GET $p: 200");
    t_eq($a0 + 1, $allLogs(), "GET $p: 1 linha em activity_log");
}
$icalLogs = static fn (): int => (int) $act()->query('SELECT COUNT(*) FROM activity_log WHERE action_code = ' . ActivityLog::CALENDAR_EXPORT)->fetchColumn();
$c0 = $icalLogs();
foreach (['/verificar', '/senha/redefinir', '/ical/' . $owner['ical_hash'], '/api/ical/' . $owner['ical_hash'], '/conta', '/painel', '/admin/tema',
    '/grupos', '/api/users/eu', '/api/painel', '/imoveis/' . $owner['hash'] . '/calendario', '/nao-existe'] as $p) {
    $h = t_http('HEAD', $base . $p);
    t_eq(405, $h['status'], "HEAD $p: 405");
    t_ok(preg_match('/^Allow:\s*GET/mi', $h['headers']) === 1, "HEAD $p: Allow: GET");
}
t_eq($c0, $icalLogs(), 'HEAD no .ics não grava CALENDAR_EXPORT');
t_eq(200, t_http('GET', $base . '/ical/' . $owner['ical_hash'])['status'], 'GET do .ics: 200');
t_eq($c0 + 1, $icalLogs(), 'GET do .ics grava CALENDAR_EXPORT (o contador de log funciona)');
@unlink($jar);
t_done();

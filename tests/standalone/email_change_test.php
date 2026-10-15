<?php
// Troca de e-mail da conta (#47): senha atual obrigatória (com limite), e-mail só muda na confirmação pelo link de
// uso único enviado ao endereço NOVO (token só em sha256 no banco, com validade), aviso ao endereço ANTIGO,
// session_version+1 na confirmação (sessões antigas caem), CSRF no POST de confirmação, web e API.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
t_temp_db();
require_once CL_ROOT . '/src/MailWorker.php';
require_once CL_ROOT . '/src/Acoes/AccountUpdate.php';
require_once CL_ROOT . '/conta/Geo/CepRepository.php';
CepRepository::ensureTable();
db()->prepare("INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88000000', 'Rua A', 'Centro', 'Florianópolis', 'SC')")->execute();
$owner = t_seed_owner();
putenv('APP_ENV=dev');
putenv('APP_URL=http://127.0.0.1:8080');
putenv('SMTP_HOST');
$dir = sys_get_temp_dir() . '/cl-mail-' . bin2hex(random_bytes(3));
mkdir($dir, 0700);
Mailer::$devLogDir = $dir;
$lock = $dir . '/w.lock';
$act = c_activity_file();
t_ratelimit_reset();
$base = t_server();
$row = static function (int $id): array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $st->closeCursor();
    return $r;
};
$mails = static function () use ($dir, $lock): array {
    MailWorker::run(MailQueues::make(), $lock);
    $dev = (string) @file_get_contents($dir . '/mail-dev.log');
    return array_values(array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", $dev))));
};
$tokenFor = static function (array $lines, string $to): string {
    $tok = '';
    foreach ($lines as $l) {
        if (($l['to'] ?? '') === $to && preg_match('#/conta/email/confirmar\?token=([0-9a-f]{32})#', (string) ($l['link'] ?? '') . ($l['body'] ?? ''), $m)) {
            $tok = $m[1];
        }
    }
    return $tok;
};
// Token errado garantidamente diferente do válido (lição do account_tokens): troca o 1º caractere.
$wrong = static fn (string $t): string => ($t[0] === 'a' ? 'b' : 'a') . substr($t, 1);
$fields = static fn (string $email): array => ['name' => 'Dono', 'cpf' => '52998224725', 'cep' => '88000000', 'address_number' => '1', 'address_complement' => '', 'phone' => '48999999999', 'email' => $email];
$uid = (int) $owner['user_id'];
$old = strtolower($owner['email']);

echo "API: senha atual obrigatória\n";
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$new1 = 'novo1' . bin2hex(random_bytes(3)) . '@casalivre.test';
$m = c_mark($act);
$r = c_api('PUT', $base, '/api/users/eu', $fields($new1), $csrf, $jar);
t_eq(403, $r['status'], 'API: sem senha atual 403');
$r = c_api('PUT', $base, '/api/users/eu', $fields($new1) + ['current_password' => $owner['password'] . 'x'], $csrf, $jar);
t_eq(403, $r['status'], 'API: senha atual errada 403');
t_eq(t('err.current_password'), $r['json']['error'] ?? null, 'API: mensagem err.current_password');
t_eq([], c_codes($act, $m), 'API: senha errada não grava');
t_eq([$old, null], [$row($uid)['email'], $row($uid)['pending_email']], 'DB: nada muda com senha errada');
t_eq(0, (int) db()->query("SELECT COUNT(*) FROM mail_queue WHERE type LIKE 'email_change%'")->fetchColumn(), 'fila: nada enfileirado com senha errada');

echo "e-mail em uso só depois da senha certa\n";
$other = c_user('active');
$r = c_api('PUT', $base, '/api/users/eu', $fields($other['email']) + ['current_password' => 'errada'], $csrf, $jar);
t_eq(403, $r['status'], 'API: e-mail de outra conta + senha errada -> 403 (não revela uso)');
$r = c_api('PUT', $base, '/api/users/eu', $fields($other['email']) + ['current_password' => $owner['password']], $csrf, $jar);
t_eq(409, $r['status'], 'API: e-mail de outra conta + senha certa -> 409');

echo "limite de tentativas\n";
t_ratelimit_reset();
for ($i = 0; $i < AccountUpdate::EMAIL_LIMIT; $i++) {
    c_api('PUT', $base, '/api/users/eu', $fields($new1) + ['current_password' => 'errada' . $i], $csrf, $jar);
}
$r = c_api('PUT', $base, '/api/users/eu', $fields($new1) + ['current_password' => $owner['password']], $csrf, $jar);
t_eq(429, $r['status'], 'API: 6ª tentativa em 15 min -> 429 (mesmo com a senha certa)');
t_ok(preg_match('/^Retry-After: [1-9]\d*\r?$/mi', (string) $r['headers']) === 1, 'API: 429 com Retry-After');
$jarW = tempnam(sys_get_temp_dir(), 'jar');
$csrfW = t_login($base, $jarW, $owner['email'], $owner['password']);
$r = c_form($base, '/conta', ['_csrf' => $csrfW, 'current_password' => $owner['password']] + $fields($new1), $jarW);
t_eq(429, $r['status'], 'web: limite compartilhado -> 429');
t_eq($old, $row($uid)['email'], 'DB: e-mail intacto');
t_ratelimit_reset();
$r = c_api('PUT', $base, '/api/users/eu', ['name' => 'Sem troca'] + $fields($old), $csrf, $jar);
t_eq(200, $r['status'], 'API: atualizar sem trocar e-mail não pede senha');

echo "API: pedido válido não troca na hora\n";
$m = c_mark($act);
$r = c_api('PUT', $base, '/api/users/eu', ['name' => 'Dono X', 'current_password' => $owner['password']] + $fields(strtoupper($new1)), $csrf, $jar);
t_eq(200, $r['status'], 'API: pedido 200');
t_eq(true, $r['json']['email_pendente'] ?? null, 'API: email_pendente=true');
t_eq($old, $r['json']['user']['email'] ?? null, 'API: user ainda com o e-mail antigo');
t_eq([ActivityLog::ACCOUNT_UPDATE, ActivityLog::EMAIL_CHANGE_ASK], c_codes($act, $m), 'API: grava ACCOUNT_UPDATE + EMAIL_CHANGE_ASK');
$u = $row($uid);
t_eq([$old, $new1, 'Dono X'], [$u['email'], $u['pending_email'], $u['name']], 'DB: e-mail igual, pending_email = novo, demais campos salvos');
$lines = $mails();
$tok = $tokenFor($lines, $new1);
t_ok($tok !== '', 'link de confirmação enviado ao endereço NOVO');
$notice = array_values(array_filter($lines, static fn ($l) => ($l['to'] ?? '') === $old && str_contains((string) ($l['subject'] ?? ''), 'troca do e-mail')));
t_eq(1, count($notice), 'aviso enviado ao endereço ANTIGO');
t_ok(!str_contains(json_encode($notice), 'token=') && !str_contains(json_encode($notice), $new1), 'aviso sem token e com o e-mail novo mascarado');
$u = $row($uid);
t_eq(hash('sha256', $tok), $u['email_change_token'], 'DB: guarda só o sha256 do token');
t_ok(!str_contains(json_encode($u), $tok), 'DB: token bruto não aparece na linha');
t_ok((string) $u['email_change_expires_at'] > date('Y-m-d H:i:s'), 'DB: token com validade futura');
$sv = (int) $u['session_version'];

echo "API: confirmação\n";
$jarC = tempnam(sys_get_temp_dir(), 'jar');
$csrfC = c_csrf($base, $jarC);
$r = c_api('POST', $base, '/api/auth/email/confirmar', ['token' => $wrong($tok)], $csrfC, $jarC);
t_eq(422, $r['status'], 'API: token errado 422');
$r = c_api('POST', $base, '/api/auth/email/confirmar', ['token' => ''], $csrfC, $jarC);
t_eq(422, $r['status'], 'API: token vazio 422');
t_eq($old, $row($uid)['email'], 'DB: token errado não troca');
$m = c_mark($act);
$r = c_api('POST', $base, '/api/auth/email/confirmar', ['token' => $tok], $csrfC, $jarC);
t_eq(200, $r['status'], 'API: token certo 200');
t_eq([ActivityLog::EMAIL_CHANGE], c_codes($act, $m), 'API: grava EMAIL_CHANGE');
$u = $row($uid);
t_eq([$new1, null, null, null], [$u['email'], $u['pending_email'], $u['email_change_token'], $u['email_change_expires_at']], 'DB: e-mail trocado e pendências limpas');
t_eq($sv + 1, (int) $u['session_version'], 'DB: session_version+1');
$r = t_http('GET', $base . '/painel', null, [], $jar);
t_eq(302, $r['status'], 'sessão antiga (API) recusada após a troca');
$r = c_api('PUT', $base, '/api/users/eu', $fields($new1), $csrf, $jar);
t_ok($r['status'] === 401 || $r['status'] === 419, 'sessão antiga não acessa a API (' . $r['status'] . ')');
$r = c_api('POST', $base, '/api/auth/email/confirmar', ['token' => $tok], $csrfC, $jarC);
t_eq(422, $r['status'], 'API: reuso do token 422');
$r = c_api('POST', $base, '/api/auth/email/confirmar', ['token' => $tok], '', tempnam(sys_get_temp_dir(), 'jar'));
t_eq(419, $r['status'], 'API: confirmação sem CSRF 419');

echo "web: pedido, expiração e confirmação com CSRF\n";
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $new1, $owner['password']);
$new2 = 'novo2' . bin2hex(random_bytes(3)) . '@casalivre.test';
$r = c_form($base, '/conta', ['_csrf' => $csrf, 'current_password' => 'errada'] + $fields($new2), $jar);
t_eq(200, $r['status'], 'web: senha errada re-renderiza 200');
t_ok(str_contains($r['body'], t('err.current_password')), 'web: mostra err.current_password');
t_eq(null, $row($uid)['pending_email'], 'web: senha errada não deixa pendência');
$r = c_form($base, '/conta', ['_csrf' => $csrf, 'current_password' => $owner['password']] + $fields($new2), $jar);
t_eq(302, $r['status'], 'web: pedido 302');
t_eq('/conta?email=pendente', c_location($r), 'web: redireciona para o aviso');
t_ok(str_contains(t_http('GET', $base . '/conta?email=pendente', null, [], $jar)['body'], t('account.email_pending')), 'web: aviso de pendência na conta');
t_eq([$new1, $new2], [$row($uid)['email'], $row($uid)['pending_email']], 'DB: web também não troca na hora');
$lines = $mails();
$tok2 = $tokenFor($lines, $new2);
t_ok($tok2 !== '' && $tok2 !== $tok, 'web: token novo ao endereço novo');
t_eq(1, count(array_filter($lines, static fn ($l) => ($l['to'] ?? '') === $new1 && str_contains((string) ($l['subject'] ?? ''), 'troca do e-mail'))), 'web: aviso ao endereço antigo');
$jarC = tempnam(sys_get_temp_dir(), 'jar');
$page = t_http('GET', $base . '/conta/email/confirmar?token=' . $tok2, null, [], $jarC);
t_eq(200, $page['status'], 'web: GET do link mostra o formulário');
t_eq($new1, $row($uid)['email'], 'web: GET não troca (pré-visualização de link é inofensiva)');
preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $mm);
$csrfC = $mm[1] ?? '';
$r = c_form($base, '/conta/email/confirmar', ['token' => $tok2], $jarC);
t_eq(419, $r['status'], 'web: POST sem CSRF 419');
$r = c_form($base, '/conta/email/confirmar', ['_csrf' => 'x' . $csrfC, 'token' => $tok2], $jarC);
t_eq(419, $r['status'], 'web: POST com CSRF errado 419');
db()->prepare('UPDATE users SET email_change_expires_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 60), $uid]);
$r = c_form($base, '/conta/email/confirmar', ['_csrf' => $csrfC, 'token' => $tok2], $jarC);
t_eq(422, $r['status'], 'web: token expirado 422');
t_eq($new1, $row($uid)['email'], 'DB: expirado não troca');
db()->prepare('UPDATE users SET email_change_expires_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() + 600), $uid]);
$r = c_form($base, '/conta/email/confirmar', ['_csrf' => $csrfC, 'token' => $wrong($tok2)], $jarC);
t_eq(422, $r['status'], 'web: token errado 422');
$sv = (int) $row($uid)['session_version'];
$r = c_form($base, '/conta/email/confirmar', ['_csrf' => $csrfC, 'token' => $tok2], $jarC);
t_eq(200, $r['status'], 'web: confirmação 200');
t_ok(str_contains($r['body'], t('email.confirm_ok')), 'web: mensagem de sucesso');
t_eq([$new2, $sv + 1], [$row($uid)['email'], (int) $row($uid)['session_version']], 'DB: web troca e incrementa session_version');
t_eq(302, t_http('GET', $base . '/conta', null, [], $jar)['status'], 'web: sessão antiga recusada');
// Limite da confirmação (10 em 15 min, web e API no mesmo balde) já foi exercido acima; zera para o reuso.
t_ratelimit_reset();
$r = c_form($base, '/conta/email/confirmar', ['_csrf' => $csrfC, 'token' => $tok2], $jarC);
t_eq(422, $r['status'], 'web: reuso 422');
t_eq(200, c_api('POST', $base, '/api/sessao', ['email' => $new2, 'password' => $owner['password']], c_csrf($base, $j = tempnam(sys_get_temp_dir(), 'jar')), $j)['status'], 'login com o e-mail novo');

echo "limite da confirmação\n";
t_ratelimit_reset();
$jl = tempnam(sys_get_temp_dir(), 'jar');
$cl = c_csrf($base, $jl);
for ($i = 0; $i < 10; $i++) {
    c_api('POST', $base, '/api/auth/email/confirmar', ['token' => str_repeat('0', 32)], $cl, $jl);
}
t_eq(429, c_api('POST', $base, '/api/auth/email/confirmar', ['token' => str_repeat('0', 32)], $cl, $jl)['status'], 'API: 11ª confirmação em 15 min -> 429');
t_eq(429, c_form($base, '/conta/email/confirmar', ['_csrf' => $cl, 'token' => str_repeat('0', 32)], $jl)['status'], 'web: mesmo balde -> 429');
t_ratelimit_reset();

echo "endereço tomado entre o pedido e a confirmação\n";
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $new2, $owner['password']);
$new3 = 'novo3' . bin2hex(random_bytes(3)) . '@casalivre.test';
c_api('PUT', $base, '/api/users/eu', $fields($new3) + ['current_password' => $owner['password']], $csrf, $jar);
$tok3 = $tokenFor($mails(), $new3);
db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$new3, (int) $other['id']]);
$r = c_api('POST', $base, '/api/auth/email/confirmar', ['token' => $tok3], c_csrf($base, $j = tempnam(sys_get_temp_dir(), 'jar')), $j);
t_eq(422, $r['status'], 'confirmação recusada se o endereço foi tomado');
t_eq($new2, $row($uid)['email'], 'DB: e-mail não muda');

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
t_done();

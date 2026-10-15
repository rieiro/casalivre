<?php
// Caracterização (PR 0 do refactor): login e atualização de conta, web x API.
// Estes testes registram o comportamento ATUAL, inclusive as diferenças entre web e API.
// As diferenças marcadas "SNAPSHOT" são retratos intencionais do estado de hoje, não o comportamento
// desejado: se um refactor mudar alguma delas, o teste deve ser atualizado de propósito, junto com a decisão.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
t_temp_db();
$owner = t_seed_owner();
require_once CL_ROOT . '/conta/Geo/CepRepository.php';
CepRepository::ensureTable();
db()->prepare("INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88000000', 'Rua A', 'Centro', 'Florianópolis', 'SC')")->execute();
$act = c_activity_file();
// SMTP "configurado" só para a fila aceitar e-mails (nenhum worker roda aqui): conta ativações enfileiradas.
putenv('SMTP_HOST=smtp.invalid');
$base = t_server();

$userRow = static fn (int $id): array => db()->query('SELECT * FROM users WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);

echo "login: senha errada\n";
$u = c_user('active');
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = c_csrf($base, $jar);
$m = c_mark($act);
$r = c_form($base, '/entrar', ['_csrf' => $csrf, 'email' => $u['email'], 'password' => 'errada'], $jar);
t_eq(200, $r['status'], 'web: senha errada re-renderiza login (200)');
t_eq(null, c_location($r), 'web: senha errada sem redirect');
t_eq([ActivityLog::LOGIN_FAIL], c_codes($act, $m), 'web: senha errada grava LOGIN_FAIL');
$m = c_mark($act);
$r = c_api('POST', $base, '/api/sessao', ['email' => $u['email'], 'password' => 'errada'], $csrf, $jar);
t_eq(401, $r['status'], 'API: senha errada 401');
t_ok(is_string($r['json']['error'] ?? null), 'API: senha errada devolve {error}');
t_eq([ActivityLog::LOGIN_FAIL], c_codes($act, $m), 'API: senha errada grava LOGIN_FAIL');
t_eq(2, (int) $userRow($u['id'])['failed_logins'], 'DB: failed_logins conta web + API (2)');
$wrongBody = $r['body'];

echo "login: conta bloqueada\n";
$u = c_user('active');
db()->prepare('UPDATE users SET failed_logins = 5 WHERE id = ?')->execute([$u['id']]);
$m = c_mark($act);
$r = c_form($base, '/entrar', ['_csrf' => $csrf, 'email' => $u['email'], 'password' => $u['password']], $jar);
t_eq(200, $r['status'], 'web: bloqueada re-renderiza login (200)');
t_eq([ActivityLog::LOGIN_LOCKED], c_codes($act, $m), 'web: bloqueada grava LOGIN_LOCKED');
$m = c_mark($act);
$r = c_api('POST', $base, '/api/sessao', ['email' => $u['email'], 'password' => $u['password']], $csrf, $jar);
t_eq(423, $r['status'], 'API: bloqueada 423');
t_eq([ActivityLog::LOGIN_LOCKED], c_codes($act, $m), 'API: bloqueada grava LOGIN_LOCKED');

echo "login: conta inativa\n";
$u = c_user('inactive');
$m = c_mark($act);
$r = c_form($base, '/entrar', ['_csrf' => $csrf, 'email' => $u['email'], 'password' => $u['password']], $jar);
t_eq(200, $r['status'], 'web: inativa re-renderiza login (200)');
$logs = c_logs($act, $m);
t_eq([ActivityLog::LOGIN_FAIL], array_column($logs, 'code'), 'web: inativa grava LOGIN_FAIL');
t_eq('inactive', $logs[0]['params']['motivo'] ?? null, 'web: LOGIN_FAIL com motivo=inactive');
$m = c_mark($act);
$r = c_api('POST', $base, '/api/sessao', ['email' => $u['email'], 'password' => $u['password']], $csrf, $jar);
t_eq(403, $r['status'], 'API: inativa 403');
// SNAPSHOT: a API não grava nada no activity_log para conta inativa/apagada; a web grava LOGIN_FAIL.
t_eq([], c_codes($act, $m), 'API: inativa NÃO grava activity_log (diferença intencionalmente registrada)');

echo "login: conta apagada\n";
$u = c_user('apagado');
$m = c_mark($act);
$r = c_form($base, '/entrar', ['_csrf' => $csrf, 'email' => $u['email'], 'password' => $u['password']], $jar);
t_eq(200, $r['status'], 'web: apagada re-renderiza login (200)');
$logs = c_logs($act, $m);
t_eq('apagado', $logs[0]['params']['motivo'] ?? null, 'web: LOGIN_FAIL com motivo=apagado');
$m = c_mark($act);
$r = c_api('POST', $base, '/api/sessao', ['email' => $u['email'], 'password' => $u['password']], $csrf, $jar);
t_eq(403, $r['status'], 'API: apagada 403');
t_eq([], c_codes($act, $m), 'API: apagada NÃO grava activity_log (SNAPSHOT)');

echo "login: conta pendente\n";
$u = c_user('pending');
$m = c_mark($act);
$r = c_form($base, '/entrar', ['_csrf' => $csrf, 'email' => $u['email'], 'password' => $u['password']], $jar);
t_eq(200, $r['status'], 'web: pendente renderiza verify_pending (200)');
t_eq(null, c_location($r), 'web: pendente sem redirect');
t_eq([], c_codes($act, $m), 'web: pendente não grava activity_log');
$m = c_mark($act);
$r = c_api('POST', $base, '/api/sessao', ['email' => $u['email'], 'password' => $u['password']], $csrf, $jar);
t_eq([403, t('err.pending')], [$r['status'], $r['json']['error'] ?? null], 'API: pendente 403 err.pending');
t_eq([], c_codes($act, $m), 'API: pendente não grava activity_log');

// Paridade (PR A): web e API reenviam a ativação pela mesma função (AccountFlow::ensureActivation).
$activations = static function (string $email): int {
    $st = db()->prepare("SELECT COUNT(*) FROM mail_queue WHERE type = 'activation' AND recipient = ?");
    $st->execute([$email]);
    return (int) $st->fetchColumn();
};
// O cooldown da fila (5 min por destinatário) é envelhecido entre as tentativas para isolar o limite por conta.
$ageQueue = static fn () => db()->exec("UPDATE mail_queue SET created_at = '2000-01-01 00:00:00'");
echo "login: conta pendente pela API reenvia a ativação\n";
$u = c_user('pending');
$r = c_api('POST', $base, '/api/sessao', ['email' => $u['email'], 'password' => $u['password']], $csrf, $jar);
t_eq([403, t('err.pending')], [$r['status'], $r['json']['error'] ?? null], 'API: pendente + senha certa: 403 err.pending');
t_eq(1, $activations($u['email']), 'API: pendente + senha certa enfileira a ativação (como a web)');

echo "login: pendente com senha errada não revela o estado nem reenvia\n";
$u = c_user('pending');
$m = c_mark($act);
$r = c_api('POST', $base, '/api/sessao', ['email' => $u['email'], 'password' => 'errada'], $csrf, $jar);
t_eq([401, t('err.bad_login')], [$r['status'], $r['json']['error'] ?? null], 'API: pendente + senha errada: 401 genérico (sem 403)');
t_eq($wrongBody, $r['body'], 'API: pendente + senha errada: corpo igual a senha errada de conta ativa');
$w = c_form($base, '/entrar', ['_csrf' => $csrf, 'email' => $u['email'], 'password' => 'errada'], $jar);
t_ok(str_contains($w['body'], e(t('err.bad_login'))) && !str_contains($w['body'], e(t('err.pending'))), 'web: pendente + senha errada: erro genérico, sem verify_pending');
t_eq(0, $activations($u['email']), 'pendente + senha errada (web e API): nenhuma ativação enfileirada');

echo "login: reenvio de ativação com limite por conta somando web e API\n";
$u = c_user('pending');
$sent = [];
for ($i = 0; $i < 2 * AccountFlow::RESEND_MAX; $i++) {
    $ageQueue();
    if ($i % 2 === 0) {
        $r = c_form($base, '/entrar', ['_csrf' => $csrf, 'email' => $u['email'], 'password' => $u['password']], $jar);
        t_eq(200, $r['status'], "web: pendente tentativa $i: 200 (verify_pending)");
    } else {
        $r = c_api('POST', $base, '/api/sessao', ['email' => $u['email'], 'password' => $u['password']], $csrf, $jar);
        t_eq(403, $r['status'], "API: pendente tentativa $i: 403 (mesma resposta acima do limite)");
    }
    $sent[] = $activations($u['email']);
}
t_eq(AccountFlow::RESEND_MAX, $activations($u['email']), 'web+API: no máximo RESEND_MAX ativações por conta na janela');
t_eq([1, 2, 3, 3, 3, 3], $sent, 'web e API contam no mesmo limite (web, API, web, depois bloqueado)');

echo "login: sucesso\n";
$u = c_user('active');
$m = c_mark($act);
$r = c_form($base, '/entrar', ['_csrf' => $csrf, 'email' => strtoupper($u['email']), 'password' => $u['password']], $jar);
t_eq(302, $r['status'], 'web: sucesso 302');
t_eq('/painel', c_location($r), 'web: sucesso redireciona para /painel');
$logs = c_logs($act, $m);
t_eq([ActivityLog::LOGIN_OK], array_column($logs, 'code'), 'web: sucesso grava LOGIN_OK');
// SNAPSHOT: params.email sai null no activity_log (o e-mail não é gravado em claro); o canal vem em params.canal.
t_eq(['email' => null, 'canal' => 'web'], $logs[0]['params'], 'web: LOGIN_OK params = {email:null, canal:web}');
$jar2 = tempnam(sys_get_temp_dir(), 'jar');
$csrf2 = c_csrf($base, $jar2);
$m = c_mark($act);
$r = c_api('POST', $base, '/api/sessao', ['email' => $u['email'], 'password' => $u['password']], $csrf2, $jar2);
t_eq(200, $r['status'], 'API: sucesso 200');
t_eq($u['email'], $r['json']['user']['email'] ?? null, 'API: sucesso devolve {user}');
$logs = c_logs($act, $m);
t_eq([ActivityLog::LOGIN_OK], array_column($logs, 'code'), 'API: sucesso grava LOGIN_OK');
t_eq('api', $logs[0]['params']['canal'] ?? null, 'API: LOGIN_OK com canal=api');

echo "conta: web\n";
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$other = c_user('active');
$fields = ['name' => 'Dono Novo', 'cpf' => '52998224725', 'cep' => '88000000', 'address_number' => '1', 'address_complement' => '', 'phone' => '48999999999'];
$m = c_mark($act);
$r = c_form($base, '/conta', ['_csrf' => $csrf] + $fields, $jar);
t_eq(302, $r['status'], 'web /conta: sucesso 302');
t_eq('/painel', c_location($r), 'web /conta: redireciona para /painel');
$logs = c_logs($act, $m);
t_eq([ActivityLog::ACCOUNT_UPDATE], array_column($logs, 'code'), 'web /conta: grava ACCOUNT_UPDATE');
t_eq(['campos_alterados' => ['name']], $logs[0]['atual'], 'web /conta: valor_atual lista campos_alterados');
t_eq('Dono Novo', $userRow($owner['user_id'])['name'], 'DB: nome atualizado (web)');
$m = c_mark($act);
$r = c_form($base, '/conta', ['_csrf' => $csrf] + $fields, $jar);
t_eq(302, $r['status'], 'web /conta: sem mudança também 302');
// SNAPSHOT (surpresa): reenviar os mesmos dados ainda grava ACCOUNT_UPDATE, com valor_atual vazio.
$logs = c_logs($act, $m);
t_eq([ActivityLog::ACCOUNT_UPDATE], array_column($logs, 'code'), 'web /conta: sem mudança AINDA grava ACCOUNT_UPDATE');
t_eq(null, $logs[0]['atual'], 'web /conta: sem mudança -> valor_atual null');
$m = c_mark($act);
$r = c_form($base, '/conta', ['_csrf' => $csrf, 'name' => ''] + $fields, $jar);
t_eq(200, $r['status'], 'web /conta: inválido re-renderiza (200)');
// A view ainda não mostra a mensagem (#account-error vazio, fica para outro PR); o texto é da ação, coberto pela API.
t_ok(!str_contains($r['body'], e(t('err.required'))), 'web /conta: não exibe err.required (pede e-mail)');
t_eq([], c_codes($act, $m), 'web /conta: inválido não grava');
$m = c_mark($act);
// Decisão de produto: o e-mail não é editável. Os antigos casos "e-mail em uso" (web 200 / API 409) saíram:
// não há mais caminho que tente gravar e-mail. No lugar, conferimos que um email no POST é ignorado.
$page = t_http('GET', $base . '/conta', null, [], $jar);
t_ok(str_contains($page['body'], e($owner['email'])), 'web /conta: e-mail aparece como texto');
t_ok(preg_match('/<input[^>]*name="email"/', $page['body']) !== 1, 'web /conta: sem input de e-mail no formulário');
$m = c_mark($act);
$r = c_form($base, '/conta', ['_csrf' => $csrf, 'email' => $other['email'], 'name' => 'Dono Web'] + $fields, $jar);
t_eq(302, $r['status'], 'web /conta: POST com email (campo desconhecido) segue como sucesso 302');
t_eq([ActivityLog::ACCOUNT_UPDATE], c_codes($act, $m), 'web /conta: grava só o ACCOUNT_UPDATE dos outros campos');
t_eq([$owner['email'], 'Dono Web'], [$userRow($owner['user_id'])['email'], $userRow($owner['user_id'])['name']], 'DB: e-mail não mudou, nome mudou');
$r = c_form($base, '/conta', ['_csrf' => $csrf, 'email' => 'novo' . bin2hex(random_bytes(3)) . '@casalivre.test'] + $fields, $jar);
t_eq($owner['email'], $userRow($owner['user_id'])['email'], 'DB: e-mail livre no POST web também não muda');

echo "conta: API\n";
$m = c_mark($act);
$r = c_api('PUT', $base, '/api/users/eu', ['name' => 'Dono API'] + $fields, $csrf, $jar);
t_eq(200, $r['status'], 'API PUT /api/users/eu: sucesso 200');
t_eq('Dono API', $r['json']['user']['name'] ?? null, 'API: devolve {user} atualizado');
t_eq([ActivityLog::ACCOUNT_UPDATE], c_codes($act, $m), 'API: grava ACCOUNT_UPDATE');
$m = c_mark($act);
$r = c_api('PUT', $base, '/api/users/eu', ['name' => 'Dono API'] + $fields, $csrf, $jar);
t_eq(200, $r['status'], 'API: sem mudança 200');
t_eq([ActivityLog::ACCOUNT_UPDATE], c_codes($act, $m), 'API: sem mudança AINDA grava ACCOUNT_UPDATE (SNAPSHOT, igual à web)');
$m = c_mark($act);
$r = c_api('PUT', $base, '/api/users/eu', ['name' => ''] + $fields, $csrf, $jar);
t_eq(422, $r['status'], 'API: inválido 422');
t_eq(t('err.profile_required'), $r['json']['error'] ?? null, 'API: perfil sem nome usa err.profile_required');
t_ok(stripos((string) ($r['json']['error'] ?? ''), 'mail') === false, 'API: mensagem do perfil não fala de e-mail');
t_eq([], c_codes($act, $m), 'API: erros não gravam activity_log');
// email no corpo = campo desconhecido: mesmo tratamento de um campo inventado (ignorado, 200).
$r1 = c_api('PUT', $base, '/api/users/eu', ['name' => 'Dono API', 'campo_inventado' => 'x'] + $fields, $csrf, $jar);
$r2 = c_api('PUT', $base, '/api/users/eu', ['name' => 'Dono API', 'email' => $other['email']] + $fields, $csrf, $jar);
$r3 = c_api('PUT', $base, '/api/users/eu', ['name' => 'Dono API', 'email' => 'livre' . bin2hex(random_bytes(3)) . '@casalivre.test'] + $fields, $csrf, $jar);
t_eq([200, 200, 200], [$r1['status'], $r2['status'], $r3['status']], 'API: email no corpo tratado como campo desconhecido (200, igual a campo_inventado)');
t_eq([$r1['json'], $r1['json']], [$r2['json'], $r3['json']], 'API: mesma resposta com e sem email');
t_eq($owner['email'], $r2['json']['user']['email'] ?? null, 'API: user devolvido com o e-mail original');
t_eq($owner['email'], $userRow($owner['user_id'])['email'], 'DB: e-mail não mudou pela API');
t_eq('Dono API', $userRow($owner['user_id'])['name'], 'DB: nome final = Dono API');
$r = c_api('PUT', $base, '/api/users/eu', $fields, $csrf, tempnam(sys_get_temp_dir(), 'jar'));
t_eq(419, $r['status'], 'API: sem sessão e com CSRF de outra sessão 419 (CSRF vem antes da auth)');

echo "cadastro continua com err.required\n";
$jarR = tempnam(sys_get_temp_dir(), 'jar');
$r = c_api('POST', $base, '/api/users', ['name' => '', 'email' => 'x' . bin2hex(random_bytes(3)) . '@casalivre.test', 'password' => 'Casa#123456xY', 'password_confirm' => 'Casa#123456xY'] + $fields, c_csrf($base, $jarR), $jarR);
t_eq([422, t('err.required')], [$r['status'], $r['json']['error'] ?? null], 'cadastro: sem nome -> 422 err.required (mensagem com e-mail)');

t_done();

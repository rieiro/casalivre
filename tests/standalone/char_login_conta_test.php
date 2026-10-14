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
t_eq(403, $r['status'], 'API: pendente 403');
t_eq([], c_codes($act, $m), 'API: pendente não grava activity_log');
// SNAPSHOT: a web chama AccountFlow::ensureActivation (pode reenfileirar o e-mail de ativação); a API só responde 403.

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
$fields = ['name' => 'Dono Novo', 'cpf' => '52998224725', 'cep' => '88000000', 'address_number' => '1', 'address_complement' => '', 'phone' => '48999999999', 'email' => $owner['email']];
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
t_eq([], c_codes($act, $m), 'web /conta: inválido não grava');
$m = c_mark($act);
$r = c_form($base, '/conta', ['_csrf' => $csrf, 'email' => $other['email']] + $fields, $jar);
t_eq(200, $r['status'], 'web /conta: e-mail em uso re-renderiza (200)');
t_eq([], c_codes($act, $m), 'web /conta: e-mail em uso não grava');
t_eq($owner['email'], $userRow($owner['user_id'])['email'], 'DB: e-mail não mudou');

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
$r = c_api('PUT', $base, '/api/users/eu', ['name' => 'Dono API', 'email' => $other['email']] + $fields, $csrf, $jar);
t_eq(409, $r['status'], 'API: e-mail em uso 409');
t_eq([], c_codes($act, $m), 'API: erros não gravam activity_log');
t_eq('Dono API', $userRow($owner['user_id'])['name'], 'DB: nome final = Dono API');
$r = c_api('PUT', $base, '/api/users/eu', $fields, $csrf, tempnam(sys_get_temp_dir(), 'jar'));
t_eq(419, $r['status'], 'API: sem sessão e com CSRF de outra sessão 419 (CSRF vem antes da auth)');

t_done();

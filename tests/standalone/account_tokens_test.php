<?php
// Tokens de ativação/redefinição em sha256 (link leva o bruto) e cadastro sem enumeração:
// e-mail novo e existente recebem a mesma resposta; existente gera só o aviso 'exists' (24 h por destinatário; conta pendente
// recebe a ativação de novo). Uso único atômico dos tokens, session_version, índices, ON CONFLICT e limite por IP na web.
require __DIR__ . '/lib.php';
t_temp_db();
require_once CL_ROOT . '/src/MailWorker.php';
$owner = t_seed_owner();
db()->exec("INSERT OR IGNORE INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88015200', 'Rua Teste', 'Centro', 'Florianópolis', 'SC')");
putenv('APP_ENV=dev');
putenv('APP_URL=http://127.0.0.1:8080');
putenv('SMTP_HOST');
$dir = sys_get_temp_dir() . '/cl-tok-' . bin2hex(random_bytes(3));
mkdir($dir, 0700);
Mailer::$devLogDir = $dir;
$lock = $dir . '/w.lock';
t_ratelimit_reset();
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$page = t_http('GET', $base . '/entrar', null, [], $jar);
preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $mm);
$csrf = $mm[1] ?? '';
$json = ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf];
$pass = 'Casa#' . random_int(100000, 999999) . 'xY';
$user = ['name' => 'Novo Usuário', 'cpf' => '52998224725', 'cep' => '88015-200', 'address_number' => '10',
    'address_complement' => '', 'phone' => '(48) 99999-0000', 'password' => $pass, 'password_confirm' => $pass];
$queued = static function (string $type): int {
    $s = db()->prepare('SELECT COUNT(*) FROM mail_queue WHERE type = ?');
    $s->execute([$type]);
    $n = (int) $s->fetchColumn();
    $s->closeCursor();
    return $n;
};

echo "cadastro: mesma resposta para e-mail novo e existente (API)\n";
$new = 'novo' . bin2hex(random_bytes(3)) . '@casalivre.test';
$t0 = microtime(true);
$rNew = t_http('POST', $base . '/api/users', json_encode($user + ['email' => $new]), $json, $jar);
$tNew = microtime(true) - $t0;
$t0 = microtime(true);
$rOld = t_http('POST', $base . '/api/users', json_encode($user + ['email' => strtoupper($owner['email'])]), $json, $jar);
$tOld = microtime(true) - $t0;
t_eq(201, $rNew['status'], 'novo -> 201');
t_eq([$rNew['status'], $rNew['body']], [$rOld['status'], $rOld['body']], 'existente: status e corpo idênticos');
t_ok(str_contains($rNew['body'], 'Enviamos um link'), 'corpo: verifique o e-mail');
t_ok(!str_contains($rOld['body'], 'email_taken') && !str_contains($rOld['body'], 'cadastrado'), 'sem email_taken');
t_ok($tOld > 0.5 * $tNew, sprintf('tempo semelhante (novo %.3fs, existente %.3fs; hash da senha nos dois)', $tNew, $tOld));
t_eq([1, 1], [$queued('activation'), $queued('exists')], 'fila: activation para o novo, exists para o existente');
$row = db()->query("SELECT * FROM mail_queue WHERE type = 'exists'")->fetchAll(PDO::FETCH_ASSOC)[0] ?? [];
t_eq(strtolower($owner['email']), $row['recipient'], 'aviso enfileirado para o e-mail existente');

echo "cooldown do aviso\n";
$rAgain = t_http('POST', $base . '/api/users', json_encode($user + ['email' => $owner['email']]), $json, $jar);
t_eq([$rNew['status'], $rNew['body']], [$rAgain['status'], $rAgain['body']], 'repetido: mesma resposta');
t_eq(1, $queued('exists'), 'repetido em < 5 min: nenhum aviso novo');

echo "web: mesma tela\n";
$norm = static fn (string $html): string => preg_replace(['/name="_csrf" value="[^"]+"/', '/\d{2}\/\d{2}\/\d{4}[^<]*/'], ['', 'DATA'], $html) ?? $html;
$c = static function () use ($base, $jar): string {
    preg_match('/name="_csrf" value="([^"]+)"/', t_http('GET', $base . '/cadastro', null, [], $jar)['body'], $m);
    return $m[1] ?? '';
};
$w1 = t_http('POST', $base . '/cadastro', http_build_query(['_csrf' => $c(), 'email' => 'web' . bin2hex(random_bytes(3)) . '@casalivre.test'] + $user), ['Content-Type: application/x-www-form-urlencoded'], $jar);
$w2 = t_http('POST', $base . '/cadastro', http_build_query(['_csrf' => $c(), 'email' => $owner['email']] + $user), ['Content-Type: application/x-www-form-urlencoded'], $jar);
t_eq([$w1['status'], $norm($w1['body'])], [$w2['status'], $norm($w2['body'])], 'web: status e página iguais (sem token CSRF/data)');

echo "worker: aviso sem token; ativação com token bruto só no e-mail\n";
$rep = MailWorker::run(MailQueues::make(), $lock);
t_eq(3, $rep['enviados'] ?? null, 'worker envia ativação (API e web) e aviso');
$dev = (string) file_get_contents($dir . '/mail-dev.log');
$lines = array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", $dev)));
$notice = array_values(array_filter($lines, static fn ($l) => ($l['to'] ?? '') === strtolower($owner['email'])))[0] ?? [];
t_ok(str_contains($notice['subject'] ?? '', 'tentou cadastrar'), 'aviso para o existente');
t_ok(!str_contains((string) ($notice['link'] ?? ''), 'token='), 'aviso sem token');
$act = array_values(array_filter($lines, static fn ($l) => ($l['to'] ?? '') === $new))[0] ?? [];
$raw = preg_match('/token=([0-9a-f]{32})/', (string) ($act['link'] ?? ''), $m) === 1 ? $m[1] : '';
t_ok($raw !== '', 'link de ativação com token bruto');
$getUser = static function (string $email): array {
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetchAll(PDO::FETCH_ASSOC);
    return $row[0] ?? [];
};
$u = $getUser($new);
t_eq(hash('sha256', $raw), $u['activation_token'], 'banco guarda sha256 do token');
$dump = json_encode(db()->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC));
t_ok(!str_contains($dump, $raw), 'token bruto em nenhuma coluna de users');
t_ok(!array_key_exists('activation_link', $u) && !array_key_exists('reset_link', $u), 'sem colunas *_link');

echo "verificação\n";
$r = t_http('POST', $base . '/api/auth/verificar', json_encode(['token' => strrev($raw)]), $json, $jar);
t_eq(400, $r['status'], 'token errado -> 400');
$r = t_http('POST', $base . '/api/auth/verificar', json_encode(['token' => $u['activation_token']]), $json, $jar);
t_eq(400, $r['status'], 'o próprio hash do banco não serve como token');
$r = t_http('POST', $base . '/api/auth/verificar', json_encode(['token' => $raw]), $json, $jar);
t_eq(200, $r['status'], 'token bruto válido -> 200');
t_eq('active', $getUser($new)['status'], 'conta ativada');
$r = t_http('POST', $base . '/api/auth/verificar', json_encode(['token' => $raw]), $json, $jar);
t_eq(400, $r['status'], 'reuso do token de ativação recusado');

echo "redefinição de senha\n";
t_http('POST', $base . '/api/auth/senha/esqueci', json_encode(['email' => $owner['email']]), $json, $jar);
MailWorker::run(MailQueues::make(), $lock);
$dev = (string) file_get_contents($dir . '/mail-dev.log');
$rr = preg_match_all('#/senha/redefinir\?token=([0-9a-f]{32})#', $dev, $all) ? end($all[1]) : '';
$o = $getUser($owner['email']);
t_ok($rr !== '' && hash_equals((string) $o['reset_token'], hash('sha256', $rr)), 'reset: banco guarda sha256, e-mail leva o bruto');
$np = 'Nova#' . random_int(100000, 999999) . 'xY';
// Outra sessão aberta antes da redefinição: deve sair depois dela (session_version).
$jar2 = tempnam(sys_get_temp_dir(), 'jar');
t_login($base, $jar2, $owner['email'], $owner['password']);
t_eq(200, t_http('GET', $base . '/painel', null, [], $jar2)['status'], 'outra sessão logada antes do reset');
$v0 = (int) $getUser($owner['email'])['session_version'];
$before = json_encode($getUser($owner['email']));
t_eq(false, UserRepository::consumeReset((int) $o['id'], hash('sha256', 'errado'), date('Y-m-d H:i:s'), 'x'), 'consumeReset com hash errado: false');
db()->prepare('UPDATE users SET reset_expires_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 60), (int) $o['id']]);
t_eq(false, UserRepository::consumeReset((int) $o['id'], hash('sha256', $rr), date('Y-m-d H:i:s'), 'x'), 'consumeReset com token expirado: false');
$r = t_http('POST', $base . '/api/auth/senha/redefinir', json_encode(['token' => $rr, 'password' => $np, 'password_confirm' => $np]), $json, $jar);
t_eq(422, $r['status'], 'reset com token expirado falha');
db()->prepare('UPDATE users SET reset_expires_at = ? WHERE id = ?')->execute([$o['reset_expires_at'], (int) $o['id']]);
t_eq($before, json_encode($getUser($owner['email'])), 'token errado/expirado não muda nenhuma linha');
$r = t_http('POST', $base . '/api/auth/senha/redefinir', json_encode(['token' => ($rr[0] === 'f' ? '0' : 'f') . substr($rr, 1), 'password' => $np, 'password_confirm' => $np]), $json, $jar);
t_eq(422, $r['status'], 'reset com token errado falha');
$r = t_http('POST', $base . '/api/auth/senha/redefinir', json_encode(['token' => $rr, 'password' => $np, 'password_confirm' => $np]), $json, $jar);
t_eq(200, $r['status'], 'reset com token válido');
t_eq(null, $getUser($owner['email'])['reset_token'], 'token apagado após o uso');
$r = t_http('POST', $base . '/api/auth/senha/redefinir', json_encode(['token' => $rr, 'password' => $np, 'password_confirm' => $np]), $json, $jar);
t_eq(422, $r['status'], 'reuso do token de redefinição recusado');
t_eq($v0 + 1, (int) $getUser($owner['email'])['session_version'], 'session_version incrementado no mesmo UPDATE');
$r = t_http('GET', $base . '/painel', null, [], $jar2);
t_ok($r['status'] === 302 && str_contains($r['headers'], '/entrar'), 'outra sessão encerrada após o reset (302 /entrar)');
$jar3 = tempnam(sys_get_temp_dir(), 'jar');
t_login($base, $jar3, $owner['email'], $np);
t_eq(200, t_http('GET', $base . '/painel', null, [], $jar3)['status'], 'login novo com a nova senha funciona');

echo "índices e ON CONFLICT\n";
$idx = array_column(db()->query('PRAGMA index_list(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
t_ok(in_array('idx_users_activation_token', $idx, true) && in_array('idx_users_reset_token', $idx, true), 'schema.sql: índices em activation_token e reset_token');
$pg = (string) file_get_contents(CL_ROOT . '/database/schema.pgsql.sql');
t_ok(str_contains($pg, 'ON users(activation_token) WHERE activation_token IS NOT NULL') && str_contains($pg, 'ON users(reset_token) WHERE reset_token IS NOT NULL'), 'schema.pgsql.sql: índices parciais');
$freshFile = sys_get_temp_dir() . '/cl-mig-' . bin2hex(random_bytes(3)) . '.sqlite';
$fresh = new PDO('sqlite:' . $freshFile);
$fresh->exec((string) file_get_contents(CL_ROOT . '/database/schema.sql'));
$fresh->exec('DROP INDEX idx_users_activation_token');
$fresh->exec('DROP INDEX idx_users_reset_token');
$fresh = null;
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('putenv("DB_DRIVER=sqlite"); putenv("DB_DSN=sqlite:' . $freshFile . '"); require "' . CL_ROOT . '/src/Db.php"; require "' . CL_ROOT . '/src/helpers.php"; db();') . ' 2>&1', $out, $code);
$fresh = new PDO('sqlite:' . $freshFile);
$idx2 = array_column($fresh->query('PRAGMA index_list(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
t_ok(in_array('idx_users_activation_token', $idx2, true) && in_array('idx_users_reset_token', $idx2, true), 'Db::migrate recria os índices' . ($code ? ' ' . implode(' ', $out) : ''));
$fresh = null;
@unlink($freshFile);
t_ok(Db::SCHEMA_VERSION >= 6, 'SCHEMA_VERSION >= 6 (tokens sha256 do #35)');
$f = ['name' => 'X', 'cpf' => '52998224725', 'address' => 'Rua', 'phone' => '48999990000', 'email' => 'conf' . bin2hex(random_bytes(3)) . '@casalivre.test', 'password_hash' => 'h', 'status' => 'pending'];
t_eq([true, false], [UserRepository::insertIfAbsent($f), UserRepository::insertIfAbsent($f)], 'ON CONFLICT(email) DO NOTHING: 1ª cria, 2ª não (rowCount)');
$src = (string) file_get_contents(CL_ROOT . '/conta/Account/AccountFlow.php') . file_get_contents(CL_ROOT . '/conta/Account/UserRepository.php');
t_ok(!str_contains($src, 'catch (PDOException') && str_contains($src, 'ON CONFLICT(email) DO NOTHING'), 'sem catch genérico de PDOException no cadastro');

echo "conta pendente: reenvia a ativação no lugar do aviso\n";
$pend = 'pend' . bin2hex(random_bytes(3)) . '@casalivre.test';
t_http('POST', $base . '/api/users', json_encode($user + ['email' => $pend]), $json, $jar);
MailWorker::run(MailQueues::make(), $lock);
t_http('POST', $base . '/api/users', json_encode($user + ['email' => $pend]), $json, $jar);
$s = db()->prepare("SELECT COUNT(*) FROM mail_queue WHERE type = 'exists' AND cooldown_key = ?");
$s->execute([SqliteMailQueue::cooldownKey('exists', $pend)]);
t_eq(1, (int) $s->fetchColumn(), 'segundo cadastro enfileira exists');
$s->closeCursor();
MailWorker::run(MailQueues::make(), $lock);
$lines = array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($dir . '/mail-dev.log'))));
$toPend = array_values(array_filter($lines, static fn ($l) => ($l['to'] ?? '') === $pend));
t_eq(2, count($toPend), 'dois e-mails para a conta pendente');
t_ok(str_contains($toPend[1]['subject'] ?? '', 'ative sua conta') && str_contains((string) ($toPend[1]['link'] ?? ''), '/verificar?token='), 'o segundo é a ativação, não o aviso');
[$sub] = Mailer::existsNotice();
t_ok(str_contains($sub, 'tentou cadastrar') && str_contains(Mailer::linkMail('reset', 'http://x/y')[1], 'http://x/y'), 'Mailer::existsNotice()/linkMail()');
t_eq('a@b.co', Email::normalize('  A@B.Co '), 'Email::normalize');

echo "intervalo de 24 h do exists\n";
$q = MailQueues::make();
$e24 = 'h24' . bin2hex(random_bytes(3)) . '@casalivre.test';
t_eq(true, $q->push('exists', $e24), '1º exists aceito');
$age = static function (int $sec) use ($e24): void {
    db()->prepare("UPDATE mail_queue SET created_at = ? WHERE cooldown_key = ?")->execute([gmdate('Y-m-d H:i:s', time() - $sec), SqliteMailQueue::cooldownKey('exists', $e24)]);
};
$age(3600);
t_eq(false, $q->push('exists', strtoupper($e24)), 'exists após 1 h: recusado (e-mail normalizado)');
$age(86400 + 60);
t_eq(true, $q->push('exists', $e24), 'exists após 24 h: aceito');
t_eq([86400, 300, 300], [MailQueues::cooldownSeconds('exists'), MailQueues::cooldownSeconds('activation'), MailQueues::cooldownSeconds('reset')], 'intervalos por tipo');

echo "limite por IP nos formulários web\n";
t_ratelimit_reset();
$form = ['Content-Type: application/x-www-form-urlencoded'];
$codes = [];
for ($i = 0; $i < 6; $i++) {
    $codes[] = t_http('POST', $base . '/senha/esqueci', http_build_query(['_csrf' => $c(), 'email' => 'x@casalivre.test']), $form, $jar)['status'];
}
t_ok(!in_array(429, array_slice($codes, 0, 5), true) && $codes[5] === 429, 'POST /senha/esqueci: 5 por 15 min, 6ª -> 429 (' . implode(',', $codes) . ')');
$codes = [];
for ($i = 0; $i < 11; $i++) {
    $codes[] = t_http('POST', $base . '/cadastro', http_build_query(['_csrf' => $c(), 'email' => 'ip' . $i . '@casalivre.test']), $form, $jar)['status'];
}
t_ok(!in_array(429, array_slice($codes, 0, 10), true) && $codes[10] === 429, 'POST /cadastro: 10 por hora, 11ª -> 429 (' . implode(',', $codes) . ')');
$r = t_http('POST', $base . '/cadastro', http_build_query(['_csrf' => $c(), 'email' => 'z@casalivre.test']), $form, $jar);
t_ok((bool) preg_match('/^Retry-After:\s*\d+/mi', $r['headers']), '429 web com Retry-After');
t_eq(200, t_http('GET', $base . '/cadastro', null, [], $jar)['status'], 'GET /cadastro não é limitado');
t_ratelimit_reset();

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
putenv('APP_ENV');
putenv('APP_URL');
t_done();

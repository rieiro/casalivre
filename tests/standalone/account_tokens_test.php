<?php
// Tokens de ativação/redefinição em sha256 (link leva o bruto) e cadastro sem enumeração:
// e-mail novo e existente recebem a mesma resposta; existente gera só o aviso 'exists' (com cooldown).
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

echo "redefinição de senha\n";
t_http('POST', $base . '/api/auth/senha/esqueci', json_encode(['email' => $owner['email']]), $json, $jar);
MailWorker::run(MailQueues::make(), $lock);
$dev = (string) file_get_contents($dir . '/mail-dev.log');
$rr = preg_match_all('#/senha/redefinir\?token=([0-9a-f]{32})#', $dev, $all) ? end($all[1]) : '';
$o = $getUser($owner['email']);
t_ok($rr !== '' && hash_equals((string) $o['reset_token'], hash('sha256', $rr)), 'reset: banco guarda sha256, e-mail leva o bruto');
$np = 'Nova#' . random_int(100000, 999999) . 'xY';
$r = t_http('POST', $base . '/api/auth/senha/redefinir', json_encode(['token' => 'f' . substr($rr, 1), 'password' => $np, 'password_confirm' => $np]), $json, $jar);
t_eq(422, $r['status'], 'reset com token errado falha');
$r = t_http('POST', $base . '/api/auth/senha/redefinir', json_encode(['token' => $rr, 'password' => $np, 'password_confirm' => $np]), $json, $jar);
t_eq(200, $r['status'], 'reset com token válido');
t_eq(null, $getUser($owner['email'])['reset_token'], 'token apagado após o uso');

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
putenv('APP_ENV');
putenv('APP_URL');
t_done();

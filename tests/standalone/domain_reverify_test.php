<?php
// pr64 C1/C2: reverificação dos domínios de grupo pelo cron (DomainReverification, scripts/verify-domains.php).
// Resolvedor DNS falso (sem rede): timeout/SERVFAIL não contam; TXT ausente conta; 3 seguidas ou 72 h sem sucesso
// derrubam (verified_at NULL, log 46, aviso aos admins); sucesso zera o contador.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Acoes/GrupoAdmin.php';
require_once CL_ROOT . '/src/DomainReverification.php';
require_once CL_ROOT . '/src/Mailer.php';
t_temp_db();
$owner = t_seed_owner();
$act = c_activity_file();
$admin = c_user('active');
db()->exec('UPDATE users SET is_admin = 1 WHERE id = ' . (int) $admin['id']);
$admin2 = c_user('active');
db()->exec('UPDATE users SET is_admin = 1 WHERE id = ' . (int) $admin2['id']);
$exAdmin = c_user('inactive');
db()->exec('UPDATE users SET is_admin = 1 WHERE id = ' . (int) $exAdmin['id']);
$adminRow = ['id' => $admin['id'], 'is_admin' => 1, 'status' => 'active'];
GrupoAdmin::create($adminRow, 'imob', 'Imob', 'padrao');
$g = GroupRepository::findBySlug('imob');
GrupoAdmin::addDomain($adminRow, $g, 'imob.example.com');
$row = static fn (): array => GroupRepository::domainRow((int) $g['id'], 'imob.example.com') + (static function () {
    return db()->query("SELECT last_check_at, fail_count FROM group_domains WHERE domain = 'imob.example.com'")->fetch();
})();
$token = (string) $row()['verify_token'];
$txt = 'casalivre-verificacao=' . $token;
// Respostas por nome: lista = resposta do DNS; 'timeout'/'servfail' = consulta falhou (false / exceção).
$answer = [];
$calls = [];
$fake = new DomainVerification(static function (string $name) use (&$answer, &$calls): array|false {
    $calls[] = $name;
    $a = $answer[$name] ?? [];
    if ($a === 'timeout') {
        return false;
    }
    if ($a === 'servfail') {
        throw new RuntimeException('SERVFAIL');
    }
    return $a;
});
$mails = [];
// Aviso falso: recebe só o e-mail (o padrão enfileira domain-notice na mail_queue; o worker monta o texto).
$rev = new DomainReverification($fake, static function (string $to) use (&$mails): void {
    $mails[] = [$to];
});
$codes = static fn (int $mark, int $code): array => array_values(array_filter(c_logs($act, $mark), static fn ($l) => $l['code'] === $code));
$name = '_casalivre.imob.example.com';
$t0 = gmmktime(12, 0, 0, 1, 10, 2027);
$h = 3600;

echo "DomainVerification::check\n";
$answer[$name] = 'timeout';
t_eq(DomainVerification::LOOKUP_FAILED, $fake->check('imob.example.com', $token), 'timeout (false): falha de consulta');
$answer[$name] = 'servfail';
t_eq(DomainVerification::LOOKUP_FAILED, $fake->check('imob.example.com', $token), 'SERVFAIL (exceção): falha de consulta');
$answer[$name] = [];
t_eq(DomainVerification::MISSING, $fake->check('imob.example.com', $token), 'NXDOMAIN/sem TXT: ausente');
$answer[$name] = ['v=spf1 -all', 'casalivre-verificacao=outro'];
t_eq(DomainVerification::MISSING, $fake->check('imob.example.com', $token), 'TXT sem o token: ausente');
$answer[$name] = ['x', ' ' . $txt . ' '];
t_eq(DomainVerification::OK, $fake->check('imob.example.com', $token), 'TXT com o token: ok');
t_eq(true, $fake->matches('imob.example.com', $token), 'matches() segue igual');
$calls = [];
t_eq(DomainVerification::MISSING, $fake->check('imob.example.com', ''), 'token vazio: ausente');
t_eq([], $calls, 'token vazio: sem consulta');

echo "pedido pendente (não verificado)\n";
t_eq(true, DomainReverification::due($row(), $t0), 'domínio novo (last_check_at NULL): vencido');
$answer[$name] = 'timeout';
$r = $rev->run($t0);
t_eq(1, $r['falha_consulta'], 'timeout contado no resumo');
t_eq([null, null, 0], [$row()['verified_at'], $row()['last_check_at'], (int) $row()['fail_count']], 'timeout: nada gravado, continua pendente');
$answer[$name] = [];
$rev->run($t0);
t_eq([null, '2027-01-10 12:00:00', 0], [$row()['verified_at'], $row()['last_check_at'], (int) $row()['fail_count']], 'ausente em não verificado: só last_check_at, sem contar falha');
t_eq(false, DomainReverification::due($row(), $t0 + 48 * $h), 'não verificado já conferido: não volta sozinho');
t_eq(['conferidos' => 0, 'ok' => 0, 'ausente' => 0, 'falha_consulta' => 0, 'desverificados' => 0], $rev->run($t0 + 48 * $h), 'nada vencido');
db()->exec("UPDATE group_domains SET last_check_at = NULL WHERE domain = 'imob.example.com'");
$answer[$name] = [$txt];
$mark = c_mark($act);
$r = $rev->run($t0);
t_eq(1, $r['ok'], 'ok contado');
t_eq(['2027-01-10 12:00:00', '2027-01-10 12:00:00', 0], [$row()['verified_at'], $row()['last_check_at'], (int) $row()['fail_count']], 'TXT certo: verificado pelo cron');
t_eq(1, count($codes($mark, ActivityLog::GROUP_DOMAIN_VERIFIED)), 'um log 45');
t_ok(GroupRepository::findByVerifiedDomain('imob.example.com') !== null, 'resolve depois do cron');

echo "reverificação diária\n";
t_eq(false, DomainReverification::due($row(), $t0 + 22 * $h), 'verificado há 22 h: não vencido');
t_eq(true, DomainReverification::due($row(), $t0 + 23 * $h), 'verificado há 23 h: vencido');
$mark = c_mark($act);
$rev->run($t0 + 24 * $h);
t_eq(['2027-01-11 12:00:00', 0], [$row()['verified_at'], (int) $row()['fail_count']], 'sucesso renova verified_at (última confirmação)');
t_eq([], $codes($mark, ActivityLog::GROUP_DOMAIN_VERIFIED), 'sucesso em verificado: sem log');

echo "3 falhas seguidas\n";
$answer[$name] = [];
$mark = c_mark($act);
$rev->run($t0 + 48 * $h);
t_eq(1, (int) $row()['fail_count'], '1ª ausência: fail_count 1');
$answer[$name] = 'timeout';
$rev->run($t0 + 60 * $h);
$answer[$name] = 'servfail';
$rev->run($t0 + 61 * $h);
t_eq([1, '2027-01-12 12:00:00'], [(int) $row()['fail_count'], $row()['last_check_at']], 'timeout e SERVFAIL não contam nem gravam');
$answer[$name] = [];
$rev->run($t0 + 62 * $h);
t_eq(1, (int) $row()['fail_count'], 'ausência 14 h depois da última checagem: nem confere');
$rev->run($t0 + 72 * $h);
t_eq(2, (int) $row()['fail_count'], '2ª ausência: fail_count 2');
t_ok($row()['verified_at'] !== null && $mails === [], 'com 2 falhas e < 72 h: continua verificado, sem aviso');
$answer[$name] = [$txt];
$rev->run($t0 + 96 * $h);
t_eq([0, '2027-01-14 12:00:00'], [(int) $row()['fail_count'], $row()['verified_at']], 'sucesso zera o contador');
$answer[$name] = [];
foreach ([120, 144] as $hh) {
    $rev->run($t0 + $hh * $h);
}
t_eq(2, (int) $row()['fail_count'], 'duas ausências depois do sucesso');
t_eq([], $codes($mark, ActivityLog::GROUP_DOMAIN_UNVERIFIED), 'ainda sem log 46');
$r = $rev->run($t0 + 150 * $h);
t_eq(0, $r['conferidos'], 'checagem só depois de 23 h');
$r = $rev->run($t0 + 167 * $h);
t_eq(1, $r['desverificados'], '3ª ausência seguida: desverificado');
t_eq([null, 0], [$row()['verified_at'], (int) $row()['fail_count']], 'verified_at NULL e contador zerado');
t_eq(null, GroupRepository::findByVerifiedDomain('imob.example.com'), 'não resolve mais');
$l = $codes($mark, ActivityLog::GROUP_DOMAIN_UNVERIFIED);
t_eq(1, count($l), 'um log 46');
t_eq(['imob', 'imob.example.com', 3, 'ausente'], [$l[0]['params']['grupo'] ?? null, $l[0]['params']['dominio'] ?? null, $l[0]['params']['falhas'] ?? null, $l[0]['params']['motivo'] ?? null], 'log 46 com grupo, domínio, falhas e motivo ausente');
t_eq(46, ActivityLog::GROUP_DOMAIN_UNVERIFIED, 'código 46');
t_eq([$admin['email'], $admin2['email']], array_column($mails, 0), 'aviso por e-mail a cada admin ativo (não ao inativo)');
[$subject, $body] = Mailer::domainNotice(GroupRepository::unverifiedCheckedDomains());
t_ok(str_contains($subject, 'perdeu a verificação') && str_contains($body, 'imob.example.com') && str_contains($body, '_casalivre.imob.example.com') && str_contains($body, '/admin/grupos/imob'), 'texto do aviso (worker) com domínio, registro TXT e caminho do admin');
t_ok(!str_contains($body, $token), 'aviso sem o token');
$mails = [];
$rev->run($t0 + 300 * $h);
t_eq([], $mails, 'não verificado já conferido: sem novo aviso');

echo "72 h sem sucesso\n";
db()->prepare('UPDATE group_domains SET verified_at = ?, last_check_at = ?, fail_count = 0 WHERE domain = ?')->execute(['2027-02-01 00:00:00', '2027-02-01 00:00:00', 'imob.example.com']);
$t1 = gmmktime(0, 0, 0, 2, 1, 2027);
$answer[$name] = 'timeout';
$rev->run($t1 + 24 * $h);
$rev->run($t1 + 48 * $h);
t_ok($row()['verified_at'] !== null, 'só timeouts por 48 h: continua verificado');
$answer[$name] = [];
$mark = c_mark($act);
$rev->run($t1 + 71 * $h);
t_eq([1, '2027-02-01 00:00:00'], [(int) $row()['fail_count'], $row()['verified_at']], '1ª ausência com 71 h: ainda verificado');
$answer[$name] = 'timeout';
$rev->run($t1 + 72 * $h);
t_ok($row()['verified_at'] !== null, '72 h: última checagem há 1 h (não vencida), nem consulta');
$answer[$name] = [];
db()->exec("UPDATE group_domains SET last_check_at = '2027-02-01 00:00:00' WHERE domain = 'imob.example.com'");
$r = $rev->run($t1 + 73 * $h);
t_eq([1, null], [$r['desverificados'], $row()['verified_at']], 'ausência com 73 h sem sucesso (2ª falha): desverificado pela carência');
t_eq(2, $codes($mark, ActivityLog::GROUP_DOMAIN_UNVERIFIED)[0]['params']['falhas'] ?? null, 'log 46 com 2 falhas');
t_eq(2, count($mails), 'aviso enviado');

echo "DNS sem responder por mais de 72 h (pr65 B1)\n";
db()->prepare('UPDATE group_domains SET verified_at = ?, last_check_at = ?, fail_count = 1 WHERE domain = ?')->execute(['2027-04-01 00:00:00', '2027-04-01 00:00:00', 'imob.example.com']);
$t2 = gmmktime(0, 0, 0, 4, 1, 2027);
$mails = [];
$mark = c_mark($act);
$before = $row();
foreach ([23, 24, 40, 47, 48, 60, 71] as $hh) {
    $answer[$name] = $hh % 2 ? 'timeout' : 'servfail';
    $r = $rev->run($t2 + $hh * $h);
    t_eq([1, 0], [$r['falha_consulta'], $r['desverificados']], "falha de consulta com {$hh} h: conta no resumo, não derruba");
}
t_eq([$before['verified_at'], $before['last_check_at'], 1], [$row()['verified_at'], $row()['last_check_at'], (int) $row()['fail_count']], 'falha de consulta < 72 h: nada muda');
t_eq([[], []], [$codes($mark, ActivityLog::GROUP_DOMAIN_UNVERIFIED), $mails], '< 72 h: sem log 46 nem aviso');
$answer[$name] = 'timeout';
$r = $rev->run($t2 + 72 * $h);
t_eq([1, 1], [$r['falha_consulta'], $r['desverificados']], 'timeout com 72 h desde a última confirmação: desverificado');
t_eq([null, '2027-04-04 00:00:00', 0], [$row()['verified_at'], $row()['last_check_at'], (int) $row()['fail_count']], 'verified_at NULL, checagem gravada, contador zerado');
t_eq(null, GroupRepository::findByVerifiedDomain('imob.example.com'), 'não resolve mais');
$l = $codes($mark, ActivityLog::GROUP_DOMAIN_UNVERIFIED);
t_eq([1, 'falha_consulta', 1], [count($l), $l[0]['params']['motivo'] ?? null, $l[0]['params']['falhas'] ?? null], 'um log 46 com motivo falha_consulta');
t_eq([$admin['email'], $admin2['email']], array_column($mails, 0), 'aviso aos admins ativos');
$mails = [];
$answer[$name] = 'servfail';
$rev->run($t2 + 100 * $h);
t_eq([[], 1], [$mails, count($codes($mark, ActivityLog::GROUP_DOMAIN_UNVERIFIED))], 'já desverificado: SERVFAIL depois não repete log nem aviso');
db()->prepare('UPDATE group_domains SET verified_at = ?, last_check_at = NULL, fail_count = 0 WHERE domain = ?')->execute(['2027-04-10 00:00:00', 'imob.example.com']);
$answer[$name] = 'servfail';
$rev->run(gmmktime(0, 0, 0, 4, 13, 2027) - 1);
t_ok($row()['verified_at'] !== null, 'SERVFAIL 1 s antes de 72 h: continua verificado');
$rev->run(gmmktime(0, 0, 0, 4, 13, 2027));
t_eq(null, $row()['verified_at'], 'SERVFAIL com exatamente 72 h: desverificado');

echo "token trocado no meio\n";
db()->prepare('UPDATE group_domains SET verified_at = ?, last_check_at = NULL, fail_count = 2 WHERE domain = ?')->execute(['2027-03-01 00:00:00', 'imob.example.com']);
$mails = [];
$tTroca = gmmktime(0, 0, 0, 3, 1, 2027) + $h;
$swap = new DomainVerification(static function (string $n): array {
    db()->exec("UPDATE group_domains SET verify_token = 'novo', verified_at = NULL WHERE domain = 'imob.example.com'");
    return [];
});
$mark = c_mark($act);
(new DomainReverification($swap, static function () use (&$mails): void {
    $mails[] = 1;
}))->run($tTroca);
t_eq(['novo', null, 2], [$row()['verify_token'], $row()['last_check_at'], (int) $row()['fail_count']], 'admin gerou token no meio: o cron não grava');
t_eq([[], []], [$codes($mark, ActivityLog::GROUP_DOMAIN_UNVERIFIED), $mails], 'sem log nem aviso');

echo "falha do aviso não derruba o cron\n";
db()->prepare('UPDATE group_domains SET verify_token = ?, verified_at = ?, last_check_at = NULL, fail_count = 2 WHERE domain = ?')->execute([$token, '2027-03-01 00:00:00', 'imob.example.com']);
$answer[$name] = [];
$r = (new DomainReverification($fake, static function (): void {
    throw new RuntimeException('smtp');
}))->run($tTroca);
t_eq([1, null], [$r['desverificados'], $row()['verified_at']], 'aviso falhou: desverificado mesmo assim');

echo "aviso pela mail_queue (pr65 C2)\n";
require_once CL_ROOT . '/src/Mailer.php';
t_ok(in_array('domain-notice', MailQueue::TYPES, true), 'domain-notice no mapa de tipos da fila');
db()->exec('DELETE FROM mail_queue');
db()->prepare('UPDATE group_domains SET verify_token = ?, verified_at = ?, last_check_at = NULL, fail_count = 2 WHERE domain = ?')->execute([$token, '2027-05-01 00:00:00', 'imob.example.com']);
$answer[$name] = [];
$envAntes = getenv('APP_ENV');
putenv('APP_ENV=dev'); // dev sem SMTP_HOST: a fila aceita (em produção sem SMTP o enfileiramento é recusado, como nos e-mails de conta)
$r = (new DomainReverification($fake))->run(gmmktime(0, 0, 0, 5, 2, 2027));
putenv($envAntes === false ? 'APP_ENV' : 'APP_ENV=' . $envAntes);
t_eq(1, $r['desverificados'], 'desverificado com o notificador padrão');
$q = db()->query('SELECT type, recipient FROM mail_queue ORDER BY id')->fetchAll(PDO::FETCH_NUM);
t_eq([['domain-notice', $admin['email']], ['domain-notice', $admin2['email']]], $q, 'notificador padrão enfileira domain-notice para cada admin ativo (sem enviar na hora)');
t_eq(false, Mailer::deliverAccountMail('domain-notice', $exAdmin['email']), 'worker: admin inativo não recebe');
t_eq(false, Mailer::deliverAccountMail('domain-notice', $owner['email']), 'worker: não-admin não recebe');
t_eq(false, Mailer::deliverAccountMail('domain-notice', 'ninguem@example.com'), 'worker: e-mail sem conta não recebe');

echo "script\n";
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(CL_ROOT . '/scripts/verify-domains.php'), $o, $code);
t_eq(0, $code, 'scripts/verify-domains.php sem erro de sintaxe');
db()->exec("UPDATE group_domains SET verify_token = ''");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/verify-domains.php') . ' 2>&1', $o2, $code);
t_eq(0, $code, 'script roda (sem domínio com token: nenhuma consulta DNS)');
t_ok(str_contains(implode("\n", $o2), 'conferidos      0'), 'script imprime o resumo');
$lockFile = CL_ROOT . '/storage/domain-verify.lock';
t_eq('600', substr(sprintf('%o', fileperms($lockFile)), -3), 'lock com permissão 0600');
// pr65 C3: com o lock seguro por outra execução, o script sai com 0 sem fazer nada.
db()->prepare("UPDATE group_domains SET verify_token = ?, verified_at = NULL, last_check_at = NULL, fail_count = 0 WHERE domain = 'imob.example.com'")->execute([$token]);
$held = fopen($lockFile, 'c');
t_ok(flock($held, LOCK_EX | LOCK_NB), 'teste segura o lock');
$o3 = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/verify-domains.php') . ' 2>&1', $o3, $code);
t_eq([0, ['outra verificação de domínios em execução']], [$code, $o3], 'segunda execução concorrente: exit 0 e mensagem curta');
t_eq([null, null], [$row()['verified_at'], $row()['last_check_at']], 'segunda execução não conferiu nada (pedido segue pendente)');
flock($held, LOCK_UN);
fclose($held);
t_done();

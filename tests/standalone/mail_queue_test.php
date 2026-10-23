<?php
// Fila de e-mails: payload só (tipo, e-mail); worker gera token/link; limpeza após ack/desistência;
// cooldown; usuário inexistente não recebe nada; backoff/desistência; purga; lock (0600, flock); teto de tempo do lote.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/MailWorker.php';
require_once CL_ROOT . '/conta/Account/AccountFlow.php';
t_temp_db();
foreach (['APP_ENV', 'APP_URL', 'SMTP_HOST', 'QUEUE_DRIVER', 'MAIL_QUEUE_RETENTION_DAYS'] as $k) {
    putenv($k);
}
$q = MailQueues::make();
$row = static function (int $id): array {
    $st = db()->prepare('SELECT * FROM mail_queue WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$lastId = static fn (): int => (int) db()->query('SELECT MAX(id) FROM mail_queue')->fetchColumn();
$delay = static fn (array $r): int => strtotime($r['next_attempt_at'] . ' UTC') - time();
$lock = sys_get_temp_dir() . '/mwlock-' . bin2hex(random_bytes(3));
$okDeliver = static fn (): bool => true;

echo "fábrica\n";
t_ok($q instanceof DbMailQueue, 'QUEUE_DRIVER ausente -> DbMailQueue');
putenv('QUEUE_DRIVER=db');
t_ok(MailQueues::make() instanceof DbMailQueue, 'QUEUE_DRIVER=db -> DbMailQueue');
foreach (['redis', 'sqlite'] as $drv) {
    putenv('QUEUE_DRIVER=' . $drv);
    $err = null;
    try {
        MailQueues::make();
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }
    t_ok($err !== null && str_contains($err, $drv), "QUEUE_DRIVER=$drv: erro claro");
}
putenv('QUEUE_DRIVER');

echo "payload: só tipo e e-mail\n";
t_ok($q->push('activation', 'Ana@CasaLivre.test'), 'push ok');
$id = $lastId();
$r = $row($id);
t_eq(['activation', 'ana@casalivre.test', 'pending', 0], [$r['type'], $r['recipient'], $r['status'], (int) $r['attempts']], 'linha: tipo, e-mail, pending');
$cols = array_keys($r);
t_ok(!array_intersect($cols, ['body', 'subject', 'token', 'link']), 'tabela sem body/subject/token/link');
t_ok(!preg_match('/token=|https?:/', implode('|', array_map('strval', $r))), 'nenhum valor com link/token');
$err = null;
try {
    $q->push('outro', 'x@casalivre.test');
} catch (InvalidArgumentException) {
    $err = true;
}
t_ok($err === true, 'tipo inválido recusado');

echo "reserve / ack limpa o destinatário\n";
$jobs = $q->reserve(10);
t_eq([['id' => $id, 'type' => 'activation', 'recipient' => 'ana@casalivre.test', 'attempts' => 0]], $jobs, 'reserve devolve tipo e e-mail');
t_eq('processing', $row($id)['status'], 'reservado fica processing');
t_eq([], $q->reserve(10), 'reservado não é entregue de novo');
$q->ack($id);
t_eq(['sent', ''], [$row($id)['status'], $row($id)['recipient']], 'ack -> sent, destinatário apagado');

echo "cooldown 5 min por destinatário+tipo\n";
t_eq(false, $q->push('activation', 'ana@casalivre.test'), 'mesmo tipo+e-mail em <5 min: ignorado (mesmo após ack)');
t_eq(true, $q->push('reset', 'ana@casalivre.test'), 'outro tipo: aceito');
t_eq(true, $q->push('activation', 'bia@casalivre.test'), 'outro e-mail: aceito');
db()->prepare('UPDATE mail_queue SET created_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - MailQueue::COOLDOWN_SECONDS - 1), $id]);
t_eq(true, $q->push('activation', 'ana@casalivre.test'), 'depois de 5 min: aceito de novo');

echo "fail: backoff 1, 5, 15, 15 min; desiste na 5ª e limpa\n";
db()->exec('DELETE FROM mail_queue');
$q->push('reset', 'bia@casalivre.test');
$id = $lastId();
foreach ([60, 300, 900, 900] as $n => $secs) {
    t_eq(false, $q->fail($id, 'RuntimeException'), 'falha ' . ($n + 1) . ': não desiste');
    $r = $row($id);
    t_eq(['pending', $n + 1], [$r['status'], (int) $r['attempts']], 'falha ' . ($n + 1) . ': pending');
    t_ok(abs($delay($r) - $secs) <= 2, 'falha ' . ($n + 1) . ": próxima em {$secs}s");
}
t_eq('bia@casalivre.test', $row($id)['recipient'], 'antes de desistir mantém o destinatário');
t_eq(true, $q->fail($id, 'RuntimeException'), 'falha 5: desiste');
$r = $row($id);
t_eq(['failed', 5, '', 'RuntimeException'], [$r['status'], (int) $r['attempts'], $r['recipient'], $r['last_error']], 'failed: destinatário apagado, só diagnóstico');

echo "worker: desistência com log sem dados pessoais\n";
db()->exec('DELETE FROM mail_queue');
$log = tempnam(sys_get_temp_dir(), 'errlog');
ini_set('error_log', $log);
$q->push('activation', 'cris@casalivre.test');
$id = $lastId();
MailWorker::$deliver = static function (): bool {
    throw new RuntimeException('smtp fora');
};
for ($i = 1; $i <= 5; $i++) {
    db()->prepare('UPDATE mail_queue SET next_attempt_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $id]);
    $rep = MailWorker::run($q, $lock);
    t_eq([1, $i === 5 ? 1 : 0], [$rep['falhas'] ?? null, $rep['desistencias'] ?? null], "tentativa $i");
}
t_eq(['failed', ''], [$row($id)['status'], $row($id)['recipient']], 'worker: failed e limpo');
$text = (string) file_get_contents($log);
t_ok(str_contains($text, 'mail_queue: e-mail #' . $id . ' desistido'), 'desistência no log');
t_ok(!str_contains($text, 'cris@'), 'log sem e-mail');

echo "lock 0600 e exclusivo\n";
@unlink($lock);
$oldMask = umask(0022);
MailWorker::$deliver = $okDeliver;
t_ok(is_array(MailWorker::run($q, $lock)), 'worker roda');
umask($oldMask);
t_eq('0600', substr(sprintf('%o', fileperms($lock)), -4), 'lock criado 0600 mesmo com umask 0022');
chmod($lock, 0644);
MailWorker::run($q, $lock);
t_eq('0600', substr(sprintf('%o', fileperms($lock)), -4), 'lock existente volta a 0600');
$h = fopen($lock, 'c');
flock($h, LOCK_EX);
t_eq(null, MailWorker::run($q, $lock), 'lock tomado: worker sai');
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('$h = fopen(' . var_export($lock, true) . ', "c"); echo flock($h, LOCK_EX | LOCK_NB) ? "livre" : "ocupado";'));
t_eq('ocupado', $out, 'outro processo vê o lock ocupado');
flock($h, LOCK_UN);
fclose($h);

echo "teto de tempo do lote\n";
t_ok(MailWorker::MAX_RUN_SECONDS < DbMailQueue::STALE_SECONDS, 'MAX_RUN_SECONDS < STALE_SECONDS');
db()->exec('DELETE FROM mail_queue');
foreach (['a', 'b', 'c', 'd'] as $u) {
    $q->push('reset', "$u@casalivre.test");
}
MailWorker::$deliver = static function (): bool {
    usleep(600000);
    return true;
};
$rep = MailWorker::run($q, $lock, 50, null, 1);
t_eq(2, $rep['enviados'] ?? null, 'teto de 1s: para após 2 itens de 0,6s');
t_eq(2, (int) db()->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending'")->fetchColumn(), 'restantes ficam pending (não presos em processing)');
$rep = MailWorker::run($q, $lock, 50, null, 100000);
t_eq(2, $rep['enviados'] ?? null, 'teto acima do máximo é limitado, lote termina');

echo "purga e retenção (Mailer::env)\n";
$old = gmdate('Y-m-d H:i:s', time() - 8 * 86400);
db()->exec("UPDATE mail_queue SET updated_at = '$old'");
$q->push('reset', 'fe@casalivre.test');
t_eq(4, $q->purge(7), 'purge(7): apaga sent antigos');
t_eq(1, (int) db()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), 'pending mantido');
t_eq(7, MailWorker::retentionDays(), 'retenção padrão 7');
putenv('MAIL_QUEUE_RETENTION_DAYS=2');
t_eq(2, MailWorker::retentionDays(), 'MAIL_QUEUE_RETENTION_DAYS=2');
putenv('MAIL_QUEUE_RETENTION_DAYS');

echo "worker gera token/link; usuário ausente não recebe nada (dev, mail-dev.log)\n";
db()->exec('DELETE FROM mail_queue');
MailWorker::$deliver = null;
putenv('APP_ENV=dev');
putenv('APP_URL=http://127.0.0.1:8080');
$dir = sys_get_temp_dir() . '/cl-mq-' . bin2hex(random_bytes(3));
mkdir($dir, 0700);
Mailer::$devLogDir = $dir;
Mailer::$queue = $q;
AccountFlow::$enqueue = [Mailer::class, 'enqueueAccountMail'];
$pdo = db();
$mk = static function (string $email, string $status) use ($pdo): void {
    $pdo->prepare("INSERT INTO users (name, cpf, address, phone, email, password_hash, status) VALUES ('U', '', '', '', ?, 'x', ?)")->execute([$email, $status]);
};
$mk('gil@casalivre.test', 'active');
$mk('hugo@casalivre.test', 'pending');
$r1 = AccountFlow::askReset('ninguem@casalivre.test', 30);
$r2 = AccountFlow::askReset('gil@casalivre.test', 30);
t_eq([array_keys($r1), null, null], [array_keys($r2), $r1['link'], $r2['link']], 'askReset: mesma resposta, sem link, com ou sem conta');
t_eq(2, (int) db()->query("SELECT COUNT(*) FROM mail_queue WHERE type = 'reset'")->fetchColumn(), 'askReset sempre enfileira');
$tok = static fn (string $col, string $email): string => (string) (($s = db()->prepare("SELECT $col FROM users WHERE email = ?")) && $s->execute([$email]) ? $s->fetchColumn() : '');
t_eq('', $tok('reset_token', 'gil@casalivre.test'), 'requisição não gera token');
t_ok(!is_file($dir . '/mail-dev.log'), 'nada escrito na requisição');
Mailer::enqueueAccountMail('activation', 'hugo@casalivre.test');
Mailer::enqueueAccountMail('reset', 'hugo@casalivre.test');
$rep = MailWorker::run($q, $lock);
t_eq([2, 2], [$rep['enviados'], $rep['ignorados']], 'worker: 2 enviados; ausente e pendente-pedindo-reset ignorados');
$dev = (string) @file_get_contents($dir . '/mail-dev.log');
$t1 = $tok('reset_token', 'gil@casalivre.test');
$t2 = $tok('activation_token', 'hugo@casalivre.test');
$raw = static fn (string $prefix): string => preg_match('#' . preg_quote($prefix, '#') . '([0-9a-f]{32})#', $dev, $mm) === 1 ? $mm[1] : '';
$rawReset = $raw('http://127.0.0.1:8080/senha/redefinir?token=');
$rawAct = $raw('http://127.0.0.1:8080/verificar?token=');
t_ok($rawReset !== '' && hash_equals($t1, hash('sha256', $rawReset)), 'reset: link com APP_URL e token bruto; banco guarda o sha256');
t_ok($rawAct !== '' && hash_equals($t2, hash('sha256', $rawAct)), 'activation: link com token bruto; banco guarda o sha256');
t_ok(!str_contains($dev, 'ninguem@'), 'usuário inexistente: nada enviado');
t_eq('', $tok('reset_token', 'hugo@casalivre.test'), 'conta pendente não recebe reset');
t_eq(0, (int) db()->query("SELECT COUNT(*) FROM mail_queue WHERE recipient <> '' OR status <> 'sent'")->fetchColumn(), 'todos ack e limpos');
t_eq('0600', substr(sprintf('%o', fileperms($dir . '/mail-dev.log')), -4), 'mail-dev.log 0600');
t_eq(false, Mailer::enqueueAccountMail('reset', 'gil@casalivre.test'), 'cooldown via Mailer');
$r3 = AccountFlow::askReset('gil@casalivre.test', 30);
t_eq(array_keys($r2), array_keys($r3), 'cooldown: mesma resposta ao chamador');
putenv('APP_ENV=production');
db()->exec('DELETE FROM mail_queue');
t_ok(!Mailer::enqueueAccountMail('reset', 'gil@casalivre.test'), 'produção sem SMTP: não enfileira');
t_eq(0, (int) db()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), 'produção sem SMTP: fila vazia');
t_ok(str_contains((string) file_get_contents($log), Mailer::NOT_CONFIGURED), 'mail_not_configured no log');
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
@unlink($lock);
putenv('APP_ENV');
putenv('APP_URL');

t_done();

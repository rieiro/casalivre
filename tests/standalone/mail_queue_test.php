<?php
// Fila de e-mails: push/reserve/ack/fail, backoff 1/5/15 min e desistência após 5 tentativas (log sem e-mail/link),
// purga de sent/failed, lock do worker (flock), mail-dev.log em dev pelo worker, fábrica por QUEUE_DRIVER.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/MailWorker.php';
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
$delay = static fn (array $r): int => strtotime($r['next_attempt_at'] . ' UTC') - time();
$lock = tempnam(sys_get_temp_dir(), 'mwlock');

echo "fábrica\n";
t_ok($q instanceof SqliteMailQueue, 'QUEUE_DRIVER ausente -> SqliteMailQueue');
putenv('QUEUE_DRIVER=redis');
$err = null;
try {
    MailQueues::make();
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
t_ok($err !== null && str_contains($err, 'redis'), 'QUEUE_DRIVER=redis ainda não implementado: erro claro');
putenv('QUEUE_DRIVER');

echo "push / reserve / ack\n";
$id = $q->push('ana@casalivre.test', 'Assunto', 'corpo https://x/verificar?token=T1');
$r = $row($id);
t_eq(['pending', 0], [$r['status'], (int) $r['attempts']], 'push: pending, 0 tentativas');
t_ok($r['created_at'] !== '' && $r['updated_at'] !== '' && $r['next_attempt_at'] !== '', 'push: timestamps preenchidos');
$jobs = $q->reserve(10);
t_eq([$id], array_column($jobs, 'id'), 'reserve devolve o e-mail pronto');
t_eq('ana@casalivre.test', $jobs[0]['recipient'] ?? null, 'reserve traz destinatário');
t_eq('processing', $row($id)['status'], 'reservado fica processing');
t_eq([], $q->reserve(10), 'reservado não é entregue de novo');
$q->ack($id);
t_eq('sent', $row($id)['status'], 'ack -> sent');

echo "fail: backoff 1, 5, 15, 15 min; desiste na 5ª\n";
$id = $q->push('bia@casalivre.test', 'S', 'b');
$expected = [60, 300, 900, 900];
foreach ($expected as $n => $secs) {
    t_eq(false, $q->fail($id), 'falha ' . ($n + 1) . ': não desiste');
    $r = $row($id);
    t_eq(['pending', $n + 1], [$r['status'], (int) $r['attempts']], 'falha ' . ($n + 1) . ': pending, attempts=' . ($n + 1));
    t_ok(abs($delay($r) - $secs) <= 2, 'falha ' . ($n + 1) . ": próxima tentativa em {$secs}s (" . $delay($r) . ')');
    t_eq([], $q->reserve(10), 'falha ' . ($n + 1) . ': não reservado antes do backoff');
}
t_eq(true, $q->fail($id), 'falha 5: desiste');
t_eq(['failed', 5], [$row($id)['status'], (int) $row($id)['attempts']], 'após 5 tentativas: failed');

echo "worker: retry e desistência com log sem dados pessoais\n";
db()->exec('DELETE FROM mail_queue');
$log = tempnam(sys_get_temp_dir(), 'errlog');
ini_set('error_log', $log);
$id = $q->push('cris@casalivre.test', 'S', 'abra https://casalivre.example/verificar?token=SEGREDO');
MailWorker::$deliver = static function (): void {
    throw new RuntimeException('smtp fora');
};
for ($i = 1; $i <= 5; $i++) {
    db()->prepare('UPDATE mail_queue SET next_attempt_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $id]);
    $rep = MailWorker::run($q, $lock);
    t_eq([1, $i === 5 ? 1 : 0], [$rep['falhas'] ?? null, $rep['desistencias'] ?? null], "worker tentativa $i: falha" . ($i === 5 ? ' + desistência' : ''));
}
t_eq('failed', $row($id)['status'], 'worker: desistiu após 5 tentativas');
$text = (string) file_get_contents($log);
t_ok(str_contains($text, 'mail_queue: e-mail #' . $id . ' desistido'), 'desistência registrada no log');
t_ok(!str_contains($text, 'cris@') && !str_contains($text, 'SEGREDO') && !str_contains($text, 'verificar'), 'log sem e-mail nem link');
$id2 = $q->push('dani@casalivre.test', 'S', 'b');
MailWorker::$deliver = static function (): void {
};
t_eq(1, MailWorker::run($q, $lock)['enviados'] ?? null, 'worker: entrega ok -> enviados 1');
t_eq('sent', $row($id2)['status'], 'worker: ack -> sent');
MailWorker::$deliver = null;

echo "reserva órfã (worker morreu) volta para a fila\n";
$id3 = $q->push('eva@casalivre.test', 'S', 'b');
db()->prepare("UPDATE mail_queue SET status = 'processing', updated_at = ? WHERE id = ?")->execute([gmdate('Y-m-d H:i:s', time() - SqliteMailQueue::STALE_SECONDS - 5), $id3]);
t_eq([$id3], array_column($q->reserve(10), 'id'), 'processing antigo é reservado de novo');
$q->ack($id3);

echo "purga de sent/failed (retenção)\n";
$old = gmdate('Y-m-d H:i:s', time() - 8 * 86400);
db()->prepare('UPDATE mail_queue SET updated_at = ? WHERE id IN (?, ?)')->execute([$old, $id, $id2]);
$pend = $q->push('fe@casalivre.test', 'S', 'b');
db()->prepare('UPDATE mail_queue SET updated_at = ?, next_attempt_at = ? WHERE id = ?')->execute([$old, gmdate('Y-m-d H:i:s', time() + 3600), $pend]);
t_eq(2, $q->purge(7), 'purge(7): apaga sent e failed com mais de 7 dias');
t_eq([$id3, $pend], array_map('intval', db()->query('SELECT id FROM mail_queue ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)), 'mantém sent recente e pending antigo');
t_eq(7, MailWorker::retentionDays(), 'MAIL_QUEUE_RETENTION_DAYS ausente -> 7');
putenv('MAIL_QUEUE_RETENTION_DAYS=2');
t_eq(2, MailWorker::retentionDays(), 'MAIL_QUEUE_RETENTION_DAYS=2');
putenv('MAIL_QUEUE_RETENTION_DAYS');

echo "lock: dois workers não rodam juntos\n";
$h = fopen($lock, 'c');
flock($h, LOCK_EX);
t_eq(null, MailWorker::run($q, $lock), 'com o lock tomado o worker sai sem processar');
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('$h = fopen(' . var_export($lock, true) . ', "c"); echo flock($h, LOCK_EX | LOCK_NB) ? "livre" : "ocupado";'));
t_eq('ocupado', $out, 'outro processo também vê o lock ocupado');
flock($h, LOCK_UN);
fclose($h);
t_ok(is_array(MailWorker::run($q, $lock)), 'lock liberado: worker roda');

echo "dev sem SMTP: worker grava mail-dev.log; sendAccountLink só enfileira\n";
db()->exec('DELETE FROM mail_queue');
putenv('APP_ENV=dev');
putenv('APP_URL=http://127.0.0.1:8080');
$dir = sys_get_temp_dir() . '/cl-mq-' . bin2hex(random_bytes(3));
mkdir($dir, 0700);
Mailer::$devLogDir = $dir;
Mailer::$queue = $q;
t_ok(Mailer::sendAccountLink('gil@casalivre.test', 'http://h/verificar?token=TOKDEV'), 'sendAccountLink enfileira');
t_ok(!is_file($dir . '/mail-dev.log'), 'nada escrito na requisição');
t_eq(1, (int) db()->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending'")->fetchColumn(), 'um pendente');
t_eq(1, MailWorker::run($q, $lock)['enviados'] ?? null, 'worker processa');
$dev = (string) @file_get_contents($dir . '/mail-dev.log');
t_ok(str_contains($dev, 'gil@casalivre.test') && str_contains($dev, 'http://127.0.0.1:8080/verificar?token=TOKDEV'), 'mail-dev.log com destinatário e link (APP_URL)');
t_eq('0600', substr(sprintf('%o', fileperms($dir . '/mail-dev.log')), -4), 'mail-dev.log 0600');
putenv('APP_ENV=production');
t_ok(!Mailer::sendAccountLink('gil@casalivre.test', 'http://h/verificar?token=TOKPROD'), 'produção sem SMTP: não enfileira');
t_eq(0, (int) db()->query("SELECT COUNT(*) FROM mail_queue WHERE body LIKE '%TOKPROD%'")->fetchColumn(), 'produção sem SMTP: link fora da fila');
t_ok(str_contains((string) file_get_contents($log), Mailer::NOT_CONFIGURED), 'produção sem SMTP: mail_not_configured no log');
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
putenv('APP_ENV');
putenv('APP_URL');

t_done();

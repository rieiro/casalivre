<?php
// #142: the long-running queue worker kept Settings' per-process copy of settings.json forever, so mail kept the old
// theme brand and account links kept the old expiry minutes after an admin change. Each QueueWorkerLoop::tick now
// reloads it when settings.json changed (WorkerCaches::refreshIfChanged): the second tick sees the new values.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/conta/Account/AccountFlow.php';
require_once CL_ROOT . '/src/Acoes/ThemeSet.php';
require_once CL_ROOT . '/src/QueueWorkerLoop.php';
require_once CL_ROOT . '/src/MailHtml.php';
putenv('APP_URL=https://casalivre.example');
putenv('APP_ENV=dev');
putenv('SMTP_HOST');
putenv('QUEUE_DRIVER');
putenv('MAIL_HTML');
t_temp_db();
// settings.json lives in storage/cache (shared with the other tests): put it back as it was.
$cacheFile = JsonCache::path('settings.json');
$saved = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : null;
register_shutdown_function(static function () use ($cacheFile, $saved): void {
    $saved === null ? @unlink($cacheFile) : file_put_contents($cacheFile, $saved);
});
Settings::refresh();
$dir = sys_get_temp_dir() . '/qwsc' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
Mailer::$devLogDir = $dir;
$mail = MailQueues::make();

echo "theme brand in the rendered mail\n";
$html = [];
MailWorker::$deliver = static function (string $type, string $email) use (&$html): bool {
    $html[] = (string) Mailer::htmlPart('Aviso', "Texto\n\nhttps://casalivre.example/");
    return true;
};
// Expected mail for a theme: the same body rendered with that theme's brand.php.
$header = static fn (string $theme): string => MailHtml::render('Aviso', "Texto\n\nhttps://casalivre.example/", Theme::brand($theme));
t_ok($header('padrao') !== $header('contraste'), 'control: the two themes render different mail');
t_ok($mail->push('activation', 'b1@casalivre.test'), 'job 1 queued');
QueueWorkerLoop::tick($mail, null, $dir);
t_ok(($html[0] ?? '') === $header('padrao'), 'tick 1: padrao brand');
t_eq('ok', ThemeSet::run('contraste', Theme::current(), Theme::available())->tipo, 'admin switches the theme to contraste (DB + settings.json)');
t_backdate($cacheFile);
t_ok($mail->push('activation', 'b2@casalivre.test'), 'job 2 queued');
QueueWorkerLoop::tick($mail, null, $dir);
t_ok(($html[1] ?? '') === $header('contraste'), 'tick 2: contraste brand');
t_eq('ok', ThemeSet::run('padrao', 'contraste', Theme::available())->tipo, 'theme back to padrao');
t_backdate($cacheFile);

echo "link expiry minutes (email_verify_minutes / password_reset_minutes)\n";
MailWorker::$deliver = null;
$minutes = static function (int $id, string $col): int {
    $st = db()->prepare("SELECT $col FROM users WHERE id = ?");
    $st->execute([$id]);
    return (int) round((strtotime((string) $st->fetchColumn()) - time()) / 60);
};
$round = static function () use ($mail, $dir): array {
    $p = c_user('pending');
    $r = c_user('active');
    $mail->push('activation', $p['email']);
    $mail->push('reset', $r['email']);
    $rep = QueueWorkerLoop::tick($mail, null, $dir);
    return [$rep['mail']['enviados'] ?? null, (int) $p['id'], (int) $r['id']];
};
[$sent, $p, $r] = $round();
t_eq(2, $sent, 'tick 1: activation and reset delivered (mail-dev.log)');
t_eq(60, $minutes($p, 'activation_expires_at'), 'tick 1: activation link expires in 60 min');
t_eq(30, $minutes($r, 'reset_expires_at'), 'tick 1: reset link expires in 30 min');
$upd = db()->prepare('UPDATE settings SET value = ? WHERE skey = ?');
$upd->execute(['180', 'email_verify_minutes']);
$upd->execute(['90', 'password_reset_minutes']);
Settings::refresh(); // what scripts/refresh-settings-cache.php does after a settings change (another process)
t_backdate($cacheFile);
[$sent, $p, $r] = $round();
t_eq(2, $sent, 'tick 2: activation and reset delivered');
t_eq(180, $minutes($p, 'activation_expires_at'), 'tick 2: activation link expires in the new 180 min');
t_eq(90, $minutes($r, 'reset_expires_at'), 'tick 2: reset link expires in the new 90 min');
t_ok(str_contains((string) file_get_contents($dir . '/mail-dev.log'), 'https://casalivre.example/senha/redefinir?token='), 'reset link in mail-dev.log');
@exec('rm -rf ' . escapeshellarg($dir));
t_done();

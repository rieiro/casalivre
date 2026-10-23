<?php
// #142: the long-running queue worker kept I18n's per-process copy of translations.json forever, so a changed
// translation was never seen. Each QueueWorkerLoop::tick reloads it when translations.json changed
// (WorkerCaches::refreshIfChanged): the next tick sees the new text.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/conta/Account/AccountFlow.php';
require_once CL_ROOT . '/src/I18n.php';
require_once CL_ROOT . '/src/QueueWorkerLoop.php';
putenv('APP_URL=https://casalivre.example');
putenv('QUEUE_DRIVER');
t_temp_db();
// translations.json lives in storage/cache (shared with the other tests): put it back as it was.
$cacheFile = JsonCache::path('translations.json');
$saved = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : null;
register_shutdown_function(static function () use ($cacheFile, $saved): void {
    $saved === null ? @unlink($cacheFile) : file_put_contents($cacheFile, $saved);
});
unset($_SESSION, $_SERVER['HTTP_ACCEPT_LANGUAGE']);
I18n::refresh();
$dir = sys_get_temp_dir() . '/qwic' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$mail = MailQueues::make();
// Delivery stub: what a translated mail would read while the worker delivers the job.
$seen = [];
MailWorker::$deliver = static function (string $type, string $email) use (&$seen): bool {
    $seen[] = I18n::t('nav.search');
    return true;
};

t_ok($mail->push('activation', 'i1@casalivre.test'), 'job 1 queued');
QueueWorkerLoop::tick($mail, null, $dir);
t_eq('Buscar', $seen[0] ?? null, 'tick 1: pt-BR nav.search from translations.json');
db()->prepare('UPDATE translations SET text = ? WHERE lang = ? AND tkey = ?')->execute(['Procurar', 'pt-BR', 'nav.search']);
I18n::refresh(); // what scripts/refresh-settings-cache.php does after a translation change (another process)
t_backdate($cacheFile);
t_ok($mail->push('activation', 'i2@casalivre.test'), 'job 2 queued');
QueueWorkerLoop::tick($mail, null, $dir);
t_eq('Procurar', $seen[1] ?? null, 'tick 2: the changed translation is seen');
@exec('rm -rf ' . escapeshellarg($dir));
t_done();

<?php
// #142: the long-running queue worker kept AssetManifest's per-process copy of public/assets/manifest.json forever,
// so a rebuilt manifest (theme list, Theme::available) was never seen. Each QueueWorkerLoop::mail batch reloads it when
// manifest.json changed (WorkerCaches::refreshIfChanged): after a rebuild that drops a theme, the next batch's mail
// falls back to the default brand.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/conta/Account/AccountFlow.php';
require_once CL_ROOT . '/src/Acoes/ThemeSet.php';
require_once CL_ROOT . '/src/QueueWorkerLoop.php';
require_once CL_ROOT . '/src/MailHtml.php';
putenv('APP_URL=https://casalivre.example');
putenv('QUEUE_DRIVER');
putenv('MAIL_HTML');
t_temp_db();
// manifest.json (tracked) and settings.json (storage/cache) are shared: put both back as they were.
$files = [AssetManifest::file(), JsonCache::path('settings.json')];
$saved = array_map(static fn (string $f): ?string => is_file($f) ? (string) file_get_contents($f) : null, $files);
$restore = static function () use ($files, $saved): void {
    foreach ($files as $i => $f) {
        $saved[$i] === null ? @unlink($f) : file_put_contents($f, $saved[$i]);
    }
};
register_shutdown_function($restore);
Settings::refresh();
$dir = sys_get_temp_dir() . '/qwmc' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$mail = MailQueues::make();
$html = [];
MailWorker::$deliver = static function (string $type, string $email) use (&$html): bool {
    $html[] = (string) Mailer::htmlPart('Aviso', "Texto\n\nhttps://casalivre.example/");
    return true;
};
// Expected mail for a theme: the same body rendered with that theme's brand.php.
$header = static fn (string $theme): string => MailHtml::render('Aviso', "Texto\n\nhttps://casalivre.example/", Theme::brand($theme));
t_eq('ok', ThemeSet::run('contraste', Theme::current(), Theme::available())->tipo, 'platform theme: contraste');
t_backdate($files[1]);

echo "batch 1: manifest lists contraste\n";
t_ok($mail->push('activation', 'm1@casalivre.test'), 'job 1 queued');
QueueWorkerLoop::mail($mail, $dir);
t_ok(in_array('contraste', Theme::available(), true), 'batch 1: contraste available');
t_ok(($html[0] ?? '') === $header('contraste'), 'batch 1: contraste brand');

echo "manifest rebuilt without contraste\n";
$m = AssetManifest::load();
unset($m['/assets/themes/contraste.css']);
file_put_contents(AssetManifest::file(), AssetManifest::encode($m));
t_backdate(AssetManifest::file());
t_ok($mail->push('activation', 'm2@casalivre.test'), 'job 2 queued');
QueueWorkerLoop::mail($mail, $dir);
t_ok(!array_key_exists('/assets/themes/contraste.css', AssetManifest::load()), 'batch 2: rebuilt manifest seen');
t_eq(['padrao'], Theme::available(), 'batch 2: theme list from the rebuilt manifest');
t_ok(($html[1] ?? '') === $header('padrao'), 'batch 2: mail falls back to the default brand');
$restore();
t_eq($saved[0], (string) file_get_contents(AssetManifest::file()), 'manifest.json restored');
@exec('rm -rf ' . escapeshellarg($dir));
t_done();

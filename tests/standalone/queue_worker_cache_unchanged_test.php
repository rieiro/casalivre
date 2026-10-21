<?php
// #142 (Rafael): the queue worker must not re-read settings.json, manifest.json or translations.json on every tick.
// WorkerCaches::refreshIfChanged reloads a cache only when its file's stamp (mtime, inode, size) changed. Here each file
// is altered in place with the same size and its old mtime put back, so its stamp is unchanged: if the next tick
// re-read it, the altered value would show up.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/conta/Account/AccountFlow.php';
require_once CL_ROOT . '/src/QueueWorkerLoop.php';
putenv('APP_URL=https://casalivre.example');
putenv('QUEUE_DRIVER');
t_temp_db();
unset($_SESSION, $_SERVER['HTTP_ACCEPT_LANGUAGE']);
Settings::refresh();
I18n::refresh();
$files = ['settings' => JsonCache::path('settings.json'), 'manifest' => AssetManifest::file(), 'i18n' => JsonCache::path('translations.json')];
$saved = array_map(static fn (string $f): ?string => is_file($f) ? (string) file_get_contents($f) : null, $files);
$restore = static function () use ($files, $saved): void {
    foreach ($files as $k => $f) {
        $saved[$k] === null ? @unlink($f) : file_put_contents($f, $saved[$k]);
    }
};
register_shutdown_function($restore);
$dir = sys_get_temp_dir() . '/qwcu' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$mail = MailQueues::make();
$read = [];
MailWorker::$deliver = static function (string $type, string $email) use (&$read): bool {
    $read[] = ['settings' => Settings::get('results_per_page'), 'manifest' => AssetManifest::load()['/assets/app.css'] ?? null, 'i18n' => I18n::t('nav.search')];
    return true;
};

t_ok($mail->push('activation', 'u1@casalivre.test'), 'job 1 queued');
QueueWorkerLoop::tick($mail, null, $dir);
$first = $read[0] ?? [];
t_eq('20', $first['settings'] ?? null, 'tick 1: results_per_page from settings.json');
t_eq('Buscar', $first['i18n'] ?? null, 'tick 1: nav.search from translations.json');
t_ok(is_string($first['manifest'] ?? null) && strlen($first['manifest']) === AssetManifest::HASH_LEN, 'tick 1: app.css hash from manifest.json');

// Same-size in-place edits, old mtime restored: the stamp does not change.
// [anchors to skip to, from, to]
$alter = [
    'settings' => [['"results_per_page"'], '"value": "20"', '"value": "29"'],
    'manifest' => [['"/assets/app.css"'], (string) ($first['manifest'] ?? ''), str_repeat('0', AssetManifest::HASH_LEN)],
    'i18n' => [['"pt-BR"', '"nav.search"'], '"Buscar"', '"Buzcar"'],
];
foreach ($files as $k => $f) {
    $st = stat($f);
    $body = (string) file_get_contents($f);
    [$anchors, $from, $to] = $alter[$k];
    $pos = 0;
    foreach (array_merge($anchors, [$from]) as $needle) {
        $pos = $pos === false ? false : strpos($body, $needle, $pos);
    }
    t_ok($pos !== false && $from !== $to && strlen($from) === strlen($to), "$k: value to alter found");
    $fh = fopen($f, 'r+');
    fseek($fh, (int) $pos);
    fwrite($fh, $to);
    fclose($fh);
    touch($f, $st['mtime']);
    t_eq(FileStamp::of($f), $st['mtime'] . ':' . $st['ino'] . ':' . $st['size'], "$k: stamp unchanged (same mtime, inode, size)");
    t_ok(str_contains((string) file_get_contents($f), $to), "$k: file content altered");
}

t_ok($mail->push('activation', 'u2@casalivre.test'), 'job 2 queued');
QueueWorkerLoop::tick($mail, null, $dir);
t_eq($first, $read[1] ?? null, 'tick 2: unchanged files are not re-read (values still from memory)');

echo "control: a real change is picked up\n";
clearstatcache();
touch($files['i18n'], time() + 5);
t_ok($mail->push('activation', 'u3@casalivre.test'), 'job 3 queued');
QueueWorkerLoop::tick($mail, null, $dir);
t_eq('Buzcar', $read[2]['i18n'] ?? null, 'tick 3: translations.json mtime changed -> re-read');
t_eq($first['settings'], $read[2]['settings'] ?? null, 'tick 3: settings.json still unchanged -> not re-read');
$restore();
@exec('rm -rf ' . escapeshellarg($dir));
t_done();

<?php
// #142 (Rafael): the queue worker must not re-read settings.json, manifest.json or translations.json on every tick.
// WorkerCaches::refreshIfChanged reloads a cache only when its file's stamp (FileStamp: mtime, ctime, inode, size)
// changed. Unchanged files: the per-process copies are overwritten in memory after tick 1; if tick 2 re-read the
// files, the memory values would be lost. Then (Arquitetura #170) each file is altered in place with the same size,
// the same inode and its old mtime put back (as cp -p / rsync -t / tar do): only ctime changes, and tick 3 must
// re-read all three.
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

// Files written just now are in FileStamp's racy window (content hashed, #158), and ctime cannot be set back: wait
// it out, so the stamps below are the plain stat fields of files that are not read on every tick.
sleep(FileStamp::RACY_SECONDS + 1);
t_ok($mail->push('activation', 'u1@casalivre.test'), 'job 1 queued');
QueueWorkerLoop::tick($mail, null, $dir);
$first = $read[0] ?? [];
t_eq('20', $first['settings'] ?? null, 'tick 1: results_per_page from settings.json');
t_eq('Buscar', $first['i18n'] ?? null, 'tick 1: nav.search from translations.json');
t_ok(is_string($first['manifest'] ?? null) && strlen($first['manifest']) === AssetManifest::HASH_LEN, 'tick 1: app.css hash from manifest.json');

echo "unchanged files are not re-read\n";
$mem = [
    'settings' => static fn () => Settings::$rows['results_per_page']['value'] = 'MEM',
    'manifest' => static fn () => AssetManifest::$cache['/assets/app.css'] = 'MEM',
    'i18n' => static fn () => I18n::$rows['pt-BR']['nav.search'] = 'MEM',
];
$classes = ['settings' => Settings::class, 'manifest' => AssetManifest::class, 'i18n' => I18n::class];
foreach ($mem as $k => $set) {
    Closure::bind($set, null, $classes[$k])();
}
t_ok($mail->push('activation', 'u2@casalivre.test'), 'job 2 queued');
QueueWorkerLoop::tick($mail, null, $dir);
t_eq(['settings' => 'MEM', 'manifest' => 'MEM', 'i18n' => 'MEM'], $read[1] ?? null, 'tick 2: files unchanged -> not re-read (values still from memory)');

echo "same-size edit with the old mtime restored: caught by ctime\n";
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
    clearstatcache(true, $f);
    $now = stat($f);
    t_eq([$st['mtime'], $st['ino'], $st['size']], [$now['mtime'], $now['ino'], $now['size']], "$k: same mtime, inode and size");
    t_ok(str_contains((string) file_get_contents($f), $to), "$k: file content altered");
}
t_ok($mail->push('activation', 'u3@casalivre.test'), 'job 3 queued');
QueueWorkerLoop::tick($mail, null, $dir);
t_eq(['settings' => '29', 'manifest' => str_repeat('0', AssetManifest::HASH_LEN), 'i18n' => 'Buzcar'], $read[2] ?? null, 'tick 3: all three re-read (ctime changed)');
$restore();
@exec('rm -rf ' . escapeshellarg($dir));
t_done();

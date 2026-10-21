<?php
// Cleanup sprint #158 (Arquitetura #149): FileStamp ("mtime:inode:size") missed a change when two same-size writes
// landed in the same second and ext4 reused the inode, so the worker kept a stale Settings/I18n/manifest cache.
// Reproduced here by rewriting a file in place (same inode) with the same size and putting its mtime back: all three
// fields repeat. A file in the racy window (mtime within RACY_SECONDS of now) now also carries a content hash.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/WorkerCaches.php';

$dir = sys_get_temp_dir() . '/fstamp' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
/** Same-size rewrite of $f, same inode, mtime put back: what ext4 inode reuse plus a 1 s mtime look like. */
$sneaky = static function (string $f, string $from, string $to): void {
    $st = stat($f);
    file_put_contents($f, str_replace($from, $to, (string) file_get_contents($f)));
    touch($f, $st['mtime']);
    clearstatcache(true, $f);
    $now = stat($f);
    t_ok([$now['mtime'], $now['ino'], $now['size']] === [$st['mtime'], $st['ino'], $st['size']], 'rewrite keeps mtime, inode and size');
};

echo "FileStamp\n";
$f = $dir . '/a.json';
file_put_contents($f, '{"v": "aaaa"}');
$s1 = FileStamp::of($f);
$sneaky($f, 'aaaa', 'bbbb');
$s2 = FileStamp::of($f);
t_ok($s1 !== $s2, "same-second same-size rewrite changes the stamp ($s1 -> $s2)");
t_eq(4, count(explode(':', (string) $s2)), 'racy file: stamp has the content-hash field');
t_eq($s2, FileStamp::of($f), 'no write: same stamp');
// Atomic replacement by rename (JsonCache::write), same size and mtime.
$tmp = $dir . '/a.tmp';
file_put_contents($tmp, '{"v": "cccc"}');
touch($tmp, (int) stat($f)['mtime']);
rename($tmp, $f);
t_ok(FileStamp::of($f) !== $s2, 'rename replacement with the same size and mtime changes the stamp');
// Old file: outside the racy window, the stamp is mtime:inode:size only (the file is not read).
touch($f, time() - 60);
clearstatcache(true, $f);
$st = stat($f);
t_eq($st['mtime'] . ':' . $st['ino'] . ':' . $st['size'], FileStamp::of($f), 'old file: 3-field stamp, no content read');
t_eq(null, FileStamp::of($dir . '/missing.json'), 'missing file: null');
// A stamp taken in the racy window differs from the one taken after it: one reload, never a missed write.
file_put_contents($f, '{"v": "dddd"}');
$racy = FileStamp::of($f);
sleep(FileStamp::RACY_SECONDS + 1);
$later = FileStamp::of($f);
t_ok($racy !== $later && count(explode(':', (string) $later)) === 3, 'after the window: 3-field stamp, different from the racy one');

echo "worker cache (Settings)\n";
putenv('QUEUE_DRIVER');
t_temp_db();
$file = JsonCache::path('settings.json');
$saved = is_file($file) ? (string) file_get_contents($file) : null;
register_shutdown_function(static function () use ($file, $saved): void {
    $saved === null ? @unlink($file) : file_put_contents($file, $saved);
});
Settings::refresh();
t_eq('20', Settings::get('results_per_page'), 'loaded: results_per_page 20');
$body = (string) file_get_contents($file);
$pos = strpos($body, '"value": "20"', (int) strpos($body, '"results_per_page"'));
t_ok($pos !== false, 'value found in settings.json');
$st = stat($file);
$fh = fopen($file, 'r+');
fseek($fh, (int) $pos);
fwrite($fh, '"value": "29"');
fclose($fh);
touch($file, $st['mtime']);
WorkerCaches::refreshIfChanged();
t_eq('29', Settings::get('results_per_page'), 'same-second same-size rewrite: the next tick sees the new value');

exec('rm -rf ' . escapeshellarg($dir));
t_done();

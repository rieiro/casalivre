<?php
// #192 (Rafael): the worker file caches store filemtime() at load and reload when it differs, so a rollback or
// checkout that puts an OLDER mtime back is seen too, not only a newer one.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/WorkerCaches.php';

echo "FileStamp\n";
$dir = sys_get_temp_dir() . '/fstamp' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$f = $dir . '/a.json';
file_put_contents($f, '{}');
touch($f, 1_000_000);
t_eq(1_000_000, FileStamp::of($f), 'stamp is filemtime()');
touch($f, 999_000);
t_eq(999_000, FileStamp::of($f), 'older mtime seen without a manual clearstatcache()');
t_eq(null, FileStamp::of($dir . '/missing.json'), 'missing file: null');
exec('rm -rf ' . escapeshellarg($dir));

echo "worker cache (Settings): a newer and an older mtime both reload\n";
putenv('QUEUE_DRIVER');
t_temp_db();
$file = JsonCache::path('settings.json');
$saved = is_file($file) ? (string) file_get_contents($file) : null;
register_shutdown_function(static function () use ($file, $saved): void {
    $saved === null ? @unlink($file) : file_put_contents($file, $saved);
});
Settings::refresh();
$mtime = time() - 600;
touch($file, $mtime);
t_eq('20', Settings::get('results_per_page'), 'loaded: results_per_page 20');
$set = static function (string $value, int $mtime) use ($file): void {
    $rows = json_decode((string) file_get_contents($file), true);
    $rows['results_per_page']['value'] = $value;
    file_put_contents($file, json_encode($rows));
    touch($file, $mtime);
};
$set('29', $mtime);
WorkerCaches::refreshIfChanged();
t_eq('20', Settings::get('results_per_page'), 'same mtime: not re-read (stale is accepted)');
$set('30', $mtime + 60);
WorkerCaches::refreshIfChanged();
t_eq('30', Settings::get('results_per_page'), 'newer mtime: re-read');
$set('31', $mtime - 60);
WorkerCaches::refreshIfChanged();
t_eq('31', Settings::get('results_per_page'), 'older mtime (rollback/checkout): re-read');
t_done();

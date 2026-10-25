<?php
// Roda todos os testes standalone: php tests/standalone/run.php
$fail = 0;
// A fresh php (php.ini only) often has no bz2; production leaves it unloaded and passes -d extension=bz2.
// run.php respawns each test, so the flag has to be repeated or the neighborhood seed fatals.
$bz2 = '';
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('exit(extension_loaded("bz2") ? 0 : 1);'), $_, $bz2Code);
if ($bz2Code !== 0) {
    $bz2 = ' -d extension=bz2';
}
foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    echo '== ' . basename($file) . "\n";
    passthru(escapeshellarg(PHP_BINARY) . $bz2 . ' ' . escapeshellarg($file), $code);
    $fail += $code !== 0 ? 1 : 0;
}
// PG (TEST_PG_DSN): drop test databases left over by processes that have finished.
if ((string) getenv('TEST_PG_DSN') !== '') {
    require_once __DIR__ . '/lib.php';
    foreach (t_pg_sweep((string) getenv('TEST_PG_DSN')) as $db) {
        echo "leftover dropped: $db\n";
    }
}
echo $fail === 0 ? "TODOS PASSARAM\n" : "$fail arquivo(s) com falha\n";
exit($fail === 0 ? 0 : 1);
